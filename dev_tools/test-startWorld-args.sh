#!/bin/bash
#
# Verifies the argument list startWorld.sh hands to valheim_server.x86_64.
#
# The point of this test is that it FAILS if the 2.40 vanilla work changed the modded
# path. A test that only asserted "the world launches" would pass either way, because a
# modded world launches fine with -public 1 or a stray -password too.
#
# Runs entirely on stubs -- no database, no Valheim, no container.

set -u

SCRIPT="$(cd "$(dirname "$0")/.." && pwd)/container/games/valheim/scripts/startWorld.sh"
SANDBOX="$(mktemp -d)"
trap 'rm -rf "$SANDBOX"' EXIT

PASS=0
FAIL=0

# Capture argv by standing in for both the `sql` tool and the game binary.
setup_stubs() {
	local settings_row="$1"
	local public_flag="$2"

	mkdir -p "$SANDBOX/opt/stateless/engine/tools"
	cat > "$SANDBOX/opt/stateless/engine/tools/sql" <<STUB
#!/bin/sh
case "\$1" in
	*"SELECT public FROM"*) printf '%s\n' '$public_flag' ;;
	*) printf '%s\n' '$settings_row' ;;
esac
STUB
	chmod +x "$SANDBOX/opt/stateless/engine/tools/sql"

	mkdir -p "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game"
	cat > "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64" <<'STUB'
#!/bin/sh
for a in "$@"; do printf '%s\n' "$a"; done
STUB
	chmod +x "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64"
}

# Run startWorld.sh with / rewritten to the sandbox, and print the argv it exec'd.
run_case() {
	local settings_row="$1" public_flag="$2"
	setup_stubs "$settings_row" "$public_flag"
	sed "s#/opt/#$SANDBOX/opt/#g" "$SCRIPT" > "$SANDBOX/startWorld.sh"
	chmod +x "$SANDBOX/startWorld.sh"
	sh "$SANDBOX/startWorld.sh" testworld hammertime 25000 2>/dev/null \
		| grep -v '^$' | grep -v 'phvalheim\]' | grep -v 'NOTICE\|ERROR'
}

# Same run, but WITHOUT the NOTICE/ERROR filtering above -- for asserting on what the script
# logged rather than on the argv it built.
run_case_log() {
	local settings_row="$1" public_flag="$2"
	setup_stubs "$settings_row" "$public_flag"
	sed "s#/opt/#$SANDBOX/opt/#g" "$SCRIPT" > "$SANDBOX/startWorld.sh"
	chmod +x "$SANDBOX/startWorld.sh"
	sh "$SANDBOX/startWorld.sh" testworld hammertime 25000 2>&1
}

check() {
	local label="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		echo "  PASS: $label"
		PASS=$((PASS+1))
	else
		echo "  FAIL: $label"
		echo "    expected: $(echo "$expected" | tr '\n' ' ')"
		echo "    actual:   $(echo "$actual" | tr '\n' ' ')"
		FAIL=$((FAIL+1))
	fi
}

SAVEDIR="$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/.config/unity3d/IronGate/Valheim"

echo "startWorld.sh argument regression"
echo

# --- 1. Modded world: must be byte-identical to pre-2.40 ---
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n0\n-savedir\n%s' "$SAVEDIR")
check "modded world args unchanged" "$expected" "$(run_case '0	0	0		' 0)"

# --- 2. public=1 (citizens gate off) must NOT leak into -public ---
# This is the upgrade-safety test. If someone reuses worlds.public for the server
# browser, every previously-opened world silently gets listed and this fails.
check "public=1 does not list the world" "$expected" "$(run_case '0	0	0		' 1)"

# --- 3. Vanilla, unlisted, no password: -password must be ABSENT, not empty ---
actual=$(run_case '1	0	0		' 0)
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n0\n-savedir\n%s' "$SAVEDIR")
check "vanilla without password omits the flag" "$expected" "$actual"
if echo "$actual" | grep -q -- '-password'; then
	echo "  FAIL: -password present with an empty value (Valheim refuses to boot)"
	FAIL=$((FAIL+1))
