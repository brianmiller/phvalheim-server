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

$opt["rowNum"] = 100; // by default 20
$opt["sortname"] = 'client_id'; // by default sort grid by this field
$opt["caption"] = "Export Selected Rows / Columns"; // caption of grid
$opt["autowidth"] = true; // expand grid to screen width
$opt["multiselect"] = true; // allow you to multi-select through checkboxes

$g->set_options($opt);

$g->set_actions(array(
                        "add"=>false, // allow/disallow add
                        "edit"=>false, // allow/disallow edit
                        "delete"=>false, // allow/disallow delete
                        "rowactions"=>false, // show/hide row wise edit/del/save option
                        "search" => "advance", // show single/multi field search condition (e.g. simple or advance)
                        "autofilter" => true,
                        "export_html" => true,
                        "showhidecolumns" => true
                    )
                );

// this db table will be used for add,edit,delete
$g->table = "clients";

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css" />	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css" />
	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
	
	<script src="//cdn.jsdelivr.net/jstorage/0.1/jstorage.min.js" type="text/javascript"></script>	
	<script src="//cdn.jsdelivr.net/json2/0.1/json2.min.js" type="text/javascript"></script>
	<script src="//cdn.jsdelivr.net/gh/gridphp/jqGridState@master/jqGrid.state.js" type="text/javascript"></script>

	<!-- library for checkbox in column chooser -->
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	
	
</head>
<body>

	<script>
	var opts = {
		"stateOptions": {         
					storageKey: "gridState-showhide-list1",
					columns: true, // remember column chooser settings
					selection: true, // row selection
					expansion: false, // subgrid expansion
					filters: false, // subgrid expansion
					pager: false, // page number
					order: false // field ordering
		}
	};	
	</script>	
	
    <div style="margin:0px;">
		<?php echo $out?>
		<br>
		<button onclick="$('#list1').gridState().remove('gridState-showhide-list1'); Cookies.remove('jqgrid_colchooser_list1'); location.reload();">Forget Settings</button>
    </div>
	
	<script>
	jQuery(document).ready(function(){

		setTimeout(()=>{
			jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager', 
			{
				'caption'      : 'Export Selected', 
				'buttonicon'   : 'ui-icon-extlink', 
				'onClickButton': function()
				{
					// for selected rows
					// var rows = jQuery('#list1').jqGrid('getGridParam','selarrrow'); 
					
					// for all selected rows (across page)
					var gState = jQuery('#list1').gridState();
					var gRows = gState.selRows;
					var rows = []; 
					for(k in gRows)
					{
						if (gRows[k] == true)
							rows[rows.length] = k;
					}

					if (rows.length)
					{
						var data = rows.join();
						
						// client_id is first column and it's data will be passed as selected row ids.
						var filter = '{"rules":[{"field":"client_id","op":"in","data":"'+data+'"}]}';
						
						window.open("<?php echo $g->options["url"]?>" + "&export=1&jqgrid_page=1&export_type=pdf&_search=true&filters="+filter);
					}
					else
						alert('Select rows to export');
				},
				'position': 'last'
			});
		},0);
	});
	</script>
</body>
</html>
