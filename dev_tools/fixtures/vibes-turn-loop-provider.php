<?php
// Deterministic Gemini-shaped provider for test-vibes-turn-limit-e2e.sh. Never contacts a model.
//   mode      loop   = every reply asks for one more write_file (a model that never finishes)
//             finish = a plain text answer
//   delay_ms  how long one reply takes, so a turn can be made to outlast the page's state poll
// Both are files so the test can change them between turns without restarting this server.
$dir = '/tmp/vibes-turn-fixture';
$mode = trim((string)@file_get_contents("$dir/mode")) ?: 'loop';
$delay = (int)@file_get_contents("$dir/delay_ms");
$input = json_decode((string)file_get_contents('php://input'), true);
$uri = (string)$_SERVER['REQUEST_URI'];
if (!str_contains($uri, 'enerateContent')) {   // model discovery
	header('Content-Type: application/json');
	echo json_encode(['models' => [['name' => 'models/fixture-flash', 'supportedGenerationMethods' => ['generateContent']]]]);
	exit;
}
$n = (int)@file_get_contents("$dir/count") + 1;
file_put_contents("$dir/count", (string)$n);
usleep($delay * 1000);
// A request with no tools is the summariser (compaction), which must get prose back.
$tool = $mode === 'loop' && !empty($input['tools']);
$parts = $tool
	? [['functionCall' => ['name' => 'write_file', 'args' => ['path' => 'notes/loop.txt', 'content' => "round $n\n"]], 'thoughtSignature' => 'fixture-signature']]
	: [['text' => $mode === 'loop' ? 'Summary of the earlier work.' : 'Finished.']];
$j = ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP']],
      'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 3]];
$stream = str_contains($uri, 'alt=sse');
header('Content-Type: ' . ($stream ? 'text/event-stream' : 'application/json'));
echo $stream ? 'data: ' . json_encode($j) . "\n\n" : json_encode($j);
