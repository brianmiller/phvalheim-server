<?php
require __DIR__.'/../container/nginx/www/includes/companion.php';
$key=str_repeat('a',64);$now=1800000000;$token=phvCompanionToken('FixtureWorld',$key,$now);$fail=0;
foreach(['valid'=>phvCompanionTokenValid('FixtureWorld',$token,$key,$now), 'wrong world'=>!phvCompanionTokenValid('OtherWorld',$token,$key,$now), 'wrong key'=>!phvCompanionTokenValid('FixtureWorld',$token,str_repeat('b',64),$now), 'expired'=>!phvCompanionTokenValid('FixtureWorld',$token,$key,$now+86401), 'tampered'=>!phvCompanionTokenValid('FixtureWorld',$token.'a',$key,$now), 'absent'=>!phvCompanionTokenValid('FixtureWorld','',$key,$now)] as $name=>$ok) {echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)$fail++;}
exit($fail?1:0);
