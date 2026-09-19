#!/bin/bash
#
# The orphan reaper, and the two liveness guards that were reading a column nobody writes.
#
# A user's 2.47 log showed "Murdering orphaned PID" once every 2 seconds with a climbing PID,
# and their supervisor log explained it: "terminated by SIGKILL; not expected" followed
# immediately by a respawn. The world program is autorestart=true, so SIGKILLing a process
# supervisor owns is an unwinnable fight -- supervisor puts it straight back and the next tick
# kills the new one. Forever.
#
# The reaper block is EXTRACTED FROM THE ENGINE and eval'd here rather than re-implemented.
# A re-implementation would answer these questions the same way whether or not the engine was
# fixed, which is the failure mode that let the What's New bug pass 18 tests.
#
# One rewrite is applied to the extracted text: the absolute /usr/bin/supervisorctl is made
# relative so a stub can intercept it. Test 9 pins the absolute path in the real file so that
# rewrite can never hide a change to it.

ENGINE="$(dirname "$0")/../container/engine/phvalheim"
FUNCS="$(dirname "$0")/../container/engine/includes/0-functions.sh"
pass=0; fail=0

ok()   { pass=$((pass+1)); echo "  PASS: $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL: $1"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }

echo "=== engine reaper + liveness guards ==="

# ---- extract the mode='stopped' block -------------------------------------------------
BLOCK=/tmp/reaper.block.$$
sed -n '/if \[ "$worldMode" = "stopped" \]; then/,/^\t\tfi$/p' "$ENGINE" \
	| sed 's#/usr/bin/supervisorctl#supervisorctl#' > "$BLOCK"

if [ ! -s "$BLOCK" ]; then
	echo "  FAIL: could not extract the reaper block from $ENGINE"
	exit 1
fi
ok "extracted the reaper block from the engine ($(wc -l < "$BLOCK") lines)"

# ---- harness ---------------------------------------------------------------------------
# $1 = supervisor state to report, $2 = "alive"|"dead" process, $3 = pids ps should list.
# Prints one line per action so ORDER is observable, not just occurrence.
runReaper() {
	local state="$1" alive="$2" pids="$3"
	local out=/tmp/reaper.out.$$
	: > "$out"

	(
		worldMode="stopped"
		worldName="ITToT1dot0nomods"
		OUT="$out"

		worldProcessRunning() { [ "$alive" = "alive" ]; }
		supervisorctl() {
			case "$1" in
				status) echo "valheimworld_$2   $state   pid 4242, uptime 0:00:05"; return 0 ;;
				stop)   echo "supervisorctl-stop $2" >> "$OUT"; return 0 ;;
			esac
		}
		ps()   { for p in $pids; do echo "phvalhe+ $p 1 0 06:20 ? 00:00:01 /opt/stateful/games/valheim/worlds/$worldName/game/valheim_server.x86_64"; done; }
		kill() { echo "kill $*" >> "$OUT"; }
		date() { echo "TESTDATE"; }
		echo() { case "$1" in TESTDATE*) : ;; *) builtin echo "$@" ;; esac; }

		eval "$(cat "$BLOCK")"
	) > /dev/null 2>&1

	cat "$out"
	rm -f "$out"
}

# ---- 1-3: supervisor owns it -- it must be asked, and asked FIRST ----------------------
r=$(runReaper RUNNING alive 46358)
case "$r" in
	*supervisorctl-stop*) ok "RUNNING: supervisor is asked to stop the world" ;;
	*) bad "RUNNING: supervisor was never asked -- this is the SIGKILL/respawn loop" ;;
esac

first=$(printf '%s\n' "$r" | head -1 | cut -d' ' -f1)
check "RUNNING: supervisor is asked BEFORE anything is killed" "$first" "supervisorctl-stop"

r=$(runReaper STARTING alive 46358)
case "$r" in
	*supervisorctl-stop*) ok "STARTING: supervisor is asked to stop the world" ;;
	*) bad "STARTING: a world mid-start was SIGKILLed behind supervisor's back" ;;
esac

r=$(runReaper BACKOFF alive 46358)
case "$r" in
	*supervisorctl-stop*) ok "BACKOFF: supervisor is asked to stop the world" ;;
	*) bad "BACKOFF: a restarting world was SIGKILLed behind supervisor's back" ;;
esac

