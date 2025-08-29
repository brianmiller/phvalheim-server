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
$grid["caption"] = "Employees Grid";
$grid["rowNum"] = 15;
$grid["auto_width"] = false;
$grid["shrink_to_fit"] = true;
$grid["width"] = 2000;
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "hr.employees";
$g->select_command = "SELECT h.*,d.department_name FROM hr.employees h INNER JOIN hr.departments d ON d.department_id = h.department_id";


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
	You must have Oracle Server installed for this demo. Also set database crendentials in this demo.
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>