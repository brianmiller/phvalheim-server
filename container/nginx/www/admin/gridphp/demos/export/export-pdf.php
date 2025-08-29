<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

// include db config
include_once("../../config.php");

// include and create object
include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array(
					"type" 		=> PHPGRID_DBTYPE,
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

// mragin for exported pdf
define("PDF_MARGIN_LEFT",5);
define("PDF_MARGIN_RIGHT",5);
define("PDF_MARGIN_TOP",5);
define("PDF_MARGIN_BOTTOM",15);

$g = new jqgrid($db_conf);
$grid = array();
$grid["caption"] = "Client Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["rowNum"] = 100;
$grid["rowList"] = array(100,200,500);
// Predefined standard page formats: http://www.tcexam.org/doc/code/classTCPDF.html#a087d4df77e60b7054e97804069ed32c5
// Orientation: landscape, portrait
$grid["export"] = array("format"=>"pdf", "filename"=>"my-file", "heading"=>"Invoice Details", "orientation"=>"landscape", "paper"=>"a4");

// equally distribute column width in export (pdf)
$grid["export"]["colwidth"] = "equal";

// Setting RTL will export pdf as RTL also
// $grid["direction"] = "rtl";

// export filtered data or all data
$grid["export"]["range"] = "filtered"; // or "all"
$grid["export"]["paged"] = "1";
$g->set_options($grid);

// params are array(<function-name>,<class-object> or <null-if-global-func>)
$e["on_render_pdf"] = array("set_pdf_format", null);
$g->set_events($e);

function set_pdf_format($arr)
{
	$pdf = $arr["pdf"];
	$data = $arr["data"];

	// enable utf8 font
	$pdf->SetFont('cid0jp', '', 11);
	$pdf->SetHeaderCellsFillColor(30,70,99);
    $pdf->SetHeaderCellsFontColor(255,255,255);

	/*
		PDF format customization API available here
		-------------------------------------------
		http://www.tcpdf.org/examples.php
		http://www.tcpdf.org/doc/code/classTCPDF.html

		More Custom Addons API (see inc/tcpdf/class.TCPDF.EasyTable.php & jqgrid_dist.php)
		----------------------------------------------------------------
		public function SetCellMinimumHeight($height)
		public function SetCellFixedHeight($height)
		public function SetHeaderCellFixedHeight($height)
		public function SetTableHeaderPerPage($var)
		public function SetTableHeaderFirstTablePerPageOnly($var)
		public function SetCellAlignment($ArrayCellAlignment)
		public function SetCellWidths($ArrayCellWidths)
		public function SetCellFillStyle($int)
		public function SetFillImageCell($fill)
		public function SetHCellSpace($var)
		public function SetVCellSpace($var)
		public function SetHeaderCellsFillColor($R,$G,$B)
		public function SetTableRowFillColors(Array $colorsArray)
		public function SetHeaderCellsFontColor($R,$G,$B)
		public function SetHeaderCellsFontStyle($var)
		public function SetCellFontColor($R,$G,$B)
		public function SetFooterExclusionZone($float)
		public function SetTableX($x)
		public function SetTableY($y)
		public function Header()
		public function Footer()
	*/
}


$g->set_actions(array(
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					)
				);

// this db table will be used for add,edit,delete
$g->table = "clients";


$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "client_id";
$col["width"] = "30";
$col["export"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Company";
$col["name"] = "company";
$col["width"] = "100";
$col["editable"] = true;
$col["template"] = "<img style='padding-top: 4px;' height=15 src='https://ssl.gstatic.com/ui/v1/icons/mail/rfr/logo_gmail_lockup_default_1x_r5.png'>";
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list_export_pdf");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>

	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>
	<div style="margin:10px">
	<br>
	<?php echo $out?>
	</div>
</body>
</html>
