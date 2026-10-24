<?php
/**
 * Hugin's Vibes -- the Forge loop (design 7.1, 7.4, 7.5).
 *
 * One turn: system prompt from the project's durable context (project.json, notes.md, tree, git
 * log, libs manifest, knowledge index, rules) + the conversation (compaction summary + prior
 * user/assistant text + the new message) -> aiChat() -> tool calls -> results -> ... -> final
 * prose. Then the epilogue: auto-build if files changed without a build, auto-commit if dirty.
 *
 * The conversation is rebuilt from the transcript's user/assistant TEXT only, never from stored
 * tool calls -- the same rule aiStream.php follows: a transcript on disk is data, and replaying a
 * tool call out of it would let an edited file run tools. aiChat() is the only model transport.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once '/opt/stateless/nginx/www/includes/aiproviders.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/git.php';
require_once __DIR__ . '/build.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/tools.php';

const FORGE_MAX_TOKENS = 8192;

/** The provider row for a project: hv_projects.provider_id or the default; hv_projects.model overrides VERBATIM. */
function forgeProvider(PDO $pdo, array $project) {
	$p = null;
	if (!empty($project['provider_id'])) $p = aiProvider($pdo, (int)$project['provider_id']);
	if (!$p) $p = aiDefaultProvider($pdo);
	if (!$p) return [null, 'No AI provider is configured. Add one on the AI page.'];
	if (empty($p['enabled'])) return [null, "The AI provider '{$p['label']}' is disabled."];
	$override = trim((string)($project['model'] ?? ''));
	// Never validated, never normalised: the operator typed it, the endpoint decides (the repo's
	// hardest-won AI rule -- model ids change faster than any list we could ship).
	if ($override !== '') $p['model'] = $override;
	if (trim((string)($p['model'] ?? '')) === '') return [null, "No model is selected for '{$p['label']}'."];
	return [$p, null];
}

/** [{topic, title, blurb}] from knowledge/*.md: first line "# Title", first "> blurb" line. */
function forgeKnowledgeIndex() {
	$out = [];
	foreach (glob(VIBES_TOOLS . '/knowledge/*.md') ?: [] as $f) {
		$topic = basename($f, '.md');
		$title = $topic; $blurb = '';
		foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], 0, 8) as $line) {
			if ($title === $topic && preg_match('/^#\s+(.+)/', $line, $m)) $title = trim($m[1]);
			elseif ($blurb === '' && preg_match('/^>\s*(.+)/', $line, $m)) $blurb = trim($m[1]);
		}
		$out[] = ['topic' => $topic, 'title' => $title, 'blurb' => $blurb];
	}
	return $out;
}

