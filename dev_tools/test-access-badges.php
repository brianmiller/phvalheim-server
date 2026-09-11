<?php
# Oracle test: the Access pills on the public world card.
#
# Four states, and the third is the one that matters:
#
#   public=1                  -> OPEN          no access list applied
#   public=0, list has ids    -> ACCESS LIST   only those ids may join
#   public=0, list EMPTY      -> OPEN          <- the honest answer, not "ACCESS LIST"
#   public=1, list EMPTY      -> OPEN
#
# Valheim applies permittedlist.txt only when it has entries. An access list that is switched on
# and empty is not "nobody may join", it is no restriction at all, and that world is reachable by
# anyone. Labelling it ACCESS LIST would repeat exactly the mistake the CROSSPLAY pill used to
# make -- describing the setting instead of the server. Nothing here changes the state (see
# CLAUDE.md and test-create-access-guards.sh: a fail-closed placeholder was tried and removed on
# purpose); this only refuses to misreport it.
#
# Also covers: every pill carries a tooltip, the "in server browser" pill is gone, and the
# modded card has an Access row at all -- modded worlds are ALWAYS gated by the citizens list,
# and were the one kind of world whose card never said so.
#
# Usage:  php dev_tools/test-access-badges.php

$root = dirname(__DIR__);
$src  = file_get_contents("$root/container/nginx/www/public/authenticated.php");

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail ? " -- $detail" : "") . "\n"; }
}

# Drive the REAL helpers out of the page rather than a copy of them here.
if (!preg_match('/# One pill\..*?\n}\n/s', $src, $a) ||
    !preg_match('/# How players get in.*?\n}\n/s', $src, $b)) {
    echo "could not find accessBadge()/accessBadges() in authenticated.php -- extraction is stale\n";
    exit(1);
}
eval($a[0] . $b[0]);

# Minimal stand-ins for the two DB reads the helper makes.
$GLOBALS['PUBLIC_FLAG'] = 1;
$GLOBALS['CITIZENS'] = '';
function getPublic($pdo, $w)   { return $GLOBALS['PUBLIC_FLAG']; }
function getCitizens($pdo, $w) { return $GLOBALS['CITIZENS']; }

function badge($public, $citizens) {
    $GLOBALS['PUBLIC_FLAG'] = $public;
    $GLOBALS['CITIZENS'] = $citizens;
    $html = accessBadges(NULL, 'w', '');
    preg_match('/title="([^"]*)">([^<]+)</', $html, $m);
    return ['label' => $m[2] ?? '', 'title' => html_entity_decode($m[1] ?? ''), 'html' => $html];
}

echo "\nThe four access states\n";
$r = badge(1, 'V_76561198000000001');
check('open world  -> "open"', $r['label'] === 'open', "got \"{$r['label']}\"");

$r = badge(0, 'V_76561198000000001');
check('restricted with ids -> "access list"', $r['label'] === 'access list', "got \"{$r['label']}\"");

# THE ONE THAT MATTERS.
$r = badge(0, '');
check('restricted but EMPTY -> "open", not "access list"', $r['label'] === 'open',
    "got \"{$r['label']}\" -- this world is joinable by anyone and the card would be claiming otherwise");
check('and the tooltip explains why it is open despite the setting',
    stripos($r['title'], 'empty') !== false || stripos($r['title'], 'nobody') !== false,
    "got \"{$r['title']}\"");
# A whitespace-only list is just as empty to Valheim.
$r = badge(0, "   \n ");
check('a whitespace-only list counts as empty too', $r['label'] === 'open', "got \"{$r['label']}\"");

$r = badge(1, '');
check('open with no list -> "open"', $r['label'] === 'open', "got \"{$r['label']}\"");

echo "\nEvery pill explains itself\n";
foreach ([[1, 'V_1'], [0, 'V_1'], [0, '']] as $case) {
    $r = badge($case[0], $case[1]);
    check("public={$case[0]} citizens=" . ($case[1] === '' ? '(none)' : $case[1]) . " has a tooltip",
        strlen($r['title']) > 20, "title=\"{$r['title']}\"");
}
# The tooltip must be attribute-safe: these strings contain an apostrophe ("world's").
$r = badge(0, 'V_1');
check('the tooltip is escaped for an attribute',
    strpos($r['html'], "world's") === false && strpos($r['html'], '&#039;') !== false,
    'a raw apostrophe would close the title attribute early');

echo "\nThe pill markup itself\n";
$r = badge(0, 'V_1');
check('carries the shared .vanilla-badge class', strpos($r['html'], 'vanilla-badge') !== false);
check('"open" is muted, "access list" is not',
    strpos(badge(1, '')['html'], 'vanilla-badge-muted') !== false &&
    strpos(badge(0, 'V_1')['html'], 'vanilla-badge-muted') === false);

echo "\nThe card markup\n";
check('the "in server browser" pill is gone',
    !preg_match("/>\s*in server browser\s*</", $src),
    'it told players how the world was discovered, which is not their question');
# Two Access rows now -- one per card kind.
check('BOTH card kinds render an Access row',
    substr_count($src, 'Access&nbsp;&nbsp;&nbsp;&nbsp;:') === 2,
    'found ' . substr_count($src, 'Access&nbsp;&nbsp;&nbsp;&nbsp;:'));
check('the modded card builds its badges from the shared helper',
    preg_match('/\$moddedBadges\s*=\s*accessBadges\(/', $src) === 1);
# Label alignment: every label on a card is padded to the same 11 monospace characters, or the
# value column stops lining up. "Access" + 4 nbsp + ":" = 11.
check('the Access label is padded to 11 characters like the rest',
    substr_count($src, 'Access&nbsp;&nbsp;&nbsp;&nbsp;:') === 2);

echo "\nCONTROL: the checks above can fail\n";
# If accessBadges() returned a constant, every state check would still "pass" for three of the
# four cases. Require the labels to actually differ.
check('the helper is not returning one constant',
    badge(0, 'V_1')['label'] !== badge(1, '')['label'],
    'restricted and open produced the same pill');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
