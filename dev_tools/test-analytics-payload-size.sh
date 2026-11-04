#!/bin/bash
# Oracle test: pushAnalytics.sh must build a payload no matter how many worlds x mods exist.
#
# The bug: worlds were accumulated in a shell variable and handed to jq as
#   --argjson worlds "$worlds_json"
# Linux caps a SINGLE argv entry at 128 KiB (MAX_ARG_STRLEN), separately from the much larger
# total ARG_MAX. Past that, jq never runs -- "Argument list too long" -- so every analytics push
# failed, with one WARN line as the only symptom:
#   pushAnalytics.sh: line 146: /usr/bin/jq: Argument list too long
#   [WARN : phvalheim] Failed to build analytics payload
#
# A test that just runs the script on a normal database cannot see this: with a handful of
# worlds the payload is a few KB and passes either way. The size IS the bug, so the test has to
# manufacture the size.
#
# Usage:  dev_tools/test-analytics-payload-size.sh [container]   (default phvalheim-dev)

C="${1:-phvalheim-dev}"
pass=0; fail=0
check() { if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"; else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi; }

if ! docker exec "$C" true 2>/dev/null; then
	echo "container '$C' is not running -- pass the right name as \$1"
	exit 1
fi

echo ""
echo "The per-argument limit is real and the file route is not subject to it"

# Establish the threshold empirically inside the container, so the test documents the actual
# platform behaviour rather than asserting a number I remembered.
argv_small=$(docker exec "$C" sh -c '
  big=$(awk "BEGIN{ s=\"\"; for(i=0;i<1000;i++) s=s \"\\\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\\",\"; print \"[\" substr(s,1,length(s)-1) \"]\" }")
  jq -n --argjson w "$big" "\$w | length" 2>&1' )
check "a 64 KB argv payload still works (so the limit is not tiny)" \
	"$([ "$argv_small" = "1000" ] && echo 1 || echo 0)" "got: $argv_small"

argv_big=$(docker exec "$C" sh -c '
  big=$(awk "BEGIN{ s=\"\"; for(i=0;i<8000;i++) s=s \"\\\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\\",\"; print \"[\" substr(s,1,length(s)-1) \"]\" }")
  jq -n --argjson w "$big" "\$w | length" 2>&1' )
check "a ~512 KB argv payload FAILS (this is the bug's mechanism)" \
	"$(echo "$argv_big" | grep -qi 'argument list too long' && echo 1 || echo 0)" \
	"got: $argv_big"

file_big=$(docker exec "$C" sh -c '
  awk "BEGIN{ s=\"\"; for(i=0;i<8000;i++) s=s \"\\\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\\",\"; print \"[\" substr(s,1,length(s)-1) \"]\" }" > /tmp/_sz.json
  jq -n --slurpfile w /tmp/_sz.json "\$w[0] | length" 2>&1; rm -f /tmp/_sz.json' )
check "the same ~512 KB via --slurpfile succeeds" \
	"$([ "$file_big" = "8000" ] && echo 1 || echo 0)" "got: $file_big"

echo ""
echo "The shipped script keeps large JSON off the command line"
src=$(docker exec "$C" cat /opt/stateless/engine/tools/pushAnalytics.sh 2>/dev/null)
[[ -z "$src" || "$src" == *PHV_TEST_ANALYTICS_DISABLED* ]] && src=$(cat "$(dirname "$0")/../container/engine/tools/pushAnalytics.sh")

check "worlds are passed to jq by file, not argv" \
	"$(echo "$src" | grep -q -- '--slurpfile worlds' && echo 1 || echo 0)"
check "no --argjson worlds remains" \
	"$(echo "$src" | grep -v '^#' | grep -q -- '--argjson worlds' && echo 0 || echo 1)" \
	"a growing payload would break again"
check "mods are passed by file too" \
	"$(echo "$src" | grep -q -- '--slurpfile mods' && echo 1 || echo 0)"
check "temp files are cleaned up on exit" \
	"$(echo "$src" | grep -q "trap .*rm -f" && echo 1 || echo 0)"

echo ""
echo "It still produces a valid payload end to end"
# Build only: never enable telemetry in the shared database or execute transport.
# Refuse an unknown sender layout instead of silently failing to intercept its POST.
transport='http_code=$(python3 /opt/stateless/engine/tools/analyticsTransport.py "$payload_file")'
enabled='analyticsEnabled=$(SQL "SELECT analyticsEnabled FROM settings" 2>/dev/null)'
if [ "$(printf '%s\n' "$src" | grep -Fc "$transport")" != 1 ] ||
   [ "$(printf '%s\n' "$src" | grep -Fc "$enabled")" != 1 ]; then
    echo "FAIL: unknown analytics sender layout; refusing execution"
    exit 1
fi
# Truncate before transport, and force enabled only in this script's local variable.
# The production database setting, credentials and health result are untouched.
builder=${src%%"$transport"*}
builder=${builder/"$enabled"/analyticsEnabled=1}
builder+=$'\n'"jq -c '{worlds_type:(.worlds|type), nested:((.worlds|type)==\"array\" and (.worlds|length)>0 and (.worlds[0]|type)==\"array\")}' \"\$payload_file\""
out=$(printf '%s\n' "$builder" | docker exec -i "$C" bash 2>&1)

check "no 'Argument list too long' in the run" \
	"$(echo "$out" | grep -qi 'argument list too long' && echo 0 || echo 1)" \
	"$(echo "$out" | grep -i 'argument list too long' | head -1)"
check "no 'Failed to build analytics payload'" \
	"$(echo "$out" | grep -q 'Failed to build analytics payload' && echo 0 || echo 1)"

payload=$(printf '%s\n' "$out" | tail -n 1)
check "a payload file was written" "$([ -n "$payload" ] && echo 1 || echo 0)"
check "it is JSON with a worlds array" \
	"$(echo "$payload" | grep -q '"worlds_type":"array"' && echo 1 || echo 0)" "got: $(echo "$payload" | head -c 120)"
check "worlds is an ARRAY, not a nested array (the slurpfile [0] trap)" \
	"$(echo "$payload" | grep -q '"nested":true' && echo 0 || echo 1)" \
	'--slurpfile wraps the file value in an array; missing $worlds[0] double-wraps it'

echo ""
echo "CONTROL: this suite can fail"
# Rebuild the old, broken form and confirm the argv assertion goes red against it.
broken=$(echo "$src" | sed 's/--slurpfile worlds/--argjson worlds/')
check "the argv check WOULD catch a reverted script" \
	"$(echo "$broken" | grep -v '^#' | grep -q -- '--argjson worlds' && echo 1 || echo 0)" \
	'the no--argjson assertion cannot see the thing it guards'

echo ""
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
