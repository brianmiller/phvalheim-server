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

$grid["caption"] = "Client Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["reloadedit"] = true;

$grid["loadComplete"] = "function(ids) { grid_onload(ids); }";

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "clients";


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
$col["edittype"] = "textarea"; 
$col["editoptions"] = array("rows"=>2, "cols"=>20); 
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
	<script>
	function grid_onload(ids)
	{
		var low;
		var low_index;
		var high;
		var high_index;
		
		if(ids.rows) 
			jQuery.each(ids.rows,function(i)
			{
				// used when scroll:true
				var gid = "list1";
				if (typeof(jQuery('#'+gid).data('jqgrid_rows')) != 'undefined')
					i = i + jQuery('#'+gid).data('jqgrid_rows');
			
				// find lowest value
				if (low == undefined || parseInt(this.client_id) < low)
				{
					low = parseInt(this.client_id);
					low_index = i;
				}	
			
				// find highest value
				if (high == undefined || parseInt(this.client_id) > high)
				{
					high = parseInt(this.client_id);
					high_index = i;
				}	
			
				// if gender = male and client_id > 10
				if (this.gender.toLowerCase() == 'male' && parseInt(this.client_id) > 5)
				{
					// highlight row
					jQuery('#list1 tr.jqgrow:eq('+i+')').css('background-image','inherit').css({'background-color':'#FBEC88', 'color':'green'});
				}

				// if clientId between 1 & 5, format cell. see 'aria-describedby=list1_client_id' is 'gridid_colname' convention to identify cell.
				if (parseInt(this.client_id) > 1 && parseInt(this.client_id) < 5)
				{
					// highlight cell
					jQuery('#list1 tr.jqgrow:eq('+i+')').css('background-image','inherit');
					jQuery('#list1 tr.jqgrow:eq('+i+') td[aria-describedby=list1_client_id]').css('background','inherit').css({'background-color':'#FBEC88', 'color':'green'});
				}
			});
			
			// highlight lowest client id
			jQuery('#list1 tr.jqgrow:eq('+low_index+')').css('background-image','inherit');
			jQuery('#list1 tr.jqgrow:eq('+low_index+') td[aria-describedby=list1_name]').css('background','inherit').css({'background-color':'orange', 'color':'white'});

			// highlight highest client id
			jQuery('#list1 tr.jqgrow:eq('+high_index+')').css('background-image','inherit');
			jQuery('#list1 tr.jqgrow:eq('+high_index+') td[aria-describedby=list1_name]').css('background','inherit').css({'background-color':'red', 'color':'white'});
	}
	</script>
	<div style="margin:10px">
	<br>
	<?php echo $out?>
	</div>
</body>
</html>
