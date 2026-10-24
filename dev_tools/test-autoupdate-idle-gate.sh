#!/bin/sh
# Oracle test: an automatic update never restarts a world that has players on it.
#
# THE BUG, from Discord: "players got kicked because phvalheim shutdown the world for an
# update". The idle gate said the world was quiet while someone was playing on it.
#
# HOW it managed that. Valheim exposes no live player count, so playerMonitor scrapes one out
# of the world log. On a crossplay world the only signal is PlayFab's "... now N player(s)",
# which is emitted on join and on leave and AT NO OTHER TIME. A player who joins and then
# plays for two hours leaves exactly one line behind, two hours old, reading 1.
#
# 2.47's isIdle() had two arms:
#     count == 0 and the zero is fresh            -> idle        (correct)
#     count  > 0 and the observation is STALE     -> idle        (the bug)
#
# The second arm was there for a real failure -- a count can stick nonzero after an unclean
# disconnect, and without some such arm one bad teardown parks a world in "waiting" forever.
# But a stale nonzero count has TWO causes that the count cannot tell apart:
#     a) everyone left and the leave was never logged   -> safe
#     b) someone joined and is STILL PLAYING            -> kicks them
# and (b) is not an edge case, it is what an ordinary session looks like. With
# autoupdate_idle_minutes defaulting to 30, any session longer than half an hour qualified.
#
# THE FIX: the arm is gone. A nonzero count is never idle, at any age. This file exists to stop
# it coming back, because the code that reintroduces it will look like a bug fix ("worlds with
# a stuck count never auto-update").
#
# The load-bearing assertion is part 2's: a nonzero count with an ANCIENT observation must come
# back NOT idle. That is the one that fails on 2.47. It is paired with a positive control (a
# fresh zero IS idle) so that "not idle" cannot be passing because the function refuses
# everything, which it easily could and which no silence assertion would notice.
#
# Usage:  sh dev_tools/test-autoupdate-idle-gate.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
APPLIER="$REPO/container/engine/tools/updateApplier"
ADMIN="$REPO/container/nginx/www/admin/index.php"
MIG47="$REPO/container/engine/dbUpdates/dbUpdate_2.47.sh"
BACKLOG="$REPO/docs/BACKLOG.md"

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

IFN=/tmp/phv-isidle.sh
awk '/^isIdle\(\) \{/{f=1} f{print} f&&/^\}$/{exit}' "$APPLIER" > "$IFN"
grep -q 'IFNULL(player_count,0)' "$IFN" \
	|| { echo "FATAL: could not extract isIdle() from $APPLIER"; exit 1; }

# Drive the REAL isIdle().
#   $1 = player_count
#   $2 = 1 if player_count_at IS NOT NULL (an observation exists at all)
#   $3 = 1 if that observation is INSIDE the fresh window (idleMinutes * 3)
#   $4 = 1 if that observation is OLDER than idleMinutes (i.e. "stale")
#
# The two window answers are supplied separately and deliberately. They are what the two
# different SQL comparisons return, and keeping them independent is what lets this harness
# describe a row that is BOTH "not fresh" and "stale" -- an ancient observation -- which is
# exactly the state the bug turned into a green light.
isidle() {
	I_COUNT="$1"; I_HAVE="$2"; I_FRESH="$3"; I_STALE="$4"; ILOG=/tmp/phv-isidle-sql.log
	: > "$ILOG"
	export I_COUNT I_HAVE I_FRESH I_STALE ILOG
	bash -c '
		SQL() {
			printf "%s\n" "$1" >> "$ILOG"
			case "$1" in
				*"IFNULL(player_count,0)"*)        echo "$I_COUNT" ;;
				*"player_count_at IS NOT NULL"*)   echo "$I_HAVE" ;;
				*"player_count_at >"*)             echo "$I_FRESH" ;;
				*"player_count_at <"*)             echo "$I_STALE" ;;
				*) : ;;
			esac
		}
		. "$1"
		if isIdle midgard 30; then echo VERDICT=idle; else echo VERDICT=busy; fi
	' _ "$IFN" 2>/dev/null
}

