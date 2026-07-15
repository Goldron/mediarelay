<?php
/* Copyright (C) 2026 SuperAdmin <info@siladel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    mediarelay/class/actions_mediarelay.class.php
 * \ingroup mediarelay
 * \brief   Hook class for module MediaRelay.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Class ActionsMediarelay
 *
 * Hooks for module MediaRelay.
 */
class ActionsMediarelay extends CommonHookActions
{
	/**
	 * Executed on every page footer (called from llxFooter()).
	 * Used here to reconfigure the CKEditor instance of the product description
	 * field on the product card, so it gets an Image button wired to our
	 * upload.php relay (Dolibarr's own 'dolibarr_details' toolbar has no
	 * Image button and no upload URL configured on purpose).
	 *
	 * @param array<string,mixed> $parameters   Hook parameters
	 * @param ?object              $object       Current object (unused here)
	 * @param string               $action       Current action (unused here)
	 * @param HookManager          $hookmanager  Hook manager
	 * @return int                              0 <= OK, >0 <= KO
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		if (!$this->isContext($parameters, 'productcard')) {
			return 0;
		}

		if (!$user->hasRight('produit', 'creer') && !$user->hasRight('service', 'creer')) {
			return 0;
		}

		$uploadUrl = dol_buildpath('/custom/mediarelay/upload.php?token='.newToken(), 1);
		$browseUrl = dol_buildpath('/custom/mediarelay/browser.php?token='.newToken(), 1);

		?>
		<script nonce="<?php echo getNonce(); ?>">
		jQuery(document).ready(function() {
			if (typeof CKEDITOR === 'undefined') {
				return;
			}
			var mediarelayUploadUrl = <?php echo json_encode($uploadUrl); ?>;
			var mediarelayBrowseUrl = <?php echo json_encode($browseUrl); ?>;
			var mediarelayPatched = {};

			CKEDITOR.on('instanceReady', function(ev) {
				if (ev.editor.name !== 'desc' || mediarelayPatched[ev.editor.name]) {
					return;
				}
				mediarelayPatched[ev.editor.name] = true;

				var oldConfig = ev.editor.config;
				var newToolbar = [
					['Maximize'],
					['SpellChecker', 'Scayt'],
					['Format', 'FontSize'],
					['Bold', 'Italic', 'Underline', 'Strike', '-', 'TextColor', 'RemoveFormat'],
					['NumberedList', 'BulletedList', 'Outdent', 'Indent'],
					['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
					['Link', 'Unlink', 'SpecialChar'],
					['Image'],
					['Source']
				];

				ev.editor.destroy(false);
				CKEDITOR.replace('desc', $.extend({}, oldConfig, {
					toolbar: newToolbar,
					// "Parcourir" tab in the Image dialog: now lists files from
					// our remote server instead of Dolibarr's local ECM browser.
					filebrowserImageBrowseUrl: mediarelayBrowseUrl,
					// "Upload" tab in the Image dialog: relays the file to our
					// remote server.
					filebrowserImageUploadUrl: mediarelayUploadUrl,
					filebrowserUploadUrl: mediarelayUploadUrl
				}));
			});
		});
		</script>
		<?php

		return 0;
	}
}
