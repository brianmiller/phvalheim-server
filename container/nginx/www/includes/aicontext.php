<?php
/**
 * The AI Helper's tool surface: what the model is allowed to look at, and how.
 *
 * 2.44 gave the model `tail -200` of exactly one log, pasted into the system prompt. It
 * could not follow a lead, could not compare two worlds, could not check whether the
 * thing it was blaming was even configured, and could not look further back than 200
 * lines — so the most common real answer ("the failure is above the window you were
 * given") was unreachable by construction.
 *
 * Here the model asks. Every tool is READ-ONLY. Nothing in this file starts, stops,
 * edits, deletes or writes anything: a wrong answer from an assistant should waste the
 * operator's time, never their world.
 *
 * SAFETY — log paths. read_log and search_log take a filename from the model, which is
 * attacker-influenced input the moment anything in a log is attacker-influenced (player
 * names appear in world logs). Both resolve through aiResolveLogPath(), which requires
 * the realpath() to sit inside /opt/stateful/logs. basename() alone is not enough: it
 * stops ../../etc/passwd but not a symlink planted inside the log directory.
 */

if (!defined('AI_LOG_DIR')) define('AI_LOG_DIR', '/opt/stateful/logs');

/* ====================================================================================
 * Tool schemas handed to the model
 * ==================================================================================== */

/**
 * @param bool $withActions Include the tools that CHANGE things.
 *
 * Read-only tools are always offered. Action tools are gated because a degraded model --
 * one whose endpoint refuses the tools parameter, or which accepts it and then answers in
 * prose -- must not be handed a catalogue of things it cannot reliably drive. Offering
 * actions we know the model cannot use produces confident claims that a world was
 * restarted when nothing happened.
 */
function aiToolDefinitions($withActions = true) {
    $str  = function ($d) { return ['type' => 'string',  'description' => $d]; };
    $int  = function ($d) { return ['type' => 'integer', 'description' => $d]; };

    $tools = [
        [
            'name'        => 'list_worlds',
            'description' => 'List every world with its status, mode (modded/vanilla), port, player-activity timestamp and mod count. Call this first when the question is not already about one specific world.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'get_world',
            'description' => 'Full configuration for one world: ports, seed, crossplay/listed/public flags, access-list counts, backup policy, disk usage and last backup.',
            'parameters'  => [
                'type' => 'object',
                'properties' => ['world' => $str('Exact world name.')],
                'required' => ['world'],
            ],
        ],
        [
            'name'        => 'list_logs',
            'description' => 'List available log files with size and last-modified time. Use this to find out what you can read before guessing a filename.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'read_log',
            'description' => 'Read a log. Defaults to the tail. Set since_last_start=true on a world log to get only the current boot, which is almost always what you want when diagnosing a start failure.',
            'parameters'  => [
                'type' => 'object',
                'properties' => [
                    'file'             => $str('Log filename as reported by list_logs, e.g. "valheimworld_Midgard.log" or "phvalheim.log".'),
                    'lines'            => $int('How many lines to return (default 200, max 1200).'),
                    'since_last_start' => ['type' => 'boolean', 'description' => 'Return only lines after the most recent server-start marker.'],
                ],
                'required' => ['file'],
            ],
        ],
        [
            'name'        => 'search_log',
            'description' => 'Search a log for a pattern and return matches with surrounding context. Use this instead of reading huge tails — it searches the whole file, not just the end.',
            'parameters'  => [
                'type' => 'object',
                'properties' => [
                    'file'    => $str('Log filename.'),
                    'pattern' => $str('Case-insensitive substring, or a regular expression if is_regex is true.'),
                    'is_regex'=> ['type' => 'boolean', 'description' => 'Treat pattern as a regular expression.'],
                    'context' => $int('Lines of context either side of each match (default 2, max 10).'),
                    'limit'   => $int('Maximum matches to return (default 40, max 200).'),
                ],
                'required' => ['file', 'pattern'],
            ],
        ],
        [
            'name'        => 'get_world_mods',
            'description' => 'The resolved mod install plan for a world: source catalogue, owner, name, version, whether it is pinned, and whether it was an explicit pick or pulled in as a dependency.',
            'parameters'  => [
                'type' => 'object',
                'properties' => ['world' => $str('Exact world name.')],
                'required' => ['world'],
            ],
        ],
        [
            'name'        => 'get_mod_sync_status',
            'description' => 'Status of the mod catalogue syncs (Thunderstore, Hexium): last run, counts, errors, and total catalogue size.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'get_backup_status',
            'description' => 'Backup state for one world or all worlds: last backup, count, total size, configured interval and retention.',
            'parameters'  => [
                'type' => 'object',
                'properties' => ['world' => $str('World name, or omit for all worlds.')],
                'required' => [],
            ],
        ],
        [
            'name'        => 'get_system_health',
            'description' => 'Host health: CPU load, memory, disk on /opt/stateful, uptime, and the state of every supervised process.',
            'parameters'  => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'get_diagnostics',
            'description' => 'Run the deterministic health scan and return structured findings with evidence. Cheap and precise — prefer this over reading logs blind when the operator asks an open "what is wrong" question.',
            'parameters'  => [
                'type' => 'object',
                'properties' => ['world' => $str('Limit the scan to one world, or omit for the whole server.')],
                'required' => [],
            ],
        ],
    ];

    if ($withActions) {
        // Required inside the function, not at file scope: the include graph runs one way
        // (aiactions requires aicontext, never the reverse) and a top-level require here
        // would make that a cycle.
        require_once __DIR__ . '/aiactions.php';
        $tools = array_merge($tools, aiActionToolDefinitions());
    }

    return $tools;
}

