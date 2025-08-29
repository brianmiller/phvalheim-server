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
$opt["caption"] = "Product Inventory";
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
$opt["add_options"]["addCaption"] = "Add Product Inventory";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Product Inventory";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Product Inventory";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_product_inventory";
$grid->select_command = "SELECT tb_product_inventory.id, tb_product_inventory.product_id, tb_product_inventory.images, tb_product_inventory.product_name, tb_product_inventory.type, tb_product_inventory.price, tb_product_inventory.colors, tb_product_inventory.style, tb_product_inventory.location, tb_product_inventory.barcode, (SELECT sum(units_arrived) FROM tb_purchase_orders WHERE tb_product_inventory.id = tb_purchase_orders.product AND 1=1) AS units_ordered, (SELECT sum(quantity) FROM tb_sales_orders WHERE tb_product_inventory.id = tb_sales_orders.product AND 1=1) AS units_sold, ((SELECT units_ordered) - (SELECT units_sold)) AS inventory, tb_product_inventory.manufacturer, tb_product_inventory.manufacturer_price, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_purchase_orders WHERE tb_product_inventory.id = tb_purchase_orders.product AND 1=1) AS purchase_orders, (SELECT group_concat(id ORDER BY id DESC) FROM tb_sales_orders WHERE tb_product_inventory.id = tb_sales_orders.product AND 1=1) AS sales_orders FROM tb_product_inventory LEFT JOIN tb_warehouse_locations ON tb_warehouse_locations.id = tb_product_inventory.location LEFT JOIN tb_manufacturers ON tb_manufacturers.id = tb_product_inventory.manufacturer WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_product_inventory.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_product_inventory.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_product_inventory.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "product_id";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_product_inventory.product_id";
$col["frozen"] = true;
$col["editoptions"]["dataInit"] = 'function(o){ if(o.value==""){ getNextId(this,o); } }';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "images";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.images";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "product_name";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.product_name";
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "type";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.type";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Bag:Bag;Scarf:Scarf;Head Accessory:Head Accessory;Blanket:Blanket;Box:Box';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Bag:Bag;Scarf:Scarf;Head Accessory:Head Accessory;Blanket:Blanket;Box:Box';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "price";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.price";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "colors";
$col["width"] = 400;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.colors";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Burgundy:Burgundy;Sundown Ash:Sundown Ash;Navy Blue:Navy Blue;Dessert Brown:Dessert Brown;Forest Green:Forest Green;Indigo:Indigo;Wool White:Wool White';
$col["editoptions"]["multiple"] = true;
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Burgundy:Burgundy;Sundown Ash:Sundown Ash;Navy Blue:Navy Blue;Dessert Brown:Dessert Brown;Forest Green:Forest Green;Indigo:Indigo;Wool White:Wool White';
$col["searchoptions"]["sopt"] = array("cn");
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "style";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.style";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Women:Women;Unisex:Unisex;Men:Men;Children:Children;Dogs:Dogs;Cats:Cats';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Women:Women;Unisex:Unisex;Men:Men;Children:Children;Dogs:Dogs;Cats:Cats';
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "location";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.location";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_warehouse_locations';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_warehouse_locations',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=warehouse_locations&grid_id=list_warehouse_locations&col=id';
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "barcode";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.barcode";
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "units_ordered";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT sum(units_arrived) FROM tb_purchase_orders WHERE tb_product_inventory.id = tb_purchase_orders.product AND 1=1)";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 10;
$cols[] = $col;

$col = array();
$col["name"] = "units_sold";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT sum(quantity) FROM tb_sales_orders WHERE tb_product_inventory.id = tb_sales_orders.product AND 1=1)";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 11;
$cols[] = $col;

$col = array();
$col["name"] = "inventory";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "((SELECT units_ordered) - (SELECT units_sold))";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["formatter"] = false;
$col["template"] = "{inventory} units";
$col["position"] = 12;
$cols[] = $col;

$col = array();
$col["name"] = "manufacturer";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.manufacturer";
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
$col["position"] = 13;
$cols[] = $col;

$col = array();
$col["name"] = "manufacturer_price";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_product_inventory.manufacturer_price";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["position"] = 14;
$cols[] = $col;

$col = array();
$col["name"] = "purchase_orders";
$col["width"] = 500;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_purchase_orders WHERE tb_product_inventory.id = tb_purchase_orders.product AND 1=1)";
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
$col["position"] = 15;
$cols[] = $col;

$col = array();
$col["name"] = "sales_orders";
$col["width"] = 500;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id ORDER BY id DESC) FROM tb_sales_orders WHERE tb_product_inventory.id = tb_sales_orders.product AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, concat('#', order_number ,' - ',order_date,' - ',sale_platform) as v from tb_sales_orders");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, concat(\'#\', order_number ,\' - \',order_date,\' - \',sale_platform) as v from tb_sales_orders',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=sales_orders&grid_id=list_sales_orders&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, concat('#', order_number ,' - ',order_date,' - ',sale_platform) as v from tb_sales_orders");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 16;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_product_inventory";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_product_inventory";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Product Inventory Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
