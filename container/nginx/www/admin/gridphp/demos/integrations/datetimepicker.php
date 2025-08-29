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

// date_default_timezone_set('US/Eastern');

$g = new jqgrid($db_conf);

$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes

$grid["form"]["position"] = "center";  // position form dialog to center
$grid["form"]["nav"] = true;  // show form navigation

$grid["export"] = array("format"=>"pdf", "filename"=>"my-file", "heading"=>"Invoice Details", "orientation"=>"landscape", "paper"=>"a4");

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true,
						"search" => "advance", // show single/multi field search condition (e.g. simple or advance)
						"showhidecolumns" => false
					) 
				);

// you can provide custom SQL query to display data - convert_tz for local time
$g->select_command = "SELECT i.id, invdate , i.client_id,
						i.note, i.total, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id";
$col["name"] = "id";
$col["width"] = "25";
$cols[] = $col;		
		
$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "i.client_id"; // to resolve ambiguity as both joined tables have client_id field
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "lookup";
$col["editoptions"] = array("table"=>"clients", "id"=>"client_id", "label"=>"name");
$col["search"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea"; // render as textarea on edit
$col["editoptions"] = array("rows"=>2, "cols"=>20); // with these attributes
$cols[] = $col;

// set current time as default
$local_now = gmdate('d/m/Y H:i:s',time() + 3600*5); // gmt + 5

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "80";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20, "defaultValue"=>$local_now); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true, "edithidden"=>true); // and is required
# to make it date time
$col["formatter"] = "datetime";
# opts array can have these options: http://trentrichardson.com/examples/timepicker/#tp-options
$col["formatoptions"] = array("srcformat"=>'Y-m-d H:i:s',"newformat"=>'d/m/Y H:i:s',"opts" => array());
$col["show"] = array("list"=>true, "add"=>true, "edit"=>true, "view"=>true);
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"1:0"); // with these values "checked_value:unchecked_value"
$col["formatter"] = "checkbox";
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

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
		/* Alternate way if we dont use formoptions */
		/* Give 50px width to all captions */
		.FormGrid .EditTable .FormData .CaptionTD
		{
			width: 50px;
			vertical-align: top;
		}
	</style>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
	
	<script>
	// change position of datepicker below the input
	$.extend($.datepicker, { _checkOffset: function(inst, offset, isFixed) { return offset } });
	</script>
	
	
</body>
</html>
