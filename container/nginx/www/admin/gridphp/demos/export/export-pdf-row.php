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

$grid["caption"] = "Client Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["rowNum"] = 100; // allow you to multi-select through checkboxes

// JSPDF auto generate pdf on edit
$grid["edit_options"]["afterSubmit"] = 'function(response) { 
											if(response.status == 200)
											{
												if (response.responseText)
												{
													var data = JSON.parse(response.responseText);
													// JSPDF, docs: http://rawgit.com/MrRio/jsPDF/master/docs/global.html	
													setTimeout(function(){print_row(data.id)},200);
												}

												return [true,""];
											}
										}';

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "clients";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "client_id"; 
$col["width"] = "30";
$col["export"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Gender";
$col["name"] = "gender";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Company";
$col["name"] = "company";
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Print";
$col["name"] = "print";
$col["width"] = "20";
$col["editable"] = false;
$col["search"] = false;
$col["default"] = "<button onclick='setTimeout(\"print_row()\",200)' class='fancybox' data-fancybox-type='iframe' >Print</>";
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
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/1.3.3/jspdf.debug.js"></script>
</head>
<body>

	<script type="text/javascript">
	function print_row(id) 
	{
		if (!id)
			var rowid = jQuery("#list1").jqGrid("getGridParam","selrow");
		else
			var rowid = id;

		var row = $("#list1").getRowData(rowid);

		// JSPDF, docs: http://rawgit.com/MrRio/jsPDF/master/docs/global.html	

		// Default export is a4 paper, portrait, using milimeters for units
		var doc = new jsPDF({orientation: "portrait", unit: "mm", format: [120, 80]});										

		// set font size
		doc.setFont("arial");
		doc.setFontType("bold");
		doc.setFontSize(16);

		var y = 10;
		var space = 4;

		doc.text("EXPO EXPRESS",4,y);
		
		doc.setFontType("normal");
		doc.setFontSize(10);
		doc.text("300 Marketplace",4,y+=space);
		doc.text("Anywhere Pakistan",4,y+=space);
		doc.text("+923001234325",4,y+=space);
		doc.text("www.expo.com",4,y+=space);

		doc.text("------------------------",4,y+=space);

		doc.setFont("courier");
		doc.setFontType("normal");
		doc.setFontSize(8);
		doc.text("Name: " + row["name"],4,y+=space);
		doc.text("Gender: " + row["gender"], 4, y+=space);
		doc.text("Company: " + row["company"], 4, y+=space);
		doc.setFontType("normal");

		y+=6;

		doc.text("Subtotal:            $" + row["client_id"]+100, 4, y+=space);
		doc.setFontType("bold");
		doc.text("Total:               $" + row["client_id"]+100, 4, y+=space);
		doc.setFontSize(8);
		doc.setFontType("italic");
		doc.text("Signature,", 4, y+=space);

		y+=10;

		doc.setFontSize(9);
		doc.setFontType("bold");
		doc.text("--------------------------------------", 4, y+=space);
		doc.text(""+ row["name"], 4, y+=space);
		doc.text("--------------------------------------",4,y+=space);
		doc.setFontSize(7);
		doc.setFontType("normal");
		doc.setFont("arial");
		doc.text("Expo Express VAT:34287557",4,y+=space);
		doc.text("We appreciate your shopping",4,y+=space);
		doc.text("Please enjoy a Free Appetizer",4,y+=space);
		doc.text("from any Expo outlet after showing",4,y+=space);
		doc.text("this receipt!",4,y+=space);
		doc.setFontType("bold");
		doc.text("© Expo Express - www.expo.com",4,y+=space);
		doc.autoPrint();

		window.open(doc.output("bloburl"), "_blank","toolbar=no,status=no,menubar=no,scrollbars=no,resizable=no,modal=yes,top=200,left=350,width=600,height=400");

		// doc.save("a4.pdf");

		// this opens a new popup, after this the PDF opens the print window view
		// doc.output("dataurlnewwindow");
	};
	</script>

	<div style="margin:10px">
	<br>
	<?php echo $out?>
	</div>
</body>
</html>