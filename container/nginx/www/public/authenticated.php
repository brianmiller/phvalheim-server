<?php

require_once '../vendor/autoload.php';
include '/opt/stateful/config/phvalheim-frontend.conf';
#include '../includes/config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';


function populateTable($pdo,$isAuthenticated,$email,$gameDNS,$phvalheimHost,$payload,$phvalheimClientURL) {
	if ($isAuthenticated) {
		$userid = $payload['sub'];
		$firstName = $payload['given_name'];
	        $lastName = $payload['family_name'];
	        $email = $payload['email'];
	        $picture = $payload['picture'];
	
		$getMyWorlds = getMyWorlds($pdo,$email);
		#$getMyWorlds = array('foo1','foo2','foo3');
		#$getMyWorlds = array('foo1','foo2','foo3','foo4','foo5','foo6','foo7','foo8','foo9','foo10','foo2','foo3','foo4','foo5','foo6','foo7','foo8','foo9','foo10');

		echo "
	                <table width=100% height=100% border=0>
                                <th class='google_header'><img src='$picture'></img></th>
				<th class='client_download_button'>
					<a href='$phvalheimClientURL'><button type='button' class='btn btn-sm btn-outline-download client_download_button_font'><img src='../images/download.svg'></img>&nbsp;Download PhValheim Client</button></a>
				</th>
				<tr>
	                        <th colspan=2 class='name_header'>Welcome, $firstName!</th>


	                        <tr>
				<tr>

	                        <td colspan=2>
        	                        <div class='outer'>
	                                        <div class='inner'>
	                                                <div class='wrapper'>

		";


	        foreach ($getMyWorlds as $myWorld) { //only query and return authorized worlds
			$launchString = getLaunchString($pdo,$myWorld,$gameDNS,$phvalheimHost); 

			echo "
				<div class=\"catbox $myWorld\">
					<table width=100% height=100% border=0>
						<th class='card_worldName' colspan=2>$myWorld</th>
						<tr>
						<th class='card_worldLaunch' colspan=2><a class='card_worldLaunch' href='phvalheim://?$launchString'>Launch!</a></th>

						<tr>

						<td style='height: 12px;'</td>			

						<tr>
						<td class='card_worldInfo'>Citizens&nbsp;&nbsp;:</td>
						<td class='card_worldInfo'>pickles</td>
						<tr>
                                                <td class='card_worldInfo'>Mods&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                <td class='card_worldInfo'>view</td>
						<tr>
						<td class='card_worldInfo'>MD5 Sum&nbsp;&nbsp;&nbsp;:</td>
						<td class='card_worldInfo'>d7ece378d0a098575af07c9dee8f53d7</td>
						<tr>
                                                <td class='card_worldInfo'>Deployed&nbsp;&nbsp;:</td>
                                                <td class='card_worldInfo'>date</td>
                                                <tr>
					</table>
				</div>

			";
	        }
			echo "
                                                </div>
                                        </div>
                                </div>
                        </td>
                </table>

			";


	} else {
		echo "Not authenticated";
	}
}


if (!empty($_POST['google_id_token'])) { //if a google auth jwt token is passed
        $id_token = $_POST['google_id_token'];

	$client = new Google_Client(['client_id' => $googleClientId]);  // Specify the CLIENT_ID of the app that accesses the backend
	$payload = $client->verifyIdToken($id_token);
	if ($payload) { //authenticated if true
		$isAuthenticated = true;
	} else { //invalid token, not authenticated
		$isAuthenticated = false;
	  print "You have attempt to login with an invalid Google auth token or ID.<br>"; //invalid token
	  print "You are being redirected to the login page in 3 seconds...<br>";
	  header('Refresh: 3; url=google.php?isAutoLoginDisabled=true');
	}
} else {
	header('Location: ../index.php');
}

?>

<!DOCTYPE html>
<html>
	<head>
		<link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css">
	</head>
	<body>
		<?php populateTable($pdo,$isAuthenticated,$email,$gameDNS,$phvalheimHost,$payload,$phvalheimClientURL) ?>
	</body>
</html>

















