#!/bin/sh
# Oracle test: a RUNNING world says so when its mod settings are not the ones it loaded.
#
# BepInEx hands a plugin its config at load. A setting saved while the world is up is stored,
# will be applied, and is not in effect -- and until this change the only thing that ever said
# so was the save modal, which is gone the moment it is closed. Reload the page and the
# dashboard's own "restart pending" badge sat there looking authoritative while being blind to
# every mod setting.
#
# WHY THE OBVIOUS IMPLEMENTATION IS WRONG, which is what most of this file defends:
#
#   mod_config_overrides.date_set looks like the answer and is not. The column has no
#   ON UPDATE clause and the save path never writes it, so editing an EXISTING override leaves
#   the original insert timestamp. A check built on it reports correctly for a brand new
#   override and is silently blind to the commonest case there is -- changing a value. T5 is
#   that case, and it is the reason the comparison is against a recorded snapshot instead.
#
# The two "nothing happened" claims are paired with proof they were reached, because both would
# also be satisfied by a function that cannot see anything at all:
#   T2  a missing snapshot must be 'unknown', NOT 'clean'. A world running from before this
#       release has nothing to compare against, and reporting that as "up to date" is the
#       0-means-two-things bug this project shipped three times in one release.
#   T6  a client-side mod's setting is NOT a restart matter. Telling an operator to restart for
#       something a restart cannot deliver is worse than saying nothing.
#
# Usage:  sh dev_tools/test-config-restart-pending.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
MC="$REPO/container/nginx/www/includes/modconfigs.php"
PY="$REPO/container/engine/tools/modConfigs.py"
IDX="$REPO/container/nginx/www/admin/index.php"
API="$REPO/container/nginx/www/admin/adminAPI.php"
WC="$REPO/container/nginx/www/admin/world_configs.php"
SANDBOX=$(mktemp -d)
trap 'rm -rf "$SANDBOX"' EXIT
PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

command -v php >/dev/null 2>&1 || { echo "SKIP: php not available"; exit 0; }

WORLDDIR="$SANDBOX/opt/stateful/games/valheim/worlds/testworld"
mkdir -p "$WORLDDIR"

# The driver. Extracts the REAL functions, rewrites only the one hardcoded world path, and
# supplies a fake PDO -- the override rows and the mod list are the inputs each case varies.
#
# It FAILS LOUDLY rather than returning a plausible answer if extraction or the path rewrite
# did not work: a function that was never defined, or a path resolving nowhere, would make
# every "nothing pending" assertion below pass for a reason that has nothing to do with the
# code under test.
cat > "$SANDBOX/drive.php" <<'DRIVER'
<?php
$src = file_get_contents($argv[1]);
$want = ['modConfigExcludedFiles', 'modConfigReachMap', 'modConfigReachOf',
         'modConfigOverrides', 'modConfigPending', 'modConfigPendingLabels'];
$code = '';
foreach ($want as $fn) {
    if (!preg_match('/function ' . $fn . '\(.*?\n}\n/s', $src, $m)) {
        fwrite(STDERR, "NOFUNC:$fn\n"); exit(2);
    }
    $code .= $m[0];
}
$sandbox = $argv[2];
$before  = $code;
$code = str_replace('/opt/stateful/games/valheim/worlds/',
                    $sandbox . '/opt/stateful/games/valheim/worlds/', $code);
if ($code === $before) { fwrite(STDERR, "NOPATH\n"); exit(2); }

// Not under test: the world lookup. A fixed id keeps the fake PDO to the two queries that
// matter.
function worldIdByName($pdo, $world) { return $world === 'testworld' ? 1 : 0; }

class FakeStmt {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function execute($p = null) { return true; }
    public function fetchAll($mode = null) { return $this->rows; }
}
class FakePdo {
    public $mods = [];
    public $overrides = [];
    public function prepare($sql) {
        if (strpos($sql, 'world_mods') !== false)           { return new FakeStmt($this->mods); }
        if (strpos($sql, 'mod_config_overrides') !== false) { return new FakeStmt($this->overrides); }
        fwrite(STDERR, "UNEXPECTED SQL: $sql\n"); exit(2);
    }
}

