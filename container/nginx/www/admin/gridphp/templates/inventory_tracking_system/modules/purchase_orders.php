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
$opt["caption"] = "Purchase Orders";
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
$opt["add_options"]["addCaption"] = "Add Purchase Order";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Purchase Order";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Purchase Order";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_purchase_orders";
$grid->select_command = "SELECT tb_purchase_orders.id, tb_purchase_orders.name, tb_purchase_orders.order_number, tb_purchase_orders.order_date, tb_purchase_orders.manufacturer, tb_purchase_orders.product, tb_purchase_orders.status, manufacturer_price AS manufacturer_price, tb_purchase_orders.quantity, (quantity * manufacturer_price) AS total_price, tb_purchase_orders.arrive_by, tb_purchase_orders.units_arrived, tb_purchase_orders.image, tb_purchase_orders.invoice, tb_purchase_orders.paid FROM tb_purchase_orders LEFT JOIN tb_manufacturers ON tb_manufacturers.id = tb_purchase_orders.manufacturer LEFT JOIN tb_product_inventory ON tb_product_inventory.id = tb_purchase_orders.product WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_purchase_orders.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_purchase_orders.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_purchase_orders.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_purchase_orders.name";
$col["frozen"] = true;
$col["editoptions"]["valuePattern"] = '#{order_number} - {product} - {manufacturer}';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "order_number";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.order_number";
$col["editoptions"]["type"] = 'number';
$col["editoptions"]["dataInit"] = 'function(o){ if(o.value==""){ getNextId(this,o); } }';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "order_date";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.order_date";
$col["editoptions"]["defaultValue"] = date("m/d/Y");
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "manufacturer";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.manufacturer";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_manufacturers';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_manufacturers',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=manufacturers&grid_id=list_manufacturers&col=id';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "product";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.product";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_product_inventory';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'product_id';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, product_id as v from tb_product_inventory',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=product_inventory&grid_id=list_product_inventory&col=id';
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "status";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.status";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Arrived:Arrived;Packaging:Packaging;Shipping:Shipping;In Production:In Production;Order Sent:Order Sent';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Arrived:Arrived;Packaging:Packaging;Shipping:Shipping;In Production:In Production;Order Sent:Order Sent';
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "manufacturer_price";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "manufacturer_price";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "quantity";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.quantity";
$col["editoptions"]["type"] = 'number';
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "total_price";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(quantity * manufacturer_price)";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["template"] = "${total_price}";
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "arrive_by";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.arrive_by";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 10;
$cols[] = $col;

$col = array();
$col["name"] = "units_arrived";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.units_arrived";
$col["editoptions"]["type"] = 'number';
$col["position"] = 11;
$cols[] = $col;

$col = array();
$col["name"] = "image";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.image";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 12;
$cols[] = $col;

$col = array();
$col["name"] = "invoice";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.invoice";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 13;
$cols[] = $col;

$col = array();
$col["name"] = "paid";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_purchase_orders.paid";
$col["formatter"] = "checkbox";
$col["edittype"] = "checkbox";
$col["editoptions"]["value"] = '1:0';
$col["position"] = 14;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_purchase_orders";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_purchase_orders";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Purchase Orders Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
