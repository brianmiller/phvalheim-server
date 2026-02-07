<?php
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

// Handle AJAX request for fetching SteamID
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetchSteamID') {

    $vanityURL = $_POST['vanityURL'];

    $apiKey = getenv('steamAPIKey');
    if (!isset($apiKey) || $apiKey == '') {
        echo "APIKEY NOT SET";
        return;
    }

    $steamID = Get_SteamID_From_VanityURL($vanityURL, $apiKey);

    if ($steamID == 2) {
     echo "invalid steam id(user may have a private account)";
    }


    echo $steamID ?: 'Invalid Vanity URL or SteamID not found';

    exit();
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
} else {
    $msg = "";
}

if (isset($_GET['world'])) {
    $world = $_GET['world'];
    $currentCitizens = getCitizens($pdo, $world);
    $currentCitizens = str_replace(' ', PHP_EOL, $currentCitizens);
}

$getPublic = getPublic($pdo, $world);
if ($getPublic) {
    $publicFlag = "checked";
}

if (isset($_GET['public'])) {
    $public = true;
} else {
    $public = false;
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'saved') {
        $saved = true;
    }
}

if ($saved == true && $public == true) {
    setPublic($pdo, $world, 1);
    $publicFlag = "checked";
}
if ($saved == true && $public == false) {
    setPublic($pdo, $world, 0);
    $publicFlag = NULL;
}

if (isset($_GET['citizens'], $_GET['world'])) {
    $citizens = $_GET['citizens'];
    $world = $_GET['world'];

    $getPublic = getPublic($pdo, $world);
    if ($getPublic) {
        file_put_contents("/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt", "// List permitted players ID ONE per line");
    } else {
        $citizens = str_replace("\r\n", " ", $citizens);
        $citizens = preg_replace('!\s+!', ' ', $citizens);

        setCitizens($pdo, $world, $citizens);
        $currentCitizens = getCitizens($pdo, $world);
        $currentCitizens = str_replace(' ', PHP_EOL, $currentCitizens);

        file_put_contents("/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt", "// List permitted players ID ONE per line\n" . $currentCitizens);
    }
}

$currentAllowListFile = file_get_contents("/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt");

// Initialize the SteamID result variable
$steamIDResult = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['vanityURL'])) {
    $vanityURL = $_POST['vanityURL'];
    $apiKey = getenv('steamAPIKey');
    $steamIDResult = Get_SteamID_From_VanityURL($vanityURL, $apiKey);
}

function Get_SteamID_From_VanityURL(string $vanityURL, string $apiKey): ?string
{
    $url = "https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?key=$apiKey&vanityurl=$vanityURL";
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($curl);
    $curl_error = curl_error($curl);
    curl_close($curl);

    if ($curl_error) {
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['response']) || $data['response']['success'] != 1) {
        return 2;
    }

    return isset($data['response']['steamid']) ? $data['response']['steamid'] : null;
}
?>

