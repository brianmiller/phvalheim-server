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

$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "desc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid

$grid["reloadedit"] = true;
$grid["sortable"] = false; // it is required for freezed column feature
$grid["loadComplete"] = "function(){ do_load(); }"; // connect onload event
	
$g->set_options($grid);

// disable all dialogs except edit
$g->navgrid["param"]["edit"] = false;
$g->navgrid["param"]["add"] = false;
$g->navgrid["param"]["del"] = true;
$g->navgrid["param"]["search"] = false;
$g->navgrid["param"]["refresh"] = true;

// enable inline editing buttons
$g->set_actions(array(
        "inline"=>true,
        "rowactions"=>true
    )
);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate,
						i.note, i.total, i.closed FROM invheader i";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "30";
$col["editable"] = true;
$col["show"] = array("edit"=>true); // only show freezed column in edit dialog
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // required:true(false), number:true(false), minValue:val, maxValue:val
$col["formatter"] = "date"; // format as date
$col["show"] = array("edit"=>true); // only show freezed column in edit dialog
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["editable"] = true;
// default render is textbox
$col["editoptions"] = array("value"=>'10');
$col["show"] = array("edit"=>false);
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"Yes:No"); // with these values "checked_value:unchecked_value"
$col["show"] = array("edit"=>false);
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["width"] = "400";
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea";

// set readmore class with textarea content
$col["formatter"] = "function(cellval,options,rowdata){ return '<div class=\"readmore\">'+cellval+'</div>'; }";
$col["unformat"] = "function(cellval,options,cell){ if(cellval == 'undefined') return ''; return jQuery(cell).children('div').html(); }";
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
	
    <script src="//cdn.jsdelivr.net/gh/jedfoster/Readmore.js/readmore.min.js" type="text/javascript"></script>
</head>
<body>

	<style>
	/* fix for freeze column div position */
	.ui-jqgrid .editable {margin: 0px !important;}
	
	.readmore {
	  max-height: 1.4em; /* (4 * 1.5 = 6) */
	}
	</style>
	
	<script>
	function do_load()
	{
		jQuery('.readmore').readmore({
								blockCSS: 'display:block;',
								afterToggle: function(trigger, element, expanded) {
								if(! expanded) {
								  $('html, body').animate( { scrollTop: element.offset().top }, {duration: 100 } );
								}
							  } 
							});
	}
	
	</script>
	
	<div style="margin:10px">
	<?php echo $out?>
	</div>
	
	<style>
	.ui-jqgrid tr.jqgrow td
	{
	    vertical-align: top;
	    white-space: normal !important;
	}
	</style>
	
</body>
</html>