#!/bin/bash
# Oracle: the 2.61 public-UI hardening is in the tree, and the holes it closed are GONE.
#
# Every assertion here is a NEGATIVE where one exists -- "the old thing is absent" -- because
# the positive form ("the new function exists") passes on a tree that also still carries the
# vulnerable path beside it. Each one is anchored on the exact spelling of the code that was
# wrong, not on a comment describing it. The live behaviour (a forged login is refused, the
# alias serves only payloads, injected SQL is refused) is checked against a real container by
# dev_tools/test-public-security-integration.py; this file is the fast tree-level gate.
#
# Run: bash dev_tools/test-public-ui-hardening.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
W="$REPO/container/nginx/www"
pass=0; fail=0
check() { # label, got, want
	if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS  $1 ($2)"
	else fail=$((fail+1)); echo "FAIL  $1 (got $2, want $3)"; fi
}

echo "== Steam login: verified, not trusted"
check "authenticated.php no longer reads the id out of openid_claimed_id" \
	"$(grep -c "explode('/', \$_GET\['openid_claimed_id'\])" "$W/public/authenticated.php")" 0
check "authenticated.php calls the verifier on the callback" \
	"$(grep -c 'phvSteamOpenIdVerify(\$openid, \$returnTo)' "$W/public/authenticated.php")" 1
check "a failed verification lands on the login page, not the portal" \
	"$(grep -c "Location: ../index.php?auth=failed" "$W/public/authenticated.php")" 1
check "the verifier posts check_authentication back to Steam" \
	"$(grep -c "\$fields\['openid.mode'\] = 'check_authentication'" "$W/includes/steam_openid.php")" 1
check "the verifier is the ONLY place check_authentication appears in served code (no second, weaker copy)" \
	"$(grep -rlF "= 'check_authentication'" "$W" | wc -l)" 1
check "the verifier pins the op_endpoint" \
	"$(grep -c "PHV_STEAM_OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login'" "$W/includes/steam_openid.php")" 1
check "storeSessionSteamID refuses anything but a SteamID64" \
	"$(grep -c "preg_match('/^\[0-9\]{17}\$/', \$steamID)" "$W/includes/session_auth.php")" 1

echo "== api.php: no unauthenticated identity"
check "the steamID query-parameter fallback is gone" \
	"$(grep -c "\$_GET\['steamID'\]" "$W/public/api.php")" 0
check "getMyWorldsStatus answers 401 with no session" \
	"$(sed -n '/if ($mode == "getMyWorldsStatus")/,$p' "$W/public/api.php" | grep -c 'http_response_code(401)')" 1
check "a world name is allowlisted before any query runs" \
	"$(grep -c "preg_match('/^\[A-Za-z0-9_-\]{1,64}\$/', (string)\$world)" "$W/public/api.php")" 1

echo "== SQL: bound, never interpolated"
check "db_gets.php has no query with name='\$world' in it" \
	"$(grep -c "name='\$world'" "$W/includes/db_gets.php")" 0
check "db_gets.php has no LIKE '%\$citizen%'" \
	"$(grep -c "LIKE '%\$citizen%'" "$W/includes/db_gets.php")" 0
check "getMyWorlds binds its LIKE and escapes the wildcards" \
	"$(grep -c "addcslashes((string)\$citizen, '%_" "$W/includes/db_gets.php")" 1
check "getBossTrophyStatus guards the column name" \
	"$(grep -c "preg_match('/^\[a-z_\]+\$/', (string)\$trophy)" "$W/includes/db_gets.php")" 1

echo "== output escaping"
check "the Steam persona name is escaped before it is printed" \
	"$(grep -c "\$playerName = htmlspecialchars((string)\$playerName, ENT_QUOTES, 'UTF-8')" "$W/public/authenticated.php")" 1
check "the avatar is only ever an https URL" \
	"$(grep -c "preg_match('#^https://\[A-Za-z0-9./_-\]+\$#', (string)\$steamAvatarURL)" "$W/public/authenticated.php")" 1
check "no card prints the raw world name into markup" \
	"$(grep -cE "data-world=\\\\\"\\\$myWorld\\\\\"|colspan=2>\\\$myWorld</th>" "$W/public/authenticated.php")" 0
check "every card prints the escaped one (2 card kinds x 2 places)" \
	"$(grep -cE "data-world=\\\\\"\\\$myWorldHtml\\\\\"|colspan=2>\\\$myWorldHtml</th>" "$W/public/authenticated.php")" 4

echo "== nginx: the download alias serves payloads only"
C="$REPO/container/nginx/phvalheim.conf"
pub=$(awk '/listen 8080;/{p=1} /listen 8081;/{p=0} p' "$C")
check "the public block no longer aliases the whole games tree" \
	"$(printf '%s\n' "$pub" | grep -c 'alias /opt/stateful/games/;')" 0
check "the public block serves exactly <world>/<world>[-config].zip" \
	"$(printf '%s\n' "$pub" | grep -cF 'location ~ ^/stateful/valheim/worlds/([A-Za-z0-9_-]+)/\1(-config)?\.zip$ {')" 1
check "...and 404s the rest of the prefix" \
	"$(printf '%s\n' "$pub" | grep -A1 'location /stateful/ {' | grep -c 'return 404;')" 1
check "nosniff / frame / referrer headers on the public block" \
	"$(printf '%s\n' "$pub" | grep -cE 'add_header (X-Content-Type-Options nosniff|X-Frame-Options SAMEORIGIN|Referrer-Policy strict-origin-when-cross-origin) always;')" 3
check "server_tokens off" "$(grep -c '^\s*server_tokens off;' "$REPO/container/nginx/nginx.conf")" 1

echo "== session cookie"
check "Secure is set only when the request came over TLS (never unconditionally)" \
	"$(grep -c "ini_set('session.cookie_secure', '1');" "$W/includes/session_auth.php")" 1
check "...guarded by the TLS detector" \
	"$(grep -c "if (\$phvTls) {" "$W/includes/session_auth.php")" 1
check "php.ini does not force cookie_secure for everyone" \
	"$(grep -c '^session.cookie_secure *= *1' "$REPO/container/php-fpm/php.ini")" 0

echo "== login page"
check "jQuery is served locally, not from a CDN pinned to 2.1.3" \
	"$(grep -c 'cdnjs.cloudflare.com/ajax/libs/jquery' "$W/public/index.php")" 0
check "login uses a native Steam POST form" "$(grep -c 'id="authenticated_form" method="post" action="https://steamcommunity.com/openid/login"' "$W/public/index.php")" 1
check "login no longer depends on the legacy modal script" "$(grep -c '/js/login.js' "$W/public/index.php")" 0

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
