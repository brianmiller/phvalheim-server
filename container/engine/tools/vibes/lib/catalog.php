<?php
/**
 * Hugin's Vibes -- the local catalogue: publish/unpublish into mods/mod_versions/mod_deps (design 6.4).
 *
 * A vibes mod is a mod. It lives in the 2.43 tables under source='vibes' with the SAME identity
 * rules (owner, name case-sensitive; full_name owner-name; deps as 'Owner-Name-X.Y.Z' strings) so
 * the picker, worldMods.py and the engine's install loop need no special case -- only the
 * download_url differs (file:///opt/stateful/vibes/catalog/<zip>), which the one engine seam
 * copies instead of fetching. modSync.py prunes per source and never iterates 'vibes', and its
 * full dependency rebuild recomputes edges from every mod_versions.deps, ours included.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/package.php';

function vibesCatalogIndexFile() { return vibesCatalogDir() . '/index.json'; }

/** Numeric x.y.z compare, newest first. */
function vibesVersionCmpDesc($a, $b) {
	$pa = array_map('intval', explode('.', $a)); $pb = array_map('intval', explode('.', $b));
	for ($i = 0; $i < 3; $i++) { $d = ($pb[$i] ?? 0) <=> ($pa[$i] ?? 0); if ($d !== 0) return $d; }
	return strcmp($b, $a);
}

/**
 * Resolve 'Owner-Name-X.Y.Z' by the LONGEST known owner-name prefix (owners and versions both
 * contain hyphens: LVH-IT, 2.0.6-beta.1), preferring thunderstore, then hexium, then vibes.
 * Returns [dep_mod_id|null, dep_version|null, dep_version_id|null].
 */
function vibesCatalogResolveDep(PDO $pdo, $depString) {
	static $byName = null;
	if ($byName === null) {
		$byName = [];
		foreach ($pdo->query("SELECT id, source, full_name FROM mods") as $r) $byName[$r['full_name']][$r['source']] = (int)$r['id'];
	}
	$best = null;
	foreach ($byName as $full => $sources) {
		if (strncmp($depString, $full . '-', strlen($full) + 1) === 0 && ($best === null || strlen($full) > strlen($best))) $best = $full;
	}
	if ($best === null) return [null, null, null];
	$sources = $byName[$best];
	$modId = $sources['thunderstore'] ?? $sources['hexium'] ?? $sources['vibes'] ?? array_values($sources)[0];
	$ver = substr($depString, strlen($best) + 1);
	$q = $pdo->prepare("SELECT id FROM mod_versions WHERE mod_id = ? AND version = ? LIMIT 1");
	$q->execute([$modId, $ver]);
	$vid = $q->fetchColumn();
	return [$modId, $ver, $vid === false ? null : (int)$vid];
}

/** Rewrite source_rank (0 = newest) and the mods row's denormalised latest/count. */
function vibesCatalogRenumber(PDO $pdo, $modId) {
	$q = $pdo->prepare("SELECT id, version FROM mod_versions WHERE mod_id = ?");
	$q->execute([$modId]);
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	usort($rows, function ($a, $b) { return vibesVersionCmpDesc($a['version'], $b['version']); });
	$u = $pdo->prepare("UPDATE mod_versions SET source_rank = ? WHERE id = ?");
	foreach ($rows as $i => $r) $u->execute([$i, (int)$r['id']]);
	$pdo->prepare("UPDATE mods SET latest_version = ?, version_count = ?, date_updated = NOW(), last_seen = NOW() WHERE id = ?")
	    ->execute([$rows[0]['version'] ?? null, count($rows), $modId]);
	return $rows[0]['version'] ?? null;
}

/**
 * Publish dist/<ns>-<name>-<ver>.zip into the catalogue. The caller (the publish job) packages
 * first when the zip is missing. Returns {success, version, zip, modId, versionId, deps:[{dep, resolved}], error}.
 */
