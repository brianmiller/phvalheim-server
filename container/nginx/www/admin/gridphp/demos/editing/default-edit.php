<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

// NOTE: Default edit mode only work with inline editing

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
$grid["rowNum"] = "10";
$grid["height"] = "";
$grid["rowheight"] = "25";
$grid["loadComplete"] = "function(){ grid_onload(); }";
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "invheader";


$col = array();
$col["title"] = "Id";
$col["name"] = "id"; 
$col["width"] = "10";
$cols[] = $col;		

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true;
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$cols[] = $col;
		
$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["editoptions"] = array("rows"=>2, "cols"=>50); // with these attributes
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"1:0"); // with these values "checked_value:unchecked_value"
$cols[] = $col;

// pass the cooked columns to grid
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

	<script type="text/javascript">
	jQuery(document).ready(function(){

		jQuery("#list1").jqGrid('navButtonAdd',"#list1_pager",{caption:"Edit All",title:"Edit All", buttonicon :'ui-icon-pencil',
			'onClickButton':function(){
				edit_all();
			}
		});

		jQuery("#list1").jqGrid('navButtonAdd',"#list1_pager",{caption:"Save All",title:"Save All", buttonicon :'ui-icon-save',
			'onClickButton':function(){
				save_all();
			}
		});

	});
	
	var changed_ids = new Array();
	function grid_onload() 
	{
		edit_all();
		
		// up/down/tab/shift-tab navigation
		$('#list1').keydown(function (e) {
			var $td = $(e.target).closest("td"),
				$tr = $td.closest("tr.jqgrow"),
				ci, ri, rows = this.rows;
			if ($td.length === 0 || $tr.length === 0) {
				return;
			}
			ci = $.jgrid.getCellIndex($td[0]);
			ri = $tr[0].rowIndex;
			if (e.keyCode === $.ui.keyCode.UP) { // 38
				if (ri > 0) {
					$(rows[ri-1].cells[ci]).find("input,select,textarea").focus();
				}
			}
			if (e.keyCode === $.ui.keyCode.DOWN) { // 40
				if (ri + 1 < rows.length) {
					$(rows[ri+1].cells[ci]).find("input,select,textarea").focus();
				}
			}
		});				
	};

	function save_all()
	{
		var $this = jQuery("#list1"), ids = $this.jqGrid('getDataIDs'), i, l = ids.length;
		for (i = 0; i < l; i++) {
			id = ids[i];

			if (jQuery.inArray(id,changed_ids) != -1)
				jQuery("#list1").jqGrid('saveRow', id);
			else
				jQuery("#list1").jqGrid('restoreRow', id);
			
			jQuery('#edit_row_list1_'+id).show();
			jQuery('#save_row_list1_'+id).hide();				
		}
	}
	
	function edit_all()
	{
		// reset array
		changed_ids = new Array();
		var $this = $("#list1"), ids = $this.jqGrid('getDataIDs'), i, l = ids.length;
		for (i = 0; i < l; i++) {
			// list1 is the name of grid, passed in ->render() function
			id = ids[i];
			jQuery('#list1').editRow(id, true, function(){}, function(){

													if (jQuery('#edit_row_list1_').val() != undefined)
													{
														jQuery('#edit_row_list1_'+id).show();
														jQuery('#save_row_list1_'+id).hide();
													}
													return true;

												},null,null,function(){
												},null,
												function(){

													if (jQuery('#edit_row_list1_').val() != undefined)
													{
														jQuery('#edit_row_list1_'+id).show();
														jQuery('#save_row_list1_'+id).hide();
													}
													return true;
												}
						); 
			
			jQuery('#edit_row_list1_'+id).hide();
			jQuery('#save_row_list1_'+id).show();
		}
		
		// set focus on first cell
		setTimeout('jQuery(".editable:first").focus()',100);

		jQuery(".editable").keydown(function(){
			var id = $(this).closest("tr").attr("id");
			changed_ids[changed_ids.length] = id;
		});

		jQuery(".editable").mousedown(function(){
			var id = $(this).closest("tr").attr("id");
			changed_ids[changed_ids.length] = id;
		});		
	}
	
	// todo - not to change icon on save and keep in edit mode
	// $.jgrid.inlineEdit.aftersavefunc = function(rowid)
		// { 
			// // setTimeout('$("#edit_row_list1_3").click();',1000);
			// jQuery("#list1").jqGrid('editRow', rowid); 
			// jQuery('#edit_row_list1_'+rowid).hide();
			// jQuery('#save_row_list1_'+rowid).show();		
		// }

	</script>
	<button onclick="edit_all()">Edit All</button>
	<button onclick="save_all()">Save All</button>
</body>
</html>