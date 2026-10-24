#!/bin/bash
# Oracle test: a forced catalogue sync reports progress THROUGH its dependency-graph write (2.58).
# requires: container
#
# WHAT IT GUARDS. The Mod Catalogues panel's bar is driven by mod_sync_runs.phase_pct, which
# the engine wrote at nine fixed waypoints. On a forced sync the first eight phases take ~4s
# and the dependency-graph rebuild takes the rest -- 40 of 45 seconds on a 290k-edge
# catalogue (2026-10-23) -- and it wrote ONE waypoint (80) at its start and nothing until
# the run finished. Brian: "the bar jumps to 75%, hangs, then jumps to 100%". The engine now
# carries the percentage with every INSERT batch (80..98) and logs a line every few seconds,
# so the bar climbs through the long phase and the log pane shows life.
#
# HOW. Starts a REAL forced Hexium sync (the smaller catalogue; still a full rebuild of the
# shared graph) and samples the run row twice a second until it finishes. The assertion is
# on what the panel's poll would have seen: several DISTINCT percentages strictly between the
# phase's start and the finish, all while the phase reads 'resolving deps', plus the progress
# line in the run's log. A sync that only wrote 80 then 100 fails; so does one whose
# percentage goes backwards. The edge count before and after must agree: the batching change
# must not drop or duplicate edges.
#
# Usage:  dev_tools/test-modsync-progress.sh [container]

C="${1:-phvalheim-dev}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "$2" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec "$C" mysql -N -e "$1" phvalheim 2>/dev/null; }

echo "=== forced Hexium sync: progress through the graph write ($C) ==="

# Not while another sync holds the graph: the lock is per source, the graph is shared.
for i in $(seq 1 60); do
	[ "$(sql "SELECT COUNT(*) FROM mod_sync_runs WHERE phase<>'done' AND finished IS NULL")" = 0 ] && break
	sleep 2
done
edgesBefore=$(sql "SELECT COUNT(*) FROM mod_deps")
before=$(sql "SELECT IFNULL(MAX(id),0) FROM mod_sync_runs")
[ -n "$edgesBefore" ] && [ "$edgesBefore" -gt 0 ] && ok "graph has edges to rebuild ($edgesBefore)" \
	|| bad "graph has edges to rebuild" "mod_deps is empty: run a sync first"

# As the cron user, the way the real syncs run (a root-owned log file once disabled every
# later cron sync -- 2.56).
docker exec -d -u phvalheim "$C" /opt/stateless/engine/tools/modSync.py --source hexium --trigger manual --force

runId=""; samples=""; t0=$(date +%s)
while [ $(( $(date +%s) - t0 )) -lt 240 ]; do
	if [ -z "$runId" ]; then
		runId=$(sql "SELECT IFNULL(MAX(id),'') FROM mod_sync_runs WHERE id > $before AND source='hexium'")
		[ -z "$runId" ] && { sleep 0.5; continue; }
	fi
	row=$(sql "SELECT phase, phase_pct, status FROM mod_sync_runs WHERE id=$runId" | tr '\t' '|')
	samples="$samples
$row"
	case "$row" in done\|*) break ;; esac
	sleep 0.5
done
[ -n "$runId" ] && ok "a hexium run started (id $runId)" || { bad "a hexium run started" "no mod_sync_runs row after 240s"; echo; echo "$pass passed, $fail failed"; exit 1; }

status=$(sql "SELECT status FROM mod_sync_runs WHERE id=$runId")
[ "$status" = "ok" ] && ok "the run finished ok" || bad "the run finished ok" "status=$status $(sql "SELECT LEFT(IFNULL(error,''),200) FROM mod_sync_runs WHERE id=$runId")"

# Distinct percentages seen DURING 'resolving deps', strictly inside the phase (81..98).
inPhase=$(printf '%s\n' "$samples" | awk -F'|' '$1=="resolving deps" && $2+0>80 && $2+0<100 {print $2+0}' | sort -n | uniq)
distinct=$(printf '%s\n' "$inPhase" | grep -c .)
echo "  sampled $(printf '%s\n' "$samples" | grep -c .) rows; percentages inside 'resolving deps': $(echo $inPhase | tr '\n' ' ')"
[ "$distinct" -ge 3 ] && ok "the bar moved through the graph write ($distinct distinct values between 80 and 100)" \
	|| bad "the bar moved through the graph write" "only $distinct distinct value(s) strictly between 80 and 100 while resolving deps -- the bar sat still"

# Never backwards: a later sample must not read lower than an earlier one.
mono=$(printf '%s\n' "$samples" | awk -F'|' 'NF>=2 { p=$2+0; if (p<last) bad=1; last=p } END { print bad+0 }')
[ "$mono" = 0 ] && ok "the percentage never went backwards" || bad "the percentage never went backwards" "see the sample list"

# The log pane's side of it: a progress line recorded against THIS run, in this phase.
lines=$(sql "SELECT COUNT(*) FROM mod_sync_log WHERE run_id=$runId AND phase='resolving deps' AND (message LIKE '%edge(s) written%' OR message LIKE 'dependency graph: writing %')")
[ "$lines" -ge 1 ] && ok "the run's log carries the edge-write progress line(s) ($lines)" \
	|| bad "the run's log carries the edge-write progress line(s)" "none for run $runId"

# The graph the run wrote is the graph the run counted. NOT "same count as before": an
# incremental sync leaves the edges of a version that has since stopped being newest (its
# delete is scoped to what it touched), so the table drifts a few rows above what a full
# rebuild computes (293,864 vs 293,858 on the first run of this). The run's own totals are
# the reference; the only legitimate shortfall is a dependency a version lists twice, which
# ON DUPLICATE KEY collapses -- seven such on the whole catalogue.
sum=$(sql "SELECT IFNULL(deps_resolved,0)+IFNULL(deps_unresolved,0) FROM mod_sync_runs WHERE id=$runId")
edgesAfter=$(sql "SELECT COUNT(*) FROM mod_deps")
short=$(( sum - edgesAfter ))
[ "$edgesAfter" -gt 0 ] && [ "$short" -ge 0 ] && [ "$short" -le 100 ] \
	&& ok "the rebuilt graph holds the edges the run counted ($edgesAfter of $sum; $short duplicate dep string(s) collapsed; was $edgesBefore before)" \
	|| bad "the rebuilt graph holds the edges the run counted" "run counted $sum, table has $edgesAfter (before: $edgesBefore)"

# The finish still owns 100: nothing in the batch loop may write it early.
last=$(printf '%s\n' "$samples" | grep -c .)
hundredBeforeDone=$(printf '%s\n' "$samples" | awk -F'|' '$1!="done" && $2+0>=100 {c++} END {print c+0}')
[ "$hundredBeforeDone" = 0 ] && ok "100% is written only by the finish" || bad "100% is written only by the finish" "$hundredBeforeDone sample(s) read 100 before phase=done"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
