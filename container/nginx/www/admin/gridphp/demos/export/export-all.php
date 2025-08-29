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

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "10";
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = false; // this column is not editable
$col["search"] = true;
$col["editable"] = true;
$col["export"] = true; // when set false, this column will not be exported
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["formatoptions"] = array("srcformat"=>'Y-m-d',"newformat"=>'d-m-Y'); // format as date
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$col["export"] = true; // when set false, this column will not be exported
$cols[] = $col;

$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "desc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes

// export PDF file params
$grid["export"] = array("filename"=>"my-file", "heading"=>"Invoice Details", "orientation"=>"landscape", "paper"=>"a4");
// for excel, sheet header
$grid["export"]["sheetname"] = "Invoice Details";
// export filtered data or all data
$grid["export"]["range"] = "filtered"; // or "all"

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export_excel"=>true, // export excel button
						"export_pdf"=>true, // export pdf button
						"export_csv"=>true, // export csv button
						"export_html"=>true, // export html button
						"autofilter" => true, // show/hide autofilter for search
						"showhidecolumns" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name,i.note FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

// pass the cooked columns to grid
$g->set_columns($cols);

$e["on_render_pdf"] = array("filter_pdf", null, true);
$e["on_render_excel"] = array("filter_xls", null, true);
$e["on_data_display"] = array("filter_display", null, true);
$g->set_events($e);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");

// filter PDF output
function filter_pdf($param)
{
	for($x=1; $x<count($param["data"]); $x++)
		$param["data"][$x]["note"] = "<img src='http://www.phpgrid.org/wp-content/uploads/customer-logos/iba.jpg'>"; // must be jpg
}
// filter Excel output
function filter_xls($param)
{
	for($x=1; $x<count($param["data"]); $x++)
		$param["data"][$x]["note"] = "/".$param["data"][$x]["note"]."/Excel";
}
// filter Grid output
function filter_display($param)
{
	$d = &$param["params"];
	
	for($x=1; $x<count($d); $x++)
		$d[$x]["note"] = "/".$d[$x]["note"]."/Display";
}
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
	<br>
	<?php echo $out?>
	</div>
</body>
</html>