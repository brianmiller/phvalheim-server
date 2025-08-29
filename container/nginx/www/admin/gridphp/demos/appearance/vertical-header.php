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
$grid["caption"] = "Sample Grid";
$grid["autowidth"] = true;

$grid["cmTemplate"] = array("align"=>"left");

$g->set_options($grid);

// set database table for CRUD operations
$g->table = "customers";

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
	
	<script src="//cdn.jsdelivr.net/raphael/2.1.2/raphael-min.js"></script>
</head>
<body>
	<style>
	</style>
	<script>
		$(window).load(function(){
			var font = {font: 'inherit'};
			var weight = {'font-weight':'bold'};
			var fill = {fill: "#1D5987"};
			$('.ui-th-column div').each(function (index, div){

				str = $(div).text();
				if (!str) return;

				$(div).text('');
				$(div).find('span').hide();
				R = Raphael($(div).attr('id'), 70, 105); // canvas size width x height
				R.text(4, 100, str) // starting x and y offset 
					.attr(font)
					.attr(fill)
					.attr(weight)
					.rotate(-60, true) // rotation angle
					.attr({'text-anchor': 'start'});
			});
		});
	</script>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>
