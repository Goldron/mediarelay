<?php
/* Copyright (c) 2026 David IGREJA <info@siladel.fr>
 *
 * MIT License. See the LICENSE file at the root of this module for details.
 */

/**
 * \file    mediarelay/admin/fix_encoding.php
 * \ingroup mediarelay
 * \brief   Detects and repairs double-encoded UTF-8 ("mojibake") in product/service
 *          descriptions - typically leftover from a one-time import out of an older,
 *          non-UTF-8 system, unrelated to MediaRelay's own live code (which never touches
 *          description text, only images - see class/actions_mediarelay.class.php).
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/mediarelay.lib.php';

global $langs, $user, $db;

$langs->loadLangs(array("admin", "mediarelay@mediarelay"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Every (table, column) pair this tool knows how to scan/repair. Table and column names below
// are only ever these hardcoded literals, never user input, so building SQL with them directly
// (not through a bind parameter) is safe.
$targets = array(
	array('table' => 'product', 'column' => 'description', 'labelkey' => 'MediaRelayFixEncodingTargetProduct'),
	array('table' => 'product_lang', 'column' => 'description', 'labelkey' => 'MediaRelayFixEncodingTargetProductLang'),
);

/**
 * Scans one (table, column) pair for rows that look double-encoded (see
 * mediarelayLooksDoubleEncoded()) and returns each one already paired with its computed fix -
 * never a row whose fix doesn't check out as valid UTF-8 (belt-and-suspenders: that function
 * already only returns true for cases the fix handles correctly, this re-checks anyway).
 *
 * @param DoliDB $db
 * @param string $table  'product' or 'product_lang' (see $targets above)
 * @param string $column Always 'description' today, kept as a parameter for future targets
 * @return array<int,array{table:string,column:string,rowid:int,ref:string,label:string,lang:string,before:string,after:string}>
 */
function mediarelayScanTarget($db, $table, $column)
{
	$rows = array();

	if ($table === 'product') {
		$sql = 'SELECT rowid, ref, label, '.$column.' as val';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product';
		$sql .= ' WHERE '.$column.' IS NOT NULL';
	} else {
		$sql = 'SELECT pl.rowid, pl.lang, p.ref, p.label, pl.'.$column.' as val';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_lang as pl';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pl.fk_product';
		$sql .= ' WHERE pl.'.$column.' IS NOT NULL';
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return $rows;
	}

	while ($obj = $db->fetch_object($resql)) {
		if (!mediarelayLooksDoubleEncoded($obj->val)) {
			continue;
		}
		$fixed = mediarelayFixDoubleEncoding($obj->val);
		if (!mb_check_encoding($fixed, 'UTF-8')) {
			continue;
		}
		$rows[] = array(
			'table' => $table,
			'column' => $column,
			'rowid' => (int) $obj->rowid,
			'ref' => isset($obj->ref) ? $obj->ref : '',
			'label' => isset($obj->label) ? $obj->label : '',
			'lang' => isset($obj->lang) ? $obj->lang : '',
			'before' => $obj->val,
			'after' => $fixed,
		);
	}

	return $rows;
}

/*
 * Actions
 */

