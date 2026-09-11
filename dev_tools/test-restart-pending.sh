#!/bin/bash
# Oracle test: a live world is described by what it is RUNNING, not by what has been saved.
#
# THE REPORT (2026-09-11): start a vanilla world without crossplay, then enable crossplay. The
# public card lit its CROSSPLAY pill immediately, while the Launch link correctly stayed a
# +connect link -- because the pill read the `crossplay` COLUMN and the link followed the
# running server. One card, two sources of truth, and the pill was advertising a crossplay
# world that Valheim was not serving. The Settings dialog said "restart the world for this to
# take effect" and then closed, leaving the wrong pill as the only lasting evidence.
#
# startWorld.sh now records what it actually launched with in <worldDir>/.running-options, and
# everything describing a LIVE world reads that. A stopped world is still described by its saved
# settings -- there is nothing running to contradict them.
#
# Usage:  dev_tools/test-restart-pending.sh [container] [world]
#   The world MUST be a running vanilla world. Its crossplay/listed columns are restored.

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-test123456}"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

ORIG=$(sql "SELECT CONCAT(IFNULL(crossplay,0),' ',IFNULL(listed,0)) FROM worlds WHERE name='$WORLD'")
ORIG_CROSS=$(echo "$ORIG" | cut -d' ' -f1)
ORIG_LISTED=$(echo "$ORIG" | cut -d' ' -f2)
restore() {
    sql "UPDATE worlds SET crossplay=$ORIG_CROSS, listed=$ORIG_LISTED WHERE name='$WORLD'" >/dev/null 2>&1
}
trap restore EXIT

# Ask the real readers, exactly as the pages do.
probe() {
    docker exec "$CONTAINER" php -r '
        require_once "/opt/stateless/nginx/www/includes/db_gets.php";
        $w = $argv[1];
        $online = trim(shell_exec("pgrep -f -- ".escapeshellarg("-[n]ame ".$w." ")." 2>&1")) !== "";
        $eff = effectiveWorldOptions($pdo, $w, $online);
        $j   = getVanillaJoinInfo($pdo, $w, "example.invalid", 25000, $online);
        printf("%s|%s|%s|%s|%s",
            $online ? "up" : "down",
            $eff["crossplay"], $eff["listed"],
            $j["playfab"] ? "joincode" : "connect",
            implode(",", worldRestartPending($pdo, $w, $online)));
    ' -- "$WORLD" 2>/dev/null
}
field() { echo "$1" | cut -d'|' -f"$2"; }

echo "(container $CONTAINER, world \"$WORLD\")"

r=$(probe)
check "PRECONDITION: the world is up" "$([ "$(field "$r" 1)" = "up" ] && echo 1 || echo 0)" \
    "start it first -- every check below would pass for the wrong reason on a stopped world"
[ "$(field "$r" 1)" = "up" ] || { echo; echo "$pass passed, $fail failed"; exit 1; }

runningOpt() {
    docker exec "$CONTAINER" sh -c \
        "grep '^$1=' /opt/stateful/games/valheim/worlds/$WORLD/.running-options 2>/dev/null | cut -d= -f2"
}
RUNNING_CROSS=$(runningOpt crossplay)
RUNNING_LISTED=$(runningOpt listed)
check "startWorld.sh recorded what it launched with" "$([ -n "$RUNNING_CROSS" ] && echo 1 || echo 0)" \
    "no .running-options -- restart the world once on this build"
[ -n "$RUNNING_CROSS" ] || { echo; echo "$pass passed, $fail failed"; exit 1; }

# Flip the column to the OPPOSITE of what is running, which is the reported case in both
# directions depending on how the world happened to start.
if [ "$RUNNING_CROSS" = "1" ]; then TOGGLED=0; else TOGGLED=1; fi

echo
echo "THE REPORT: change crossplay on a RUNNING world"
sql "UPDATE worlds SET crossplay=$TOGGLED WHERE name='$WORLD'" >/dev/null
r=$(probe)
check "the description still follows the RUNNING world, not the saved column" \
    "$([ "$(field "$r" 2)" = "$RUNNING_CROSS" ] && echo 1 || echo 0)" \
    "effective crossplay=$(field "$r" 2), running=$RUNNING_CROSS"