# ---- 4-5: a TRUE orphan -- supervisor disowns it, so SIGKILL is correct ----------------
r=$(runReaper STOPPED alive 46358)
case "$r" in
	*supervisorctl-stop*) bad "STOPPED: pointless supervisor stop for a process it does not own" ;;
	*) ok "STOPPED: no supervisor stop -- supervisor does not own this process" ;;
esac
case "$r" in
	*"kill -9 46358"*) ok "STOPPED: the true orphan is still SIGKILLed" ;;
	*) bad "STOPPED: a genuinely orphaned process was left running" ;;
esac

# ---- 6-7: nothing running -- the whole block must be a no-op ---------------------------
r=$(runReaper STOPPED dead "")
check "no process: nothing is killed" "$(printf '%s' "$r" | grep -c 'kill')" "0"
check "no process: supervisor is not even queried" "$(printf '%s' "$r" | grep -c 'supervisorctl-stop')" "0"

# ---- 8: multiple survivors are all reaped ----------------------------------------------
r=$(runReaper STOPPED alive "100 200 300")
check "every orphaned pid is killed" "$(printf '%s\n' "$r" | grep -c '^kill -9')" "3"

# ---- 9: the engine really does call supervisorctl by absolute path ---------------------
# The harness rewrites this path, so without this assertion a change to it would be invisible.
n=$(grep -c '/usr/bin/supervisorctl stop valheimworld_\$worldName' "$ENGINE")
if [ "$n" -ge 1 ]; then ok "engine calls /usr/bin/supervisorctl by absolute path ($n sites)"
else bad "engine no longer calls /usr/bin/supervisorctl stop by absolute path"; fi

# ---- 10-12: worlds.pid is never a liveness answer --------------------------------------
# NOTHING in the engine writes worlds.pid. Both guards that read it -- the update refusal and
# the stop-loop's confirmation -- therefore ran `ps -p ""`, always failed, and always answered
# "not running". That is how a live world got steamcmd'd underneath its own players.
check "no ps -p \$worldPID guard survives in the engine" \
	"$(grep -c 'ps -p \$worldPID' "$ENGINE")" "0"
check "the engine no longer reads worlds.pid for liveness" \
	"$(grep -c 'SELECT pid FROM worlds' "$ENGINE")" "0"
# 5 sites: the reaper, the stop-loop confirmation, and three in the update branch
# (the initial check, the post-stop wait, and the did-it-actually-stop decision).
check "worldProcessRunning is what the engine asks instead" \
	"$(grep -c 'worldProcessRunning "\$worldName"' "$ENGINE")" "5"

# ---- 13-15: the helper itself ----------------------------------------------------------
check "worldProcessRunning is defined in 0-functions.sh" \
	"$(grep -c '^function worldProcessRunning()' "$FUNCS")" "1"

# It must match the world's OWN directory, or two worlds with related names collide.
if grep -A4 '^function worldProcessRunning()' "$FUNCS" | grep -q 'worlds/\$1/game/valheim_server.x86_64'; then
	ok "worldProcessRunning matches the world's own game directory"
else
	bad "worldProcessRunning does not scope its match to the world directory"
fi

# Behaviour, not text: it must answer yes for its own world and no for a lookalike.
(
	source "$FUNCS" 2>/dev/null
	sleep 7 &
	sleeper=$!
	# A stand-in process whose argv carries the path, so pgrep has something real to find.
	bash -c 'exec -a "/opt/stateful/games/valheim/worlds/Alpha/game/valheim_server.x86_64 -name Alpha" sleep 5' &
	stand=$!
	sleep 0.4
	worldProcessRunning Alpha && a=yes || a=no
	worldProcessRunning AlphaBeta && b=yes || b=no
	kill $sleeper $stand 2>/dev/null
	[ "$a" = "yes" ] && [ "$b" = "no" ]
) && ok "worldProcessRunning: finds its own world, not a lookalike name" \
  || bad "worldProcessRunning: wrong answer for its own world or a lookalike"

# ---- 16-23: the update branch must not spin on a running world -----------------------
# Nothing that sets mode=update stops the world first (updateWorld() in db_sets.php, used by
# the mod-list save, the Rebuild Mods button and Hugin). So the moment the liveness guard
# above started working, the branch refused a running world, left mode=update untouched, and
# the 2s loop reprinted the same refusal forever. A real server logged it ~30x a minute.
PRO=/tmp/upd.pro.$$
EPI=/tmp/upd.epi.$$
# The range ends INSIDE the else, so close it -- otherwise the text is not a complete
# compound command and eval runs only part of it, which silently passed some assertions.
{ sed -n '/^\t\t\twasRunning=0$/,/mode=.updating./p' "$ENGINE" | sed 's#/usr/bin/supervisorctl#supervisorctl#'; echo fi; } > "$PRO"
sed -n '/#finally, put the world back/,/^\t\t\t\tfi$/p' "$ENGINE" > "$EPI"
[ -s "$PRO" ] && [ -s "$EPI" ] || { echo "  FAIL: could not extract the update prologue/epilogue"; exit 1; }