eval($code);

$in = json_decode(file_get_contents($argv[3]), true);
$pdo = new FakePdo();
$pdo->mods      = $in['mods'];
$pdo->overrides = $in['overrides'];

$p = modConfigPending($pdo, 'testworld', $in['online']);
$labels = modConfigPendingLabels($pdo, 'testworld', $in['online']);
$names = [];
foreach ($p['settings'] as $s) { $names[] = $s['key'] . ':' . $s['action']; }
echo $p['state'], '|', implode(',', $names), '|', implode(';', $labels), "\n";
DRIVER

# $1=json scenario. Prints "state|key:action,...|labels".
drive() {
	printf '%s' "$1" > "$SANDBOX/in.json"
	php "$SANDBOX/drive.php" "$MC" "$SANDBOX" "$SANDBOX/in.json" 2>"$SANDBOX/err"
}

# A mod the players run (deploy_client=1) and one only the server runs (0).
MODS='[{"mod_id":7,"dc":1,"owner":"acme","name":"HudThing"},
       {"mod_id":9,"dc":0,"owner":"acme","name":"ServerThing"}]'

# One override per case, written as the DB row shape modConfigOverrides() returns.
row() { # file section key value mod_id server_only
	printf '{"cfg_file":"%s","section":"%s","ckey":"%s","cvalue":"%s","mod_id":%s,"server_only":%s,"locked":0,"origin":"operator"}' \
	       "$1" "$2" "$3" "$4" "$5" "$6"
}
# The snapshot is written by the REAL record_applied(), not by a hand-rolled printf.
#
# The first version of this helper wrote the JSON itself and every comparison case came back
# 'unknown', which looked like a broken reader and was a broken FIXTURE: the keys are joined
# with 0x1F, and a raw control byte inside a JSON string is invalid JSON, so php's json_decode
# returned NULL. Python's json.dump emits it as a six-character backslash-u escape instead.
# A fixture the parser silently rejects proves nothing about the code, and here it would have
# proved the opposite of the truth. Driving the real writer also makes this two-sided: writer
# and reader have to agree, not merely each satisfy me separately.
snap() { # file section key value server_only
	python3 - "$PY" "$SANDBOX" "$1" "$2" "$3" "$4" "$5" <<'SNAP'
import importlib.util, os, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)

mc.WORLDS_ROOT = os.path.join(sys.argv[2], "opt/stateful/games/valheim/worlds")
cfg, section, key, value, srv = sys.argv[3:8]
# shutil.chown to a user that does not exist outside the container must not fail the fixture.
import shutil
shutil.chown = lambda *a, **k: None
mc.record_applied("testworld", {cfg: [{
    "cfg_file": cfg, "section": section, "ckey": key, "cvalue": value,
    "server_only": int(srv), "locked": 0, "origin": "operator",
}]})
SNAP
}

echo
echo "== the harness itself =="
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[]}")
if [ -z "$out" ]; then
	echo "  FATAL: the driver produced nothing -- $(cat "$SANDBOX/err")"
	echo; echo "$PASS passed, $((FAIL+1)) failed"; exit 1
fi
pass "the real functions were extracted and the world path was rewritten into the sandbox"

echo
echo "== T1-T3: the three states that are not 'pending' =="

# T1. A stopped world. Everything differs and NONE of it is pending: its next start applies
# the lot, so there is nothing for the operator to do.
rm -f "$WORLDDIR/.applied-configs"
out=$(drive "{\"online\":false,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main Rate 99 9 1)]}")
case "$out" in
	na\|\|*) pass "T1 a STOPPED world reports 'na' -- nothing is pending against a world that is not running" ;;
	*)       fail "T1 a stopped world did not report 'na'" "got: $out" ;;
esac

