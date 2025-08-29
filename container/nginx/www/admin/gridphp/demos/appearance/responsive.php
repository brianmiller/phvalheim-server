<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

// test: http://www.viewlike.us/operator/?url=www.phpgrid.org/demo/demos/appearance/responsive.php

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

// set few params
$grid["caption"] = "Sample Grid";
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "invheader";

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"showhidecolumns"=>true,
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
					)
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name,
						i.note, i.total, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "20";
$col["editable"] = false;
$col["visible"] = "xl";
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true;
$col["formatter"] = "date"; // format as date
$col["formatoptions"] = array("srcformat"=>'Y-m-d',"newformat"=>'d/m/Y'); // http://docs.jquery.com/UI/Datepicker/formatDate
$col["visible"] = "xl";
$cols[] = $col;
		
$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = false;
$col["search"] = false; 
$col["visible"] = "xs+";
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["editable"] = true;
$col["edittype"] = "textarea";
$col["editoptions"] = array("rows"=>2, "cols"=>20);
$col["visible"] = "md+";
$cols[] = $col;

$col = array();
$col["title"] = "Tax";
$col["name"] = "tax";
$col["width"] = "50";
$col["editable"] = true;
$col["formatter"] = "number";
$col["visible"] = "md+";
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
$col["formatter"] = "number";
$col["visible"] = "lg+";
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox";
$col["editoptions"] = array("value"=>"1:0");
$col["visible"] = "lg+";
$cols[] = $col; 

$col = array();
$col["title"] = "Ship Via";
$col["name"] = "ship_via";
$col["width"] = "50";
$col["editable"] = true;
$col["visible"] = "xl";
$cols[] = $col; 

// pass the cooked columns to grid
$g->set_columns($cols);
				
// render grid
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
	
	<!-- library for checkbox in column chooser -->
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	

</head>
<body>
	<div>
	<?php echo $out?>
	</div>
	<script>
	$.jgrid.nav.addtext = "Add";
	$.jgrid.nav.edittext = "Edit";
	</script>
</body>
</html>
