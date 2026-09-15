#!/bin/bash
# Oracle test: does Hugin know which worlds are RUNNING?
#
# WHAT IT GUARDS. `worlds.status` is not a running indicator, and the AI layer used it as
# one in six places. Measured on the production box, 2026-09-15:
#
#     status   mode      count
#     Down     stopped      31
#     Down     running       2      <-- the two with live valheim_server processes
#     failed   stopped       2
#
# `status` is the literal string "Down" for ALL 33 worlds, running ones included. So
# aiTruthy($row,'status') was permanently false and:
#
#   - the system prompt injected "0 running, 33 stopped" plus a line telling the model that
#     every world being stopped is the server resting state. Hugin then explained a world an
#     operator was standing in as stopped, and read its live log as history.
#   - stop_world and restart_world refused EVERY world with "already stopped".
#   - start_world would happily start a world that was already up.
#   - aidiagnose downgraded every finding to history and skipped the restart-loop and
#     backup-freshness checks for worlds that were actually serving players.
#
# `worlds.mode` is the column the engine maintains and the admin UI renders
# (admin/index.php: `$isRunning = ($row['mode'] === 'running')`), and on production it
# matched the process list exactly.
#
# WHY THE FIXTURE USES status='Down' WITH mode='running'. That combination IS the bug. A
# fixture that set status='Running' for a running world would pass against the broken code
# and prove nothing -- which is exactly how this shipped in 2.45.
#
# MUTATION CHECK: point aiWorldIsRunning() back at `status` and WS1-WS8 must go red.
#
#   dev_tools/test-ai-world-state.sh [container]

set -u
C="${1:-phvalheim-dev}"
DB=aiwstest
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
pass=0; fail=0

ok()    { pass=$((pass+1)); printf "  PASS  %s\n" "$1"; }
bad()   { fail=$((fail+1)); printf "  FAIL  %s\n         wanted: %s\n         got:    %s\n" "$1" "$2" "$3"; }
check() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "$2" "$3"; }

docker exec "$C" mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;
  GRANT ALL ON $DB.* TO 'phvalheim_user'@'localhost'; FLUSH PRIVILEGES;" || exit 1
docker exec "$C" sh -c "mysqldump --no-data --skip-add-drop-table phvalheim | mysql $DB" || exit 1

# Verbatim from production: status says Down for everything, mode carries the truth.
docker exec "$C" mysql "$DB" -e "
INSERT INTO worlds (name,port,status,mode,vanilla,seed) VALUES
 ('Jotunheimdallingus',25201,'Down','running',0,'s'),
 ('Quiet',            25202,'Down','stopped',1,'s'),
 ('Brokenboot',       25203,'failed','stopped',0,'s');" || exit 1

docker exec "$C" rm -rf /tmp/aiws && docker exec "$C" mkdir -p /tmp/aiws
docker cp "$REPO/container/nginx/www/includes/." "$C:/tmp/aiws/" >/dev/null

# -i, or docker exec does not forward the heredoc and the file lands EMPTY, failing every
# assertion for a reason unrelated to the code under test.
docker exec -i "$C" sh -c "cat > /tmp/aiws/probe.php" <<'PHP'
<?php
$pdo = new PDO('mysql:host=localhost;dbname=aiwstest','phvalheim_user','phvalheim_secretpassword',
               [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_once '/tmp/aiws/aicontext.php';
switch ($argv[1]) {
    case 'list':   echo aiToolListWorlds($pdo); break;
    case 'get':    echo aiToolGetWorld($pdo, $argv[2]); break;
    case 'prompt': echo aiSystemPrompt($pdo, '', false); break;
    // The predicate itself, on the production row shape. Every guard in aiactions.php and
    // aidiagnose.php now routes through this one function.
    case 'pred':
        $rows = $pdo->query("SELECT * FROM worlds ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) echo $r['name'], '=', aiWorldIsRunning($r) ? 'running' : 'stopped', "\n";
        break;
}
PHP

lw() { docker exec "$C" php /tmp/aiws/probe.php list | python3 -c "
import json,sys
for w in json.load(sys.stdin):
    if w['name']==sys.argv[1]: print(w.get(sys.argv[2]))" "$1" "$2"; }
gw() { docker exec "$C" php /tmp/aiws/probe.php get "$1" | python3 -c "
import json,sys; print(json.load(sys.stdin).get(sys.argv[1]))" "$2"; }

echo "== the predicate, on the production row shape =="
PRED=$(docker exec "$C" php /tmp/aiws/probe.php pred)
check "WS1  status=Down + mode=running  -> RUNNING" \
      'running' "$(echo "$PRED" | grep '^Jotunheimdallingus=' | cut -d= -f2)"
check "WS2  status=Down + mode=stopped  -> stopped" \
      'stopped' "$(echo "$PRED" | grep '^Quiet=' | cut -d= -f2)"
check "WS3  status=failed + mode=stopped -> stopped" \
      'stopped' "$(echo "$PRED" | grep '^Brokenboot=' | cut -d= -f2)"

echo "== list_worlds =="
check "WS4  a running world is reported running"    'running' "$(lw Jotunheimdallingus status)"
check "WS5  a stopped world is reported stopped"    'stopped' "$(lw Quiet status)"
# `status` carries a signal `mode` does not: a world whose last start attempt failed.
check "WS6  a failed start is distinguishable"      'stopped (last start failed)' "$(lw Brokenboot status)"

echo "== get_world =="
check "WS7  running is an explicit boolean"         'True'    "$(gw Jotunheimdallingus running)"
check "WS8  ...and false for a stopped world"       'False'   "$(gw Quiet running)"
check "WS9  status agrees with list_worlds"         'running' "$(gw Jotunheimdallingus status)"
# The two tools used to disagree about what `mode` meant: running/stopped here,
# vanilla/modded in list_worlds. A model reading both had no way to tell.
check "WS10 mode means vanilla/modded, as in list_worlds" 'modded' "$(gw Jotunheimdallingus mode)"
check "WS11 ...and vanilla for a vanilla world"     'vanilla' "$(gw Quiet mode)"

echo "== the live state injected into the prompt =="
P=$(docker exec "$C" php /tmp/aiws/probe.php prompt)
check "WS12 the count is right" \
      '1' "$(echo "$P" | grep -c 'Worlds: 1 running, 2 stopped, 3 configured')"
# This line told the model that a stopped server is the resting state. With a world up it
# is false, and it is what produced "the world is currently stopped" about a live world.
check "WS13 the resting-state line is absent when a world is up" \
      '0' "$(echo "$P" | grep -c 'Every world is currently stopped')"

echo "== ...and present when nothing is running =="
docker exec "$C" mysql "$DB" -e "UPDATE worlds SET mode='stopped';"
P0=$(docker exec "$C" php /tmp/aiws/probe.php prompt)
check "WS14 count with none running" \
      '1' "$(echo "$P0" | grep -c 'Worlds: 0 running, 3 stopped, 3 configured')"
check "WS15 the resting-state line returns" \
      '1' "$(echo "$P0" | grep -c 'Every world is currently stopped')"

docker exec "$C" sh -c "mysql -e \"DROP DATABASE IF EXISTS $DB\"; rm -rf /tmp/aiws"
echo
echo "passed $pass, failed $fail"
[ "$fail" -eq 0 ]