# T2. THE CONTROL. Running, but nothing was ever recorded -- a world started before this
# release, or a materialise that could not write the file. "I cannot tell" and "nothing
# pending" are different answers and the badge must not borrow one for the other.
rm -f "$WORLDDIR/.applied-configs"
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main Rate 99 9 1)]}")
case "$out" in
	unknown\|\|) pass "T2 no snapshot reports 'unknown' and contributes NO badge label" ;;
	clean*)      fail "T2 a world with no snapshot was reported CLEAN" \
	                  "that is the 0-means-two-things bug: unknown must not read as up to date" ;;
	*)           fail "T2 no snapshot gave an unexpected verdict" "got: $out" ;;
esac

# T3. Recorded, and identical. The ordinary case, and the one that proves a 'pending' verdict
# elsewhere is a comparison rather than a constant.
snap srv.cfg Main Rate 99 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main Rate 99 9 1)]}")
case "$out" in
	clean\|\|) pass "T3 a world running exactly what is saved reports 'clean'" ;;
	*)         fail "T3 an up-to-date world was not clean" "got: $out" ;;
esac

echo
echo "== T4-T8: what counts as pending =="

# T4/T5. The same shape twice, and T5 is the whole reason this is a snapshot diff: the row
# ALREADY EXISTED and only its value moved, which is what date_set cannot see.
snap srv.cfg Main Rate 10 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main Rate 99 9 1)]}")
case "$out" in
	"pending|Rate:changed|1 mod setting") pass "T5 an EXISTING override whose value changed is pending (date_set would miss this)" ;;
	*) fail "T5 a changed value was not reported pending" "got: $out" ;;
esac

# T7. A setting saved for the first time since the world started.
snap other.cfg Main Other 1 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row other.cfg Main Other 1 9 1),$(row srv.cfg Main New 5 9 1)]}")
case "$out" in
	"pending|New:set|1 mod setting") pass "T7 a NEW server-side override is pending, and reports action 'set'" ;;
	*) fail "T7 a new override was not reported pending" "got: $out" ;;
esac

# T8. Reset since the world started: applied then, gone from the database now. The world is
# still running the old value, so this is pending in exactly the way an edit is -- and leaving
# it out would make "reset it, then restart" a step the UI never admits is needed.
snap srv.cfg Main Gone 7 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[]}")
case "$out" in
	"pending|Gone:reset|1 mod setting") pass "T8 an override RESET since the world started is pending, as 'reset'" ;;
	*) fail "T8 a reset override was not reported pending" "got: $out" ;;
esac

# T6. THE SECOND CONTROL. A setting only players read is not waiting on a restart; it is
# waiting on Apply to players, which the editor already says. mod 7 is deploy_client=1.
snap hud.cfg Main Size 1 0
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row hud.cfg Main Size 2 7 0)]}")
case "$out" in
	clean\|\|) pass "T6 a CLIENT-side mod's changed setting is NOT a restart matter" ;;
	pending*)  fail "T6 a client-only change was reported as restart-pending" \
	                "a restart cannot deliver it -- Apply to players can" ;;
	*)         fail "T6 unexpected verdict for a client-side change" "got: $out" ;;
esac

# And the mirror image of T6: the same file and mod, but server_only. Without this, T6 passes
# for a function that ignores every change in a non-server_only file for the wrong reason.
snap hud.cfg Main Size 1 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row hud.cfg Main Size 2 7 1)]}")
case "$out" in
	pending*) pass "T6b the SAME setting marked server_only IS pending -- T6 is about reach, not about being ignorable" ;;
	*)        fail "T6b a server_only change on a client mod's file was not pending" "got: $out" ;;
esac

# A mod that is installed but NOT deployed to clients also reads its config on the server.
snap srvmod.cfg Main Tick 1 0
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srvmod.cfg Main Tick 2 9 0)]}")
case "$out" in
	pending*) pass "T6c a deploy_client=0 mod's setting is pending without needing server_only" ;;
	*)        fail "T6c a server-only mod's change was not pending" "got: $out" ;;
esac

echo
echo "== T9-T11: the edges =="

