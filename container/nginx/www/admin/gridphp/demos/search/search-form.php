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

// $grid["url"] = ""; // your paramterized URL -- defaults to REQUEST_URI
$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes
$grid["responsive"] = true; // allow you to multi-select through checkboxes

// set hidden, dont load data
// $grid["hiddengrid"] = true;

// export PDF file
$grid["export"] = array("format"=>"pdf", "filename"=>"my-file", "range"=>"filtered");

// initial search that will persist
$sarr = <<< SEARCH_JSON
{
    "groupOp":"AND",
    "rules":[
      {"field":"name","op":"cn","data":"Mar"}
     ]
}
SEARCH_JSON;
$grid["persistsearch"] = true;
$grid["postData"] = array("filters" => $sarr);


$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance", // show single/multi field search condition (e.g. simple or advance)
						"refreshstate" => "currentfilter" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name,
						i.note, i.total, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "15";
# $col["hidden"] = true; // hide column by default
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = false; // this column is not editable
$col["align"] = "center"; // this column is not editable
$col["search"] = false; // this column is not searchable
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
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // required:true(false), number:true(false), minValue:val, maxValue:val
$col["formatter"] = "date"; // format as date
$cols[] = $col;
		
$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
// default render is textbox
$col["editoptions"] = array("value"=>'10');
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"Yes:No"); // with these values "checked_value:unchecked_value"
$cols[] = $col;

# Custom made column to show link, must have default value as it's not db driven
$col = array();
$col["title"] = "Details";
$col["name"] = "view_more";
$col["width"] = "30";
$col["align"] = "center";
$col["search"] = false;
$col["sortable"] = false;
$col["link"] = "http://localhost/?id={id}"; // e.g. http://domain.com?id={id} given that, there is a column with $col["name"] = "id" exist
$col["linkoptions"] = "target='_blank'"; // extra params with <a> tag
$col["default"] = "View More"; // default link text
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// to show date from / to in exported pdf
$e["on_export"] = array("custom_export", null, true);
$g->set_events($e);

