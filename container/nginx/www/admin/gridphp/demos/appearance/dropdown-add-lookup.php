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

$opt["sortname"] = 'id'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["caption"] = "Invoice Data"; // caption of grid
#$grid["autowidth"] = true; // expand grid to screen width


$opt["edit_options"]["afterShowForm"] = 'function (form) {
  var fldSrc = "";
  var fldDest= "";
  var wData  = "";

  var $btn = $(\'<a href="#"><span class="ui-icon ui-icon-search"></span></a>\')
      .addClass("fm-button ui-state-default")
      .css({"padding": "4px", "margin":"0px 0px 3px 0px", "vertical-align": "bottom"})
      .click(function() {

			// set source / dest field names
			if (this.id == "btnClient") {
			  fldSrc = "client_id";
			  fldDest= "client_id";
			}
			
			jQuery(document).unbind("keypress").unbind("keydown").unbind("mousedown");
			
			jQuery.fancybox.open({href: "#lookup_grid",
				afterClose : function() {
					
					// read selected value from list2 grid, if using for subgrid use jQuery("table[id$=list2]")
					var selr = jQuery("#list2").jqGrid("getGridParam","selrow");

					if (selr != null) {
						idRow = selr;
						wData = jQuery("#list2").jqGrid("getCell", idRow, fldSrc);
						jQuery("[name="+fldDest+"].FormElement").append("<option value=\'"+ wData +"\'></option>");
						jQuery("[name="+fldDest+"].FormElement").val(wData);
						fx_reload_dropdown(fldDest,fldDest);
					}
				}
			  });
	  });

  var $btn1 = $btn.clone(true);
  $($btn1).attr("id", "btnClient");
  $("#tr_client_id > td.DataTD").append(" ").append($btn1);
  $("#client_id").css("width","calc(88% - 30px)");
  
}';

$opt["add_options"]["afterShowForm"] = $opt["edit_options"]["afterShowForm"];

$g->set_options($opt);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
					) 
				);

$g->select_command = "SELECT id, invdate, invheader.client_id, name, amount, note FROM invheader 
						INNER JOIN clients on clients.client_id = invheader.client_id
						";

// this db table will be used for add,edit,delete
$g->table = "invheader";


$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "10";
$cols[] = $col;		
		
$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "concat(invheader.client_id,' - ',name)"; // this is required as we need to search in name field, not id
$col["width"] = "100";
$col["align"] = "left";
$col["search"] = true;
$col["editable"] = true;
$col["export"] = false; // not for export
$col["edittype"] = "select"; // render as select
# fetch data from database, with alias k for key, v for value
$str = $g->get_dropdown_values("select distinct client_id as k, name as v from clients");
$col["editoptions"] = array("value"=>":;".$str); 

// reloading dropdown sql
$col["editoptions"]["onload"]["sql"] = "select distinct client_id as k, name as v from clients"; 

$col["editrules"] = array("required"=>true);
$col["show"] = array("list"=>true, "add"=>true, "edit"=>true, "view"=>true, "bulkedit"=>false);
$col["formatter"] = "select"; // display label, not value
$cols[] = $col;
		
// only for export
$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["hidden"] = true;
$col["export"] = true;
$col["width"] = "100";
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>10); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["search"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Amount";
$col["name"] = "amount"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>10); // with default display of textbox with size 20
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>10); // with default display of textbox with size 20
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);


$g->set_actions(array( 
                        "export_pdf"=>true,												
                    ) 
                ); 
				
// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");


// ---------------------------------------------------------------------------------
// another lookup grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["sortname"]    = 'client_id'; // by default sort grid by this field
$opt["sortorder"]   = "desc"; // ASC or DESC
$opt["caption"]     = "Clients"; // caption of grid
$opt["hidefirst"]     = true;
$opt["responsive"]     = false;
$opt["height"]     = 300;

$opt["add_options"]["modal"] = false;
$opt["edit_options"]["modal"] = false;

// after lookup add, dont close (blank id passed after add dialog)
$opt["onSelectRow"] = "function(id){ if (!id) return; jQuery.fancybox.close(); }";
$grid->set_options($opt);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>false, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => true // show single/multi field search condition (e.g. simple or advance)
					)
				);
				
$grid->table = "clients";

// generate grid output, with unique grid name as 'list1'
$out_detail = $grid->render("list2");


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

	<!-- Add fancyBox main JS and CSS files -->
	<link type="text/css" rel="stylesheet" href="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.css" />
	<script type="text/javascript" src="//cdn.jsdelivr.net/fancybox/2.1.4/jquery.fancybox.js"></script>
	
</head>
<body>
	<style>
	/* required for add/edit dialog overlapping */
	.fancybox-overlay { z-index:943 !important; }
	#editmodlist1.ui-jqdialog { z-index: 942 !important; }
	</style>

	<div style="margin:10px">
	<?php echo $out?>
	</div>

	<div id='lookup_grid' style='display:none; width:60%'>
	<?php echo $out_detail?>
	</div>

</body>
</html>