if ($action == 'apply' && GETPOST('token', 'alpha') === newToken()) {
	$validKeys = array();
	foreach ($targets as $target) {
		$validKeys[$target['table'].':'.$target['column']] = true;
	}

	$fixedCount = 0;
	$skippedCount = 0;

	foreach (GETPOST('fixrow', 'array') as $key) {
		$parts = explode(':', $key, 3);
		if (count($parts) !== 3) {
			continue;
		}
		list($table, $column, $rowid) = $parts;
		$rowid = (int) $rowid;
		if (empty($validKeys[$table.':'.$column]) || $rowid <= 0) {
			continue;
		}

		// Never trust the submitted "after" value: always re-read the current row and
		// re-verify it from scratch, in case it changed (or was already fixed) since the scan.
		$sql = 'SELECT '.$column.' as val FROM '.MAIN_DB_PREFIX.$table.' WHERE rowid = '.$rowid;
		$resql = $db->query($sql);
		if (!$resql || !($obj = $db->fetch_object($resql))) {
			continue;
		}
		if (!mediarelayLooksDoubleEncoded($obj->val)) {
			$skippedCount++;
			continue;
		}
		$fixed = mediarelayFixDoubleEncoding($obj->val);
		if (!mb_check_encoding($fixed, 'UTF-8')) {
			$skippedCount++;
			continue;
		}

		$sqlUpdate = 'UPDATE '.MAIN_DB_PREFIX.$table.' SET '.$column." = '".$db->escape($fixed)."' WHERE rowid = ".$rowid;
		if ($db->query($sqlUpdate)) {
			$fixedCount++;
		}
	}

	if ($fixedCount) {
		setEventMessages($langs->trans('MediaRelayFixEncodingApplied', $fixedCount), null, 'mesgs');
	}
	if ($skippedCount) {
		setEventMessages($langs->trans('MediaRelayFixEncodingSkipped', $skippedCount), null, 'warnings');
	}
	if (!$fixedCount && !$skippedCount) {
		setEventMessages($langs->trans('MediaRelayFixEncodingNothingSelected'), null, 'warnings');
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$candidates = array();
$targetLabels = array();
foreach ($targets as $target) {
	$targetLabels[$target['table']] = $langs->trans($target['labelkey']);
	$candidates = array_merge($candidates, mediarelayScanTarget($db, $target['table'], $target['column']));
}

$form = new Form($db);

$help_url = '';
$page_name = "MediaRelayFixEncoding";

llxHeader('', $langs->trans($page_name), $help_url);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = mediarelayAdminPrepareHead();
print dol_get_fiche_head($head, 'fixencoding', $langs->trans($page_name), -1, "mediarelay@mediarelay");

print '<span class="opacitymedium">'.$langs->trans("MediaRelayFixEncodingIntro").'</span><br><br>';

if (empty($candidates)) {
	print '<div class="ok marginbottomonly">'.$langs->trans("MediaRelayFixEncodingNoIssue").'</div>';
} else {
	print '<div class="warning marginbottomonly">'.$langs->trans("MediaRelayFixEncodingIssuesFound", count($candidates)).'</div>';

	print '<form method="post" action="'.$_SERVER['PHP_SELF'].'" onsubmit="return confirm('.json_encode($langs->trans("MediaRelayFixEncodingConfirm")).');">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="apply">';

	print '<div class="div-table-responsive">';
	print '<table class="liste centpercent">';
	print '<tr class="liste_titre">';
	print '<td class="center"><input type="checkbox" checked onclick="var b=this.form.querySelectorAll(\'input[name=\\\'fixrow[]\\\']\'); for (var i=0;i<b.length;i++) b[i].checked=this.checked;"></td>';
	print '<td>'.$langs->trans("MediaRelayFixEncodingField").'</td>';
	print '<td>'.$langs->trans("MediaRelayFixEncodingRecord").'</td>';
	print '<td>'.$langs->trans("MediaRelayFixEncodingBefore").'</td>';
	print '<td>'.$langs->trans("MediaRelayFixEncodingAfter").'</td>';
	print '</tr>';

	foreach ($candidates as $candidate) {
		$key = $candidate['table'].':'.$candidate['column'].':'.$candidate['rowid'];
		$recordLabel = trim($candidate['ref'].' '.$candidate['label']);
		if ($candidate['lang']) {
			$recordLabel .= ' ['.$candidate['lang'].']';
		}

		print '<tr class="oddeven">';
		print '<td class="center"><input type="checkbox" name="fixrow[]" value="'.dol_escape_htmltag($key).'" checked></td>';
		print '<td>'.dol_escape_htmltag($targetLabels[$candidate['table']]).'</td>';
		print '<td>'.dol_escape_htmltag($recordLabel).'</td>';
		print '<td class="wordbreak">'.dol_escape_htmltag(dol_trunc(strip_tags($candidate['before']), 150)).'</td>';
		print '<td class="wordbreak">'.dol_escape_htmltag(dol_trunc(strip_tags($candidate['after']), 150)).'</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';

	print '<div class="center marginbottomonly">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans("MediaRelayFixEncodingApply").'">';
	print '</div>';

	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
