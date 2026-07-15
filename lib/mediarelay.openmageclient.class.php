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
	 * @var bool Whether the wysiwyg storage session current path has been primed to the media/wysiwyg root
	 */
	private $primed = false;

	/**
	 * Constructor
	 *
	 * @param string $baseUrl  OpenMage store base URL (e.g. https://shop.example.com)
	 * @param string $username Dedicated admin backend username
	 * @param string $password Dedicated admin backend password
	 */
	public function __construct($baseUrl, $username, $password)
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->username = $username;
		$this->password = $password;
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
		$this->ensureLogin();
		$this->primeSession();

		$postFields = array(
			'image' => new CURLFile($tmpPath, $mime, $fileName),
			'form_key' => $this->formKey,
		);

		$response = $this->request('POST', '/admin/cms_wysiwyg_images/upload?type=image', $postFields, true);
		$result = json_decode($response, true);

		if (!is_array($result) || !empty($result['error']) || empty($result['file'])) {
			$message = is_array($result) && !empty($result['error']) ? $result['error'] : 'Unexpected upload response';
			throw new MediarelayOpenmageClientException('OpenMage upload failed: '.$message);
		}

		return $this->baseUrl.'/media/wysiwyg/'.rawurlencode($result['file']);
	}

	/**
	 * List images already present in media/wysiwyg/ (root folder only).
	 *
	 * @return array<int,array{url:string,name:string,thumbnail:string}>
	 * @throws MediarelayOpenmageClientException
	 */
	public function listImages()
	{
		$this->ensureLogin();
		$html = $this->primeSession();

		$files = array();
		if (preg_match_all('/<div class="filecnt".*?<\/div>/s', $html, $blocks)) {
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

				$url = $this->baseUrl.'/media/wysiwyg/'.rawurlencode($name);
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
			throw new MediarelayOpenmageClientException('Unable to find form_key on OpenMage admin login page');
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
			throw new MediarelayOpenmageClientException('OpenMage admin login failed (check MEDIARELAY_ADMIN_USER/PASSWORD)');
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
	 * Point the wysiwyg storage session at the media/wysiwyg root and return its file listing HTML.
	 * Required before uploadAction, which stores into whatever path was last set this way.
	 *
	 * @return string Raw HTML response of the contents action (the file listing)
	 * @throws MediarelayOpenmageClientException
	 */
	private function primeSession()
	{
		$html = $this->request('POST', '/admin/cms_wysiwyg_images/contents?type=image', array('form_key' => $this->formKey));

		$decoded = json_decode($html, true);
		if (is_array($decoded) && !empty($decoded['error'])) {
			throw new MediarelayOpenmageClientException('OpenMage contents call failed: '.$decoded['message']);
		}

		$this->primed = true;

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
			throw new MediarelayOpenmageClientException('OpenMage request failed: '.$curlErr);
		}
		if ($httpCode >= 400) {
			dol_syslog('mediarelay: OpenMage request to '.$url.' returned HTTP '.$httpCode, LOG_ERR);
			throw new MediarelayOpenmageClientException('OpenMage request to '.$url.' returned HTTP '.$httpCode);
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
