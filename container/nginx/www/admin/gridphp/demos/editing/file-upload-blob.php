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

// code to download blob field
if (!empty($_GET["get_file"]))
{
	$fid = intval($_GET["get_file"]);
	$rs = $g->get_one("SELECT fname,fcontents from filecontents where fid = $fid");
	
	header( 'Content-Type: image');
	header( 'Content-Disposition: attachment;filename='.$rs["fname"]);		

	echo $rs["fcontents"];
	die;
}

$grid["height"] = '450'; // by default sort grid by this field
$grid["sortname"] = 'fid'; // by default sort grid by this field
$grid["sortorder"] = "asc"; // ASC or DESC
$grid["caption"] = "File Upload Blob"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["form"]["position"] = "center"; // allow you to multi-select through checkboxes

$grid["add_options"]["bottominfo"] = "Only pdf, gif, jpg, txt, doc, bmp, png files are allowed!";

$g->set_options($grid);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$g->table = "filecontents";
// select query with FK_data as FK_id, e.g. clients.name as client_id
$g->select_command = "SELECT fid,fname,fcontents as fcontents FROM filecontents";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "fid"; 
$col["width"] = "10";
$cols[] = $col;		
		
$col = array();
$col["title"] = "Name";
$col["name"] = "fname";
$col["width"] = "100";
$col["align"] = "left";
$col["editable"] = false;
$cols[] = $col;


$col = array();
$col["title"] = "Content";
$col["name"] = "fcontents";
$col["align"] = "left";
$col["formatter"] = "blob";
$col["editable"] = true;
$col["edittype"] = "file"; // render as file
$col["upload_dir"] = "temp"; // upload here
$col["editrules"] = array("ifexist"=>"rename"); // "rename", "override" can also be set
$col["default"] = '<a href="data:image/jpeg;base64,{fcontents}" download="{fname}"><img height="100" src="data:image/jpeg;base64,{fcontents}" /></a>';
// $col["default"] = "<a href='?get_file={fid}'>Download</a>";
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// use events if you need custom logic for upload
$e["on_insert"] = array("add_blob", null, false);
$e["on_update"] = array("edit_blob", null, false);
$g->set_events($e);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");

// callback for add
function add_blob($data)
{
	$upload_file_path = $data["params"]["fcontents"];
	unset($data["params"]["fcontents"]);
	
	// if file is uploaded
	if ($upload_file_path)
	{
		$data["params"]["fname"] = pathinfo(realpath($upload_file_path), PATHINFO_BASENAME);

		$file_content = file_get_contents($upload_file_path);
		
		// check if file has hello
		if (strpos($file_content, 'testing') === true) 
		{
		   phpgrid_error("Not allowed");
		}

		// check if file ext allowed
		$ext = pathinfo(realpath($upload_file_path), PATHINFO_EXTENSION);
		if ($ext <> "pdf" && $ext <> "gif" && $ext <> "jpg" && $ext <> "txt" && $ext <> "doc" && $ext <> "bmp" && $ext <> "png")
		{
			unlink(realpath($upload_file_path));
			phpgrid_error("Only pdf, gif, jpg, txt, doc, bmp, png files are allowed!");
		}
		
		// insert in db as blob
		$g = new jqgrid();
		$g->execute_query("insert into filecontents (fname,fcontents) values (?,?)",array($data["params"]["fname"], $file_content));
	}
}


// callback for add
function edit_blob($data)
{
	$upload_file_path = $data["params"]["fcontents"];
	unset($data["params"]["fcontents"]);
	
	// if file is uploaded
	if ($upload_file_path)
	{
		$data["params"]["fname"] = pathinfo(realpath($upload_file_path), PATHINFO_BASENAME);

		$file_content = file_get_contents($upload_file_path);
		
		// check if file has hello
		if (strpos($file_content, 'testing') === true) 
		{
		   phpgrid_error("Not allowed");
		}

		// check if file ext allowed
		$ext = pathinfo(realpath($upload_file_path), PATHINFO_EXTENSION);
		if ($ext <> "pdf" && $ext <> "gif" && $ext <> "jpg" && $ext <> "txt" && $ext <> "doc" && $ext <> "bmp" && $ext <> "png")
		{
			unlink(realpath($upload_file_path));
			phpgrid_error("Only pdf, gif, jpg, txt, doc, bmp, png files are allowed!");
		}
		
		// insert in db as blob
		$g = new jqgrid();
		$g->execute_query("UPDATE filecontents SET fname=?,fcontents=? WHERE fid = ?",
			array($data["params"]["fname"], $file_content, $data["fid"])
		);
	}
}

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
	// open dialog for editing, disable inline for files
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
