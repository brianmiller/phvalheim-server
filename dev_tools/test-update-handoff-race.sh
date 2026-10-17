#!/bin/bash
# Oracle test: an update's mod rebuild is actually requested, and is judged on THIS run.
#
# WHAT HAPPENED. Brian ran Settings -> Updates on 'Midgard'. Five seconds into the mods phase
# the log said "Auto-update: mod rebuild failed". Nothing had failed -- the rebuild was never
# requested at all, and the "failure" was a leftover flag from an unrelated incident hours
# earlier. Two independent defects, either of which alone would have been survivable:
#
#   1. THE RACE. The engine's stop branch loops `sleep 10; worldProcessRunning` and writes
#      mode='stopped' only after a sleep in which the process was already gone -- so it runs
#      for up to 10s past the kill and its LAST act is a mode write. stopWorldAndWait returned
#      as soon as the process vanished (~5s), the applier ran steamcmd (~10s), then set
#      mode='update' for the mods phase, and the engine woke and wrote mode='stopped' over it.
#      Both writes landed in the same second of the real log.
#
#   2. THE STALE VERDICT. waitForEngineUpdate judges the rebuild by worlds.status, and nothing
#      anywhere ever clears that field. Midgard carried status='failed' from before -- read
#      directly from the database eight minutes BEFORE the update that then "failed".
#
# PART 1 IS A REAL RACE, RUN FOR REAL. It starts a stub engine that mimics the stop branch's
# late mode write, against a sqlite stand-in for worlds, and checks whether the applier's
# mode='update' survives. A source-level check cannot see a lost write: both versions of
# stopWorldAndWait contain the same SQL, and the difference is only WHEN they return.
#
# Usage:  bash dev_tools/test-update-handoff-race.sh [--control]

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
REL="container/engine/tools/updateApplier"
F="$REPO/$REL"

CONTROL=0
[ "$1" = "--control" ] && CONTROL=1
if [ "$CONTROL" = "1" ]; then
	F=$(mktemp)
	git -C "$REPO" show "HEAD:$REL" > "$F" 2>/dev/null || { echo "FATAL: cannot read HEAD:$REL"; exit 1; }
	echo "== CONTROL RUN: updateApplier as it is in HEAD. Failures below are the POINT. =="
fi

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== part 1: the engine's late mode write must not eat the update request (real race) =="

# Harness. A FILE holds worlds.mode -- this box has no sqlite3, and the race does not need a
# database: it needs two concurrent writers to one cell, which a file is. SQL() is stubbed to
# understand only the two statement shapes the function under test actually issues, and it
# FAILS LOUDLY on anything else rather than silently returning empty, because a silent no-op
# here would make the fixed version look like it never wrote the mode at all.
runRace() {
	local variant="$1"   # "fixed" uses the extracted stopWorldAndWait; "naive" returns on process death
	local WORK; WORK=$(mktemp -d)

	# Extract the real function so the test cannot drift from the shipped code.
	awk '/^stopWorldAndWait\(\)/,/^}$/' "$F" > "$WORK/fn.sh"
	grep -q 'stopWorldAndWait' "$WORK/fn.sh" || { echo "EXTRACT-FAILED"; rm -rf "$WORK"; return; }

	WORK="$WORK" VARIANT="$variant" bash -c '
		set +e
		MODE="$WORK/mode"; PROC="$WORK/running"; UNKNOWN="$WORK/unknown_sql"
		echo running > "$MODE"
		: > "$PROC"          # the world is "running"

		SQL() {
			case "$1" in
				*"SET mode="*)
					# Pull the value out of SET mode='X'
					printf "%s" "$1" | sed -E "s/.*SET mode=.([a-z]+)..*/\\1/" > "$MODE" ;;
				*"SELECT mode FROM worlds"*) cat "$MODE" ;;
				*) echo "$1" >> "$UNKNOWN" ;;
			esac
		}
		worldProcessRunning() { [ -f "$PROC" ]; }
		date() { echo DATE; }

		# THE STUB ENGINE, mimicking the real stop branch: it kills the process quickly but
		# keeps the pen for several more seconds, then writes mode=stopped LAST.
		(
			sleep 1
			rm -f "$PROC"                 # process gone at t+1
			echo stopping > "$MODE"
			sleep 7                       # the engine is still inside its own sleep
			echo stopped  > "$MODE"       # ...and its LAST act is this write
		) &
		enginePid=$!

		if [ "$VARIANT" = "naive" ]; then
			# The pre-fix shape, inlined: return the moment the process is gone.
			echo stop > "$MODE"
			waited=0
			while [ "$waited" -lt 180 ]; do
				sleep 1; waited=$((waited+1))
				worldProcessRunning || break
			done
		else
			. "$WORK/fn.sh"
			stopWorldAndWait W > /dev/null 2>&1
		fi

		# The applier now hands the rebuild over, exactly as the mods phase does.
		echo update > "$MODE"

		# Give the stub engine time to land its late write, if it still has one pending.
		wait $enginePid 2>/dev/null
		if [ -s "$UNKNOWN" ]; then
			echo "UNKNOWN-SQL=$(head -1 "$UNKNOWN")"
		fi
		echo "FINAL=$(cat "$MODE")"
	'
	rm -rf "$WORK"
}

