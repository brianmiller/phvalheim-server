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
$opt["caption"] = "Contacts";
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
$opt["add_options"]["addCaption"] = "Add Contact";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Contact";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Contact";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_contacts";
$grid->select_command = "SELECT tb_contacts.id, tb_contacts.name_and_organization, tb_contacts.name, tb_contacts.account, tb_contacts.vip, tb_contacts.department, tb_contacts.email, tb_contacts.phone, tb_contacts.title, tb_contacts.linkedin, (SELECT group_concat(id) FROM tb_interactions WHERE FIND_IN_SET(tb_contacts.id, tb_interactions.contact) AND 1=1) AS interactions, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_opportunities WHERE tb_contacts.id = tb_opportunities.primary_contact AND 1=1) AS opportunities FROM tb_contacts LEFT JOIN tb_accounts ON tb_accounts.id = tb_contacts.account WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_contacts.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_contacts.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_contacts.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name_and_organization";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_contacts.name_and_organization";
$col["frozen"] = true;
$col["editoptions"]["valuePattern"] = '{name} — {account}';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.name";
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "account";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.account";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_accounts';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_accounts',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=accounts&grid_id=list_accounts&col=id';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "vip";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.vip";
$col["formatter"] = "checkbox";
$col["edittype"] = "checkbox";
$col["editoptions"]["value"] = '1:0';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "department";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.department";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Marketing:Marketing;EMEA operations:EMEA operations;Design:Design;Customer success:Customer success;Human resources:Human resources';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Marketing:Marketing;EMEA operations:EMEA operations;Design:Design;Customer success:Customer success;Human resources:Human resources';
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "email";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.email";
$col["formatter"] = "email";
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "phone";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.phone";
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "title";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.title";
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "linkedin";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.linkedin";
$col["formatter"] = "url";
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "interactions";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id) FROM tb_interactions WHERE FIND_IN_SET(tb_contacts.id, tb_interactions.contact) AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, interaction as v from tb_interactions");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, interaction as v from tb_interactions',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=interactions&grid_id=list_interactions&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, interaction as v from tb_interactions");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 10;
$cols[] = $col;

$col = array();
$col["name"] = "opportunities";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_opportunities WHERE tb_contacts.id = tb_opportunities.primary_contact AND 1=1)";
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
$col["position"] = 11;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_contacts";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_contacts";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Contacts Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
