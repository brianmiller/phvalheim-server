#!/bin/bash
# Oracle test: a restore never writes over a RUNNING world.
#
# THE BUG, reported 2026-10-16. An operator restored a 10-03 backup of Midgard, every step
# reported success, and the damage they had done that evening was still there. The restore had
# worked; something put the newer world back afterwards.
#
# worldRestore's stop step asked `supervisorctl status`. That script runs as the `phvalheim`
# user (adminAPI.php launches it), and supervisord.conf is 0660 root:root with a 0700 socket,
# so supervisorctl exits NON-ZERO with "could not read config file". `[ $? -eq 0 ]` read that
# permission failure as "the world is not running" and skipped the stop. The backup then went
# in underneath a live Valheim, and the `mode='update'` at the end made the ENGINE -- root, so
# its supervisorctl works -- stop the world cleanly. Valheim SAVES on a clean shutdown, so it
# wrote its in-memory world over the files that had just been restored.
#
# "Stopping world process..." appears nowhere in worldBackups.log for that restore. That
# absence is the whole diagnosis.
#
# WHY PART 1 IS BEHAVIOURAL. The source-level check ("is supervisorctl gone") cannot tell a
# correct stop from a stop that silently does nothing, and the failure mode here was a stop
# that silently did nothing while printing success. So part 1 runs the real step-2 block
# against a REAL decoy process whose command line the REAL pgrep can match, and asserts the
# block refuses to continue while that process is alive.
#
# Every case runs against HEAD~ too (--control), where it must behave the broken way. A test
# that passes on both builds is measuring nothing.
#
# Usage:  bash dev_tools/test-restore-stops-the-world.sh [--control]

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
REL="container/engine/tools/worldRestore"
F="$REPO/$REL"

CONTROL=0
[ "$1" = "--control" ] && CONTROL=1

if [ "$CONTROL" = "1" ]; then
	F=$(mktemp)
	git -C "$REPO" show "HEAD:$REL" > "$F" 2>/dev/null || { echo "FATAL: cannot read HEAD:$REL"; exit 1; }
	echo "== CONTROL RUN: the code as it is in HEAD. Failures below are the POINT. =="
fi

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

# ---------------------------------------------------------------------------
# Extract the real step-2 block.
# ---------------------------------------------------------------------------
BLOCK=$(mktemp)
awk '/^# step 2: stop the world/{f=1} /^# step 3:/{f=0} f{print}' "$F" > "$BLOCK"
if [ ! -s "$BLOCK" ]; then
	echo "FATAL: could not extract the step-2 block -- extraction is stale"
	exit 1
fi
pass "extracted the real step-2 stop block from $REL ($(wc -l < "$BLOCK") lines)"

# ---------------------------------------------------------------------------
# A decoy whose command line the REAL pgrep matches. exec -a puts the path in argv[0], so
# `pgrep -f` sees the same string it sees for a live world server.
# ---------------------------------------------------------------------------
DECOYPAT="/opt/stateful/games/valheim/worlds/TESTW/game/valheim_server.x86_64"

# Drive the block with stubs. $1 = how many loop ticks before the decoy dies (0 = never dies,
# -1 = no decoy at all). Prints a one-line verdict the caller parses.
#
# `sleep` is stubbed to a no-op counter, so the 120s wait loop runs instantly AND gives us the
# hook to kill the decoy mid-loop. Without that this test would take two minutes per case.
run_step2() {
	local dieAfter="$1"
	DIE_AFTER="$dieAfter" DECOYPAT="$DECOYPAT" BLOCK="$BLOCK" bash -c '
		set +e
		DECOY=""
		if [ "$DIE_AFTER" != "-1" ]; then
			setsid bash -c "exec -a \"$DECOYPAT -nographics\" sleep 300" &
			DECOY=$!
			# Wait for it to be visible to pgrep, else the test races its own fixture.
			for i in 1 2 3 4 5 6 7 8 9 10; do
				pgrep -f "worlds/TESTW/game/[v]alheim_server" >/dev/null 2>&1 && break
				command sleep 0.2
			done
			if ! pgrep -f "worlds/TESTW/game/[v]alheim_server" >/dev/null 2>&1; then
				echo "VERDICT fixture=BROKEN"; exit 0
			fi
		fi

		worldName=TESTW
		SQLCALLS=""
		TICKS=0

		SQL() { SQLCALLS="$SQLCALLS|$1"; }
		progress() { echo "PROGRESS: $1" >> /tmp/phv-restore-test-progress.$$; }
		jsonError() { echo "VERDICT fatal=1 sql=$SQLCALLS"; kill -9 -- -$DECOY 2>/dev/null; exit 7; }
		date() { echo DATE; }
		# No-op sleep: it is also where we retire the decoy, so the loop sees it vanish the
		# same way it would in production.
		sleep() {
			TICKS=$((TICKS+1))
			if [ "$DIE_AFTER" != "0" ] && [ "$DIE_AFTER" != "-1" ] && [ "$TICKS" -ge "$DIE_AFTER" ]; then
				kill -9 -- -$DECOY 2>/dev/null
				# Reap, so the kernel stops listing it before the loop re-checks.
				command sleep 0.3
			fi
		}
		export -f 2>/dev/null

		: > /tmp/phv-restore-test-progress.$$
		. "$BLOCK"

		# Reached only if the block let the restore proceed.
		echo "VERDICT fatal=0 sql=$SQLCALLS stopped=$(pgrep -f "worlds/TESTW/game/[v]alheim_server" >/dev/null 2>&1 && echo no || echo yes) said=$(grep -c "Stopping world process" /tmp/phv-restore-test-progress.$$)"
		kill -9 -- -$DECOY 2>/dev/null
		rm -f /tmp/phv-restore-test-progress.$$
	' 2>/dev/null
}

