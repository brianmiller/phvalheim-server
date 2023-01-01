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
$grid["multiselect"] = true;
$g->set_options($grid);

// set database table for CRUD operations
$g->table = "clients";

// subqueries are also supported now (v1.2)
// $g->select_command = "select * from (select * from invheader) as o";
			
// render grid
$out = $g->render("list1");

/*
js/jqgrid/js/i18n/grid.locale-en.js
js/jqgrid/js/i18n/grid.locale-fr.js
js/jqgrid/js/i18n/grid.locale-ar.js
... over 39 languages

change JS file on line 47, to your need (current is it - italian)
*/

$lang_path = strstr(realpath("."),"demos",true)."lib/js/jqgrid/js/i18n";

$cdir = scandir($lang_path);
foreach ($cdir as $key => $value)
{
  if (!in_array($value,array(".","..")))
  {
	$langs[] = $value;
  }
}

// if set from page
if (!empty($_GET["lang"]))
	$i = $_GET["lang"];
else
	$i = "grid.locale-en.js";

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/<?php echo $i?>" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>
	<div style="margin:10px">
	<form method="get">
		Language: <select name="lang" onchange="form.submit()">
		<?php foreach($langs as $k=>$t) { ?>
			<option value=<?php echo $t?> <?php echo ($i==$t)?"selected":""?>><?php echo ucwords($t)?></option>
		<?php } ?>
	</select>
	</form>
	<br>
	<?php echo $out?>
	</div>
</body>
</html>
