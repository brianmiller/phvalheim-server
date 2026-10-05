<?php
/**
 * The mod config editor's data layer (2.55).
 *
 * An operator's mod config edits live in `mod_config_overrides` as SPARSE PER-KEY rows, not as
 * whole files. purgeWorldModsConfigsPatchers() deletes BepInEx/config/* on every world update,
 * so a config file is DERIVED and never state; the pre-2.55 answer was to copy whole files into
 * custom_configs/, which pins a config at the shape the mod had when it was copied. Per-key
 * rows re-apply onto whatever the new version generates, so a setting the new version adds
 * arrives at its new default instead of being silently overwritten by an old file.
 *
 * WHY THIS SHELLS OUT TO modConfigs.py RATHER THAN PARSING .cfg IN PHP
 * There is exactly one cfg parser in this product and it is in engine/tools/modConfigs.py.
 * Two parsers for one hand-written format drift apart, and the symptom of a drift here is the
 * editor disagreeing with the engine about what the operator set -- which nobody can debug from
 * the UI. The same applies to file-to-mod attribution: this file hands the world's mods to the
 * tool and the tool attributes, rather than reimplementing its normalisation rules.
 *
 * WHICH MODES ARE SAFE TO CALL FROM HERE
 * php-fpm runs as `phvalheim` and reaches the database as `phvalheim_user` through PDO.
 * modConfigs.py's --materialise / --import-legacy / --discover modes talk to mysql as -uroot,
 * so this file calls ONLY the two no-database modes (--parse-dir, --parse-file) and does every
 * database read and write itself through $pdo.
 */

// Shared with modcatalog.php, which already resolves a world name to its id. Not redeclared
// here -- adminAPI.php requires both, and a second copy would be a fatal redeclare.
require_once '/opt/stateless/nginx/www/includes/modcatalog.php';

define('MODCONFIG_TOOL', '/opt/stateless/engine/tools/modConfigs.py');
define('MODCONFIG_WORLDS_ROOT', '/opt/stateful/games/valheim/worlds');
// Where --import-legacy parked each file it consumed. Kept in step with IMPORTED_DIR in
// modConfigs.py -- two spellings of this name would silently stop the review finding anything.
define('MODCONFIG_IMPORTED_DIR', '.imported-pre-2.55');

// Never editable. The loader's own BepInEx.cfg is engine state, not a mod config -- 2.49
// swept it and silenced the world log and the client's console window in one stroke. The seed
// file is computed by the engine from worlds.seed, and an operator-editable copy would let
// them fight the engine and lose the world's MAP on the next update. Kept in step with
// EXCLUDED_FILES in modConfigs.py; both lists are short and both are load-bearing.
function modConfigExcludedFiles() {
    return ['BepInEx.cfg', 'ZeroBandwidth.CustomSeed.cfg', 'quick_connect_servers.cfg'];
}

/**
 * This world's running state, read through $pdo.
 *
 * NOT getWorldMode() from db_gets.php. This file does every database read itself, on purpose
 * (see the header), and it does not include db_gets.php -- so the getWorldMode() call that used
 * to be in modConfigSave() only worked because adminAPI.php happens to include both files. Any
 * other caller requiring just modconfigs.php got "Call to undefined function", which is how the
 * coverage summary first died. A helper that works because of a caller's include list is a fatal
 * waiting for the next caller.
 *
 * `mode`, NOT `status`: status is a human-facing label ("Down"), mode is the running state.
 */
function modConfigWorldMode($pdo, $world) {
    $st = $pdo->prepare("SELECT mode FROM worlds WHERE name = ?");
    $st->execute([$world]);
    return (string)($st->fetchColumn() ?: 'unknown');
}

/**
 * The world's selected mods, in the shape modConfigs.py --catalogue-file expects.
 */
