<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

include_once("../../config.php");

include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array(
	"type" 		=> PHPGRID_DBTYPE,
	"server" 	=> PHPGRID_DBHOST,
	"user" 		=> PHPGRID_DBUSER,
	"password" 	=> PHPGRID_DBPASS,
	"database" 	=> PHPGRID_DBNAME
);

$g = new jqgrid();

$opt["caption"] = "Dairy Products";
$opt["rowNum"] = 20; 
$opt["rowheight"] = 30; 
$opt["add_options"]["width"] = "500";
$opt["edit_options"]["width"] = "500";
$opt["view_options"]["width"] = "500";
// $opt["height"] = "100%";
$opt["subGrid"] = true;
$opt["subgridurl"] = "index_detail.php";
$opt["export"]["heading"] = "Dairy Products";
$opt["form"]["nav"] = true;
// $opt["readonly"] = true; 

// grouping
$opt["grouping"] = true;
$opt["groupingView"] = array();
$opt["groupingView"]["groupField"] = array("company_name"); // specify column name to group listing
$opt["groupingView"]["groupColumnShow"] = array(true); // either show grouped column in list or not (default: true)
$opt["groupingView"]["groupText"] = array("<b>{0} - {1} Products(s)</b>"); // {0} is grouped value, {1} is count in group
$opt["groupingView"]["groupOrder"] = array("desc"); // show group in asc or desc order
$opt["groupingView"]["groupDataSorted"] = array(true); // show sorted data within group
$opt["groupingView"]["groupSummary"] = array(false); // work with summaryType, summaryTpl, see column: $col["name"] = "total"; (if false, set showSummaryOnHide to false)
$opt["groupingView"]["groupCollapse"] = false; // Turn true to show group collapse (default: false) 
$opt["groupingView"]["showSummaryOnHide"] = false; // show summary row even if group collapsed (hide) 

$opt["loadComplete"] = "function(){ grid_load(); }";


// Define predefined search templates
$opt["search_options"]["tmplNames"] = array("Choco Products","Price > 10");
$opt["search_options"]["tmplFilters"] = array(
	array(
		"groupOp" => "AND",
		"rules" => array (
						array("field"=>"product_name", "op"=>"cn", "data"=>"Choc")
						)
		),
		array(
			"groupOp" => "AND",
			"rules" => array (
							array("field"=>"unit_price", "op"=>"gt", "data"=>"10"),
							array("field"=>"quantity_per_unit", "op"=>"cn", "data"=>"box"),		
							)
		)	
);

$g->set_options($opt);

$g->table = "products";
$g->select_command = "select p.*,s.company_name, category_name from products p
			inner join suppliers s on s.supplier_id = p.supplier_id
			inner join categories c on c.category_id = p.category_id
			";

$cols = array();

