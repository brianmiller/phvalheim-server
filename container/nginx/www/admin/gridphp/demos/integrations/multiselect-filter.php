<?php 
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */
// http://stackoverflow.com/questions/12869819/excel-like-filtering-in-jqgrid
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
$grid["export"]["range"] = "filtered";
$grid["autowith"] = true;
$grid["sortable"] = false;
// $grid["persistsearch"] = true;
// $grid["loadComplete"] = "function(){ test(); }";

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"export_pdf"=>true, // show/hide row wise edit/del/save option
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance", // show/hide autofilter for search
					) 
				);

// to make dropdown work with export, we need clients.name as client_id logic in sql
$g->select_command = "SELECT id, invdate, clients.name as client_id, amount, note, tax FROM invheader 
						INNER JOIN clients on clients.client_id = invheader.client_id
						";

// this db table will be used for add,edit,delete
$g->table = "invheader";


$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "100";
$cols[] = $col;		
		
$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "invheader.client_id"; // this is required as we need to search in name field, not id
$col["width"] = "300";
$col["align"] = "left";
$col["search"] = true;
$col["editable"] = true;
$col["edittype"] = "select"; // render as select
# fetch data from database, with alias k for key, v for value
$str = $g->get_dropdown_values("select distinct client_id as k, name as v from clients limit 10");
$col["editoptions"] = array("value"=>":;".$str); 

// multi-select in search filter
$col["stype"] = "select-multiple";
$col["searchoptions"]["value"] = $str;

$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "500";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["search"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Amount";
$col["name"] = "amount"; 
$col["width"] = "500";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$cols[] = $col;

$col = array();
$col["title"] = "Tax";
$col["name"] = "tax"; 
$col["width"] = 350;
$col["editable"] = true;
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["formatter"] = "select";
$str = $g->get_dropdown_values("select distinct tax as k, tax as v from invheader");
$col["editoptions"] = array("value"=>":;".$str); 
// multi-select in search filter
$col["stype"] = "select-multiple";
$col["searchoptions"]["value"] = $str;
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note"; 
$col["width"] = "500";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$str = $g->get_dropdown_values("select distinct note as k, note as v from invheader limit 10");
$col["editoptions"] = array("value"=>":;".$str); 

// multi-select in search filter
$col["stype"] = "select-multiple";
$col["searchoptions"]["value"] = $str;

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
	
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-multiselect-widget/1.17/jquery.multiselect.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-multiselect-widget/1.17/jquery.multiselect.filter.css">
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-multiselect-widget/1.17/jquery.multiselect.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-multiselect-widget/1.17/jquery.multiselect.filter.js"></script>

	<!--link rel="stylesheet" href="../../lib/js/integration/multiselect/jquery.multiselect.css">
	<link rel="stylesheet" href="../../lib/js/integration/multiselect/jquery.multiselect.filter.css">
	<script src="../../lib/js/integration/multiselect/jquery.multiselect.js"></script>
	<script src="../../lib/js/integration/multiselect/jquery.multiselect.filter.js"></script-->
	
</head>
<body>
	<div style="margin:10px">
	<?php echo $out ?>
	</div>
		
	<button onclick="test()">Reset Multi-select</button>

	<script>

	function test()
	{
		// reset
		// $('.ui-multiselect-none').click();
		
		// select all
		// $('.ui-multiselect-all').click();
		
		// toggle
		// $("select").multiselect("widget").find(":checkbox").each(function(){
			// this.click();
		// });
		
		$("#gs_client_id > option[value=1]").prop('selected', true);
		$("#gs_client_id > option[value=2]").prop('selected', true);
		$("#gs_client_id").multiselect('refresh',true);

		$("#gs_note > option").prop('selected', false);
		$("#gs_note").multiselect('refresh',true);
		
		// $(".ui-multiselect-checkboxes input[value=1]").click();
		// $(".ui-multiselect-checkboxes input[value=3]").click();
		jQuery("#list1")[0].triggerToolbar(); 
	}
	</script>	
</body>
</html>
