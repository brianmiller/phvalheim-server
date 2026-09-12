<?php
/**
 * The 2.43 multi-source mod catalogue.
 *
 * Everything here reads `mods` / `mod_versions` / `world_mods` and identifies a mod by
 * its `mods.id`. It deliberately does NOT use the source's uuid: Hexium mirrors
 * Thunderstore packages carrying their ORIGINAL uuid4, so 600 package uuids exist in
 * both catalogues. Keying the picker on a uuid -- which is what everything before 2.43
 * did -- would conflate those 600 the moment a second source was enabled.
 *
 * Replaces getAllMods / getAllModsLatestVersion / resolveModDeps / buildOwnerNameLookup,
 * whose self-join and PHP-side dependency string parsing are no longer needed: the
 * catalogue stores the latest version on the mod row and the resolved dependency graph
 * lives in `mod_deps`.
 */

/** Sources the server has switched on, in display order. */
function catalogSources($pdo)
{
    $out = [];
    $cols = [];
    foreach ($pdo->query("DESCRIBE settings") as $r) {
        $cols[$r['Field']] = true;
    }
    $row = [];
    if ($cols) {
        $want = array_values(array_filter(
            ['thunderstoreEnabled', 'hexiumEnabled'],
            fn($c) => isset($cols[$c])
        ));
        if ($want) {
            $sth = $pdo->query("SELECT " . implode(',', $want) . " FROM settings LIMIT 1");
            $row = $sth->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    }

    // Colours are fixed, not themeable: the pill colour IS how an operator tells at a
    // glance which catalogue a mod came from, so it must mean the same thing everywhere.
    $defs = [
        'thunderstore' => ['label' => 'Thunderstore', 'colour' => 'ts',  'enabled_col' => 'thunderstoreEnabled'],
        'hexium'       => ['label' => 'Hexium',       'colour' => 'hex', 'enabled_col' => 'hexiumEnabled'],
    ];
    foreach ($defs as $key => $d) {
        $col = $d['enabled_col'];
        // Default ON when the column does not exist yet (a server mid-migration) --
        // defaulting off would make the picker look empty and read as data loss.
        $enabled = array_key_exists($col, $row) ? ((int)$row[$col] === 1) : true;
        $out[$key] = [
            'key' => $key,
            'label' => $d['label'],
            'colour' => $d['colour'],
            'enabled' => $enabled,
        ];
    }
    return $out;
}

/** Per-source counts and freshness for the picker header and the Sync panel. */
function catalogStats($pdo)
{
    $stats = [];
    $sth = $pdo->query(
        "SELECT m.source,
                COUNT(DISTINCT m.id)  AS mods,
                COUNT(v.id)           AS versions,
                MAX(m.last_seen)      AS last_seen
           FROM mods m
           LEFT JOIN mod_versions v ON v.mod_id = m.id
          GROUP BY m.source"
    );
    foreach ($sth as $r) {
        $stats[$r['source']] = [
            'mods' => (int)$r['mods'],
            'versions' => (int)$r['versions'],
            'last_seen' => $r['last_seen'],
        ];
    }
    return $stats;
}

/**
 * The whole picker list: one row per mod, newest version inlined.
 *
 * Only the LATEST version is sent. The full history is 91,701 rows and shipping it to the
 * browser to populate a dropdown nobody has opened would be a multi-megabyte payload;
 * versions are fetched per mod by modVersions() when the operator actually opens the
 * selector.
 *
 * Deprecated mods are included but flagged -- an operator upgrading a world needs to see
 * that something they already depend on has been deprecated, and hiding it would make a
 * previously-working selection look like it had vanished.
 */
function catalogMods($pdo, $sources = null)
{
    $where = '';
    $params = [];
    if ($sources) {
        $where = "WHERE m.source IN (" . implode(',', array_fill(0, count($sources), '?')) . ")";
        $params = array_values($sources);
    }

    $sth = $pdo->prepare(
        "SELECT m.id, m.source, m.owner, m.name, m.full_name,
                COALESCE(m.package_url,'') AS url,
                COALESCE(m.latest_version,'') AS version,
                m.version_count, m.is_deprecated, m.is_nsfw, m.downloads,
                m.date_updated
           FROM mods m
           $where
          ORDER BY m.name, m.source"
    );
    $sth->execute($params);

    $mods = [];
    foreach ($sth as $r) {
        $mods[] = [
            'id' => (int)$r['id'],
            'source' => $r['source'],
            'owner' => $r['owner'],
            'name' => $r['name'],
            'full_name' => $r['full_name'],
            'url' => $r['url'],
            'version' => $r['version'],
            'versions' => (int)$r['version_count'],
            'deprecated' => (int)$r['is_deprecated'] === 1,
            'nsfw' => (int)$r['is_nsfw'] === 1,
            'downloads' => (int)$r['downloads'],
            'updated' => $r['date_updated'],
        ];
    }
    return $mods;
}

/**
 * Dependency mod ids for each mod's newest version, from the precomputed graph.
 *
 * Returned as mod_id => [dep mod_id, ...] so the picker can show what a selection will
 * drag in. Unresolved edges (dep_mod_id NULL) are omitted here and surfaced separately
 * by catalogMissingDeps() -- a dependency we do not have is a warning to show, not a
 * checkbox to tick.
 */
function catalogDeps($pdo)
{
    $sth = $pdo->query(
        "SELECT v.mod_id, d.dep_mod_id
           FROM mod_deps d
           JOIN mod_versions v ON v.id = d.version_id
          WHERE d.dep_mod_id IS NOT NULL
            AND v.source_rank = 0"
    );
    $deps = [];
    foreach ($sth as $r) {
        $deps[(int)$r['mod_id']][] = (int)$r['dep_mod_id'];
    }
    return $deps;
}

/** Dependencies naming packages no enabled catalogue contains, per mod. */
function catalogMissingDeps($pdo)
{
    $sth = $pdo->query(
        "SELECT v.mod_id, d.dep_string
           FROM mod_deps d
           JOIN mod_versions v ON v.id = d.version_id
          WHERE d.dep_mod_id IS NULL
            AND v.source_rank = 0"
    );
    $out = [];
    foreach ($sth as $r) {
        $out[(int)$r['mod_id']][] = $r['dep_string'];
    }
    return $out;
}

/**
 * Every published version of one mod, newest first, for the version selector.
 *
 * Ordered by the source's own ranking rather than by parsing the version string.
 * Published versions are not reliably semver -- 2.0.6-beta.1 exists -- so sorting them
 * ourselves would reorder real releases.
 */
function modVersions($pdo, $modId)
{
    $sth = $pdo->prepare(
        "SELECT id, version, file_size, date_created, is_active, source_rank
           FROM mod_versions
          WHERE mod_id = ?
          ORDER BY source_rank ASC"
    );
    $sth->execute([(int)$modId]);
    $out = [];
    foreach ($sth as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'version' => $r['version'],
            'size' => (int)$r['file_size'],
            'date' => $r['date_created'],
            'active' => (int)$r['is_active'] === 1,
            'latest' => (int)$r['source_rank'] === 0,
        ];
    }
    return $out;
}

