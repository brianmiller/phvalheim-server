<?php
/*
 * The event log (2.56): PhValheim's own actions as rows, not lines.
 *
 * WHY: everything PhValheim did went into one shared text file, untyped. An operator working
 * on a world read the engine log to find out what happened to it; Hugin grepped a tail and
 * guessed. Now every action is a row in phv_events with a source (engine, updateApplier,
 * backup, admin, hugin...), a level, the world it concerns and the OPERATION it belongs to
 * (one start/stop/update/create/delete/repackage carried out by the engine, with an outcome,
 * in phv_operations). Valheim's own stdout stays a file: it is the game's, and a firehose.
 *
 * Writers: phvlog() in engine/includes/phvalheim-static.conf (bash); phvEvent() here (PHP,
 * for the admin UI and Hugin). Readers: this file, adminAPI (getEvents...), aicontext (Hugin's
 * tools). The world log view merges events with the Valheim log by time (phvMergeTimeline).
 */

if (!defined('PHV_LOG_DIR')) { define('PHV_LOG_DIR', '/opt/stateful/logs'); }

const PHV_EVENT_LEVELS = ['debug' => 0, 'notice' => 1, 'warn' => 2, 'error' => 3];

/* ------------------------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------------------------- */

/* The source the current PHP request writes as: 'admin' for the operator's own clicks,
 * 'hugin' while Hugin applies an action. Set by the caller, read by phvEvent(). */
function phvEventSource($set = null) {
	static $src = 'admin';
	if ($set !== null) { $src = $set; }
	return $src;
}

function phvEvent($pdo, $level, $message, $world = null, $detail = null, $opId = null) {
	if (!$pdo) { return false; }
	$level = isset(PHV_EVENT_LEVELS[$level]) ? $level : 'notice';
	try {
		$s = $pdo->prepare("INSERT INTO phv_events (source, level, world, op_id, message, detail) VALUES (?, ?, ?, ?, ?, ?)");
		return $s->execute([phvEventSource(), $level, $world !== null && $world !== '' ? $world : null, $opId,
			(string)$message, $detail === null ? null : json_encode($detail)]);
	} catch (Throwable $e) {
		// Never let the log break the action it describes.
		return false;
	}
}

/* ------------------------------------------------------------------------------------------
 * Reading
 * ---------------------------------------------------------------------------------------- */

function phvEventRow($r) {
	$r['id'] = (int)$r['id'];
	$r['detail'] = isset($r['detail']) && $r['detail'] !== null ? json_decode($r['detail'], true) : null;
	$r['t'] = isset($r['ts']) ? strtotime($r['ts']) : null;
	return $r;
}

/* Events, newest last. $f: world, source, min_level, op_id, since_id (exclusive), before_id
 * (exclusive), q (substring), limit (<= 1000). */
