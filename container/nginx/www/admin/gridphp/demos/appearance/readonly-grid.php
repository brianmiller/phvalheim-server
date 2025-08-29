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

$grid["caption"] = "Report Only"; // empty will remove it
$grid["autowidth"] = true; // expand grid to screen width
$grid["readonly"] = true; // expand grid to screen width
$grid["globalsearch"] = true; // expand grid to screen width
$grid["height"] = "100%";
$grid["rowNum"] = 15; // num of rows per page

$g->set_options($grid);

// this db table will be used for add,edit,delete
$g->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id"; 
$col["width"] = "20";
$col["editable"] = false;
$col["hidden"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Name"; 
$col["name"] = "name"; 
$col["formatter"] = "function(cellvalue, options, rowObject){ return '<DIV title=\"'+rowObject.company+'\">'+cellvalue+'</DIV>'; }";
$col["unformat"] = "function(cellvalue, options, rowObject){ return $.jgrid.stripHtml(cellvalue); }";
$col["editable"] = true;
$col["width"] = "80";
$col["editoptions"] = array("size"=>20);
$cols[] = $col;	

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$col["editoptions"]["onfocus"] = "$(this).parent().css('backgroundColor','green')";
$col["editoptions"]["onblur"] = "$(this).parent().css('backgroundColor','inherit')";
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

	<style>
	/* remove top toolbar*/
	.ui-jqgrid-toppager
	{
		-display:none;
	}
	
	/* remove bottom toolbar*/
	.ui-jqgrid-pager
	{
		-display:none;
	}
	</style>
	
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>
