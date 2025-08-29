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

$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "asc"; // ASC or DESC
$grid["caption"] = "Bulk Editing Rows"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = true; // allow you to multi-select through checkboxes
// $grid["bulkedit_options"]["afterShowForm"] = "function(){ }"; // js callback for bulk edit form dialog
$grid["bulkedit_options"]["position"] = 4; // js callback for bulk edit form dialog
$g->set_options($grid);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"bulkedit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true,
						"autofilter" => true, 
						"search" => "simple"
					) 
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT i.id, invdate, c.name, note, total,closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id";

// this db table will be used for add,edit,delete
$g->table = "invheader";


$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; 
$col["width"] = "10";
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "100";
$col["search"] = true;
$col["editable"] = false;
$col["export"] = false; // this column will not be exported
$col["link"] = "http://localhost/?id={id}"; // e.g. http://domain.com?id={id} given that, there is a column with $col["name"] = "id" exist
$col["linkoptions"] = "target='_blank'"; // extra params with <a> tag
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate"; 
$col["formatter"] = "date"; 
$col["editrules"] = array("required"=>true);
$col["width"] = "50";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20); // with default display of textbox with size 20
// $col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

$col = array();
$col["title"] = "Amount";
$col["name"] = "total"; 
$col["width"] = "50";
$col["edittype"] = "select";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("value"=>":;100:One Hundred;200:Two Hundred;300:Three Hundred");
// $col["editrules"] = array("required"=>true); // and is required
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note"; 
$col["width"] = "150";
$col["editable"] = true; // this column is editable
$col["link"] = 'javascript:window.open("customer.php?iCompanyId={f_id}","newwind","height=500,width=500,scrollbars=yes"); void(0);';
$col["show"]["bulkedit"] = false; // do not show in bulk edit dialog
$cols[] = $col;

$col = array();
$col["title"] = "Closed";
$col["name"] = "closed";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "checkbox"; // render as checkbox
$col["formatter"] = "checkbox"; // render as checkbox
$col["editoptions"] = array("value"=>"1:0"); // with these values "checked_value:unchecked_value"
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

//CONDITION CSS
$f = array();
$f["column"] = "note";
$f["op"] = "cn";
$f["value"] = "Active";
$f["cellcss"] = "'color':'green', 'font-weight':'normal'"; // must use (single quote ') with css attr and value
$f_conditions[] = $f;
 
$f = array();
$f["column"] = "note";
$f["op"] = "cn";
$f["value"] = "Inactive";
$f["cellcss"] = "'color':'red', 'font-weight':'normal'"; // must use (single quote ') with css attr and value
$f_conditions[] = $f;
 
$g->set_conditional_css($f_conditions);

// event handler to manage Bulk Operations
$e["on_update"] = array("update_data","",true);
$g->set_events($e);

function update_data($data)
{
	// temp grid object to execute sql
	$g = new jqgrid();
	
	// If bulk operation is requested, (default otherwise)
	if ($data["params"]["bulk"] == "set-desc")
	{
		$selected_ids = $data["id"]; // e.g. the selected values from grid 5,7,14 (where "id" is field name of first col)
		$str = $data["params"]["data"];

		// here you can code your logic to do bulk processing
		$g->execute_query("UPDATE invheader SET note = '$str' WHERE id IN ($selected_ids)");
				
		// first param is message, second is autofade after 1 sec (0/1)
		phpgrid_msg("Custom Message: Download zip file from <a target='_blank' href='http://google.com'>http://google.com</a>",0);
		die;
	}
	else if ($data["params"]["bulk"] == "download-zip")
	{
		$selected_ids = $data["id"]; // e.g. the selected values from grid 5,7,14 (where "id" is field name of first col)
		// $g->execute_query("UPDATE invheader SET note = 'Email Sent' WHERE id IN ($selected_ids)");

		$selected_ids = explode(",",$selected_ids);

		$zip_name = "archive.zip";
		$zip = new ZipArchive;
		if ($zip->open($zip_name, ZipArchive::CREATE|ZipArchive::OVERWRITE) === TRUE)
		{
			// Add files to the zip file
			// $zip->addFile('test.txt');
			// $zip->addFile('test.pdf');
		 
			// Add random.txt file to zip and rename it to newfile.txt
			// $zip->addFile('random.txt', 'newfile.txt');
		 
			// Add a file new.txt file to zip using the text specified
			foreach($selected_ids as $id)
				$zip->addFromString("$id.txt", "ID $id to be added in archive");
		 
			// All files are added, so close the zip file.
			$zip->close();

			phpgrid_msg("Downloading archive ...<script>location.href='$zip_name'</script>",1);
			die;
		}

		die;
	}
	else if ($data["params"]["bulk"] == "update-amount")
	{
		$str = $data["params"]["data"];
		$g->execute_query("UPDATE invheader SET total = '$str'");
		die;
	}
	else
	{
		$selected_ids = $data["id"];
		if (count(explode(",",$selected_ids)) > 1)
		{
			// phpgrid_error("bulk edit event catched!");
		}
		else
		{
			// phpgrid_error("simple edit event catched!");
		}
	}
}

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
	<br>
	<?php echo $out?>
	</div>

	<script>
	// add toolbar button for bulk operation
	setTimeout(function(){
		jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager', 
		{
			'caption'      : 'Edit Notes', 
			'buttonicon'   : 'ui-icon-pencil', 
			'onClickButton': function()
			{
				var str = prompt("Please enter comment")
				if (str)
					fx_bulk_update("set-desc",str);
			},
			'position': 'last'
		});
	
		jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager', 
		{
			'caption'      : 'Download', 
			'buttonicon'   : 'ui-icon-pencil', 
			'onClickButton': function()
			{
				fx_bulk_update("download-zip");
			},
			'position': 'last'
		});
			
		// jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager', 
		// {
		// 	'caption'      : 'Update All Amount', 
		// 	'buttonicon'   : 'ui-icon-pencil', 
		// 	'onClickButton': function()
		// 	{
		// 		var str = prompt("Enter value");
		// 		if (str)
		// 			fx_bulk_update("update-amount",str,-1);
		// 	},
		// 	'position': 'last'
		// });
	},200);
	</script>
</body>
</html>
