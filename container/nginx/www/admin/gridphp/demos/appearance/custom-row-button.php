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

// $grid["url"] = ""; // your paramterized URL -- defaults to REQUEST_URI
$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "desc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes

// export XLS file
// export to excel parameters
$grid["export"] = array("format"=>"pdf", "filename"=>"my-file", "sheetname"=>"test");

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT * FROM (SELECT i.id, invdate , c.name,
						i.note, i.total, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id) o";

// this db table will be used for add,edit,delete
$g->table = "invheader";

// you can customize your own columns ...
$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "20";
# $col["hidden"] = true; // hide column by default
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = false; // this column is not editable
$col["search"] = false; // this column is not searchable

# $col["formatter"] = "image"; // format as image -- if data is image url e.g. http://<domain>/test.jpg
# $col["formatoptions"] = array("width"=>'20',"height"=>'30'); // image width / height etc

$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
# $col["width"] = "300"; // not specifying width will expand to fill space
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea"; // render as textarea on edit
$col["editoptions"] = array("rows"=>2, "cols"=>20); // with these attributes

// don't show this column in list, but in edit/add mode
$col["hidden"] = true;
$col["editrules"] = array("edithidden"=>true); 

$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // required:true(false), number:true(false), minValue:val, maxValue:val
$col["formatter"] = "date"; // format as date
$cols[] = $col;
		
		
$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
// default render is textbox
$col["editoptions"] = array("value"=>'10');
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"Yes:No"); // with these values "checked_value:unchecked_value"
$cols[] = $col;

# Custom made column to show link, must have default value as it's not db driven
$col = array();
$col["title"] = "Logo";
$col["name"] = "logo";
$col["width"] = "40";
$col["align"] = "center";
$col["search"] = false;
$col["sortable"] = false;
$col["default"] = "<img height=100% src='http://ssl.gstatic.com/ui/v1/icons/mail/logo_default.png'>";
$cols[] = $col;

# Custom made column to show link, must have default value as it's not db driven
$col = array();
$col["title"] = "Buy";
$col["name"] = "buy_button";
$col["width"] = 20;
$col["align"] = "center";
$col["search"] = false;
$col["position"] = 0;
$col["sortable"] = false;
# no new line in this html, only space. otherwise it may break ui of grid
$col["formatter"] = "function(cellvalue, options, rowObject){ return custom_format(cellvalue, options, rowObject);}";
$cols[] = $col;

$col = array();
$col["title"] = "Actions";
$col["name"] = "act";
$cols[] = $col;

# Custom made column to show extra buttons, must have default value as it's not db driven
$col = array();
$col["title"] = "More";
$col["name"] = "more_icons";
$col["fixed"] = true;
$col["width"] = "105";
$col["align"] = "center";
$col["search"] = false;
$col["sortable"] = false;
# no new line in this html, only space. otherwise it may break ui of grid

$buttons_html = '<a class="ui-custom-icon ui-icon ui-icon-print" title="Print this row" href="javascript:void(0);" onclick="alert({id})"></a>';
$buttons_html .= '<a class="ui-custom-icon ui-icon ui-icon-move" title="Move this row" href="javascript:void(0);" onclick="alert({id});"></a>';
$buttons_html .= '<a class="ui-custom-icon ui-icon ui-icon-document" title="Doc this row" href="javascript:void(0);" onclick="alert({id});"></a>';

$col["default"] = $buttons_html;
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
	<div style="margin:10px">
	<?php echo $out?>
	</div>

	<script>
	function custom_format(cellvalue, options, rowObject)
	{
		return '<div><span style="vertical-align:middle; font-size:18px; margin-top:5px; padding:0px 15px 0px 10px;display:inline;cursor:hand; cursor:pointer;" class="ui-icon ui-icon-disk" onclick="post_field('+options.rowId+'); return true;"></span></div>';
	}

	function post_field(id)
	{
		var str = prompt("Please enter your Prio") 
		if (str) 
			fx_bulk_update("set-prio",str,id); 
	}
	</script>
</body>
</html>