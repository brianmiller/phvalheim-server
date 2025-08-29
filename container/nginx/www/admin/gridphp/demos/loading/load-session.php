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

$db_conf = array( 	
					"type" 		=> PHPGRID_DBTYPE, 
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$g = new jqgrid($db_conf);

// load data from session (custom ajax call)
if ($_GET["reload_data"] == "1")
{
	$arr = array();
	foreach($_SESSION["list1_data"] as $d)
		$arr[] = $d;
		
	echo json_encode($arr);
	die;
}

// load data from session (custom ajax call)
if ($_GET["save_data"] == "1")
{
	$arr = array();
	foreach($_SESSION["list1_data"] as $d)
	{
		$d["name"] = addslashes($d["name"]);
		$d["code"] = addslashes($d["code"]);
		
		$sql = "INSERT INTO items (item,item_cd) VALUES ('{$d["name"]}','{$d["code"]}')";
		$g->execute_query($sql);
		
		$arr["success"] = 1;
	}
	
	// reset session after save
	$_SESSION["list1_data"] = array();
	
	echo json_encode($arr);
	die;
}

// set few params
$grid["caption"] = "Loading from Array & Session";
$grid["width"] = 800;
$grid["height"] = "auto";
$grid["autowidth"] = false;
$grid["sortable"] = false;
$grid["multiselect"] = true;

$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "desc"; // ASC or DESC

$grid["ignoreCase"] = true; // do case insensitive sorting
$grid["export"] = array("format"=>"excel", "filename"=>"my-file", "heading"=>"Array Data", "orientation"=>"landscape", "paper"=>"a4");


// add on clientside and set new id
$grid["add_options"]["reloadAfterSubmit"] = false;
$grid["add_options"]["afterSubmit"] = "function (response) {
																var result = jQuery.parseJSON(response.responseText);
																console.log(result); return [true, '', result.id];
															}";
$grid["edit_options"]["reloadAfterSubmit"] = false;

$g->set_options($grid);

// disabling reset for internal ajax add/edit calls
// $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
// if (!$is_ajax) unset($_SESSION["list1_data"]);

if (empty($_SESSION["list1_data"]))
{
	// keep initially blank
	$data = array();
	$_SESSION["list1_data"] = array();

	// --- OR --- initially fill grid with data (could be from database query)
	
	// $name = array('Pepsi 1.5 Litre', 'Sprite 1.5 Litre', 'Cocacola 1.5 Litre', 'Dew 1.5 Litre', 'Nestle 1.5 Litre');
	// for ($i = 0; $i < 5; $i++)
	// {
		// $data[$i]['id'] = $i+1;
		// $data[$i]['code'] = $name[rand(0, 4)][0].($i+5);
		// $data[$i]['name'] = $name[rand(0, 4)];

		// // to simulate case insensitive sort
		// $data[$i]['name'] = ($i%2)?strtoupper($data[$i]['name']):$data[$i]['name'];

		// $data[$i]['cost'] = rand(0, 100)." USD";
		// $data[$i]['quantity'] = rand(0, 100);
		// $data[$i]['discontinued'] = rand(0, 1);
		// $data[$i]['email'] = 'buyer_'. rand(0, 100) .'@google.com';
		// // $data[$i]['more_options'] = "<a class='fancybox' href='http://upload.wikimedia.org/wikipedia/commons/4/4a/Logo_2013_Google.png'><img height=25 src='http://ssl.gstatic.com/ui/v1/icons/mail/logo_default.png'></a>";

		// $_SESSION["list1_data"][$i+1] = $data[$i];
	// }
}
else
{
	foreach ($_SESSION["list1_data"] as $s)
		$data[] = $s;
}

// pass data in table param for local array grid display
$g->table = $data; // blank array(); will show no records

