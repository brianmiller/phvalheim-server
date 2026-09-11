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
# canonicalAccessId(): the player's ID is shown in the V_ form, which is the ONLY form Valheim
# matches in permittedlist.txt. Handing out a bare SteamID64 sends people to a server owner
# with a string that silently matches nothing.
require_once '../includes/accesslists.php';
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

# One pill. Every pill on a card carries a title, because "CROSSPLAY" and "ACCESS LIST" are
# server-operator words and the people reading this page are players.
function accessBadge($label, $dimClass, $tooltip, $extraClass = '') {
	return "<span class='vanilla-badge $extraClass $dimClass' title=\""
		. htmlspecialchars($tooltip, ENT_QUOTES) . "\">"
		. htmlspecialchars($label) . "</span>";
}

# How players get in, for BOTH kinds of world. Modded and vanilla worlds are gated the same way
# -- worlds.public is the CITIZENS access-control flag in both cases -- so they say it the same
# way rather than the modded card leaving it unsaid.
#
# The empty-list case is called what it IS, not what it was set to. Valheim applies
# permittedlist.txt only when it has entries: an access list that is switched on and empty is
# not "nobody may join", it is no restriction at all, and a world in that state is open to
# anyone who can reach it. Labelling it ACCESS LIST would be the same lie the CROSSPLAY pill
# used to tell -- describing the setting instead of the server. Nothing here fixes the state;
# createWorld and saveCitizens refuse to create it, and startWorld logs a warning for any world
# already in it. This just refuses to misreport it.
# Each pill is one thing standing between a player and the world, so a world with two of them
# shows two. OPEN is the absence of all of them, which is why it is decided last: it means
# nothing is in the way, not merely that the access list is off.
function accessBadges($pdo, $world, $dimClass, $hasPassword = false) {
	$open = (getPublic($pdo, $world) == 1);
	# Valheim applies permittedlist.txt only when it has ENTRIES. A list that is switched on and
	# empty is not "nobody may join", it is no restriction at all, so it does not count as a gate
	# here. Nothing in this function fixes that state -- createWorld and saveCitizens refuse to
	# create it and startWorld warns about any world already in it -- it just will not claim a
	# world is list-restricted when Valheim is ignoring the list.
	$listInForce = !$open && trim((string)getCitizens($pdo, $world)) !== '';

	$badges = [];
	if ($listInForce) {
		$badges[] = accessBadge('access list', $dimClass,
			'Only the player IDs on this world\'s access list may join. Ask the server owner to add '
			. 'yours -- it is the V_ id shown under your name at the top of this page.');
	}
	if ($hasPassword) {
		$badges[] = accessBadge('password', $dimClass,
			'This world needs a password to join. It is shown in the Password row above when the '
			. 'server owner has chosen to publish it.');
	}

	if (!$badges) {
		# Nothing gates entry: no access list in force AND no password.
		$badges[] = accessBadge('open', $dimClass,
			$open
				? 'Anyone who can reach this server may join. No access list and no password.'
				: 'The access list is switched on but has nobody on it. Valheim ignores an empty '
				  . 'list, so anyone who can reach this server may join. Ask the server owner to '
				  . 'add player IDs.',
			'vanilla-badge-muted');
	}
	return implode(' ', $badges);
}

