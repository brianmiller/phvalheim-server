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

$opt["caption"] = "Clients Data";
$g->set_options($opt);

// params are array(<function-name>,<class-object> or <null-if-global-func>,<continue-default-operation>)
// if you pass last argument as true, functions will act as a data filter, and insert/update will be performed by grid
$e["on_insert"] = array("add_client", null, false);
$e["on_update"] = array("edit_client", null, false);
$e["on_delete"] = array("del_client", null, false);
$g->set_events($e);

function add_client($data)
{
	global $g;
	
	if (empty($data["params"]["name"]))
		phpgrid_error("Name field is required.");

	$check_sql = "SELECT count(*) as c from clients where LOWER(`name`) = '".strtolower($data["params"]["name"])."'";
	$rs = $g->get_one($check_sql);
	
	if ($rs["c"] > 0)
		phpgrid_error("Client already exist in database");

	$g->execute_query("INSERT INTO clients VALUES (null,'{$data["params"]["name"]}','{$data["params"]["gender"]}','{$data["params"]["company"]}')");
}

function edit_client($data)
{
	phpgrid_error("Updation is restricted!");
}

function del_client($data)
{
	/*
	// to debug
	
	ob_start();
	print_r($data);
	$s = ob_get_clean();
	phpgrid_error($s);
	*/
	
	phpgrid_error("Access denied!");
}

$g->table = "clients";
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