<?php
/**
 * Hugin's Vibes -- harvesting the game's reference assemblies from a world (design 6.2).
 *
 * A mod compiles against the game's own DLLs. We never download them: every world the operator
 * runs has them under game/valheim_server_Data/Managed, so the newest world's set is copied into
 * libs/, assembly_valheim is publicized (private members become compile-time visible -- see
 * knowledge/pitfalls.md for why that is also a trap) and, optionally, decompiled to C# so the
 * agent can grep the real game code instead of guessing an API.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';

const VIBES_WORLDS_DIR = '/opt/stateful/games/valheim/worlds';

/** The Companion's refresh-libs.sh NEEDED list, minus what the pak supplies (BepInEx, 0Harmony) and what we produce (publicized). */
function vibesLibsReferenceSet() {
	return [
		'netstandard.dll', 'Splatform.dll', 'System.Memory.dll', 'System.Runtime.CompilerServices.Unsafe.dll',
		'UnityEngine.AnimationModule.dll', 'UnityEngine.CoreModule.dll', 'UnityEngine.dll', 'UnityEngine.IMGUIModule.dll',
		'UnityEngine.InputLegacyModule.dll', 'UnityEngine.PhysicsModule.dll', 'UnityEngine.TextRenderingModule.dll',
		'UnityEngine.UI.dll', 'UnityEngine.UIModule.dll', 'Unity.TextMeshPro.dll',
		'assembly_guiutils.dll', 'assembly_utils.dll', 'assembly_valheim.dll',
	];
}

function vibesWorldManagedDir($world) { return VIBES_WORLDS_DIR . "/$world/game/valheim_server_Data/Managed"; }

/** Every world that has game files, newest assembly first: [{world, mtime, buildId}]. */
function vibesHarvestCandidates() {
	$out = [];
	foreach (glob(VIBES_WORLDS_DIR . '/*/game/valheim_server_Data/Managed/assembly_valheim.dll') ?: [] as $dll) {
		$world = basename(dirname(dirname(dirname(dirname($dll)))));
		$out[] = ['world' => $world, 'mtime' => filemtime($dll), 'buildId' => vibesWorldBuildId($world)];
	}
	usort($out, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
	return $out;
}

/** Steam's buildid for a world's install, from steamapps/appmanifest_896660.acf; null when absent. */
function vibesWorldBuildId($world) {
	$acf = VIBES_WORLDS_DIR . "/$world/game/steamapps/appmanifest_896660.acf";
	if (!is_file($acf)) return null;
	return preg_match('/"buildid"\s+"(\d+)"/', (string)file_get_contents($acf), $m) ? $m[1] : null;
}

/** The world to harvest from: the setting if it names a world with game files, else the newest. */
function vibesHarvestSourceWorld(array $doc, $explicit = null) {
	$want = trim((string)($explicit ?? vibesSetting($doc, 'libs.source_world', '')));
	if ($want !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $want) && is_file(vibesWorldManagedDir($want) . '/assembly_valheim.dll')) return $want;
	$c = vibesHarvestCandidates();
	return $c ? $c[0]['world'] : null;
}

/**
 * The harvest. $step($label, $progress, $detail). Returns ['success', 'error', 'manifest'].
 * Steps that need a pak tool fail with a sentence naming the tool; the copy step needs none.
 */
