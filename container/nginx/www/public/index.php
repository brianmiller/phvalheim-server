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
  <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css'>
  <link rel="stylesheet" href="/css/login.css">
  <link rel="stylesheet" href="/css/phvalheimStyles.css">

                <!-- Google Fonts -->
                <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">

                <!-- CSS Reset -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css">

</head>
<body>
<!-- partial:index.partial.html -->

<div class="login_header">Welcome to PhValheim</div>

<div class="user-modal">
		<div class="user-modal-container">
			<ul class="switcher">
				<li><a href="#0">Sign in</a></li>
				<li><a href="#0">New account</a></li>
			</ul>

			<div id="login">
				<form class="form" id="authenticated_form" method="post" action="https://steamcommunity.com/openid/login">
					<p class="fieldset">
						<label class="image-replace email" for="signin-email">E-mail</label>
						<input class="full-width has-padding has-border" id="signin-email" type="email" placeholder="E-mail">
						<span class="error-message">An account with this email address does not exist!</span>
					</p>

					<p class="fieldset">
						<label class="image-replace password" for="signin-password">Password</label>
						<input class="full-width has-padding has-border" id="signin-password" type="password" placeholder="Password">
						<a href="#0" class="hide-password">Show</a>
						<span class="error-message">Wrong password! Try again.</span>
					</p>

					<p class="fieldset">
						<!-- <input type="checkbox" id="remember-me" checked>
						     <label for="remember-me">Remember me</label> -->
					</p>

					<p class="fieldset">
						<input class="full-width" type="submit" value="Login (disabled)" disabled>
					</p>

					<p class="centered">
						or	
					</p>

					<div class="centered" id="steamSignInButton">
						<input type="hidden" name="openid.identity" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.claimed_id" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.ns" value="http://specs.openid.net/auth/2.0">
						<input type="hidden" name="openid.mode" value="checkid_setup">
						<input type="hidden" name="openid.realm" value="<?php echo $steamRealm?>">
						<input type="hidden" name="openid.return_to" value="<?php echo $steamRealm?>/authenticated.php">
						<input type="image" name="submit" src="https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_01.png" border="0" alt="Submit">
					</div>
					
				</form>
				
				<!--<p class="form-bottom-message"><a href="#0">Forgot your password?</a></p>-->
			</div>

			<div id="signup">
				<form class="form">

					<p class="fieldset">
						<label class="image-replace email" for="signup-email">E-mail</label>
						<input class="full-width has-padding has-border" id="signup-email" type="email" placeholder="E-mail">
						<span class="error-message">Enter a valid email address!</span>
					</p>

					<p class="fieldset">
						<label class="image-replace password" for="signup-password">Password</label>
						<input class="full-width has-padding has-border" id="signup-password" type="password"  placeholder="Password">
						<a href="#0" class="hide-password">Show</a>
						<span class="error-message">Your password has to be at least 6 characters long!</span>
					</p>

					<p class="fieldset">
						<!--<input type="checkbox" id="accept-terms">
						<label for="accept-terms">I agree to the <a class="accept-terms" href="#0">Terms</a></label>-->
					</p>

					<p class="fieldset">
						<input class="full-width has-padding" type="submit" value="Create account (disabled)" disabled>
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

