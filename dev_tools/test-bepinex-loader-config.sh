#!/bin/bash
#
# 2.49 -- the loader's BepInEx.cfg must survive a world rebuild, and must have the console on.
#
# Both symptoms 2.49 fixes come from ONE file going missing: BepInEx/config/BepInEx.cfg.
# purgeWorldModsConfigsPatchers() swept it with the mod configs, so every rebuilt world booted
# on BepInEx's stock defaults where [Logging.Console] is false. That killed the plugin lines in
# the world log (BepInEx's console logger writes to stdout, which supervisor captures) and the
# client's console window (packageClient() zips ./BepInEx wholesale, cfg and all).
#
# THIS TEST IS AN ORACLE: every case below fails against the pre-2.49 code. Case 1 fails
# because the purge deleted the cfg; cases 2-5 fail because ensureBepInExLoaderConfig() did not
# exist. If you change the implementation and these still pass without you meaning them to,
# check the extraction below is still finding the functions.

set -u

REPO="$(cd "$(dirname "$0")/.." && pwd)"
FUNCS="$REPO/container/engine/includes/0-functions.sh"
SANDBOX=$(mktemp -d)
trap 'rm -rf "$SANDBOX"' EXIT

fails=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; fails=$((fails + 1)); }

# Pull just the two functions under test out of 0-functions.sh. Sourcing the whole file would
# drag in phvalheim-static.conf, the database and the container's paths.
extract() {
	awk -v fn="$1" '
		$0 ~ "^function " fn "\\(\\)" { depth = 1; print; next }
		depth > 0 {
			print
			depth += gsub(/{/, "{")
			depth -= gsub(/}/, "}")
			if (depth <= 0) exit
		}
	' "$FUNCS"
}

{
	echo 'worldsDirectoryRoot="'"$SANDBOX"'/worlds"'
	echo 'function chown() { :; }'   # no phvalheim user in the sandbox
	extract purgeWorldModsConfigsPatchers
	extract ensureBepInExLoaderConfig
} > "$SANDBOX/harness.sh"

for fn in purgeWorldModsConfigsPatchers ensureBepInExLoaderConfig; do
	grep -q "^function $fn()" "$SANDBOX/harness.sh" || {
		echo "FATAL: could not extract $fn() from 0-functions.sh -- the test is not testing anything."
		exit 1
	}
done

# shellcheck disable=SC1090
source "$SANDBOX/harness.sh"

# The cfg the pack actually ships, trimmed to the sections that matter.
packCfg() {
	printf '%s\n' \
		'[Logging]' '' 'UnityLogListening = true' '' \
		'[Logging.Console]' '' 'Enabled = true' '' 'LogLevels = Fatal, Error, Warning, Message, Info' '' \
		'[Logging.Disk]' '' 'Enabled = true'
}

newWorld() {
	local w="$1"
	rm -rf "$SANDBOX/worlds/$w"
	mkdir -p "$SANDBOX/worlds/$w/game/BepInEx/config" \
	         "$SANDBOX/worlds/$w/game/BepInEx/plugins" \
	         "$SANDBOX/worlds/$w/game/BepInEx/patchers"
}

