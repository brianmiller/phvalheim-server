#!/bin/bash
# Oracle test for: creating a VANILLA world must not log
#   "Setting world md5sum for 'x' to ''"
# A vanilla world has no client payload, so the md5 is deliberately cleared -- but the
# old message read like a checksum that had failed.
#
# Asserts on the ACTUAL BYTES setMD5 writes to stdout, for both branches, and includes a
# CONTROL that proves the assertion can fail. Does NOT assert on the DB write succeeding,
# because that would pass whether or not the message is right.
#
# Usage: dev_tools/test-md5-log.sh [path/to/0-functions.sh]

FUNCS="${1:-$(dirname "$0")/../container/engine/includes/0-functions.sh}"
pass=0; fail=0
check () { # $1=name $2=ok $3=detail
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

# Pull setMD5 out on its own. Sourcing the whole file drags in the container's paths.
tmp=$(mktemp)
awk '/^function setMD5 \(\)/,/^}/' "$FUNCS" > "$tmp"
if [ ! -s "$tmp" ]; then echo "could not extract setMD5 from $FUNCS"; exit 1; fi

# Stub the DB so we measure the MESSAGE, not the query.
SQL () { echo "SQL:$1"; }
. "$tmp"

echo
echo "Case 1: vanilla world (empty md5)"
out=$(setMD5 "testworld" "" 2>&1)
echo "$out" | grep -q "to ''" && bad=1 || bad=0
check "does not log \"to ''\"" "$([ $bad -eq 0 ] && echo 1 || echo 0)" "$out"
echo "$out" | grep -qi "clearing world md5sum for 'testworld'" && ok=1 || ok=0
check "logs an explicit 'clearing' message" "$ok" "$out"
echo "$out" | grep -q "SQL:UPDATE worlds SET world_md5='' WHERE name='testworld';" && ok=1 || ok=0
check "still clears the column in the DB" "$ok" "$out"

echo
echo "Case 2: modded world (real md5) -- message unchanged"
md5="d41d8cd98f00b204e9800998ecf8427e"
out=$(setMD5 "testworld" "$md5" 2>&1)
echo "$out" | grep -q "Setting world md5sum for 'testworld' to '$md5'" && ok=1 || ok=0
check "logs the md5 it set" "$ok" "$out"
echo "$out" | grep -q "SQL:UPDATE worlds SET world_md5='$md5' WHERE name='testworld';" && ok=1 || ok=0
check "writes the md5 to the DB" "$ok" "$out"

echo
echo "Case 3: CONTROL -- the assertion must be able to fail"
out="`date` [NOTICE : phvalheim] Setting world md5sum for 'testworld' to ''"
echo "$out" | grep -q "to ''" && bad=1 || bad=0
check "control: a bad message IS detected" "$([ $bad -eq 1 ] && echo 1 || echo 0)" "control did not fire"

rm -f "$tmp"
echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
