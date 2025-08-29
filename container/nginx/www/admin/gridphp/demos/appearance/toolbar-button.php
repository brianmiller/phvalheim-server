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

$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "desc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes

$grid["loadComplete"] = "function(){ gridLoad(); }";

$g->set_options($grid);

$g->set_actions(array(
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"export_pdf"=>true,
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => false // show single/multi field search condition (e.g. simple or advance)
					)
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name FROM invheader i INNER JOIN clients c ON c.client_id = i.client_id";

if ($_GET["query"]==1)
	$g->select_command = "SELECT i.id, invdate , c.name FROM invheader i INNER JOIN clients c ON c.client_id = i.client_id
							WHERE c.name like 'm%'";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id";
$col["width"] = "10";
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$col["export"] = false; // this column will not be exported
$col["link"] = "http://localhost/?id={id}";
$col["linkoptions"] = "target='_blank'";
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate";
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["search"] = false;
$cols[] = $col;

// pass the cooked columns to grid
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

	<script type="text/javascript">
	// Add toolbar buttons with 100ms delay to complete grid setup properly
	jQuery("document").ready(function(){
		/*
			CUSTOM TOOLBAR BUTTON
			---------------------
			caption: (string) the caption of the button, can be a empty string.
			buttonicon: (string) is the ui icon name from UI theme icon set. If this option is set to 'none' only the text appear.
			onClickButton: (function) action to be performed when a button is clicked. Default null.
			position: ('first' or 'last') the position where the button will be added (i.e., before or after the standard buttons).
			title: (string) a tooltip for the button.
			cursor : string (default pointer) determines the cursor when we mouseover the element
			id : string (optional) - if set defines the id of the button (actually the id of TD element) for future manipulation
		*/
    	setTimeout(()=>{
			jQuery("#list1").jqGrid('navButtonAdd',"#list1_pager",{caption:"Autofilter",title:"Toggle Search Toolbar", buttonicon :'ui-icon-search',
				'onClickButton':function(){
					jQuery("#list1")[0].toggleToolbar();
				}
			});

			jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager',
			{
				'caption'      : 'Find',
				'buttonicon'   : 'ui-icon-search',
				'onClickButton': function()
				{
					// open search dialog via code
					$("#list1").jqGrid ('searchGrid', <?php echo json_encode_jsfunc($g->options["search_options"])?>);
				},
				'position': 'last'
			});

			jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager',
			{
				'id'      		: 'emailSelected',
				'caption'      	: 'Email Selected',
				'buttonicon'   	: 'ui-icon-extlink',
				'onClickButton'	: function()
				{
					// for all ids
					// var allRowsOnCurrentPage = $('#list1').jqGrid('getDataIDs');

					// don't process if nothing is selected
					var ids = jQuery('#list1').jqGrid('getGridParam','selarrrow');
					if (ids.length == 0)
					{
						jQuery.jgrid.info_dialog(jQuery.jgrid.errors.errcap,'<div class=\"ui-state-error\">'+jQuery.jgrid.nav.alerttext+'</div>',
													jQuery.jgrid.edit.bClose,{buttonalign:'right'});
						return;
					}

					// for selected rows
					var selectedRows = jQuery('#list1').jqGrid('getGridParam','selarrrow');
					alert('Simulating: Passing id of selected row to a page with email sending code ...')
					window.open("sendemail.php?id="+selectedRows);
				},
				'position': 'last'
			});

			// show checkbox in toolbar
			jQuery('.navtable tr').append('<td><div style="padding-left: 5px; padding-top:0px;"><label><input class="filtermore" type="checkbox" /><span style="float:right;padding-left:4px; padding-top:1px;">Filter</span></label></div></td>');
			jQuery(".filtermore").click(function(){

				var type = ($(this)[0].checked)?1:0;

				grid = jQuery("#list1");

				if (type == 1)
					grid.data('jqgrid_detail_grid_params','&query=1');
				else
					grid.data('jqgrid_detail_grid_params','');

				grid[0].p.search = false;
				jQuery.extend(grid[0].p.postData,{'query':type});

				grid.trigger("reloadGrid",[{page:1}]);
			});

			setTimeout( ()=> { 

				jQuery('#list1_filtersearch').before("<button style='margin-top:-5px; border-color: transparent;' id='list1_circle' type='button'>xxx</button>");
			jQuery("#list1_circle").uibutton({ icons: { primary: "ui-icon-bars fa fa-circle" },text: false }).click(function ()
			{
				alert('circle');
				return false;
			});

			}, 200);

		},10);
	});

	function gridLoad()
	{
		var rows =  $('#list1').getRowData();
		for (var i=0;i<rows.length;i++)
		{
			if (rows[i].id == '399')
			{
				$("#emailSelected").remove();
			}
		}
	}	
	</script>
</body>
</html>
