#!/bin/bash
# Oracle test: one world that will not start must not take the engine down.
#
# THE BUG: the start path did `exit 1` when supervisorctl could not start a world. supervisor
# then restarted the engine, whose pre-flight resets EVERY world to 'stopped' -- so a single
# unstartable world became a restart loop that also clobbered create/update commands queued
# against the other worlds. A second defect sat right under it: the "waiting for the world to
# start" loop had no bound, so a world that started and then died parked the engine forever.
#
# THE ORACLE IS THE ENGINE'S PID. With the old code the engine exits and supervisor gives it
# a new one; with the fix the pid is unchanged. Asserting "the world ended up broken" alone
# would pass EITHER way -- the restarted engine marks it broken too, just after taking the
# whole service down with it. The pid is what separates the two.
#
# Case 3 then proves the engine is not merely alive but still WORKING, by having it process a
# command for a different world afterwards.
#
# Usage: dev_tools/test-engine-survives-bad-world.sh [container] [badWorld] [goodWorld]

C="${1:-phvalheim-dev}"
BAD="${2:-northlands}"     # exists in the db, has no world directory -> cannot start
GOOD="${3:-test2}"

pass=0; fail=0
check () {
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

q () { docker exec "$C" mysql -N -e "$1"; }
enginePid () { docker exec "$C" supervisorctl status phvalheim 2>/dev/null | grep -oE 'pid [0-9]+' | grep -oE '[0-9]+'; }
mode () { q "select ifnull(mode,'') from phvalheim.worlds where name='$1'"; }

# --------------------------------------------------------------- preflight
docker exec "$C" test -f /opt/stateless/engine/phvalheim || { echo "no engine in $C"; exit 1; }
if ! docker exec "$C" grep -q "Marking it broken and continuing" /opt/stateless/engine/phvalheim; then
	echo "PRECONDITION NOT MET: the container is running the OLD engine."
	echo "Deploy container/engine/phvalheim into $C first, or this tests nothing."
	exit 2
fi
ORIG_BAD=$(mode "$BAD"); ORIG_GOOD=$(mode "$GOOD")
[ -n "$(q "select name from phvalheim.worlds where name='$BAD'")" ] || { echo "world '$BAD' not found"; exit 1; }
echo "Captured: $BAD mode='$ORIG_BAD', $GOOD mode='$ORIG_GOOD'"
restore () {
	q "update phvalheim.worlds set mode='$ORIG_BAD' where name='$BAD'" >/dev/null
	q "update phvalheim.worlds set mode='$ORIG_GOOD' where name='$GOOD'" >/dev/null
	echo; echo "Restored $BAD and $GOOD."
}
trap restore EXIT

PID_BEFORE=$(enginePid)
echo "Engine pid before: $PID_BEFORE"
[ -n "$PID_BEFORE" ] || { echo "engine not running in $C"; exit 1; }

# --------------------------------------------------------------- case 1
echo
echo "Case 1: asking an unstartable world to start"
q "update phvalheim.worlds set mode='start' where name='$BAD'" >/dev/null
# The engine polls every 2s; supervisorctl start can block for its startsecs window.
waited=0
while [ $waited -lt 60 ]; do
	m=$(mode "$BAD")
	[ "$m" = "broken" ] && break
	sleep 2; waited=$((waited+2))
done
FINAL=$(mode "$BAD")
[ "$FINAL" = "broken" ] && ok=1 || ok=0
check "the bad world is marked broken" "$ok" "mode='$FINAL' after ${waited}s"

# --------------------------------------------------------------- case 2
echo
echo "Case 2: THE ORACLE -- the engine did not go down with it"
PID_AFTER=$(enginePid)
[ -n "$PID_AFTER" ] && [ "$PID_AFTER" = "$PID_BEFORE" ] && ok=1 || ok=0
check "engine pid unchanged (never exited, never restarted)" "$ok" "before=$PID_BEFORE after=$PID_AFTER"
docker exec "$C" supervisorctl status phvalheim 2>/dev/null | grep -q RUNNING && ok=1 || ok=0
check "engine still RUNNING" "$ok"

# --------------------------------------------------------------- case 3
echo
echo "Case 3: and it still processes commands for OTHER worlds"
# Alive-but-wedged is the second failure mode -- the unbounded wait loop. A pid check
# alone cannot tell a working engine from one parked in a while loop forever.
q "update phvalheim.worlds set mode='stop' where name='$GOOD'" >/dev/null
waited=0
while [ $waited -lt 40 ]; do
	m=$(mode "$GOOD")
	[ "$m" != "stop" ] && break
	sleep 2; waited=$((waited+2))
done
GOODMODE=$(mode "$GOOD")
[ "$GOODMODE" != "stop" ] && ok=1 || ok=0
check "a later command was consumed, not left queued" "$ok" "mode='$GOODMODE' after ${waited}s"

# --------------------------------------------------------------- case 4
echo
echo "Case 4: it said why, and pointed at the world log"
# grep -a is REQUIRED here. phvalheim.log can contain NUL bytes -- torn writes left by the
# very crash loop this fix removes -- and grep treats a file with a NUL in it as binary,
# silently under-reporting matches after that point. Without -a this reads a stale line
# from days ago and reports a failure that is not there.
LOGLINE=$(docker exec "$C" sh -c "grep -a 'Could not start world' /opt/stateful/logs/phvalheim.log 2>/dev/null | tail -1")
echo "$LOGLINE" | grep -q "$BAD" && ok=1 || ok=0
check "logged the failure for '$BAD'" "$ok" "${LOGLINE:0:100}"
echo "$LOGLINE" | grep -q "valheimworld_$BAD.log" && ok=1 || ok=0
check "...and named the log holding the reason" "$ok"
echo "$LOGLINE" | grep -qi "exiting" && ok=0 || ok=1
check "no longer claims it is exiting" "$ok" "${LOGLINE:0:100}"

echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
