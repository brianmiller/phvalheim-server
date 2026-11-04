<?php

include_once '/opt/stateless/nginx/www/includes/config_env_puller.php';

# 2.61: mark the session cookie Secure when the request arrived over TLS, and only then.
# Unconditional would be wrong: many operators serve :8080 over plain HTTP, and a Secure
# cookie is never sent back over HTTP, so every one of them would lose login outright.
if (session_status() === PHP_SESSION_NONE) {
    $phvTls = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($phvTls) {
        ini_set('session.cookie_secure', '1');
    }
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

# The ONLY writer of the session identity. It refuses anything that is not a SteamID64:
# the caller is expected to have verified the id with Steam (includes/steam_openid.php),
# and this is the backstop for a future caller that does not. Returns whether it stored.
function storeSessionSteamID($steamID) {
    if (!is_string($steamID) || !preg_match('/^[0-9]{17}$/', $steamID)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['steamID'] = $steamID;
    $_SESSION['login_time'] = time();
    return true;
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
