<?php
/**
 * Hugin's Vibes -- the Thunderstore-layout package (design 6.3).
 *
 * dist/<Namespace>-<Name>-<version>.zip contains EXACTLY: manifest.json, README.md, CHANGELOG.md
 * (if present), icon.png, plugins/<Name>.dll and any extra DLL the build produced that is not a
 * framework/game/BepInEx assembly. The engine installs it by unzipping into BepInEx/ exactly as
 * it does a Thunderstore package, so the layout is not ours to vary.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/build.php';

/** DLLs beside the plugin in bin/ that must NOT ship: references with Private=false never copy, but a stray one would break every player. */
function vibesPackageIsForeignDll($name) {
	return preg_match('/^(BepInEx|0Harmony|HarmonyX|Mono\.|MonoMod|assembly_|UnityEngine|Unity\.|netstandard|System\.|System$|mscorlib|Microsoft\.|Splatform)/i', $name) === 1;
}

/** Entries a package of this project must contain, from the manifest; for the layout test and the packer. */
function vibesPackageExpectedEntries($name, $hasChangelog, $extraDlls = []) {
	$e = ['manifest.json', 'README.md', 'icon.png', "plugins/$name.dll"];
	if ($hasChangelog) $e[] = 'CHANGELOG.md';
	foreach ($extraDlls as $d) $e[] = "plugins/$d";
	sort($e);
	return $e;
}

/** Build dist/<Namespace>-<Name>-<ver>.zip. {success, file, size, entries, error}. */
function vibesPackage($slug) {
	$dir = vibesProjectDir($slug);
	if (!is_dir($dir)) return ['success' => false, 'error' => "No project directory for '$slug'."];
	$pj = vibesReadJson("$dir/.vibes/project.json", []);
	$m = vibesProjectManifestRead($slug);
	[$ok, $errors] = vibesManifestValidate($m, $dir);
	if (!$ok) return ['success' => false, 'error' => 'manifest.json is not valid: ' . implode(' ', $errors)];
	$name = $m['name'];
	$ns = (string)($pj['namespace'] ?? '');
	if (!vibesValidNamespace($ns)) return ['success' => false, 'error' => 'The project has no namespace (.vibes/project.json).'];
	$dll = vibesBuildOutputDll($slug, $name);
	if (!$dll) return ['success' => false, 'error' => "No built plugin found (bin/*/net472/$name.dll). Build first."];
	$last = vibesBuildLast($slug);
	if ($last && empty($last['ok'])) return ['success' => false, 'error' => 'The last build failed; package refuses to ship a stale DLL. Build first.'];

	$stage = vibesDownloadsDir() . "/package-$slug-" . getmypid();
	vibesRmTree($stage);
	@mkdir("$stage/plugins", 0775, true);
	$copy = ["$dir/manifest.json" => 'manifest.json', "$dir/README.md" => 'README.md', "$dir/icon.png" => 'icon.png', $dll => "plugins/$name.dll"];
	if (is_file("$dir/CHANGELOG.md")) $copy["$dir/CHANGELOG.md"] = 'CHANGELOG.md';
	$extra = [];
	foreach (glob(dirname($dll) . '/*.dll') ?: [] as $f) {
		$b = basename($f);
		if ($b === "$name.dll" || vibesPackageIsForeignDll($b)) continue;
		$copy[$f] = "plugins/$b"; $extra[] = $b;
	}
	foreach ($copy as $src => $rel) {
		if (!is_file($src)) { vibesRmTree($stage); return ['success' => false, 'error' => "Missing $rel."]; }
		if (!@copy($src, "$stage/$rel")) { vibesRmTree($stage); return ['success' => false, 'error' => "Could not stage $rel."]; }
	}
	@mkdir("$dir/dist", 0775, true);
	$zipName = "$ns-$name-{$m['version_number']}.zip";
	$out = "$dir/dist/$zipName";
	@unlink($out);
	// No ZipArchive in the image (php -m: no zip), so the zip binary. -X drops uid/gid extras,
	// -D skips directory entries so the listing is exactly the files Thunderstore expects.
	$entries = vibesPackageExpectedEntries($name, is_file("$dir/CHANGELOG.md"), $extra);
	$r = vibesRun(array_merge(['/usr/bin/zip', '-q', '-X', '-D', $out], $entries), $stage, null, 120);
	vibesRmTree($stage);
	if (!$r['ok'] || !is_file($out)) return ['success' => false, 'error' => 'zip failed: ' . trim($r['err'] ?: $r['out'])];
	return ['success' => true, 'file' => $out, 'zip' => $zipName, 'size' => filesize($out), 'entries' => $entries, 'version' => $m['version_number'], 'error' => null];
}

/** The entries of a zip, sorted, via `unzip -Z1` (for the layout oracle and publish). */
function vibesZipEntries($file) {
	$r = vibesRun(['/usr/bin/unzip', '-Z1', $file], null, null, 60);
	if (!$r['ok']) return null;
	$e = array_values(array_filter(array_map('trim', explode("\n", $r['out'])), 'strlen'));
	sort($e);
	return $e;
}