function phvEvents($pdo, $f = []) {
	$where = []; $args = [];
	if (!empty($f['world']))  { $where[] = 'world = ?';  $args[] = $f['world']; }
	if (!empty($f['source'])) { $where[] = 'source = ?'; $args[] = $f['source']; }
	if (!empty($f['op_id']))  { $where[] = 'op_id = ?';  $args[] = $f['op_id']; }
	if (!empty($f['min_level']) && isset(PHV_EVENT_LEVELS[$f['min_level']])) {
		$lv = array_keys(array_filter(PHV_EVENT_LEVELS, fn($n) => $n >= PHV_EVENT_LEVELS[$f['min_level']]));
		$where[] = 'level IN (' . implode(',', array_fill(0, count($lv), '?')) . ')'; $args = array_merge($args, $lv);
	}
	if (!empty($f['since_id']))  { $where[] = 'id > ?'; $args[] = (int)$f['since_id']; }
	if (!empty($f['before_id'])) { $where[] = 'id < ?'; $args[] = (int)$f['before_id']; }
	if (!empty($f['since_ts']))  { $where[] = 'ts >= ?'; $args[] = date('Y-m-d H:i:s', (int)$f['since_ts']); }
	if (!empty($f['q']))         { $where[] = 'message LIKE ?'; $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%'; }
	$limit = max(1, min(1000, (int)($f['limit'] ?? 200)));
	$sql = "SELECT id, ts, source, level, world, op_id, message, detail FROM phv_events"
	     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit";
	$s = $pdo->prepare($sql); $s->execute($args);
	$rows = array_map('phvEventRow', $s->fetchAll(PDO::FETCH_ASSOC));
	return array_reverse($rows);
}

function phvOperationRow($r) {
	$r['started_t'] = strtotime($r['started']);
	$r['finished_t'] = !empty($r['finished']) ? strtotime($r['finished']) : null;
	$r['open'] = empty($r['finished']);
	$r['event_count'] = (int)($r['event_count'] ?? 0);
	$r['error_count'] = (int)($r['error_count'] ?? 0);
	return $r;
}

/* The most recent operations, newest first, with event counts. */
function phvOperations($pdo, $world = null, $limit = 20) {
	$limit = max(1, min(200, (int)$limit));
	$sql = "SELECT o.op_id, o.world, o.kind, o.source, o.started, o.finished, o.outcome, o.summary,
	               (SELECT COUNT(*) FROM phv_events e WHERE e.op_id = o.op_id) AS event_count,
	               (SELECT COUNT(*) FROM phv_events e WHERE e.op_id = o.op_id AND e.level IN ('warn','error')) AS error_count
	          FROM phv_operations o" . ($world !== null && $world !== '' ? ' WHERE o.world = ?' : '')
	     . " ORDER BY o.started DESC LIMIT $limit";
	$s = $pdo->prepare($sql); $s->execute($world !== null && $world !== '' ? [$world] : []);
	return array_map('phvOperationRow', $s->fetchAll(PDO::FETCH_ASSOC));
}

function phvOperation($pdo, $opId) {
	$s = $pdo->prepare("SELECT op_id, world, kind, source, started, finished, outcome, summary FROM phv_operations WHERE op_id = ?");
	$s->execute([$opId]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	if (!$r) { return null; }
	$r = phvOperationRow($r);
	$r['events'] = phvEvents($pdo, ['op_id' => $opId, 'limit' => 1000]);
	$r['event_count'] = count($r['events']);
	$r['error_count'] = count(array_filter($r['events'], fn($e) => $e['level'] === 'warn' || $e['level'] === 'error'));
	return $r;
}

/* World names with an operation still open: the dashboard pulses their Logs button. */
function phvOpenOperations($pdo) {
	$s = $pdo->query("SELECT world, kind, started FROM phv_operations WHERE finished IS NULL ORDER BY started");
	$out = [];
	foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['world']] = ['kind' => $r['kind'], 'started_t' => strtotime($r['started'])]; }
	return $out;
}

/* What the sidebar needs every poll: unseen warnings/errors since the operator's mark, the
 * newest id, and the worlds with an operation in flight. One cheap query each. */
function phvEventsSummary($pdo) {
	$seen = 0;
	try { $seen = (int)$pdo->query("SELECT logsSeenEventId FROM settings LIMIT 1")->fetchColumn(); } catch (Throwable $e) { $seen = 0; }
	// Unseen = not acknowledged, PER EVENT (2.58). The 2.56 design was one watermark id in
	// settings, which could not express "I looked at this world's log" and -- see
	// phvEventsAck() -- never moved once ids reached four digits.
	$s = $pdo->prepare("SELECT level, COUNT(*) AS n FROM phv_events WHERE acked = 0 AND level IN ('warn','error') GROUP BY level");
	$s->execute();
	$unseen = ['warn' => 0, 'error' => 0];
	foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $unseen[$r['level']] = (int)$r['n']; }
	$max = (int)$pdo->query("SELECT IFNULL(MAX(id), 0) FROM phv_events")->fetchColumn();
	return ['seen_id' => $seen, 'max_id' => $max, 'unseen_warn' => $unseen['warn'], 'unseen_error' => $unseen['error'],
	        'open_ops' => phvOpenOperations($pdo)];
}