function vibesCatalogPublish(PDO $pdo, array $project, $version = null) {
	$slug = $project['slug']; $ns = $project['namespace']; $name = $project['name'];
	$m = vibesProjectManifestRead($slug);
	$version = $version ?: ($m['version_number'] ?? null);
	if (!vibesValidVersion((string)$version)) return ['success' => false, 'error' => 'No valid version to publish (manifest.json version_number).'];
	if (($m['name'] ?? '') !== $name) return ['success' => false, 'error' => "manifest.json names '" . ($m['name'] ?? '') . "' but the project is '$name'."];
	[$ok, $errors] = vibesManifestValidate($m, vibesProjectDir($slug));
	if (!$ok) return ['success' => false, 'error' => 'manifest.json is not valid: ' . implode(' ', $errors)];
	$zipName = "$ns-$name-$version.zip";
	$src = vibesProjectDir($slug) . "/dist/$zipName";
	if (!is_file($src)) return ['success' => false, 'error' => "dist/$zipName does not exist; package first."];
	$entries = vibesZipEntries($src);
	if ($entries === null || !in_array("plugins/$name.dll", $entries, true) || !in_array('manifest.json', $entries, true)) {
		return ['success' => false, 'error' => "dist/$zipName is not a valid package (plugins/$name.dll or manifest.json missing)."];
	}
	@mkdir(vibesCatalogDir(), 0775, true);
	$dst = vibesCatalogDir() . "/$zipName";
	if (!@copy($src, $dst)) return ['success' => false, 'error' => 'Could not copy the package into catalog/.'];
	$size = filesize($dst);
	$deps = array_values(array_filter($m['dependencies'] ?? [], 'is_string'));
	$desc = vibesSubstr((string)($m['description'] ?? ''), 0, 400);

	$pdo->beginTransaction();
	try {
		$q = $pdo->prepare("SELECT id FROM mods WHERE source='vibes' AND owner=? AND name=? LIMIT 1");
		$q->execute([$ns, $name]);
		$modId = $q->fetchColumn();
		if ($modId === false) {
			$pdo->prepare("INSERT INTO mods (source, owner, name, full_name, source_uuid, package_url, date_created, date_updated, categories, version_count, latest_version)
			               VALUES ('vibes', ?, ?, ?, NULL, NULL, NOW(), NOW(), ?, 0, NULL)")
			    ->execute([$ns, $name, "$ns-$name", json_encode(["Hugin's Vibes"])]);
			$modId = (int)$pdo->lastInsertId();
		} else $modId = (int)$modId;

		$q = $pdo->prepare("SELECT id FROM mod_versions WHERE mod_id=? AND version=?");
		$q->execute([$modId, $version]);
		$vid = $q->fetchColumn();
		$url = 'file://' . $dst;
		if ($vid === false) {
			$pdo->prepare("INSERT INTO mod_versions (mod_id, version, full_name, source_uuid, download_url, icon_url, website_url, description, file_size, downloads, is_active, source_rank, date_created, deps)
			               VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, 0, 1, 0, NOW(), ?)")
			    ->execute([$modId, $version, "$ns-$name-$version", $url, (string)($m['website_url'] ?? ''), $desc, $size, json_encode($deps)]);
			$vid = (int)$pdo->lastInsertId();
		} else {
			// Re-publishing the same version replaces the file and its metadata: the operator
			// rebuilt and clicked Publish again, which is the normal fix-and-ship loop.
			$vid = (int)$vid;
			$pdo->prepare("UPDATE mod_versions SET download_url=?, website_url=?, description=?, file_size=?, deps=?, is_active=1, last_seen=NOW() WHERE id=?")
			    ->execute([$url, (string)($m['website_url'] ?? ''), $desc, $size, json_encode($deps), $vid]);
			$pdo->prepare("DELETE FROM mod_deps WHERE version_id=?")->execute([$vid]);
		}
		$ins = $pdo->prepare("INSERT INTO mod_deps (version_id, dep_string, dep_mod_id, dep_version, dep_version_id) VALUES (?, ?, ?, ?, ?)");
		$depReport = [];
		foreach (array_unique($deps) as $dep) {
			[$dm, $dv, $dvid] = vibesCatalogResolveDep($pdo, $dep);
			$ins->execute([$vid, $dep, $dm, $dv, $dvid]);
			$depReport[] = ['dep' => $dep, 'resolved' => $dm !== null];
		}
		$latest = vibesCatalogRenumber($pdo, $modId);
		$pdo->prepare("UPDATE hv_projects SET status='published', published_version=? WHERE id=?")->execute([$latest, (int)$project['id']]);
		$pdo->commit();
	} catch (Throwable $e) {
		$pdo->rollBack();
		return ['success' => false, 'error' => 'Catalogue write failed: ' . $e->getMessage()];
	}
	vibesCatalogWriteIndex($pdo);
	return ['success' => true, 'version' => $version, 'zip' => $zipName, 'size' => $size, 'modId' => $modId, 'versionId' => $vid, 'deps' => $depReport, 'error' => null];
}

/** [{version,file,size,published_at,worlds:[...]}], newest first. */
function vibesCatalogVersions(PDO $pdo, $projectId) {
	$p = $pdo->prepare("SELECT namespace, name FROM hv_projects WHERE id=?");
	$p->execute([(int)$projectId]);
	$pr = $p->fetch(PDO::FETCH_ASSOC);
	if (!$pr) return [];
	$q = $pdo->prepare("SELECT v.id, v.version, v.download_url, v.file_size, v.date_created, v.source_rank, m.id AS mod_id
	                      FROM mod_versions v JOIN mods m ON m.id = v.mod_id
	                     WHERE m.source='vibes' AND m.owner=? AND m.name=? ORDER BY v.source_rank");
	$q->execute([$pr['namespace'], $pr['name']]);
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	if (!$rows) return [];
	$modId = (int)$rows[0]['mod_id'];
	// Worlds per version: a pin names a version; "follow latest" (pin NULL) means the newest.
	$pinned = []; $following = [];
	try {
		$w = $pdo->prepare("SELECT w.name, wm.pin_version_id FROM world_mods wm JOIN worlds w ON w.id = wm.world_id WHERE wm.mod_id=? ORDER BY w.name");
		$w->execute([$modId]);
		foreach ($w as $r) { if ($r['pin_version_id'] === null) $following[] = $r['name']; else $pinned[(int)$r['pin_version_id']][] = $r['name']; }
	} catch (Throwable $e) { }
	$out = [];
	foreach ($rows as $r) {
		$worlds = $pinned[(int)$r['id']] ?? [];
		if ((int)$r['source_rank'] === 0) $worlds = array_values(array_unique(array_merge($worlds, $following)));
		$file = strncmp($r['download_url'], 'file://', 7) === 0 ? substr($r['download_url'], 7) : null;
		$out[] = ['version' => $r['version'], 'versionId' => (int)$r['id'], 'file' => $file ? basename($file) : null, 'present' => $file ? is_file($file) : false,
		          'size' => (int)$r['file_size'], 'published_at' => $r['date_created'], 'worlds' => $worlds, 'latest' => (int)$r['source_rank'] === 0];
	}
	return $out;
}

/**
 * Remove one version. Refuses while a world pins it; when it is the last version the mods row
 * goes too, unless a world still lists the mod (then the worlds are named). {success, error, removedMod}.
 */
function vibesCatalogUnpublish(PDO $pdo, array $project, $version) {
	if (!vibesValidVersion((string)$version)) return ['success' => false, 'error' => 'A version x.y.z is required.'];
	$q = $pdo->prepare("SELECT v.id, v.download_url, m.id AS mod_id FROM mod_versions v JOIN mods m ON m.id=v.mod_id WHERE m.source='vibes' AND m.owner=? AND m.name=? AND v.version=?");
	$q->execute([$project['namespace'], $project['name'], $version]);
	$row = $q->fetch(PDO::FETCH_ASSOC);
	if (!$row) return ['success' => false, 'error' => "Version $version is not in the catalogue."];
	$vid = (int)$row['id']; $modId = (int)$row['mod_id'];
	$w = $pdo->prepare("SELECT w.name FROM world_mods wm JOIN worlds w ON w.id=wm.world_id WHERE wm.pin_version_id=? ORDER BY w.name");
	$w->execute([$vid]);
	if ($pins = $w->fetchAll(PDO::FETCH_COLUMN)) return ['success' => false, 'error' => "Version $version is pinned by: " . implode(', ', $pins) . '. Unpin it there first.'];
	$c = $pdo->prepare("SELECT COUNT(*) FROM mod_versions WHERE mod_id=?");
	$c->execute([$modId]);
	$isLast = (int)$c->fetchColumn() <= 1;
	$removedMod = false;
	if ($isLast) {
		$w = $pdo->prepare("SELECT w.name FROM world_mods wm JOIN worlds w ON w.id=wm.world_id WHERE wm.mod_id=? ORDER BY w.name");
		$w->execute([$modId]);
		if ($users = $w->fetchAll(PDO::FETCH_COLUMN)) return ['success' => false, 'error' => "This is the only published version and these worlds still list the mod: " . implode(', ', $users) . '. Remove it from their mod lists first.'];
	}
	$pdo->beginTransaction();
	try {
		$pdo->prepare("DELETE FROM mod_deps WHERE version_id=?")->execute([$vid]);
		$pdo->prepare("DELETE FROM mod_versions WHERE id=?")->execute([$vid]);
		if ($isLast) { $pdo->prepare("DELETE FROM mods WHERE id=?")->execute([$modId]); $removedMod = true; $latest = null; }
		else $latest = vibesCatalogRenumber($pdo, $modId);
		$st = $pdo->prepare("SELECT last_build_ok FROM hv_projects WHERE id=?"); $st->execute([(int)$project['id']]);
		$status = $latest !== null ? 'published' : ((int)$st->fetchColumn() === 1 ? 'built' : 'draft');
		$pdo->prepare("UPDATE hv_projects SET published_version=?, status=? WHERE id=?")->execute([$latest, $status, (int)$project['id']]);
		$pdo->commit();
	} catch (Throwable $e) { $pdo->rollBack(); return ['success' => false, 'error' => 'Catalogue write failed: ' . $e->getMessage()]; }
	if (strncmp($row['download_url'], 'file://' . vibesCatalogDir() . '/', strlen(vibesCatalogDir()) + 8) === 0) @unlink(substr($row['download_url'], 7));
	vibesCatalogWriteIndex($pdo);
	return ['success' => true, 'error' => null, 'removedMod' => $removedMod, 'latest' => $latest];
}

/** catalog/index.json: what is published, for humans and for the uninstall summary. */
function vibesCatalogWriteIndex(PDO $pdo) {
	$out = ['generatedAt' => date('c'), 'mods' => []];
	try {
		$q = $pdo->query("SELECT m.id, m.owner, m.name, m.full_name, m.latest_version, v.version, v.download_url, v.file_size, v.date_created, v.deps
		                    FROM mods m LEFT JOIN mod_versions v ON v.mod_id = m.id WHERE m.source='vibes' ORDER BY m.full_name, v.source_rank");
		foreach ($q as $r) {
			$k = $r['full_name'];
			if (!isset($out['mods'][$k])) $out['mods'][$k] = ['owner' => $r['owner'], 'name' => $r['name'], 'latest' => $r['latest_version'], 'versions' => []];
			if ($r['version'] !== null) $out['mods'][$k]['versions'][] = ['version' => $r['version'], 'file' => basename((string)$r['download_url']), 'size' => (int)$r['file_size'],
			                                                             'published_at' => $r['date_created'], 'dependencies' => json_decode((string)$r['deps'], true) ?: []];
		}
	} catch (Throwable $e) { }
	$out['mods'] = array_values($out['mods']);
	@mkdir(vibesCatalogDir(), 0775, true);
	return vibesWriteJson(vibesCatalogIndexFile(), $out);
}

/** Every world that lists any vibes mod, for the uninstall warning. */
function vibesCatalogWorldsUsingAny(PDO $pdo) { return array_keys(vibesWorldsUsing($pdo)); }

/** Remove every vibes row (uninstall without keep_catalog). Refuses when a world lists one. */
function vibesCatalogWipe(PDO $pdo) {
	$worlds = vibesCatalogWorldsUsingAny($pdo);
	if ($worlds) return ['success' => false, 'error' => 'These worlds still list a Hugin\'s Vibes mod: ' . implode(', ', $worlds) . '. Keep the catalogue, or remove the mods from them first.'];
	$pdo->exec("DELETE d FROM mod_deps d JOIN mod_versions v ON v.id = d.version_id JOIN mods m ON m.id = v.mod_id WHERE m.source='vibes'");
	$pdo->exec("DELETE v FROM mod_versions v JOIN mods m ON m.id = v.mod_id WHERE m.source='vibes'");
	$n = $pdo->exec("DELETE FROM mods WHERE source='vibes'");
	return ['success' => true, 'removed' => (int)$n];
}
