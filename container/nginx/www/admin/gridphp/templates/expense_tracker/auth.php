<?php
###############################################################
# Simple Auth 2.13
# Website: https://www.gridphp.com/
# Inspired from: http://www.zubrag.com/scripts/
###############################################################
#
# Usage:
# Set usernames / passwords below between SETTINGS START and SETTINGS END.
# Open it in browser with 'help' parameter to get the code
# to add to all files being protected.
#		Example: auth.php?help
# Include protection string which it gave you into every file that needs to be protected
#
# Add following HTML code to your page where you want to have logout link
# <a href='http://www.example.com/path/to/protected/page.php?logout=1'>Logout</a>
#
###############################################################

/*
-------------------------------------------------------------------
SAMPLE if you only want to request login and password on login form.
Each row represents different user.

$LOGIN_INFORMATION=array(
	'test' => 'testpass',
	'admin' => 'passwd'
);
*/

#	SETTINGS START

// Use any one of them
define('USE_DB_AUTH', true);
define('USE_HTTP_AUTH', false);

// Add login/password pairs below, for HTTP_AUTH
$LOGIN_INFORMATION=array(
	'admin' => 'admin'
);

// request login? true - show login and password boxes, false - password box only
define('USE_USERNAME', true);

// User will be redirected to this page after logout
define('LOGOUT_URL', 'index.php');

// time out after NN minutes of inactivity. Set to 0 to not timeout
define('TIMEOUT_MINUTES', 0);

// This parameter is only useful when TIMEOUT_MINUTES is not zero
// true - timeout time from last activity, false - timeout time from login
define('TIMEOUT_CHECK_ACTIVITY', true);

////////////////////////////
// do not change code below
////////////////////////////

// timeout in seconds
$timeout=( TIMEOUT_MINUTES == 0 ? 0 : time() + TIMEOUT_MINUTES * 60);

if ( USE_DB_AUTH )
	session_start();

// logout?
if ( isset( $_GET['logout'] ) ) 
{
	if ( USE_DB_AUTH )
	{
		unset($_SESSION['loggedin']);
	}
	else if ( USE_HTTP_AUTH )
		setcookie('verify', '', $timeout, '/');
	
	header('Location: ' . LOGOUT_URL);
	exit();
}

// if authentication is enabled in app settings
if (get_option("auth_enabled") !== "yes")
	return;

if ( USE_DB_AUTH )
{
	if ( isset( $_POST['access_password'] ) ) 
	{
		$login=isset( $_POST['access_login'] ) ? $_POST['access_login'] : '';
		$pass=$_POST['access_password'];

		$user = authenticate_db($login,$pass);
		$is_auth = !empty($user);
		
		if ( !$is_auth ) 
		{
			show_login_screen('Incorrect username or password.');
		} 
		else
		{
			$_SESSION['loggedin'] = true;
			$_SESSION["userid"] = $user["id"];
			$_SESSION["name"] = $user["name"];
			$_SESSION["email"] = $user["email"];
			$_SESSION["role"] = $user["role"];
		}
	}
	else
	{
		// check if session valid
		if (!isset($_SESSION['loggedin']))
			show_login_screen('');
	}
}
else if ( USE_HTTP_AUTH )
{
	if ( isset( $_POST['access_password'] ) ) 
	{
		$login=isset( $_POST['access_login'] ) ? $_POST['access_login'] : '';
		$pass=$_POST['access_password'];
	
		$is_auth=(!USE_USERNAME && !in_array( $pass, $LOGIN_INFORMATION ) || ( USE_USERNAME && ( !array_key_exists( $login, $LOGIN_INFORMATION ) || $LOGIN_INFORMATION[ $login ] != $pass ) ));
	
		if ( !$is_auth ) 
		{
			show_login_screen('Incorrect username or password.');
		} 
		else 
		{
			// set cookie if password was validated
			setcookie('verify', md5( $login.'%'.$pass ), $timeout, '/');
	
			// Some programs ( like Form1 Bilder ) check $_POST array to see if parameters passed
			// So need to clear password protector variables
			unset( $_POST['access_login']);
			unset( $_POST['access_password']);
			unset( $_POST['Submit']);
		}
	
	}
	else 
	{
		$found=false;
	
		// check if password cookie is set
		if ( !isset( $_COOKIE['verify'] ) ) {
			show_login_screen('');
		}
	
		// check if cookie is good
		foreach ( $LOGIN_INFORMATION as $key=>$val ) {
			$lp=( USE_USERNAME ? $key : '' ) .'%'.$val;
			if ( $_COOKIE['verify'] == md5( $lp ) ) {
				$found=true;
				// prolong timeout
				if ( TIMEOUT_CHECK_ACTIVITY ) {
					setcookie('verify', md5( $lp ), $timeout, '/');
				}
				break;
			}
		}
	
		if ( !$found ) {
			show_login_screen('');
		}
	}
}

function authenticate_db( $login, $pass )
{
	include_once('../config.php');
	include_once( PHPGRID_LIBPATH.'inc/jqgrid_dist.php');
	$obj=new jqgrid();

	$rs=$obj->get_one('SELECT * FROM tb_users WHERE email=? AND status=?', array( $login,'active' ));
	if (!empty($rs) && password_verify($pass,$rs["password"]))
		return $rs;
	else
		return null;
}

