<?php

## security 

#received from post
$steamID = $_POST['steamID'];

# send back to public home if someone tried to mess with the steam ID
if(strlen($steamID)<17) {
        header('Location: /');
}

# send back to public home if the steamID passed isn't a global admin
if(!(globalAdminCheck($pdo,$steamID))) {
        header('Location: /');
}

?>
