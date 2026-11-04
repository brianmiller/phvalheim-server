<?php
require __DIR__ . '/../container/nginx/www/includes/remote_access.php';
$n = 0;
function ck($yes, $why) { global $n; if (!$yes) { fwrite(STDERR, "FAIL: $why\n"); exit(1); } $n++; }
ck(phvRemoteSecure(['REMOTE_ADDR'=>'127.0.0.1']), 'local control');
ck(phvRemoteSecure(['REMOTE_ADDR'=>'::1']), 'IPv6 local control');
ck(phvRemoteSecure(['REMOTE_ADDR'=>'172.17.0.1','HTTP_HOST'=>'localhost:18081']), 'Docker loopback-published port');
ck(phvRemoteSecure(['REMOTE_ADDR'=>'192.0.2.1','HTTPS'=>'on']), 'server TLS');
ck(!phvRemoteSecure(['REMOTE_ADDR'=>'192.0.2.1','HTTP_X_FORWARDED_PROTO'=>'https']), 'spoofed forwarding does not authorize secret entry');
$s = ['HTTP_HOST'=>'localhost:18081','HTTP_ORIGIN'=>'http://localhost:18081','HTTP_SEC_FETCH_SITE'=>'same-origin'];
ck(phvRemoteOriginAllowed($s), 'same origin allowed');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>'https://evil.example'])), 'foreign origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_SEC_FETCH_SITE'=>'cross-site'])), 'cross site refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_SEC_FETCH_SITE'=>'same-site'])), 'sibling origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>''])), 'missing origin refused');
ck(!phvRemoteOriginAllowed(array_merge($s,['HTTP_ORIGIN'=>'http://localhost:18080'])), 'public origin refused');
echo "PASS: $n Remote Access request guards\n";
