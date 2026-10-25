<?php
/** A launch-scoped capability permits read-only connection refresh without a browser session. */
function phvCompanionKey() {
 $file='/opt/stateful/.companion-status-key';
 $mask=umask(0077);$f=@fopen($file,'c+');umask($mask);
 if(!$f)return null;
 try {
  if(!flock($f,LOCK_EX))return null;
  $key=stream_get_contents($f);
  if(strlen($key)!==64){$key=bin2hex(random_bytes(32));rewind($f);ftruncate($f,0);fwrite($f,$key);fflush($f);@chmod($file,0600);}
  return $key;
 } finally {flock($f,LOCK_UN);fclose($f);}
}
function phvCompanionToken($world,$key=null,$now=null) {
 $key=$key??phvCompanionKey();if(!$key)return '';
 $expiry=(int)(floor(($now??time())/3600)*3600+86400);
 return $expiry.'.'.hash_hmac('sha256',"connection\n$world\n$expiry",$key);
}
function phvCompanionTokenValid($world,$token,$key=null,$now=null) {
 if(!is_string($token)||!preg_match('/^(\d{10})\.([a-f0-9]{64})$/',$token,$m))return false;
 $now=$now??time();if((int)$m[1]<$now || (int)$m[1]>$now+86400)return false;
 $key=$key??phvCompanionKey();if(!$key)return false;
 return hash_equals(hash_hmac('sha256',"connection\n$world\n{$m[1]}",$key),$m[2]);
}
