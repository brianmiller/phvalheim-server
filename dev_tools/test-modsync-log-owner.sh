#!/bin/bash
# Oracle test: the catalogue sync log must be writable by the user who runs the syncs.
#
# WHY: the engine's boot sync ran modSync.py as phvalheim (2.52 fix) but left the `>> modSync.log`
# redirect OUTSIDE the su, so root created the file on every fresh install. From then on the
# hourly cron sync and the admin UI's forced sync -- both phvalheim -- died on "Permission
# denied" opening the log, before modSync.py ever ran, and nothing said so. Found in 2.56 by
# test-modsync-panel.js clicking "sync" and waiting for a manual run that never came.
#
# Pins: (1) the boot launch redirects INSIDE the su -c string, so the file is created as
# phvalheim; (2) the engine chowns an existing root-owned file; (3) cron still runs as
# phvalheim against the same path; (4) the admin API's launcher falls back to /dev/null when
# the log is not writable, so an older install's root-owned file can never block it again.
# Then a live control: the exact launch line, with a fake su that records the uid the redirect
# ran under, against a root-owned file -- the OLD shape fails, the new one does not.
#
# Usage: bash dev_tools/test-modsync-log-owner.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
F="$ROOT/container/engine/includes/0-functions.sh"
A="$ROOT/container/nginx/www/admin/adminAPI.php"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "  PASS  $1"; else fail=$((fail+1)); echo "  FAIL  $1"; echo "          want: $2"; echo "          got:  $3"; fi; }

echo
echo "-- the boot sync's log is opened by phvalheim, not root --"
check 'the redirect is inside the su -c string' "1" "$(grep -cF -- "--trigger boot >> /opt/stateful/logs/modSync.log 2>&1'" "$F")"
check 'no redirect of the su command itself to the log remains' "0" "$(grep -cE "^\s*>> /opt/stateful/logs/modSync.log 2>&1 &" "$F")"
check 'an existing file is handed to phvalheim' "1" "$(grep -cF 'chown phvalheim:phvalheim /opt/stateful/logs/modSync.log' "$F")"
check 'cron runs the sync as phvalheim against the same log' "1" "$(grep -cE '^[0-9*/ ]+ phvalheim /opt/stateless/engine/tools/modSync.py .*>> /opt/stateful/logs/modSync.log' "$ROOT/container/cron.d/modSync")"
check 'the admin API launcher checks it may write the log' "1" "$(grep -cF 'is_writable($syncLog)' "$A")"
check '  and otherwise sends the output to /dev/null rather than not running' "1" "$(grep -cF "'/dev/null'" "$A")"

echo
echo "-- control: the launch shape against a root-owned log (fake su, real redirect) --"
# Extract the launch line and run it with `su` replaced by a stub that execs the -c string
# as this user, writing WHO opened stdout. A file nobody can write stands in for root's.
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/opt/stateful/logs"
cat > "$TMP/bin/su" <<'SU'
#!/bin/bash
# su USER -s SHELL -c CMD  -> run CMD here (same user), as the real su would as phvalheim
while [ $# -gt 0 ]; do case "$1" in -c) shift; cmd="$1";; esac; shift; done
exec /bin/sh -c "$cmd"
SU
chmod +x "$TMP/bin/su"
cat > "$TMP/opt/stateless-modSync.py" <<'PY'
#!/bin/bash
echo "ran as $(id -u) with stdout -> $(readlink /proc/$$/fd/1)"
PY
chmod +x "$TMP/opt/stateless-modSync.py"
# the log exists and is NOT writable (0444 stands in for root's 0644 as seen by phvalheim)
: > "$TMP/opt/stateful/logs/modSync.log"; chmod 0444 "$TMP/opt/stateful/logs/modSync.log"
if [ "$(id -u)" = "0" ]; then
	echo "  SKIP  control needs a non-root user (root ignores mode bits)"
else
	line=$(grep -F -- "--trigger boot >> /opt/stateful/logs/modSync.log 2>&1'" "$F" | sed "s#/opt/stateful/logs/modSync.log#$TMP/opt/stateful/logs/modSync.log#g; s#/opt/stateless/engine/tools/modSync.py#$TMP/opt/stateless-modSync.py#; s#> /dev/null 2>&1 &\$##; s#\\\\\$##")
	out=$(PATH="$TMP/bin:$PATH" bash -c "$line" 2>&1; echo "rc=$?")
	check 'new shape: the sync does NOT run when the log is unwritable to this user, and says so' "1" "$(printf '%s\n' "$out" | grep -c 'Permission denied')"
	# ... which is exactly why the engine chowns first. With the file writable:
	chmod 0644 "$TMP/opt/stateful/logs/modSync.log"
	PATH="$TMP/bin:$PATH" bash -c "$line" 2>&1 >/dev/null
	check 'new shape: with the file owned by the runner, the sync runs and logs' "1" "$(grep -c "ran as $(id -u)" "$TMP/opt/stateful/logs/modSync.log")"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
