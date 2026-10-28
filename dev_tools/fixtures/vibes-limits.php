<?php
// Runs shipping adapters and Forge loop, with a fake PDO (no live DB changes).
require dirname(__DIR__).'/vibes-test-lib.php';
vibesTestLoad('agent');
$now=microtime(true);
check('curl timer precision retains turn timeout reason',aiCallStopReason(['_deadline'=>$now+.005,'_requestDeadline'=>$now+10],.01)==='turn_timeout');
check('curl timer precision retains response timeout reason',aiCallStopReason(['_deadline'=>$now+10,'_requestDeadline'=>$now+.005],.01)==='model_timeout');
check('connection timeout well before budget is not a turn limit',aiCallStopReason(['_deadline'=>$now+60,'_requestDeadline'=>$now+30],.01)===null);
check('normal progress checks do not expire early',aiCallStopReason(['_deadline'=>microtime(true)+.005])===null);
$servers=[];
register_shutdown_function(function()use(&$servers){foreach($servers as [$p,$dir]) {if(is_resource($p)){proc_terminate($p);proc_close($p);} vibesRmTree($dir);}});
function providerFixture($kind,$mode) {
    global $servers;
    $dir=VIBES_ROOT.'/http-'.count($servers); mkdir($dir,0775,true);
    copy(__DIR__.'/vibes-limits-provider.php',$dir.'/router.php');
    $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
    $p=proc_open([PHP_BINARY,'-S',$address,$dir.'/router.php'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/http.log','a'],2=>['file',$dir.'/http.log','a']],$pipes,null,array_merge(getenv(),['LIMIT_MODE'=>$mode,'LIMIT_KIND'=>$kind]));
    if(!is_resource($p))throw new RuntimeException('Fixture failed to start');
    $servers[]=[$p,$dir];usleep(150000);
    return [['id'=>1,'kind'=>$kind,'label'=>'Fixture','base_url'=>'http://'.$address,'model'=>'fixture-flash','enabled'=>1,'is_default'=>1,'api_key'=>'','extra_headers'=>'{}','headers'=>[]],$dir];
}
function stopFixture(){global $servers;[$p]=end($servers);proc_terminate($p);proc_close($p);}
function callFixture($kind,$mode,$extra,$stream=true) {
    [$p,$dir]=providerFixture($kind,$mode);$text='';$start=microtime(true);
    $r=aiChat($p+$extra,[['role'=>'user','content'=>'test']],'system',[],$stream?function($d)use(&$text){$text.=$d;}:null);
    $elapsed=microtime(true)-$start;stopFixture();return [$r,$elapsed,$text,$dir];
}
foreach(['gemini','openai_compatible','anthropic'] as $kind) {
    [$r,$t]=callFixture($kind,'slow',['_request_timeout_s'=>2]);
    check("$kind allows slow successful response",$r['success'] && $r['content']==='Finished.' && $t>=.2);
    [$r,$t,$text]=callFixture($kind,'partial',['_request_timeout_s'=>.8]);
    check("$kind partial stream times out instead of success",!$r['success'] && ($r['stop_reason']??'')==='model_timeout' && $t<3 && $text==='Partial response');
    check("$kind interrupted call supplies no tools",$r['tool_calls']===[]);
    $start=microtime(true);
    [$r,$t]=callFixture($kind,'stall',['_request_timeout_s'=>10,'_isCancelled'=>fn()=>microtime(true)-$start>.5]);
    check("$kind Stop interrupts silent response",($r['stop_reason']??'')==='cancelled' && $t<3);
    [$r,$t]=callFixture($kind,'stall',['_request_timeout_s'=>10,'_deadline'=>microtime(true)+.7],false);
    check("$kind non-streaming call obeys remaining turn time",($r['stop_reason']??'')==='turn_timeout' && $t<3);
}
[$r,$t,,$dir]=callFixture('gemini','retry',['_request_timeout_s'=>1]);
check('Gemini negotiation retry shares response deadline',($r['stop_reason']??'')==='model_timeout' && $t<1.8 && (int)file_get_contents($dir.'/count')===2);
[$p]=providerFixture('gemini','stall');
try {forgeSummarize($p+['_request_timeout_s'=>.5],'history',4000);check('compaction propagates stop',false);} catch(AiCallInterrupted $e){check('compaction propagates stop',$e->reason==='model_timeout');}stopFixture();
// These PDO substitutes only supply settings/provider rows and record session updates.
// All conversation, HTTP, tool, file and stop behavior remains shipping code.
class LimitsStatement extends PDOStatement {
    public function __construct(public $db,public $sql){}
    public function execute(?array $params=null):bool {$this->db->writes[]=[$this->sql,$params];return true;}
    public function fetchColumn(int $column=0):mixed {return json_encode($this->db->settings);}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [$this->db->provider];}
}
class LimitsDb extends PDO {
    public array $writes=[];
    public function __construct(public $settings,public $provider){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new LimitsStatement($this,$query);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new LimitsStatement($this,$query);}
}
function turnFixture($mode,$settings=[],$cancel=null,$history=false) {
    [$provider,$http]=providerFixture('gemini',$mode);
    $doc=vibesMergeSettings(vibesSettingsDefaults(),['agent'=>$settings]);$db=new LimitsDb($doc,$provider);
    $id=count($GLOBALS['servers']);$slug='limits'.$id;$dir=vibesProjectDir($slug);mkdir($dir.'/.vibes/sessions',0775,true);
    $project=['id'=>$id,'slug'=>$slug,'name'=>'Fixture','namespace'=>'Fixture','description'=>'Test','version'=>'1.0.0','template'=>'basic-plugin','created'=>'2026-01-01','provider_id'=>1,'model'=>'fixture-flash'];
    $session=['id'=>$id,'summary'=>null];$log=vibesSessionLog($slug,$id);$emit=new HvEmitter(VIBES_ROOT.'/job'.$id.'.jsonl',$log);
    if($history)for($i=0;$i<6;$i++)$emit->emit(['type'=>$i%2?'assistant':'user','text'=>str_repeat('previous context ',12000)]);
    $start=microtime(true);$r=forgeTurn($db,$session,$project,'Make a change and report completion.',$emit,$cancel??fn()=>false);$elapsed=microtime(true)-$start;
    $events=array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES));stopFixture();return [$r,$events,$dir,$http,$elapsed,$db];
}
// A ready pak makes the auto-build assertion meaningful: a wrongly executed epilogue
// would emit a build failure (this fixture deliberately has no csproj).
$pak=vibesPakCurrent();mkdir($pak.'/dotnet',0775,true);mkdir($pak.'/git/usr/bin',0775,true);
file_put_contents($pak.'/manifest.json','{}');
foreach(['dotnet/dotnet','git/usr/bin/git'] as $bin){file_put_contents($pak.'/'.$bin,"#!/bin/sh\nexit 0\n");chmod($pak.'/'.$bin,0755);}
check('round-limit fixture has a ready build pak',vibesPakReady());
[$r,$ev,$dir]=turnFixture('loop',['max_rounds'=>2]);
check('Gemini loop stops at configured round limit',$r['stop_reason']==='round_limit' && $r['rounds']===2);
check('completed tool writes survive limit',file_get_contents($dir.'/saved.txt')==='saved before limit');
check('round stop explains distinction and Continue',str_contains(end($ev)['text'],'not a timeout') && str_contains(end($ev)['text'],'Continue'));
check('round limit skips auto-build and commit',!array_intersect(['build','commit'],array_column($ev,'type')));
vibesRmTree($pak);
[$r,$ev,,$http]=turnFixture('complete',['max_rounds'=>2]);
check('Gemini answer on last allowed round completes normally',$r['success'] && $r['stop_reason']===null && end($ev)['text']==='Finished.');
$request=json_decode(file_get_contents($http.'/request-2.json'),true);
check('Gemini tool round replays signature intact',str_contains(json_encode($request),'fixture-signature') && str_contains(json_encode($request),'functionResponse'));
$start=microtime(true);[$r,$ev,$dir]=turnFixture('partial',[],fn()=>microtime(true)-$start>.7);
check('actual Forge Stop interrupts Gemini call',$r['cancelled'] && $r['stop_reason']==='cancelled');
check('partial Gemini tool call never executes',!is_file($dir.'/partial.txt') && !in_array('tool_call',array_column($ev,'type')));
$start=microtime(true);[$r,$ev]=turnFixture('stall',[],fn()=>microtime(true)-$start>.7,true);
check('Stop during initial compaction reaches clean stop',$r['stop_reason']==='cancelled' && $r['rounds']===0);
if(!getenv('LIMITS_QUICK')) {
    [$r,$ev,$dir,,$elapsed]=turnFixture('partial',['model_timeout_s'=>30,'turn_timeout_s'=>60]);
    check('real Forge response limit is enforced',$r['stop_reason']==='model_timeout' && $elapsed>=29 && $elapsed<34);
    check('timeout retains no executable partial call',!is_file($dir.'/partial.txt') && !in_array('tool_call',array_column($ev,'type')));
    check('silent/slow wait emits periodic status',count(array_filter($ev,fn($e)=>$e['type']==='thinking' && str_contains($e['text'],'seconds')))>=1);
    [$r,$ev,,,$elapsed]=turnFixture('stall',['model_timeout_s'=>60,'turn_timeout_s'=>30]);
    check('real Forge turn limit bounds longer response limit',$r['stop_reason']==='turn_timeout' && $elapsed>=29 && $elapsed<34);
}
check('legacy settings get response default',vibesMergeSettings(vibesSettingsDefaults(),['agent'=>['max_rounds'=>7]])['agent']['model_timeout_s']===900);
foreach([29,7201,'invalid'] as $bad){[, $errors]=vibesSettingsApplyClient(vibesSettingsDefaults(),['agent'=>['model_timeout_s'=>$bad]]);check('invalid response limit rejected: '.$bad,count($errors)===1);}
foreach([30,7200] as $value){[, $errors]=vibesSettingsApplyClient(vibesSettingsDefaults(),['agent'=>['model_timeout_s'=>$value]]);check('response boundary accepted: '.$value,$errors===[]);}
vibesTestDone();