function populateTable($pdo,$gameDNS,$phvalheimHost,$phvalheimClientURL,$steamAPIKey,$backupsToKeep,$defaultSeed,$basePort,$httpScheme,$operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {

		# steam
		$steamID = getSessionSteamID();
		if ($steamID) {
			$steamJSON = file_get_contents("https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=$steamAPIKey&steamids=$steamID");
			$steamJSONObj = json_decode($steamJSON);
			$steamJSONObj = $steamJSONObj->response->players;
			$steamJSONObj = $steamJSONObj[0];

			# The access-list form of the player's own id, for handing to a server owner.
			# Falls back to a plain V_ prefix if canonicalAccessId() cannot parse it, so the
			# row never renders a bare id that would match nothing.
			$steamAccessID = canonicalAccessId($steamID) ?: 'V_' . $steamID;

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
                                <th colspan=2 class='name_header'>Welcome, $playerName!
                                        <!--
                                                The player's own id in the V_ form Valheim matches, so they can
                                                hand it to a server owner who needs to add them to an access
                                                list. Before this the only way to find it was a third-party
                                                lookup site, which is why the admin UI carried \"easiest way to
                                                get an ID\" banners.
                                        -->
                                        <div class='steamid-self' data-steamid='$steamAccessID' onclick='copySteamSelfId(this)' title='Click to copy your player ID'>
                                                <span class='steamid-self-value'>$steamAccessID</span>
                                        </div>
                                </th>


                                <tr>
                                <tr>

                                <td colspan=2 style='width:100%;'>
                                        <div class='outer'>
                                                <div class='inner'>
                                                        <div class='wrapper'>
                ";


		$getMyWorlds = getMyWorlds($pdo,$steamID);

		# Online first, then alphabetical within each group.
		#
		# This cannot be done in SQL. "Online" here is a LIVE process check --
		# isWorldRunning() -- not a database column, so the query has no way to sort by it.
		# getMyWorlds() ordered by `currentMemory`, a cron-updated column that is stale for a
		# world that just started or stopped and meaningless for one that never ran, which is
		# why the order looked arbitrary.
		#
		# isWorldRunning() is evaluated ONCE per world here and reused in the loop below,
		# rather than being called again for the same world a few lines later.
		$worldIsOnline = [];
		foreach ($getMyWorlds as $w) {
			$worldIsOnline[$w] = isWorldRunning($w);
		}
		$getMyWorlds = sortWorldsOnlineFirst($getMyWorlds, $worldIsOnline);

                if(!empty($getMyWorlds)) {
                        foreach ($getMyWorlds as $myWorld) { //only query and return authorized worlds
                                $launchString = getLaunchString($pdo,$myWorld,$gameDNS,$phvalheimHost,$httpScheme);
				$md5 = getMD5($pdo,$myWorld);
				$seed = getSeed($pdo,$myWorld);
				$hideSeed = getHideSeed($pdo,$myWorld);
				$dateDeployed = getDateDeployed($pdo,$myWorld);
				$dateUpdated = getDateUpdated($pdo,$myWorld);

				// Check real-time process status instead of cached DB value.
				// Computed once in the sort above; reused here so the card and the ordering
				// cannot disagree about whether the world is up.
				$isOnline = $worldIsOnline[$myWorld];
				$worldMemory = $isOnline ? getWorldMemory($pdo,$myWorld) : "offline";
				// Show "pending..." if online but memory cron hasn't updated yet
				if ($isOnline && $worldMemory == "offline") {
					$worldMemory = "<i>pending...</i>";
				}

				# A vanilla world has no companion mod, so it reports no boss progression
				# at all. It gets its own card below rather than an empty trophy row.
				$isVanilla = (getVanilla($pdo,$myWorld) == 1);
				$bossProgression = $isVanilla ? [] : getBossProgression($pdo,$myWorld);

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

				# Render the trophy row straight from the registry, in progression order.
				# Adding a boss is an entry in includes/bosses.php -- nothing here changes.
				$trophyRow = "";
				foreach ($bossProgression as $bossKey => $boss) {
					$trophyDimmed = ($boss['defeated'] && $worldDimmed == "") ? "" : "trophy_dimmed";
					$trophyRow .= "<td class='trophy_icon trophy-" . htmlspecialchars($bossKey) . " $trophyDimmed'>"
						. "<img title='" . htmlspecialchars($boss['status']) . "' src='../images/" . htmlspecialchars($boss['icon']) . "'></img></td>\n";
				}


				if ($hideSeed == 1) {
					$seed = '<i>hidden</i>';
				} elseif ($seed === '' || $seed === NULL) {
					# A vanilla world's seed is chosen by Valheim at first world generation
					# and read back out of the .fwl afterwards, so it is genuinely unknown
					# until the world has started once. Say so rather than render an empty
					# cell that looks like a rendering fault.
					$seed = '<i>generated on first start</i>';
				}

				if ($isVanilla) {
					# --- Vanilla world card ---
					#
					# Deliberately NOT the modded card with the mod/MD5/trophy rows blanked
					# out. A vanilla world is joined with Valheim's own +connect and has no
					# client payload, so what a player needs is the endpoint and password,
					# not a row of grey trophies telling them nothing.
					$vanillaPassword = getWorldPassword($pdo,$myWorld);
					$vanillaPort = getPort($pdo,$myWorld);
					# The RUNNING options for a live world, the saved ones for a stopped one --
					# never the saved ones for a live world. Toggling crossplay on a running
					# world used to light the CROSSPLAY pill immediately while the Launch link
					# correctly stayed a direct-connect link, because the pill read the column
					# and the link read the server. The pill advertised a crossplay world that
					# Valheim was not serving, and would go on doing so until someone restarted.
					$vanillaOpts = effectiveWorldOptions($pdo, $myWorld, $isOnline);
					$vanillaCrossplay = ((int)$vanillaOpts['crossplay'] === 1);
					$vanillaListed = ((int)$vanillaOpts['listed'] === 1);
					$vanillaEndpoint = htmlspecialchars($gameDNS . ":" . $vanillaPort);
					$vanillaSteamUrl = htmlspecialchars("steam://run/892970//+connect " . $gameDNS . ":" . $vanillaPort);

					# Vanilla Valheim has no launch argument to pre-fill a password, so the
					# player has to type it. Showing it here is the other half of the feature.
					#
					# An admin can turn that off per world (password_public), in which case the
					# row is dropped from the card entirely rather than rendered empty or masked
					# with no way to reveal it -- a permanently blank "Password:" row just reads
					# as a bug.
					$showPassword = (getPasswordPublic($pdo,$myWorld) != 0) && !empty($vanillaPassword);
					$passwordRow = "";
					if ($showPassword) {
						$passwordRow = "
                                                        <td class='$worldDimmed card_worldInfo'>Password&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-password'>"
							. "<span class='vanilla-password' data-password=\"" . htmlspecialchars($vanillaPassword) . "\">"
							. "<span class='vanilla-password-mask'>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>"
							. "<a href='#' class='vanilla-password-action' onclick='revealVanillaPassword(this); return false;'>show</a>"
							. "<a href='#' class='vanilla-password-action' onclick='copyVanillaPassword(this); return false;'>copy</a>"
							. "</span></td>
                                                        <tr>";
					}

					# Offline cards are fully greyed, same as the modded ones. Carry the dimmed
					# class onto the badges themselves -- they set their own background colour,
					# so opacity alone still leaves a tinted pill on an otherwise grey card.
					$badgeDim = $worldDimmed ? "vanilla-badge-dimmed" : "";
					# The "in server browser" pill is deliberately gone. It answered a question
					# no player has -- they are already looking at the world's card, so how it
					# was discovered is the operator's business, not theirs.
					$badges = accessBadges($pdo, $myWorld, $badgeDim,
						$vanillaPassword !== '' && $vanillaPassword !== NULL);
					# These two are NOT gates, so they are appended rather than passed into
					# accessBadges(): they must not suppress OPEN. A world can be published and
					# still be open -- being easy to find is not the same as being hard to enter,
					# and Valheim will not publish one without a password anyway.
					if ($vanillaListed) {
						$badges = trim($badges . " " . accessBadge('published', $badgeDim,
							'Listed in Valheim\'s public server browser, so players can find this '
							. 'world without being given its address.'));
					}
					if ($vanillaCrossplay) {
						$badges = trim($badges . " " . accessBadge('crossplay', $badgeDim,
							'Hosted on PlayFab so Xbox, PlayStation and Nintendo players can join. '
							. 'Join with the code above -- a crossplay world cannot be joined by IP.'));
					}

					# A CROSSPLAY world cannot be joined by IP at all. Valheim opens a PlayFab
					# server rather than a Steam one and hands out a join code; steam:// +connect
					# asks for a direct connection that the server is not offering, so the button
					# silently did nothing while the in-game browser worked fine. Show the code
					# the player actually needs instead of a link that cannot work.
					# Decide from what the server IS running, not from the column. -crossplay is
					# applied at launch, so toggling the flag on a running world leaves the two
					# disagreeing until it restarts -- and in that window the column is simply
					# wrong about how players can reach it.
					# The running session wins when it has logged a backend; for the ~30s of
					# world-loading before it has, worldIsPlayFab() falls back to the options the
					# world was STARTED with -- so the link is right from the first moment the
					# world appears, not 30 seconds later.
					# Offline, there is no session to ask, so the card describes what the world
					# WILL start as -- which is what its pills already say. Answering `false`
					# here used to give an offline crossplay world a CROSSPLAY pill and a
					# "join by IP" hint on the same card.
					$vanillaIsPlayFab = $isOnline
						? worldIsPlayFab($pdo, $myWorld, $isOnline)
						: $vanillaCrossplay;
					$vanillaJoinCode = $vanillaIsPlayFab ? getWorldJoinCode($myWorld) : NULL;

					# Valheim takes the join code on the command line -- `-joincode` is a
					# recognised launch argument, alongside -crossplay/-password/-port/-world.
					# So a crossplay world IS launchable; it just cannot use +connect, which
					# asks for a direct IP connection that a PlayFab-hosted server never offers.
					$vanillaJoinUrl = $vanillaJoinCode !== NULL
						? htmlspecialchars("steam://run/892970//-joincode " . $vanillaJoinCode)
						: NULL;

					# Same label as a modded world -- a vanilla world is a peer, not a
					# different kind of thing. Only the scheme differs: steam:// +connect
					# instead of phvalheim://, because there is no client payload to sync.
					# Keep the .launch-link class so the AJAX refresh finds and updates it.
					if (!$isOnline) {
						$joinLink = "<a class='$worldDimmed card_worldLaunch launch-link' href='#'>offline</a>";
					} elseif ($vanillaIsPlayFab) {
						# Launchable via -joincode once the lobby exists. Before that there is
						# genuinely no code to pass, so the label goes static rather than
						# offering a link with an empty argument.
						$joinLink = $vanillaJoinUrl !== NULL
							? "<a class='card_worldLaunch launch-link' href='$vanillaJoinUrl'>Launch!</a>"
							: "<span class='card_worldLaunch launch-link launch-link-static'>starting&hellip;</span>";
					} else {
						$joinLink = "<a class='card_worldLaunch launch-link' href='$vanillaSteamUrl'>Launch!</a>";
					}

					# The instructions differ by networking mode, and the old text told every
					# player to use "Join IP" with the address above -- which is precisely the
					# thing that does not work on a crossplay world.
					# Follows the RUNNING backend, not the column, for the same reason the link
					# does: telling a player "cannot be joined by IP" about a server that is
					# currently accepting exactly that is worse than saying nothing.
					$vanillaHint = $vanillaIsPlayFab
						# Kept to two lines at the card's width. Each of these used to run to three,
						# and the hint is the single tallest thing on a vanilla card -- it was
						# what stopped the card getting any shorter. Both still name the exact
						# Valheim screen, which is the part a player cannot guess.
						? "Crossplay world &mdash; use Launch, or the join code above in Valheim's <em>Join by code</em> box. It cannot be joined by IP."
						: "Use Launch, or the address above in Valheim's <em>Join IP</em> screen.";

					# The Server address is the thing you type into Valheim's "Join IP" screen.
					# A crossplay world is a PlayFab server: it does not accept a direct IP
					# connection at all, so the address is not just unhelpful, it is an invitation
					# to try the one thing that cannot work. Dropped entirely for crossplay.
					$serverRow = $vanillaIsPlayFab ? "" : "
                                                        <td class='$worldDimmed card_worldInfo'>Server&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-endpoint'><code>$vanillaEndpoint</code></td>
                                                        <tr>";

					# Only rendered for crossplay. A missing code means the world is up but has
					# not registered its lobby yet -- say so rather than showing an empty row.
					#
					# The label is padded with &nbsp; to the same 11 monospace characters as every
					# other label on the card. "Join code:" is one character shorter than the rest,
					# and since the label column shrinks to fit its widest entry, that left the join
					# code sitting 8px right of every other value in the column.
					$joinCodeRow = "";
					if ($vanillaIsPlayFab) {
						$codeCell = $vanillaJoinCode !== NULL
							? "<span class='vanilla-joincode' data-joincode=\"" . htmlspecialchars($vanillaJoinCode) . "\">"
								. "<code>" . htmlspecialchars($vanillaJoinCode) . "</code>"
								. "<a href='#' class='vanilla-password-action' onclick='copyVanillaJoinCode(this); return false;'>copy</a></span>"
							: ($isOnline ? "<em>starting&hellip;</em>" : "&mdash;");
						$joinCodeRow = "
                                                        <td class='$worldDimmed card_worldInfo'>Join&nbsp;code&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-joincode'>$codeCell</td>
                                                        <tr>";
					}

					# A password-protected, non-crossplay world has nothing to put on this row --
					# the Password row above already says how you get in. Drop the row rather than
					# render a label with nothing after the colon, which reads as a missing value.
					$accessRow = $badges === "" ? "" : "
                                                        <td class='$worldDimmed card_worldInfo'>Access&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$badges</td>
                                                        <tr>";

					echo "
                                        <div class=\"$worldDimmed catbox catbox-vanilla\" data-world=\"$myWorld\" data-vanilla=\"1\">
                                                <table width=100% height=100% border=0>
                                                        <th class='$worldDimmed card_worldName' colspan=2>$myWorld</th>
                                                        <tr>
                                                        <th class='$worldDimmed card_worldLaunch' colspan=2>$joinLink</th>
                                                        <tr>
                                                        <td class='card-gap' colspan=2></td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Type&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>unmodded</td>
                                                        <tr>
                                                        $serverRow
                                                        $joinCodeRow
                                                        $passwordRow
                                                        $accessRow
                                                        <td class='$worldDimmed card_worldInfo'>Seed&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-seed'>$seed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Deployed&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-deployed'>$dateDeployed</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Memory&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-memory'>$worldMemory</td>
                                                        <tr>
                                                        <td colspan=2 class='card-slack'></td>
                                                        <tr>
                                                        <td class='$worldDimmed vanilla-hint' colspan=2>$vanillaHint</td>
                                                        <tr>
                                                </table>
                                        </div>
                                ";
				} else {
				# Modded worlds are gated by the same CITIZENS list as vanilla ones, so they say
				# so on the card too. Leaving it off the modded card meant the one kind of world
				# that is ALWAYS access-controlled was the one that never mentioned it.
				$moddedBadges = accessBadges($pdo, $myWorld,
					$worldDimmed ? "vanilla-badge-dimmed" : "");
				echo "
                                        <div class=\"$worldDimmed catbox\" data-world=\"$myWorld\">
                                                <table width=100% height=100% border=0>
                                                        <th class='$worldDimmed card_worldName' colspan=2>$myWorld</th>
                                                        <tr>
                                                        <th class='$worldDimmed card_worldLaunch' colspan=2><a class='$worldDimmed card_worldLaunch launch-link' href='phvalheim://?$launchString' data-launch='$launchString'>$launchLabel</a></th>

                                                        <tr>

                                                        <td class='card-gap' colspan=2></td>

                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>Mods&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo world-mods'>$modListToolTip</td>
                                                        <tr>
                                                        <td class='$worldDimmed card_worldInfo'>MD5 Sum&nbsp;&nbsp;&nbsp;:</td>
							<td class='$worldDimmed card_worldInfo world-md5'>$md5</td>
							<tr>
                                                        <td class='$worldDimmed card_worldInfo'>Access&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                                                        <td class='$worldDimmed card_worldInfo'>$moddedBadges</td>
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
                                                        <td colspan=2 class='card-slack'></td>
                                                        <tr>
                                                </table>
						<table border=0 class='trophy-table'>
							$trophyRow
						</table>
                                        </div>
                                ";
				}
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
<?php
# Loud and unmissable, so a bypassed instance can never be mistaken for a real one. Fixed
# position and pointer-events:none so it cannot disturb the layout being inspected.
if (phvDevSteamID() !== NULL) {
	echo "<div style='position:fixed;top:0;left:0;right:0;z-index:99999;pointer-events:none;"
	   . "background:#b30000;color:#fff;font:bold 12px monospace;text-align:center;padding:3px'>"
	   . "STEAM AUTH BYPASSED &mdash; phvalheimDevSteamID=" . htmlspecialchars(phvDevSteamID())
	   . " &mdash; DEVELOPMENT ONLY</div>";
}
?>

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

                // Vanilla worlds show their server password on the card, since vanilla
                // Valheim has no way to receive it from a launch argument. Masked until
                // asked for, so it isn't sitting in plain sight on a shared screen.
                function revealVanillaPassword(link) {
                    const wrap = link.closest('.vanilla-password');
                    if (!wrap) return;
                    const mask = wrap.querySelector('.vanilla-password-mask');
                    if (!mask) return;
                    if (mask.dataset.revealed === '1') {
                        mask.textContent = '••••••••';
                        mask.dataset.revealed = '0';
                        link.textContent = 'show';
                    } else {
                        mask.textContent = wrap.dataset.password;
                        mask.dataset.revealed = '1';
                        link.textContent = 'hide';
                    }
                }

                function copyVanillaPassword(link) {
                    const wrap = link.closest('.vanilla-password');
                    if (!wrap) return;
                    copyCardValue(link, wrap.dataset.password);
                }

                // A crossplay world is joined by code, not by address, so the code needs the
                // same one-click copy the password has. Shares the implementation rather than
                // carrying a second copy of the insecure-context fallback below.
                // The player's own Steam ID, under their avatar. Restores to the ID rather than
                // to the word "copy", so the value stays readable once the flash clears.
                function copySteamSelfId(el) {
                    const id = el.dataset.steamid;
                    const label = el && el.querySelector('.steamid-self-value');
                    if (!id || !label) return;

                    writeToClipboard(id, (ok) => {
                        label.textContent = ok ? 'copied!' : 'copy failed';
                        el.classList.add(ok ? 'steamid-self-copied' : 'steamid-self-failed');
                        setTimeout(() => {
                            label.textContent = id;
                            el.classList.remove('steamid-self-copied', 'steamid-self-failed');
                        }, 1500);
                    });
                }

                function copyVanillaJoinCode(link) {
                    const wrap = link.closest('.vanilla-joincode');
                    if (!wrap) return;
                    copyCardValue(link, wrap.dataset.joincode);
                }

                // The clipboard write itself, split out so the Steam ID under the avatar and the
                // password/join-code copy links share ONE implementation. They restore their
                // label differently -- a copy link goes back to the word "copy", the Steam ID
                // goes back to the ID -- and that difference is all that should differ.
                function writeToClipboard(text, done) {
                    // navigator.clipboard needs a secure context. A self-hosted PhValheim is
                    // very often reached over plain http on a LAN, where it is simply
                    // undefined — so fall back rather than throwing and looking dead.
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(() => done(true), () => done(false));
                        return;
                    }

                    try {
                        const scratch = document.createElement('textarea');
                        scratch.value = text;
                        scratch.setAttribute('readonly', '');
                        scratch.style.position = 'fixed';
                        scratch.style.opacity = '0';
                        document.body.appendChild(scratch);
                        scratch.select();
                        const ok = document.execCommand('copy');
                        document.body.removeChild(scratch);
                        done(ok);
                    } catch (e) {
                        done(false);
                    }
                }

                function copyCardValue(link, password) {
                    if (password === undefined || password === null) return;

                    writeToClipboard(password, (ok) => {
                        link.textContent = ok ? 'copied!' : 'failed';
                        link.classList.add(ok ? 'vanilla-password-copied' : 'vanilla-password-failed');
                        setTimeout(() => {
                            link.textContent = 'copy';
                            link.classList.remove('vanilla-password-copied', 'vanilla-password-failed');
                        }, 1500);
                    });
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
                        // The RUNNING backend, not the crossplay setting -- see api.php. A world
                        // whose flag was toggled but which has not restarted is still serving
                        // the old way, and the card has to match reality, not intent.
                        const isCrossplay = !!(world.vanilla && world.connection && world.connection.playfab);
                        if (launchLink) {
                            if (isOnline) {
                                // A CROSSPLAY world has no launchable URL at all -- it is
                                // reached by join code, not by address. Without this branch the
                                // poll rewrites the server-rendered "Join Code" label back to
                                // "Launch!" with a null href a few seconds after page load,
                                // which is exactly how the dead button survived being noticed.
                                if (isCrossplay) {
                                    // A crossplay world launches with -joincode, but only once
                                    // the lobby exists. Until then there is no code to pass, so
                                    // do not offer a link with an empty argument.
                                    if (world.connection.steamUrl) {
                                        launchLink.textContent = 'Launch!';
                                        launchLink.href = world.connection.steamUrl;
                                    } else {
                                        launchLink.textContent = 'starting…';
                                        launchLink.removeAttribute('href');
                                    }
                                } else {
                                    launchLink.textContent = 'Launch!';
                                    // A vanilla world has no client payload and no quickconnect
                                    // mod, so phvalheim:// is meaningless for it -- it is joined
                                    // with Valheim's own +connect via steam://. This runs every
                                    // 5s, so without the branch the poll overwrites the correct
                                    // server-rendered link a few seconds after page load.
                                    launchLink.href = (world.vanilla && world.connection)
                                        ? world.connection.steamUrl
                                        : `phvalheim://?${world.launchString}`;
                                }
                                launchLink.classList.remove(dimmedClass);
                                if (launchTh) launchTh.classList.remove(dimmedClass);
                            } else {
                                launchLink.textContent = 'offline';
                                launchLink.href = '#';
                                launchLink.classList.add(dimmedClass);
                                if (launchTh) launchTh.classList.add(dimmedClass);
                            }
                        }

                        // Update the join code. It is issued per session, so a world that
                        // restarts gets a new one -- the poll is what keeps a card that was
                        // open across a restart from advertising the old, dead code.
                        const joinCodeEl = card.querySelector('.world-joincode');
                        if (joinCodeEl && isCrossplay) {
                            const code = world.connection.joinCode;
                            if (code) {
                                joinCodeEl.innerHTML =
                                    `<span class="vanilla-joincode" data-joincode="${code}">`
                                    + `<code>${code}</code>`
                                    + `<a href="#" class="vanilla-password-action" onclick="copyVanillaJoinCode(this); return false;">copy</a></span>`;
                            } else {
                                joinCodeEl.innerHTML = isOnline ? '<em>starting&hellip;</em>' : '&mdash;';
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

                        // Update trophy states.
                        //
                        // Emitted from includes/bosses.php so this is not a second list to
                        // keep in step with the PHP one -- a new boss is still a single
                        // entry in the registry. (The old hardcoded map here also drifted:
                        // it said "The Seeker Queen" where the server-rendered card said
                        // "The Queen", so the tooltip changed on the first AJAX refresh.)
                        const trophyMap = <?php
                            $jsTrophyMap = [];
                            foreach ($PHVALHEIM_BOSSES as $b) {
                                $jsTrophyMap[$b['key']] = [
                                    'el'         => '.trophy-' . $b['key'],
                                    'defeated'   => $b['name'] . ' has been defeated',
                                    'undefeated' => $b['name'] . ' is undefeated'
                                ];
                            }
                            echo json_encode($jsTrophyMap);
                        ?>;

                        // A vanilla world reports no boss progression and renders no trophy
                        // row. Skip only this block -- the dimmed-state update below still
                        // has to run for vanilla cards.
                        if (!world.vanilla) {
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
                        }

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

                <!-- macOS Install Modal -->
                <div class="modal fade" id="macInstallModal" tabindex="-1" aria-labelledby="macInstallModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content mac-install-modal">
                                        <div class="modal-header mac-install-header">
                                                <img src="../images/macos.svg" alt="macOS" style="width:28px;height:28px;margin-right:10px;">
                                                <h5 class="modal-title" id="macInstallModalLabel">Install PhValheim Client for macOS</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body mac-install-body">
                                                <p>Open <strong>Terminal</strong> and paste the following command:</p>
                                                <div class="mac-install-command-wrap">
                                                        <code class="mac-install-command" id="macInstallCmd" title="curl -fsSL https://raw.githubusercontent.com/brianmiller/phvalheim-client/master/macinstall.sh | bash">curl -fsSL https://.../<wbr>macinstall.sh | bash</code>
                                                        <input type="hidden" id="macInstallCmdFull" value="curl -fsSL https://raw.githubusercontent.com/brianmiller/phvalheim-client/master/macinstall.sh | bash">
                                                        <button type="button" class="btn btn-sm mac-install-copy-btn" onclick="copyMacCommand()" title="Copy to clipboard">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                        </button>
                                                </div>
                                                <p class="mac-install-note">The installer will download and configure everything automatically. You'll be ready to play in just a moment.</p>
                                        </div>
                                        <div class="modal-footer mac-install-footer">
                                                <a href="https://github.com/brianmiller/phvalheim-client#macos" target="_blank" rel="noopener" class="mac-install-ref-link">View install guide on GitHub</a>
                                                <button type="button" class="btn btn-sm btn-outline-download" data-bs-dismiss="modal">Close</button>
                                        </div>
                                </div>
                        </div>
                </div>

                <script>
                function copyMacCommand() {
                        var cmd = document.getElementById('macInstallCmdFull').value;
                        var showSuccess = function() {
                                var btn = document.querySelector('.mac-install-copy-btn');
                                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="palegreen" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                                setTimeout(function() {
                                        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                                }, 2000);
                        };
                        if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(cmd).then(showSuccess);
                        } else {
                                var sel = window.getSelection();
                                var range = document.createRange();
                                var el = document.getElementById('macInstallCmdFull');
                                el.type = 'text';
                                el.style.position = 'fixed';
                                el.style.left = '-9999px';
                                el.select();
                                document.execCommand('copy');
                                el.type = 'hidden';
                                el.style.position = '';
                                el.style.left = '';
                                sel.removeAllRanges();
                                showSuccess();
                        }
                }
                </script>

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
