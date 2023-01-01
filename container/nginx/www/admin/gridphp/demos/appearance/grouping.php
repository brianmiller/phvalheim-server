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

$grid["rowNum"] = 99; // by default 20
$grid["caption"] = "Orders"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes
$grid["reloadedit"] = true; // auto reload after editing
$grid["footerrow"] = true; // Show footer row
$grid["userDataOnFooter"] = true; // Fill footer row with userdata (with on_data_display event)

// to use footerdata without formatter, comment above and use this
$grid["loadComplete"] = 'function(){ var userData = jQuery("#list1").jqGrid("getGridParam","userData"); jQuery("#list1").jqGrid("footerData","set",userData,false); }';

// grouping
$grid["grouping"] = true;
$grid["groupingView"] = array();
$grid["groupingView"]["groupField"] = array("ship_name"); // specify column name to group listing
$grid["groupingView"]["groupColumnShow"] = array(true); // either show grouped column in list or not (default: true)
$grid["groupingView"]["groupText"] = array("<b>{0} - {1} Item(s)</b>"); // {0} is grouped value, {1} is count in group
$grid["groupingView"]["groupOrder"] = array("desc"); // show group in asc or desc order
$grid["groupingView"]["groupDataSorted"] = array(true); // show sorted data within group
$grid["groupingView"]["groupSummary"] = array(true); // work with summaryType, summaryTpl, see column: $col["name"] = "total"; (if false, set showSummaryOnHide to false)
$grid["groupingView"]["groupCollapse"] = false; // Turn true to show group collapse (default: false) 
$grid["groupingView"]["showSummaryOnHide"] = true; // show summary row even if group collapsed (hide) 

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "orders";

$col = array();
$col["title"] = "Total";
$col["name"] = "freight";
$col["width"] = "70";
$col["editable"] = true;
$col["formatter"] = "number";
$col["summaryType"] = "sum"; // available grouping fx: sum, count, min, max, avg
$col["summaryRound"] = 2; // decimal places
$col["summaryRoundType"] = 'fixed'; // round or fixed
$col["summaryTpl"] = 'Total ${0}'; // display html for summary row - work when "groupSummary" is set true. search below
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols,true);

$grid_id = "list1";
// generate grid output, with unique grid name as 'list1'
$out = $g->render($grid_id);
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
	<style>
	/* change color of group text */
	.jqgroup b {
		color: navy;
		line-height: 25px;
	}
	
	.jqgroup .ui-icon {
		font-size: 2em;
	}
	
	.ui-jqgrid tr.jqgroup td
	{
		background-color: lightyellow;
	}
	</style>
	<div style="margin:10px">
	Dynamic Group By: 
	<select class="chngroup">
		<?php foreach($g->options["colModel"] as $c) { ?>
		<option value="<?php echo $c["name"] ?>"><?php echo $c["title"] ?></option>
		<?php } ?>
		<option value="clear">Clear</option> 
	</select>
	<button id="toggleGroup">Toggle Grouping</button>
	<script>
	jQuery(window).load(function()
	{
		// show dropdown in toolbar
		jQuery('.navtable tr:first').append('<td><div style="padding-left: 5px; padding-top:0px; float:left"><select style="height:24px" class="chngroup"><option value="clear" >-Group-</option><?php foreach($g->options["colModel"] as $c) { if($c["title"] !='Action'){?><option value="<?php echo $c["name"] ?>" <?php echo ($c["name"]=="role")?"selected":"" ?>><?php echo $c["title"] ?></option><?php }} ?></select></div></td>');

		var grid_id = '<?php echo $g->id ?>';
		
		jQuery(".chngroup").change(function()
		{
			var vl = jQuery(this).val(); 
			if(vl) 
			{ 
				if(vl == "clear") 
					jQuery("#"+grid_id).jqGrid('groupingRemove',true); 
				else 
					jQuery("#"+grid_id).jqGrid('groupingGroupBy',vl); 
			} 
		});
		
		// http://www.trirand.com/jqgridwiki/doku.php?id=wiki:grouping#methods
		jQuery("#toggleGroup").click(function()
		{
			jQuery("."+grid_id+"ghead_0").each(function(){
				jQuery("#"+grid_id).jqGrid('groupingToggle',jQuery(this).attr('id')); 
			});
		});
	});
	</script>			
	<br>
	<br>
	<?php echo $out?>
	</div>	
</body>
</html>
