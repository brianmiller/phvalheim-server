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

$opt = array();
$opt["rowNum"] = 10; // by default 20
$opt["sortname"] = 'id'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["caption"] = "Invoice Data"; // caption of grid
$opt["autowidth"] = true; // expand grid to screen width
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["rowactions"] = true; // allow you to multi-select through checkboxes
$opt["loadComplete"] = "function(ids){load_sparklines(ids);}"; // allow you to multi-select through checkboxes
$opt["view_options"]["beforeShowForm"] = 'function (form) { setTimeout("load_sparklines()",200); }';
				
// change order of operands in search dialog
$opt["search_options"]["sopt"] = array('cn','eq','ne','lt','le','gt','ge','bw','bn','in','ni','ew','en','cn','nc','nu','nn');

$g->set_options($opt);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name,
						i.note, i.total, (i.total/10) as bar, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";


// you can customize your own columns ...
$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "20";
# $col["hidden"] = true; // hide column by default
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = false; // this column is not editable
$col["search"] = false; // this column is not searchable

# $col["formatter"] = "image"; // format as image -- if data is image url e.g. http://<domain>/test.jpg
# $col["formatoptions"] = array("width"=>'20',"height"=>'30'); // image width / height etc

$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
# $col["width"] = "300"; // not specifying width will expand to fill space
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea"; // render as textarea on edit
$col["editoptions"] = array("rows"=>2, "cols"=>20); // with these attributes
// don't show this column in list, but in edit/add mode
$col["hidden"] = true;
$col["editrules"] = array("edithidden"=>true); 

$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "30";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // required:true(false), number:true(false), minValue:val, maxValue:val
$col["formatter"] = "date"; // format as date
$cols[] = $col;
		
		
$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "20";
$col["editable"] = true;
// default render is textbox
$col["editoptions"] = array("value"=>'10');

$cols[] = $col;
		
$col = array();
$col["title"] = "Trend 1";
$col["name"] = "trend1";
$col["width"] = "20";
$col["align"] = "center";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<span id='spark1_{id}' class='spark1'>..........</span>";
$cols[] = $col;
		
$col = array();
$col["title"] = "Trend 2";
$col["name"] = "trend2";
$col["width"] = "20";
$col["align"] = "center";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<span id='spark2_{id}' class='spark2'></span>";
$cols[] = $col;
		
$col = array();
$col["title"] = "Trend 3";
$col["name"] = "trend3";
$col["width"] = "20";
$col["align"] = "center";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<span sparkType='bullet' class='sparklines' values='1,2,3,4,5,4,3,2,1'></span>";
$cols[] = $col;

$col = array();
$col["title"] = "W/L";
$col["name"] = "trend_winloss";
$col["width"] = "20";
$col["align"] = "center";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<span sparkType='tristate' class='sparklines' values='1,0,1,-1,1,-1,0,0,1,1'></span>";
$cols[] = $col;

# Custom made column to show bar graph
$col = array();
$col["title"] = "Performance";
$col["name"] = "bar";
$col["width"] = "40";
$col["align"] = "left";
$col["search"] = false;
$col["sortable"] = false;
$col["default"] = "<div style='width:{bar}%; background-color:#3366CC; height:14px'></div>";
$cols[] = $col;

$col = array();
$col["title"] = "Trend 4";
$col["name"] = "trend4";
$col["width"] = "20";
$col["align"] = "center";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<span sparkType='box' sparkColor='violet' class='sparklines' values='4,27,34,52,54,59,61,68,78,82,85,87,91,93,100'></span>";
$cols[] = $col;

// pass the cooked columns to grid
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
	<div style="margin:10px">
	<?php echo $out?>
	</div>
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-sparklines/2.1.2/jquery.sparkline.min.js"></script>
	<script>
	function load_sparklines()
	{
		// for more config & charts: https://omnipotent.net/jquery.sparkline/#s-about
		
		$(".sparklines").sparkline('html', { enableTagOptions: true, width:'100%' });
			
		$(".spark1").each(function(){
			
			var t = $(this).attr("data");
			
			var max = 10;
			var min = 1;
			
			var nums = new Array;
			for (var e=0; e<10; e++) {
			nums[e] = (Math.round((max-min) * Math.random() + min))
			}
	
			$(this).sparkline(nums, {type: 'line', width: '100%'});
		});
		
		$(".spark2").each(function(){
			
			var t = $(this).attr("data");
			
			var max = 100;
			var min = 10;
			
			var nums = new Array;
			for (var e=0; e<10; e++) {
			nums[e] = (Math.round((max-min) * Math.random() + min))
			}
	
			$(this).sparkline(nums, {type: 'bar', barColor:'green', width: '100%'});
		});
		
		// fit in view dialog
		$(".FormGrid .DataTD canvas").css({'max-width':'25%'});
		// remove &nbsp; space from view dialog
		$(".FormGrid .DataTD").each(function(){$(this)[0].childNodes[0].remove();});
	}
	</script>
	
</body>
</html>