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
$opt["caption"] = "Accounts";
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
$opt["add_options"]["addCaption"] = "Add Account";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Account";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Account";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_accounts";
$grid->select_command = "SELECT tb_accounts.id, tb_accounts.name, tb_accounts.industry, tb_accounts.size, tb_accounts.company_website, tb_accounts.company_linkedin, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_contacts WHERE tb_accounts.id = tb_contacts.account AND 1=1) AS contacts, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_opportunities WHERE tb_accounts.id = tb_opportunities.account AND 1=1) AS opportunities, tb_accounts.hq_address FROM tb_accounts  WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_accounts.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_accounts.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_accounts.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_accounts.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "industry";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_accounts.industry";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Chemical:Chemical;Insurance:Insurance;Retail:Retail;Consumer goods:Consumer goods;Telecommunications:Telecommunications;Energy:Energy;Information technology:Information technology;Banking:Banking;Publishing:Publishing;Automotive:Automotive';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Chemical:Chemical;Insurance:Insurance;Retail:Retail;Consumer goods:Consumer goods;Telecommunications:Telecommunications;Energy:Energy;Information technology:Information technology;Banking:Banking;Publishing:Publishing;Automotive:Automotive';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "size";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_accounts.size";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = '11-50:11-50;101-500:101-500;1-10:1-10;51-100:51-100;10000+:10000+;501-1000:501-1000;1000-5000:1000-5000;5000-10000:5000-10000';
$col["stype"] = "select";
$col["searchoptions"]["value"] = '11-50:11-50;101-500:101-500;1-10:1-10;51-100:51-100;10000+:10000+;501-1000:501-1000;1000-5000:1000-5000;5000-10000:5000-10000';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "company_website";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_accounts.company_website";
$col["formatter"] = "url";
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "company_linkedin";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_accounts.company_linkedin";
$col["formatter"] = "url";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "contacts";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_contacts WHERE tb_accounts.id = tb_contacts.account AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name_and_organization as v from tb_contacts");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name_and_organization as v from tb_contacts',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=contacts&grid_id=list_contacts&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name_and_organization as v from tb_contacts");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "opportunities";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_opportunities WHERE tb_accounts.id = tb_opportunities.account AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, opportunity_name as v from tb_opportunities");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, opportunity_name as v from tb_opportunities',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=opportunities&grid_id=list_opportunities&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, opportunity_name as v from tb_opportunities");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "hq_address";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_accounts.hq_address";
$col["position"] = 8;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_accounts";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_accounts";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Accounts Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
