<?php
require_once '/opt/stateless/nginx/www/includes/config_env_puller.php';
require_once '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/companion.php';
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');
$world=(string)($_GET['world']??'');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
$token=str_starts_with($auth,'Bearer ')?substr($auth,7):'';
if(!phvCompanionTokenValid($world,$token)){http_response_code(403);echo "error=Launch authorization expired. Launch this world again from PhValheim.\n";exit;}
$q=$pdo->prepare('SELECT name,mode,port,world_md5 FROM worlds WHERE name=?');$q->execute([$world]);$row=$q->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);echo "error=World no longer exists.\n";exit;}
$online=$row['mode']==='running';
$opts=effectiveWorldOptions($pdo,$world,$online);
$playfab=$online?worldIsPlayFab($pdo,$world,true):((int)$opts['crossplay']===1);
$code=$online&&$playfab?(getWorldJoinCode($pdo,$world)??''):'';
foreach(['world'=>$world,'mode'=>$row['mode'],'crossplay'=>$playfab?'1':'0','joinCode'=>$code,'port'=>$row['port'],'mods'=>$row['world_md5']??'','checkedAt'=>gmdate('c')] as $k=>$v)echo $k.'='.str_replace(["\r","\n"],'',(string)$v)."\n";