function vibesHarvest(array $doc, $step, $explicitWorld = null) {
	$world = vibesHarvestSourceWorld($doc, $explicitWorld);
	if ($world === null) return ['success' => false, 'error' => 'No world has game files yet (no game/valheim_server_Data/Managed/assembly_valheim.dll under any world). Create or update a world first.', 'manifest' => null];
	if ($explicitWorld !== null && $world !== $explicitWorld) return ['success' => false, 'error' => "World '$explicitWorld' has no game files to harvest.", 'manifest' => null];
	$src = vibesWorldManagedDir($world);
	$libs = vibesLibsDir();
	@mkdir($libs, 0775, true);

	$step('Copying game assemblies', 5, "from world '$world'");
	$files = [];
	$want = vibesLibsReferenceSet();
	// Plus every Unity module that exists: a mod that touches audio or image loading needs
	// modules the Companion never did, and copying them costs a few MB.
	foreach (scandir($src) ?: [] as $f) {
		if (preg_match('/^(UnityEngine\..*|Unity\..*|assembly_.*)\.dll$/', $f) && !in_array($f, $want, true)) $want[] = $f;
	}
	$missing = [];
	foreach ($want as $f) {
		if (!is_file("$src/$f")) { if (in_array($f, vibesLibsReferenceSet(), true)) $missing[] = $f; continue; }
		if (!@copy("$src/$f", "$libs/$f")) return ['success' => false, 'error' => "Could not copy $f into libs/.", 'manifest' => null];
		$files[$f] = vibesSha256File("$libs/$f");
	}
	if ($missing) return ['success' => false, 'error' => "World '$world' is missing reference assemblies: " . implode(', ', $missing) . '. Update the world and harvest again.', 'manifest' => null];

	$publicized = false; $publicizerNote = '';
	if ((bool)vibesSetting($doc, 'libs.publicize', true)) {
		$step('Publicizing assembly_valheim', 40, '');
		[$ok, $publicizerNote] = vibesPublicize("$libs/assembly_valheim.dll", "$libs/assembly_valheim_publicized.dll");
		if (!$ok) return ['success' => false, 'error' => $publicizerNote, 'manifest' => null];
		$publicized = true;
		$files['assembly_valheim_publicized.dll'] = vibesSha256File("$libs/assembly_valheim_publicized.dll");
	}

	$decompiled = false; $decompileNote = '';
	if ((bool)vibesSetting($doc, 'libs.decompile', true)) {
		$step('Decompiling the game to C#', 55, 'ilspycmd (this takes a few minutes)');
		[$decompiled, $decompileNote] = vibesDecompile("$libs/assembly_valheim.dll", "$libs/decompiled");
		// A failed decompile is not a failed harvest: builds work without it, game_source does not.
	}

	$step('Indexing game types', 90, '');
	$typesCount = vibesBuildTypesIndex($publicized ? "$libs/assembly_valheim_publicized.dll" : "$libs/assembly_valheim.dll", "$libs/types.txt", $decompiled ? "$libs/decompiled" : null);

	// assembly_valheim.dll carries FileVersion 0.0.0.0 (verified against a real build), so the
	// honest identity of a harvest is Steam's buildid plus the assembly's own hash.
	$manifest = [
		'gameBuild'     => vibesWorldBuildId($world),
		'gameVersion'   => null,
		'assemblySha256'=> $files['assembly_valheim.dll'] ?? null,
		'sourceWorld'   => $world,
		'harvestedAt'   => date('c'),
		'publicized'    => $publicized,
		'publicizer'    => $publicizerNote,
		'decompiled'    => $decompiled,
		'decompileNote' => $decompileNote,
		'typesIndexed'  => $typesCount,
		'files'         => $files,
	];
	vibesWriteJson("$libs/manifest.json", $manifest);
	$step('Harvest complete', 100, count($files) . ' assemblies');
	return ['success' => true, 'error' => null, 'manifest' => $manifest];
}

/**
 * Run the pak's publicizer. The CLI from BepInEx.AssemblyPublicizer.Cli takes `<input> [-o out]`;
 * when -o is rejected by an older build we accept its default `<name>_publicized.dll` beside the
 * input. [ok, note].
 */
