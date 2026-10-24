<?php
# Oracle: vibesBuildParseDiagnostics() (tools/vibes/lib/build.php) against REAL dotnet output shapes.
#
# dotnet prints every compiler diagnostic twice -- once as the compiler emits it and once in the
# MSBuild summary with a " [/path/proj.csproj]" suffix -- so a parser that does not deduplicate
# reports 2x the errors and the UI's "3 errors" badge lies. MSBuild/NuGet errors (MSB..., NU...)
# carry no file position at all, and a warning-only build must come out as ok with a count.
#
# Usage: php dev_tools/test-vibes-build-parser.php

require __DIR__ . '/vibes-test-lib.php';
vibesTestLoad('build');

# Fixture 1: a multi-error build, verbatim shape of `dotnet build -v minimal` (paths as the
# runner sees them, inside /opt/stateful/vibes/projects/<slug>/).
$multi = <<<'TXT'
  Determining projects to restore...
  All projects are up-to-date for restore.
/opt/stateful/vibes/projects/stamina-tweaks/src/Plugin.cs(41,9): error CS0103: The name 'Logg' does not exist in the current context [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]
/opt/stateful/vibes/projects/stamina-tweaks/src/Plugin.cs(57,22): error CS1061: 'Player' does not contain a definition for 'GetStaminaX' and no accessible extension method 'GetStaminaX' accepting a first argument of type 'Player' could be found (are you missing a using directive or an assembly reference?) [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]
/opt/stateful/vibes/projects/stamina-tweaks/src/Patches.cs(12,5): warning CS0168: The variable 'e' is declared but never used [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]

Build FAILED.

/opt/stateful/vibes/projects/stamina-tweaks/src/Plugin.cs(41,9): error CS0103: The name 'Logg' does not exist in the current context [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]
/opt/stateful/vibes/projects/stamina-tweaks/src/Plugin.cs(57,22): error CS1061: 'Player' does not contain a definition for 'GetStaminaX' and no accessible extension method 'GetStaminaX' accepting a first argument of type 'Player' could be found (are you missing a using directive or an assembly reference?) [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]
/opt/stateful/vibes/projects/stamina-tweaks/src/Patches.cs(12,5): warning CS0168: The variable 'e' is declared but never used [/opt/stateful/vibes/projects/stamina-tweaks/StaminaTweaks.csproj]
    1 Warning(s)
    2 Error(s)

Time Elapsed 00:00:08.80
TXT;
$d = vibesBuildParseDiagnostics($multi);
$errs = array_values(array_filter($d, fn($x) => $x['severity'] === 'error'));
$warns = array_values(array_filter($d, fn($x) => $x['severity'] === 'warning'));
check('multi-error: 2 errors after dedup (dotnet printed each twice)', count($errs) === 2, count($errs) . ' errors');
check('multi-error: 1 warning after dedup', count($warns) === 1, count($warns) . ' warnings');
check('multi-error: first error fields', $errs && $errs[0]['file'] === 'src/Plugin.cs' && $errs[0]['line'] === 41 && $errs[0]['col'] === 9 && $errs[0]['code'] === 'CS0103' && $errs[0]['message'] === "The name 'Logg' does not exist in the current context", json_encode($errs[0] ?? null));
check('multi-error: path is project-relative, not /opt/stateful/...', !$errs || strpos($errs[0]['file'], '/opt/') === false);
check('multi-error: the [proj.csproj] suffix is stripped from the message', !$errs || strpos($errs[1]['message'], '.csproj') === false);
check('multi-error: long message with parentheses kept whole', ($errs[1]['message'] ?? '') === "'Player' does not contain a definition for 'GetStaminaX' and no accessible extension method 'GetStaminaX' accepting a first argument of type 'Player' could be found (are you missing a using directive or an assembly reference?)");

# Fixture 2: a warning-only build.
$warnOnly = <<<'TXT'
  Determining projects to restore...
  Restored /opt/stateful/vibes/projects/demo/Demo.csproj (in 412 ms).
/opt/stateful/vibes/projects/demo/src/Plugin.cs(30,16): warning CS0414: The field 'Plugin._unused' is assigned but its value is never used [/opt/stateful/vibes/projects/demo/Demo.csproj]
/opt/stateful/vibes/projects/demo/src/Plugin.cs(44,13): warning CS0219: The variable 'x' is assigned but its value is never used [/opt/stateful/vibes/projects/demo/Demo.csproj]
  Demo -> /opt/stateful/vibes/projects/demo/bin/Release/net472/Demo.dll

Build succeeded.

/opt/stateful/vibes/projects/demo/src/Plugin.cs(30,16): warning CS0414: The field 'Plugin._unused' is assigned but its value is never used [/opt/stateful/vibes/projects/demo/Demo.csproj]
/opt/stateful/vibes/projects/demo/src/Plugin.cs(44,13): warning CS0219: The variable 'x' is assigned but its value is never used [/opt/stateful/vibes/projects/demo/Demo.csproj]
    2 Warning(s)
    0 Error(s)
TXT;
$d = vibesBuildParseDiagnostics($warnOnly);
check('warning-only: zero errors', count(array_filter($d, fn($x) => $x['severity'] === 'error')) === 0);
check('warning-only: 2 warnings after dedup', count($d) === 2, count($d) . ' diagnostics');
check('warning-only: the "Demo -> ...dll" output line is not a diagnostic', !array_filter($d, fn($x) => strpos($x['message'], 'Demo.dll') !== false));

# Fixture 3: MSBuild and NuGet errors carry no (line,col) and the tool name is not a file.
$msb = <<<'TXT'
MSBUILD : error MSB1009: Project file does not exist.
Switch: /opt/stateful/vibes/projects/gone/Gone.csproj
/opt/stateful/vibes/projects/demo/Demo.csproj : error NU1101: Unable to find package JotunnLib. No packages exist with this id in source(s): pak [/opt/stateful/vibes/projects/demo/Demo.csproj]
/opt/stateful/vibes/projects/demo/Demo.csproj : error NU1101: Unable to find package JotunnLib. No packages exist with this id in source(s): pak [/opt/stateful/vibes/projects/demo/Demo.csproj]
TXT;
$d = vibesBuildParseDiagnostics($msb);
check('msbuild: MSB1009 parsed with file=null', count(array_filter($d, fn($x) => $x['code'] === 'MSB1009' && $x['file'] === null && $x['line'] === null)) === 1, json_encode($d));
check('msbuild: NU1101 parsed once with the csproj as file', count(array_filter($d, fn($x) => $x['code'] === 'NU1101')) === 1 && array_values(array_filter($d, fn($x) => $x['code'] === 'NU1101'))[0]['file'] === 'Demo.csproj');
check('msbuild: "Switch:" line ignored', count($d) === 2, count($d) . ' diagnostics');

# Fixture 4: nothing to parse.
check('empty output -> empty list', vibesBuildParseDiagnostics('') === []);
check('prose that mentions the word error is not a diagnostic', vibesBuildParseDiagnostics("An error occurred somewhere\nerror: something\n") === []);

vibesTestDone();
