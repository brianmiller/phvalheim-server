<?php

include_once '/opt/stateless/nginx/www/includes/config_env_puller.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

# DEVELOPMENT ONLY -- render the public UI as a fixed player, skipping Steam OpenID.
#
# The value comes from a container ENVIRONMENT VARIABLE and from nowhere else: not the settings
# table, not a query string, not a header, not a cookie, not the admin UI. A shipped container
# never sets it -- the Dockerfile, the compose file, the Helm chart and the README all leave it
# undefined -- so the only way to turn it on is to pass `-e` at `docker run`, which is already
# full control of the server. dev_tools/test-dev-auth-bypass.sh enforces all of that.
#
# It exists because the world-card layout was reworked four times without the real page ever
# being loaded, each attempt measured against synthetic markup and each one wrong.
function phvDevSteamID() {
    $id = getenv('phvalheimDevSteamID');
    return (is_string($id) && preg_match('/^[0-9]{17}$/', $id)) ? $id : NULL;
}

function isSessionValid() {
    global $sessionTimeout;

    if (phvDevSteamID() !== NULL) {
        return true;
    }

    if (empty($_SESSION['steamID']) || empty($_SESSION['login_time'])) {
        return false;
    }

    if ((time() - $_SESSION['login_time']) > $sessionTimeout) {
        session_unset();
        session_destroy();
        return false;
    }

    return true;
}

function getSessionSteamID() {
    $dev = phvDevSteamID();
    if ($dev !== NULL) {
        return $dev;
    }
    if (isSessionValid()) {
        return $_SESSION['steamID'];
    }
    return null;
}

function storeSessionSteamID($steamID) {
    session_regenerate_id(true);
    $_SESSION['steamID'] = $steamID;
    $_SESSION['login_time'] = time();
}

function requireSession() {
    if (!isSessionValid()) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        header('Location: ../index.php');
        exit;
    }
}
