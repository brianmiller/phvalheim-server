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
$grid->select_command = "SELECT tb_accounts.id, tb_accounts.name, (SELECT sum(amount) FROM tb_transactions WHERE tb_accounts.id = tb_transactions.account AND category IN (select id FROM tb_categories where type = 'Income')) AS income, (SELECT sum(amount) FROM tb_transactions WHERE tb_accounts.id = tb_transactions.account AND category IN (select id FROM tb_categories where type = 'Expense')) AS expense, (SELECT income-expense) AS balance FROM tb_accounts  WHERE 1=1 ";


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
$cols[] = $col;

$col = array();
$col["name"] = "income";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT sum(amount) FROM tb_transactions WHERE tb_accounts.id = tb_transactions.account AND category IN (select id FROM tb_categories where type = 'Income'))";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$cols[] = $col;

$col = array();
$col["name"] = "expense";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT sum(amount) FROM tb_transactions WHERE tb_accounts.id = tb_transactions.account AND category IN (select id FROM tb_categories where type = 'Expense'))";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
$cols[] = $col;

$col = array();
$col["name"] = "balance";
$col["width"] = 100;
$col["editable"] = false;
$col["dbname"] = "(SELECT income-expense)";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["show"]["add"] = false;
$col["show"]["view"] = true;
$col["show"]["edit"] = false;
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
