<?php
/** Read-only local library summary. No catalogue sync, AI call, or Build Pak download. */
require_once __DIR__ . '/vibes.php';

function phvVibesLibrarySummary($pdo) {
    try {
        $row = $pdo->query('SELECT vibesInstalled FROM settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Settings unavailable');
        $installed = (int)$row['vibesInstalled'] === 1;
        $projects = (int)$pdo->query('SELECT COUNT(*) FROM hv_projects')->fetchColumn();
        $counts = $pdo->query("SELECT COUNT(DISTINCT m.id) AS mods, COUNT(v.id) AS versions
            FROM mods m JOIN mod_versions v ON v.mod_id=m.id AND v.is_active=1 WHERE m.source='vibes'")->fetch(PDO::FETCH_ASSOC);
        $worlds = (int)$pdo->query("SELECT COUNT(DISTINCT wm.world_id) FROM world_mods wm JOIN mods m ON m.id=wm.mod_id WHERE m.source='vibes'")->fetchColumn();
        $jobs = $pdo->query("SELECT kind, status, step, progress FROM hv_jobs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $pak = vibesPakManifest();
        $libs = vibesLibsManifest();
        $pakReady = $pak && in_array((int)($pak['pakFormat'] ?? 0), VIBES_PAK_FORMATS, true) && ($pak['base'] ?? '') === VIBES_PAK_BASE
            && is_executable(vibesPakCurrent() . '/dotnet/dotnet') && is_executable(vibesPakCurrent() . '/git/usr/bin/git');
        $files = is_array($libs['files'] ?? null) ? array_keys($libs['files']) : [];
        $missing = [];
        foreach (array_unique(array_merge(['assembly_valheim.dll'], $files)) as $file) {
            if (!is_string($file) || basename($file) !== $file || !is_readable(vibesLibsDir() . '/' . $file)) $missing[] = $file;
        }
        $libsReady = $libs && $files && !$missing;
        $state = 'ok';
        $detail = 'Local Build Pak and harvested game references are ready. No catalogue sync is required.';
        if (!$installed) $detail = 'Not installed. Retained projects and published mods are kept.';
        elseif ($jobs) { $state = 'unknown'; $detail = 'Hugin is working: ' . $jobs[0]['kind'] . '. Library readiness updates when the job finishes.'; }
        elseif (!$pakReady) { $state = 'warn'; $detail = 'The local Build Pak is missing or incompatible. Open Hugin’s Vibes to repair it.'; }
        elseif (!$libs) { $state = 'unknown'; $detail = 'Build Pak ready. Harvest game libraries in Hugin’s Vibes before building mods.'; }
        elseif (!$libsReady) { $state = 'warn'; $detail = 'Harvested game references are incomplete or unreadable. Re-harvest libraries in Hugin’s Vibes.'; }
        return ['available'=>true, 'installed'=>$installed, 'state'=>$state, 'detail'=>$detail,
            'projects'=>$projects, 'mods'=>(int)$counts['mods'], 'versions'=>(int)$counts['versions'], 'worlds'=>$worlds,
            'pak'=>['ready'=>(bool)$pakReady, 'version'=>$pak['version'] ?? null],
            'libs'=>['ready'=>(bool)$libsReady, 'assemblies'=>count($files), 'gameBuild'=>$libs['gameBuild'] ?? null,
                'sourceWorld'=>$libs['sourceWorld'] ?? null, 'harvestedAt'=>$libs['harvestedAt'] ?? null],
            'jobs'=>$jobs];
    } catch (Throwable $e) {
        return ['available'=>false, 'state'=>'unknown', 'detail'=>'Local library status could not be read. Open Hugin’s Vibes for details.'];
    }
}
