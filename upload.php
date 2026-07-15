<?php
/* Endpoint d'upload appelé par CKEditor 4 (plugins uploadimage + filebrowser).
 * Reçoit le fichier et le pousse vers media/wysiwyg/ sur la boutique OpenMage,
 * via une session admin dédiée (voir lib/mediarelay.openmageclient.class.php).
 *
 * Emplacement : custom/mediarelay/upload.php
 */

// Chargement de l'environnement Dolibarr (session, droits, CSRF)
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

// --- Configuration --------------------------------------------------------
// Réglable depuis Accueil > Configuration > Modules > MediaRelay
$REMOTE_ADMIN_URL      = getDolGlobalString('MEDIARELAY_ADMIN_URL');
$REMOTE_ADMIN_USER     = getDolGlobalString('MEDIARELAY_ADMIN_USER');
$REMOTE_ADMIN_PASSWORD = getDolGlobalString('MEDIARELAY_ADMIN_PASSWORD');
$MAX_SIZE              = 10 * 1024 * 1024; // 10 Mo
$ALLOWED_MIMES         = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
// ---------------------------------------------------------------------------

if (empty($REMOTE_ADMIN_URL) || empty($REMOTE_ADMIN_USER) || empty($REMOTE_ADMIN_PASSWORD)) {
	http_response_code(500);
	ckError("Connexion à OpenMage non configurée (Configuration > Modules > MediaRelay)");
}

/**
 * Réponse JSON au format attendu par CKEditor 4 puis arrêt.
 *
 * @param array $data Payload
 * @return void
 */
function ckResponse(array $data)
{
	header('Content-Type: application/json; charset=utf-8');
	print json_encode($data);
	exit;
}

/**
 * Réponse d'erreur CKEditor 4
 *
 * @param string $message Message affiché dans l'éditeur
 * @return void
 */
function ckError($message)
{
	ckResponse(array('uploaded' => 0, 'error' => array('message' => $message)));
}

// --- Contrôles de sécurité -------------------------------------------------

// Utilisateur connecté à Dolibarr obligatoire
if (empty($user) || empty($user->id)) {
	http_response_code(403);
	ckError('Non authentifié');
}

// Jeton CSRF passé en query string par le hook.
// NB : depuis Dolibarr 17, main.inc.php effectue déjà un contrôle CSRF
// automatique sur les POST ; cette vérification explicite reste une
// ceinture-bretelles et couvre les configurations où le contrôle
// automatique est assoupli.
$token = GETPOST('token', 'alpha');
if (empty($token)) {
	http_response_code(403);
	ckError('Jeton CSRF manquant');
}
$valid = ($token === ($_SESSION['newtoken'] ?? '')) || ($token === ($_SESSION['token'] ?? ''));
if (!$valid) {
	http_response_code(403);
	ckError('Jeton CSRF invalide');
}

// CKEditor envoie le fichier dans le champ "upload"
if (empty($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
	ckError('Aucun fichier reçu');
}

$file = $_FILES['upload'];

if ($file['size'] > $MAX_SIZE) {
	ckError('Fichier trop volumineux (max '.($MAX_SIZE / 1024 / 1024).' Mo)');
}

// Vérification du MIME réel (pas celui déclaré par le navigateur)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $ALLOWED_MIMES, true)) {
	ckError('Type de fichier non autorisé ('.$mime.')');
}

// Nom de fichier assaini + unique (OpenMage renomme de toute façon en cas de collision)
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$safeName = dol_sanitizeFileName(pathinfo($file['name'], PATHINFO_FILENAME));
$finalName = $safeName.'-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.substr(md5(uniqid('', true)), 0, 6).'.'.$ext;

// --- Envoi vers OpenMage ------------------------------------------

try {
	$client = new MediarelayOpenmageClient($REMOTE_ADMIN_URL, $REMOTE_ADMIN_USER, $REMOTE_ADMIN_PASSWORD);
	$publicUrl = $client->uploadImage($file['tmp_name'], $finalName, $mime);
} catch (MediarelayOpenmageClientException $e) {
	dol_syslog('mediarelay/upload.php: '.$e->getMessage(), LOG_ERR);
	ckError("Échec de l'envoi vers OpenMage");
}

// --- Réponse ------------------------------------------------------------------

// Cas 1 : upload via boîte de dialogue filebrowser (ancien mécanisme callback JS)
$funcNum = GETPOST('CKEditorFuncNum', 'int');
if ($funcNum) {
	header('Content-Type: text/html; charset=utf-8');
	print '<script>window.parent.CKEDITOR.tools.callFunction('
		.((int) $funcNum).', '.json_encode($publicUrl).', "");</script>';
	exit;
}

// Cas 2 : plugin uploadimage (drag & drop, copier/coller) - réponse JSON
ckResponse(array(
	'uploaded' => 1,
	'fileName' => $finalName,
	'url' => $publicUrl,
));
