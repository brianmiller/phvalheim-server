<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

$db_conf = array();
$db_conf["type"] = "pdo";
$db_conf["server"] = "firebird:host=localhost;dbname=C:/Data/PHP WORK/Projects/JQGrid/public_html/dev/sampledb/employee.fdb";
$db_conf["user"] = "SYSDBA";
$db_conf["password"] = "masterkey";
$db_conf["database"] = "";

// Use small case field names
define("ADODB_ASSOC_CASE","ADODB_ASSOC_CASE_LOWER");

include("../../lib/inc/jqgrid_dist.php");
$g = new jqgrid($db_conf);

// set few params
$grid["caption"] = "Sample Grid";
$grid["rowNum"] = 10;
$grid["cellEdit"] = true;
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "CUSTOMER";

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
	You must have Firebird installed for this demo. Also set database crendentials in this demo.
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>