/* The operator has looked: every event up to $id is seen -- for one world when $world is
 * given (the world log view), for the whole server otherwise (the Logs page).
 *
 * THE 2.56 BUG. This was `UPDATE settings SET logsSeenEventId = GREATEST(logsSeenEventId, ?)`
 * with the id bound by PDO as a STRING, and MySQL's GREATEST compares as strings when any
 * argument is one: GREATEST(669, '6429') is '669' because "66" sorts after "64". The
 * watermark climbed while ids had three digits and then froze at 669 for good; the Logs
 * page posted its ack on every visit and the sidebar badge never cleared (production,
 * 2026-10-24). The watermark is still written, for anything that reads it, with the id
 * CAST so the comparison is numeric; the count the badge shows comes from the per-event
 * flag above, which a world-scoped ack can also set. */
function phvEventsAck($pdo, $id, $world = null) {
	$id = (int)$id;
	if ($world !== null && $world !== '') {
		$s = $pdo->prepare("UPDATE phv_events SET acked=1 WHERE acked=0 AND id <= ? AND world = ?");
		return $s->execute([$id, $world]);
	}
	$s = $pdo->prepare("UPDATE phv_events SET acked=1 WHERE acked=0 AND id <= ?");
	$ok = $s->execute([$id]);
	try {
		$w = $pdo->prepare("UPDATE settings SET logsSeenEventId = GREATEST(logsSeenEventId, CAST(? AS UNSIGNED))");
		$w->execute([$id]);
	} catch (Throwable $e) { /* the watermark is informational now */ }
	return $ok;
}

/* Errors in the last hour, for the Server Health check. */
function phvRecentErrors($pdo, $seconds = 3600) {
	$s = $pdo->prepare("SELECT COUNT(*) AS n, MAX(ts) AS last_ts FROM phv_events WHERE level = 'error' AND ts >= NOW() - INTERVAL ? SECOND");
	$s->execute([(int)$seconds]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	$last = null;
	if ((int)$r['n'] > 0) {
		$l = $pdo->prepare("SELECT message, world FROM phv_events WHERE level = 'error' ORDER BY id DESC LIMIT 1");
		$l->execute(); $last = $l->fetch(PDO::FETCH_ASSOC) ?: null;
	}
	return ['count' => (int)$r['n'], 'last_t' => !empty($r['last_ts']) ? strtotime($r['last_ts']) : null, 'last' => $last];
}

/* ------------------------------------------------------------------------------------------
 * Raw log files
 * ---------------------------------------------------------------------------------------- */

/* A plain file name ending in .log or .log.1, that exists inside the logs directory. No
 * separators, no dot-dot, and the realpath must stay under the directory. */
function phvLogFileAllowed($name) {
	if (!is_string($name) || $name === '' || strlen($name) > 200) { return false; }
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.log(\.1)?$/', $name) || str_contains($name, '..')) { return false; }
	$real = realpath(PHV_LOG_DIR . '/' . $name);
	$dir = realpath(PHV_LOG_DIR);
	return $real !== false && $dir !== false && str_starts_with($real, $dir . '/') && is_file($real);
}

/* Every raw log, with what the Logs page shows about it. */
function phvLogFiles() {
	$out = [];
	foreach (glob(PHV_LOG_DIR . '/*.log') ?: [] as $p) {
		$name = basename($p);
		$out[] = [
			'name'  => $name,
			'size'  => (int)filesize($p),
			'mtime' => (int)filemtime($p),
			'world' => preg_match('/^valheimworld_(.+)\.log$/', $name, $m) ? $m[1] : null,
			'rotated' => is_file($p . '.1'),
		];
	}
	usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
	return $out;
}

/* ------------------------------------------------------------------------------------------
 * The Valheim log for one world, and the merged timeline
 * ---------------------------------------------------------------------------------------- */

/* Valheim stamps its lines "MM/DD/YYYY HH:MM:SS: text". A line without a stamp (a stack
 * trace, a continuation) belongs to the stamped line before it. Returns [t, text]. */
function phvValheimLineTime($line, $prevT) {
	if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2}):(\d{2}): ?/', $line, $m)) {
		return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[1], (int)$m[2], (int)$m[3]);
	}
	return $prevT;
}

