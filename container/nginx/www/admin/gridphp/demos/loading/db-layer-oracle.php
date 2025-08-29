<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

/**
 * To support non-mysql databases (even mysql), see adodb lib documentation below:
 * http://phplens.com/lens/adodb/docs-adodb.htm#connect_ex
 * http://phplens.com/lens/adodb/docs-adodb.htm#drivers
 * 
 * For oracle, extension of oci8 should be enabled in php.ini
 */
 
$db_conf = array();
$db_conf["type"] = "oci8"; // mysql,oci8(for oracle),mssql,postgres,sybase
$db_conf["server"] = "127.0.0.1:1521";
$db_conf["user"] = "system";
$db_conf["password"] = "asd";
$db_conf["database"] = "xe";
		 
include("../../lib/inc/jqgrid_dist.php");
$g = new jqgrid($db_conf);

// set few params
$grid["caption"] = "Sample Grid";
$grid["rowNum"] = 15;
$g->set_options($grid);
$g->set_actions(array("inlineadd"=>true,
						"export_pdf"=>true, // export pdf button
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
						));

						
$g->table = "cat";

$col = array();
$col["title"] = "Table"; // caption of column
$col["name"] = "TABLE_NAME"; 
$col["search"] = true;
$col["editable"] = true;
$cols[] = $col;		
		
$col = array();
$col["title"] = "Type";
$col["name"] = "TABLE_TYPE";
$col["search"] = true;
$col["editable"] = true;
$cols[] = $col;

$g->set_columns($cols);

// render grid
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
	Requirements: You must edit this file to set your database crendentials to run this demo.
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>