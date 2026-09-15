#!/bin/bash
#
# Oracle tests for updateApplier's two gates.
#
# This is the only code in PhValheim that stops a server nobody asked it to stop, so the
# gates get tested rather than reasoned about. Every case below is one where a plausible
# wrong implementation gives a different answer than the right one.
#
# isIdle() needs a database, so those cases run against the dev container's MySQL using a
# scratch world row that is created and dropped by the test. Nothing else is touched.
#
# Run: dev_tools/test-updateApplier.sh [container]

set -u

CONTAINER="${1:-phvalheim-dev}"
TOOL="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/updateApplier"
SCRATCH="zz_updateapplier_test"

pass=0
fail=0

check() {
	local name="$1" expected="$2" actual="$3"
	if [ "$actual" = "$expected" ]; then
		echo "  PASS  $name"
		pass=$((pass + 1))
	else
		echo "  FAIL  $name"
		echo "          expected: $expected"
		echo "          actual:   $actual"
		fail=$((fail + 1))
	fi
}

echo "updateApplier gate tests"
echo

# --- inWindow: pure, runs locally ----------------------------------------------------
#
# Extracted rather than sourced: the tool sources the container's config and 0-functions.sh
# at the top, neither of which exists here.
sed -n '/^inWindow() {/,/^}/p' "$TOOL" > /tmp/inwindow.fn

runWindow() {
	local start="$1" hours="$2" hour="$3"
	# shellcheck disable=SC2016
	bash -c '
		date() { echo "'"$hour"'"; }
		local_stub=1
		'"$(cat /tmp/inwindow.fn)"'
		if inWindow "'"$start"'" "'"$hours"'"; then echo OPEN; else echo SHUT; fi
	' 2>/dev/null
}

check "start -1 means any time"            "OPEN" "$(runWindow -1 0 13)"
check "empty start means any time"         "OPEN" "$(runWindow '' '' 13)"
check "zero-length window means any time"  "OPEN" "$(runWindow 2 0 13)"
check "inside a plain window (2..6, now 3)"    "OPEN" "$(runWindow 2 4 3)"
check "outside a plain window (2..6, now 8)"   "SHUT" "$(runWindow 2 4 8)"
check "at the start hour is inside"            "OPEN" "$(runWindow 2 4 2)"
check "at the end hour is outside"             "SHUT" "$(runWindow 2 4 6)"
# The wrap case is where the obvious implementation breaks: 23 + 4 = 27 % 24 = 3, so a
# naive start<now<end test is false for every hour of the window.
check "wrapping window, late side (23..3, now 23)" "OPEN" "$(runWindow 23 4 23)"
check "wrapping window, early side (23..3, now 1)" "OPEN" "$(runWindow 23 4 1)"
check "wrapping window, outside (23..3, now 12)"   "SHUT" "$(runWindow 23 4 12)"

echo

# --- isIdle: needs the database ------------------------------------------------------
if ! docker exec "$CONTAINER" true 2>/dev/null; then
	echo "  SKIP  isIdle cases -- container '$CONTAINER' not running"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

dbq() { docker exec "$CONTAINER" mysql -N -B -e "$1" 2>/dev/null; }

# Refuse to run the isIdle cases against a pre-2.47 schema. Without this guard every case
# fails with "Unknown column player_count", which reads as six logic bugs in the gate and
# is really one container that has not had its migration run. A test that reports a code
# failure for an environmental reason is worse than no test.
if [ "$(dbq "SELECT COUNT(*) FROM information_schema.columns \
	WHERE table_schema='phvalheim' AND table_name='worlds' \
	AND column_name IN ('player_count','player_count_at');")" != "2" ]; then
	echo "  SKIP  isIdle cases -- '$CONTAINER' has no 2.47 columns; run dbUpdate_2.47.sh there first"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

cleanup() { dbq "DELETE FROM phvalheim.worlds WHERE name='$SCRATCH';"; }
trap cleanup EXIT
cleanup

dbq "INSERT INTO phvalheim.worlds (name, mode) VALUES ('$SCRATCH','stopped');"
if [ -z "$(dbq "SELECT name FROM phvalheim.worlds WHERE name='$SCRATCH';")" ]; then
	echo "  SKIP  isIdle cases -- could not create scratch world"
	echo
	echo "  $pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

sed -n '/^isIdle() {/,/^}/p' "$TOOL" > /tmp/isidle.fn

runIdle() {
	local count="$1" agoMinutes="$2" threshold="$3" nullAt="${4:-0}"

	if [ "$nullAt" = "1" ]; then
		dbq "UPDATE phvalheim.worlds SET player_count=$count, player_count_at=NULL WHERE name='$SCRATCH';"
	else
		dbq "UPDATE phvalheim.worlds SET player_count=$count, \
		     player_count_at=DATE_SUB(NOW(), INTERVAL $agoMinutes MINUTE) WHERE name='$SCRATCH';"
	fi

	docker exec -i "$CONTAINER" bash -s <<EOF 2>/dev/null
SQL(){ /usr/bin/mysql --skip-column-names -uroot --database=phvalheim -e "\$1"; }
$(cat /tmp/isidle.fn)
if isIdle "$SCRATCH" "$threshold"; then echo IDLE; else echo BUSY; fi
EOF
}

check "0 players, seen just now -> idle"          "IDLE" "$(runIdle 0 1 30)"
check "2 players, seen just now -> busy"          "BUSY" "$(runIdle 2 1 30)"
# The stuck-count case: a nonzero count whose observation has gone stale is idle. Without
# this arm a bad teardown parks a world in "waiting" forever and it is never updated.
check "2 players, last seen 90m ago -> idle"      "IDLE" "$(runIdle 2 90 30)"
check "2 players, last seen 20m ago -> busy"      "BUSY" "$(runIdle 2 20 30)"
# A zero that is itself ancient means the log stopped being written. Not knowing is not
# the same as knowing it is empty.
check "0 players, last seen 5h ago -> busy"       "BUSY" "$(runIdle 0 300 30)"
# Never observed at all. Unknown must never authorise a restart.
check "never observed -> busy"                    "BUSY" "$(runIdle 0 0 30 1)"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
