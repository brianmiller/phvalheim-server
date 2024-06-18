<?php
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

// Handle AJAX request for fetching SteamID
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetchSteamID') {
    $vanityURL = $_POST['vanityURL'];
    $apiKey = '8F4C845549D1B8C0ABCA2AC00925D322';  // Replace with your actual API key
    $steamID = Get_SteamID_From_VanityURL($vanityURL, $apiKey);
    echo $steamID ?: 'Invalid Vanity URL or SteamID not found';
    //echo $steamID;
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
    $apiKey = '8F4C845549D1B8C0ABCA2AC00925D322';
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
        return null;
    }

    return isset($data['response']['steamid']) ? $data['response']['steamid'] : null;
}
?>

<!DOCTYPE HTML>
<html>
    <head>
        <meta charset="UTF-8">
        <title>PhValheim Citzens Editor</title>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css?refreshcss=<?php echo rand(100, 1000)?>'>
        <link rel="stylesheet" href="/css/phvalheimStyles.css?refreshcss=<?php echo rand(100, 1000)?>">

        <!-- Google Fonts -->
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic?refreshcss=<?php echo rand(100, 1000)?>">

        <!-- CSS Reset -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css?refreshcss=<?php echo rand(100, 1000)?>">
    </head>

    <body>
        <p class='pri-color' style='margin-top: 1%;' align='center'>Add player's SteamID to grant access or set the world to public.</p>
        <p class='pri-color' style='margin-top: 1%;' align='center'><label class='alt-color'>Note:</label> <i>SteamIDs listed below will be ignored when world is set to public.</i></p>

        <form action='citizensEditor.php'>
            <table style="margin-top: 25px;" align='center' border='0' class='outline'>
                <th colspan='2' class='bottom_line'>
                    <?php print "World: $world";?>
                </th>

                <tr>
                    <th>
                        <p class='outline alt-color'>Editor</p>
                    </th>
                    <th>
                        <p class='outline alt-color'>Current List on Disk</p>
                    </th>
                </tr>

                <tr>
                    <th>
                        <textarea class='outline textarea' style='resize: none;' cols='17' rows='20' name='citizens'><?php print $currentCitizens;?></textarea>
                    </th>
                    <td>
                        <textarea class='disabled outline textarea' style='resize: none;' cols='40' rows='20' name='citizens' disabled><?php print $currentAllowListFile;?></textarea>
                    </td>
                </tr>

                <tr>
                    <td align='center' style='text-align: center;' colspan='2'>
                        <table align='center' style='text-align: center;' border=0>
                            <td style='padding: 0 5px 0;' align='center' style='text-align: center;'>
                                <a href='index.php'><button class='sm-bttn' type="button">Back</button></a>
                            <td align='center' style='text-align: center;'>
                                <input class='sm-bttn' type="submit" value="Save">
                            <tr>
                            <td align='center' class='alt-color' colspan='2' style='padding-top: 6px; text-align: center;'>
                                <input name='public' type="checkbox" <?php print $publicFlag?>> Set to public</input>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td colspan='2' style='text-align: center;'>
                        <button type="button" class="sm-bttn" id="openModalButton">Convert Vanity URL to SteamID</button>
                    </td>
                </tr>

                <tr>
                    <td colspan='2'>
                        <div style='text-align: center;'><?php print $msg;?></div>
                    </td>
                </tr>

                <input type='hidden' name='world' value='<?php echo $world;?>'></input>
                <input type='hidden' name='msg' value='saved'></input>
            </table>
        </form>

        <!-- Modal Structure -->
        <div id="vanityModal" class="modal">
            <div class="modal-content outline">
                <span class="close" id="modalClose">&times;</span>
                <h2 style="color: var(--button-font-color-hover);">Enter Steam Vanity URL</h2>
                <input type="text" id="vanityURLInput" class="sm-bttn textarea" placeholder="Enter Steam Username" />
                <button id="submitVanityURL" onclick="fetchSteamID()" class="sm-bttn">Get SteamID</button>
                    <div id="steamIDOutput" style="color: var(--button-font-color-idle); padding-top:10px;"></div>
                    <button onclick"copy()" id="copy-btn" class="sm-bttn">Copy</button>
            </div>
        </div>

        <!-- Modal Styling -->
        <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: transparent;
            backdrop-filter: blur(5px); /* Apply a slight blur to the backdrop */
        }

        .modal-content {
            background-color: var(--main-background-color);
            margin: 15% auto;
            padding: 20px;
            border: 1px solid var(--outline-light);
            width: 600px;
            text-align: center;
        }

        .close {
            color: var(--outline-light);
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        </style>

        <!-- JavaScript for Modal and AJAX -->
        <script>
        document.addEventListener("DOMContentLoaded", function() {




            var modal = document.getElementById("vanityModal");
            var span = document.getElementById("modalClose");

document.getElementById("copy-btn").onclick = function() {
    var copyText = document.getElementById("steamIDOutput");

    if (copyText) {
        // Create a range object and select the text
        let range = document.createRange();
        range.selectNodeContents(copyText);

        // Remove any existing selections and add the new range
        let selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        try {
            // Attempt to copy the selected text to the clipboard
            navigator.clipboard.writeText(copyText.innerText)
                .then(() => {
                    alert("Copied the text: " + copyText.innerText);
                })
                .catch(err => {
                    console.error('Could not copy text: ', err);
                });
        } catch (err) {
            console.error('Error copying text: ', err);
        }

        // Clean up the selection
        selection.removeAllRanges();
    } else {
        console.error("Element with ID 'steamIDOutput' not found.");
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
                        document.getElementById("steamIDOutput").innerText = "SteamID: " + response;
                    }
                };

                xhr.send("action=fetchSteamID&vanityURL=" + encodeURIComponent(vanityURL));
            }
        });

        </script>
    </body>
</html>
