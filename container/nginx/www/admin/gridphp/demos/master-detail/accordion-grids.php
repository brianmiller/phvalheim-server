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
				
// first grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Employees Data";
$opt["height"] = "200";
$grid->set_options($opt);
$grid->table = "employees";
// generate grid output, with unique grid name as 'list1'
$out_master = $grid->render("list1");

// second grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["height"] = "200";
$opt["caption"] = "Products Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["autowidth"] = true;
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

// this db table will be used for add,edit,delete
$grid->table = "products";
// generate grid output, with unique grid name as 'list2'
$out_detail = $grid->render("list2");


// third grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Customers";
$opt["height"] = "200";
$grid->set_options($opt);
$grid->table = "clients";
// generate grid output, with unique grid name as 'list3'
$out_third = $grid->render("list3");

// forth second grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Customers";
$opt["height"] = "200";
$opt["scroll"] = true;
$grid->set_options($opt);
$grid->table = "customers";
// generate grid output, with unique grid name as 'list4'
$out_forth = $grid->render("list4");
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


<script>
$(function() {
	$( "#accordion" ).accordion({
      heightStyle: "content"
    });
});
</script>
</head>
<body>

<div id="accordion">
  <h3>Employees</h3>
  <div>
	<p>Proin elit arcu, rutrum commodo, vehicula tempus, commodo a, risus. Curabitur nec arcu. Donec sollicitudin mi sit amet mauris. Nam elementum quam ullamcorper ante. Etiam aliquet massa et lorem. Mauris dapibus lacus auctor risus. Aenean tempor ullamcorper leo. Vivamus sed magna quis ligula eleifend adipiscing. Duis orci. Aliquam sodales tortor vitae ipsum. Aliquam nulla. Duis aliquam molestie erat. Ut et mauris vel pede varius sollicitudin. Sed ut dolor nec orci tincidunt interdum. Phasellus ipsum. Nunc tristique tempus lectus.</p>
	<?php echo $out_master ?>
  </div>
  <h3>Products</h3>
  <div>
	<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
	<?php echo $out_detail?>
  </div>
  <h3 onclick="setTimeout(()=>{phpgrid_list3.fx_grid_resize(); phpgrid_list4.fx_grid_resize();},20)">Customers</h3>
  <div>
		<p>Duis cursus. Maecenas ligula eros, blandit nec, pharetra at, semper at, magna. Nullam ac lacus. Nulla facilisi. Praesent viverra justo vitae neque. Praesent blandit adipiscing velit. Suspendisse potenti. Donec mattis, pede vel pharetra blandit, magna ligula faucibus eros, id euismod lacus dolor eget odio. Nam scelerisque. Donec non libero sed nulla mattis commodo. Ut sagittis. Donec nisi lectus, feugiat porttitor, tempor ac, tempor vitae, pede. Aenean vehicula velit eu tellus interdum rutrum. Maecenas commodo. Pellentesque nec elit. Fusce in lacus. Vivamus a libero vitae lectus hendrerit hendrerit.</p>
		
		<div style="margin:0px; display: flex">

			<div style="width:48%; margin-right:40px;">
				<?php echo $out_third; ?>
			</div>

			<div style="width:48%; float:left;">
				<?php echo $out_forth; ?>
			</div>	
		</div>
  </div>
</div>

</body>
</html>