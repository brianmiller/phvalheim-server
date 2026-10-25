<?php
/**
 * Hugin's Vibes -- GitHub and GitLab REST (design 6, lib/scm.php): test a token, create the repo
 * if missing, push with the token in the environment only.
 *
 * Tokens are read from the settings document the runner fetched with vibesSettingsGet() -- the
 * unmasked one. They never appear in an event, a log line or an error message.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/git.php';

/** JSON request helper: [code, json|null, rawBody]. */
function vibesScmRequest($method, $url, array $headers, $body = null) {
	$ch = curl_init($url);
	$h = array_merge(['Accept: application/json', 'User-Agent: phvalheim-server-vibes'], $headers);
	if ($body !== null) $h[] = 'Content-Type: application/json';
	curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
	                        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
	if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
	$raw = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$err = curl_error($ch);
	curl_close($ch);
	if ($raw === false) return [0, null, $err];
	$j = json_decode($raw, true);
	return [$code, is_array($j) ? $j : null, (string)$raw];
}

function vibesScmConfig(array $doc, $which) {
	if ($which === 'github') {
		return ['api' => rtrim((string)vibesSetting($doc, 'github.api', 'https://api.github.com'), '/'), 'token' => (string)vibesSetting($doc, 'github.token', ''),
		        'owner' => trim((string)vibesSetting($doc, 'github.owner', '')), 'visibility' => (string)vibesSetting($doc, 'github.visibility', 'private'),
		        'enabled' => (bool)vibesSetting($doc, 'github.enabled', false)];
	}
	return ['host' => rtrim((string)vibesSetting($doc, 'gitlab.host', 'https://gitlab.com'), '/'), 'token' => (string)vibesSetting($doc, 'gitlab.token', ''),
	        'group' => trim((string)vibesSetting($doc, 'gitlab.group', ''), '/'), 'visibility' => (string)vibesSetting($doc, 'gitlab.visibility', 'private'),
	        'enabled' => (bool)vibesSetting($doc, 'gitlab.enabled', false)];
}

function vibesScmHeaders(array $c, $which) {
	return $which === 'github' ? ['Authorization: Bearer ' . $c['token'], 'X-GitHub-Api-Version: 2022-11-28'] : ['PRIVATE-TOKEN: ' . $c['token']];
}

/** {success, login, url} or {success:false, error}. */
function vibesScmTest(array $doc, $which) {
	if (!in_array($which, ['github', 'gitlab'], true)) return ['success' => false, 'error' => 'which must be github or gitlab'];
	$c = vibesScmConfig($doc, $which);
	if ($c['token'] === '') return ['success' => false, 'error' => 'No token is set.'];
	$url = $which === 'github' ? $c['api'] . '/user' : $c['host'] . '/api/v4/user';
	[$code, $j, $raw] = vibesScmRequest('GET', $url, vibesScmHeaders($c, $which));
	if ($code === 0) return ['success' => false, 'error' => "Could not reach $url: $raw"];
	if ($code === 401 || $code === 403) return ['success' => false, 'error' => "The token was rejected (HTTP $code)."];
	if ($code !== 200 || !$j) return ['success' => false, 'error' => "Unexpected answer from $url (HTTP $code)."];
	$login = (string)($j[$which === 'github' ? 'login' : 'username'] ?? '');
	return ['success' => true, 'login' => $login, 'url' => (string)($j[$which === 'github' ? 'html_url' : 'web_url'] ?? '')];
}

