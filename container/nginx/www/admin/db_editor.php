<?php
/** Adminer Editor - Compact database editor
* @link https://www.adminer.org/
* @author Jakub Vrana, https://www.vrana.cz/
* @copyright 2009 Jakub Vrana
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
* @version 5.3.0
*/namespace
Adminer;const
VERSION="5.3.0";error_reporting(24575);set_error_handler(function($Hb,$Ib){return!!preg_match('~^Undefined (array key|offset|index)~',$Ib);},E_WARNING|E_NOTICE);$Xb=!preg_match('~^(unsafe_raw)?$~',ini_get("filter.default"));if($Xb||ini_get("filter.default_flags")){foreach(array('_GET','_POST','_COOKIE','_SERVER')as$W){$Gf=filter_input_array(constant("INPUT$W"),FILTER_UNSAFE_RAW);if($Gf)$$W=$Gf;}}if(function_exists("mb_internal_encoding"))mb_internal_encoding("8bit");function
connection($f=null){return($f?:Db::$instance);}function
adminer(){return
Adminer::$instance;}function
driver(){return
Driver::$instance;}function
connect(){$ab=adminer()->credentials();$K=Driver::connect($ab[0],$ab[1],$ab[2]);return(is_object($K)?$K:null);}function
idf_unescape($s){if(!preg_match('~^[`\'"[]~',$s))return$s;$cd=substr($s,-1);return
str_replace($cd.$cd,$cd,substr($s,1,-1));}function
q($Q){return
connection()->quote($Q);}function
escape_string($W){return
substr(q($W),1,-1);}function
idx($ja,$w,$h=null){return($ja&&array_key_exists($w,$ja)?$ja[$w]:$h);}function
number($W){return
preg_replace('~[^0-9]+~','',$W);}function
number_type(){return'((?<!o)int(?!er)|numeric|real|float|double|decimal|money)';}function
remove_slashes(array$oe,$Xb=false){if(function_exists("get_magic_quotes_gpc")&&get_magic_quotes_gpc()){while(list($w,$W)=each($oe)){foreach($W
as$Wc=>$V){unset($oe[$w][$Wc]);if(is_array($V)){$oe[$w][stripslashes($Wc)]=$V;$oe[]=&$oe[$w][stripslashes($Wc)];}else$oe[$w][stripslashes($Wc)]=($Xb?$V:stripslashes($V));}}}}function
bracket_escape($s,$ra=false){static$xf=array(':'=>':1',']'=>':2','['=>':3','"'=>':4');return
strtr($s,($ra?array_flip($xf):$xf));}function
min_version($Pf,$ld="",$f=null){$f=connection($f);$Me=$f->server_info;if($ld&&preg_match('~([\d.]+)-MariaDB~',$Me,$_)){$Me=$_[1];$Pf=$ld;}return$Pf&&version_compare($Me,$Pf)>=0;}function
charset(Db$e){return(min_version("5.5.3",0,$e)?"utf8mb4":"utf8");}function
ini_bool($Kc){$W=ini_get($Kc);return(preg_match('~^(on|true|yes)$~i',$W)||(int)$W);}function
sid(){static$K;if($K===null)$K=(SID&&!($_COOKIE&&ini_bool("session.use_cookies")));return$K;}function
set_password($Y,$O,$U,$G){$_SESSION["pwds"][$Y][$O][$U]=($_COOKIE["adminer_key"]&&is_string($G)?array(encrypt_string($G,$_COOKIE["adminer_key"])):$G);}function
get_password(){$K=get_session("pwds");if(is_array($K))$K=($_COOKIE["adminer_key"]?decrypt_string($K[0],$_COOKIE["adminer_key"]):false);return$K;}function
get_val($I,$j=0,$Ta=null){$Ta=connection($Ta);$J=$Ta->query($I);if(!is_object($J))return
false;$L=$J->fetch_row();return($L?$L[$j]:false);}function
get_vals($I,$c=0){$K=array();$J=connection()->query($I);if(is_object($J)){while($L=$J->fetch_row())$K[]=$L[$c];}return$K;}function
get_key_vals($I,$f=null,$Pe=true){$f=connection($f);$K=array();$J=$f->query($I);if(is_object($J)){while($L=$J->fetch_row()){if($Pe)$K[$L[0]]=$L[1];else$K[]=$L[0];}}return$K;}function
get_rows($I,$f=null,$i="<p class='error'>"){$Ta=connection($f);$K=array();$J=$Ta->query($I);if(is_object($J)){while($L=$J->fetch_assoc())$K[]=$L;}elseif(!$J&&!$f&&$i&&(defined('Adminer\PAGE_HEADER')||$i=="-- "))echo$i.error()."\n";return$K;}function
unique_array($L,array$u){foreach($u
as$t){if(preg_match("~PRIMARY|UNIQUE~",$t["type"])){$K=array();foreach($t["columns"]as$w){if(!isset($L[$w]))continue
2;$K[$w]=$L[$w];}return$K;}}}function
escape_key($w){if(preg_match('(^([\w(]+)('.str_replace("_",".*",preg_quote(idf_escape("_"))).')([ \w)]+)$)',$w,$_))return$_[1].idf_escape(idf_unescape($_[2])).$_[3];return
idf_escape($w);}function
where(array$Z,array$k=array()){$K=array();foreach((array)$Z["where"]as$w=>$W){$w=bracket_escape($w,true);$c=escape_key($w);$j=idx($k,$w,array());$Ub=$j["type"];$K[]=$c.(JUSH=="sql"&&$Ub=="json"?" = CAST(".q($W)." AS JSON)":(JUSH=="sql"&&is_numeric($W)&&preg_match('~\.~',$W)?" LIKE ".q($W):(JUSH=="mssql"&&strpos($Ub,"datetime")===false?" LIKE ".q(preg_replace('~[_%[]~','[\0]',$W)):" = ".unconvert_field($j,q($W)))));if(JUSH=="sql"&&preg_match('~char|text~',$Ub)&&preg_match("~[^ -@]~",$W))$K[]="$c = ".q($W)." COLLATE ".charset(connection())."_bin";}foreach((array)$Z["null"]as$w)$K[]=escape_key($w)." IS NULL";return
implode(" AND ",$K);}function
where_check($W,array$k=array()){parse_str($W,$Fa);remove_slashes(array(&$Fa));return
where($Fa,$k);}function
where_link($q,$c,$X,$Nd="="){return"&where%5B$q%5D%5Bcol%5D=".urlencode($c)."&where%5B$q%5D%5Bop%5D=".urlencode(($X!==null?$Nd:"IS NULL"))."&where%5B$q%5D%5Bval%5D=".urlencode($X);}function
convert_fields(array$d,array$k,array$N=array()){$K="";foreach($d
as$w=>$W){if($N&&!in_array(idf_escape($w),$N))continue;$ka=convert_field($k[$w]);if($ka)$K
.=", $ka AS ".idf_escape($w);}return$K;}function
cookie($C,$X,$hd=2592000){header("Set-Cookie: $C=".urlencode($X).($hd?"; expires=".gmdate("D, d M Y H:i:s",time()+$hd)." GMT":"")."; path=".preg_replace('~\?.*~','',$_SERVER["REQUEST_URI"]).(HTTPS?"; secure":"")."; HttpOnly; SameSite=lax",false);}function
get_settings($Xa){parse_str($_COOKIE[$Xa],$Qe);return$Qe;}function
get_setting($w,$Xa="adminer_settings"){$Qe=get_settings($Xa);return$Qe[$w];}function
save_settings(array$Qe,$Xa="adminer_settings"){$X=http_build_query($Qe+get_settings($Xa));cookie($Xa,$X);$_COOKIE[$Xa]=$X;}function
restart_session(){if(!ini_bool("session.use_cookies")&&(!function_exists('session_status')||session_status()==1))session_start();}function
stop_session($bc=false){$Mf=ini_bool("session.use_cookies");if(!$Mf||$bc){session_write_close();if($Mf&&@ini_set("session.use_cookies",'0')===false)session_start();}}function&get_session($w){return$_SESSION[$w][DRIVER][SERVER][$_GET["username"]];}function
set_session($w,$W){$_SESSION[$w][DRIVER][SERVER][$_GET["username"]]=$W;}function
auth_url($Y,$O,$U,$g=null){$Kf=remove_from_uri(implode("|",array_keys(SqlDriver::$drivers))."|username|ext|".($g!==null?"db|":"").($Y=='mssql'||$Y=='pgsql'?"":"ns|").session_name());preg_match('~([^?]*)\??(.*)~',$Kf,$_);return"$_[1]?".(sid()?SID."&":"").($Y!="server"||$O!=""?urlencode($Y)."=".urlencode($O)."&":"").($_GET["ext"]?"ext=".urlencode($_GET["ext"])."&":"")."username=".urlencode($U).($g!=""?"&db=".urlencode($g):"").($_[2]?"&$_[2]":"");}function
is_ajax(){return($_SERVER["HTTP_X_REQUESTED_WITH"]=="XMLHttpRequest");}function
redirect($z,$A=null){if($A!==null){restart_session();$_SESSION["messages"][preg_replace('~^[^?]*~','',($z!==null?$z:$_SERVER["REQUEST_URI"]))][]=$A;}if($z!==null){if($z=="")$z=".";header("Location: $z");exit;}}function
query_redirect($I,$z,$A,$we=true,$Mb=true,$Rb=false,$of=""){if($Mb){$Ze=microtime(true);$Rb=!connection()->query($I);$of=format_time($Ze);}$We=($I?adminer()->messageQuery($I,$of,$Rb):"");if($Rb){adminer()->error
.=error().$We.script("messagesPrint();")."<br>";return
false;}if($we)redirect($z,$A.$We);return
true;}class
Queries{static$queries=array();static$start=0;}function
queries($I){if(!Queries::$start)Queries::$start=microtime(true);Queries::$queries[]=(preg_match('~;$~',$I)?"DELIMITER ;;\n$I;\nDELIMITER ":$I).";";return
connection()->query($I);}function
apply_queries($I,array$jf,$Jb='Adminer\table'){foreach($jf
as$R){if(!queries("$I ".$Jb($R)))return
false;}return
true;}function
queries_redirect($z,$A,$we){$re=implode("\n",Queries::$queries);$of=format_time(Queries::$start);return
query_redirect($re,$z,$A,$we,false,!$we,$of);}function
format_time($Ze){return
sprintf('%.3f s',max(0,microtime(true)-$Ze));}function
relative_uri(){return
str_replace(":","%3a",preg_replace('~^[^?]*/([^?]*)~','\1',$_SERVER["REQUEST_URI"]));}function
remove_from_uri($Wd=""){return
substr(preg_replace("~(?<=[?&])($Wd".(SID?"":"|".session_name()).")=[^&]*&~",'',relative_uri()."&"),0,-1);}function
get_file($w,$ib=false,$lb=""){$Vb=$_FILES[$w];if(!$Vb)return
null;foreach($Vb
as$w=>$W)$Vb[$w]=(array)$W;$K='';foreach($Vb["error"]as$w=>$i){if($i)return$i;$C=$Vb["name"][$w];$uf=$Vb["tmp_name"][$w];$Va=file_get_contents($ib&&preg_match('~\.gz$~',$C)?"compress.zlib://$uf":$uf);if($ib){$Ze=substr($Va,0,3);if(function_exists("iconv")&&preg_match("~^\xFE\xFF|^\xFF\xFE~",$Ze))$Va=iconv("utf-16","utf-8",$Va);elseif($Ze=="\xEF\xBB\xBF")$Va=substr($Va,3);}$K
.=$Va;if($lb)$K
.=(preg_match("($lb\\s*\$)",$Va)?"":$lb)."\n\n";}return$K;}function
upload_error($i){$qd=($i==UPLOAD_ERR_INI_SIZE?ini_get("upload_max_filesize"):0);return($i?'Unable to upload a file.'.($qd?" ".sprintf('Maximum allowed file size is %sB.',$qd):""):'File does not exist.');}function
repeat_pattern($ce,$fd){return
str_repeat("$ce{0,65535}",$fd/65535)."$ce{0,".($fd%65535)."}";}function
is_utf8($W){return(preg_match('~~u',$W)&&!preg_match('~[\0-\x8\xB\xC\xE-\x1F]~',$W));}function
format_number($W){return
strtr(number_format($W,0,".",','),preg_split('~~u','0123456789',-1,PREG_SPLIT_NO_EMPTY));}function
friendly_url($W){return
preg_replace('~\W~i','-',$W);}function
table_status1($R,$Sb=false){$K=table_status($R,$Sb);return($K?reset($K):array("Name"=>$R));}function
column_foreign_keys($R){$K=array();foreach(adminer()->foreignKeys($R)as$fc){foreach($fc["source"]as$W)$K[$W][]=$fc;}return$K;}function
fields_from_edit(){$K=array();foreach((array)$_POST["field_keys"]as$w=>$W){if($W!=""){$W=bracket_escape($W);$_POST["function"][$W]=$_POST["field_funs"][$w];$_POST["fields"][$W]=$_POST["field_vals"][$w];}}foreach((array)$_POST["fields"]as$w=>$W){$C=bracket_escape($w,true);$K[$C]=array("field"=>$C,"privileges"=>array("insert"=>1,"update"=>1,"where"=>1,"order"=>1),"null"=>1,"auto_increment"=>($w==driver()->primary),);}return$K;}function
dump_headers($Ec,$zd=false){$K=adminer()->dumpHeaders($Ec,$zd);$Td=$_POST["output"];if($Td!="text")header("Content-Disposition: attachment; filename=".adminer()->dumpFilename($Ec).".$K".($Td!="file"&&preg_match('~^[0-9a-z]+$~',$Td)?".$Td":""));session_write_close();if(!ob_get_level())ob_start(null,4096);ob_flush();flush();return$K;}function
dump_csv(array$L){foreach($L
as$w=>$W){if(preg_match('~["\n,;\t]|^0|\.\d*0$~',$W)||$W==="")$L[$w]='"'.str_replace('"','""',$W).'"';}echo
implode(($_POST["format"]=="csv"?",":($_POST["format"]=="tsv"?"\t":";")),$L)."\r\n";}function
apply_sql_function($o,$c){return($o?($o=="unixepoch"?"DATETIME($c, '$o')":($o=="count distinct"?"COUNT(DISTINCT ":strtoupper("$o("))."$c)"):$c);}function
get_temp_dir(){$K=ini_get("upload_tmp_dir");if(!$K){if(function_exists('sys_get_temp_dir'))$K=sys_get_temp_dir();else{$l=@tempnam("","");if(!$l)return'';$K=dirname($l);unlink($l);}}return$K;}function
file_open_lock($l){if(is_link($l))return;$n=@fopen($l,"c+");if(!$n)return;chmod($l,0660);if(!flock($n,LOCK_EX)){fclose($n);return;}return$n;}function
file_write_unlock($n,$fb){rewind($n);fwrite($n,$fb);ftruncate($n,strlen($fb));file_unlock($n);}function
file_unlock($n){flock($n,LOCK_UN);fclose($n);}function
first(array$ja){return
reset($ja);}function
password_file($Ya){$l=get_temp_dir()."/adminer.key";if(!$Ya&&!file_exists($l))return'';$n=file_open_lock($l);if(!$n)return'';$K=stream_get_contents($n);if(!$K){$K=rand_string();file_write_unlock($n,$K);}else
file_unlock($n);return$K;}function
rand_string(){return
md5(uniqid(strval(mt_rand()),true));}function
select_value($W,$y,array$j,$mf){if(is_array($W)){$K="";foreach($W
as$Wc=>$V)$K
.="<tr>".($W!=array_values($W)?"<th>".h($Wc):"")."<td>".select_value($V,$y,$j,$mf);return"<table>$K</table>";}if(!$y)$y=adminer()->selectLink($W,$j);if($y===null){if(is_mail($W))$y="mailto:$W";if(is_url($W))$y=$W;}$K=adminer()->editVal($W,$j);if($K!==null){if(!is_utf8($K))$K="\0";elseif($mf!=""&&is_shortable($j))$K=shorten_utf8($K,max(0,+$mf));else$K=h($K);}return
adminer()->selectVal($K,$y,$j,$W);}function
is_mail($zb){$la='[-a-z0-9!#$%&\'*+/=?^_`{|}~]';$sb='[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])';$ce="$la+(\\.$la+)*@($sb?\\.)+$sb";return
is_string($zb)&&preg_match("(^$ce(,\\s*$ce)*\$)i",$zb);}function
is_url($Q){$sb='[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])';return
preg_match("~^(https?)://($sb?\\.)+$sb(:\\d+)?(/.*)?(\\?.*)?(#.*)?\$~i",$Q);}function
is_shortable(array$j){return
preg_match('~char|text|json|lob|geometry|point|linestring|polygon|string|bytea~',$j["type"]);}function
count_rows($R,array$Z,$v,array$p){$I=" FROM ".table($R).($Z?" WHERE ".implode(" AND ",$Z):"");return($v&&(JUSH=="sql"||count($p)==1)?"SELECT COUNT(DISTINCT ".implode(", ",$p).")$I":"SELECT COUNT(*)".($v?" FROM (SELECT 1$I GROUP BY ".implode(", ",$p).") x":$I));}function
slow_query($I){$g=adminer()->database();$pf=adminer()->queryTimeout();$Se=driver()->slowQuery($I,$pf);$f=null;if(!$Se&&support("kill")){$f=connect();if($f&&($g==""||$f->select_db($g))){$Yc=get_val(connection_id(),0,$f);echo
script("const timeout = setTimeout(() => { ajax('".js_escape(ME)."script=kill', function () {}, 'kill=$Yc&token=".get_token()."'); }, 1000 * $pf);");}}ob_flush();flush();$K=@get_key_vals(($Se?:$I),$f,false);if($f){echo
script("clearTimeout(timeout);");ob_flush();flush();}return$K;}function
get_token(){$ue=rand(1,1e6);return($ue^$_SESSION["token"]).":$ue";}function
verify_token(){list($vf,$ue)=explode(":",$_POST["token"]);return($ue^$_SESSION["token"])==$vf;}function
lzw_decompress($ya){$pb=256;$za=8;$La=array();$Ae=0;$Be=0;for($q=0;$q<strlen($ya);$q++){$Ae=($Ae<<8)+ord($ya[$q]);$Be+=8;if($Be>=$za){$Be-=$za;$La[]=$Ae>>$Be;$Ae&=(1<<$Be)-1;$pb++;if($pb>>$za)$za++;}}$ob=range("\0","\xFF");$K="";$Wf="";foreach($La
as$q=>$Ka){$yb=$ob[$Ka];if(!isset($yb))$yb=$Wf.$Wf[0];$K
.=$yb;if($q)$ob[]=$Wf.$yb[0];$Wf=$yb;}return$K;}function
script($Ue,$wf="\n"){return"<script".nonce().">$Ue</script>$wf";}function
script_src($Lf,$jb=false){return"<script src='".h($Lf)."'".nonce().($jb?" defer":"")."></script>\n";}function
nonce(){return' nonce="'.get_nonce().'"';}function
input_hidden($C,$X=""){return"<input type='hidden' name='".h($C)."' value='".h($X)."'>\n";}function
input_token(){return
input_hidden("token",get_token());}function
target_blank(){return' target="_blank" rel="noreferrer noopener"';}function
h($Q){return
str_replace("\0","&#0;",htmlspecialchars($Q,ENT_QUOTES,'utf-8'));}function
nl_br($Q){return
str_replace("\n","<br>",$Q);}function
checkbox($C,$X,$Ga,$Zc="",$Ld="",$Ja="",$bd=""){$K="<input type='checkbox' name='$C' value='".h($X)."'".($Ga?" checked":"").($bd?" aria-labelledby='$bd'":"").">".($Ld?script("qsl('input').onclick = function () { $Ld };",""):"");return($Zc!=""||$Ja?"<label".($Ja?" class='$Ja'":"").">$K".h($Zc)."</label>":$K);}function
optionlist($D,$Ie=null,$Nf=false){$K="";foreach($D
as$Wc=>$V){$Pd=array($Wc=>$V);if(is_array($V)){$K
.='<optgroup label="'.h($Wc).'">';$Pd=$V;}foreach($Pd
as$w=>$W)$K
.='<option'.($Nf||is_string($w)?' value="'.h($w).'"':'').($Ie!==null&&($Nf||is_string($w)?(string)$w:$W)===$Ie?' selected':'').'>'.h($W);if(is_array($V))$K
.='</optgroup>';}return$K;}function
html_select($C,array$D,$X="",$Kd="",$bd=""){static$Zc=0;$ad="";if(!$bd&&substr($D[""],0,1)=="("){$Zc++;$bd="label-$Zc";$ad="<option value='' id='$bd'>".h($D[""]);unset($D[""]);}return"<select name='".h($C)."'".($bd?" aria-labelledby='$bd'":"").">".$ad.optionlist($D,$X)."</select>".($Kd?script("qsl('select').onchange = function () { $Kd };",""):"");}function
html_radios($C,array$D,$X="",$Le=""){$K="";foreach($D
as$w=>$W)$K
.="<label><input type='radio' name='".h($C)."' value='".h($w)."'".($w==$X?" checked":"").">".h($W)."</label>$Le";return$K;}function
confirm($A="",$Je="qsl('input')"){return
script("$Je.onclick = () => confirm('".($A?js_escape($A):'Are you sure?')."');","");}function
print_fieldset($r,$ed,$Sf=false){echo"<fieldset><legend>","<a href='#fieldset-$r'>$ed</a>",script("qsl('a').onclick = partial(toggle, 'fieldset-$r');",""),"</legend>","<div id='fieldset-$r'".($Sf?"":" class='hidden'").">\n";}function
bold($_a,$Ja=""){return($_a?" class='active $Ja'":($Ja?" class='$Ja'":""));}function
js_escape($Q){return
addcslashes($Q,"\r\n'\\/");}function
pagination($F,$db){return" ".($F==$db?$F+1:'<a href="'.h(remove_from_uri("page").($F?"&page=$F".($_GET["next"]?"&next=".urlencode($_GET["next"]):""):"")).'">'.($F+1)."</a>");}function
hidden_fields(array$oe,array$Gc=array(),$ke=''){$K=false;foreach($oe
as$w=>$W){if(!in_array($w,$Gc)){if(is_array($W))hidden_fields($W,array(),$w);else{$K=true;echo
input_hidden(($ke?$ke."[$w]":$w),$W);}}}return$K;}function
hidden_fields_get(){echo(sid()?input_hidden(session_name(),session_id()):''),(SERVER!==null?input_hidden(DRIVER,SERVER):""),input_hidden("username",$_GET["username"]);}function
enum_input($T,$b,array$j,$X,$Bb=null){preg_match_all("~'((?:[^']|'')*)'~",$j["length"],$nd);$K=($Bb!==null?"<label><input type='$T'$b value='$Bb'".((is_array($X)?in_array($Bb,$X):$X===$Bb)?" checked":"")."><i>".'empty'."</i></label>":"");foreach($nd[1]as$q=>$W){$W=stripcslashes(str_replace("''","'",$W));$Ga=(is_array($X)?in_array($W,$X):$X===$W);$K
.=" <label><input type='$T'$b value='".h($W)."'".($Ga?' checked':'').'>'.h(adminer()->editVal($W,$j)).'</label>';}return$K;}function
input(array$j,$X,$o,$qa=false){$C=h(bracket_escape($j["field"]));echo"<td class='function'>";if(is_array($X)&&!$o){$X=json_encode($X,128|64|256);$o="json";}$_e=(JUSH=="mssql"&&$j["auto_increment"]);if($_e&&!$_POST["save"])$o=null;$kc=(isset($_GET["select"])||$_e?array("orig"=>'original'):array())+adminer()->editFunctions($j);$qb=stripos($j["default"],"GENERATED ALWAYS AS ")===0?" disabled=''":"";$b=" name='fields[$C]'$qb".($qa?" autofocus":"");$Fb=driver()->enumLength($j);if($Fb){$j["type"]="enum";$j["length"]=$Fb;}echo
driver()->unconvertFunction($j)." ";$R=$_GET["edit"]?:$_GET["select"];if($j["type"]=="enum")echo
h($kc[""])."<td>".adminer()->editInput($R,$j,$b,$X);else{$tc=(in_array($o,$kc)||isset($kc[$o]));echo(count($kc)>1?"<select name='function[$C]'$qb>".optionlist($kc,$o===null||$tc?$o:"")."</select>".on_help("event.target.value.replace(/^SQL\$/, '')",1).script("qsl('select').onchange = functionChange;",""):h(reset($kc))).'<td>';$Mc=adminer()->editInput($R,$j,$b,$X);if($Mc!="")echo$Mc;elseif(preg_match('~bool~',$j["type"]))echo"<input type='hidden'$b value='0'>"."<input type='checkbox'".(preg_match('~^(1|t|true|y|yes|on)$~i',$X)?" checked='checked'":"")."$b value='1'>";elseif($j["type"]=="set"){preg_match_all("~'((?:[^']|'')*)'~",$j["length"],$nd);foreach($nd[1]as$q=>$W){$W=stripcslashes(str_replace("''","'",$W));$Ga=in_array($W,explode(",",$X),true);echo" <label><input type='checkbox' name='fields[$C][$q]' value='".h($W)."'".($Ga?' checked':'').">".h(adminer()->editVal($W,$j)).'</label>';}}elseif(preg_match('~blob|bytea|raw|file~',$j["type"])&&ini_bool("file_uploads"))echo"<input type='file' name='fields-$C'>";elseif($o=="json"||preg_match('~^jsonb?$~',$j["type"]))echo"<textarea$b cols='50' rows='12' class='jush-js'>".h($X).'</textarea>';elseif(($lf=preg_match('~text|lob|memo~i',$j["type"]))||preg_match("~\n~",$X)){if($lf&&JUSH!="sqlite")$b
.=" cols='50' rows='12'";else{$M=min(12,substr_count($X,"\n")+1);$b
.=" cols='30' rows='$M'";}echo"<textarea$b>".h($X).'</textarea>';}else{$Bf=driver()->types();$sd=(!preg_match('~int~',$j["type"])&&preg_match('~^(\d+)(,(\d+))?$~',$j["length"],$_)?((preg_match("~binary~",$j["type"])?2:1)*$_[1]+($_[3]?1:0)+($_[2]&&!$j["unsigned"]?1:0)):($Bf[$j["type"]]?$Bf[$j["type"]]+($j["unsigned"]?0:1):0));if(JUSH=='sql'&&min_version(5.6)&&preg_match('~time~',$j["type"]))$sd+=7;echo"<input".((!$tc||$o==="")&&preg_match('~(?<!o)int(?!er)~',$j["type"])&&!preg_match('~\[\]~',$j["full_type"])?" type='number'":"")." value='".h($X)."'".($sd?" data-maxlength='$sd'":"").(preg_match('~char|binary~',$j["type"])&&$sd>20?" size='".($sd>99?60:40)."'":"")."$b>";}echo
adminer()->editHint($R,$j,$X);$Yb=0;foreach($kc
as$w=>$W){if($w===""||!$W)break;$Yb++;}if($Yb&&count($kc)>1)echo
script("qsl('td').oninput = partial(skipOriginal, $Yb);");}}function
process_input(array$j){if(stripos($j["default"],"GENERATED ALWAYS AS ")===0)return;$s=bracket_escape($j["field"]);$o=idx($_POST["function"],$s);$X=$_POST["fields"][$s];if($j["type"]=="enum"||driver()->enumLength($j)){if($X==-1)return
false;if($X=="")return"NULL";}if($j["auto_increment"]&&$X=="")return
null;if($o=="orig")return(preg_match('~^CURRENT_TIMESTAMP~i',$j["on_update"])?idf_escape($j["field"]):false);if($o=="NULL")return"NULL";if($j["type"]=="set")$X=implode(",",(array)$X);if($o=="json"){$o="";$X=json_decode($X,true);if(!is_array($X))return
false;return$X;}if(preg_match('~blob|bytea|raw|file~',$j["type"])&&ini_bool("file_uploads")){$Vb=get_file("fields-$s");if(!is_string($Vb))return
false;return
driver()->quoteBinary($Vb);}return
adminer()->processInput($j,$X,$o);}function
search_tables(){$_GET["where"][0]["val"]=$_POST["query"];$Ke="<ul>\n";foreach(table_status('',true)as$R=>$S){$C=adminer()->tableName($S);if(isset($S["Engine"])&&$C!=""&&(!$_POST["tables"]||in_array($R,$_POST["tables"]))){$J=connection()->query("SELECT".limit("1 FROM ".table($R)," WHERE ".implode(" AND ",adminer()->selectSearchProcess(fields($R),array())),1));if(!$J||$J->fetch_row()){$me="<a href='".h(ME."select=".urlencode($R)."&where[0][op]=".urlencode($_GET["where"][0]["op"])."&where[0][val]=".urlencode($_GET["where"][0]["val"]))."'>$C</a>";echo"$Ke<li>".($J?$me:"<p class='error'>$me: ".error())."\n";$Ke="";}}}echo($Ke?"<p class='message'>".'No tables.':"</ul>")."\n";}function
on_help($Qa,$Re=0){return
script("mixin(qsl('select, input'), {onmouseover: function (event) { helpMouseover.call(this, event, $Qa, $Re) }, onmouseout: helpMouseout});","");}function
edit_form($R,array$k,$L,$Jf,$i=''){$if=adminer()->tableName(table_status1($R,true));page_header(($Jf?'Edit':'Insert'),$i,array("select"=>array($R,$if)),$if);adminer()->editRowPrint($R,$k,$L,$Jf);if($L===false){echo"<p class='error'>".'No rows.'."\n";return;}echo"<form action='' method='post' enctype='multipart/form-data' id='form'>\n";if(!$k)echo"<p class='error'>".'You have no privileges to update this table.'."\n";else{echo"<table class='layout'>".script("qsl('table').onkeydown = editingKeydown;");$qa=!$_POST;foreach($k
as$C=>$j){echo"<tr><th>".adminer()->fieldName($j);$h=idx($_GET["set"],bracket_escape($C));if($h===null){$h=$j["default"];if($j["type"]=="bit"&&preg_match("~^b'([01]*)'\$~",$h,$ye))$h=$ye[1];if(JUSH=="sql"&&preg_match('~binary~',$j["type"]))$h=bin2hex($h);}$X=($L!==null?($L[$C]!=""&&JUSH=="sql"&&preg_match("~enum|set~",$j["type"])&&is_array($L[$C])?implode(",",$L[$C]):(is_bool($L[$C])?+$L[$C]:$L[$C])):(!$Jf&&$j["auto_increment"]?"":(isset($_GET["select"])?false:$h)));if(!$_POST["save"]&&is_string($X))$X=adminer()->editVal($X,$j);$o=($_POST["save"]?idx($_POST["function"],$C,""):($Jf&&preg_match('~^CURRENT_TIMESTAMP~i',$j["on_update"])?"now":($X===false?null:($X!==null?'':'NULL'))));if(!$_POST&&!$Jf&&$X==$j["default"]&&preg_match('~^[\w.]+\(~',$X))$o="SQL";if(preg_match("~time~",$j["type"])&&preg_match('~^CURRENT_TIMESTAMP~i',$X)){$X="";$o="now";}if($j["type"]=="uuid"&&$X=="uuid()"){$X="";$o="uuid";}if($qa!==false)$qa=($j["auto_increment"]||$o=="now"||$o=="uuid"?null:true);input($j,$X,$o,$qa);if($qa)$qa=false;echo"\n";}if(!support("table")&&!fields($R))echo"<tr>"."<th><input name='field_keys[]'>".script("qsl('input').oninput = fieldChange;")."<td class='function'>".html_select("field_funs[]",adminer()->editFunctions(array("null"=>isset($_GET["select"]))))."<td><input name='field_vals[]'>"."\n";echo"</table>\n";}echo"<p>\n";if($k){echo"<input type='submit' value='".'Save'."'>\n";if(!isset($_GET["select"]))echo"<input type='submit' name='insert' value='".($Jf?'Save and continue edit':'Save and insert next')."' title='Ctrl+Shift+Enter'>\n",($Jf?script("qsl('input').onclick = function () { return !ajaxForm(this.form, '".'Saving'."â€¦', this); };"):"");}echo($Jf?"<input type='submit' name='delete' value='".'Delete'."'>".confirm()."\n":"");if(isset($_GET["select"]))hidden_fields(array("check"=>(array)$_POST["check"],"clone"=>$_POST["clone"],"all"=>$_POST["all"]));echo
input_hidden("referer",(isset($_POST["referer"])?$_POST["referer"]:$_SERVER["HTTP_REFERER"])),input_hidden("save",1),input_token(),"</form>\n";}function
shorten_utf8($Q,$fd=80,$ef=""){if(!preg_match("(^(".repeat_pattern("[\t\r\n -\x{10FFFF}]",$fd).")($)?)u",$Q,$_))preg_match("(^(".repeat_pattern("[\t\r\n -~]",$fd).")($)?)",$Q,$_);return
h($_[1]).$ef.(isset($_[2])?"":"<i>â€¦</i>");}function
icon($Dc,$C,$Cc,$qf){return"<button type='submit' name='$C' title='".h($qf)."' class='icon icon-$Dc'><span>$Cc</span></button>";}if(isset($_GET["file"])){if(substr(VERSION,-4)!='-dev'){if($_SERVER["HTTP_IF_MODIFIED_SINCE"]){header("HTTP/1.1 304 Not Modified");exit;}header("Expires: ".gmdate("D, d M Y H:i:s",time()+365*24*60*60)." GMT");header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");header("Cache-Control: immutable");}@ini_set("zlib.output_compression",'1');if($_GET["file"]=="default.css"){header("Content-Type: text/css; charset=utf-8");echo
lzw_decompress("h:M‡±h´ÄgÌĞ±ÜÍŒ\"PÑiÒm„™cQCa¤é	2Ã³éˆŞd<Ìfóa¼ä:;NBˆqœR;1Lf³9ÈŞu7&)¤l;3ÍÑñÈÀJ/‹†CQXÊr2MÆaäi0›„ƒ)°ìe:LuÃhæ-9ÕÍ23lÈÎi7†³màZw4™†Ñš<-•ÒÌ´¹!†U,—ŒFÃ©”vt2‘S,¬äa´Ò‡FêVXúa˜Nqã)“-—ÖÎÇœhê:n5û9ÈY¨;jµ”-Ş÷_‘9krùœÙ“;.ĞtTqËo¦0‹³­Öò®{íóyùı\rçHnìGS™ Zh²œ;¼i^ÀuxøWÎ’C@Äö¤©k€Ò=¡Ğb©Ëâì¼/AØà0¤+Â(ÚÁ°lÂÉÂ\\ê Ãxè:\rèÀb8\0æ–0!\0FÆ\nB”Íã(Ò3 \r\\ºÛêÈ„a¼„œ'Iâ|ê(iš\n‹\r©¸ú4Oüg@4ÁC’î¼†º@@†!ÄQB°İ	Â°¸c¤ÊÂ¯Äq,\r1EhèÈ&2PZ‡¦ğiGûH9G’\"v§ê’¢££¤œ4r”ÆñÍDĞR¤\n†pJë-A“|/.¯cê“Du·£¤ö:,˜Ê=°¢RÅ]U5¥mVÁkÍLLQ@-\\ª¦ËŒ@9Áã%ÚSrÁÎñMPDãÂIa\rƒ(YY\\ã@XõpÃê:£p÷lLC —Åñè¸ƒÍÊO,\rÆ2]7œ?m06ä»pÜTÑÍaÒ¥Cœ;_Ë—ÑyÈ´d‘>¨²bnğ…«n¼Ü£3÷X¾€ö8\rí[Ë€-)Ûi>V[Yãy&L3¯#ÌX|Õ	†X \\Ã¹`ËC§ç˜å#ÑÙHÉÌ2Ê2.# ö‹Zƒ`Â<¾ãs®·¹ªÃ’£º\0uœhÖ¾—¥M²Í_\niZeO/CÓ’_†`3İòğ1>‹=Ğk3£…‰R/;ä/dÛÜ\0ú‹ŒãŞÚµmùúò¾¤7/«ÖAÎXƒÂÿ„°“Ãq.½sáL£ı— :\$ÉF¢—¸ª¾£‚w‰8óß¾~«HÔj…­\"¨¼œ•¹Ô³7gSõä±âFLéÎ¯çQò_¤’O'WØö]c=ı5¾1X~7;˜™iş´\rí*\n’¨JS1Z¦™ø£ØÆßÍcå‚tœüAÔVí86fĞdÃy;Y]©õzIÀp¡Ñû§ğc‰3®YË]}Â˜@¡\$.+”1¶'>ZÃcpdàéÒGLæá„#kô8PzœYÒAuÏvİ]s9‰ÑØ_AqÎÁ„:†ÆÅ\nK€hB¼;­ÖŠXbAHq,âCIÉ`†‚çj¹S[ËŒ¶1ÆVÓrŠñÔ;¶pŞBÃÛ)#é‰;4ÌHñÒ/*Õ<Â3L Á;lfª\n¶s\$K`Ğ}ÆôÕ”£¾7ƒjx`d–%j] ¸4œ—Y¤–HbY ØJ`¤GG ’.ÅÜK‚òfÊI©)2ÂŠMfÖ¸İX‰RC‰¸Ì±V,©ÛÑ~g\0è‚àg6İ:õ[jí1H½:AlIq©u3\"™êæq¤æ|8<9s'ãQ]JÊ|Ğ\0Â`p ³îƒ«‰jf„OÆbĞÉú¬¨q¬¢\$é©²Ã1J¹>RœH(Ç”q\n#rŠ’à@e(yóVJµ0¡QÒˆ£òˆ6†Pæ[C:·Gä¼‘ İ4©‘Ò^ÓğÃPZŠµ\\´‘è(\nÖ)š~¦´°9R%×Sj·{‰7ä0Ş_šÇs	z|8ÅHê	\"@Ü#9DVLÅ\$H5ÔWJ@—…z®a¿J Ä^	‘)®2\nQvÀÔ]ëÇ†ÄÁ˜‰j (A¸Ó°BB05´6†bË°][ŒèkªA•wvkgôÆ´öºÕ+k[jm„zc¶}èMyDZií\$5e˜«Ê·°º	”A˜ CY%.W€b*ë®¼‚.­Ùóq/%}BÌXˆ­çZV337‡Ê»a™„€ºòŞwW[áLQÊŞ²ü_È2`Ç1IÑi,÷æ›£’Mf&(s-˜ä˜ëÂAÄ°Ø*””DwØÄTNÀÉ»ÅjX\$éxª+;ĞğËFÚ93µJkÂ™S;·§ÁqR{>l;B1AÈIâb) (6±­r÷\rİ\rÚ‡’Ú‚ìZ‘R^SOy/“ŞM#ÆÏ9{k„àê¸v\"úKCâJƒ¨rEo\0øÌ\\,Ñ|faÍš†³hI“©/oÌ4Äk^pî1HÈ^“ÍphÇ¡VÁvox@ø`ígŸ&(ùˆ­ü;›ƒ~ÇzÌ6×8¯*°ÆÜ5®Ü‰±E ÁÂp†éâîÓ˜˜¤´3“öÅ†gŸ™rDÑLó)4g{»ˆä½å³©—Lš&ú>è„»¢ØÚZì7¡\0ú°ÌŠ@×ĞÓÛœffÅRVhÖ²çIŠÛˆ½âğrÓw)‹ ‚„=x^˜,k’Ÿ2ôÒİ“jàbël0uë\"¬fp¨¸1ñRI¿ƒz[]¤wpN6dIªzëõån.7X{;ÁÈ3ØË-I	‹âûü7pjÃ¢R#ª,ù_-ĞüÂ[ó>3À\\æêÛWqŞq”JÖ˜uh£‡ĞFbLÁKÔåçyVÄ¾©¦ÃŞÑ•®µªüVœîÃf{K}S ÊŞ…‰Mş‡·Í€¼¦.M¶\\ªix¸bÁ¡1‡+£Î±?<Å3ê~HıÓ\$÷\\Ğ2Û\$î eØ6tÔOÌˆã\$s¼¼©xÄşx•ó§CánSkVÄÉ=z6½‰¡Ê'Ã¦äNaŸ¢Ö¸hŒÜü¸º±ı¯R¤å™£8g‰¢äÊw:_³î­íÿêÒ’IRKÃ¨.½nkVU+dwj™§%³`#,{é†³ËğÊƒY‡ı×õ(oÕ¾Éğ.¨c‚0gâDXOk†7®èKäÎlÒÍhx;ÏØ İƒLû´\$09*–9 ÜhNrüMÕ.>\0ØrP9ï\$Èg	\0\$\\Fó*²d'ÎõLå:‹bú—ğ42Àô¢ğ9Àğ@ÂHnbì-¤óE #ÄœÉÃ¨\0ÀpY‚ê¨ tÍ Ø\nğ5.©àÊâî\$op l€X\n@`\r€	àˆ\r€Ğ Î ¦ ” ‚ àêğÛ`”\r ´\r £`‚` „0åpä	‘Ş@“\0’ÀĞ	 V\0ò`fÀÏÀª\0¤ Îf€\0j\n f`â	 ®\n`´@˜\$n=`†\0ÈÀƒànIĞ\$ÿP(Âd'Ëğô„Äà·gÉ\n¬4±\n0·¤ˆ.0ÃpËğÒ\r\0‡`–1`“àÎ\n\0_ óqñ1qµ`ß\0¡À”‚ äà˜†\0¢\n@â€ fÍPæ€æ RÇ ŞÇì‚€@ÙrÇFˆ˜¯h\r€@J¶Ñ^LNË!Àé\"\nÒÄeÊ]r:ÊZ7Ò9#\$0¬µ\"gÚ­t”RB×|‘/¼#í”×¸D’1\"®Ff‡\"nºòæ(Yp`W…”YÆ‘Ò]\$ÀFğF¨ğ¯ÜRn\ràw!MrìæK²*s%S\$² Ä¨.s*G*R©(=+‹Ş‹	\n)Òdûò£*mp‘‚\$rĞìä×\$”ÜÀë-â?.2©+r:~²Ğ‚I69+œ4H¼h ú\nz\"Ğ(,2 +Döjuåt@q. ğ³²½RÃ&i,kJ–r`„cÀÕ\"¢CIÑ	êâz8ÚŒ¥¾Û\r´š¯8êÒøİfƒ¢¿ëÃ.\"úÖËä›ê®Ó*h(åé\0ôO‰ªªÍ€Õ r| Ş…M\nĞå¾­o|LJªê²v1N´Ü3E(„R\".fh+FW/ÒÎIšÎ“~ğ/)ÀÚ¦\rÄ‰ï<ÀÛ=h1‰b]¢Ô&Åiœò-òmRôç?ä0Íîú“¦ĞäÔï êïl¦“‰„“ ×®×@ÎÚœo~ò³DÒì—T7t	>k'\$1+î*’ã)2tëzÃ2©<”Y)sæğÓêta4€û1³,\rø+îµ=7l©B/ï;î²×åŠû¯¾ì­)„!>“í<fš¡j]¸ ê\\àÉKç\$Äª5*rQ4‚");}elseif($_GET["file"]=="dark.css"){header("Content-Type: text/css; charset=utf-8");echo
lzw_decompress("h:M‡±h´ÄgÆÈh0ÁLĞàd91¢S!¤Û	Fƒ!°æ\"-6N‘€ÄbdGgÓ°Â:;Nr£)öc7›\rç(HØb81˜†s9¼¤Ük\rçc)Êm8O•VA¡Âc1”c34Of*’ª- P¨‚1©”r41Ùî6˜Ìd2ŒÖ•®Ûo½ÜÌ#3—‰–BÇf#	ŒÖg9Î¦êØŒfc\rÇI™ĞÂb6E‡C&¬Ğ,buÄêm7aVã•ÂÁs²#m!ôèhµårùœŞv\\3\rL:SA”Âdk5İnÇ·×ìšıÊaF†¸3é˜Òe6fS¦ëy¾óør!ÇLú -ÎK,Ì3Lâ@º“J¶ƒË²¢*J äìµ£¤‚»	¸ğ—¹Ášb©cèà9­ˆê9¹¤æ@ÏÔè¿ÃHÜ8£ \\·Ãê6>«`ğÅ¸Ş;‡Aˆà<T™'¨p&q´qEˆê4Å\rl­…ÃhÂ<5#pÏÈR Ñ#I„İ%„êfBIØŞÜ²”¨>…Ê«29<«åCîj2¯î»¦¶7j¬“8jÒìc(nÔÄç?(a\0Å@”5*3:Î´æ6Œ£˜æ0Œã-àAÀlL›•PÆ4@ÊÉ°ê\$¡H¥4 n31¶æ1Ítò0®áÍ™9ŒƒéWO!¨r¼ÚÔØÜÛÕèHÈ†£Ã9ŒQ°Â96èF±¬«<ø7°\rœ-xC\n Üã®@Òø…ÜÔƒ:\$iÜØ¶m«ªË4íKid¬²{\n6\r–…xhË‹â#^'4Vø@aÍÇ<´#h0¦Sæ-…c¸Ö9‰+pŠ«Ša2Ôcy†h®BO\$Áç9öw‡iX›É”ùVY9*r÷Htm	@bÖÑ|@ü/€l’\$z¦­ +Ô%p2l‹˜É.õØúÕÛìÄ7ï;Ç&{ÀËm„€X¨C<l9ğí6x9ïmìò¤ƒ¯À­7RüÀ0\\ê4Î÷PÈ)AÈoÀx„ÄÚqÍO#¸¥Èf[;»ª6~PÛ\rŒa¸ÊTGT0„èìu¸ŞŸ¾³Ş\n3ğ\\ \\ÊƒJ©udªCGÀ§©PZ÷>“³Áûd8ÖÒ¨èéñ½ïåôC?V…·dLğÅL.(tiƒ’­>«,ôƒÖLÀ");}elseif($_GET["file"]=="functions.js"){header("Content-Type: text/javascript; charset=utf-8");echo
lzw_decompress("':œÌ¢™Ğäi1ã³1Ôİ	4›ÍÀ£‰ÌQ6a&ó°Ç:OAIìäe:NFáD|İ!‘Ÿ†CyŒêm2ËÅ\"ã‰ÔÊr<”Ì±˜ÙÊ/C#‚‘Ùö:DbqSe‰JË¦CÜº\n\n¡œÇ±S\rZ“H\$RAÜS+XKvtdÜg:£í6Ÿ‰EvXÅ³j‘ÉmÒ©ej×2šM§©äúB«Ç&Ê®‹L§C°3„åQ0ÕLÆé-xè\nÓìD‘ÈÂyNaäPn:ç›¼äèsœÍƒ( cLÅÜ/õ£(Æ5{ŞôQy4œøg-–‚ı¢êi4ÚƒfĞÎ(ÕëbUıÏk·îo7Ü&ãºÃ¤ô*ACb’¾¢Ø`.‡­ŠÛ\rÎĞÜü»ÏÄú¼Í\n ©ChÒ<\r)`èØ¥`æ7¥CÊ’ŒÈâZùµãXÊ<QÅ1X÷¼‰@·0dp9EQüf¾°ÓFØ\r‰ä!ƒæ‹(hô£)‰Ã\np'#ÄŒ¤£HÌ(i*†r¸æ&<#¢æ7KÈÈ~Œ# È‡A:N6ã°Ê‹©lÕ,§\r”ôJPÎ3£!@Ò2>Cr¾¡¬h°N„á]¦(a0M3Í2”×6…ÔUæ„ãE2'!<·Â#3R<ğÛãXÒæÔCHÎ7ƒ#nä+±€a\$!èÜ2àPˆ0¤.°wd¡r:Yö¨éE²æ…!]„<¹šjâ¥ó@ß\\×pl§_\rÁZ¸€Ò“¬TÍ©ZÉsò3\"²~9À©³jã‰PØ)Q“Ybİ•DëYc¿`ˆzácµÑ¨ÌÛ'ë#t“BOh¢*2ÿ…<Å’Oêfg-Z£œˆÕ# è8aĞ^ú+r2b‰ø\\á~0©áş“¥ùàW©¸ÁŞnœÙp!#•`åëZö¸6¶12×Ã@é²kyÈÆ9\rìäB3çƒpŞ…î6°è<£!pïG¯9àn‘o›6s¿ğ#FØ3íÙàbA¨Ê6ñ9¦ıÀZ£#ÂŞ6ûÊ%?‡s¨È\"ÏÉ|Ø‚§)şbœJc\r»Œ½NŞsÉÛih8Ï‡¹æİŸè:Š;èúHåŞŒõu‹I5û@è1îªAèPaH^\$H×vãÖ@Ã›L~—¨ùb9'§ø¿±S?PĞ-¯˜ò˜0Cğ\nRòmÌ4‡ŞÓÈ“:ÀõÜÔ¸ï2òÌ4œµh(k\njIŠÈ6\"˜EYˆ#¹W’rª\r‘G8£@tĞáXÔ“âÌBS\nc0Ék‚C I\rÊ°<u`A!ó)ĞÔ2”ÖC¢\0=‡¾ æáäPˆ1‘Ó¢K!¹!†åŸpÄIsÑ,6âdÃéÉi1+°ÈâÔk‰€ê<•¸^	á\nÉ20´FÔ‰_\$ë)f\0 ¤C8E^¬Ä/3W!×)Œu™*äÔè&\$ê”2Y\n©]’„EkñDV¨\$ïJ²’‡xTse!RY» R™ƒ`=Lò¸ãàŞ«\nl_.!²V!Â\r\nHĞk²\$×`{1	|± °i<jRrPTG|‚w©4b´\r‰¡Ç4d¤,§E¡È6©äÏ<Ãh[N†q@Oi×>'Ñ©\rŠ¥ó—;¦]#“æ}Ğ0»ASIšJdÑA/QÁ´â¸µÂ@t\r¥UG‚Ä_G<éÍ<y-IÉzò„¤Ğ\" PÂàB\0ıíÀÈÁœq`‘ïvAƒˆaÌ¡Jå RäÊ®)Œ…JB.¦TÜñL¡îy¢÷ Cpp\0(7†cYY•a¨M€é1•em4Óc¢¸r£«S)oñÍà‚pæC!I†¼¾SÂœb0mìñ(d“EHœøš¸ß³„X‹ª£/¬•™P©èøyÆXé85ÈÒ\$+—Ö–»²gdè€öÎÎyİÜÏ³J×Øë ¢lE“¢urÌ,dCX}e¬ìÅ¥õ«mƒ]ˆĞ2 Ì½È(-z¦‚Zåú;Iöî¼\\Š) ,\n¤>ò)·¤æ\rVS\njx*w`â´·SFiÌÓd¯¼,»áĞZÂJFM}ĞŠ À†\\Z¾Pìİ`¹zØZûE]íd¤”ÉŸOëcmÔ]À ¬Á™•‚ƒ%ş\"w4Œ¥\n\$øÉzV¢SQDÛ:İ6«äG‹wMÔîS0B‰-sÆê)ã¾Zí¤cÇ2†˜Î´A;æ¥n©Wz/AÃZh G~cœc%Ë[ÉD£&lFRæ˜77|ªI„¢3¹íg0ÖLƒˆa½äcÃ0RJ‘2ÏÑ%“³ÃFáº SÃ ©L½^‘ trÚîÙtñÃ›¡Ê©;”Ç.å–šÅ”>ù€Ãá[®a‡N»¤Ï^Ã(!g—@1ğğó¢üN·zÔ<béİ–ŒäÛÑõO,ÛóCîuº¸D×tjŞ¹I;)®İ€é\nnäcºáÈ‚íˆW<sµ	Å\0÷hN¼PÓ9ÎØ{ue…¤utëµ•öè°ºó§½ 3ò‡î=ƒg¥ëº¸ÎÓJìÍºòWQ‡0ø•Øw9p-…Àº	ı§”øËğÙ'5»´\nOÛ÷e)MÈ)_kàz\0V´ÖÚúŞ;jîlîÎ\nÀ¦êçxÕPf-ä`CË.@&]#\0Ú¶pğyÍ–Æ›ŒtËdú¶ Ãó¼b}	G1·mßru™ßÀ*ñ_ÀxD²3Çq¼„BÓsQæ÷u€ús%ê\nª5s§ut½„Â{sòy¥€øNŸ¯4¥,J{4@®ş\0»’PÄÊÃ^ºš=“¯l„“²`èe~FÙ¡h3oé\"¤”q·R<iUT°[QàôUˆÇM6üT. ºê0'pe\\¼½ôŞ5ßÖÌ”pCe	Ù•Ô\"* M	”¨¦–D™ş±?ûhüØ2¡ĞãzU@7°CÓ4ıaµ²iE!fË\$üB¤…<œ9o*\$¯ælH™\$ Å@ààÊæ€P\rNÀYn<\$²	ÀQ…=F&¥ *@]\0ÊÏË W'dÖ z\$æĞjĞP[¢ö\$òä¯Ğ0#& _Ì`+†B)„wŒv%	âÔ›LcJ„€RSÀÂi`ÌÅ®	F€W	êË\nBP\nç\r\0}	ï¦®0²Zğ¸‚ò/`j\$«: §8ieüÀØÏ†xâ¹Â±îa ¬GnøsgO¢äU%VU°†@‚NÀ¤Ïúd+®(oJï†@XÆèàzM'FÙ£àWhV®I^Ù¢™1>İ@Ğ\"î¨¤‰ ÈQñR!‘\\¢`[¥¤«¨‰.Ø0fb†F;ëÂ‡çFpÏp/t`Â ô®(§ÀVé¸ø b“È²‰(€ˆHˆl‚œÁÎÔ¯1v­Ş‘€ğHĞï1Tï3ñ“q›àÉ1¦ÑªfË\nT\$°éàNq+Ëí`ŞvÖÇœï\rüVmûÇr°¨Ø'Ï¸±ñg%«\"Lˆm¼…‘(’(CLzˆ\"hâXØm= \\H\n0U‡‚ f&M\$¤g\$ñU`a\rPş>`Ë#gªhôî`†R4H€Ñ'ç©­³²GK;\"M¶Û¨TŒhµBEn\"b> Ú\rÀš©#›\0æ•N:í#_	QQ1{	f:BËÂáRª&àÜã)JµÄBr¹+ÂK.\$ĞPqõ-r®S%TIT&Qö·Ò{#2o(*P¯â5ï`„1H…®¢'	<Tğd±÷ª¾sÀì,NÚÊ ÒÉÔì^\r%ƒ3îĞ\r&à“4Bì/\0ĞkLH\$³4dÓ>ŠàÒ/³à¶µ€Hö€·* ºù3JÇĞ¥<†Hh©pú'‚çO/&ï2I.îx3V.¢s5Óe3íªÛZÛ(õ9E”g§;R—;±J½‘QÃ@ªÓvgz@¶“‚Şó†'dZ&Â,Uã²ßò¦F æb*²D‹òH! ä\r’;%‡x'G#°šÍ w‰Á#°Ö È2;#òBvÀXÉâ”aí\nb”{4K€G¦ß%°†ÒGuE`\\\rB\r\0¨-mW\rM\"¶#EôcFbFÕnzÓóÿ@4JÈÒ[\$Êë%2V”‹%ô&TÔV›ˆdÕ4hemN¯-;EÄ¾%E¥E´r <\"@»FÔPÂ€·L Üß­Ü4EÉğ°ÒÄz`ĞuŒ7éNŠ4¯Ë\0°F:hÎKœh/:\"™MÊZÔö\r+P4\r?¤™Sø™O;B©0\$FCEp‚ÇM\"%H4D´|€LN†FtEÑşgŠş°5å=J\r\"›Ş¼5³õ4à¾KñP\rbZà¨\r\"pEQ'DwKõW0î’g'…l\"hQFïC,ùCcŒ®òIHÒP hF]5µ& fŸTæÌiSTUS¨ÿîÉ[4™[uºNe–\$oüKìÜO àÿb\" 5ï\0›DÅ)EÒ%\"±]Âî/­âÈĞŒJ­6UÂdÿ‡`õña)V-0—DÓ”bMÍ)­šŠïÔ¯ØıÄ`Šæ%ñELtˆ˜+ìÛ6C7jëdµ¤:´V4Æ¡3î -ßR\rGòIT®…#¥<4-CgCP{V…\$'ëˆÓ÷gàûR@ä'Ğ²S=%À½óFñk: ¢k‘Ø9®²¤óe]aO¼ÒG9˜;îù-6Ûâ8WÀ¨*øx\"U‹®YlBïîöò¯ğÖ´°·	§ı\n‚îp®ğÉlšÉìÒZ–m\0ñ5¢òä®ğOqÌ¨ÌÍbÊW1s@ĞùKéº-pîûÆE¦Spw\nGWoQÓqG}vp‹w}q€ñqÓ\\Æ7ÆRZ÷@Ìì¡t‡ıtÆ;pG}w×€/%\"LE\0tÀhâ)§\r€àJÚ\\W@à	ç|D#S³¸ÆƒVÏâR±z‰2Ïõövµú©–‘	ã}¨’‡¢¯(¸\0y<¤X\r×İx±°‹q·<µœIsk1Sñ-Q4Yq8î#Şîv—îĞd.Ö¹S;qË!,'(òƒä<.è±J7Hç\"’š.³·¨ñuŒ°‡ü€#ÊQ\reƒrÀXv[¬h\$â{-éY °ûJBgé‰iM8¸”'Â\nÆ˜tDZ~/‹b‹ÖÕ8¸\$¸¸DbROÂOÆû`O5S>¸ö˜Î[ DÇê”¸¥ä€_3Xø)©À'éÄJd\rX»©¸UDìU X8ò•x¯-æ—…àPÌN` 	à¦\nŠZà‹”@Ra48§Ì:ø©\0éŠx°†ÖN§\\ê0%ãŒ·f“˜\\ ğ>\"@^\0ZxàZŸ\0ZaBr#åXÇğ\r•¨{•àË•¹flFb\0[–Şˆ\0[—6›˜	˜¢° ©=’â\n ¦WBøÆ\$'©kG´(\$yÌe9Ò(8Ù& h®îRÜ”ÙæoØÈ¼ Ç‡øƒ†Y£–4Øô7_’­dùã9'ı‘¢ú Üúï²ûz\r™ÙÖ  Ÿåğşv›G€èO8èØìMOh'æèXöS0³\0\0Ê	¸ı9s?‡öI¹MY¢8Ø 9ğ˜üä£HO“—,4	•xs‘‚P¤*G‡¢çc8·ªQÉ ø˜wB|Àz	@¦	à£9cÉK¤¤QGÄbFjÀXú’oSª\$ˆdFHÄ‚PÃ@Ñ§<å¶´Å,‚}ï®m£–rœÿ\"Å'k‹`Œ¡cà¡x‹¦e»C¨ÑCìì:¼ŞØ:XÌ ¹TŞÂÂ^´dÆÃ†qh¤ÎsÃ¹×LvÊÒ®0\r,4µ\r_vÔLòj¥jMáb[  ğƒlsÀŞ•Z°@øºäÁ¶;f”í`2Ycëeº'ƒMerÊÛF\$È!êê\n ¤	*0\rºAN»LP¥äjÙ“»»¿¼;Æ£VÓQ|(ğ‰3’†ÄÊ[p‰˜8óú¼|Ô^\räBf/DÆØÕÒ Bğ€_¶N5Mô© \$¼\naZĞ¦¶È€~ÀUlï¥eõrÅ§rÒ™Z®aZ³•¹ãøÕ£s8RÀGŒZŒ w®¢ªNœ_Æ±«YÏ£òm­‰âªÀ]’¦;ÆšLÚÿ‚º¶cø™€û°Å°ÆÚIÀQ3¹”Oã‡Ç|’y*`  ê5ÉÚ4ğ;&v8‘#¯Rô8+`XÍbVğ6¸Æ«i•3Fõ×EĞô„Øoc82ÛM­\"¶˜¹©G¦Wb\rOĞC¿VdèÓ­¤w\\äÍ¯*cSiÀQÒ¯“ã³R`úd7}	‚ºš)¢Ï´·,+bd§Û¹½FN£3¾¹L\\ãşeRn\$&\\rôê+dæÕ]O5kq,&\"DCU6j§pçÇÉ\\'‚@oµ~è5N=¨|”&è´!ÏÕBØwˆHÚyyz7Ï·(Çøâ½b5(3Öƒ_\0`zĞb®Ğ£r½‚8	ğ¢ZàvÈ8LË“·)²SİM<²*7\$›º\rRŒb·–âB%ıàÆ´Ds€zÏR>[‚Q½ŒĞ&Q«¨À¯¡Ì'\r‡ppÌz·/<‹‡}L¢#°Î•ÂĞâZ¹ã²\"tÆï\n„.4Şgæ«Pºp®Dìnà¥Ê¹NÈâFàd\0`^—åä\rnÈ‚×³#_âÄ w(ü2÷<7-ªXŞ¹\0··s¬ø,^¹hC,å!:×\rK„Ó.äİÓ¢¯Å¢ï¹ÔØ\\„ò+v˜Zàê\0§Q9eÊ›ËEöw?>°\$}£·D#ªğã cÓ0MV3½%Y»ÛÀ\rûÄtj5ÔÅ7¼ü{ÅšLz=­<ƒë8IøMõ°•õâGØÑÎŞLÅ\$’á2‰€{(ÿpe?uİ,Rïd*Xº4é®ı¿‡Í\0\"@Šˆš}<.@õ’	€ŞN²²\$î«XUjsİ/üî<>\"* è#\$Ôş÷Õ&CPI	ÿèt¿áùü¦î?è †´	ğOËÇ\\ Ì_èÎQ5YH@‹ŠÙbâÑcÑhî·ùæë±––…O0T©' 8¡wü»­öj+H€v_#º„íïì06ÈwÖœX†à»d+£Ü“\\Àå–\n\0	\\ğŸŸ>sî…ÓšA	PFöd8m'@š\nH´\0¬cèOwSßØ’—Yá`²ˆˆ¨¢R×ıDna\" ì™~Â?Ámğ†|@6ä½+ìGxV’ä\0°‰WƒÓ°’nw”„‘.¡Øƒb«Ÿ9Ã¸ˆEÈ|E·ÃÂ\rĞˆr¬\"Ğøx„‘¸-¸êŠâš\rN6n·\$Ò¬ı-BíHæ^Ó)â¥y&ãã×šW–Ç§àbv…Rì	¸¥³N\0°Ànâ	T„–`8X¬ğA\r:{Oş@\" Œ!Á¤\$KÂäqoĞËjYÖªJ´şÂíÜh}d<1IÇxdŠÊÎTT4NeeC0ä¥¿‡:D›FÚ5LŞ*::H”jZå—­FõRªMÖ€nS\n>POó[Œ\$V8;#‰K\\'ùBÖè»R®Ø¯°›RÑ_8Ájé*Ej \\~vÆÂĞvÄÛp@T€X‹\0002dE	…Hí‡Vğñ×D”\"Q'EDJB~A´ƒA¤Il*'\n¶Yå.è›+©9¾ñpg†ƒÒ/\"¸1—8Ä0„IAÊFCÈ¨ŠV*a™èPÀdÖĞ£5H\" AØå6İs¬YİØ;è¨È/¨¸0ãv}y˜\rÍƒâÎ×¥1…u\"Ë‹Šmãñ_º0ç„„`ß¯¿\\B1^\nk\r]lhø}]HBW`±—0½ê¨¹rFf€)”W,ÕÒ§]sm9'O¢xÔ½Í,ê9J8§£? 4ÉÉï¡\"Ò…èÛ½Ì<Ñ-S¨ÉÃşMÃ;ĞvÌñ6y|„ZòÁ‹¨%àa•#8¢ˆTC‘!pºË\nØïCZ(ï½9|Ü¾æª,Ú\nº+Q\$äÅ­ôÈ+İ_+ãÊ\$¸ú%d  eDQ‚JŸØü¥iXˆ}\0P×¾‡²Çü·æ”BPë†¾ÄW?¥úÉè¯Œ‹7áHQ~§üWòşS¾É\n?	Å ç€Êúö>µ!oĞ\0ğR1áÂ9‚c‘x\$bĞ6ŠzB‹ƒ‹”\"ÄY«Ö²‚©ù\$k#w 4„Èr’¿ÆîˆÎ|J y>ãú\$˜¹'İà)æ~8˜ÀÂ„é-¼«ÒD”‡Äu!¥~öCÌ&c–dPú&ö–¡şÈ‚Aîœ<=bnIÿ	\\‰xÑÈX'@ˆ	ùËÛOìƒçSª`XÉ‘[dÓ!ÕŠâ&¹Šèå‡±Aà!I\$'””íUS(&SîÚl¨¼®uk—†GÉ'»¡Rš>WI¡~Òj”Œ™†L¦õ>…ôbË(Ğ™ßé'U²IİÄ’º½¤<òI(¡*Jc¢XBÖ|zGprñÔb+LZ‹U­–fQ±<DáçU\n“Tô\"¥ìñaÃ~SÀ™t¤ÂÙ©E|NRĞ");}elseif($_GET["file"]=="jush.js"){header("Content-Type: text/javascript; charset=utf-8");echo
lzw_decompress('');}elseif($_GET["file"]=="logo.png"){header("Content-Type: image/png");echo"‰PNG\r\n\n\0\0\0\rIHDR\0\0\09\0\0\09\0\0\0~6¶\0\0\0000PLTE\0\0\0ƒ—­+NvYt“s‰£®¾´¾ÌÈÒÚü‘üsuüIJ÷ÓÔü/.üü¯±úüúC¥×\0\0\0tRNS\0@æØf\0\0\0	pHYs\0\0\0\0\0šœ\0\0´IDAT8Õ”ÍNÂ@ÇûEáìlÏ¶õ¤p6ˆG.\$=£¥Ç>á	w5r}‚z7²>€‘På#\$Œ³K¡j«7üİ¶¿ÌÎÌ?4m•„ˆÑ÷t&î~À3!0“0Šš^„½Af0Ş\"å½í,Êğ* ç4¼Œâo¥Eè³è×X(*YÓó¼¸	6	ïPcOW¢ÉÎÜŠm’¬rƒ0Ã~/ áL¨\rXj#ÖmÊÁújÀC€]G¦mæ\0¶}ŞË¬ß‘u¼A9ÀX£\nÔØ8¼V±YÄ+ÇD#¨iqŞnKQ8Jà1Q6²æY0§`•ŸP³bQ\\h”~>ó:pSÉ€£¦¼¢ØóGEõQ=îIÏ{’*Ÿ3ë2£7÷\neÊLèBŠ~Ğ/R(\$°)Êç‹ —ÁHQn€i•6J¶	<×-.–wÇÉªjêVm«êüm¿?SŞH ›vÃÌûñÆ©§İ\0àÖ^Õq«¶)ª—Û]÷‹U¹92Ñ,;ÿÇî'pøµ£!XËƒäÚÜÿLñD.»tÃ¦—ı/wÃÓäìR÷	w­dÓÖr2ïÆ¤ª4[=½E5÷S+ñ—c\0\0\0\0IEND®B`‚";}exit;}if($_GET["script"]=="version"){$l=get_temp_dir()."/adminer.version";@unlink($l);$n=file_open_lock($l);if($n)file_write_unlock($n,serialize(array("signature"=>$_POST["signature"],"version"=>$_POST["version"])));exit;}if(!$_SERVER["REQUEST_URI"])$_SERVER["REQUEST_URI"]=$_SERVER["ORIG_PATH_INFO"];if(!strpos($_SERVER["REQUEST_URI"],'?')&&$_SERVER["QUERY_STRING"]!="")$_SERVER["REQUEST_URI"].="?$_SERVER[QUERY_STRING]";if($_SERVER["HTTP_X_FORWARDED_PREFIX"])$_SERVER["REQUEST_URI"]=$_SERVER["HTTP_X_FORWARDED_PREFIX"].$_SERVER["REQUEST_URI"];define('Adminer\HTTPS',($_SERVER["HTTPS"]&&strcasecmp($_SERVER["HTTPS"],"off"))||ini_bool("session.cookie_secure"));@ini_set("session.use_trans_sid",'0');if(!defined("SID")){session_cache_limiter("");session_name("adminer_sid");session_set_cookie_params(0,preg_replace('~\?.*~','',$_SERVER["REQUEST_URI"]),"",HTTPS,true);session_start();}remove_slashes(array(&$_GET,&$_POST,&$_COOKIE),$Xb);if(function_exists("get_magic_quotes_runtime")&&get_magic_quotes_runtime())set_magic_quotes_runtime(false);@set_time_limit(0);@ini_set("precision",'15');function
lang($s,$Gd=null){$ia=func_get_args();$ia[0]=$s;return
call_user_func_array('Adminer\lang_format',$ia);}function
lang_format($yf,$Gd=null){if(is_array($yf)){$he=($Gd==1?0:1);$yf=$yf[$he];}$yf=str_replace("'",'â€™',$yf);$ia=func_get_args();array_shift($ia);$hc=str_replace("%d","%s",$yf);if($hc!=$yf)$ia[0]=format_number($Gd);return
vsprintf($hc,$ia);}define('Adminer\LANG','en');abstract
class
SqlDb{static$instance;var$extension;var$flavor='';var$server_info;var$affected_rows=0;var$info='';var$errno=0;var$error='';protected$multi;abstract
function
attach($O,$U,$G);abstract
function
quote($Q);abstract
function
select_db($gb);abstract
function
query($I,$Cf=false);function
multi_query($I){return$this->multi=$this->query($I);}function
store_result(){return$this->multi;}function
next_result(){return
false;}}if(extension_loaded('pdo')){abstract
class
PdoDb
extends
SqlDb{protected$pdo;function
dsn($ub,$U,$G,array$D=array()){$D[\PDO::ATTR_ERRMODE]=\PDO::ERRMODE_SILENT;$D[\PDO::ATTR_STATEMENT_CLASS]=array('Adminer\PdoResult');try{$this->pdo=new
\PDO($ub,$U,$G,$D);}catch(\Exception$Kb){return$Kb->getMessage();}$this->server_info=@$this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);return'';}function
quote($Q){return$this->pdo->quote($Q);}function
query($I,$Cf=false){$J=$this->pdo->query($I);$this->error="";if(!$J){list(,$this->errno,$this->error)=$this->pdo->errorInfo();if(!$this->error)$this->error='Unknown error.';return
false;}$this->store_result($J);return$J;}function
store_result($J=null){if(!$J){$J=$this->multi;if(!$J)return
false;}if($J->columnCount()){$J->num_rows=$J->rowCount();return$J;}$this->affected_rows=$J->rowCount();return
true;}function
next_result(){$J=$this->multi;if(!is_object($J))return
false;$J->_offset=0;return@$J->nextRowset();}}class
PdoResult
extends
\PDOStatement{var$_offset=0,$num_rows;function
fetch_assoc(){return$this->fetch_array(\PDO::FETCH_ASSOC);}function
fetch_row(){return$this->fetch_array(\PDO::FETCH_NUM);}private
function
fetch_array($xd){$K=$this->fetch($xd);return($K?array_map(array($this,'unresource'),$K):$K);}private
function
unresource($W){return(is_resource($W)?stream_get_contents($W):$W);}function
fetch_field(){$L=(object)$this->getColumnMeta($this->_offset++);$T=$L->pdo_type;$L->type=($T==\PDO::PARAM_INT?0:15);$L->charsetnr=($T==\PDO::PARAM_LOB||(isset($L->flags)&&in_array("blob",(array)$L->flags))?63:0);return$L;}function
seek($Hd){for($q=0;$q<$Hd;$q++)$this->fetch();}}}function
add_driver($r,$C){SqlDriver::$drivers[$r]=$C;}function
get_driver($r){return
SqlDriver::$drivers[$r];}abstract
class
SqlDriver{static$instance;static$drivers=array();static$extensions=array();static$jush;protected$conn;protected$types=array();var$insertFunctions=array();var$editFunctions=array();var$unsigned=array();var$operators=array();var$functions=array();var$grouping=array();var$onActions="RESTRICT|NO ACTION|CASCADE|SET NULL|SET DEFAULT";var$partitionBy=array();var$inout="IN|OUT|INOUT";var$enumLength="'(?:''|[^'\\\\]|\\\\.)*'";var$generated=array();static
function
connect($O,$U,$G){$e=new
Db;return($e->attach($O,$U,$G)?:$e);}function
__construct(Db$e){$this->conn=$e;}function
types(){return
call_user_func_array('array_merge',array_values($this->types));}function
structuredTypes(){return
array_map('array_keys',$this->types);}function
enumLength(array$j){}function
unconvertFunction(array$j){}function
select($R,array$N,array$Z,array$p,array$E=array(),$x=1,$F=0,$me=false){$v=(count($p)<count($N));$I=adminer()->selectQueryBuild($N,$Z,$p,$E,$x,$F);if(!$I)$I="SELECT".limit(($_GET["page"]!="last"&&$x&&$p&&$v&&JUSH=="sql"?"SQL_CALC_FOUND_ROWS ":"").implode(", ",$N)."\nFROM ".table($R),($Z?"\nWHERE ".implode(" AND ",$Z):"").($p&&$v?"\nGROUP BY ".implode(", ",$p):"").($E?"\nORDER BY ".implode(", ",$E):""),$x,($F?$x*$F:0),"\n");$Ze=microtime(true);$K=$this->conn->query($I);if($me)echo
adminer()->selectQuery($I,$Ze,!$K);return$K;}function
delete($R,$se,$x=0){$I="FROM ".table($R);return
queries("DELETE".($x?limit1($R,$I,$se):" $I$se"));}function
update($R,array$P,$se,$x=0,$Le="\n"){$Of=array();foreach($P
as$w=>$W)$Of[]="$w = $W";$I=table($R)." SET$Le".implode(",$Le",$Of);return
queries("UPDATE".($x?limit1($R,$I,$se,$Le):" $I$se"));}function
insert($R,array$P){return
queries("INSERT INTO ".table($R).($P?" (".implode(", ",array_keys($P)).")\nVALUES (".implode(", ",$P).")":" DEFAULT VALUES").$this->insertReturning($R));}function
insertReturning($R){return"";}function
insertUpdate($R,array$M,array$le){return
false;}function
begin(){return
queries("BEGIN");}function
commit(){return
queries("COMMIT");}function
rollback(){return
queries("ROLLBACK");}function
slowQuery($I,$pf){}function
convertSearch($s,array$W,array$j){return$s;}function
convertOperator($Nd){return$Nd;}function
value($W,array$j){return(method_exists($this->conn,'value')?$this->conn->value($W,$j):$W);}function
quoteBinary($De){return
q($De);}function
warnings(){}function
tableHelp($C,$Uc=false){}function
inheritsFrom($R){return
array();}function
inheritedTables($R){return
array();}function
partitionsInfo($R){return
array();}function
hasCStyleEscapes(){return
false;}function
engines(){return
array();}function
supportsIndex(array$S){return!is_view($S);}function
indexAlgorithms(array$hf){return
array();}function
checkConstraints($R){return
get_key_vals("SELECT c.CONSTRAINT_NAME, CHECK_CLAUSE
FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS c
JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME
WHERE c.CONSTRAINT_SCHEMA = ".q($_GET["ns"]!=""?$_GET["ns"]:DB)."
AND t.TABLE_NAME = ".q($R)."
AND CHECK_CLAUSE NOT LIKE '% IS NOT NULL'",$this->conn);}function
allFields(){$K=array();if(DB!=""){foreach(get_rows("SELECT TABLE_NAME AS tab, COLUMN_NAME AS field, IS_NULLABLE AS nullable, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS length".(JUSH=='sql'?", COLUMN_KEY = 'PRI' AS `primary`":"")."
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ".q($_GET["ns"]!=""?$_GET["ns"]:DB)."
ORDER BY TABLE_NAME, ORDINAL_POSITION",$this->conn)as$L){$L["null"]=($L["nullable"]=="YES");$K[$L["tab"]][]=$L;}}return$K;}}class
Adminer{static$instance;var$error='';private$values=array();function
name(){return"<a href='https://www.adminer.org/editor/'".target_blank()." id='h1'><img src='".h(preg_replace("~\\?.*~","",ME)."?file=logo.png&version=5.3.0")."' width='24' height='24' alt='' id='logo'>".'Editor'."</a>";}function
credentials(){return
array(SERVER,$_GET["username"],get_password());}function
connectSsl(){}function
permanentLogin($Ya=false){return
password_file($Ya);}function
bruteForceKey(){return$_SERVER["REMOTE_ADDR"];}function
serverName($O){}function
database(){if(connection()){$hb=adminer()->databases(false);return(!$hb?get_val("SELECT SUBSTRING_INDEX(CURRENT_USER, '@', 1)"):$hb[(information_schema($hb[0])?1:0)]);}}function
operators(){return
array("<=",">=");}function
schemas(){return
schemas();}function
databases($ac=true){return
get_databases($ac);}function
pluginsLinks(){}function
queryTimeout(){return
5;}function
headers(){}function
csp($bb){return$bb;}function
head($eb=null){return
true;}function
bodyClass(){echo" editor";}function
css(){$K=array();foreach(array("","-dark")as$xd){$l="adminer$xd.css";if(file_exists($l)){$Vb=file_get_contents($l);$K["$l?v=".crc32($Vb)]=($xd?"dark":(preg_match('~prefers-color-scheme:\s*dark~',$Vb)?'':'light'));}}return$K;}function
loginForm(){echo"<table class='layout'>\n",adminer()->loginFormField('username','<tr><th>'.'Username'.'<td>',input_hidden("auth[driver]","server").'<input name="auth[username]" autofocus value="'.h($_GET["username"]).'" autocomplete="username" autocapitalize="off">'),adminer()->loginFormField('password','<tr><th>'.'Password'.'<td>','<input type="password" name="auth[password]" autocomplete="current-password">'),"</table>\n","<p><input type='submit' value='".'Login'."'>\n",checkbox("auth[permanent]",1,$_COOKIE["adminer_permanent"],'Permanent login')."\n";}function
loginFormField($C,$xc,$X){return$xc.$X."\n";}function
login($id,$G){return
true;}function
tableName($hf){return
h(isset($hf["Engine"])?($hf["Comment"]!=""?$hf["Comment"]:$hf["Name"]):"");}function
fieldName($j,$E=0){return
h(preg_replace('~\s+\[.*\]$~','',($j["comment"]!=""?$j["comment"]:$j["field"])));}function
selectLinks($hf,$P=""){$a=$hf["Name"];if($P!==null)echo'<p class="tabs"><a href="'.h(ME.'edit='.urlencode($a).$P).'">'.'New item'."</a>\n";}function
foreignKeys($R){return
foreign_keys($R);}function
backwardKeys($R,$gf){$K=array();foreach(get_rows("SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = ".q(adminer()->database())."
AND REFERENCED_TABLE_SCHEMA = ".q(adminer()->database())."
AND REFERENCED_TABLE_NAME = ".q($R)."
ORDER BY ORDINAL_POSITION",null,"")as$L)$K[$L["TABLE_NAME"]]["keys"][$L["CONSTRAINT_NAME"]][$L["COLUMN_NAME"]]=$L["REFERENCED_COLUMN_NAME"];foreach($K
as$w=>$W){$C=adminer()->tableName(table_status1($w,true));if($C!=""){$Fe=preg_quote($gf);$Le="(:|\\s*-)?\\s+";$K[$w]["name"]=(preg_match("(^$Fe$Le(.+)|^(.+?)$Le$Fe\$)iu",$C,$_)?$_[2].$_[3]:$C);}else
unset($K[$w]);}return$K;}function
backwardKeysPrint($ta,$L){foreach($ta
as$R=>$sa){foreach($sa["keys"]as$Pa){$y=ME.'select='.urlencode($R);$q=0;foreach($Pa
as$c=>$W)$y
.=where_link($q++,$c,$L[$W]);echo"<a href='".h($y)."'>".h($sa["name"])."</a>";$y=ME.'edit='.urlencode($R);foreach($Pa
as$c=>$W)$y
.="&set".urlencode("[".bracket_escape($c)."]")."=".urlencode($L[$W]);echo"<a href='".h($y)."' title='".'New item'."'>+</a> ";}}}function
selectQuery($I,$Ze,$Rb=false){return"<!--\n".str_replace("--","--><!-- ",$I)."\n(".format_time($Ze).")\n-->\n";}function
rowDescription($R){foreach(fields($R)as$j){if(preg_match("~varchar|character varying~",$j["type"]))return
idf_escape($j["field"]);}return"";}function
rowDescriptions($M,$ec){$K=$M;foreach($M[0]as$w=>$W){if(list($R,$r,$C)=$this->_foreignColumn($ec,$w)){$Fc=array();foreach($M
as$L)$Fc[$L[$w]]=q($L[$w]);$nb=$this->values[$R];if(!$nb)$nb=get_key_vals("SELECT $r, $C FROM ".table($R)." WHERE $r IN (".implode(", ",$Fc).")");foreach($M
as$B=>$L){if(isset($L[$w]))$K[$B][$w]=(string)$nb[$L[$w]];}}}return$K;}function
selectLink($W,$j){}function
selectVal($W,$y,$j,$Sd){$K=$W;$y=h($y);if(preg_match('~blob|bytea~',$j["type"])&&!is_utf8($W)){$K=lang_format(array('%d byte','%d bytes'),strlen($Sd));if(preg_match("~^(GIF|\xFF\xD8\xFF|\x89PNG\x0D\x0A\x1A\x0A)~",$Sd))$K="<img src='$y' alt='$K'>";}if(like_bool($j)&&$K!="")$K=(preg_match('~^(1|t|true|y|yes|on)$~i',$W)?'yes':'no');if($y)$K="<a href='$y'".(is_url($y)?target_blank():"").">$K</a>";if(preg_match('~date~',$j["type"]))$K="<div class='datetime'>$K</div>";return$K;}function
editVal($W,$j){if(preg_match('~date|timestamp~',$j["type"])&&$W!==null)return
preg_replace('~^(\d{2}(\d+))-(0?(\d+))-(0?(\d+))~','$1-$3-$5',$W);return$W;}function
config(){return
array();}function
selectColumnsPrint($N,$d){}function
selectSearchPrint($Z,$d,$u){$Z=(array)$_GET["where"];echo'<fieldset id="fieldset-search"><legend>'.'Search'."</legend><div>\n";$Xc=array();foreach($Z
as$w=>$W)$Xc[$W["col"]]=$w;$q=0;$k=fields($_GET["select"]);foreach($d
as$C=>$mb){$j=$k[$C];if(preg_match("~enum~",$j["type"])||like_bool($j)){$w=$Xc[$C];$q--;echo"<div>".h($mb).":".input_hidden("where[$q][col]",$C);$W=idx($Z[$w],"val");echo(like_bool($j)?"<select name='where[$q][val]'>".optionlist(array(""=>"",'no','yes'),$W,true)."</select>":enum_input("checkbox"," name='where[$q][val][]'",$j,(array)$W,($j["null"]?0:null))),"</div>\n";unset($d[$C]);}elseif(is_array($D=$this->foreignKeyOptions($_GET["select"],$C))){if($k[$C]["null"])$D[0]='('.'empty'.')';$w=$Xc[$C];$q--;echo"<div>".h($mb).input_hidden("where[$q][col]",$C).input_hidden("where[$q][op]","=").": <select name='where[$q][val]'>".optionlist($D,idx($Z[$w],"val"),true)."</select></div>\n";unset($d[$C]);}}$q=0;foreach($Z
as$W){if(($W["col"]==""||$d[$W["col"]])&&"$W[col]$W[val]"!=""){echo"<div><select name='where[$q][col]'><option value=''>(".'anywhere'.")".optionlist($d,$W["col"],true)."</select>",html_select("where[$q][op]",array(-1=>"")+adminer()->operators(),$W["op"]),"<input type='search' name='where[$q][val]' value='".h($W["val"])."'>".script("mixin(qsl('input'), {onkeydown: selectSearchKeydown, onsearch: selectSearchSearch});","")."</div>\n";$q++;}}echo"<div><select name='where[$q][col]'><option value=''>(".'anywhere'.")".optionlist($d,null,true)."</select>",script("qsl('select').onchange = selectAddRow;",""),html_select("where[$q][op]",array(-1=>"")+adminer()->operators()),"<input type='search' name='where[$q][val]'></div>",script("mixin(qsl('input'), {onchange: function () { this.parentNode.firstChild.onchange(); }, onsearch: selectSearchSearch});"),"</div></fieldset>\n";}function
selectOrderPrint($E,$d,$u){$Rd=array();foreach($u
as$w=>$t){$E=array();foreach($t["columns"]as$W)$E[]=$d[$W];if(count(array_filter($E,'strlen'))>1&&$w!="PRIMARY")$Rd[$w]=implode(", ",$E);}if($Rd)echo'<fieldset><legend>'.'Sort'."</legend><div>","<select name='index_order'>".optionlist(array(""=>"")+$Rd,(idx($_GET["order"],0)!=""?"":$_GET["index_order"]),true)."</select>","</div></fieldset>\n";if($_GET["order"])echo"<div style='display: none;'>".hidden_fields(array("order"=>array(1=>reset($_GET["order"])),"desc"=>($_GET["desc"]?array(1=>1):array()),))."</div>\n";}function
selectLimitPrint($x){echo"<fieldset><legend>".'Limit'."</legend><div>",html_select("limit",array("",50,100),$x),"</div></fieldset>\n";}function
selectLengthPrint($mf){}function
selectActionPrint($u){echo"<fieldset><legend>".'Action'."</legend><div>","<input type='submit' value='".'Select'."'>","</div></fieldset>\n";}function
selectCommandPrint(){return
true;}function
selectImportPrint(){return
true;}function
selectEmailPrint($_b,$d){}function
selectColumnsProcess($d,$u){return
array(array(),array());}function
selectSearchProcess($k,$u){$K=array();foreach((array)$_GET["where"]as$w=>$Z){$Ma=$Z["col"];$Md=$Z["op"];$W=$Z["val"];if(($w>=0&&$Ma!="")||$W!=""){$Sa=array();foreach(($Ma!=""?array($Ma=>$k[$Ma]):$k)as$C=>$j){if($Ma!=""||is_numeric($W)||!preg_match(number_type(),$j["type"])){$C=idf_escape($C);if($Ma!=""&&$j["type"]=="enum")$Sa[]=(in_array(0,$W)?"$C IS NULL OR ":"")."$C IN (".implode(", ",array_map('Adminer\q',$W)).")";else{$nf=preg_match('~char|text|enum|set~',$j["type"]);$X=adminer()->processInput($j,(!$Md&&$nf&&preg_match('~^[^%]+$~',$W)?"%$W%":$W));$Sa[]=driver()->convertSearch($C,$Z,$j).($X=="NULL"?" IS".($Md==">="?" NOT":"")." $X":(in_array($Md,adminer()->operators())||$Md=="="?" $Md $X":($nf?" LIKE $X":" IN (".($X[0]=="'"?str_replace(",","', '",$X):$X).")")));if($w<0&&$W=="0")$Sa[]="$C IS NULL";}}}$K[]=($Sa?"(".implode(" OR ",$Sa).")":"1 = 0");}}return$K;}function
selectOrderProcess($k,$u){$Ic=$_GET["index_order"];if($Ic!="")unset($_GET["order"][1]);if($_GET["order"])return
array(idf_escape(reset($_GET["order"])).($_GET["desc"]?" DESC":""));foreach(($Ic!=""?array($u[$Ic]):$u)as$t){if($Ic!=""||$t["type"]=="INDEX"){$sc=array_filter($t["descs"]);$mb=false;foreach($t["columns"]as$W){if(preg_match('~date|timestamp~',$k[$W]["type"])){$mb=true;break;}}$K=array();foreach($t["columns"]as$w=>$W)$K[]=idf_escape($W).(($sc?$t["descs"][$w]:$mb)?" DESC":"");return$K;}}return
array();}function
selectLimitProcess(){return(isset($_GET["limit"])?intval($_GET["limit"]):50);}function
selectLengthProcess(){return"100";}function
selectEmailProcess($Z,$ec){return
false;}function
selectQueryBuild($N,$Z,$p,$E,$x,$F){return"";}function
messageQuery($I,$of,$Rb=false){return" <span class='time'>".@date("H:i:s")."</span><!--\n".str_replace("--","--><!-- ",$I)."\n".($of?"($of)\n":"")."-->";}function
editRowPrint($R,$k,$L,$Jf){}function
editFunctions($j){$K=array();if($j["null"]&&preg_match('~blob~',$j["type"]))$K["NULL"]='empty';$K[""]=($j["null"]||$j["auto_increment"]||like_bool($j)?"":"*");if(preg_match('~date|time~',$j["type"]))$K["now"]='now';if(preg_match('~_(md5|sha1)$~i',$j["field"],$_))$K[]=strtolower($_[1]);return$K;}function
editInput($R,$j,$b,$X){if($j["type"]=="enum")return(isset($_GET["select"])?"<label><input type='radio'$b value='-1' checked><i>".'original'."</i></label> ":"").enum_input("radio",$b,$j,($X||isset($_GET["select"])?$X:""),($j["null"]?"":null));$D=$this->foreignKeyOptions($R,$j["field"],$X);if($D!==null)return(is_array($D)?"<select$b>".optionlist($D,$X,true)."</select>":"<input value='".h($X)."'$b class='hidden'>"."<input value='".h($D)."' class='jsonly'>"."<div></div>".script("qsl('input').oninput = partial(whisper, '".ME."script=complete&source=".urlencode($R)."&field=".urlencode($j["field"])."&value='); qsl('div').onclick = whisperClick;",""));if(like_bool($j))return'<input type="checkbox" value="1"'.(preg_match('~^(1|t|true|y|yes|on)$~i',$X)?' checked':'')."$b>";$zc="";if(preg_match('~time~',$j["type"]))$zc='HH:MM:SS';if(preg_match('~date|timestamp~',$j["type"]))$zc='[yyyy]-mm-dd'.($zc?" [$zc]":"");if($zc)return"<input value='".h($X)."'$b> ($zc)";if(preg_match('~_(md5|sha1)$~i',$j["field"]))return"<input type='password' value='".h($X)."'$b>";return'';}function
editHint($R,$j,$X){return(preg_match('~\s+(\[.*\])$~',($j["comment"]!=""?$j["comment"]:$j["field"]),$_)?h(" $_[1]"):'');}function
processInput($j,$X,$o=""){if($o=="now")return"$o()";$K=$X;if(preg_match('~date|timestamp~',$j["type"])&&preg_match('(^'.str_replace('\$1','(?P<p1>\d*)',preg_replace('~(\\\\\\$([2-6]))~','(?P<p\2>\d{1,2})',preg_quote('$1-$3-$5'))).'(.*))',$X,$_))$K=($_["p1"]!=""?$_["p1"]:($_["p2"]!=""?($_["p2"]<70?20:19).$_["p2"]:gmdate("Y")))."-$_[p3]$_[p4]-$_[p5]$_[p6]".end($_);$K=q($K);if($X==""&&like_bool($j))$K="'0'";elseif($X==""&&($j["null"]||!preg_match('~char|text~',$j["type"])))$K="NULL";elseif(preg_match('~^(md5|sha1)$~',$o))$K="$o($K)";return
unconvert_field($j,$K);}function
dumpOutput(){return
array();}function
dumpFormat(){return
array('csv'=>'CSV,','csv;'=>'CSV;','tsv'=>'TSV');}function
dumpDatabase($g){}function
dumpTable($R,$cf,$Uc=0){echo"\xef\xbb\xbf";}function
dumpData($R,$cf,$I){$J=connection()->query($I,1);if($J){while($L=$J->fetch_assoc()){if($cf=="table"){dump_csv(array_keys($L));$cf="INSERT";}dump_csv($L);}}}function
dumpFilename($Ec){return
friendly_url($Ec);}function
dumpHeaders($Ec,$zd=false){$Nb="csv";header("Content-Type: text/csv; charset=utf-8");return$Nb;}function
dumpFooter(){}function
importServerPath(){}function
homepage(){return
true;}function
navigation($wd){echo"<h1>".adminer()->name()." <span class='version'>".VERSION;$Cd=$_COOKIE["adminer_version"];echo" <a href='https://www.adminer.org/editor/#download'".target_blank()." id='version'>".(version_compare(VERSION,$Cd)<0?h($Cd):"")."</a>","</span></h1>\n";if($wd=="auth"){$Yb=true;foreach((array)$_SESSION["pwds"]as$Y=>$Ne){foreach($Ne[""]as$U=>$G){if($G!==null){if($Yb){echo"<ul id='logins'>",script("mixin(qs('#logins'), {onmouseover: menuOver, onmouseout: menuOut});");$Yb=false;}echo"<li><a href='".h(auth_url($Y,"",$U))."'>".($U!=""?h($U):"<i>".'empty'."</i>")."</a>\n";}}}}else{adminer()->databasesPrint($wd);if($wd!="db"&&$wd!="ns"){$S=table_status('',true);if(!$S)echo"<p class='message'>".'No tables.'."\n";else
adminer()->tablesPrint($S);}}}function
syntaxHighlighting($jf){}function
databasesPrint($wd){}function
tablesPrint($jf){echo"<ul id='tables'>",script("mixin(qs('#tables'), {onmouseover: menuOver, onmouseout: menuOut});");foreach($jf
as$L){echo'<li>';$C=adminer()->tableName($L);if($C!="")echo"<a href='".h(ME).'select='.urlencode($L["Name"])."'".bold($_GET["select"]==$L["Name"]||$_GET["edit"]==$L["Name"],"select")." title='".'Select data'."'>$C</a>\n";}echo"</ul>\n";}function
_foreignColumn($ec,$c){foreach((array)$ec[$c]as$dc){if(count($dc["source"])==1){$C=adminer()->rowDescription($dc["table"]);if($C!=""){$r=idf_escape($dc["target"][0]);return
array($dc["table"],$r,$C);}}}}private
function
foreignKeyOptions($R,$c,$X=null){if(list($kf,$r,$C)=$this->_foreignColumn(column_foreign_keys($R),$c)){$K=&$this->values[$kf];if($K===null){$S=table_status1($kf);$K=($S["Rows"]>1000?"":array(""=>"")+get_key_vals("SELECT $r, $C FROM ".table($kf)." ORDER BY 2"));}if(!$K&&$X!==null)return
get_val("SELECT $C FROM ".table($kf)." WHERE $r = ".q($X));return$K;}}}class
Plugins{private
static$append=array('dumpFormat'=>true,'dumpOutput'=>true,'editRowPrint'=>true,'editFunctions'=>true,'config'=>true);var$plugins;var$error='';private$hooks=array();function
__construct($fe){if($fe===null){$fe=array();$wa="adminer-plugins";if(is_dir($wa)){foreach(glob("$wa/*.php")as$l)$Hc=include_once"./$l";}$yc=" href='https://www.adminer.org/plugins/#use'".target_blank();if(file_exists("$wa.php")){$Hc=include_once"./$wa.php";if(is_array($Hc)){foreach($Hc
as$ee)$fe[get_class($ee)]=$ee;}else$this->error
.=sprintf('%s must <a%s>return an array</a>.',"<b>$wa.php</b>",$yc)."<br>";}foreach(get_declared_classes()as$Ja){if(!$fe[$Ja]&&preg_match('~^Adminer\w~i',$Ja)){$xe=new
\ReflectionClass($Ja);$Ua=$xe->getConstructor();if($Ua&&$Ua->getNumberOfRequiredParameters())$this->error
.=sprintf('<a%s>Configure</a> %s in %s.',$yc,"<b>$Ja</b>","<b>$wa.php</b>")."<br>";else$fe[$Ja]=new$Ja;}}}$this->plugins=$fe;$ba=new
Adminer;$fe[]=$ba;$xe=new
\ReflectionObject($ba);foreach($xe->getMethods()as$vd){foreach($fe
as$ee){$C=$vd->getName();if(method_exists($ee,$C))$this->hooks[$C][]=$ee;}}}function
__call($C,array$Xd){$ia=array();foreach($Xd
as$w=>$W)$ia[]=&$Xd[$w];$K=null;foreach($this->hooks[$C]as$ee){$X=call_user_func_array(array($ee,$C),$ia);if($X!==null){if(!self::$append[$C])return$X;$K=$X+(array)$K;}}return$K;}}abstract
class
Plugin{protected$translations=array();function
description(){return$this->lang('');}function
screenshot(){return"";}protected
function
lang($s,$Gd=null){$ia=func_get_args();$ia[0]=idx($this->translations[LANG],$s)?:$s;return
call_user_func_array('Adminer\lang_format',$ia);}}Adminer::$instance=(function_exists('adminer_object')?adminer_object():(is_dir("adminer-plugins")||file_exists("adminer-plugins.php")?new
Plugins(null):new
Adminer));SqlDriver::$drivers=array("server"=>"MySQL / MariaDB")+SqlDriver::$drivers;if(!defined('Adminer\DRIVER')){define('Adminer\DRIVER',"server");if(extension_loaded("mysqli")&&$_GET["ext"]!="pdo"){class
Db
extends
\MySQLi{static$instance;var$extension="MySQLi",$flavor='';function
__construct(){parent::init();}function
attach($O,$U,$G){mysqli_report(MYSQLI_REPORT_OFF);list($Ac,$ge)=explode(":",$O,2);$Ye=adminer()->connectSsl();if($Ye)$this->ssl_set($Ye['key'],$Ye['cert'],$Ye['ca'],'','');$K=@$this->real_connect(($O!=""?$Ac:ini_get("mysqli.default_host")),($O.$U!=""?$U:ini_get("mysqli.default_user")),($O.$U.$G!=""?$G:ini_get("mysqli.default_pw")),null,(is_numeric($ge)?intval($ge):ini_get("mysqli.default_port")),(is_numeric($ge)?null:$ge),($Ye?($Ye['verify']!==false?2048:64):0));$this->options(MYSQLI_OPT_LOCAL_INFILE,false);return($K?'':$this->error);}function
set_charset($Ea){if(parent::set_charset($Ea))return
true;parent::set_charset('utf8');return$this->query("SET NAMES $Ea");}function
next_result(){return
self::more_results()&&parent::next_result();}function
quote($Q){return"'".$this->escape_string($Q)."'";}}}elseif(extension_loaded("mysql")&&!((ini_bool("sql.safe_mode")||ini_bool("mysql.allow_local_infile"))&&extension_loaded("pdo_mysql"))){class
Db
extends
SqlDb{private$link;function
attach($O,$U,$G){if(ini_bool("mysql.allow_local_infile"))return
sprintf('Disable %s or enable %s or %s extensions.',"'mysql.allow_local_infile'","MySQLi","PDO_MySQL");$this->link=@mysql_connect(($O!=""?$O:ini_get("mysql.default_host")),("$O$U"!=""?$U:ini_get("mysql.default_user")),("$O$U$G"!=""?$G:ini_get("mysql.default_password")),true,131072);if(!$this->link)return
mysql_error();$this->server_info=mysql_get_server_info($this->link);return'';}function
set_charset($Ea){if(function_exists('mysql_set_charset')){if(mysql_set_charset($Ea,$this->link))return
true;mysql_set_charset('utf8',$this->link);}return$this->query("SET NAMES $Ea");}function
quote($Q){return"'".mysql_real_escape_string($Q,$this->link)."'";}function
select_db($gb){return
mysql_select_db($gb,$this->link);}function
query($I,$Cf=false){$J=@($Cf?mysql_unbuffered_query($I,$this->link):mysql_query($I,$this->link));$this->error="";if(!$J){$this->errno=mysql_errno($this->link);$this->error=mysql_error($this->link);return
false;}if($J===true){$this->affected_rows=mysql_affected_rows($this->link);$this->info=mysql_info($this->link);return
true;}return
new
Result($J);}}class
Result{var$num_rows;private$result;private$offset=0;function
__construct($J){$this->result=$J;$this->num_rows=mysql_num_rows($J);}function
fetch_assoc(){return
mysql_fetch_assoc($this->result);}function
fetch_row(){return
mysql_fetch_row($this->result);}function
fetch_field(){$K=mysql_fetch_field($this->result,$this->offset++);$K->orgtable=$K->table;$K->charsetnr=($K->blob?63:0);return$K;}function
__destruct(){mysql_free_result($this->result);}}}elseif(extension_loaded("pdo_mysql")){class
Db
extends
PdoDb{var$extension="PDO_MySQL";function
attach($O,$U,$G){$D=array(\PDO::MYSQL_ATTR_LOCAL_INFILE=>false);$Ye=adminer()->connectSsl();if($Ye){if($Ye['key'])$D[\PDO::MYSQL_ATTR_SSL_KEY]=$Ye['key'];if($Ye['cert'])$D[\PDO::MYSQL_ATTR_SSL_CERT]=$Ye['cert'];if($Ye['ca'])$D[\PDO::MYSQL_ATTR_SSL_CA]=$Ye['ca'];if(isset($Ye['verify']))$D[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=$Ye['verify'];}return$this->dsn("mysql:charset=utf8;host=".str_replace(":",";unix_socket=",preg_replace('~:(\d)~',';port=\1',$O)),$U,$G,$D);}function
set_charset($Ea){return$this->query("SET NAMES $Ea");}function
select_db($gb){return$this->query("USE ".idf_escape($gb));}function
query($I,$Cf=false){$this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,!$Cf);return
parent::query($I,$Cf);}}}class
Driver
extends
SqlDriver{static$extensions=array("MySQLi","MySQL","PDO_MySQL");static$jush="sql";var$unsigned=array("unsigned","zerofill","unsigned zerofill");var$operators=array("=","<",">","<=",">=","!=","LIKE","LIKE %%","REGEXP","IN","FIND_IN_SET","IS NULL","NOT LIKE","NOT REGEXP","NOT IN","IS NOT NULL","SQL");var$functions=array("char_length","date","from_unixtime","lower","round","floor","ceil","sec_to_time","time_to_sec","upper");var$grouping=array("avg","count","count distinct","group_concat","max","min","sum");static
function
connect($O,$U,$G){$e=parent::connect($O,$U,$G);if(is_string($e)){if(function_exists('iconv')&&!is_utf8($e)&&strlen($De=iconv("windows-1250","utf-8",$e))>strlen($e))$e=$De;return$e;}$e->set_charset(charset($e));$e->query("SET sql_quote_show_create = 1, autocommit = 1");$e->flavor=(preg_match('~MariaDB~',$e->server_info)?'maria':'mysql');add_driver(DRIVER,($e->flavor=='maria'?"MariaDB":"MySQL"));return$e;}function
__construct(Db$e){parent::__construct($e);$this->types=array('Numbers'=>array("tinyint"=>3,"smallint"=>5,"mediumint"=>8,"int"=>10,"bigint"=>20,"decimal"=>66,"float"=>12,"double"=>21),'Date and time'=>array("date"=>10,"datetime"=>19,"timestamp"=>19,"time"=>10,"year"=>4),'Strings'=>array("char"=>255,"varchar"=>65535,"tinytext"=>255,"text"=>65535,"mediumtext"=>16777215,"longtext"=>4294967295),'Lists'=>array("enum"=>65535,"set"=>64),'Binary'=>array("bit"=>20,"binary"=>255,"varbinary"=>65535,"tinyblob"=>255,"blob"=>65535,"mediumblob"=>16777215,"longblob"=>4294967295),'Geometry'=>array("geometry"=>0,"point"=>0,"linestring"=>0,"polygon"=>0,"multipoint"=>0,"multilinestring"=>0,"multipolygon"=>0,"geometrycollection"=>0),);$this->insertFunctions=array("char"=>"md5/sha1/password/encrypt/uuid","binary"=>"md5/sha1","date|time"=>"now",);$this->editFunctions=array(number_type()=>"+/-","date"=>"+ interval/- interval","time"=>"addtime/subtime","char|text"=>"concat",);if(min_version('5.7.8',10.2,$e))$this->types['Strings']["json"]=4294967295;if(min_version('',10.7,$e)){$this->types['Strings']["uuid"]=128;$this->insertFunctions['uuid']='uuid';}if(min_version(9,'',$e)){$this->types['Numbers']["vector"]=16383;$this->insertFunctions['vector']='string_to_vector';}if(min_version(5.1,'',$e))$this->partitionBy=array("HASH","LINEAR HASH","KEY","LINEAR KEY","RANGE","LIST");if(min_version(5.7,10.2,$e))$this->generated=array("STORED","VIRTUAL");}function
unconvertFunction(array$j){return(preg_match("~binary~",$j["type"])?"<code class='jush-sql'>UNHEX</code>":($j["type"]=="bit"?doc_link(array('sql'=>'bit-value-literals.html'),"<code>b''</code>"):(preg_match("~geometry|point|linestring|polygon~",$j["type"])?"<code class='jush-sql'>GeomFromText</code>":"")));}function
insert($R,array$P){return($P?parent::insert($R,$P):queries("INSERT INTO ".table($R)." ()\nVALUES ()"));}function
insertUpdate($R,array$M,array$le){$d=array_keys(reset($M));$ke="INSERT INTO ".table($R)." (".implode(", ",$d).") VALUES\n";$Of=array();foreach($d
as$w)$Of[$w]="$w = VALUES($w)";$ef="\nON DUPLICATE KEY UPDATE ".implode(", ",$Of);$Of=array();$fd=0;foreach($M
as$P){$X="(".implode(", ",$P).")";if($Of&&(strlen($ke)+$fd+strlen($X)+strlen($ef)>1e6)){if(!queries($ke.implode(",\n",$Of).$ef))return
false;$Of=array();$fd=0;}$Of[]=$X;$fd+=strlen($X)+2;}return
queries($ke.implode(",\n",$Of).$ef);}function
slowQuery($I,$pf){if(min_version('5.7.8','10.1.2')){if($this->conn->flavor=='maria')return"SET STATEMENT max_statement_time=$pf FOR $I";elseif(preg_match('~^(SELECT\b)(.+)~is',$I,$_))return"$_[1] /*+ MAX_EXECUTION_TIME(".($pf*1000).") */ $_[2]";}}function
convertSearch($s,array$W,array$j){return(preg_match('~char|text|enum|set~',$j["type"])&&!preg_match("~^utf8~",$j["collation"])&&preg_match('~[\x80-\xFF]~',$W['val'])?"CONVERT($s USING ".charset($this->conn).")":$s);}function
warnings(){$J=$this->conn->query("SHOW WARNINGS");if($J&&$J->num_rows){ob_start();print_select_result($J);return
ob_get_clean();}}function
tableHelp($C,$Uc=false){$kd=($this->conn->flavor=='maria');if(information_schema(DB))return
strtolower("information-schema-".($kd?"$C-table/":str_replace("_","-",$C)."-table.html"));if(DB=="mysql")return($kd?"mysql$C-table/":"system-schema.html");}function
partitionsInfo($R){$ic="FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ".q(DB)." AND TABLE_NAME = ".q($R);$J=connection()->query("SELECT PARTITION_METHOD, PARTITION_EXPRESSION, PARTITION_ORDINAL_POSITION $ic ORDER BY PARTITION_ORDINAL_POSITION DESC LIMIT 1");$K=array();list($K["partition_by"],$K["partition"],$K["partitions"])=$J->fetch_row();$ae=get_key_vals("SELECT PARTITION_NAME, PARTITION_DESCRIPTION $ic AND PARTITION_NAME != '' ORDER BY PARTITION_ORDINAL_POSITION");$K["partition_names"]=array_keys($ae);$K["partition_values"]=array_values($ae);return$K;}function
hasCStyleEscapes(){static$Ca;if($Ca===null){$Xe=get_val("SHOW VARIABLES LIKE 'sql_mode'",1,$this->conn);$Ca=(strpos($Xe,'NO_BACKSLASH_ESCAPES')===false);}return$Ca;}function
engines(){$K=array();foreach(get_rows("SHOW ENGINES")as$L){if(preg_match("~YES|DEFAULT~",$L["Support"]))$K[]=$L["Engine"];}return$K;}function
indexAlgorithms(array$hf){return(preg_match('~^(MEMORY|NDB)$~',$hf["Engine"])?array("HASH","BTREE"):array());}}function
idf_escape($s){return"`".str_replace("`","``",$s)."`";}function
table($s){return
idf_escape($s);}function
get_databases($ac){$K=get_session("dbs");if($K===null){$I="SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME";$K=($ac?slow_query($I):get_vals($I));restart_session();set_session("dbs",$K);stop_session();}return$K;}function
limit($I,$Z,$x,$Hd=0,$Le=" "){return" $I$Z".($x?$Le."LIMIT $x".($Hd?" OFFSET $Hd":""):"");}function
limit1($R,$I,$Z,$Le="\n"){return
limit($I,$Z,1,0,$Le);}function
db_collation($g,array$Oa){$K=null;$Ya=get_val("SHOW CREATE DATABASE ".idf_escape($g),1);if(preg_match('~ COLLATE ([^ ]+)~',$Ya,$_))$K=$_[1];elseif(preg_match('~ CHARACTER SET ([^ ]+)~',$Ya,$_))$K=$Oa[$_[1]][-1];return$K;}function
logged_user(){return
get_val("SELECT USER()");}function
tables_list(){return
get_key_vals("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");}function
count_tables(array$hb){$K=array();foreach($hb
as$g)$K[$g]=count(get_vals("SHOW TABLES IN ".idf_escape($g)));return$K;}function
table_status($C="",$Sb=false){$K=array();foreach(get_rows($Sb?"SELECT TABLE_NAME AS Name, ENGINE AS Engine, TABLE_COMMENT AS Comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ".($C!=""?"AND TABLE_NAME = ".q($C):"ORDER BY Name"):"SHOW TABLE STATUS".($C!=""?" LIKE ".q(addcslashes($C,"%_\\")):""))as$L){if($L["Engine"]=="InnoDB")$L["Comment"]=preg_replace('~(?:(.+); )?InnoDB free: .*~','\1',$L["Comment"]);if(!isset($L["Engine"]))$L["Comment"]="";if($C!="")$L["Name"]=$C;$K[$L["Name"]]=$L;}return$K;}function
is_view(array$S){return$S["Engine"]===null;}function
fk_support(array$S){return
preg_match('~InnoDB|IBMDB2I'.(min_version(5.6)?'|NDB':'').'~i',$S["Engine"]);}function
fields($R){$kd=(connection()->flavor=='maria');$K=array();foreach(get_rows("SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ".q($R)." ORDER BY ORDINAL_POSITION")as$L){$j=$L["COLUMN_NAME"];$T=$L["COLUMN_TYPE"];$mc=$L["GENERATION_EXPRESSION"];$Qb=$L["EXTRA"];preg_match('~^(VIRTUAL|PERSISTENT|STORED)~',$Qb,$lc);preg_match('~^([^( ]+)(?:\((.+)\))?( unsigned)?( zerofill)?$~',$T,$md);$h=$L["COLUMN_DEFAULT"];if($h!=""){$Tc=preg_match('~text|json~',$md[1]);if(!$kd&&$Tc)$h=preg_replace("~^(_\w+)?('.*')$~",'\2',stripslashes($h));if($kd||$Tc){$h=($h=="NULL"?null:preg_replace_callback("~^'(.*)'$~",function($_){return
stripslashes(str_replace("''","'",$_[1]));},$h));}if(!$kd&&preg_match('~binary~',$md[1])&&preg_match('~^0x(\w*)$~',$h,$_))$h=pack("H*",$_[1]);}$K[$j]=array("field"=>$j,"full_type"=>$T,"type"=>$md[1],"length"=>$md[2],"unsigned"=>ltrim($md[3].$md[4]),"default"=>($lc?($kd?$mc:stripslashes($mc)):$h),"null"=>($L["IS_NULLABLE"]=="YES"),"auto_increment"=>($Qb=="auto_increment"),"on_update"=>(preg_match('~\bon update (\w+)~i',$Qb,$_)?$_[1]:""),"collation"=>$L["COLLATION_NAME"],"privileges"=>array_flip(explode(",","$L[PRIVILEGES],where,order")),"comment"=>$L["COLUMN_COMMENT"],"primary"=>($L["COLUMN_KEY"]=="PRI"),"generated"=>($lc[1]=="PERSISTENT"?"STORED":$lc[1]),);}return$K;}function
indexes($R,$f=null){$K=array();foreach(get_rows("SHOW INDEX FROM ".table($R),$f)as$L){$C=$L["Key_name"];$K[$C]["type"]=($C=="PRIMARY"?"PRIMARY":($L["Index_type"]=="FULLTEXT"?"FULLTEXT":($L["Non_unique"]?($L["Index_type"]=="SPATIAL"?"SPATIAL":"INDEX"):"UNIQUE")));$K[$C]["columns"][]=$L["Column_name"];$K[$C]["lengths"][]=($L["Index_type"]=="SPATIAL"?null:$L["Sub_part"]);$K[$C]["descs"][]=null;$K[$C]["algorithm"]=$L["Index_type"];}return$K;}function
foreign_keys($R){static$ce='(?:`(?:[^`]|``)+`|"(?:[^"]|"")+")';$K=array();$Za=get_val("SHOW CREATE TABLE ".table($R),1);if($Za){preg_match_all("~CONSTRAINT ($ce) FOREIGN KEY ?\\(((?:$ce,? ?)+)\\) REFERENCES ($ce)(?:\\.($ce))? \\(((?:$ce,? ?)+)\\)(?: ON DELETE (".driver()->onActions."))?(?: ON UPDATE (".driver()->onActions."))?~",$Za,$nd,PREG_SET_ORDER);foreach($nd
as$_){preg_match_all("~$ce~",$_[2],$Ue);preg_match_all("~$ce~",$_[5],$kf);$K[idf_unescape($_[1])]=array("db"=>idf_unescape($_[4]!=""?$_[3]:$_[4]),"table"=>idf_unescape($_[4]!=""?$_[4]:$_[3]),"source"=>array_map('Adminer\idf_unescape',$Ue[0]),"target"=>array_map('Adminer\idf_unescape',$kf[0]),"on_delete"=>($_[6]?:"RESTRICT"),"on_update"=>($_[7]?:"RESTRICT"),);}}return$K;}function
view($C){return
array("select"=>preg_replace('~^(?:[^`]|`[^`]*`)*\s+AS\s+~isU','',get_val("SHOW CREATE VIEW ".table($C),1)));}function
collations(){$K=array();foreach(get_rows("SHOW COLLATION")as$L){if($L["Default"])$K[$L["Charset"]][-1]=$L["Collation"];else$K[$L["Charset"]][]=$L["Collation"];}ksort($K);foreach($K
as$w=>$W)sort($K[$w]);return$K;}function
information_schema($g){return($g=="information_schema")||(min_version(5.5)&&$g=="performance_schema");}function
error(){return
h(preg_replace('~^You have an error.*syntax to use~U',"Syntax error",connection()->error));}function
create_database($g,$Na){return
queries("CREATE DATABASE ".idf_escape($g).($Na?" COLLATE ".q($Na):""));}function
drop_databases(array$hb){$K=apply_queries("DROP DATABASE",$hb,'Adminer\idf_escape');restart_session();set_session("dbs",null);return$K;}function
rename_database($C,$Na){$K=false;if(create_database($C,$Na)){$jf=array();$Rf=array();foreach(tables_list()as$R=>$T){if($T=='VIEW')$Rf[]=$R;else$jf[]=$R;}$K=(!$jf&&!$Rf)||move_tables($jf,$Rf,$C);drop_databases($K?array(DB):array());}return$K;}function
auto_increment(){$pa=" PRIMARY KEY";if($_GET["create"]!=""&&$_POST["auto_increment_col"]){foreach(indexes($_GET["create"])as$t){if(in_array($_POST["fields"][$_POST["auto_increment_col"]]["orig"],$t["columns"],true)){$pa="";break;}if($t["type"]=="PRIMARY")$pa=" UNIQUE";}}return" AUTO_INCREMENT$pa";}function
alter_table($R,$C,array$k,array$cc,$Ra,$Cb,$Na,$oa,$Zd){$ga=array();foreach($k
as$j){if($j[1]){$h=$j[1][3];if(preg_match('~ GENERATED~',$h)){$j[1][3]=(connection()->flavor=='maria'?"":$j[1][2]);$j[1][2]=$h;}$ga[]=($R!=""?($j[0]!=""?"CHANGE ".idf_escape($j[0]):"ADD"):" ")." ".implode($j[1]).($R!=""?$j[2]:"");}else$ga[]="DROP ".idf_escape($j[0]);}$ga=array_merge($ga,$cc);$af=($Ra!==null?" COMMENT=".q($Ra):"").($Cb?" ENGINE=".q($Cb):"").($Na?" COLLATE ".q($Na):"").($oa!=""?" AUTO_INCREMENT=$oa":"");if($Zd){$ae=array();if($Zd["partition_by"]=='RANGE'||$Zd["partition_by"]=='LIST'){foreach($Zd["partition_names"]as$w=>$W){$X=$Zd["partition_values"][$w];$ae[]="\n  PARTITION ".idf_escape($W)." VALUES ".($Zd["partition_by"]=='RANGE'?"LESS THAN":"IN").($X!=""?" ($X)":" MAXVALUE");}}$af
.="\nPARTITION BY $Zd[partition_by]($Zd[partition])";if($ae)$af
.=" (".implode(",",$ae)."\n)";elseif($Zd["partitions"])$af
.=" PARTITIONS ".(+$Zd["partitions"]);}elseif($Zd===null)$af
.="\nREMOVE PARTITIONING";if($R=="")return
queries("CREATE TABLE ".table($C)." (\n".implode(",\n",$ga)."\n)$af");if($R!=$C)$ga[]="RENAME TO ".table($C);if($af)$ga[]=ltrim($af);return($ga?queries("ALTER TABLE ".table($R)."\n".implode(",\n",$ga)):true);}function
alter_indexes($R,$ga){$Da=array();foreach($ga
as$W)$Da[]=($W[2]=="DROP"?"\nDROP INDEX ".idf_escape($W[1]):"\nADD $W[0] ".($W[0]=="PRIMARY"?"KEY ":"").($W[1]!=""?idf_escape($W[1])." ":"")."(".implode(", ",$W[2]).")");return
queries("ALTER TABLE ".table($R).implode(",",$Da));}function
truncate_tables(array$jf){return
apply_queries("TRUNCATE TABLE",$jf);}function
drop_views(array$Rf){return
queries("DROP VIEW ".implode(", ",array_map('Adminer\table',$Rf)));}function
drop_tables(array$jf){return
queries("DROP TABLE ".implode(", ",array_map('Adminer\table',$jf)));}function
move_tables(array$jf,array$Rf,$kf){$ze=array();foreach($jf
as$R)$ze[]=table($R)." TO ".idf_escape($kf).".".table($R);if(!$ze||queries("RENAME TABLE ".implode(", ",$ze))){$kb=array();foreach($Rf
as$R)$kb[table($R)]=view($R);connection()->select_db($kf);$g=idf_escape(DB);foreach($kb
as$C=>$Qf){if(!queries("CREATE VIEW $C AS ".str_replace(" $g."," ",$Qf["select"]))||!queries("DROP VIEW $g.$C"))return
false;}return
true;}return
false;}function
copy_tables(array$jf,array$Rf,$kf){queries("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");foreach($jf
as$R){$C=($kf==DB?table("copy_$R"):idf_escape($kf).".".table($R));if(($_POST["overwrite"]&&!queries("\nDROP TABLE IF EXISTS $C"))||!queries("CREATE TABLE $C LIKE ".table($R))||!queries("INSERT INTO $C SELECT * FROM ".table($R)))return
false;foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")))as$L){$_f=$L["Trigger"];if(!queries("CREATE TRIGGER ".($kf==DB?idf_escape("copy_$_f"):idf_escape($kf).".".idf_escape($_f))." $L[Timing] $L[Event] ON $C FOR EACH ROW\n$L[Statement];"))return
false;}}foreach($Rf
as$R){$C=($kf==DB?table("copy_$R"):idf_escape($kf).".".table($R));$Qf=view($R);if(($_POST["overwrite"]&&!queries("DROP VIEW IF EXISTS $C"))||!queries("CREATE VIEW $C AS $Qf[select]"))return
false;}return
true;}function
trigger($C,$R){if($C=="")return
array();$M=get_rows("SHOW TRIGGERS WHERE `Trigger` = ".q($C));return
reset($M);}function
triggers($R){$K=array();foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")))as$L)$K[$L["Trigger"]]=array($L["Timing"],$L["Event"]);return$K;}function
trigger_options(){return
array("Timing"=>array("BEFORE","AFTER"),"Event"=>array("INSERT","UPDATE","DELETE"),"Type"=>array("FOR EACH ROW"),);}function
routine($C,$T){$fa=array("bool","boolean","integer","double precision","real","dec","numeric","fixed","national char","national varchar");$Ve="(?:\\s|/\\*[\s\S]*?\\*/|(?:#|-- )[^\n]*\n?|--\r?\n)";$Db=driver()->enumLength;$Af="((".implode("|",array_merge(array_keys(driver()->types()),$fa)).")\\b(?:\\s*\\(((?:[^'\")]|$Db)++)\\))?"."\\s*(zerofill\\s*)?(unsigned(?:\\s+zerofill)?)?)(?:\\s*(?:CHARSET|CHARACTER\\s+SET)\\s*['\"]?([^'\"\\s,]+)['\"]?)?";$ce="$Ve*(".($T=="FUNCTION"?"":driver()->inout).")?\\s*(?:`((?:[^`]|``)*)`\\s*|\\b(\\S+)\\s+)$Af";$Ya=get_val("SHOW CREATE $T ".idf_escape($C),2);preg_match("~\\(((?:$ce\\s*,?)*)\\)\\s*".($T=="FUNCTION"?"RETURNS\\s+$Af\\s+":"")."(.*)~is",$Ya,$_);$k=array();preg_match_all("~$ce\\s*,?~is",$_[1],$nd,PREG_SET_ORDER);foreach($nd
as$Wd)$k[]=array("field"=>str_replace("``","`",$Wd[2]).$Wd[3],"type"=>strtolower($Wd[5]),"length"=>preg_replace_callback("~$Db~s",'Adminer\normalize_enum',$Wd[6]),"unsigned"=>strtolower(preg_replace('~\s+~',' ',trim("$Wd[8] $Wd[7]"))),"null"=>true,"full_type"=>$Wd[4],"inout"=>strtoupper($Wd[1]),"collation"=>strtolower($Wd[9]),);return
array("fields"=>$k,"comment"=>get_val("SELECT ROUTINE_COMMENT FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ".q($C)),)+($T!="FUNCTION"?array("definition"=>$_[11]):array("returns"=>array("type"=>$_[12],"length"=>$_[13],"unsigned"=>$_[15],"collation"=>$_[16]),"definition"=>$_[17],"language"=>"SQL",));}function
routines(){return
get_rows("SELECT ROUTINE_NAME AS SPECIFIC_NAME, ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()");}function
routine_languages(){return
array();}function
routine_id($C,array$L){return
idf_escape($C);}function
last_id($J){return
get_val("SELECT LAST_INSERT_ID()");}function
explain(Db$e,$I){return$e->query("EXPLAIN ".(min_version(5.1)&&!min_version(5.7)?"PARTITIONS ":"").$I);}function
found_rows(array$S,array$Z){return($Z||$S["Engine"]!="InnoDB"?null:$S["Rows"]);}function
create_sql($R,$oa,$cf){$K=get_val("SHOW CREATE TABLE ".table($R),1);if(!$oa)$K=preg_replace('~ AUTO_INCREMENT=\d+~','',$K);return$K;}function
truncate_sql($R){return"TRUNCATE ".table($R);}function
use_sql($gb){return"USE ".idf_escape($gb);}function
trigger_sql($R){$K="";foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")),null,"-- ")as$L)$K
.="\nCREATE TRIGGER ".idf_escape($L["Trigger"])." $L[Timing] $L[Event] ON ".table($L["Table"])." FOR EACH ROW\n$L[Statement];;\n";return$K;}function
show_variables(){return
get_rows("SHOW VARIABLES");}function
show_status(){return
get_rows("SHOW STATUS");}function
process_list(){return
get_rows("SHOW FULL PROCESSLIST");}function
convert_field(array$j){if(preg_match("~binary~",$j["type"]))return"HEX(".idf_escape($j["field"]).")";if($j["type"]=="bit")return"BIN(".idf_escape($j["field"])." + 0)";if(preg_match("~geometry|point|linestring|polygon~",$j["type"]))return(min_version(8)?"ST_":"")."AsWKT(".idf_escape($j["field"]).")";}function
unconvert_field(array$j,$K){if(preg_match("~binary~",$j["type"]))$K="UNHEX($K)";if($j["type"]=="bit")$K="CONVERT(b$K, UNSIGNED)";if(preg_match("~geometry|point|linestring|polygon~",$j["type"])){$ke=(min_version(8)?"ST_":"");$K=$ke."GeomFromText($K, $ke"."SRID($j[field]))";}return$K;}function
support($Tb){return
preg_match('~^(comment|columns|copy|database|drop_col|dump|indexes|kill|privileges|move_col|procedure|processlist|routine|sql|status|table|trigger|variables|view'.(min_version(5.1)?'|event':'').(min_version(8)?'|descidx':'').(min_version('8.0.16','10.2.1')?'|check':'').')$~',$Tb);}function
kill_process($W){return
queries("KILL ".number($W));}function
connection_id(){return"SELECT CONNECTION_ID()";}function
max_connections(){return
get_val("SELECT @@max_connections");}function
types(){return
array();}function
type_values($r){return"";}function
schemas(){return
array();}function
get_schema(){return"";}function
set_schema($Ee,$f=null){return
true;}}define('Adminer\JUSH',Driver::$jush);define('Adminer\SERVER',$_GET[DRIVER]);define('Adminer\DB',$_GET["db"]);define('Adminer\ME',preg_replace('~\?.*~','',relative_uri()).'?'.(sid()?SID.'&':'').(SERVER!==null?DRIVER."=".urlencode(SERVER).'&':'').($_GET["ext"]?"ext=".urlencode($_GET["ext"]).'&':'').(isset($_GET["username"])?"username=".urlencode($_GET["username"]).'&':'').(DB!=""?'db='.urlencode(DB).'&'.(isset($_GET["ns"])?"ns=".urlencode($_GET["ns"])."&":""):''));function
page_header($qf,$i="",$Ba=array(),$rf=""){page_headers();if(is_ajax()&&$i){page_messages($i);exit;}if(!ob_get_level())ob_start('ob_gzhandler',4096);$sf=$qf.($rf!=""?": $rf":"");$tf=strip_tags($sf.(SERVER!=""&&SERVER!="localhost"?h(" - ".SERVER):"")." - ".adminer()->name());echo'<!DOCTYPE html>
<html lang="en" dir="ltr">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>',$tf,'</title>
<link rel="stylesheet" href="',h(preg_replace("~\\?.*~","",ME)."?file=default.css&version=5.3.0"),'">
';$cb=adminer()->css();if(is_int(key($cb)))$cb=array_fill_keys($cb,'light');$uc=in_array('light',$cb)||in_array('',$cb);$rc=in_array('dark',$cb)||in_array('',$cb);$eb=($uc?($rc?null:false):($rc?:null));$td=" media='(prefers-color-scheme: dark)'";if($eb!==false)echo"<link rel='stylesheet'".($eb?"":$td)." href='".h(preg_replace("~\\?.*~","",ME)."?file=dark.css&version=5.3.0")."'>\n";echo"<meta name='color-scheme' content='".($eb===null?"light dark":($eb?"dark":"light"))."'>\n",script_src(preg_replace("~\\?.*~","",ME)."?file=functions.js&version=5.3.0");if(adminer()->head($eb))echo"<link rel='icon' href='data:image/gif;base64,R0lGODlhEAAQAJEAAAQCBPz+/PwCBAROZCH5BAEAAAAALAAAAAAQABAAAAI2hI+pGO1rmghihiUdvUBnZ3XBQA7f05mOak1RWXrNq5nQWHMKvuoJ37BhVEEfYxQzHjWQ5qIAADs='>\n","<link rel='apple-touch-icon' href='".h(preg_replace("~\\?.*~","",ME)."?file=logo.png&version=5.3.0")."'>\n";foreach($cb
as$Lf=>$xd){$b=($xd=='dark'&&!$eb?$td:($xd=='light'&&$rc?" media='(prefers-color-scheme: light)'":""));echo"<link rel='stylesheet'$b href='".h($Lf)."'>\n";}echo"\n<body class='".'ltr'." nojs";adminer()->bodyClass();echo"'>\n";$l=get_temp_dir()."/adminer.version";if(!$_COOKIE["adminer_version"]&&function_exists('openssl_verify')&&file_exists($l)&&filemtime($l)+86400>time()){$Pf=unserialize(file_get_contents($l));$pe="-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwqWOVuF5uw7/+Z70djoK
RlHIZFZPO0uYRezq90+7Amk+FDNd7KkL5eDve+vHRJBLAszF/7XKXe11xwliIsFs
DFWQlsABVZB3oisKCBEuI71J4kPH8dKGEWR9jDHFw3cWmoH3PmqImX6FISWbG3B8
h7FIx3jEaw5ckVPVTeo5JRm/1DZzJxjyDenXvBQ/6o9DgZKeNDgxwKzH+sw9/YCO
jHnq1cFpOIISzARlrHMa/43YfeNRAm/tsBXjSxembBPo7aQZLAWHmaj5+K19H10B
nCpz9Y++cipkVEiKRGih4ZEvjoFysEOdRLj6WiD/uUNky4xGeA6LaJqh5XpkFkcQ
fQIDAQAB
-----END PUBLIC KEY-----
";if(openssl_verify($Pf["version"],base64_decode($Pf["signature"]),$pe)==1)$_COOKIE["adminer_version"]=$Pf["version"];}echo
script("mixin(document.body, {onkeydown: bodyKeydown, onclick: bodyClick".(isset($_COOKIE["adminer_version"])?"":", onload: partial(verifyVersion, '".VERSION."', '".js_escape(ME)."', '".get_token()."')")."});
document.body.classList.replace('nojs', 'js');
const offlineMessage = '".js_escape('You are offline.')."';
const thousandsSeparator = '".js_escape(',')."';"),"<div id='help' class='jush-".JUSH." jsonly hidden'></div>\n",script("mixin(qs('#help'), {onmouseover: () => { helpOpen = 1; }, onmouseout: helpMouseout});"),"<div id='content'>\n","<span id='menuopen' class='jsonly'>".icon("move","","menu","")."</span>".script("qs('#menuopen').onclick = event => { qs('#foot').classList.toggle('foot'); event.stopPropagation(); }");if($Ba!==null){$y=substr(preg_replace('~\b(username|db|ns)=[^&]*&~','',ME),0,-1);echo'<p id="breadcrumb"><a href="'.h($y?:".").'">'.get_driver(DRIVER).'</a> Â» ';$y=substr(preg_replace('~\b(db|ns)=[^&]*&~','',ME),0,-1);$O=adminer()->serverName(SERVER);$O=($O!=""?$O:'Server');if($Ba===false)echo"$O\n";else{echo"<a href='".h($y)."' accesskey='1' title='Alt+Shift+1'>$O</a> Â» ";if($_GET["ns"]!=""||(DB!=""&&is_array($Ba)))echo'<a href="'.h($y."&db=".urlencode(DB).(support("scheme")?"&ns=":"")).'">'.h(DB).'</a> Â» ';if(is_array($Ba)){if($_GET["ns"]!="")echo'<a href="'.h(substr(ME,0,-1)).'">'.h($_GET["ns"]).'</a> Â» ';foreach($Ba
as$w=>$W){$mb=(is_array($W)?$W[1]:h($W));if($mb!="")echo"<a href='".h(ME."$w=").urlencode(is_array($W)?$W[0]:$W)."'>$mb</a> Â» ";}}echo"$qf\n";}}echo"<h2>$sf</h2>\n","<div id='ajaxstatus' class='jsonly hidden'></div>\n";restart_session();page_messages($i);$hb=&get_session("dbs");if(DB!=""&&$hb&&!in_array(DB,$hb,true))$hb=null;stop_session();define('Adminer\PAGE_HEADER',1);}function
page_headers(){header("Content-Type: text/html; charset=utf-8");header("Cache-Control: no-cache");header("X-Frame-Options: deny");header("X-XSS-Protection: 0");header("X-Content-Type-Options: nosniff");header("Referrer-Policy: origin-when-cross-origin");foreach(adminer()->csp(csp())as$bb){$vc=array();foreach($bb
as$w=>$W)$vc[]="$w $W";header("Content-Security-Policy: ".implode("; ",$vc));}adminer()->headers();}function
csp(){return
array(array("script-src"=>"'self' 'unsafe-inline' 'nonce-".get_nonce()."' 'strict-dynamic'","connect-src"=>"'self'","frame-src"=>"https://www.adminer.org","object-src"=>"'none'","base-uri"=>"'none'","form-action"=>"'self'",),);}function
get_nonce(){static$Ed;if(!$Ed)$Ed=base64_encode(rand_string());return$Ed;}function
page_messages($i){$Kf=preg_replace('~^[^?]*~','',$_SERVER["REQUEST_URI"]);$ud=idx($_SESSION["messages"],$Kf);if($ud){echo"<div class='message'>".implode("</div>\n<div class='message'>",$ud)."</div>".script("messagesPrint();");unset($_SESSION["messages"][$Kf]);}if($i)echo"<div class='error'>$i</div>\n";if(adminer()->error)echo"<div class='error'>".adminer()->error."</div>\n";}function
page_footer($wd=""){echo"</div>\n\n<div id='foot' class='foot'>\n<div id='menu'>\n";adminer()->navigation($wd);echo"</div>\n";if($wd!="auth")echo'<form action="" method="post">
<p class="logout">
<span>',h($_GET["username"])."\n",'</span>
<input type="submit" name="logout" value="Logout" id="logout">
',input_token(),'</form>
';echo"</div>\n\n",script("setupSubmitHighlight(document);");}function
int32($B){while($B>=2147483648)$B-=4294967296;while($B<=-2147483649)$B+=4294967296;return(int)$B;}function
long2str(array$V,$Tf){$De='';foreach($V
as$W)$De
.=pack('V',$W);if($Tf)return
substr($De,0,end($V));return$De;}function
str2long($De,$Tf){$V=array_values(unpack('V*',str_pad($De,4*ceil(strlen($De)/4),"\0")));if($Tf)$V[]=strlen($De);return$V;}function
xxtea_mx($Yf,$Xf,$ff,$Wc){return
int32((($Yf>>5&0x7FFFFFF)^$Xf<<2)+(($Xf>>3&0x1FFFFFFF)^$Yf<<4))^int32(($ff^$Xf)+($Wc^$Yf));}function
encrypt_string($bf,$w){if($bf=="")return"";$w=array_values(unpack("V*",pack("H*",md5($w))));$V=str2long($bf,true);$B=count($V)-1;$Yf=$V[$B];$Xf=$V[0];$qe=floor(6+52/($B+1));$ff=0;while($qe-->0){$ff=int32($ff+0x9E3779B9);$vb=$ff>>2&3;for($Ud=0;$Ud<$B;$Ud++){$Xf=$V[$Ud+1];$_d=xxtea_mx($Yf,$Xf,$ff,$w[$Ud&3^$vb]);$Yf=int32($V[$Ud]+$_d);$V[$Ud]=$Yf;}$Xf=$V[0];$_d=xxtea_mx($Yf,$Xf,$ff,$w[$Ud&3^$vb]);$Yf=int32($V[$B]+$_d);$V[$B]=$Yf;}return
long2str($V,false);}function
decrypt_string($bf,$w){if($bf=="")return"";if(!$w)return
false;$w=array_values(unpack("V*",pack("H*",md5($w))));$V=str2long($bf,false);$B=count($V)-1;$Yf=$V[$B];$Xf=$V[0];$qe=floor(6+52/($B+1));$ff=int32($qe*0x9E3779B9);while($ff){$vb=$ff>>2&3;for($Ud=$B;$Ud>0;$Ud--){$Yf=$V[$Ud-1];$_d=xxtea_mx($Yf,$Xf,$ff,$w[$Ud&3^$vb]);$Xf=int32($V[$Ud]-$_d);$V[$Ud]=$Xf;}$Yf=$V[$B];$_d=xxtea_mx($Yf,$Xf,$ff,$w[$Ud&3^$vb]);$Xf=int32($V[0]-$_d);$V[0]=$Xf;$ff=int32($ff-0x9E3779B9);}return
long2str($V,true);}$H=array();if($_COOKIE["adminer_permanent"]){foreach(explode(" ",$_COOKIE["adminer_permanent"])as$W){list($w)=explode(":",$W);$H[$w]=$W;}}function
add_invalid_login(){$va=get_temp_dir()."/adminer.invalid";foreach(glob("$va*")?:array($va)as$l){$n=file_open_lock($l);if($n)break;}if(!$n)$n=file_open_lock("$va-".rand_string());if(!$n)return;$Qc=unserialize(stream_get_contents($n));$of=time();if($Qc){foreach($Qc
as$Rc=>$W){if($W[0]<$of)unset($Qc[$Rc]);}}$Pc=&$Qc[adminer()->bruteForceKey()];if(!$Pc)$Pc=array($of+30*60,0);$Pc[1]++;file_write_unlock($n,serialize($Qc));}function
check_invalid_login(array&$H){$Qc=array();foreach(glob(get_temp_dir()."/adminer.invalid*")as$l){$n=file_open_lock($l);if($n){$Qc=unserialize(stream_get_contents($n));file_unlock($n);break;}}$Pc=idx($Qc,adminer()->bruteForceKey(),array());$Dd=($Pc[1]>29?$Pc[0]-time():0);if($Dd>0)auth_error(lang_format(array('Too many unsuccessful logins, try again in %d minute.','Too many unsuccessful logins, try again in %d minutes.'),ceil($Dd/60)),$H);}$na=$_POST["auth"];if($na){session_regenerate_id();$Y=$na["driver"];$O=$na["server"];$U=$na["username"];$G=(string)$na["password"];$g=$na["db"];set_password($Y,$O,$U,$G);$_SESSION["db"][$Y][$O][$U][$g]=true;if($na["permanent"]){$w=implode("-",array_map('base64_encode',array($Y,$O,$U,$g)));$ne=adminer()->permanentLogin(true);$H[$w]="$w:".base64_encode($ne?encrypt_string($G,$ne):"");cookie("adminer_permanent",implode(" ",$H));}if(count($_POST)==1||DRIVER!=$Y||SERVER!=$O||$_GET["username"]!==$U||DB!=$g)redirect(auth_url($Y,$O,$U,$g));}elseif($_POST["logout"]&&(!$_SESSION["token"]||verify_token())){foreach(array("pwds","db","dbs","queries")as$w)set_session($w,null);unset_permanent($H);redirect(substr(preg_replace('~\b(username|db|ns)=[^&]*&~','',ME),0,-1),'Logout successful.'.' '.'Thanks for using Adminer, consider <a href="https://www.adminer.org/en/donation/">donating</a>.');}elseif($H&&!$_SESSION["pwds"]){session_regenerate_id();$ne=adminer()->permanentLogin();foreach($H
as$w=>$W){list(,$Ia)=explode(":",$W);list($Y,$O,$U,$g)=array_map('base64_decode',explode("-",$w));set_password($Y,$O,$U,decrypt_string(base64_decode($Ia),$ne));$_SESSION["db"][$Y][$O][$U][$g]=true;}}function
unset_permanent(array&$H){foreach($H
as$w=>$W){list($Y,$O,$U,$g)=array_map('base64_decode',explode("-",$w));if($Y==DRIVER&&$O==SERVER&&$U==$_GET["username"]&&$g==DB)unset($H[$w]);}cookie("adminer_permanent",implode(" ",$H));}function
auth_error($i,array&$H){$Oe=session_name();if(isset($_GET["username"])){header("HTTP/1.1 403 Forbidden");if(($_COOKIE[$Oe]||$_GET[$Oe])&&!$_SESSION["token"])$i='Session expired, please login again.';else{restart_session();add_invalid_login();$G=get_password();if($G!==null){if($G===false)$i
.=($i?'<br>':'').sprintf('Master password expired. <a href="https://www.adminer.org/en/extension/"%s>Implement</a> %s method to make it permanent.',target_blank(),'<code>permanentLogin()</code>');set_password(DRIVER,SERVER,$_GET["username"],null);}unset_permanent($H);}}if(!$_COOKIE[$Oe]&&$_GET[$Oe]&&ini_bool("session.use_only_cookies"))$i='Session support must be enabled.';$Xd=session_get_cookie_params();cookie("adminer_key",($_COOKIE["adminer_key"]?:rand_string()),$Xd["lifetime"]);if(!$_SESSION["token"])$_SESSION["token"]=rand(1,1e6);page_header('Login',$i,null);echo"<form action='' method='post'>\n","<div>";if(hidden_fields($_POST,array("auth")))echo"<p class='message'>".'The action will be performed after successful login with the same credentials.'."\n";echo"</div>\n";adminer()->loginForm();echo"</form>\n";page_footer("auth");exit;}if(isset($_GET["username"])&&!class_exists('Adminer\Db')){unset($_SESSION["pwds"][DRIVER]);unset_permanent($H);page_header('No extension',sprintf('None of the supported PHP extensions (%s) are available.',implode(", ",Driver::$extensions)),false);page_footer("auth");exit;}$e='';if(isset($_GET["username"])&&is_string(get_password())){list($Ac,$ge)=explode(":",SERVER,2);if(preg_match('~^\s*([-+]?\d+)~',$ge,$_)&&($_[1]<1024||$_[1]>65535))auth_error('Connecting to privileged ports is not allowed.',$H);check_invalid_login($H);$ab=adminer()->credentials();$e=Driver::connect($ab[0],$ab[1],$ab[2]);if(is_object($e)){Db::$instance=$e;Driver::$instance=new
Driver($e);if($e->flavor)save_settings(array("vendor-".DRIVER."-".SERVER=>get_driver(DRIVER)));}}$id=null;if(!is_object($e)||($id=adminer()->login($_GET["username"],get_password()))!==true){$i=(is_string($e)?nl_br(h($e)):(is_string($id)?$id:'Invalid credentials.')).(preg_match('~^ | $~',get_password())?'<br>'.'There is a space in the input password which might be the cause.':'');auth_error($i,$H);}if($_POST["logout"]&&$_SESSION["token"]&&!verify_token()){page_header('Logout','Invalid CSRF token. Send the form again.');page_footer("db");exit;}if(!$_SESSION["token"])$_SESSION["token"]=rand(1,1e6);stop_session(true);if($na&&$_POST["token"])$_POST["token"]=get_token();$i='';if($_POST){if(!verify_token()){$Kc="max_input_vars";$rd=ini_get($Kc);if(extension_loaded("suhosin")){foreach(array("suhosin.request.max_vars","suhosin.post.max_vars")as$w){$W=ini_get($w);if($W&&(!$rd||$W<$rd)){$Kc=$w;$rd=$W;}}}$i=(!$_POST["token"]&&$rd?sprintf('Maximum number of allowed fields exceeded. Please increase %s.',"'$Kc'"):'Invalid CSRF token. Send the form again.'.' '.'If you did not send this request from Adminer then close this page.');}}elseif($_SERVER["REQUEST_METHOD"]=="POST"){$i=sprintf('Too big POST data. Reduce the data or increase the %s configuration directive.',"'post_max_size'");if(isset($_GET["sql"]))$i
.=' '.'You can upload a big SQL file via FTP and import it from server.';}function
doc_link(array$be,$lf=""){return"";}function
email_header($vc){return"=?UTF-8?B?".base64_encode($vc)."?=";}function
send_mail($zb,$df,$A,$ic="",array$Wb=array()){$Gb=PHP_EOL;$A=str_replace("\n",$Gb,wordwrap(str_replace("\r","","$A\n")));$Aa=uniqid("boundary");$ma="";foreach((array)$Wb["error"]as$w=>$W){if(!$W)$ma
.="--$Aa$Gb"."Content-Type: ".str_replace("\n","",$Wb["type"][$w]).$Gb."Content-Disposition: attachment; filename=\"".preg_replace('~["\n]~','',$Wb["name"][$w])."\"$Gb"."Content-Transfer-Encoding: base64$Gb$Gb".chunk_split(base64_encode(file_get_contents($Wb["tmp_name"][$w])),76,$Gb).$Gb;}$xa="";$wc="Content-Type: text/plain; charset=utf-8$Gb"."Content-Transfer-Encoding: 8bit";if($ma){$ma
.="--$Aa--$Gb";$xa="--$Aa$Gb$wc$Gb$Gb";$wc="Content-Type: multipart/mixed; boundary=\"$Aa\"";}$wc
.=$Gb."MIME-Version: 1.0$Gb"."X-Mailer: Adminer Editor".($ic?$Gb."From: ".str_replace("\n","",$ic):"");return
mail($zb,email_header($df),$xa.$A.$ma,$wc);}function
like_bool(array$j){return
preg_match("~bool|(tinyint|bit)\\(1\\)~",$j["full_type"]);}connection()->select_db(adminer()->database());add_driver(DRIVER,'Login');if(isset($_GET["select"])&&($_POST["edit"]||$_POST["clone"])&&!$_POST["save"])$_GET["edit"]=$_GET["select"];if(isset($_GET["download"])){$a=$_GET["download"];$k=fields($a);header("Content-Type: application/octet-stream");header("Content-Disposition: attachment; filename=".friendly_url("$a-".implode("_",$_GET["where"])).".".friendly_url($_GET["field"]));$N=array(idf_escape($_GET["field"]));$J=driver()->select($a,$N,array(where($_GET,$k)),$N);$L=($J?$J->fetch_row():array());echo
driver()->value($L[0],$k[$_GET["field"]]);exit;}elseif(isset($_GET["edit"])){$a=$_GET["edit"];$k=fields($a);$Z=(isset($_GET["select"])?($_POST["check"]&&count($_POST["check"])==1?where_check($_POST["check"][0],$k):""):where($_GET,$k));$Jf=(isset($_GET["select"])?$_POST["edit"]:$Z);foreach($k
as$C=>$j){if(!isset($j["privileges"][$Jf?"update":"insert"])||adminer()->fieldName($j)==""||$j["generated"])unset($k[$C]);}if($_POST&&!$i&&!isset($_GET["select"])){$z=$_POST["referer"];if($_POST["insert"])$z=($Jf?null:$_SERVER["REQUEST_URI"]);elseif(!preg_match('~^.+&select=.+$~',$z))$z=ME."select=".urlencode($a);$u=indexes($a);$Ef=unique_array($_GET["where"],$u);$te="\nWHERE $Z";if(isset($_POST["delete"]))queries_redirect($z,'Item has been deleted.',driver()->delete($a,$te,$Ef?0:1));else{$P=array();foreach($k
as$C=>$j){$W=process_input($j);if($W!==false&&$W!==null)$P[idf_escape($C)]=$W;}if($Jf){if(!$P)redirect($z);queries_redirect($z,'Item has been updated.',driver()->update($a,$P,$te,$Ef?0:1));if(is_ajax()){page_headers();page_messages($i);exit;}}else{$J=driver()->insert($a,$P);$dd=($J?last_id($J):0);queries_redirect($z,sprintf('Item%s has been inserted.',($dd?" $dd":"")),$J);}}}$L=null;if($_POST["save"])$L=(array)$_POST["fields"];elseif($Z){$N=array();foreach($k
as$C=>$j){if(isset($j["privileges"]["select"])){$ka=($_POST["clone"]&&$j["auto_increment"]?"''":convert_field($j));$N[]=($ka?"$ka AS ":"").idf_escape($C);}}$L=array();if(!support("table"))$N=array("*");if($N){$J=driver()->select($a,$N,array($Z),$N,array(),(isset($_GET["select"])?2:1));if(!$J)$i=error();else{$L=$J->fetch_assoc();if(!$L)$L=false;}if(isset($_GET["select"])&&(!$L||$J->fetch_assoc()))$L=null;}}if(!support("table")&&!$k){if(!$Z){$J=driver()->select($a,array("*"),array(),array("*"));$L=($J?$J->fetch_assoc():false);if(!$L)$L=array(driver()->primary=>"");}if($L){foreach($L
as$w=>$W){if(!$Z)$L[$w]=null;$k[$w]=array("field"=>$w,"null"=>($w!=driver()->primary),"auto_increment"=>($w==driver()->primary));}}}edit_form($a,$k,$L,$Jf,$i);}elseif(isset($_GET["select"])){$a=$_GET["select"];$S=table_status1($a);$u=indexes($a);$k=fields($a);$gc=column_foreign_keys($a);$Id=$S["Oid"];$ca=get_settings("adminer_import");$Ce=array();$d=array();$Ge=array();$Qd=array();$mf="";foreach($k
as$w=>$j){$C=adminer()->fieldName($j);$Ad=html_entity_decode(strip_tags($C),ENT_QUOTES);if(isset($j["privileges"]["select"])&&$C!=""){$d[$w]=$Ad;if(is_shortable($j))$mf=adminer()->selectLengthProcess();}if(isset($j["privileges"]["where"])&&$C!="")$Ge[$w]=$Ad;if(isset($j["privileges"]["order"])&&$C!="")$Qd[$w]=$Ad;$Ce+=$j["privileges"];}list($N,$p)=adminer()->selectColumnsProcess($d,$u);$N=array_unique($N);$p=array_unique($p);$v=count($p)<count($N);$Z=adminer()->selectSearchProcess($k,$u);$E=adminer()->selectOrderProcess($k,$u);$x=adminer()->selectLimitProcess();if($_GET["val"]&&is_ajax()){header("Content-Type: text/plain; charset=utf-8");foreach($_GET["val"]as$Ff=>$L){$ka=convert_field($k[key($L)]);$N=array($ka?:idf_escape(key($L)));$Z[]=where_check($Ff,$k);$K=driver()->select($a,$N,$Z,$N);if($K)echo
first($K->fetch_row());}exit;}$le=$Hf=array();foreach($u
as$t){if($t["type"]=="PRIMARY"){$le=array_flip($t["columns"]);$Hf=($N?$le:array());foreach($Hf
as$w=>$W){if(in_array(idf_escape($w),$N))unset($Hf[$w]);}break;}}if($Id&&!$le){$le=$Hf=array($Id=>0);$u[]=array("type"=>"PRIMARY","columns"=>array($Id));}if($_POST&&!$i){$Vf=$Z;if(!$_POST["all"]&&is_array($_POST["check"])){$Ha=array();foreach($_POST["check"]as$Fa)$Ha[]=where_check($Fa,$k);$Vf[]="((".implode(") OR (",$Ha)."))";}$Vf=($Vf?"\nWHERE ".implode(" AND ",$Vf):"");if($_POST["export"]){save_settings(array("output"=>$_POST["output"],"format"=>$_POST["format"]),"adminer_import");dump_headers($a);adminer()->dumpTable($a,"");$ic=($N?implode(", ",$N):"*").convert_fields($d,$k,$N)."\nFROM ".table($a);$oc=($p&&$v?"\nGROUP BY ".implode(", ",$p):"").($E?"\nORDER BY ".implode(", ",$E):"");$I="SELECT $ic$Vf$oc";if(is_array($_POST["check"])&&!$le){$Df=array();foreach($_POST["check"]as$W)$Df[]="(SELECT".limit($ic,"\nWHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check($W,$k).$oc,1).")";$I=implode(" UNION ALL ",$Df);}adminer()->dumpData($a,"table",$I);adminer()->dumpFooter();exit;}if(!adminer()->selectEmailProcess($Z,$gc)){if($_POST["save"]||$_POST["delete"]){$J=true;$da=0;$P=array();if(!$_POST["delete"]){foreach($_POST["fields"]as$C=>$W){$W=process_input($k[$C]);if($W!==null&&($_POST["clone"]||$W!==false))$P[idf_escape($C)]=($W!==false?$W:idf_escape($C));}}if($_POST["delete"]||$P){$I=($_POST["clone"]?"INTO ".table($a)." (".implode(", ",array_keys($P)).")\nSELECT ".implode(", ",$P)."\nFROM ".table($a):"");if($_POST["all"]||($le&&is_array($_POST["check"]))||$v){$J=($_POST["delete"]?driver()->delete($a,$Vf):($_POST["clone"]?queries("INSERT $I$Vf".driver()->insertReturning($a)):driver()->update($a,$P,$Vf)));$da=connection()->affected_rows;if(is_object($J))$da+=$J->num_rows;}else{foreach((array)$_POST["check"]as$W){$Uf="\nWHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check($W,$k);$J=($_POST["delete"]?driver()->delete($a,$Uf,1):($_POST["clone"]?queries("INSERT".limit1($a,$I,$Uf)):driver()->update($a,$P,$Uf,1)));if(!$J)break;$da+=connection()->affected_rows;}}}$A=lang_format(array('%d item has been affected.','%d items have been affected.'),$da);if($_POST["clone"]&&$J&&$da==1){$dd=last_id($J);if($dd)$A=sprintf('Item%s has been inserted.'," $dd");}queries_redirect(remove_from_uri($_POST["all"]&&$_POST["delete"]?"page":""),$A,$J);if(!$_POST["delete"]){$ie=(array)$_POST["fields"];edit_form($a,array_intersect_key($k,$ie),$ie,!$_POST["clone"],$i);page_footer();exit;}}elseif(!$_POST["import"]){if(!$_POST["val"])$i='Ctrl+click on a value to modify it.';else{$J=true;$da=0;foreach($_POST["val"]as$Ff=>$L){$P=array();foreach($L
as$w=>$W){$w=bracket_escape($w,true);$P[idf_escape($w)]=(preg_match('~char|text~',$k[$w]["type"])||$W!=""?adminer()->processInput($k[$w],$W):"NULL");}$J=driver()->update($a,$P," WHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check($Ff,$k),($v||$le?0:1)," ");if(!$J)break;$da+=connection()->affected_rows;}queries_redirect(remove_from_uri(),lang_format(array('%d item has been affected.','%d items have been affected.'),$da),$J);}}elseif(!is_string($Vb=get_file("csv_file",true)))$i=upload_error($Vb);elseif(!preg_match('~~u',$Vb))$i='File must be in UTF-8 encoding.';else{save_settings(array("output"=>$ca["output"],"format"=>$_POST["separator"]),"adminer_import");$J=true;$Pa=array_keys($k);preg_match_all('~(?>"[^"]*"|[^"\r\n]+)+~',$Vb,$nd);$da=count($nd[0]);driver()->begin();$Le=($_POST["separator"]=="csv"?",":($_POST["separator"]=="tsv"?"\t":";"));$M=array();foreach($nd[0]as$w=>$W){preg_match_all("~((?>\"[^\"]*\")+|[^$Le]*)$Le~",$W.$Le,$od);if(!$w&&!array_diff($od[1],$Pa)){$Pa=$od[1];$da--;}else{$P=array();foreach($od[1]as$q=>$Ma)$P[idf_escape($Pa[$q])]=($Ma==""&&$k[$Pa[$q]]["null"]?"NULL":q(preg_match('~^".*"$~s',$Ma)?str_replace('""','"',substr($Ma,1,-1)):$Ma));$M[]=$P;}}$J=(!$M||driver()->insertUpdate($a,$M,$le));if($J)driver()->commit();queries_redirect(remove_from_uri("page"),lang_format(array('%d row has been imported.','%d rows have been imported.'),$da),$J);driver()->rollback();}}}$if=adminer()->tableName($S);if(is_ajax()){page_headers();ob_start();}else
page_header('Select'.": $if",$i);$P=null;if(isset($Ce["insert"])||!support("table")){$Xd=array();foreach((array)$_GET["where"]as$W){if(isset($gc[$W["col"]])&&count($gc[$W["col"]])==1&&($W["op"]=="="||(!$W["op"]&&(is_array($W["val"])||!preg_match('~[_%]~',$W["val"])))))$Xd["set"."[".bracket_escape($W["col"])."]"]=$W["val"];}$P=$Xd?"&".http_build_query($Xd):"";}adminer()->selectLinks($S,$P);if(!$d&&support("table"))echo"<p class='error'>".'Unable to select the table'.($k?".":": ".error())."\n";else{echo"<form action='' id='form'>\n","<div style='display: none;'>";hidden_fields_get();echo(DB!=""?input_hidden("db",DB).(isset($_GET["ns"])?input_hidden("ns",$_GET["ns"]):""):""),input_hidden("select",$a),"</div>\n";adminer()->selectColumnsPrint($N,$d);adminer()->selectSearchPrint($Z,$Ge,$u);adminer()->selectOrderPrint($E,$Qd,$u);adminer()->selectLimitPrint($x);adminer()->selectLengthPrint($mf);adminer()->selectActionPrint($u);echo"</form>\n";$F=$_GET["page"];$m=null;if($F=="last"){$m=get_val(count_rows($a,$Z,$v,$p));$F=floor(max(0,intval($m)-1)/$x);}$He=$N;$nc=$p;if(!$He){$He[]="*";$Wa=convert_fields($d,$k,$N);if($Wa)$He[]=substr($Wa,2);}foreach($N
as$w=>$W){$j=$k[idf_unescape($W)];if($j&&($ka=convert_field($j)))$He[$w]="$ka AS $W";}if(!$v&&$Hf){foreach($Hf
as$w=>$W){$He[]=idf_escape($w);if($nc)$nc[]=idf_escape($w);}}$J=driver()->select($a,$He,$Z,$nc,$E,$x,$F,true);if(!$J)echo"<p class='error'>".error()."\n";else{if(JUSH=="mssql"&&$F)$J->seek($x*$F);$Ab=array();echo"<form action='' method='post' enctype='multipart/form-data'>\n";$M=array();while($L=$J->fetch_assoc()){if($F&&JUSH=="oracle")unset($L["RNUM"]);$M[]=$L;}if($_GET["page"]!="last"&&$x&&$p&&$v&&JUSH=="sql")$m=get_val(" SELECT FOUND_ROWS()");if(!$M)echo"<p class='message'>".'No rows.'."\n";else{$ua=adminer()->backwardKeys($a,$if);echo"<div class='scrollable'>","<table id='table' class='nowrap checkable odds'>",script("mixin(qs('#table'), {onclick: tableClick, ondblclick: partialArg(tableClick, true), onkeydown: editingKeydown});"),"<thead><tr>".(!$p&&$N?"":"<td><input type='checkbox' id='all-page' class='jsonly'>".script("qs('#all-page').onclick = partial(formCheck, /check/);","")." <a href='".h($_GET["modify"]?remove_from_uri("modify"):$_SERVER["REQUEST_URI"]."&modify=1")."'>".'Modify'."</a>");$Bd=array();$kc=array();reset($N);$ve=1;foreach($M[0]as$w=>$W){if(!isset($Hf[$w])){$W=idx($_GET["columns"],key($N))?:array();$j=$k[$N?($W?$W["col"]:current($N)):$w];$C=($j?adminer()->fieldName($j,$ve):($W["fun"]?"*":h($w)));if($C!=""){$ve++;$Bd[$w]=$C;$c=idf_escape($w);$Bc=remove_from_uri('(order|desc)[^=]*|page').'&order%5B0%5D='.urlencode($w);$mb="&desc%5B0%5D=1";echo"<th id='th[".h(bracket_escape($w))."]'>".script("mixin(qsl('th'), {onmouseover: partial(columnMouse), onmouseout: partial(columnMouse, ' hidden')});","");$jc=apply_sql_function($W["fun"],$C);$Te=isset($j["privileges"]["order"])||$jc;echo($Te?"<a href='".h($Bc.($E[0]==$c||$E[0]==$w||(!$E&&$v&&$p[0]==$c)?$mb:''))."'>$jc</a>":$jc),"<span class='column hidden'>";if($Te)echo"<a href='".h($Bc.$mb)."' title='".'descending'."' class='text'> â†“</a>";if(!$W["fun"]&&isset($j["privileges"]["where"]))echo'<a href="#fieldset-search" title="'.'Search'.'" class="text jsonly"> =</a>',script("qsl('a').onclick = partial(selectSearch, '".js_escape($w)."');");echo"</span>";}$kc[$w]=$W["fun"];next($N);}}$gd=array();if($_GET["modify"]){foreach($M
as$L){foreach($L
as$w=>$W)$gd[$w]=max($gd[$w],min(40,strlen(utf8_decode($W))));}}echo($ua?"<th>".'Relations':"")."</thead>\n";if(is_ajax())ob_end_clean();foreach(adminer()->rowDescriptions($M,$gc)as$B=>$L){$Ef=unique_array($M[$B],$u);if(!$Ef){$Ef=array();reset($N);foreach($M[$B]as$w=>$W){if(!preg_match('~^(COUNT|AVG|GROUP_CONCAT|MAX|MIN|SUM)\(~',current($N)))$Ef[$w]=$W;next($N);}}$Ff="";foreach($Ef
as$w=>$W){$j=(array)$k[$w];if((JUSH=="sql"||JUSH=="pgsql")&&preg_match('~char|text|enum|set~',$j["type"])&&strlen($W)>64){$w=(strpos($w,'(')?$w:idf_escape($w));$w="MD5(".(JUSH!='sql'||preg_match("~^utf8~",$j["collation"])?$w:"CONVERT($w USING ".charset(connection()).")").")";$W=md5($W);}$Ff
.="&".($W!==null?urlencode("where[".bracket_escape($w)."]")."=".urlencode($W===false?"f":$W):"null%5B%5D=".urlencode($w));}echo"<tr>".(!$p&&$N?"":"<td>".checkbox("check[]",substr($Ff,1),in_array(substr($Ff,1),(array)$_POST["check"])).($v||information_schema(DB)?"":" <a href='".h(ME."edit=".urlencode($a).$Ff)."' class='edit'>".'edit'."</a>"));reset($N);foreach($L
as$w=>$W){if(isset($Bd[$w])){$c=current($N);$j=(array)$k[$w];$W=driver()->value($W,$j);if($W!=""&&(!isset($Ab[$w])||$Ab[$w]!=""))$Ab[$w]=(is_mail($W)?$Bd[$w]:"");$y="";if(preg_match('~blob|bytea|raw|file~',$j["type"])&&$W!="")$y=ME.'download='.urlencode($a).'&field='.urlencode($w).$Ff;if(!$y&&$W!==null){foreach((array)$gc[$w]as$fc){if(count($gc[$w])==1||end($fc["source"])==$w){$y="";foreach($fc["source"]as$q=>$Ue)$y
.=where_link($q,$fc["target"][$q],$M[$B][$Ue]);$y=($fc["db"]!=""?preg_replace('~([?&]db=)[^&]+~','\1'.urlencode($fc["db"]),ME):ME).'select='.urlencode($fc["table"]).$y;if($fc["ns"])$y=preg_replace('~([?&]ns=)[^&]+~','\1'.urlencode($fc["ns"]),$y);if(count($fc["source"])==1)break;}}}if($c=="COUNT(*)"){$y=ME."select=".urlencode($a);$q=0;foreach((array)$_GET["where"]as$V){if(!array_key_exists($V["col"],$Ef))$y
.=where_link($q++,$V["col"],$V["val"],$V["op"]);}foreach($Ef
as$Wc=>$V)$y
.=where_link($q++,$Wc,$V);}$Cc=select_value($W,$y,$j,$mf);$r=h("val[$Ff][".bracket_escape($w)."]");$je=idx(idx($_POST["val"],$Ff),bracket_escape($w));$xb=!is_array($L[$w])&&is_utf8($Cc)&&$M[$B][$w]==$L[$w]&&!$kc[$w]&&!$j["generated"];$T=(preg_match('~^(AVG|MIN|MAX)\((.+)\)~',$c,$_)?$k[idf_unescape($_[2])]["type"]:$j["type"]);$lf=preg_match('~text|json|lob~',$T);$Sc=preg_match(number_type(),$T)||preg_match('~^(CHAR_LENGTH|ROUND|FLOOR|CEIL|TIME_TO_SEC|COUNT|SUM)\(~',$c);echo"<td id='$r'".($Sc&&($W===null||is_numeric(strip_tags($Cc))||$T=="money")?" class='number'":"");if(($_GET["modify"]&&$xb&&$W!==null)||$je!==null){$qc=h($je!==null?$je:$L[$w]);echo">".($lf?"<textarea name='$r' cols='30' rows='".(substr_count($L[$w],"\n")+1)."'>$qc</textarea>":"<input name='$r' value='$qc' size='$gd[$w]'>");}else{$jd=strpos($Cc,"<i>â€¦</i>");echo" data-text='".($jd?2:($lf?1:0))."'".($xb?"":" data-warning='".h('Use edit link to modify this value.')."'").">$Cc";}}next($N);}if($ua)echo"<td>";adminer()->backwardKeysPrint($ua,$M[$B]);echo"</tr>\n";}if(is_ajax())exit;echo"</table>\n","</div>\n";}if(!is_ajax()){if($M||$F){$Lb=true;if($_GET["page"]!="last"){if(!$x||(count($M)<$x&&($M||!$F)))$m=($F?$F*$x:0)+count($M);elseif(JUSH!="sql"||!$v){$m=($v?false:found_rows($S,$Z));if(intval($m)<max(1e4,2*($F+1)*$x))$m=first(slow_query(count_rows($a,$Z,$v,$p)));else$Lb=false;}}$Vd=($x&&($m===false||$m>$x||$F));if($Vd)echo(($m===false?count($M)+1:$m-$F*$x)>$x?'<p><a href="'.h(remove_from_uri("page")."&page=".($F+1)).'" class="loadmore">'.'Load more data'.'</a>'.script("qsl('a').onclick = partial(selectLoadMore, $x, '".'Loading'."â€¦');",""):''),"\n";echo"<div class='footer'><div>\n";if($Vd){$pd=($m===false?$F+(count($M)>=$x?2:1):floor(($m-1)/$x));echo"<fieldset>";if(JUSH!="simpledb"){echo"<legend><a href='".h(remove_from_uri("page"))."'>".'Page'."</a></legend>",script("qsl('a').onclick = function () { pageClick(this.href, +prompt('".'Page'."', '".($F+1)."')); return false; };"),pagination(0,$F).($F>5?" â€¦":"");for($q=max(1,$F-4);$q<min($pd,$F+5);$q++)echo
pagination($q,$F);if($pd>0)echo($F+5<$pd?" â€¦":""),($Lb&&$m!==false?pagination($pd,$F):" <a href='".h(remove_from_uri("page")."&page=last")."' title='~$pd'>".'last'."</a>");}else
echo"<legend>".'Page'."</legend>",pagination(0,$F).($F>1?" â€¦":""),($F?pagination($F,$F):""),($pd>$F?pagination($F+1,$F).($pd>$F+1?" â€¦":""):"");echo"</fieldset>\n";}echo"<fieldset>","<legend>".'Whole result'."</legend>";$rb=($Lb?"":"~ ").$m;$Ld="const checked = formChecked(this, /check/); selectCount('selected', this.checked ? '$rb' : checked); selectCount('selected2', this.checked || !checked ? '$rb' : checked);";echo
checkbox("all",1,0,($m!==false?($Lb?"":"~ ").lang_format(array('%d row','%d rows'),$m):""),$Ld)."\n","</fieldset>\n";if(adminer()->selectCommandPrint())echo'<fieldset',($_GET["modify"]?'':' class="jsonly"'),'><legend>Modify</legend><div>
<input type="submit" value="Save"',($_GET["modify"]?'':' title="'.'Ctrl+click on a value to modify it.'.'"'),'>
</div></fieldset>
<fieldset><legend>Selected <span id="selected"></span></legend><div>
<input type="submit" name="edit" value="Edit">
<input type="submit" name="clone" value="Clone">
<input type="submit" name="delete" value="Delete">',confirm(),'</div></fieldset>
';$hc=adminer()->dumpFormat();foreach((array)$_GET["columns"]as$c){if($c["fun"]){unset($hc['sql']);break;}}if($hc){print_fieldset("export",'Export'." <span id='selected2'></span>");$Td=adminer()->dumpOutput();echo($Td?html_select("output",$Td,$ca["output"])." ":""),html_select("format",$hc,$ca["format"])," <input type='submit' name='export' value='".'Export'."'>\n","</div></fieldset>\n";}adminer()->selectEmailPrint(array_filter($Ab,'strlen'),$d);echo"</div></div>\n";}if(adminer()->selectImportPrint())echo"<p>","<a href='#import'>".'Import'."</a>",script("qsl('a').onclick = partial(toggle, 'import');",""),"<span id='import'".($_POST["import"]?"":" class='hidden'").">: ","<input type='file' name='csv_file'> ",html_select("separator",array("csv"=>"CSV,","csv;"=>"CSV;","tsv"=>"TSV"),$ca["format"])," <input type='submit' name='import' value='".'Import'."'>","</span>";echo
input_token(),"</form>\n",(!$p&&$N?"":script("tableCheck();"));}}}if(is_ajax()){ob_end_clean();exit;}}elseif(isset($_GET["script"])){if($_GET["script"]=="kill")connection()->query("KILL ".number($_POST["kill"]));elseif(list($R,$r,$C)=adminer()->_foreignColumn(column_foreign_keys($_GET["source"]),$_GET["field"])){$x=11;$J=connection()->query("SELECT $r, $C FROM ".table($R)." WHERE ".(preg_match('~^[0-9]+$~',$_GET["value"])?"$r = $_GET[value] OR ":"")."$C LIKE ".q("$_GET[value]%")." ORDER BY 2 LIMIT $x");for($q=1;($L=$J->fetch_row())&&$q<$x;$q++)echo"<a href='".h(ME."edit=".urlencode($R)."&where".urlencode("[".bracket_escape(idf_unescape($r))."]")."=".urlencode($L[0]))."'>".h($L[1])."</a><br>\n";if($L)echo"...\n";}exit;}else{page_header('Server',"",false);if(adminer()->homepage()){echo"<form action='' method='post'>\n","<p>".'Search data in tables'.": <input type='search' name='query' value='".h($_POST["query"])."'> <input type='submit' value='".'Search'."'>\n";if($_POST["query"]!="")search_tables();echo"<div class='scrollable'>\n","<table class='nowrap checkable odds'>\n",script("mixin(qsl('table'), {onclick: tableClick, ondblclick: partialArg(tableClick, true)});"),'<thead><tr class="wrap">','<td><input id="check-all" type="checkbox" class="jsonly">'.script("qs('#check-all').onclick = partial(formCheck, /^tables\[/);",""),'<th>'.'Table','<td>'.'Rows',"</thead>\n";foreach(table_status()as$R=>$L){$C=adminer()->tableName($L);if($C!=""){echo'<tr><td>'.checkbox("tables[]",$R,in_array($R,(array)$_POST["tables"],true)),"<th><a href='".h(ME).'select='.urlencode($R)."'>$C</a>";$W=format_number($L["Rows"]);echo"<td align='right'><a href='".h(ME."edit=").urlencode($R)."'>".($L["Engine"]=="InnoDB"&&$W?"~ $W":$W)."</a>";}}echo"</table>\n","</div>\n","</form>\n",script("tableCheck();");adminer()->pluginsLinks();}}page_footer();