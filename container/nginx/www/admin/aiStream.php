<?php
/**
 * Server-Sent Events endpoint for the AI Helper.
 *
 * Separate from adminAPI.php because that file sets Content-Type: application/json at
 * the top for every action. A streaming response needs text/event-stream and needs to
 * flush incrementally, which is the opposite of what the rest of the API wants.
 *
 * Normalised event shape, regardless of which provider is behind it:
 *   {"type":"start"}
 *   {"type":"tool",  "name":"read_log", "args":{...}}
 *   {"type":"delta", "text":"..."}
 *   {"type":"done",  "usage":{...}, "model":"...", "trace":[...]}
 *   {"type":"error", "error":"..."}
 *
 * The request arrives as a POST body; EventSource cannot POST, so the client uses fetch()
 * with a streaming reader rather than EventSource. The wire format is still SSE so the
 * framing logic is conventional and debuggable with curl.
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/aiproviders.php';
require_once '/opt/stateless/nginx/www/includes/aicontext.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// nginx buffers proxied responses by default, which holds the whole stream until the
// request completes and defeats the point. This header is the documented opt-out.
header('X-Accel-Buffering: no');

// PHP's own output buffering has to go too, or ob_flush() below has nothing to flush
// through and the deltas still arrive in one lump.
while (ob_get_level() > 0) ob_end_flush();
ignore_user_abort(false);

function sse($obj) {
    $json = json_encode($obj);

    // json_encode returns FALSE on malformed UTF-8, and `'data: ' . false` is `'data: '` --
    // a frame with no payload. The browser's JSON.parse throws, the catch does `continue`,
    // and the text is gone with no error anywhere. Measured: a reply containing an em dash,
    // fragmented on byte boundaries, rendered as "a change ts happened yet." -- two deltas
    // silently deleted mid-sentence.
    //
    // Substituting is strictly better than dropping: worst case the operator sees one
    // replacement character instead of losing a clause. The carry buffer below is what
    // normally prevents it getting here at all.
    if ($json === false) {
        $json = json_encode($obj, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return;   // nothing sane left to send
    }

    echo 'data: ' . $json . "\n\n";
    @ob_flush();
    @flush();
}

/**
 * Hold back an incomplete trailing UTF-8 sequence so it can be joined to the next chunk.
 *
 * A provider is free to end a stream chunk in the middle of a multibyte character -- the
 * bytes are correct, the split is just inconvenient -- and re-encoding half a character is
 * what breaks sse() above. Returns the safe prefix and stores the remainder for next time.
 */
function aiUtf8Carry($text) {
    static $carry = '';

    $text  = $carry . $text;
    $carry = '';
    $len   = strlen($text);

    // Walk back over at most 3 continuation bytes to find the last lead byte, and if the
    // sequence it starts is not complete yet, hold it.
    for ($i = 1; $i <= 3 && $i <= $len; $i++) {
        $b = ord($text[$len - $i]);
        if (($b & 0xC0) === 0x80) continue;              // continuation byte, keep looking
        $need = ($b & 0xE0) === 0xC0 ? 2
              : (($b & 0xF0) === 0xE0 ? 3
              : (($b & 0xF8) === 0xF0 ? 4 : 1));
        if ($need > $i) {                                 // started but not finished
            $carry = substr($text, $len - $i);
            $text  = substr($text, 0, $len - $i);
        }
        break;
    }
    return $text;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sse(['type' => 'error', 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$message    = trim((string)($input['message'] ?? ''));
$history    = is_array($input['history'] ?? null) ? $input['history'] : [];
$world      = (string)($input['world'] ?? '');
$providerId = (int)($input['provider_id'] ?? 0);
$modelOver  = trim((string)($input['model'] ?? ''));

if ($message === '') {
    sse(['type' => 'error', 'error' => 'No message']);
    exit;
}

$provider = $providerId ? aiProvider($pdo, $providerId) : aiDefaultProvider($pdo);
if (!$provider) {
    sse(['type' => 'error', 'error' => 'No AI provider is configured. Add one from Server Settings → AI Helper.']);
    exit;
}
if (!$provider['enabled']) {
    sse(['type' => 'error', 'error' => "Provider '{$provider['label']}' is disabled."]);
    exit;
}

// A model chosen in the panel's dropdown overrides the provider's pinned default for
// this turn only. It is used VERBATIM — the whole point of 2.45 is that we never second
// guess a model id against a list of our own (see issue #83).
if ($modelOver !== '') $provider['model'] = $modelOver;

if (trim($provider['model']) === '') {
    sse(['type' => 'error', 'error' => "No model selected for '{$provider['label']}'. Pick one in the panel header, or set a default in Server Settings."]);
    exit;
}

// Rebuild the conversation. Only plain user/assistant turns are accepted from the
// browser: tool calls and their results are re-derived server-side each turn, so a
// crafted history cannot inject a fake tool result the model would then trust.
$messages = [];
foreach (array_slice($history, -20) as $h) {
    $role = $h['role'] ?? '';
    $text = trim((string)($h['content'] ?? ''));
    if ($text === '' || !in_array($role, ['user', 'assistant'], true)) continue;
    $messages[] = ['role' => $role, 'content' => $text];
}
$messages[] = ['role' => 'user', 'content' => $message];

sse(['type' => 'start', 'provider' => $provider['label'], 'model' => $provider['model']]);

$result = aiConverse(
    $pdo,
    $provider,
    $messages,
    $world,
    function ($text) {
        $safe = aiUtf8Carry($text);
        if ($safe !== '') sse(['type' => 'delta', 'text' => $safe]);
    },
    function ($name, $args) { sse(['type' => 'tool', 'name' => $name, 'args' => $args]); }
);

// Confirm-cards raised this turn, each with its single-use token.
//
// Sent on BOTH paths. If the model proposed a change and then the turn died -- a timeout,
// a gateway 500 -- the proposal row is already written and valid. Withholding it would
// leave the operator with an error message and a pending change they were never shown,
// and the card is self-describing anyway: its summary is rendered server-side from the
// validated parameters, not from anything the model said.
// aiConverse() already read-and-CLEARED the collector on its success paths, so its return
// value is authoritative there. Calling aiProposalsCollect() unconditionally would hand back
// an empty list and no card would ever appear. Only the error paths, which return before
// collecting, need the direct read.
$proposals = $result['proposals']
          ?? (function_exists('aiProposalsCollect') ? aiProposalsCollect() : []);

if (!$result['success']) {
    sse([
        'type'      => 'error',
        'error'     => $result['error'],
        'trace'     => $result['trace'] ?? [],
        'proposals' => $proposals,
    ]);
} else {
    sse([
        'type'  => 'done',
        'usage' => $result['usage'] ?? null,
        'model' => $result['model'] ?? $provider['model'],
        'trace' => $result['trace'] ?? [],
        // The accumulated text is sent again so a client that dropped a delta mid-stream
        // can reconcile against the authoritative copy rather than render a gap.
        'content'   => $result['content'] ?? '',
        'proposals' => $proposals,
        // '' normally; 'text' when the endpoint refused the tools parameter and the reply
        // was produced without any ability to inspect the server.
        'degraded'  => $result['degraded'] ?? '',
    ]);
}
