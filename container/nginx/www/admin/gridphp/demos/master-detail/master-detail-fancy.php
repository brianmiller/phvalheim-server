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

// master grid
$grid = new jqgrid($db_conf);

$opt["caption"] = "Clients Data";
// following params will enable subgrid -- by default first column (PK) of parent is passed as param 'id'
$opt["detail_grid_id"] = "list2";
$opt["multiselect"] = true;

// extra params passed to detail grid, column name comma separated
$opt["subgridparams"] = "client_id,gender,company";
$opt["export"] = array("filename"=>"my-file", "sheetname"=>"test", "format"=>"pdf");
$opt["export"]["range"] = "filtered";
$grid->set_options($opt);
$grid->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id"; 
$col["width"] = "20";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Name";
$col["name"] = "name"; 
$col["editable"] = true;
$col["width"] = "80";
$cols[] = $col;	

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Company";
$col["name"] = "company"; 
$col["editable"] = true;
$col["edittype"] = "textarea"; 
$col["editoptions"] = array("rows"=>2, "cols"=>20); 
$cols[] = $col;	

$col = array();
$col["title"] = "Detail Info";
$col["name"] = "detail"; 
$col["default"] = "<a class='fancybox' onclick='setTimeout(function(){jQuery(\"#list1\").resetSelection(); jQuery(\"#list1\").setSelection({client_id})},50);' href='#box_detail_grid'>View Details</a>"; 
$col["width"] = "40";
$col["editable"] = true;
$col["search"] = false;
$col["export"] = false;
$cols[] = $col;

$grid->set_columns($cols);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"showhidecolumns" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);
				
$out_master = $grid->render("list1");

// detail grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["height"] = ""; // autofit height of subgrid
$opt["caption"] = "Invoice Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["responsive"] = false;
$opt["globalsearch"] = false;
$opt["export"] = array("filename"=>"my-file", "sheetname"=>"test", "format"=>"pdf"); // export to excel parameters
$opt["export"]["range"] = "filtered";

// fill detail add form, with parent id
$opt["add_options"]["afterShowForm"] = "function(formid){ var selr = jQuery('#list1').jqGrid('getGridParam','selrow'); $('#client_id').val(jQuery('#list1').jqGrid('getCell', selr, 'client_id'));  }";

// Check if master record is selected before detail addition
$opt["add_options"]["beforeInitData"] = "function(formid){ var selr = jQuery('#list1').jqGrid('getGridParam','selrow'); if (!selr) { alert('Please select master record first'); return false; } }";
$grid->set_options($opt);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"showhidecolumns" => true,
						"search" => false // show single/multi field search condition (e.g. simple or advance)
					) 
				);


// receive id, selected row of parent grid
$id = intval($_GET["rowid"]);
$gender = $_GET["gender"];
$company = addslashes(utf8_encode($_GET["company"]));
$cid = intval($_GET["client_id"]);

// for non-int fields as PK
// $id = (empty($_GET["rowid"])?0:$_GET["rowid"]);

// and use in sql for filteration
$grid->select_command = "SELECT id,client_id,invdate,amount,tax,total,'$company' as 'company' FROM invheader WHERE client_id = $cid";
// this db table will be used for add,edit,delete
$grid->table = "invheader";

$cols = array();

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // field name, must be exactly same as with SQL prefix or db field
$col["width"] = "10";
$cols[] = $col;	

$col = array();
$col["title"] = "Company"; // caption of column
$col["name"] = "company"; // field name, must be exactly same as with SQL prefix or db field
$col["width"] = "100";
$col["editable"] = false;
$col["search"] = false;
$cols[] = $col;	

$col = array();
$col["title"] = "Client Id";
$col["name"] = "client_id";
$col["width"] = "10";
$col["editable"] = true;
// set detail form client_id as readonly and for add dialog only
$col["editoptions"] = array("readonly"=>"readonly");
$col["show"]["edit"] = false;
$cols[] = $col;		

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate";
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

$col = array();
$col["title"] = "Amount";
$col["name"] = "amount";
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

$grid->set_columns($cols);
$e["on_insert"] = array("add_client", null, true);
$grid->set_events($e);

function add_client(&$data)
{
	$id = intval($_GET["rowid"]);
	$data["params"]["client_id"] = $id;
}

// generate grid output, with unique grid name as 'list1'
$out_detail = $grid->render("list2");
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
	
	<!-- Add fancyBox main JS and CSS files -->
	<link type="text/css" rel="stylesheet" href="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.css" />
	<script type="text/javascript" src="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.js"></script>

	<!-- library for checkbox in column chooser -->
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	
	
</head>
<body>
	
	<div style="margin:10px">
	<?php echo $out_master ?>
	</div>

	<div id='box_detail_grid' style='display:none; width:70%'>
	<?php echo $out_detail ?>
	</div>
	
	<script>
	$(document).ready(function() {
		$('.fancybox').fancybox({
			autosize: false,
			width    : "auto",
			afterClose : function() { $('#list1').trigger("reloadGrid"); } 
		});
	});
	</script>
	
</body>
</html>
