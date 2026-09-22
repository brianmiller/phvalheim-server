#!/bin/bash
# 2.50: a failed Valheim update must not be reported as a success.
#
# The bug: InstallAndUpdateValheim decided a steamcmd run had worked by testing
# `[ -f valheim_server.x86_64 ]` and nothing else. steamcmd reports a partial update as
#
#     Error! App 896660 state is 0x6 after update job.
#
# and 0x6 is StateFullyInstalled|StateUpdateRequired -- the binary IS on disk. So the
# check answered "success" identically whether the update had worked or not, which is the
# definition of a non-oracle. Worse, setting steamcmdSuccess=true also exited the retry
# loop, so the five retries never ran for the exact fault they exist for.
#
# This test drives the SHIPPED functions out of container/engine/includes/0-functions.sh
# against fixture manifests. It does not reimplement the decision -- reimplementing it
# would pass against a broken engine, which is the failure mode being fixed.
#
# Mutation check: replace valheimInstallVerdict with `[ -f .../valheim_server.x86_64 ]`
# and cases 2, 3 and 6 below go red. Confirmed before shipping.
#
# Run: ./dev_tools/test-steamcmd-install-verdict.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Overridable so the mutation check can point this at a deliberately-broken copy without
# touching the working tree. Defaults to the real thing.
FUNCS="${FUNCS_UNDER_TEST:-$REPO/container/engine/includes/0-functions.sh}"

pass=0
fail=0

# Source ONLY the two functions under test. 0-functions.sh as a whole pulls in the engine
# config and talks to the database at load time, which a unit test has no business doing.
eval "$(awk '/^function valheimAppStateFlags\(\)/,/^}/' "$FUNCS")"
eval "$(awk '/^function valheimInstallVerdict\(\)/,/^}/' "$FUNCS")"

if ! declare -f valheimInstallVerdict > /dev/null; then
	echo "FAIL  could not extract valheimInstallVerdict from $FUNCS"
	echo "      (renamed? then this test is asserting nothing and must be updated)"
	exit 1
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
worldsDirectoryRoot="$TMP"

# $1=world  $2=StateFlags value, or the word "none" for no manifest at all
# $3="nobinary" to omit valheim_server.x86_64
makeWorld() {
	mkdir -p "$TMP/$1/game/steamapps"
	[ "$3" = "nobinary" ] || touch "$TMP/$1/game/valheim_server.x86_64"
	[ "$2" = "none" ] && return
	cat > "$TMP/$1/game/steamapps/appmanifest_896660.acf" <<ACF
"AppState"
{
	"appid"		"896660"
	"Universe"		"1"
	"name"		"Valheim Dedicated Server"
	"StateFlags"		"$2"
	"buildid"		"20460518"
}
ACF
}

# $1=label  $2=world  $3=expected verdict
check() {
	valheimInstallVerdict "$2"
	local got=$?
	if [ "$got" = "$3" ]; then
		echo "PASS  $1 (verdict $got)"
		pass=$((pass + 1))
	else
		echo "FAIL  $1 -- expected verdict $3, got $got"
		fail=$((fail + 1))
	fi
}

# 1. The healthy case. StateFlags 4 = StateFullyInstalled, nothing outstanding.
makeWorld clean 4
check "state 4 (fully installed) is a success" clean 0

# 2. THE REPORTED BUG. 0x6 with the binary present -- the old check said success here.
makeWorld broken 6
check "state 6 (update required) is a FAILURE" broken 1

# 3. Update queued on top of installed: 4|8 = 12. Also not finished.
makeWorld queued 12
check "state 12 (update queued) is a FAILURE" queued 1

# 4. Files missing, 4|32 = 36. Installed bit set and the tree is still incomplete.
makeWorld missingfiles 36
check "state 36 (files missing) is a FAILURE" missingfiles 1

# 5. Uninstalled, 1. Nothing to run.
makeWorld uninstalled 1
check "state 1 (uninstalled) is a FAILURE" uninstalled 1

# 5b. 4|16 = 20, StateUpdateOptional. An OPTIONAL update being on offer says nothing about
#     whether this install finished. Failing here would stop healthy worlds, so 20 must be
#     a success -- this is the case that stops the bad-bit mask from being "flags != 4".
makeWorld optional 20
check "state 20 (optional update available) is a success" optional 0

# 6. No manifest at all. Genuinely UNKNOWN -- must be its own verdict, not silently
#    folded into either success or failure. A 0/false default doubling as a real answer
#    is how "unknown" shipped as "up to date" three times in one release.
makeWorld nomanifest none
check "missing manifest is unverifiable, not success" nomanifest 2

# 7. No binary at all: failure regardless of what any manifest claims.
makeWorld nobinary 4 nobinary
check "missing server binary is a FAILURE" nobinary 1

# 8. The parser itself, against real whitespace. A manifest whose StateFlags cannot be
#    read would silently degrade every case above to verdict 2.
makeWorld parse 4
got=$(valheimAppStateFlags parse)
if [ "$got" = "4" ]; then
	echo "PASS  StateFlags parsed out of a tab-indented acf (got $got)"
	pass=$((pass + 1))
else
	echo "FAIL  StateFlags parse -- expected 4, got '$got'"
	fail=$((fail + 1))
fi

# 9. NEGATIVE: the old non-oracle must be gone from the install path. If someone puts a
#    bare binary-existence success back, every case above still passes.
if grep -q 'steamcmdSuccess=true' "$FUNCS" && \
   grep -B2 'steamcmdSuccess=true' "$FUNCS" | grep -q 'valheim_server.x86_64.*\]; then'; then
	echo "FAIL  a bare valheim_server.x86_64 existence test still sets steamcmdSuccess"
	fail=$((fail + 1))
else
	echo "PASS  no bare binary-existence success remains in the install path"
	pass=$((pass + 1))
fi

echo
echo "passed=$pass failed=$fail"
[ "$fail" = "0" ] || exit 1
exit 0
