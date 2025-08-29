<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

// include db config
include_once("../../config.php");

// include and create object
include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// master grid
// Database config file to be passed in phpgrid constructor
$db_conf = array(
					"type" 		=> PHPGRID_DBTYPE,
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$grid = new jqgrid($db_conf);

$opt["caption"] = "Clients Data";
$opt["height"] = "150";

$opt["detail_grid_id"] = "list2";
$opt["subgridparams"] = "client_id,gender,company";
$opt["hidefirst"] = true;
$opt["multiselect"] = true;

// keep multiselect only by checkbox, otherwise single selection
$opt["multiboxonly"] = true;

// disable detail grid import if client_id 5 is selected
$opt["onSelectRow"] = "function(rid){
    var rowdata = $('#list1').getRowData(rid);
    if (rowdata.client_id == 5)
        jQuery('#list2_pager #import_list2, #list2_toppager #import_list2').addClass('ui-state-disabled');
    else	
        jQuery('#list2_pager #import_list2, #list2_toppager #import_list2').removeClass('ui-state-disabled');
}";

$opt["beforeGrid"] = "function(){ $.jgrid.nav.addtext = 'Add Master Record'; }";
$grid->set_options($opt);
$grid->table = "clients";

$cols = array();

$col = array();
$col["name"] = "name";
$col["title"] = "Name";
$col["stype"] = "select";
$col["searchoptions"]["value"] = $grid->get_dropdown_values("SELECT distinct name as k, name as v FROM clients");
$cols[] = $col;

$grid->set_columns($cols,true);
$out_master = $grid->render("list1");

// detail grid
$grid = new jqgrid($db_conf);

// receive id, selected row of parent grid
// check if comma sep numeric ids
$re = '/^([0-9]+[,]?)+$/';
preg_match_all($re, $_GET["rowid"], $matches);
if (count($matches[0]))
    $id = $_GET["rowid"];
else
    $id = intval($_GET["rowid"]);

$gender = $_GET["gender"];
$company = utf8_encode($_GET["company"]); // if passed param contains utf8
// $company = urldecode($_GET["company"]); // if passed param contains utf8
// $company = iconv("ISO-8859-1", "UTF-8", $_GET["company"]);

$opt = array();

$opt["beforeGrid"] = "function(){ $.jgrid.nav.addtext = 'Add Detail Record'; }";
$opt["datatype"] = "local"; // stop loading detail grid at start
$opt["height"] = ""; // autofit height of subgrid
$opt["caption"] = "Invoice Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["reloadedit"] = true; // reload after inline edit
$opt["hidefirst"] = true;

// fill detail grid add dialog with master grid id
$opt["add_options"]["afterShowForm"] = 'function(frm) { 
                                                    var selr = jQuery("#list1").jqGrid("getGridParam","selrow");  
                                                    var n = jQuery("#list1").jqGrid("getCell",selr,"name");  
                                                    jQuery("#client_id",frm).val( n ) 
                                                }';

// reload master after detail update
$opt["onAfterSave"] = "function(){ jQuery('#list1').trigger('reloadGrid',[{current:true}]); }";

$opt["delete_options"]["afterSubmit"] = 'function(response) { if(response.status == 200)
                                                                                {
                                                                                    jQuery("#list1").trigger("reloadGrid",[{current:true}]);
                                                                                    return [true,""];
                                                                                }
                                                                            }';

$grid->set_options($opt);

// and use in sql for filteration
$grid->select_command = "SELECT id,i.client_id,invdate,amount,tax,note,total FROM invheader i
                            INNER JOIN clients ON clients.client_id = i.client_id
                            WHERE i.client_id IN ($id)";

$grid->table = "invheader";

$cols = array();

$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "i.client_id";
$col["width"] = "100";
$col["align"] = "left";
$col["search"] = true;
$col["editable"] = true;
$col["editoptions"] = array("readonly"=>"readonly");
$col["show"] = array("list"=>false,"edit"=>true,"add"=>true,"view"=>false);
$cols[] = $col;


$col = array();
$col["title"] = "Company"; // caption of column
$col["name"] = "company"; // field name, must be exactly same as with SQL prefix or db field
$col["width"] = "100";
$col["editable"] = false;
$col["show"] = array("list"=>true,"edit"=>true,"add"=>false,"view"=>false);
$cols[] = $col;

$col = array();
$col["title"] = "Invoices";
$col["name"] = "note";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$col["edittype"] = "select";
$str = $grid->get_dropdown_values("select distinct note as k, note as v from invheader");
$col["editoptions"] = array("value"=>":;".$str);
$cols[] = $col;

$grid->set_columns($cols,true);

$e["on_insert"] = array("add_client", null, true);
$e["on_update"] = array("update_client", null, true);
$grid->set_events($e);

function add_client(&$data)
{
    $id = intval($_GET["rowid"]);
    $data["params"]["client_id"] = $id;
    $data["params"]["total"] = $data["params"]["amount"] + $data["params"]["tax"];
}

function update_client(&$data)
{
    $id = intval($_GET["rowid"]);
    $g = $_GET["gender"] . ' client note';
    $data["params"]["note"] = $g;
    $data["params"]["client_id"] = $id;
    $data["params"]["total"] = $data["params"]["amount"] + $data["params"]["tax"];
}
// generate grid output, with unique grid name as 'list1'
$out_detail = $grid->render("list2");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>
    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>
    <script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
    <script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
    <script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
	</head>
<body>
	
    <div style="margin:10px">
        Master Detail Grid, on same page
    	<button onclick='$("#master_div").load("?grid_id=list1&oper=ajaxload");'>Reload Master Structure & Data</button>
        <br><br>
    
    	<div id="master_div">
            <?php echo $out_master ?>
        </div>
    	<br>
        <div>
            <?php echo $out_detail; ?>
        </div>
    </div>

</body>
</html>
