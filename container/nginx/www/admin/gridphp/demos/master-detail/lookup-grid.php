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

// master grid
$grid = new jqgrid($db_conf);

$opt["caption"] = "Clients Data";

$opt["edit_options"]["afterShowForm"] = 'function (form) {

	function initLookupGrid(baseGridId, baseField, detailGridId, detailField)
	{
		var $btn = $(\'<a href="#"><span class="ui-icon ui-icon-search"></span></a>\')
			.addClass("fm-button ui-state-default")
			.css({"padding": "4px", "margin": "0px 5px 3px 5px", "vertical-align": "bottom"})
			.click(function() {

				searchVal = jQuery("[name="+baseField+"]",form).val();
				jQuery("#gbox_"+detailGridId+" #gs_"+detailField).val(searchVal);
				jQuery("#"+detailGridId)[0].triggerToolbar();

				jQuery(document).unbind("keypress").unbind("keydown").unbind("mousedown");
				
				jQuery.fancybox.open({href: "#lookup_"+detailGridId,
					beforeShow : function() { eval("phpgrid_"+detailGridId+".fx_grid_resize();"); }, 
					afterClose : function() {

						// read selected value from list2 grid, if using for subgrid use jQuery("table[id$=list2]")

						if (jQuery("#"+detailGridId).jqGrid("getGridParam","multiselect"))
						{
							var selr = jQuery("#"+detailGridId).jqGrid("getGridParam","selarrrow");

							if (selr != null) 
							{
								idRow = selr;

								var arr = idRow.toString().split(",");
								var data = new Array();
								for(i in arr)
								data[data.length] = jQuery("#list2").jqGrid("getCell", arr[i], fldSrc);
								data = data.join(", ");

								// and set in edit form field of list1
								jQuery("[name="+fldDest+"].FormElement").val(data);
							}
						}
						else
						{
							var selr = jQuery("#"+detailGridId).jqGrid("getGridParam","selrow");

							if (selr != null) 
							{
								var data;
								idRow = selr;
								data = jQuery("#"+detailGridId).jqGrid("getCell", idRow, detailField);
								// and set in edit form field of list1
								jQuery("[name="+baseField+"].FormElement",form).val(data);
							}
						}

					}
				});


			});

		var $btn1 = $btn.clone(true);
		$("[name="+baseField+"].FormElement",form).parent().append(" ").append($btn1);
		$("[name="+baseField+"].FormElement",form).css("width","calc(88% - 35px)")
	}

	// baseField, baseGridId, detailField, detailGridId
	initLookupGrid("list1", "company", "list2", "name");
}';

$opt["add_options"]["afterShowForm"] = $opt["edit_options"]["afterShowForm"];

$grid->set_options($opt);
$grid->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id"; 
$col["width"] = "20";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Name";
$col["name"] = "name"; 
$col["editable"] = true;
$col["width"] = "80";
$cols[] = $col;	

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Company";
$col["name"] = "company"; 
$col["editable"] = true;
$cols[] = $col;	

$grid->set_columns($cols);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

$out_master = $grid->render("list1");

// ---------------------------------------------------------------------------------
// detail grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["sortname"] = 'id'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["caption"] = "Stores"; // caption of grid

$opt["onSelectRow"] = "function(){ jQuery.fancybox.close(); }";
$grid->set_options($opt);

$grid->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => true // show single/multi field search condition (e.g. simple or advance)
					)
				);
				
$grid->table = "store";

// generate grid output, with unique grid name as 'list1'
$out_detail = $grid->render("list2");
?>
<!DOCTYPE html>
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
	
	<!-- Add fancyBox main JS and CSS files -->
	<link type="text/css" rel="stylesheet" href="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.css" />
	<script type="text/javascript" src="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.js"></script>
</head>
<body>
	<style>
		/* required for add/edit dialog overlapping */
		.fancybox-overlay { z-index:943 !important; }
		#editmodlist1.ui-jqdialog { z-index: 942 !important; }
	</style>

	<div style="margin:10px">
	<?php echo $out_master ?>
	</div>

	<!-- set id of container div to lookup_{gridid} e.g. lookup_list2 -->
	<div id='lookup_list2' style='display:none; width:70vw'>
	<?php echo $out_detail?>
	</div>

</body>
</html>