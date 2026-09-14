<?php
/* Copyright (c) 2026 David IGREJA <info@siladel.fr>
 *
 * MIT License. See the LICENSE file at the root of this module for details.
 */

/**
 * \file    mediarelay/lib/mediarelay.openmageclient.class.php
 * \ingroup mediarelay
 * \brief   HTTP client driving the OpenMage/Magento1 admin backend to store
 *          generic (non product-bound) images under media/wysiwyg/, using
 *          the same controller the CMS WYSIWYG "Insert Image" popup uses.
 *
 * OpenMage/Magento1 has no REST/SOAP resource for a product-less media
 * library: catalog_product_attribute_media always requires a product.
 * The only endpoint that writes into media/wysiwyg/ is the admin-only
 * Mage_Adminhtml_Cms_Wysiwyg_ImagesController, which needs a full admin
 * session (login[username]/login[password] + form_key), not an API token.
 * This client authenticates as a dedicated OpenMage admin account and talks
 * to that controller directly over HTTP.
 */
class MediarelayOpenmageClientException extends Exception
{
}

/**
 * Class MediarelayOpenmageClient
 */
class MediarelayOpenmageClient
{
	/**
	 * @var string
	 */
	private $baseUrl;

	/**
	 * @var string
	 */
	private $username;

	/**
	 * @var string
	 */
	private $password;

	/**
	 * @var string Subfolder of media/wysiwyg/ to store/list images in (no leading/trailing slash), '' for the root
	 */
	private $folder;

	/**
	 * @var string Cloudflare Access service token Client Id, '' if Cloudflare Access is not in front of this store
	 */
	private $cfAccessClientId;

	/**
	 * @var string Cloudflare Access service token Client Secret, '' if Cloudflare Access is not in front of this store
	 */
	private $cfAccessClientSecret;

	/**
	 * @var resource|\CurlHandle|null Shared curl handle (keeps the session cookie across calls)
	 */
	private $ch;

	/**
	 * @var bool
	 */
	private $loggedIn = false;

	/**
	 * @var string|null Current admin form_key, refreshed after login
	 */
	private $formKey;

	/**
	 * @var string|null HTML listing of $folder, fetched once the storage session is primed on that folder
	 */
	private $primedHtml;

