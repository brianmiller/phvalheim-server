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

$grid["height"] = '250'; // by default sort grid by this field
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "asc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["form"]["position"] = "center"; // allow you to multi-select through checkboxes

$grid["add_options"]["bottominfo"] = "Only pdf, gif, jpg, txt, doc, bmp, png files are allowed!";

$g->set_options($grid);

// callback to show how to insert new row for each uploaded files
//$e["on_insert"] = array("add_files", null, false);

$e["on_delete"] = array("delete_files", null, true);
$g->set_events($e);

// callback to show how to insert new row for each uploaded files
function add_files($data)
{
	global $g;

	/*
		Array
		(
			[id] => _empty
			[params] => Array
				(
					[client_id] => 72
					[invdate] => 2021-03-12
					[amount] => 113
					[note] => temp/Scan_20170721 (2).png,temp/Scan_20170721.png,temp/Scan_20190226_2.png
					[id] => _empty
				)

		)	
	*/

	$uploads = explode(",",$data["params"]["note"]);
	foreach($uploads as $v)
	{
		$d = array();
		$d[] = $data["params"]["invdate"];
		$d[] = $data["params"]["client_id"];
		$d[] = $data["params"]["amount"];
		$d[] = $v;
		$last_id = $g->execute_query("INSERT INTO invheader (invdate,client_id,amount,note) VALUES(?,?,?,?)",$d,"insert_id");
	}

	if (intval($last_id)>0)
		$res = array("id" => $insert_id, "success" => true);
	else
		$res = array("id" => 0, "success" => false);

	echo json_encode($res);
	die;
}

function delete_files($data)
{
	global $g;
	$id = $data["id"];
	$rs = $g->get_one("select * from invheader where id = $id");
	$files = explode(",",$rs["note"]);

	foreach($files as $f)
		unlink($f);
}

$g->set_actions(array(
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					)
				);

// this db table will be used for add,edit,delete
$g->table = "invheader";
// select query with FK_data as FK_id, e.g. clients.name as client_id
$g->select_command = "SELECT id, invdate, clients.name as client_id, amount, note FROM invheader
						INNER JOIN clients on clients.client_id = invheader.client_id
						";


$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id";
$col["width"] = "10";
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "client_id";
$col["dbname"] = "clients.name"; // this is required as we need to search in name field, not id
$col["width"] = "100";
$col["align"] = "left";
$col["editable"] = true;
$col["edittype"] = "select"; // render as select
$str = $g->get_dropdown_values("select distinct client_id as k, name as v from clients");
$col["editoptions"] = array("value"=>$str);
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
$col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

// file upload column
$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"]["multiple"] = "multiple"; // to enable multiple file upload

$col["edittype"] = "file"; // render as file
$col["upload_dir"] = "temp"; // upload here
$col["editrules"] = array("ifexist"=>"rename"); // "rename", "override" can also be set
$col["show"] = array("list"=>false,"view"=>false,"edit"=>true,"add"=>true); // only show in add/edit dialog
$cols[] = $col;

// virtual column to display uploaded file in grid
$col = array();
$col["title"] = "Attachments";
$col["name"] = "logo";
$col["width"] = "200";
$col["editable"] = false;
$col["hidden"] = true;

// display none if nothing is uploaded, otherwise make link.
$col["on_data_display"] = array("render_images","");

// only show in listing & image in edit
$col["show"] = array("list"=>true,"edit"=>false,"add"=>false,"view"=>true);

$cols[] = $col;

function render_images($row)
{
	// get upload folder url for display in grid -- change it as per your upload path
	$upload_url = explode("/",$_SERVER["REQUEST_URI"]);
	array_pop($upload_url);
	$upload_url = implode("/",$upload_url)."/";

	if ($row["note"] == "")
		return "None";
	else
	{
		$imgs = explode(",",$row["note"]);
		foreach($imgs as $i)
			$ret .= "<li><a target='_blank' href='$upload_url/$i' target='_blank'>$i</a></li>";

		return $ret;
	}
}

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
<script>
// open dialog for editing
var opts = {
    'ondblClickRow': function (id) {
        jQuery(this).jqGrid('editGridRow', id, <?php echo json_encode_jsfunc($g->options["edit_options"])?>);
    }
};
</script>
	<div style="margin:10px">
	<?php echo $out?>
	</div>
</body>
</html>
