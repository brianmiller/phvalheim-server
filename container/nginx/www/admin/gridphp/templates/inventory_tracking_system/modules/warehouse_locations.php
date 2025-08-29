<?php 
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 1.5.2
 * @license: see license.txt included in package
 */
 
include_once("./config.php");

include_once(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array( 	
					"type"		=> PHPGRID_DBTYPE, 
					"server"	=> PHPGRID_DBHOST,
					"user"		=> PHPGRID_DBUSER,
					"password"	=> PHPGRID_DBPASS,
					"database"	=> PHPGRID_DBNAME
				);


$grid = new jqgrid($db_conf);

// default actions, moved up so set_options overrides it if required
$actions = array (
  'add' => true,
  'edit' => true,
  'bulkedit' => true,
  'delete' => true,
  'rowactions' => true,
  'export_csv' => true,
  'aiassistant' => true,
  'autofilter' => true,
  'search' => 'advanced',
);
$grid->set_actions($actions);

// grid options
$opt = array();
$opt["caption"] = "Warehouse Locations";
$opt["sortname"] = "id";
$opt["sortorder"] = "ASC";
$opt["readonly"] = false;
$opt["multiselect"] = true;
$opt["autoheight"] = true;
$opt["columnicon"] = true;
$opt["loadComplete"] = "function(o){ if (typeof gridLoad === 'function') gridLoad(o); }";
$opt["onAfterSave"] = "function(){ if (typeof afterSave === 'function') afterSave(); }";
$opt["shrinkToFit"] = false;
$opt["sortable"] = false;
$opt["cmTemplate"]["visible"] = 'xs+';
$opt["cmTemplate"]["editoptions"]["dataEvents"] = array( array (
  'type' => 'loadform change click keyup',
  'fn' => 'function(e){ if (formCallback) formCallback(this,e); }',
) );

// Customize add/edit/view dialogs
$opt["add_options"]["width"] = 800;
$opt["add_options"]["addCaption"] = "Add Warehouse Location";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Warehouse Location";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Warehouse Location";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_warehouse_locations";
$grid->select_command = "SELECT tb_warehouse_locations.id, tb_warehouse_locations.name, tb_warehouse_locations.shorthand, tb_warehouse_locations.product_types, (SELECT group_concat(id) FROM tb_product_inventory WHERE tb_warehouse_locations.id = tb_product_inventory.location AND 1=1) AS product_inventory FROM tb_warehouse_locations  WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_warehouse_locations.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_warehouse_locations.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_warehouse_locations.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_warehouse_locations.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "shorthand";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_warehouse_locations.shorthand";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'A-1:A-1;A-2:A-2;A-3:A-3;B-1:B-1;B-2:B-2;C-1:C-1;C-2:C-2;D-1:D-1';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'A-1:A-1;A-2:A-2;A-3:A-3;B-1:B-1;B-2:B-2;C-1:C-1;C-2:C-2;D-1:D-1';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "product_types";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_warehouse_locations.product_types";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Bags:Bags;Scarves:Scarves;Head Accessories:Head Accessories;Miscellaneous:Miscellaneous';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Bags:Bags;Scarves:Scarves;Head Accessories:Head Accessories;Miscellaneous:Miscellaneous';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "product_inventory";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id) FROM tb_product_inventory WHERE tb_warehouse_locations.id = tb_product_inventory.location AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, product_name as v from tb_product_inventory");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, product_name as v from tb_product_inventory',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=product_inventory&grid_id=list_product_inventory&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, product_name as v from tb_product_inventory");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 4;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_warehouse_locations";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_warehouse_locations";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Warehouse Locations Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
