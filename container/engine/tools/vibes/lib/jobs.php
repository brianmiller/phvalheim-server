<?php
/**
 * Hugin's Vibes -- job rows and the event stream (design sections 6, 7.3).
 *
 * A job is a row first and a detached process second. The UI learns everything from the row
 * (step, progress, status) and from the JSONL file logs/jobs/<id>.jsonl, never from the process,
 * so a page reload mid-install loses nothing. Every event has a monotonically increasing `seq`
 * and a float `t`, because the SSE tailer resumes with `after=<seq>`.
 *
 * For a `turn` job the SAME events also go to the session transcript
 * (projects/<slug>/.vibes/sessions/<id>.jsonl) -- the transcript gets every event, the job log
 * gets only status/done/error, so the job log stays a progress record and the transcript is the
 * conversation.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';

class HvEmitter {
	private $jobFile;
	private $jobSeq = 0;
	private $jobOnly;          // event types the job log receives when a transcript is attached
	private $sessionFile = null;
	private $sessionSeq = 0;
	public  $last = null;      // the last event written, for tests and the CLI

	public function __construct($jobFile, $sessionFile = null) {
		$this->jobFile = $jobFile;
		@mkdir(dirname($jobFile), 0775, true);
		$this->jobSeq = self::lastSeq($jobFile);
		$this->jobOnly = ['status', 'done', 'error'];
		if ($sessionFile !== null) $this->attachSession($sessionFile);
	}

	public function attachSession($file) {
		@mkdir(dirname($file), 0775, true);
		$this->sessionFile = $file;
		$this->sessionSeq  = self::lastSeq($file);
	}

	/** The last seq in a JSONL file, so a resumed session or re-run job never repeats a number. */
	public static function lastSeq($file) {
		if (!is_file($file) || filesize($file) === 0) return 0;
		$fh = fopen($file, 'r');
		if (!$fh) return 0;
		// Read the tail only: the last line is what carries the highest seq.
		$size = filesize($file);
		fseek($fh, max(0, $size - 65536));
		$tail = stream_get_contents($fh);
		fclose($fh);
		$lines = array_filter(explode("\n", $tail), 'strlen');
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$e = json_decode($lines[$i], true);
			if (is_array($e) && isset($e['seq'])) return (int)$e['seq'];
		}
		return 0;
	}

	/** Append one event. $event['type'] is required; seq and t are added here. */
	public function emit(array $event) {
		$type = $event['type'] ?? 'status';
		$t = round(microtime(true), 3);
		if ($this->sessionFile !== null) {
			$e = ['seq' => ++$this->sessionSeq, 't' => $t] + $event;
			self::append($this->sessionFile, $e);
			$this->last = $e;
		}
		if ($this->sessionFile === null || in_array($type, $this->jobOnly, true)) {
			$e = ['seq' => ++$this->jobSeq, 't' => $t] + $event;
			self::append($this->jobFile, $e);
			$this->last = $e;
		}
	}

	private static function append($file, $e) {
		$line = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($line === false) $line = json_encode(['seq' => $e['seq'], 't' => $e['t'], 'type' => 'error', 'error' => 'unencodable event']);
		@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
	}
}

/**
 * Take ownership of a queued row: status running, our pid, started. Returns the row or null when
 * it is not ours to run (already claimed, cancelled, unknown). A row the CLI inserted itself for
 * an inline run is claimed the same way -- one path.
 */
function hvJobClaim($pdo, $id) {
	$s = $pdo->prepare("UPDATE hv_jobs SET status='running', pid=?, started=NOW(), step='Starting', progress=-1 WHERE id=? AND status='queued'");
	$s->execute([getmypid(), (int)$id]);
	if ($s->rowCount() !== 1) return null;
	return vibesJob($pdo, $id);
}

function hvJobStep($pdo, $id, HvEmitter $emit, $step, $progress = -1, $detail = '') {
	$pdo->prepare("UPDATE hv_jobs SET step=?, progress=? WHERE id=?")->execute([vibesSubstr($step, 0, 64), (int)$progress, (int)$id]);
	$ev = ['type' => 'status', 'step' => $step, 'progress' => (int)$progress];
	if ($detail !== '') $ev['detail'] = $detail;
	$emit->emit($ev);
}

/** Progress only (no step change, no event unless the integer percent moved). */
function hvJobProgress($pdo, $id, HvEmitter $emit, $step, $progress, $detail = '') {
	static $lastPct = [];
	$p = (int)$progress;
	if (($lastPct[$id] ?? -2) === $p) return;
	$lastPct[$id] = $p;
	hvJobStep($pdo, $id, $emit, $step, $p, $detail);
}

function hvJobFinish($pdo, $id, HvEmitter $emit, array $result = []) {
	$pdo->prepare("UPDATE hv_jobs SET status='done', progress=100, finished=NOW(), result=? WHERE id=? AND status='running'")
	    ->execute([json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$id]);
	$emit->emit(['type' => 'done'] + $result);
}

function hvJobFail($pdo, $id, HvEmitter $emit, $error) {
	$error = (string)$error;
	$pdo->prepare("UPDATE hv_jobs SET status='failed', finished=NOW(), error=? WHERE id=? AND status IN ('running','queued')")
	    ->execute([vibesSubstr($error, 0, 500), (int)$id]);
	$emit->emit(['type' => 'error', 'error' => $error]);
}

/** Re-read the row: the UI cancels by flipping status, and the loop checks between steps. */
function hvIsCancelled($pdo, $id) {
	try {
		$st = $pdo->prepare("SELECT status FROM hv_jobs WHERE id=?");
		$st->execute([(int)$id]);
		$v = $st->fetchColumn();
		return $v === false || $v === 'cancelled';
	} catch (Throwable $e) { return false; }
}

/** Thrown by a step that noticed a cancel; the runner turns it into a clean exit, not an error. */
class HvCancelled extends RuntimeException {}

/** phvEvent with source 'vibes', never letting the log break the job (events.php's own rule). */
function hvEvent($pdo, $level, $message, $detail = null) {
	if (!function_exists('phvEvent')) require_once '/opt/stateless/nginx/www/includes/events.php';
	try {
		phvEventSource('vibes');
		return phvEvent($pdo, $level, $message, null, $detail);
	} catch (Throwable $e) { return false; }
}

/** One line into logs/vibes.log in the engine's classic format, which the log viewer already parses. */
function hvLog($level, $message) {
	$line = date('D M j H:i:s T Y') . " [$level : vibes] $message\n";
	@file_put_contents(vibesLogsDir() . '/vibes.log', $line, FILE_APPEND | LOCK_EX);
}