# T9. A torn write decodes to null. Same rule as T2: cannot tell is not clean.
#
# Produced by truncating a REAL snapshot rather than by hand-writing broken JSON, so it is the
# failure that can actually happen on disk. (record_applied writes to a temp file and renames,
# so this is unlikely -- but "unlikely" is not a reason for the reader to answer 'clean'.)
snap srv.cfg Main Rate 99 1
full=$(wc -c < "$WORLDDIR/.applied-configs")
head -c $((full / 2)) "$WORLDDIR/.applied-configs" > "$WORLDDIR/.trunc"
mv "$WORLDDIR/.trunc" "$WORLDDIR/.applied-configs"
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main Rate 99 9 1)]}")
case "$out" in
	unknown\|\|) pass "T9 a truncated snapshot reports 'unknown', not 'clean'" ;;
	*)           fail "T9 a corrupt snapshot was not reported unknown" "got: $out" ;;
esac

# T10. The loader's own file is excluded from the editor everywhere else; it must not raise a
# badge the operator has no page on which to resolve.
snap BepInEx.cfg Logging Level 1 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row BepInEx.cfg Logging Level 9 9 1)]}")
case "$out" in
	clean\|\|) pass "T10 an EXCLUDED file (the BepInEx loader's own) raises nothing" ;;
	*)         fail "T10 an excluded file was reported pending" "got: $out" ;;
esac

# T11. Plural agreement, because the label is the whole badge text the operator reads.
snap srv.cfg Main A 1 1
out=$(drive "{\"online\":true,\"mods\":$MODS,\"overrides\":[$(row srv.cfg Main A 2 9 1),$(row srv.cfg Main B 2 9 1)]}")
case "$out" in
	*\|2\ mod\ settings) pass "T11 two pending settings label as '2 mod settings'" ;;
	*)                   fail "T11 wrong label for two settings" "got: $out" ;;
esac

echo
echo "== T12-T14: the doors =="

# Everything above tests a function. These test that anything reaches the operator at all --
# a working check wired to nothing is this project's most repeated shipping mistake.
grep -q "require_once '/opt/stateless/nginx/www/includes/modconfigs.php'" "$IDX" \
	&& pass "T12 index.php requires modconfigs.php (without it the dashboard is a fatal, not a missing badge)" \
	|| fail "T12 index.php does not require modconfigs.php" \
	        "modConfigPendingLabels() is called in its worlds loop -- the front page would die"

for f in "$IDX" "$API"; do
	n=$(grep -cF 'modConfigPendingLabels($pdo, $row[' "$f")
	[ "$n" = "1" ] \
		&& pass "T13 $(basename "$f") merges the labels into restartPending" \
		|| fail "T13 $(basename "$f") does not merge the labels (count $n)" \
		        "the page renders this badge server-side on load and from the poll after; one site without the merge is a badge that appears then vanishes"
done

# T14. The snapshot writer must run even when the world has NO overrides, or a world whose
# overrides were all reset keeps the previous run's file and reports settings pending forever
# with no action that clears it. Asserted by line order: the call must come BEFORE the
# early return it would otherwise sit under.
callLine=$(grep -n '^    record_applied(world, overrides)' "$PY" | head -1 | cut -d: -f1)
retLine=$(awk '/^    if not overrides:/{print NR; exit}' "$PY")
if [ -n "$callLine" ] && [ -n "$retLine" ]; then
	[ "$callLine" -lt "$retLine" ] \
		&& pass "T14 materialise() records the snapshot BEFORE the no-overrides return (line $callLine < $retLine)" \
		|| fail "T14 the snapshot is recorded after the no-overrides return (line $callLine > $retLine)" \
		        "a world with every override reset would keep the old snapshot and report pending forever"
else
	fail "T14 could not locate the record_applied call or the no-overrides return" \
	     "call=$callLine return=$retLine -- this check is blind, treat it as failing"
fi

grep -q 'handOffToDashboard(note)' "$WC" && grep -q 'handOffToDashboard(prog)' "$WC" \
	&& pass "T15 BOTH apply paths hand off to the dashboard (the modal's button and the nothing-to-save one)" \
	|| fail "T15 only one of the two apply paths hands off to the dashboard" \
	        "they are the same button to the operator and must end in the same place"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
