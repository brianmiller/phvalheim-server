<?php
/**
 * Hugin's action layer (2.45).
 *
 * 2.45 gave Hugin ten read-only tools. This file lets it change things -- and the entire
 * design exists to answer one question: what happens when the model is wrong?
 *
 * We do not control the model. The operator brings their own: it may be a frontier model
 * or a 7B quant on a laptop that invents world names. So the rule here is that the safety
 * of an action must never depend on the quality of the model.
 *
 * Two tiers:
 *
 *   SAFE          additive or trivially reversible. Executed on the spot.
 *   CONSEQUENTIAL stops a service, changes config, or destroys data. NOT executed.
 *                 A validated, server-authored plan is written to ai_proposals and the
 *                 operator gets a confirm card. Apply posts back nothing but a token.
 *
 * That inversion is the whole point. If Apply posted parameters, the blast radius of a
 * hallucination (or a crafted client) would be everything the admin API can do. Posting
 * an opaque token means the worst a bad proposal achieves is a card someone reads and
 * dismisses.
 *
 * See dev_tools/DESIGN-2.45-hugin-agentic.md.
 */

require_once __DIR__ . '/aicontext.php';

if (!defined('AI_PROPOSAL_TTL')) define('AI_PROPOSAL_TTL', 900); // 15 minutes

/**
 * aiLog() lives in aiproviders.php, which this file does NOT require -- the include graph
 * runs one way and pulling the provider layer in here would invert it.
 *
 * So log through a guard. 2.45 shipped a fatal of exactly this shape: aicontext.php called
 * aiTruthy() before that function had been moved next to it, every chat died, and the
 * symptom was an empty grey bubble rather than an error. A logging call is never worth a
 * fatal.
 */
function aiActionLog($event, $detail) {
    if (function_exists('aiLog')) aiLog($event, $detail);
}

/* ====================================================================================
 * Telemetry counters
 * ==================================================================================== */

/**
 * Bump one usage counter. Counters only -- never text.
 *
 * Every call site passes a CLASS, never a message: 'http_400', not the body of the error.
 * An error string routinely contains a URL, a model id or a world name, and this table is
 * the source for what gets sent to analytics. Keeping it uncountable-by-construction is
 * cheaper than sanitising on the way out.
 *
 * Failure here is swallowed deliberately: telemetry must never be able to break a chat.
 */
function aiUsageBump($pdo, $metric, $subkey = '', $n = 1) {
    try {
        $sth = $pdo->prepare(
            "INSERT INTO ai_usage (metric, subkey, day, count) VALUES (?, ?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE count = count + VALUES(count)");
        $sth->execute([substr((string)$metric, 0, 32), substr((string)$subkey, 0, 64), max(1, (int)$n)]);
    } catch (Exception $e) {
        // Intentionally silent.
    }
}

/* ====================================================================================
 * Calling the admin UI's own handlers
 * ==================================================================================== */

/**
 * Run one of adminAPI.php's *Json() handlers and return its decoded reply.
 *
 * Those handlers echo JSON rather than returning it, so this captures the buffer. That is
 * deliberate, and it is NOT a workaround to be tidied away later: calling the exact
 * function the admin UI calls is what guarantees Hugin inherits every guard that function
 * carries. Re-implementing the write in this file would bypass them all while the UI
 * carried on looking correct.
 *
 * The guard that matters most:
 *
 *   Valheim enforces permittedlist.txt ONLY when it has entries, so an enforced-but-empty
 *   list is a WIDE OPEN server whose Access tab claims otherwise. saveCitizensJson()
 *   refuses to write one, and its own comment says that refusal "must not be relaxed to a
 *   confirmation". A Hugin path with its own SQL would have quietly relaxed it to nothing
 *   at all.
 *
 * Verified safe to buffer: none of the wrapped handlers call exit() or die(), so the
 * buffer always closes. aiActionApply() is the only caller and it is never inside another
 * output buffer.
 */
function aiCallJsonHandler(callable $fn) {
    ob_start();
    try {
        $fn();
    } catch (Exception $e) {
        ob_end_clean();
        return ['success' => false, 'error' => $e->getMessage()];
    }
    $raw = ob_get_clean();

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'The handler returned something that was not JSON.'];
    }
    // The handlers are inconsistent: some return {success:true}, some only {error:...}.
    // Normalise so callers do not have to know which is which.
    if (!isset($decoded['success'])) {
        $decoded['success'] = empty($decoded['error']);
    }
    return $decoded;
}

/* ====================================================================================
 * The catalogue
 * ==================================================================================== */

/**
 * Every action Hugin can take.
 *
 * 'tier'    'safe' runs immediately; 'confirm' writes a proposal.
 * 'typed'   true = the operator must type the world name into the card. Reserved for the
 *           irreversible ones. Not because the model might be wrong -- because the
 *           OPERATOR might be, and Hugin puts destructive intent one sentence away.
 * 'summary' renders the confirm card from the VALIDATED parameters, not from the model's
 *           description of them. When the two disagree, that is exactly the moment the
 *           operator needs to see the truth.
 */
