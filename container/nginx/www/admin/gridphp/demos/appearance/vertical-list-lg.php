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

$g = new jqgrid($db_conf);

$opt["caption"] = "Large Screen Vertical Listing";
// $opt["search_options"]["autofilter"] = "xs+"; // xs+, sm+, md+

// remove tooltip for custom column
$opt["loadComplete"] = "function(){ 
			jQuery('[aria-describedby$=xs_list]').each(function(){ jQuery(this).removeAttr('title'); }) 
}";

$opt["cmTemplate"]["visible"] = array("xs","sm","md");
$g->set_options($opt);

$g->table = "products";
$g->select_command = "select product_id, '' as image, product_name, s.company_name, category_name, 
					concat('$', format(unit_price, 2)) as unit_price,
					reorder_level, units_in_stock, units_on_order, quantity_per_unit
					from products p
					inner join suppliers s on s.supplier_id = p.supplier_id
					inner join categories c on c.category_id = p.category_id
					";

$col = array();
$col["title"] = "ID";
$col["name"] = "product_id";
$col["hidden"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Image";
$col["name"] = "image";
$col["width"] = 90;
$col["editable"] = false;
$col["sortable"] = false;
$col["search"] = false;
$col["template"] = "<div style='background-image: url(../images/products/{product_id}.jpg); max-width:100px; max-height:100px; width:100px; height: 30px; background-position-y: center;'><img style='display:none' src='../images/products/{product_id}.jpg' /></div>";
$cols[] = $col;

$col = array();
$col["title"] = "Items List";
$col["name"] = "custom_lg_column"; // this name 'xs_list' must be used as it is for xs custom column listing
$col["width"] = "100";
$col["visible"] = "lg+"; // only for lg screens
$col["viewable"] = false;
$col["editable"] = false; // this column is not editable
$col["search"] = false; // this column is not searchable
$col["template"] = "<div class='xs_row'>

	<div style='float:left; width:10%'><img width='100%' src='../images/products/{product_id}.jpg'></div>

	<div style='float:left; width:40%; margin-left:5px;'>
		<div class='xs_caption'>Product:</div>
		<div class='xs_data'>{product_name}</div>
		<div style='clear:both'></div>

		<div class='xs_caption'>Company:</div>
		<div class='xs_data'>{company_name}</div>
		<div style='clear:both'></div>

		<div class='xs_caption'>Category:</div>
		<div class='xs_data'>{category_name}</div>
		<div style='clear:both'></div>

		<div class='xs_caption'>Price:</div>
		<div class='xs_data'>{unit_price}</div>
		<div style='clear:both'></div>
	</div>

	<div style='float:left; width:40%; margin-left:5px;'>
		<div class='xs_caption'>Notes:</div>
		<div class='xs_data'>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</div>
		<div style='clear:both'></div>
	</div>

</div>";
$cols[] = $col;

$col = array();
$col["name"] = "act"; // this name 'xs_list' must be used as it is for xs custom column listing
$col["title"] = "Actions"; // this name 'xs_list' must be used as it is for xs custom column listing
$col["visible"] = array("xs","sm","md");
$cols[] = $col;

$g->set_columns($cols,true);

$g->set_actions(array(	
						"add"=>false, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "group",
					) 
				);

$out = $g->render("list1");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/material/jquery-ui.custom.css" />
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css" />

	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

</head>
<body>
	<style>
	/* customization to show vertial record listing */
	@media only screen and (min-width:992px) 
	{
		.xs_caption { font-weight:bold; padding:5px; float:left; text-align:left; width: 15%; }
		.xs_data { padding:5px; float:left; }
		.xs_row { padding:5px; }

		/* hide header+search of custom listing */
		.ui-jqgrid-hdiv { display: none; }
		.ui-jqgrid tr.jqgrow td {white-space: normal;}

		/* hide only search (if showing header) of custom listing */
		.ui-search-toolbar { display: none !important; }
	}		
	</style>
	<div>
	<?php echo $out?>
	</div>
</body>
</html>