out=$(runRace fixed)
case "$out" in
	*EXTRACT-FAILED*) fail "could not extract stopWorldAndWait" "extraction is stale" ;;
	*UNKNOWN-SQL*)    fail "the function issued SQL the harness does not model" \
	                       "${out#*UNKNOWN-SQL=}" ;;
	*FINAL=update*)   pass "the update request survives the engine's late mode write" ;;
	*FINAL=stopped*)  fail "the engine's late write ERASED the update request (mode=stopped)" \
	                       "this is the live bug: the rebuild is never requested" ;;
	*)                fail "no verdict from the race harness" "$out" ;;
esac

# THE CONTROL FOR THE RACE. The naive shape must lose the write, or the harness is not
# actually racing anything and part 1 would pass with the fix removed.
out=$(runRace naive)
case "$out" in
	*FINAL=stopped*) pass "and the old return-on-process-death shape DOES lose it -- the race is real" ;;
	*FINAL=update*)  fail "the naive shape also survived" \
	                      "the harness is not reproducing the race; part 1 proves nothing" ;;
	*)               fail "no verdict from the control race" "$out" ;;
esac

echo
echo "== part 2: a stale 'failed' must not condemn a new run =="

code(){ grep -vE '^[[:space:]]*#' "$F"; }

n=$(code | grep -c "clearPriorVerdict")
[ "${n:-0}" -ge 2 ] \
	&& pass "clearPriorVerdict is defined and called ($n references)" \
	|| fail "clearPriorVerdict appears $n time(s) -- want the definition plus at least one call"

# It has to run BEFORE the phases, not after. Positional, because a call in the wrong place
# clears the verdict this run just earned instead of the one it inherited.
cv=$(code | grep -n 'clearPriorVerdict "\$worldName"' | head -1 | cut -d: -f1)
bk=$(code | grep -n 'setPhase "\$worldName" "backup"' | head -1 | cut -d: -f1)
if [ -n "$cv" ] && [ -n "$bk" ]; then
	[ "$cv" -lt "$bk" ] \
		&& pass "it runs before the first phase (clear=$cv, backup=$bk)" \
		|| fail "it runs AFTER the phases start (clear=$cv, backup=$bk)" \
		        "then it would wipe the verdict this run produced"
else
	fail "could not locate the clear call or the first phase" "clear=${cv:-none} backup=${bk:-none}"
fi

code | grep -q "status=''" \
	&& pass "and it actually clears worlds.status, the field the rebuild is judged on" \
	|| fail "status is not cleared" "nothing else in the codebase ever resets it"

echo
echo "== part 2b: clearing the verdict must not erase the LIVE signal =="

