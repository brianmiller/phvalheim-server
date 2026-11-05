#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")/.."
php <<'PHP'
<?php
require 'container/nginx/www/includes/player_config.php';
function check($value,$label){if(!$value)throw new Exception($label);echo "PASS $label\n";}
$key=str_repeat('a',64);$now=1800000000;$player='76561198000000001';
$token=playerConfigToken('Midgard',$player,$key,$now);
check(playerConfigTokenPlayer('Midgard',$token,$key,$now)===$player,'player token round trip');
check(playerConfigTokenPlayer('Other',$token,$key,$now)===null,'world scoped');
check(playerConfigTokenPlayer('Midgard',$token,$key,$now+3601)===null,'expired token refused');
check(playerConfigTokenPlayer('Midgard',$token,str_repeat('b',64),$now)===null,'different server key refused');
check(playerConfigTokenPlayer('Midgard','fixture-token',$key,$now)===null,'companion credential cannot read personal settings');
check(playerConfigToken('Midgard','invalid',$key,$now)==='','invalid player refused');
check(!playerConfigValueValid(['type'=>'Single','range'=>[1,12]],'13'),'numeric range');
check(!playerConfigValueValid(['type'=>'Int32'],'3.4'),'integer validation');
check(!playerConfigValueValid(['type'=>'Boolean'],'maybe'),'boolean validation');
check(!playerConfigValueValid(['acceptable'=>['A','B']],'C'),'enum validation');
check(!playerConfigValueValid([],"safe\nInjected=1"),'line injection refused');
check(playerConfigValueValid(['type'=>'String'],'雪 = yes'),'unicode text value');
PHP
