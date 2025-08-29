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
$grid["caption"] = "Working with XML RSS Datasource";
$grid["readonly"] = true;
$grid["view_options"]["width"] = 700;
$grid["ignoreCase"] = true;
$grid["rowNum"] = 200;
$grid["autowidth"] = true;
$grid["autoheight"] = true;
$grid["responsive"] = true;
$grid["tooltip"] = false;

// grouping
$grid["grouping"] = true;
$grid["groupingView"] = array();
$grid["groupingView"]["groupField"] = array("pubDate"); // specify column name to group listing
$grid["groupingView"]["groupColumnShow"] = array(false); // either show grouped column in list or not (default: true)
$grid["groupingView"]["groupText"] = array("<b>{0} - {1} Item(s)</b>"); // {0} is grouped value, {1} is count in group
$grid["groupingView"]["groupOrder"] = array("desc"); // show group in asc or desc order
$grid["groupingView"]["groupDataSorted"] = array(true); // show sorted data within group
$grid["groupingView"]["groupSummary"] = array(false); // work with summaryType, summaryTpl, see column: $col["name"] = "total"; (if false, set showSummaryOnHide to false)
$grid["groupingView"]["groupCollapse"] = false; // Turn true to show group collapse (default: false) 
$grid["groupingView"]["showSummaryOnHide"] = true; // show summary row even if group collapsed (hide) 

$g->set_options($grid);

function get_data($url) {
    $ch = curl_init();
	$timeout = 50;
	curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);

	curl_close($ch);
	return $data;
}

$xml="https://www.zdnet.com/news/rss.xml";
$xml="https://www.dawn.com/feed";

$xmlDoc = simplexml_load_string(get_data($xml));
$data = array();

$i = 1;
foreach($xmlDoc->channel->item as $item)
{	
	$d = array();
	$d["id"] = $i++;
	$d["title"] = (string)$item->title;
	$d["description"] = (string)$item->description;
	$d["link"] = (string)$item->link;
	$d["pubDate"] = (string)$item->pubDate;
	$d["pubDate"] = date("d M, Y",strtotime($d["pubDate"]));

	$d["title"] = trim($d["title"]);
	$d["description"] = strip_tags($d["description"]);
	$d["description"] = str_replace("\n"," ",$d["description"]);
	$d["link"] = "<a target='_blank' href='".$d["link"]."'>More</a>";

	$data[] = $d;
}

$titles = array("id","title","description","link","pubDate");
$cols = array();

$col = array();
$col["title"] = "Id";
$col["name"] = "id";
$col["width"] = "10";
$col["hidden"] = true;
$col["editable"] = false; // this column is not editable
$col["search"] = true; // this column is not searchable
$cols[] = $col;
	
$col = array();
$col["title"] = "Title";
$col["name"] = "title";
$col["width"] = "50";
$col["editable"] = false; // this column is not editable
$col["search"] = true; // this column is not searchable
$cols[] = $col;
	
$col = array();
$col["title"] = "Description";
$col["name"] = "description";
$col["width"] = "150";
$col["editable"] = false; // this column is not editable
$col["search"] = true; // this column is not searchable
$cols[] = $col;
	
$col = array();
$col["title"] = "Link";
$col["name"] = "link";
$col["fixed"] = true;
$col["width"] = "60";
$col["editable"] = false; // this column is not editable
$col["search"] = false; // this column is not searchable
$cols[] = $col;
	
$col = array();
$col["title"] = "Date";
$col["name"] = "pubDate";
$col["width"] = "150";
$col["editable"] = false; // this column is not editable
$col["search"] = true; // this column is not searchable
$cols[] = $col;

$g->set_columns($cols);

for ($i = 0; $i < count($data); $i++)
    $data[$i]['id'] = $i+1;

// pass data in table param for local array grid display
$g->table = $data;

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
	<style>
	/* required for minimum 1% width of view dialog caption cells */
	.ui-jqdialog-content .EditTable 
	{
		table-layout: inherit !important;
	}
	.ui-jqdialog-content .CaptionTD
	{
		vertical-align: top;
		width: 1%;
		min-width: 10%;
	}
	.ui-jqdialog-content .form-view-data
	{
		white-space: normal;
	}

	/* .ui-widget
	{
		font-family:'Roboto',tahoma,Arial,Helvetica,sans-serif; 
		font-size: 1rem;
	}
	.ui-jqgrid tr.jqgroup td {
		line-height: 35px;
	}
	.ui-jqdialog-content .form-view-data
	{
		line-height: 25px;
	}
	.ui-jqgrid .ui-jqgrid-htable .ui-jqgrid-labels th 
	{
		height: 2rem;
	} */
	</style>
	<div style="margin:10px">
		<?php echo $out?>
	</div>
</body>
</html>
