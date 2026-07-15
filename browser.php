<?php
/* Popup de navigation dans les images déjà présentes dans media/wysiwyg/
 * sur la boutique OpenMage. Ouverte par CKEditor 4 comme bouton "Parcourir"
 * du dialogue Image (filebrowserImageBrowseUrl, voir class/actions_mediarelay.class.php).
 *
 * Emplacement : custom/mediarelay/browser.php
 */

// Chargement de l'environnement Dolibarr (session, droits)
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	die('Include of main.inc.php fails');
}

require_once __DIR__.'/lib/mediarelay.openmageclient.class.php';

global $langs, $user;

$langs->loadLangs(array("mediarelay@mediarelay"));

// --- Configuration --------------------------------------------------------
$REMOTE_ADMIN_URL      = getDolGlobalString('MEDIARELAY_ADMIN_URL');
$REMOTE_ADMIN_USER     = getDolGlobalString('MEDIARELAY_ADMIN_USER');
$REMOTE_ADMIN_PASSWORD = getDolGlobalString('MEDIARELAY_ADMIN_PASSWORD');
// ---------------------------------------------------------------------------

// --- Contrôles de sécurité -------------------------------------------------

// Utilisateur connecté à Dolibarr obligatoire
if (empty($user) || empty($user->id)) {
	http_response_code(403);
	die('Non authentifié');
}

// Paramètres transmis par CKEditor lors de l'ouverture du popup
$funcNum = GETPOST('CKEditorFuncNum', 'int');

$files = array();
if (!empty($REMOTE_ADMIN_URL) && !empty($REMOTE_ADMIN_USER) && !empty($REMOTE_ADMIN_PASSWORD)) {
	try {
		$client = new MediarelayOpenmageClient($REMOTE_ADMIN_URL, $REMOTE_ADMIN_USER, $REMOTE_ADMIN_PASSWORD);
		$files = $client->listImages();
	} catch (MediarelayOpenmageClientException $e) {
		dol_syslog('mediarelay/browser.php: '.$e->getMessage(), LOG_ERR);
		$files = array();
	}
}

/*
 * View
 */

top_httphead();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo dol_escape_htmltag($langs->trans("MediaRelayBrowserTitle")); ?></title>
<style>
	body { font-family: sans-serif; margin: 0; padding: 12px; }
	.mediarelay-grid { display: flex; flex-wrap: wrap; gap: 10px; }
	.mediarelay-item { width: 120px; cursor: pointer; text-align: center; border: 1px solid #ddd; border-radius: 4px; padding: 5px; background: none; }
	.mediarelay-item:hover { border-color: #999; background: #f5f5f5; }
	.mediarelay-item img { max-width: 100%; max-height: 90px; display: block; margin: 0 auto 4px; }
	.mediarelay-item span { display: block; font-size: 11px; word-break: break-all; }
	.mediarelay-empty { color: #999; }
</style>
</head>
<body>
<?php if (empty($REMOTE_ADMIN_URL) || empty($REMOTE_ADMIN_USER) || empty($REMOTE_ADMIN_PASSWORD)) { ?>
	<p class="mediarelay-empty"><?php echo dol_escape_htmltag($langs->trans("MediaRelayListNotConfigured")); ?></p>
<?php } elseif (empty($files)) { ?>
	<p class="mediarelay-empty"><?php echo dol_escape_htmltag($langs->trans("MediaRelayNoFile")); ?></p>
<?php } else { ?>
	<div class="mediarelay-grid">
	<?php foreach ($files as $file) { ?>
		<button type="button" class="mediarelay-item" onclick="mediarelaySelect(<?php echo dol_escape_htmltag(json_encode($file['url'])); ?>)">
			<img src="<?php echo dol_escape_htmltag($file['thumbnail']); ?>" alt="">
			<span><?php echo dol_escape_htmltag($file['name']); ?></span>
		</button>
	<?php } ?>
	</div>
<?php } ?>
<script nonce="<?php echo getNonce(); ?>">
var mediarelayFuncNum = <?php echo json_encode($funcNum); ?>;
function mediarelaySelect(url) {
	if (window.opener && window.opener.CKEDITOR) {
		window.opener.CKEDITOR.tools.callFunction(mediarelayFuncNum, url);
	}
	window.close();
}
</script>
</body>
</html>
