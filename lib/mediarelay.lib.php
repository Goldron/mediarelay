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

	$head[$h][0] = dol_buildpath("/mediarelay/admin/fix_encoding.php", 1);
	$head[$h][1] = $langs->trans("MediaRelayFixEncoding");
	$head[$h][2] = 'fixencoding';
	$h++;

	$head[$h][0] = dol_buildpath("/mediarelay/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * True if $value is valid, well-formed text where every non-ASCII character sits in the
 * U+0080-U+00FF range (the Latin-1 Supplement block) AND it contains at least one of the
 * well-known French "mojibake" bigrams (e.g. "Ã©" for "é"). That combination is the fingerprint
 * of one very specific, common bug: a UTF-8 string whose bytes got misread as ISO-8859-1/
 * Windows-1252 and were then re-encoded to UTF-8 on top of that - typically a leftover from a
 * one-time import out of an older system that used a different WYSIWYG editor/charset (e.g.
 * TinyMCE-era content later migrated into Dolibarr's CKEditor-based description field).
 *
 * Deliberately conservative: any character outside U+0080-U+00FF (a real accented/special
 * Unicode character beyond Latin-1, an emoji, CJK, etc.) makes this return false, even if the
 * string also contains a mojibake bigram elsewhere - safer to leave an ambiguous string alone
 * than to risk mangling genuinely correct content next to it.
 *
 * @param ?string $value Raw column value, as read from the database
 * @return bool
 */
function mediarelayLooksDoubleEncoded($value)
{
	if ($value === null || $value === '') {
		return false;
	}

	// Every byte must be plain ASCII, or a well-formed 2-byte UTF-8 sequence with lead byte
	// 0xC2/0xC3 (i.e. the UTF-8 encoding of a codepoint in U+0080-U+00FF) - exactly the range a
	// misread-as-Latin-1-or-CP1252 byte re-encoded into UTF-8 can produce.
	if (!preg_match('/^(?:[\x00-\x7F]|[\xC2\xC3][\x80-\xBF])*$/', $value)) {
		return false;
	}

	$signatures = array(
		'Ã©', 'Ã¨', 'Ã ', 'Ãª', 'Ã®', 'Ã´', 'Ã»', 'Ã§', 'Ã¢', 'Ã¹', 'Ã¯', 'Ã¼',
		'Ã‰', 'Ã€', 'Ã‡',
		'Â°', 'Â«', 'Â»', 'Â ',
	);
	foreach ($signatures as $signature) {
		if (mb_strpos($value, $signature) !== false) {
			return true;
		}
	}

	return false;
}

/**
 * Reverses the exact corruption mediarelayLooksDoubleEncoded() detects: re-maps every character
 * of $value (all guaranteed <= U+00FF by that check) back to the single byte it came from. Since
 * that byte sequence is precisely the original, correctly-encoded UTF-8 text, the result is
 * already valid UTF-8 on its own - no further conversion needed.
 *
 * Only ever call this after mediarelayLooksDoubleEncoded() returned true for the same value.
 *
 * @param string $value
 * @return string
 */
function mediarelayFixDoubleEncoding($value)
{
	return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
}