function modConfigCatalogueJson($pdo, $worldId) {
    $st = $pdo->prepare(
        "SELECT m.id, m.name, m.full_name FROM world_mods wm
         JOIN mods m ON m.id = wm.mod_id WHERE wm.world_id = ?");
    $st->execute([$worldId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Parse the world's live config tree. Returns the tool's JSON decoded, or null on failure.
 */
function modConfigParseTree($pdo, $world, $worldId) {
    $catalogue = modConfigCatalogueJson($pdo, $worldId);
    $tmp = tempnam(sys_get_temp_dir(), 'phvcfg');
    file_put_contents($tmp, json_encode($catalogue));

    $cmd = MODCONFIG_TOOL . ' --world ' . escapeshellarg($world)
         . ' --parse-dir --catalogue-file ' . escapeshellarg($tmp) . ' 2>&1';
    $raw = shell_exec($cmd);
    unlink($tmp);

    $data = json_decode((string)$raw, true);
    // A parse failure must not render as "this world has no configs" -- that is the same
    // 0/false-doubling-as-a-real-answer that has shipped here three times. The caller
    // distinguishes null (could not read) from an empty file list (nothing generated yet).
    return is_array($data) ? $data : null;
}

/**
 * Every override row for a world, indexed by "file\x1Fsection\x1Fkey".
 */
function modConfigOverrides($pdo, $worldId) {
    $st = $pdo->prepare(
        "SELECT cfg_file, section, ckey, cvalue, mod_id, server_only, locked, origin
         FROM mod_config_overrides WHERE world_id = ?");
    $st->execute([$worldId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['cfg_file'] . "\x1F" . $r['section'] . "\x1F" . $r['ckey']] = $r;
    }
    return $out;
}

/**
 * The editor's whole payload: parsed files merged with override rows, plus the override rows
 * that no longer match anything.
 *
 * THREE STATES, and they are not interchangeable:
 *   tree === null            the config tree could not be read at all
 *   generated === false      the world has no config files yet. Most BepInEx mods do not ship
 *                            a cfg -- it is written on the first Config.Bind() -- so a mod
 *                            that was just added has nothing to edit until the world has
 *                            booted once with it. This is NOT "all defaults".
 *   generated === true       there are files, each entry carrying its own default
 */
function modConfigEditorPayload($pdo, $world) {
    $worldId = worldIdByName($pdo, $world);
    if (!$worldId) {
        return ['error' => "No world named '$world'"];
    }

    $tree = modConfigParseTree($pdo, $world, $worldId);
    if ($tree === null) {
        return ['error' => 'Could not read this world&rsquo;s config directory.',
                'world' => $world, 'world_id' => (int)$worldId, 'readable' => false];
    }

    $overrides = modConfigOverrides($pdo, $worldId);
    $modNames = [];
    foreach (modConfigCatalogueJson($pdo, $worldId) as $m) {
        $modNames[(int)$m['id']] = $m['full_name'] ?: $m['name'];
    }

    $seenKeys = [];
    $files = [];
    // (file => true) when BepInEx has documented at least one setting in it, and the
    // overridden entries in it that carry no documentation at all. See the $stale block below.
    $fileDocumented = [];
    $undocumentedOverrides = [];
    foreach ($tree['files'] as $f) {
        $entries = [];
        $modifiedCount = 0;
        foreach ($f['entries'] as $e) {
            if ($e['type'] || $e['has_default']) {
                $fileDocumented[$f['file']] = true;
            }
            $k = $f['file'] . "\x1F" . $e['section'] . "\x1F" . $e['key'];
            $ov = $overrides[$k] ?? null;
            if ($ov) {
                $seenKeys[$k] = true;
                if (!$e['type'] && !$e['has_default']) {
                    $undocumentedOverrides[$f['file']][] = $ov;
                }
            }

            $effective = $ov ? $ov['cvalue'] : $e['value'];
            // "Modified" is measured against the file's OWN `# Default value:` comment, which
            // travels inside the file and so needs no catalogue lookup and no network. An
            // entry with no documented default has no baseline, and saying "modified" or
            // "unmodified" about it would both be guesses.
            $modified = $e['has_default'] && ($effective !== $e['default']);
            if ($modified) { $modifiedCount++; }

            $entries[] = [
                'section'     => $e['section'],
                'key'         => $e['key'],
                'type'        => $e['type'],
                'description' => $e['description'],
                'default'     => $e['default'],
                'has_default' => (bool)$e['has_default'],
                'acceptable'  => $e['acceptable'],
                'range'       => $e['range'],
                'file_value'  => $e['value'],
                'value'       => $effective,
                'overridden'  => $ov !== null,
                'modified'    => $modified,
                'server_only' => $ov ? (int)$ov['server_only'] : 0,
                'locked'      => $ov ? (int)$ov['locked'] : 0,
                'origin'      => $ov ? $ov['origin'] : null,
            ];
        }
        $modId = $f['mod_id'] !== null ? (int)$f['mod_id'] : null;
        $files[] = [
            'file'           => $f['file'],
            'plugin'         => $f['plugin'],
            'guid'           => $f['guid'],
            'mod_id'         => $modId,
            'mod_name'       => $modId !== null ? ($modNames[$modId] ?? null) : null,
            'entry_count'    => count($entries),
            'modified_count' => $modifiedCount,
            'entries'        => $entries,
        ];
    }

    // Rows that match nothing in the current tree. Surfaced, never dropped: when a mod update
    // renames or removes a setting, the row will never apply again, and the operator is the
    // only one who can decide whether to delete it or set the new key. Hiding it would let the
    // editor imply an override is in force when it is not.
    // Detected by METADATA, not by the key being absent.
    //
    // materialise() injects a missing key rather than refusing to write it -- that refusal was
    // the 2.55 bug, because on a world update the config directory has just been purged and
    // the mod has not written its file yet, so "absent" is the normal case. A consequence is
    // that after any start or update every override's key IS present, so a presence test would
    // report nothing at all, forever.
    //
    // What still separates them is what BepInEx writes. A key the plugin actually bound gets
    // `# Setting type:` / `# Default value:` above it; a key we injected that nothing binds --
    // because the mod renamed or dropped the setting -- keeps its bare `key = value` line and
    // never gains metadata.
    //
    // Gated on the file having documented something, because a file this tool has just created
    // has no metadata on anything: the mod has not booted with it yet. Without that gate every
    // freshly-applied setting would be reported as broken.
    $stale = [];
    foreach ($overrides as $k => $ov) {
        if (!isset($seenKeys[$k])) {
            $stale[] = $ov + ['reason' => 'the mod has not written this config yet'];
        }
    }
    foreach ($undocumentedOverrides as $file => $ovs) {
        if (empty($fileDocumented[$file])) {
            continue;
        }
        foreach ($ovs as $ov) {
            $stale[] = $ov + ['reason' => 'this version of the mod does not use this setting'];
        }
    }

    return [
        'world'     => $world,
        'world_id'  => (int)$worldId,
        'readable'  => true,
        'generated' => (bool)$tree['generated'],
        'files'     => $files,
        'stale'     => $stale,
    ];
}

/**
 * Per-mod override counts, for the Config column in the mod picker.
 *
 * Counts only rows attributed to a mod. The badge on a mod's row must not include the
 * unattributed files, or every mod row would show the same inflated number.
 */
function modConfigSummary($pdo, $world) {
    $worldId = worldIdByName($pdo, $world);
    if (!$worldId) { return []; }
    $st = $pdo->prepare(
        "SELECT mod_id, COUNT(*) AS n FROM mod_config_overrides
         WHERE world_id = ? AND mod_id IS NOT NULL GROUP BY mod_id");
    $st->execute([$worldId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['mod_id']] = (int)$r['n'];
    }
    return $out;
}

/**
 * Save a batch of edits.
 *
 * Each item: {file, section, key, value, server_only, mod_id, reset}
 *
 * `reset` deletes the row rather than storing the default as an override. Storing it would be
 * wrong in a way that only shows up later: the stored value would pin that setting at today's
 * default, so when the mod's own default changes in a later version the world would silently
 * keep the old one -- the exact failure the per-key store exists to avoid.
 *
 * A `locked` row is refused. Those are engine-owned; the only one so far is the world seed,
 * and letting an operator set it would change the map out from under a live world.
 */
function modConfigSaveOverrides($pdo, $world, $items) {
    $worldId = worldIdByName($pdo, $world);
    if (!$worldId) { return ['ok' => false, 'error' => "No world named '$world'"]; }
    if (!is_array($items)) { return ['ok' => false, 'error' => 'No changes supplied']; }

    $excluded = modConfigExcludedFiles();
    $saved = $removed = 0;
    $refused = [];
    $changes = [];

    // Which of this world's mods are installed on PLAYERS' clients.
    //
    // Read once, not per item: a batch save can carry dozens of settings and this is the same
    // answer for all of them.
    $modMap = [];
    $mm = $pdo->prepare(
        "SELECT wm.mod_id, IFNULL(wm.deploy_client,1) AS dc, m.owner, m.name
           FROM world_mods wm JOIN mods m ON m.id = wm.mod_id
          WHERE wm.world_id = ?");
    $mm->execute([$worldId]);
    foreach ($mm->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $modMap[(int)$r['mod_id']] = [
            'client' => ((int)$r['dc'] === 1),
            'label'  => $r['owner'] . '-' . $r['name'],
        ];
    }

    /*
     * Who has to receive a change for it to take effect. THREE answers, and the third one is
     * not a cop-out -- on a real world 15 of 40 override rows have no mod_id at all.
     *
     *  'server'  - server_only, so it is never written into the client payload; or the mod is
     *              not deployed to players' clients, so nothing there reads it. Note the
     *              difference: a deploy_client=0 mod's config IS still written into the
     *              payload (materialise filters on server_only alone), it simply has no
     *              plugin on the player's side to read it. So no push is needed either way.
     *  'players' - the mod runs on players' clients, so they need a rebuilt payload.
     *  'unknown' - PhValheim could not attribute the config FILE to one of this world's mods.
     *              Attribution matches the filename against installed mods and deliberately
     *              refuses near-misses, so this is common and expected. The value ships
     *              regardless; we just cannot say whether anything client-side reads it.
     *
     * Unknown is grouped WITH players for the purpose of "do you need to push", because the
     * two errors are not symmetric: pushing unnecessarily costs one small download, while not
     * pushing loses the operator's change with no symptom.
     */
    $reachOf = function ($serverOnly, $modId) use ($modMap) {
        if ($serverOnly) { return 'server'; }
        if ($modId === null || !isset($modMap[$modId])) { return 'unknown'; }
        return $modMap[$modId]['client'] ? 'players' : 'server';
    };

    $lockSt = $pdo->prepare(
        "SELECT locked FROM mod_config_overrides
         WHERE world_id = ? AND cfg_file = ? AND section = ? AND ckey = ?");
    // The row as it stands BEFORE this save, so the modal can show "from -> to" rather than
    // just the new value. Nothing read the previous value before 2.55's save summary, which is
    // why the old confirmation could only ever report a count.
    $prevSt = $pdo->prepare(
        "SELECT cvalue, server_only, mod_id FROM mod_config_overrides
         WHERE world_id = ? AND cfg_file = ? AND section = ? AND ckey = ?");
    $del = $pdo->prepare(
        "DELETE FROM mod_config_overrides
         WHERE world_id = ? AND cfg_file = ? AND section = ? AND ckey = ? AND locked = 0");
    // ON DUPLICATE KEY so a re-save of the same setting updates in place. origin is forced to
    // 'operator' on write: a value the operator has now set by hand is no longer a legacy
    // import awaiting review, and leaving it as 'legacy-review' would keep nagging them
    // about a decision they have already made.
    $up = $pdo->prepare(
        "INSERT INTO mod_config_overrides
           (world_id, cfg_file, section, ckey, cvalue, mod_id, server_only, locked, origin)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'operator')
         ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue),
                                 mod_id = VALUES(mod_id),
                                 server_only = VALUES(server_only),
                                 origin = 'operator',
                                 date_set = CURRENT_TIMESTAMP");

    foreach ($items as $it) {
        $file    = (string)($it['file'] ?? '');
        $section = (string)($it['section'] ?? '');
        $key     = (string)($it['key'] ?? '');
        if ($file === '' || $key === '') { continue; }
        if (in_array($file, $excluded, true)) {
            $refused[] = "$file is not an editable mod config";
            continue;
        }
        // A path separator here would mean the caller is addressing something outside the
        // world's own config directory. cfg_file is a bare filename by construction.
        if (strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
            $refused[] = "$file is not a valid config file name";
            continue;
        }

        $lockSt->execute([$worldId, $file, $section, $key]);
        if ((int)$lockSt->fetchColumn() === 1) {
            $refused[] = "[$section] $key in $file is managed by PhValheim and cannot be changed here";
            continue;
        }

        // Read the previous state BEFORE mutating, for the from->to summary and so a reset
        // can be classified from the row it is about to delete.
        $prevSt->execute([$worldId, $file, $section, $key]);
        $prev = $prevSt->fetch(PDO::FETCH_ASSOC);

        $newModId = isset($it['mod_id']) && $it['mod_id'] !== '' && $it['mod_id'] !== null
            ? (int)$it['mod_id'] : null;

        if (!empty($it['reset'])) {
            $del->execute([$worldId, $file, $section, $key]);
            $n = $del->rowCount();
            $removed += $n;
            // Only report a reset that actually removed something. A reset click on a setting
            // with no stored override is a no-op, and listing it as a change would have the
            // modal claim work that did not happen.
            if ($n > 0) {
                $prevModId = ($prev && $prev['mod_id'] !== null) ? (int)$prev['mod_id'] : null;
                $changes[] = [
                    'action'  => 'reset',
                    'file'    => $file,
                    'section' => $section,
                    'key'     => $key,
                    'from'    => $prev ? (string)$prev['cvalue'] : null,
                    'to'      => null,   // null = back to the mod author's own default
                    'reach'   => $reachOf(!empty($prev['server_only']), $prevModId),
                    'mod'     => $prevModId !== null && isset($modMap[$prevModId])
                                   ? $modMap[$prevModId]['label'] : null,
                ];
            }
            continue;
        }

        $newValue   = (string)($it['value'] ?? '');
        $newSrvOnly = !empty($it['server_only']) ? 1 : 0;

        $up->execute([
            $worldId, $file, $section, $key, $newValue, $newModId, $newSrvOnly,
        ]);
        $saved++;

        $changes[] = [
            'action'  => $prev ? 'changed' : 'set',
            'file'    => $file,
            'section' => $section,
            'key'     => $key,
            // null from = there was no override before, so it was sitting at the mod's default.
            // That is NOT the same as an empty string, which is a legitimate stored value.
            'from'    => $prev ? (string)$prev['cvalue'] : null,
            'to'      => $newValue,
            'reach'   => $reachOf($newSrvOnly, $newModId),
            'mod'     => $newModId !== null && isset($modMap[$newModId])
                           ? $modMap[$newModId]['label'] : null,
        ];
    }

    $tally = ['players' => 0, 'server' => 0, 'unknown' => 0];
    foreach ($changes as $c) { $tally[$c['reach']]++; }

    return ['ok' => true, 'saved' => $saved, 'removed' => $removed, 'refused' => $refused,
            'changes' => $changes,
            'tally'   => $tally,
            // Whether a push is needed at all, decided here rather than in the browser so the
            // button and the explanation cannot disagree about it.
            'needsPush' => ($tally['players'] + $tally['unknown']) > 0,
            // The world's mode right now, so the summary can say what a restart would even
            // mean. A stopped world picks server-side changes up on its next start, with
            // nothing for the operator to do.
            'worldMode' => modConfigWorldMode($pdo, $world),
            // BepInEx reads its config at plugin load, so a running world cannot pick this up.
            // The side matters and the first version of this note ignored it: a client-side
            // mod reads the config from the client payload zip, which only packageClient()
            // rebuilds. "Restart to apply" sent an operator looking for a change that a
            // restart could not possibly have delivered.
            'note' => 'Restart the world to apply. If the mod runs on players\' clients, '
                    . 'use Apply to players so the client payload is rebuilt.'];
}

/**
 * Drop every override for one file (the editor's "reset this mod to defaults").
 * Locked rows survive deliberately -- see modConfigSaveOverrides().
 */
function modConfigResetFile($pdo, $world, $file) {
    $worldId = worldIdByName($pdo, $world);
    if (!$worldId) { return ['ok' => false, 'error' => "No world named '$world'"]; }
    $st = $pdo->prepare(
        "DELETE FROM mod_config_overrides
         WHERE world_id = ? AND cfg_file = ? AND locked = 0");
    $st->execute([$worldId, $file]);
    return ['ok' => true, 'removed' => $st->rowCount(), 'note' => 'Restart the world to apply.'];
}

/**
 * Diff a pasted .cfg against the world's installed copy.
 *
 * This replaces what the filesystem used to be for: operators share config files with each
 * other, and before 2.55 the way to use one was to drop it into custom_configs/ whole. Pasting
 * it here is strictly better, because the whole file is never adopted -- only the keys that
 * actually differ from the installed default are offered, and the operator sees the list before
 * anything is stored. A whole-file copy silently carried along every unrelated default too,
 * which is how an imported world ends up frozen at someone else's config.
 */
function modConfigDiffPasted($pdo, $world, $file, $text) {
    $worldId = worldIdByName($pdo, $world);
    if (!$worldId) { return ['ok' => false, 'error' => "No world named '$world'"]; }

    $tmp = tempnam(sys_get_temp_dir(), 'phvpaste');
    file_put_contents($tmp, (string)$text);
    $raw = shell_exec(MODCONFIG_TOOL . ' --parse-file ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    $pasted = json_decode((string)$raw, true);
    if (!is_array($pasted) || !isset($pasted['entries'])) {
        return ['ok' => false, 'error' => 'That does not parse as a BepInEx config file.'];
    }

    $tree = modConfigParseTree($pdo, $world, $worldId);
    if ($tree === null) {
        return ['ok' => false, 'error' => 'Could not read this world&rsquo;s config directory.'];
    }
    $installed = [];
    foreach ($tree['files'] as $f) {
        if ($f['file'] !== $file) { continue; }
        foreach ($f['entries'] as $e) {
            $installed[$e['section'] . "\x1F" . $e['key']] = $e;
        }
    }
    if (!$installed) {
        return ['ok' => false,
                'error' => "This world has no installed '$file' to compare against. "
                         . 'Start the world once so the mod writes its config, then paste again.'];
    }

    $changes = $unknown = [];
    foreach ($pasted['entries'] as $e) {
        $k = $e['section'] . "\x1F" . $e['key'];
        if (!isset($installed[$k])) {
            // In the pasted file but not in the installed version: a different mod version, or
            // a different mod. Listed rather than dropped, so the operator can see why a
            // setting they expected did not come across.
            $unknown[] = ['section' => $e['section'], 'key' => $e['key'], 'value' => $e['value']];
            continue;
        }
        $cur = $installed[$k];
        if ($e['value'] !== $cur['value']) {
            $changes[] = [
                'section'   => $e['section'],
                'key'       => $e['key'],
                'value'     => $e['value'],
                'installed' => $cur['value'],
                'default'   => $cur['default'],
                'type'      => $cur['type'],
            ];
        }
    }

    return ['ok' => true, 'file' => $file, 'changes' => $changes, 'unknown' => $unknown];
}

/**
 * One row per MOD for the Configs picker, instead of every setting of every mod at once.
 *
 * The editor already accepts ?mod=<id> and has since 2.55 -- what was missing was anything
 * that let an operator choose. Opening it unfiltered renders every setting a world has
 * (VikingOutlaws: 80 overrides across 7 files, hundreds of documented entries), which is
 * unusable as a starting point.
 *
 * Grouped from modConfigEditorPayload() rather than from world_mods, deliberately. A mod only
 * belongs in this list once it has actually WRITTEN a config file -- most write theirs on
 * first load -- so listing the world's mods would offer entries that open an empty editor.
 * The payload is the same source the editor itself renders from, so the picker cannot offer
 * a mod the editor would then show as empty.
 *
 * mod_id NULL is kept, not dropped: a file PhValheim could not match to an installed mod is
 * still editable and still ships to players. It is collected under a single "unmatched"
 * pseudo-entry so it cannot silently disappear from the only index into these files.
 */
function modConfigModSummary($pdo, $world) {
    $payload = modConfigEditorPayload($pdo, $world);
    if (isset($payload['error'])) {
        return ['error' => $payload['error']];
    }

    $mods = [];
    foreach (($payload['files'] ?? []) as $f) {
        $id  = $f['mod_id'];
        $key = $id === null ? 'unmatched' : (string)$id;

        if (!isset($mods[$key])) {
            $mods[$key] = [
                'mod_id'         => $id,
                'name'           => $id === null ? 'Unmatched config files' : ($f['mod_name'] ?: $f['plugin'] ?: 'Unknown mod'),
                'files'          => 0,
                'entry_count'    => 0,
                'modified_count' => 0,
                'file_names'     => [],
            ];
        }
        $mods[$key]['files']          += 1;
        $mods[$key]['entry_count']    += (int)$f['entry_count'];
        $mods[$key]['modified_count'] += (int)$f['modified_count'];
        $mods[$key]['file_names'][]    = $f['file'];
    }

    // Mods the operator has actually changed first -- that is what they come back to edit --
    // then alphabetically. The unmatched bucket sinks to the bottom either way.
    $out = array_values($mods);
    usort($out, function ($a, $b) {
        if ($a['mod_id'] === null) { return 1; }
        if ($b['mod_id'] === null) { return -1; }
        if ($a['modified_count'] !== $b['modified_count']) {
            return $b['modified_count'] - $a['modified_count'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    // ---- COVERAGE: how much of the world this list actually covers ----
    //
    // Brian opened this picker on a world with 29 installed mods and saw 6. The list was
    // correct -- a world update had purged BepInEx/config and the world had not been started
    // since, so only the files materialise rebuilt from saved overrides existed -- but the
    // modal presented 6 as though it were all there was. A partial answer rendered as a
    // complete one reads as a broken feature, and this is the second time that exact shape has
    // bitten this project: see "Unknown is not up to date".
    //
    // So the picker now states its own coverage and names what is missing. The counts come
    // from world_mods (what the world installs) against the payload (what has written a file).
    $worldId   = worldIdByName($pdo, $world);
    $catalogue = $worldId ? modConfigCatalogueJson($pdo, $worldId) : [];

    $haveConfig = [];
    foreach ($out as $m) {
        if ($m['mod_id'] !== null) { $haveConfig[(int)$m['mod_id']] = true; }
    }

    $waiting = [];
    foreach ($catalogue as $m) {
        if (!isset($haveConfig[(int)$m['id']])) {
            $waiting[] = ['mod_id' => (int)$m['id'], 'name' => $m['full_name'] ?: $m['name']];
        }
    }
    usort($waiting, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    // The live mode decides which ADVICE is true, so it is read here rather than guessed in
    // the browser. A stopped world needs "start it once"; a running one has already had the
    // chance, so the honest reading there is "these may simply have no settings".
    //
    $mode = modConfigWorldMode($pdo, $world);

    return [
        'world'     => $world,
        'generated' => (bool)($payload['generated'] ?? false),
        'mods'      => $out,
        'total'     => count($out),
        // Coverage. 'installed' counts the world's mods; 'configured' counts those with at
        // least one config file. They are reported separately rather than as a percentage
        // because the gap itself is the thing the operator needs to act on.
        'installed'  => count($catalogue),
        'configured' => count($haveConfig),
        'waiting'    => $waiting,
        'mode'       => $mode,
        // The unmatched bucket is a LIST ROW but not an installed mod, so without this the
        // banner says "5 of 29" above a list of 6 and invites the operator to go looking for
        // the discrepancy. Counted separately so the banner can name it for what it is.
        'unmatched_files' => (int)($mods['unmatched']['files'] ?? 0),
    ];
}

/**
 * What the 2.55 config import did, per world, and whether its originals are safe to delete.
 *
 * WHY THIS EXISTS AS A REPORT RATHER THAN AS A CLEANUP
 * --import-legacy parks each consumed file in a `.imported-pre-2.55` folder inside
 * custom_configs and custom_configs_secure, and it only parks a file AFTER inserting at least
 * one row (the move lives inside `if took:`). So a parked file means "its settings reached the
 * database" -- at the time of the import.
 *
 * (That sentence originally wrote the pair as `custom_configs*` with a slash after the star.
 * Inside a docblock that two-character sequence ENDS the comment, and everything after it was
 * parsed as code. Same family as the `--` that made an SVG comment undrawable.)
 *
 * On a real server that stopped being true. Three parked files on VikingOutlaws hold 12
 * non-default settings between them -- ValheimRAFT alone has 6, including MaxSailSpeed 45
 * against a default of 30 -- and the database now holds NONE of them. Rows that the import
 * demonstrably wrote are gone, and nothing here can say what removed them: a reset in the
 * editor is harmless and expected, a mod-removal sweep taking rows for a still-installed mod
 * is not.
 *
 * Deleting those originals would therefore destroy the only remaining copy of settings the
 * product itself dropped, and rm is not reversible. So this function REPORTS and the operator
 * decides. Each file is classified by comparing what it holds against what the database holds:
 *
 *   accounted  -- the database has rows for this file. Safe to delete.
 *   empty      -- the file has no non-default values left to lose. Safe to delete.
 *   AT RISK    -- the file holds non-default settings the database does not have. The modal
 *                 must say so, per file, and must not pre-select it for deletion.
 *
 * The only-parse-what-we-must shape is deliberate: parsing is a subprocess per file, and a file
 * the database already covers needs no parse to be judged safe.
 */
function modConfigMigrationReport($pdo) {
    $worlds = [];
    $root = MODCONFIG_WORLDS_ROOT;
    if (!is_dir($root)) { return ['worlds' => [], 'totals' => ['files' => 0, 'at_risk' => 0]]; }

    $totalFiles = 0;
    $totalRisk  = 0;

    foreach (scandir($root) as $world) {
        if ($world === '.' || $world === '..' || !is_dir("$root/$world")) { continue; }

        $wid = worldIdByName($pdo, $world);
        if (!$wid) { continue; }

        // One query per world rather than one per file.
        $st = $pdo->prepare("SELECT cfg_file, COUNT(*) n FROM mod_config_overrides
                             WHERE world_id = ? GROUP BY cfg_file");
        $st->execute([$wid]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[$r['cfg_file']] = (int)$r['n']; }

        $files = [];
        foreach ([['custom_configs', 0], ['custom_configs_secure', 1]] as [$sub, $secure]) {
            $parked = "$root/$world/$sub/" . MODCONFIG_IMPORTED_DIR;
            if (!is_dir($parked)) { continue; }

            foreach (scandir($parked) as $name) {
                if (substr($name, -4) !== '.cfg' || !is_file("$parked/$name")) { continue; }

                $inDb = $rows[$name] ?? 0;
                $changed = null;
                $state = 'accounted';

                if ($inDb === 0) {
                    // Only now is a parse worth its subprocess. The file's own
                    // `# Default value:` comments are the baseline -- the same one the import
                    // used and the same one the editor's modified badge uses, so this cannot
                    // disagree with either.
                    $changed = modConfigCountChangedFromDefault("$parked/$name");
                    $state = $changed > 0 ? 'at_risk' : 'empty';
                }

                $files[] = [
                    'file'       => $name,
                    'dir'        => $sub,
                    'secure'     => $secure,
                    'rows_in_db' => $inDb,
                    'changed'    => $changed,
                    'state'      => $state,
                    'bytes'      => (int)filesize("$parked/$name"),
                ];
                $totalFiles++;
                if ($state === 'at_risk') { $totalRisk++; }
            }
        }

        if ($files) {
            usort($files, function ($a, $b) {
                if ($a['state'] !== $b['state']) { return $a['state'] === 'at_risk' ? -1 : 1; }
                return strcasecmp($a['file'], $b['file']);
            });
            $worlds[] = [
                'world'      => $world,
                'files'      => $files,
                'at_risk'    => count(array_filter($files, fn($f) => $f['state'] === 'at_risk')),
                'db_files'   => count($rows),
                'db_rows'    => array_sum($rows),
            ];
        }
    }

    return ['worlds' => $worlds, 'totals' => ['files' => $totalFiles, 'at_risk' => $totalRisk]];
}

/**
 * How many settings in this .cfg differ from the default documented inside it.
 *
 * Returns -1 when the file could not be parsed. NOT 0: zero means "nothing to lose, safe to
 * delete", and a parse failure answering zero would mark an unreadable file safe. That is the
 * same class of bug as a 0/false default doubling as a real answer.
 */
function modConfigCountChangedFromDefault($path) {
    $raw = shell_exec(MODCONFIG_TOOL . ' --parse-file ' . escapeshellarg($path) . ' 2>/dev/null');
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) { return -1; }

    $entries = $d['entries'] ?? null;
    if ($entries === null) {
        $f = $d['files'][0] ?? null;
        $entries = $f['entries'] ?? null;
    }
    if (!is_array($entries)) { return -1; }

    $n = 0;
    foreach ($entries as $e) {
        if (!empty($e['has_default']) && ($e['value'] ?? null) !== ($e['default'] ?? null)) { $n++; }
    }
    return $n;
}

/**
 * Delete named parked originals for one world, after the operator has chosen them.
 *
 * Every name is reduced to a basename and the resolved path is required to sit inside this
 * world's parked directory. The caller is the admin UI, but a delete endpoint that trusts a
 * filename is a delete endpoint that can be pointed at the save files -- which live inside
 * game/ on this very tree, where clearing the wrong directory has destroyed worlds here before.
 *
 * Returns per-file outcomes rather than a count. "3 deleted" cannot tell the operator WHICH
 * three, and this is the one action in the feature that cannot be undone.
 */
function modConfigDeleteMigrationBackups($pdo, $world, $names) {
    if (!worldIdByName($pdo, $world)) { return ['error' => "No world named '$world'"]; }
    if (!is_array($names) || !$names) { return ['error' => 'No files selected.']; }

    $results = [];
    foreach ($names as $raw) {
        $name = basename((string)$raw);
        $done = false;

        foreach (['custom_configs', 'custom_configs_secure'] as $sub) {
            $parked = MODCONFIG_WORLDS_ROOT . "/$world/$sub/" . MODCONFIG_IMPORTED_DIR;
            $real   = realpath("$parked/$name");
            $base   = realpath($parked);
            if (!$real || !$base || strpos($real, $base . '/') !== 0) { continue; }
            if (!is_file($real)) { continue; }

            if (@unlink($real)) {
                $results[] = ['file' => $name, 'dir' => $sub, 'deleted' => true];
                error_log("[modConfigs] $world: $sub/" . MODCONFIG_IMPORTED_DIR . "/$name deleted by operator");
            } else {
                $results[] = ['file' => $name, 'dir' => $sub, 'deleted' => false,
                              'error' => 'could not delete'];
            }
            $done = true;
            break;
        }

        if (!$done) {
            $results[] = ['file' => $name, 'deleted' => false, 'error' => 'not found in this world'];
        }
    }

    // Tidy the now-empty parked dirs, and nothing above them. rmdir refuses a non-empty
    // directory, which is the safety here: a file the operator kept also keeps its folder.
    foreach (['custom_configs', 'custom_configs_secure'] as $sub) {
        $parked = MODCONFIG_WORLDS_ROOT . "/$world/$sub/" . MODCONFIG_IMPORTED_DIR;
        if (is_dir($parked)) { @rmdir($parked); }
    }

    $ok = count(array_filter($results, fn($r) => !empty($r['deleted'])));
    return ['ok' => true, 'deleted' => $ok, 'results' => $results];
}
