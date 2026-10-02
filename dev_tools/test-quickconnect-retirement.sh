#!/bin/bash
#
# The QuickConnect retirement in dbUpdate_2.53.sh, tested for the three things that can go
# wrong and are invisible at runtime.
#
# 1. It must RUN. Brian updated a world with companionProvidesConnect=1 and QuickConnect was
#    still in plugins/ afterwards, on both trees. The plugin files are purged before every
#    rebuild, so it looked handled -- but world_mods is the source of truth and the reinstall
#    read the row straight back. A migration that never fires looks exactly like this.
#
# 2. It must run ONCE. QuickConnect is an ordinary Thunderstore mod an operator may want. The
#    schema cannot distinguish "PhValheim inserted this" from "the operator picked it", so a
#    delete on every boot would silently strip a deliberate choice every two seconds.
#
# 3. It must be OWNER-SCOPED. The live catalogue carries FOUR QuickConnect packages from four
#    owners (bdew, HouseAtreides, ValheimEnjoyers, GillianAprils). PhValheim only ever
#    installed bdew's. Matching on name alone strips three unrelated operators' picks.
#
# Driven by stubbing sql(), so it tests the DECISIONS rather than needing a live MariaDB. The
# queries themselves are asserted literally, which is what catches an owner guard going away.

set -u
cd "$(dirname "$0")/.." || exit 1

MIG=container/engine/dbUpdates/dbUpdate_2.53.sh
pass=0; fail=0
ok()  { pass=$((pass+1)); printf '  ok    %s\n' "$1"; }
bad() { fail=$((fail+1)); printf '  FAIL  %s -- %s\n' "$1" "$2"; }

[ -f "$MIG" ] || { echo "  FAIL  $MIG missing"; exit 1; }

# Pull just the retirement block out of the migration and run it against a stub. Taking the
# real text rather than a copy means the test cannot drift from the code it guards.
block=$(sed -n '/--- QuickConnect is retired from worlds/,/^## END UPDATE ##/p' "$MIG" | sed '/^## END UPDATE ##/d')
if [ -z "$block" ]; then
	echo "  FAIL  could not find the QuickConnect retirement block in $MIG"
	exit 1
fi

# $1 = initial quickConnectRetired value, $2 = row count the COUNT(*) should report.
# Emits every statement the block issued, one per line, on stdout.
runBlock() {
	local retired="$1" rows="$2"
	RETIRED="$retired" ROWS="$rows" bash -c '
		sql() {
			echo "SQL: $1" >> "$TRACE"
			case "$1" in
				"DESCRIBE settings")  printf "id\nquickConnectRetired\n" ;;
				*quickConnectRetired*FROM\ settings*) echo "$RETIRED" ;;
				*COUNT*QuickConnect*) echo "$ROWS" ;;
			esac
		}
		'"$block"'
	' 2>/dev/null
}

echo "== 1. a server that still has QuickConnect rows =="
TRACE=$(mktemp); export TRACE
runBlock 0 8 >/dev/null
if grep -q "SQL: DELETE wm FROM world_mods" "$TRACE"; then
	ok "issues the DELETE when the flag is 0 and rows exist"
else
	bad "DELETE" "not issued -- QuickConnect would survive every update"
fi
if grep -q "SQL: UPDATE settings SET quickConnectRetired = 1" "$TRACE"; then
	ok "sets the one-shot flag"
else
	bad "one-shot flag" "never set -- this would re-run on every boot"
fi
# The guard that matters: owner AND name.
if grep -q "m.owner = 'bdew' AND m.name = 'QuickConnect'" "$TRACE"; then
	ok "scopes the DELETE to bdew/QuickConnect"
else
	bad "owner scope" "DELETE is not owner-scoped; it would strip the other three owners' QuickConnect packages"
fi
rm -f "$TRACE"

echo
echo "== 2. the SAME server on its next boot (flag already 1) =="
TRACE=$(mktemp); export TRACE
runBlock 1 8 >/dev/null
if grep -q "SQL: DELETE wm FROM world_mods" "$TRACE"; then
	bad "second boot" "issues the DELETE again -- an operator who re-added QuickConnect would lose it every two seconds"
else
	ok "issues NO DELETE once retired"
fi
if grep -q "SQL: UPDATE settings SET quickConnectRetired = 1" "$TRACE"; then
	bad "second boot" "re-writes the flag on a steady-state boot"
else
	ok "does not re-write the flag"
fi
rm -f "$TRACE"

echo
echo "== 3. a server that never had QuickConnect =="
TRACE=$(mktemp); export TRACE
runBlock 0 0 >/dev/null
if grep -q "SQL: DELETE wm FROM world_mods" "$TRACE"; then
	bad "no rows" "issues a DELETE with nothing to delete"
else
	ok "issues no DELETE when there is nothing to retire"
fi
if grep -q "SQL: UPDATE settings SET quickConnectRetired = 1" "$TRACE"; then
	ok "still marks itself done, so it does not re-check forever"
else
	bad "no rows" "leaves the flag at 0 -- the COUNT would run on every boot for ever"
fi
rm -f "$TRACE"

echo
echo "== 4. CONTROL -- the stub really drives the decision =="
# If runBlock ignored its arguments, cases 1 and 2 could both pass by accident. This proves
# the flag value is what changes the outcome.
TRACE=$(mktemp); export TRACE; runBlock 0 8 >/dev/null; a=$(grep -c "DELETE wm" "$TRACE"); rm -f "$TRACE"
TRACE=$(mktemp); export TRACE; runBlock 1 8 >/dev/null; b=$(grep -c "DELETE wm" "$TRACE"); rm -f "$TRACE"
if [ "$a" -gt 0 ] && [ "$b" -eq 0 ]; then
	ok "flag=0 deletes, flag=1 does not (delete count $a vs $b)"
else
	bad "control" "the flag does not change the outcome (flag0=$a flag1=$b) -- every result above is meaningless"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
exit 0
