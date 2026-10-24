<?php
/**
 * Hugin's Vibes -- a project on disk (design 5): create from a template, read/write the
 * Thunderstore manifest, bump versions, list the tree, delete, export.
 *
 * Identity rules come from includes/vibes.php (vibesValidName etc.) and are the catalogue's:
 * (namespace, name) is case-sensitive there, so it is case-sensitive here.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/git.php';

function vibesTemplatesDir() { return VIBES_TOOLS . '/templates'; }

/** [{key,label,description}] from templates/<key>/template.json; a template without one is not offered. */
function vibesTemplates() {
	$out = [];
	foreach (glob(vibesTemplatesDir() . '/*/template.json') ?: [] as $f) {
		$t = vibesReadJson($f, null);
		$key = basename(dirname($f));
		if (!is_array($t) || !preg_match('/^[a-z0-9-]{1,32}$/', $key)) continue;
		$out[] = ['key' => $key, 'label' => (string)($t['label'] ?? $key), 'description' => (string)($t['description'] ?? '')];
	}
	usort($out, function ($a, $b) { return strcmp($a['key'], $b['key']); });
	return $out;
}

/** Token substitution for template text: {{Name}} {{Namespace}} {{Guid}} {{Description}} {{Version}} {{Slug}} {{Year}}. */
function vibesTemplateRender($text, array $vars) {
	return preg_replace_callback('/\{\{(Name|Namespace|Guid|Description|Version|Slug|Year)\}\}/', function ($m) use ($vars) {
		return (string)($vars[$m[1]] ?? '');
	}, $text);
}

/** Files whose CONTENT is rendered; everything else (icon.png) is copied byte for byte. */
function vibesTemplateIsText($file) {
	return preg_match('/\.(cs|csproj|props|json|md|txt|gitignore|xml|targets|cfg|yml|yaml)$/i', $file) === 1 || basename($file) === '.gitignore';
}

function vibesTemplateCopy($src, $dst, array $vars) {
	@mkdir($dst, 0775, true);
	foreach (scandir($src) ?: [] as $e) {
		if ($e === '.' || $e === '..' || $e === 'template.json') continue;
		$from = "$src/$e";
		$to = "$dst/" . vibesTemplateRender($e, $vars);
		if (is_dir($from)) { if (!vibesTemplateCopy($from, $to, $vars)) return false; continue; }
		$data = file_get_contents($from);
		if ($data === false) return false;
		if (vibesTemplateIsText($e)) $data = vibesTemplateRender($data, $vars);
		if (file_put_contents($to, $data) === false) return false;
	}
	return true;
}

/**
 * Create a project. $f = ['name','namespace','description','template'] (namespace falls back to
 * settings author.namespace). Returns {success, project} or {success:false, error}.
 */
