<?php
/* Copyright (c) 2026 David IGREJA <info@siladel.fr>
 *
 * MIT License. See the LICENSE file at the root of this module for details.
 */

/**
 * \file    mediarelay/lib/mediarelay.lib.php
 * \ingroup mediarelay
 * \brief   Library files with common functions for MediaRelay
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function mediarelayAdminPrepareHead()
{
	global $langs;

	$langs->load("mediarelay@mediarelay");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/mediarelay/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/mediarelay/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	return $head;
}