# THE REGRESSION THIS PART EXISTS FOR, and part 2 above could not see it. The ordering
# assertion only compared the clear against the first PHASE, which stayed correct the whole
# time. Meanwhile clearPriorVerdict was setting update_state='running' one line after the
# applier set it to 'updating' -- a value no reader anywhere knows.
#
# Cost: the admin UI polls the Updates tab only while
# `update_check_state === 'checking' || update_state === 'updating'`, so it stopped on the
# first tick, and renderUpdateStatus has no 'running' branch, so an update in progress
# rendered as up to date. It also disarmed reconcileStrandedUpdateState, which finds
# interrupted updates by looking for exactly 'updating'. Shipped in a :rc and reported by
# Brian watching a real update.

code | grep -q "clearPriorVerdict" && cvBody=$(awk '/^clearPriorVerdict\(\)/,/^}/' "$F") || cvBody=""
if [ -z "$cvBody" ]; then
	fail "could not extract clearPriorVerdict" "extraction is stale"
elif printf '%s' "$cvBody" | grep -q 'update_state'; then
	fail "clearPriorVerdict writes update_state" \
	     "that column is the live signal for this run; clearing it blinds the UI poll and the stranded sweep"
else
	pass "clearPriorVerdict does not touch update_state -- only the stale verdict fields"
fi

# Positional: the clear must come BEFORE the 'updating' declaration, or it erases it again.
cvLine=$(code | grep -n 'clearPriorVerdict "\$worldName"' | head -1 | cut -d: -f1)
updLine=$(code | grep -n "update_state='updating'" | head -1 | cut -d: -f1)
if [ -n "$cvLine" ] && [ -n "$updLine" ]; then
	[ "$cvLine" -lt "$updLine" ] \
		&& pass "the clear runs before the 'updating' declaration (clear=$cvLine, declare=$updLine)" \
		|| fail "the clear runs AFTER the declaration (clear=$cvLine, declare=$updLine)" \
		        "it would wipe the live signal the UI and the stranded sweep both read"
else
	fail "could not locate both the clear and the declaration" "clear=${cvLine:-none} declare=${updLine:-none}"
fi

# THE NEGATIVE, wants 0 anywhere in the file: 'running' is not a state any reader knows.
n=$(code | grep -c "update_state='running'")
[ "${n:-0}" = "0" ] \
	&& pass "no update_state='running' anywhere ($n) -- nothing reads that value" \
	|| fail "$n write(s) of update_state='running' remain" \
	        "the UI has no branch for it and falls through to 'up to date'"

# And the value the UI DOES watch has to be the one that gets written.
code | grep -q "update_state='updating'" \
	&& pass "the applier declares the state the UI actually polls on ('updating')" \
	|| fail "nothing sets update_state='updating'" "the tab can never show a live update"

echo
echo "== part 3: 'never started' must not read as 'finished' =="

code | grep -q 'sawRebuild' \
	&& pass "waitForEngineUpdate tracks whether the rebuild was ever observed running" \
	|| fail "no sawRebuild guard" "a pre-existing terminal mode exits the wait on the first poll"

code | grep -q 'never started' \
	&& pass "and says so plainly when the request was overwritten" \
	|| fail "an unstarted rebuild is still reported as a failed one"

# The negative: the bare break that caused it must be gone.
bare=$(code | grep -cE '^\s+\*\)\s+break\s*;;')
[ "${bare:-0}" = "0" ] \
	&& pass "the unguarded 'break on any other mode' is gone ($bare)" \
	|| fail "$bare unguarded break(s) remain in a mode wait"

echo
echo "== part 4: the stop waits on the engine, not just the process =="

code | grep -q 'mode" = "stopped" \] && ! worldProcessRunning' \
	&& pass "stopWorldAndWait requires BOTH mode=stopped and the process gone" \
	|| fail "the stop does not wait for the engine's mode write" \
	        "returning early is what let the engine clobber mode='update'"

echo
echo "$PASS passed, $FAIL failed"
[ "$CONTROL" = "1" ] && rm -f "$F"
[ "$FAIL" -eq 0 ] || exit 1