function aiActionCatalogue() {
    $str  = function ($d) { return ['type' => 'string',  'description' => $d]; };
    $bool = function ($d) { return ['type' => 'boolean', 'description' => $d]; };

    return [
        'start_world' => [
            'tier'  => 'safe',
            'world' => true,
            'desc'  => 'Start a stopped world. Safe and immediate.',
            'props' => [],
            'summary' => function ($p) { return "Start the world '{$p['world']}'."; },
            'run'   => function ($pdo, $p) {
                startWorld($pdo, $p['world']);
                return ['success' => true, 'message' => "Asked the engine to start '{$p['world']}'. It picks the change up within a couple of seconds."];
            },
        ],

        'stop_world' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Stop a running world. This DISCONNECTS every player currently on it.',
            'props' => [],
            'summary' => function ($p) {
                return "Stop the world '{$p['world']}'. Anyone currently playing on it will be disconnected.";
            },
            'run'   => function ($pdo, $p) {
                stopWorld($pdo, $p['world']);
                return ['success' => true, 'message' => "Asked the engine to stop '{$p['world']}'."];
            },
        ],

        'restart_world' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Stop and then start a world. Disconnects players. Use this after a configuration change that needs a restart, not as a blind fix for an unexamined symptom.',
            'props' => [],
            'summary' => function ($p) {
                return "Restart the world '{$p['world']}'. Players are disconnected and the world is unavailable for roughly a minute.";
            },
            'run'   => function ($pdo, $p) {
                // The engine loop is a state machine on worlds.mode, so 'stop' then 'start'
                // in the same tick would be a lost update -- the second write simply
                // overwrites the first and the world never stops. Ask for the stop and let
                // the caller's follow-up handle the start.
                stopWorld($pdo, $p['world']);
                return ['success' => true, 'restart' => true,
                        'message' => "Stopping '{$p['world']}' now; it will be started again once it has fully stopped."];
            },
        ],

        'update_world' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Rebuild a world: re-run the Valheim/BepInEx install and apply its current mod plan. This is what makes a mod change take effect. The world is down for several minutes.',
            'props' => [],
            'summary' => function ($p) {
                return "Rebuild the world '{$p['world']}'. It will be stopped, its mods reinstalled from the current plan, and started again. Expect several minutes of downtime.";
            },
            'run'   => function ($pdo, $p) {
                updateWorld($pdo, $p['world']);
                return ['success' => true, 'message' => "Queued a rebuild of '{$p['world']}'. Watch its log for progress."];
            },
        ],

        'delete_world' => [
            'tier'  => 'confirm',
            'typed' => true,
            'world' => true,
            'desc'  => 'Permanently delete a world, its save data and its backups. Irreversible.',
            'props' => [],
            'summary' => function ($p) {
                return "PERMANENTLY DELETE the world '{$p['world']}', including its save data and its backups. This cannot be undone.";
            },
            'run'   => function ($pdo, $p) {
                deleteWorld($pdo, $p['world']);
                return ['success' => true, 'message' => "Queued deletion of '{$p['world']}'."];
            },
        ],

        'set_world_options' => [
            'tier'  => 'confirm',
            'world' => true,
            // Lists exactly the props below and nothing else. An over-generous description
            // is how a model ends up calling a tool with an argument it does not accept --
            // and this same string is what the operator reads on the capability card.
            'desc'  => 'Change a world\'s settings: crossplay, server-browser listing, password, auto-start or launch parameters. Only pass the fields you intend to change. Use set_world_access to change who may join.',
            'props' => [
                'crossplay'     => $bool('Enable Valheim crossplay (PlayFab). VANILLA WORLDS ONLY.'),
                'listed'        => $bool('List the server in the public server browser. VANILLA WORLDS ONLY, and a listed world must also have a password or Valheim refuses to start.'),
                'password'      => $str('Server password. VANILLA WORLDS ONLY — modded worlds are gated by the CITIZENS list instead. Empty string clears it.'),
                'autostart'     => $bool('Start this world automatically when the container boots.'),
                'launch_params' => $str('Extra launch parameters passed to the Valheim server.'),
            ],
            'summary' => function ($p) {
                $bits = [];
                foreach ($p['changes'] as $k => $v) {
                    $bits[] = "$k: " . $v['from'] . ' → ' . $v['to'];
                }
                return "Change settings on '{$p['world']}' — " . implode('; ', $bits)
                     . ". Some of these only take effect the next time the world starts.";
            },
            'run'   => function ($pdo, $p) {
                $res = ['success' => true, 'message' => ''];
                if (!empty($p['input'])) {
                    $res = aiCallJsonHandler(function () use ($pdo, $p) {
                        saveWorldOptionsJson($pdo, $p['world'], $p['input']);
                    });
                }
                // autostart is NOT part of saveWorldOptionsJson's contract -- it has its
                // own setter. Passing it in $input would have been silently dropped.
                if (!empty($res['success']) && array_key_exists('autostart', $p)) {
                    setAutoStart($pdo, $p['world'], $p['autostart']);
                }
                return $res;
            },
        ],

        'set_world_access' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Replace a world\'s CITIZENS, ADMINS or BANNED list. You must be given the player IDs explicitly — never invent, guess or pad one.',
            'props' => [
                'list'    => ['type' => 'string', 'enum' => ['citizens', 'admins', 'banned'],
                              'description' => 'Which list to write.'],
                'ids'     => $str('Newline- or comma-separated player IDs, in V_ form or bare SteamID64. This REPLACES the list; include the existing entries you want to keep.'),
                'enforce' => $bool('Citizens only: whether the access list is enforced.'),
            ],
            'summary' => function ($p) {
                $n = $p['id_count'];
                return "Replace the " . strtoupper($p['list']) . " list on '{$p['world']}' with $n "
                     . ($n === 1 ? 'entry' : 'entries') . ".";
            },
            'run'   => function ($pdo, $p) {
                return aiCallJsonHandler(function () use ($pdo, $p) {
                    switch ($p['list']) {
                        case 'citizens': saveCitizensJson($pdo, $p['world'], $p['ids'], $p['enforce']); break;
                        case 'admins':   saveAdminsJson($pdo, $p['world'], $p['ids']); break;
                        case 'banned':   saveBannedJson($pdo, $p['world'], $p['ids']); break;
                    }
                });
            },
        ],

        'create_backup' => [
            'tier'  => 'safe',
            'world' => true,
            'desc'  => 'Take a manual backup of a world now. Safe: it only adds a new backup and never touches the live save.',
            'props' => [
                'compression' => ['type' => 'string', 'enum' => ['none', 'gzip', 'zstd'],
                                  'description' => 'Compression to use. Omit for the server default.'],
            ],
            'summary' => function ($p) { return "Back up the world '{$p['world']}' now."; },
            'run'   => function ($pdo, $p) {
                $res = startManualBackupJob($p['world'], $p['compression'] ?? '');
                if (!empty($res['success'])) {
                    $res['message'] = "Backup of '{$p['world']}' started. It runs in the background — "
                                    . "watch the Backups panel for progress.";
                }
                return $res;
            },
        ],

        'restore_backup' => [
            'tier'  => 'confirm',
            'typed' => true,
            'world' => true,
            'desc'  => 'Restore a world from one of its backups. This OVERWRITES the current save data with the backup\'s contents. Use get_backup_status first to find the backup id.',
            'props' => [
                'backup_id' => ['type' => 'integer', 'description' => 'The id of the backup to restore, from get_backup_status.'],
            ],
            'summary' => function ($p) {
                return "RESTORE the world '{$p['world']}' from backup #{$p['backup_id']}"
                     . ($p['backup_when'] ? " taken {$p['backup_when']}" : '')
                     . ". This OVERWRITES the current save — anything that has happened in the world since "
                     . "that backup will be lost.";
            },
            'run'   => function ($pdo, $p) {
                $res = startRestoreBackupJob($p['backup_id']);
                if (!empty($res['success'])) {
                    $res['message'] = "Restore of '{$p['world']}' from backup #{$p['backup_id']} started.";
                }
                return $res;
            },
        ],

        'set_world_backup_policy' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Change how often a world is backed up and how many backups are kept.',
            // Named for the columns that actually exist. Retention here is TIERED
            // (all / daily / weekly / monthly), so there is no single "how many to keep"
            // number to offer, and inventing one would mean the card promised something
            // the saver cannot do.
            'props' => [
                'interval_hours'   => ['type' => 'integer', 'description' => 'Hours between automatic backups.'],
                'retain_all_hours' => ['type' => 'integer', 'description' => 'Keep every backup taken within this many hours.'],
                'use_global'       => ['type' => 'boolean', 'description' => 'Follow the server-wide backup policy instead of this world\'s own.'],
            ],
            'summary' => function ($p) {
                $bits = [];
                foreach ($p['changes'] as $k => $v) $bits[] = "$k: " . $v['from'] . ' → ' . $v['to'];
                return "Change the backup policy for '{$p['world']}' — " . implode('; ', $bits) . '.';
            },
            'run'   => function ($pdo, $p) {
                $okSave = saveWorldBackupSettings($pdo, $p['world'], $p['settings']);
                return $okSave
                    ? ['success' => true, 'message' => "Backup policy updated for '{$p['world']}'."]
                    : ['success' => false, 'error' => 'The backup settings could not be saved.'];
            },
        ],

        'set_world_mods' => [
            'tier'  => 'confirm',
            'world' => true,
            'desc'  => 'Replace the set of mods selected for a world. Takes mod ids from get_world_mods or the catalogue. IMPORTANT: this only changes the PLAN — the world must then be rebuilt with update_world before the change reaches players.',
            'props' => [
                'mod_ids' => ['type' => 'array', 'items' => ['type' => 'integer'],
                              'description' => 'The complete set of mod ids the world should have. This REPLACES the current selection, so include everything you want kept.'],
            ],
            'summary' => function ($p) {
                return "Set the mod list for '{$p['world']}' to {$p['mod_count']} "
                     . ($p['mod_count'] === 1 ? 'mod' : 'mods')
                     . " (currently {$p['mod_before']}). Dependencies are resolved automatically. "
                     . "The world must be REBUILT afterwards for this to take effect.";
            },
            'run'   => function ($pdo, $p) {
                return aiCallJsonHandler(function () use ($pdo, $p) {
                    saveWorldModsJson($pdo, $p['world'], $p['mods'], '', 0, 0, 0);
                });
            },
        ],

        'set_server_settings' => [
            'tier'  => 'confirm',
            'world' => false,
            'desc'  => 'Change server-wide settings such as backup retention, mod sync interval or analytics. Only pass the fields you intend to change.',
            'props' => [
                'backupsToKeep'        => ['type' => 'integer', 'description' => 'How many backups to retain per world.'],
                'modSyncIntervalHours' => ['type' => 'integer', 'description' => 'Hours between mod catalogue syncs.'],
                'analyticsEnabled'     => $bool('Send anonymous usage analytics.'),
            ],
            'summary' => function ($p) {
                $bits = [];
                foreach ($p['changes'] as $k => $v) $bits[] = "$k: " . $v['from'] . ' → ' . $v['to'];
                return 'Change server settings — ' . implode('; ', $bits) . '.';
            },
            'run'   => function ($pdo, $p) {
                // This one already has an _internal variant that returns an array.
                return saveServerSettingsJson_internal($pdo, $p['input']);
            },
        ],
    ];
}

