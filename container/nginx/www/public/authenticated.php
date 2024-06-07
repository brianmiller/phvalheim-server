<?php

require_once '../vendor/autoload.php';
include '../includes/config_env_puller.php';
include '../includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';
include '../includes/userAgent.php';
include '../includes/clientDownloadButton.php';
include '../includes/modViewerGenerator.php';

# simple security: if this page is accessed from a source other than steam, redirect back to login page
# NOTE: this security check only works when HTTPS is used!
if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
	if ($_SERVER['HTTP_REFERER'] != "https://steamcommunity.com/")
	{
		header('Location: ../index.php');
	}	
}


if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
	$httpScheme = "https";
} else {
	$httpScheme = "http";
}


function populateTable($pdo,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey,$backupsToKeep,$defaultSeed,$basePort,$httpScheme,$operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {

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
                                <th class='client_download_button'>";
				populateDownloadMenu($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender);
		echo "
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
                                $launchString = getLaunchString($pdo,$myWorld,$gameDNS,$phvalheimHost,$httpScheme); 
				$md5 = getMD5($pdo,$myWorld);
				$seed = getSeed($pdo,$myWorld);
				$hideSeed = getHideSeed($pdo,$myWorld);
				$dateDeployed = getDateDeployed($pdo,$myWorld);
				$dateUpdated = getDateUpdated($pdo,$myWorld);
				$worldMemory = getWorldMemory($pdo,$myWorld);

				$trophyEikthyr = getBossTrophyStatus($pdo,$myWorld,"trophyeikthyr");
                                $trophyTheElder = getBossTrophyStatus($pdo,$myWorld,"trophytheelder");
                                $trophyBonemass = getBossTrophyStatus($pdo,$myWorld,"trophybonemass");
                                $trophyDragonQueen = getBossTrophyStatus($pdo,$myWorld,"trophydragonqueen");
                                $trophyGoblinKing = getBossTrophyStatus($pdo,$myWorld,"trophygoblinking");
				$trophySeekerQueen = getBossTrophyStatus($pdo,$myWorld,"trophyseekerqueen");
				$trophyFader = getBossTrophyStatus($pdo,$myWorld,"trophyfader");

				if($worldMemory == "offline") {
					$worldDimmed = "card_dimmed";
					$launchLabel = "offline";
					$modListToolTip = "offline";
				} else {
	                                # running mods public viewer
        	                        $runningMods_head = "\n<table border=\"0\" style=\"line-height:auto;\">\n";
	                                $runningMods_foot = "</table>\n";
	                                $runningMods = $runningMods_head . generateToolTip($pdo,$myWorld) . $runningMods_foot;
	                                $modListToolTip = "<a href='#' class='' style='box-shadow:none;border:none;outline:none;border-spacing: 0 2em;' data-trigger='focus' data-toggle='popover' data-placement='bottom' title='Running Mods' data-html='true' data-content='$runningMods'</a>(<label class='alt-color'>view</label>)</a>";

					$worldDimmed = "";
					$launchLabel = "Launch!";
				}
			
				if($trophyEikthyr && $worldDimmed == "") {
						$trophyEikthyrDimmed = "";
						$trophyEikthyrStatus = "Eikthyr has been defeated";
				} else {
						$trophyEikthyrDimmed = "trophy_dimmed";
						$trophyEikthyrStatus = "Eikthyr is undefeated";
				}
                                if($trophyTheElder && $worldDimmed == "") {
                                                $trophyTheElderDimmed = "";
						$trophyTheElderStatus = "The Elder has been defeated";
                                } else {
                                                $trophyTheElderDimmed = "trophy_dimmed";
						$trophyTheElderStatus = "The Elder is undefeated";
                                }
                                if($trophyBonemass && $worldDimmed == "") {
                                                $trophyBonemassDimmed = "";
						$trophyBonemassStatus = "Bonemass has been defeated";
                                } else {
                                                $trophyBonemassDimmed = "trophy_dimmed";
						$trophyBonemassStatus = "Bonemass is undefeated";
                                }
                                if($trophyDragonQueen && $worldDimmed == "") {
                                                $trophyDragonQueenDimmed = "";
                                                $trophyDragonQueenStatus = "Moder has been defeated";
                                } else {
                                                $trophyDragonQueenDimmed = "trophy_dimmed";
						$trophyDragonQueenStatus = "Moder is undefeated";
                                }
                                if($trophyGoblinKing && $worldDimmed == "") {
                                                $trophyGoblinKingDimmed = "";
						$trophyGoblinKingStatus = "Yagluth has been defeated";
                                } else {
                                                $trophyGoblinKingDimmed = "trophy_dimmed";
						$trophyGoblinKingStatus = "Yagluth is undefeated";
                                }
                                if($trophySeekerQueen && $worldDimmed == "") {
                                                $trophySeekerQueenDimmed = "";
						$trophySeekerQueenStatus = "The Queen has been defeated";
                                } else {
                                                $trophySeekerQueenDimmed = "trophy_dimmed";
						$trophySeekerQueenStatus = "The Queen is undefeated";
						
                                }	
                                if($trophyFader && $worldDimmed == "") {
                                                $trophyFaderDimmed = "";
                                                $trophyFaderStatus = "Fader has been defeated";
                                } else {
                                                $trophyFaderDimmed = "trophy_dimmed";
                                                $trophyFaderStatus = "Fader is undefeated";

                                }


				if ($hideSeed == 1) {
					$seed = '<i>hidden</i>';
				}

				echo "
                                        <div class=\"$worldDimmed catbox $myWorld\">
                                                <table width=100% height=100% border=0>
                                                        <th class='$worldDimmed card_worldName' colspan=2>$myWorld</th>
                                                        <tr>
                                                        <th class='$worldDimmed card_worldLaunch' colspan=2><a class='$worldDimmed card_worldLaunch' href='phvalheim://?$launchString'>$launchLabel</a></th>

                                                        <tr>

                                                        <td style='height: 12px;'</td>

                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Citizens&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>WIP</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Mods&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$modListToolTip</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>MD5 Sum&nbsp;&nbsp;&nbsp;:</td>
							<td class='$worldDimmed card_worldInfo'>$md5</td>
							<tr>
                                                        <td class='$worldDimmed card_worldInfo'>Seed&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
							<td class='$worldDimmed card_worldInfo'>$seed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Deployed&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$dateDeployed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Updated&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$dateUpdated</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Memory&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$worldMemory</td>
                                                        <tr>
                                                </table>
						<table border=0>
							<td class='$trophyEikthyrDimmed trophy_icon'><img title='$trophyEikthyrStatus' src='../images/TrophyEikthyr.png'></img></td>
                                                        <td class='$trophyTheElderDimmed trophy_icon'><img title='$trophyTheElderStatus' src='../images/TrophyTheElder.png'></img></td>
                                                        <td class='$trophyBonemassDimmed trophy_icon'><img title='$trophyBonemassStatus' src='../images/TrophyBonemass.png'></img></td>
                                                        <td class='$trophyDragonQueenDimmed trophy_icon'><img title='$trophyDragonQueenStatus' src='../images/TrophyDragonQueen.png'></img></td>
                                                        <td class='$trophyGoblinKingDimmed trophy_icon'><img title='$trophyGoblinKingStatus' src='../images/TrophyGoblinKing.png'></img></td>
							<td class='$trophySeekerQueenDimmed trophy_icon'><img title='$trophySeekerQueenStatus' src='../images/TrophySeekerQueen.png'></img></td>
							<td class='$trophyFaderDimmed trophy_icon'><img title='$trophyFaderStatus' src='../images/TrophyFader.png'></img></td>
						</table>
                                        </div>
                                ";
                        }//end foreach loop through worlds

                } else {
                        echo "<div>You don't have any worlds in your library.</div>";

                }//end if worlds are empty


		# mandatory vars missing
                if(empty($backupsToKeep)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: Backup retention is not configured properly. Ensure you're passing the \"backupsToKeep\" variable to your Docker run command.</div>
                        ";
                }

                if(empty($playerName)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: The SteamAPI could not be contacted. Ensure you're passing a valid Steam API Key to the \"steamAPIKey\" variable within your Docker run command.</div>
                        ";
                }
		
                if(empty($phvalheimClientURL)) {

	                echo "
				<tr>
				<td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Client Download URL is missing! Ensure you're passing the \"phvalheimClientURL\" variable to your Docker run command.</div>
			";
		}

                if(empty($phvalheimHost)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Host FQDN variable is missing! Ensure you're passing the \"phvalheimHost\" variable to your Docker run command.</div>
                        ";
                }

                if(empty($basePort)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Base Port is not set! Ensure you're passing the \"basePort\" variable to your Docker run command.</div>
                        ";
                }

                if(empty($gameDNS)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim game DNS endpoint is not set! Ensure you're passing the \"gameDNS\" variable to your Docker run command.</div>
                        ";
                }

                if(empty($defaultSeed)) {

                        echo "
                                <tr>
                                <td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Default Seed is not set! Ensure you're passing the \"defaultSeed\" variable to your Docker run command.</div>
                        ";
                }



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
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css?refreshcss=<?php echo rand(100, 1000)?>">
		<link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css?refreshcss=<?php echo rand(100, 1000)?>">
		<script src="../js/jquery-3.6.0.js"></script>
		<script src="../js/bootstrap.min.js"></script>

        </head>
        <body>

        <script>
                $(document).ready(function(){
                  $('[data-toggle="popover"]').popover({
		   sanitize:false,
		  });
                });
        </script>

                <?php populateTable($pdo,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey,$backupsToKeep,$defaultSeed,$basePort,$httpScheme,$operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) ?>
        </body>
</html>
