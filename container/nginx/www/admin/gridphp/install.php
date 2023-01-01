<?php
/**
 * Grid 4 PHP Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - https://www.gridphp.com
 * @version 2.8
 * @license: see license.txt included in package
 */

set_time_limit(0);

global $valid;

$valid['config'] = true;
$valid['connection'] = true;
$valid['dbready'] = true;
$valid['confwritable'] = false;

if(is_writeable('.'))
{
    $valid['confwritable'] = true;
}

if (!empty($_POST))
{
	do_install();
	
	$ready = true;
	foreach($valid as $key=>$value) 
	{
		if($value === false)
		{
			$ready = false;
		}
	}
	if ($ready)
	{
		// delete from non-dev server
		if ($_SERVER["SERVER_ADDR"] != "127.0.0.1")
		{
			@unlink("install.php");
			@unlink("database.sql");
			@unlink("config.sample.php");
		}
		
		header("location: ./index.php?track=installed");
		die;
	}
}	

function do_install()
{
	global $valid;
	
	extract($_POST);
		
	if (function_exists("mysqli_connect"))
	{
		if (isset($_POST["createdb"]) && $_POST["createdb"] == 1)
			$link = @mysqli_connect($dbhost, $dbuser, $dbpass);
		else
			$link = @mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
			
		if (!$link) {
			$valid['connection'] = false;
			$valid['connection_msg'] = 'Database not connected, Kindly check database configuration.';
		}
	}
	else
	{
		$link = mysql_connect($dbhost, $dbuser, $dbpass);
		if (!$link) {
			$valid['connection'] = false;
			$valid['connection_msg'] = 'Database not connected, Kindly check database configuration.';
		}

		// if db does not need to be created, then select it
		if (empty($_POST["createdb"]))
		{
			$db_selected = mysql_select_db($dbname);
			if (!$db_selected) {
				$valid['connection'] = false;
				$valid['connection_msg'] = 'Database not connected, Kindly check database configuration.';
			}
		}
	}
	
	if (!$valid['connection'])
		return;
	
	if ($valid['connection'] == true)
	{
		$templine = '';
		
		// Read in entire file
		$lines = file("database.sql");

		// append create db calls
		if ($_POST["createdb"] == 1)
		{
			// Loop through each line
			foreach ($lines as &$line)
			{
				// ignore internal create db if used from installer
				if ((strstr($line,"CREATE DATABASE") !== false || strstr($line,"USE") !== false))
					$line = "";
			}
			
			array_unshift($lines, "CREATE DATABASE `$dbname`;", "USE `$dbname`;");
		}
		// append on USE db call
		else
		{
			// Loop through each line
			foreach ($lines as &$line)
			{
				// ignore internal create db if used from installer
				if ((strstr($line,"CREATE DATABASE") !== false || strstr($line,"USE") !== false))
					$line = "";
			}
			
			array_unshift($lines, "USE `$dbname`;");		
		}
		
		// was reference to last index of $lines
		unset($line);
		
		// Loop through each line
		foreach ($lines as $line)
		{
			// Skip it if it's a comment
			if (substr($line, 0, 2) == '--' || $line == '')
				continue;
			// Add this line to the current segment
			$templine .= $line;
			// If it has a semicolon at the end, it's the end of the query
			if (substr(trim($line), -1, 1) == ';')
			{
				// Perform the query
				if (function_exists("mysqli_connect"))
				{
					if (!mysqli_query($link,$templine))
					{
						$valid['dbready'] = false;
						$valid['dbready_msg'] .= 'Error performing query \'<strong>' . $templine . '\': ' . mysqli_error($link) .'</strong>' ;
						// break;
					}
				}
				else
				{
					if (!mysql_query($templine))
					{
						$valid['dbready'] = false;
						$valid['dbready_msg'] .= 'Error performing query \'<strong>' . $templine . '\': ' . mysql_error() .'</strong>';
						// break;
					}
				}
				// Reset temp variable to empty
				$templine = '';
			}
		}
	}
	
	if (!$valid['dbready'])
		return;	
	
	// create or override config file
	$scriptName = $_SERVER['SCRIPT_NAME'];
	$webRoot = substr($scriptName, 0, strlen($scriptName) - strlen('/install.php'));

	$configContents = file_get_contents("config.sample.php");
	$configContents = str_replace("{{dbtype}}", $dbtype, $configContents);
	$configContents = str_replace("{{dbhost}}", $dbhost, $configContents);
	$configContents = str_replace("{{dbuser}}", $dbuser, $configContents);
	$configContents = str_replace("{{dbpass}}", $dbpass, $configContents);
	$configContents = str_replace("{{dbname}}", $dbname, $configContents);

	$handle = fopen("config.php", "w+");
	
	if (!$handle)
		$valid['config'] = false;
	
	fwrite($handle, $configContents);
	fclose($handle);
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Grid 4 PHP Framework Demos | www.gridphp.com</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="">
    <meta name="author" content="">

    <!-- Le styles -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style type="text/css">
      body {
        padding-top: 60px;
        padding-bottom: 0;
      }
      .sidebar-nav {
        padding: 9px 0;
      }
    </style>

    <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
    <!--[if lt IE 9]>
      <script src="//html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->

  </head>

  <body>
    <div class="navbar navbar-inverse navbar-fixed-top">
      <div class="navbar-inner">
        <div class="container-fluid">
          <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
          </a>
          <a class="brand" href="#">Grid 4 PHP Framework Demos</a>
          <div class="nav-collapse collapse">
            <p class="navbar-text pull-right">
            	(Build Version 2.8) — 
              	<a href="https://www.gridphp.com/" class="navbar-link">www.gridphp.com</a>
            </p>
            <ul class="nav">
			  <li><a target="_blank" href="https://www.gridphp.com/">Home</a></li>
              <li><a target="_blank" href="https://www.gridphp.com/docs/">Documentation & FAQs</a></li>
              <li><a target="_blank" href="https://www.gridphp.com/support/">Support Forum</a></li>
              <li><a target="_blank" href="https://www.gridphp.com/contact/">Contact Us</a></li>
            </ul>
          </div><!--/.nav-collapse -->
        </div>
      </div>
    </div>

    <div class="container-fluid">
      <div class="row-fluid">
        <div class="span12">


			<!-- Form Name -->
			<legend>Grid 4 PHP - Installation</legend>
			
			<?php if (!empty($_POST)): ?>
				<?php if ($valid['connection']): ?>
				<div class="alert alert-success">
					<strong>Checking if connection is valid:</strong> Database connected.
				</div>
				<?php else: ?>
				<div class="alert alert-error">
					<strong>Checking if connection is valid:</strong> <?php echo $valid['connection_msg']; ?>
				</div>
				<?php endif; ?>
				
				<?php if (!$valid['config']): ?>
				<div class="alert alert-error">
					<strong>Writing to config file:</strong> <p>The configuration file is not writable. Check permissions of the containing folder.
					<p>Please rename config.sample.php to config.php and update the database configuration referring <a href="README.txt">Manual Setup</a></p>
				</div>				
				<?php endif; ?>
				
			<?php endif; ?>
			
			<?php if ($valid['confwritable']): ?>
			<div class="alert alert-success">
				<strong>Checking if config writable:</strong> Your config file is writable.
			</div>
			<?php else: ?>
			<div class="alert alert-error">
					<strong>Writing to config file:</strong> <p>The configuration file is not writable. Check permissions of the containing folder.
					<p>Please rename config.sample.php to config.php and update the database configuration referring <a href="README.txt">Manual Setup</a></p>
			</div>
			<?php endif; ?>
			
			<?php if ($valid['dbready'] === false): ?>
			<div class="alert alert-error">
				<strong>Error:</strong> <?php echo $valid['dbready_msg']; ?>
			</div>
			<?php endif; ?>
			
			<form class="form-horizontal" method="post">
			<fieldset>

			<!-- Select Basic -->
			<div class="control-group">
			  <label class="control-label" for="selectbasic">Database Type</label>
			  <div class="controls">
				<select id="dbtype" name="dbtype" class="input-xlarge">
				  <option value="mysqli" <?php echo (isset($_POST['dbtype']) && $_POST['dbtype']=='mysqli') ? "selected" : "" ?>>MySql</option>
				</select>
			  </div>
			</div>

			<!-- Text input-->
			<div class="control-group">
			  <label class="control-label" for="db">Database Host</label>
			  <div class="controls">
				<input id="dbhost" name="dbhost" type="text" placeholder="localhost" class="input-xlarge" required="" value="<?php echo isset($_POST['dbhost']) ? $_POST['dbhost'] : "localhost" ?>">
				
			  </div>
			</div>

			<!-- Text input-->
			<div class="control-group">
			  <label class="control-label" for="dbuser">Database Username</label>
			  <div class="controls">
				<input id="dbuser" name="dbuser" type="text" placeholder="" class="input-xlarge" required="" value="<?php echo isset($_POST['dbuser']) ? $_POST['dbuser'] : "" ?>">
				
			  </div>
			</div>

			<!-- Password input-->
			<div class="control-group">
			  <label class="control-label" for="dbpass">Database Password</label>
			  <div class="controls">
				<input id="dbpass" name="dbpass" type="password" placeholder="" class="input-xlarge" value="<?php echo isset($_POST['dbpass']) ? $_POST['dbpass'] : "" ?>">
				
			  </div>
			</div>
			
			<!-- Dbname input-->
			<div class="control-group">
			  <label class="control-label" for="dbpass">Database Name</label>
			  <div class="controls">
				<input id="dbname" name="dbname" type="text" placeholder="" class="input-xlarge" required="" value="<?php echo isset($_POST['dbname']) ? $_POST['dbname'] : "" ?>">
				
				<div>
				<label class="checkbox inline" for="createdb">
				  <input name="createdb" id="createdb" value="1" type="checkbox" onclick="if (this.checked) jQuery('#create_tip').show();">
				  Create Database
				  <span class="help-block alert">
				   NOTE: If checked, database User must have CREATE DATABASE privilege<br>
				   Otherwise You should create database manually before install.
				  </span>
				</label>		
				</div>
			</div>
	  
			</div>

			<!-- Button -->
			<div class="control-group">
			  <label class="control-label" for=""></label>
			  <div class="controls">
				<button id="" name="" class="btn btn-primary">Install</button>
				or 
				<a href="README.txt">Manual Setup</a>
			  </div>
			</div>

			</fieldset>
			</form>		
		</div>
		
		<div class="row-fluid">
			<div class="span12">
			  <div class="row-fluid">
				<div class="alert alert-info">
					<a name="contact"></a>
				  <h2>Technical Support</h2>
				  <p class="text-info">For technical support query, ask at our <a href="https://www.gridphp.com/support/">Support Center</a> </p>
				  <p>&copy; <a href="//www.gridphp.com/">www.gridphp.com</a> 2010-<?php echo date("Y");?></p>
				</div><!--/span-->
			  </div><!--/row-->
			</div><!--/span-->
		  </div><!--/row-->
		  
      </div><!--/row-->

		<!-- Le javascript
		================================================== -->
		<!-- Placed at the end of the document so the pages load faster -->
		<script src="bootstrap/js/jquery.js"></script>
		<script src="bootstrap/js/bootstrap.min.js"></script>
    </div><!--/.fluid-container-->

	<script>
	  (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
	  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
	  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
	  })(window,document,'script','//www.google-analytics.com/analytics.js','ga');
	  ga('create', 'UA-50875146-1', 'gridphp.com');
	  ga('send', 'pageview');
	</script>
	<!-- Chatra {literal} -->
	<script>
	    (function(d, w, c) {
	        w.ChatraID = 'de92EMe5e2YEPcdJ3';
	        var s = d.createElement('script');
	        w[c] = w[c] || function() {
	            (w[c].q = w[c].q || []).push(arguments);
	        };
	        s.async = true;
	        s.src = 'https://call.chatra.io/chatra.js';
	        if (d.head) d.head.appendChild(s);
	    })(document, window, 'Chatra');
	</script>
	<!-- /Chatra {/literal} -->

  </body>
</html>