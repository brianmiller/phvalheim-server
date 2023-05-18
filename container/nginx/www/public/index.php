<?php

	# Steam Auth stuff
        declare(strict_types=1);
        require '/opt/stateless/nginx/www/vendor/autoload.php';
        #use xPaw\Steam\SteamOpenID;

	include '/opt/stateless/nginx/www/includes/config_env_puller.php';
	include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

	# are we using TLS?
	if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
	        $steamRealm = "https://" . $_SERVER['HTTP_HOST'];
	} else {
	        $steamRealm = "http://" . $_SERVER['HTTP_HOST'];
	}



#foreach($_SERVER as $key_name => $key_value) {
#       print $key_name . " = " . $key_value . "<br>";
#}


?>


<!DOCTYPE html>
<html lang="en" >
<head>
  <meta charset="UTF-8">
  <title>PhValheim Login</title>
  <!--<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/5.0.0/normalize.min.css">-->
  <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css?refreshcss=<?php echo rand(100, 1000)?>'>
  <link rel="stylesheet" href="/css/login.css?refreshcss=<?php echo rand(100, 1000)?>">
  <link rel="stylesheet" href="/css/phvalheimStyles.css?refreshcss=<?php echo rand(100, 1000)?>">

                <!-- Google Fonts -->
                <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic?refreshcss=<?php echo rand(100, 1000)?>">

                <!-- CSS Reset -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css?refreshcss=<?php echo rand(100, 1000)?>">

</head>
<body>
<!-- partial:index.partial.html -->

<div class="login_header">Welcome to PhValheim</div>

<div class="user-modal">
		<div class="user-modal-container">
			<ul class="switcher">
				<li><a href="#0">Sign in</a></li>
				<li><a href="#0">About</a></li>
			</ul>

			<div id="login">
			
				<p class="center pri-color" style="width:auto !important;">Welcome to PhValheim's World Dashboard</p>
				
				<form class="form" id="authenticated_form" method="post" action="https://steamcommunity.com/openid/login">
					<p class="center alt-color" style="width:auto !important;">Click below to sign in with Steam</p>
					<div class="centered" id="steamSignInButton">
						<input type="hidden" name="openid.identity" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.claimed_id" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.ns" value="http://specs.openid.net/auth/2.0">
						<input type="hidden" name="openid.mode" value="checkid_setup">
						<input type="hidden" name="openid.realm" value="<?php echo $steamRealm?>">
						<input type="hidden" name="openid.return_to" value="<?php echo $steamRealm?>/authenticated.php">
						<!-- <input type="image" name="submit" src="https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_01.png" border="0" alt="Submit"> -->
						<input type="image" name="submit" src="https://steamcdn-a.akamaihd.net/steamcommunity/public/images/steamworks_docs/english/sits_large_border.png" border="0" alt="Submit">

					</div>
				</form>
				
				<!--<p class="form-bottom-message"><a href="#0">Forgot your password?</a></p>-->
			</div>

			<div id="signup">
				<form class="form">

					<p>
						<label class="full-width alt-color">What is PhValheim?</label>
					</p>
			
                                        <p>
                                                <label class="full-width">PhValheim is a server+client combination that keeps remote Valheim worlds in-sync with remote clients, ensuring all players have a controlled and identical experience with any combination of mods.</label>
                                        </p>

                                        <p>
                                                <label class="full-width">For more information, visit PhValheim's <a target="_blank" href="https://github.com/brianmiller/phvalheim-server">GitHub page</a> and <a target="_blank" href="https://discord.gg/8RMMrJVQgy">Discord community</a>.</label>
                                        </p>

				</form>

			</div>

			<div id="reset-password">
				<p class="form-message">Lost your password? Please enter your email address.</br> You will receive a link to create a new password.</p>

				<form class="form">
					<p class="fieldset">
						<label class="image-replace email" for="reset-email">E-mail</label>
						<input class="full-width has-padding has-border" id="reset-email" type="email" placeholder="E-mail">
						<span class="error-message">An account with this email does not exist!</span>
					</p>

					<p class="fieldset">
						<input class="full-width has-padding" type="submit" value="Reset password">
					</p>
				</form>

				<p class="form-bottom-message"><a href="#0">Back to log-in</a></p>
			</div>
		</div>
	</div>
<!-- partial -->
	<script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js'></script><script  src="/js/login.js"></script> 

</body>
</html>

