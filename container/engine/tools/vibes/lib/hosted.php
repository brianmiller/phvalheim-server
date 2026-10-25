<?php
/** Hosted publishing uses the usermedia protocol documented by Thunderstore CLI and Hexium's OpenAPI.
 * Credentials go only to the selected API origin; signed storage URLs never receive them.
 */
require_once __DIR__ . '/package.php';
require_once __DIR__ . '/scm.php';
function vibesHostedBase($target) {
 if($target==='thunderstore') return 'https://thunderstore.io';
 if($target==='hexium') return 'https://valheim.hexium.gg';
 throw new RuntimeException('Choose Thunderstore or Hexium.');
}
function vibesHostedJson($base,$token,$path,$body) {
 [$code,$j,$raw]=vibesScmRequest('POST',$base.$path,['Authorization: Bearer '.$token],$body);
 if($code<200 || $code>=300 || !is_array($j)) {
  $detail=is_array($j)?json_encode($j):'The catalogue did not return a JSON response.';
  $detail=str_replace($token,'[redacted]',$detail);
  throw new RuntimeException('Catalogue request failed (HTTP '.$code.'): '.vibesSubstr($detail,0,800));
 }
 return $j;
}
function vibesHostedChunk($file,array $part,$base,$token) {
 $url=$part['url']??''; if(str_starts_with($url,'/')) $url=$base.$url;
 $u=parse_url($url);
 if(!$u || ($u['scheme']??'')!=='https' || isset($u['user']) || isset($u['pass']) || (isset($u['port'])&&$u['port']!==443)) throw new RuntimeException('Catalogue returned an unsafe upload URL.');
 $host=$u['host']??'';$ips=gethostbynamel($host);
 if(!$ips) throw new RuntimeException('Could not resolve the upload host.');
 foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Upload host is not a public address.');
 $off=(int)($part['offset']??-1);$len=(int)($part['length']??0);$size=filesize($file);
 if($off<0||$len<1||$off+$len>$size) throw new RuntimeException('Catalogue returned invalid upload offsets.');
 $src=fopen($file,'rb');$chunk=tmpfile();fseek($src,$off);
 if(stream_copy_to_stream($src,$chunk,$len)!==$len) throw new RuntimeException('Could not read the complete package chunk.');
 fclose($src);rewind($chunk);$hash=hash_init('md5');hash_update_stream($hash,$chunk);$md5=base64_encode(hash_final($hash,true));rewind($chunk);
 $headers=['Content-Type: application/octet-stream','Content-MD5: '.$md5,'Expect:'];
 // Hexium uploads on its own API; S3 presigned requests are self-authorizing.
 if(($u['host']??'')===parse_url($base,PHP_URL_HOST)) $headers[]='Authorization: Bearer '.$token;
 $etag='';$ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$chunk,CURLOPT_INFILESIZE=>$len,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$etag){if(stripos($line,'etag:')===0)$etag=trim(substr($line,5));return strlen($line);}]);
 curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);fclose($chunk);
 if($code<200||$code>=300||$etag==='') throw new RuntimeException('Package chunk upload failed (HTTP '.$code.').');
 return ['ETag'=>$etag,'PartNumber'=>(int)$part['part_number']];
}
/** Injectable transports exercise the complete upload protocol without publishing a test mod. */
function vibesHostedUpload($target,$file,array $settings,$step,$json=null,$chunk=null) {
 $base=vibesHostedBase($target);$token=trim((string)($settings['token']??''));$team=trim((string)($settings['team']??''));
 if($token===''||!vibesValidNamespace($team)) throw new RuntimeException('Set the '.$target.' API token and team in Settings → Publishing.');
 if(!is_file($file)||filesize($file)>512*1024*1024) throw new RuntimeException('Package must exist and be no larger than 512 MB.');
 $json=$json??'vibesHostedJson';$chunk=$chunk??'vibesHostedChunk';$uuid=null;
 try {
  $step('Preparing '.$target.' upload',15,basename($file));
  $init=$json($base,$token,'/api/experimental/usermedia/initiate-upload/',['filename'=>basename($file),'file_size_bytes'=>filesize($file)]);
  $uuid=$init['user_media']['uuid']??'';$parts=$init['upload_urls']??[];
  if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$uuid)||!$parts) throw new RuntimeException('Catalogue returned an incomplete upload session.');
  $done=[];$offset=0;usort($parts,fn($a,$b)=>($a['offset']??0)<=>($b['offset']??0));
  foreach($parts as $i=>$part){
   if((int)($part['offset']??-1)!==$offset || (int)($part['length']??0)<1) throw new RuntimeException('Upload chunks do not cover the package contiguously.');
   $done[]=$chunk($file,$part,$base,$token);$offset+=(int)$part['length'];
   $step('Uploading to '.$target,20+(int)(60*($i+1)/count($parts)),($i+1).' / '.count($parts).' chunks');
  }
  if($offset!==filesize($file)) throw new RuntimeException('Upload did not cover the complete package.');
  $json($base,$token,"/api/experimental/usermedia/$uuid/finish-upload/",['parts'=>$done]);
  $cats=array_values(array_filter(array_map('trim',explode(',',(string)($settings['categories']??'')))));
  $step('Validating and publishing package',85,$team);
  return $json($base,$token,'/api/experimental/submission/submit/',['author_name'=>$team,'upload_uuid'=>$uuid,'communities'=>['valheim'],'categories'=>[],'community_categories'=>['valheim'=>$cats],'has_nsfw_content'=>false]);
 } catch(Throwable $e) {
  if($uuid && preg_match('/^[a-zA-Z0-9-]{16,64}$/',$uuid)) try{$json($base,$token,"/api/experimental/usermedia/$uuid/abort-upload/",[]);}catch(Throwable $ignored){}
  throw $e;
 }
}
function vibesHostedPublish(PDO $pdo,array $doc,array $project,array $args,$step) {
 $target=$args['target']??'';vibesHostedBase($target);
 $p=vibesPackage($project['slug']);if(!$p['success']) return $p;
 $result=vibesHostedUpload($target,$p['file'],vibesSetting($doc,$target,[]),$step);
 if(empty($result['package_version'])) throw new RuntimeException('Catalogue did not confirm a published package. Check the catalogue before retrying this version.');
 $url=$result['available_communities'][0]['url']??'';
 if(!preg_match('~^https://~',$url))$url='';
 $receipt=['version'=>$p['version'],'at'=>gmdate('c'),'url'=>$url,'hidden'=>!empty($result['hidden'])];
 $file=vibesProjectDir($project['slug']).'/.vibes/builds/hosted.json';$receipts=vibesReadJson($file,[]);$receipts[$target]=$receipt;vibesWriteJson($file,$receipts);
 $step('Published to '.$target,100,$url?:$p['version']);
 return ['success'=>true,'target'=>$target]+$receipt;
}
