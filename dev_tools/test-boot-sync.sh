#!/bin/bash
# Oracle test: BOTH catalogues sync on every engine start, not just when the catalogue is empty.
#
# THE BUG THIS CATCHES: the previous seedModCatalogue() only ran when `mods` was empty, so a
# restarted server kept serving whatever the last cron tick left behind -- for up to
# modSyncIntervalHours (default 6). That is invisible: the mod list looks populated and the
# panel shows a successful previous run, so nothing suggests it is stale.
#
# WHY THIS TEST CAN SEE IT: it runs the boot path against a POPULATED catalogue and asserts a
# NEW sync run row appears for each source. A test against an empty catalogue passes under both
# the old and new code, and a test that merely greps for the function call passes under the old
# code too -- the call was always there, it was the guard inside that skipped.
#
# Usage: dev_tools/test-boot-sync.sh [container]
set -uo pipefail
CONTAINER="${1:-phvalheim-dev}"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  PASS  $1"; }
no() { fail=$((fail+1)); echo "  FAIL  $1${2:+ -- $2}"; }
q()  { docker exec "$CONTAINER" mysql -uroot phvalheim -N -e "$1" 2>/dev/null; }

echo
echo "=== boot sync covers both catalogues ($CONTAINER) ==="

# Precondition: the catalogue must be POPULATED, or this test is blind -- an empty one syncs
# under the old guard too.
MODS=$(q "SELECT COUNT(*) FROM mods;")
if [ "${MODS:-0}" -lt 1 ]; then
    echo "  SKIP  catalogue is empty; this test can only see the bug against a populated one"
    exit 0
fi
ok "catalogue is populated ($MODS mods) -- the old empty-only guard would skip"

BEFORE_TS=$(q "SELECT COALESCE(MAX(id),0) FROM mod_sync_runs WHERE source='thunderstore';")
BEFORE_HX=$(q "SELECT COALESCE(MAX(id),0) FROM mod_sync_runs WHERE source='hexium';")

# Run the real boot path, not a reimplementation of it.
docker exec "$CONTAINER" bash -c '
    source /opt/stateless/engine/includes/phvalheim-static.conf
    source /opt/stateless/engine/includes/0-functions.sh
    syncModCatalogue' >/tmp/bootsync.$$ 2>&1
grep -qi "refreshing both mod catalogues" /tmp/bootsync.$$ \
    && ok "boot path announced a refresh of a populated catalogue" \
    || no "no refresh notice" "$(head -2 /tmp/bootsync.$$ | tr '\n' ' ')"

# Wait for both runs to appear and finish. Polling the DB, not sleeping a guessed duration.
for i in $(seq 1 90); do
    AFTER_TS=$(q "SELECT COALESCE(MAX(id),0) FROM mod_sync_runs WHERE source='thunderstore';")
    AFTER_HX=$(q "SELECT COALESCE(MAX(id),0) FROM mod_sync_runs WHERE source='hexium';")
    DONE=$(q "SELECT COUNT(*) FROM mod_sync_runs WHERE id IN ($AFTER_TS,$AFTER_HX) AND status<>'running';")
    [ "$AFTER_TS" -gt "$BEFORE_TS" ] && [ "$AFTER_HX" -gt "$BEFORE_HX" ] && [ "$DONE" = "2" ] && break
    sleep 2
done

[ "${AFTER_TS:-0}" -gt "$BEFORE_TS" ] \
    && ok "a new Thunderstore sync run was created (#$AFTER_TS)" \
    || no "no new Thunderstore run" "still #$BEFORE_TS"
[ "${AFTER_HX:-0}" -gt "$BEFORE_HX" ] \
    && ok "a new Hexium sync run was created (#$AFTER_HX)" \
    || no "no new Hexium run" "still #$BEFORE_HX"

# trigger_kind='boot' is what bypasses modSyncIntervalHours. If the boot path ever passed
# trigger=cron, a recent cron sync would silently skip it and this whole feature would be off.
TRIG=$(q "SELECT GROUP_CONCAT(DISTINCT trigger_kind) FROM mod_sync_runs WHERE id IN (${AFTER_TS:-0},${AFTER_HX:-0});")
[ "$TRIG" = "boot" ] && ok "both runs recorded trigger_kind='boot' (bypasses the interval gate)" \
                     || no "trigger_kind is '$TRIG', expected 'boot'" "cron-triggered runs obey the interval and would skip"

# Both must have SUCCEEDED, not merely started.
# COALESCE, not a bare GROUP_CONCAT: `mysql -N` prints the literal string "NULL" for a NULL
# result, so `[ -z "$BAD" ]` is never true and this assertion fails on a perfectly good sync.
BAD=$(q "SELECT COALESCE(GROUP_CONCAT(CONCAT(source,'=',status)),'') FROM mod_sync_runs
         WHERE id IN (${AFTER_TS:-0},${AFTER_HX:-0}) AND status NOT IN ('ok','unchanged');")
[ -z "$BAD" ] && ok "both boot runs finished successfully" || no "a boot run did not succeed" "$BAD"

# A restart must be CHEAP, or this feature is a tax on every restart. Both runs together
# should land well inside the cold-build time; change detection is what buys that.
SECS=$(q "SELECT COALESCE(ROUND(SUM(TIMESTAMPDIFF(MICROSECOND,started,finished))/1e6,1),999)
          FROM mod_sync_runs WHERE id IN (${AFTER_TS:-0},${AFTER_HX:-0});")
awk -v s="$SECS" 'BEGIN{exit !(s < 30)}' \
    && ok "both catalogues refreshed in ${SECS}s (change detection short-circuited)" \
    || no "boot sync took ${SECS}s; too slow to run on every restart"

# And it must NOT have been a forced full rebuild -- that would make every restart expensive.
FORCED=$(grep -c -- "--force" /tmp/bootsync.$$ 2>/dev/null || true)
grep -q -- "--force" <(docker exec "$CONTAINER" grep -A3 "setsid /opt/stateless/engine/tools/modSync.py" \
    /opt/stateless/engine/includes/0-functions.sh 2>/dev/null) \
    && no "the boot sync passes --force; every restart becomes a full refetch" \
    || ok "the boot sync does not force a full rebuild"

# The catalogue must not have been emptied by any of this.
AFTER_MODS=$(q "SELECT COUNT(*) FROM mods;")
[ "${AFTER_MODS:-0}" -ge 1 ] && ok "catalogue still populated after the boot sync ($AFTER_MODS mods)" \
                             || no "catalogue was emptied by the boot sync"

rm -f /tmp/bootsync.$$
echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