verdict() { isidle "$@" | grep '^VERDICT=' | cut -d= -f2; }
# The literal path, NOT "$ILOG". verdict() runs isidle() inside $( ), so the assignment
# happens in a subshell and $ILOG is empty back here -- which made this `grep -q ... ""`,
# i.e. "grep: : No such file or directory", and reported the function as never having run.
asked()   { grep -q 'IFNULL(player_count,0)' /tmp/phv-isidle-sql.log; }

echo
echo "== part 1: the positive control -- a fresh zero IS idle =="

# Without this, every "busy" result below could be an isIdle() that just returns 1 always.
v=$(verdict 0 1 1 0)
[ "$v" = "idle" ] \
	&& pass "count=0, observed recently -> idle" \
	|| fail "a recently-observed empty world is not idle ($v)" \
	        "auto-updates would never run at all, and every assertion below is meaningless"

echo
echo "== part 2: THE REGRESSION ASSERTION -- a stale nonzero count is NOT idle =="

# A player joined 2 hours ago and is still playing. One log line, long past idleMinutes.
# This is the exact row that got a real operator's players disconnected.
v=$(verdict 1 1 0 1)
if [ "$v" = "busy" ]; then
	pass "count=1 with an ANCIENT observation -> NOT idle"
else
	fail "a world with 1 player and a stale observation was declared idle" \
	     "this is the 2.47 behaviour: the update proceeds and the player is disconnected mid-session"
fi

# Same shape, more players, and a much older observation. Age must never buy a green light.
v=$(verdict 4 1 0 1)
[ "$v" = "busy" ] \
	&& pass "count=4 with an ancient observation -> NOT idle (age never authorises)" \
	|| fail "4 players were declared idle because the log line was old"

# And the fresh-nonzero case, which was never idle even on 2.47. Kept so the fix cannot be
# "made everything busy by breaking the count read".
v=$(verdict 2 1 1 0)
[ "$v" = "busy" ] \
	&& pass "count=2, freshly observed -> NOT idle" \
	|| fail "a world with 2 players on it was declared idle"

echo
echo "== part 3: unknown is not empty =="

# No observation at all. Must not authorise a restart.
v=$(verdict 0 0 0 0)
[ "$v" = "busy" ] \
	&& pass "no observation at all (player_count_at IS NULL) -> NOT idle" \
	|| fail "a world nothing is known about was declared idle"

# An ANCIENT zero. The log stopped being written, which is not the same as "empty".
v=$(verdict 0 1 0 1)
if asked; then
	[ "$v" = "busy" ] \
		&& pass "count=0 but the zero is ancient -> NOT idle" \
		|| fail "an ancient zero was treated as a current empty world"
else
	fail "cannot judge the ancient-zero case: isIdle never read the count"
fi

echo
echo "== part 4: the staleness arm is really gone from the source =="

# The behavioural assertions above are the real test, but this one names the thing, so a
# future reader sees WHY rather than just a red test. The retired arm returned 0 (idle) off
# the back of a `player_count_at <` comparison.
if awk '/player_count_at </{f=NR} f&&NR>f&&NR<=f+3&&/return 0/{bad=1} END{exit !bad}' "$IFN"; then
	fail "a 'player_count_at <' comparison still leads to 'return 0'" \
	     "that is the staleness arm; a stale nonzero count is being called idle again"
else
	pass "no 'return 0' follows a staleness comparison"
fi

# The sweep must check the force-on-timeout escape hatch BEFORE isIdle, or the operator's
# opt-in to "update even with players on" can never fire now that isIdle refuses.
zForce=$(grep -n 'onTimeout" = "force"' "$APPLIER" | head -1 | cut -d: -f1)
zIdle=$(grep -n 'if ! isIdle' "$APPLIER" | head -1 | cut -d: -f1)
if [ -n "$zForce" ] && [ -n "$zIdle" ]; then
	[ "$zForce" -lt "$zIdle" ] \
		&& pass "the force-on-timeout escape hatch is checked before the idle gate ($zForce < $zIdle)" \
		|| fail "the idle gate runs first ($zIdle < $zForce)" \
		        "isIdle now always refuses a busy world, so max-wait+force could never take effect"
else
	fail "could not locate both the force check and the idle gate" "force=$zForce idle=$zIdle"
fi

