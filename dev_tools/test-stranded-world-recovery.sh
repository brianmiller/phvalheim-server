#!/bin/sh
# Oracle test: a world left mid-flight by a container restart recovers by itself.
#
# THE BUG, from Discord: an operator restarted the phvalheim-server container while one of
# their worlds was updating. The world was then stuck "updating" forever -- a pulsing lowercase
# pill, no button that could clear it, and nothing short of hand-editing the database would.
#
# WHY it could never clear itself. worlds.mode carries two different kinds of value:
#
#   COMMANDS   create, start, stop, update, delete, repackage
#              The main loop has an `if [ "$worldMode" = ... ]` branch for each, so it picks
#              them up on the next 2s tick. An interruption here only means "runs late".
#
#   IN-FLIGHT  creating, updating, starting, stopping, repackaging, backup
#              Set by the engine on ITSELF while work runs, so the UI can say what is
#              happening and the next tick cannot re-enter work already going. NOTHING in the
#              loop matches them -- deliberately -- so they are cleared only by the very code
#              that set them. Kill that code and the world is stranded permanently.
#
# THE FIX has three parts, and part 3 is the one with teeth:
#   1. reconcileStrandedWorlds() lands every in-flight world on the TRUTH from the process
#      table -- running or stopped, never on a command.
#   2. The two label maps in admin/index.php learned 'creating'/'updating'/'broken', which
#      they never had. The fallback is the raw column value, which is literally what the
#      operator was looking at.
#   3. A world caught mid-creating/updating/repackaging had its FILES being rewritten, so it
#      is marked update_interrupted=1 and autoStart() refuses it. Without this, the single
#      most likely interruption point -- after purgeWorldModsConfigsPatchers() and before the
#      mods are reinstalled -- autostarts a MODDED world carrying zero plugins and publishes
#      it to players as an effectively vanilla server. The update path already refuses to
#      produce that outcome; it must not arrive by the back door either.
#
# Several assertions below are "nothing happened" claims (a command mode is left alone, a
# broken world is left alone, an interrupted world is not started). Every one of them is
# paired, in the SAME run, with a world that MUST have moved -- because a harness that never
# invokes the function satisfies every silence assertion in this file at once. That is not
# hypothetical: test-blankpassword-retirement.sh shipped exactly that bug by running the
# harness under dash, which rejects `function name()` outright.
#
# Usage:  sh dev_tools/test-stranded-world-recovery.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
FUNCS="$REPO/container/engine/includes/0-functions.sh"
ENGINE="$REPO/container/engine/phvalheim"
MIG="$REPO/container/engine/dbUpdates/dbUpdate_2.55.sh"
ADMIN="$REPO/container/nginx/www/admin/index.php"
CSS="$REPO/container/nginx/www/css/phvalheimStyles.css"

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== part 1: reconcileStrandedWorlds() lands a stranded world on the truth =="

grep -qE '^function reconcileStrandedWorlds *\(\)' "$FUNCS" \
	|| { fail "reconcileStrandedWorlds() is missing from 0-functions.sh" \
	          "this is the whole fix; nothing below can be meaningful"; \
	     echo; echo "$PASS passed, $FAIL failed"; exit 1; }
pass "reconcileStrandedWorlds() exists in 0-functions.sh"

RFN=/tmp/phv-stranded-fn.sh
awk '/^function reconcileStrandedWorlds *\(\)/{f=1} f{print} f&&/^}$/{exit}' "$FUNCS" > "$RFN"
grep -q 'UPDATE worlds SET mode=' "$RFN" \
	|| { echo "FATAL: extraction of reconcileStrandedWorlds is stale"; exit 1; }

