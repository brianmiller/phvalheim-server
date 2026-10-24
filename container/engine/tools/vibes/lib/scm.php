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
 * The push job's work: ensure the repo, set the remote, push main + tags, remember the remote path.
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
	$step('Pushing main and tags', 60, $repo['path']);
	$c = vibesScmConfig($doc, $which);
	[$ok, $out] = vibesGitPush($project['slug'], $which, $repo['clone_url'], $c['token'], $which);
	if (!$ok) return ['success' => false, 'error' => 'git push failed: ' . vibesSubstr($out, -400), 'output' => $out];
	$col = $which === 'github' ? 'github_remote' : 'gitlab_remote';
	$pdo->prepare("UPDATE hv_projects SET $col = ? WHERE id = ?")->execute([$repo['path'], (int)$project['id']]);
	return ['success' => true, 'url' => $repo['html_url'], 'path' => $repo['path'], 'created' => !empty($repo['created']), 'output' => $out, 'error' => null];
}
