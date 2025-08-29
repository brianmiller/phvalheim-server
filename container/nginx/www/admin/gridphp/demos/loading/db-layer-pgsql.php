<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

$db_conf = array();
$db_conf["type"] = "postgres"; // mysql,oci8(for oracle),mssql,postgres,sybase
$db_conf["server"] = "localhost";
$db_conf["user"] = "postgres";
$db_conf["password"] = "a";
$db_conf["database"] = "postgres";
		 
// $db_conf = array();
// $db_conf["type"] = "pdo";
// $db_conf["server"] = "pgsql:host=localhost"; 
// $db_conf["user"] = "postgres"; // username
// $db_conf["password"] = "a"; // password
// $db_conf["database"] = "test"; // database

include("../../lib/inc/jqgrid_dist.php");
$g = new jqgrid($db_conf);

// set few params
$grid["caption"] = "Sample Grid";
$grid["rowNum"] = 5;
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "pg_tables";

// render grid
$out = $g->render("listpg1");
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
	You must have PostgreSQL installed for this demo. Also set database crendentials in this demo.
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>