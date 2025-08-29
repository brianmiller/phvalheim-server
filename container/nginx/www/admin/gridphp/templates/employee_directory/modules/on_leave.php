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
$opt["caption"] = "On Leave";
$opt["sortname"] = "id";
$opt["sortorder"] = "ASC";
$opt["readonly"] = true;
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
$opt["grouping"] = 1;
$opt["groupingView"]["groupField"] = array('location');
$opt["groupingView"]["groupColumnShow"] = array(false);
$opt["groupingView"]["groupText"] = array('<div style=\'float:right;margin-right:5px;font-weight:normal;font-size:12px;line-height:28px;\'>Count {1}</div><div style=\'font-size:14px;font-weight:500;overflow:hidden;text-overflow:ellipsis;width:auto;\'>{0}</div>');
$opt["groupingView"]["groupOrder"] = array('desc');
$opt["groupingView"]["groupDataSorted"] = array(true);
$opt["groupingView"]["groupSummary"] = array(true);
$opt["groupingView"]["groupCollapse"] = false;
$opt["groupingView"]["showSummaryOnHide"] = true;

// Customize add/edit/view dialogs
$opt["add_options"]["width"] = 800;
$opt["add_options"]["addCaption"] = "Add On Leave";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit On Leave";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View On Leave";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_employees";
$grid->select_command = "SELECT tb_employees.id, tb_employees.name, tb_employees.photo, tb_employees.department, tb_employees.location, tb_employees.title, tb_employees.status, tb_employees.start_date, tb_employees.reports_to, tb_employees.email_address, tb_employees.phone, tb_employees.home_address, tb_employees.dob FROM tb_employees LEFT JOIN tb_departments ON tb_departments.id = tb_employees.department LEFT JOIN tb_employees AS employees1 ON employees1.id = tb_employees.reports_to WHERE 1=1  AND (tb_employees.status='On leave')";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_employees.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_employees.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_employees.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_employees.name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "photo";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_employees.photo";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "department";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.department";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["editoptions"]["table"] = 'tb_departments';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_departments',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=departments&grid_id=list_departments&col=id';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "location";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.location";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct location as k, location as v from tb_employees");;
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct location as k, location as v from tb_employees");;
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "title";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.title";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "status";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.status";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct status as k, status as v from tb_employees");;
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct status as k, status as v from tb_employees");;
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "start_date";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.start_date";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "reports_to";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.reports_to";
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = true;
$col["editoptions"]["table"] = 'tb_employees';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_employees',
);
$col["badgeoptions"]["editurl"] = 'index.php?mod=employees&grid_id=list_employees&col=id';
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "email_address";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.email_address";
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "phone";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.phone";
$col["formatter"] = "phone";
$col["position"] = 10;
$cols[] = $col;

$col = array();
$col["name"] = "home_address";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.home_address";
$col["position"] = 11;
$cols[] = $col;

$col = array();
$col["name"] = "dob";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_employees.dob";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 12;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_employees";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_employees";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "On Leave Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