function vibesProjectCreate(PDO $pdo, array $f) {
	$doc = vibesSettingsGet($pdo);
	$name = trim((string)($f['name'] ?? ''));
	$ns   = trim((string)($f['namespace'] ?? ''));
	if ($ns === '') $ns = trim((string)vibesSetting($doc, 'author.namespace', ''));
	$desc = trim((string)($f['description'] ?? ''));
	$tpl  = trim((string)($f['template'] ?? vibesSetting($doc, 'defaults.template', 'basic-plugin')));
	$ver  = trim((string)($f['version'] ?? '0.1.0'));
	if (!vibesValidName($name))      return ['success' => false, 'error' => 'Name must start with a letter and contain only letters, digits and underscores (2-96 characters). It is the C# assembly name and the Thunderstore package name.'];
	if (!vibesValidNamespace($ns))   return ['success' => false, 'error' => 'Namespace must contain only letters, digits and underscores (set one here or in Settings -> Author).'];
	if (!vibesValidVersion($ver))    return ['success' => false, 'error' => 'Version must be x.y.z.'];
	if (vibesStrlen($desc) > 250)      return ['success' => false, 'error' => 'Description must be 250 characters or fewer (Thunderstore\'s limit).'];
	if (preg_match('/[\r\n]/', $desc)) return ['success' => false, 'error' => 'Description must be a single line.'];
	$templates = array_column(vibesTemplates(), 'key');
	if (!in_array($tpl, $templates, true)) return ['success' => false, 'error' => "Unknown template '$tpl'."];
	if ($missing = vibesPakMissing()) return ['success' => false, 'error' => $missing];

	$slug = vibesSlugify($name);
	$dir = vibesProjectDir($slug);
	$s = $pdo->prepare("SELECT id, slug FROM hv_projects WHERE slug = ? OR (namespace = ? AND name = ?) LIMIT 1");
	$s->execute([$slug, $ns, $name]);
	if ($row = $s->fetch(PDO::FETCH_ASSOC)) return ['success' => false, 'error' => "A Vibe named '$name' (or with the directory name '$slug') already exists."];
	if (file_exists($dir)) return ['success' => false, 'error' => "The directory projects/$slug already exists on disk but no project row points at it. Remove or rename it first."];

	$vars = ['Name' => $name, 'Namespace' => $ns, 'Guid' => vibesGuid($ns, $name), 'Description' => $desc === '' ? "$name, a Valheim mod made with Hugin's Vibes." : $desc,
	         'Version' => $ver, 'Slug' => $slug, 'Year' => date('Y')];
	if (!vibesTemplateCopy(vibesTemplatesDir() . "/$tpl", $dir, $vars)) { vibesRmTree($dir); return ['success' => false, 'error' => 'Copying the template failed (is /opt/stateful/vibes/projects writable by phvalheim?).']; }
	@mkdir("$dir/.vibes/sessions", 0775, true);
	@mkdir("$dir/.vibes/builds", 0775, true);
	$pak = vibesPakManifest();
	vibesWriteJson("$dir/.vibes/project.json", [
		'slug' => $slug, 'name' => $name, 'namespace' => $ns, 'guid' => $vars['Guid'], 'created' => date('c'), 'template' => $tpl,
		'pakFormat' => (int)($pak['pakFormat'] ?? 0), 'pakVersion' => $pak['version'] ?? null, 'serverVersion' => getenv('phvalheimVersion') ?: '',
	]);
	if (!is_file("$dir/.vibes/notes.md")) {
		file_put_contents("$dir/.vibes/notes.md", "# Notes for $name\n\nHugin's durable memory for this project: decisions, open bugs, TODOs. One dated bullet per entry.\n\n- " . date('Y-m-d') . ": created from the `$tpl` template as $ns.$name (GUID `{$vars['Guid']}`).\n");
	}
	vibesGitSettings($doc);
	[$ok, $err] = vibesGitInit($slug);
	if (!$ok) { vibesRmTree($dir); return ['success' => false, 'error' => "git init failed: $err"]; }
	[$ok, $hash] = vibesGitCommit($slug, "vibe: create $name from $tpl");
	if (!$ok) { vibesRmTree($dir); return ['success' => false, 'error' => "The first commit failed: $hash"]; }

	$ins = $pdo->prepare("INSERT INTO hv_projects (slug, name, namespace, description, version, template, status, provider_id, model) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?)");
	$pid = vibesSetting($doc, 'defaults.provider_id');
	$ins->execute([$slug, $name, $ns, $desc, $ver, $tpl, $pid === null || $pid === '' ? null : (int)$pid, (string)vibesSetting($doc, 'defaults.model', '')]);
	$id = (int)$pdo->lastInsertId();
	return ['success' => true, 'project' => vibesProjectRow($pdo, $id)];
}

