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
$opt["caption"] = "Opportunities";
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
$opt["add_options"]["addCaption"] = "Add Opportunity";
$opt["add_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["edit_options"]["width"] = 800;
$opt["edit_options"]["editCaption"] = "Edit Opportunity";
$opt["edit_options"]["afterShowForm"] = 'function (form) { $("input,select,textarea",form).trigger("loadform"); }';
$opt["view_options"]["width"] = 800;
$opt["view_options"]["caption"] = "View Opportunity";
$opt["view_options"]["beforeShowForm"] = 'function (form) { unlink_dialog_lookup(form);}';

// Make it readonly for restricted role
if (!has_access("editing")) $opt["readonly"] = true;

$grid->set_options($opt);

// grid properties
$grid->table = "tb_opportunities";
$grid->select_command = "SELECT tb_opportunities.id, tb_opportunities.opportunity_name, tb_opportunities.status, tb_opportunities.priority, tb_opportunities.estimated_value, tb_opportunities.owner, tb_opportunities.account, tb_opportunities.primary_contact, tb_opportunities.proposal_deadline, tb_opportunities.expected_close_date, tb_opportunities.last_contact, (SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_interactions WHERE tb_opportunities.id = tb_interactions.opportunity AND 1=1) AS interactions FROM tb_opportunities LEFT JOIN tb_accounts ON tb_accounts.id = tb_opportunities.account LEFT JOIN tb_contacts ON tb_contacts.id = tb_opportunities.primary_contact WHERE 1=1 ";


// column settings
$cols = array();

$col = array();
$col["name"] = "id";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_opportunities.id";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "updated_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_opportunities.updated_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "created_at";
$col["width"] = 150;
$col["hidden"] = true;
$col["dbname"] = "tb_opportunities.created_at";
$col["frozen"] = true;
$cols[] = $col;

$col = array();
$col["name"] = "opportunity_name";
$col["width"] = 150;
$col["editable"] = true;
$col["formatter"] = "rowbar";
$col["dbname"] = "tb_opportunities.opportunity_name";
$col["frozen"] = true;
$col["position"] = 1;
$cols[] = $col;

$col = array();
$col["name"] = "status";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.status";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Qualification:Qualification;Proposal:Proposal;Evaluation:Evaluation;Negotiation:Negotiation;Closed—won:Closed—won;Closed—lost:Closed—lost';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Qualification:Qualification;Proposal:Proposal;Evaluation:Evaluation;Negotiation:Negotiation;Closed—won:Closed—won;Closed—lost:Closed—lost';
$col["position"] = 2;
$cols[] = $col;

$col = array();
$col["name"] = "priority";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.priority";
$col["formatter"] = "badge";
$col["edittype"] = "select";
$col["editoptions"]["value"] = 'Medium:Medium;Very high:Very high;Very low:Very low;Low:Low;High:High';
$col["stype"] = "select";
$col["searchoptions"]["value"] = 'Medium:Medium;Very high:Very high;Very low:Very low;Low:Low;High:High';
$col["position"] = 3;
$cols[] = $col;

$col = array();
$col["name"] = "estimated_value";
$col["width"] = 100;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.estimated_value";
$col["editoptions"]["type"] = 'number';
$col["formatter"] = "currency";
$col["position"] = 4;
$cols[] = $col;

$col = array();
$col["name"] = "owner";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.owner";
$col["position"] = 5;
$cols[] = $col;

$col = array();
$col["name"] = "account";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.account";
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
$col["position"] = 6;
$cols[] = $col;

$col = array();
$col["name"] = "primary_contact";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.primary_contact";
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
$col["position"] = 7;
$cols[] = $col;

$col = array();
$col["name"] = "proposal_deadline";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.proposal_deadline";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 8;
$cols[] = $col;

$col = array();
$col["name"] = "expected_close_date";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.expected_close_date";
$col["formatter"] = "date";
$col["formatoptions"]["srcformat"] = 'Y-m-d';
$col["formatoptions"]["newformat"] = 'm/d/Y';
$col["position"] = 9;
$cols[] = $col;

$col = array();
$col["name"] = "last_contact";
$col["width"] = 150;
$col["editable"] = true;
$col["dbname"] = "tb_opportunities.last_contact";
$col["position"] = 10;
$cols[] = $col;

$col = array();
$col["name"] = "interactions";
$col["width"] = 400;
$col["editable"] = false;
$col["dbname"] = "(SELECT GROUP_CONCAT(id ORDER BY id DESC) FROM tb_interactions WHERE tb_opportunities.id = tb_interactions.opportunity AND 1=1)";
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
$col["position"] = 11;
$cols[] = $col;

$grid->set_columns($cols,true);

$grid_id = "list_opportunities";

// template variables
$var = array();
$var["out_grid"] = $grid->render($grid_id);
$var["grid_id"] = "list_opportunities";
$var["grid_theme"] = "base";
$var["locale"] = "en";
$var["form_title"] = "Opportunities Management";
$var["form_details"] = "";
$var["tab_class"] = "";


// if loaded in iframe, use content layout (without header)
if ($_GET["iframe"] == "1")
	$layout = "content";

return $var;
