<?php

// PHP Grid database connection settings, Only need to update these in new project

// replace mysqli with one of these: mysqli,oci8 (for oracle),pdo,mssqlnative,postgres,sybase
define("PHPGRID_DBTYPE","mysqli"); 
define("PHPGRID_DBHOST","{{dbhost}}");
define("PHPGRID_DBUSER","{{dbuser}}");
define("PHPGRID_DBPASS","{{dbpass}}");
define("PHPGRID_DBNAME","ct_donation_management");

// Basepath for lib
// define("PHPGRID_LIBPATH",dirname(__FILE__).DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR);
define("PHPGRID_LIBPATH","../../lib".DIRECTORY_SEPARATOR);
define("PHPGRID_URL","../../");

// get application name setting or use default
define("APP_NAME",get_option("app_name","Donation Management"));

// Show debugging message in case of an issue, should be turned off for production
define("PHPGRID_DEBUG","1");

// AI Api Key, Free API Key can be obtained from https://console.groq.com/
define("PHPGRID_AI_KEY","{{apikey}}");

// set default timezone
date_default_timezone_set("Asia/Karachi");

// ----------------
// Helper Functions
// ----------------

function get_clean($str)
{
	$str = trim($str);
	$str = strtolower($str);

	// kill anything that is not a letter, digit, space
	$str = preg_replace ("/[^a-zA-Z0-9]/", "_", $str);
	$str = preg_replace ("/[_]+/", "_", $str);
	$str = trim($str,'_');
	return $str;
}

function has_access($access)
{
	// if auth not enabled
	if (!isset($_SESSION["loggedin"]))
		return true;

	switch ($access)
	{
		// editing operation
		case "editing":
			switch($_SESSION["role"])
			{
				case "admin":
				case "editor":
					return true;
			}
		break;

		// users operation
		case "manage_users":
		case "all_rows":
			switch($_SESSION["role"])
			{
				case "admin":
					return true;
			}
	}

	return false;
}

function has_role($role)
{
	// if auth not enabled
	if (!isset($_SESSION["loggedin"]))
		return true;

	if ($role[0] == "!")
	{
		$role = substr($role,1);
		return ($_SESSION["role"] != $role);
	}
	else
		return ($_SESSION["role"] == $role);
}

function logged_in()
{
	return isset($_SESSION["loggedin"]);
}


function get_option($name,$default="")
{
	include_once(PHPGRID_LIBPATH.'inc/jqgrid_dist.php');
	$obj=new jqgrid();

	$rs=$obj->get_one('SELECT name,value FROM tb_settings WHERE name=?',array($name));
	if (!empty($rs["value"]))
		return $rs["value"];
	else
		return $default;
}