function vibesPublicize($in, $out) {
	$bin = vibesPakPublicizer();
	if (!$bin) return [false, 'The Build Pak has no publicizer in tools/ (expected assembly-publicizer from BepInEx.AssemblyPublicizer.Cli). Harvest cannot publicize; update the pak.'];
	$env = vibesPakEnv();
	@unlink($out);
	$r = vibesRun([$bin, $in, '-o', $out], dirname($in), $env, 600);
	if (!$r['ok'] || !is_file($out)) {
		$r2 = vibesRun([$bin, $in], dirname($in), $env, 600);
		$default = dirname($in) . '/' . pathinfo($in, PATHINFO_FILENAME) . '_publicized.dll';
		if ($r2['ok'] && is_file($default) && realpath($default) !== realpath($out)) @rename($default, $out);
		if (!is_file($out)) return [false, 'The publicizer failed: ' . trim(substr(($r['err'] ?: $r['out']) . "\n" . ($r2['err'] ?: $r2['out']), -500))];
	}
	return [true, basename($bin)];
}

/** ilspycmd project-mode decompile into $outDir (replaced). [ok, note]. */
function vibesDecompile($dll, $outDir) {
	$bin = vibesPakBin('ilspycmd');
	if (!$bin) return [false, 'The Build Pak has no tools/ilspycmd; game_source will be unavailable.'];
	vibesRmTree($outDir);
	@mkdir($outDir, 0775, true);
	$r = vibesRun([$bin, '--disable-updatecheck', '-p', '-o', $outDir, '-r', dirname($dll), $dll], dirname($dll), vibesPakEnv(), 2400);
	if (!$r['ok']) { return [false, 'ilspycmd failed: ' . trim(substr($r['err'] ?: $r['out'], -400))]; }
	$n = count(glob("$outDir/*.cs") ?: []) + count(glob("$outDir/*/*.cs") ?: []);
	return [$n > 0, "$n source files"];
}

/**
 * libs/types.txt: one public type per line, for the `game_types` tool. From `ilspycmd -l` when
 * available, else from the decompiled file names (project mode writes one file per top-level
 * type). Returns the count written.
 */
function vibesBuildTypesIndex($dll, $outFile, $decompiledDir) {
	$names = [];
	if ($bin = vibesPakBin('ilspycmd')) {
		// One kind per call: ilspycmd 9 takes `-l c` and lists NOTHING for `-l c,i,s,e,d` (measured:
		// 979 lines vs 0 on the real assembly). --disable-updatecheck keeps its "a newer version is
		// available" nag out of the listing -- the first harvest's types.txt was exactly that nag.
		foreach (['c', 'i', 's', 'e', 'd'] as $kind) {
			$r = vibesRun([$bin, '--disable-updatecheck', '-l', $kind, $dll], dirname($dll), vibesPakEnv(), 600);
			if (!$r['ok']) continue;
			foreach (explode("\n", $r['out']) as $line) {
				$line = trim($line);
				// "Class Player" / "Struct ZDOID" (a space, not a colon). Compiler-generated names
				// (<Module>, <>c__DisplayClass, <Foo>d__12) are noise to a model.
				if (!preg_match('/^(Class|Interface|Struct|Enum|Delegate)\s+(\S.*)$/i', $line, $m)) continue;
				if (strpos($m[2], '<') !== false) continue;
				$names[] = $m[2];
			}
		}
	}
	if (!$names && $decompiledDir && is_dir($decompiledDir)) {
		foreach (glob("$decompiledDir/*.cs") ?: [] as $f) $names[] = basename($f, '.cs');
		foreach (glob("$decompiledDir/*/*.cs") ?: [] as $f) $names[] = basename(dirname($f)) . '.' . basename($f, '.cs');
	}
	$names = array_values(array_unique(array_filter($names, 'strlen')));
	sort($names, SORT_STRING | SORT_FLAG_CASE);
	@file_put_contents($outFile, implode("\n", $names) . ($names ? "\n" : ''));
	return count($names);
}

/** Is a world's assembly newer than the harvest? For the Settings page's "re-harvest" hint. */
function vibesHarvestStale() {
	$m = vibesLibsManifest();
	if ($m === null) return null;
	$c = vibesHarvestCandidates();
	if (!$c) return false;
	$newest = $c[0];
	return isset($m['gameBuild']) && $newest['buildId'] !== null && $newest['buildId'] !== $m['gameBuild'];
}
