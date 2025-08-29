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

$url="https://www.zdnet.com/news/rss.xml";
// $url="https://www.dawn.com/feed";

if (isset($_GET["url"]))
	$url = $_GET["url"];

$xmlDoc = simplexml_load_string(get_data($url));
$channel = (string) $xmlDoc->channel->title;

$g = new jqgrid();

// set few params
$grid["caption"] = "RSS Reader: $channel - Grid 4 PHP Framework";
$grid["readonly"] = true;
$grid["view_options"]["width"] = "800";
$grid["ignoreCase"] = true;
$grid["rowNum"] = 200;
$grid["autowidth"] = true;
$grid["height"] = "500";
$grid["responsive"] = true;
$grid["tooltip"] = false;
$grid["form"]["nav"] = true;

// grouping
$grid["grouping"] = true;
$grid["groupingView"] = array();
$grid["groupingView"]["groupField"] = array("pubDate"); // specify column name to group listing
$grid["groupingView"]["groupColumnShow"] = array(false); // either show grouped column in list or not (default: true)
$grid["groupingView"]["groupText"] = array("<b>{0} - {1} Item(s)</b>"); // {0} is grouped value, {1} is count in group
$grid["groupingView"]["groupOrder"] = array("desc");
$grid["groupingView"]["groupDataSorted"] = array(true); // show sorted data within group
$grid["groupingView"]["groupSummary"] = array(false); // work with summaryType, summaryTpl, see column: $col["name"] = "total"; (if false, set showSummaryOnHide to false)
$grid["groupingView"]["groupCollapse"] = false; // Turn true to show group collapse (default: false) 
$grid["groupingView"]["showSummaryOnHide"] = true; // show summary row even if group collapsed (hide) 

$g->set_options($grid);

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
$col["sorttype"] = "date";
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
$out = $g->render("list_rss");
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
		line-height:25px;
	}
	.ui-jqdialog-content .form-view-data
	{
		white-space: normal;
		line-height:25px;
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
	
	<script>
	var opts = {
		'ondblClickRow': function (id) {
			jQuery(this).jqGrid('viewGridRow', id, <?php echo json_encode_jsfunc($g->options["view_options"])?>);
		}
	};
	</script>
	
	<div style="margin:10px;">
		<form id="form_rss" style='display:none;font-family: arial, tahoma; margin: 10px 0px'>
			RSS Feed: <input size="30" type="text" name="url" value="<?php echo $url ?>" />
			<input type="submit" value="Refresh">
		</form>
		<?php echo $out?>
	</div>
	
	<script>
    jQuery(window).load(function() {

		var fshtml = jQuery("#form_rss")[0].outerHTML;
		jQuery("#form_rss").remove();
		// show dropdown in toolbar
		jQuery('.navtable tr:first').append('<td><div style="padding-left: 5px; padding-top:0px;">'+fshtml+'</div></td>');
		jQuery("#form_rss").show();
	});	
	</script>
</body>
</html>