/**
 * Tool schemas for the actions, merged into the read-only ones by aiToolDefinitions().
 *
 * The description carries the CONSEQUENCE, in the operator's words. A model writes better
 * proposals when the schema is honest about cost, and that text is what the operator falls
 * back on when the model summarises badly.
 */
function aiActionToolDefinitions() {
    $out = [];
    foreach (aiActionCatalogue() as $name => $a) {
        $props = $a['props'];
        $req   = [];
        if (!empty($a['world'])) {
            $props = ['world' => ['type' => 'string', 'description' => 'Exact world name.']] + $props;
            $req[] = 'world';
        }
        if ($name === 'set_world_access') $req[] = 'list';

        $desc = $a['desc'];
        if ($a['tier'] === 'confirm') {
            $desc .= ' This needs the operator to confirm, so calling it shows them a card'
                   . ' describing the change — it does not happen straight away.';
        }

        $out[] = [
            'name'        => $name,
            'description' => $desc,
            'parameters'  => [
                'type'       => 'object',
                'properties' => $props ?: (object)[],
                'required'   => $req,
            ],
        ];
    }
    return $out;
}

/* ====================================================================================
 * Validation
 * ==================================================================================== */

/**
 * Turn the model's raw arguments into a validated plan, or an error.
 *
 * This is where a hallucination dies. Every world name is checked against the worlds
 * table, every enum against its allowed set, every flag coerced. Nothing downstream ever
 * sees a string the model invented.
 */
