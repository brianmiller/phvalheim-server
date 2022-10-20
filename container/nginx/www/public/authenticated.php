<?php

require_once '../vendor/autoload.php';
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';

# simple security: if this page is accessed from a source other than steam, redirect back to login page
if ($_SERVER['HTTP_REFERER'] != "https://steamcommunity.com/")
{
	header('Location: ../index.php');
}


function populateTable($pdo,$steamID,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey) {

		# steam
	        if( isset( $_GET[ 'openid_claimed_id' ] ) )
	        {
	                $steamIDArr = explode('/', $_GET[ 'openid_claimed_id' ]);
	                $steamID = end($steamIDArr);
			$steamJSON = file_get_contents("https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=$steamAPIKey&steamids=$steamID");
	                $steamJSONObj = json_decode($steamJSON);
	                $steamJSONObj = $steamJSONObj->response->players;
	                $steamJSONObj = $steamJSONObj[0];
	
	                $steamNickName = $steamJSONObj->personaname;
	                $steamFullName = $steamJSONObj->realname;
	                $steamAvatarURL = $steamJSONObj->avatarmedium;


			# if steam profile is set to private, the fullname isn't visible, use the nickname instead 
			if(!empty($steamFullName)) {
				$playerName = explode(' ',$steamFullName)[0];
			} else {
				$playerName = $steamNickName;
			}

	        } else {
		        header('Location: ../index.php');
		}





                echo "
                        <table width=100% height=100% border=0>
                                <th class='google_header'><img src='$steamAvatarURL'></img></th>
                                <th class='client_download_button'>
                                        <a href='$phvalheimClientURL'><button type='button' class='btn btn-sm btn-outline-download client_download_button_font'><img src='../images/download.svg'></img>&nbsp;Download PhValheim Client</button></a>
                                </th>
                                <tr>
                                <th colspan=2 class='name_header'>Welcome, $playerName!</th>


                                <tr>
                                <tr>

                                <td colspan=2>
                                        <div class='outer'>
                                                <div class='inner'>
                                                        <div class='wrapper'>

                ";

		$getMyWorlds = getMyWorlds($pdo,$steamID);

                if(!empty($getMyWorlds)) {
                        foreach ($getMyWorlds as $myWorld) { //only query and return authorized worlds
                                $launchString = getLaunchString($pdo,$myWorld,$gameDNS,$phvalheimHost); 
				$md5 = getMD5($pdo,$myWorld);
				$dateDeployed = getDateDeployed($pdo,$myWorld);
				$dateUpdated = getDateUpdated($pdo,$myWorld);
				$worldMemory = getWorldMemory($pdo,$myWorld);

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
                                                        <td class='card_worldInfo'>WIP</td>
                                                        <tr>
                                                        <td class='card_worldInfo'>Mods&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='card_worldInfo'>WIP</td>
                                                        <tr>
                                                        <td class='card_worldInfo'>MD5 Sum&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='card_worldInfo'>$md5</td>
                                                        <tr>
                                                        <td class='card_worldInfo'>Deployed&nbsp;&nbsp;:</td>
                                                        <td class='card_worldInfo'>$dateDeployed</td>
                                                        <tr>
                                                        <td class='card_worldInfo'>Updated&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='card_worldInfo'>$dateUpdated</td>
                                                        <tr>
                                                        <td class='card_worldInfo'>Memory&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='card_worldInfo'>$worldMemory</td>
                                                        <tr>
                                                </table>
                                        </div>
                                ";
                        }//end foreach loop through worlds

                } else {
                        echo "<div>You don't have any worlds in your library.</div>";

                }//end if worlds are empty

                        echo "
                                                </div>
                                        </div>
                                </div>
                        </td>
                </table>

                        ";
}

?>

<!DOCTYPE html>
<html>
        <head>
                <link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css">
        </head>
        <body>
                <?php populateTable($pdo,$steamID,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey) ?>
        </body>
</html>