else
	echo "  PASS: no empty -password"
	PASS=$((PASS+1))
fi

# --- 3b. Crossplay applies to ANY world (2.53) ---
# This assertion has now been correct in THREE directions, so the reason matters more than
# the assertion:
#
#   Scoped vanilla-only by mistake, then widened to all worlds because crossplay really is
#   orthogonal to mods as far as VALHEIM is concerned, then scoped BACK to vanilla for a
#   client reason -- QuickConnect's config file is host:port and a PlayFab server has neither,
#   so a modded crossplay world could not be joined.
#
#   Widened again in 2.53, because the client never needed to do the connecting. Its job on a
#   modded world is the mod payload and the BepInEx injection, and the modded launch path
#   passes no connect argument at all. The player joins from Valheim's "Join by code" box.
#
# If this is ever scoped back a second time, the thing to change is the CLIENT's launch path,
# not this line.
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n0\n-crossplay\n-savedir\n%s' "$SAVEDIR")
check "modded world DOES get -crossplay" "$expected" "$(run_case '0	0	1		' 0)"

# CONTROL, and the important half of this pair: a modded world with crossplay=0 must NOT get
# the flag. Without this, deleting the gate by unconditionally appending -crossplay -- which
# is very nearly what the fix looks like -- passes the assertion above and breaks every
# non-crossplay world in the same stroke.
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n0\n-savedir\n%s' "$SAVEDIR")
check "modded world without crossplay does NOT get -crossplay" "$expected" "$(run_case '0	0	0		' 0)"

# The log has to carry the console caveat, because it is the one that ruins a world and the
# operator may never see the admin UI's warning again after they click Save. Anchored on
# "CANNOT run mods" rather than on the word "crossplay", which appears in the vanilla notice
# too and would pass for the wrong reason.
if run_case_log '0	0	1		' 0 | grep -q "CANNOT run mods"; then
	echo "  PASS: modded+crossplay logs the console-players-cannot-load-mods caveat"
	PASS=$((PASS+1))
else
	echo "  FAIL: modded+crossplay did not log the console mods caveat"
	FAIL=$((FAIL+1))
fi

# The old gate's message must be GONE. A negative: the fix is not done if the script still
# announces that it dropped the flag while also passing it.
if run_case_log '0	0	1		' 0 | grep -q "crossplay set but is MODDED\|crossplay is OFF"; then
	echo "  FAIL: startWorld.sh still logs the old vanilla-only crossplay refusal"
	FAIL=$((FAIL+1))
else
	echo "  PASS: the old vanilla-only crossplay refusal message is gone"
	PASS=$((PASS+1))
fi

# CONTROL: a VANILLA world with crossplay=1 must still get the flag.
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n0\n-crossplay\n-savedir\n%s' "$SAVEDIR")
check "vanilla world still honours crossplay" "$expected" "$(run_case '1	0	1		' 0)"

# --- 3c. .running-options must record crossplay for a MODDED world (2.53) ---
# Separate from the argv assertions because it is separate code, and because it was the half
# of the fix that was easy to miss: effectiveCrossplay used to be computed INSIDE the
# `if isVanilla` branch. A modded world would then be handed -crossplay above and record
# crossplay=0 here -- and since everything describing a LIVE world reads this file, every UI
# would have drawn a direct-connect link and no join code for a PlayFab server.
#
# Asserting on the file rather than on a UI is deliberate: this is the seam the UIs agree
# through, so it is the cheapest place to catch them disagreeing.
runopts="$SANDBOX/opt/stateful/games/valheim/worlds/testworld/.running-options"

run_case '0	0	1		' 0 >/dev/null 2>&1
if [ -f "$runopts" ] && grep -qx 'crossplay=1' "$runopts"; then
	echo "  PASS: modded+crossplay records crossplay=1 in .running-options"
	PASS=$((PASS+1))
else
	echo "  FAIL: modded+crossplay did not record crossplay=1 in .running-options"
	echo "    file: $(cat "$runopts" 2>/dev/null | tr '\n' ' ')"
	FAIL=$((FAIL+1))
