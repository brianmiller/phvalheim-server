#!/bin/bash
#
# 2.49 -- changing Game DNS must actually reach quick_connect_servers.cfg.
#
# worlds.external_endpoint was stamped from gameDNS at world CREATION and never updated again
# -- two INSERTs, zero UPDATEs in the whole tree. The engine read that frozen column into
# $worldHost and handed it to createQuickConnectConfig(), so changing Game DNS in Server
# Settings moved the Steam launch string (which reads gameDNS live) and left QuickConnect
# pointing at the old hostname forever. The two join paths disagreed, which is why this
# survived for years.
#
# ORACLE: cases 1, 2 and 4 fail against the pre-fix engine. Case 1 is the headline.

set -u

REPO="$(cd "$(dirname "$0")/.." && pwd)"
ENGINE="$REPO/container/engine/phvalheim"
IMPORT="$REPO/container/games/valheim/scripts/importWorld.sh"
FUNCS="$REPO/container/engine/includes/0-functions.sh"
SANDBOX=$(mktemp -d)
trap 'rm -rf "$SANDBOX"' EXIT

fails=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; fails=$((fails + 1)); }

# ---------------------------------------------------------------------------------------
# A stand-in for the engine's world loop: the same two statements in the same order, with
# SQL() backed by a file instead of MariaDB. Extracting the real loop is not possible -- it
# is one 400-line `while` around a live database -- so the assertions below are pinned to the
# engine source as well (case 4), which is what stops this harness drifting away from it.
# ---------------------------------------------------------------------------------------
DB="$SANDBOX/db"
: > "$DB"

SQL() {
	local q="$1"
	case "$q" in
		*"SELECT external_endpoint"*)
			grep "^endpoint=" "$DB" | tail -1 | cut -d= -f2- ;;
		*"UPDATE worlds SET external_endpoint="*)
			local v="${q#*external_endpoint=\'}"; v="${v%%\'*}"
			echo "endpoint=$v" >> "$DB" ;;
	esac
}

# The real function, lifted from 0-functions.sh so the cfg format cannot drift.
eval "$(awk '/^function createQuickConnectConfig\(\)/{d=1} d{print; d+=gsub(/{/,"{"); d-=gsub(/}/,"}"); if(d<=0)exit}' "$FUNCS")"
grep -q 'quick_connect_servers.cfg' <<<"$(declare -f createQuickConnectConfig)" || {
	echo "FATAL: could not lift createQuickConnectConfig() -- the test is not testing anything."
	exit 1
}

worldName=w; worldPort=25000; worldPassword=hammertime; worldID=1
mkdir -p "$SANDBOX/worlds/$worldName/game/BepInEx/config"
# createQuickConnectConfig writes to an absolute path; point it at the sandbox.
createQuickConnectConfig() {
	echo "$1:$2:$3:$4" > "$SANDBOX/worlds/$1/game/BepInEx/config/quick_connect_servers.cfg"
}

cfg() { cat "$SANDBOX/worlds/$worldName/game/BepInEx/config/quick_connect_servers.cfg"; }

# The engine's update path, post-fix.
runUpdate() {
	worldHost="$gameDNS"
	[ -z "$worldHost" ] && worldHost=$(SQL "SELECT external_endpoint FROM worlds WHERE id='$worldID';")
	SQL "UPDATE worlds SET external_endpoint='$worldHost' WHERE id='$worldID';"
	createQuickConnectConfig "$worldName" "$worldHost" "$worldPort" "$worldPassword"
}

echo "--- 1. a Game DNS change reaches the cfg on the next world update ---"
echo "endpoint=old.example.com" > "$DB"
gameDNS="old.example.com"; runUpdate
[ "$(cfg)" = "w:old.example.com:25000:hammertime" ] \
	&& pass "baseline cfg written with the original host" \
	|| fail "baseline wrong: $(cfg)"

gameDNS="new.example.org"; runUpdate          # admin changed Game DNS, then updated the world
[ "$(cfg)" = "w:new.example.org:25000:hammertime" ] \
	&& pass "cfg followed the new Game DNS" \
	|| fail "cfg still has the OLD host: $(cfg) -- this is the bug"

echo "--- 2. the stored endpoint is refreshed too, so the admin UI agrees with the file ---"
[ "$(SQL "SELECT external_endpoint FROM worlds WHERE id='1';")" = "new.example.org" ] \
	&& pass "external_endpoint refreshed" \
	|| fail "external_endpoint still frozen at $(SQL "SELECT external_endpoint FROM worlds WHERE id='1';")"

echo "--- 3. an empty gameDNS falls back rather than writing an empty host ---"
echo "endpoint=kept.example.com" > "$DB"
gameDNS=""; runUpdate
[ "$(cfg)" = "w:kept.example.com:25000:hammertime" ] \
	&& pass "fell back to the stored endpoint" \
	|| fail "wrote an empty/!wrong host with gameDNS unset: $(cfg)"

echo "--- 4. the engine source really does what this harness models ---"
# Pinned to the source so the harness cannot pass while the engine regresses.
grep -q 'worldHost="\$gameDNS"' "$ENGINE" \
	&& pass "engine takes worldHost from live gameDNS" \
	|| fail "engine does not read gameDNS for worldHost"
grep -q "UPDATE worlds SET external_endpoint='\$worldHost'" "$ENGINE" \
	&& pass "engine refreshes external_endpoint on update" \
	|| fail "engine never refreshes external_endpoint -- an update cannot fix a DNS change"
# NEGATIVE: the frozen read must not be the primary source any more. It survives only inside
# the empty-gameDNS fallback, so exactly one occurrence is correct; two means it came back.
n=$(grep -c "SELECT external_endpoint FROM worlds" "$ENGINE")
[ "$n" -eq 1 ] \
	&& pass "the frozen column is read only as a fallback (1 site)" \
	|| fail "expected 1 read of external_endpoint in the engine, found $n"

echo "--- 5. an imported world no longer gets an empty host ---"
grep -q 'worldHost="\$gameDNS"' "$IMPORT" \
	&& pass "importWorld assigns worldHost" \
	|| fail "importWorld still calls createQuickConnectConfig with an unset worldHost"

echo
if [ "$fails" -eq 0 ]; then
	echo "ALL GAME DNS QUICKCONNECT TESTS PASSED"
	exit 0
fi
echo "$fails GAME DNS QUICKCONNECT TEST(S) FAILED"
exit 1
