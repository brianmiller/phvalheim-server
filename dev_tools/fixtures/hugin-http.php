<?php
require '/opt/stateless/nginx/www/includes/aiproviders.php';
define('AI_LOCK_DIR','/tmp/hugin-http-locks');
$n=0;
function ck($ok,$what){global $n;$n++;if(!$ok)throw new RuntimeException($what);}
function config($c){file_put_contents('/tmp/hugin-fixture/hugin-provider.json',json_encode($c));}
$limits=aiValidateLimits(['request_timeout_s'=>5,'response_tokens'=>777]);
foreach(['openai_compatible','anthropic','gemini'] as $kind) {
 $p=['id'=>501,'kind'=>$kind,'model'=>'fixture','base_url'=>'http://127.0.0.1:18999','api_key'=>'','runtime_limits'=>$limits];
 config([]);$r=aiChat($p,[['role'=>'user','content'=>'hello']],'system');ck($r['success'], $kind.' basic call');
 $rows=file('/tmp/hugin-fixture/hugin-requests.jsonl');$last=json_decode(end($rows),true)['input'];
 $max=$kind==='gemini' ? $last['generationConfig']['maxOutputTokens'] : $last['max_tokens'];ck($max===777,$kind.' output budget applied');
 config(['delay'=>6]);$before=microtime(true);$r=aiChat($p,[['role'=>'user','content'=>'hello']],'system');
 ck(!$r['success'] && ($r['stop_reason']??'')==='model_timeout',$kind.' configured timeout');ck(microtime(true)-$before<5.8,$kind.' bounded duration');
 // Wait for the single-threaded mock to finish the aborted request, then prove a longer limit succeeds.
 sleep(2);$p['runtime_limits']['request_timeout_s']=10;$r=aiChat($p,[['role'=>'user','content'=>'hello']],'system');ck($r['success'],$kind.' longer response limit succeeds');
 config([]);$p['_deadline']=microtime(true)-1;$r=aiChat($p,[['role'=>'user','content'=>'hello']],'system');ck(($r['stop_reason']??'')==='turn_timeout',$kind.' expired turn refused');
 unset($p['_deadline']);$p['_isCancelled']=fn()=>true;$r=aiChat($p,[['role'=>'user','content'=>'hello']],'system');ck(($r['stop_reason']??'')==='cancelled',$kind.' cancel refused');
}
config(['discovery_delay'=>2]);$p=['kind'=>'openai_compatible','base_url'=>'http://127.0.0.1:18999/v1','runtime_limits'=>aiValidateLimits(['discovery_timeout_s'=>1])];
$before=microtime(true);$r=aiDiscoverModels($p);ck(!$r['success'] && microtime(true)-$before<1.8,'discovery timeout independent');sleep(2);config([]);
// Summary and final request both go through the real adapter and shared output/context bounds.
$p=['id'=>501,'kind'=>'openai_compatible','model'=>'fixture','base_url'=>'http://127.0.0.1:18999/v1','runtime_limits'=>aiValidateLimits(['context_tokens'=>10000,'response_tokens'=>777,'compact_percent'=>25])];
$events=[];$p['_onRuntime']=function($e)use(&$events){$events[]=$e;};
$r=aiChat($p,[['role'=>'user','content'=>str_repeat('history ',3000)],['role'=>'user','content'=>'latest request']],'system');
ck($r['success'] && in_array('compaction',array_column($events,'type')),'real transport compaction');
echo "$n HTTP adapter checks passed\n";
