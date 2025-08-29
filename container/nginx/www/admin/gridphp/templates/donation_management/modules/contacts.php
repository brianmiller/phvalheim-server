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
$grid->select_command = "SELECT tb_contacts.id, tb_contacts.name, tb_contacts.associated_companies, tb_contacts.address, tb_contacts.telephone, tb_contacts.email, (SELECT group_concat(id) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1) AS donations, (SELECT sum(amount) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1) AS lifetime_donation_total, (SELECT count(amount) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1) AS number_of_donations, tb_contacts.linkedin_profile FROM tb_contacts LEFT JOIN tb_companies ON tb_companies.id = tb_contacts.associated_companies WHERE 1=1 ";


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
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_contacts.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "associated_companies";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.associated_companies";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_companies';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_companies',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=companies&grid_id=list_companies&col=id';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "address";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.address";
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "telephone";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.telephone";
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "email";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.email";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "donations";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, gift_id as v from tb_donations");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, gift_id as v from tb_donations',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=donations&grid_id=list_donations&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, gift_id as v from tb_donations");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "lifetime_donation_total";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT sum(amount) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1)";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "number_of_donations";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT count(amount) FROM tb_donations WHERE tb_contacts.id = tb_donations.donor AND 1=1)";
$col["editoptions"]["type"] = 'number';
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "linkedin_profile";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_contacts.linkedin_profile";
$col["formatter"] = "url";
$col["position"] = 9;
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