# The pill and the link come from this one value, so they cannot disagree any more.
WANT_JOIN=$([ "$RUNNING_CROSS" = "1" ] && echo joincode || echo connect)
check "and the Launch link matches it" \
    "$([ "$(field "$r" 4)" = "$WANT_JOIN" ] && echo 1 || echo 0)" \
    "got $(field "$r" 4), wanted $WANT_JOIN"
check "the world is flagged restart-pending, naming crossplay" \
    "$(echo "$(field "$r" 5)" | grep -q crossplay && echo 1 || echo 0)" \
    "pending=[$(field "$r" 5)]"

echo
echo "A second pending change is named too, not folded into one flag"
sql "UPDATE worlds SET listed=1-$ORIG_LISTED WHERE name='$WORLD'" >/dev/null
r=$(probe)
check "both changes are listed" \
    "$(echo "$(field "$r" 5)" | grep -q crossplay && echo "$(field "$r" 5)" | grep -q 'server browser' && echo 1 || echo 0)" \
    "pending=[$(field "$r" 5)]"

echo
echo "CONTROL: matching the saved settings to the running ones clears it"
# Without this, "pending" could simply be stuck on for any world that has ever been edited.
#
# Set them to what is RUNNING, not to what was saved when this script started. Those are not the
# same thing: a world can already be out of step before the test runs -- which is exactly the
# state being fixed -- and restoring the saved values would leave it out of step and fail here
# for the right reason at the wrong moment.
sql "UPDATE worlds SET crossplay=$RUNNING_CROSS, listed=$RUNNING_LISTED WHERE name='$WORLD'" >/dev/null
r=$(probe)
check "nothing pending once saved matches running" \
    "$([ -z "$(field "$r" 5)" ] && echo 1 || echo 0)" "pending=[$(field "$r" 5)]"

echo
echo "The admin table and its poll payload agree with the readers"
sql "UPDATE worlds SET crossplay=$TOGGLED WHERE name='$WORLD'" >/dev/null
badge=$(docker exec "$CONTAINER" php -r '
    $h = @file_get_contents("http://127.0.0.1:8081/index.php");
    $w = preg_quote($argv[1], "/");
    echo preg_match("/world-name\">".$w."<\/span>\s*<span class=\"restart-pending-badge\"/", $h) ? "yes" : "no";
' -- "$WORLD" 2>/dev/null)
check "the Worlds table row carries the badge" "$([ "$badge" = "yes" ] && echo 1 || echo 0)" "got $badge"

payload=$(docker exec "$CONTAINER" php -r '
    $j = json_decode(@file_get_contents("http://127.0.0.1:8081/adminAPI.php?action=getWorlds"), true);
    foreach (($j["worlds"] ?? []) as $x) { if ($x["name"] === $argv[1]) { echo implode(",", $x["restartPending"]); } }
' -- "$WORLD" 2>/dev/null)
# The dashboard re-renders rows from this payload every few seconds. If only the PHP render knew
# about the badge it would appear on load and vanish on the next poll.
check "and so does the poll payload the dashboard re-renders from" \
    "$(echo "$payload" | grep -q crossplay && echo 1 || echo 0)" "restartPending=[$payload]"

echo
echo "The PUBLIC card does not advertise the pending setting"
card=$(docker exec "$CONTAINER" php -r '
    $h = @file_get_contents("http://127.0.0.1:8080/authenticated.php");
    $i = strpos($h, ">".$argv[1]."<");
    if ($i === false) { echo "NOCARD"; exit; }
    $s = strrpos(substr($h, 0, $i), "<div class=");
    $card = substr($h, $s, 4000);
    preg_match_all("/vanilla-badge[^>]*>([^<]+)</", $card, $m);
    echo implode(",", $m[1]);
' -- "$WORLD" 2>/dev/null)
check "the card is rendered at all" "$([ "$card" != "NOCARD" ] && echo 1 || echo 0)" \
    "the bypass steamID must be a citizen of this world"
if [ "$RUNNING_CROSS" = "1" ]; then
    check "a running crossplay world keeps its crossplay pill" \
        "$(echo "$card" | grep -q crossplay && echo 1 || echo 0)" "pills=[$card]"
else
    check "no crossplay pill for a world that is not serving crossplay" \
        "$(echo "$card" | grep -q crossplay && echo 0 || echo 1)" "pills=[$card]"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
