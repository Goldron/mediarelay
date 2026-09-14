<?php
/* Copyright (c) 2026 David IGREJA <info@siladel.fr>
 *
 * MIT License. See the LICENSE file at the root of this module for details.
 */

/**
 * \file    mediarelay/admin/setup.php
 * \ingroup mediarelay
 * \brief   MediaRelay setup page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/mediarelay.lib.php';
require_once '../lib/mediarelay.openmageclient.class.php';

// Translations
$langs->loadLangs(array("admin", "mediarelay@mediarelay"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('mediarelaysetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09'); // Used by actions_setmoduleoptions.inc.php

$setupnotempty = 0;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}

$formSetup = new FormSetup($db);
// $_SERVER['PHP_SELF'] can be empty on some nginx/php-fpm setups (missing PATH_INFO),
// which makes the generated <form action=""> resubmit to the current URL including
// its query string (?action=edit) and shadow the posted action=update. Force it.
$formSetup->formAttributes['action'] = dol_buildpath('/mediarelay/admin/setup.php', 1);

// Base URL of the OpenMage/Magento1 store (e.g. https://shop.example.com)
$item = $formSetup->newItem('MEDIARELAY_ADMIN_URL');
$item->defaultFieldValue = '';
$item->cssClass = 'minwidth500';

// Dedicated OpenMage admin backend account used to drive the WYSIWYG media
// library (media/wysiwyg/) - see cms_wysiwyg_images controller. This is a
// full admin account, not a scoped API user: no REST/SOAP resource in
// OpenMage handles product-less images, only this admin-only controller does.
$item = $formSetup->newItem('MEDIARELAY_ADMIN_USER');
$item->defaultFieldValue = '';
$item->cssClass = 'minwidth300';

$item = $formSetup->newItem('MEDIARELAY_ADMIN_PASSWORD');
$item->defaultFieldValue = '';
$item->cssClass = 'minwidth300';
$item->setAsGenericPassword();

// Subfolder of media/wysiwyg/ where images are stored (created automatically
// if missing). Leave empty to use the media/wysiwyg root directly.
$item = $formSetup->newItem('MEDIARELAY_ADMIN_FOLDER');
$item->defaultFieldValue = 'uploads';
$item->cssClass = 'minwidth200';

// Cloudflare Access service token, only needed when the store's /admin/ is
// gated by Cloudflare Access (Zero Trust): without it, every request is
// intercepted by Access' own SSO login before it ever reaches OpenMage.
$item = $formSetup->newItem('MEDIARELAY_CLOUDFLARE_ENABLED');
$item->defaultFieldValue = '0';
$item->setAsYesNo();

$item = $formSetup->newItem('MEDIARELAY_CLOUDFLARE_CLIENT_ID');
$item->defaultFieldValue = '';
$item->cssClass = 'minwidth300';

$item = $formSetup->newItem('MEDIARELAY_CLOUDFLARE_CLIENT_SECRET');
$item->defaultFieldValue = '';
$item->cssClass = 'minwidth300';
$item->setAsGenericPassword();

$setupnotempty += count($formSetup->items);

/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "MediaRelaySetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = mediarelayAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "mediarelay@mediarelay");

// Setup page goes here
echo '<span class="opacitymedium">'.$langs->trans("MediaRelaySetupPage").'</span><br><br>';

if ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
	// FormSetup's password/genericpassword widget hardcodes required="required"
	// on its <input> with no way to opt out (see generateInputFieldPassword()
	// in html.formsetup.class.php). MEDIARELAY_CLOUDFLARE_CLIENT_SECRET is
	// optional (only used when MEDIARELAY_CLOUDFLARE_ENABLED is on), and that
	// attribute would otherwise block saving the whole page - not just this
	// field - whenever it's left empty.
	?>
	<script nonce="<?php echo getNonce(); ?>">
	document.getElementById('MEDIARELAY_CLOUDFLARE_CLIENT_SECRET')?.removeAttribute('required');
	</script>
	<?php
} elseif (!empty($formSetup->items)) {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
	if (getDolGlobalString('MEDIARELAY_ADMIN_URL') && getDolGlobalString('MEDIARELAY_ADMIN_USER') && getDolGlobalString('MEDIARELAY_ADMIN_PASSWORD')) {
		print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=testconnection&token='.newToken().'">'.$langs->trans("TestConnection").'</a>';
	}
	print '</div>';

	// Test the connection with the currently saved settings (login, then
	// folder creation/priming) - same code path a real upload goes through,
	// with or without Cloudflare Access depending on MEDIARELAY_CLOUDFLARE_ENABLED.
	if ($action == 'testconnection') {
		print '<br>';
		$cfEnabled = getDolGlobalInt('MEDIARELAY_CLOUDFLARE_ENABLED');
		try {
			$client = new MediarelayOpenmageClient(
				getDolGlobalString('MEDIARELAY_ADMIN_URL'),
				getDolGlobalString('MEDIARELAY_ADMIN_USER'),
				getDolGlobalString('MEDIARELAY_ADMIN_PASSWORD'),
				getDolGlobalString('MEDIARELAY_ADMIN_FOLDER'),
				$cfEnabled ? getDolGlobalString('MEDIARELAY_CLOUDFLARE_CLIENT_ID') : '',
				$cfEnabled ? getDolGlobalString('MEDIARELAY_CLOUDFLARE_CLIENT_SECRET') : ''
			);
			$client->testConnection();

			// Cloudflare Access status is appended as plain concatenation, not
			// as a %s param: trans() already runs its whole result (including
			// substituted params) through htmlentities(), so feeding it the
			// result of another trans() call (Yes/No) would encode that inner
			// result a second time (e.g. accented chars turning into literal
			// "&eacute;" instead of being decoded by the browser).
			print img_picto('', 'info').' ';
			print '<span class="ok">'.$langs->trans("MediaRelayTestConnectionOK", getDolGlobalString('MEDIARELAY_ADMIN_USER')).' '.$langs->trans("MediaRelayCloudflareAccessStatus").' '.($cfEnabled ? $langs->trans("Yes") : $langs->trans("No")).'</span>';
		} catch (MediarelayOpenmageClientException $e) {
			// Same reason: $e->getMessage() already went through trans() once
			// (inside the client class), so it must be concatenated here, not
			// passed as a %s param to another trans() call.
			print img_picto('', 'error').' ';
			print '<span class="error">'.$langs->trans("MediaRelayTestConnectionKOPrefix").' '.$e->getMessage().'</span>';
		}
		print '<br>';
	}
} else {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
