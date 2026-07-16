<?php
/* Copyright (c) 2026 David IGREJA <info@siladel.fr>
 *
 * MIT License. See the LICENSE file at the root of this module for details.
 */

/**
 * 	\defgroup   mediarelay     Module MediaRelay
 *  \brief      MediaRelay module descriptor.
 *
 *  \file       htdocs/mediarelay/core/modules/modMediarelay.class.php
 *  \ingroup    mediarelay
 *  \brief      Description and activation file for module MediaRelay
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module MediaRelay
 */
class modMediarelay extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 729999; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'mediarelay';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleMediarelayName' not found.
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleMediarelayDesc' not found.
		$this->description = "MediaRelayDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "MediaRelayDescriptionLong";

		// Author
		$this->editor_name = 'SILADEL';
		$this->editor_url = 'https://www.siladel.fr';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0.1';
		

		// Key used in llx_const table to save module status enabled/disabled (where MEDIARELAY is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'fa-satellite-dish';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/mediarelay/css/mediarelay.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/mediarelay/js/mediarelay.js.php',
			),
			// Set here all hooks context managed by module.
			'hooks' => array('productcard', 'globalcard'),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/mediarelay/temp");

		// Config pages. Put here list of php page, stored into mediarelay/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@mediarelay");

		// Dependencies
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("mediarelay@mediarelay");

		// Prerequisites
		$this->phpmin = array(7, 4); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(19, -3); // Minimum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array();
		// Disable CKEditor's Advanced Content Filter globally so <img> (and
		// other tags) inserted through the editor are never stripped client-side.
		$this->const[] = array('FCKEDITOR_ALLOW_ANY_CONTENT', 'chaine', '1', '', 0, 'current');

		if (!isset($conf->mediarelay) || !isset($conf->mediarelay->enabled)) {
			$conf->mediarelay = new stdClass();
			$conf->mediarelay->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		/* BEGIN MODULEBUILDER PERMISSIONS */
		/* END MODULEBUILDER PERMISSIONS */

		// Main menu entries to add
		// None: MediaRelay has no user-facing page, it only wires a hook (see
		// class/actions_mediarelay.class.php) and an admin setup page.
		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/mediarelay/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error occurs when loading module SQL queries
		}

		// Permissions
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from Dolibarr database.
	 *  Data directories are not deleted
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
