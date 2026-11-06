<?php
// Local integration fixture only. Configuration and captured requests never contain real keys.
$c=json_decode(file_get_contents(__DIR__.'/hugin-provider.json'),true) ?: [];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_contains($path,'models') && $_SERVER['REQUEST_METHOD']==='GET') {
    if(!empty($c['discovery_delay'])) usleep((int)($c['discovery_delay']*1000000));
    if(!empty($c['fail'])) {http_response_code(503);echo '{"error":{"message":"fixture offline"}}';exit;}
    echo json_encode(['data'=>$c['models'] ?? [['id'=>'old-model','context_length'=>64000]]]);exit;
}
$in=json_decode(file_get_contents('php://input'),true) ?: [];
file_put_contents(__DIR__.'/hugin-requests.jsonl',json_encode(['path'=>$path,'input'=>$in])."\n",FILE_APPEND | LOCK_EX);
if(!empty($c['delay']))usleep((int)($c['delay']*1000000));
$text='Fixture reply.';
if(str_contains(json_encode($in),'Summarize'))$text='Earlier decisions retained in a short summary.';
$stream=!empty($in['stream']) || str_contains($_SERVER['REQUEST_URI'],'alt=sse');
function ev($data){echo 'data: '.json_encode($data)."\n\n";}
if($stream)header('Content-Type: text/event-stream');
if(str_contains($path,'generateContent') || str_contains($path,'streamGenerateContent')) {
 $r=['candidates'=>[['content'=>['role'=>'model','parts'=>[['text'=>$text]]],'finishReason'=>'STOP']]];
 if($stream)ev($r);else echo json_encode($r);
} elseif(str_contains($path,'messages')) {
 if($stream){ev(['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>$text]]);ev(['type'=>'message_stop']);}
 else echo json_encode(['content'=>[['type'=>'text','text'=>$text]]]);
} else {
 if($stream){ev(['choices'=>[['delta'=>['content'=>$text]]]]);echo "data: [DONE]\n\n";}
 else echo json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>$text]]]]);
}
