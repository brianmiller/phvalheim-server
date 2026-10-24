<?php
/**
 * Hugin's Vibes -- the event stream (2.59). docs/HUGINS-VIBES-DESIGN.md section 7.3.
 *
 *   vibesStream.php?job=<id>&after=<seq>      tails /opt/stateful/vibes/logs/jobs/<id>.jsonl
 *   vibesStream.php?session=<id>&after=<seq>  tails projects/<slug>/.vibes/sessions/<id>.jsonl
 *
 * Same SSE prologue as aiStream.php (X-Accel-Buffering: no, output buffers drained) because the
 * same nginx sits in front. The runner WRITES the file; we only read it, so this endpoint is
 * pure: no state, no database writes. Each new line goes out as one `data:` frame exactly as
 * written -- the browser gets the runner's JSON, not a re-encoding of it -- except for lines
 * that are not complete JSON yet (the runner may be mid-write), which wait for the next poll.
 *
 * Why the hv_jobs row is polled as well: the file only grows while the runner is alive. If it
 * dies without writing `done` or `error` the UI would sit on a spinner for ever, so a status
 * change on the row (failed / cancelled / done) is sent as a synthetic {"type":"job"} frame and
 * ends the stream. `after` lets a reconnecting client resume from the last seq it rendered.
 *
 * Exits: a done/error event, a finished job row, the client going away, or two hours.
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/vibes.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
ignore_user_abort(false);
set_time_limit(0);

function vibesSseRaw($line) {
	echo 'data: ' . $line . "\n\n";
	@ob_flush();
	@flush();
}
function vibesSseObj($obj) { vibesSseRaw(json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); }
function vibesSseEnd($why) {
	vibesSseObj(['type' => 'eos', 'reason' => $why]);
	exit;
}

$after     = max(0, (int)($_GET['after'] ?? 0));
$jobId     = isset($_GET['job'])     ? (int)$_GET['job']     : 0;
$sessionId = isset($_GET['session']) ? (int)$_GET['session'] : 0;

if ($jobId <= 0 && $sessionId <= 0) {
	vibesSseObj(['type' => 'error', 'error' => 'Give ?job=<id> or ?session=<id>.']);
	vibesSseEnd('bad-request');
}

// Resolve what we tail and which job row tells us the writer is still alive.
if ($jobId > 0) {
	$file = vibesJobLog($jobId);
	$watchJob = fn() => vibesJob($pdo, $jobId);
	if (!$watchJob()) { vibesSseObj(['type' => 'error', 'error' => "No job with id $jobId."]); vibesSseEnd('not-found'); }
} else {
	$s = $pdo->prepare("SELECT s.id, s.project_id, p.slug FROM hv_sessions s JOIN hv_projects p ON p.id = s.project_id WHERE s.id = ?");
	$s->execute([$sessionId]);
	$row = $s->fetch(PDO::FETCH_ASSOC);
	if (!$row) { vibesSseObj(['type' => 'error', 'error' => "No session with id $sessionId."]); vibesSseEnd('not-found'); }
	$file = vibesSessionLog($row['slug'], $sessionId);
	// A session's "writer" is whichever job is open on its project; between turns there is none,
	// and then the stream simply idles until the client sends a turn (it reconnects on its own).
	$projectId = (int)$row['project_id'];
	$watchJob = fn() => vibesJobOpen($pdo, $projectId);
}

vibesSseObj(['type' => 'hello', 'after' => $after, 'file' => basename($file)]);

$offset     = 0;
$carry      = '';            // an incomplete trailing line, waiting for its newline
$lastSeq    = $after;
$lastPing   = time();
$lastRow    = time();
$lastStatus = null;
$started    = time();
$skipping   = $after > 0;    // true until we pass the client's last seq

while (true) {
	if (connection_aborted()) exit;
	if (time() - $started > 7200) vibesSseEnd('timeout');

	clearstatcache(true, $file);
	if (is_file($file)) {
		$size = filesize($file);
		if ($size < $offset) { $offset = 0; $carry = ''; }       // truncated/rotated: start over
		if ($size > $offset) {
			$fh = @fopen($file, 'r');
			if ($fh) {
				fseek($fh, $offset);
				$chunk = stream_get_contents($fh);
				fclose($fh);
				$offset += strlen($chunk);
				$buf = $carry . $chunk;
				$lines = explode("\n", $buf);
				$carry = array_pop($lines);                        // '' when the chunk ended cleanly
				foreach ($lines as $line) {
					$line = rtrim($line, "\r");
					if ($line === '') continue;
					$ev = json_decode($line, true);
					if (!is_array($ev)) continue;                   // never forward a line that is not JSON
					$seq = (int)($ev['seq'] ?? 0);
					if ($skipping && $seq <= $after) continue;
					$skipping = false;
					$lastSeq = max($lastSeq, $seq);
					vibesSseRaw($line);
					$type = $ev['type'] ?? '';
					// A job stream is over at done/error. A SESSION stream outlives a turn: the
					// next turn appends to the same file, so only the client decides when to leave.
					if ($jobId > 0 && ($type === 'done' || $type === 'error')) vibesSseEnd($type);
				}
			}
		}
	}

	// The row, once a second: cheap, and it is the only tell when the runner died silently.
	if (time() - $lastRow >= 1) {
		$lastRow = time();
		$j = $watchJob();
		$status = $j ? $j['status'] : 'none';
		if ($status !== $lastStatus) {
			$lastStatus = $status;
			vibesSseObj(['type' => 'job', 'status' => $status, 'job' => $j]);
		}
		if ($jobId > 0 && $j && !in_array($j['status'], ['queued', 'running'], true)) {
			// Give the file one more read so a `done` written a moment after the row update is not lost.
			usleep(300000);
			clearstatcache(true, $file);
			if (!is_file($file) || filesize($file) <= $offset) vibesSseEnd('job-' . $j['status']);
			continue;
		}
	}

	if (time() - $lastPing >= 15) {
		$lastPing = time();
		echo ": ping\n\n";
		@ob_flush();
		@flush();
	}
	usleep(250000);
}