function forgeSystemPrompt(array $project, array $doc) {
	$slug = $project['slug']; $dir = vibesProjectDir($slug);
	$pj = vibesReadJson("$dir/.vibes/project.json", []);
	$notes = is_file("$dir/.vibes/notes.md") ? trim((string)file_get_contents("$dir/.vibes/notes.md")) : '';
	if (strlen($notes) > 12000) $notes = "[notes truncated to the newest 12 KB]\n" . substr($notes, -12000);
	$tree = [];
	foreach (vibesProjectTree($slug) as $e) {
		if (preg_match('~^(dist|\.vibes/(sessions|builds))(/|$)~', $e['path'])) continue;
		$tree[] = $e['kind'] === 'dir' ? $e['path'] . '/' : $e['path'] . ' (' . $e['size'] . ' B)';
		if (count($tree) >= 200) { $tree[] = '... (use list_files)'; break; }
	}
	vibesGitSettings($doc);
	$log = array_map(function ($c) { return "{$c['short']} {$c['date']} {$c['message']}"; }, vibesGitLog($slug, 5));
	$libs = vibesLibsManifest();
	$libsText = $libs === null ? 'NOT HARVESTED -- builds will fail until the operator runs Harvest.'
	          : sprintf('game build %s from world %s, harvested %s; publicized: %s; decompiled source: %s; type index: %s.',
	                    $libs['gameBuild'] ?? ($libs['gameVersion'] ?? '?'), $libs['sourceWorld'] ?? '?', $libs['harvestedAt'] ?? '?',
	                    !empty($libs['publicized']) ? 'yes (assembly_valheim_publicized.dll)' : 'no', !empty($libs['decompiled']) ? 'yes (game_source works)' : 'no (game_source unavailable)',
	                    !empty($libs['typesIndexed']) ? $libs['typesIndexed'] . ' types (game_types works)' : 'none');
	$last = vibesBuildLast($slug);
	$buildText = $last === null ? 'never built' : (($last['ok'] ? 'green' : 'FAILED (' . count($last['errors']) . ' error(s))') . " at {$last['at']} ({$last['configuration']})");
	$manifest = vibesProjectManifestRead($slug);
	$know = array_map(function ($k) { return "- {$k['topic']}: {$k['title']}" . ($k['blurb'] !== '' ? " -- {$k['blurb']}" : ''); }, forgeKnowledgeIndex());
	$author = trim((string)vibesSetting($doc, 'author.display', ''));

	return implode("\n", [
		"You are Hugin, the mod-forging raven of PhValheim, working inside Hugin's Vibes: a workbench that builds Valheim mods (BepInEx 5 plugins, C#, .NET Framework 4.7.2) for the operator's own dedicated servers. You have tools that read and edit the project, build it with the real compiler against the real game assemblies, search the decompiled game source, and commit. You cannot run the game.",
		'',
		'## This project',
		'- Name: ' . ($pj['name'] ?? $project['name']) . '   Namespace (Thunderstore team / catalogue owner): ' . ($pj['namespace'] ?? $project['namespace']),
		'- BepInEx GUID: ' . ($pj['guid'] ?? vibesGuid($project['namespace'], $project['name'])) . '  (NEVER change it once published; players\' configs are keyed on it)',
		'- Version: ' . ($manifest['version_number'] ?? $project['version']) . '   Status: ' . ($project['status'] ?? 'draft') . '   Published: ' . ($project['published_version'] ?? 'never'),
		'- Template: ' . ($pj['template'] ?? $project['template']) . '   Created: ' . ($pj['created'] ?? $project['created']),
		'- Last build: ' . $buildText,
		'- Game libraries: ' . $libsText,
		$author !== '' ? "- Operator: $author" : '',
		'',
		'## Project notes (.vibes/notes.md -- your memory; append with the note tool)',
		$notes === '' ? '(none yet)' : $notes,
		'',
		'## Files',
		implode("\n", $tree),
		'',
		'## Recent commits',
		$log ? implode("\n", $log) : '(none)',
		'',
		'## Knowledge topics (read one with the knowledge tool)',
		implode("\n", $know),
		'',
		'## Rules',
		'1. Build before you say you are done. A change you did not compile is a change you did not make. Read the diagnostics and fix them.',
		'2. Never invent a game API. Confirm every Valheim type and member with game_source or game_types before you call it; if the source is not available, say which calls are unverified.',
		'3. Prefer a Harmony Postfix or Prefix over a Transpiler. Patch the smallest method that gives you the hook.',
		'4. Do not change the BepInEx GUID after the mod has been published. Bump version_number (manifest tool) whenever behaviour changes; the csproj and PluginVersion follow automatically.',
		'5. One feature per commit, with a message that says what changed for a player. Commit with the git tool when a change is complete and builds; the operator can also let auto-commit do it.',
		'6. Keep the mod server-safe: guard client-only Unity UI code behind ZNet.instance checks (see server-vs-client) so a dedicated server never touches a missing camera or canvas.',
		'7. Say plainly what cannot be verified without a running client or server. A green build proves the code compiles, not that it works.',
		'8. Write a note (note tool) when you make a decision the next session must know, find a bug you did not fix, or leave a TODO.',
		'9. Answer in short, concrete prose. Lead with what you did and what the build said; end with what the operator should test in game.',
	]);
}

