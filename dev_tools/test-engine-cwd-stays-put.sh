#!/bin/bash
# Oracle test: packaging a world never moves the ENGINE's working directory.
#
# THE BUG, reported by an operator who could not place it:
#
#     shell-init: error retrieving current directory: getcwd: cannot access parent directories
#     chdir: error retrieving current directory: getcwd: cannot access parent directories
#
# packageClient() did a bare `cd "$stage"` with nothing to put it back, and so did
# packageClientConfig(). Two cd sites in the whole engine, zero ways back. packageClient() is
# called from the MAIN LOOP -- a bash process that lives for weeks -- so after the first
# package the engine's cwd sat inside whichever world it had packaged last. Delete or
# re-create that world and the cwd became a dead inode, after which every child process bash
# spawned printed those two lines at startup, forever, until the engine restarted. Measured on
# a live server: /proc/<engine>/cwd -> .../worlds/Midgard/client.
#
# Non-fatal, which is exactly why it survived: the engine keeps working, so nothing fails
# loudly enough to investigate, and the noise lands in the log an operator sends when
# something ELSE is wrong.
#
# Part 1 is BEHAVIOURAL and is the one that matters: it runs the real function with everything
# else stubbed and compares $PWD before and after. A source-level check for "is there a cd"
# cannot see whether the cd is scoped, and scoping is the entire fix.
#
# Usage:  bash dev_tools/test-engine-cwd-stays-put.sh

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
F="$REPO/container/engine/includes/0-functions.sh"

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== part 1: the engine's cwd survives a package (behavioural) =="

# Extract the two real functions.
FN=/tmp/phv-cwd-fns.sh
awk '/^function packageClient\(\)/{f=1} f{print} f&&/^}$/{exit}'       "$F" >  "$FN"
awk '/^function packageClientConfig\(\)/{f=1} f{print} f&&/^}$/{exit}' "$F" >> "$FN"
grep -q 'cd "\$stage"' "$FN" || { echo "FATAL: extraction is stale -- no cd \"\$stage\" found"; exit 1; }
pass "extracted packageClient + packageClientConfig from the real source"

# Drive packageClient with a real staging tree and everything else stubbed. The harness
# records $PWD on both sides of the call; that difference is the whole assertion.
run_pkg() {
	local stageExists="$1"
	STAGE_EXISTS="$stageExists" bash -c '
		set +e
		WORK=$(mktemp -d); cd "$WORK" || exit 9
		START_PWD="$PWD"

		mkdir -p "$WORK/stageroot/w1/client/BepInEx/config"
		: > "$WORK/stageroot/w1/client/doorstop_config.ini"
		: > "$WORK/stageroot/w1/client/start_game_bepinex.sh"
		: > "$WORK/stageroot/w1/client/winhttp.dll"
		mkdir -p "$WORK/stageroot/w1/client/doorstop_libs"
		[ "$STAGE_EXISTS" = "0" ] && rm -rf "$WORK/stageroot/w1/client"

		worldsDirectoryRoot="$WORK/stageroot"
		clientStagingRoot() { echo "$worldsDirectoryRoot/$1/client"; }

		# Stubs. Each one is silent and successful so the only thing under test is the cd.
		writeClientManifest() { :; }
		setModsMD5()          { :; }
		setConfigMD5()        { :; }
		getMD5()              { echo d41d8cd98f00b204e9800998ecf8427e; }
		materialiseModConfigs(){ :; }
		zip() { : ; }          # a no-op zip: success, writes nothing
		date() { echo "DATE"; }

		. '"$FN"'

		packageClient w1 >/dev/null 2>&1
		rc=$?

		# getcwd AFTER. If the function moved us, this differs -- and if it moved us into a
		# directory that was then removed, pwd fails outright, which is the production symptom.
		END_PWD=$(pwd 2>/dev/null) || END_PWD="<getcwd FAILED>"
		echo "rc=$rc start=$START_PWD end=$END_PWD"
		cd /; rm -rf "$WORK"
	'
}

out=$(run_pkg 1)
start=$(sed -E 's/.*start=([^ ]*).*/\1/' <<<"$out")
end=$(sed -E 's/.*end=(.*)$/\1/' <<<"$out")
if [ -z "$start" ]; then
	fail "harness did not run" "$out"
elif [ "$end" = "<getcwd FAILED>" ]; then
	fail "getcwd FAILED after packaging" "this is the production symptom, reproduced"
elif [ "$start" = "$end" ]; then
	pass "cwd unchanged across packageClient ($end)"
else
	fail "packageClient MOVED the engine's cwd" "before: $start   after: $end"
fi

# The control: the same harness on a tree that cannot be entered. It must still not move us,
# and it must still fail. Without this, "cwd unchanged" could just mean the function bailed
# before reaching the cd on the happy path too.
out=$(run_pkg 0)
start=$(sed -E 's/.*start=([^ ]*).*/\1/' <<<"$out")
end=$(sed -E 's/.*end=(.*)$/\1/' <<<"$out")
rc=$(sed -E 's/.*rc=([0-9]+).*/\1/' <<<"$out")
[ "$start" = "$end" ] \
	&& pass "cwd unchanged when the staging tree is missing too" \
	|| fail "a failed package moved the cwd" "before: $start after: $end"
[ "$rc" != "0" ] \
	&& pass "and a missing staging tree is still fatal (rc=$rc)" \
	|| fail "a missing staging tree returned success" "the un-enterable case must stay fatal"

echo
echo "== part 2: THE REGRESSION ASSERTION -- no one-way cd in the engine =="

# Any `cd` at statement level inside these files is one-way. The fix is that both are inside
# a subshell, so the pattern to forbid is a cd that is NOT preceded by `( `.
oneway=$(grep -nE '^[[:space:]]+cd "' "$F" "$REPO/container/engine/phvalheim" 2>/dev/null \
         | grep -vE '\(\s*cd ' | grep -v '^\s*#')
if [ -n "$oneway" ]; then
	fail "an unscoped cd exists in the engine" "$oneway"
else
	pass "no unscoped 'cd \"...\"' in 0-functions.sh or the main loop"
fi

n=$(grep -cE '^\s+\( cd "\$stage" && zip' "$F")
[ "$n" = "2" ] \
	&& pass "both zip invocations are subshell-scoped ($n)" \
	|| fail "$n of 2 zip calls are subshell-scoped"

n=$(grep -cE 'if ! \( cd "\$stage" \)' "$F")
[ "$n" = "2" ] \
	&& pass "both sites still probe cd-ability first, so the failure stays named and fatal" \
	|| fail "$n of 2 sites probe the staging tree before zipping"

echo
echo "== part 3: the subshell only works if the zip targets are ABSOLUTE =="

# A relative $zipPath would be created inside $stage instead of the world directory. The old
# code cd'd too, so this requirement is not new -- but it is now load-bearing in a place that
# reads like it could be relative, so it gets pinned.
for v in 'local zipPath="/opt/stateful/games/valheim/worlds' 'local zipPath="$worldsDirectoryRoot'; do
	grep -qF "$v" "$F" && pass "a zip target is rooted absolutely ($(cut -c1-40 <<<"$v")...)" \
	                   || fail "could not confirm an absolute zip target" "$v"
done
grep -qF 'local tmpZip="$zipPath.tmp"' "$F" \
	&& pass "tmpZip derives from the absolute zipPath" \
	|| fail "tmpZip is not derived from zipPath"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
