<?php
/**
 * Deterministic health scan. Pure PHP — no LLM involved.
 *
 * This runs BEFORE the model and exists for three reasons:
 *
 *  1. The AI Helper should be useful with no provider configured at all. Every finding
 *     below is a real, actionable answer produced by pattern matching and SQL.
 *  2. A small self-hosted model handed 200 raw log lines will hallucinate. Handed
 *     "PrimaryPlugin failed to load, its hard dependency X is absent, here are the four
 *     lines proving it", it does well. Grounding beats parameter count.
 *  3. It bounds cost. The expensive model reasons about evidence, not about scrollback.
 *
 * Findings are structured, not prose:
 *   severity  critical|warning|info
 *   title     one line, no jargon
 *   detail    what it means and what to do
 *   evidence  the actual log lines / rows that triggered it
 *   world     the world it belongs to, or '' for server-wide
 *   ask       a seeded question for the "Ask AI" button
 *
 * Every signature here comes from a failure this project has actually shipped. Adding a
 * pattern is cheap; adding one that cannot distinguish healthy from broken is worse than
 * adding nothing, because a check that reads the same either way is indistinguishable
 * from a passing check.
 */

require_once __DIR__ . '/aicontext.php';

function aiDiagnose($pdo, $scopeWorld = '') {
    $findings = [];

    $worlds = aiWorldRows($pdo);
    if ($scopeWorld !== '') {
        $worlds = array_values(array_filter($worlds, function ($w) use ($scopeWorld) {
            return $w['name'] === $scopeWorld;
        }));
    }

    foreach ($worlds as $w) {
        $findings = array_merge($findings, aiDiagnoseWorld($pdo, $w));
    }

    if ($scopeWorld === '') {
        $findings = array_merge($findings, aiDiagnoseServer($pdo, $worlds));
    }

    $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($findings, function ($a, $b) use ($rank) {
        $d = $rank[$a['severity']] <=> $rank[$b['severity']];
        return $d !== 0 ? $d : strcmp($a['title'], $b['title']);
    });

    return $findings;
}

// "3,837 hours" is a number an operator has to do arithmetic on before it means anything,
// and it was the form the model parroted back when describing seven-month-old logs.
function aiAgeText($seconds) {
    if ($seconds < 90)          return round($seconds) . 's';
    if ($seconds < 5400)        return round($seconds / 60) . ' minutes';
    if ($seconds < 172800)      return round($seconds / 3600) . ' hours';
    if ($seconds < 86400 * 60)  return round($seconds / 86400) . ' days';
    return round($seconds / (86400 * 30.4)) . ' months';
}

function aiFinding($severity, $title, $detail, $evidence, $world, $ask) {
    return [
        'severity' => $severity,
        'title'    => $title,
        'detail'   => $detail,
        'evidence' => is_array($evidence) ? array_values($evidence) : [$evidence],
        'world'    => $world,
        'ask'      => $ask,
    ];
}

/* ---------------------------------------------------------------------------------- */