# Reads Enabled from ONE named section, so a true in [Logging.Disk] cannot be mistaken for a
# true in [Logging.Console]. That confusion is the whole reason the fix is section-scoped.
consoleEnabled() {
	awk '
		/^[[:space:]]*\[/ { section = $0; next }
		section ~ /^[[:space:]]*\[Logging\.Console\]/ && /^[[:space:]]*Enabled[[:space:]]*=/ {
			sub(/^[[:space:]]*Enabled[[:space:]]*=[[:space:]]*/, ""); sub(/[[:space:]]*$/, "")
			print tolower($0); exit
		}
	' "$1"
}

echo "--- 1. the purge clears mod configs but keeps the loader's cfg ---"
newWorld w1
packCfg > "$SANDBOX/worlds/w1/game/BepInEx/config/BepInEx.cfg"
echo 'stale' > "$SANDBOX/worlds/w1/game/BepInEx/config/some_removed_mod.cfg"
mkdir -p "$SANDBOX/worlds/w1/game/BepInEx/config/SomeMod"
echo 'stale' > "$SANDBOX/worlds/w1/game/BepInEx/config/SomeMod/nested.cfg"
echo 'plugin' > "$SANDBOX/worlds/w1/game/BepInEx/plugins/Mod.dll"
echo 'patcher' > "$SANDBOX/worlds/w1/game/BepInEx/patchers/P.dll"

purgeWorldModsConfigsPatchers w1 > /dev/null 2>&1

[ -f "$SANDBOX/worlds/w1/game/BepInEx/config/BepInEx.cfg" ] \
	&& pass "BepInEx.cfg survived the purge" \
	|| fail "BepInEx.cfg was deleted by the purge -- this is the 2.49 bug"
[ ! -f "$SANDBOX/worlds/w1/game/BepInEx/config/some_removed_mod.cfg" ] \
	&& pass "a removed mod's config was cleared" \
	|| fail "mod configs are no longer being cleared -- the purge lost its actual job"
[ ! -d "$SANDBOX/worlds/w1/game/BepInEx/config/SomeMod" ] \
	&& pass "a mod's config subdirectory was cleared" \
	|| fail "mod config subdirectories survive the purge"
[ ! -f "$SANDBOX/worlds/w1/game/BepInEx/plugins/Mod.dll" ] \
	&& pass "plugins were cleared" || fail "plugins survived the purge"
[ ! -f "$SANDBOX/worlds/w1/game/BepInEx/patchers/P.dll" ] \
	&& pass "patchers were cleared" || fail "patchers survived the purge"

echo "--- 2. a missing cfg is restored from the pack stash ---"
newWorld w2
packCfg > "$SANDBOX/worlds/w2/game/bepinex_default.cfg"
ensureBepInExLoaderConfig w2 > /dev/null 2>&1
if [ -f "$SANDBOX/worlds/w2/game/BepInEx/config/BepInEx.cfg" ] && [ "$(consoleEnabled "$SANDBOX/worlds/w2/game/BepInEx/config/BepInEx.cfg")" = "true" ]; then
	pass "restored from the stash with the console on"
else
	fail "a missing cfg was not restored from the stash"
fi

echo "--- 3. no stash, no cfg: a minimal one is written ---"
newWorld w3
ensureBepInExLoaderConfig w3 > /dev/null 2>&1
[ "$(consoleEnabled "$SANDBOX/worlds/w3/game/BepInEx/config/BepInEx.cfg")" = "true" ] \
	&& pass "wrote a minimal cfg with the console on" \
	|| fail "no cfg written when there is no stash -- worlds on an up-to-date pack stay broken"

echo "--- 4. an existing cfg with the console OFF is corrected, section-scoped ---"
newWorld w4
printf '%s\n' \
	'[Logging.Console]' '' 'Enabled = false' '' \
	'[Logging.Disk]' '' 'Enabled = true' > "$SANDBOX/worlds/w4/game/BepInEx/config/BepInEx.cfg"
ensureBepInExLoaderConfig w4 > /dev/null 2>&1
[ "$(consoleEnabled "$SANDBOX/worlds/w4/game/BepInEx/config/BepInEx.cfg")" = "true" ] \
	&& pass "console flipped to true" \
	|| fail "a stock cfg kept the console off -- BepInEx rewrites this file every boot"
# The Disk section must be untouched: both sections have a key spelled 'Enabled'.
diskEnabled=$(awk '
	/^[[:space:]]*\[/ { section = $0; next }
	section ~ /^[[:space:]]*\[Logging\.Disk\]/ && /^[[:space:]]*Enabled[[:space:]]*=/ {
		sub(/^[[:space:]]*Enabled[[:space:]]*=[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print tolower($0); exit
	}' "$SANDBOX/worlds/w4/game/BepInEx/config/BepInEx.cfg")
[ "$diskEnabled" = "true" ] \
	&& pass "[Logging.Disk] was left alone" \
	|| fail "the rewrite leaked into [Logging.Disk] (got '$diskEnabled')"

echo "--- 5. a cfg with no [Logging.Console] section at all gains one ---"
newWorld w5
printf '%s\n' '[Logging]' '' 'UnityLogListening = true' > "$SANDBOX/worlds/w5/game/BepInEx/config/BepInEx.cfg"
ensureBepInExLoaderConfig w5 > /dev/null 2>&1
[ "$(consoleEnabled "$SANDBOX/worlds/w5/game/BepInEx/config/BepInEx.cfg")" = "true" ] \
	&& pass "the missing section was appended" \
	|| fail "no [Logging.Console] section was added"

echo "--- 6. running twice changes nothing (idempotent) ---"
before=$(cat "$SANDBOX/worlds/w4/game/BepInEx/config/BepInEx.cfg")
ensureBepInExLoaderConfig w4 > /dev/null 2>&1
[ "$before" = "$(cat "$SANDBOX/worlds/w4/game/BepInEx/config/BepInEx.cfg")" ] \
	&& pass "a second run is a no-op" \
	|| fail "the cfg keeps changing on every run -- it would grow a section per rebuild"

echo "--- 7. a vanilla world is left without a BepInEx tree ---"
rm -rf "$SANDBOX/worlds/w6"; mkdir -p "$SANDBOX/worlds/w6/game"
ensureBepInExLoaderConfig w6 > /dev/null 2>&1
[ ! -d "$SANDBOX/worlds/w6/game/BepInEx" ] \
	&& pass "vanilla world untouched" \
	|| fail "a BepInEx tree was created on a vanilla world -- that is the one thing it is defined by not having"

echo
if [ "$fails" -eq 0 ]; then
	echo "ALL BEPINEX LOADER CONFIG TESTS PASSED"
	exit 0
fi
echo "$fails BEPINEX LOADER CONFIG TEST(S) FAILED"
exit 1