function custom_export($param)
{
	$sql = $param["sql"]; // the SQL statement for export
	$grid = $param["grid"]; // the complete grid object reference
	
	// search params
	$search_str = $grid->strip($_SESSION['jqgrid_list1_searchstr']);
	$search_arr = json_decode($search_str,true);
	
	$gopr = $search_arr['groupOp'];
	$date_from = $search_arr['rules'][0]["data"];
	$date_to = $search_arr['rules'][1]["data"];
	
	if ($grid->options["export"]["format"] == "pdf")
	{
		$grid->options["export"]["heading"] = "Report $date_from to $date_to";
	}
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
	
	<!-- library for checkbox in column chooser -->
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	
</head>
<body>

	<div style="margin:10px">
		<fieldset style="float:left; width:300px; font-family:tahoma; font-size:12px">
			<legend>Search in Name OR Date</legend>
			<form>
			Search: <input type="text" id="filter"/>
			<input type="submit" id="search_text" value="Filter">
			</form>
		</fieldset>
		<fieldset style="float:left; width:300px; font-family:tahoma; font-size:12px">
			<legend>Search Templates</legend>
			<form>
			<select id="search_tpl">
				<option value="">- Select -</option>
				<option value="f1">Year > 2017</option>
				<option value="f2">Note with Invoice</option>
				<option value="f3">All 'M' clients</option>
			</select>
			<input type="submit" id="button_search_tpl" value="Filter">
			</form>
		</fieldset>
		<fieldset style="float:left; font-family:tahoma; font-size:12px">
				<legend>Search in Date (From AND To)</legend>
				<form>
			Date from: <input class="datepicker" type="text" id="datefrom"/>
			Date to: <input class="datepicker" type="text" id="dateto"/>
			<input type="submit" id="search_date" value="Filter">
			</form>
		</fieldset>
		<div style="clear:both;margin-bottom:10px"></div>
		<fieldset style="float:left; font-family:tahoma; font-size:12px">
			<legend>Search Templates</legend>
			<form>
			<select id="search_name" multiple="multiple">
				<option>Maria Anders</option>
				<option>Ana Trujillo</option>
				<option>Antonio Moreno</option>
			</select>
			<input type="submit" id="button_search_name" value="Filter">
			</form>
		</fieldset>
		<script>
		$(function() {
			$('select[multiple]').multipleSelect()
		})
		</script>	
		<style>.ms-parent {margin-top:0px;}</style>

		<div style="clear:both;margin-bottom:10px"></div>
		<?php echo $out?>
	</div>

	<script>
    jQuery(window).load(function() {
	
		// formats: http://api.jqueryui.com/datepicker/#option-dateFormat
		jQuery(".datepicker").datepicker(
								{
								"disabled":false,
								"dateFormat":"yy-mm-dd",
								"changeMonth": true,
								"changeYear": true,
								"firstDay": 1,
								"showOn":'both'
								}
							).next('button').button({
								icons: {
									primary: 'ui-icon-calendar'
								}, text:false
							}).css({'font-size':'80%', 'margin-left':'2px', 'margin-top':'-5px'});
											
	});
	
    jQuery("#search_text").click(function() {
    	grid = jQuery("#list1");
		
		// open initially hidden grid
		// $('.ui-jqgrid-titlebar-close').click();

        var searchFilter = jQuery("#filter").val(), f;

		if (searchFilter.length === 0) 
		{
            grid[0].p.search = false;
			jQuery.extend(grid[0].p.postData,{filters:""});
		}
		else
		{
			f = {groupOp:"OR",rules:[]};
	
			// initialize search, 'name' field equal to (eq) 'Client 1'
			// operators: ['eq','ne','lt','le','gt','ge','bw','bn','in','ni','ew','en','cn','nc']
	
			f.rules.push({field:"name",op:"bw",data:searchFilter});
			f.rules.push({field:"invdate",op:"bw",data:searchFilter});
	
			grid[0].p.search = true;
			
			// if toolbar filter, keep in with external search form
			if (grid[0].p.postData.filters != undefined && grid[0].p.postData.filters != '')
			{
				var toolbar_filters = JSON.parse(grid[0].p.postData.filters);
				var combine_filters = {groupOp:"AND",rules:[],groups:[toolbar_filters,f]};
			}
			else
				var combine_filters = f;
				
			jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(combine_filters)});
		}

        grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);

        return false;
    });
	
    jQuery("#search_date").click(function() {
    	grid = jQuery("#list1");

		// open initially hidden grid
		// $('.ui-jqgrid-titlebar-close').click();
		
		if (jQuery("#datefrom").val() == '' || jQuery("#dateto").val() == '')
			return false;
			
		var f = {groupOp:"AND",rules:[]};
		if (jQuery("#datefrom").val())
        f.rules.push({field:"invdate",op:"ge",data:jQuery("#datefrom").val()});
		
		if (jQuery("#dateto").val())
        f.rules.push({field:"invdate",op:"le",data:jQuery("#dateto").val()});

		var s = {groupOp:"OR",rules:[],groups:[f]};
		s.rules.push({field:"invdate",op:"nu",data:''});
		   
        grid[0].p.search = true;
        jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(s)});

        grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);
        return false;
    });

	var search_with_tpl = function() {
    	grid = jQuery("#list1");

		// open initially hidden grid
		// $('.ui-jqgrid-titlebar-close').click();
		
		var template = jQuery("#search_tpl").val();
		if (template == "f1")
		{
			var f = {groupOp:"AND",rules:[]};
			f.rules.push({field:"invdate",op:"ge",data:'2017-01-01'});
			
		}
		else if (template == "f2")
		{
			var f = {groupOp:"AND",rules:[]};
			f.rules.push({field:"note",op:"cn",data:'invoice'});
		}
		else if (template == "f3")
		{
			var f = {groupOp:"AND",rules:[]};
			f.rules.push({field:"name",op:"bw",data:'m'});
		}
		else
			return false;

        grid[0].p.search = true;
        jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(f)});
        grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);
        return false;
    };

	var search_with_name = function() {
    	grid = jQuery("#list1");

		var template = jQuery("#search_name").val();
		if (template.length === 0) 
		{
            grid[0].p.search = false;
			jQuery.extend(grid[0].p.postData,{filters:""});
		}
		
		template = template.join(",");
		var f = {groupOp:"AND",rules:[]};
		f.rules.push({field:"name",op:"in",data:template});

        grid[0].p.search = true;
        jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(f)});
        grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);
        return false;
    };

    jQuery("#button_search_name").click(search_with_name);
    jQuery("#button_search_tpl").click(search_with_tpl);
    jQuery("#search_tpl").change(search_with_tpl);
	
	</script>

<script>
	setTimeout(() => {
		
		$(window).load( function () {
			// bind custom reload handler, required for url based filters
			$('.ui-jqgrid-toppager #refresh_list1, .ui-jqgrid-pager #refresh_list1').unbind( "click" );
			$('.ui-jqgrid-toppager #refresh_list1, .ui-jqgrid-pager #refresh_list1').click( function (event) {
				reloadGrid();
			});

		});

	}, 200);

	// new handler for reload button
	function reloadGrid()
	{
		var grid = $("#list1");
		grid.trigger("reloadGrid",[{current:true}]);
	}
</script>

</body>
</html>