<?php
// Include the function

function Get_SteamID_From_VanityURL(string $vanityURL, string $apiKey): ?string
{
  // Build the API request URL
  $url = "http://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key=$apiKey&vanityurl=$vanityURL";

  // Initialize cURL
  $curl = curl_init($url);

  // Set cURL options
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Verify SSL peer (optional, can be set to true for security)

  // Execute the request
  $response = curl_exec($curl);
  $curl_error = curl_error($curl);

  // Close cURL connection
  curl_close($curl);

  // Check for cURL errors
  if ($curl_error) {
    return null;
  }

  // Decode JSON response
  $data = json_decode($response, true);

  // Check for successful response
  if (!isset($data['response']) || $data['response']['success'] != 1) {
    return null;
  }

  // Extract SteamID
  return isset($data['response']['steamid']) ? $data['response']['steamid'] : null;
}




// Get vanity URL from POST data
$vanityURL = $_POST['vanityURL'];

// Call the function to get SteamID
//$apiKey = getenv('steamAPIKey');
$apiKey = '8F4C845549D1B8C0ABCA2AC00925D322'
$steamID = Get_SteamID_From_VanityURL($vanityURL, $apiKey);

// Display the SteamID
if ($steamID) {
  echo "<p>SteamID: $steamID</p>";
} else {
  echo "<p>Unable to retrieve SteamID.</p>";
}
