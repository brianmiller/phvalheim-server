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
// set table for CRUD operations
$g->table = "clients";
$grid["caption"] = "Grid 1";
$grid["autowidth"] = true;
$grid["responsive"] = true; // responsive effect
// required for iphone/safari scroll display
// $grid["height"] = "auto";
$g->set_options($grid);
// render grid
$out1 = $g->render("list1");


$g = new jqgrid($db_conf);
// set table for CRUD operations
$g->table = "invheader";
$grid["caption"] = "Grid 2";
$grid["autowidth"] = true;
$grid["responsive"] = true; // responsive effect
// required for iphone/safari scroll display
// $grid["height"] = "auto";
$g->set_options($grid);
// render grid
$out2 = $g->render("list2");


$black = array("dark-one","metro-black","black-tie","dark-hive","dot-luv","trontastic","vader","ui-darkness");
$white = array("base","material","metro-light","blitzer","south-street","start","cupertino","flick","hot-sneaks","redmond","smoothness");
$mix = array("metro-dark","swanky-purse","eggplant","le-frog","mint-choc","sunny","ui-lightness","pepper-grinder","overcast","humanity","excite-bike");
$wijmo = array("arctic","midnight","aristo","rocket","cobalt","sterling");

$themes = array_merge($black,$white,$mix,$wijmo);
$i = rand(0,count($themes)-1);

// if set from page
if (is_numeric($_GET["themeid"]))
	$i = $_GET["themeid"];
else
	$i = 0;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>PHP Grid Control Demos | www.phpgrid.org</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
    <!--[if lt IE 9]>
      <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->

	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/<?php echo $themes[$i] ?>/jquery-ui.custom.css">

  <script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
  
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta/css/bootstrap.min.css">
	<!-- bootstrap3 + jqgrid compatibility css -->
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.bs.css">	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.11.0/umd/popper.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta/js/bootstrap.min.js"></script>

	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
		
	
  </head>

  <body>
		
    <nav class="navbar navbar-expand-md navbar-dark fixed-top bg-dark">
      <a class="navbar-brand" href="#">Navbar</a>
      <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarsExampleDefault" aria-controls="navbarsExampleDefault" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navbarsExampleDefault">
        <ul class="navbar-nav mr-auto">
          <li class="nav-item active">
            <a class="nav-link" href="#">Home <span class="sr-only">(current)</span></a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">Link</a>
          </li>
          <li class="nav-item">
            <a class="nav-link disabled" href="#">Disabled</a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="http://example.com" id="dropdown01" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Dropdown</a>
            <div class="dropdown-menu" aria-labelledby="dropdown01">
              <a class="dropdown-item" href="#">Action</a>
              <a class="dropdown-item" href="#">Another action</a>
              <a class="dropdown-item" href="#">Something else here</a>
            </div>
          </li>
        </ul>
        <form class="form-inline my-2 my-lg-0">
          <input class="form-control mr-sm-2" type="text" placeholder="Search" aria-label="Search">
          <button class="btn btn-outline-success my-2 my-sm-0" type="submit">Search</button>
        </form>
      </div>
    </nav>

    <!-- Main jumbotron for a primary marketing message or call to action -->
    <div class="jumbotron" style="padding-bottom:10px; margin-bottom:0px">
      <div class="container">
        <h1 class="display-3">Hello, world!</h1>
        <p>This is a template for a simple marketing or informational website. It includes a large callout called a jumbotron and three supporting pieces of content. Use it as a starting point to create something more unique.</p>
      </div>
    </div>
	
	<div class="container">
		<div class="row">
			<div class="col-md-12">
		
				<div style="padding:0 20px">

					<p>
					<form method="get">
					Choose Theme: <select name="themeid" onchange="form.submit()">
						<?php foreach($themes as $k=>$t) { ?>
							<option value=<?php echo $k?> <?php echo ($i==$k)?"selected":""?>><?php echo ucwords($t)?></option>
						<?php } ?>
					</select> - 
					You can also have your customized theme (<a href="http://jqueryui.com/themeroller">jqueryui.com/themeroller</a>).
					</form>			
					</p>
							
							
					<ul id="bstabs" class="nav nav-tabs">
					  <li class="nav-item">
						<a class="nav-link active" href="#home">Active</a>
					  </li>
					  <li class="nav-item">
						<a class="nav-link" href="#profile">Profile</a>
					  </li>
					</ul>
							
					<!-- Tab panes -->
					<div class="tab-content">
						<div role="tabpanel" class="tab-pane active" id="home"><?php echo $out1?></div>
						<div role="tabpanel" class="tab-pane" id="profile">
							
							<?php echo $out2 ?>
						
						</div>
						<div role="tabpanel" class="tab-pane" id="messages">3...</div>
						<div role="tabpanel" class="tab-pane" id="settings">4...</div>
					</div>

				</div>

			
				<script>
				jQuery('#bstabs a').click(function (e) {
					e.preventDefault()
					jQuery(this).tab('show')
				})	
				</script>

				<style>
				.tab-pane {padding:10px;}
				</style>
			</div>
		</div>
      <!-- Example row of columns -->
      <div class="row">
        <div class="col-md-4">
          <h2>Heading</h2>
          <p>
		  Donec id elit non mi porta gravida at eget metus. Fusce dapibus, tellus ac cursus commodo, tortor mauris condimentum nibh, ut fermentum massa justo sit amet risus. Etiam porta sem malesuada magna mollis euismod. Donec sed odio dui. </p>
          <p><a class="btn btn-secondary" href="#" role="button">View details &raquo;</a></p>
        </div>
        <div class="col-md-4">
          <h2>Heading</h2>
          <p>Donec id elit non mi porta gravida at eget metus. Fusce dapibus, tellus ac cursus commodo, tortor mauris condimentum nibh, ut fermentum massa justo sit amet risus. Etiam porta sem malesuada magna mollis euismod. Donec sed odio dui. </p>
          <p><a class="btn btn-secondary" href="#" role="button">View details &raquo;</a></p>
        </div>
        <div class="col-md-4">
          <h2>Heading</h2>
          <p>Donec sed odio dui. Cras justo odio, dapibus ac facilisis in, egestas eget quam. Vestibulum id ligula porta felis euismod semper. Fusce dapibus, tellus ac cursus commodo, tortor mauris condimentum nibh, ut fermentum massa justo sit amet risus.</p>
          <p><a class="btn btn-secondary" href="#" role="button">View details &raquo;</a></p>
        </div>
      </div>

      <hr>

      <footer>
        <p>&copy; Company 2017</p>
      </footer>
    </div> <!-- /container -->
		
  </body>
</html>
