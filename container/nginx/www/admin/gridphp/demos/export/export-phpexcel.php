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

$g = new jqgrid($db_conf);

$grid["caption"] = "Clients"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes

// export to excel parameters - range could be "all" or "filtered"
$grid["export"] = array("format"=>"xls", "filename"=>"my-file", "heading"=>"Export to Excel Test", "range" => "filtered");

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "invheader";
		
// customize phpexcel settings
$e["on_render_excel"] = array("custom_export", null);
$g->set_events($e);

// custom on_export callback function
function custom_export($param)
{
	$objPHPExcel = $param["phpexcel"];
	$arr = $param["data"];

	// column formatting using phpexcel 
	for($r=1;$r<count($arr);$r++)
	{
		$cell = $r+2;
		$data = 'Client: '.$arr[$r]["client_id"];
		
		// making hyperlink (clientid : column C)
		$objPHPExcel->setActiveSheetIndex(0)
					->getCell("C$cell")
					->getHyperlink()
					->setUrl('https://www.google.com/search?q='.$data);
		
		// format column D as decimal 0.00
		$objPHPExcel->setActiveSheetIndex(0)->getStyle("D$r")->getNumberFormat()->setFormatCode('0.00'); 
					
		$objPHPExcel->setActiveSheetIndex(0)->setCellValue("C$cell", $data);
	}
}

function custom_header($param)
{
	global $g;
	$objPHPExcel = &$param["phpexcel"];
	
	// clear sheet
	$objPHPExcel = new PHPExcel();

	$arr = $param["data"];

	// set title
	$objPHPExcel->getActiveSheet()->setTitle($g->options["export"]["heading"]);

	// set heading in A1 and bold
	$objPHPExcel->getActiveSheet()->setCellValue("A1",$g->options["export"]["heading"]);
	$objPHPExcel->getActiveSheet()->getStyle("A1")->getFont()->setBold(true);

	// put array from a2
	$objPHPExcel->getActiveSheet()->fromArray($arr, NULL, "A2");

	// date formatting in excel 2007 +
	$excelCol = 'A';
	foreach($arr[0] as $k => $v)
	{
		if ($g->get_column($k,"formatter") == "date")
		{
			for($r=1;$r<count($arr);$r++)
			{			
				$date = $arr[$r][$k];
				if (!empty($date))
				{

					$objPHPExcel->getActiveSheet()
					->setCellValue($excelCol.($r+2), PHPExcel_Shared_Date::PHPToExcel( $date ));
					
					$objPHPExcel->getActiveSheet()
					->getStyle($excelCol.($r+2))
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_DATE_YYYYMMDD2);
				}
			}
		}
		$excelCol++;
	}
}

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
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
	<?php echo $out?>
	</div>
</body>
</html>