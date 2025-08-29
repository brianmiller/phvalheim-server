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
// $db_conf = array();
// $db_conf["type"] = "pdo"; // mysql,oci8(for oracle),mssql,postgres,sybase
// $db_conf["server"] = "sqlite:../../sampledb/northwind.db";
// $db_conf["user"] = "";
// $db_conf["password"] = "";
// $db_conf["database"] = "";

// Database config file to be passed in phpgrid constructor
$db_conf = array(
	"type" 		=> PHPGRID_DBTYPE,
	"server" 	=> PHPGRID_DBHOST,
	"user" 		=> PHPGRID_DBUSER,
	"password" 	=> PHPGRID_DBPASS,
	"database" 	=> PHPGRID_DBNAME
);

$g = new jqgrid($db_conf);

$opt["caption"] = "";
$opt["responsive"] = true;
$opt["globalsearch"] = true;
$opt["readonly"] = true; 
$opt["height"] = "100%"; 
$g->set_options($opt);

$pid = intval($_GET["rowid"]);
$g->select_command = "SELECT order_date,ship_city,ship_country,shipped_date,unit_price,quantity,freight from `order_details` od
						inner join orders o on o.order_id = od.order_id
						where product_id = $pid";
						
$out = $g->render("list_detail");

?>
<div style="margin:10px">
<?php echo $out?>
</div>
<script>
$.jgrid.nav.addtext = "Add";
$.jgrid.nav.edittext = "Edit";
$.jgrid.nav.deltext = "Delete";
$.jgrid.nav.viewtext = "View";
</script>