# Drive the REAL function. $1 = the mode the world is stranded in, $2 = 1 if its server
# process is alive. The world is always id 7 / name 'midgard'.
#
# bash, not sh: 0-functions.sh uses `function name()`, which dash rejects, and a rejected
# function body means every "nothing was written" assertion below passes for the wrong reason.
reconcile() {
	R_MODE="$1"; R_ALIVE="$2"; RLOG=/tmp/phv-stranded-sql.log
	: > "$RLOG"
	export R_MODE R_ALIVE RLOG
	bash -c '
		SQL() {
			printf "%s\n" "$1" >> "$RLOG"
			case "$1" in
				*"SELECT id FROM worlds WHERE mode IN"*) echo "7" ;;
				*"SELECT name FROM worlds"*)             echo "midgard" ;;
				*"SELECT mode FROM worlds"*)             echo "$R_MODE" ;;
				*) : ;;
			esac
		}
		worldProcessRunning() { [ "$R_ALIVE" = "1" ]; }
		. "$1"
		reconcileStrandedWorlds
	' _ "$RFN" > /tmp/phv-stranded-out.log 2>&1
}

# Did the function actually execute? Every silence assertion is guarded by this.
ran() { grep -q 'SELECT id FROM worlds WHERE mode IN' "$RLOG"; }

# --- the reported case: stranded 'updating', nothing running ---------------------------
reconcile updating 0
if ran; then
	pass "the function ran (it queried for stranded worlds)"
else
	fail "the function never ran" "harness is broken; ignore every result below"
fi

grep -q "UPDATE worlds SET mode='stopped' WHERE id='7'" "$RLOG" \
	&& pass "a world stranded 'updating' with no process lands on 'stopped'" \
	|| fail "it was not moved to stopped" "$(grep 'SET mode=' "$RLOG" | head -3)"

grep -q "update_interrupted=1" "$RLOG" \
	&& pass "and is flagged update_interrupted, because its files were being rewritten" \
	|| fail "update_interrupted was not set for a stranded 'updating' world"

# The flag must not be the only thing the operator gets. They need a sentence.
grep -q "update_last_result='Interrupted by a restart" "$RLOG" \
	&& pass "the UI is given a sentence saying what happened and what to do" \
	|| fail "no update_last_result explaining the interruption"

# --- the truth, not a guess: a LIVE process means running -----------------------------
reconcile updating 1
if grep -q "UPDATE worlds SET mode='running' WHERE id='7'" "$RLOG"; then
	pass "a stranded world whose process IS alive lands on 'running'"
else
	fail "a live world was not landed on running" \
	     "the mode must come from the process table, not from an assumption that a restart killed everything"
fi
grep -q "UPDATE worlds SET mode='stopped'" "$RLOG" \
	&& fail "it was ALSO written to stopped" "the two branches are not exclusive" \
	|| pass "and is not also written to stopped"

# --- never a COMMAND ------------------------------------------------------------------
# Landing on 'start' would boot a world the operator had deliberately stopped; landing on
# 'update' would silently rebuild a world nobody touched, once per boot, forever.
reconcile updating 0
if ran; then
	if grep -qE "SET mode='(start|update|create|repackage|delete|stop)'" "$RLOG"; then
		fail "the reconciler issued a COMMAND mode" \
		     "$(grep -E "SET mode='(start|update|create|repackage|delete|stop)'" "$RLOG" | head -2)"
	else
		pass "it never lands a world on a command mode"
	fi
else
	fail "cannot judge the command-mode claim: the function did not run"
fi

echo
echo "== part 2: which modes it does and does NOT claim =="

# The in-flight set, each of which must be reconciled.
for m in creating updating starting stopping repackaging backup; do
	reconcile "$m" 0
	grep -q "UPDATE worlds SET mode='stopped' WHERE id='7'" "$RLOG" \
		&& pass "'$m' is recognised as stranded and cleared" \
		|| fail "'$m' was left stranded"
done

# Only the three that were WRITING FILES get the autostart refusal. A world caught mid
# 'starting'/'stopping'/'backup' has a complete, valid tree -- flagging it would refuse to
# autostart a world that is perfectly fine, every boot, until someone pressed Update.
for m in creating updating repackaging; do
	reconcile "$m" 0
	grep -q 'update_interrupted=1' "$RLOG" \
		&& pass "'$m' sets update_interrupted (its files were half-written)" \
		|| fail "'$m' did not set update_interrupted"
done
for m in starting stopping backup; do
	reconcile "$m" 0
	if ran; then
		grep -q 'update_interrupted=1' "$RLOG" \
			&& fail "'$m' wrongly set update_interrupted" \
			        "nothing was rewriting this world's files; it will now refuse to autostart" \
			|| pass "'$m' does NOT set update_interrupted"
	else
		fail "cannot judge '$m': the function did not run"
	fi
