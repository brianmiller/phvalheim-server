<?php
# Oracle: Thunderstore manifest validation (tools/vibes/lib/project.php vibesManifestValidate,
# vibesProjectManifestWrite, vibesPngSize, vibesProjectBumpVersion).
#
# Thunderstore's rules are the engine's rules: the install loop unzips whatever the catalogue
# names, so a bad manifest is discovered by a world, not by us. The validator must refuse every
# shape Thunderstore refuses, and the writer must never let the NAME change (it is the assembly,
# the GUID and the catalogue row).
#
# Usage: php dev_tools/test-vibes-manifest-validate.php

require __DIR__ . '/vibes-test-lib.php';
vibesTestLoad('project');

$good = ['name' => 'StaminaTweaks', 'version_number' => '0.1.0', 'website_url' => '', 'description' => 'Tweaks stamina.', 'dependencies' => ['denikson-BepInExPack_Valheim-5.4.2202']];
[$ok, $e] = vibesManifestValidate($good);
check('a valid manifest passes (no icon check without a dir)', $ok && $e === [], implode(' ', $e));

$cases = [
	'name with a space'         => ['name' => 'Stamina Tweaks'],
	'name with a hyphen'        => ['name' => 'Stamina-Tweaks'],
	'name starting with digit'  => ['name' => '1Mod'],
	'name of one character'     => ['name' => 'A'],
	'missing name'              => ['name' => null],
	'version 1.0'               => ['version_number' => '1.0'],
	'version with suffix'       => ['version_number' => '1.0.0-beta'],
	'version with v'            => ['version_number' => 'v1.0.0'],
	'description 251 chars'     => ['description' => str_repeat('x', 251)],
	'description with newline'  => ['description' => "a\nb"],
	'description not a string'  => ['description' => 5],
	'website_url not a URL'     => ['website_url' => 'example.com'],
	'dependencies missing'      => ['dependencies' => null],
	'dependencies an object'    => ['dependencies' => ['a' => 'b']],
	'dependency without version'=> ['dependencies' => ['denikson-BepInExPack_Valheim']],
	'dependency with space'     => ['dependencies' => ['denikson-BepInEx Pack-5.4.2202']],
	'dependency not a string'   => ['dependencies' => [5]],
];
foreach ($cases as $label => $over) {
	$m = $good;
	foreach ($over as $k => $v) { if ($v === null) unset($m[$k]); else $m[$k] = $v; }
	[$ok, $e] = vibesManifestValidate($m);
	check("refused: $label", !$ok && count($e) >= 1, $ok ? 'accepted' : '');
}
[$ok, $e] = vibesManifestValidate(['name' => 'X_1', 'version_number' => '10.20.30', 'website_url' => 'https://example.com/x', 'description' => str_repeat('d', 250), 'dependencies' => []]);
check('edge: 250-char description, empty deps, underscore name, https url pass', $ok, implode(' ', $e));
[$ok, $e] = vibesManifestValidate(['name' => 'Ok', 'version_number' => '0.0.1', 'description' => 'x', 'dependencies' => ['LVH-IT-UseEquipmentInWater-0.2.4', 'sinai-dev-UnityExplorer-4.9.0']]);
check('edge: hyphenated owners accepted as dependency strings', $ok, implode(' ', $e));
check('not an array -> refused', vibesManifestValidate('nope')[0] === false);

# Icon rules with a project directory
$dir = VIBES_ROOT . '/projects/stamina-tweaks';
@mkdir("$dir/.vibes", 0775, true);
[$ok, $e] = vibesManifestValidate($good, $dir);
check('icon missing -> refused', !$ok && (bool)preg_grep('/icon\.png is missing/', $e));
file_put_contents("$dir/icon.png", 'not a png');
[$ok, $e] = vibesManifestValidate($good, $dir);
check('icon not a PNG -> refused', !$ok && (bool)preg_grep('/not a PNG/', $e));
# A hand-made 128x128 PNG header (signature + IHDR) is enough for the dimension check.
file_put_contents("$dir/icon.png", "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 128) . pack('N', 128) . "\x08\x06\x00\x00\x00" . pack('N', 0));
check('vibesPngSize reads IHDR', vibesPngSize("$dir/icon.png") === [128, 128]);
[$ok, $e] = vibesManifestValidate($good, $dir);
check('icon 128x128 -> refused with both sizes named', !$ok && (bool)preg_grep('/256x256.*128x128/', $e), implode(' ', $e));
copy(VIBES_TOOLS . '/templates/basic-plugin/icon.png', "$dir/icon.png");
check('the shipped template icon IS 256x256', vibesPngSize("$dir/icon.png") === [256, 256]);
[$ok, $e] = vibesManifestValidate($good, $dir);
check('icon 256x256 -> passes', $ok, implode(' ', $e));

# The writer
vibesWriteJson("$dir/.vibes/project.json", ['slug' => 'stamina-tweaks', 'name' => 'StaminaTweaks', 'namespace' => 'Ns']);
vibesWriteJson("$dir/manifest.json", $good);
[$ok, $e] = vibesProjectManifestWrite('stamina-tweaks', ['description' => 'Better.', 'dependencies' => []]);
check('write: partial update merges over the current manifest', $ok && vibesProjectManifestRead('stamina-tweaks')['description'] === 'Better.' && vibesProjectManifestRead('stamina-tweaks')['version_number'] === '0.1.0', implode(' ', $e));
[$ok, $e] = vibesProjectManifestWrite('stamina-tweaks', ['name' => 'Renamed']);
check('write: renaming is refused', !$ok && (bool)preg_grep('/fixed/', $e));
[$ok, $e] = vibesProjectManifestWrite('stamina-tweaks', ['version_number' => '1.2']);
check('write: invalid version refused and file untouched', !$ok && vibesProjectManifestRead('stamina-tweaks')['version_number'] === '0.1.0');
check('write: unknown slug refused', vibesProjectManifestWrite('nope', [])[0] === false);

# Version bump touches all three places
file_put_contents("$dir/StaminaTweaks.csproj", "<Project><PropertyGroup><Version>0.1.0</Version></PropertyGroup></Project>");
@mkdir("$dir/src"); file_put_contents("$dir/src/Plugin.cs", "public const string PluginVersion = \"0.1.0\";\n");
[$ok, $v] = vibesProjectBumpVersion('stamina-tweaks', 'minor');
check('bump minor -> 0.2.0', $ok && $v === '0.2.0', (string)$v);
check('bump: manifest updated', vibesProjectManifestRead('stamina-tweaks')['version_number'] === '0.2.0');
check('bump: csproj <Version> updated', strpos(file_get_contents("$dir/StaminaTweaks.csproj"), '<Version>0.2.0</Version>') !== false);
check('bump: PluginVersion const updated', strpos(file_get_contents("$dir/src/Plugin.cs"), 'PluginVersion = "0.2.0"') !== false);
[$ok, $v] = vibesProjectBumpVersion('stamina-tweaks', '3.4.5');
check('bump explicit x.y.z', $ok && $v === '3.4.5');
[$ok, $v] = vibesProjectBumpVersion('stamina-tweaks', 'huge');
check('bump garbage refused', !$ok);

vibesTestDone();
