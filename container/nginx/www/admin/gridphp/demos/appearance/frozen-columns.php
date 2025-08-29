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

$grid["caption"] = "Employees"; // caption of grid
$grid["height"] = 300;
$grid["shrinkToFit"] = false; // dont shrink to fit on screen
$grid["sortable"] = false; // it is required for freezed column feature

$g->set_options($grid);

// disable all dialogs
$g->navgrid["param"]["edit"] = false;
$g->navgrid["param"]["add"] = false;
$g->navgrid["param"]["del"] = true;
$g->navgrid["param"]["search"] = false;
$g->navgrid["param"]["refresh"] = true;

// enable inline editing buttons
$g->set_actions(array(
        "inline"=>true,
        "rowactions"=>true
    )
);

// this db table will be used for add,edit,delete
$g->table = "employees";

// you can customize your own columns ...
$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "employee_id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "60";
$col["frozen"] = true;
$col["editable"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Lastname"; // caption of column
$col["name"] = "last_name";
$col["width"] = "80";
$col["frozen"] = true;
$col["editable"] = true;
$col["show"] = array("edit"=>true); // only show freezed column in edit dialog
$cols[] = $col;

$col = array();
$col["title"] = "First name"; // caption of column
$col["name"] = "first_name";
$col["width"] = "80";
$col["frozen"] = true;
$col["editable"] = true;
$col["show"] = array("edit"=>true); // only show freezed column in edit dialog
$cols[] = $col;

// only change defined rest as it is. 
$g->set_columns($cols,true);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css">
    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.bs.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.6/umd/popper.min.js" integrity="sha384-wHAiFfRlMFy6i5SRaxvfOCifBUQy1xHdJ/yoi7FRNXMRBu5WHdZYu1hA6ZOblgut" crossorigin="anonymous"></script>

    <script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/js/bootstrap.min.js" integrity="sha384-B0UglyR+jN6CkvvICOB2joaf5I4l3gm9GU6Hc1og6Ls7i6U/mkkaduKaBhlAXv9k" crossorigin="anonymous"></script>

    <script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
    <script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
    <script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>

	<style>
	/* fix for freeze column div position */
	.ui-jqgrid .editable {margin: 0px !important;}
	</style>
	
	<div style="margin:10px">
	<?php echo $out?>
	</div>

	<script>
	jQuery(document).ready(function(){

		setTimeout(()=>{
			jQuery('#list1').jqGrid('navButtonAdd', '#list1_toppager', 
			{
				'caption'      : 'Toggle Freeze', 
				'buttonicon'   : 'ui-icon-extlink', 
				'onClickButton': function()
				{
					var t;
					if (jQuery('div.frozen-bdiv').length == 0)
					{
						fx_freeze_grid('list1');
					}
					else
					{
						jQuery('#list1').jqGrid('destroyFrozenColumns');
					}				
				},
				'position': 'last'
			});
			jQuery(".ui-icon-plus").click(function(){ jQuery('#list1').jqGrid('destroyFrozenColumns'); });

		},500);
	});
	</script>
	
	
</body>
</html>