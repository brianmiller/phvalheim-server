<?php
// Prevent browser caching - world status changes dynamically
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

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

// Extract steamID at global scope for AJAX polling
$steamID = null;
if (isset($_GET['openid_claimed_id'])) {
	$steamIDArr = explode('/', $_GET['openid_claimed_id']);
	$steamID = end($steamIDArr);
}

// Helper function to check if world process is running for real-time detection
function isWorldRunning($worldName) {
	// Use pgrep to check if valheim_server process is running for this world
	// Match on "-name worldname " to avoid substring matches (foo matching foo3)
	// Use [n] character class to prevent pgrep from matching its own shell process
	$output = shell_exec("pgrep -f -- " . escapeshellarg("-[n]ame " . $worldName . " ") . " 2>&1");
	return (!empty(trim($output)));
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

				// Check real-time process status instead of cached DB value
				$isOnline = isWorldRunning($myWorld);
				$worldMemory = $isOnline ? getWorldMemory($pdo,$myWorld) : "offline";
				// Show "pending..." if online but memory cron hasn't updated yet
				if ($isOnline && $worldMemory == "offline") {
					$worldMemory = "<i>pending...</i>";
				}

				$trophyEikthyr = getBossTrophyStatus($pdo,$myWorld,"trophyeikthyr");
                                $trophyTheElder = getBossTrophyStatus($pdo,$myWorld,"trophytheelder");
                                $trophyBonemass = getBossTrophyStatus($pdo,$myWorld,"trophybonemass");
                                $trophyDragonQueen = getBossTrophyStatus($pdo,$myWorld,"trophydragonqueen");
                                $trophyGoblinKing = getBossTrophyStatus($pdo,$myWorld,"trophygoblinking");
				$trophySeekerQueen = getBossTrophyStatus($pdo,$myWorld,"trophyseekerqueen");
				$trophyFader = getBossTrophyStatus($pdo,$myWorld,"trophyfader");

				if(!$isOnline) {
					$worldDimmed = "card_dimmed";
					$launchLabel = "offline";
					$modListToolTip = "offline";
				} else {
	                                # running mods public viewer
        	                        $runningMods_head = "\n<table border=\"0\" style=\"line-height:auto;\">\n";
	                                $runningMods_foot = "</table>\n";
	                                $runningMods = $runningMods_head . generateToolTip($pdo,$myWorld) . $runningMods_foot;
	                                $modListToolTip = "<a href='#' class='mod-view-link' style='box-shadow:none;border:none;outline:none;' tabindex='0' data-bs-trigger='focus' data-bs-toggle='popover' data-bs-placement='bottom' data-bs-title='Running Mods' data-bs-html='true' data-bs-content='$runningMods'>(<span class='view-text'>view</span>)</a>";

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
                                        <div class=\"$worldDimmed catbox\" data-world=\"$myWorld\">
                                                <table width=100% height=100% border=0>
                                                        <th class='$worldDimmed card_worldName' colspan=2>$myWorld</th>
                                                        <tr>
                                                        <th class='$worldDimmed card_worldLaunch' colspan=2><a class='$worldDimmed card_worldLaunch launch-link' href='phvalheim://?$launchString' data-launch='$launchString'>$launchLabel</a></th>

                                                        <tr>

                                                        <td style='height: 12px;'</td>

                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Mods&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-mods'>$modListToolTip</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>MD5 Sum&nbsp;&nbsp;&nbsp;:</td>
							<td class='$worldDimmed card_worldInfo world-md5'>$md5</td>
							<tr>
                                                        <td class='$worldDimmed card_worldInfo'>Seed&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
							<td class='$worldDimmed card_worldInfo world-seed'>$seed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Deployed&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-deployed'>$dateDeployed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Updated&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-updated'>$dateUpdated</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Memory&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-memory'>$worldMemory</td>
                                                        <tr>
                                                </table>
						<table border=0 class='trophy-table'>
							<td class='trophy_icon trophy-eikthyr $trophyEikthyrDimmed'><img title='$trophyEikthyrStatus' src='../images/TrophyEikthyr.png'></img></td>
                                                        <td class='trophy_icon trophy-theElder $trophyTheElderDimmed'><img title='$trophyTheElderStatus' src='../images/TrophyTheElder.png'></img></td>
                                                        <td class='trophy_icon trophy-bonemass $trophyBonemassDimmed'><img title='$trophyBonemassStatus' src='../images/TrophyBonemass.png'></img></td>
                                                        <td class='trophy_icon trophy-dragonQueen $trophyDragonQueenDimmed'><img title='$trophyDragonQueenStatus' src='../images/TrophyDragonQueen.png'></img></td>
                                                        <td class='trophy_icon trophy-goblinKing $trophyGoblinKingDimmed'><img title='$trophyGoblinKingStatus' src='../images/TrophyGoblinKing.png'></img></td>
							<td class='trophy_icon trophy-seekerQueen $trophySeekerQueenDimmed'><img title='$trophySeekerQueenStatus' src='../images/TrophySeekerQueen.png'></img></td>
							<td class='trophy_icon trophy-fader $trophyFaderDimmed'><img title='$trophyFaderStatus' src='../images/TrophyFader.png'></img></td>
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

		<link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css?v=<?php echo time(); ?>">
		<script src="../js/jquery-3.6.0.js"></script>
		<script src="../js/bootstrap.min.js"></script>

        </head>
        <body>

        <script>
                // Store steamID for AJAX polling
                const STEAM_ID = '<?php echo isset($steamID) ? $steamID : ""; ?>';
                const POLL_INTERVAL = 5000; // 5 seconds

                $(document).ready(function(){
                  // Bootstrap 5 popover initialization
                  var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl, {
                      sanitize: false,
                      html: true,
                      popperConfig: function(defaultConfig) {
                        defaultConfig.modifiers.push({
                          name: 'offset',
                          options: {
                            offset: [0, 10]
                          }
                        });
                        return defaultConfig;
                      }
                    });
                  });

                  // Start AJAX polling for world status
                  if (STEAM_ID) {
                    setInterval(fetchWorldStatus, POLL_INTERVAL);
                  }
                });

                async function fetchWorldStatus() {
                    try {
                        const response = await fetch(`api.php?mode=getMyWorldsStatus&steamID=${STEAM_ID}`);
                        const data = await response.json();

                        if (data.success && data.worlds) {
                            updateWorldCards(data.worlds);
                        }
                    } catch (error) {
                        console.error('Failed to fetch world status:', error);
                    }
                }

                function updateWorldCards(worlds) {
                    worlds.forEach(world => {
                        const card = document.querySelector(`.catbox[data-world="${world.name}"]`);
                        if (!card) return;

                        const isOnline = world.online;
                        const dimmedClass = 'card_dimmed';

                        // Update card dimmed state
                        if (isOnline) {
                            card.classList.remove(dimmedClass);
                        } else {
                            card.classList.add(dimmedClass);
                        }

                        // Update launch link
                        const launchLink = card.querySelector('.launch-link');
                        const launchTh = launchLink ? launchLink.parentElement : null;
                        if (launchLink) {
                            if (isOnline) {
                                launchLink.textContent = 'Launch!';
                                launchLink.href = `phvalheim://?${world.launchString}`;
                                launchLink.classList.remove(dimmedClass);
                                if (launchTh) launchTh.classList.remove(dimmedClass);
                            } else {
                                launchLink.textContent = 'offline';
                                launchLink.href = '#';
                                launchLink.classList.add(dimmedClass);
                                if (launchTh) launchTh.classList.add(dimmedClass);
                            }
                        }

                        // Update mods field
                        const modsEl = card.querySelector('.world-mods');
                        if (modsEl) {
                            // Dispose existing popover if any
                            const existingLink = modsEl.querySelector('.mod-view-link');
                            if (existingLink) {
                                const existingPopover = bootstrap.Popover.getInstance(existingLink);
                                if (existingPopover) existingPopover.dispose();
                            }

                            if (isOnline && world.mods && world.mods.length > 0) {
                                // Build mods tooltip content
                                let modsContent = '<table border="0" style="line-height:auto;">';
                                world.mods.sort((a, b) => b.name.toLowerCase().localeCompare(a.name.toLowerCase()));
                                world.mods.forEach(mod => {
                                    modsContent += `<tr><td><li><a target="_blank" href="${mod.url}">${mod.name}</a></li></td></tr>`;
                                });
                                modsContent += '</table>';

                                modsEl.innerHTML = `<a href='#' class='mod-view-link' style='box-shadow:none;border:none;outline:none;' tabindex='0' data-bs-trigger='focus' data-bs-toggle='popover' data-bs-placement='bottom' data-bs-title='Running Mods' data-bs-html='true' data-bs-content="${modsContent.replace(/"/g, '&quot;')}">(<span class='view-text'>view</span>)</a>`;

                                // Initialize new popover
                                const newLink = modsEl.querySelector('.mod-view-link');
                                if (newLink) {
                                    new bootstrap.Popover(newLink, {
                                        sanitize: false,
                                        html: true
                                    });
                                }
                            } else {
                                modsEl.textContent = 'offline';
                            }
                        }

                        // Update info fields
                        const md5El = card.querySelector('.world-md5');
                        if (md5El) md5El.innerHTML = world.md5;

                        const seedEl = card.querySelector('.world-seed');
                        if (seedEl) seedEl.innerHTML = world.seed;

                        const deployedEl = card.querySelector('.world-deployed');
                        if (deployedEl) deployedEl.textContent = world.dateDeployed;

                        const updatedEl = card.querySelector('.world-updated');
                        if (updatedEl) updatedEl.textContent = world.dateUpdated;

                        const memoryEl = card.querySelector('.world-memory');
                        if (memoryEl) {
                            if (isOnline && world.memory === 'offline') {
                                // World is online but memory hasn't updated yet
                                memoryEl.innerHTML = '<i>pending...</i>';
                            } else {
                                memoryEl.textContent = world.memory;
                            }
                        }

                        // Update trophy states
                        const trophyMap = {
                            'eikthyr': { el: '.trophy-eikthyr', defeated: 'Eikthyr has been defeated', undefeated: 'Eikthyr is undefeated' },
                            'theElder': { el: '.trophy-theElder', defeated: 'The Elder has been defeated', undefeated: 'The Elder is undefeated' },
                            'bonemass': { el: '.trophy-bonemass', defeated: 'Bonemass has been defeated', undefeated: 'Bonemass is undefeated' },
                            'dragonQueen': { el: '.trophy-dragonQueen', defeated: 'Moder has been defeated', undefeated: 'Moder is undefeated' },
                            'goblinKing': { el: '.trophy-goblinKing', defeated: 'Yagluth has been defeated', undefeated: 'Yagluth is undefeated' },
                            'seekerQueen': { el: '.trophy-seekerQueen', defeated: 'The Seeker Queen has been defeated', undefeated: 'The Seeker Queen is undefeated' },
                            'fader': { el: '.trophy-fader', defeated: 'Fader has been defeated', undefeated: 'Fader is undefeated' }
                        };

                        Object.keys(trophyMap).forEach(key => {
                            const trophyEl = card.querySelector(trophyMap[key].el);
                            if (trophyEl) {
                                const img = trophyEl.querySelector('img');
                                if (world.trophies[key] && isOnline) {
                                    trophyEl.classList.remove('trophy_dimmed');
                                    if (img) img.title = trophyMap[key].defeated;
                                } else {
                                    trophyEl.classList.add('trophy_dimmed');
                                    if (img) img.title = trophyMap[key].undefeated;
                                }
                            }
                        });

                        // Update all card_worldInfo cells dimmed state
                        card.querySelectorAll('.card_worldInfo, .card_worldName, .card_worldLaunch').forEach(el => {
                            if (isOnline) {
                                el.classList.remove(dimmedClass);
                            } else {
                                el.classList.add(dimmedClass);
                            }
                        });
                    });
                }
        </script>

                <?php populateTable($pdo,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey,$backupsToKeep,$defaultSeed,$basePort,$httpScheme,$operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) ?>
        </body>
</html>