function aiActionValidate($pdo, $name, $args) {
    $cat = aiActionCatalogue();
    if (!isset($cat[$name])) return ['error' => "Unknown action '$name'."];
    $a = $cat[$name];
    $p = ['action' => $name];

    if (!empty($a['world'])) {
        $world = trim((string)($args['world'] ?? ''));
        if ($world === '') return ['error' => 'Which world? No world name was given.'];

        $sth = $pdo->prepare("SELECT * FROM worlds WHERE name = ?");
        $sth->execute([$world]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Name the real ones. A model that guessed can correct itself; an operator
            // reading the transcript sees immediately that it guessed.
            $have = array_column(aiWorldRows($pdo), 'name');
            return ['error' => "There is no world called '$world'. Worlds on this server: "
                             . ($have ? implode(', ', $have) : '(none)') . '.'];
        }
        $p['world'] = $world;
        $p['row']   = $row;
    }

    switch ($name) {
        case 'start_world':
            if (aiWorldIsRunning($p['row'])) return ['error' => "'{$p['world']}' is already running."];
            break;

        case 'stop_world':
        case 'restart_world':
            // Read `mode`, not `status`. On the real box `status` is "Down" for every world
            // including the running ones, so this guard refused EVERY stop and restart with
            // "already stopped" -- the operator could not turn anything off through Hugin.
            if (!aiWorldIsRunning($p['row'])) return ['error' => "'{$p['world']}' is already stopped."];
            break;

        case 'set_world_options': {
            $vanilla = !empty($p['row']['vanilla']);

            // Crossplay, listing and password are VANILLA-ONLY. saveWorldOptionsJson
            // silently forces all three to 0/'' on a modded world -- deliberately: a modded
            // crossplay world opens a PlayFab server with no host:port, and the PhValheim
            // client reaches modded worlds through QuickConnect, which is host:port. So the
            // world would be unreachable by the client.
            //
            // Silent for the admin UI (which hides the fields) is fine. Silent for Hugin is
            // NOT: the model would propose "enable crossplay", the card would say 0 → 1, the
            // operator would confirm, and nothing would change. A confident lie is the worst
            // outcome this design can produce, so refuse it up front with the reason.
            if (!$vanilla) {
                $blocked = array_intersect(['crossplay', 'listed', 'password'], array_keys($args));
                if ($blocked) {
                    return ['error' => "'{$p['world']}' is a MODDED world, and " . implode('/', $blocked)
                          . ' apply only to vanilla worlds — modded worlds are gated by the CITIZENS'
                          . ' access list instead, and crossplay would make the world unreachable by'
                          . ' the PhValheim client. Use set_world_access for a modded world.'];
                }
            }

            // saveWorldOptionsJson is a FULL REPLACE, not a patch: every key it does not
            // find defaults to 0 or ''. Sending only the changed field would therefore blank
            // the password, drop the launch parameters, unlist the world -- and set
            // vanilla=0, quietly converting a vanilla world to modded.
            //
            // So start from the world's CURRENT values, in that function's own key names,
            // and overlay only what changed.
            $input = [
                'world'          => $p['world'],
                'vanilla'        => (int)($p['row']['vanilla'] ?? 0),
                'crossplay'      => (int)($p['row']['crossplay'] ?? 0),
                'listed'         => (int)($p['row']['listed'] ?? 0),
                'passwordPublic' => (int)($p['row']['password_public'] ?? 1),
                'password'       => (string)($p['row']['password'] ?? ''),
                'launchParams'   => (string)($p['row']['launch_params'] ?? ''),
            ];

            // tool argument => the key saveWorldOptionsJson actually reads
            $map = [
                'crossplay'     => 'crossplay',
                'listed'        => 'listed',
                'password'      => 'password',
                'launch_params' => 'launchParams',
            ];
            $changes = [];
            foreach ($map as $arg => $key) {
                if (!array_key_exists($arg, $args)) continue;
                $new = in_array($arg, ['crossplay', 'listed'], true)
                     ? (aiBoolArg($args[$arg]) ? 1 : 0)
                     : (string)$args[$arg];
                $old = $input[$key];
                if ((string)$old === (string)$new) continue;
                $changes[$arg] = [
                    'from' => $arg === 'password' ? ($old === '' ? '(none)' : '(set)') : (string)$old,
                    'to'   => $arg === 'password' ? ($new === '' ? '(none)' : '(set)') : (string)$new,
                ];
                $input[$key] = $new;
            }

            // autostart has its own setter and is not part of that contract.
            if (array_key_exists('autostart', $args)) {
                $new = aiBoolArg($args['autostart']) ? 1 : 0;
                $old = (int)($p['row']['autostart'] ?? 0);
                if ($old !== $new) {
                    $changes['autostart'] = ['from' => (string)$old, 'to' => (string)$new];
                    $p['autostart'] = $new;
                }
            }

            if (!$changes) return ['error' => 'Those settings are already what you asked for — nothing to change.'];

            // Valheim refuses to start a listed world with no password: "password is too
            // short". Catching it here means the operator never applies a change that
            // bricks the next start.
            $vanilla  = !empty($p['row']['vanilla']);
            $listed   = array_key_exists('listed', $input) ? $input['listed'] : ($p['row']['listed'] ?? 0);
            $password = array_key_exists('password', $input) ? $input['password'] : ($p['row']['password'] ?? '');
            if ($vanilla && $listed && trim((string)$password) === '') {
                return ['error' => "A listed vanilla world must have a password — Valheim refuses to start without one. Set a password in the same change, or leave the world unlisted."];
            }

            $p['changes'] = $changes;
            $p['input']   = $input;
            break;
        }

        case 'set_world_access': {
            $list = strtolower(trim((string)($args['list'] ?? '')));
            if (!in_array($list, ['citizens', 'admins', 'banned'], true)) {
                return ['error' => "Which list? Must be one of citizens, admins or banned."];
            }
            $ids = trim((string)($args['ids'] ?? ''));

            // Never write an entry the operator did not supply. If the model produced no
            // ids at all, that is not "clear the list" -- for citizens it would mean an
            // enforced-but-empty list, which is a WIDE OPEN server. saveCitizensJson would
            // refuse it anyway; refusing here gives a better message and never writes a
            // proposal the operator might confirm expecting the opposite.
            if ($ids === '') {
                return ['error' => "No player IDs were given. I won't write an empty access list — "
                                 . ($list === 'citizens'
                                    ? 'an enforced but empty CITIZENS list lets everyone in rather than nobody.'
                                    : 'say explicitly which IDs should be on it.')];
            }

            $p['list']     = $list;
            $p['ids']      = $ids;
            $p['enforce']  = array_key_exists('enforce', $args)
                             ? (aiBoolArg($args['enforce']) ? 1 : 0)
                             : (int)($p['row']['public'] ?? 0);
            $p['id_count'] = count(preg_split('/[\s,]+/', $ids, -1, PREG_SPLIT_NO_EMPTY));
            break;
        }

        case 'create_backup': {
            $c = strtolower(trim((string)($args['compression'] ?? '')));
            if ($c !== '' && !in_array($c, ['none', 'gzip', 'zstd'], true)) {
                return ['error' => "Compression must be none, gzip or zstd (got '$c')."];
            }
            $p['compression'] = $c;
            break;
        }

        case 'restore_backup': {
            $id = (int)($args['backup_id'] ?? 0);
            if ($id <= 0) return ['error' => 'Which backup? Use get_backup_status to find its id.'];

            // The backup must belong to THIS world. Without this check a transposed or
            // invented id would restore one world's save over another's -- the single most
            // destructive thing in the catalogue, from a one-digit mistake.
            $sth = $pdo->prepare("SELECT id, world_name, created_at FROM backups WHERE id = ?");
            $sth->execute([$id]);
            $b = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$b) return ['error' => "There is no backup with id $id."];
            if ((string)$b['world_name'] !== $p['world']) {
                return ['error' => "Backup #$id belongs to '{$b['world_name']}', not '{$p['world']}'. "
                                 . "I will not restore one world's save over another."];
            }
            $p['backup_id']   = $id;
            $p['backup_when'] = (string)($b['created_at'] ?? '');
            break;
        }

        case 'set_world_backup_policy': {
            $map = [
                'interval_hours'   => 'backup_interval_minutes',
                'retain_all_hours' => 'backup_retain_all_hours',
                'use_global'       => 'backup_use_global',
            ];
            $changes = []; $settings = [];
            foreach ($map as $arg => $col) {
                if (!array_key_exists($arg, $args)) continue;
                if ($arg === 'use_global')      $new = aiBoolArg($args[$arg]) ? 1 : 0;
                elseif ($arg === 'interval_hours') $new = max(1, (int)$args[$arg]) * 60;  // stored in MINUTES
                else                            $new = max(0, (int)$args[$arg]);

                $old = (int)($p['row'][$col] ?? 0);
                if ($old === $new) continue;
                // Show hours on the card because that is what was asked for; store minutes
                // because that is what the column holds.
                $shown = $arg === 'interval_hours' ? [$old / 60 . 'h', $new / 60 . 'h'] : [(string)$old, (string)$new];
                $changes[$arg]  = ['from' => $shown[0], 'to' => $shown[1]];
                $settings[$col] = $new;
            }
            if (!$changes) return ['error' => 'The backup policy already has those values.'];
            $p['changes']  = $changes;
            // saveWorldBackupSettings only writes the keys it is given, so a partial set is
            // safe here -- unlike saveWorldOptionsJson, which is a full replace.
            $p['settings'] = $settings;
            break;
        }

        case 'set_world_mods': {
            $ids = $args['mod_ids'] ?? null;
            if (!is_array($ids)) return ['error' => 'Give me the complete list of mod ids the world should have.'];

            $clean = [];
            foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $clean[$i] = true; }
            $clean = array_keys($clean);
            if (!$clean) {
                return ['error' => 'That would remove every mod from the world. If that is really the '
                                 . 'intention, say so explicitly and I will set it out as its own change.'];
            }

            // Every id must exist. A hallucinated id would otherwise be dropped silently by
            // the saver and the operator would confirm a mod list that is quietly shorter
            // than the card claimed.
            $in  = implode(',', array_fill(0, count($clean), '?'));
            $sth = $pdo->prepare("SELECT id FROM mods WHERE id IN ($in)");
            $sth->execute($clean);
            $found   = array_map('intval', array_column($sth->fetchAll(PDO::FETCH_ASSOC), 'id'));
            $unknown = array_diff($clean, $found);
            if ($unknown) {
                return ['error' => 'No mod in the catalogue has id ' . implode(', ', $unknown)
                                 . '. Use get_world_mods or the Mods tab to find the real ids.'];
            }

            $before = 0;
            try {
                $s2 = $pdo->prepare("SELECT COUNT(*) FROM world_mods wm JOIN worlds w ON w.id = wm.world_id WHERE w.name = ?");
                $s2->execute([$p['world']]);
                $before = (int)$s2->fetchColumn();
            } catch (Exception $e) { /* count is cosmetic */ }

            // saveWorldModSelection takes [{id, pin}]; pin null means follow latest.
            $p['mods']       = array_map(function ($i) { return ['id' => $i, 'pin' => null]; }, $clean);
            $p['mod_count']  = count($clean);
            $p['mod_before'] = $before;
            break;
        }

        case 'set_server_settings': {
            $allowed = ['backupsToKeep', 'modSyncIntervalHours', 'analyticsEnabled'];
            $cur = [];
            try {
                $cur = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) { $cur = []; }

            $changes = []; $input = [];
            foreach ($allowed as $k) {
                if (!array_key_exists($k, $args)) continue;
                $new = $k === 'analyticsEnabled' ? (aiBoolArg($args[$k]) ? 1 : 0) : (int)$args[$k];
                $old = $cur[$k] ?? '';
                if ((string)$old === (string)$new) continue;
                $changes[$k] = ['from' => (string)$old, 'to' => (string)$new];
                $input[$k]   = $new;
            }
            if (!$changes) return ['error' => 'Those settings already have those values.'];

            // saveServerSettingsJson_internal writes what it is given, so the rest of the
            // settings row must be carried through unchanged or this becomes a silent reset.
            $p['changes'] = $changes;
            $p['input']   = array_merge($cur, $input);
            break;
        }
    }

    unset($p['row']); // not stored: it is a snapshot, and apply-time re-validation refetches
    return $p;
}