function aiDiagnoseWorld($pdo, $w) {
    $out  = [];
    $name = $w['name'];
    $log  = aiWorldLogPath($name);

    // Only the CURRENT boot matters. A dependency error from three restarts ago that the
    // operator already fixed must not be reported as a live fault -- that was the single
    // most misleading thing about the 2.44 prompt, which shipped the last 200 lines
    // regardless of where the most recent start marker fell.
    $lines = $log ? aiTailSinceLastStart($log, 4000) : [];

    // A STOPPED WORLD IS NOT A BROKEN WORLD.
    //
    // "Since the last start" is the right window, but it says nothing about WHEN that start
    // was. On a real server with eleven deliberately-stopped test worlds, every one of them
    // produced present-tense critical findings quoting log lines from seven months earlier:
    // "Ironbound: restarting repeatedly", "cannot bind its port", "backups are overdue".
    // The model then faithfully reported a server in crisis. Nothing was wrong with it --
    // the worlds were simply off, which is the operator's own doing and the normal resting
    // state of this product.
    //
    // So findings drawn from the log of a stopped world are HISTORY. They keep their
    // evidence (it is still the best clue to why it stopped) but they are capped at
    // 'warning', they say when they happened, and the two checks that are meaningless for a
    // stopped world -- restart loops and backup freshness -- are skipped outright.
    $running = aiTruthy($w, 'status');
    $logAge  = ($log && is_readable($log)) ? time() - filemtime($log) : null;
    $stale   = !$running;
    $when    = $logAge === null ? '' : ' (last activity ' . aiAgeText($logAge) . ' ago)';

    // Downgrade + re-phrase in one place so no individual check can forget to do it.
    $finding = function ($severity, $title, $detail, $evidence, $ask)
               use ($stale, $when, $name) {
        if ($stale) {
            $severity = ($severity === 'critical') ? 'warning' : $severity;
            $title    = "$title — at its last run$when";
            $detail   = "This world is STOPPED, so this is a record of what happened when it "
                      . "last ran, not a live fault. It is worth reading before starting the "
                      . "world again. " . $detail;
        }
        return aiFinding($severity, $title, $detail, $evidence, $name, $ask);
    };

    if ($lines) {
        // --- BepInEx plugin load failures -------------------------------------------
        $failed = aiGrep($lines, '/\[(Error|Fatal)\s*:\s*BepInEx\]|Could not load \[|Skipping \[|failed to load/i', 1);
        if ($failed) {
            $out[] = $finding('critical',
                "$name: a mod failed to load",
                'BepInEx rejected at least one plugin on the most recent start. A modded world with a failed plugin usually will not accept clients, and clients will mismatch.',
                array_slice($failed, 0, 12),
                "The world '$name' has a BepInEx plugin load failure. Identify which mod failed and why, and tell me exactly what to change in the mod selection to fix it."
            );
        }

        // --- Missing hard dependencies ----------------------------------------------
        $missing = aiGrep($lines, '/missing dependenc|hard dependenc|could not find dependenc/i', 1);
        if ($missing) {
            $out[] = $finding('critical',
                "$name: a mod is missing a dependency",
                'A selected mod declares a dependency that is not installed. Add it in the world\'s mod selection — the dependency resolver will normally pull it in, so this usually means a pinned version whose dependency set differs.',
                array_slice($missing, 0, 10),
                "World '$name' reports a missing mod dependency. Compare the expected mod list against what BepInEx actually loaded and name the mod I need to add."
            );
        }

        // --- The 2.39 permission class ----------------------------------------------
        $perms = aiGrep($lines, '/UnauthorizedAccessException|Permission denied|Access to the path/i', 1);
        if ($perms) {
            $out[] = $finding('critical',
                "$name: permission error in the mod directory",
                'Mod archives packaged on Windows can store directories without the execute bit. This is the class of failure fixed in 2.39; if it has reappeared, a rebuild of the mod pack for this world normally clears it.',
                array_slice($perms, 0, 8),
                "World '$name' is throwing permission errors under BepInEx. Walk me through fixing it."
            );
        }

        // --- SteamCMD trouble --------------------------------------------------------
        $steam = aiGrep($lines, '/Failed to install app|Missing configuration|No subscription|Steam is having trouble/i', 1);
        if (count($steam) >= 3) {
            $out[] = $finding('warning',
                "$name: Steam is failing to deliver the game files",
                'Repeated SteamCMD failures. A handful is normal and self-healing; a sustained run means the update will not complete and the world will not start on the current build.',
                array_slice($steam, 0, 8),
                "World '$name' keeps failing its Steam download. Is this transient or do I need to act?"
            );
        }

        // --- Port conflicts -----------------------------------------------------------
        $port = aiGrep($lines, '/Address already in use|bind failed|EADDRINUSE|port .* in use/i', 1);
        if ($port) {
            $out[] = $finding('critical',
                "$name: UDP port conflict",
                'The world could not bind its port. Two worlds sharing a port, or something outside PhValheim holding it, will both produce this.',
                array_slice($port, 0, 6),
                "World '$name' cannot bind its port. Which port is it, what is holding it, and how do I resolve the clash?"
            );
        }

        // --- Crash loop ---------------------------------------------------------------
        // Deliberately counted over the whole tail rather than since the last start:
        // repeated start markers ARE the signal here.
        //
        // Only meaningful for a world that is actually UP. A stopped world is not looping,
        // it is off -- and its log necessarily contains every start it ever made, so this
        // check fired on all eleven stopped worlds and reported a server-wide crisis.
        $all    = ($running && $log) ? aiTailLines($log, 4000) : [];
        $starts = aiGrep($all, '/Valheim version|DungeonDB Start|Game server connected|Net scene destroyed/i', 0);
        if ($running && count($starts) >= 6) {
            $out[] = $finding('warning',
                "$name: restarting repeatedly",
                'Several server-start markers appear in a short span of log. A world that starts, dies and restarts will look "running" in the UI while never actually serving players.',
                array_slice($starts, -8),
                "World '$name' looks like it is in a restart loop. Find the fault that kills it each time."
            );
        }
    } elseif (aiTruthy($w, 'status')) {
        $out[] = aiFinding('info',
            "$name: no log yet",
            'The world is marked running but has written no log. If this persists past a minute or two, the process is not actually starting.',
            [], $name,
            "World '$name' is marked running but has no log output. What should I check?"
        );
    }

    // --- Expected mods vs actually loaded -------------------------------------------
    // The database is the source of truth for what SHOULD be installed; the log is the
    // truth about what IS. Disagreement between them is the single most useful signal
    // this scan produces, and it needs no model to compute.
    if ($lines) {
        $expected = aiExpectedMods($pdo, $name);
        if ($expected) {
            $loadedLines = aiGrep($lines, '/\[(Info|Message)\s*:\s*BepInEx\]\s*Loading \[/i', 0);
            $loadedBlob  = strtolower(implode("\n", $loadedLines));
            if ($loadedBlob !== '') {
                $absent = [];
                foreach ($expected as $modName) {
                    // Compare on a loosened name: BepInEx prints the plugin's display
                    // name, which routinely differs from the package name by separators
                    // and casing (JewelHeim vs Jewelheim, Epic_Loot vs EpicLoot).
                    $needle = strtolower(preg_replace('/[^a-z0-9]/i', '', $modName));
                    if ($needle === '') continue;
                    $hay = preg_replace('/[^a-z0-9\n]/i', '', $loadedBlob);
                    if (strpos($hay, $needle) === false) $absent[] = $modName;
                }
                if ($absent) {
                    $out[] = $finding(count($absent) > 2 ? 'critical' : 'warning',
                        "$name: " . count($absent) . " configured mod(s) did not load",
                        'These mods are selected for the world in the database but no matching "Loading [" line appears since the last start. Name matching is approximate, so confirm against the log before acting.',
                        array_slice($absent, 0, 15),
                        "World '$name' has configured mods that never loaded: " . implode(', ', array_slice($absent, 0, 10)) . ". Confirm from the log which are genuinely absent and why."
                    );
                }
            }
        }
    }

    // --- Enforced-but-empty access list ----------------------------------------------
    // Valheim enforces permittedlist.txt only when it has entries. An empty enforced list
    // is a WIDE OPEN server whose Access tab claims otherwise -- the exact trap the
    // engine already warns about at world start. Surfacing it here puts it in front of
    // the operator instead of in a log nobody reads.
    if (!aiTruthy($w, 'public') && !aiTruthy($w, 'vanilla')) {
        $permitted = "/opt/stateful/worlds/$name/permittedlist.txt";
        if (is_readable($permitted)) {
            $entries = array_filter(array_map('trim', file($permitted)), function ($l) {
                return $l !== '' && strpos($l, '//') !== 0;
            });
            if (!$entries) {
                $out[] = aiFinding('critical',
                    "$name: access list is enforced but empty — the world is open to everyone",
                    'Valheim only enforces permittedlist.txt when it has entries. An empty file is no restriction at all, so this world accepts any player while the Access tab implies it is private. Add at least one CITIZEN.',
                    [$permitted . ' contains no entries'], $name,
                    "World '$name' has an enforced but empty permitted list. Explain the exposure and the fix."
                );
            }
        }
    }

    // --- Backups --------------------------------------------------------------------
    // Running worlds only. Backups exist to protect a world that is being PLAYED; a world
    // the operator stopped months ago has nothing new to save, and flagging it produces a
    // permanent warning that can never be cleared except by deleting the world. Reported
    // as "Test and ligmaballs haven't successfully backed up in over 5 months", which is
    // true, useless, and reads like a fault.
    $last = $running ? ($w['last_backup_time'] ?? null) : null;
    if ($last && $last !== '0000-00-00 00:00:00') {
        $age = time() - strtotime($last);
        $interval = (int)($w['backup_interval_minutes'] ?? 0);
        $budget   = $interval > 0 ? $interval * 60 * 3 : 86400 * 2;
        if ($age > $budget) {
            $out[] = aiFinding('warning',
                "$name: backups are overdue",
                'The last successful backup is older than three times the configured interval. Check worldBackups.log for the reason — a backup that is silently failing looks identical to one that is merely not due.',
                ['Last backup: ' . $last . ' (' . round($age / 3600) . 'h ago)'], $name,
                "World '$name' has not backed up in " . round($age / 3600) . " hours. Read the backup log and tell me why."
            );
        }
    }

    return $out;
}

