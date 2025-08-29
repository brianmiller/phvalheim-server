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
$grid["sortorder"] = "asc"; // ASC or DESC
$grid["caption"] = "Stores Location - Country/State/City wise"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
// $grid["add_options"]["afterShowForm"] = "function(){ setup_fields(); }";
// $grid["edit_options"]["afterShowForm"] = "function(){ setup_fields(); }";

// show reload link with dropdown
$grid["add_options"]["afterShowForm"] = 'function(formid){ add_dropdown_action(formid); }';
$grid["edit_options"]["afterShowForm"] = 'function(formid){ add_dropdown_action(formid); }';

$g->set_options($grid);

if (empty($_POST["country_id"]))
	$_POST["country_id"] = 0;

$g->set_actions(array(
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"export_html" => true, // export html table ... can change to export_pdf, export_csv, export_xls
					)
				);

$g->select_command = "SELECT store.id, store.country_id, store.state_id, store.city_id, store.name from store
						left JOIN country on country.id = store.country_id
						INNER JOIN city on city.id = store.city_id
						INNER JOIN state on state.id = store.state_id
						";

// this db table will be used for add,edit,delete
$g->table = "store";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id";
$col["width"] = "15";
$cols[] = $col;

$col = array();
$col["title"] = "Country";
$col["name"] = "country_id";
$col["dbname"] = "store.country_id";
$col["width"] = "100";
$col["align"] = "left";
$col["search"] = true;
$col["editable"] = true;
$col["edittype"] = "select"; // render as select
# fetch data from database, with alias k for key, v for value

// $sql_state_id = "";
// if (is_array($_POST["state_id"]))
	// $_POST["state_id"] = implode(",",$_POST["state_id"]);
// if (!empty($_POST["state_id"]))
	// $sql_state_id = ' AND state_id IN ({state_id})';

# on change, update other dropdown
$str = $g->get_dropdown_values("select distinct id as k, name as v from country");
$col["editoptions"] = array(
								"value"=>":Select...;".$str,
								"onchange" => array(
													"update_field"=>"state_id",
													"sql"=>"select id as k, name as v from state WHERE country_id IN ({country_id})",
													)
							);
$col["editoptions"]["dataInit"] = "function(){ setTimeout(function(){ link_select2('{$col["name"]}'); },200); }";

$col["formatter"] = "select"; // display label, not value
$col["stype"] = "select"; // enable dropdown search
$col["searchoptions"] = array("value" => $str);

$cols[] = $col;

$col = array();
$col["title"] = "State";
$col["name"] = "state_id";
$col["dbname"] = "store.state_id";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$col["edittype"] = "select"; // render as select
$str = $g->get_dropdown_values("select id as k, name as v from state");
$col["editoptions"] = array(
			"value"=>":;".$str,
			"onchange" => array(	"update_field"=>"city_id",
									"sql"=>"select city.id as k, city.name as v from city inner join state on city.state_id = state.id WHERE state_id IN ({state_id})"
								)
			);
$col["editoptions"]["dataInit"] = "function(){ setTimeout(function(){ link_select2('{$col["name"]}'); },200); }";

// $col["editoptions"]["onload"]["sql"] = "select id as k, name as v from state WHERE country_id IN ({country_id})";
$col["formatter"] = "select"; // display label, not value
$col["stype"] = "select"; // enable dropdown search
$col["searchoptions"] = array("value" => ":;".$str);

$cols[] = $col;

$col = array();
$col["title"] = "City";
$col["name"] = "city_id";
$col["dbname"] = "store.city_id";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$col["edittype"] = "select"; // render as select
$str = $g->get_dropdown_values("select id as k, name as v from city");
$col["editoptions"] = array(
							"value"=>":;".$str
							);
$col["editoptions"]["dataInit"] = "function(){ setTimeout(function(){ link_select2('{$col["name"]}'); },200); }";

// required for manual refresh link
// $col["editoptions"]["onload"]["sql"] = "select city.id as k, city.name as v from city inner join state on city.state_id = state.id WHERE country_id IN ({country_id}) $sql_state_id";

$col["formatter"] = "select"; // display label, not value
$col["stype"] = "select"; // enable dropdown search
$col["searchoptions"] = array("value" => ":;".$str);

$cols[] = $col;


$col = array();
$col["title"] = "Store";
$col["name"] = "name";
$col["dbname"] = "store.name";
$col["width"] = "150";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
?>
<!DOCTYPE html>
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


	<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" rel="stylesheet" />
	<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>
	
</head>
<body>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
	<script>
	function link_select2(id)
	{
		$('select[name='+id+'].editable, select[id='+id+']').select2({onSelect: function(){ jQuery(this).trigger('change'); }});
		$(document).unbind('keypress').unbind('keydown');
	}
	</script>
	<script>
	// hide state/city rows if country not selected
	function setup_fields()
	{
		if ($('#country_id').val() == '')
		{
			$('#tr_city_id').hide();
			$('#tr_state_id').hide();
		}

		$('#country_id').change(function(){

			if ($('#country_id').val() == '')
			{
				$('#tr_city_id').hide();
				$('#tr_state_id').hide();
			}
			else
			{
				$('#tr_city_id').show();
				$('#tr_state_id').show();
			}
		});
	}

	// add refresh and add link with field
	function add_dropdown_action(formid)
	{
		var str = '';
		str += "<a href='javascript:void(0)' style='outline:none;' onclick='fx_reload_dropdown(\"city_id\",\"state_id\");'><span style='padding:0 5px; color: green; display:inline; padding: 0 7px; margin:0 0 0 5px; top: 2px;' class='ui-icon ui-icon-refresh'></span></a>";
		jQuery("#city_id").after(str);
	}
	</script>

	<style>
	/* multiselect filter style for appearance */
	.ui-multiselect-menu .ui-state-hover {
		font-weight: normal !important;
	}
	.ui-multiselect-header {
		font-weight: normal !important;
	}
	.ui-multiselect-header li.ui-multiselect-close {
		margin: 3px;
	}
	</style>
</body>
</html>
