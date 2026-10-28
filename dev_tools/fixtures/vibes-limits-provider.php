<?php
// Deterministic provider fixture. Never contacts an external model.
$mode = getenv('LIMIT_MODE');
$kind = getenv('LIMIT_KIND');
$input = json_decode(file_get_contents('php://input'), true);
$n = (int)@file_get_contents(__DIR__.'/count') + 1;
file_put_contents(__DIR__.'/count', (string)$n);
file_put_contents(__DIR__.'/request-'.$n.'.json', json_encode($input));
if ($mode === 'retry' && $n === 1) {
    usleep(600000); http_response_code(400);
    echo json_encode(['error'=>['message'=>'Developer instruction is not enabled']]); exit;
}
$stream = isset($input['stream']) ? $input['stream'] : str_contains($_SERVER['REQUEST_URI'], 'alt=sse');
header('Content-Type: '.($stream ? 'text/event-stream' : 'application/json'));
function sendEvent($j) { echo 'data: '.json_encode($j)."\n\n"; ob_flush(); flush(); }
$parts = [['text'=>'Partial response'], ['functionCall'=>['name'=>'write_file','args'=>['path'=>'partial.txt','content'=>'must not execute']], 'thoughtSignature'=>'fixture-signature']];
if (in_array($mode, ['partial','stall','retry'], true)) {
    if ($mode === 'partial') {
        if ($kind === 'gemini') sendEvent(['candidates'=>[['content'=>['parts'=>$parts]]]]);
        elseif ($kind === 'anthropic') sendEvent(['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>'Partial response']]);
        else sendEvent(['choices'=>[['delta'=>['content'=>'Partial response']]]]);
    }
    sleep(45); exit;
}
if ($mode === 'slow') usleep(250000);
$tool = $mode === 'loop' || ($mode === 'complete' && $n === 1);
$parts = $tool ? [['functionCall'=>['name'=>'write_file','args'=>['path'=>'saved.txt','content'=>'saved before limit']], 'thoughtSignature'=>'fixture-signature']] : [['text'=>'Finished.']];
if ($kind === 'gemini') {
    $j=['candidates'=>[['content'=>['role'=>'model','parts'=>$parts], 'finishReason'=>'STOP']], 'usageMetadata'=>['promptTokenCount'=>10,'candidatesTokenCount'=>3]];
    if ($stream) sendEvent($j); else echo json_encode($j);
} elseif ($kind === 'anthropic') {
    if ($stream) { sendEvent(['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>'Finished.']]); sendEvent(['type'=>'message_stop']); }
    else echo json_encode(['content'=>[['type'=>'text','text'=>'Finished.']]]);
} else {
    if ($stream) { sendEvent(['choices'=>[['delta'=>['content'=>'Finished.']]]]); echo "data: [DONE]\n\n"; }
    else echo json_encode(['choices'=>[['message'=>['content'=>'Finished.']]]]);
}
