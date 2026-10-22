<?php
# Oracle test: a crossplay world's join code must survive log rotation -- any number of them --
# and must never be served once its session is over.
#
# THE BUG (production, 2026-10-18, a server running :rc):
# `VikingOutlaws` was up and joinable on code 417250 for 35 hours while the admin dashboard said
# "Crossplay world: waiting for its join code". A modded world's startup passes
# settings.maxLogSize (1 MB) within minutes; logRotater.sh is `cp` + truncate with ONE
# generation kept, so every mention of the code moved into `<log>.1`, and Valheim re-prints the
# code only when a player joins or leaves. Nobody had joined. It never self-heals.
#
# NOT the 256 KB tail window being too small: the live log was 175 KB. The lines were in a
# different FILE. A test built on the window theory would have passed while testing nothing.
#
# THE FIX has two halves, and this file drives BOTH with the same fixtures, because they are
# two implementations of one rule and the dangerous failure is the two drifting apart:
#
#   writer  engine/tools/playerMonitor  -- every 2 min, reads `.log.1` + live, remembers the
#                                          code in worlds.join_code, forgets it on a new session
#   reader  getWorldJoinCode()          -- live log, then `.log.1` + live, then the remembered
#                                          code, but ONLY when no session start is visible
#
# The rule, over the history (`.log.1` then the live log):
#   1. last code after the last session start  -> that code
#   2. a session start with no code after it   -> NULL (not issued yet)
#   3. no session start visible                -> the remembered code
#
# Case 2 is the one that matters. The code is reissued on every restart, so a code from an
# earlier session silently does not work -- handing it out is worse than the bug.
#
# Every behavioural claim has a mutation control that re-runs the scenarios against a
# deliberately broken copy of the code under test (a PHP subprocess for the reader, a rewritten
# script for the writer). Each control is asserted to apply exactly once; a mutation that
# silently fails to apply turns its control into a test that the code still works.
#
# Usage: php dev_tools/test-joincode-survives-rotation.php

$root = dirname(__DIR__);

# ---------------------------------------------------------------- fixtures
# Line shapes taken from real Valheim logs (VikingOutlaws on production, test123456 on a dev server).
const START   = '10/17/2026 01:21:48: Loading: Starting to load scene: start.unity (169d7618616154c03be07e9ad3af5893)';
const RESTART = '10/18/2026 09:00:00: Loading: Starting to load scene: start.unity (169d7618616154c03be07e9ad3af5893)';
const FILLER  = '10/17/2026 01:22:00: World saved ( 142.1583ms )';

function filler($bytes) {
    return str_repeat(FILLER . "\n", (int)ceil($bytes / (strlen(FILLER) + 1)));
}
function lines(...$l) { return implode("\n", $l) . "\n"; }

