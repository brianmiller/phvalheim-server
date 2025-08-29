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
$opt["caption"] = "Interactions";
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
$opt["add_options"]["addCaption"] = "Add Interaction";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Interaction";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Interaction";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_interactions";
$grid->select_command = "SELECT tb_interactions.id, tb_interactions.interaction, tb_interactions.type, tb_interactions.date, tb_opportunities.status AS status, tb_interactions.opportunity, tb_interactions.contact FROM tb_interactions LEFT JOIN tb_opportunities ON tb_opportunities.id = tb_interactions.opportunity WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_interactions.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_interactions.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_interactions.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "interaction";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_interactions.interaction";
$col["frozen"] = true;
$col["editoptions"]["valuePattern"] = '{opportunity} — {type}';
$col["editoptions"]["readonly"] = 'readonly';
$col["editoptions"]["style"] = 'border:0px; margin: 3px 0 0 0;';
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "type";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_interactions.type";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Discovery:Discovery;Demo:Demo;Pricing discussion:Pricing discussion;Legal discussion:Legal discussion';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Discovery:Discovery;Demo:Demo;Pricing discussion:Pricing discussion;Legal discussion:Legal discussion';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "date";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_interactions.date";
$col["editoptions"]["defaultValue"] = date("m/d/Y h:i a");
$col["formatter"] = "datetime";
$col["formatoptions"]["srcformat"] = 'Y-m-d H:i:s';
$col["formatoptions"]["newformat"] = 'm/d/Y h:i a';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "status";
$col["width"] = 150;
$col["editable"] = false;
$col["dbname"] = "tb_opportunities.status";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct status as k, status as v from tb_interactions");;
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct status as k, status as v from tb_interactions");;
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "opportunity";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_interactions.opportunity";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_opportunities';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'opportunity_name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, opportunity_name as v from tb_opportunities',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=opportunities&grid_id=list_opportunities&col=id';
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "contact";
$col["width"] = 400;
$col["editable"] = true;
$col["dbname"] = "tb_interactions.contact";
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
$col["position"] = 7;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_interactions";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_interactions";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Interactions Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
