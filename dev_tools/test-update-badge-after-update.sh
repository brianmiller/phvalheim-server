#!/bin/bash
# Oracle test: updating a world recomputes its "update available" badge, and the badge names
# the mods (2.58).
# requires: container
#
# WHAT IT GUARDS. The maintainer updated a world and its yellow "1" badge stayed. The flags
# behind it (update_available_game / update_available_mods) are written ONLY by
# updateChecker.py, which cron runs every six hours for RUNNING worlds -- so a world updated by
# hand, which is stopped, kept a stale badge until it next ran and happened to be checked. The
# engine's update block now runs the checker for that one world as soon as the update ends.
# And the badge said "1" with no idea WHICH mod: the checker now records the names.
#
# HOW. A real engine update of the fixture world (mode='update' is the same command the Update
# button and the mod-list save issue), with the flags and a fake named mod planted first. The
# planted names are the CONTROL: the dashboard must show them before the update (so the test
# can see its own stimulus) and must not show them after, because the checker rewrote the list
# from what is actually on disk.
#
# Usage:  dev_tools/test-update-badge-after-update.sh [container] [stoppedModdedWorld]

C="${1:-phvalheim-dev}"
W="${2:-midgard}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "$2" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec "$C" mysql -N -e "$1" phvalheim 2>/dev/null; }
page() { docker exec "$C" php -r 'echo @file_get_contents("http://127.0.0.1:8081/index.php");' 2>/dev/null; }
# The badge markup for ONE world's row, from the served page.
rowBadge() { page | python3 -c '
import sys,re
html=sys.stdin.read(); w=sys.argv[1]
m=re.search(r"<tr data-world=\"%s\"[^>]*>(.*?)</tr>" % re.escape(w), html, re.S)
if not m: print("NOROW"); sys.exit()
b=re.search(r"<span class=\"update-badge\" title=\"([^\"]*)\">([^<]*)</span>", m.group(1))
print("NOBADGE" if not b else b.group(2).strip()+"|"+b.group(1).replace("\n"," / "))' "$1"; }

echo "=== update badge after a world update ($C, world $W) ==="

mode=$(sql "SELECT mode FROM worlds WHERE name='$W'")
vanilla=$(sql "SELECT IFNULL(vanilla,0) FROM worlds WHERE name='$W'")
[ "$mode" = "stopped" ] && [ "$vanilla" = "0" ] || { echo "HARNESS: $W must be a stopped modded world (mode=$mode vanilla=$vanilla)"; exit 2; }
# A modded world with NO mods selected cannot be updated: the engine refuses to publish it
# ("selected mods but NO plugins were installed") and the update ends 'failed' before the
# re-check runs. That is the engine's rule, not what this test is about -- the first run of
# this used such a world and reported five failures that were all that one precondition.
nmods=$(sql "SELECT COUNT(*) FROM world_mods WHERE world_id=(SELECT id FROM worlds WHERE name='$W')")
[ "${nmods:-0}" -ge 1 ] || { echo "HARNESS: $W has no mods selected; an update of it fails by design. Pass a world with at least one mod."; exit 2; }
docker exec "$C" supervisorctl status phvalheim | grep -q RUNNING || { echo "HARNESS: the engine is not running"; exit 2; }

# The stimulus: an update waiting, with a mod nothing on this server has.
sql "UPDATE worlds SET update_available_game=1, update_available_mods=2, update_state='idle', update_mods_list='Oracle-PlantedMod 1.0.0 -> 9.9.9\nOracle-OtherPlant 0.1 -> 0.2', update_checked_at=NULL WHERE name='$W'"
before=$(rowBadge "$W")
case "$before" in 3\|*Oracle-PlantedMod*9.9.9*) ok "control: the dashboard shows the planted badge \"3\" naming Oracle-PlantedMod (the stimulus is visible)" ;;
	*) bad "control: the dashboard shows the planted badge naming the mod" "$before" ;; esac

priorOp=$(sql "SELECT COALESCE(MAX(started),'1970-01-01 00:00:00.000') FROM phv_operations WHERE world='$W' AND kind='update'")
t0=$(sql "SELECT NOW()")
sql "UPDATE worlds SET mode='update' WHERE name='$W'"
echo "  (engine update of $W started at $t0; waiting for it to end)"
for i in $(seq 1 180); do
	sleep 5
	m=$(sql "SELECT mode FROM worlds WHERE name='$W'")
	[ "$m" = "stopped" ] && break
done
st=$(sql "SELECT mode FROM worlds WHERE name='$W'")
# status is the world's historical process status; a stopped-world rebuild can leave an
# earlier failed process status intact. The new operation row records THIS update's result.
op=''
for j in $(seq 1 12); do
    op=$(sql "SELECT CONCAT(outcome,'|',COALESCE(summary,'')) FROM phv_operations WHERE world='$W' AND kind='update' AND started>'$priorOp' AND finished IS NOT NULL ORDER BY started DESC LIMIT 1")
    [ -n "$op" ] && break
    sleep 1
done
case "$st|$op" in
    stopped\|ok\|*) ok "the new update operation completed successfully with the world stopped (${i}x5s)" ;;
    *) bad "the world update ended cleanly" "mode=$st operation=${op:-no completed update operation}" ;;
esac

# The re-check is backgrounded; give it a minute (steamcmd app_info_print, cached after the update).
for i in $(seq 1 24); do
	at=$(sql "SELECT IFNULL(update_checked_at,'') FROM worlds WHERE name='$W'")
	[ -n "$at" ] && break
	sleep 5
done
[ -n "$at" ] && ok "updateChecker ran for this world after the update (update_checked_at=$at)" \
	|| bad "updateChecker ran for this world after the update" "update_checked_at still NULL 120s after the update ended"

list=$(sql "SELECT IFNULL(update_mods_list,'') FROM worlds WHERE name='$W'")
case "$list" in *Oracle-PlantedMod*) bad "the planted mod is gone from the named list" "list=$list" ;;
	*) ok "the planted mod is gone from the named list (rewritten from what is on disk)" ;; esac

cnt=$(sql "SELECT IFNULL(update_available_mods,-1), IFNULL(update_available_game,-1), IFNULL(update_mods_error,''), IFNULL(update_check_error,'') FROM worlds WHERE name='$W'" | tr '\t' '|')
mods=${cnt%%|*}
# Every unpinned mod was just installed at its latest version, so the honest count is 0. A
# nonzero here with an empty error means the checker compared something real and found it
# behind -- which after a rebuild is a product bug, not a test artefact.
[ "$mods" = "0" ] && ok "update_available_mods is 0 right after a rebuild" || bad "update_available_mods is 0 right after a rebuild" "row=$cnt"

after=$(rowBadge "$W")
case "$after" in NOBADGE) ok "the dashboard shows no badge for $W after the update" ;;
	*Oracle-PlantedMod*) bad "the stale badge is gone" "still: $after" ;;
	*) echo "  NOTE  a badge remains with a real reason: $after"; [ "$mods" = "0" ] && bad "the dashboard shows no badge after the update" "$after" || ok "the badge that remains names a real update ($after)" ;; esac

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
