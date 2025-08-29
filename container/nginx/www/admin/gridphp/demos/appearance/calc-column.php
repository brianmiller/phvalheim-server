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

$opt["sortname"] = 'client_id'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["caption"] = "Clients Data"; // caption of grid
$opt["autowidth"] = true; // expand grid to screen width
$opt["multiselect"] = false; // allow you to multi-select through checkboxes
$opt["reloadedit"] = true;

$opt["export"] = array("format"=>"pdf", "filename"=>"my-file", "heading"=>"Invoice Details", "orientation"=>"landscape", "paper"=>"a4");

// export pdf using html renderer
$opt["export"]["render_type"] = "html";
$g->set_options($opt);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"export" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT * FROM clients";

// this db table will be used for add,edit,delete
$g->table = "clients";

// grid columns
$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "client_id"; 
$col["width"] = "10";
$col["editable"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Formula"; // caption of column
$col["name"] = "cal_field"; 
$col["width"] = "20";
$col["editable"] = false;
$cols[] = $col;	

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Diff";
$col["name"] = "diff";
$col["width"] = "20";
$col["search"] = true;
$col["editable"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Calc Text";
$col["name"] = "newcol";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = false;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

$e["on_data_display"] = array("filter_display", null, true);
$e["on_render_pdf"] = array("set_pdf_format", null);
$g->set_events($e);

function filter_display($data)
{
	foreach($data["params"] as &$d)
	{
		$d["cal_field"] = "<a target='_blank' href='http://test/index.php?i=".md5($d["client_id"])."'>Click Me</a>";
		$d["newcol"] = "Hello, ".$d["name"] . " ({$d["client_id"]})";
		$d["diff"] = 200-$d["client_id"];
	}
}

function set_pdf_format($param)
{
	$opt = $param["grid"];
	$arr = $param["data"];

	$html .= "<h1>".$opt->options["export"]["heading"]."</h1>";
	$html .= '<table border="0" cellpadding="4" cellspacing="2">';
	
	$i = 0;
	foreach($arr as $v)
	{
		$shade = ($i++ % 2) ? 'bgcolor="#efefef"' : '';
		$html .= "<tr>";
		foreach($v as $d)
		{
			// bold header
			if  ($i == 1)
				$html .= "<td bgcolor=\"lightgrey\"><strong>$d</strong></td>";
			else
				$html .= "<td $shade>$d</td>";
		}
		$html .= "</tr>";
	}

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
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>