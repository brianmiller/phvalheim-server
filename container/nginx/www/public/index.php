<?php
	include '/opt/stateful/config/phvalheim-frontend.conf';

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
<html lang="en" >
<head>
  <meta charset="UTF-8">
  <title>PhValheim Login</title>
  <!--<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/5.0.0/normalize.min.css">-->
  <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css'>
  <link rel="stylesheet" href="/css/login.css">
  <link rel="stylesheet" href="/css/phvalheimStyles.css">


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
				<form class="form" id="authenticated_form" method="post" action="authenticated.php">
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

					<div class="centered" id="googleSignInButton"></div>
				 	
                                        <input type="hidden" name="google_id_token" id="google_id_token" value=""></input>
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

