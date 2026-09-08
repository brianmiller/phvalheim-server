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