echo
echo "== part 5: a held-back world says why =="

grep -q "update_last_result='Waiting:" "$APPLIER" \
	&& pass "a deferred world records a reason an operator can read" \
	|| fail "nothing explains the wait" "'pending' on its own is indistinguishable from a stuck job"

grep -q "player(s) last seen on this world" "$APPLIER" \
	&& pass "and the reason names the player count when that is the cause" \
	|| fail "the deferral message does not distinguish 'players on it' from 'no data'"

echo
echo "== part 6: off by default, and marked Beta =="

# The defaults. Both columns, because getEffective() reads the per-world one whenever
# autoupdate_use_global is not 1 -- so a global default of 0 is only half the answer.
grep -q 'addColumn settings autoUpdateMode "TINYINT DEFAULT 0"' "$MIG47" \
	&& pass "settings.autoUpdateMode defaults to 0 (off)" \
	|| fail "the global auto-update default is not 0"

grep -q 'addColumn worlds autoupdate_mode "TINYINT DEFAULT 0"' "$MIG47" \
	&& pass "worlds.autoupdate_mode defaults to 0 (off)" \
	|| fail "the per-world auto-update default is not 0"

# Nothing may turn it on behind the operator's back. This is the assertion that would catch a
# migration or a setup-wizard default quietly enabling it for everyone.
enablers=$(grep -rEl "autoUpdateMode[ ]*=[ ]*1|autoupdate_mode[ ]*=[ ]*1" \
	"$REPO/container/engine" "$REPO/container/nginx/www/admin" 2>/dev/null \
	| grep -v vendor | grep -v gridphp)
[ -z "$enablers" ] \
	&& pass "nothing in the tree sets auto-update mode to 1" \
	|| fail "something enables auto-updates on its own" "$enablers"

# 2.58: the BETA badges came OFF. The idle decision rests on the Companion's own player roster
# now (playerMonitor reads its file; see test-playerMonitor.sh), so the warning that stood on
# both switch-on points while the count was scraped from the log would be a lie. The two
# assertions below are therefore inverted: neither surface may carry the badge any more.
# The anchoring lesson that follows still holds -- it is why they look at the heading's line.
#
# NOT a count of "BETA" in the file: admin/index.php already renders a BETA pill for any world
# with worlds.beta set, three times, so `grep -c BETA` was 8 before any of this work and would
# have passed on the unbadged UI. The two assertions below are anchored to the two auto-update
# surfaces specifically, which is the claim actually being made.
# SAME LINE, both of them. The first cut set a flag on the heading and then scanned the rest
# of the file for "BETA" -- which admin/index.php contains anyway for the per-world beta pill,
# several hundred lines further down. So it passed with the badge deleted. Verified by
# deleting it: the check still said PASS. The badge markup sits on the heading's own line, so
# that is what to assert on.
grep -q 'Per-World Update Settings.*BETA' "$ADMIN" \
	&& fail "the per-world auto-update panel still carries a BETA badge (2.58 took it off: the count is real)" \
	|| pass "the per-world panel no longer carries the BETA badge (2.58)"

grep -q "sectionHead('Automatic Updates.*BETA" "$ADMIN" \
	&& fail "the global Automatic Updates section still carries a BETA badge (2.58 took it off)" \
	|| pass "the Server Settings section no longer carries the BETA badge (2.58)"

# Both selects must say the default out loud, so "Off" is not mistaken for "someone turned
# this off".
grep -q 'Off (default)' "$ADMIN" \
	&& pass "the selects name Off as the default" \
	|| fail "neither select says Off is the default"

echo
echo "== part 7: the real fix is written down =="

[ -f "$BACKLOG" ] \
	&& pass "docs/BACKLOG.md exists" \
	|| fail "no backlog file" "the Companion-based detection work would be lost"

grep -q 'GetConnectedPeers' "$BACKLOG" \
	&& pass "it names the authoritative source (ZNet GetConnectedPeers)" \
	|| fail "the backlog entry does not say what the real fix is"

grep -qi 'heartbeat, not an event' "$BACKLOG" \
	&& pass "and records the property the log signal lacks (a heartbeat)" \
	|| fail "the backlog entry does not record WHY the current signal fails"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
