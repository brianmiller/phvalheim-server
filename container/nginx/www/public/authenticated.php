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
include '/opt/stateless/nginx/www/includes/session_auth.php';

if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
	$httpScheme = "https";
} else {
	$httpScheme = "http";
}

// Session-based authentication
$steamID = null;

if (isset($_GET['openid_claimed_id'])) {
	// Fresh Steam login callback - extract steamID and store in session
	$steamIDArr = explode('/', $_GET['openid_claimed_id']);
	$steamID = end($steamIDArr);
	storeSessionSteamID($steamID);

	// Redirect to clean URL (removes openid params from address bar)
	header('Location: ' . $httpScheme . '://' . $_SERVER['HTTP_HOST'] . '/authenticated.php');
	exit;
} elseif (isSessionValid()) {
	// Existing valid session
	$steamID = getSessionSteamID();
} else {
	// No valid auth - redirect to login
	header('Location: ../index.php');
	exit;
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
		$steamID = getSessionSteamID();
		if ($steamID) {
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
			exit;
		}


                echo "
                        <table width=100% height=100% border=0>
                                <th class='google_header'><img src='$steamAvatarURL'></img></th>
                                <th class='header_right_section'><div class='header_right_inner'>
                                        <span class='client_download_button'>";
				populateDownloadMenu($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender);
		echo "
                                        </span>
                                        <a href='logout.php' class='signout-icon' title='Sign Out'>
                                                <svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/><polyline points='16 17 21 12 16 7'/><line x1='21' y1='12' x2='9' y2='12'/></svg>
                                        </a>
                                </div></th>
                                <tr>
                                <th colspan=2 class='name_header'>Welcome, $playerName!</th>


                                <tr>
                                <tr>

                                <td colspan=2 style='width:100%;'>
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
                        echo "<tr><td colspan='2' style='text-align: center;color:red;'><div>WARNING: Backup retention is not configured. Set this in Admin UI &rarr; Server Settings.</div>";
                }

                if(empty($playerName)) {
                        echo "<tr><td colspan='2' style='text-align: center;color:red;'><div>WARNING: The SteamAPI could not be contacted. Check your Steam API Key in Admin UI &rarr; Server Settings.</div>";
                }

                if(empty($phvalheimClientURL)) {
                        echo "<tr><td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Client Download URL is missing! Set this in Admin UI &rarr; Server Settings.</div>";
		}

                if(empty($basePort)) {
                        echo "<tr><td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim Base Port is not set! Set this in Admin UI &rarr; Server Settings.</div>";
                }

                if(empty($gameDNS)) {
                        echo "<tr><td colspan='2' style='text-align: center;color:red;'><div>WARNING: The PhValheim game DNS endpoint is not set! Set this in Admin UI &rarr; Server Settings.</div>";
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

<?php if ($setupComplete === 0): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
    <link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css?v=<?php echo time(); ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-primary);color:var(--text-primary);">
    <div style="text-align:center;max-width:400px;padding:2rem;">
        <img src="/images/phvalheim_favicon.svg" style="width:64px;height:64px;margin-bottom:1.5rem;" alt="PhValheim">
        <h2 style="margin-bottom:0.75rem;">PhValheim is Starting Up</h2>
        <p style="color:var(--text-muted);">The server is being configured by the administrator. Please check back soon.</p>
    </div>
</body>
</html>
<?php exit; endif; ?>

<!DOCTYPE html>
<html>
        <head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">

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
                        const response = await fetch(`api.php?mode=getMyWorldsStatus`);
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

                <div class="public-footer">
                        <a href="https://github.com/brianmiller/phvalheim-server" target="_blank" rel="noopener" class="social-link" title="View on GitHub">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                        </a>
                        <a href="https://discord.gg/8RMMrJVQgy" target="_blank" rel="noopener" class="social-link" title="Join our Discord">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
                        </a>
                </div>

                <div class="cookie-consent" id="cookieConsent">
                        <div class="cookie-consent-content">
                                <div class="cookie-consent-header">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/></svg>
                                        <span>Cookie Notice</span>
                                </div>
                                <p>This site uses a single session cookie to keep you signed in via Steam. No tracking, analytics, or third-party cookies are used. Your session expires after 30 days of inactivity.</p>
                                <p class="cookie-consent-legal">By clicking Accept, you consent to the use of this cookie in accordance with GDPR and ePrivacy regulations.</p>
                                <div class="cookie-consent-actions">
                                        <button class="cookie-btn cookie-btn-accept" onclick="acceptCookies()">Accept</button>
                                        <button class="cookie-btn cookie-btn-deny" onclick="denyCookies()">Deny</button>
                                </div>
                        </div>
                </div>

                <script>
                        function getCookie(name) {
                                var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                                return match ? match[2] : null;
                        }

                        function setCookie(name, value, days) {
                                var d = new Date();
                                d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
                                document.cookie = name + '=' + value + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
                        }

                        function acceptCookies() {
                                setCookie('cookie_consent', 'accepted', 365);
                                var el = document.getElementById('cookieConsent');
                                el.classList.remove('show');
                                setTimeout(function() { el.style.display = 'none'; }, 400);
                        }

                        function denyCookies() {
                                window.location.href = 'logout.php';
                        }

                        $(document).ready(function() {
                                if (!getCookie('cookie_consent')) {
                                        setTimeout(function() {
                                                document.getElementById('cookieConsent').classList.add('show');
                                        }, 500);
                                }
                        });
                </script>
        </body>
</html>
