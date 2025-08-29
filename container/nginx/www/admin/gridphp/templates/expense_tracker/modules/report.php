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
$opt["caption"] = "Report";
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
$opt["grouping"] = 1;
$opt["groupingView"]["groupField"] = array('month');
$opt["groupingView"]["groupColumnShow"] = array(false);
$opt["groupingView"]["groupText"] = array('<div style=\'float:right;margin-right:5px;font-weight:normal;font-size:12px;line-height:28px;\'>Count {1}</div><div style=\'font-size:14px;font-weight:500;overflow:hidden;text-overflow:ellipsis;width:auto;\'>{0}</div>');
$opt["groupingView"]["groupOrder"] = array('desc');
$opt["groupingView"]["groupDataSorted"] = array(true);
$opt["groupingView"]["groupSummary"] = array(true);
$opt["groupingView"]["groupCollapse"] = false;
$opt["groupingView"]["showSummaryOnHide"] = true;

// Customize add/edit/view dialogs
$opt["add_options"]["width"] = 800;
$opt["add_options"]["addCaption"] = "Add Report";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Report";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Report";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_transactions";
$grid->select_command = "SELECT tb_transactions.id, type AS type, tb_transactions.amount, tb_transactions.category, tb_transactions.account, tb_transactions.notes, tb_transactions.date, MONTHNAME(date) AS month, tb_transactions.attachments, tb_transactions.details FROM tb_transactions LEFT JOIN tb_categories ON tb_categories.id = tb_transactions.category LEFT JOIN tb_accounts ON tb_accounts.id = tb_transactions.account WHERE 1=1  AND (type = 'Expense')";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_transactions.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_transactions.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_transactions.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "trx_id";
$col["width"] = 200;
$col["editable"] = false;
$col["formatter"] = "rowbar";
$col["dbname"] = "concat(tb_transactions.id,' ',notes)";
$col["frozen"] = true;
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["template"] = "#{id} - {amount} {notes}";
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "type";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "type";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Expense:Expense;Income:Income';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Expense:Expense;Income:Income';
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "amount";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.amount";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "category";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.category";
$col["editoptions"]["defaultValue"] = 'Food - Groceries';
$col["editoptions"]["table"] = 'tb_categories';
$col["editoptions"]["id"] = 'id';
$col["editoptions"]["label"] = 'name';
$col["editoptions"]["onload"] = array (
  'sql' => 'select distinct id as k, name as v from tb_categories',
);
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["isnull"] = "";
$col["badgeoptions"]["editurl"] = 'index.php?mod=categories&grid_id=list_categories&col=id';
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "account";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.account";
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
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "notes";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.notes";
$col["formatter"] = "autocomplete";
$col["formatoptions"]["sql"] = 'SELECT distinct notes as k, notes as v FROM tb_transactions';
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "date";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.date";
$col["editoptions"]["defaultValue"] = date("m/d/Y h:i a");
$col["formatter"] = "datetime";
$col["formatoptions"]["srcformat"] = 'Y-m-d H:i:s';
$col["formatoptions"]["newformat"] = 'm/d/Y h:i a';
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "month";
$col["width"] = 150;
$col["editable"] = false;
$col["dbname"] = "MONTHNAME(date)";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$col["hidden"] = true;
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "attachments";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.attachments";
$col["edittype"] = "file";
$col["upload_dir"] = "temp";
$col["editrules"]["ifexist"] = 'rename';
$col["editrules"]["allowedext"] = 'pdf,png,gif,bmp,jpeg,jpg,doc,xls,docx,xlsx,pptx,csv,md,zip';
$col["editrules"]["allowedsize"] = 31457280;
$col["editoptions"]["multiple"] = 'multiple';
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "details";
$col["width"] = 200;
$col["editable"] = true;
$col["dbname"] = "tb_transactions.details";
$col["edittype"] = "textarea";
$col["editoptions"]["style"] = 'height:150px';
$col["hidden"] = true;
$col["editrules"]["edithidden"] = true;
$col["position"] = 10;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_transactions";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_transactions";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Report Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