/** The repository for a project, created when missing. {success, html_url, clone_url, path} or {success:false, error}. */
function vibesScmEnsureRepo(array $doc, $which, array $project) {
	$c = vibesScmConfig($doc, $which);
	if (!$c['enabled']) return ['success' => false, 'error' => ucfirst($which) . ' is not enabled in Settings.'];
	if ($c['token'] === '') return ['success' => false, 'error' => ucfirst($which) . ' has no token.'];
	$name = $project['name'];
	$desc = vibesSubstr((string)($project['description'] ?? ''), 0, 250);
	$h = vibesScmHeaders($c, $which);
	if ($which === 'github') {
		$me = vibesScmTest($doc, 'github');
		if (!$me['success']) return $me;
		$owner = $c['owner'] !== '' ? $c['owner'] : $me['login'];
		[$code, $j] = vibesScmRequest('GET', "{$c['api']}/repos/" . rawurlencode($owner) . '/' . rawurlencode($name), $h);
		if ($code === 200 && $j) return ['success' => true, 'html_url' => $j['html_url'], 'clone_url' => $j['clone_url'], 'path' => $j['full_name']];
		if ($code !== 404) return ['success' => false, 'error' => "GitHub answered HTTP $code looking up $owner/$name."];
		$body = ['name' => $name, 'description' => $desc, 'private' => $c['visibility'] !== 'public', 'auto_init' => false];
		$url = strcasecmp($owner, $me['login']) === 0 ? "{$c['api']}/user/repos" : "{$c['api']}/orgs/" . rawurlencode($owner) . '/repos';
		[$code, $j, $raw] = vibesScmRequest('POST', $url, $h, $body);
		if ($code !== 201 || !$j) return ['success' => false, 'error' => "Creating $owner/$name on GitHub failed (HTTP $code): " . vibesSubstr((string)($j['message'] ?? $raw), 0, 200)];
		return ['success' => true, 'html_url' => $j['html_url'], 'clone_url' => $j['clone_url'], 'path' => $j['full_name'], 'created' => true];
	}
	// GitLab: a project lives under a namespace (the user, or a group path like org/sub).
	$me = vibesScmTest($doc, 'gitlab');
	if (!$me['success']) return $me;
	$ns = $c['group'] !== '' ? $c['group'] : $me['login'];
	$full = "$ns/$name";
	[$code, $j] = vibesScmRequest('GET', "{$c['host']}/api/v4/projects/" . rawurlencode($full), $h);
	if ($code === 200 && $j) return ['success' => true, 'html_url' => $j['web_url'], 'clone_url' => $j['http_url_to_repo'], 'path' => $j['path_with_namespace']];
	if ($code !== 404) return ['success' => false, 'error' => "GitLab answered HTTP $code looking up $full."];
	$body = ['name' => $name, 'path' => $name, 'description' => $desc, 'visibility' => $c['visibility'], 'initialize_with_readme' => false];
	if ($c['group'] !== '') {
		[$gc, $g] = vibesScmRequest('GET', "{$c['host']}/api/v4/groups/" . rawurlencode($c['group']), $h);
		if ($gc !== 200 || !$g || !isset($g['id'])) return ['success' => false, 'error' => "GitLab group '{$c['group']}' was not found (HTTP $gc)."];
		$body['namespace_id'] = (int)$g['id'];
	}
	[$code, $j, $raw] = vibesScmRequest('POST', "{$c['host']}/api/v4/projects", $h, $body);
	if ($code !== 201 || !$j) return ['success' => false, 'error' => "Creating $full on GitLab failed (HTTP $code): " . vibesSubstr(is_array($j['message'] ?? null) ? json_encode($j['message']) : (string)($j['message'] ?? $raw), 0, 200)];
	return ['success' => true, 'html_url' => $j['web_url'], 'clone_url' => $j['http_url_to_repo'], 'path' => $j['path_with_namespace'], 'created' => true];
}

/**
 * The push job's work: ensure the repo, set the remote, push the current branch + tags, remember the remote path.
 * $step($label, $progress, $detail). Returns {success, url, path, created, output, error}.
 */
function vibesScmPush(PDO $pdo, array $doc, $which, array $project, $step) {
	if (!in_array($which, ['github', 'gitlab'], true)) return ['success' => false, 'error' => 'remote must be github or gitlab'];
	if ($missing = vibesPakMissing()) return ['success' => false, 'error' => $missing];
	$step('Checking the remote repository', 10, ucfirst($which));
	$repo = vibesScmEnsureRepo($doc, $which, $project);
	if (!$repo['success']) return $repo;
	$step('Setting the remote', 40, $repo['path']);
	vibesGitSettings($doc);
	[$ok, $err] = vibesGitRemoteSet($project['slug'], $which, $repo['clone_url']);
	if (!$ok) return ['success' => false, 'error' => "git remote failed: $err"];
	$step('Pushing current branch and tags', 60, $repo['path']);
	$c = vibesScmConfig($doc, $which);
	[$ok, $out] = vibesGitPush($project['slug'], $which, $repo['clone_url'], $c['token'], $which);
	if (!$ok) return ['success' => false, 'error' => 'git push failed: ' . vibesSubstr($out, -400), 'output' => $out];
	$col = $which === 'github' ? 'github_remote' : 'gitlab_remote';
	$pdo->prepare("UPDATE hv_projects SET $col = ? WHERE id = ?")->execute([$repo['path'], (int)$project['id']]);
	return ['success' => true, 'url' => $repo['html_url'], 'path' => $repo['path'], 'created' => !empty($repo['created']), 'output' => $out, 'error' => null];
}

