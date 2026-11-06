<?php
require __DIR__ . '/../container/nginx/www/includes/remote_network.php';
function ck($ok,$label) { if (!$ok) throw new Exception($label); echo "PASS: $label\n"; }
$row=['name'=>'world <one>','mode'=>'running','crossplay'=>1,'port'=>25000,'password'=>'SECRET'];
$r=phvRemoteNetworkRow($row,'steam',null);
ck(!$r['crossplay'] && $r['restartPending'] && $r['basis']==='Running session','live Steam backend overrides newly saved crossplay');
ck($r['port']===25000 && $r['queryPort']===25001,'both assigned UDP ports shown');
ck(!array_key_exists('password',$r),'snapshot never returns world password');
$r=phvRemoteNetworkRow($row,null,['crossplay'=>0]);
ck(!$r['crossplay'] && $r['restartPending'],'launch snapshot overrides saved crossplay');
$r=phvRemoteNetworkRow($row,'playfab',null);
ck($r['crossplay'] && !$r['restartPending'],'observed relay identified');
$row['mode']='stopped';$r=phvRemoteNetworkRow($row,'steam',['crossplay'=>0]);
ck($r['crossplay'] && $r['basis']==='Saved settings' && !$r['restartPending'],'stopped world ignores old session evidence');
foreach ([null,'',0,65535,'25000oops'] as $port) { $row['port']=$port; $r=phvRemoteNetworkRow($row,null,null); ck($r['port']===null && $r['queryPort']===null,'unassigned or invalid port is not invented'); }
$row['port']=65534;$r=phvRemoteNetworkRow($row,null,null);ck($r['queryPort']===65535,'last complete UDP pair valid');
