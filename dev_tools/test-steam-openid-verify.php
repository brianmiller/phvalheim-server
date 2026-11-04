#!/usr/bin/env php
<?php
// Oracle: the Steam OpenID callback is VERIFIED, not trusted (2.61).
//
// Drives includes/steam_openid.php with a fake Steam (the injectable $post), so the decision
// logic runs here with no network. Each case is an oracle for one thing the real login used
// to get wrong or could get wrong: before 2.61 every one of the "rejected" cases below
// signed the visitor in as whoever they named in the URL.
//
// Mutation control: with the check_authentication POST short-circuited to "accept", the
// forged-signature case fails; with the return_to check removed, the other-site case fails.
//
// Run: php dev_tools/test-steam-openid-verify.php

require_once __DIR__ . '/../container/nginx/www/includes/steam_openid.php';

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label" . ($detail !== '' ? "  -- $detail" : '') . "\n"; }
}

$RETURN_TO = 'http://phv.example:8080/authenticated.php';
$STEAMID   = '76561198000000001';

// A faithful positive assertion, shaped exactly as Steam returns one.
function assertion(array $over = []) {
    global $RETURN_TO, $STEAMID;
    return array_merge([
        'openid.ns'             => 'http://specs.openid.net/auth/2.0',
        'openid.mode'           => 'id_res',
        'openid.op_endpoint'    => 'https://steamcommunity.com/openid/login',
        'openid.claimed_id'     => "https://steamcommunity.com/openid/id/$STEAMID",
        'openid.identity'       => "https://steamcommunity.com/openid/id/$STEAMID",
        'openid.return_to'      => $RETURN_TO,
        'openid.response_nonce' => '2026-11-03T12:00:00ZabcDEF',
        'openid.assoc_handle'   => '1234567890',
        'openid.signed'         => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig'            => 'c2lnbmF0dXJl',
    ], $over);
}

// The fake Steam: records what it was asked, answers from a script.
$posted = [];
$steamSays = "ns:http://specs.openid.net/auth/2.0\nis_valid:true\n";
$fakeSteam = function ($url, $fields) use (&$posted, &$steamSays) {
    $posted[] = ['url' => $url, 'fields' => $fields];
    return $steamSays;
};

// ---- the happy path, and what it must send
$posted = [];
$id = phvSteamOpenIdVerify(assertion(), $RETURN_TO, $fakeSteam);
check('a genuine assertion Steam confirms yields the SteamID64', $id === $STEAMID, var_export($id, true));
check('exactly one check_authentication POST was made', count($posted) === 1, count($posted) . ' posts');
check('...to the Steam OpenID endpoint', ($posted[0]['url'] ?? '') === 'https://steamcommunity.com/openid/login');
check('...with openid.mode rewritten to check_authentication', ($posted[0]['fields']['openid.mode'] ?? '') === 'check_authentication');
check('...echoing the signature and signed list untouched',
    ($posted[0]['fields']['openid.sig'] ?? '') === 'c2lnbmF0dXJl'
    && ($posted[0]['fields']['openid.signed'] ?? '') === assertion()['openid.signed']);

// ---- the Discord report: a URL with only a claimed id (what PHP's $_GET used to hand us)
$posted = [];
$id = phvSteamOpenIdVerify(['openid.claimed_id' => "https://steamcommunity.com/openid/id/$STEAMID"], $RETURN_TO, $fakeSteam);
check('a bare claimed_id with nothing else is rejected', $id === null);
check('...without ever asking Steam', count($posted) === 0);

// ---- a full-shaped assertion whose signature Steam does NOT confirm
$posted = []; $steamSays = "ns:http://specs.openid.net/auth/2.0\nis_valid:false\n";
$id = phvSteamOpenIdVerify(assertion(['openid.sig' => 'Zm9yZ2Vk']), $RETURN_TO, $fakeSteam);
check('a forged signature (Steam says is_valid:false) is rejected', $id === null);
check('...and Steam WAS asked (the decision came from Steam, not from us)', count($posted) === 1);
$steamSays = "ns:http://specs.openid.net/auth/2.0\nis_valid:true\n";