/* The byte offset where this world's current Valheim session begins: recorded by the engine
 * (phvWorldSessionMark) in the newest session event. 0 when unknown or when the file has
 * been rotated since (it is shorter than the offset). */
function phvValheimSessionOffset($pdo, $world, $fileSize) {
	try {
		// Not JSON_EXTRACT(...) = true: on MariaDB the extracted scalar is text and the comparison never
		// matches. The session event is the one carrying a log_offset.
		$s = $pdo->prepare("SELECT detail FROM phv_events WHERE world = ? AND detail LIKE '%\"log_offset\"%' ORDER BY id DESC LIMIT 1");
		$s->execute([$world]);
		$d = json_decode((string)$s->fetchColumn(), true);
		$off = (int)($d['log_offset'] ?? 0);
		return ($off > 0 && $off <= $fileSize) ? $off : 0;
	} catch (Throwable $e) { return 0; }
}

/* Lines of the world's Valheim log: the whole current file, or from the session offset.
 * Returns ['lines' => [[t, text], ...], 'from_offset' => int, 'size' => int, 'truncated' => bool].
 * Capped at $maxBytes from the END so a huge log never floods the page. */
function phvValheimLog($pdo, $world, $thisBoot = true, $maxBytes = 2000000) {
	$path = PHV_LOG_DIR . '/valheimworld_' . $world . '.log';
	clearstatcache();
	if (!is_file($path)) { return ['lines' => [], 'from_offset' => 0, 'size' => 0, 'truncated' => false, 'exists' => false]; }
	$size = (int)filesize($path);
	$from = $thisBoot ? phvValheimSessionOffset($pdo, $world, $size) : 0;
	$truncated = false;
	if ($size - $from > $maxBytes) { $from = $size - $maxBytes; $truncated = true; }
	$raw = (string)file_get_contents($path, false, null, $from);
	$raw = preg_replace('/\x1b\[[0-9;]*m/', '', $raw);
	if ($truncated) { $raw = substr($raw, (int)strpos($raw, "\n") + 1); }
	$lines = []; $t = null;
	foreach (explode("\n", rtrim($raw, "\n")) as $l) {
		if ($l === '') { continue; }
		$t = phvValheimLineTime($l, $t);
		$lines[] = [$t, $l];
	}
	return ['lines' => $lines, 'from_offset' => $from, 'size' => $size, 'truncated' => $truncated, 'exists' => true];
}

/* One timeline: events (kind 'event') and Valheim lines (kind 'valheim') ordered by time.
 * Stable for equal times: input order, and events are appended first, so at an equal second
 * PhValheim's line precedes the game's. A Valheim line with no time yet (nothing stamped
 * before it) sorts to the start. Pure: no I/O. */
function phvMergeTimeline($events, $valheimLines) {
	$out = []; $n = 0;
	foreach ($events as $e) {
		$out[] = ['t' => (int)($e['t'] ?? 0), 'kind' => 'event', 'order' => $n++, 'level' => $e['level'], 'source' => $e['source'],
		          'op_id' => $e['op_id'], 'text' => $e['message'], 'id' => $e['id'], 'detail' => $e['detail'] ?? null];
	}
	foreach ($valheimLines as [$t, $text]) {
		$out[] = ['t' => (int)($t ?? 0), 'kind' => 'valheim', 'order' => $n++, 'level' => phvValheimLineLevel($text), 'source' => 'valheim',
		          'op_id' => null, 'text' => $text, 'id' => null, 'detail' => null];
	}
	usort($out, function ($a, $b) {
		if ($a['t'] !== $b['t']) { return $a['t'] <=> $b['t']; }
		return $a['order'] <=> $b['order'];
	});
	foreach ($out as &$r) { unset($r['order']); }
	return $out;
}

/* A rough level for a Valheim line, for colouring only. */
function phvValheimLineLevel($text) {
	if (preg_match('/(Exception\b|\bERROR\b|\bError:|\bFailed\b|\bFATAL\b)/', $text)) { return 'error'; }
	if (preg_match('/\b(Warning|WARN|warning:)\b/', $text)) { return 'warn'; }
	return 'info';
}
