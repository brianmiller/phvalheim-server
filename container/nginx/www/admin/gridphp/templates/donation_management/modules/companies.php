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
$opt["caption"] = "Companies";
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
$opt["add_options"]["addCaption"] = "Add Company";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Company";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Company";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_companies";
$grid->select_command = "SELECT tb_companies.id, tb_companies.name, tb_companies.logo, (SELECT group_concat(id) FROM tb_contacts WHERE tb_companies.id = tb_contacts.associated_companies AND 1=1) AS contacts FROM tb_companies  WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_companies.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_companies.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_companies.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_companies.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "logo";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_companies.logo";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "contacts";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT group_concat(id) FROM tb_contacts WHERE tb_companies.id = tb_contacts.associated_companies AND 1=1)";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name as v from tb_contacts");
$col["editoptions"]["multiple"] = true;
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_contacts',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=contacts&grid_id=list_contacts&col=id';
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT id as k, name as v from tb_contacts");
$col["searchoptions"]["sopt"] = array("cn");
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 3;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_companies";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_companies";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Companies Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
