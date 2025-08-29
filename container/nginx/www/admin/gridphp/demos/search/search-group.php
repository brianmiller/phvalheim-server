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

$grid["caption"] = "Complex Group Searching"; // expand grid to screen width
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["view_options"] = array("width"=>"500");
$grid["loadComplete"] = "function(){ fx_onload(); }";

// enable/disable query debugging
// $grid["search_options"]["showQuery"] = true; 

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"view"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "group", // enable complex group searching
						"showhidecolumns" => false
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "clients";

$col = array();
$col["title"] = "Id";
$col["name"] = "client_id"; 
$col["width"] = "20";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Name";
$col["name"] = "name"; 
$col["editable"] = true;
$col["width"] = "80";
$cols[] = $col;	

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender"; 
$col["width"] = "30";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Company";
$col["name"] = "company"; 
$col["editable"] = true;
$col["edittype"] = "textarea"; 
$col["editoptions"] = array("rows"=>2, "cols"=>20); 
$cols[] = $col;	

$g->set_columns($cols);

// group columns header
$g->set_group_header( array(
						    "useColSpanStyle"=>true,
						    "groupHeaders"=>array(
						        array(
						            "startColumnName"=>'name', // group starts from this column
						            "numberOfColumns"=>2, // group span to next 2 columns
						            "titleText"=>'Personal Information' // caption of group header
						        ),
						        array(
						            "startColumnName"=>'company', // group starts from this column
						            "numberOfColumns"=>2, // group span to next 2 columns
						            "titleText"=>'Company Details' // caption of group header
						        )
						    )
						)
					);

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
	
	<script>
	// show search criteria on title bar
	function fx_onload()
	{
        var postData = jQuery('#list1').getGridParam("postData");
        var newCapture = "Complex Group Searching";
        if (postData._search === true && typeof postData.filters !== "undefined") {
            var filters = jQuery.parseJSON(postData.filters);
            newCapture += ": [";
            var rules = filters.rules;
			
            for (var i=0; i<rules.length; i++) {
                var rule = rules[i];
                var op = rule.op;  // the code name of the operation
                if (jQuery.fn.searchFilter && jQuery.fn.searchFilter.defaults &&
                    jQuery.fn.searchFilter.defaults.operators) {
                    // find op description 
                    var operators = jQuery.fn.searchFilter.defaults.operators;
                    for (var j=0; j<operators.length; j++) {
                        if (operators[j].op === rule.op) {
                            op = operators[j].text;
                            //op = $.jgrid.search.odata[j];
                            break;
                        }
                    }
                }
                newCapture += rule.field + " " + op + " '" + rule.data + "'";
                if (i+1 !== rules.length)
                    newCapture += ", ";
            }
            newCapture += "]";
			jQuery('#list1').setCaption(newCapture);		
        }
		else
			jQuery('#list1').setCaption(newCapture);		
	}
	</script>
</body>
</html>
