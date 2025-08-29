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

$g = new jqgrid();

// set few params
$grid["caption"] = "Working with JSON Data - Stackoverflow.com Tag: php";
$grid["readonly"] = true;
$grid["ignoreCase"] = true;
$g->set_options($grid);

function get_data($url) {
    $ch = curl_init();
	$timeout = 50;
	curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_ENCODING,'gzip'); // needed by stackexchange api
    $data = curl_exec($ch);

	curl_close($ch);
	return $data;
}

$json_url = "http://api.stackexchange.com/2.2/questions?order=desc&sort=activity&tagged=php&site=stackoverflow";
$data = json_decode(get_data($json_url),true);

$data = $data["items"];

$i=1;
foreach($data as &$item)
{
	foreach($item as $k => &$v)
	{
		if ($k == "tags")
			$v = implode(", ",$v);
		
		if ($k == "owner")
			$v = $v["display_name"];
				
	}

	$item["id"] = $i++;
	$item["link"] = "<a target='_blank' href='".$item["link"]."'>Web link</a>";
}
	
$titles = array("title","owner","tags","view_count","link");
$cols = array();
foreach($titles as $k)
{
	$col = array();
	$col["title"] = ucwords($k);
	$col["name"] = "$k";
	$col["width"] = "50";
	$col["editable"] = false; // this column is not editable
	$col["search"] = true; // this column is not searchable
	$cols[] = $col;
}
$g->set_columns($cols);

// little customization in columns
$g->set_prop("title","title","Question");
$g->set_prop("title","width","200");
$g->set_prop("view_count","title","Views");

// pass data in table param for local array grid display
$g->table = $data; // blank array(); will show no records

// // custom events for rest services
// $e["on_insert"] = array("on_insert", null, true);
// $e["on_update"] = array("on_update", null, true);
// $e["on_delete"] = array("on_delete", null, true);
// $g->set_events($e);

// function on_delete($data)
// {
// }

// function on_insert($data)
// {
// }

// function on_update($data)
// {
// }

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
</body>
</html>
