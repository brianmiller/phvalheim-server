<?php
# Oracle test: public world cards are ordered ONLINE FIRST, then alphabetically.
#
# THE BUG: getMyWorlds() ordered by `ORDER BY currentMemory, name ASC`. currentMemory is written
# by a cron, so it is stale for a world that has just started or stopped and meaningless for one
# that has never run -- the order looked arbitrary. Worse, the public page decides online-ness
# with a LIVE isWorldRunning() process check, so the column the query sorted on and the state the
# card displayed could disagree outright.
#
# Sorting therefore cannot happen in SQL. sortWorldsOnlineFirst() takes the online map as a plain
# array specifically so this can be tested without a database, a container, or a running Valheim.
#
# Run:  php dev_tools/test-world-card-order.php

# db_gets.php includes two config files by their in-CONTAINER absolute paths, which do not
# exist when running from the repo. Warnings are suppressed for the include only, and restored
# immediately: the function under test is pure and touches neither of them. Suppressing more
# widely would hide a real error inside the sort itself.
$prevErrorReporting = error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/../container/nginx/www/includes/db_gets.php';
error_reporting($prevErrorReporting);

if (!function_exists('sortWorldsOnlineFirst')) {
    fwrite(STDERR, "sortWorldsOnlineFirst() not found -- has it been renamed or moved?\n");
    exit(1);
}

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail ? " -- $detail" : '') . "\n"; }
}
function eq($name, $got, $want) {
    check($name, $got === $want, 'got [' . implode(', ', $got) . '] want [' . implode(', ', $want) . ']');
}

echo "\nOnline worlds come first, whatever their names\n";
# The load-bearing case: an offline world that sorts alphabetically FIRST must still be pushed
# below every online one. A comparator that only sorted by name would pass a test whose online
# worlds happened to be alphabetically early.
eq('offline "aaa" sinks below online "zzz"',
    sortWorldsOnlineFirst(['aaa', 'zzz'], ['aaa' => false, 'zzz' => true]),
    ['zzz', 'aaa']);

echo "\nAlphabetical within each group\n";
eq('online sorted, then offline sorted',
    sortWorldsOnlineFirst(
        ['delta', 'alpha', 'charlie', 'bravo'],
        ['alpha' => false, 'bravo' => true, 'charlie' => false, 'delta' => true]),
    ['bravo', 'delta', 'alpha', 'charlie']);

echo "\nSorting is case-insensitive\n";
# strcmp would put every capitalised name above every lowercase one, so "banana" would sort
# above "Apple". These are read by people.
eq('Apple before banana before Cherry',
    sortWorldsOnlineFirst(['banana', 'Cherry', 'Apple'],
        ['banana' => false, 'Cherry' => false, 'Apple' => false]),
    ['Apple', 'banana', 'Cherry']);

echo "\nEdge cases\n";
eq('empty list', sortWorldsOnlineFirst([], []), []);
eq('single world', sortWorldsOnlineFirst(['solo'], ['solo' => true]), ['solo']);
# A name missing from the map must not throw -- it counts as offline and sinks.
eq('a world missing from the map is treated as offline',
    sortWorldsOnlineFirst(['known', 'missing'], ['known' => true]),
    ['known', 'missing']);
eq('all offline is still alphabetical',
    sortWorldsOnlineFirst(['zeta', 'beta'], ['zeta' => false, 'beta' => false]),
    ['beta', 'zeta']);
eq('all online is still alphabetical',
    sortWorldsOnlineFirst(['zeta', 'beta'], ['zeta' => true, 'beta' => true]),
    ['beta', 'zeta']);

echo "\nCONTROL: the function actually reorders\n";
# If sortWorldsOnlineFirst() were a no-op returning its input, several cases above would still
# pass by luck. This input is wrong in BOTH dimensions, so a no-op cannot survive it.
$input  = ['zulu', 'alpha'];
$sorted = sortWorldsOnlineFirst($input, ['zulu' => false, 'alpha' => true]);
check('input order is not simply preserved', $sorted !== $input,
    'got [' . implode(', ', $sorted) . ']');

echo "\nGUARD: the SQL no longer sorts on the stale memory column\n";
# If ORDER BY currentMemory came back, the PHP sort would still fix the display -- so nothing
# would visibly break, and the misleading query would live on.
$src = file_get_contents(__DIR__ . '/../container/nginx/www/includes/db_gets.php');
check('getMyWorlds does not ORDER BY currentMemory',
    strpos($src, 'ORDER BY currentMemory') === false);
check('authenticated.php calls the shared sorter',
    strpos(file_get_contents(__DIR__ . '/../container/nginx/www/public/authenticated.php'),
           'sortWorldsOnlineFirst($getMyWorlds, $worldIsOnline)') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