$col = array();
$col["title"] = "Product Id";
$col["name"] = "product_id";
$col["width"] = "10";
$col["editable"] = true;
$col["search"] = true;
$col["hidden"] = true;
$col["export"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Image";
$col["name"] = "product_image";
$col["width"] = "30";
$col["editable"] = false;
$col["icon"] = "image";
$col["search"] = false;
$col["sortable"] = false;
$col["template"] = "<div style='background-image: url(../images/products/{product_id}.jpg); max-width:100px; max-height:100px; width:100px; height:30px; background-position-y: center;'><img style='display:none' src='../images/products/{product_id}.jpg' /></div>";
$cols[] = $col;

$col = array();
$col["title"] = "Product Name";
$col["name"] = "product_name";
$col["width"] = "50";
$col["editable"] = true;
$col["search"] = true;
$col["formatter"] = "rowbar";
$cols[] = $col;

$col = array();
$col["title"] = "Company Name";
$col["name"] = "company_name";
$col["width"] = "50";
$col["editable"] = false;
$col["export"] = false;
$col["search"] = true;
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["editoptions"] = array("table"=>"suppliers", "id"=>"company_name", "label"=>"company_name");
$cols[] = $col;

$col = array();
$col["title"] = "Supplier";
$col["name"] = "supplier_id";
$col["dbname"] = "s.supplier_id";
$col["width"] = "50";
$col["show"]["list"] = false;
$col["show"]["view"] = false;
$col["editable"] = true;
$col["search"] = true;
$col["hidedlg"] = true;
$col["edittype"] = "lookup";
$col["editoptions"] = array("table"=>"suppliers", "id"=>"supplier_id", "label"=>"company_name");
$cols[] = $col;

$col = array();
$col["title"] = "Category Name";
$col["name"] = "category_name";
$col["width"] = "50";
$col["editable"] = false;
$col["export"] = false;
$col["search"] = true;
$col["formatter"] = "badge";
$col["edittype"] = "lookup";
$col["editoptions"] = array("table"=>"categories", "id"=>"category_name", "label"=>"category_name");

// instead of above you can specify SQL query instead of these table,id,label combination. e.g.
// $col["editoptions"]["sql"] = "SELECT category_name as k, category_name as v FROM categories";

$cols[] = $col;

$col = array();
$col["title"] = "Category";
$col["name"] = "category_id";
$col["dbname"] = "c.category_id";
$col["width"] = "30";
$col["show"]["list"] = false;
$col["show"]["view"] = false;
$col["editable"] = true;
$col["search"] = true;
$col["hidedlg"] = true;
$col["edittype"] = "lookup";
$col["editoptions"] = array("table"=>"categories", "id"=>"category_id", "label"=>"category_name");
$cols[] = $col;

$col = array();
$col["title"] = "Quantity / Unit";
$col["name"] = "quantity_per_unit";
$col["width"] = "50";
$col["editable"] = true;
$col["search"] = true;
$col["sanitize"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Unit Price";
$col["name"] = "unit_price";
$col["formatter"] = "currency";
$col["width"] = "30";
$col["align"] = "right";
$col["firstsortorder"] = "desc";
$col["editable"] = true;
$col["search"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "# in Stock";
$col["name"] = "units_in_stock";
$col["formatter"] = "integer";
$col["width"] = "30";
$col["editable"] = true;
$col["search"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Reorder";
$col["name"] = "reorder_level";
$col["formatter"] = "integer";
$col["width"] = "30";
$col["editable"] = true;
$col["search"] = true;
$cols[] = $col;

$g->set_columns($cols);

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "1";
$f["cellcss"] = "'color':'#8b86ff','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "2";
$f["cellcss"] = "'color':'#20c933','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "3";
$f["cellcss"] = "'color':'#20c933','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "4";
$f["cellcss"] = "'color':'#f82b60','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "5";
$f["cellcss"] = "'color':'#f82b60','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "6";
$f["cellcss"] = "'color':'#20d9d2','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "7";
$f["cellcss"] = "'color':'#ff6f2c','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "category_id";
$f["target"] = "category_name";
$f["op"] = "=";
$f["value"] = "8";
$f["cellcss"] = "'color':'#ff6f2c','font-weight':'bold','background':'none'"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "units_in_stock";
$f["op"] = "<";
$f["value"] = "{reorder_level}";
$f["cellcss"] = "'background-color':'#ff851b','color':'white','fontWeight':'bold','opacity':0.4"; 
$f_conditions[] = $f;

$f = array();
$f["column"] = "unit_price";
$f["op"] = "<";
$f["value"] = "20";
$f["cellcss"] = "'background-color':'#c0e8ca','color':'green','fontWeight':'bold','opacity':0.4"; 
$f_conditions[] = $f;

$g->set_conditional_css($f_conditions);

$g->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"export_pdf"=>true, 
						"showhidecolumns"=>true,
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "group",
						"aiassistant" => true
					)
				);

$out = $g->render("list_promo");

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/material/jquery-ui.custom.css" />
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css" />

	<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script src="//cdnjs.cloudflare.com/ajax/libs/jquery-migrate/3.0.1/jquery-migrate.min.js"></script>

	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
	
	<link href="//cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" rel="stylesheet" />
	<script src="//cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>	
	<link href="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.css" rel="stylesheet" />
	<script src="//cdn.jsdelivr.net/gh/wenzhixin/multiple-select@1.2.1/multiple-select.js"></script>	

</head>
<body>

	<div>
		<?php echo $out?>
	</div>
	<script>

	// $.jgrid.nav.addtext = "Add";
	// $.jgrid.nav.edittext = "Edit";
	// $.jgrid.nav.deltext = "Delete";
	// $.jgrid.nav.viewtext = "View";

	function grid_load(){}
	</script>
	
	<style>
	.ui-priority-secondary
	{
		background-color: #f5f5f5;
		opacity: 1 !important;
	}
	
	#trv_product_image #v_product_image div
	{
		height: 110px !important;
		position: absolute;
		right: 20px;
		background-repeat: no-repeat;
		opacity: 0.9;
		top: 10px; 
	}
	</style>

</body>
</html>
