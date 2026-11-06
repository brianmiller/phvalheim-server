<?php
require __DIR__.'/../container/nginx/www/includes/presentation.php';
$n=0;
function check($yes,$label) { global $n; if (!$yes) {fwrite(STDERR,"FAIL $label\n");exit(1);} $n++; }
foreach (['classic','reforged','gate','ember'] as $theme) check(phvPublicTheme($theme)===$theme,'allowlisted theme');
foreach ([null,'','../gate','<script>',[],1,'GATE'] as $bad) check(phvPublicTheme($bad)==='reforged','safe Reforged fallback');
foreach ([[0,null,false],[1,null,true],[1,0,false],[0,1,true],[1,1,true],[0,0,false]] as [$server,$world,$expected]) check(phvOverlayPolicy($server,$world)===$expected,'overlay inheritance');
// Execute the real builder, replacing only its external token dependency.
$source=file_get_contents(__DIR__.'/../container/nginx/www/includes/db_gets.php');
preg_match('/function phvBuildLaunchString\(.*?^}/ms',$source,$match);
check(!empty($match),'builder exists');
$builder=preg_replace('/require_once __DIR__ \. .*?;/', '',$match[0]);
function phvCompanionToken($world) { return $GLOBALS['fixtureToken']; }
function getSessionSteamID() { return $GLOBALS['fixturePlayer'] ?? null; }
function playerConfigToken($world,$player) { return 'scoped-personal-token'; }
eval($builder);
$base=['launch','Midgard','password','game.example','2456','web.example','https','0','1','123456'];
foreach (['','test-token'] as $token) {
 $GLOBALS['fixtureToken']=$token;
 foreach ([false,true] as $disabled) {
  $parts=explode('?',base64_decode(phvBuildLaunchString('Midgard','password','game.example',2456,'web.example','https',0,1,'123456',$disabled)));
  check(array_slice($parts,0,10)===$base,'existing positional fields unchanged');
  check($parts[10]===$token,'Companion token keeps slot 10 including empty');
  check($parts[11]===($disabled?'overlay=off':'overlay=on'),'overlay in slot 11');
 }
 $legacy=explode('?',base64_decode(phvBuildLaunchString('Midgard','password','game.example',2456,'web.example','https',0,1,'123456')));
 check(count($legacy)===($token===''?10:11),'legacy builder unchanged without policy');
}
$GLOBALS['fixturePlayer']='76561198000000001';
$GLOBALS['fixtureToken']='';
$personal=explode('?',base64_decode(phvBuildLaunchString('Midgard','password','game.example',2456,'web.example','https',0,1,'123456',false)));
check(array_slice($personal,0,10)===$base,'personal extension preserves every existing field');
check($personal[10]==='' && $personal[11]==='overlay=on' && $personal[12]==='playerConfig=scoped-personal-token','personal field 12 cannot shift empty Companion token or overlay');
echo "$n presentation and launch policy checks passed\n";
