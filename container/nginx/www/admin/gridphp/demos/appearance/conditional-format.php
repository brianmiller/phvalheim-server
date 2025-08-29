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

$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["reloadedit"] = true;

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, 10 as jid, invdate, date_format(invdate,'%Y%m%d') as invdate_calc, c.name FROM invheader i
						left JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "10";
$cols[] = $col;		

// temporary column for column placeholder comparison in formatting
$col = array();
$col["title"] = "jId"; // caption of column
$col["name"] = "jid"; 
$col["width"] = "10";
$col["link"] = "http://www.google.es/q/{search}";
$col["linkoptions"] = "target='_blank'";
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = false;
$col["export"] = false; // this column will not be exported
$cols[] = $col;

$col = array();
$col["title"] = "Date Calc";
$col["name"] = "invdate_calc"; // sql aliased column of invdate with date format (20120101)
$col["width"] = "20";
$col["editable"] = false; // this column is editable
$col["hidden"] = true; // this column is hidden, just used for formatting
$col["search"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "10";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["formatoptions"] = array("srcformat"=>'Y-m-d',"newformat"=>'d/m/Y'); 
$col["search"] = false;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// conditional css formatting of rows
$f = array();
$f["column"] = "name"; // exact column name, as defined above in set_columns or sql field name
$f["op"] = "cn"; // cn - contains, eq - equals
$f["value"] = "Ana";
$f["class"] = "focus-row-2"; // css class name or "'background-color':'orange','color':'white'";
$f_conditions[] = $f;

// If date is > than 20120101, highlight row with green
$f = array();
$f["column"] = "invdate";
$f["op"] = ">=";
$f["value"] = "2015-01-01";
$f["cellclass"] = "focus-date";
$f_conditions[] = $f;

$f = array();
$f["column"] = "id";
$f["op"] = "<";
$f["value"] = "{jid}"; // you can use placeholder of column name as value
$f["cellcss"] = "'background-color':'green','border':'1px solid darkgray','color':'white'"; 
$f_conditions[] = $f;

// apply style on target column, if defined cellclass OR cellcss
$f = array();
$f["column"] = "id";
$f["target"] = "name";
$f["op"] = "=";
$f["value"] = "7";
$f["cellclass"] = "focus-cell";
$f_conditions[] = $f;

// if nothing set in 'op' and 'value', it will set column formatting for all cell
// $f = array();
// $f["column"] = "invdate";
// $f["css"] = "'background-color':'#FBEC88', 'color':'green'"; // must use (single quote ') with css attr and value
// $f_conditions[] = $f;

$g->set_conditional_css($f_conditions);

// generate grid output, with unique grid name as 'list1'
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
	<style>
	.focus-date
	{
		background: #c8d578;
		color: green;
		border: 1px solid darkgray;
	}

	tr.focus-row-2
	{
		background: Bisque;
		color: brown;
	}

	tr.focus-row-3
	{
		background: teal;
	}

	.focus-cell
	{
		background: red;
		color: white;
	}
	</style>
	<div style="margin:10px">
	<br>
	<?php echo $out?>
	</div>
</body>
</html>