function worldIdByName($pdo, $world)
{
    $sth = $pdo->prepare("SELECT id FROM worlds WHERE name = ? LIMIT 1");
    $sth->execute([$world]);
    $id = $sth->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * A world's selection: explicit picks with their pins, plus the resolved dependencies.
 *
 * `pin` is null for "follow latest", which is the default and the pre-2.43 behaviour.
 * `pin_missing` marks a pin whose version row is gone -- the sync keeps pinned versions
 * alive even when a source delists them, so this should be rare, but if it happens the
 * operator has to be told rather than silently moved onto a different version.
 */
function worldModSelection($pdo, $world)
{
    $wid = worldIdByName($pdo, $world);
    if ($wid === null) {
        return ['selected' => [], 'deps' => [], 'sources' => ''];
    }

    $sth = $pdo->prepare(
        "SELECT wm.mod_id, wm.is_dep, wm.pin_version_id,
                v.version AS pin_version,
                m.source, m.owner, m.name,
                COALESCE(latest.version,'') AS latest_version
           FROM world_mods wm
           JOIN mods m ON m.id = wm.mod_id
           LEFT JOIN mod_versions v ON v.id = wm.pin_version_id
           LEFT JOIN mod_versions latest ON latest.mod_id = m.id AND latest.source_rank = 0
          WHERE wm.world_id = ?"
    );
    $sth->execute([$wid]);

    $selected = [];
    $deps = [];
    foreach ($sth as $r) {
        $entry = [
            'id' => (int)$r['mod_id'],
            'source' => $r['source'],
            'owner' => $r['owner'],
            'name' => $r['name'],
            'pin' => $r['pin_version_id'] === null ? null : (int)$r['pin_version_id'],
            'pin_version' => $r['pin_version'],
            'pin_missing' => $r['pin_version_id'] !== null && $r['pin_version'] === null,
            'latest_version' => $r['latest_version'],
        ];
        if ((int)$r['is_dep'] === 1) {
            $deps[] = $entry;
        } else {
            $selected[] = $entry;
        }
    }

    $sth = $pdo->prepare("SELECT COALESCE(mod_sources,'') FROM worlds WHERE id = ?");
    $sth->execute([$wid]);

    return [
        'selected' => $selected,
        'deps' => $deps,
        'sources' => (string)$sth->fetchColumn(),
    ];
}

/**
 * Replace a world's explicit selection.
 *
 * $mods is a list of either bare mod ids or ['id' => int, 'pin' => int|null].
 *
 * Only is_dep=0 rows are touched. The dependency rows are rebuilt by
 * `worldMods.py --resolve` when the engine processes the update, because resolution has
 * to follow the version that will actually be installed -- which is the pin, if there is
 * one.
 *
 * A pin is validated to belong to the mod it is attached to. Without that check a
 * crafted or stale request could pin mod A to a version row belonging to mod B, and the
 * world would install something nobody chose.
 */
function saveWorldModSelection($pdo, $world, $mods)
{
    $wid = worldIdByName($pdo, $world);
    if ($wid === null) {
        return ['ok' => false, 'error' => "world '$world' not found"];
    }

    $wanted = [];
    foreach ((array)$mods as $m) {
        if (is_array($m)) {
            $id = (int)($m['id'] ?? 0);
            $pin = isset($m['pin']) && $m['pin'] !== null && $m['pin'] !== ''
                ? (int)$m['pin'] : null;
        } else {
            $id = (int)$m;
            $pin = null;
        }
        if ($id > 0) {
            $wanted[$id] = $pin;
        }
    }

    $rejected = [];
    if ($wanted) {
        $ids = array_keys($wanted);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sth = $pdo->prepare("SELECT id FROM mods WHERE id IN ($in)");
        $sth->execute($ids);
        $real = array_map('intval', $sth->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_diff($ids, $real) as $gone) {
            $rejected[] = "mod id $gone is not in the catalogue";
            unset($wanted[$gone]);
        }
    }

    foreach ($wanted as $id => $pin) {
        if ($pin === null) {
            continue;
        }
        $sth = $pdo->prepare("SELECT COUNT(*) FROM mod_versions WHERE id = ? AND mod_id = ?");
        $sth->execute([$pin, $id]);
        if (!(int)$sth->fetchColumn()) {
            $rejected[] = "pinned version $pin does not belong to mod $id; using latest";
            $wanted[$id] = null;
        }
    }

    // One selection per PLUGIN, not per catalogue entry.
    //
    // Both catalogues carry denikson/BepInExPack_Valheim as separate `mods` rows, and the
    // picker offers both with only a coloured pill to tell them apart. Ticking both stored
    // two world_mods rows for one plugin: the install collapsed them (they unzip into the
    // same game/BepInEx tree, so only one can win), but every count that reads world_mods
    // directly -- the world card, and "Mods Running" on this page -- reported one too many,
    // and the operator was never told their second pick had been overridden.
    //
    // Collapsed HERE rather than in the counts, so world_mods can never hold the duplicate
    // in the first place and no reader has to know about this rule.
    //
    // The winner must match worldMods.py install_rows(): the newest version wins, since it
    // satisfies the highest requirement, and Thunderstore breaks a tie as the canonical
    // upstream that Hexium mirrors. Ranked by the EFFECTIVE version -- the pin if pinned --
    // because that is what install_rows() ranks by, and a different rule here would name a
    // winner in the message that is not the one installed.
    if (count($wanted) > 1) {
        $ids = array_keys($wanted);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sth = $pdo->prepare("SELECT id, source, owner, name, COALESCE(latest_version,'0') AS latest
                                FROM mods WHERE id IN ($in)");
        $sth->execute($ids);

        $byPlugin = [];
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id  = (int)$row['id'];
            $ver = $row['latest'];
            if ($wanted[$id] !== null) {
                $p = $pdo->prepare("SELECT version FROM mod_versions WHERE id = ?");
                $p->execute([$wanted[$id]]);
                $pinned = $p->fetchColumn();
                if ($pinned !== false && $pinned !== null) {
                    $ver = $pinned;
                }
            }
            // Case-SENSITIVE key: mods.owner/name are utf8mb4_0900_as_cs and
            // IronTeam/Iron_ModPack is genuinely a different mod from Iron_Modpack.
            // Folding case here would merge the 22 such pairs that really exist, and
            // silently drop a mod the operator legitimately chose. The build verify
            // greps this whole file for case-folding calls and expects none, so do not
            // name one even in a comment.
            $byPlugin[$row['owner'] . "\0" . $row['name']][] = [
                'id' => $id, 'source' => $row['source'], 'version' => $ver,
                'label' => $row['owner'] . '/' . $row['name'],
            ];
        }

        foreach ($byPlugin as $candidates) {
            if (count($candidates) < 2) {
                continue;
            }
            usort($candidates, function ($a, $b) {
                $c = version_compare($b['version'], $a['version']);
                if ($c !== 0) {
                    return $c;
                }
                $rank = ['thunderstore' => 0, 'hexium' => 1];
                return ($rank[$a['source']] ?? 9) <=> ($rank[$b['source']] ?? 9);
            });
            $win = array_shift($candidates);
            foreach ($candidates as $loser) {
                $rejected[] = "{$loser['label']} from {$loser['source']} was not added: "
                            . "{$win['source']}'s copy of the same mod ({$win['version']}) "
                            . "is already selected, and only one can be installed";
                unset($wanted[$loser['id']]);
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $sth = $pdo->prepare("DELETE FROM world_mods WHERE world_id = ? AND is_dep = 0");
        $sth->execute([$wid]);

        // Dependency rows for mods that are no longer chosen are cleared too, so a
        // removed mod does not leave its dependencies installed. worldMods.py --resolve
        // rebuilds them from the new selection.
        $sth = $pdo->prepare("DELETE FROM world_mods WHERE world_id = ? AND is_dep = 1");
        $sth->execute([$wid]);

        $ins = $pdo->prepare(
            "INSERT INTO world_mods (world_id, mod_id, pin_version_id, is_dep)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE pin_version_id = VALUES(pin_version_id), is_dep = 0"
        );
        foreach ($wanted as $id => $pin) {
            $ins->execute([$wid, $id, $pin]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'count' => count($wanted), 'warnings' => $rejected];
}

/** Per-world source filter. Empty means "every source the server has enabled". */
function saveWorldModSources($pdo, $world, $sources)
{
    $valid = array_keys(catalogSources($pdo));
    $keep = array_values(array_intersect((array)$sources, $valid));
    $sth = $pdo->prepare("UPDATE worlds SET mod_sources = ? WHERE name = ?");
    $sth->execute([implode(',', $keep), $world]);
    return $keep;
}

/**
 * Sync history for the progress panel: the run in flight plus the last finished one.
 *
 * Both are returned per source so the UI can show "this run vs last run" without a
 * second request, which is what makes the panel useful rather than just a spinner.
 */
function modSyncStatus($pdo)
{
    $out = [];
    foreach (array_keys(catalogSources($pdo)) as $src) {
        $cur = null;
        $sth = $pdo->prepare(
            "SELECT * FROM mod_sync_runs
              WHERE source = ? AND status = 'running'
              ORDER BY id DESC LIMIT 1"
        );
        $sth->execute([$src]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cur = $row;
        }

        $sth = $pdo->prepare(
            "SELECT * FROM mod_sync_runs
              WHERE source = ? AND status <> 'running'
              ORDER BY id DESC LIMIT 2"
        );
        $sth->execute([$src]);
        $done = $sth->fetchAll(PDO::FETCH_ASSOC);

        $out[$src] = [
            'running' => $cur,
            'last' => $done[0] ?? null,
            'previous' => $done[1] ?? null,
        ];
    }
    return $out;
}

/**
 * Live log lines for one catalogue, for the panel's per-provider log pane.
 *
 * Incremental by design: the caller passes the highest id it has already shown and gets
 * only what is new. Re-sending the whole log every two seconds during a sync would grow
 * quadratically and make the pane flicker as it re-rendered.
 *
 * `$runId` null means "the run the panel should be showing" — the one in flight, else the
 * most recent finished one. Resolved server-side so the client cannot end up tailing a run
 * that has just been superseded.
 */
function modSyncLog($pdo, $source, $afterId = 0, $includeDetail = true, $runId = null, $limit = 400)
{
    if ($runId === null) {
        $sth = $pdo->prepare(
            "SELECT id FROM mod_sync_runs
              WHERE source = ?
              ORDER BY (status = 'running') DESC, id DESC
              LIMIT 1");
        $sth->execute([$source]);
        $runId = $sth->fetchColumn();
        if ($runId === false) {
            return ['runId' => null, 'lines' => [], 'lastId' => 0, 'running' => false];
        }
    }

    $sth = $pdo->prepare("SELECT status FROM mod_sync_runs WHERE id = ?");
    $sth->execute([$runId]);
    $status = $sth->fetchColumn();

    // LIMIT guards against a pathological run flooding the pane. Ordered ASC so the client
    // can simply append; the cap therefore drops the OLDEST unseen lines, which is the right
    // end to lose when tailing.
    $sql = "SELECT id, level, phase, message, is_detail, created
              FROM mod_sync_log
             WHERE run_id = ? AND id > ?";
    $params = [(int)$runId, (int)$afterId];
    if (!$includeDetail) {
        $sql .= " AND is_detail = 0";
    }
    $sql .= " ORDER BY id ASC LIMIT " . (int)$limit;

    $sth = $pdo->prepare($sql);
    $sth->execute($params);

    $lines = [];
    $lastId = (int)$afterId;
    foreach ($sth as $r) {
        $lastId = max($lastId, (int)$r['id']);
        $lines[] = [
            'id' => (int)$r['id'],
            'level' => $r['level'],
            'phase' => $r['phase'],
            'message' => $r['message'],
            'detail' => (int)$r['is_detail'] === 1,
            // Time only: the pane is a tail of one run, so the date is the same on every
            // line and would just eat width.
            'at' => substr((string)$r['created'], 11, 12),
        ];
    }

    return [
        'runId' => (int)$runId,
        'running' => $status === 'running',
        'lines' => $lines,
        'lastId' => $lastId,
    ];
}

/** How many log lines each catalogue's most recent run produced. */
function modSyncLogCounts($pdo)
{
    $out = [];
    foreach ($pdo->query(
        "SELECT source, run_id, COUNT(*) AS n FROM mod_sync_log GROUP BY source, run_id") as $r) {
        $out[$r['source']][(int)$r['run_id']] = (int)$r['n'];
    }
    return $out;
}

/** Local mod zip cache -- the "on disk" half of the sync panel. */
function modCacheStats()
{
    $dir = '/opt/stateful/games/valheim/mods/ts';
    if (!is_dir($dir)) {
        return ['files' => 0, 'bytes' => 0, 'dir' => $dir];
    }
    $files = 0;
    $bytes = 0;
    foreach (glob($dir . '/*.zip') ?: [] as $f) {
        $files++;
        $bytes += (int)@filesize($f);
    }
    return ['files' => $files, 'bytes' => $bytes, 'dir' => $dir];
}
