<?php

	# Steam Auth stuff
        declare(strict_types=1);
        require '/opt/stateless/nginx/www/vendor/autoload.php';
        #use xPaw\Steam\SteamOpenID;

	include '/opt/stateless/nginx/www/includes/config_env_puller.php';
	include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
	include '/opt/stateless/nginx/www/includes/session_auth.php';

	// If user already has a valid session, skip login
	if (isSessionValid()) {
		header('Location: authenticated.php');
		exit;
	}

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
  <link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
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
	<div class="public-footer">
		<a href="https://github.com/brianmiller/phvalheim-server" target="_blank" rel="noopener" class="social-link" title="View on GitHub">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
		</a>
		<a href="https://discord.gg/8RMMrJVQgy" target="_blank" rel="noopener" class="social-link" title="Join our Discord">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
		</a>
	</div>

	<script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js'></script><script  src="/js/login.js"></script>

</body>
</html>

