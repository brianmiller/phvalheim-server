<?php

// If you define the constant K_TCPDF_EXTERNAL_CONFIG, the following settings will be ignored.

if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {

	// DOCUMENT_ROOT fix for IIS Webserver
	if ((!isset($_SERVER['DOCUMENT_ROOT'])) OR (empty($_SERVER['DOCUMENT_ROOT']))) {
		if(isset($_SERVER['SCRIPT_FILENAME'])) {
			$_SERVER['DOCUMENT_ROOT'] = str_replace( '\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0-strlen($_SERVER['PHP_SELF'])));
		} elseif(isset($_SERVER['PATH_TRANSLATED'])) {
			$_SERVER['DOCUMENT_ROOT'] = str_replace( '\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0-strlen($_SERVER['PHP_SELF'])));
		}	else {
			// define here your DOCUMENT_ROOT path if the previous fails
			$_SERVER['DOCUMENT_ROOT'] = '/var/www';
		}
	}

	// Automatic calculation for the following K_PATH_MAIN constant
	$k_path_main = str_replace( '\\', '/', realpath(substr(dirname(__FILE__), 0, 0-strlen('config'))));
	if (substr($k_path_main, -1) != '/') {
		$k_path_main .= '/';
	}

	/**
	 * Installation path (/var/www/tcpdf/).
	 * By default it is automatically calculated but you can also set it as a fixed string to improve performances.
	 */
	define ('K_PATH_MAIN', $k_path_main);

	// Automatic calculation for the following K_PATH_URL constant
	$k_path_url = $k_path_main; // default value for console mode
	if (isset($_SERVER['HTTP_HOST']) AND (!empty($_SERVER['HTTP_HOST']))) {
		if(isset($_SERVER['HTTPS']) AND (!empty($_SERVER['HTTPS'])) AND strtolower($_SERVER['HTTPS'])!='off') {
			$k_path_url = 'https://';
		} else {
			$k_path_url = 'http://';
		}
		$k_path_url .= $_SERVER['HTTP_HOST'];
		$k_path_url .= str_replace( '\\', '/', substr(K_PATH_MAIN, (strlen($_SERVER['DOCUMENT_ROOT']) - 1)));
	}

	/**
	 * URL path to tcpdf installation folder (http://localhost/tcpdf/).
	 * By default it is automatically calculated but you can also set it as a fixed string to improve performances.
	 */
	define ('K_PATH_URL', $k_path_url);

	/**
	 * path for PDF fonts
	 * use K_PATH_MAIN.'fonts/old/' for old non-UTF8 fonts
	 */
	define ('K_PATH_FONTS', K_PATH_MAIN.'fonts/');

	/**
	 * cache directory for temporary files (full path)
	 */
	define ('K_PATH_CACHE', K_PATH_MAIN.'cache/');

	/**
	 * cache directory for temporary files (url path)
	 */
	define ('K_PATH_URL_CACHE', K_PATH_URL.'cache/');

	/**
	 *images directory
	 */
	define ('K_PATH_IMAGES', K_PATH_MAIN.'images/');

	/**
	 * blank image
	 */
	define ('K_BLANK_IMAGE', K_PATH_IMAGES.'_blank.png');

	/**
	 * page format
	 */
//	 define ('PDF_PAGE_FORMAT', 'A4');

	/**
	 * page orientation (P=portrait, L=landscape)
	 */
//	define ('PDF_PAGE_ORIENTATION', 'P');
	/**
	 * document creator
	 */
//	define ('PDF_CREATOR', 'jqGrid');

	/**
	 * document author
	 */
//	define ('PDF_AUTHOR', 'jqGrid');

	/**
	 * document title
	 */
//	define ('PDF_TITLE', 'jqGrid Table');

	/**
	 * document subject
	 */
//	define ('PDF_SUBJECT', 'jqGrid Table');

	/**
	 * document keywords
	 */

//	define ('PDF_KEYWORDS', 'pdf, table, data');
	/**
	 * header title
	 */

//	define ('PDF_HEADER_TITLE', 'jqGrid table');

	/**
	 * header description string
	 */
//	define ('PDF_HEADER_STRING', "by Trirand Inc - www.trirand.net");

	/**
	 * image logo
	 */

//	define ('PDF_HEADER_LOGO', 'logo.gif');

	/**
	 * header logo image width in pdf unit
	 */
//	define ('PDF_HEADER_LOGO_WIDTH', 35);
	/**
	 * Grid header height in pdf unit or 0
	 */
//	define ('PDF_HEADER_GRID_HEIGHT', 6);

	/**
	 * grid data height row in unit or 0
	 */
//	define ('PDF_DATA_GRID_HEIGHT', 5);

//	define ('PDF_GRID_HEAD_COLOR', "#dfeffc");
//	define ('PDF_GRID_HEAD_TEXT_COLOR', "#2e6e9e");
//	define ('PDF_GRID_DRAW_COLOR', "#5c9ccc");

//	define ('PDF_GRID_ROW_COLOR', "#ffffff");
//	define ('PDF_GRID_ROW_TEXT_COLOR', "#000000");

	/**
	 * define alternate rows
	 */
//	define ('PDF_ALTERNATE_GRID_ROWS', false);

	/**
	 *  document unit of measure [pt=point, mm=millimeter, cm=centimeter, in=inch]
	 */
//	define ('PDF_UNIT', 'mm');
	
	/**
	 * enable/disable header
	 */
	
//	define ('PDF_HEADER', true);

	/**
	 * disable/enable footer
	 */
//	define ('PDF_FOOTER', true);

	/**
	 * header margin in pdf unit
	 */
//	define ('PDF_MARGIN_HEADER', 5);

	/**
	 * footer margin in pdf unit
	 */
//	define ('PDF_MARGIN_FOOTER', 10);

	/**
	 * top margin in pdf unit
	 */
//	define ('PDF_MARGIN_TOP', 27);

	/**
	 * bottom margin in pdf unit
	 */
//	define ('PDF_MARGIN_BOTTOM', 25);

	/**
	 * left margin in pdf unit
	 */
//	define ('PDF_MARGIN_LEFT', 12);

	/**
	 * right margin in pdf unit
	 */
//	define ('PDF_MARGIN_RIGHT', 12);

	/**
	 * default main font name
	 */
//	define ('PDF_FONT_NAME_MAIN', 'helvetica');

	/**
	 * default main font size
	 */
//	define ('PDF_FONT_SIZE_MAIN', 10);

	/**
	 * default data font name
	 */
//	define ('PDF_FONT_NAME_DATA', 'helvetica');

	/**
	 * default data font size
	 */
//	define ('PDF_FONT_SIZE_DATA', 8);

	/**
	 * default monospaced font name
	 */
//	define ('PDF_FONT_MONOSPACED', 'courier');

	/**
	 * ratio used to adjust the conversion of pixels to user units
	 */
//	define ('PDF_IMAGE_SCALE_RATIO', 1.25);

	/**
	 * magnification factor for titles
	 */
	define('HEAD_MAGNIFICATION', 1.1);

	/**
	 * height of cell repect font height
	 */
	define('K_CELL_HEIGHT_RATIO', 1.25);

	/**
	 * title magnification respect main font size
	 */
	define('K_TITLE_MAGNIFICATION', 1.3);

	/**
	 * reduction factor for small font
	 */
	define('K_SMALL_RATIO', 2/3);

	/**
	 * set to true to enable the special procedure used to avoid the overlappind of symbols on Thai language
	 */
	define('K_THAI_TOPCHARS', true);

	/**
	 * if true allows to call TCPDF methods using HTML syntax
	 * IMPORTANT: For security reason, disable this feature if you are printing user HTML content.
	 */
	define('K_TCPDF_CALLS_IN_HTML', true);
}

//============================================================+
// END OF FILE
//============================================================+
