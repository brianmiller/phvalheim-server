<?php
// Mock OpenAI-compatible provider, served by `php -S` inside the test container.
// First chat call asks for a tool; the second answers. Streams SSE when stream=true.
//
// Payloads are built as named variables rather than one deeply nested literal: the
// first version of this file was a single six-level array expression and was missing
// one closing bracket, which cost a debugging round trip.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

function sse($obj) { echo 'data: ' . json_encode($obj) . "\n\n"; }

if ($path === '/v1/models') {
    if ($auth !== 'Bearer testkey') {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Incorrect API key provided.']]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['data' => [
        ['id' => 'mock-small', 'context_length' => 8192],
        ['id' => 'mock-large', 'max_model_len' => 128000],
    ]]);
    exit;
}

if ($path === '/v1/chat/completions') {
    $in = json_decode(file_get_contents('php://input'), true);

    $sawTool = false;
    $lastUser = '';
    foreach (($in['messages'] ?? []) as $m) {
        if (($m['role'] ?? '') === 'tool') $sawTool = true;
        if (($m['role'] ?? '') === 'user') $lastUser = (string)($m['content'] ?? '');
    }

    // Which tool to ask for is driven by the OPERATOR's words, so one mock serves both the
    // read-only path and the action path. "stop <world>" exercises a confirm-card proposal;
    // anything else falls back to the diagnostics call the earlier suites rely on.
    $toolName = 'get_diagnostics';
    $toolArgs = '{}';
    $answer   = 'I read the diagnostics. Here is what I found.';
    if (preg_match('/\bstop\s+([A-Za-z0-9_-]+)/i', $lastUser, $mm)) {
        $toolName = 'stop_world';
        $toolArgs = json_encode(['world' => $mm[1]]);
        $answer   = 'I have put that to you as a change to confirm — nothing has happened yet.';
    }

    // One tool call, expressed in layers.
    $fn       = ['name' => $toolName, 'arguments' => $toolArgs];
    $toolCall = ['id' => 'call_1', 'type' => 'function', 'function' => $fn];

    if (!empty($in['stream'])) {
        header('Content-Type: text/event-stream');
        if (!$sawTool) {
            // Name and arguments arrive as separate fragments, as real providers do.
            $frag1 = ['index' => 0, 'id' => 'call_1', 'function' => ['name' => $toolName, 'arguments' => '']];
            $frag2 = ['index' => 0, 'function' => ['arguments' => $toolArgs]];
            sse(['choices' => [['delta' => ['tool_calls' => [$frag1]]]]]);
            sse(['choices' => [['delta' => ['tool_calls' => [$frag2]]]]]);
        } else {
            // Split on CHARACTERS, not bytes: str_split cuts the em dash in half and the
            // fragment cannot then be json_encoded. That is a bug in the mock -- a real
            // provider streams whole characters -- and the product's own byte-split handling
            // is owned deliberately by dev_tools/test-ai-stream-utf8.sh.
            //
            // preg_split with /u rather than mb_str_split: THIS IMAGE HAS NO MBSTRING
            // (`extension_loaded("mbstring")` is false), so any mb_* call is a fatal. PCRE
            // has its own UTF-8 mode and is always present.
            $chars  = preg_split('//u', $answer, -1, PREG_SPLIT_NO_EMPTY);
            $chunks = array_map(function ($c) { return implode('', $c); }, array_chunk($chars, 6));
            foreach ($chunks as $f) {
                sse(['choices' => [['delta' => ['content' => $f]]]]);
            }
            sse(['usage' => ['total_tokens' => 123]]);
        }
        echo "data: [DONE]\n\n";
        exit;
    }

    header('Content-Type: application/json');
    if (!$sawTool) {
        $msg = ['content' => null, 'tool_calls' => [$toolCall]];
    } else {
        $msg = ['content' => $answer];
    }
    echo json_encode([
        'model'   => $in['model'] ?? '',
        'usage'   => ['total_tokens' => 123],
        'choices' => [['message' => $msg]],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'no such path ' . $path]]);
