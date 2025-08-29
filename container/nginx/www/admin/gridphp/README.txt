INSTALLATION 
------------

INSTALLER
.........

1) Extract the product archive in web root. e.g. www/gridphp
2) Open it in browser to run installer. e.g. http://localhost/gridphp

MANUAL
......

1) Execute "database.sql" on a Mysql Database. It will create 'griddemo' database.
2) Place all files in a directory on the web server. e.g. ".../www/gridphp/"
3) Rename config.sample.php to config.php, and update database config. e.g.

	define("PHPGRID_DBTYPE","mysqli");
	define("PHPGRID_DBHOST","localhost");
	define("PHPGRID_DBUSER","root");
	define("PHPGRID_DBPASS","");
	define("PHPGRID_DBNAME","griddemo");
	
	// It will work in normal cases, unless you change lib folder location
	define("PHPGRID_LIBPATH",dirname(__FILE__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR);

4) Run the product demos in browser. e.g. http://localhost/gridphp/index.php 

INTEGRATION
-----------
- For integration in your app, you need to consider 3 points.

1) Set Database config in config.php OR in grid php file e.g.

	define("PHPGRID_DBTYPE","mysqli"); // mysqli,oci8(for oracle),mssql,postgres,sybase
	define("PHPGRID_DBHOST","127.0.0.1");
	define("PHPGRID_DBUSER","root");
	define("PHPGRID_DBPASS","abc");
	define("PHPGRID_DBNAME","testdb");

2) The folder "../../lib" will be replaced by path where you place 'lib' folder (if changed)

	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/start/jquery-ui.custom.css">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css">
	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>	
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

3) Update include path where you place lib/inc/jqgrid_dist.php. (if changed)

	include("../../lib/inc/jqgrid_dist.php");
	$g = new jqgrid($db_conf);

Refer 'Getting Started' section on https://www.gridphp.com/docs for more details.

Refer 'FAQs' on https://www.gridphp.com/faqs for common questions and issues.

UPGRADE
-------
To upgrade, just override "lib/inc" & "lib/js" folder in previous implementations. For technical support queries, suggestions and wishlist, you can contact at our Support Center (https://www.gridphp.com/support)

UPDATES
-------
Visit https://www.gridphp.com/updates for updates and changelog.

FEEDBACK
--------
Post bugs/wishlist at https://www.gridphp.com/support

LICENSE
-------
Must read and agree LICENSE.txt before use.
