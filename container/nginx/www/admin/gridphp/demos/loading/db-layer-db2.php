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
 */
 
/**
 * To install extension refer: http://php.net/manual/en/ibm-db2.installation.php#108477
 */
 
include("../../lib/inc/jqgrid_dist.php");

$db_conf = array();
$db_conf["type"] = "odbc_db2"; // db2 using odbc
$db_conf["server"] = "db2"; // System DSN
$db_conf["user"] = "db2admin";
$db_conf["password"] = "asd";
$db_conf["database"] = "";
	 
// $db_conf = array();
// $db_conf["type"] = "db2"; // for native php driver
// $db_conf["server"] = "localhost";
// $db_conf["user"] = "db2admin";
// $db_conf["password"] = "asd";
// $db_conf["database"] = "";

$g = new jqgrid($db_conf);

// set few params
$grid["caption"] = "Sample DB2 Grid";
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "systools.hmon_atm_info";

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
	You must have DB2 installed for this demo. Also set database crendentials in this demo.
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>