/** Only the configured SCM hosts may receive credentials. No URL userinfo or alternate protocols. */
function vibesScmParseUrl($url, array $doc) {
 $u = parse_url(trim((string)$url));
 if (!$u || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) throw new RuntimeException('Use an HTTPS GitHub or GitLab repository URL without credentials or query parameters.');
 $host = strtolower($u['host'] ?? '');
 $gitlab = parse_url(vibesSetting($doc, 'gitlab.host', 'https://gitlab.com'));
 $which = $host === 'github.com' && !isset($u['port']) ? 'github' : (($host === strtolower($gitlab['host'] ?? '') && ($u['port'] ?? 443) === ($gitlab['port'] ?? 443)) ? 'gitlab' : null);
 if (!$which) throw new RuntimeException('Repository must be on github.com or the GitLab host configured in Settings.');
 $path = preg_replace('/\.git$/', '', trim($u['path'] ?? '', '/'));
 if (!preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_.-]*(/[A-Za-z0-9_-][A-Za-z0-9_.-]*)+$~', $path) || strpos($path, '..') !== false) throw new RuntimeException('Use the repository URL, not a branch or file link.');
 if ($which === 'github' && count(explode('/', $path)) !== 2) throw new RuntimeException('GitHub URLs must have owner/repository only.');
 return ['which'=>$which, 'path'=>$path, 'url'=>'https://'.$host.(isset($u['port'])?':'.$u['port']:'').'/'.$path.'.git'];
}
function vibesScmAuthEnv(array $doc, array $repo) {
 $token = (string)vibesSetting($doc, $repo['which'].'.token', '');
 $env=['GIT_CONFIG_COUNT'=>'2','GIT_CONFIG_KEY_0'=>'http.followRedirects','GIT_CONFIG_VALUE_0'=>'false','GIT_CONFIG_KEY_1'=>'protocol.file.allow','GIT_CONFIG_VALUE_1'=>'never'];
 if ($token !== '') { $env['GIT_CONFIG_COUNT']='3'; $env['GIT_CONFIG_KEY_2']='http.'.$repo['url'].'.extraHeader'; $env['GIT_CONFIG_VALUE_2']='Authorization: Basic '.base64_encode(($repo['which']==='github'?'x-access-token':'oauth2').':'.$token); }
 return $env;
}
function vibesScmStatus($slug) {
 $r=vibesGit($slug,['status','--porcelain']);
 if(!$r['ok']) return ['error'=>'Git status unavailable. Install or repair the Build Pak.','remotes'=>[]];
 $branch=trim(vibesGit($slug,['branch','--show-current'])['out']);
 $out=['branch'=>$branch,'head'=>trim(vibesGit($slug,['rev-parse','HEAD'])['out']),'changes'=>vibesGitStatus($slug),'remotes'=>[], 'checkedAt'=>vibesReadJson(vibesProjectDir($slug).'/.vibes/builds/scm-check.json',[])['at']??null];
 foreach(array_filter(explode("\n",trim(vibesGit($slug,['remote'])['out']))) as $remote) {
  if(!in_array($remote,['origin','github','gitlab'],true)) continue;
  $url=trim(vibesGit($slug,['remote','get-url',$remote])['out']);
  // Do not echo credentials from a hand-edited config.
  if(!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]+)?/[a-zA-Z0-9_./-]+$~',$url)) continue;
  $count=vibesGit($slug,['rev-list','--left-right','--count','HEAD...refs/remotes/'.$remote.'/'.$branch]);
  $n=preg_split('/\s+/',trim($count['out']));
  $out['remotes'][]=['name'=>$remote,'url'=>preg_replace('/\.git$/','',$url),'ahead'=>$count['ok']?(int)$n[0]:null,'behind'=>$count['ok']?(int)($n[1]??0):null];
 }
 return $out;
}
function vibesScmSync(PDO $pdo, array $doc, array $project, array $args, $step) {
 $slug=$project['slug'];$remote=$args['remote']??'';$op=$args['operation']??'';
 if(!in_array($remote,['origin','github','gitlab'],true)||!in_array($op,['fetch','pull','push'],true)) throw new RuntimeException('Invalid SCM operation.');
 $r=vibesGit($slug,['remote','get-url',$remote]);if(!$r['ok']) throw new RuntimeException('Remote is not configured.');
 $repo=vibesScmParseUrl(trim($r['out']),$doc);$env=vibesScmAuthEnv($doc,$repo);
 $branch=trim(vibesGit($slug,['branch','--show-current'])['out']);
 if($branch==='' || $branch[0]==='-') throw new RuntimeException('Check out a branch before synchronizing.');
 if($op==='pull' && vibesGitIsDirty($slug)) throw new RuntimeException('Commit your local changes before pulling.');
 $step('Checking remote branch',20,$remote.'/'.$branch);
 $r=vibesGit($slug,['fetch','--prune',$remote],$env,180);
 if(!$r['ok']) throw new RuntimeException('Remote fetch failed. Check the URL, token, and repository access in Settings.');
 if($op!=='fetch') {
  $step($op==='pull'?'Fast-forwarding local branch':'Pushing current branch',60,$branch);
  $args=$op==='pull'?['merge','--ff-only','refs/remotes/'.$remote.'/'.$branch]:['push','-u',$remote,'HEAD:refs/heads/'.$branch];
  $r=vibesGit($slug,$args,$env,180);
  if(!$r['ok']) throw new RuntimeException($op==='pull'?'Cannot fast-forward. Review the branches and resolve divergence in your SCM before pulling.':'Push rejected. Check access and fetch to inspect any remote commits. No force-push was attempted.');
 }
 vibesWriteJson(vibesProjectDir($slug).'/.vibes/builds/scm-check.json',['at'=>gmdate('c')]);
 return ['success'=>true,'scm'=>vibesScmStatus($slug)];
}