# $1 = "alive"|"dead", $2 = ticks the world stays alive after the stop (999 = never stops)
runPrologue() {
	local start="$1" stops="$2" out=/tmp/upd.out.$$
	: > "$out"
	(
		worldName="ITToT1dot0nomods"
		OUT="$out"; CNT=/tmp/upd.cnt.$$; echo 0 > "$CNT"
		# File-backed, because the calls happen inside a `while` whose condition is a
		# command -- a plain variable increment there is lost to the subshell.
		worldProcessRunning() {
			local n; n=$(cat "$CNT"); n=$((n+1)); echo "$n" > "$CNT"
			[ "$start" = "alive" ] && [ "$n" -le "$stops" ]
		}
		supervisorctl() { echo "supervisorctl $1 $2" >> "$OUT"; }
		SQL() { echo "SQL $*" >> "$OUT"; }
		sleep() { :; }
		date() { echo "TESTDATE"; }
		echo() { case "$1" in TESTDATE*) : ;; *) builtin echo "$@" ;; esac; }
		eval "$(cat "$PRO")"
		builtin echo "wasRunning=$wasRunning" >> "$OUT"
		rm -f "$CNT"
	) > /dev/null 2>&1
	cat "$out"; rm -f "$out"
}

r=$(runPrologue alive 2)          # alive for the guard, stops after the supervisor stop
case "$r" in
	*"supervisorctl stop valheimworld_ITToT1dot0nomods"*) ok "update: a running world is stopped, not refused" ;;
	*) bad "update: a running world was not stopped -- the branch spins on it forever" ;;
esac
case "$r" in
	*"mode='updating'"*) ok "update: it then proceeds with the update" ;;
	*) bad "update: the update never ran after stopping the world" ;;
esac
case "$r" in
	*"wasRunning=1"*) ok "update: it remembers the world was running" ;;
	*) bad "update: wasRunning not set, so the world will not be restarted" ;;
esac

r=$(runPrologue dead 0)
case "$r" in
	*supervisorctl*) bad "update: pointless stop issued for an already-stopped world" ;;
	*) ok "update: an already-stopped world is not stopped again" ;;
esac
case "$r" in
	*"wasRunning=0"*) ok "update: a stopped world is remembered as stopped" ;;
	*) bad "update: wasRunning set for a world that was not running" ;;
esac

r=$(runPrologue alive 999)        # never dies
case "$r" in
	*"mode='broken'"*) ok "update: an unstoppable world is marked broken, not retried forever" ;;
	*) bad "update: an unstoppable world leaves mode=update -- the 2s spin is back" ;;
esac
case "$r" in
	*"mode='updating'"*) bad "update: it updated a world it could not stop" ;;
	*) ok "update: a world that would not stop is NOT updated" ;;
esac

# The epilogue: restore what the operator had.
runEpilogue() {
	(
		wasRunning="$1"; worldName="W"
		SQL() { builtin echo "SQL $*"; }
		date() { builtin echo "TESTDATE"; }
		echo() { case "$1" in TESTDATE*) : ;; *) builtin echo "$@" ;; esac; }
		eval "$(cat "$EPI")"
	) 2>/dev/null
}
case "$(runEpilogue 1)" in
	*"mode='start'"*) ok "epilogue: a world that was running is started again" ;;
	*) bad "epilogue: a running world was left stopped after its update" ;;
esac
case "$(runEpilogue 0)" in
	*"mode='stopped'"*) ok "epilogue: a world that was stopped stays stopped" ;;
	*) bad "epilogue: an update started a world the operator had stopped" ;;
esac

# The refusal that caused the spin must be gone entirely.
check "the spin-forever refusal text is gone" \
	"$(grep -c 'Stop the world before updating' "$ENGINE")" "0"

rm -f "$BLOCK" "$PRO" "$EPI"

echo
echo "=== $pass passed, $fail failed ==="
[ "$fail" -eq 0 ]