<!DOCTYPE HTML>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Citizens Editor - PhValheim Admin</title>
        <link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
        <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
        <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
        <script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
        <script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
    </head>

    <body>
        <div class="container-fluid px-3 px-lg-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
                <h4 class="mb-0" style="color: var(--accent-primary);">Citizens Editor</h4>
                <a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
            </div>

            <!-- Info Banner -->
            <div class="card-panel mb-4" style="border-color: var(--info);">
                <p class="mb-1">Add player's SteamID to grant access or set the world to public.</p>
                <p class="mb-0 small text-secondary"><strong class="alt-color">Note:</strong> <em>SteamIDs listed below will be ignored when world is set to public.</em></p>
            </div>

            <form action='citizensEditor.php'>
                <input type='hidden' name='world' value='<?php echo $world;?>'>
                <input type='hidden' name='msg' value='saved'>

                <!-- World Info -->
                <div class="card-panel mb-4">
                    <div class="card-panel-header">World: <span class="pri-color"><?php print $world;?></span></div>

                    <div class="row g-4">
                        <!-- Editor Column -->
                        <div class="col-12 col-md-6">
                            <label class="form-label alt-color">Allowed SteamIDs</label>
                            <textarea class="form-control textarea" style="resize: vertical; min-height: 300px; font-family: var(--font-mono, monospace);" name='citizens' placeholder="Enter SteamIDs, one per line"><?php print $currentCitizens;?></textarea>
                        </div>

                        <!-- Current List Column -->
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Current List on Disk (Read-only)</label>
                            <textarea class="form-control textarea disabled" style="resize: vertical; min-height: 300px; font-family: var(--font-mono, monospace);" disabled><?php print $currentAllowListFile;?></textarea>
                        </div>
                    </div>

                    <!-- SteamID Lookup Button -->
                    <div class="text-center mt-3">
                        <button type="button" class="sm-bttn" id="openModalButton">Get SteamID</button>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <a href='index.php'><button class='sm-bttn' type="button">Cancel</button></a>
                        <button class='sm-bttn' type="submit" style="background-color: var(--success-dark); border-color: var(--success);">Save</button>
                    </div>

                    <!-- Public Checkbox -->
                    <div class="text-center mt-3">
                        <div class="form-check d-inline-block">
                            <input class="form-check-input" type="checkbox" name='public' id="publicCheck" <?php print $publicFlag?>>
                            <label class="form-check-label alt-color" for="publicCheck">Set world to public</label>
                        </div>
                    </div>

                    <!-- Status Message -->
                    <?php if (!empty($msg) && $msg == 'saved'): ?>
                    <div class="text-center mt-3">
                        <span class="text-success">Settings saved successfully.</span>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Modal Structure -->
        <div id="vanityModal" class="modal">
            <div class="modal-content">
                <span class="close" id="modalClose">&times;</span>
                <h5 class="mb-3" style="color: var(--success);">SteamID Lookup Helper</h5>
                <div class="mb-3">
                    <input type="text" id="vanityURLInput" class="form-control" placeholder="Enter Steam Username">
                </div>
                <button id="submitVanityURL" onclick="fetchSteamID()" class="sm-bttn">Look Up SteamID</button>
                <div id="steamIDOutput" class="mt-3 p-2" style="color: var(--accent-secondary); background: var(--bg-primary); border-radius: 0.375rem; min-height: 2rem;"></div>
                <button id="copy-btn" class="sm-bttn mt-2">Copy to Clipboard</button>
            </div>
        </div>

        <!-- JavaScript for Modal and AJAX -->
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            var modal = document.getElementById("vanityModal");
            var span = document.getElementById("modalClose");

            document.getElementById("copy-btn").onclick = function() {
                var copyText = document.getElementById("steamIDOutput");

                if (copyText && copyText.innerText.trim()) {
                    navigator.clipboard.writeText(copyText.innerText)
                        .then(() => {
                            alert("Copied: " + copyText.innerText);
                        })
                        .catch(err => {
                            console.error('Could not copy text: ', err);
                        });
                }
            }

            document.getElementById("openModalButton").onclick = function() {
                modal.style.display = "block";
            }

            span.onclick = function() {
                modal.style.display = "none";
            }

            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }

            window.fetchSteamID = function() {
                var vanityURL = document.getElementById("vanityURLInput").value;
                var xhr = new XMLHttpRequest();
                xhr.open("POST", "citizensEditor.php", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

                xhr.onreadystatechange = function() {
                    if (xhr.readyState == 4 && xhr.status == 200) {
                        var response = xhr.responseText;
                        document.getElementById("steamIDOutput").innerText = response;
                    }
                };

                xhr.send("action=fetchSteamID&vanityURL=" + encodeURIComponent(vanityURL));
            }

            // Allow Enter key in the input field
            document.getElementById("vanityURLInput").addEventListener("keypress", function(e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    fetchSteamID();
                }
            });
        });
        </script>
    </body>
</html>
