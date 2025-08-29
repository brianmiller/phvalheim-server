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
$opt["caption"] = "Donations";
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
$opt["add_options"]["addCaption"] = "Add Donation";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Donation";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Donation";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_donations";
$grid->select_command = "SELECT tb_donations.id, tb_donations.gift_id, tb_donations.designated_program, tb_donations.donor, tb_donations.first_contacted, tb_donations.date_of_donation, tb_donations.amount, tb_donations.thank_you_sent, tb_donations.on_behalf_of_corporation, tb_donations.donors_employer FROM tb_donations LEFT JOIN tb_designations ON tb_designations.id = tb_donations.designated_program LEFT JOIN tb_contacts ON tb_contacts.id = tb_donations.donor LEFT JOIN tb_companies ON tb_companies.id = tb_donations.donors_employer WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_donations.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_donations.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_donations.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "gift_id";
$col["width"] = 200;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_donations.gift_id";
$col["frozen"] = true;
$col["editoptions"]["valuePattern"] = '{date_of_donation}—{donor}';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "designated_program";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_donations.designated_program";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_designations';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_designations',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=designations&grid_id=list_designations&col=id';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "donor";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_donations.donor";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_contacts';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_contacts',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=contacts&grid_id=list_contacts&col=id';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "first_contacted";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_donations.first_contacted";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "date_of_donation";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_donations.date_of_donation";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "amount";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_donations.amount";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "thank_you_sent";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_donations.thank_you_sent";
$col["formatter"] = "checkbox";
$col["edittype"] = "checkbox";
$col["editoptions"]["value"] = '1:0';
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "on_behalf_of_corporation";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_donations.on_behalf_of_corporation";
$col["formatter"] = "checkbox";
$col["edittype"] = "checkbox";
$col["editoptions"]["value"] = '1:0';
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "donors_employer";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_donations.donors_employer";
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
$col["position"] = 9;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_donations";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_donations";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Donations Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
