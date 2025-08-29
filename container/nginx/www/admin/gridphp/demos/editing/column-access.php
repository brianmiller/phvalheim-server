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

// set few params
$grid["caption"] = "Sample Grid";
$grid["height"] = "250";
$grid["autowidth"] = true;
$grid["multiselect"] = true;
$grid["rowList"] = array();
$grid["rowNum"] = 15;
$grid["form"]["position"] = "center";

// JS events
$grid["ondblClickRow"] = "function (id) { grid_dblclick(id); }";
$grid["loadComplete"] = "function (id) { grid_load(id); }";
$grid["onSelectRow"] = "function (id) { grid_select(id); }";

$g->set_options($grid);

// set database table for CRUD operations
$g->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Name";
$col["name"] = "name";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender";
$col["editable"] = true;
$col["edittype"] = "checkbox";
$col["editoptions"] = array("value" => "male:female");

// use callback function condition for readonly
$col["editrules"] = array("required"=>true, "readonly"=>true, "readonly-when"=>"check_client");

// use certain field value condition for readonly
// $col["editrules"] = array("required"=>true, "readonly"=>true, "readonly-when"=>array("client_id","==","8"));

$col["show"] = array("list"=>true, "add"=>true, "edit"=>true, "view"=>true);
$cols[] = $col;

$col = array();
$col["title"] = "Company";
$col["name"] = "company";
$col["editable"] = true;
// $col["formatter"] = "wysiwyg";
$col["editoptions"] = array("defaultValue" => "Default Company");
$cols[] = $col;

$g->set_columns($cols);

$g->set_actions(array(
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"clone"=>true, // allow/disallow delete
						"delete"=>true, // allow/disallow delete
						"view"=>true, // allow/disallow view
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					)
				);

// render grid
$out = $g->render("list1");

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>
	<script src="../../lib/js/ckeditor/ckeditor.js" type="text/javascript"></script>
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
	function grid_dblclick(id)
	{
		var grid = $('#list1');
		var data = grid.getRowData(id);

		if (data.name.indexOf("Ana") != -1) // show only edit, no delete
		{
			jQuery(this).jqGrid('editGridRow', id, <?php echo json_encode_jsfunc($g->options["edit_options"])?>);
		}
		else if (data.gender == 'male') // view only
		{
			return;
		}
		else
			jQuery(this).jqGrid('editGridRow', id, <?php echo json_encode_jsfunc($g->options["edit_options"])?>);
    }
	function grid_load()
	{
		var grid = $('#list1');
		var rowids = grid.getDataIDs();
		var columnModels = grid.getGridParam().colModel;

		// check each visible row
		for (var i = 0; i < rowids.length; i++)
		{
			var rowid = rowids[i];
			var data = grid.getRowData(rowid);

			if (data.name.indexOf("Ana") != -1) // show only edit, no delete
			{
			  	jQuery("tr#"+rowid+" td[aria-describedby$='_act'] span:first").html(jQuery("tr#"+rowid+" td[aria-describedby$='_act']").find("a:first"));
			}
			else if (data.gender == 'male') // view only
			{
				jQuery("tr#"+rowid).addClass("not-editable-row");
			  	jQuery("tr#"+rowid+" td[aria-describedby$='_act']").html("-");
			}

		}

		// for multiselect check all, list1 is grid id
		$("#cb_list1").click(function(){

			var selr_one = grid.getGridParam('selrow');
			var selr = [];
			selr = grid.jqGrid('getGridParam','selarrrow'); // array of id's of the selected rows when multiselect options is true. Empty array if not selection
			if (selr.length < 2 && selr_one)
				selr[0] = selr_one;

			for (var x=0;x < selr.length;x++)
			{
				rowid = selr[x];
				var data = grid.getRowData(rowid);

				if (data.name.indexOf("Ana") != -1) // show only edit, no delete
				{
					jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
				}
				else if (data.gender == 'male') // view only
				{
					jQuery("#list1_pager #edit_list1, #list1_toppager #edit_list1").addClass("ui-state-disabled");
					jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
				}
			}
		});
	}

	function grid_select(id)
	{
		var grid = $('#list1');

		var rowid = grid.getGridParam('selrow');
		if (rowid)
		{
			var data = grid.getRowData(rowid);
			if (data.name.indexOf("Ana") != -1) // show only edit, no delete
			{
				jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
				jQuery("#list1_pager #edit_list1, #list1_toppager #edit_list1").removeClass("ui-state-disabled");
			}
			else if (data.gender == 'male') // view only
			{
				jQuery("#list1_pager #edit_list1, #list1_toppager #edit_list1").addClass("ui-state-disabled");
				jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
			}
			else
			{
				jQuery("#list1_pager #edit_list1, #list1_toppager #edit_list1").removeClass("ui-state-disabled");
				jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").removeClass("ui-state-disabled");
			}
		}
		// for multiselect
		var rowids = grid.getGridParam('selarrrow');
		if (rowids.length > 1)
		{
			for (var x=0;x < rowids.length;x++)
			{
				rowid = rowids[x];
				var data = grid.getRowData(rowid);

				if (data.name.indexOf("Ana") != -1) // show only edit, no delete
				{
					jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
				}
				else if (data.gender == 'male') // view only
				{
					jQuery("#list1_pager #del_list1, #list1_toppager #del_list1").addClass("ui-state-disabled");
				}
			}
		}
	}

	// readonly gender conditional function - when return true, field will be readonly
	function check_client(formid)
	{
		client_id = jQuery("input[name=client_id]:last, select[name=client_id]:last",formid).val();
		client_id = parseInt(client_id);

		if (jQuery.inArray(client_id,[3,6,7,8,9]) != -1)
			return true;
	}
	</script>
</body>
</html>