fi

# CONTROL for the same file: crossplay=0 must still be recorded as 0, so a fix that
# hardcodes 1 fails here.
run_case '0	0	0		' 0 >/dev/null 2>&1
if [ -f "$runopts" ] && grep -qx 'crossplay=0' "$runopts"; then
	echo "  PASS: modded without crossplay records crossplay=0"
	PASS=$((PASS+1))
else
	echo "  FAIL: modded without crossplay did not record crossplay=0"
	echo "    file: $(cat "$runopts" 2>/dev/null | tr '\n' ' ')"
	FAIL=$((FAIL+1))
fi

# ...and a modded world must STILL never be listed or password-protected, which stayed inside
# the isVanilla branch. Guards against moving too much out of it along with crossplay.
run_case '0	1	1	hunter2secret	' 0 >/dev/null 2>&1
if grep -qx 'listed=0' "$runopts" && grep -qx 'passwordhash=' "$runopts"; then
	echo "  PASS: modded world still records listed=0 and no password hash"
	PASS=$((PASS+1))
else
	echo "  FAIL: modded world recorded a listing or a password it cannot have"
	echo "    file: $(cat "$runopts" 2>/dev/null | tr '\n' ' ')"
	FAIL=$((FAIL+1))
fi

# --- 4. Vanilla, listed, password, crossplay ---
expected=$(printf -- '-nographics\n-batchmode\n-name\ntestworld\n-port\n25000\n-world\ntestworld\n-oldconsole\n-public\n1\n-password\nhunter2secret\n-crossplay\n-savedir\n%s' "$SAVEDIR")
check "vanilla listed+password+crossplay" "$expected" "$(run_case '1	1	1	hunter2secret	' 0)"

# --- 5. Listed with no password must refuse to start ---
out=$(run_case '1	1	0		' 0)
if [ -z "$out" ]; then
	echo "  PASS: listed without password refuses to start"
	PASS=$((PASS+1))
else
	echo "  FAIL: listed without password started anyway"
	FAIL=$((FAIL+1))
fi

# --- 6. launch_params are appended as literal argv, never executed ---
rm -f /tmp/phvalheim_pwned
actual=$(run_case '0	0	0		; touch /tmp/phvalheim_pwned' 0)
if [ -e /tmp/phvalheim_pwned ]; then
	echo "  FAIL: launch_params executed as a shell command"
	FAIL=$((FAIL+1))
	rm -f /tmp/phvalheim_pwned
else
	echo "  PASS: launch_params not executed"
	PASS=$((PASS+1))
fi
if [ "$(echo "$actual" | tail -3 | head -1)" = ";" ]; then
	echo "  PASS: launch_params passed through as literal arguments"
	PASS=$((PASS+1))
else
	echo "  FAIL: launch_params not passed through (got: $(echo "$actual" | tail -3 | tr '\n' ' '))"
	FAIL=$((FAIL+1))
fi

# --- 7. Vanilla must not export doorstop ---
setup_stubs '1	0	0		' 0
sed "s#/opt/#$SANDBOX/opt/#g" "$SCRIPT" > "$SANDBOX/startWorld.sh"
cat > "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64" <<'STUB'
#!/bin/sh
echo "DOORSTOP_ENABLED=${DOORSTOP_ENABLED:-unset}"
echo "LD_PRELOAD=${LD_PRELOAD:-unset}"
STUB
chmod +x "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64"
env_out=$(sh "$SANDBOX/startWorld.sh" testworld pw 25000 2>/dev/null | grep -E '^(DOORSTOP_ENABLED|LD_PRELOAD)=')
if [ "$env_out" = "$(printf 'DOORSTOP_ENABLED=unset\nLD_PRELOAD=unset')" ]; then
	echo "  PASS: vanilla exports no doorstop variables"
	PASS=$((PASS+1))
else
	echo "  FAIL: vanilla leaked doorstop env: $(echo "$env_out" | tr '\n' ' ')"
	FAIL=$((FAIL+1))
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
