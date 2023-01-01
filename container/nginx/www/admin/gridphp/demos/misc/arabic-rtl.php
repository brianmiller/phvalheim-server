
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

// set few params
$grid["caption"] = "ملازمین کی فہرست";
$grid["autowidth"] = true;
$grid["multiselect"] = true;
$grid["direction"] = "rtl";				
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "clients";

$col = array();
$col["title"] = "آئی دی";
$col["name"] = "client_id"; 
$col["width"] = "20";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "نام";
$col["name"] = "name"; 
$col["editable"] = true;
$col["width"] = "80";
$cols[] = $col;	

$col = array();
$col["title"] = "جنس";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "کمپنی کا نام";
$col["name"] = "company"; 
$col["editable"] = true;
$col["edittype"] = "textarea"; 
$col["editoptions"] = array("rows"=>2, "cols"=>20); 
$cols[] = $col;	

$col = array();
$col["title"] = "آپریشن";
$col["name"] = "act"; 
$cols[] = $col;	

$g->set_columns($cols);
			
// $g->select_command = "select * from (select * from invheader) as o";
			//,'Noto Naskh Arabic'
// render grid
$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/base/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-ur.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>
	<style>
	/*
	@import url(//fonts.googleapis.com/earlyaccess/notonaskharabic.css); 
	@import url(//fonts.googleapis.com/earlyaccess/notonaskharabicui.css); 
	*/
	@import url(//fonts.googleapis.com/earlyaccess/notosanskufiarabic.css); 
	/* RTL font customizations */
	.ui-widget
	{
		font-family:'Noto Sans Kufi Arabic','Segoe ui',tahoma,Arial,Helvetica,sans-serif; 
		font-size: 0.8rem;
	}
	.ui-jqgrid .ui-jqgrid-htable .ui-jqgrid-labels th 
	{
		/* height: 3rem; */
	}
	</style>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>