/* ------------------------------------------------------------------------------------------
 * The conversation: transcript -> messages, compaction
 * ---------------------------------------------------------------------------------------- */

/** user/assistant text events after $afterSeq, in order: [{seq, role, content}]. Tool calls are never replayed. */
function forgeTranscriptTurns($file, $afterSeq = 0) {
	$out = [];
	if (!is_file($file)) return $out;
	$fh = fopen($file, 'r');
	while (($line = fgets($fh)) !== false) {
		$e = json_decode($line, true);
		if (!is_array($e) || (int)($e['seq'] ?? 0) <= $afterSeq) continue;
		if (($e['type'] ?? '') === 'user' && isset($e['text']))           $out[] = ['seq' => (int)$e['seq'], 'role' => 'user', 'content' => (string)$e['text']];
		elseif (($e['type'] ?? '') === 'assistant' && isset($e['text'])) $out[] = ['seq' => (int)$e['seq'], 'role' => 'assistant', 'content' => (string)$e['text']];
	}
	fclose($fh);
	return $out;
}

/** The summary column carries "[through:N]\n..." so the runner knows which transcript seqs it replaced. */
function forgeSummaryParse($summary) {
	if (!is_string($summary) || $summary === '') return [0, ''];
	if (preg_match('/^\[through:(\d+)\]\n?(.*)$/s', $summary, $m)) return [(int)$m[1], trim($m[2])];
	return [0, trim($summary)];
}

function forgeEstimateTokens(array $messages, $system = '') {
	$n = strlen($system);
	foreach ($messages as $m) $n += strlen(is_string($m['content'] ?? null) ? $m['content'] : json_encode($m));
	return (int)ceil($n / 4);
}

/**
 * Compaction (7.5): when the conversation exceeds the budget, ask the model ONCE (no tools) for
 * a <= 800-token summary of the older half, store it in hv_sessions.summary with the seq it
 * covers, and continue with summary + newer half. Returns the new [$summaryText, $turns].
 */
function forgeCompact(PDO $pdo, array $provider, array $session, $summaryText, array $turns, $budget, $system) {
	$msgs = forgeMessagesFrom($summaryText, $turns);
	if (count($turns) < 4 || forgeEstimateTokens($msgs, $system) <= $budget) return [$summaryText, $turns, false];
	$cut = intdiv(count($turns), 2);
	$older = array_slice($turns, 0, $cut); $newer = array_slice($turns, $cut);
	$text = ($summaryText !== '' ? "Earlier summary:\n$summaryText\n\n" : '') . "Conversation to summarise:\n";
	foreach ($older as $t) $text .= strtoupper($t['role']) . ": " . $t['content'] . "\n\n";
	$res = aiChat($provider, [['role' => 'user', 'content' => $text]],
	              'Summarise this modding conversation for your future self in at most 800 tokens: what the operator asked for, what was built and where (files, classes), decisions and their reasons, open bugs and TODOs. Plain prose and bullets; no preamble.',
	              [], null, 1200);
	if (empty($res['success']) || trim((string)$res['content']) === '') return [$summaryText, $turns, false];
	$through = $older[count($older) - 1]['seq'];
	$new = trim((string)$res['content']);
	$pdo->prepare("UPDATE hv_sessions SET summary = ? WHERE id = ?")->execute(["[through:$through]\n$new", (int)$session['id']]);
	return [$new, $newer, true];
}

function forgeMessagesFrom($summaryText, array $turns) {
	$msgs = [];
	if ($summaryText !== '') {
		$msgs[] = ['role' => 'user', 'content' => "[Summary of the earlier part of this conversation]\n$summaryText"];
		$msgs[] = ['role' => 'assistant', 'content' => 'Understood; continuing from that summary.'];
	}
	foreach ($turns as $t) $msgs[] = ['role' => $t['role'], 'content' => $t['content']];
	// Providers reject two consecutive same-role messages; fold them.
	$folded = [];
	foreach ($msgs as $m) {
		$last = count($folded) - 1;
		if ($last >= 0 && $folded[$last]['role'] === $m['role']) $folded[$last]['content'] .= "\n\n" . $m['content'];
		else $folded[] = $m;
	}
	return $folded;
}

