<?php
# Oracle test for the crossplay join code on the public world card.
#
# THE BUG (reported on production 2026-09-10): "I can connect to my vanilla world BayArea
# using the in-game browser, but not via the Steam:// Launch! button."
#
# A crossplay world does not accept a direct IP connection. Valheim opens a PlayFab server
# instead of a Steam one, registers a lobby, and players join with a code:
#
#   Session "BayArea" registered with join code 441944
#
# The card offered `steam://run/892970//+connect <host>:<port>` regardless, which asks for a
# direct connection the server is not serving. It failed silently -- Steam launches the game
# and nothing happens -- which is why it survived unnoticed while the in-game browser worked.
#
# NOTE ON A DEAD END: I first assumed the discriminator was the game port not being bound.
# It is not. A NON-crossplay vanilla world also binds only its query port (port+1); Steam
# servers carry game traffic over Steam's relay, so neither mode shows a game-port listener.
# The real discriminator is "Opened PlayFab server" vs "Opened Steam server". A test built on
# the port theory would have passed while testing the wrong thing entirely.
#
# Cases 1-5 exercise the REAL getWorldJoinCode() from db_gets.php. Cases 6+ extract the card's
# decision block from authenticated.php and run it, so this tests the shipped source rather
# than a copy of it that can drift.
#
# Usage: php dev_tools/test-vanilla-joincode.php

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail ? " -- $detail" : "") . "\n"; }
}

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/joincode_' . getmypid();
@mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") as $f) { @unlink($f); }
    @rmdir($tmp);
});

# getWorldJoinCode() reads a fixed path, so point that path at the sandbox by defining the
# log directory the same way the function builds it. Simplest honest approach: copy the real
# function body out and run it against a parameterised path.
$src = file_get_contents("$root/container/nginx/www/includes/db_gets.php");
if (!preg_match('/function getWorldJoinCode\(\$world\) \{.*?\n\}/s', $src, $m)) {
    echo "could not extract getWorldJoinCode from db_gets.php\n"; exit(1);
}
$fnSrc = str_replace(
    '$log = "/opt/stateful/logs/valheimworld_" . $world . ".log";',
    '$log = $GLOBALS["TESTLOGDIR"] . "/valheimworld_" . $world . ".log";',
    $m[0]
);
if (strpos($fnSrc, 'TESTLOGDIR') === false) {
    echo "path rebase failed -- the function's log path line changed shape\n"; exit(1);
}
eval($fnSrc);
$GLOBALS['TESTLOGDIR'] = $tmp;

function writeLog($name, $body) {
    file_put_contents($GLOBALS['TESTLOGDIR'] . "/valheimworld_$name.log", $body);
}

echo "\nCase 1: a real crossplay log yields its join code\n";
# Shape taken verbatim from BayArea's production log.
writeLog('cross', implode("\n", [
    '09/10/2026 12:53:55: Opened PlayFab server',
    '09/10/2026 12:53:55: Register PlayFab server "BayArea" with IP 203.0.113.10:27058',
    '09/10/2026 12:53:57: Session "BayArea" registered with join code 441944',
    '09/10/2026 12:53:59: Session "BayArea" with join code 441944 and IP 203.0.113.10:27058 is active with 0 player(s)',
]));
check('extracts 441944', getWorldJoinCode('cross') === '441944', var_export(getWorldJoinCode('cross'), true));

echo "\nCase 2: after a RESTART the newest code wins\n";
# The stale code is the dangerous answer: it looks perfectly valid and simply does not work.
writeLog('restart', implode("\n", [
    '09/10/2026 10:00:00: Session "W" registered with join code 111111',
    '09/10/2026 11:00:00: Session "W" registered with join code 222222',
]));
check('returns the LAST code, not the first', getWorldJoinCode('restart') === '222222',
    var_export(getWorldJoinCode('restart'), true));

echo "\nCase 3: a NON-crossplay (Steam) log has no code\n";
writeLog('steam', implode("\n", [
    '09/09/2026 21:02:15: Game server connected',
    '09/09/2026 21:02:34: Opened Steam server',
]));
check('returns NULL for a Steam server', getWorldJoinCode('steam') === NULL,
    var_export(getWorldJoinCode('steam'), true));

echo "\nCase 4: a missing log is not an error\n";
check('returns NULL, does not warn or throw', getWorldJoinCode('nosuchworld') === NULL);