/** The row with the extras the page needs, or null. */
function vibesProjectRow(PDO $pdo, $id) {
	$s = $pdo->prepare("SELECT * FROM hv_projects WHERE id = ?");
	$s->execute([(int)$id]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	if (!$r) return null;
	return vibesProjectDecorate($pdo, $r);
}

function vibesProjectRowBySlug(PDO $pdo, $slug) {
	$s = $pdo->prepare("SELECT * FROM hv_projects WHERE slug = ?");
	$s->execute([(string)$slug]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	return $r ? vibesProjectDecorate($pdo, $r) : null;
}

function vibesProjectDecorate(PDO $pdo, array $r) {
	foreach (['id', 'provider_id', 'last_build_ok'] as $k) if ($r[$k] !== null) $r[$k] = (int)$r[$k];
	$dir = vibesProjectDir($r['slug']);
	$r['dir']       = $dir;
	$r['exists']    = is_dir($dir) && is_file("$dir/.vibes/project.json");
	$r['size']      = vibesDirSize($dir);
	$r['guid']      = vibesGuid($r['namespace'], $r['name']);
	$r['manifest']  = $r['exists'] ? vibesProjectManifestRead($r['slug']) : [];
	$r['lastBuild'] = $r['exists'] ? vibesReadJson("$dir/.vibes/builds/last.json", null) : null;
	$r['worldsUsing'] = [];
	try {
		$q = $pdo->prepare("SELECT DISTINCT w.name FROM world_mods wm JOIN mods m ON m.id = wm.mod_id JOIN worlds w ON w.id = wm.world_id WHERE m.source='vibes' AND m.owner=? AND m.name=? ORDER BY w.name");
		$q->execute([$r['namespace'], $r['name']]);
		$r['worldsUsing'] = $q->fetchAll(PDO::FETCH_COLUMN);
	} catch (Throwable $e) { }
	return $r;
}

/** [{path,size,kind}] depth-first, skipping .git, bin, obj (the parts nobody edits by hand). */
function vibesProjectTree($slug) {
	$root = vibesProjectDir($slug);
	if (!vibesValidSlug($slug) || !is_dir($root)) return [];
	$out = [];
	$walk = function ($dir, $rel) use (&$walk, &$out) {
		$entries = scandir($dir) ?: [];
		sort($entries, SORT_STRING | SORT_FLAG_CASE);
		foreach ($entries as $e) {
			if ($e === '.' || $e === '..') continue;
			$p = "$dir/$e"; $r = ($rel === '' ? '' : "$rel/") . $e;
			if (in_array($r, ['.git', 'bin', 'obj'], true)) continue;
			if (is_link($p)) continue;
			if (is_dir($p)) { $out[] = ['path' => $r, 'size' => 0, 'kind' => 'dir']; $walk($p, $r); }
			else $out[] = ['path' => $r, 'size' => (int)filesize($p), 'kind' => 'file'];
			if (count($out) > 5000) return;
		}
	};
	$walk($root, '');
	return $out;
}

function vibesProjectManifestRead($slug) {
	$m = vibesReadJson(vibesProjectDir($slug) . '/manifest.json', []);
	return is_array($m) ? $m : [];
}

/**
 * Thunderstore's manifest rules (design 6.3), plus the icon when a project dir is given.
 * Returns [ok, errors[]].
 */
function vibesManifestValidate($m, $projectDir = null) {
	$errors = [];
	if (!is_array($m)) return [false, ['manifest.json must be a JSON object.']];
	if (!vibesValidName($m['name'] ?? null)) $errors[] = 'name must match ^[A-Za-z][A-Za-z0-9_]+$ (letters, digits, underscores; 2-96 characters).';
	if (!vibesValidVersion($m['version_number'] ?? null)) $errors[] = 'version_number must be x.y.z with numbers only.';
	$d = $m['description'] ?? null;
	if (!is_string($d)) $errors[] = 'description must be a string.';
	elseif (vibesStrlen($d) > 250) $errors[] = 'description must be 250 characters or fewer.';
	elseif (preg_match('/[\r\n]/', $d)) $errors[] = 'description must be a single line.';
	$u = $m['website_url'] ?? '';
	if (!is_string($u) || ($u !== '' && !preg_match('~^https?://[^\s]+$~', $u))) $errors[] = 'website_url must be empty or an http(s) URL.';
	if (!array_key_exists('dependencies', $m) || !is_array($m['dependencies']) || ($m['dependencies'] !== [] && !array_is_list($m['dependencies']))) $errors[] = 'dependencies must be a list (possibly empty).';
	else foreach ($m['dependencies'] as $dep) {
		// Owners (LVH-IT, sinai-dev) and versions (2.0.6-beta.1) contain hyphens: at least two
		// hyphen-separated words before the version, and the catalogue resolves the split by
		// longest known owner-name prefix (CLAUDE.md, mods section).
		if (!is_string($dep) || !preg_match('/^[A-Za-z0-9_]+(-[A-Za-z0-9_]+)+-\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/', $dep)) $errors[] = "dependency '" . (is_string($dep) ? $dep : gettype($dep)) . "' must look like Owner-Name-1.2.3.";
	}
	if ($projectDir !== null) {
		$icon = "$projectDir/icon.png";
		if (!is_file($icon)) $errors[] = 'icon.png is missing.';
		else {
			$dim = vibesPngSize($icon);
			if ($dim === null) $errors[] = 'icon.png is not a PNG.';
			elseif ($dim !== [256, 256]) $errors[] = "icon.png must be 256x256 (it is {$dim[0]}x{$dim[1]}).";
		}
	}
	return [!$errors, $errors];
}

/** [w, h] from the IHDR chunk, no GD needed; null when not a PNG. */
function vibesPngSize($file) {
	$h = @file_get_contents($file, false, null, 0, 24);
	if ($h === false || strlen($h) < 24 || substr($h, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($h, 12, 4) !== 'IHDR') return null;
	$w = unpack('N', substr($h, 16, 4))[1];
	$hh = unpack('N', substr($h, 20, 4))[1];
	return [(int)$w, (int)$hh];
}

/**
 * Write manifest.json after validation. The name is NOT writable: it is the project's identity
 * (assembly, GUID, catalogue row); a manifest whose name differs from the project is refused.
 * Returns [ok, errors].
 */
function vibesProjectManifestWrite($slug, array $m) {
	$dir = vibesProjectDir($slug);
	if (!is_dir($dir)) return [false, ["No project directory for '$slug'."]];
	$pj = vibesReadJson("$dir/.vibes/project.json", []);
	$cur = vibesProjectManifestRead($slug);
	$new = [
		'name'           => $pj['name'] ?? ($cur['name'] ?? ''),
		'version_number' => (string)($m['version_number'] ?? ($cur['version_number'] ?? '0.1.0')),
		'website_url'    => (string)($m['website_url'] ?? ($cur['website_url'] ?? '')),
		'description'    => (string)($m['description'] ?? ($cur['description'] ?? '')),
		'dependencies'   => array_values($m['dependencies'] ?? ($cur['dependencies'] ?? [])),
	];
	if (isset($m['name']) && $m['name'] !== $new['name']) return [false, ["The package name is fixed at '{$new['name']}' (it is the assembly and GUID); rename is not supported."]];
	[$ok, $errors] = vibesManifestValidate($new, $dir);
	if (!$ok) return [false, $errors];
	if (!vibesWriteJson("$dir/manifest.json", $new)) return [false, ['Could not write manifest.json.']];
	return [true, []];
}

/**
 * Bump to 'patch'|'minor'|'major' or an explicit x.y.z: manifest.json, the csproj <Version>, and
 * the PluginVersion const in src/Plugin.cs (BepInEx reads THAT one at runtime; three places must
 * agree or the catalogue, the DLL and Thunderstore each tell a different story). [ok, newVersion|error].
 */
function vibesProjectBumpVersion($slug, $how, PDO $pdo = null) {
	$dir = vibesProjectDir($slug);
	$m = vibesProjectManifestRead($slug);
	$cur = (string)($m['version_number'] ?? '0.1.0');
	if (!vibesValidVersion($cur)) $cur = '0.1.0';
	[$a, $b, $c] = array_map('intval', explode('.', $cur));
	switch ($how) {
		case 'patch': $new = "$a.$b." . ($c + 1); break;
		case 'minor': $new = "$a." . ($b + 1) . '.0'; break;
		case 'major': $new = ($a + 1) . '.0.0'; break;
		default: $new = (string)$how; if (!vibesValidVersion($new)) return [false, "Version '$how' must be patch, minor, major or x.y.z."];
	}
	$m['version_number'] = $new;
	[$ok, $errors] = vibesProjectManifestWrite($slug, $m);
	if (!$ok) return [false, implode(' ', $errors)];
	if ($csproj = (glob("$dir/*.csproj") ?: [null])[0]) {
		$x = file_get_contents($csproj);
		$x2 = preg_replace('~<Version>[^<]*</Version>~', "<Version>$new</Version>", $x, 1);
		if ($x2 !== null && $x2 !== $x) file_put_contents($csproj, $x2);
	}
	foreach (glob("$dir/src/*.cs") ?: [] as $cs) {
		$x = file_get_contents($cs);
		$x2 = preg_replace('~(const\s+string\s+PluginVersion\s*=\s*")[^"]*(")~', '${1}' . $new . '${2}', $x, 1);
		if ($x2 !== null && $x2 !== $x) { file_put_contents($cs, $x2); break; }
	}
	if ($pdo) { try { $pdo->prepare("UPDATE hv_projects SET version=? WHERE slug=?")->execute([$new, $slug]); } catch (Throwable $e) { } }
	return [true, $new];
}

/** Delete a project: refuses while a job is open; removes the dir, its sessions/jobs rows and job logs. */
function vibesProjectDelete(PDO $pdo, $id) {
	$row = vibesProjectRow($pdo, $id);
	if (!$row) return ['success' => false, 'error' => 'No such Vibe.'];
	if ($j = vibesJobOpen($pdo, $row['id'])) return ['success' => false, 'error' => "A '{$j['kind']}' job (#{$j['id']}) is still running for this Vibe; wait for it or cancel it first."];
	if (!vibesRmTree($row['dir']) && is_dir($row['dir'])) return ['success' => false, 'error' => 'Could not remove the project directory.'];
	$q = $pdo->prepare("SELECT id FROM hv_jobs WHERE project_id = ?");
	$q->execute([$row['id']]);
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $jid) @unlink(vibesJobLog($jid));
	$pdo->prepare("DELETE FROM hv_jobs WHERE project_id = ?")->execute([$row['id']]);
	$pdo->prepare("DELETE FROM hv_sessions WHERE project_id = ?")->execute([$row['id']]);
	$pdo->prepare("DELETE FROM hv_projects WHERE id = ?")->execute([$row['id']]);
	return ['success' => true, 'slug' => $row['slug']];
}

/** tar.gz of the repository (with .git) minus build products and transcripts. */
function vibesProjectExportTar($slug, $outFile) {
	$dir = vibesProjectDir($slug);
	if (!vibesValidSlug($slug) || !is_dir($dir)) return false;
	@mkdir(dirname($outFile), 0775, true);
	$r = vibesRun(['/usr/bin/tar', '-czf', $outFile, '-C', vibesProjectsDir(),
	               '--exclude', "$slug/bin", '--exclude', "$slug/obj", '--exclude', "$slug/dist",
	               '--exclude', "$slug/.vibes/sessions", '--exclude', "$slug/.vibes/builds", $slug], null, null, 300);
	return $r['ok'] && is_file($outFile);
}
