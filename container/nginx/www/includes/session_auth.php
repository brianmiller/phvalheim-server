<?php

include_once '/opt/stateless/nginx/www/includes/config_env_puller.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isSessionValid() {
    global $sessionTimeout;

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
