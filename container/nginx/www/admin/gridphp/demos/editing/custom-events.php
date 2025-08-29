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

$grid = new jqgrid($db_conf);

$opt["caption"] = "Clients Data";

## ------------------ ##
## CLIENT SIDE EVENTS ##
## ------------------ ##
// just set the JS function name (should exist)
$opt["onSelectRow"] = "function(ids) { do_onselect(ids); }";
$opt["loadComplete"] = "function(ids) { do_onload(ids); }";

// to simulate, comment theh onselectrow event line
// $opt["ondblClickRow"] = "function(id,row,col) { do_ondblclick(id,row,col); }";

$grid->set_options($opt);

## ------------------ ##
## SERVER SIDE EVENTS ##
## ------------------ ##

// params are array(<function-name>,<class-object> or <null-if-global-func>,<continue-default-operation>)
// if you pass last argument as true, functions will act as a data filter, and insert/update will be performed by grid
$e["on_insert"] = array("add_client", null, false);
$e["on_update"] = array("update_client", null, false);
$e["on_delete"] = array("delete_client", null, true);
$e["on_after_insert"] = array("after_insert", null, true); // return last inserted id for further working
$e["on_data_display"] = array("filter_display", null, true);
$grid->set_events($e);

function update_client($data)
{
	// you can also use grid object to execute sql, useful in non-mysql driver

	// global $grid;
	// $grid->execute_query("MY-SQL");

	/*
		These comments are just to show the input param format

		$data => Array
		(
			[client_id] => 2
			[params] => Array
				(
					[client_id] => 2
					[name] => Client 2
					[gender] => male
					[company] => Client 2 Company
				)

		)
	*/

	global $grid;

	$str = "UPDATE clients SET name='My custom {$data["params"]["name"]}'
	WHERE client_id = {$data["client_id"]}";

	$grid->execute_query($str);
}

function delete_client($data)
{
	/*
		These comments are just to show the input param format.
		The on_delete event only receive ID of grid. Rest data can be fetched with another mysql query
		$data => Array
		(
			[client_id] => 2
		)
	*/

	// global $grid;
	// $str = "SELECT * FROM clients WHERE client_id = {$data["client_id"]}";
	// $rs = $grid->get_one($str);
	// phpgrid_error($rs); // can get $rs["gender] of deleting id 
}

function add_client($data)
{
	global $grid;

	$keys = implode(',',array_keys($data["params"]));
	$vals = array_values($data["params"]);

	$grid->execute_query("INSERT INTO clients ($keys) VALUES (?,?,?,?,?)",$vals);
	
	/*
		These comments are just to show the input param format
		$data => Array
			(
				[params] => Array
					(
						[client_id] =>
						[name] => Test
						[gender] => male
						[company] => Comp
					)

			)

	*/

	/*
	// if you make it 3rd param to false, then it should return json data
	// e.g. $e["on_insert"] = array("add_client", null, false);

    $insert_id = $grid->execute_query($sql,false,"insert_id");

    if (intval($insert_id)>0)
        $res = array("id" => $insert_id, "success" => true);
    else
        $res = array("id" => 0, "success" => false);

    echo json_encode($res);
    die;
	*/
}

function after_insert($data)
{
	/*
		These comments are just to show the input $data format
		Array
		(
			[client_id] => 99
			[params] => Array
				(
					[client_id] =>
					[name] => Test
					[gender] => male
					[company] => Comp Test
				)

		)
	*/
	/*
	ob_start();
	print_r($data);
	$str = ob_get_clean();
	phpgrid_error($str);
	*/
}


/**
 * Just update the passed argument, as it is passed by reference
 * Changes will be reflected in grid
 */
function filter_display($data)
{
	/*
	These comments are just to show the input param format
	Array
	(
	    [params] => Array
	        (
	            [0] => Array
	                (
	                    [client_id] => 1
	                    [name] => Client 1
	                    [gender] => My custom malea
	                    [company] => My custom Client 1 Company 1
	                )

	            [1] => Array
	                (
	                    [client_id] => 2
	                    [name] => Client 2
	                    [gender] => male
	                    [company] => Client 2 Com2pany 11
	                )

				.......
	*/
	foreach($data["params"] as &$d)
	{
		foreach($d as $k=>$v)
			$d[$k] = strtoupper($d[$k]);
	}
}

$grid->table = "clients";
$out = $grid->render("list1");
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
	<script>
	function do_onselect(id)
	{
		console.log('Simulating, on select row event')
		var rd = jQuery('#list1').jqGrid('getCell', id, 'company'); // where invdate is column name
		jQuery("#span_extra").html(rd);

		//open in new window on selection
		//var selectedRow = jQuery('#list1').jqGrid('getGridParam','selrow');
		//window.open("edit.php?id="+selectedRow);
	}
	function do_onload(id)
	{
		// remove all tooltip from cell
		$("#list1 td").attr('title','');
		console.log('Simulating, data on load event')
	}
	function do_ondblclick(id,row,col)
	{
		console.log('Simulating, double click on id:'+id+', row:'+row+', col:'+col);
	}
	</script>
	<div style="margin:10px">
	Custom events example ...
	<br>
	<br>
	<?php echo $out?>
	<br>
	Company: <span id="span_extra">Not Selected</span>
	</div>
</body>
</html>
