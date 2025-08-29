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

// Database config file to be passed in phpgrid constructor
$db_conf = array( 	
					"type" 		=> PHPGRID_DBTYPE, 
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$g = new jqgrid($db_conf);

// set few params
$grid["caption"] = "Sample Grid";
$grid["multiselect"] = true;

// show add row on load and on save reload
$grid["loadComplete"] = "function(){ $('#list1_iladd').click(); }";
$g->set_options($grid);

// disable all dialogs except edit
$g->navgrid["param"]["edit"] = false;
$g->navgrid["param"]["add"] = false;
$g->navgrid["param"]["del"] = false;
$g->navgrid["param"]["search"] = false;
$g->navgrid["param"]["refresh"] = true;

// enable inline editing buttons
$g->set_actions(array(	
						"inline"=>true,
						"rowactions"=>true
					) 
				);
			
// set database table for CRUD operations
$g->select_command = "select invheader.*,clients.name from invheader, clients where invheader.client_id = clients.client_id";
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "10";
$cols[] = $col;		

$col = array();
$col["title"] = "Client Id"; // caption of column
$col["name"] = "client_id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "10";
$col["editable"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Client Name"; // caption of column
$col["name"] = "name"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "10";
$col["editable"] = true;

$col["formatter"] = "autocomplete"; // autocomplete

// callback function
$col["formatoptions"] = array(	"sql"=>"SELECT client_id as k, name as v FROM clients ORDER BY name desc",
								"search_on"=>"concat(name,'-',client_id)",
								"update_field"=>"client_id");
								
$cols[] = $col;		

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "30";
$col["editable"] = true; // this column is editable
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$cols[] = $col;

$col = array();
$col["title"] = "Amount"; // caption of column
$col["name"] = "amount"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "30";
$col["editable"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Total"; // caption of column
$col["name"] = "total"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "30";
$col["editable"] = true;
$col["editrules"] = array("required"=>true, "readonly"=>true, "readonly-when"=>array(">=","10"));
$cols[] = $col;		

$col = array();
$col["title"] = "Note"; // caption of column
$col["name"] = "note"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "50";
$col["editable"] = true;
$cols[] = $col;		

$g->set_columns($cols);

// render grid
$out = $g->render("list1");
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
	<?php echo $out?>
	</div>
	
	<script>
	// js event before save
	jQuery.extend(jQuery.jgrid.inlineEdit, {
			restoreAfterError: false, // used to keep edit mode after input error
			beforeSaveRow: function (o,rowid) {
				// alert('before save inline event');
				return true;
			}
		});
		
	// for quick save, save on tab focus
	setInterval('quick_save()',1000);
	function quick_save(){ 
		jQuery('a.ui-icon-disk').focus(function(){ this.click(); }); 
	}
	</script>
</body>
</html>
