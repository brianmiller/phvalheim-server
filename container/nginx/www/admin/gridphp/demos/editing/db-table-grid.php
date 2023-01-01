<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.6.2
 * @license: see license.txt included in package
 */

include_once("../../config.php");

// set up DB
$db_conf = array();
$db_conf["type"] = "mysqli"; // mysql,oci8(for oracle),mssql,postgres,sybase
$db_conf["server"] = PHPGRID_DBHOST;
$db_conf["user"] = PHPGRID_DBUSER;
$db_conf["password"] = PHPGRID_DBPASS;
$db_conf["database"] = PHPGRID_DBNAME;

// put tables not to be shown in table editor
$restricted_tables = array();
$allowed_tables = array();
$hide_first = true;

// include and create object
include_once("../../lib/inc/adodb/adodb.inc.php");
include_once("../../lib/inc/jqgrid_dist.php");

session_start();

// load table array
$con = ADONewConnection($db_conf["type"]);
$con->Connect($db_conf["server"], $db_conf["user"], $db_conf["password"], $db_conf["database"]);
$result = $con->Execute("SHOW TABLES");
$table_arr = $result->GetRows();

$tab_fields = array();
if (!empty($_POST["tables"]))
{
	$sql = "SELECT * FROM {$_POST["tables"]} LIMIT 1 OFFSET 0";
	$result = $con->Execute($sql);

	$cnt = $result->FieldCount();
	$str = '';
	
	for ($x=0; $x<$cnt; $x++)
	{
		$fld = $result->FetchField($x);
		$str .= "<option>{$fld->name}</option>";
		$tab_fields[] = $fld->name;
	}		

	if (!empty($_POST["ajax"]))
		die($str);
}

// preserve selection for ajax call
if (!empty($_POST["tables"]))
{
	$_SESSION["tab"] = $_POST["tables"];
	$_SESSION["fields"] = $_POST["fields"];
	$tab = $_SESSION["tab"];
	$fields = $_SESSION["fields"];
}

// update on ajax call
if (!empty($_GET["grid_id"]))
{
	$tab = $_SESSION["tab"];
	$fields = $_SESSION["fields"];
}

$g = new jqgrid($db_conf);

