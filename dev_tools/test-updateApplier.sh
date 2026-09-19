#!/bin/bash
#
# Oracle tests for updateApplier's two gates.
#
# This is the only code in PhValheim that stops a server nobody asked it to stop, so the
# gates get tested rather than reasoned about. Every case below is one where a plausible
# wrong implementation gives a different answer than the right one.
#
# isIdle() needs a database, so those cases run against the dev container's MySQL using a
# scratch world row that is created and dropped by the test. Nothing else is touched.
#
# Run: dev_tools/test-updateApplier.sh [container]

set -u

CONTAINER="${1:-phvalheim-dev}"
TOOL="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/updateApplier"
SCRATCH="zz_updateapplier_test"

pass=0
fail=0

check() {
	local name="$1" expected="$2" actual="$3"
	if [ "$actual" = "$expected" ]; then
		echo "  PASS  $name"
		pass=$((pass + 1))
	else
		echo "  FAIL  $name"
		echo "          expected: $expected"
		echo "          actual:   $actual"
		fail=$((fail + 1))
	fi
}

echo "updateApplier gate tests"
echo

# --- inWindow: pure, runs locally ----------------------------------------------------
#
# Extracted rather than sourced: the tool sources the container's config and 0-functions.sh
# at the top, neither of which exists here.
sed -n '/^inWindow() {/,/^}/p' "$TOOL" > /tmp/inwindow.fn

runWindow() {
	local start="$1" hours="$2" hour="$3"
	# shellcheck disable=SC2016
	bash -c '
		date() { echo "'"$hour"'"; }
		local_stub=1
		'"$(cat /tmp/inwindow.fn)"'
		if inWindow "'"$start"'" "'"$hours"'"; then echo OPEN; else echo SHUT; fi
	' 2>/dev/null
}

check "start -1 means any time"            "OPEN" "$(runWindow -1 0 13)"
check "empty start means any time"         "OPEN" "$(runWindow '' '' 13)"
check "zero-length window means any time"  "OPEN" "$(runWindow 2 0 13)"
check "inside a plain window (2..6, now 3)"    "OPEN" "$(runWindow 2 4 3)"
check "outside a plain window (2..6, now 8)"   "SHUT" "$(runWindow 2 4 8)"
check "at the start hour is inside"            "OPEN" "$(runWindow 2 4 2)"
check "at the end hour is outside"             "SHUT" "$(runWindow 2 4 6)"
# The wrap case is where the obvious implementation breaks: 23 + 4 = 27 % 24 = 3, so a
# naive start<now<end test is false for every hour of the window.
check "wrapping window, late side (23..3, now 23)" "OPEN" "$(runWindow 23 4 23)"
check "wrapping window, early side (23..3, now 1)" "OPEN" "$(runWindow 23 4 1)"
check "wrapping window, outside (23..3, now 12)"   "SHUT" "$(runWindow 23 4 12)"

echo

# --- isIdle: needs the database ------------------------------------------------------
if ! docker exec "$CONTAINER" true 2>/dev/null; then
	echo "  SKIP  isIdle cases -- container '$CONTAINER' not running"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

dbq() { docker exec "$CONTAINER" mysql -N -B -e "$1" 2>/dev/null; }

# Refuse to run the isIdle cases against a pre-2.47 schema. Without this guard every case
# fails with "Unknown column player_count", which reads as six logic bugs in the gate and
# is really one container that has not had its migration run. A test that reports a code
# failure for an environmental reason is worse than no test.
if [ "$(dbq "SELECT COUNT(*) FROM information_schema.columns \
	WHERE table_schema='phvalheim' AND table_name='worlds' \
	AND column_name IN ('player_count','player_count_at');")" != "2" ]; then
	echo "  SKIP  isIdle cases -- '$CONTAINER' has no 2.47 columns; run dbUpdate_2.47.sh there first"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

cleanup() { dbq "DELETE FROM phvalheim.worlds WHERE name='$SCRATCH';"; }
trap cleanup EXIT
cleanup

dbq "INSERT INTO phvalheim.worlds (name, mode) VALUES ('$SCRATCH','stopped');"
if [ -z "$(dbq "SELECT name FROM phvalheim.worlds WHERE name='$SCRATCH';")" ]; then
	echo "  SKIP  isIdle cases -- could not create scratch world"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

sed -n '/^isIdle() {/,/^}/p' "$TOOL" > /tmp/isidle.fn

runIdle() {
	local count="$1" agoMinutes="$2" threshold="$3" nullAt="${4:-0}"

	if [ "$nullAt" = "1" ]; then
		dbq "UPDATE phvalheim.worlds SET player_count=$count, player_count_at=NULL WHERE name='$SCRATCH';"
	else
		dbq "UPDATE phvalheim.worlds SET player_count=$count, \
		     player_count_at=DATE_SUB(NOW(), INTERVAL $agoMinutes MINUTE) WHERE name='$SCRATCH';"
	fi

	docker exec -i "$CONTAINER" bash -s <<EOF 2>/dev/null
SQL(){ /usr/bin/mysql --skip-column-names -uroot --database=phvalheim -e "\$1"; }
$(cat /tmp/isidle.fn)
if isIdle "$SCRATCH" "$threshold"; then echo IDLE; else echo BUSY; fi
EOF
}

