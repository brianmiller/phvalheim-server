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

$opt["rowNum"] = 10; // by default 20
$opt["sortname"] = 'id'; // by default sort grid by this field
$opt["sortorder"] = "asc"; // ASC or DESC
$opt["caption"] = "Footer Row"; // caption of grid
$opt["autowidth"] = true; // expand grid to screen width
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["footerrow"] = true;
$opt["reloadedit"] = true;

// use html renderer
$opt["export"]["render_type"] = "html";

$opt["onSelectAll"] = "function(id,status){ grid_onselect(); }";
$g->set_options($opt);

$g->set_actions(array(
                        "add"=>true, // allow/disallow add
                        "edit"=>true, // allow/disallow edit
                        "delete"=>true, // allow/disallow delete
                        "rowactions"=>true, // show/hide row wise edit/del/save option
                        "search" => "advance", // show single/multi field search condition (e.g. simple or advance)
                        "autofilter" => true
                    )
                );
				
// you can provide custom SQL query to display data
$g->select_command = "SELECT id,invdate,note,total,client_id FROM invheader";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$cols = array();

$col = array();
$col["title"] = "id";
$col["name"] = "id";
$col["width"] = "20";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "invdate";
$col["name"] = "invdate";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "total";
$col["name"] = "total";
$col["width"] = "100";
$col["editable"] = true;
$col["formatter"] = "currency";
$col["formatoptions"] = array("prefix" => "€",
                                "suffix" =>"",
                                "thousandsSeparator" => ",",
                                "decimalSeparator" => ".",
                                "decimalPlaces" => 2);
$cols[] = $col;

// virtual column for running total
$col = array();
$col["title"] = "running_total";
$col["name"] = "running_total";
$col["width"] = "100";
$col["hidden"] = true;
$cols[] = $col;

// virtual column for grand total
$col = array();
$col["title"] = "table_total";
$col["name"] = "table_total";
$col["width"] = "100";
$col["hidden"] = true;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);


// If Profit is < than zero, highlight cell with red
$f = array();
$f["column"] = "total";
$f["op"] = "<";
$f["value"] = "0";
$f["cellcss"] = "'color':'#f00'";
$f_conditions[] = $f;
$g->set_conditional_css($f_conditions);

// running total calculation
$e = array();
$e["on_data_display"] = array("pre_render","",true);
$e["on_render_pdf"] = array("render_pdf", null, true);

$e["js_on_select_row"] = "grid_onselect";
$e["js_on_load_complete"] = "grid_onload";

$g->set_events($e);

function pre_render($data)
{
	$rows = $_GET["jqgrid_page"] * $_GET["rows"];
	$sidx = $_GET['sidx']; // get index row - i.e. user click to sort
	$sord = $_GET['sord']; // get the direction
	
	// to where filtered data
	$swhere = "WHERE 1=1 ".$_SESSION["jqgrid_list1_filter"];

	global $g;
	
	// running total
	$result = $g->execute_query("SELECT SUM(total) as s FROM (SELECT total FROM invheader $swhere ORDER BY $sidx $sord LIMIT $rows) AS tmp");
	$rs = $result->GetRows();
	$rs = $rs[0];
	foreach($data["params"] as &$d)
	{
		$d["running_total"] = $rs["s"];
	}
	
	// table total (with filter)
	$result = $g->execute_query("SELECT SUM(total) as s FROM (SELECT total FROM invheader $swhere) AS tmp");
	$rs = $result->GetRows();
	$rs = $rs[0];
	foreach($data["params"] as &$d)
	{
		$d["table_total"] = $rs["s"];
	}	
}

// custom on_export callback function
function render_pdf($param)
{
	$grid = $param["grid"];
	$arr = $param["data"];

	$html .= "<h1>".$grid->options["export"]["heading"]."</h1>";
	$html .= '<table border="0" cellpadding="4" cellspacing="2">';
	
	$i = 0;
	$total = 0;
	foreach($arr as $v)
	{
		$shade = ($i++ % 2) ? 'bgcolor="#efefef"' : '';
		$html .= "<tr>";
		foreach($v as $k=>$d)
		{
			if ($k == 'total')
				$total += floatval($d);
				
			// bold header
			if  ($i == 1)
				$html .= "<td bgcolor=\"lightgrey\"><strong>".ucwords($d)."</strong></td>";
			else
				$html .= "<td $shade>$d</td>";
		}
		$html .= "</tr>";
	}
	
	
	$html .= "<tr bgcolor=\"lightgrey\"><td></td><td></td><td align='right'><strong>Total: $total</strong></td><td></td><td></td></tr>";

	$html .= "</table>";
	
	return $html;
}

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
    <div style="margin:10px;">
	<script>
	// e.g. to show footer summary
	function grid_onload() 
	{
		var grid = $("#list1");

		// initially select few records
		// grid.jqGrid('setSelection', 2, true);
		// grid.jqGrid('setSelection', 5, true);
		// grid.jqGrid('setSelection', 6, true);
		
		// sum of displayed result
		sum = grid.jqGrid('getCol', 'total', false, 'sum'); // 'sum, 'avg', 'count' (use count-1 as it count footer row).

		// sum of running total records
		sum_running = grid.jqGrid('getCol', 'running_total')[0];

		// sum of total records
		sum_table = grid.jqGrid('getCol', 'table_total')[0];

		// record count
		c = grid.jqGrid('getCol', 'id', false, 'count');

		sum = Number(sum).toLocaleString('en-US', { style: 'currency', currency: 'GBP' });
		sum_running = Number(sum_running).toLocaleString('en-US', { style: 'currency', currency: 'EUR' });
		sum_table = Number(sum_table).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

		// 4th arg value of false will disable the using of formatter
		grid.jqGrid('footerData','set', {note: 'Total: ' + sum, invdate: 'Sub Total: '+sum_running, total: 'Grand Total: '+sum_table}, false);
	};
	
	// e.g. to update footer summary on selection
	function grid_onselect() 
	{

		var grid = $("#list1");

		var t = 0;
		var selr = grid.jqGrid('getGridParam','selarrrow'); // array of id's of the selected rows when multiselect options is true. Empty array if not selection 
		for (var x=0;x<selr.length;x++)
		{
			t += parseFloat(grid.jqGrid('getCell', selr[x], 'total'));
		}

		t = Number(t).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

		grid.jqGrid('footerData','set', {invdate: 'Selected Total: '+ t }, false);
	};
	
	function select_all()
	{
		jQuery('#list1').jqGrid('resetSelection');
		var ids = jQuery('#list1').jqGrid('getDataIDs');
		for (i = 0; i < ids.length; i++) {
			jQuery('#list1').jqGrid('setSelection', ids[i], true);
		}	
	}
	</script>
    <?php echo $out?>
    </div>
	<style>
		/* highlight grand total */
		.footrow td[title*="Grand"] { color: red; }
	</style>
	<br>
	<button onclick="select_all()">Select All</button>
</body>
</html>