	/**
	 * Constructor
	 *
	 * @param string $baseUrl              OpenMage store base URL (e.g. https://shop.example.com)
	 * @param string $username             Dedicated admin backend username
	 * @param string $password             Dedicated admin backend password
	 * @param string $folder               Subfolder of media/wysiwyg/ to use (e.g. "uploads"), '' for the root
	 * @param string $cfAccessClientId     Cloudflare Access service token Client Id, '' to skip sending it
	 * @param string $cfAccessClientSecret Cloudflare Access service token Client Secret, '' to skip sending it
	 */
	public function __construct($baseUrl, $username, $password, $folder = '', $cfAccessClientId = '', $cfAccessClientSecret = '')
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->username = $username;
		$this->password = $password;
		$this->folder = trim($folder, '/');
		$this->cfAccessClientId = $cfAccessClientId;
		$this->cfAccessClientSecret = $cfAccessClientSecret;
	}

	/**
	 * Log in and make sure the configured folder is reachable, without
	 * uploading or listing anything. Used by the "Test connection" button on
	 * the setup page: exercises the exact same path (login, then folder
	 * creation/priming) as a real upload, so it also catches a misconfigured
	 * MEDIARELAY_ADMIN_FOLDER, not just bad URL/credentials.
	 *
	 * @return void
	 * @throws MediarelayOpenmageClientException
	 */
	public function testConnection()
	{
		$this->ensureReady();
	}

	/**
	 * Upload an image file into media/wysiwyg/ and return its public URL.
	 *
	 * @param string $tmpPath  Local path of the file to upload
	 * @param string $fileName Original file name (extension is what matters, name is not kept as-is by Magento)
	 * @param string $mime     MIME type of the file
	 * @return string Public URL of the uploaded file
	 * @throws MediarelayOpenmageClientException
	 */
	public function uploadImage($tmpPath, $fileName, $mime)
	{
		$this->ensureReady();

		$postFields = array(
			'image' => new CURLFile($tmpPath, $mime, $fileName),
			'form_key' => $this->formKey,
		);

		$response = $this->request('POST', '/admin/cms_wysiwyg_images/upload?type=image', $postFields, true);
		$result = json_decode($response, true);

		if (!is_array($result) || !empty($result['error']) || empty($result['file'])) {
			global $langs;
			// $result['error'] is raw data from OpenMage (safe as a trans() %s param), but the
			// "unexpected response" fallback is itself a trans() result: concatenate that one
			// instead of nesting it as a param, or its own htmlentities() encoding gets encoded
			// a second time by the outer trans() call.
			if (is_array($result) && !empty($result['error'])) {
				throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorUploadFailed', $result['error']));
			}
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorUploadFailedPrefix').' '.$langs->trans('MediaRelayErrorUnexpectedUploadResponse'));
		}

		return $this->baseUrl.'/media/wysiwyg/'.$this->urlPathPrefix().rawurlencode($result['file']);
	}

	/**
	 * List images already present in the configured media/wysiwyg/ subfolder.
	 *
	 * @return array<int,array{url:string,name:string,thumbnail:string}>
	 * @throws MediarelayOpenmageClientException
	 */
	public function listImages()
	{
		$this->ensureReady();

		$files = array();
		if (preg_match_all('/<div class="filecnt".*?<\/div>/s', $this->primedHtml, $blocks)) {
			foreach ($blocks[0] as $block) {
				$name = null;
				if (preg_match('/<img[^>]*alt="([^"]*)"/', $block, $m)) {
					$name = html_entity_decode($m[1], ENT_QUOTES);
				} elseif (preg_match_all('/<small>(.*?)<\/small>/s', $block, $sm) && !empty($sm[1])) {
					$name = html_entity_decode(trim(end($sm[1])), ENT_QUOTES);
				}

				if (empty($name)) {
					continue;
				}

				$url = $this->baseUrl.'/media/wysiwyg/'.$this->urlPathPrefix().rawurlencode($name);
				$files[] = array(
					'url' => $url,
					'name' => $name,
					// The admin thumbnailAction is auth-gated and unreachable from the
					// Dolibarr user's browser (no OpenMage session there), so we reuse
					// the public static file itself; the popup's CSS already caps its size.
					'thumbnail' => $url,
				);
			}
		}

		return $files;
	}

	/**
	 * @return string $this->folder followed by a slash, or '' if using the media/wysiwyg root
	 */
	private function urlPathPrefix()
	{
		return $this->folder === '' ? '' : $this->folder.'/';
	}

	/**
	 * Log in if needed, make sure the target folder exists, and prime the
	 * wysiwyg storage session on it. Safe to call on every request: each
	 * step is skipped once already done on this client instance.
	 *
	 * @return void
	 * @throws MediarelayOpenmageClientException
	 */
	private function ensureReady()
	{
		$this->ensureLogin();

		if ($this->primedHtml !== null) {
			return;
		}

		if ($this->folder !== '') {
			$this->ensureFolderExists();
			$this->primedHtml = $this->primeSession($this->idEncode('/'.$this->folder));
		} else {
			$this->primedHtml = $this->primeSession();
		}
	}

	/**
	 * Create the configured subfolder under media/wysiwyg/ if it doesn't already exist.
	 *
	 * Not fatal on failure: the most common failure is the folder already
	 * existing (expected on every call after the first), and OpenMage's
	 * error message for that is localized (e.g. French "Il existe déjà un
	 * répertoire portant le même nom."), so it can't be matched reliably by
	 * text and is logged as a warning rather than an error either way. Any
	 * other real problem (invalid name, permissions) will surface on its
	 * own when primeSession() below fails to actually enter the folder and
	 * silently falls back to the media/wysiwyg root - the warning logged
	 * here is the only trace of that.
	 *
	 * @return void
	 */
	private function ensureFolderExists()
	{
		// Point the storage session at the root first: newFolder creates
		// inside whatever path was last saved to the session.
		$this->primeSession();

		$response = $this->request('POST', '/admin/cms_wysiwyg_images/newFolder?type=image', array(
			'name' => $this->folder,
			'form_key' => $this->formKey,
		));
		$result = json_decode($response, true);

		if (is_array($result) && !empty($result['error'])) {
			dol_syslog('mediarelay: OpenMage newFolder for "'.$this->folder.'" on '.$this->baseUrl.' reported: '.(string) ($result['message'] ?? '').' (harmless if the folder already exists, otherwise it may not be reachable - check MEDIARELAY_ADMIN_FOLDER and the account permissions)', LOG_WARNING);
		}
	}

	/**
	 * Replicates Mage_Cms_Helper_Wysiwyg_Images::idEncode() so we can point
	 * the "node" (folder) parameter at our configured subfolder.
	 *
	 * @param string $path
	 * @return string
	 */
	private function idEncode($path)
	{
		return strtr(base64_encode($path), '+/=', ':_-');
	}

	/**
	 * Log in to the OpenMage admin backend if not already done on this client instance.
	 *
	 * @return void
	 * @throws MediarelayOpenmageClientException
	 */
	private function ensureLogin()
	{
		if ($this->loggedIn) {
			return;
		}

		$loginPage = $this->request('GET', '/admin/');

		if (!preg_match('/name="form_key"\s+type="hidden"\s+value="([^"]+)"/', $loginPage, $mKey)) {
			dol_syslog('mediarelay: no form_key found on OpenMage admin login page at '.$this->baseUrl.' (check MEDIARELAY_ADMIN_URL, or the store may be unreachable/down)', LOG_ERR);
			global $langs;
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorNoFormKey'));
		}
		$formKey = $mKey[1];

		$actionUrl = $this->baseUrl.'/admin/index/index/';
		if (preg_match('/<form[^>]*id="login-form"[^>]*action="([^"]+)"/', $loginPage, $mAction)) {
			$actionUrl = html_entity_decode($mAction[1], ENT_QUOTES);
		}

		$postFields = array(
			'login[username]' => $this->username,
			'login[password]' => $this->password,
			'form_key' => $formKey,
		);

		$this->requestAbsolute('POST', $actionUrl, $postFields);

		// Confirm the session is authenticated and grab a fresh form_key from
		// an authenticated page before doing anything else.
		$check = $this->request('GET', '/admin/cms_wysiwyg_images/index?type=image');
		if (strpos($check, 'login[username]') !== false) {
			dol_syslog('mediarelay: OpenMage admin login rejected for user "'.$this->username.'" on '.$this->baseUrl.' (check MEDIARELAY_ADMIN_USER/PASSWORD)', LOG_ERR);
			global $langs;
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorLoginFailed'));
		}

		if (preg_match('/name="form_key"\s+type="hidden"\s+value="([^"]+)"/', $check, $mKey2)) {
			$formKey = $mKey2[1];
		} elseif (preg_match('/var\s+FORM_KEY\s*=\s*\'([^\']+)\'/', $check, $mKey3)) {
			$formKey = $mKey3[1];
		}

		$this->formKey = $formKey;
		$this->loggedIn = true;
	}

	/**
	 * Point the wysiwyg storage session at a folder (root if $node is omitted)
	 * and return its file listing HTML. Required before uploadAction/newFolder,
	 * which act on whatever path was last set this way.
	 *
	 * @param string|null $node Encoded folder id (see idEncode()), null for the media/wysiwyg root
	 * @return string Raw HTML response of the contents action (the file listing)
	 * @throws MediarelayOpenmageClientException
	 */
	private function primeSession($node = null)
	{
		$path = '/admin/cms_wysiwyg_images/contents?type=image';
		if ($node !== null) {
			$path .= '&node='.rawurlencode($node);
		}

		$html = $this->request('POST', $path, array('form_key' => $this->formKey));

		$decoded = json_decode($html, true);
		if (is_array($decoded) && !empty($decoded['error'])) {
			global $langs;
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorContentsFailed', $decoded['message']));
		}

		return $html;
	}

	/**
	 * @param string $method GET or POST
	 * @param string $path   Path relative to the store base URL
	 * @param array<string,mixed>|null $postFields Fields for POST requests
	 * @param bool   $multipart Whether $postFields contains a CURLFile
	 * @return string Response body
	 * @throws MediarelayOpenmageClientException
	 */
	private function request($method, $path, $postFields = null, $multipart = false)
	{
		return $this->requestAbsolute($method, $this->baseUrl.$path, $postFields, $multipart);
	}

	/**
	 * @param string $method
	 * @param string $url
	 * @param array<string,mixed>|null $postFields
	 * @param bool   $multipart
	 * @return string
	 * @throws MediarelayOpenmageClientException
	 */
	private function requestAbsolute($method, $url, $postFields = null, $multipart = false)
	{
		if (!$this->ch) {
			$this->ch = curl_init();
			curl_setopt_array($this->ch, array(
				CURLOPT_COOKIEFILE => '', // enable curl's in-memory cookie engine, nothing persisted to disk
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_SSL_VERIFYPEER => true,
			));

			// Cloudflare Access service token: bypasses the interactive SSO login
			// that would otherwise intercept every request (including this one)
			// before it ever reaches OpenMage. Sent on every request for the
			// lifetime of this handle, not just the login, since Access checks
			// each one independently.
			if ($this->cfAccessClientId !== '' && $this->cfAccessClientSecret !== '') {
				curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
					'CF-Access-Client-Id: '.$this->cfAccessClientId,
					'CF-Access-Client-Secret: '.$this->cfAccessClientSecret,
				));
			}
		}

		curl_setopt($this->ch, CURLOPT_URL, $url);
		curl_setopt($this->ch, CURLOPT_HTTPGET, true); // reset method/body from any previous call
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, null);

		if ($method === 'POST') {
			curl_setopt($this->ch, CURLOPT_POST, true);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $multipart ? $postFields : http_build_query((array) $postFields));
		}

		$response = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($this->ch);

		if ($response === false) {
			dol_syslog('mediarelay: OpenMage request to '.$url.' failed: '.$curlErr, LOG_ERR);
			global $langs;
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorRequestFailed', $curlErr));
		}
		if ($httpCode >= 400) {
			dol_syslog('mediarelay: OpenMage request to '.$url.' returned HTTP '.$httpCode, LOG_ERR);
			global $langs;
			throw new MediarelayOpenmageClientException($langs->trans('MediaRelayErrorHttpCode', $url, (string) $httpCode));
		}

		return $response;
	}

	/**
	 * Destructor: release the curl handle.
	 */
	public function __destruct()
	{
		if ($this->ch) {
			curl_close($this->ch);
		}
	}
}
