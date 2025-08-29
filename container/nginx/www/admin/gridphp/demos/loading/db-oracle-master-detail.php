<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

/**
 * To support non-mysql databases (even mysql), see adodb lib documentation below:
 * http://phplens.com/lens/adodb/docs-adodb.htm#connect_ex
 * http://phplens.com/lens/adodb/docs-adodb.htm#drivers
 * 
 * For oracle, extension of oci8 should be enabled in php.ini
 */
 
$db_conf = array();
$db_conf["type"] = "oci8"; // mysql,oci8(for oracle),mssql,postgres,sybase
$db_conf["server"] = "127.0.0.1:1521";
$db_conf["user"] = "system";
$db_conf["password"] = "asd";
$db_conf["database"] = "xe";
		 
include("../../lib/inc/jqgrid_dist.php");
$grid = new jqgrid($db_conf);

$opt["caption"] = "Departments Data";
// following params will enable subgrid -- by default first column (PK) of parent is passed as param 'id'
$opt["detail_grid_id"] = "list2";

// extra params passed to detail grid, column name comma separated
$opt["subgridparams"] = "DEPARTMENT_ID,DEPARTMENT_NAME,MANAGER_ID";

$grid->set_options($opt);
$grid->table = "hr.departments";

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>false, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);
$out_master = $grid->render("list1");

// detail grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["sortname"] = 'EMPLOYEE_ID'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["rowNum"] = "10";
$opt["height"] = ""; // autofit height of subgrid
$opt["caption"] = "Employee Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["export"] = array("filename"=>"my-file", "sheetname"=>"test"); // export to excel parameters
$grid->set_options($opt);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// receive id, selected row of parent grid
$id = intval($_GET["rowid"]); // rownum of master column
$dept = $_GET["DEPARTMENT_NAME"];
$did = intval($_GET["DEPARTMENT_ID"]);

// and use in sql for filteration
if ($did)
	$str = "WHERE department_id = $did";

$grid->select_command = "SELECT * FROM hr.employees $str";

$col = array();
$col["title"] = "Employee Id";
$col["name"] = "EMPLOYEE_ID";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "First Name";
$col["name"] = "FIRST_NAME";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Last Name";
$col["name"] = "LAST_NAME";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Email";
$col["name"] = "EMAIL";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Manager Id";
$col["name"] = "MANAGER_ID";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Department Id";
$col["name"] = "DEPARTMENT_ID";
$col["editable"] = false;
$cols[] = $col;

$grid->set_columns($cols);

$e["on_insert"] = array("add_dept_id", null, true);
$grid->set_events($e);

function add_dept_id(&$data)
{
	$did = intval($_GET["rowid"]);
	$data["params"]["DEPARTMENT_ID"] = $did;
}

// this db table will be used for add,edit,delete
$grid->table = "hr.employees";

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
</head>
<body>
	You must have Oracle Server installed for this demo. Also set database crendentials in this demo.
	<div style="margin:10px">
	<?php echo $out_master ?>
	<br>
	<br>
	<?php echo $out_detail?>
	</div>
</body>
</html>
