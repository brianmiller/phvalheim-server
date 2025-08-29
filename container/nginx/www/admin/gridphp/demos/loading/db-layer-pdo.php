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

$db_conf = array();
$db_conf["type"] = "pdo";
$db_conf["server"] = "mysql:host=localhost;port=3306"; 
$db_conf["user"] = PHPGRID_DBUSER; // username
$db_conf["password"] = PHPGRID_DBPASS; // password
$db_conf["database"] = PHPGRID_DBNAME; // database

// include and create object
include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");
$g = new jqgrid($db_conf);

// $g->con->ssl_key    = "key.pem";
// $g->con->ssl_cert   = "cert.pem"; 
// $g->con->ssl_ca     = "cacert.pem";
// $g->con->ssl_capath = null; 
// $g->con->ssl_cipher = null; 

// set few params
$grid["caption"] = "Sample Grid";
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "clients";

// render grid
$out = $g->render("list1");

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
<meta charset="UTF-8">
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
