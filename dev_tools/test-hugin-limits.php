<?php
require (getenv('PHV_AI_SOURCE') ?: dirname(__DIR__).'/container/nginx/www/includes').'/aiproviders.php';
$checks=0;
function check($yes,$message) {global $checks;$checks++;if(!$yes)throw new RuntimeException($message);}
$l=aiLimits([]);check($l['request_timeout_s']===120,'default request timeout');
foreach(aiLimitSpec() as $key=>[$label,$default,$min,$max]) {
 foreach([$min-1,$max+1,'no',false] as $bad) {try{aiValidateLimits([$key=>$bad]);throw new Exception('accepted invalid '.$key);}catch(InvalidArgumentException $e){$checks++;}}
}
try{aiValidateLimits(['context_tokens'=>4000,'response_tokens'=>3000]);throw new Exception('invalid reserve');}catch(InvalidArgumentException $e){$checks++;}
$p=['id'=>37,'model'=>'example','runtime_limits'=>aiValidateLimits(['context_tokens'=>10000,'response_tokens'=>1000,'queue_timeout_s'=>1,'concurrency'=>1])];
check(aiContextBudget($p+['_models'=>[['id'=>'example','context'=>8000]]])===8000,'reported capacity caps manual budget');
check(aiContextBudget($p+['_models'=>[['id'=>'different','context'=>4000]]])===10000,'other model does not cap selection');
$events=[];$p['_onRuntime']=function($e)use(&$events){$events[]=$e;};
$msg=[['role'=>'user','content'=>str_repeat('old context ',2200)],['role'=>'assistant','content'=>'previous answer'],['role'=>'user','content'=>'Keep my latest request'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'c1']],'provider_raw'=>['opaque'=>'signature']],['role'=>'tool','tool_call_id'=>'c1','content'=>'result']];
$summaryCalls=0;
$summary=function($provider,$messages,$system,$tools,$delta,$tokens)use(&$summaryCalls,$p){$summaryCalls++;check($provider['_compacting']===true,'summary recursion disabled');check(aiEstimateContext($messages,$system)+$tokens<=aiContextBudget($p),'summary itself fits');return ['success'=>true,'content'=>'Earlier decisions retained.'];};
$out=aiFitContext($p,$msg,'system',[['name'=>'a_tool']],1000,$summary);
check($summaryCalls===1,'summarized long history');
check($out[1]===$msg[2] && $out[2]===$msg[3] && $out[3]===$msg[4],'latest task and whole opaque tool exchange preserved');
check(aiEstimateContext($out,'system')+1000<=10000,'result fits');
check(in_array('memory',array_column($events,'type')),'summary persists to chat history');
$small=[['role'=>'user','content'=>'short']];check(aiFitContext($p,$small,'system',[],1000,$summary)===$small,'small control retained');
try{aiFitContext($p,[['role'=>'user','content'=>str_repeat('X',30000)]],'system',[],1000,$summary);throw new Exception('oversize newest task accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'exceed'),'oversize latest request refused');}
// Different compaction thresholds change behavior before overflow.
$medium=[['role'=>'user','content'=>str_repeat('old ',3500)],['role'=>'user','content'=>'latest']];
$p['runtime_limits']['compact_percent']=90;$before=$summaryCalls;aiFitContext($p,$medium,'system',[],1000,$summary);check($before===$summaryCalls,'90 percent threshold keeps history');
$p['runtime_limits']['compact_percent']=25;aiFitContext($p,$medium,'system',[],1000,$summary);check($before+1===$summaryCalls,'25 percent threshold compacts');
$near=[['role'=>'user','content'=>'old'],['role'=>'user','content'=>str_repeat('X',26000)]];
check(aiFitContext($p,$near,'system',[],1000,$summary)===$near,'soft compaction threshold never rejects fitting fixed context');
$dir=sys_get_temp_dir().'/phv-ai-locks-'.getmypid();define('AI_LOCK_DIR',$dir);
try {
 $slot=aiAcquireSlot($p);check(is_resource($slot),'first slot acquired');
 $start=microtime(true);try{aiAcquireSlot($p);throw new Exception('concurrency exceeded');}catch(AiCallInterrupted $e){check($e->reason==='queue_timeout','busy slot times out');}
 check(microtime(true)-$start>=1,'queue timeout enforced');
 $other=$p;$other['id']=38;$h=aiAcquireSlot($other);check(is_resource($h),'other provider independent');aiReleaseSlot($h);
 $cancel=$p;$cancel['_isCancelled']=fn()=>true;try{aiAcquireSlot($cancel);throw new Exception('cancel ignored');}catch(AiCallInterrupted $e){check($e->reason==='cancelled','queued cancel enforced');}
 aiReleaseSlot($slot);$slot=aiAcquireSlot($p);check(is_resource($slot),'released slot reusable');aiReleaseSlot($slot);
 $two=$p;$two['runtime_limits']['concurrency']=2;$one=aiAcquireSlot($two);$second=aiAcquireSlot($two);check(is_resource($one) && is_resource($second),'raising concurrency permits two slots');aiReleaseSlot($one);aiReleaseSlot($second);
 // A real child holding a kernel lock exits without cleanup; no stale PID block remains.
 if(function_exists('pcntl_fork')) { $pid=pcntl_fork();if(!$pid){$h=aiAcquireSlot($p);exit(0);}pcntl_waitpid($pid,$status);$h=aiAcquireSlot($p);check(is_resource($h),'process exit releases lock');aiReleaseSlot($h); }
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "$checks Hugin limit assertions passed\n";
