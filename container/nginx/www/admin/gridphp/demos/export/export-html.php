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

$grid["caption"] = "Customer Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["rowNum"] = 100; // allow you to multi-select through checkboxes
$grid["rowList"] = array(100,200,500);

$grid["export"] = array("format"=>"html");
$grid["export"]["range"] = "filtered"; // or "all"
$grid["export"]["paged"] = "1";
$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>false, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"export" => true
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "customers";

// callback handler to show manual html export
// $e["on_export_data"] = array("custom_export", null, false);
// $g->set_events($e);

// custom on_export callback function
function custom_export($param)
{
	$arr = $param["data"];
	$grid = $param["grid"];

	$style = 'style="border:0px solid #ccc; font-family:arial; font-size:13px"';
	$hstyle = 'style="font-family:arial; font-size:35px; margin:10px 0px"';

	echo "<h1 $hstyle>".$grid->options["export"]["heading"]."</h1>";
	echo "<table cellpadding='4'>";
	foreach ($arr as $key => $value)
	{
		if ($key == 0)
			$row_style = 'style="background-color:#d7dce2;font-weight:bold"';
		else if ($key % 2 == 0)
			$row_style = 'style="background-color:#fff;"';
		else
			$row_style = 'style="background-color:#f9f9f9;"';

		echo("<tr $row_style>");
		echo("<td $style>");
		echo(implode("</td><td $style>", $value));
		echo("</td>");
		echo("</tr>");
	}
	echo "</table>";
	die;
}

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list_export");
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