#!/bin/bash
# Oracle test: every Hugin tool result reaches the conversation loop as TEXT (issue #93, 2.58).
# requires: container
#
# THE BUG. "Hugin is broken in 2.56 ... Agent seems to return no data for any command that tries
# to read log files" -- the PHP log said why: "TypeError: strlen(): Argument #1 ($str) must be of
# type string, array given in includes/aicontext.php:1107". aiConverse() ran strlen() on every
# tool result to truncate oversized ones, and three tools added in 2.56 (last_operation,
# world_activity, server_activity) return PHP arrays. One of them in a plan -- which is what
# "any world broken?" and "full health check" produce -- killed the stream before the model
# said a word. Reproduced on 2.57 with the real tools below; fixed by normalising at the seam.
#
# HOW. Runs php INSIDE the container (the real database, the real log directory) and calls
# aiRunTool() for every read-only tool the catalogue offers, then does what aiConverse() does
# to each result. The control proves the raw tool still returns an array, so a pass here is the
# wrapper's doing and not a tool that happened to be rewritten.
#
#   $1 = container (default phvalheim-dev)   $2 = a world name (default midgard)

C="${1:-phvalheim-dev}"; W="${2:-midgard}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; fail=$((fail+1)); }

docker exec "$C" true 2>/dev/null || { echo "HARNESS: container $C not running"; exit 2; }
docker exec "$C" mysql -N phvalheim -e "SELECT 1 FROM worlds WHERE name='$W'" 2>/dev/null | grep -q 1 || { echo "HARNESS: world $W not found in $C"; exit 2; }

echo "=== Hugin tool results are text (issue #93) ==="

out=$(docker exec -i -e W="$W" "$C" php 2>&1 <<'PHP'
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/aicontext.php';
$W = getenv('W');
$tools = [
    ['list_worlds', []], ['get_world', ['world' => $W]],
    ['last_operation', ['world' => $W]], ['world_activity', ['world' => $W, 'hours' => 24]], ['server_activity', ['hours' => 24]],
    ['list_logs', []], ['read_log', ['file' => 'phvalheim.log', 'lines' => 5]], ['search_log', ['file' => 'phvalheim.log', 'pattern' => 'NOTICE']],
    ['get_world_mods', ['world' => $W]], ['get_mod_sync_status', []], ['get_backup_status', ['world' => $W]],
    ['get_system_health', []], ['get_diagnostics', ['world' => $W]],
];
foreach ($tools as [$n, $a]) {
    $r = aiRunTool($pdo, $n, $a);
    echo "TOOL\t$n\t", gettype($r), "\t", is_string($r) ? strlen($r) : -1, "\n";
}
// Control: the raw function still hands back an ARRAY for the 2.56 tools; the wrapper is the fix.
$raw = aiRunToolRaw($pdo, 'last_operation', ['world' => $W]);
echo "RAW\tlast_operation\t", gettype($raw), "\n";
$raw = aiRunToolRaw($pdo, 'server_activity', ['hours' => 24]);
echo "RAW\tserver_activity\t", gettype($raw), "\n";
// What the loop does to every result (the line that threw): must not throw.
try { foreach ($tools as [$n, $a]) { strlen(aiRunTool($pdo, $n, $a)); } echo "LOOP\tok\n"; }
catch (Throwable $e) { echo "LOOP\t", get_class($e), ": ", $e->getMessage(), "\n"; }
// The text the model gets for last_operation is the structure, not a PHP notice.
$t = aiRunTool($pdo, 'last_operation', ['world' => $W]);
$j = json_decode($t, true);
echo "JSON\tlast_operation\t", (is_array($j) && array_key_exists('world', $j)) ? 'decodes-with-operation' : 'not-json', "\n";
PHP
)
case "$out" in *"PHP Fatal"*|*"Parse error"*) bad "the harness ran" "$(echo "$out" | head -3)"; echo; echo "$pass passed, $fail failed"; exit 1 ;; esac

n=$(echo "$out" | grep -c '^TOOL')
[ "$n" -ge 13 ] && ok "every read-only tool was exercised ($n)" || bad "every read-only tool was exercised" "only $n TOOL lines: $(echo "$out" | head -5)"
nonstr=$(echo "$out" | awk -F'\t' '$1=="TOOL" && $3!="string" {print $2"="$3}' | tr '\n' ' ')
[ -z "$nonstr" ] && ok "every tool result is a string" || bad "every tool result is a string" "$nonstr"
for t in last_operation world_activity server_activity; do
	ty=$(echo "$out" | awk -F'\t' -v t="$t" '$1=="TOOL" && $2==t {print $3}')
	[ "$ty" = "string" ] && ok "$t (a 2.56 tool that returned an array) now reaches the loop as a string" || bad "$t reaches the loop as a string" "got $ty"
done
raw=$(echo "$out" | awk -F'\t' '$1=="RAW" && $2=="last_operation" {print $3}')
[ "$raw" = "array" ] && ok "control: the raw last_operation tool still returns an array, so the wrapper is doing the work" || bad "control: the raw tool returns an array" "got $raw (if the tool was rewritten to return text, this test no longer proves the seam)"
loop=$(echo "$out" | awk -F'\t' '$1=="LOOP" {print $2}')
[ "$loop" = "ok" ] && ok "the loop's strlen() over every result does not throw (the line that killed 2.56/2.57)" || bad "the loop's strlen() over every result does not throw" "$loop"
js=$(echo "$out" | awk -F'\t' '$1=="JSON" {print $3}')
[ "$js" = "decodes-with-operation" ] && ok "the model receives last_operation as JSON it can read (world key present)" || bad "the model receives last_operation as JSON" "$js"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