check "0 players, seen just now -> idle"          "IDLE" "$(runIdle 0 1 30)"
check "2 players, seen just now -> busy"          "BUSY" "$(runIdle 2 1 30)"
# The stuck-count case: a nonzero count whose observation has gone stale is idle. Without
# this arm a bad teardown parks a world in "waiting" forever and it is never updated.
check "2 players, last seen 90m ago -> idle"      "IDLE" "$(runIdle 2 90 30)"
check "2 players, last seen 20m ago -> busy"      "BUSY" "$(runIdle 2 20 30)"
# A zero that is itself ancient means the log stopped being written. Not knowing is not
# the same as knowing it is empty.
check "0 players, last seen 5h ago -> busy"       "BUSY" "$(runIdle 0 300 30)"
# Never observed at all. Unknown must never authorise a restart.
check "never observed -> busy"                    "BUSY" "$(runIdle 0 0 30 1)"

# --- stopping the world ----------------------------------------------------------------
#
# The worst bug this feature has had. updateApplier is run from cron AS THE phvalheim USER,
# and supervisorctl cannot work as that user: supervisord.conf is 0660 root:root and its
# socket is 0700 root:root, so it exits 2 with "could not read config file". The old code was
#
#     /usr/bin/supervisorctl stop valheimworld_$worldName > /dev/null 2>&1
#
# which discarded that, so the world was never stopped and steamcmd went on to rewrite the
# game tree underneath a live server with players on it. Verified on a real server: the
# process ran straight through the update while the UI reported the world left stopped.
echo

# The whole file, minus the shebang/source line, so the greps below see the real thing.
BODY=$(tail -n +3 "$TOOL")

# NEGATIVE, and the one that matters. Counting the new code would pass on a file that kept
# the broken call beside it.
#
# Matched on the INVOCATION path, not the bare word: the comments above the fix explain why
# supervisorctl cannot be used here and name it three times, so a bare word count is 3 on the
# corrected file.
supCalls=$(printf '%s' "$BODY" | grep -c '/usr/bin/supervisorctl')
check "supervisorctl is never called (it cannot work as this user)" "0" "$supCalls"

# Stop must go through worlds.mode, which the engine owns and the engine runs as root.
check "the stop goes through worlds.mode" "1" \
	"$(printf '%s' "$BODY" | grep -c "UPDATE worlds SET mode='stop' WHERE")"
check "the start goes through worlds.mode" "1" \
	"$(printf '%s' "$BODY" | grep -c "UPDATE worlds SET mode='start' WHERE")"

# Liveness is asked of the process table. Asking supervisor is impossible here, and asking
# worlds.mode would be trusting the thing we are trying to verify.
check "liveness is checked against the process table" "1" \
	"$(printf '%s' "$BODY" | grep -c 'pgrep -f')"

# stopWorldAndWait is pure enough to run directly, with the two things it touches stubbed.
sed -n '/^worldProcessRunning() {/,/^}/p' "$TOOL" >  /tmp/stopw.fn
sed -n '/^stopWorldAndWait() {/,/^}/p'    "$TOOL" >> /tmp/stopw.fn

# $1 = how many liveness checks report "still running" before it goes quiet; 99 = never stops.
runStop() {
	bash -c '
		calls=0
		stopsAfter='"$1"'
		worldProcessRunning() { calls=$((calls+1)); [ "$calls" -le "$stopsAfter" ]; }
		SQL() { echo "SQL:$*" >> /tmp/stopw.sql; }
		sleep() { :; }          # no real waiting in a test
		date() { echo "-"; }
		'"$(sed -n '/^stopWorldAndWait() {/,/^}/p' "$TOOL")"'
		if stopWorldAndWait "w"; then echo STOPPED; else echo STILLUP; fi
	' 2>/dev/null | tail -1   # the failure path logs an ERROR line first; the verdict is last
}

rm -f /tmp/stopw.sql
check "a world that goes quiet reports stopped"      "STOPPED" "$(runStop 2)"
# The load-bearing one: it must NOT claim success just because it waited.
check "a world that never stops reports failure"     "STILLUP" "$(runStop 99)"
# Already down before we start: no stop command, nothing to wait for.
rm -f /tmp/stopw.sql
check "an already-stopped world returns immediately" "STOPPED" "$(runStop 0)"
check "...and issues no stop command"                "0" \
	"$(grep -c "mode='stop'" /tmp/stopw.sql 2>/dev/null || echo 0)"

# A failure to stop must abort before anything is written. This asserts the wiring: the
# stopping phase is followed by a guarded call whose failure branch returns.
check "a failed stop aborts the update" "1" \
	"$(printf '%s' "$BODY" | grep -c 'if ! stopWorldAndWait')"
