#!/bin/sh
# Oracle test: a world that has just been started has NOTHING pending.
#
# The "restart pending" badge compares two things that are produced by two different files:
#
#   startWorld.sh            writes .running-options  (what the server was HANDED)
#   savedWorldOptions()      reads the database       (what the operator has SAVED)
#
# savedWorldOptions() therefore has to mirror startWorld.sh's gating exactly. When it does not,
# a world reports a pending change forever and NO restart clears it -- restarting only
# re-confirms the running side, so the badge is unclearable by any action the operator can take.
#
# That has now happened twice in 2.53, both times because one file moved and the mirror did not:
# crossplay first, then listed+passwordhash when access control was decoupled from world type.
# The second one shipped to :rc and put an unclearable badge on three of four live worlds.
#
# Neither failure was visible to any existing test, because every test checked ONE side. This
# one runs the REAL startWorld.sh to produce a real .running-options, then feeds it to the REAL
# PHP comparison with a database row saying the same thing, and asserts the verdict is "nothing
# pending". It fails if either side grows a gate the other does not have, without needing to
# know what the gate is.
#
# Usage:  sh dev_tools/test-restart-pending-mirror.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
SCRIPT="$REPO/container/games/valheim/scripts/startWorld.sh"
GETS="$REPO/container/nginx/www/includes/db_gets.php"
SANDBOX=$(mktemp -d)
trap 'rm -rf "$SANDBOX"' EXIT
PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

command -v php >/dev/null 2>&1 || { echo "SKIP: php not available"; exit 0; }

# Run the real startWorld.sh with / rewritten into the sandbox, so it writes a real
# .running-options for the given settings row.
start_world() {
	settings_row="$1"
	rm -rf "$SANDBOX/opt"
	mkdir -p "$SANDBOX/opt/stateless/engine/tools" \
	         "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game"
	cat > "$SANDBOX/opt/stateless/engine/tools/sql" <<STUB
#!/bin/sh
case "\$1" in
	*citizens*|*adminlist*|*bannedlist*) printf '' ;;
	*) printf '%s\n' '$settings_row' ;;
esac
STUB
	chmod +x "$SANDBOX/opt/stateless/engine/tools/sql"
	cat > "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64" <<'STUB'
#!/bin/sh
exit 0
STUB
	chmod +x "$SANDBOX/opt/stateful/games/valheim/worlds/testworld/game/valheim_server.x86_64"
	sed "s#/opt/#$SANDBOX/opt/#g" "$SCRIPT" > "$SANDBOX/startWorld.sh"
	sh "$SANDBOX/startWorld.sh" testworld ignored 25000 >/dev/null 2>&1
}

# Drive the REAL PHP comparison. The only transformation is the hardcoded /opt/stateful path
# inside runningWorldOptions(), rewritten into the sandbox -- and the harness asserts that the
# rewrite worked, because a path that resolves nowhere makes runningWorldOptions() return NULL,
# worldRestartPending() return [], and every assertion below pass for the wrong reason.
pending() {
	vanilla="$1"; listed="$2"; crossplay="$3"; password="$4"
	php -r '
		$gets = file_get_contents($argv[1]);
		$fns = "";
		foreach (["runningWorldOptions", "savedWorldOptions", "worldRestartPending"] as $fn) {
			if (!preg_match("/function " . $fn . "\(.*?\n}\n/s", $gets, $m)) {
				fwrite(STDERR, "EXTRACTFAIL $fn\n"); exit(2);
			}
			$fns .= $m[0];
		}
		$fns = str_replace("\"/opt/stateful/", "\"" . $argv[2] . "/opt/stateful/", $fns);
		if (strpos($fns, $argv[2]) === false) { fwrite(STDERR, "PATHFAIL\n"); exit(2); }
		eval($fns);

		# Minimal PDO stand-in: one row, the settings the world was started with.
		class FakeSth {
			public $row;
			function __construct($r) { $this->row = $r; }
			function execute($a = null) { return true; }
			function fetch($mode = null) { return $this->row; }
		}
		class FakePdo {
			public $row;
			function __construct($r) { $this->row = $r; }
			function prepare($q) { return new FakeSth($this->row); }
		}
		$pdo = new FakePdo([
			"vanilla"   => (int)$argv[3], "crossplay" => (int)$argv[5],
			"listed"    => (int)$argv[4], "password"  => $argv[6],
		]);

		# CONTROL: the running file must actually have been read.
		if (runningWorldOptions("testworld") === NULL) { fwrite(STDERR, "NORUNNING\n"); exit(2); }

		$p = worldRestartPending($pdo, "testworld", true);
		echo $p ? implode(",", $p) : "NONE";
	' "$GETS" "$SANDBOX" "$vanilla" "$listed" "$crossplay" "$password" 2>&1
}

echo
echo "restart-pending mirror: startWorld.sh vs savedWorldOptions()"
echo

# settings row is TAB separated: vanilla, listed, crossplay, password, launch_params
check_clean() {
	label="$1"; v="$2"; l="$3"; x="$4"; pw="$5"
	start_world "$(printf '%s\t%s\t%s\t%s\t' "$v" "$l" "$x" "$pw")"
	got=$(pending "$v" "$l" "$x" "$pw")
	[ "$got" = "NONE" ] && pass "$label" \
		|| fail "$label" "a world reports [$got] pending the moment it starts -- no restart can clear this"
}

check_clean "modded, password (the 2.53 upgrade state)"      0 0 0 'hunter2secret'
check_clean "modded, password, LISTED"                       0 1 0 'hunter2secret'
check_clean "modded, password, crossplay"                     0 0 1 'hunter2secret'
check_clean "modded, no password (every pre-2.53 world)"      0 0 0 ''
check_clean "vanilla, password, listed, crossplay"            1 1 1 'hunter2secret'
check_clean "vanilla, no password, not listed"                1 0 0 ''

echo
echo "Controls -- the comparison must still SEE a real change:"

# Start with one password, then ask with another. Without these, a mirror that returned a
# constant (or a runningWorldOptions() that read nothing) would pass every case above.
start_world "$(printf '0\t0\t0\thunter2secret\t')"
got=$(pending 0 0 0 'something-else-entirely')
case "$got" in *password*) pass "a CHANGED password is reported" ;;
	*) fail "a changed password was NOT reported (got [$got])" "the badge can never fire; it is decoration" ;; esac

got=$(pending 0 1 0 'hunter2secret')
case "$got" in *listing*) pass "a CHANGED listing is reported" ;;
	*) fail "a changed listing was NOT reported (got [$got])" ;; esac

got=$(pending 0 0 1 'hunter2secret')
case "$got" in *crossplay*) pass "a CHANGED crossplay is reported" ;;
	*) fail "a changed crossplay was NOT reported (got [$got])" ;; esac

got=$(pending 1 0 0 'hunter2secret')
case "$got" in *"world type"*) pass "a CHANGED world type is reported" ;;
	*) fail "a changed world type was NOT reported (got [$got])" ;; esac

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