echo "\nCase 5: NUL bytes do not hide the code\n";
# World logs pick up NULs from torn writes. They made grep silently under-report elsewhere
# in this project, so the same data must not defeat this reader.
writeLog('nul', "junk\0\0\0more junk\n" . 'Session "W" registered with join code 987654' . "\n\0trailing\0");
check('still finds 987654', getWorldJoinCode('nul') === '987654', var_export(getWorldJoinCode('nul'), true));

echo "\nCase 6: the code is found at the END of a very large log\n";
# The reader only looks at the tail. A log bigger than that window must still work, because
# every real log is bigger than the window.
writeLog('big', str_repeat("filler line that is not interesting\n", 40000)
    . 'Session "W" registered with join code 555000' . "\n");
$bigSize = filesize("$tmp/valheimworld_big.log");
check('finds the code in a ' . round($bigSize / 1024) . 'KB log', getWorldJoinCode('big') === '555000',
    var_export(getWorldJoinCode('big'), true));

# ---------------------------------------------------------------- card rendering
# Drive the REAL decision block out of authenticated.php.
$card = file_get_contents("$root/container/nginx/www/public/authenticated.php");
$lines = explode("\n", $card);
$start = NULL; $end = NULL;
foreach ($lines as $i => $l) {
    if ($start === NULL && strpos($l, '# A CROSSPLAY world cannot be joined by IP at all') !== false) { $start = $i; }
    if ($start !== NULL && trim($l) === 'echo "') { $end = $i; break; }
}
if ($start === NULL || $end === NULL) {
    echo "\ncould not locate the card decision block in authenticated.php\n"; exit(1);
}
$block = implode("\n", array_slice($lines, $start, $end - $start));

function renderCard($block, $crossplay, $online, $code) {
    # Stand in for the real lookup so the render cases are about the RENDER, not the parser.
    $GLOBALS['STUB_CODE'] = $code;
    $vanillaCrossplay = $crossplay;
    $isOnline = $online;
    $worldDimmed = $online ? '' : 'dimmed';
    $vanillaSteamUrl = 'steam://run/892970//+connect example.com:27058';
    $myWorld = 'W';
    $joinLink = $joinCodeRow = $vanillaHint = '';
    eval($block);
    return ['link' => $joinLink, 'row' => $joinCodeRow, 'hint' => $vanillaHint];
}
function getWorldJoinCodeStub($w) { return $GLOBALS['STUB_CODE']; }
# The block calls getWorldJoinCode(); route it to the stub for these cases.
$block = str_replace('getWorldJoinCode($myWorld)', 'getWorldJoinCodeStub($myWorld)', $block);

echo "\nCase 7: an ONLINE CROSSPLAY world offers the code, not a dead link\n";
$r = renderCard($block, true, true, '441944');
check('no steam:// link is offered', strpos($r['link'], 'steam://') === false, $r['link']);
check('the button reads "Join Code"', strpos($r['link'], 'Join Code') !== false, $r['link']);
check('the code appears on the card', strpos($r['row'], '441944') !== false, $r['row']);
check('a copy action is offered', strpos($r['row'], 'copyVanillaJoinCode') !== false);
check('the hint stops telling players to Join by IP',
    stripos($r['hint'], 'cannot be joined by IP') !== false, $r['hint']);

echo "\nCase 8: an ONLINE NON-crossplay world keeps its Launch link\n";
# The fix must not take the working button away from the worlds where it works.
$r = renderCard($block, false, true, NULL);
check('steam:// link still offered', strpos($r['link'], 'steam://') !== false, $r['link']);
check('button still reads "Launch!"', strpos($r['link'], 'Launch!') !== false, $r['link']);
check('no join code row at all', $r['row'] === '', $r['row']);
check('hint still points at Join IP', stripos($r['hint'], 'Join IP') !== false, $r['hint']);

echo "\nCase 9: crossplay world that is UP but has not registered its lobby yet\n";
# Between process start and lobby registration there is genuinely no code. An empty row
# reads as a broken card; say what is happening.
$r = renderCard($block, true, true, NULL);
check('says it is starting rather than showing blank',
    stripos($r['row'], 'starting') !== false, $r['row']);

echo "\nCase 10: an OFFLINE crossplay world\n";
$r = renderCard($block, true, false, NULL);
check('button reads offline', stripos($r['link'], 'offline') !== false, $r['link']);
check('no stale code is shown', strpos($r['row'], '441944') === false, $r['row']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