check "...and says the world is still running" "1" \
	"$(printf '%s' "$BODY" | grep -c 'could not be stopped, so nothing was updated')"

# --- the world has to come back up -------------------------------------------------------
#
# The engine's update path ends with "finally, set the world to stopped state" -- it does NOT
# restart what it updated, because a mod-list edit from the admin UI goes through the same
# path. The start used to sit in an `else` against the mods branch, so the default scope
# ('both') stopped the world, handed the rebuild to the engine, and never started it again,
# while reporting "Updated ... mods rebuilt". VikingOutlaws was down for 7 minutes that way.
check "the start is NOT in an else against the mods branch" "0" \
	"$(printf '%s' "$BODY" | grep -A2 'mods rebuilt"$' | grep -c '^	else')"
check "every scope reaches the starting phase" "1" \
	"$(printf '%s' "$BODY" | grep -c 'Start the world again, for EVERY scope')"

# The engine handoff is asynchronous, so the completion message has to wait for it. Without
# this the world reported Updated and idle while the engine was still downloading mods.
check "the mods handoff is waited on" "1" \
	"$(printf '%s' "$BODY" | grep -c 'if ! waitForEngineUpdate')"

sed -n '/^waitForEngineUpdate() {/,/^}/p' "$TOOL" > /tmp/waitupd.fn

# $1 = space-separated modes returned on successive polls; $2 = final status
#
# The poll counter lives in a FILE, not a variable. waitForEngineUpdate reads the mode with
# `mode=$(SQL ...)`, which runs the stub in a command-substitution subshell, so an in-memory
# counter never increments in the parent -- every call returns the first mode and the loop
# spins to its timeout. The first version of this harness did exactly that and reported the
# product broken when it was not.
runWait() {
	echo 0 > /tmp/waitupd.i
	bash -c '
		modes="'"$1"'"; finalStatus="'"$2"'"
		SQL() {
			case "$*" in
				*"SELECT mode"*)
					i=$(( $(cat /tmp/waitupd.i) + 1 )); echo "$i" > /tmp/waitupd.i
					echo "$modes" | cut -d" " -f$i ;;
				*status*) echo "$finalStatus" ;;
			esac
		}
		sleep() { :; }
		date() { echo "-"; }
		'"$(cat /tmp/waitupd.fn)"'
		if waitForEngineUpdate "w"; then echo OK; else echo BAD; fi
	' 2>/dev/null | tail -1
}

check "waits through update->updating->stopped"  "OK"  "$(runWait 'update updating stopped' '')"
# The tick the engine has not seen yet: mode is still 'update' and must not be read as done.
check "does not mistake the un-ticked handoff for completion" "OK" \
	"$(runWait 'update update update stopped' '')"
# A rebuild the engine marked failed must not be reported as a successful update.
check "a failed rebuild is a failure"            "BAD" "$(runWait 'update stopped' 'failed')"

# --- a skipped backup is not a backup ----------------------------------------------------
#
# worldBackup holds ONE global lock, so "already running" usually means a different world.
# It exited 0 for that, and this script only checked for non-zero, so a skip read as a
# successful backup and the update proceeded with no way back.
BACKUP="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/worldBackup"
check "worldBackup exits 75 when it skips, not 0" "1" \
	"$(grep -A4 'Backup already running' "$BACKUP" | grep -c 'exit 75')"
check "the applier treats 75 as no-backup-taken" "1" \
	"$(printf '%s' "$BODY" | grep -c 'backupStatus" -eq 75')"
# Deferred, not failed: the condition is transient, and 'pending' is what the sweep retries.
# Anchored on the deferral message -- the sweep sets 'pending' too, so a bare count is 2.
check "a deferred backup leaves the world retryable" "1" \
	"$(printf '%s' "$BODY" | grep -c 'Deferred: another backup was running')"

# --- the game update's own exit status ---------------------------------------------------
#
# InstallAndUpdateValheim ended with `chown -R`, so a single unchownable file decided the
# whole function's return value. The engine runs as root and leaves the client payload zip
# root-owned; this script runs as phvalheim, so that chown is EPERM. A steamcmd run that had
# just logged "installed successfully" was reported as a failed update.
FUNCS="$(cd "$(dirname "$0")/.." && pwd)/container/engine/includes/0-functions.sh"
tail=$(awk '/^function InstallAndUpdateValheim/,/^}/' "$FUNCS" | grep -v '^\s*#' | grep -v '^\s*$' | tail -2 | head -1)
check "InstallAndUpdateValheim ends with an explicit return, not a chown" "1" \
	"$(awk '/^function InstallAndUpdateValheim/,/^}/' "$FUNCS" | grep -c '^        return 0$')"
check "...and its chown cannot decide the return value" "1" \
	"$(awk '/^function InstallAndUpdateValheim/,/^}/' "$FUNCS" | grep -c 'if ! chown -R phvalheim:')"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