/** {in, out} from whichever shape the adapter returned. */
function forgeUsage($u) {
	if (!is_array($u)) return ['in' => 0, 'out' => 0];
	return ['in'  => (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? $u['promptTokenCount'] ?? 0),
	        'out' => (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? $u['candidatesTokenCount'] ?? 0)];
}

/* ------------------------------------------------------------------------------------------
 * The turn
 * ---------------------------------------------------------------------------------------- */

/**
 * Run one turn. Emits every event of 7.3 through $emit (which writes the transcript and the job
 * log). Returns ['success', 'rounds', 'usage' => ['in','out'], 'model', 'error'].
 */
function forgeTurn(PDO $pdo, array $session, array $project, $userText, HvEmitter $emit, callable $isCancelled) {
	$doc = vibesSettingsGet($pdo);
	$slug = $project['slug'];
	$userText = trim((string)$userText);
	if ($userText === '') return ['success' => false, 'error' => 'The message is empty.', 'rounds' => 0, 'usage' => ['in' => 0, 'out' => 0], 'model' => ''];
	[$provider, $perr] = forgeProvider($pdo, $project);
	if (!$provider) return ['success' => false, 'error' => $perr, 'rounds' => 0, 'usage' => ['in' => 0, 'out' => 0], 'model' => ''];
	$model = (string)$provider['model'];

	// The user's message is the first event of the turn, so a reload mid-turn shows it.
	$emit->emit(['type' => 'user', 'text' => $userText]);
	$pdo->prepare("UPDATE hv_sessions SET status='running', title = IF(title = '', ?, title) WHERE id = ?")
	    ->execute([vibesSubstr(preg_replace('/\s+/', ' ', $userText), 0, 120), (int)$session['id']]);

	$system = forgeSystemPrompt($project, $doc);
	$transcript = vibesSessionLog($slug, $session['id']);
	[$through, $summaryText] = forgeSummaryParse($session['summary'] ?? null);
	$turns = forgeTranscriptTurns($transcript, $through);
	// The new user text is already in the file (emitted above); drop that trailing copy so the
	// loop appends exactly one user message for it.
	if ($turns && $turns[count($turns) - 1]['role'] === 'user' && $turns[count($turns) - 1]['content'] === $userText) array_pop($turns);
	$budget = max(4000, (int)vibesSetting($doc, 'agent.context_budget_tokens', 60000));
	[$summaryText, $turns] = forgeCompact($pdo, $provider, $session, $summaryText, $turns, $budget, $system);
	$messages = forgeMessagesFrom($summaryText, $turns);
	if ($messages && $messages[count($messages) - 1]['role'] === 'user') $messages[count($messages) - 1]['content'] .= "\n\n" . $userText;
	else $messages[] = ['role' => 'user', 'content' => $userText];

	$maxRounds = max(1, (int)vibesSetting($doc, 'agent.max_rounds', 40));
	$deadline = microtime(true) + max(30, (int)vibesSetting($doc, 'agent.turn_timeout_s', 900));
	$timedOut = false;
	if (function_exists('pcntl_async_signals') && function_exists('pcntl_alarm')) {
		pcntl_async_signals(true);
		pcntl_signal(SIGALRM, function () use (&$timedOut) { $timedOut = true; });
		pcntl_alarm((int)max(30, $deadline - microtime(true)));
	}
	$ctx = forgeCtx($pdo, $doc, $project, $emit);
	$tools = forgeToolDefinitions();
	$usage = ['in' => 0, 'out' => 0];
	$rounds = 0; $finalText = ''; $error = null; $cancelled = false;
	$onDelta = function ($text) use ($emit) { if ($text !== '') $emit->emit(['type' => 'token', 'text' => $text]); };

	while ($rounds < $maxRounds) {
		if ($isCancelled()) { $cancelled = true; break; }
		if ($timedOut || microtime(true) > $deadline) { $timedOut = true; break; }
		$rounds++;
		$res = aiChat($provider, $messages, $system, $tools, $onDelta, FORGE_MAX_TOKENS);
		if (empty($res['success'])) { $error = 'The model call failed: ' . ($res['error'] ?? 'unknown error'); break; }
		$u = forgeUsage($res['usage'] ?? null); $usage['in'] += $u['in']; $usage['out'] += $u['out'];
		if (!empty($res['model'])) $model = (string)$res['model'];
		$calls = is_array($res['tool_calls'] ?? null) ? $res['tool_calls'] : [];
		if (!$calls) { $finalText = (string)$res['content']; break; }
		$messages[] = ['role' => 'assistant', 'content' => (string)$res['content'], 'tool_calls' => $calls,
		               // Opaque echo Gemini requires back (thoughtSignature); never inspected.
		               'provider_raw' => $res['provider_raw'] ?? null];
		foreach ($calls as $call) {
			if ($isCancelled()) { $cancelled = true; break 2; }
			if ($timedOut || microtime(true) > $deadline) { $timedOut = true; break 2; }
			$args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
			$emit->emit(['type' => 'tool_call', 'id' => (string)$call['id'], 'name' => (string)$call['name'], 'args' => $args]);
			$t0 = microtime(true);
			$result = forgeRunTool($ctx, (string)$call['name'], $args);
			$ok = !preg_match('/^(Refused:|No such|Unknown tool|Tool \'.*\' failed|BUILD FAILED|grep failed|Could not|exit [1-9])/', $result);
			$emit->emit(['type' => 'tool_result', 'id' => (string)$call['id'], 'ok' => $ok, 'preview' => vibesSubstr($result, 0, 240), 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
			$messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $call['name'], 'content' => $result];
		}
	}
	if (function_exists('pcntl_alarm')) pcntl_alarm(0);

	if ($cancelled) $finalText = $finalText !== '' ? $finalText : '[Stopped by the operator.]';
	elseif ($timedOut) $finalText = ($finalText !== '' ? $finalText . "\n\n" : '') . '[The turn hit its time limit (agent.turn_timeout_s) and was stopped; what was written is on disk.]';
	elseif ($error === null && $rounds >= $maxRounds && $finalText === '') $finalText = '[Stopped after the maximum number of tool rounds (agent.max_rounds); what was written is on disk.]';
	if ($error === null) $emit->emit(['type' => 'assistant', 'text' => $finalText]);

	// Epilogue (7.1): a build if files changed without one, a commit if the tree is dirty.
	if ($error === null && !$cancelled) {
		if ((bool)vibesSetting($doc, 'agent.auto_build', true) && $ctx['changed'] && !$ctx['built'] && vibesPakReady()) {
			$b = vibesBuild($slug, null, $pdo, $doc);
			$emit->emit(['type' => 'build', 'ok' => $b['ok'], 'errors' => $b['errors'], 'warnings' => $b['warnings'], 'ms' => $b['ms'], 'auto' => true]);
		}
		if ((bool)vibesSetting($doc, 'agent.auto_commit', true) && vibesPakReady()) {
			vibesGitSettings($doc);
			if (vibesGitIsDirty($slug)) {
				$msg = 'vibe: ' . vibesSubstr(preg_replace('/\s+/', ' ', $userText), 0, 60);
				[$ok, $hash] = vibesGitCommit($slug, $msg);
				if ($ok && $hash !== '') $emit->emit(['type' => 'commit', 'hash' => $hash, 'message' => $msg]);
			}
		}
	}
	$pdo->prepare("UPDATE hv_sessions SET status = ?, turns = turns + 1, tokens_in = tokens_in + ?, tokens_out = tokens_out + ? WHERE id = ?")
	    ->execute([$error === null ? 'idle' : 'error', $usage['in'], $usage['out'], (int)$session['id']]);
	return ['success' => $error === null, 'error' => $error, 'rounds' => $rounds, 'usage' => $usage, 'model' => $model, 'cancelled' => $cancelled];
}
