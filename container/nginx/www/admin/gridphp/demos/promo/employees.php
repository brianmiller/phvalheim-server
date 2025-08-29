<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

include_once("../../config.php");

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

$grid["caption"] = "Sample Employees Grid";

// in case of first column as auto-increment
$grid["hidefirst"] = true;
$grid["autocolumn"] = 3;

$grid["view_options"] = array("jqModal" => false, "modal" => true, "closeOnEscape"=>true, "recreateForm"=>true, "width" => 400,
								"rowButton" => true, "labelswidth" => "20%"
								);

$grid["view_options"]["beforeShowForm"] = 'function (form) 
				{
					// edit form button on view dialog
	        		$(\'<a href="#">Edit<span class="ui-icon ui-icon-disk"></span></a>\')
	            	.addClass("fm-button ui-state-default ui-corner-all fm-button-icon-left")
	              	.prependTo("#Act_Buttons>td.EditButton")
	              	.click(function() 
							{
		                		jQuery("#cData").click();
		                		jQuery("#edit_list1").click();
		            		});
						
					// set view dialog caption
					$(".viewhdlist1 .ui-jqdialog-title").html("View");

					// increase colspan for notes
					jQuery("#trv_notes td.DataTD:first").attr("colspan",4);
					jQuery("#trv_notes td.DataTD:first").next().remove();
					jQuery("#trv_notes td.DataTD:first").next().remove();
					jQuery("#trv_notes td.DataTD:first").next().remove();
					jQuery("#trv_notes td.DataTD:first").next().remove();
				}';

$g->set_options($grid);
$g->table = "employees";

$col = array();
$col["title"] = "Notes";
$col["name"] = "notes";
$col["formoptions"]["rowpos"] = 12;
$col["formoptions"]["colpos"] = 1;
$col["show"]["list"] = false;
$col["editoptions"]["dataInit"] = "function(o){ jQuery(o).parent().attr('colspan',10); jQuery(o).css('width','100%').css('height','100px'); }";
$cols[] = $col;

$col = array();
$col["title"] = "Reports To";
$col["name"] = "reports_to";
$col["edittype"] = "lookup";
$col["align"] = "left";
$col["editoptions"] = array("table"=>"employees", "id"=>"employee_id", "label"=>"concat(first_name,' ',last_name)");
$cols[] = $col;

$col = array();
$col["title"] = "Photo";
$col["name"] = "photo";
$col["editable"] = false;
$col["template"] = "<img src='https://via.placeholder.com/150' />";
$col["show"]["list"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Photo Path";
$col["name"] = "photo_path";
$col["hidden"] = true;
$cols[] = $col;

$g->set_columns($cols,true);

$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/material/jquery-ui.custom.css" />
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css" />

	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	
</head>
<body>
	<div>
	<?php echo $out?>
	</div>
</body>
</html>
