#!/bin/bash
# Oracle test: a world whose access list is ENABLED but EMPTY must say so at every start.
#
# THE CONDITION (production, 2026-09-10): worlds.public=0 ("Use Access List: on") with no
# citizens. Valheim only applies permittedlist.txt when it has ENTRIES, so an empty one is no
# restriction at all -- the world is open while its Access tab claims it is restricted.
#
# saveCitizensJson() refuses to CREATE that state, but nothing re-saves an existing world, so
# worlds already in it stay open and stay silent. syncAccessLists.sh runs at every world start
# and is the only code that can see the condition, so it warns.
#
# WHAT MAKES THIS AN ORACLE: cases 2 and 3 assert the warning is ABSENT. A script that simply
# printed the warning unconditionally would pass case 1 and 4 while being useless -- an alarm
# that is always on tells an operator nothing. Case 5 checks the file on disk actually matches
# what the warning claims, so the test fails if the warning becomes a lie.
#
# Usage:  dev_tools/test-accesslist-empty-warning.sh [container] [world]
#   The world's access state is restored on exit.

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-test2}"
SCRIPT=/opt/stateless/games/valheim/scripts/syncAccessLists.sh
SAVEDIR="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }
setState() { sql "UPDATE worlds SET public=$1, citizens=$2 WHERE name='$WORLD'"; }
runSync() { docker exec "$CONTAINER" "$SCRIPT" "$WORLD" 2>&1; }
# The entries, not the header comment Valheim writes at the top of the file.
# NO `|| echo 0` fallback here: grep -c already PRINTS 0 when nothing matches, and exits 1
# while doing it, so the fallback fired on the empty case and produced "00".
# An absent file prints nothing, which fails the comparison -- correct, that is a real fault.
listEntries() {
    docker exec "$CONTAINER" sh -c "grep -c '^[^/]' '$SAVEDIR/permittedlist.txt' 2>/dev/null; true"
}

# Save the original state so a real world is not left altered by a test run.
ORIG=$(sql "SELECT IFNULL(public,0), IFNULL(CONCAT('@@',citizens),'NULL') FROM worlds WHERE name='$WORLD'")
ORIG_PUBLIC=$(echo "$ORIG" | cut -f1)
ORIG_CITIZENS=$(echo "$ORIG" | cut -f2)
if [ "$ORIG_CITIZENS" = "NULL" ]; then ORIG_CITIZENS_SQL="NULL"
else ORIG_CITIZENS_SQL="'${ORIG_CITIZENS#@@}'"; fi
restore() { setState "$ORIG_PUBLIC" "$ORIG_CITIZENS_SQL" >/dev/null 2>&1; }
trap restore EXIT

echo "(container $CONTAINER, world \"$WORLD\")"
# Match the CONDITION the warning names, not its consequence clause. The wording of the
# consequence has already changed once ("ANYONE CAN JOIN" -> "NOBODY can join") when the
# sentinel landed, and a test keyed to it silently stopped matching -- which made the
# must-NOT-warn cases pass vacuously, since a string that never matches is always "silent".
WARN='access list ENABLED but EMPTY'

echo
echo "Case 1: list ENABLED, EMPTY -- must warn"
setState 0 "''" >/dev/null
out=$(runSync)
echo "$out" | grep -q "$WARN"; check "warns" "$([ $? -eq 0 ] && echo 1 || echo 0)"
echo "$out" | grep -q "WARNING"; check "logged at WARNING, not NOTICE" "$([ $? -eq 0 ] && echo 1 || echo 0)"
echo "$out" | grep -qi "Use Access List"; check "names the fix" "$([ $? -eq 0 ] && echo 1 || echo 0)"

echo
echo "Case 2: list ENABLED with a real id -- must NOT warn"
# An always-on alarm would pass every other case in this file. This is what rules that out.
setState 0 "'76561197960287930'" >/dev/null
out=$(runSync)
echo "$out" | grep -q "$WARN"; check "silent" "$([ $? -ne 0 ] && echo 1 || echo 0)" "warned on a populated list"

echo
echo "Case 3: list OFF, empty -- deliberately open, must NOT warn"
# Running an open world is supported. Warning here would train operators to ignore the warning.
setState 1 "''" >/dev/null
out=$(runSync)
echo "$out" | grep -q "$WARN"; check "silent" "$([ $? -ne 0 ] && echo 1 || echo 0)" "warned on a deliberately open world"

echo
echo "Case 4: whitespace-only citizens is still empty -- must warn"
# Sails straight past a naive [ -z "$citizens" ] check.
setState 0 "'   \n  '" >/dev/null
out=$(runSync)
echo "$out" | grep -q "$WARN"; check "warns" "$([ $? -eq 0 ] && echo 1 || echo 0)"

echo
echo "Case 5: the warning tells the truth -- the list is CLOSED, not empty"
# This case previously asserted ZERO entries, back when an enforced-empty list really did
# render empty and the warning said "ANYONE CAN JOIN". The sentinel changed that: the file now
# carries exactly one unassignable entry, which is what makes Valheim enforce it at all.
# The assertion was updated deliberately -- if it had been left alone it would have failed,
# which is the point of tying it to the bytes on disk rather than to the log text.
SENTINEL="V_76561197960265728"
setState 0 "''" >/dev/null
runSync >/dev/null
n=$(listEntries | tr -d '[:space:]')
check "exactly 1 entry (the placeholder) when we warn" "$([ "$n" = "1" ] && echo 1 || echo 0)" "found $n"
docker exec "$CONTAINER" grep -q "^$SENTINEL\$" "$SAVEDIR/permittedlist.txt"
check "and that entry IS the placeholder" "$([ $? -eq 0 ] && echo 1 || echo 0)"

setState 0 "'76561197960287930'" >/dev/null
runSync >/dev/null
n=$(listEntries | tr -d '[:space:]')
check "1 entry when we stay silent" "$([ "$n" = "1" ] && echo 1 || echo 0)" "found $n"

echo
echo "Case 6: a real player's list must NOT get the placeholder"
# The placeholder exists only to stop an empty list meaning "open". Once a real ID is present
# Valheim already enforces, and an extra entry would be unexplained cruft in the operator's file.
docker exec "$CONTAINER" grep -q "$SENTINEL" "$SAVEDIR/permittedlist.txt"
check "absent when the operator has entries" "$([ $? -ne 0 ] && echo 1 || echo 0)" "placeholder leaked into a real list"

echo
echo "Case 7: a deliberately OPEN world must NOT get the placeholder"
# THE MOST IMPORTANT CASE. If the placeholder leaked in here it would silently lock out every
# player on a world the operator deliberately opened -- turning a safety fix into an outage.
setState 1 "''" >/dev/null
runSync >/dev/null
n=$(listEntries | tr -d '[:space:]')
check "open world renders 0 entries" "$([ "$n" = "0" ] && echo 1 || echo 0)" "found $n -- an open world was locked"

echo
echo "Case 8: admin and banned lists never get the placeholder"
# An empty admin list ("no admins") and an empty banned list ("nobody banned") are both correct
# and safe. Forcing an entry into either would invent access rules nobody asked for.
setState 0 "''" >/dev/null
runSync >/dev/null
for f in adminlist bannedlist; do
    docker exec "$CONTAINER" grep -q "$SENTINEL" "$SAVEDIR/$f.txt"
    check "$f.txt is clean" "$([ $? -ne 0 ] && echo 1 || echo 0)"
done

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
