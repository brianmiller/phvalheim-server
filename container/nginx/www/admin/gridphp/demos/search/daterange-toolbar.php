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
$grid["persistSearch"] = true; 

// set hidden, dont load data
// $grid["hiddengrid"] = true;

// export PDF file
$grid["export"] = array("format"=>"pdf", "filename"=>"my-file", "range"=>"filtered");

// date_default_timezone_set('America/New_York');
$datetime = new DateTime('-6 month');
$v1 = $datetime->format('Y-m-d');
$datetime = new DateTime('tomorrow');
$v2 = $datetime->format('Y-m-d');

// for retaining old values
if ($g->get_searched_value("list1","invdate","ge"))
	$v1 = $g->get_searched_value("list1","invdate","ge");
if ($g->get_searched_value("list1","invdate","le"))
	$v2 = $g->get_searched_value("list1","invdate","le");

$val = '{"start":"'.$v1.'","end":"'.$v2.'"}';
$sarr = <<< SEARCH_JSON
{
    "groupOp":"AND",
    "rules":[
      {"field":"invdate","op":"ge","data":"{$v1}"},
      {"field":"invdate","op":"le","data":"{$v2}"}
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
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
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
$col["search"] = true; // this column is not searchable
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
$col["search"] = false;
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
	
	<link rel="stylesheet" type="text/css" media="screen" href="//cdn.jsdelivr.net/gh/tamble/jquery-ui-daterangepicker@0.5.0/jquery.comiseo.daterangepicker.css" />	
	<script src="//cdnjs.cloudflare.com/ajax/libs/moment.js/2.13.0/moment.min.js" type="text/javascript"></script>
	<script src="//cdn.jsdelivr.net/gh/tamble/jquery-ui-daterangepicker@0.5.0/jquery.comiseo.daterangepicker.min.js" type="text/javascript"></script>

</head>
<body>

	<div style="margin:10px">
		<fieldset id="fsdaterange" style="display:none;float:left; font-family:tahoma; font-size:12px">
				<form>
					<input name="invdate" class="daterangepicker" type="text" id="daterangefilter" value='<?php echo $val ?>'/>
				</form>
		</fieldset>
		<div style="clear:both;margin-bottom:10px"></div>
		<?php echo $out?>
	</div>

	<script>
	function link_daterange_picker(el,opts)
	{
		// http://tamble.github.io/jquery-ui-daterangepicker/
		opts = (typeof(opts) == 'undefined') ? {} : opts;
		jQuery(el).daterangepicker(jQuery.extend({	
			'change': function() { 
				grid = jQuery("#list1");
				grid[0].p.search = true;

				// append in old filter
				var f = JSON.parse(grid[0].p.postData.filters);
				if (!f) f = {groupOp:"AND",rules:[]};
				
				var field = jQuery(el).attr("name");
				var vals = JSON.parse(jQuery(el).val());

				f.rules.push({"field":field,"op":"ge","data":vals.start});
				f.rules.push({"field":field,"op":"le","data":vals.end});
								
				jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(f)});
				grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);
			}, 
			'clear': function() { 
				jQuery("#list1")[0].triggerToolbar(); 
			}, 
			'datepickerOptions': {'numberOfMonths':2, 'changeYear':true, 'maxDate':null}
		}, opts));
	}		
	
    jQuery(window).load(function() {
		setTimeout(() => {
			var fshtml = jQuery("#fsdaterange").html();
			jQuery("#fsdaterange").remove();
			// show dropdown in top toolbar
			jQuery('.ui-jqgrid-toppager .navtable:first tr:first').append('<td><div style="padding-left: 5px; padding-top:0px;">'+fshtml+'</div></td>');
			link_daterange_picker($("#daterangefilter")[0]);
		}, 200);
	});
	
	</script>
</body>
</html>