done

# 'broken' is a terminal state the engine chooses when it wants a human to look. Re-deciding
# it here would quietly undo that on the next restart.
STRANDED_LIST=$(grep -o "strandedModes=.*" "$RFN")
echo "$STRANDED_LIST" | grep -q "broken" \
	&& fail "'broken' is in the stranded list" "a restart would silently clear it: $STRANDED_LIST" \
	|| pass "'broken' is NOT reconciled -- it is a deliberate terminal state"

# And the command modes must not be in it either. If 'update' were, every container restart
# would rebuild every world that happened to have the command pending.
for m in update start stop create delete repackage; do
	echo "$STRANDED_LIST" | grep -qE "'$m'" \
		&& fail "the command mode '$m' is in the stranded list" \
		        "the main loop already handles it; reconciling it would fight the loop" \
		|| pass "the command mode '$m' is left to the main loop"
done

echo
echo "== part 3: autoStart() refuses a world whose update was interrupted =="

AFN=/tmp/phv-autostart-fn.sh
awk '/^function autoStart *\(\)/{f=1} f{print} f&&/^}$/{exit}' "$FUNCS" > "$AFN"
grep -q "UPDATE worlds SET mode='start'" "$AFN" \
	|| { echo "FATAL: extraction of autoStart is stale"; exit 1; }

# $1 = autostart flag, $2 = update_interrupted flag
autostart() {
	A_FLAG="$1"; A_INT="$2"; ALOG=/tmp/phv-autostart-sql.log
	: > "$ALOG"
	export A_FLAG A_INT ALOG
	bash -c '
		SQL() {
			printf "%s\n" "$1" >> "$ALOG"
			case "$1" in
				"SELECT id FROM worlds;")              echo "7" ;;
				*"SELECT name FROM worlds"*)           echo "midgard" ;;
				*"IFNULL(update_interrupted,0)"*)      echo "$A_INT" ;;
				*"SELECT autostart FROM worlds"*)      echo "$A_FLAG" ;;
				*) : ;;
			esac
		}
		. "$1"
		autoStart
	' _ "$AFN" > /tmp/phv-autostart-out.log 2>&1
}

# POSITIVE CONTROL FIRST. Without it, the refusal below could be passing simply because
# autoStart no longer starts anything at all.
autostart 1 0
grep -q "UPDATE worlds SET mode='start'" "$ALOG" \
	&& pass "control: a normal autostart=1 world IS still started" \
	|| fail "autoStart no longer starts anything" \
	        "the refusal assertion below is meaningless until this passes"

# THE LOAD-BEARING ASSERTION.
autostart 1 1
if grep -q "IFNULL(update_interrupted,0)" "$ALOG"; then
	grep -q "UPDATE worlds SET mode='start'" "$ALOG" \
		&& fail "an interrupted world was autostarted anyway" \
		        "a modded world whose mods were purged is now published with zero plugins" \
		|| pass "autostart=1 + update_interrupted=1 is NOT started"
else
	fail "autoStart never consulted update_interrupted" \
	     "its silence above means nothing -- the column is not being read at all"
fi

# autostart=0 + interrupted=1. Nothing should be started, and -- the part worth asserting --
# the operator should not be WARNED about an autostart that was never going to happen.
#
# Found by reading the first cut of this change: the flag was read before the autostart test,
# so a world the operator had deliberately left stopped was told it "is set to autostart, but
# an interrupted update ...". A warning about a non-event, on every boot.
autostart 0 1
grep -q "UPDATE worlds SET mode='start'" "$ALOG" \
	&& fail "a world with autostart=0 was started" \
	|| pass "autostart=0 + update_interrupted=1 starts nothing"
grep -q 'IFNULL(update_interrupted,0)' "$ALOG" \
	&& fail "the interrupted flag is read for a world that was never going to autostart" \
	        "that path emits a warning about an autostart that cannot happen" \
	|| pass "and the flag is not even consulted -- no warning about a non-event"

echo
echo "== part 4: the ordering, which is what makes part 3 work =="

