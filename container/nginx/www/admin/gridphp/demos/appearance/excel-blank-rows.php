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

$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // by default 20
$grid["scroll"] = true; // by default 20

// excel visual params
$grid["cellEdit"] = true; // inline cell editing, like spreadsheet
$grid["rownumbers"] = true;
$grid["rownumWidth"] = 30;

// optional reload grid after save cell
// $grid["afterSaveCell"] = "function() { jQuery(this).trigger('reloadGrid',[{page:999}]); }";
	
$g->set_options($grid);

// you can provide custom SQL query to display data
$g->select_command = "SELECT * FROM invheader WHERE client_id = 1";

// this db table will be used for add,edit,delete
$g->table = "invheader";

// server-validation & custom events work on excel view, but only first (pk) and changed column is available
$e["on_update"] = array("update_client", null, true);
$g->set_events($e);

function update_client($data)
{
	global $g;
	if ($data["params"]["bulk"] == "add-rows")
	{
		$rows = $data["params"]["data"];

		for($i=0;$i<$rows;$i++)
			$g->execute_query("INSERT INTO invheader (id,client_id) VALUES ('',1)");

		die;
	}
}
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
	// add toolbar button for bulk operation
	jQuery(document).ready(function(){
	
		setTimeout(()=>{
			jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager', 
			{
				'caption'      : 'Add Rows', 
				'buttonicon'   : 'ui-icon-plus', 
				'onClickButton': function()
				{
					var str = prompt("Enter Rows:");
					if (str)
						fx_bulk_update("add-rows",str, -1);
				},
				'position': 'last'
			});
		},0);

	});
	</script>	
</body>
</html>