function vibesScmImport(PDO $pdo, array $doc, $url, $step) {
 if($missing=vibesPakMissing()) throw new RuntimeException($missing);
 $repo=vibesScmParseUrl($url,$doc);
 $step('Cloning repository',10,$repo['path']);
 @mkdir(vibesDownloadsDir(),0775,true);
 $tmp=vibesDownloadsDir().'/import-'.bin2hex(random_bytes(6));@mkdir($tmp,0775,true);
 try {
  $r=vibesGit('', ['clone','--no-recurse-submodules','--', $repo['url'], $tmp.'/repo'],vibesScmAuthEnv($doc,$repo),180,$tmp);
  if(!$r['ok']) throw new RuntimeException('Clone failed. Check the repository URL and the matching SCM token for private repositories.');
  $dir=$tmp.'/repo';
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $f) if($f->isLink()) throw new RuntimeException('Import contains symlinks. Remove them before importing.');
  $m=vibesReadJson($dir.'/manifest.json',[]);
  $raw=basename($repo['path']);$projects=glob($dir.'/*.csproj')?:[];
  $name=$m['name']??(count($projects)===1?basename($projects[0],'.csproj'):preg_replace('/[^A-Za-z0-9_]/','',$raw));
  if(!vibesValidName($name)) throw new RuntimeException('Repository needs a valid mod name in its root manifest.json.');
  $ns=(string)vibesSetting($doc,'author.namespace','');if($ns==='') $ns=preg_replace('/[^A-Za-z0-9_]/','',explode('/',$repo['path'])[0]);
  if(!vibesValidNamespace($ns)) throw new RuntimeException('Set your author namespace in Settings first.');
  $slug=substr(vibesSlugify($name),0,50).'-'.bin2hex(random_bytes(3));$dest=vibesProjectDir($slug);
  $ver=vibesValidVersion($m['version_number']??null)?$m['version_number']:'0.1.0';
  // Imported transcripts and harness metadata are not trusted as sessions in this server.
  if(is_dir($dir.'/.vibes')) vibesRmTree($dir.'/.vibes');
  @mkdir($dir.'/.vibes',0775,true);
  vibesWriteJson($dir.'/.vibes/project.json',['name'=>$name,'namespace'=>$ns,'guid'=>'Inspect the existing BepInPlugin declaration; preserve its GUID','template'=>'import','created'=>gmdate('c'),'importedFrom'=>$repo['url']]);
  file_put_contents($dir.'/.vibes/notes.md',"Imported from {$repo['url']}. Inspect the project and build configuration before building. Preserve the original BepInEx GUID.\n");
  // Local runtime metadata must never enter the imported upstream history.
  file_put_contents($dir.'/.git/info/exclude',"\n.vibes/\nbin/\nobj/\ndist/\n",FILE_APPEND);
  @mkdir(vibesProjectsDir(),0775,true);
  if(!rename($dir,$dest)) throw new RuntimeException('Could not move the imported repository into the workspace.');
  try {
   $q=$pdo->prepare("INSERT INTO hv_projects (slug,name,namespace,description,version,template,status,provider_id,model) VALUES (?,?,?,?,?,'import','draft',?,?)");
   $q->execute([$slug,$name,$ns,vibesSubstr($m['description']??'',0,250),$ver,vibesSetting($doc,'defaults.provider_id'),vibesSetting($doc,'defaults.model','')]);
  } catch(Throwable $e) { vibesRmTree($dest);throw $e; }
  $id=(int)$pdo->lastInsertId();
  $step('Repository imported; ready for review',100,$name);
  return ['success'=>true,'projectId'=>$id];
 } finally { vibesRmTree($tmp); }
}
