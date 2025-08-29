<?php
/**
 * Grid 4 PHP Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - https://www.gridphp.com
 * @version 3.0.0
 * @license: see license.txt included in package
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set("display_errors","off");

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
		@unlink("install.php");
		@unlink("database.sql");
		@unlink("config.sample.php");
		header("location: ./index.php");
		die;
	}
}	

function do_install()
{
	global $valid;
	
	extract($_POST);
		
	mysqli_report(MYSQLI_REPORT_OFF);
	$link = @mysqli_connect($dbhost, $dbuser, $dbpass);
		
	if (!$link) {
		$valid['connection'] = false;
		$valid['connection_msg'] = 'Database not connected, Kindly check database configuration.';
	}
	
	if (!$valid['connection'])
		return;
	
	if ($valid['connection'] == true)
	{
		$templine = '';
		
		// Read in entire file
		$lines = file("database.sql");

		// // append create db calls
		// if ($_POST["createdb"] == 1)
		// {
		// 	// Loop through each line
		// 	foreach ($lines as &$line)
		// 	{
		// 		// ignore internal create db if used from installer
		// 		if ((strstr($line,"CREATE DATABASE") !== false || strstr($line,"USE") !== false))
		// 			$line = "";
		// 	}
			
		// 	array_unshift($lines, "CREATE DATABASE `$dbname`;", "USE `$dbname`;");
		// }
		// // append on USE db call
		// else
		// {
		// 	// Loop through each line
		// 	foreach ($lines as &$line)
		// 	{
		// 		// ignore internal create db if used from installer
		// 		if ((strstr($line,"CREATE DATABASE") !== false || strstr($line,"USE") !== false))
		// 			$line = "";
		// 	}
			
		// 	array_unshift($lines, "USE `$dbname`;");		
		// }
		
		// // was reference to last index of $lines
		// unset($line);
		
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
						$valid['dbready_msg'] .= 'Error performing query \'' . $templine . '\': <strong>' . mysqli_error($link) .'</strong>' ;
						$valid['dbready_err'] .= mysqli_error($link);
						break;
					}
				}
				// Reset temp variable to empty
				$templine = '';
			}
		}
	}

	if (strstr($valid["dbready_err"],"Access denied for user") !== false)
		$valid["dbready_msg"] = "Provided user is able to connect but it does not have permission to create database.";

	if (!$valid['dbready'])
		return;	
	
	// create or override config file
	$scriptName = $_SERVER['SCRIPT_NAME'];
	$webRoot = substr($scriptName, 0, strlen($scriptName) - strlen('/install.php'));

	$configContents = file_get_contents("config.sample.php");
	$configContents = str_replace("{{dbhost}}", $dbhost, $configContents);
	$configContents = str_replace("{{dbuser}}", $dbuser, $configContents);
	$configContents = str_replace("{{dbpass}}", $dbpass, $configContents);

	// set temporary api key if not set
	if (empty($apikey))	$apikey = "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";
	
	$configContents = str_replace("{{apikey}}", $apikey, $configContents);

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
	<title>Donation Management Installer | Grid 4 PHP Framework</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="description" content="">
	<meta name="author" content="">

	<!-- Le styles -->
	<link href="https://ajax.aspnetcdn.com/ajax/bootstrap/2.3.1/css/bootstrap.min.css" rel="stylesheet">
	<link href="theme/dist/css/bootstrap2.min.css" rel="stylesheet">
	<style type="text/css">
	body {
		padding-top: 60px;
		padding-bottom: 0;
	}

	.sidebar-nav {
		padding: 9px 0;
	}

	.help {
		color: gray;
		padding: 10px;
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
				<a class="brand" href="#">Grid 4 PHP Framework</a>
				<div class="nav-collapse collapse">
					<p class="navbar-text pull-right">
						<a href="https://www.gridphp.com/" class="navbar-link">www.gridphp.com</a>
					</p>
					<ul class="nav">
						<li><a target="_blank" href="https://www.gridphp.com/">Home</a></li>
						<li><a target="_blank" href="https://www.gridphp.com/docs/">Docs & FAQs</a></li>
						<li><a target="_blank" href="https://www.gridphp.com/support/">Support Forum</a></li>
						<li><a target="_blank" href="https://www.gridphp.com/contact/">Contact Us</a></li>
					</ul>
				</div>
				<!--/.nav-collapse -->
			</div>
		</div>
	</div>

	<div class="container-fluid">
		<div class="row-fluid">
			<div class="span12">

				<!-- Form Name -->
				<legend>Donation Management — Application Template Installation</legend>

				<div class="alert alert-info">
					Please enter your MySQL Connection details to setup database.
				</div>

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
						<strong>Writing to config file:</strong>
						<p>The configuration file is not writable.
						<p>Please copy config.sample.php to config.php and update the database configuration OR Try <a target="_blank"
								href="https://www.gridphp.com/docs/setup/#installing-demos-manually">Manual Setup</a></p>
					</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ($valid['confwritable']): ?>
				<div class="alert alert-success">
					<strong>Checking if config writable:</strong> Your config file is writable.
				</div>
				<?php else: ?>
				<div class="alert alert-error">
					<strong>Checking if config writable:</strong>
					<p>The configuration file is not writable.
					<p>Please copy config.sample.php to config.php and update the database configuration OR Try <a target="_blank"
							href="https://www.gridphp.com/docs/setup/#installing-demos-manually">Manual Setup</a></p>
				</div>
				<?php endif; ?>

				<?php if ($valid['dbready'] === false): ?>
				<div class="alert alert-error">
					<strong>Error:</strong> <?php echo $valid['dbready_msg']; ?>
				</div>
				<?php endif; ?>

				<?php
				// if parent config exist, load values in fields
				if (file_exists("../../config.php") && empty($_POST))
				{
					include_once("../../config.php");
					$_POST["dbhost"] = PHPGRID_DBHOST;	
					$_POST["dbuser"] = PHPGRID_DBUSER;	
					$_POST["dbpass"] = PHPGRID_DBPASS;	
					$_POST["apikey"] = defined("PHPGRID_AI_KEY") ? PHPGRID_AI_KEY : "";	
				}				
				?>

				<form class="form-horizontal" method="post">
					<fieldset>

						<!-- Text input-->
						<div class="control-group">
							<label class="control-label" for="db">Database Host</label>
							<div class="controls">
								<input id="dbhost" name="dbhost" type="text" placeholder="localhost"
									class="input-xlarge" required=""
									value="<?php echo isset($_POST['dbhost']) ? $_POST['dbhost'] : "localhost" ?>">
								<span class="help">You should be able to get this info from your webhost, if localhost
									doesn't work.</span>
							</div>
						</div>

						<!-- Text input-->
						<div class="control-group">
							<label class="control-label" for="dbuser">Username</label>
							<div class="controls">
								<input id="dbuser" name="dbuser" type="text" placeholder="" class="input-xlarge"
									required=""
									value="<?php echo isset($_POST['dbuser']) ? $_POST['dbuser'] : "root" ?>">
								<span class="help">Your database username, make sure this user has permissions to create
									database</span>
							</div>
						</div>

						<!-- Password input-->
						<div class="control-group">
							<label class="control-label" for="dbpass">Password</label>
							<div class="controls">
								<input id="dbpass" name="dbpass" type="password" placeholder="" class="input-xlarge"
									value="<?php echo isset($_POST['dbpass']) ? $_POST['dbpass'] : "" ?>">
								<span class="help">Your database password.</span>
							</div>
						</div>

						<!-- Apikey input-->
						<div class="control-group">
							<label class="control-label" for="apikey">AI API Key</label>
							<div class="controls">
								<input id="apikey" name="apikey" type="text" placeholder="" class="input-xlarge"
									value="<?php echo isset($_POST['apikey']) ? $_POST['apikey'] : "" ?>">
								<span class="help">You can obtain a Free AI API key from <a href='https://console.groq.com/' target='_blank'>Groq Cloud Platform</a>. — (Optional)</span>
							</div>
						</div>

						<!-- Button -->
						<div class="control-group">
							<label class="control-label" for=""> </label>
							<div class="controls">
								<button id="" name="" class="btn btn-primary">Install</button>
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
							<p class="text-info">For technical support query, ask at our <a
									href="https://www.gridphp.com/support/">Support Center</a> </p>
							<p>&copy; <a href="//www.gridphp.com/">www.gridphp.com</a> 2010-<?php echo date("Y");?></p>
						</div>
						<!--/span-->
					</div>
					<!--/row-->
				</div>
				<!--/span-->
			</div>
			<!--/row-->

		</div>
		<!--/row-->

	</div>
	<!--/.fluid-container-->

	<!-- Matomo -->
	<script type="text/javascript">
	var _paq = window._paq || [];
	/* tracker methods like "setCustomDimension" should be called before "trackPageView" */
	_paq.push(['trackPageView']);
	_paq.push(['enableLinkTracking']);
	(function() {
		var u = "//config.gridphp.com/piwik/";
		_paq.push(['setTrackerUrl', u + 'matomo.php']);
		_paq.push(['setSiteId', '3']);
		var d = document,
			g = d.createElement('script'),
			s = d.getElementsByTagName('script')[0];
		g.type = 'text/javascript';
		g.async = true;
		g.defer = true;
		g.src = u + 'matomo.js';
		s.parentNode.insertBefore(g, s);
	})();
	</script>
	<!-- End Matomo Code -->

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

	<script type='text/javascript'>
	window.smartlook || (function(d) {
		var o = smartlook = function() {
				o.api.push(arguments)
			},
			h = d.getElementsByTagName('head')[0];
		var c = d.createElement('script');
		o.api = new Array();
		c.async = true;
		c.type = 'text/javascript';
		c.charset = 'utf-8';
		c.src = 'https://web-sdk.smartlook.com/recorder.js';
		h.appendChild(c);
	})(document);
	smartlook('init', '48d2662ab0dc9862570160eb655eca65d7ba8459', {
		region: 'eu'
	});
	</script>

</body>

</html>