// ---- Steam unreachable or answering garbage
$id = phvSteamOpenIdVerify(assertion(), $RETURN_TO, function () { return null; });
check('Steam unreachable (null body) is rejected, not treated as a pass', $id === null);
$id = phvSteamOpenIdVerify(assertion(), $RETURN_TO, function () { throw new RuntimeException('curl'); });
check('a transport exception is rejected, not treated as a pass', $id === null);
$id = phvSteamOpenIdVerify(assertion(), $RETURN_TO, function () { return "<html>busy</html>"; });
check('an HTML error page from Steam is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(), $RETURN_TO, function () { return "is_valid:true\n"; });
check('is_valid:true without the OpenID ns line is rejected', $id === null);

// ---- an assertion minted for ANOTHER PhValheim server, replayed here
$posted = [];
$id = phvSteamOpenIdVerify(assertion(['openid.return_to' => 'http://other.example:8080/authenticated.php']), $RETURN_TO, $fakeSteam);
check('an assertion for another site (return_to differs) is rejected', $id === null);
check('...without asking Steam', count($posted) === 0);
$id = phvSteamOpenIdVerify(assertion(['openid.return_to' => $RETURN_TO . '?x=1']), $RETURN_TO, $fakeSteam);
check('return_to is compared without its query string', $id === $STEAMID);

// ---- identity games
$id = phvSteamOpenIdVerify(assertion(['openid.claimed_id' => 'https://evil.example/openid/id/' . $STEAMID,
                                      'openid.identity'   => 'https://evil.example/openid/id/' . $STEAMID]), $RETURN_TO, $fakeSteam);
check('a claimed_id that is not a steamcommunity.com profile is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.claimed_id' => 'https://steamcommunity.com/openid/id/7656119800000000']), $RETURN_TO, $fakeSteam);
check('a 16-digit id is rejected (SteamID64 is 17 digits)', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.claimed_id' => "https://steamcommunity.com/openid/id/$STEAMID/../76561198000000002"]), $RETURN_TO, $fakeSteam);
check('a claimed_id with trailing path tricks is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.identity' => 'https://steamcommunity.com/openid/id/76561198000000002']), $RETURN_TO, $fakeSteam);
check('claimed_id and identity disagreeing is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.claimed_id' => "http://steamcommunity.com/openid/id/$STEAMID",
                                      'openid.identity'   => "http://steamcommunity.com/openid/id/$STEAMID"]), $RETURN_TO, $fakeSteam);
check('the older http:// form of the profile URL is accepted', $id === $STEAMID);

// ---- fields the signature must cover
$id = phvSteamOpenIdVerify(assertion(['openid.signed' => 'signed,op_endpoint,identity,response_nonce,assoc_handle']), $RETURN_TO, $fakeSteam);
check('an assertion whose signature does not cover claimed_id/return_to is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.mode' => 'cancel']), $RETURN_TO, $fakeSteam);
check('mode=cancel (the player pressed Cancel at Steam) is rejected', $id === null);
$id = phvSteamOpenIdVerify(assertion(['openid.op_endpoint' => 'https://steamcommunity.com.evil.example/openid/login']), $RETURN_TO, $fakeSteam);
check('a foreign op_endpoint is rejected', $id === null);
foreach (['openid.sig', 'openid.response_nonce', 'openid.assoc_handle'] as $k) {
    $id = phvSteamOpenIdVerify(assertion([$k => '']), $RETURN_TO, $fakeSteam);
    check("an empty $k is rejected", $id === null);
}

// ---- the query-string parser keeps the dots PHP would have mangled
$q = 'openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0&openid.sig=a%2Bb%3D&foo=bar&openid.claimed_id=https%3A%2F%2Fsteamcommunity.com%2Fopenid%2Fid%2F' . $STEAMID;
$p = phvOpenIdParamsFromQuery($q);
check('parser keeps openid.* names with their dots', isset($p['openid.ns']) && isset($p['openid.claimed_id']));
check('parser decodes values (a %2B is a +)', ($p['openid.sig'] ?? '') === 'a+b=');
check('parser drops non-openid parameters', !isset($p['foo']));
check('phvSteamIdFromClaimedId extracts the id', phvSteamIdFromClaimedId("https://steamcommunity.com/openid/id/$STEAMID") === $STEAMID);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
