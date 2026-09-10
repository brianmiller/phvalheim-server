<?php
/**
 * One-time normalisation of stored access-list ids to Valheim 1.0's display format.
 *
 * Before 2.40 an operator typed a bare SteamID64 (76561198...) into the CITIZENS editor
 * and that is what went into the database. Valheim 1.0 matches on the PlatformUserID
 * display form -- V_76561198... for Steam -- so 2.40 canonicalises on the way out to
 * permittedlist.txt / adminlist.txt / bannedlist.txt.
 *
 * That means an upgraded server is already FUNCTIONALLY correct: syncAccessLists.sh
 * rewrites all three files at every world start, and upgrading restarts every world.
 * What it does not do is change what the ADMIN sees. The Access tab kept showing bare
 * ids while the file Valheim reads said V_..., so anyone comparing the two -- or reading
 * the Valheim 1.0 PSA about the prefix -- had no way to tell whether their server had
 * been fixed. This closes that gap by storing what Valheim actually receives.
 *
 * SAFETY
 *  - Reuses canonicalAccessId() from accesslists.php. A third copy of the prefix rules
 *    (there is already one in PHP and one in shell) is a liability, not a convenience.
 *  - An entry canonicalAccessId() cannot parse is KEPT VERBATIM, never dropped. A
 *    migration that silently deletes an unrecognised id would lock someone out of their
 *    own world, which is far worse than leaving it looking untidy.
 *  - Idempotent: V_x canonicalises to V_x, so re-running changes nothing.
 *  - getMyWorlds() matches with LIKE '%<bare id>%' on the public UI. V_76561198... still
 *    contains 76561198..., so that match survives this rewrite (covered by
 *    dev_tools/test-access-id-migration.sh).
 *
 * Prints a summary and exits 0. Exits 1 only if the database is unreachable, so a
 * failure here is visible in the engine log rather than silently skipped.
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/accesslists.php';

if (!isset($pdo) || !$pdo) {
    fwrite(STDERR, "migrateAccessIds: no database connection\n");
    exit(1);
}

/**
 * Normalise one stored list. Entries are whitespace separated in the column.
 * Returns [normalisedText, convertedCount, keptVerbatimCount].
 */
function normaliseStoredList($raw) {
    if ($raw === null || trim($raw) === '') {
        return [$raw, 0, 0];
    }

    $entries = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $converted = 0;
    $kept = 0;

    foreach ($entries as $entry) {
        $canonical = canonicalAccessId($entry);
        if ($canonical === null) {
            # Unparseable -- keep it exactly as the operator left it.
            $out[] = $entry;
            $kept++;
            continue;
        }
        if ($canonical !== $entry) {
            $converted++;
        }
        $out[] = $canonical;
    }

    return [implode(' ', $out), $converted, $kept];
}

$columns = ['citizens', 'admins', 'banned'];

$stmt = $pdo->query('SELECT name, citizens, admins, banned FROM worlds ORDER BY name');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalConverted = 0;
$totalKept = 0;
$worldsTouched = 0;

foreach ($rows as $row) {
    $world = $row['name'];
    $updates = [];
    $worldConverted = 0;

    foreach ($columns as $col) {
        list($normalised, $converted, $kept) = normaliseStoredList($row[$col]);
        $totalKept += $kept;
        if ($converted > 0 && $normalised !== $row[$col]) {
            $updates[$col] = $normalised;
            $worldConverted += $converted;
        }
    }

    if (!$updates) {
        continue;
    }

    $sets = [];
    foreach (array_keys($updates) as $col) {
        $sets[] = "$col = :$col";
    }
    $sql = 'UPDATE worlds SET ' . implode(', ', $sets) . ' WHERE name = :world';
    $update = $pdo->prepare($sql);
    foreach ($updates as $col => $value) {
        $update->bindValue(':' . $col, $value);
    }
    $update->bindValue(':world', $world);
    $update->execute();

    $worldsTouched++;
    $totalConverted += $worldConverted;
    echo date('D M j H:i:s T Y') . " [NOTICE : phvalheim] Access ids for '$world': converted $worldConverted ("
        . implode(', ', array_keys($updates)) . ")\n";
}

if ($totalConverted > 0) {
    echo date('D M j H:i:s T Y') . " [NOTICE : phvalheim] Converted $totalConverted access id(s) across $worldsTouched world(s) to Valheim 1.0 display format.\n";
    # 0 = the admin has not seen the explanation yet. Set ONLY when something actually
    # changed, so a fresh install never gets a notice about a migration it did not have.
    $pdo->exec('UPDATE settings SET accessIdNoticeShown = 0');
} else {
    echo date('D M j H:i:s T Y') . " [NOTICE : phvalheim] Access ids already in Valheim 1.0 display format, nothing to convert.\n";
}

if ($totalKept > 0) {
    echo date('D M j H:i:s T Y') . " [NOTICE : phvalheim] Left $totalKept unrecognised access entr(ies) untouched -- check them in the Access tab.\n";
}

exit(0);
