<?php
require __DIR__.'/vibes-test-lib.php';vibesTestLoad('agent');
$messages=[['role'=>'user','content'=>'Keep the stamina rule and compile it.'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'call1','name'=>'read_file','arguments'=>['path'=>'Plugin.cs']]]],['role'=>'tool','tool_call_id'=>'call1','content'=>str_repeat('old diagnostic ',10000)]];
$seen='';$summary=function($provider,$text,$budget)use(&$seen){$seen=$text;return 'Plugin.cs changed; stamina rule retained; compile next.';};
[$fit,$did]=forgeFitRound([],$messages,'System rules',[],8000,2000,null,$summary);
check('in-turn compaction fires',$did);
check('summarizer saw diagnostics',str_contains($seen,'old diagnostic'));
check('latest user request preserved',$fit[count($fit)-1]['content']===$messages[0]['content']);
check('fits reserve budget',forgeEstimateTokens($fit,'System rules')<6000);
check('no orphan tool result or call',!in_array('tool',array_column($fit,'role')) && !isset($fit[1]['tool_calls']));
[$same,$did]=forgeFitRound([],$fit,'System rules',[],8000,2000,null,$summary);
check('small context left intact',!$did && $same===$fit);
$huge=[['role'=>'assistant','content'=>'','tool_calls'=>[['arguments'=>str_repeat('x',15000)]]]];
check('tool arguments counted',forgeEstimateTokens($huge)>5000);
$asked=0;
$chat=function($p,$m)use(&$asked){$asked=strlen($m[0]['content']);return ['success'=>false];};
$fallback=forgeSummarize([],str_repeat('decision ',10000),4000,$chat);
check('summary request bounded',$asked<10000);
check('failed summary preserves explicit excerpts',str_contains($fallback,'Summary service unavailable') && str_contains($fallback,'decision') && strlen($fallback)<3000);
try{forgeFitRound([],[['role'=>'user','content'=>str_repeat('x',20000)]],'system',[],4000,1000);check('oversize latest user rejected',false);}catch(RuntimeException $e){check('oversize latest user rejected',str_contains($e->getMessage(),'latest request'));}
vibesTestDone();
