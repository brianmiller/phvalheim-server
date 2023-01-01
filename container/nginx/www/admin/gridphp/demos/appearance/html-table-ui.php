<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

include_once("../../config.php");

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

$grid["caption"] = "Clients";
$grid["height"] = "";
$g->set_options($grid);

$act["autofilter"] = false;
$act["rowactions"] = false;
$g->set_actions($act);

$g->table = "clients";

$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>	
</head>
<body>
    <style>
	.ui-jqgrid-pager,.ui-jqgrid-toppager, .HeaderButton, .ui-jqgrid-hbox { display: none; }
	.ui-jqgrid {box-shadow:none;}
	.ui-jqgrid .ui-jqgrid-titlebar {padding-left:5px;}
	.ui-alt-rows {background-color:#ececec; }
	.ui-jqgrid tr.jqgrow td {border:0px;}
	.ui-jqdialog .ui-jqdialog-titlebar-close span { padding-top: 20px }
	.ui-jqdialog { background-color: #fefefe; }
	</style>

	<div>
	<?php echo $out?>
	</div>
</body>
</html>
