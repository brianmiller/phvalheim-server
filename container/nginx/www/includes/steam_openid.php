<?php
/**
 * Steam OpenID 2.0: verify the assertion Steam sent back BEFORE trusting a Steam ID.
 *
 * Until 2.61 authenticated.php took the Steam ID straight out of openid.claimed_id and never
 * asked Steam whether it had issued that response. Anyone could open
 * /authenticated.php?openid_claimed_id=.../<any SteamID64> and the portal treated them as
 * that player: their world list, any published password, their Steam name and avatar.
 * (Reported by a community member on Discord, 2026-11-03.)
 *
 * OpenID 2.0 stateless verification (spec section 11.4.2): post the positive assertion back
 * to the provider with openid.mode=check_authentication. Steam answers is_valid:true only for
 * a response it signed, and only ONCE per response_nonce, so a captured assertion cannot be
 * replayed either.
 *
 * The signature alone is not enough, so this also checks what it does not cover:
 *  - the assertion is for THIS site (openid.return_to is our callback),
 *  - the claimed id is a Steam profile URL carrying a 17-digit SteamID64,
 *  - claimed_id and identity agree,
 *  - every field we rely on is in openid.signed, so it is covered by the signature.
 *
 * The HTTP POST is injectable so the decision logic is testable without the network:
 * dev_tools/test-steam-openid-verify.php drives it with a fake Steam.
 */

const PHV_STEAM_OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';
const PHV_STEAM_OPENID_NS       = 'http://specs.openid.net/auth/2.0';

/**
 * The openid.* parameters from the raw query string, names intact.
 *
 * PHP rewrites '.' to '_' in $_GET keys, and the check_authentication request must echo the
 * names exactly as Steam sent them, so $_GET cannot be used for this.
 */
function phvOpenIdParamsFromQuery(string $queryString): array {
    $out = [];
    foreach (explode('&', $queryString) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        $key = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
        $val = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
        if (str_starts_with($key, 'openid.')) {
            $out[$key] = $val;
        }
    }
    return $out;
}

/** The SteamID64 inside a Steam claimed_id, or null when it is not one. */
function phvSteamIdFromClaimedId(string $claimedId): ?string {
    return preg_match('#^https?://steamcommunity\.com/openid/id/([0-9]{17})$#', $claimedId, $m)
        ? $m[1] : null;
}

/** Steam's key:value response body, parsed. */
function phvOpenIdParseKeyValue(string $body): array {
    $out = [];
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $out[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
    }
    return $out;
}

/**
 * Verify a Steam OpenID positive assertion.
 *
 * @param array         $params   the openid.* parameters of the callback (phvOpenIdParamsFromQuery)
 * @param string        $returnTo this site's callback URL, scheme://host/authenticated.php
 * @param callable|null $post     fn(string $url, array $fields): ?string -- the HTTPS POST to
 *                                Steam; null uses phvSteamOpenIdPost(). Injectable for tests.
 * @return string|null  the verified SteamID64, or null when ANYTHING is off. There is
 *                      deliberately no "why": a login page has no business explaining to an
 *                      attacker which check they failed.
 */
function phvSteamOpenIdVerify(array $params, string $returnTo, ?callable $post = null): ?string {
    if (($params['openid.ns'] ?? '') !== PHV_STEAM_OPENID_NS) {
        return null;
    }
    if (($params['openid.mode'] ?? '') !== 'id_res') {
        return null;
    }
    if (($params['openid.op_endpoint'] ?? '') !== PHV_STEAM_OPENID_ENDPOINT) {
        return null;
    }

    $claimed = (string)($params['openid.claimed_id'] ?? '');
    $steamID = phvSteamIdFromClaimedId($claimed);
    if ($steamID === null) {
        return null;
    }
    if (($params['openid.identity'] ?? '') !== $claimed) {
        return null;
    }

    # Ours, and only ours. Steam signs return_to, so a valid assertion minted for another
    # PhValheim server cannot be redeemed here. Compared without any query string: Steam
    # echoes exactly what index.php sent, which carries none.
    $sentTo = explode('?', (string)($params['openid.return_to'] ?? ''), 2)[0];
    if ($sentTo === '' || $sentTo !== explode('?', $returnTo, 2)[0]) {
        return null;
    }

    foreach (['openid.sig', 'openid.signed', 'openid.assoc_handle', 'openid.response_nonce'] as $k) {
        if (empty($params[$k])) {
            return null;
        }
    }
    $signed = explode(',', (string)$params['openid.signed']);
    foreach (['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle'] as $f) {
        if (!in_array($f, $signed, true)) {
            return null;
        }
    }

    $fields = $params;
    $fields['openid.mode'] = 'check_authentication';
    try {
        $body = ($post ?? 'phvSteamOpenIdPost')(PHV_STEAM_OPENID_ENDPOINT, $fields);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_string($body)) {
        return null;
    }
    $answer = phvOpenIdParseKeyValue($body);
    if (($answer['ns'] ?? '') !== PHV_STEAM_OPENID_NS) {
        return null;
    }
    if (($answer['is_valid'] ?? '') !== 'true') {
        return null;
    }
    return $steamID;
}

/** The real POST to Steam. Returns the body on HTTP 200, null on anything else. */
function phvSteamOpenIdPost(string $url, array $fields): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'PhValheim-Server',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return (is_string($body) && $code === 200) ? $body : null;
}