/**
 * Coerce a model's idea of a boolean.
 *
 * Models emit true, "true", 1, "1", "yes", "on" and "enabled" interchangeably, and which
 * one you get varies by provider and by day. json_decode gives us whatever was on the
 * wire, so normalise rather than trusting the type.
 */
function aiBoolArg($v) {
    if (is_bool($v)) return $v;
    if (is_int($v))  return $v !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

/* ====================================================================================
 * Propose / apply
 * ==================================================================================== */

/**
 * Proposals raised during the current turn, collected for the UI.
 *
 * The token travels HERE rather than in the tool result, so the model never sees it. Not
 * because the model could use one -- it has no way to make a request -- but because
 * anything in a tool result can end up quoted in the reply, and a confirmation token
 * printed into the chat transcript is a confirmation token in the browser history, in a
 * screenshot, and in whatever the operator pastes into a bug report.
 *
 * Call with no argument to read and clear.
 */
function aiProposalsCollect($p = null) {
    static $pending = [];
    if ($p === null) { $out = $pending; $pending = []; return $out; }
    $pending[] = $p;
    return $pending;
}

/**
 * Called from the tool dispatcher. Safe actions run; consequential ones become a card.
 *
 * The string returned here goes back to the MODEL, so it says what happened in terms the
 * model should relay -- including, for a proposal, that nothing has happened yet. A model
 * that thinks it already stopped the world will tell the operator so.
 */
function aiActionRun($pdo, $name, $args) {
    $cat = aiActionCatalogue();
    if (!isset($cat[$name])) return "Unknown action '$name'.";
    $a = $cat[$name];

    $p = aiActionValidate($pdo, $name, $args);
    if (isset($p['error'])) {
        aiUsageBump($pdo, 'action_rejected', $name);
        return 'Cannot do that: ' . $p['error'];
    }

    $summary = ($a['summary'])($p);

    if ($a['tier'] === 'safe') {
        aiUsageBump($pdo, 'action_applied', $name);
        $res = ($a['run'])($pdo, $p);
        aiActionLog($res['success'] ? 'ACTION' : 'ACTION_FAIL', "$name " . ($p['world'] ?? ''));
        return json_encode([
            'done'    => !empty($res['success']),
            'summary' => $summary,
            'message' => $res['message'] ?? ($res['error'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
    }

    // Consequential: write the plan, hand back a token.
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $sth = $pdo->prepare(
        "INSERT INTO ai_proposals (token, action, world, params_json, summary, typed_name, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
    $sth->execute([
        $token, $name, $p['world'] ?? '',
        json_encode($p, JSON_UNESCAPED_SLASHES), $summary,
        !empty($a['typed']) ? ($p['world'] ?? '') : '',
        AI_PROPOSAL_TTL,
    ]);

    aiUsageBump($pdo, 'action_proposed', $name);
    aiActionLog('PROPOSE', "$name " . ($p['world'] ?? ''));

    aiProposalsCollect([
        'token'   => $token,
        'action'  => $name,
        'world'   => $p['world'] ?? '',
        'summary' => $summary,
        // The card asks for the world name to be typed only when the action is
        // irreversible. Sent as the LABEL to type, never as something the browser compares
        // against -- that comparison happens server-side in aiActionApply().
        'typed'   => !empty($a['typed']) ? ($p['world'] ?? '') : '',
        'expires' => AI_PROPOSAL_TTL,
    ]);

    return json_encode([
        'done'     => false,
        'proposed' => true,
        'summary'  => $summary,
        'note'     => 'NOTHING HAS BEEN CHANGED YET. The operator has been shown a confirmation '
                    . 'card describing this change and must click Apply. Tell them what you are '
                    . 'proposing and why, and do not claim it is done.',
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Execute a confirmed proposal. Called only by the applyAiProposal endpoint.
 *
 * Re-validates rather than trusting the stored plan, because state moves between propose
 * and apply: a world that was running when Hugin proposed stopping it may already be
 * stopped by the time the card is clicked. Failing closed with a plain explanation beats
 * acting on a stale premise.
 */
function aiActionApply($pdo, $token, $typedName = '') {
    $sth = $pdo->prepare("SELECT * FROM ai_proposals WHERE token = ?");
    $sth->execute([(string)$token]);
    $row = $sth->fetch(PDO::FETCH_ASSOC);

    if (!$row)                              return ['success' => false, 'error' => 'That confirmation is not valid.'];
    if ($row['status']  !== 'pending')      return ['success' => false, 'error' => 'That change has already been dealt with.'];
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE ai_proposals SET status='expired' WHERE id=?")->execute([$row['id']]);
        aiUsageBump($pdo, 'action_expired', $row['action']);
        return ['success' => false, 'error' => 'That confirmation has expired. Ask Hugin again and it will propose it fresh.'];
    }

    // Irreversible actions: the typed name must match exactly.
    if ($row['typed_name'] !== '' && trim((string)$typedName) !== $row['typed_name']) {
        return ['success' => false, 'error' => "Type the world name exactly ({$row['typed_name']}) to confirm."];
    }

    // Claim it BEFORE running. Two clicks on a slow action must not run it twice, and a
    // crash mid-execution must not leave a replayable token behind.
    $claim = $pdo->prepare("UPDATE ai_proposals SET status='applying', consumed_at=NOW() WHERE id=? AND status='pending'");
    $claim->execute([$row['id']]);
    if ($claim->rowCount() !== 1) {
        return ['success' => false, 'error' => 'That change is already being applied.'];
    }

    $p     = json_decode($row['params_json'], true) ?: [];
    $fresh = aiActionValidate($pdo, $row['action'], aiActionArgsFromPlan($row['action'], $p));
    if (isset($fresh['error'])) {
        $pdo->prepare("UPDATE ai_proposals SET status='failed', result=? WHERE id=?")
            ->execute([$fresh['error'], $row['id']]);
        return ['success' => false,
                'error'   => 'Things have changed since Hugin suggested this: ' . $fresh['error']];
    }

    $cat = aiActionCatalogue();
    $res = ($cat[$row['action']]['run'])($pdo, $fresh);
    $ok  = !empty($res['success']);

    $pdo->prepare("UPDATE ai_proposals SET status=?, result=? WHERE id=?")
        ->execute([$ok ? 'applied' : 'failed',
                   substr((string)($res['message'] ?? $res['error'] ?? ''), 0, 2000),
                   $row['id']]);

    aiUsageBump($pdo, $ok ? 'action_applied' : 'action_failed', $row['action']);
    aiActionLog($ok ? 'APPLY' : 'APPLY_FAIL', $row['action'] . ' ' . $row['world']);

    return [
        'success' => $ok,
        'action'  => $row['action'],
        'world'   => $row['world'],
        'summary' => $row['summary'],
        'message' => $res['message'] ?? '',
        'error'   => $ok ? '' : ($res['error'] ?? 'The change could not be applied.'),
        'restart' => !empty($res['restart']),
    ];
}

/**
 * Rebuild the tool-shaped arguments from a stored plan so apply-time re-validation runs
 * the same code path as propose-time validation. Keeping one validator rather than two is
 * what stops the two drifting into disagreeing about what is allowed.
 */
function aiActionArgsFromPlan($action, $p) {
    $args = [];
    if (isset($p['world'])) $args['world'] = $p['world'];
    switch ($action) {
        case 'set_world_options':
            foreach (($p['changes'] ?? []) as $k => $v) {
                // The password's from/to are MASKED for display ('(set)' / '(none)'), so
                // replaying $v['to'] would try to set the literal string "(set)". Take the
                // real value from the stored input instead.
                $args[$k] = ($k === 'password') ? ($p['input']['password'] ?? '') : $v['to'];
            }
            // Only changed keys are replayed. Passing every field back would re-trigger the
            // vanilla-only check on a modded world whose launch params were all that moved,
            // so a proposal valid at propose time would be refused at apply time.
            break;
        case 'set_world_access':
            $args['list']    = $p['list'] ?? '';
            $args['ids']     = $p['ids'] ?? '';
            $args['enforce'] = $p['enforce'] ?? 0;
            break;
        case 'create_backup':
            $args['compression'] = $p['compression'] ?? '';
            break;
        case 'restore_backup':
            $args['backup_id'] = $p['backup_id'] ?? 0;
            break;
        case 'set_world_backup_policy':
            // Rebuild from the STORED column values, converting the interval back to the
            // hours the tool speaks, so re-validation compares like with like.
            foreach (($p['settings'] ?? []) as $col => $v) {
                if ($col === 'backup_interval_minutes')   $args['interval_hours']   = (int)($v / 60);
                if ($col === 'backup_retain_all_hours')   $args['retain_all_hours'] = $v;
                if ($col === 'backup_use_global')         $args['use_global']       = $v;
            }
            break;
        case 'set_world_mods':
            $args['mod_ids'] = array_column($p['mods'] ?? [], 'id');
            break;
        case 'set_server_settings':
            foreach (($p['changes'] ?? []) as $k => $v) $args[$k] = $v['to'];
            break;
    }
    return $args;
}

/* ====================================================================================
 * "What can Hugin do for me?"
 * ==================================================================================== */

/**
 * The capability card, built FROM the catalogue rather than written out by hand.
 *
 * Deliberately NOT answered by the model. Three reasons, in order of how much they matter:
 *
 *  1. A model asked what it can do will invent. It has the tool schemas in front of it and
 *     will still offer to restore a backup or edit a modpack, because those are things a
 *     server manager plausibly does. This is the one answer in the product that has to be
 *     exactly true, so it is generated from aiActionCatalogue() and aiToolDefinitions() --
 *     add an action and this updates itself; the two cannot drift.
 *  2. It has to work when the model CANNOT. An operator whose endpoint refuses tools is
 *     precisely the one who needs to know what the feature is for, and asking a degraded
 *     model to describe its own degradation is not a plan.
 *  3. It is free and instant. No tokens, no round trip, no spinner.
 */
function aiCapabilityCard($pdo) {
    $cat  = aiActionCatalogue();
    $read = [];
    foreach (aiToolDefinitions(false) as $t) $read[] = $t['name'];

    $safe = $confirm = $typed = [];
    foreach ($cat as $n => $a) {
        if (($a['tier'] ?? '') === 'safe') { $safe[] = $n; continue; }
        if (!empty($a['typed'])) $typed[] = $n; else $confirm[] = $n;
    }

    // Live state, so the card is about THIS server rather than the product in general.
    $rows = aiWorldRows($pdo);
    $run  = 0;
    foreach ($rows as $r) if (aiWorldIsRunning($r)) $run++;
    $tot  = count($rows);

    $verb = function ($n) use ($cat) {
        return '`' . $n . '` — ' . rtrim(strtok($cat[$n]['desc'], '.'), '.') . '.';
    };

    $m  = "### What I can do for you\n\n";
    $m .= "Right now this server has **$tot world" . ($tot === 1 ? '' : 's') . "**"
        . ($tot ? " — $run running, " . ($tot - $run) . " stopped" : '') . ".\n\n";

    $m .= "**I can look at anything**, without asking:\n\n";
    $m .= "- Every world's configuration, mods, access lists, ports and backups\n";
    $m .= "- Any log — I can search the whole file, or read just the current boot\n";
    $m .= "- Host health: CPU, memory, disk, and every supervised process\n";
    $m .= "- A deterministic fault scan that finds problems without guessing\n\n";

    $m .= "**I can do these straight away**, because they are easy to undo:\n\n";
    foreach ($safe as $n) $m .= '- ' . $verb($n) . "\n";
    $m .= "\n";

    $m .= "**I can propose these, and you confirm before anything happens:**\n\n";
    foreach ($confirm as $n) $m .= '- ' . $verb($n) . "\n";
    $m .= "\n";

    if ($typed) {
        $m .= "**And these, which destroy data, need you to type the world name:**\n\n";
        foreach ($typed as $n) $m .= '- ' . $verb($n) . "\n";
        $m .= "\n";
    }

    $m .= "### How the confirming works\n\n";
    $m .= "When I propose a change you get a card showing exactly what will change, "
        . "**old → new**, built from the validated settings rather than from my description "
        . "of them. Nothing happens until you click Apply.\n\n";
    $m .= "- The plan is stored here on the server; your browser only ever holds a token\n";
    $m .= "- Each confirmation works **once**, and expires after 15 minutes\n";
    $m .= "- I re-check everything at the moment you click, so if the world changed in the "
        . "meantime I stop instead of acting on stale information\n\n";

    $m .= "### Things I will refuse to do\n\n";
    $m .= "- Write an **empty CITIZENS list** while access control is on — that lets "
        . "*everyone* in, not nobody\n";
    $m .= "- Add a player ID you did not give me\n";
    $m .= "- List a vanilla world in the server browser with no password — Valheim will not start\n";
    $m .= "- Turn on crossplay, listing or a password for a **modded** world, where they do nothing\n";
    $m .= "- Act on a world name I am not sure about — I will show you the real list instead\n\n";

    // Use a world this operator actually has. A generic placeholder invites them to type it
    // literally, and then Hugin's first act is to tell them no such world exists.
    $eg = $rows ? $rows[0]['name'] : 'your world';

    $m .= "### Good things to ask me\n\n";
    $m .= "- *\"Why won't $eg start?\"* — I read the log from the current boot and tell you the cause\n";
    $m .= "- *\"Is anything wrong with this server?\"* — full scan, most urgent first\n";
    $m .= "- *\"Who can join each world?\"* — and whether that matches how it is configured\n";
    $m .= "- *\"Stop $eg and rebuild it\"* — I will set it out and wait for your go-ahead\n";

    return $m;
}

/**
 * Mark a proposal dismissed. Counted separately from expiry: "the operator looked and said
 * no" and "nobody came back to it" mean different things about how much Hugin is trusted.
 */
function aiActionDismiss($pdo, $token) {
    $sth = $pdo->prepare("UPDATE ai_proposals SET status='dismissed', consumed_at=NOW()
                           WHERE token=? AND status='pending'");
    $sth->execute([(string)$token]);
    if ($sth->rowCount() === 1) aiUsageBump($pdo, 'action_dismissed');
    return ['success' => true];
}
