<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

include_once("../../config.php");

$db_conf = array();
$db_conf["type"] = "mysqli";
$db_conf["server"] = PHPGRID_DBHOST; 
$db_conf["user"] = PHPGRID_DBUSER; // username
$db_conf["password"] = PHPGRID_DBPASS; // password
$db_conf["database"] = PHPGRID_DBNAME; // database

include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

$g = new jqgrid($db_conf);
$grid["caption"] = "Sample Tree Grid";
$grid["hidefirst"] = true;
$grid["sortname"] = 'reports_to';
$grid["rowNum"] = 99999;
$grid["edit_options"]["beforeShowForm"] = "function(form){ 

												// don't allow parent change option, 'list1' is grid-id and 'reports_to' is parent field
												if( !$('#list1').getRowData($('#list1').getGridParam('selrow')).reports_to ) 
													$('#reports_to',form).replaceWith(' - '); 

											}";
/*
column: how hierarchical data in this column
id: unique identifier of column
parent: parent id of node
loaded: open tree by default 
*/

$grid["treeGrid"]=true;
$grid["sanitize"]=false;
$grid["treeConfig"] = array('id'=>'employee_id', 'parent'=>'reports_to', 'loaded'=>true, 'column'=>'last_name');
$g->set_options($grid);

$g->select_command = "select employee_id,last_name,first_name,country,city,postal_code,home_phone,extension,reports_to from employees";
$g->table = "employees";

$col = array();
$col["title"] = "Reports To";
$col["name"] = "reports_to";
$col["edittype"] = "lookup";
$col["editable"] = true;
$col["align"] = "left";
$col["editoptions"] = array("table"=>"employees", "id"=>"employee_id", "label"=>"concat(last_name,' ',first_name)");
$cols[] = $col;

$g->set_columns($cols,true);

$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/material/jquery-ui.custom.css"></link>	
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