/* ====================================================================================
 * Dispatch
 * ==================================================================================== */

/**
 * Run one tool call. Always returns a string (JSON or text) for the model.
 *
 * A tool that fails returns an explanatory string rather than throwing: the model can
 * recover from "that log does not exist, here is what does", but a 500 ends the turn.
 */
function aiRunTool($pdo, $name, $args) {
    try {
        // Count every call, including the read-only ones. ai_tools_used is how we find out
        // which tools actually earn their place in the catalogue.
        require_once __DIR__ . '/aiactions.php';
        aiUsageBump($pdo, 'tool', $name);

        switch ($name) {
            case 'list_worlds':         return aiToolListWorlds($pdo);
            case 'get_world':           return aiToolGetWorld($pdo, $args['world'] ?? '');
            case 'list_logs':           return aiToolListLogs();
            case 'read_log':            return aiToolReadLog($args);
            case 'search_log':          return aiToolSearchLog($args);
            case 'get_world_mods':      return aiToolWorldMods($pdo, $args['world'] ?? '');
            case 'get_mod_sync_status': return aiToolSyncStatus($pdo);
            case 'get_backup_status':   return aiToolBackups($pdo, $args['world'] ?? '');
            case 'get_system_health':   return aiToolHealth();
            case 'get_diagnostics':
                require_once __DIR__ . '/aidiagnose.php';
                return json_encode(aiDiagnose($pdo, $args['world'] ?? ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Anything left might be an action. Actions are dispatched by NAME from the
        // catalogue rather than listed here, so adding one cannot be half-done -- a tool
        // the model can see is always a tool that runs, and vice versa.
        if (isset(aiActionCatalogue()[$name])) {
            return aiActionRun($pdo, $name, $args);
        }

        return "Unknown tool '$name'.";
    } catch (Exception $e) {
        return "Tool '$name' failed: " . $e->getMessage();
    }
}

/* ---- worlds ---------------------------------------------------------------------- */

/**
 * Is this row's flag on?
 *
 * Lives HERE, not in aidiagnose.php, because the include graph runs one way: aidiagnose
 * requires aicontext, never the reverse. A helper the chat path needs must sit on the
 * aicontext side or the chat path fatals while the diagnostics path looks perfectly fine.
 *
 * 'Running' is a value, not just a boolean: worlds.status is a word.
 */
function aiTruthy($row, $key) {
    if (!isset($row[$key])) return false;
    $v = $row[$key];
    return $v === 1 || $v === '1' || $v === true || strtolower((string)$v) === 'running';
}

function aiWorldRows($pdo) {
    try {
        // SELECT * deliberately: the worlds table has grown a column nearly every
        // release since 2.27, and naming them here would mean this file needs editing
        // every time. Consumers read defensively with ?? instead.
        return $pdo->query("SELECT * FROM worlds ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function aiToolListWorlds($pdo) {
    $out = [];
    foreach (aiWorldRows($pdo) as $w) {
        $out[] = [
            'name'                 => $w['name'],
            'status'               => $w['status'] ?? 'unknown',
            'mode'                 => !empty($w['vanilla']) ? 'vanilla' : 'modded',
            'port'                 => $w['port'] ?? null,
            'crossplay'            => (int)($w['crossplay'] ?? 0),
            'listed'               => (int)($w['listed'] ?? 0),
            'access_open_to_all'   => (int)($w['public'] ?? 0),
            'last_player_activity' => $w['last_player_activity'] ?? null,
            'last_backup'          => $w['last_backup_time'] ?? null,
            'mod_count'            => count(aiExpectedMods($pdo, $w['name'])),
            'has_log'              => aiWorldLogPath($w['name']) !== null,
        ];
    }
    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function aiToolGetWorld($pdo, $world) {
    if ($world === '') return 'A world name is required.';
    foreach (aiWorldRows($pdo) as $w) {
        if (strcasecmp($w['name'], $world) !== 0) continue;

        // Never hand a credential to a third-party model. The operator can read the
        // password in the UI; the assistant has no reason to and every reason not to.
        foreach (['password', 'password_public'] as $secret) {
            if (isset($w[$secret])) $w[$secret] = ($w[$secret] !== '' ? '(set — redacted)' : '(not set)');
        }

        $dir = "/opt/stateful/worlds/{$w['name']}";
        $w['_access_lists'] = [];
        foreach (['permittedlist', 'adminlist', 'bannedlist'] as $list) {
            $p = "$dir/$list.txt";
            $w['_access_lists'][$list] = is_readable($p)
                ? count(array_filter(array_map('trim', file($p)), function ($l) { return $l !== '' && strpos($l, '//') !== 0; }))
                : 'file missing';
        }
        $w['_log_file'] = aiWorldLogPath($w['name']) ? basename(aiWorldLogPath($w['name'])) : null;
        return json_encode($w, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    return "No world named '$world'. Call list_worlds for the exact names.";
}

/** Package names selected for a world, via the 2.43+ catalogue. */
function aiExpectedMods($pdo, $world) {
    try {
        $stmt = $pdo->prepare(
            "SELECT m.name
             FROM world_mods wm
             JOIN worlds w ON w.id = wm.world_id
             JOIN mods   m ON m.id = wm.mod_id
             WHERE w.name = ?"
        );
        $stmt->execute([$world]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    } catch (Exception $e) {
        return [];
    }
}

function aiToolWorldMods($pdo, $world) {
    if ($world === '') return 'A world name is required.';
    try {
        // m.latest_version, NOT m.version.
        //
        // The newest version is denormalised onto `mods` as `latest_version`; there is no
        // `version` column, so this tool returned nothing but
        // "Column not found: 1054 Unknown column 'm.version'" for its whole life. The
        // mock-provider test never called this tool, so nothing caught it until a live
        // model asked a world about its mods.
        $stmt = $pdo->prepare(
            "SELECT m.source, m.owner, m.name, m.latest_version AS latest_version,
                    mv.version AS pinned_version, wm.pin_version_id
             FROM world_mods wm
             JOIN worlds w ON w.id = wm.world_id
             JOIN mods   m ON m.id = wm.mod_id
             LEFT JOIN mod_versions mv ON mv.id = wm.pin_version_id
             WHERE w.name = ?
             ORDER BY m.owner, m.name"
        );
        $stmt->execute([$world]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return 'The mod catalogue tables are not available on this install: ' . $e->getMessage();
    }

    if (!$rows) return "World '$world' has no mods selected (or is a vanilla world).";

    foreach ($rows as &$r) {
        $r['follows_latest'] = empty($r['pin_version_id']);
        $r['effective_version'] = $r['pinned_version'] ?: $r['latest_version'];
        unset($r['pin_version_id']);
    }
    return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/* ---- logs ------------------------------------------------------------------------ */

/**
 * Resolve a model-supplied filename to a real path inside the log directory, or null.
 *
 * realpath() containment, not basename(): basename() blocks traversal but would happily
 * follow a symlink sitting inside the log directory. Both checks are cheap; only one of
 * them is sufficient.
 */
function aiResolveLogPath($file) {
    $file = (string)$file;
    if ($file === '' || strpos($file, "\0") !== false) return null;

    $candidate = AI_LOG_DIR . '/' . basename($file);
    $real      = realpath($candidate);
    if ($real === false) return null;

    $root = realpath(AI_LOG_DIR);
    if ($root === false) return null;
    if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    if (!is_file($real) || !is_readable($real)) return null;

    return $real;
}

function aiWorldLogPath($world) {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$world);
    if ($safe === '') return null;
    return aiResolveLogPath("valheimworld_{$safe}.log");
}

function aiToolListLogs() {
    $out = [];
    foreach (glob(AI_LOG_DIR . '/*.log') ?: [] as $p) {
        if (!is_readable($p)) continue;
        $out[] = [
            'file'     => basename($p),
            'size_kb'  => round(filesize($p) / 1024, 1),
            'modified' => date('Y-m-d H:i:s', filemtime($p)),
        ];
    }
    usort($out, function ($a, $b) { return strcmp($a['file'], $b['file']); });
    return $out ? json_encode($out, JSON_PRETTY_PRINT) : 'No log files found.';
}

/** Read the last $max lines without loading the whole file. */
function aiTailLines($path, $max = 200) {
    $real = is_file($path) ? $path : aiResolveLogPath($path);
    if (!$real) return [];

    $fp = @fopen($real, 'r');
    if (!$fp) return [];

    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $lines[] = $line;
        if (count($lines) > $max) array_shift($lines);
    }
    fclose($fp);
    return $lines;
}

/**
 * Lines since the most recent server-start marker.
 *
 * Falls back to the plain tail when no marker is found, so a log that has not restarted
 * recently still returns something useful rather than nothing.
 */
function aiTailSinceLastStart($path, $max = 3000) {
    $lines = aiTailLines($path, $max);
    if (!$lines) return [];

    $markers = '/Valheim version|DungeonDB Start|Starting to load scene|Net scene destroyed|\[Message\s*:\s*BepInEx\]\s*BepInEx .* - Valheim/i';
    $start = -1;
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match($markers, $lines[$i])) { $start = $i; break; }
    }
    return $start >= 0 ? array_slice($lines, $start) : $lines;
}

function aiToolReadLog($args) {
    $file = $args['file'] ?? '';
    $real = aiResolveLogPath($file);
    if (!$real) return "No readable log named '$file'. Call list_logs for the exact filenames.";

    $n = (int)($args['lines'] ?? 200);
    $n = max(1, min(1200, $n));

    $lines = !empty($args['since_last_start'])
        ? array_slice(aiTailSinceLastStart($real, max($n, 3000)), -$n)
        : aiTailLines($real, $n);

    if (!$lines) return basename($real) . ' is empty.';

    $header = basename($real) . ' — ' . count($lines) . ' lines'
            . (!empty($args['since_last_start']) ? ' since the most recent server start' : ' (tail)');
    return $header . ":\n" . rtrim(implode('', $lines));
}

function aiToolSearchLog($args) {
    $file = $args['file'] ?? '';
    $real = aiResolveLogPath($file);
    if (!$real) return "No readable log named '$file'. Call list_logs for the exact filenames.";

    $pattern = (string)($args['pattern'] ?? '');
    if ($pattern === '') return 'A search pattern is required.';

    $ctx   = max(0, min(10, (int)($args['context'] ?? 2)));
    $limit = max(1, min(200, (int)($args['limit'] ?? 40)));

    if (!empty($args['is_regex'])) {
        // The model wrote this pattern. Delimit and validate it before it reaches PCRE,
        // or a stray '/' turns into a syntax error and a malformed one can hang.
        $rx = '/' . str_replace('/', '\\/', $pattern) . '/i';
        if (@preg_match($rx, '') === false) {
            return "That regular expression is not valid: $pattern";
        }
    } else {
        $rx = '/' . preg_quote($pattern, '/') . '/i';
    }

    $fp = @fopen($real, 'r');
    if (!$fp) return 'Could not open ' . basename($real);

    $window = [];       // rolling buffer of the preceding $ctx lines
    $out    = [];
    $after  = 0;
    $hits   = 0;
    $lineNo = 0;

    while (($line = fgets($fp)) !== false) {
        $lineNo++;
        $line = rtrim($line, "\r\n");

        if ($hits < $limit && preg_match($rx, $line)) {
            foreach ($window as $w) $out[] = $w;
            $window = [];
            $out[]  = sprintf('%6d> %s', $lineNo, $line);
            $after  = $ctx;
            $hits++;
        } elseif ($after > 0) {
            $out[] = sprintf('%6d  %s', $lineNo, $line);
            $after--;
        } else {
            $window[] = sprintf('%6d  %s', $lineNo, $line);
            if (count($window) > $ctx) array_shift($window);
        }
    }
    fclose($fp);

    if (!$hits) return "No match for '$pattern' in " . basename($real) . " ($lineNo lines searched).";

    $note = ($hits >= $limit) ? " (stopped at the $limit-match limit — narrow the pattern for more)" : '';
    return "$hits match(es) for '$pattern' in " . basename($real) . "$note:\n" . implode("\n", $out);
}

/* ---- status ---------------------------------------------------------------------- */

function aiToolSyncStatus($pdo) {
    $out = [];
    try {
        $out['catalogue'] = $pdo->query(
            "SELECT source, COUNT(*) AS mods FROM mods GROUP BY source"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $out['catalogue'] = 'unavailable: ' . $e->getMessage(); }

    try {
        $out['last_runs'] = $pdo->query(
            "SELECT * FROM mod_sync_runs WHERE id IN (SELECT MAX(id) FROM mod_sync_runs GROUP BY source)"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $out['last_runs'] = 'unavailable: ' . $e->getMessage(); }

    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function aiToolBackups($pdo, $world) {
    $rows = aiWorldRows($pdo);
    $out  = [];
    foreach ($rows as $w) {
        if ($world !== '' && strcasecmp($w['name'], $world) !== 0) continue;

        $dir  = "/opt/stateful/backups/{$w['name']}";
        $files = is_dir($dir) ? (glob("$dir/*") ?: []) : [];
        $bytes = 0;
        foreach ($files as $f) $bytes += @filesize($f) ?: 0;

        $out[] = [
            'world'            => $w['name'],
            'last_backup'      => $w['last_backup_time'] ?? null,
            'backup_count'     => count($files),
            'total_size_mb'    => round($bytes / 1048576, 1),
            'interval_minutes' => $w['backup_interval_minutes'] ?? null,
            'use_global'       => $w['backup_use_global'] ?? null,
            'require_activity' => $w['backup_require_activity'] ?? null,
            'retain'           => [
                'all_hours'       => $w['backup_retain_all_hours'] ?? null,
                'daily_days'      => $w['backup_retain_daily_days'] ?? null,
                'weekly_days'     => $w['backup_retain_weekly_days'] ?? null,
                'monthly_months'  => $w['backup_retain_monthly_months'] ?? null,
            ],
        ];
    }
    if (!$out) return $world !== '' ? "No world named '$world'." : 'No worlds configured.';
    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function aiToolHealth() {
    $h = [];

    $load = @sys_getloadavg();
    if ($load) $h['load_average'] = ['1m' => $load[0], '5m' => $load[1], '15m' => $load[2]];
    $h['cpu_cores'] = (int)trim((string)@shell_exec('nproc 2>/dev/null')) ?: null;

    if (is_readable('/proc/meminfo')) {
        $mem = [];
        foreach (file('/proc/meminfo') as $l) {
            if (preg_match('/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+) kB/', $l, $m)) {
                $mem[$m[1]] = round($m[2] / 1024) . ' MB';
            }
        }
        $h['memory'] = $mem;
    }

    $free  = @disk_free_space('/opt/stateful');
    $total = @disk_total_space('/opt/stateful');
    if ($free && $total) {
        $h['disk_opt_stateful'] = [
            'free_gb'    => round($free / 1073741824, 1),
            'total_gb'   => round($total / 1073741824, 1),
            'percent_free' => round(($free / $total) * 100, 1),
        ];
    }

    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime) $h['uptime_hours'] = round(((float)explode(' ', $uptime)[0]) / 3600, 1);

    $sv = @shell_exec('supervisorctl status 2>/dev/null');
    if ($sv) {
        $procs = [];
        foreach (explode("\n", trim($sv)) as $l) {
            if (preg_match('/^(\S+)\s+(\S+)/', $l, $m)) $procs[$m[1]] = $m[2];
        }
        $h['supervisor'] = $procs;
    }

    return json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/* ====================================================================================
 * System prompt
 * ==================================================================================== */

/**
 * @param bool $withActions Whether this turn is offering the state-changing tools.
 *
 * The operating procedures below are omitted when it is not. Telling a model that cannot
 * call a tool how to sequence a rebuild only invites it to narrate one it never performed.
 */
function aiSystemPrompt($pdo, $contextWorld = '', $withActions = true) {
    $p  = "You are PhValheim's own AI Helper — not a general assistant that happens to be pointed at "
        . "a server. You are part of this product, you know how it is built, and you speak about it "
        . "in the first person plural: our engine, our worlds, this UI.\n\n";

    $p .= "WHAT PHVALHEIM IS\n"
        . "A single Docker container that manages Valheim dedicated servers and keeps every player's "
        . "mods identical to the server's. Inside it: nginx (8080 public, 8081 admin), PHP-FPM for both "
        . "web UIs, MariaDB for state, supervisor for process control, and the engine — a bash main loop "
        . "at /opt/stateless/engine/phvalheim that reconciles every world's desired state every two "
        . "seconds. Each world is a supervised process (valheimworld_<name>) with its own UDP port, its "
        . "own save directory under /opt/stateful/worlds/<name>, and its own log at "
        . "/opt/stateful/logs/valheimworld_<name>.log. Mods install into that world's game/BepInEx tree. "
        . "The database is the source of truth for what SHOULD be true; the logs are the truth about what "
        . "IS. Where they disagree, say so — that gap is the most useful thing you can find.\n\n";

    $p .= "A STOPPED WORLD IS NOT A BROKEN WORLD\n"
        . "This is the mistake to avoid above all others. Operators start and stop worlds deliberately, "
        . "and a server where every world is stopped is a completely normal server — very often a test "
        . "box, or one between play sessions. It is NOT 'severely degraded', not an outage, and not a "
        . "crisis.\n"
        . "- Never describe stopped worlds as down, failed, offline-as-a-fault, or in a restart loop.\n"
        . "- A stopped world's log describes the PAST. get_diagnostics marks those findings as historical "
        . "and tells you how long ago they happened — respect that. Report them as 'when it last ran', "
        . "and check the age before you treat anything as current. A log line from months ago is not "
        . "happening now.\n"
        . "- Do not tell the operator to fix a world they chose to switch off. If its last run ended "
        . "badly, mention it as something to know BEFORE starting it again.\n"
        . "- Backups, restarts and player access only matter for worlds that are running.\n\n";

    $p .= "SCOPE: ANSWER THE QUESTION THAT WAS ASKED\n"
        . "- Scoped to one world: answer about THAT world. Do not inventory the others, do not grade the "
        . "whole server, do not append a server-wide summary nobody asked for.\n"
        . "- Asked about the server: report the host and the services. Worlds appear as a count and a "
        . "state, not as eleven paragraphs. Only name a world if something about it genuinely needs "
        . "attention right now.\n"
        . "- Never pad an answer to look thorough. A correct two-line answer is the better answer.\n\n";

    $p .= "HOW YOU WORK\n"
        . "- You have read-only tools. Use them. Do not speculate about a log you have not read, "
        . "and do not describe what a setting 'probably' is when get_world will tell you.\n"
        . "- For an open-ended 'what is wrong' question, call get_diagnostics first — it is a fast "
        . "deterministic scan with evidence — then read logs to confirm or extend what it found.\n"
        . "- When diagnosing a world that will not start, read its log with since_last_start=true. "
        . "Errors from previous boots are noise.\n"
        . "- search_log covers the whole file; read_log only covers the tail. If the first tail does "
        . "not explain the failure, search rather than asking for a bigger tail.\n"
        . "- Ground every claim in something a tool returned. Quote the line.\n"
        . "- Answer as soon as you can support an answer. Do not gather everything that might be "
        . "relevant before replying: get_diagnostics plus the one log that explains the fault is "
        . "usually enough. You have a limited number of tool rounds, and spending them all on "
        . "breadth means the operator gets no answer at all.\n\n";

    $p .= "THIS IS A HEADLESS DEDICATED SERVER\n"
        . "Never report on fonts, shaders, graphics, rendering, cameras, DepthOfField, textures, "
        . "materials, meshes, sprites, screen resolution or any visual warning — none of it applies "
        . "and mentioning it buries the real fault. Ignore mod RPC registration chatter and the "
        . "createDirectory /root/.config error; both are normal.\n\n";

    $p .= "DOMAIN FACTS YOU MUST NOT GET WRONG\n"
        . "- Mods come from two catalogues, Thunderstore and Hexium. A mod's identity is "
        . "(source, owner, name) — never a UUID, which both catalogues reuse.\n"
        . "- worlds.public is the CITIZENS access-control flag. It is NOT Valheim's -public server-browser "
        . "argument; that is the separate 'listed' column. Do not conflate them.\n"
        . "- Valheim enforces permittedlist.txt only when it has entries. An empty enforced list means the "
        . "world is open to everyone, which is a security finding, not a healthy state.\n"
        . "- BepInEx is engine-installed on every modded world and is not a selectable mod.\n\n";

    $p .= "ANSWERING\n"
        . "- Lead with the answer. Supporting detail after.\n"
        . "- Be specific about the fix: name the mod, the setting, the tab in this UI, or the exact file.\n"
        . "- If the evidence does not support a conclusion, say so and say what you would need to look at.\n"
        . "- Use Markdown. Short bullets over paragraphs. Fenced code blocks for log excerpts and commands.\n"
        . "- You cannot change anything. Recommend actions for the operator to take; never claim to have "
        . "taken one.\n";

    // State the operator can see on their own screen, stated up front so the model does not
    // have to infer it from a tool call and cannot get it wrong. Without this, "all worlds
    // are down" was reported as a discovery rather than the ordinary fact it is.
    $rows    = aiWorldRows($pdo);
    $total   = count($rows);
    $running = 0;
    foreach ($rows as $r) if (aiTruthy($r, 'status')) $running++;

    $p .= "\nLIVE STATE (as of this moment)\n"
        . "- Worlds: $running running, " . ($total - $running) . " stopped, $total configured.\n";
    if ($total > 0 && $running === 0) {
        $p .= "- Every world is currently stopped. Treat this as the server's resting state, not a "
            . "fault, and do not open your answer by announcing it as a problem.\n";
    }

    // OPERATING PROCEDURES -- only when the model can actually act.
    //
    // These are assertions about THIS system, not general prompt-craft, which is why they
    // live beside the code they describe. Each one is a mistake that is easy to make, looks
    // reasonable in a transcript, and costs an operator real time or real data. A model
    // with the tool list but none of this pokes at things.
    if ($withActions) {
        $p .= "\nOPERATING PROCEDURES — HOW TO RUN THIS SERVER\n"
            . "- DIAGNOSE BEFORE ACTING. get_diagnostics is cheap and deterministic. Never propose a\n"
            . "  restart for a symptom you have not looked at; 'turn it off and on again' destroys the\n"
            . "  evidence and usually fixes nothing. A world that is merely stopped is not broken.\n"
            . "- A MOD CHANGE IS NOT LIVE UNTIL THE WORLD IS REBUILT. set_world_mods edits the plan;\n"
            . "  update_world applies it. If you propose the first without saying the second is needed,\n"
            . "  the operator will believe a change landed when it did not.\n"
            . "- NEVER INVENT A PLAYER ID. Not a SteamID, not a placeholder, not an example. If you were\n"
            . "  not given one, ask. And if a CITIZENS list would end up empty while access control is\n"
            . "  on, say plainly that this would leave the server OPEN TO EVERYONE — Valheim enforces\n"
            . "  permittedlist.txt only when it has entries — and stop.\n"
            . "- VANILLA AND MODDED WORLDS ARE CONFIGURED DIFFERENTLY. Modded worlds are gated by the\n"
            . "  CITIZENS list and run with -public 0; crossplay, server-browser listing and password\n"
            . "  do nothing on them. Vanilla worlds use those columns for real, and a LISTED vanilla\n"
            . "  world MUST have a password or Valheim refuses to start.\n"
            . "- worlds.public IS THE CITIZENS FLAG, NOT Valheim's -public. The server-browser flag is\n"
            . "  'listed'. Confusing the two is a one-word mistake that exposes a server.\n"
            . "- STOPPING OR REBUILDING DISCONNECTS PLAYERS. Say so. Check the world's recent player\n"
            . "  activity first and mention it if someone was on lately.\n"
            . "- PREFER THE NARROW TOOL. search_log over a 1200-line read_log; one world over all of\n"
            . "  them. Large dumps cost the operator money and bury the answer.\n"
            . "- PROPOSING IS NOT DOING. Anything consequential produces a confirmation card the\n"
            . "  operator must click. Say what you are proposing and why; never report it as done, and\n"
            . "  never claim you have already changed something.\n"
            . "- IF A TOOL REFUSES, BELIEVE IT. The refusal explains a rule of this system. Relay it;\n"
            . "  do not retry the same call hoping for a different answer, and do not work around it.\n";
    }

    if ($contextWorld !== '') {
        $p .= "\nCURRENT CONTEXT: the operator is looking at the world '$contextWorld' and every "
            . "unqualified question is about THAT world. Answer about '$contextWorld' alone. Do not "
            . "summarise the other worlds or the server's overall health unless explicitly asked.\n";
    } else {
        $p .= "\nCURRENT CONTEXT: no single world is selected — the question is about the server as a "
            . "whole. Keep per-world detail to a minimum.\n";
    }

    return $p;
}

/**
 * Remember what this ENDPOINT turned out to be capable of.
 *
 * Discovered by asking, never looked up. A table of "these models support tools" would be
 * the same mistake as 2.44's hardcoded model list and would rot at the same rate -- new
 * models appear weekly and gateways lie about what they proxy.
 *
 * Only ever records a POSITIVE result or an outright refusal. A turn where the model
 * simply had nothing to look up ("thanks!") proves nothing and must not demote a provider
 * that worked a minute ago.
 */
/**
 * Reduce an error message to a countable CLASS.
 *
 * Deliberately lossy. The message itself may name the operator's internal gateway, the
 * model they run, or a world -- none of which is ours to collect. What is useful in
 * aggregate is only the shape of the failure.
 */
function aiErrorClass($msg) {
    $m = strtolower((string)$msg);
    if (preg_match('/\b(http\s*)?(4\d\d|5\d\d)\b/', $m, $mm)) return 'http_' . $mm[2];
    if (strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false) return 'timeout';
    if (strpos($m, 'could not resolve') !== false || strpos($m, 'connect') !== false) return 'connect';
    if (strpos($m, 'api key') !== false || strpos($m, 'unauthor') !== false) return 'auth';
    if (strpos($m, 'no model') !== false) return 'no_model';
    if (strpos($m, 'rounds of tool calls') !== false) return 'round_cap';
    return 'other';
}

function aiRecordCapability($pdo, $provider, $res, $sawToolCall) {
    $id = (int)($provider['id'] ?? 0);
    if (!$id) return;

    $cap = '';
    if ($sawToolCall)                     $cap = 'tools';
    elseif (!empty($res['tools_dropped'])) $cap = 'text';
    if ($cap === '') return;

    if ((string)($provider['tool_capability'] ?? '') === $cap) return;  // nothing changed

    try {
        $pdo->prepare("UPDATE ai_providers SET tool_capability = ?, capability_checked = NOW() WHERE id = ?")
            ->execute([$cap, $id]);
        if (function_exists('aiLog')) aiLog('CAPABILITY', ($provider['label'] ?? '?') . " => $cap");
    } catch (Exception $e) {
        // Never let bookkeeping break a reply.
    }
}

/**
 * The agentic loop: call the model, run any tools it asked for, feed the results back,
 * repeat until it answers in prose.
 *
 * $maxRounds bounds it. A model that loops — and small local models do — must cost a
 * bounded number of round trips, not an unbounded one.
 */
function aiConverse($pdo, $provider, $messages, $contextWorld, $onDelta = null, $onTool = null, $maxRounds = 10) {
    // Actions are offered only to an endpoint already known to drive tools. A provider
    // recorded as 'text' or 'inert' gets the read-only surface: handing a catalogue of
    // state-changing tools to a model that cannot reliably call them invites confident
    // claims that a world was restarted when nothing happened.
    $cap        = (string)($provider['tool_capability'] ?? '');
    $canAct     = !in_array($cap, ['text', 'inert'], true);
    $tools      = aiToolDefinitions($canAct);
    // The prompt and the tool list must agree. Describing how to sequence a rebuild to a
    // model that was handed no rebuild tool produces a confident narration of work that
    // never happened.
    $system     = aiSystemPrompt($pdo, $contextWorld, $canAct);
    $trace      = [];
    $sawToolCall = false;

    require_once __DIR__ . '/aiactions.php';
    aiUsageBump($pdo, 'chats');

    for ($round = 0; $round < $maxRounds; $round++) {
        $res = aiChat($provider, $messages, $system, $tools, $onDelta);

        if (!$res['success']) {
            // A CLASS, never the message. An error string routinely carries a URL, a model
            // id or a world name, and this counter is the source for what leaves the box.
            aiUsageBump($pdo, 'error', aiErrorClass($res['error'] ?? ''));
            return ['success' => false, 'error' => $res['error'], 'trace' => $trace];
        }

        if (empty($res['tool_calls'])) {
            aiRecordCapability($pdo, $provider, $res, $sawToolCall);
            // Histogram bucket, not a per-conversation row: a median is derivable from
            // counts alone, and storing one row per conversation would be a usage log.
            aiUsageBump($pdo, 'rounds', (string)$round);
            return [
                'success'   => true,
                'content'   => $res['content'],
                // Degraded answers must be LABELLED. A Hugin that looks identical whether
                // or not it could actually inspect anything is how an operator comes to
                // trust something the model invented.
                'degraded'  => !empty($res['tools_dropped']) ? 'text' : '',
                'usage'     => $res['usage'] ?? null,
                'model'     => $res['model'] ?? ($provider['model'] ?? ''),
                'trace'     => $trace,
                // Any confirm-cards raised this turn, carrying their tokens. Read
                // and cleared here so a token belongs to exactly one reply.
                'proposals' => function_exists('aiProposalsCollect') ? aiProposalsCollect() : [],
            ];
        }

        $messages[] = [
            'role'       => 'assistant',
            'content'    => $res['content'],
            'tool_calls' => $res['tool_calls'],
            // Opaque, provider-specific echo of the turn. Gemini 3 requires its
            // thoughtSignature back on functionCall parts or the next round trip is
            // rejected outright; adapters that do not set it simply see null here.
            // Never inspect or rewrite this -- pass it back exactly as received.
            'provider_raw' => $res['provider_raw'] ?? null,
        ];

        $sawToolCall = true;

        foreach ($res['tool_calls'] as $call) {
            if ($onTool) $onTool($call['name'], $call['arguments']);

            $result = aiRunTool($pdo, $call['name'], is_array($call['arguments']) ? $call['arguments'] : []);

            // A single tool result must not blow the context window. Truncating here with
            // an explicit marker is better than letting the provider reject the whole
            // request for length, which surfaces as an opaque 400.
            if (strlen($result) > 60000) {
                $result = substr($result, 0, 60000) . "\n\n[...truncated. Use search_log to narrow this down.]";
            }

            $trace[] = [
                'tool'    => $call['name'],
                'args'    => $call['arguments'],
                'preview' => substr($result, 0, 240),
            ];

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'name'         => $call['name'],
                'content'      => $result,
            ];
        }
    }

    // Out of rounds. Do NOT throw the investigation away.
    //
    // Measured against live Gemini 3.5 on a two-world server: an open "full health check"
    // produced TWELVE tool calls -- two per round, because the model investigates in
    // parallel -- and the old code hit the cap and returned an error blaming the model
    // ("try a stronger model"). The model had done the work; only the answer was missing.
    // Discarding a dozen successful tool results and showing a failure is the worst
    // possible outcome for the operator, and the advice was wrong as well: the cap was
    // ours.
    //
    // So make one final call with NO tools. The model cannot ask for anything else, and
    // has to answer from what it has already gathered.
    $messages[] = [
        'role'    => 'user',
        'content' => 'Stop investigating and answer now, using only what you have already '
                   . 'gathered above. If something is still unverified, say so explicitly '
                   . 'rather than calling another tool.',
    ];

    $final = aiChat($provider, $messages, $system, [], $onDelta);

    if ($final['success'] && trim($final['content']) !== '') {
        return [
            'success'  => true,
            'content'  => $final['content'],
            'usage'    => $final['usage'] ?? null,
            'model'    => $final['model'] ?? ($provider['model'] ?? ''),
            'trace'    => $trace,
            'proposals' => function_exists('aiProposalsCollect') ? aiProposalsCollect() : [],
            // The UI can note that the answer came from a forced wrap-up rather than the
            // model deciding it was finished.
            'capped'   => true,
        ];
    }

    return [
        'success' => false,
        'error'   => "The model made $maxRounds rounds of tool calls and could not produce an answer"
                   . ($final['error'] ? ': ' . $final['error'] : '. Try a narrower question.'),
        'trace'   => $trace,
    ];
}
