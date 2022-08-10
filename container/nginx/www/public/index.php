<?php
include '/opt/stateful/config/phvalheim-frontend.conf';
#include '../includes/config.php';

#from publicAuthenticated.php
if (!empty($_GET['isAutoLoginDisabled'])) {
	$isAutoLoginDisabled = $_GET['isAutoLoginDisabled'];
}

#auto login toggle
function autoLogin($mode) {
	echo "<script>window.autoLogin = " . $mode . ";</script>";
}

#if publicAuthenticated.php cannot authorize, disable auto login to prevent infinite loop/login attempts
if($isAutoLoginDisabled == "true") {
	$googleAutoLogin = "false";
}

?>

<!DOCTYPE html>
<html>
	<head>
		<!-- Google Identity -->
		<script src="https://accounts.google.com/gsi/client" async defer></script>

		<?php autoLogin($googleAutoLogin); ?>
	
		<?php echo "<script>window.clientId = '" . $googleClientId . "';</script>" ?>

		<!-- Google Identity for PhValheim -->
		<script src="/js/googleAuth.js"></script>
	
	        <!-- Google Fonts -->
		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">

		<!-- CSS Reset -->
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css">
		
		<!-- Milligram CSS -->
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/milligram/1.4.1/milligram.css">

		<link rel="stylesheet" href="/css/google.css">

	</head>
	<body>
		<div class="centered padding-top">
			<div id="no-auth" class="container">
				<h1 id="welcome">Hello there!</h1>
	        		<h3>This is a basic example on how to enable Google One Tap Authentication in a web page</h3>
	        		<p>To proceed with testing the authentication flow, <b>follow the prompt shown in the top right corner of the page.</b></p>
			        <p>The Google One Tap flow is configured to automatically show the prompt in the right top corner, auto-select the current Google Account if you are already logged in with Google and proceed with the authentication flow automatically if you have done it before and your Google account allows it.</p>
	        			<div id="alternative-login">
				        	<p><b>If no prompt appears just click the button bellow to start the authentication flow:</b></p>
	        					<div id="buttonDiv"></div>
	        			</div>
	        			<div id="authenticated" style="display:none">
					          <h3>Looks like you have already authenticated yourself!</h3>
					          <p><b>Here is the info I recovered about your profile in your Google account:</b></p>
					          <table id="token-table">
					          	<thead>
	             						<tr>
	                						<th>Key</th>
	                						<th>Value</th>
	              						</tr>
						        </thead>
	            					<tbody>
							</tbody>
						</table>

					<form id="authenticated_form" method="post" action="authenticated.php">
						<input type="hidden" name="google_id_token" id="google_id_token" value=""></input>
					</form>

					<p id="bar" value=""></p>
	        			</div>
		     </div>
		</div>
	</body>
</html>
