<?php 
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - https://www.gridphp.com
 * @version 2.8.2
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

$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["caption"] = "Shipment Approval"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes
$grid["reloadedit"] = true;
$grid["edit_options"]["width"] = 800;
$grid["add_options"]["width"] = 800;
$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"bulkedit"=>true, // allow/disallow bulk edit
						"delete"=>true, // allow/disallow delete
						"view"=>true, // allow/disallow view
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "advance", // show single/multi field search condition (e.g. simple or advance)
						"showhidecolumns" => false
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate , c.name,
						i.note, i.total, i.closed, i.ship_via FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias) 
$col["width"] = "10";
$col["editable"] = true;
$col["hidden"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["dbname"] = "c.name";
$col["width"] = "100";
$col["editable"] = false; // this column is not editable
$cols[] = $col;



$col = array();
$col["title"] = "Shipping";
$col["name"] = "ship_via";
$col["width"] = "50";
$col["edittype"] = "select";
$col["formatter"] = "select";
$col["editoptions"]["value"] = "1:Maersk;2:CMA CGM;3:Hapag-Lloyd;4:IRISL Group";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20, "defaultValue"=>"02/02/2013"); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true, "edithidden"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["formatoptions"] = array("srcformat"=>'Y-m-d',"newformat"=>'d/m/Y'); // http://docs.jquery.com/UI/Datepicker/formatDate
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "50";
$col["editable"] = true;
$col["firstsortorder"] = "desc";
$col["formatter"] = "number";
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["formatter"] = "checkbox"; // render as checkbox
$col["edittype"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"1:0"); // with these values "checked_value:unchecked_value"
$col["editrules"] = array("readonly"=>true, "readonly-when"=>"unchecked");
$cols[] = $col; 



$col = array();
$col["title"] = "Approver Signature";
$col["name"] = "note";
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea"; // render as textarea on edit
$col["editoptions"] = array("rows"=>2, "cols"=>20); // with these attributes

$col["formatter"] = "function(cellvalue, options, rowObject){ console.log(cellvalue,options,rowObject); return \"<img height='50' src='\"+cellvalue+\"' />\"; }";
$col["unformat"] = "function(cellvalue,options,cell){ return jQuery('img',cell).attr('src'); }";

$col["editoptions"]["dataInit"] = "function(o){
	
	if(o.previousSibling) o.parentNode.removeChild(o.previousSibling);
	jQuery(o).parent().css('padding-left','6px');

	jQuery(o).hide();
	var canvas = jQuery(\"<canvas id='signature-\"+jQuery(o).attr('name')+\"' style='margin-left:4px; display:block;' width=500 height=300></canvas>\");
	var container = jQuery(\"<div style='border:2px dotted lightgray; width:95%'></div>\");
	var button = jQuery(\"<a style='padding-top: 0.4em; padding-bottom: 0.5em;' class='fm-button ui-state-default ui-corner-all fm-button-icon-left'>Clear<span class='ui-icon ui-icon-refresh'></span></a>\");

	container.append(canvas);
	container.append(button);

	jQuery(o).parent().append(container);

	setTimeout(()=>{

		var signaturePad = new SignaturePad(document.getElementById('signature-'+jQuery(o).attr('name')), {
			backgroundColor: 'rgba(255, 255, 255, 0)',
			penColor: 'rgb(0, 0, 0)',
			minWidth: 2,
			maxWidth: 2
		  });

		signaturePad.fromDataURL(jQuery(o).val(),{ ratio: 1, width: 500, height: 300 });
		button.on('click',function(){
			signaturePad.clear();
			jQuery(o).val('');
		});
	
		signaturePad.addEventListener('endStroke', () => {
			jQuery(o).val(signaturePad.toDataURL());
		});

	},200);

}";

$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

	<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.9/dist/signature_pad.umd.min.js" type="text/javascript"></script>
	
</head>
<body>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>