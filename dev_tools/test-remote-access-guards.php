<?php
require __DIR__ . '/../container/nginx/www/includes/remote_access.php';
$n = 0;
function ck($yes, $why) { global $n; if (!$yes) { fwrite(STDERR, "FAIL: $why\n"); exit(1); } $n++; }
$s = ['HTTP_HOST'=>'localhost:18081','HTTP_ORIGIN'=>'http://localhost:18081','HTTP_SEC_FETCH_SITE'=>'same-origin'];
ck(phvRemoteOriginAllowed($s), 'same origin allowed');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>'https://evil.example'])), 'foreign origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_SEC_FETCH_SITE'=>'cross-site'])), 'cross site refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_SEC_FETCH_SITE'=>'same-site'])), 'sibling origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>''])), 'missing origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>'http://localhost:18080'])), 'public origin refused');
foreach (['192.168.1.50:8081', 'phvalheim:8081', 'server.example.net:8081', '[fd00::50]:8081'] as $host) {
    ck(phvRemoteOriginAllowed(['HTTP_HOST'=>$host,'HTTP_ORIGIN'=>'http://'.$host]), 'ordinary LAN admin authority allowed');
    ck(phvRemoteOriginAllowed(['HTTP_HOST'=>$host,'HTTP_ORIGIN'=>'https://'.$host]), 'existing TLS proxy authority allowed');
    ck(!phvRemoteOriginAllowed(['HTTP_HOST'=>$host,'HTTP_ORIGIN'=>'http://'.$host,'HTTPS'=>'on']), 'direct TLS rejects downgrade origin');
}
ck(!phvRemoteOriginAllowed(['HTTP_HOST'=>'lan:8081','HTTP_ORIGIN'=>'https://evil.example','HTTP_X_FORWARDED_HOST'=>'evil.example','HTTP_X_FORWARDED_PROTO'=>'https']), 'forwarded headers cannot authorize foreign origin');
ck(!phvRemoteOriginAllowed(['HTTP_HOST'=>'lan:8081','HTTP_ORIGIN'=>'http://lan:8081.evil.example']), 'authority suffix refused');
echo "PASS: $n Remote Access request guards\n";
