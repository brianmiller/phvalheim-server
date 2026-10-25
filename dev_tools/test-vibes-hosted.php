<?php
require __DIR__.'/vibes-test-lib.php';vibesTestLoad('hosted');
$file=VIBES_ROOT.'/payload.zip';file_put_contents($file,'abcdefgh');$calls=[];$aborts=0;
$json=function($base,$token,$path,$body)use(&$calls,&$aborts){
 $calls[]=[$base,$path,$body];
 if(str_contains($path,'initiate-upload'))return ['user_media'=>['uuid'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],'upload_urls'=>[['offset'=>0,'length'=>4,'part_number'=>1],['offset'=>4,'length'=>4,'part_number'=>2]]];
 if(str_contains($path,'abort-upload'))$aborts++;
 return ['package_version'=>['version_number'=>'1.0.0'],'available_communities'=>[]];
};
$chunk=function($file,$part,$base,$token){return ['ETag'=>'test-'.$part['part_number'],'PartNumber'=>$part['part_number']];};
foreach(['thunderstore','hexium'] as $target){
 $calls=[];$r=vibesHostedUpload($target,$file,['token'=>'fixture-token','team'=>'Fixture','categories'=>'quality-of-life, tools'],function(){},$json,$chunk);
 check($target.' confirmed',isset($r['package_version']));
 check($target.' complete lifecycle',count($calls)===3 && str_contains($calls[1][1],'finish-upload') && str_contains($calls[2][1],'submit/'));
 check($target.' multipart completion',count($calls[1][2]['parts'])===2 && $calls[1][2]['parts'][1]['PartNumber']===2);
 check($target.' metadata',$calls[2][2]['author_name']==='Fixture' && $calls[2][2]['community_categories']['valheim']===['quality-of-life','tools']);
 check($target.' fixed origin',$calls[0][0]===vibesHostedBase($target));
}
try{vibesHostedUpload('hexium',$file,['token'=>'x','team'=>'Fixture'],function(){},$json,function(){throw new RuntimeException('fixture upload failed');});check('failure not swallowed',false);}catch(RuntimeException $e){check('failure not swallowed',$e->getMessage()==='fixture upload failed');}
check('failed upload aborted',$aborts===1);
$doc=vibesSettingsDefaults();$doc['thunderstore']['token']='fixture-SECRET-value';$doc['hexium']['token']='other-SECRET-value';$masked=vibesSettingsForClient($doc);
check('hosted credentials masked',!str_contains(json_encode($masked),'SECRET'));
foreach(['http://github.com/a/b','https://github.com@evil.test/a/b','https://github.com/a/b/tree/main','https://localhost/a/b','https://gitlab.com/a/../b'] as $url){try{vibesScmParseUrl($url,$doc);check('reject '.$url,false);}catch(RuntimeException $e){check('reject '.$url,true);}}
check('GitLab subgroup supported',vibesScmParseUrl('https://gitlab.com/team/sub/mod.git',$doc)['path']==='team/sub/mod');
vibesTestDone();
