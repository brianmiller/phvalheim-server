#!/bin/bash
# Oracle test: the Logs badge clears when the operator looks (2.58).
# requires: container
#
# THE BUG. The sidebar count of warnings/errors "not yet looked at" never cleared. The Logs page
# posted its ack on every visit; phvEventsAck() ran
#     UPDATE settings SET logsSeenEventId = GREATEST(logsSeenEventId, ?)
# with the id bound by PDO as a STRING, and MySQL's GREATEST compares as strings when any
# argument is one: GREATEST(669, '6429') is '669'. The watermark climbed while ids had three
# digits and froze at 669 for good (production, 2026-10-24: watermark 669, newest event 6429).
# A second gap: the per-world log view (world_logs.php) never acked at all, and a single
# watermark could not have expressed "I looked at THIS world's log".
#
# HOW. Plants a three-digit watermark and events with FOUR-digit ids -- the exact shape that
# froze -- then acks through the real functions inside the container and reads the real summary.
# The control shows MySQL doing the string comparison, so the trap is on record. Deltas, not
# absolutes: the box has its own unseen events.

C="${1:-phvalheim-dev}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec -i "$C" mysql -N phvalheim -e "$1" 2>&1; }

docker exec "$C" true 2>/dev/null || { echo "HARNESS: container $C not running"; exit 2; }
sql "DESCRIBE phv_events" | awk '{print $1}' | grep -qx acked || { echo "HARNESS: phv_events.acked missing -- dbUpdate_2.58.sh has not run on this volume"; exit 2; }

echo "=== Logs badge acknowledgement ==="

# --- control: the mechanism of the 2.56 bug, on this MySQL ---------------------------------
g=$(sql "SELECT GREATEST(999, '1000')")
[ "$g" = "999" ] && ok "control: MySQL GREATEST(999, '1000') is '999' -- a string-bound id can never pass a 3-digit watermark" || bad "control: GREATEST compares as strings" "got $g (then the old code would have worked here, and this test proves less)"
g=$(sql "SELECT GREATEST(999, CAST('1000' AS UNSIGNED))")
[ "$g" = "1000" ] && ok "control: with CAST the comparison is numeric (1000)" || bad "CAST makes it numeric" "got $g"

# --- fixture ------------------------------------------------------------------------------
origMark=$(sql "SELECT IFNULL(logsSeenEventId,0) FROM settings LIMIT 1")
# The global ack below also acks the box's own older unseen events; remember them and put them back.
preUnseen=$(sql "SELECT GROUP_CONCAT(id) FROM phv_events WHERE acked=0" | grep -v NULL)
maxid=$(sql "SELECT IFNULL(MAX(id),0) FROM phv_events")
base=$(( maxid >= 1000 ? maxid + 1 : 1000 ))
A=$base; B=$((base + 1)); E=$((base + 2))
cleanup() {
	sql "DELETE FROM phv_events WHERE id IN ($A,$B,$E) AND source='oracle'" >/dev/null
	sql "UPDATE settings SET logsSeenEventId=$origMark" >/dev/null
	[ -n "$preUnseen" ] && sql "UPDATE phv_events SET acked=0 WHERE id IN ($preUnseen)" >/dev/null
}
trap cleanup EXIT
sql "INSERT INTO phv_events (id, source, level, world, message) VALUES ($A,'oracle','warn','ztest_ackA','oracle warn A'), ($B,'oracle','warn','ztest_ackB','oracle warn B'), ($E,'oracle','error','ztest_ackB','oracle error B')" >/dev/null
sql "UPDATE settings SET logsSeenEventId=999" >/dev/null
[ "$(sql "SELECT COUNT(*) FROM phv_events WHERE id IN ($A,$B,$E) AND acked=0")" = "3" ] && ok "fixture: three unseen events with ids $A..$E (four digits) behind a 3-digit watermark" || { bad "fixture planted" "$(sql "SELECT id,acked FROM phv_events WHERE id IN ($A,$B,$E)")"; echo; echo "$pass passed, $fail failed"; exit 1; }

php_summary() {
	docker exec -i "$C" php <<'PHP'
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/events.php';
$s = phvEventsSummary($pdo); echo $s['unseen_warn'], "\t", $s['unseen_error'], "\t", $s['seen_id'], "\n";
PHP
}
php_ack() { # $1 id, $2 world or ''
	docker exec -i -e ID="$1" -e WORLD="$2" "$C" php <<'PHP'
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/events.php';
$w = getenv('WORLD'); phvEventsAck($pdo, (int)getenv('ID'), $w === '' ? null : $w); echo "acked\n";
PHP
}

s0=$(php_summary); w0=$(echo "$s0" | cut -f1); e0=$(echo "$s0" | cut -f2)
[ "$w0" -ge 2 ] && [ "$e0" -ge 1 ] && ok "the summary counts the planted events as unseen (warn=$w0 error=$e0)" || bad "the summary counts the planted events" "summary=$s0"

# --- world-scoped ack: the world log view -------------------------------------------------
php_ack "$E" "ztest_ackB" >/dev/null
s1=$(php_summary); w1=$(echo "$s1" | cut -f1); e1=$(echo "$s1" | cut -f2)
[ "$((w0 - w1))" = "1" ] && [ "$((e0 - e1))" = "1" ] && ok "acking world ztest_ackB up to $E clears exactly its warn and its error" || bad "world-scoped ack clears only that world's events" "before=$s0 after=$s1"
[ "$(sql "SELECT acked FROM phv_events WHERE id=$A")" = "0" ] && ok "the other world's warning is still unseen after a world-scoped ack" || bad "the other world's warning is untouched"

# --- global ack from the Logs page: the four-digit id must pass the three-digit watermark --
php_ack "$B" "" >/dev/null
s2=$(php_summary); w2=$(echo "$s2" | cut -f1)
[ "$(sql "SELECT acked FROM phv_events WHERE id=$A")" = "1" ] && [ "$w2" -le "$((w1 - 1))" ] && ok "a global ack up to $B clears the remaining planted warning (id $A, four digits, behind watermark 999)" || bad "global ack clears the remaining planted warning" "A.acked=$(sql "SELECT acked FROM phv_events WHERE id=$A") before=$s1 after=$s2"
mark=$(sql "SELECT logsSeenEventId FROM settings LIMIT 1")
[ "$mark" = "$B" ] && ok "the watermark moved from 999 to $B (numeric comparison; the 2.56 code left it at 999)" || bad "the watermark moved past three digits" "logsSeenEventId=$mark"

# --- the pages post the right thing (static) ----------------------------------------------
L=container/nginx/www/admin/logs.php; WL=container/nginx/www/admin/world_logs.php; API=container/nginx/www/admin/adminAPI.php
cd "$(dirname "$0")/.." || exit 1
grep -q "visibilitychange" $L && ok "logs.php acks when brought to the front (a background tab used to show everything and ack nothing)" || bad "logs.php acks on visibilitychange"
grep -qF "body: JSON.stringify({ id: id, world: WORLD })" $WL && ok "world_logs.php acks ITS world's events" || bad "world_logs.php acks its world"
grep -qF "isset(\$input['world']) ? (string)\$input['world'] : null" $API && ok "ackEvents passes the world through to phvEventsAck()" || bad "ackEvents passes the world through"
grep -qF 'GREATEST(logsSeenEventId, ?)")' container/nginx/www/includes/events.php && bad "the uncast GREATEST is gone from events.php" "still present" || ok "the uncast GREATEST(logsSeenEventId, ?) is gone from events.php"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
