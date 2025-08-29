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

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "15";
$cols[] = $col;		
		
$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "clients.name";
$col["width"] = "50";
$col["align"] = "left";
$col["search"] = true;
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["editrules"] = array("required"=>true); // and is required
$col["formatter"] = "date"; // format as date
$col["search"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Amount";
$col["name"] = "amount"; 
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note"; 
$col["width"] = "150";
$col["editable"] = false; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
$col["show"] = array("list"=>false, "view"=>true);

// Google map integration lat, lng can be replaced with database field with placeholder {field}
$col["template"] = "<div class='maps' id='map_{id}' style='height:300px' data-address='' data-lat='24.914' data-lng='67.15'></div>";
// $col["template"] = "<div class='maps' id='map_{id}' style='height:300px' data-address='Baloch Colony' data-lat='44.54' data-lng='-78.56'>[Map]</div>";

// Open Street Map integration
// $col["template"] = '<iframe width="425" height="250" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://www.openstreetmap.org/export/embed.html?bbox=67.12609577218245%2C24.907024183188263%2C67.15369033852767%2C24.920607873171733&amp;layer=mapnik" style="border: 1px solid black"></iframe><br/><small><a href="https://www.openstreetmap.org/#map=16/24.9138/67.1399">View Larger Map</a></small>';
$cols[] = $col;

$grid = array();
$grid["caption"] = "Invoice Data"; // caption of grid
// to load in view dialog
$grid["view_options"]["width"] = "700";
$grid["view_options"]["beforeShowForm"] = "function(){ initMap(); }";
$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"export_pdf"=>true, // show/hide row wise edit/del/save option
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
					) 
				);

// to make dropdown work with export, we need clients.name as client_id logic in sql
$g->select_command = "SELECT id, invdate, clients.name as client_id, amount, note FROM invheader 
						INNER JOIN clients on clients.client_id = invheader.client_id
						";

// this db table will be used for add,edit,delete
$g->table = "invheader";

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
	
	<style>
	.ui-jqdialog-content .CaptionTD { width: 20% }
	</style>
	
	<div style="margin:10px">
	<?php echo $out?>
	</div>

    <script>
		function initMap() 
		{
		  
			if (typeof google == 'undefined')
			{
				var oHead = document.body;
				var oScript = document.createElement('script');
				oScript.type = 'text/javascript';
				oScript.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyA3s0SmXVoV0mD8ZLbbQ0ztNK_sUVF3EPk&libraries=places&callback=showMap';
				oHead.appendChild(oScript);
			}
			else
			{
				showMap();
			}
		}

		function showMap() 
		{
			var rowid = jQuery("#<?php echo $g->id?>").jqGrid('getGridParam','selrow');
			var mapDiv = jQuery("#map_"+rowid)[0];

			var lt = parseFloat(jQuery(mapDiv).attr('data-lat'));
			var lg = parseFloat(jQuery(mapDiv).attr('data-lng'));

			// remove space
			if(mapDiv.parentNode) mapDiv.parentNode.parentNode.removeChild(mapDiv.parentNode.parentNode.firstChild)

			var res_map = new google.maps.Map(mapDiv, {
				center: {lat: lt, lng: lg},
				zoom: 12
			});
			var marker = new google.maps.Marker({
			  map: res_map,
			  position: {lat: lt, lng: lg}
			});			


			// if address string passed, relocate on map
			var address = jQuery(mapDiv).attr('data-address');
			if (address != '')
			{
				var geocoder = new google.maps.Geocoder();
				geocodeAddress(geocoder, map, address);

				function geocodeAddress(geocoder, resultsMap, address) {
					geocoder.geocode({'address': address}, function(results, status) {
						if (status === 'OK') 
						{
							resultsMap.setCenter(results[0].geometry.location);
							var marker = new google.maps.Marker({
							  map: resultsMap,
							  position: results[0].geometry.location
							});
						} 
						else 
						{
							alert('Geocode was not successful for the following reason: ' + status);
						}
					});
				}
			}
		}
    </script>
	
</body>
</html>
