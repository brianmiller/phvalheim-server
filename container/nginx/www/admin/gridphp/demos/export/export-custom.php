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
$grid["export"] = array("format"=>"csv", "filename"=>"my-file", "heading"=>"Export to Excel Test", "range" => "filtered");

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
$g->table = "clients";

// params are array(<function-name>,<class-object> or <null-if-global-func>,<continue-default-operation>)
$e["on_export"] = array("custom_export", null, false);
$g->set_events($e);

// custom on_export callback function
function custom_export($param)
{
	$sql = $param["sql"]; // the SQL statement for export
	$grid = $param["grid"]; // the complete grid object reference

	if ($grid->options["export"]["format"] == "xls")
	{
		function xlsBOF(){
			echo pack("ssssss",0x809,0x8,0x0,0x10,0x0,0x0);
			return;
		}

		function xlsEOF(){
			echo pack("ss",0x0A,0x00);
			return;
		}

		function xlsWriteNumber($Row,$Col,$Value){
			echo pack("sssss",0x203,14,$Row,$Col,0x0);
			echo pack("d",$Value);
			return;
		}

		function xlsWriteLabel($Row,$Col,$Value){
			$L= strlen($Value);
			echo pack("ssssss",0x204,8+$L,$Row,$Col,0x0,$L);
			echo $Value;
			return;
		}

		//Query Database
		$rs=$grid->execute_query($sql);

		//Send Header
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate,post-check=0,pre-check=0");
		header("Content-Type: application/force-download");
		header("Content-Type: application/vnd.ms-excel");
		header("Content-Type: application/download");
		header("Content-Disposition: attachment;filename=".$grid->options["export"]["filename"].".xls");
		header("Content-Transfer-Encoding: binary");

		//XLS Data Cell
		xlsBOF();
		if(!empty($grid->options["export"]["heading"])){
			xlsWriteLabel(0,0,$grid->options["export"]["heading"]);
		}

		$col=0;
		$rs_data = $rs->GetRows();
		
		// export col headers
		foreach($rs_data[0] as $k=>$v)
		{
			xlsWriteLabel(2,$col,ucwords($k));
			$col++;
		}

		$total = $rs->RecordCount();
		$xlsRow=3;
		foreach($rs_data as $rec)
		{
			$i=0;
			foreach($rec as $v)
			{
				xlsWriteLabel($xlsRow,$i++,utf8_decode($v));
			}
			$xlsRow++;
		}

		xlsEOF();
		exit();
	}
	else if ($grid->options["export"]["format"] == "csv")
	{
		
		// for big datasets, export without using array to avoid memory leaks
	
		// include db config
		include_once("../../config.php");
				
		$db_conf = array();
		$db_conf["server"] = PHPGRID_DBHOST; // or you mysql ip
		$db_conf["user"] = PHPGRID_DBUSER; // username
		$db_conf["password"] = PHPGRID_DBPASS; // password
		$db_conf["database"] = PHPGRID_DBNAME; // database

		$conn = mysqli_connect($db_conf["server"], $db_conf["user"], $db_conf["password"],$db_conf["database"]);
	
		$fields = array();
		foreach ($grid->options["colModel"] as $c)
		{
			// remove not-to-export columns
			if ($c["export"] === false) continue;
			
			$header[$c["name"]] = $c["title"];
			$fields[] = $c["name"];
		}

		// append WHERE clause if available
		$export_where = $_SESSION["jqgrid_list1_filter"];

		$sql = "SELECT ".implode(", ",$fields)." FROM ".$grid->table. " WHERE 1=1 ".$export_where;
		
		$result = mysqli_query($conn, $sql);
		
		if (strstr($grid->options["export"]["filename"],".csv") === false)
			$grid->options["export"]["filename"] .= ".csv";
							
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment;filename='.$grid->options["export"]["filename"]);		

		$fp = fopen('php://output', 'w');
		
		// push rows header
		fputcsv($fp, $header);

		// push rows
		while ($row = mysqli_fetch_assoc($result))
			fputcsv($fp, $row);
		
		die;
	
	}
	else if ($grid->options["export"]["format"] == "pdf")
	{
		// your custom pdf generation code goes here ...		
	}

}

// Example Export handler if want to redirect using other file
function custom_export_external($param)
{
	$cols_skip = array();
	$titles = array();
	foreach ($grid->options["colModel"] as $c)
	{
		if ($c["export"] === false)
			$cols_skip[] = $c["name"];

		$titles[$c["index"]] = $c["title"];
	}
	
	$_SESSION["phpgrid_sql"]=$sql;
	$_SESSION["phpgrid_filename"]=$grid->options["export"]["filename"];
	$_SESSION["phpgrid_heading"]=$grid->options["export"]["heading"];
	$_SESSION["phpgrid_cols_skip"]=serialize($cols_skip);
	$_SESSION["phpgrid_cols_title"]=serialize($titles);

	// just for example
	header("Location: export-external.php");
	die();	
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