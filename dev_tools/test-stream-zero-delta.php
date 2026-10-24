<?php
/**
 * Oracle test: a streamed delta that is exactly "0" survives (issue #94, 2.59).
 *
 * THE BUG. The OpenAI-compatible adapter guarded each streamed fragment with
 * `!empty($delta['content'])`. PHP's empty("0") is TRUE, so any chunk that was exactly the
 * single character "0" was dropped from the accumulated reply AND from the live stream. A model
 * that tokenises digits one at a time therefore lost every zero: "2026-10-24 13:40:20" became
 * "226-1-24 13:4:2" (the reporter's screenshots), in quoted log lines and in Hugin's prose.
 *
 * HOW. A throw-away PHP built-in server plays an OpenAI-compatible /chat/completions that
 * streams the reply one character per SSE event, zeros included. The real aiChat() is pointed at
 * it. The CONTROL streams the same text with no zeros; it must come back unchanged whether the
 * guard is right or wrong, which proves the measurement is of the zero, not of the stream.
 */
// Run by dev_tools/test-stream-zero-delta.sh INSIDE the container: the host's php has no curl
// extension and aiHttp() is curl. AIPROVIDERS_PHP names the copy under test (the wrapper copies
// the working tree's file in, so a build is not needed to test a change to it).
$root = dirname(__DIR__);
$aiproviders = getenv('AIPROVIDERS_PHP') ?: "$root/container/nginx/www/includes/aiproviders.php";
if (!defined('AI_HTTP_TIMEOUT')) define('AI_HTTP_TIMEOUT', 20);
require_once $aiproviders;
if (!function_exists('curl_init')) { echo "HARNESS: this php has no curl; run dev_tools/test-stream-zero-delta.sh instead\n"; exit(2); }

$pass = 0; $fail = 0;
function ok($m)      { global $pass; echo "  PASS  $m\n"; $pass++; }
function bad($m, $d = '') { global $fail; echo "  FAIL  $m\n"; if ($d !== '') echo "        $d\n"; $fail++; }

// --- the fake provider ---------------------------------------------------------------------
$docroot = sys_get_temp_dir() . '/phv-zero-delta-' . getmypid();
@mkdir($docroot, 0700, true);
file_put_contents("$docroot/index.php", <<<'PHP'
<?php
// One route: POST /chat/completions. The text to stream comes from the model name so a single
// server serves both the measurement and the control.
$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$text = (string)base64_decode(str_replace('b64-', '', $in['model'] ?? ''));
if (empty($in['stream'])) {
    header('Content-Type: application/json');
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
    exit;
}
header('Content-Type: text/event-stream');
$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
foreach ($chars as $c) {
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $c], 'index' => 0]]]) . "\n\n";
}
echo 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => count($chars)]]) . "\n\n";
echo "data: [DONE]\n\n";
PHP
);
$port = 18000 + (getmypid() % 2000);
$cmd  = sprintf('php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!', $port, escapeshellarg($docroot));
$pid  = (int)trim(shell_exec($cmd));
usleep(400000);
register_shutdown_function(function () use ($pid, $docroot) { if ($pid) @posix_kill($pid, 15); @unlink("$docroot/index.php"); @rmdir($docroot); });

function provider($port, $text) {
    return ['id' => 0, 'kind' => 'openai_compatible', 'label' => 'fixture', 'base_url' => "http://127.0.0.1:$port",
            'api_key' => '', 'model' => 'b64-' . base64_encode($text), 'enabled' => 1, 'extra_headers' => ''];
}
function run($port, $text) {
    $deltas = [];
    $r = aiChat(provider($port, $text), [['role' => 'user', 'content' => 'go']], 'test', [], function ($d) use (&$deltas) { $deltas[] = $d; });
    return [$r, implode('', $deltas)];
}

echo "=== streamed zeros (issue #94) ===\n";
// control: no zeros -- passes with the old guard and the new one, so it pins the harness
[$r, $streamed] = run($port, "226-1-24 13:4:2 ok");
if (!$r['success']) { bad("control: the fixture provider answers", $r['error'] ?? ''); echo "=== $pass passed, $fail failed ===\n"; exit(1); }
$r['content'] === "226-1-24 13:4:2 ok" ? ok("control: a zero-free reply streams back unchanged") : bad("control reply", $r['content']);
$streamed === "226-1-24 13:4:2 ok"     ? ok("control: the deltas concatenate to the same text")   : bad("control deltas", $streamed);

// the measurement: zeros streamed as lone single-character chunks
$want = "2026-10-24 13:40:20 total 1000 zeros 00000";
[$r, $streamed] = run($port, $want);
$r['content'] === $want ? ok("accumulated reply keeps every zero: '$want'") : bad("accumulated reply lost zeros", "got '{$r['content']}'");
$streamed === $want     ? ok("every streamed delta reached the caller, '0' included")   : bad("stream dropped the lone-zero deltas", "got '$streamed'");
$nz = substr_count($want, '0');
substr_count($r['content'], '0') === $nz ? ok("$nz zeros in, $nz zeros out") : bad("zero count", substr_count($r['content'], '0') . " of $nz");

// a reply that IS just "0"
[$r, $streamed] = run($port, "0");
$r['content'] === "0" && $streamed === "0" ? ok("a reply consisting of the single character '0' is not an empty reply") : bad("lone '0' reply", "content='{$r['content']}' streamed='$streamed'");

// the shipped guard, by its text: the regression is one !empty away
$src = file_get_contents($aiproviders);
strpos($src, "!empty(\$delta['content'])") === false ? ok("no !empty() guard on a streamed content delta") : bad("!empty guard is back on the content delta");

echo "=== $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
