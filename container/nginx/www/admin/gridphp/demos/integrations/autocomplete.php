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

$grid["caption"] = "Autocomplete Demo"; // expand grid to screen width
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["form"]["position"] = "center";
$grid["view_options"] = array("width"=>"500");

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"view"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "advance", // show single/multi field search condition (e.g. simple or advance)
						"showhidecolumns" => false
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id";
$col["width"] = "20";
$cols[] = $col;	

$col = array();
$col["title"] = "Name";
$col["name"] = "name"; 
$col["editable"] = true;
$col["width"] = "80";

$col["edittype"] = "autocomplete"; // autocomplete

// typeahead same field
// $col["editoptions"] = array("sql"=>"SELECT name as k, name as v FROM clients");

// typeahead same field - force selection for list
$col["editoptions"] = array("sql"=>"SELECT name as k, name as v FROM clients", "force_select"=>true);

// auto-fill alternate field								
// $col["editoptions"] = array("sql"=>"SELECT company as k, name as v FROM clients", "update_field"=>"company");

// callback function
// $col["editoptions"] = array(	"sql"=>"SELECT *, name as v FROM clients ORDER BY name desc",
// 								"force_select"=>true,
// 								"search_on"=>"concat(name,'-',client_id)",
// 								"callback"=>"fill_form");
								
$cols[] = $col;	

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$col["edittyoe"] = "autocomplete"; // autocomplete
$col["editoptions"] = array("sql"=>"SELECT distinct gender as k, gender as v FROM clients", "force_select"=>true);
$cols[] = $col;	

$col = array();
$col["title"] = "Company";
$col["name"] = "company"; 
$col["editable"] = true;
$col["editoptions"] = array("rows"=>2, "cols"=>20); 
$cols[] = $col;	

$col = array();
$col["title"] = "Code";
$col["name"] = "client_id"; 
$col["width"] = "40";
$col["editable"] = false;
$cols[] = $col;

$g->set_columns($cols);

// generate grid output, with unique grid name as 'list1'
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
	function fill_form(data)
	{
		jQuery("input[name=gender].FormElement").val(data.gender);
		jQuery("input[name=company].FormElement").val(data.company);

		jQuery("input[name=gender].editable").val(data.gender);
		jQuery("input[name=company].editable").val(data.company);
	}

	
	</script>
	
	<style>
	.ui-autocomplete-loading { background:url('https://loading.io/assets/img/landing/curved-bars.svg') #FFFFFF no-repeat right center !important; }
	ul.ui-autocomplete {
    background: #efefef;
	}
	</style>
</body>
</html>