if (!empty($tab))
{
	// set few params
	$grid["caption"] = "Grid for '$tab'";
	$grid["autowidth"] = true;
	$grid["multiselect"] = true;
	$grid["resizable"] = true;
	
	$g->set_options($grid);

	// set database table for CRUD operations
	$g->table = $tab;
	
	$index = 0;
	if (!empty($fields))
	{
		$flds = $fields;
		$cols = array();
		for($i=0; $i<count($flds); $i++)
		{
			$f = $flds[$i];
			
			$col = array();
			$col["title"] = ucwords(str_replace("_"," ",$f));
			$col["name"] = $f;
			$col["editable"] = true;
			
			// hide firsst
			if ($i == 0 && $hide_first == true)
				$col["hidden"] = true;
			
			$cols[] = $col;
			$index++;
		}
		$g->set_columns($cols);
	}
	
	$g->set_actions(array(	
							"add"=>false, // allow/disallow add
							"edit"=>true, // allow/disallow edit
							"delete"=>true, // allow/disallow delete
							"bulkedit"=>true, // allow/disallow delete
							"showhidecolumns"=>true, // allow/disallow delete
							"rowactions"=>true, // show/hide row wise edit/del/save option
							"autofilter" => true, // show/hide autofilter for search
							"import" => true,
							"search" => "advance"
						) 
					);

	// render grid
	$out = $g->render("list1_$tab");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>	
	
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
	
	<!-- Multiple Select -->
	<script src="//cdn.jsdelivr.net/jstorage/0.1/jstorage.min.js" type="text/javascript"></script>	
	<script src="//cdn.jsdelivr.net/json2/0.1/json2.min.js" type="text/javascript"></script>
	<script src="//cdn.jsdelivr.net/gh/gridphp/jqGridState@10b365046ebd687914855e807eb5f769277317d5/jqGrid.state.js" type="text/javascript"></script>

	<!-- library for checkbox in column chooser -->
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	

	<title>.: PHP DataGrid :. <?php echo ucwords($tab) ?> - Grid 4 PHP Framework</title>
</head>
<body>
	<style>form {font-family: "Open Sans", tahoma;}</style>
	<form method="post">
		<fieldset>
		<legend>Database Tables</legend>
		Select: 
		<select name="tables" onchange="get_fields();" style="width:200px;">
			<?php
				$arr = $table_arr;
				foreach($arr as $rs)
				{ 
					if (!empty($restricted_tables) && in_array($rs[0],$restricted_tables))
						continue;

					if (!empty($allowed_tables) && !in_array($rs[0],$allowed_tables))
						continue;
						
					$sel = (($rs[0] == $_POST["tables"])?"selected":"");
				?>
					<option <?php echo $sel?>><?php echo $rs[0]?></option>
				<?php
				}
			?>
		</select>
		
		<select multiple="multiple" id="fields" name="fields[]" style="width:200px;">
			<?php 
			foreach($tab_fields as $f) 
			{ 
				if (in_array($f,$_POST["fields"]))
					$sel = 'selected="selected"';
				else
					$sel = '';
			?>
				<option <?php echo $sel?>><?php echo $f?></option>
			<?php 
			} 
			?>
		</select>
		
		<script>
		var last_tab = jQuery("select[name=tables]").val();
		function get_fields()
		{
			var request = {};
			request.tables = jQuery("select[name=tables]").val();
			request.ajax = 1;
				
			if (last_tab == request.tables) 
				return;
			else
				last_tab = request.tables;

			call = jQuery.ajax({
						url: "?r="+Math.random(),
						dataType: 'html',
						data: request,
						type: 'POST',
						error: function(res, status) {
							alert(res.status+' : '+res.statusText+'. Status: '+status);
						},
						success: function( data ) {
								jQuery('select[id=fields]').html(data);

								jQuery("select[id=fields]").multipleSelect({
									filter: true,
									placeholder: 'Select Fields'
								});
								
								jQuery("select[id=fields]").multipleSelect("checkAll");
						}
					});
			
		}
		
		$("select[name=tables]").multipleSelect({
			filter: true,
			single: true,
			placeholder: 'Select Table'
		});	
		
		$("select[id=fields]").multipleSelect({
			filter: true,
			placeholder: 'Select Fields'
		});		
		
		</script>
		<input type="submit" value="Load Table">
		</fieldset>
	</form>
	<?php if (!empty($out)) { ?>
	<!-- library for persistance storage -->

	<script>
	var opts = {
		"stateOptions": {         
					storageKey: "gridState-<?php echo $tab?>",
					columns: true, // remember column chooser settings
					filters: true, // search filters
					selection: true, // row selection
					expansion: false, // subgrid expansion
					pager: false, // page number
					order: true // field ordering
					}
		};
	</script>	
	<br>
	<fieldset>
		<?php echo $out?>
		<br>
		<button onclick="$('#list1_<?php echo $tab?>').gridState().remove('gridState-<?php echo $tab?>'); forms[0].submit(); ">Forget Settings</button>		
	</fieldset>	
	<?php } ?>
	
    <script>
	// show tooltip on column header
    $(document).ready(function(){
        $("th.ui-th-column").each(function(){
            $(this).attr("title", $(this).text() );
        });
    });
    </script>

	<script>
	jQuery(document).ready(function(){

		jQuery('#list1_<?php echo $tab?>').jqGrid('navButtonAdd', '#list1_<?php echo $tab?>_pager', 
		{
			'caption'      : 'Export Selected', 
			'buttonicon'   : 'ui-icon-extlink', 
			'onClickButton': function()
			{
				// for selected rows
				// var rows = jQuery('#list1').jqGrid('getGridParam','selarrrow'); 
				
				// for all selected rows (across page)
				var gState = jQuery('#list1_<?php echo $tab?>').gridState();
				var gRows = gState.selRows;
				var rows = []; 
				for(k in gRows)
				{
					if (gRows[k] == true)
						rows[rows.length] = k;
				}

				if (rows.length)
				{
					var data = rows.join();
					
					var colModel = jQuery("#list1_<?php echo $tab?>").jqGrid("getGridParam", "colModel");

					var field = colModel[1].name;
					// client_id is first column and it's data will be passed as selected row ids.
					var filter = '{"rules":[{"field":"'+field+'","op":"in","data":"'+data+'"}]}';
					
					window.open("<?php echo $g->options["url"]?>" + "&export=1&jqgrid_page=1&export_type=pdf&_search=true&filters="+filter);
				}
				else
					alert('Select rows to export');
			},
			'position': 'last'
		});
	});
	</script>
	
</body>
</html>