// If you want to customize columns params
$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "60";
$col["hidden"] = true;
$col["editable"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Code"; // caption of column
$col["name"] = "code"; 
$col["width"] = "40";
$col["editable"] = true;
$cols[] = $col;	

$col = array();
$col["title"] = "Name"; // caption of column
$col["name"] = "name"; 
$col["width"] = "120";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Cost"; // caption of column
$col["name"] = "cost"; 
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Quantity"; // caption of column
$col["name"] = "quantity"; 
$col["width"] = "100";
$col["editable"] = true;
$cols[] = $col;		

$col = array();
$col["title"] = "Email"; // caption of column
$col["name"] = "email"; 
$col["width"] = "400";
$col["editable"] = true;
$cols[] = $col;		


$g->set_columns($cols);

// only show inline add/edit/del buttons. Remove rest.
$g->navgrid["param"]["edit"] = false;
$g->navgrid["param"]["add"] = false;
$g->navgrid["param"]["del"] = true;
$g->navgrid["param"]["search"] = false;
$g->navgrid["param"]["refresh"] = true;
	
$g->set_actions(array(	
						"inline"=>true, // allow/disallow inline
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
					) 
				);

// custom events for session storage
$e["on_insert"] = array("on_insert", null, false);
$e["on_update"] = array("on_update", null, false);
$e["on_delete"] = array("on_delete", null, false);
$g->set_events($e);

function on_delete($data)
{
	// multi select delete
	if ( strstr($data["id"],",") !== false )
	{
		$ids = array();
		$ids = explode(",",$data["id"]);
		foreach($ids as $i)
			unset($_SESSION["list1_data"][$i]);
		
	}
	// single row delete
	else
	{
		unset($_SESSION["list1_data"][$data["id"]]);
	}
	// print_r($_SESSION);
}

function on_insert($data)
{
	$_SESSION["list1_data"][] = $data["params"];

	$keys = array_keys($_SESSION["list1_data"]);
	$id = $keys[count($keys)-1];
	
	$_SESSION["list1_data"][$id]["id"] = $id;
	
	$res = array("id" => $id, "success" => true);
	echo json_encode($res);	
}

function on_update($data)
{
	$_SESSION["list1_data"][$data["id"]] = $data["params"];
	// print_r($_SESSION);
}

// render grid
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
		<br>
		<button onclick="reloadGrid()">Refresh Data</button>
		<button onclick="saveToDb()">Save To Database</button>
		<br><br>	
		<fieldset style="font-family:tahoma; font-size:12px">
			<legend>Session Grid Data (Refresh page for Updated data)</legend>
			<pre><?php print_r($_SESSION["list1_data"]); ?></pre>
		</fieldset>
	</div>
	<script>
	// override refresh button handler
	$(window).load( function () {
		$('#refresh_list1').unbind( "click" );
		$('#refresh_list1').click( function (event) {
			 reloadGrid();      
		 });
	});
	
	function saveToDb()
	{
		jQuery.ajax({
			url: "<?php echo $g->options["url"]?>",
			dataType: "html",
			data: {'save_data':1},
			type: "GET",
			error: function(res, status) {
				alert(res.status+" : "+res.statusText+". Status: "+status);
				fx_success_msg("Records Saved",1);
			},
			success: function( data ) {
				var result = JSON.parse(data);
				if (result.success)
					fx_success_msg("Records Saved",1);
				
				jQuery("#list1")
					.jqGrid('clearGridData')
					.jqGrid('setGridParam',
						{ 
							'data':{}
						})
					.trigger("reloadGrid");				
			}
		});
	}

	function reloadGrid()
	{
		jQuery.ajax({
			url: "<?php echo $g->options["url"]?>",
			dataType: "html",
			data: {'reload_data':1},
			type: "GET",
			error: function(res, status) {
				alert(res.status+" : "+res.statusText+". Status: "+status);
			},
			success: function( data ) {
				
				var results = JSON.parse(data);
			
				jQuery("#list1")
					.jqGrid('clearGridData')
					.jqGrid('setGridParam',
						{ 
							'data':results
						})
					.trigger("reloadGrid");
			}
		});
	}
	</script>
</body>
</html>