zRec=$(grep -n '^reconcileStrandedWorlds' "$ENGINE" | head -1 | cut -d: -f1)
zAuto=$(grep -n '^autoStart' "$ENGINE" | head -1 | cut -d: -f1)

[ -n "$zRec" ] \
	&& pass "the engine calls reconcileStrandedWorlds at boot (line $zRec)" \
	|| fail "the engine never calls reconcileStrandedWorlds" "the function exists but nothing runs it"

if [ -n "$zRec" ] && [ -n "$zAuto" ]; then
	[ "$zRec" -lt "$zAuto" ] \
		&& pass "it runs BEFORE autoStart ($zRec < $zAuto)" \
		|| fail "it runs AFTER autoStart ($zRec > $zAuto)" \
		        "the flag is set too late to stop the start it exists to stop"
else
	fail "could not locate both calls" "reconcile=$zRec autoStart=$zAuto"
fi

echo
echo "== part 5: a completed update clears the flag =="

# Otherwise the first interruption makes a world permanently un-autostartable.
grep -q 'UPDATE worlds SET update_interrupted=0' "$ENGINE" \
	&& pass "the engine clears update_interrupted somewhere" \
	|| fail "nothing ever clears update_interrupted" "one interruption would strand the world for good"

# It must be cleared by SUCCESS, not merely by reaching the end of the branch. Every failure
# path in the update branch does a `continue`, so position is the proof: the clear sits after
# the last of them, next to the date_updated stamp.
awk '/#set last updated timestamp/{f=NR} f&&NR>f&&NR<=f+8&&/update_interrupted=0/{found=1} END{exit !found}' "$ENGINE" \
	&& pass "the update branch clears it next to the date_updated stamp (past every failure exit)" \
	|| fail "the clear is not on the update branch's success path" \
	        "a FAILED rebuild would clear the flag and let the world autostart half-built"

echo
echo "== part 6: the migration =="

grep -q 'addColumn worlds update_interrupted' "$MIG" \
	&& pass "dbUpdate_2.55.sh adds worlds.update_interrupted" \
	|| fail "the column is never created" "every read of it returns an error, not 0"

grep 'addColumn worlds update_interrupted' "$MIG" | grep -q 'DEFAULT 0' \
	&& pass "it defaults to 0" \
	|| fail "it does not default to 0" \
	        "DEFAULT 1 would refuse to autostart every existing world on the first boot after upgrade"

echo
echo "== part 7: the pill the operator was actually looking at =="

# Both maps, because the second is the one the 5-second poll uses: a mode present only in the
# PHP map shows correctly on load and reverts to raw lowercase on the first refresh. 2.53
# shipped a bug that was exactly a third caller nobody updated.
for mode in creating updating broken; do
	# ' *(=>|:) *' -- the PHP map writes `'updating' => 'Updating'` and the JS map writes
	# `'updating': 'Updating'` with NO space before the colon. A pattern demanding one
	# matched only the PHP copy and reported the JS map missing when it was present.
	n=$(grep -cE "'$mode' *(=>|:) *'" "$ADMIN")
	[ "$n" -ge 2 ] \
		&& pass "'$mode' has a label in both the PHP and the JS map ($n sites)" \
		|| fail "'$mode' is labelled in $n of 2 maps" \
		        "the fallback is the raw column value, so it renders as a bare lowercase word"
done

# A mode with a label but no CSS rule renders with the bare .status-badge style: no colour,
# no pulse, so a world that is genuinely busy looks idle.
for mode in creating updating broken; do
	grep -q "^\.status-badge\.$mode" "$CSS" \
		&& pass ".status-badge.$mode has a rule" \
		|| fail ".status-badge.$mode has no rule" "it will render as an unstyled grey pill"
done

# 'broken' must not pulse. A pulse means work is happening; the entire meaning of broken is
# that nothing is happening and nothing will until a human acts.
awk '/^\.status-badge\.broken/{f=1} f&&/animation:/{print; exit}' "$CSS" | grep -q 'none' \
	&& pass "and 'broken' does not pulse (animation: none)" \
	|| fail "'broken' pulses like a transitional state" "it reads as 'busy', which is the opposite of true"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
