<?php 
/**
 * Grid 4 PHP Framework
 *
 * @author Abu Ghufran <gridphp@gmail.com> - https://www.gridphp.com
 * @version 3.0.0
 * @license: see license.txt included in package
 */

if(!file_exists("config.php"))
{
	header("location: ./install.php");
	die;
}
 
include_once("./config.php");

include_once("./auth.php");

$mod = $_GET["mod"];
$rep = $_GET["rep"];

// template variables
if (empty($mod)) $mod = "product_inventory";

if (!empty($mod))
{
	$vars = require_once("modules/$mod.php");
	extract($vars);
}
else if (!empty($rep))
{
	$vars = require_once("reports/$rep.php");
	extract($vars);
}

// load in default layout if not specified
$layout = empty($layout) ? "layout1" : $layout;
include_once("./theme/$layout.php");