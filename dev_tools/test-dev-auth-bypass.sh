#!/bin/bash
# Oracle test: the development Steam-auth bypass is off unless a container env var says otherwise.
#
# WHY IT EXISTS: the public world-card layout was reworked four times without the real page ever
# being loaded -- every attempt was measured against synthetic markup in a test that built its
# own cards, and every attempt was wrong. Loading authenticated.php needs a Steam login, so the
# bypass makes the real page reachable for measurement (dev_tools/probe-public-cards.js).
#
# WHY IT IS SAFE, and what this file is actually guarding -- this is a PUBLIC repo and the flag
# would be a full authentication bypass if any of these stopped holding:
#
#   1. The value is read ONLY from getenv(). Not the settings table, not $_GET/$_POST/$_REQUEST,
#      not a header, not a cookie, not the admin UI. Setting an env var means you already ran
#      the container.
#   2. It must be exactly 17 digits. No "1", no "true", no SQL, no path.
#   3. Nothing we ship ever sets it -- Dockerfile, compose, Helm, README, the dev build scripts.
#   4. When it is on, the page says so in red across the top.
#
# Usage:  dev_tools/test-dev-auth-bypass.sh [container]

CONTAINER="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO/container/nginx/www/includes/session_auth.php"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

# Run a snippet against the REAL file, with the env var set to $1 ("" = unset).
ask() {
    local val="$1" expr="$2"
    if [ -z "$val" ]; then
        env -u phvalheimDevSteamID php -r "include '$SRC'; $expr" 2>/dev/null
    else
        phvalheimDevSteamID="$val" php -r "include '$SRC'; $expr" 2>/dev/null
    fi
}

# session_auth.php includes config_env_puller.php by absolute container path, so the behavioural
# half runs in the container. The source half runs against the repo.
cask() {
    local val="$1" expr="$2"
    if [ -z "$val" ]; then
        docker exec -e phvalheimDevSteamID= "$CONTAINER" php -r \
            "include '/opt/stateless/nginx/www/includes/session_auth.php'; $expr" 2>/dev/null
    else
        docker exec -e phvalheimDevSteamID="$val" "$CONTAINER" php -r \
            "include '/opt/stateless/nginx/www/includes/session_auth.php'; $expr" 2>/dev/null
    fi
}

echo "(container $CONTAINER)"
echo
echo "OFF by default"
check "unset -> phvDevSteamID() is NULL" \
    "$([ "$(cask '' 'var_dump(phvDevSteamID()===NULL);')" = "bool(true)" ] && echo 1 || echo 0)" \
    "got $(cask '' 'var_dump(phvDevSteamID());')"
check "unset -> isSessionValid() is false with no session" \
    "$([ "$(cask '' 'var_dump(isSessionValid());')" = "bool(false)" ] && echo 1 || echo 0)"
check "unset -> getSessionSteamID() is NULL" \
    "$([ "$(cask '' 'var_dump(getSessionSteamID()===NULL);')" = "bool(true)" ] && echo 1 || echo 0)"

echo
echo "ON only for a well-formed SteamID64"
check "17 digits -> that exact id" \
    "$([ "$(cask 76561198000000001 'echo getSessionSteamID();')" = "76561198000000001" ] && echo 1 || echo 0)" \
    "got $(cask 76561198000000001 'echo getSessionSteamID();')"
check "17 digits -> isSessionValid() true" \
    "$([ "$(cask 76561198000000001 'var_dump(isSessionValid());')" = "bool(true)" ] && echo 1 || echo 0)"

# A truthy-but-junk value must not authenticate anyone. "1" and "true" are what a careless
# operator would set if they thought this were a boolean switch.
for bad in 1 true yes on 0 76561198 765611980000000012 " 76561198000000001" "76561198000000001; DROP" "../../etc/passwd" "7656119800000000a"; do
    got=$(cask "$bad" 'var_dump(getSessionSteamID()===NULL);')
    check "rejected: '$bad'" "$([ "$got" = "bool(true)" ] && echo 1 || echo 0)" "got $got"
done

echo
echo "The value can come from NOWHERE but the environment"
body=$(awk '/function phvDevSteamID/,/^}/' "$SRC")
check "phvDevSteamID() calls getenv()" \
    "$(echo "$body" | grep -q 'getenv(' && echo 1 || echo 0)"
for src in '_GET' '_POST' '_REQUEST' '_COOKIE' '_SERVER' '_SESSION' 'apache_request_headers' 'settings'; do
    check "and never reads \$$src" \
        "$(echo "$body" | grep -q "$src" && echo 0 || echo 1)"
done
# Anchored: 17 digits and nothing else. A missing ^ or $ is the classic hole here.
check "the pattern is anchored to exactly 17 digits" \
    "$(echo "$body" | grep -q "\^\[0-9\]{17}\\\$" && echo 1 || echo 0)"

echo
echo "Nothing we ship turns it on"
# container/ is what goes into the image. The only two files there may READ it; none may SET it.
# Anything else under container/ naming it at all is a leak.
strays=$(grep -rl 'phvalheimDevSteamID' "$REPO/container" 2>/dev/null \
        | grep -v '/includes/session_auth.php$' \
        | grep -v '/public/authenticated.php$')
check "no file in container/ references it but the two that read it" \
    "$([ -z "$strays" ] && echo 1 || echo 0)" "found in: $strays"
# They may PRINT the name (the banner does). They must never write the environment -- putenv()
# or a $_ENV/$_SERVER assignment would let any other code path switch the bypass on.
check "and neither of those two writes the environment" \
    "$(grep -hE 'putenv[[:space:]]*\(|\$_(ENV|SERVER)\[[^]]*\][[:space:]]*=[^=]' \
        "$REPO/container/nginx/www/includes/session_auth.php" \
        "$REPO/container/nginx/www/public/authenticated.php" 2>/dev/null \
        | grep -q . && echo 0 || echo 1)"
# The deployment descriptors an operator actually runs.
for f in Dockerfile docker-compose.yml docker-compose.yaml helm/phvalheim/values.yaml README.md; do
    [ -f "$REPO/$f" ] || continue
    check "$f does not mention it" \
        "$(grep -q 'phvalheimDevSteamID' "$REPO/$f" && echo 0 || echo 1)"
done
# dev_tools/devUp.sh sets it on purpose -- that is fine ONLY because dev_tools never ships.
check "dev_tools/ is not copied into the image" \
    "$(grep -qE '^[[:space:]]*(COPY|ADD).*dev_tools' "$REPO/Dockerfile" && echo 0 || echo 1)"

echo
echo "A bypassed page announces itself"
check "authenticated.php renders the red banner when it is on" \
    "$(grep -q 'STEAM AUTH BYPASSED' "$REPO/container/nginx/www/public/authenticated.php" && echo 1 || echo 0)"
check "and the banner is gated on phvDevSteamID()" \
    "$(grep -B4 'STEAM AUTH BYPASSED' "$REPO/container/nginx/www/public/authenticated.php" | grep -q 'phvDevSteamID() !== NULL' && echo 1 || echo 0)"

echo
echo "CONTROL: the checks above can actually fail"
# If the container were running a build without the bypass, every "OFF by default" check would
# pass for the wrong reason -- the function would not exist at all.
check "phvDevSteamID() exists in the running container" \
    "$([ "$(cask '' 'var_dump(function_exists("phvDevSteamID"));')" = "bool(true)" ] && echo 1 || echo 0)" \
    "the container predates the bypass; the OFF results above are meaningless"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
