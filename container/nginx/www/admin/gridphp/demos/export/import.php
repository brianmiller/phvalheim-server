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

$opt["caption"] = "Sample Grid";
$opt["multiselect"] = true;
$opt["autowidth"] = true;

// show append or replace option on step2
$opt["import"]["allowreplace"] = true;
// remove unwanted field in import mapping
$opt["import"]["hidefields"] = array("client_id");

$g->set_options($opt);

$g->table = "clients";

// allow null field
$col = array();
$col["title"] = "gender";
$col["name"] = "gender";
$col["isnull"] = true;
$col["editable"] = true;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols,true);

// false in 3rd param means don't process insert by grid. Use callback and stop.
$e = array();
$e["on_import"] = array("do_import","",true);
$g->set_events($e);
	
function do_import($data)
{

	/*
	Array
	(
		[params] => Array
			(
				[name] => 1
				[gender] => 2
				[company] => 3
			)

	)
	*/

	// ==== Reject import if columns are not five ====
	// if (count($data["params"]) != 5)
	// {
	// 	$data["msg"] = "Imported data must have 5 columns";
	// 	return;
	// }

	// ==== set default value for a column ====
	// $data["params"]["company"] = "test company";

	// ==== custom import query, set callback 3rd param to false ====
	// global $g;
	// $values = $data["params"];
	// $g->execute_query("INSERT INTO clients (name,gender,company) VALUES (?,?,?)",array($values["name"],$values["gender"],$values["company"]));
}
	
$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"import"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
					) 
				);

$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
 
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>
	
	<div>
	<?php echo $out?>
	</div>
</body>
</html>
