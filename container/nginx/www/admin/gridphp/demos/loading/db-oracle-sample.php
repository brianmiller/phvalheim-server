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
$grid = new jqgrid($db_conf);

$opt["caption"] = "Departments Data";

$grid->set_options($opt);
$grid->table = "hr.employees";

$col["name"]="EMPLOYEE_ID";

$grid->set_columns(array($col),true);

$grid->set_actions(array(	
						"add"=>false, // allow/disallow add
						"inlineadd"=>true, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>false, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);
				
$e["on_insert"] = array("add_client", null, false);
$grid->set_events($e);
function add_client($data)
{
	global $grid;
	$grid->execute_query("INSERT INTO hr.employees VALUES (207,'Abu','Ghuf','abu@gmail.com','123','2007-01-01','AC_MGR',1000,'',205,110)");
}
				
$out_master = $grid->render("list1");

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
	<?php echo $out_master ?>
	<br>
	</div>
	
	<script>
	jQuery.extend(jQuery.jgrid.inlineEdit, {
			beforeSaveRow: function (o,rowid) {
				alert('before save inline event');
				return true;
			}
		});
	</script>	

</body>
</html>
