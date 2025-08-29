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

$grid["caption"] = "Sample Grid";
$grid["autowidth"] = true;
	
$g->set_options($grid);

$g->table = "clients";

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
	
	<script src="//cdn.jsdelivr.net/gh/jeresig/jquery.hotkeys@master/jquery.hotkeys.js" type="text/javascript"></script>
	
	<script>
	// more help here: https://github.com/jeresig/jquery.hotkeys
	jQuery(document).ready(function(){
		
		// where list1 is your grid id
		$(document).bind('keydown', 'e', function assets() {
			$('#edit_list1').click();
			return false;
		});		
		
		$(document).bind('keydown', 'a', function assets() {
			$('#add_list1').click();
			return false;
		});
		
		$(document).bind('keydown', 'd', function assets() {
			$('#del_list1').click();
			return false;
		});

	});
	</script>
	
</head>
<body>
	<div>
	<?php echo $out?>
	</div>

	
</body>
</html>