echo
echo "== part 1: a world that will not stop must ABORT the restore (behavioural) =="

out=$(run_step2 0)
case "$out" in
	*fixture=BROKEN*) fail "the decoy process was never visible to pgrep" "the harness, not the code, is broken" ;;
	*fatal=1*)        pass "a world still running after the wait is FATAL -- nothing is extracted" ;;
	*fatal=0*)        fail "the restore CONTINUED with the world still running" \
	                       "this is the data-loss path: Valheim saves on shutdown and overwrites the restore ($out)" ;;
	*)                fail "the block produced no verdict" "$out" ;;
esac

# The stop must be issued through worlds.mode, because that is the only stop this uid can
# actually cause. A block that aborts without ever ASKING for a stop would pass the assertion
# above while being useless.
case "$out" in
	*"mode='stop'"*) pass "and it asked the engine to stop the world (mode='stop'), not supervisorctl" ;;
	*fixture=BROKEN*) : ;;
	*) fail "no mode='stop' was written" "nothing asked for the stop; the abort is all it does ($out)" ;;
esac

echo
echo "== part 2: a world that DOES stop lets the restore proceed =="

out=$(run_step2 3)
case "$out" in
	*fixture=BROKEN*) fail "decoy not visible to pgrep" "harness" ;;
	*fatal=1*)        fail "a world that stopped was still treated as fatal" "$out" ;;
	*"stopped=yes"*)  pass "the block waits for the process to actually die, then continues" ;;
	*)                fail "the block continued while the process was still alive" "$out" ;;
esac
case "$out" in
	*said=0*) fail "it never reported stopping the world" \
	               "the absence of this line in worldBackups.log is how the live bug was found" ;;
	*said=*)  pass "and it logs 'Stopping world process...' -- the line missing from the live incident" ;;
esac

echo
echo "== part 3: a world that is already stopped is not asked to stop =="

out=$(run_step2 -1)
case "$out" in
	*fatal=1*) fail "a stopped world was treated as fatal" "$out" ;;
	*fatal=0*) pass "a stopped world proceeds straight to the restore" ;;
	*)         fail "no verdict" "$out" ;;
esac
case "$out" in
	*"mode='stop'"*) fail "it wrote mode='stop' for a world that was not running" \
	                      "that would strand the world's mode for no reason" ;;
	*) pass "and it does not touch worlds.mode for a world that is already down" ;;
esac

echo
echo "== part 4: regression assertions on the source =="

# CODE ONLY. The first cut of both greps in this part counted the comments I had just written
# ABOUT the bug -- "it used to ask supervisorctl status", "reported either started
# successfully or..." -- and reported 3 of each on the fixed file. A marker that matches the
# prose explaining the fix fails hardest exactly when the fix is right.
CODE=$(mktemp)
grep -vE '^[[:space:]]*#' "$F" > "$CODE"

n=$(grep -c 'supervisorctl' "$CODE")
[ "$n" = "0" ] \
	&& pass "no supervisorctl left in worldRestore ($n) -- every call failed as the phvalheim user" \
	|| fail "$n supervisorctl call(s) remain" "$(grep -n supervisorctl "$CODE" | head -4)"

# The stop confirmation must read the PROCESS TABLE. Anchored on the pgrep pattern, not on a
# function name, so renaming the helper cannot quietly disarm this.
grep -q 'pgrep -f "worlds/\$1/game/\[v\]alheim_server' "$F" \
	&& pass "the running check reads the process table, anchored as [v]alheim_server" \
	|| fail "no [v]alheim_server pgrep found" "an unanchored pgrep -f matches its own invocation"

# Step 8 must not claim the world started. It cannot start one: the engine's update branch
# ends on mode='stopped' by design.
n=$(grep -ci 'started successfully\|engine will start when ready' "$CODE")
[ "$n" = "0" ] \
	&& pass "step 8 no longer claims the world started or was queued ($n)" \
	|| fail "step 8 still reports a start that cannot happen ($n)"

grep -qi 'stays STOPPED' "$CODE" \
	&& pass "and it tells the operator the world stays stopped until the rebuild finishes" \
	|| fail "the operator is not told the world is left stopped"
rm -f "$CODE"

echo
echo "$PASS passed, $FAIL failed"
[ "$CONTROL" = "1" ] && rm -f "$F"
rm -f "$BLOCK"
[ "$FAIL" -eq 0 ] || exit 1