# name => [live, prev|NULL (no rotated generation), remembered|NULL, expected reader answer]
function scenarios() {
    return [
        # 1. THE REPORTED CASE, one rotation. The startup -- and its only code mentions -- sit
        #    ~1 MB from the END of `.log.1`, which is where a rotation at the 1 MB threshold
        #    really leaves them. Answered from the logs alone; nothing remembered yet.
        'rotated' => [
            "\n" . filler(175000),
            lines(START,
                '10/17/2026 01:23:24: Session "VikingOutlaws" registered with join code 417250',
                '10/17/2026 01:23:26: Session "VikingOutlaws" with join code 417250 and IP 203.0.113.10:25058 is active with 0 player(s)')
                . filler(900000),
            NULL, '417250'],

        # 2. THE CONTROL THAT MATTERS. A session started AFTER the rotation and has no code yet.
        #    `.log.1` holds an earlier session's 111111, and -- the nasty part -- so does the
        #    database, because playerMonitor has not run since the restart. Both are dead.
        'staleafter' => [
            lines(RESTART, '10/18/2026 09:00:03: New session server "W" that has join code , now 0 player(s)'),
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 111111') . filler(400000),
            '111111', NULL],

        # 3. No regression: a code in the live log wins over everything else.
        'liveok' => [
            lines(RESTART, '10/18/2026 09:00:03: Session "W" registered with join code 222222'),
            lines('Session "W" registered with join code 333333'),
            '999999', '222222'],

        # 4. Nothing anywhere. NULL, never '' -- an empty string renders as a code, and
        #    "waiting for its code" must stay distinguishable from "this is its code".
        'neither' => ["\n" . filler(20000), filler(20000), NULL, NULL],

        # 5. A world that has never rotated: the absent `.log.1` must not warn.
        'nolog1' => ["\n" . filler(20000), NULL, NULL, NULL],

        # 6. THE DURABLE CASE: two rotations, so the session start and every code mention are
        #    gone from BOTH generations. Only the remembered code can answer. This is the case
        #    a `.log.1`-only fix silently fails, a day or two later.
        'twice' => ["\n" . filler(175000), filler(1000000), '417250', '417250'],

        # 7. A remembered empty string is still "nothing" -- never rendered as a code.
        'emptyremembered' => ["\n" . filler(20000), filler(20000), '', NULL],

        # 8. A live log past the 4 MB read cap, so its tail no longer adjoins `.log.1`. The
        #    running session's start and code are in the GAP; `.log.1` holds an older session's
        #    start and code. Joining the two makes that old session look newest, and its dead
        #    111111 is served. The reader must defer to the writer, which reads both whole.
        'biglive' => [
            lines(RESTART, '10/18/2026 09:01:00: Session "W" registered with join code 555555') . filler(4300000),
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 111111'),
            '555555', '555555'],

        # ---- 2.57: the engine's session mark (element 5: [offset|'end', mark 'before'|'after' .log.1's mtime])
        # 9. THE RELAUNCH FLICKER. The world was just restarted: the log still ends with the OLD
        #    session's start and code (nothing truncates it), the database remembers the same
        #    code, and Valheim has not printed its start line yet. Rule 1 served 417250 -- a
        #    dead code -- for those seconds. The engine marked the session at the END of the
        #    file: nothing after it, so NULL (waiting), never the text before the mark.
        'launched' => [
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 417250') . filler(5000),
            NULL, '417250', NULL, ['end', 'after']],

        # 10. The same world a few seconds later: the new session has reported after the mark.
        'launchedcode' => [
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 417250')
                . lines(RESTART, '10/18/2026 09:00:03: Created new join code 284283 for session "W"'),
            NULL, '417250', '284283', [strlen(lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 417250')), 'after']],

        # 11. A mark taken BEFORE a rotation points into unrelated text: `.log.1` is newer than
        #     the mark, so it is unusable and the history rule answers (the 2.56 reported case).
        #     Trusting it would read filler from byte 10 and say "waiting" -- the 35-hour bug.
        'markrotated' => [
            "\n" . filler(175000),
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 417250') . filler(400000),
            NULL, '417250', [10, 'before']],

        # 12. A mark past the end of the file (rotated, no .log.1 kept) is unusable too.
        'markoverflow' => [
            lines(RESTART, '10/18/2026 09:00:03: Session "W" registered with join code 222222'),
            NULL, NULL, '222222', [999999, 'after']],

        # 13. A usable mark at 0 with an older `.log.1`: the whole live log is this session.
        'markfresh' => [
            lines(RESTART, '10/18/2026 09:00:03: Session "W" registered with join code 222222'),
            lines(START, '10/17/2026 01:23:24: Session "W" registered with join code 111111'),
            '111111', '222222', [0, 'after']],
    ];
}
const LOG1_MTIME = 1000000000;

function writeScenario($dir, $name, $s) {
    $base = "$dir/valheimworld_$name.log";
    file_put_contents($base, $s[0]);
    @unlink("$base.1");
    if ($s[1] !== NULL) { file_put_contents("$base.1", $s[1]); touch("$base.1", LOG1_MTIME); }
}
# The engine's mark for a scenario, or NULL: offset 'end' = the live file's size (just launched).
function scenarioMark($dir, $name, $s) {
    if (!isset($s[4])) { return NULL; }
    $off = $s[4][0] === 'end' ? strlen($s[0]) : (int)$s[4][0];
    return [$off, $s[4][1] === 'after' ? LOG1_MTIME + 100 : LOG1_MTIME - 100];
}

# ---------------------------------------------------------------- a stand-in for $pdo
# Answers phvRememberedJoinCode()'s one query from an array, and records the query text so the
# test can assert the STIMULUS -- that the reader asked for worlds.join_code by name -- rather
# than trusting a stub that would answer any question.
class FakePdo {
    public $codes = [];
    public $marks = [];     # 2.57: world => [log_offset, epoch], the engine's session mark
    public $queries = [];
    function prepare($q) { $this->queries[] = $q; return new FakeStmt($this, $q); }
}
class FakeStmt {
    private $pdo; private $q; private $name;
    function __construct($pdo, $q) { $this->pdo = $pdo; $this->q = $q; }
    function execute($args) { $this->name = $args[0]; return true; }
    function fetchColumn() {
        return array_key_exists($this->name, $this->pdo->codes) ? $this->pdo->codes[$this->name] : false;
    }
    # The mark row, only for a query that asks phv_events for it; anything else gets no row.
    function fetch($mode = null) {
        if (stripos($this->q, 'phv_events') === false || !array_key_exists($this->name, $this->pdo->marks)) { return false; }
        list($off, $t) = $this->pdo->marks[$this->name];
        return ['t' => $t, 'detail' => json_encode(['session' => true, 'log_offset' => $off])];
    }
}

# ---------------------------------------------------------------- the reader under test
function loadReader($srcPath) {
    $src = @file_get_contents($srcPath);
    if ($src === false) { echo "cannot read $srcPath\n"; exit(1); }
    $fnSrc = '';
    foreach ([
        'phvWorldLogPath\(\$world, \$gen = \d+\)',
        'phvReadLogTail\(\$world, \$window = \d+, \$gen = \d+\)',
        'phvSessionStartPos\(\$tail\)',
        'phvSessionScope\(\$tail\)',
        'phvLastJoinCode\(\$text\)',
        'phvRememberedJoinCode\(\$pdo, \$world\)',
        'phvEngineSessionMark\(\$pdo, \$world\)',
        'phvSessionMarkUsable\(\$world, \$mark\)',
        'getWorldJoinCode\(\$pdo, \$world\)',
    ] as $sig) {
        if (!preg_match('/function ' . $sig . ' \{.*?\n\}/s', $src, $m)) {
            echo "could not extract /$sig/ from $srcPath -- has it been renamed?\n"; exit(1);
        }
        $fnSrc .= $m[0] . "\n";
    }
    $fnSrc = str_replace(
        '$log = "/opt/stateful/logs/valheimworld_" . $world . ".log";',
        '$log = $GLOBALS["TESTLOGDIR"] . "/valheimworld_" . $world . ".log";', $fnSrc);
    if (strpos($fnSrc, 'TESTLOGDIR') === false) { echo "path rebase failed\n"; exit(1); }
    eval($fnSrc);
}

function readAll($dir) {
    $out = [];
    foreach (scenarios() as $name => $s) {
        writeScenario($dir, $name, $s);
        $pdo = new FakePdo();
        if ($s[2] !== NULL) { $pdo->codes[$name] = $s[2]; }
        if (($mk = scenarioMark($dir, $name, $s)) !== NULL) { $pdo->marks[$name] = $mk; }
        $GLOBALS['WARNED'] = '';
        set_error_handler(function ($no, $str) { $GLOBALS['WARNED'] .= "$str; "; return true; });
        $code = getWorldJoinCode($pdo, $name);
        restore_error_handler();
        $out[$name] = ['code' => $code, 'warn' => $GLOBALS['WARNED'], 'queries' => $pdo->queries];
    }
    return $out;
}

# ---------------------------------------------------------------- the writer under test
# Runs the REAL playerMonitor against the fixtures, with three lines rebased: the config source
# becomes an SQL stub that prints each statement, the log directory points at the sandbox, and
# the running-world query returns the one world under test. Everything else is shipped code.
function writerScript($toolSrc, $dir, $world) {
    $n = 0;
    $s = preg_replace('#^source /opt/stateless.*$#m', 'SQL() { echo "SQL $*"; }', $toolSrc, 1, $c); $n += $c;
    $s = preg_replace('#^logDir=.*$#m', 'logDir=' . escapeshellarg($dir), $s, 1, $c); $n += $c;
    $s = preg_replace('#^worldRows=.*$#m', 'worldRows=$(printf "%s\t%s" ' . escapeshellarg($world) . ' 1)', $s, 1, $c); $n += $c;
    if ($n !== 3) { echo "writer rebase applied $n of 3 rewrites -- playerMonitor changed shape\n"; exit(1); }
    return $s;
}

# What playerMonitor does to worlds.join_code for one world: 'keep' (no statement), NULL
# (forgotten), or the code it stored.
function runWriter($toolSrc, $dir, $world) {
    $script = "$dir/pm_$world.sh";
    file_put_contents($script, writerScript($toolSrc, $dir, $world));
    $out = shell_exec('bash ' . escapeshellarg($script) . ' 2>/dev/null');
    if (!preg_match_all("/SQL UPDATE worlds SET join_code=(NULL|'(\\d+)') WHERE name='" . preg_quote($world, '/') . "'/", (string)$out, $m)) {
        return 'keep';
    }
    $last = count($m[0]) - 1;
    return $m[1][$last] === 'NULL' ? NULL : $m[2][$last];
}

# ---------------------------------------------------------------- sandbox
$tmp = sys_get_temp_dir() . '/jcrot_' . getmypid();
@mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") as $f) { @unlink($f); }
    @rmdir($tmp);
});
$GLOBALS['TESTLOGDIR'] = $tmp;

# Child mode: a reader mutation needs a FRESH process, since PHP cannot redeclare a function.
$childSrc = getenv('PHV_JOINCODE_SRC');
if ($childSrc !== false && $childSrc !== '') {
    loadReader($childSrc);
    $r = readAll($tmp);
    echo json_encode(array_map(function ($x) { return $x['code']; }, $r)), "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; }
}
function show($v) { return var_export($v, true); }

$dbGets  = "$root/container/nginx/www/includes/db_gets.php";
$monitor = "$root/container/engine/tools/playerMonitor";
loadReader($dbGets);
$toolSrc = file_get_contents($monitor);

# ================================================================ reader
echo "\nREADER -- getWorldJoinCode()\n";
$r = readAll($tmp);
$why = [
    'rotated'         => 'one rotation: answered from .log.1 (the reported case)',
    'staleafter'      => 'restarted after rotation: NULL, NOT the dead 111111 in .log.1 AND the database',
    'liveok'          => 'a code in the live log wins over .log.1 and the database',
    'neither'         => 'nothing anywhere: NULL, not the empty string',
    'nolog1'          => 'never rotated: NULL',
    'twice'           => 'TWO rotations: only the remembered code can answer',
    'emptyremembered' => 'a remembered empty string is still NULL',
    'biglive'         => 'live log past the read cap: defers to the writer instead of trusting .log.1',
    'launched'        => 'RELAUNCHED, engine mark at the end: NULL, NOT the old session\'s code still in the log and the database',
    'launchedcode'    => 'the new session reported after the mark: its code',
    'markrotated'     => 'a mark older than .log.1 is unusable: the history rule answers (no "waiting forever")',
    'markoverflow'    => 'a mark past the end of the file is unusable: the live log answers',
    'markfresh'       => 'a usable mark at 0 with an older .log.1: the live session wins',
];
foreach (scenarios() as $name => $s) {
    check($why[$name], $r[$name]['code'] === $s[3], 'got ' . show($r[$name]['code']) . ', want ' . show($s[3]));
}
check('no warning when .log.1 does not exist', $r['nolog1']['warn'] === '', $r['nolog1']['warn']);
# The stimulus: case 6 must have been answered by ASKING for worlds.join_code, by name.
# 2.57: every read first asks phv_events for the engine's session mark (log_offset) -- that is
# the only way to know a relaunch happened -- and worlds.join_code only when no log can answer.
$isMark = function ($q) { return (bool)preg_match('/FROM\s+phv_events\s+WHERE\s+world\s*=\s*\?.*log_offset/is', $q); };
$isCode = function ($q) { return (bool)preg_match('/SELECT\s+join_code\s+FROM\s+worlds\s+WHERE\s+name\s*=\s*\?/i', $q); };
check('the remembered code is read from worlds.join_code by world name, after the mark lookup',
    count($r['twice']['queries']) === 2 && $isMark($r['twice']['queries'][0]) && $isCode($r['twice']['queries'][1]),
    show($r['twice']['queries']));
check('when the live log answers, only the mark is looked up; worlds.join_code is not read',
    count($r['liveok']['queries']) === 1 && $isMark($r['liveok']['queries'][0]),
    show($r['liveok']['queries']));
check('the relaunch case never reaches worlds.join_code (the remembered code is the dead one)',
    count($r['launched']['queries']) === 1 && $isMark($r['launched']['queries'][0]),
    show($r['launched']['queries']));

# ================================================================ writer
echo "\nWRITER -- engine/tools/playerMonitor\n";
$w = [];
foreach (scenarios() as $name => $s) {
    writeScenario($tmp, $name, $s);
    $w[$name] = runWriter($toolSrc, $tmp, $name);
}
check('one rotation: remembers 417250',              $w['rotated'] === '417250', show($w['rotated']));
check('new session, no code yet: FORGETS (NULL)',     $w['staleafter'] === NULL, show($w['staleafter']));
check('live code: remembers 222222',                  $w['liveok'] === '222222', show($w['liveok']));
check('nothing visible: leaves the remembered code',  $w['twice'] === 'keep', show($w['twice']));
check('nothing at all: writes nothing',               $w['neither'] === 'keep', show($w['neither']));

# A writer that stored the REGISTRATION line's code would remember the sticky stale one.
writeScenario($tmp, 'replaced', [lines(RESTART,
    '10/01/2026 23:17:57: Session "test123" registered with join code 537586',
    '10/01/2026 23:17:58: Created new join code 284283 for session "test123"'), NULL, NULL, NULL]);
check('remembers the REPLACEMENT 284283, not the sticky registered 537586',
    ($x = runWriter($toolSrc, $tmp, 'replaced')) === '284283', show($x));

echo "\nWRITER AND READER AGREE wherever the logs can answer\n";
foreach (['rotated', 'staleafter', 'liveok'] as $name) {
    check("'$name': writer stores what the reader serves", $w[$name] === $r[$name]['code'],
        'writer ' . show($w[$name]) . ', reader ' . show($r[$name]['code']));
}

# ================================================================ lifecycle
# The whole story, in order, with the real writer and the real reader sharing one database
# value and real rotations (logRotater's exact cp + truncate) in between. Note what is ABSENT:
# no step tells either side that the world restarted. That is the supervisor-autorestart case
# -- a crash relaunched without the engine knowing -- and the design does not depend on being
# told.
echo "\nLIFECYCLE -- start, rotate, rotate, restart, new code\n";
$world = 'life';
$log   = "$tmp/valheimworld_$world.log";
$db    = NULL;
@unlink("$log.1");
$rotate = function () use ($log) { copy($log, "$log.1"); file_put_contents($log, "\n"); };
$append = function ($text) use ($log) { file_put_contents($log, $text, FILE_APPEND); };
$tick   = function () use (&$db, $toolSrc, $tmp, $world) {
    $v = runWriter($toolSrc, $tmp, $world);
    if ($v !== 'keep') { $db = $v; }
};
$serve  = function () use (&$db, $world) {
    $pdo = new FakePdo(); if ($db !== NULL) { $pdo->codes[$world] = $db; }
    return getWorldJoinCode($pdo, $world);
};

file_put_contents($log, lines(START, '10/17/2026 01:23:24: Session "life" registered with join code 417250') . filler(300000));
$tick();
check('up: serves 417250, and playerMonitor remembered it', $serve() === '417250' && $db === '417250', "db=" . show($db));

$rotate(); $append(filler(1100000)); $tick();
check('one rotation later: still 417250', ($x = $serve()) === '417250', show($x));

$rotate(); $append(filler(200000)); $tick();
check('TWO rotations later: still 417250, from the database', ($x = $serve()) === '417250', show($x));
# Control: the same moment with nothing remembered. If this also said 417250, the case above
# would prove nothing about the database.
$saved = $db; $db = NULL;
check('  control: with nothing remembered, the same moment is NULL', ($x = $serve()) === NULL, show($x));
$db = $saved;

# The world restarts (a crash, autorestarted). The new session has not registered yet.
$append(lines(RESTART));
check('restarted, no code yet: NULL even though the database still says 417250 (before the writer runs)',
    ($x = $serve()) === NULL && $db === '417250', show($x) . ', db=' . show($db));
$tick();
check('and the next playerMonitor pass forgets it', $db === NULL, show($db));

$append(lines('10/18/2026 09:01:30: Session "life" registered with join code 284283'));
$tick();
check('the new session\'s code 284283 is served and remembered', ($x = $serve()) === '284283' && $db === '284283',
    show($x) . ', db=' . show($db));

# ================================================================ mutation controls
echo "\nMUTATION CONTROLS -- each must BREAK the case it claims to cover\n";

$readerMutations = [
    ['rule 2 removed: a start with no code falls through to the remembered code',
        'if (phvSessionStartPos($hist) !== false) { return NULL; }', '',
        'staleafter', '111111'],
    ['the remembered code is never consulted (a .log.1-only fix)',
        'return phvRememberedJoinCode($pdo, $world);', 'return NULL;',
        'twice', NULL],
    ['the history is tailed at 256 KB like the live log',
        '$cap  = 4194304;', '$cap  = 262144;',
        'rotated', NULL],
    ['.log.1 is joined on even when it does not adjoin the live tail',
        '(@filesize(phvWorldLogPath($world)) <= $cap) ?', '(true) ?',
        # Predicted NULL (case 2) when this was written; the run showed worse -- the old
        # session's 111111 is the "last code after the last start" across the gap, so case 1
        # serves a DEAD code. Pinned to what actually happens.
        'biglive', '111111'],
    # 2.57: the engine's session mark.
    ['the engine mark is ignored: a relaunch serves the previous session\'s dead code',
        'if (phvSessionMarkUsable($world, $mark)) {', 'if (false) {',
        'launched', '417250'],
    ['a rotation since the mark goes unnoticed: filler after byte 10 reads as "waiting"',
        'if ($mt === false || $mt >= $mark[1]) { return false; }', '',
        'markrotated', NULL],
    ['a mark past the end of the file is trusted',
        'if ($size === false || $mark[0] > $size) { return false; }', '',
        'markoverflow', NULL],
];
$src = file_get_contents($dbGets);
foreach ($readerMutations as $i => list($label, $find, $replace, $case, $expect)) {
    if (($n = substr_count($src, $find)) !== 1) {
        check("reader mutation applies exactly once: $label", false, "found $n -- the control is vacuous"); continue;
    }
    $mutPath = "$tmp/db_gets_mut$i.php";
    file_put_contents($mutPath, str_replace($find, $replace, $src));
    $got = json_decode((string)shell_exec('PHV_JOINCODE_SRC=' . escapeshellarg($mutPath)
        . ' php ' . escapeshellarg(__FILE__) . ' 2>/dev/null'), true);
    if (!is_array($got) || !array_key_exists($case, $got)) {
        check("reader mutation ran: $label", false, 'child produced no result'); continue;
    }
    check("reader: $label => '$case' breaks", $got[$case] === $expect,
        'want ' . show($expect) . ', got ' . show($got[$case]));
}

$writerMutations = [
    ['the forget branch removed: a new session keeps the dead code',
        "\telif [ -n \"\$startNo\" ]; then\n\t\tSQL \"UPDATE worlds SET join_code=NULL WHERE name='\$worldName';\"\n", '',
        'staleafter', 'keep'],
    ['the writer reads the live log only, not the history',
        '[ -f "$logFile.1" ] && cat "$logFile.1"; ', '',
        'rotated', 'keep'],
];
foreach ($writerMutations as list($label, $find, $replace, $case, $expect)) {
    if (($n = substr_count($toolSrc, $find)) !== 1) {
        check("writer mutation applies exactly once: $label", false, "found $n -- the control is vacuous"); continue;
    }
    $s = scenarios()[$case];
    writeScenario($tmp, $case, $s);
    $got = runWriter(str_replace($find, $replace, $toolSrc), $tmp, $case);
    check("writer: $label => '$case' breaks", $got === $expect, 'want ' . show($expect) . ', got ' . show($got));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