function show_login_screen( $error_msg ) {
	renderLoginForm( $error_msg);
	die();
}

function renderLoginForm($error_msg)
{
	// if ajax call showing login box, reload page
	if (isset($_REQUEST["nd"])) {echo "Logging Out ...<script>location.reload();</script>";die;}
	?>
	<!DOCTYPE html>
	<html>
	<head>
	<META HTTP-EQUIV='CACHE-CONTROL' CONTENT='NO-CACHE'>
	<META HTTP-EQUIV='PRAGMA' CONTENT='NO-CACHE'>
	<link href='//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css' rel='stylesheet' id='bootstrap-css'>
	<script src='//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/js/bootstrap.min.js'></script>
	<script src='//cdnjs.cloudflare.com/ajax/libs/jquery/3.2.1/jquery.min.js'></script>
	<!------ Include the above in your HEAD tag ---------->
	<style>
	@import url('https://fonts.googleapis.com/css?family=Numans');

	html, body {
		/* background-image: url('http://getwallpapers.com/wallpaper/full/6/8/0/104245.jpg');
		*/
		/* background-image: url('http://getwallpapers.com/wallpaper/full/c/f/0/391951.jpg');
		*/
		/* background-image: url('http://getwallpapers.com/wallpaper/full/a/5/d/544750.jpg');
		*/
		background-image: url('http://getwallpapers.com/wallpaper/full/a/8/b/892247-vertical-jakarta-wallpapers-2560x1440-laptop.jpg');
		background-size: cover;
		background-repeat: no-repeat;
		height: 100%;
		font-family: 'Numans', sans-serif;
	}

	.container {
		height: 100%;
		align-content: center;
	}

	.card {
		min-height: 270px;
		margin-top: auto;
		margin-bottom: auto;
		width: 400px;
		background-color: rgba( 0, 0, 0, 0.5 ) !important;
	}

	.social_icon span {
		font-size: 60px;
		margin-left: 10px;
		color: #FFC312;
	}

	.social_icon span:hover {
		color: white;
		cursor: pointer;
	}

	.card-header h3 {
		color: white;
	}

	.social_icon {
		position: absolute;
		right: 20px;
		top: -45px;
	}

	.input-group-prepend span {
		width: 50px;
		background-color: #FFC312;
		color: black;
		border:0 !important;
	}

	input:focus {
		outline: 0 0 0 0	!important;
		box-shadow: 0 0 0 0 !important;

	}

	.remember {
		color: white;
	}

	.remember input
	{
		width: 20px;
		height: 20px;
		margin-left: 15px;
		margin-right: 5px;
	}

	.login_btn {
		color: black;
		background-color: #FFC312;
		width: 100px;
	}

	.login_btn:hover {
		color: black;
		background-color: white;
	}

	.links {
		color: white;
	}

	.links a {
		margin-left: 4px;
	}
</style>
<title>Sign In - <?php echo APP_NAME ?></title>
<!--Bootsrap 4 CDN-->
<link rel='stylesheet' href='https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css' integrity='sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO' crossorigin='anonymous'>
<link rel='stylesheet' href='https://use.fontawesome.com/releases/v5.3.1/css/all.css' integrity='sha384-mzrmE5qonljUremFsqc01SB46JvROS7bZs3IO2EmfFsd15uHvIt+Y8vEf7N7fWAU' crossorigin='anonymous'>
</head>
<body>
	<div class='container'>
		<div class='d-flex justify-content-center h-100'>
			<div class='card'>
				<div class='card-header'>
					<h3><?php echo APP_NAME ?></h3>
				</div>
				<div class='card-body'>
					<?php if ( isset( $error_msg ) && !empty( $error_msg ) ) {
						?>
					<div class='alert alert-warning' role='alert'><?php echo $error_msg ?></div>
					<?php }
						?>
					<form method='post'>
						<?php if ( USE_USERNAME ) {
							?>
						<div class='input-group form-group'>
							<div class='input-group-prepend'>
								<span class='input-group-text'><i class='fas fa-user'></i></span>
							</div>
							<input name='access_login' type='text' class='form-control' required='required' placeholder='Username' value='<?php echo (isset($_POST['access_login']) ? $_POST['access_login'] : ''); ?>'>
						</div>
						<?php }
							?>
						<div class='input-group form-group'>
							<div class='input-group-prepend'>
								<span class='input-group-text'><i class='fas fa-key'></i></span>
							</div>
							<input name='access_password' type='password' class='form-control' required='required' placeholder='Password'>
						</div>
						<!-- <div class='row align-items-center remember'>
							<input type='checkbox'>Remember Me
							</div> -->
						<div class='form-group'>
							<input name='Submit' type='submit' value='Login' class='btn float-right login_btn'>
						</div>
					</form>
				</div>
				<div class='card-footer'>
					<!-- <div class='d-flex justify-content-center links'>
					Don't have an account?<a href='#'>Sign Up</a>
					</div>
					<div class='d-flex justify-content-center'>
						<a href='#'>Forgot your password?</a>
					</div> -->
					<div class='d-flex justify-content-center links'>
					Made with <a style="color:white;decoration:underline;" target="_blank" href='https://www.gridphp.com'>Grid 4 PHP</a>
					</div>
					</div>
			</div>
		</div>
	</div>
</body>
</html>
<?php
}