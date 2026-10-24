#!/bin/bash
# Oracle test: startWorld.sh and the supervisor program agree on the command line (2.58).
#
# WHAT IT GUARDS. createSupervisorWorldConfig() used to write
#     startWorld.sh $worldName $worldPassword $worldPort      (unquoted)
# and startWorld.sh demanded three arguments. The password argument has been dead since 2.53
# (the script reads the real one from the database), and "no password" is a supported state
# since 2.55 -- so for a no-password world the empty argument vanished, the port slid into its
# place, and startWorld.sh refused with "Missing arguments". Any such world whose program was
# (re)generated could never start; the dev volume hit it on 2026-10-23 the moment the engine
# learned to regenerate a missing program. The program is now NAME PORT, quoted, and the
# script accepts both that and the legacy three-argument shape.
#
# HOW. The argument block is run in isolation (sliced out of the real script by its two
# anchors) with each shape, and the template line is read for what it would write.

set -u
cd "$(dirname "$0")/.." || exit 1
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; fail=$((fail+1)); }

SCRIPT=container/games/valheim/scripts/startWorld.sh
FUNCS=container/engine/includes/0-functions.sh

echo "=== startWorld.sh argument shapes ==="

# The argument block, from its first `if [ -n "$3" ]` to the matching `fi`.
block=$(awk '/^if \[ -n "\$3" \]; then/{p=1} p{print} p&&/^fi$/{exit}' "$SCRIPT")
[ -n "$block" ] && ok "the argument block is where this test expects it" || { bad "the argument block is where this test expects it" "anchor 'if [ -n \"\$3\" ]; then' not found"; echo; echo "$pass passed, $fail failed"; exit 1; }

shape() { # $1 label, then args
	local label="$1"; shift
	bash -c "$block"'; echo "name=$worldName port=$worldPort"' _ "$@" 2>/dev/null | tail -1
}
[ "$(shape two midgard 25000)" = "name=midgard port=25000" ] && ok "NAME PORT -> name=midgard port=25000 (what 2.58 programs carry)" || bad "NAME PORT parses" "$(shape two midgard 25000)"
[ "$(shape three midgard s3cret 25000)" = "name=midgard port=25000" ] && ok "NAME PASSWORD PORT -> same values (programs written before 2.58)" || bad "legacy NAME PASSWORD PORT parses" "$(shape three midgard s3cret 25000)"
out=$(bash -c "$block" _ midgard 2>&1); rc=$?
[ "$rc" != 0 ] && echo "$out" | grep -q "Missing arguments" && ok "NAME alone is refused with 'Missing arguments' (exit $rc)" || bad "NAME alone is refused" "rc=$rc out=$(echo "$out" | head -1)"
# THE BUG: the old unquoted template with an empty password produced exactly this call.
[ "$(shape empty midgard 25000)" = "name=midgard port=25000" ] && ok "control: the shape an empty password used to produce (NAME PORT) now starts the world" || bad "control" "$(shape empty midgard 25000)"

# The template writes NAME PORT, quoted, and no password.
line=$(grep -E '^\s*command=/opt/stateless/games/valheim/scripts/startWorld.sh' "$FUNCS")
[ "$(echo "$line" | grep -c .)" = 1 ] && ok "one supervisor program template" || bad "one supervisor program template" "$line"
echo "$line" | grep -qF 'startWorld.sh \"$worldName\" \"$worldPort\"' && ok "the template is: startWorld.sh \"\$worldName\" \"\$worldPort\"" || bad "the template is name + port, quoted" "$line"
echo "$line" | grep -q 'worldPassword' && bad "the template no longer puts the password on the command line" "$line" || ok "the template no longer puts the password on the command line (nor in a file under conf.d)"
# ...and the script never reads a password from its arguments: the database is the source.
grep -qE '^\s*worldPassword="\$2"' "$SCRIPT" && bad "startWorld.sh does not take the password from argv" "worldPassword=\"\$2\" is back" || ok "startWorld.sh does not take the password from argv"
grep -qE 'worldPasswordDb' "$SCRIPT" && ok "startWorld.sh reads the password from the database (worldPasswordDb)" || bad "startWorld.sh reads the password from the database"

# The engine regenerates a missing program at start (the path that exposed all of this).
grep -qF 'createSupervisorWorldConfig "$worldName" "$healPassword" "$healPort"' container/engine/phvalheim && ok "the engine's start block regenerates a missing supervisor program" || bad "the engine's start block regenerates a missing supervisor program"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
