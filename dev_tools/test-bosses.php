<?php
//
// Unit tests for the boss trophy registry (container/nginx/www/includes/bosses.php).
//
// Run from anywhere:  php dev_tools/test-bosses.php
//
// bosses.php is loaded via eval rather than require so these tests need no database
// and no running container -- getBossTrophyStatus() is stubbed out below.
//
function getBossTrophyStatus($pdo,$w,$c){ return 0; }
$src = file_get_contents(__DIR__ . '/../container/nginx/www/includes/bosses.php');
eval('?>' . $src);

$fail = 0;
function ck($label,$got,$want){ global $fail; if($got===$want){echo "  PASS: $label\n";} else {echo "  FAIL: $label (got ".var_export($got,true).", want ".var_export($want,true).")\n"; $fail++;} }

echo "bosses.php registry\n\n";
ck('known prefab resolves', bossColumnForPrefab('TrophyFader'), 'trophyfader');
ck('all 7 bosses registered', count($PHVALHEIM_BOSSES), 7);
ck('unknown prefab rejected', bossColumnForPrefab('TrophyDeepNorth'), NULL);
ck('junk rejected', bossColumnForPrefab('Trophy; DROP TABLE worlds'), NULL);
ck('empty rejected', bossColumnForPrefab(''), NULL);

// The whole point of the unknown-boss path: a new boss must be *recognised as a boss*
// so it gets logged, while junk must not be.
ck('new boss looks like a boss', looksLikeBossPrefab('TrophyDeepNorth'), true);
ck('sql injection does not', looksLikeBossPrefab('Trophy; DROP TABLE worlds'), false);
ck('non-trophy does not', looksLikeBossPrefab('Wood'), false);
ck('null-ish does not', looksLikeBossPrefab(''), false);

// Every column must be a safe SQL identifier -- setHungHeads interpolates it.
$bad = array_filter($PHVALHEIM_BOSSES, fn($b) => !preg_match('/^[a-z_]+$/', $b['column']));
ck('all columns are safe identifiers', count($bad), 0);

// Column must be strtolower(prefab) -- setHungHeads relies on the pairing.
$mismatch = array_filter($PHVALHEIM_BOSSES, fn($b) => $b['column'] !== strtolower($b['prefab']));
ck('column matches lowercased prefab', count($mismatch), 0);

// Keys must be unique or the trophy row would collide on CSS class.
ck('keys unique', count(array_unique(array_column($PHVALHEIM_BOSSES,'key'))), 7);

exit($fail === 0 ? 0 : 1);
