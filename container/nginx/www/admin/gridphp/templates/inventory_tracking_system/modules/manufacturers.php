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
$opt["caption"] = "Manufacturers";
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
$opt["add_options"]["addCaption"] = "Add Manufacturer";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Manufacturer";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Manufacturer";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_manufacturers";
$grid->select_command = "SELECT tb_manufacturers.id, tb_manufacturers.name, tb_manufacturers.image, tb_manufacturers.contact_name, tb_manufacturers.location, tb_manufacturers.email, tb_manufacturers.phone_number, tb_manufacturers.address, (SELECT group_concat(id) FROM tb_product_inventory WHERE tb_manufacturers.id = tb_product_inventory.manufacturer AND 1=1) AS products, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_purchase_orders WHERE tb_manufacturers.id = tb_purchase_orders.manufacturer AND 1=1) AS purchase_orders, (SELECT COUNT(id) FROM tb_purchase_orders WHERE tb_manufacturers.id = tb_purchase_orders.manufacturer AND 1=1) AS total_orders FROM tb_manufacturers  WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_manufacturers.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_manufacturers.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_manufacturers.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_manufacturers.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "image";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.image";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "contact_name";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.contact_name";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Abu Ghufran:Abu Ghufran;Ellie Wood:Ellie Wood;Thomas Train:Thomas Train;Lisa Barns:Lisa Barns;Rosa Ng:Rosa Ng;Felix Villanueva:Felix Villanueva;Sasha Stockton:Sasha Stockton;Rachel Chan:Rachel Chan;Raphael Solane:Raphael Solane;Richie Gere:Richie Gere;Daisy Bishop:Daisy Bishop';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Abu Ghufran:Abu Ghufran;Ellie Wood:Ellie Wood;Thomas Train:Thomas Train;Lisa Barns:Lisa Barns;Rosa Ng:Rosa Ng;Felix Villanueva:Felix Villanueva;Sasha Stockton:Sasha Stockton;Rachel Chan:Rachel Chan;Raphael Solane:Raphael Solane;Richie Gere:Richie Gere;Daisy Bishop:Daisy Bishop';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "location";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.location";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'San Francisco:San Francisco;San Jose:San Jose;San Bruno:San Bruno;Millbrae:Millbrae;Palo Alto:Palo Alto;Oakland:Oakland;Berkeley:Berkeley';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'San Francisco:San Francisco;San Jose:San Jose;San Bruno:San Bruno;Millbrae:Millbrae;Palo Alto:Palo Alto;Oakland:Oakland;Berkeley:Berkeley';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "email";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.email";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "phone_number";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.phone_number";
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "address";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_manufacturers.address";
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "products";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id) FROM tb_product_inventory WHERE tb_manufacturers.id = tb_product_inventory.manufacturer AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, concat(product_name,' ', product_id) as v from tb_product_inventory");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, concat(product_name,\' \', product_id) as v from tb_product_inventory',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=product_inventory&grid_id=list_product_inventory&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, concat(product_name,' ', product_id) as v from tb_product_inventory");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "purchase_orders";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_purchase_orders WHERE tb_manufacturers.id = tb_purchase_orders.manufacturer AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name as v from tb_purchase_orders");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_purchase_orders',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=purchase_orders&grid_id=list_purchase_orders&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name as v from tb_purchase_orders");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "total_orders";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT COUNT(id) FROM tb_purchase_orders WHERE tb_manufacturers.id = tb_purchase_orders.manufacturer AND 1=1)";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 10;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_manufacturers";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_manufacturers";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Manufacturers Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