/* ---------------------------------------------------------------------------------- */

function aiDiagnoseServer($pdo, $worlds) {
    $out = [];

    // --- Engine log ------------------------------------------------------------------
    $engine = aiTailLines('/opt/stateful/logs/phvalheim.log', 1500);
    if ($engine) {
        $errs = aiGrep($engine, '/\[(ERROR|FATAL)\s*:/i', 0);
        if ($errs) {
            $out[] = aiFinding(count($errs) > 5 ? 'critical' : 'warning',
                'Engine errors in phvalheim.log',
                'The orchestration engine logged errors. These affect world lifecycle operations — create, start, stop, update — rather than gameplay.',
                array_slice($errs, -10), '',
                'Read the engine log and explain these errors and what I should do about them.'
            );
        }
        $warns = aiGrep($engine, '/\[WARNING\s*:.*permittedlist|wide open|no entries/i', 0);
        if ($warns) {
            $out[] = aiFinding('critical',
                'A world is running with an empty enforced access list',
                'syncAccessLists.sh warned at world start that a world has an enforced but empty permitted list, which means it is open to any player.',
                array_slice($warns, -6), '',
                'Which worlds have an empty enforced access list, and how do I close them?'
            );
        }
    }

    // --- Mod catalogue sync -----------------------------------------------------------
    try {
        $rows = $pdo->query(
            "SELECT source, status, started_at, finished_at, error
             FROM mod_sync_runs
             WHERE id IN (SELECT MAX(id) FROM mod_sync_runs GROUP BY source)"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $failed = isset($r['status']) && stripos((string)$r['status'], 'fail') !== false;
            if ($failed) {
                $out[] = aiFinding('warning',
                    'Mod catalogue sync failed for ' . $r['source'],
                    'The most recent sync run for this catalogue did not complete. New and updated mods will not appear in the picker until it succeeds.',
                    [trim((string)($r['error'] ?? 'no error text recorded'))], '',
                    'The ' . $r['source'] . ' mod catalogue sync is failing. Read modSync.log and tell me why.'
                );
                continue;
            }
            $ts = $r['finished_at'] ?? $r['started_at'] ?? null;
            if ($ts && (time() - strtotime($ts)) > 86400 * 2) {
                $out[] = aiFinding('info',
                    'Mod catalogue for ' . $r['source'] . ' is stale',
                    'No successful sync in over two days. Sync runs hourly via cron, throttled by the configured interval, so a gap this size usually means cron or the feed is unhappy.',
                    ['Last run: ' . $ts], '',
                    'The ' . $r['source'] . ' catalogue has not synced recently. What is blocking it?'
                );
            }
        }
    } catch (Exception $e) { /* pre-2.43 schema; nothing to report */ }

    // --- Disk ------------------------------------------------------------------------
    $free  = @disk_free_space('/opt/stateful');
    $total = @disk_total_space('/opt/stateful');
    if ($free && $total) {
        $pct = ($free / $total) * 100;
        if ($pct < 10) {
            $out[] = aiFinding($pct < 5 ? 'critical' : 'warning',
                'Low disk space on /opt/stateful',
                'Worlds, backups and the mod cache all share this volume. Backups fail quietly when it fills, and a world save interrupted by a full disk can corrupt.',
                [sprintf('%.1f%% free (%.1f GB of %.1f GB)', $pct, $free / 1073741824, $total / 1073741824)], '',
                'I am low on disk on /opt/stateful. What is safe to prune?'
            );
        }
    }

    // --- Supervisor process states -----------------------------------------------------
    $sv = @shell_exec('supervisorctl status 2>/dev/null');
    if ($sv) {
        $bad = aiGrep(explode("\n", $sv), '/\b(FATAL|BACKOFF|EXITED|UNKNOWN)\b/', 0);
        if ($bad) {
            $out[] = aiFinding('critical',
                'A supervised process is not running',
                'One or more services under supervisor are in a failed state. If this is mariadb, nginx or php-fpm8, the admin UI you are reading this in is running on borrowed time.',
                array_slice($bad, 0, 10), '',
                'Supervisor reports failed processes. Diagnose them.'
            );
        }
    }

    if (!$out) {
        $out[] = aiFinding('info',
            'No problems detected',
            'The deterministic scan found nothing wrong across logs, mod state, backups, disk and services. Ask a question below if something still looks off — the assistant can read any log directly.',
            [], '',
            'Give me a short health summary of this PhValheim server.'
        );
    }

    return $out;
}

/* ---------------------------------------------------------------------------------- */
/* helpers                                                                              */

// aiTruthy() now lives in aicontext.php. It was here, and aiSystemPrompt() -- which is in
// aicontext -- started calling it. The include runs ONE WAY (aidiagnose requires aicontext,
// never the reverse), so the chat path loaded aicontext alone and fatalled on every single
// message with "Call to undefined function aiTruthy()". The stream emitted its `start`
// event, the PHP process died, and the panel showed an empty bubble and no error at all.

/** Grep with an optional number of trailing context lines. */
function aiGrep($lines, $pattern, $context = 0) {
    $hits = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match($pattern, $lines[$i])) continue;
        $hits[] = rtrim($lines[$i]);
        for ($c = 1; $c <= $context && ($i + $c) < $n; $c++) {
            $hits[] = '    ' . rtrim($lines[$i + $c]);
        }
    }
    return $hits;
}
