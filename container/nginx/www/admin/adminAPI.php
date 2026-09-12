<?php
/**
 * Admin Dashboard API
 * Provides JSON endpoints for AJAX-powered admin interface
 *
 * NOTE: This is separate from /public/api.php which serves phvalheim-client
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';
# Owns the permittedlist/adminlist/bannedlist files. Absolute path to match db_sets.php's
# include of bosses.php -- a relative include here would redeclare its functions fatally.
require_once '/opt/stateless/nginx/www/includes/accesslists.php';
# The 2.43 multi-source catalogue (mods / mod_versions / world_mods). Absolute +
# require_once for the same reason as accesslists.php above.
require_once '/opt/stateless/nginx/www/includes/modcatalog.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch($action) {
    case 'getWorlds':
        getWorldsJson($pdo);
        break;

    case 'getSystemStats':
        getSystemStatsJson($pdo);
        break;

    case 'getWorldMods':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getSyncStatus':
        getSyncStatusJson($pdo);
        break;

    case 'getWorldSettings':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldSettingsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getWorldStats':
        getWorldStatsJson($pdo);
        break;

    case 'getWorldHealth':
        getWorldHealthJson($pdo);
        break;

    case 'getCitizens':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getCitizensJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveCitizens':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $citizens = $input['citizens'] ?? '';
            $isPublic = isset($input['public']) ? (int)$input['public'] : 0;
            if ($world) {
                saveCitizensJson($pdo, $world, $citizens, $isPublic);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getAdmins':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getAdminsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveAdmins':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $admins = $input['admins'] ?? '';
            if ($world) {
                saveAdminsJson($pdo, $world, $admins);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getBanned':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getBannedJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveBanned':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $banned = $input['banned'] ?? '';
            if ($world) {
                saveBannedJson($pdo, $world, $banned);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getWorldOptions':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldOptionsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveWorldOptions':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            if ($world) {
                saveWorldOptionsJson($pdo, $world, $input);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'fetchSteamID':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $vanityURL = $input['vanityURL'] ?? '';
            if ($vanityURL) {
                fetchSteamIDJson($vanityURL);
            } else {
                echo json_encode(['error' => 'Vanity URL required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getWorldModUUIDs':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModUUIDsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getWorldModsWithNames':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModsWithNamesJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getAllWorlds':
        getAllWorldsJson($pdo);
        break;

    case 'getWorldFolderContents':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldFolderContentsJson($world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'cloneWorldFolders':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $sourceWorld = $input['sourceWorld'] ?? '';
            $targetWorld = $input['targetWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            if ($sourceWorld && $targetWorld) {
                cloneWorldFoldersJson($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins);
            } else {
                echo json_encode(['error' => 'Source and target world names required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getAllModsWithDeps':
        getAllModsWithDepsJson($pdo);
        break;

    case 'getWorldModSelection':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModSelectionJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveWorldMods':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $mods = $input['mods'] ?? [];
            $cloneSource = $input['cloneSourceWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            $modSources = isset($input['modSources']) && is_array($input['modSources'])
                ? $input['modSources'] : null;
            if ($world) {
                saveWorldModsJson($pdo, $world, $mods, $cloneSource, $cloneConfigs,
                                  $clonePlugins, $modSources);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getModVersions':
        $modId = (int)($_GET['modId'] ?? 0);
        if ($modId > 0) {
            getModVersionsJson($pdo, $modId);
        } else {
            echo json_encode(['success' => false, 'error' => 'modId required']);
        }
        break;

    case 'getModSyncLog':
        $logSource = $_GET['source'] ?? '';
        if (!array_key_exists($logSource, catalogSources($pdo))) {
            echo json_encode(['success' => false, 'error' => 'unknown source']);
            break;
        }
        echo json_encode(array_merge(
            ['success' => true, 'source' => $logSource],
            modSyncLog(
                $pdo,
                $logSource,
                (int)($_GET['afterId'] ?? 0),
                ($_GET['detail'] ?? '1') !== '0',
                isset($_GET['runId']) && $_GET['runId'] !== '' ? (int)$_GET['runId'] : null
            )
        ));
        break;

    case 'getModSyncStatus':
        echo json_encode([
            'success' => true,
            'sources' => array_values(catalogSources($pdo)),
            'stats'   => catalogStats($pdo),
            'runs'    => modSyncStatus($pdo),
            'cache'   => modCacheStats()
        ]);
        break;

    case 'createWorld':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $seed = $input['seed'] ?? '';
            $mods = $input['mods'] ?? [];
            $cloneSource = $input['cloneSourceWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            $vanillaOptions = [
                'vanilla'   => isset($input['vanilla'])   ? (int)$input['vanilla']   : 0,
                'password'  => $input['password'] ?? '',
                'crossplay' => isset($input['crossplay']) ? (int)$input['crossplay'] : 0,
                'listed'    => isset($input['listed'])    ? (int)$input['listed']    : 0
            ];
            # The CITIZENS access flag, not Valheim's -public browser argument ('listed').
            # Absent means RESTRICTED: an older client, a script, or a replayed request must
            # never be able to create an open world by simply omitting the field.
            $accessOpen   = isset($input['accessOpen']) ? (int)$input['accessOpen'] : 0;
            $accessFirstId = trim((string)($input['accessFirstId'] ?? ''));

            # Validated HERE and not only in the browser. The form checks this too, for a fast
            # message, but the endpoint is reachable directly -- and a restricted world with an
            # empty list is precisely the state Valheim reads as "anyone may join".
            if (!$accessOpen) {
                if ($accessFirstId === '') {
                    echo json_encode(['error' => 'A restricted world needs at least one player ID. An empty access list lets everyone in rather than nobody.']);
                    break;
                }
                # Validated with canonicalAccessId() rather than a bare-digits regex, so this
                # accepts exactly what the Access tab accepts -- the V_ form the player page
                # now shows, a bare SteamID64, or a console prefix. A digits-only check here
                # would have rejected the very string the form's own example tells you to use.
                $canonicalFirstId = canonicalAccessId($accessFirstId);
                if ($canonicalFirstId === null) {
                    echo json_encode(['error' => "Not a valid player ID: $accessFirstId. Use the V_ form (V_76561197960287930) or a bare 17-digit SteamID64."]);
                    break;
                }
                # Store the canonical form, matching what partitionSteamIds() stores.
                $accessFirstId = $canonicalFirstId;
            }
            $modSources = isset($input['modSources']) && is_array($input['modSources'])
                ? $input['modSources'] : null;
            if ($world) {
                createWorldJson($pdo, $world, $seed, $mods, $cloneSource, $cloneConfigs, $clonePlugins, $vanillaOptions, $accessOpen, $accessFirstId, $modSources);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getAiProviders':
        getAiProvidersJson($aiKeys);
        break;

    case 'aiHelper':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $message  = trim($input['message'] ?? '');
            $history  = $input['history'] ?? [];
            $context  = $input['context'] ?? 'none';
            $world    = $input['world'] ?? '';
            $provider = $input['provider'] ?? '';
            $model    = $input['model'] ?? '';
            if (!$message) { echo json_encode(['success'=>false,'error'=>'No message']); break; }
            aiHelperDispatch($aiKeys, $provider, $model, $message, $history, $context, $world);
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'worldAction':
        $world = $_GET['world'] ?? '';
        $cmd = $_GET['cmd'] ?? '';
        if ($world && in_array($cmd, ['start', 'stop', 'update', 'delete'])) {
            switch ($cmd) {
                case 'start':  startWorld($pdo, $world); break;
                case 'stop':   stopWorld($pdo, $world); break;
                case 'update': updateWorld($pdo, $world); break;
                case 'delete': deleteWorld($pdo, $world); break;
            }
            echo json_encode(['success' => true, 'world' => $world, 'action' => $cmd]);
        } else {
            echo json_encode(['success' => false, 'error' => 'World name and valid cmd (start/stop/update/delete) required']);
        }
        break;

    case 'getServerSettings':
        getServerSettingsJson($pdo);
        break;

    case 'saveServerSettings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                saveServerSettingsJson($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'completeSetup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                completeSetupJson($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'dismissMigrationNotice':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dismissMigrationNoticeJson($pdo);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'dismissWhatsNew':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dismissWhatsNewJson($pdo, $phvalheimVersion);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'dismissAccessIdNotice':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dismissAccessIdNoticeJson($pdo);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'dismissAccessSwitchNotice':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dismissAccessSwitchNoticeJson($pdo);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'getBackupDiskStats':
        $mounted = isBackupPathMounted();
        $info = getBackupDiskInfo();
        $count = getTotalBackupCount($pdo);
        $totalSize = getTotalBackupSize($pdo);
        echo json_encode([
            'success' => true,
            'mounted' => $mounted,
            'total' => $info['total'],
            'used' => $info['used'],
            'free' => $info['free'],
            'perc' => $info['perc'],
            'backupCount' => $count,
            'backupTotalSize' => $totalSize,
        ]);
        break;

    case 'getVolumeStats':
        $volumes = getVolumeStats();
        $backupCount = getTotalBackupCount($pdo);
        $backupTotalSize = getTotalBackupSize($pdo);
        $orphanedCount = getOrphanedBackupCount($pdo);
        echo json_encode([
            'success' => true,
            'volumes' => $volumes,
            'backupCount' => $backupCount,
            'backupTotalSize' => $backupTotalSize,
            'backupMounted' => isBackupPathMounted(),
            'orphanedCount' => $orphanedCount,
        ]);
        break;

    case 'getBackupPreflight':
        $world = $_GET['world'] ?? '';
        if ($world) {
            $mounted = isBackupPathMounted();
            $worldSize = getWorldDirSize($world);
            $freeBytes = getBackupDiskFreeBytes();
            $worldMode = getWorldMode($pdo, $world);
            $transitional = isWorldTransitional($worldMode);
            echo json_encode([
                'success' => true,
                'mounted' => $mounted,
                'worldSize' => $worldSize,
                'freeBytes' => $freeBytes,
                'worldMode' => $worldMode,
                'transitional' => $transitional,
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'World name required']);
        }
        break;

    case 'getWorldBackups':
        $world = $_GET['world'] ?? '';
        if ($world) {
            $backups = getWorldBackups($pdo, $world);
            echo json_encode(['success' => true, 'backups' => $backups]);
        } else {
            echo json_encode(['success' => false, 'error' => 'World name required']);
        }
        break;

    case 'createManualBackup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $compression = $input['compression'] ?? '';
            if (!in_array($compression, ['none', 'gzip', 'zstd'])) $compression = '';
            if ($world) {
                // Launch backup in background, write progress to temp file for polling
                $jobId = bin2hex(random_bytes(8));
                $progressFile = "/tmp/phv_job_{$jobId}.log";
                $doneFile = "/tmp/phv_job_{$jobId}.done";
                touch($progressFile);
                $compArg = $compression ? ' ' . escapeshellarg($compression) : '';
                $cmd = "/opt/stateless/engine/tools/worldBackup " . escapeshellarg($world) . " manual" . $compArg;
                // Run fully detached: redirect all FDs so PHP doesn't wait
                $shell = "nohup bash -c '($cmd) > " . escapeshellarg($progressFile) . " 2>&1; echo \$? > " . escapeshellarg($doneFile) . "' > /dev/null 2>&1 < /dev/null &";
                exec($shell);
                echo json_encode(['success' => true, 'jobId' => $jobId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'World name required']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'restoreBackup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $backupId = (int)($input['backupId'] ?? 0);
            if ($backupId > 0) {
                // Launch restore in background, write progress to temp file for polling
                $jobId = bin2hex(random_bytes(8));
                $progressFile = "/tmp/phv_job_{$jobId}.log";
                $doneFile = "/tmp/phv_job_{$jobId}.done";
                touch($progressFile);
                $cmd = "/opt/stateless/engine/tools/worldRestore " . escapeshellarg($backupId);
                // Run fully detached: redirect all FDs so PHP doesn't wait
                $shell = "nohup bash -c '($cmd) > " . escapeshellarg($progressFile) . " 2>&1; echo \$? > " . escapeshellarg($doneFile) . "' > /dev/null 2>&1 < /dev/null &";
                exec($shell);
                echo json_encode(['success' => true, 'jobId' => $jobId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Valid backup ID required']);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'getJobProgress':
        $jobId = preg_replace('/[^a-f0-9]/', '', $_GET['jobId'] ?? '');
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        if (!$jobId) {
            echo json_encode(['error' => 'Job ID required']);
            break;
        }
        $progressFile = "/tmp/phv_job_{$jobId}.log";
        $doneFile = "/tmp/phv_job_{$jobId}.done";
        if (!file_exists($progressFile)) {
            echo json_encode(['error' => 'Job not found']);
            break;
        }
        // Read new lines from the progress file starting at offset
        $lines = [];
        $fp = fopen($progressFile, 'r');
        if ($fp) {
            fseek($fp, $offset);
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed !== '') $lines[] = $trimmed;
            }
            $newOffset = ftell($fp);
            fclose($fp);
        } else {
            $newOffset = $offset;
        }
        $done = file_exists($doneFile);
        $exitCode = $done ? (int)trim(file_get_contents($doneFile)) : null;
        $result = ['lines' => $lines, 'offset' => $newOffset, 'done' => $done];
        if ($done) {
            $result['exitCode'] = $exitCode;
            // Clean up temp files
            @unlink($progressFile);
            @unlink($doneFile);
        }
        echo json_encode($result);
        break;

    case 'deleteBackup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $backupId = (int)($input['backupId'] ?? 0);
            if ($backupId > 0) {
                $backup = getBackupById($pdo, $backupId);
                if ($backup) {
                    if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                        unlink($backup['file_path']);
                    }
                    deleteBackupRecord($pdo, $backupId);
                    echo json_encode(['success' => true, 'message' => 'Backup deleted']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Backup not found']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Valid backup ID required']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'deleteBackups':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $backupIds = $input['backupIds'] ?? [];
            if (!empty($backupIds) && is_array($backupIds)) {
                $deleted = 0;
                foreach ($backupIds as $bid) {
                    $bid = (int)$bid;
                    if ($bid <= 0) continue;
                    $backup = getBackupById($pdo, $bid);
                    if ($backup) {
                        if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                            unlink($backup['file_path']);
                        }
                        deleteBackupRecord($pdo, $bid);
                        $deleted++;
                    }
                }
                echo json_encode(['success' => true, 'deleted' => $deleted]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Backup IDs array required']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'downloadBackup':
        $backupId = (int)($_GET['backupId'] ?? 0);
        if ($backupId > 0) {
            $backup = getBackupById($pdo, $backupId);
            if ($backup && !empty($backup['file_path']) && file_exists($backup['file_path'])) {
                $filePath = $backup['file_path'];
                $fileName = basename($filePath);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                header('Content-Length: ' . filesize($filePath));
                header('Cache-Control: no-cache, must-revalidate');
                readfile($filePath);
                exit;
            } else {
                echo json_encode(['success' => false, 'error' => 'Backup file not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Valid backup ID required']);
        }
        break;

    case 'getWorldBackupSettings':
        $world = $_GET['world'] ?? '';
        if ($world) {
            $settings = getWorldBackupSettings($pdo, $world);
            if ($settings) {
                echo json_encode(['success' => true, 'settings' => $settings]);
            } else {
                echo json_encode(['success' => false, 'error' => 'World not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'World name required']);
        }
        break;

    case 'saveWorldBackupSettings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $settings = $input['settings'] ?? [];
            if ($world && !empty($settings)) {
                $result = saveWorldBackupSettings($pdo, $world, $settings);
                echo json_encode(['success' => $result ? true : false]);
            } else {
                echo json_encode(['success' => false, 'error' => 'World name and settings required']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'reconcileBackups':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $output = trim(shell_exec('/opt/stateless/engine/tools/worldBackupReconcile --json 2>&1'));
            $result = json_decode($output, true);
            if ($result && isset($result['success'])) {
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'error' => 'Reconcile failed', 'raw' => $output]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'purgeOrphanedBackups':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $purged = purgeOrphanedBackups($pdo);
            echo json_encode(['success' => true, 'purged' => $purged]);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'getOrphanedBackupCount':
        $count = getOrphanedBackupCount($pdo);
        echo json_encode(['success' => true, 'count' => $count]);
        break;

    default:
        // Preserve original behavior for backwards compatibility
        echo "true";
}

/**
 * Returns all worlds with their current status
 */
function getWorldsJson($pdo) {
    global $gameDNS, $phvalheimHost;

    // HTTP(S) detector
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
        $httpScheme = "https";
    } else {
        $httpScheme = "http";
    }

    $stmt = $pdo->query("SELECT status, mode, name, port, external_endpoint, seed, autostart, beta, date_updated, IFNULL(vanilla,0) AS vanilla, password FROM worlds ORDER BY name");
    $worlds = [];

    foreach ($stmt as $row) {
        // Same positional contract as getLaunchString() in db_gets.php -- keep the two
        // in step, and only ever append fields.
        $vanilla = (int)$row['vanilla'];
        $password = $vanilla ? ($row['password'] ?: "") : "hammertime";
        $launchString = base64_encode("launch?{$row['name']}?$password?$gameDNS?{$row['port']}?$phvalheimHost?$httpScheme?$vanilla");

        // Shared with the dashboard's PHP render and the public card, so a crossplay world
        // gets -joincode rather than a +connect that can never reach a PlayFab server.
        $isRunning = ($row['mode'] === 'running');
        $joinInfo = $vanilla
            ? getVanillaJoinInfo($pdo, $row['name'], $gameDNS, $row['port'], $isRunning)
            : ['href' => 'phvalheim://?' . $launchString, 'playfab' => false, 'joinCode' => NULL];

        $worlds[] = [
            'name' => $row['name'],
            'status' => $row['status'],
            'mode' => $row['mode'],
            'port' => $row['port'],
            'endpoint' => $row['external_endpoint'],
            'seed' => $row['seed'],
            'autostart' => (int)$row['autostart'],
            'beta' => (int)$row['beta'],
            'vanilla' => $vanilla,
            'modCount' => getTotalModCountOfWorld($pdo, $row['name']),
            'launchString' => $launchString,
            // MUST stay in step with getWorldsData() in index.php: the dashboard renders
            // Launch from PHP on load and then re-renders it from this payload on every
            // poll. If only one of them knows about vanilla worlds, the button is correct
            // on load and wrong a few seconds later. Both now call getVanillaJoinInfo().
            'launchHref' => $joinInfo['href'],
            'launchPlayfab' => $joinInfo['playfab'],
            'launchJoinCode' => $joinInfo['joinCode'],
            // Same contract note as launchHref above: the dashboard renders this badge from PHP
            // on load and from this payload on every poll. Saving an option has to light it
            // within a poll, and restarting the world has to clear it.
            'restartPending' => worldRestartPending($pdo, $row['name'], $isRunning),
            'dateUpdated' => $row['date_updated']
        ];
    }

    echo json_encode([
        'success' => true,
        'worlds' => $worlds,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns system resource statistics
 */
function getSystemStatsJson($pdo) {
    // Get memory percentage (used/total * 100)
    $memUsedRaw = trim(exec("free | grep Mem: | tr -s ' ' | cut -d ' ' -f3"));
    $memTotalRaw = trim(exec("free | grep Mem: | tr -s ' ' | cut -d ' ' -f2"));
    $memPercent = ($memTotalRaw > 0) ? round(($memUsedRaw / $memTotalRaw) * 100, 1) : 0;

    // Get CPU utilization directly using mpstat for real-time data
    $cpuIdle = trim(exec("mpstat 1 1 2>/dev/null | tail -1 | awk '{print \$NF}'"));
    if (is_numeric($cpuIdle)) {
        $cpuPercent = round(100 - (float)$cpuIdle, 1);
    } else {
        // Fallback to /proc/stat calculation
        $stat1 = file_get_contents('/proc/stat');
        usleep(100000); // 100ms
        $stat2 = file_get_contents('/proc/stat');

        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1);
        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2);

        if ($m1 && $m2) {
            $idle1 = $m1[4];
            $idle2 = $m2[4];
            $total1 = $m1[1] + $m1[2] + $m1[3] + $m1[4];
            $total2 = $m2[1] + $m2[2] + $m2[3] + $m2[4];
            $idleDiff = $idle2 - $idle1;
            $totalDiff = $total2 - $total1;
            $cpuPercent = ($totalDiff > 0) ? round((1 - $idleDiff / $totalDiff) * 100, 1) : 0;
        } else {
            $cpuPercent = 0;
        }
    }

    $cpuUtilization = $cpuPercent . '%';

    echo json_encode([
        'success' => true,
        'memory' => [
            'total' => getTotalMemory(),
            'used' => getUsedMemory(),
            'free' => getFreeMemory(),
            'percent' => $memPercent
        ],
        'disk' => [
            'total' => getTotalDisk('/opt/stateful'),
            'used' => getUsedDisk('/opt/stateful'),
            'free' => getFreeDisk('/opt/stateful'),
            'percent' => getUsedDiskPerc('/opt/stateful')
        ],
        'cpu' => [
            'model' => getCpuModel($pdo),
            'utilization' => $cpuUtilization,
            'percent' => $cpuPercent
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns mods list for a specific world
 */
function getWorldModsJson($pdo, $world) {
    $modsJson = getModViewerJsonForWorld($pdo, $world);
    $mods = json_decode($modsJson, true) ?? [];

    // Filter out placeholder entries
    $mods = array_filter($mods, function($mod) {
        return isset($mod['uuid']) && $mod['uuid'] !== 'placeholder' && !empty($mod['uuid']);
    });

    // Sort by name
    usort($mods, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    echo json_encode([
        'success' => true,
        'world' => $world,
        'mods' => array_values($mods),
        'count' => count($mods)
    ]);
}

/**
 * Returns settings for a specific world
 */
function getWorldSettingsJson($pdo, $world) {
    // Get autostart, endpoint, and port
    $stmt = $pdo->prepare("SELECT autostart, external_endpoint, port FROM worlds WHERE name = ?");
    $stmt->execute([$world]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $autostart = (int)($row['autostart'] ?? 0);
    $endpoint = $row['external_endpoint'] ?? '';
    $port = $row['port'] ?? '';

    echo json_encode([
        'success' => true,
        'world' => $world,
        'seed' => getSeed($pdo, $world),
        'md5' => getMd5($pdo, $world),
        'endpoint' => $endpoint,
        'port' => $port,
        'dateDeployed' => getDateDeployed($pdo, $world),
        'dateUpdated' => getDateUpdated($pdo, $world),
        'hideSeed' => GetHideSeed($pdo, $world),
        'autostart' => $autostart
    ]);
}

/**
 * Returns sync and maintenance status
 */
/**
 * Sync and maintenance status for the non-catalogue jobs.
 *
 * The `thunderstore` block is gone: it read systemstats columns the pre-2.43 bash sync
 * wrote, which nothing updates any more, so it reported a frozen timestamp and a permanent
 * "idle" forever. Catalogue state comes from `getModSyncStatus` (mod_sync_runs) instead.
 */
function getSyncStatusJson($pdo) {
    echo json_encode([
        'success' => true,
        'worldBackup' => [
            'time' => getLastWorldBackupExecTime($pdo),
            'status' => getLastWorldBackupExecStatus($pdo)
        ],
        'logRotate' => [
            'time' => getLastLogRotateExecTime($pdo),
            'status' => getLastLogRotateExecStatus($pdo)
        ],
        'utilization' => [
            'time' => getLastUtilizationMonitorExecTime($pdo),
            'status' => getLastUtilizationMonitorExecStatus($pdo)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
/**
 * Returns resource stats for each running world
 */
function getWorldStatsJson($pdo) {
    $stmt = $pdo->query("SELECT name, mode FROM worlds WHERE mode = 'running' ORDER BY name");
    $stats = [];

    foreach ($stmt as $row) {
        $worldName = $row['name'];

        // Get the PID of the valheim_server process for this world
        $pid = trim(exec("pgrep -f 'valheim_server.*-world $worldName' 2>/dev/null | head -1"));

        if (!empty($pid) && is_numeric($pid)) {
            // Calculate real-time CPU usage using /proc/[pid]/stat
            // Read process stat twice with a small interval
            $stat1 = @file_get_contents("/proc/$pid/stat");
            $cpuInfo1 = @file_get_contents('/proc/stat');
            usleep(100000); // 100ms
            $stat2 = @file_get_contents("/proc/$pid/stat");
            $cpuInfo2 = @file_get_contents('/proc/stat');

            $cpu = 0;
            if ($stat1 && $stat2 && $cpuInfo1 && $cpuInfo2) {
                // Parse process CPU times (utime + stime)
                $parts1 = explode(' ', $stat1);
                $parts2 = explode(' ', $stat2);
                if (count($parts1) > 14 && count($parts2) > 14) {
                    $procTime1 = (int)$parts1[13] + (int)$parts1[14]; // utime + stime
                    $procTime2 = (int)$parts2[13] + (int)$parts2[14];

                    // Parse total CPU time
                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuInfo1, $m1);
                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuInfo2, $m2);

                    if ($m1 && $m2) {
                        $total1 = array_sum(array_slice($m1, 1));
                        $total2 = array_sum(array_slice($m2, 1));
                        $totalDiff = $total2 - $total1;
                        $procDiff = $procTime2 - $procTime1;

                        if ($totalDiff > 0) {
                            // Get number of CPU cores for accurate percentage
                            $numCores = (int)trim(exec("nproc 2>/dev/null")) ?: 1;
                            $cpu = round(($procDiff / $totalDiff) * 100 * $numCores, 1);
                            // Cap at 100%
                            $cpu = min($cpu, 100);
                        }
                    }
                }
            }

            // Get memory percentage and RSS
            $memOutput = trim(exec("ps -p $pid -o %mem,rss --no-headers 2>/dev/null"));
            $memParts = preg_split('/\s+/', trim($memOutput));
            $mem = isset($memParts[0]) ? round((float)$memParts[0], 1) : 0;
            $rss = isset($memParts[1]) && is_numeric($memParts[1]) ? round((int)$memParts[1] / 1024) : 0;

            $stats[] = [
                'name' => $worldName,
                'cpu' => $cpu,
                'mem' => $mem,
                'memFormatted' => $rss . 'M'
            ];
        } else {
            // World is marked as running but no process found
            $stats[] = [
                'name' => $worldName,
                'cpu' => 0,
                'mem' => 0,
                'memFormatted' => '—'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns tick health data from PhValheim.TickMonitor plugin
 */
function getWorldHealthJson($pdo) {
    $stmt = $pdo->query("SELECT name FROM worlds WHERE mode = 'running' ORDER BY name");
    $results = [];

    foreach ($stmt as $row) {
        $worldName = $row['name'];
        $path = "/opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/data/PhValheim.TickMonitor/tick_stats.json";

        // Only return data if file exists and is fresh (< 30 seconds old)
        if (file_exists($path) && (time() - filemtime($path)) < 30) {
            $data = json_decode(file_get_contents($path), true);
            if ($data) {
                $results[$worldName] = $data;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'health' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns citizens list for a specific world
 */
function getCitizensJson($pdo, $world) {
    $citizensStr = getCitizens($pdo, $world);
    $isPublic = getPublic($pdo, $world);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'citizens' => $citizensStr ?: '',
        'public' => (int)$isPublic
    ]);
}

/**
 * Save citizens for a specific world
 *
 * A public world writes an EMPTY permittedlist, which is Valheim's "everyone may join".
 * The citizens are still stored, so turning public back off restores the previous list.
 */
function saveCitizensJson($pdo, $world, $citizens, $isPublic) {
    $citizens = normaliseIdList($citizens);

    list($valid, $rejected) = partitionSteamIds($citizens);
    if (!empty($rejected)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Not a valid player ID: ' . implode(', ', $rejected) . '. Use the V_ form (V_76561197960287930) or a bare 17-digit SteamID64, which is upgraded automatically.'
        ]);
        return;
    }
    $citizens = implode(' ', $valid);

    # An ENFORCED but EMPTY list is not a restriction -- it is an open server wearing a lock.
    # Valheim only applies permittedlist.txt when it has entries, so writing an empty one lets
    # anyone in while the Access tab reads "Use Access List: on". Observed on production: a
    # world with public=0 and no citizens, which a player joined without being on any list.
    #
    # Refused rather than silently corrected: the two sane intents ("let anyone in" and "let
    # these people in") are both one click away, and guessing which one was meant is how a
    # server ends up open when its owner believed otherwise.
    #
    # A placeholder entry was briefly written at render time to make this state fail closed,
    # which made the wording below temporarily false. That placeholder was dropped, so an
    # enforced-but-empty list is once again genuinely OPEN and the original wording is correct.
    # This is a SECURITY guard, not a lockout one -- with nothing else standing between an
    # empty list and an open server, it must not be relaxed to a confirmation.
    if (!$isPublic && $citizens === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'The access list is empty, so it would let everyone in rather than nobody. Add at least one player ID, or switch "Use Access List" off to open the world deliberately.'
        ]);
        return;
    }

    setCitizens($pdo, $world, $citizens);
    setPublic($pdo, $world, $isPublic);

    $result = writeAccessList($world, 'citizens', $isPublic ? '' : $citizens);
    if (!$result['ok']) {
        echo json_encode([
            'success' => false,
            'error'   => 'Saved to the database, but permittedlist.txt could not be written: ' . $result['error']
        ]);
        return;
    }

    # Valheim re-reads these files while running -- verified on a live server, where a player
    # rejected at 18:39 was admitted at 18:41 with no restart in between. No restart prompt.
    echo json_encode([
        'success' => true,
        'message' => 'Citizens saved.'
    ]);
}

/**
 * Returns the admin list for a specific world
 */
function getAdminsJson($pdo, $world) {
    $adminsStr = getAdmins($pdo, $world);

    echo json_encode([
        'success' => true,
        'admins'  => $adminsStr ?: ''
    ]);
}

/**
 * Save the admin list for a specific world.
 *
 * Mirrors saveCitizensJson(), but writes adminlist.txt instead of permittedlist.txt.
 * Valheim reads adminlist.txt at world start, so changes need a world restart.
 */
function saveAdminsJson($pdo, $world, $admins) {
    saveAccessListJson($pdo, $world, 'admins', $admins);
}

/**
 * Returns the banned list for a specific world
 */
function getBannedJson($pdo, $world) {
    $bannedStr = getBanned($pdo, $world);

    echo json_encode([
        'success' => true,
        'banned'  => $bannedStr ?: ''
    ]);
}

/**
 * Save the banned list for a specific world
 */
function saveBannedJson($pdo, $world, $banned) {
    saveAccessListJson($pdo, $world, 'banned', $banned);
}

/**
 * Shared save path for the admin and banned lists.
 *
 * Citizens keeps its own function only because it also carries the `public` toggle.
 *
 * SteamID64 only. Anything else in these files is silently ignored by Valheim, which
 * from the operator's side looks exactly like "I added them and it did nothing".
 */
function saveAccessListJson($pdo, $world, $kind, $raw) {
    global $PHVALHEIM_ACCESS_LISTS;
    $label = $PHVALHEIM_ACCESS_LISTS[$kind]['label'];
    $file  = $PHVALHEIM_ACCESS_LISTS[$kind]['file'];

    list($valid, $rejected) = partitionSteamIds(normaliseIdList($raw));
    if (!empty($rejected)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Not a valid player ID: ' . implode(', ', $rejected) . '. Use the V_ form (V_76561197960287930) or a bare 17-digit SteamID64, which is upgraded automatically.'
        ]);
        return;
    }

    $ids = implode(' ', $valid);
    if ($kind === 'admins') {
        setAdmins($pdo, $world, $ids);
    } else {
        setBanned($pdo, $world, $ids);
    }

    $result = writeAccessList($world, $kind, $ids);
    if (!$result['ok']) {
        echo json_encode([
            'success' => false,
            'error'   => "Saved to the database, but $file could not be written: " . $result['error']
        ]);
        return;
    }

    # Valheim re-reads these files while running, so no restart is needed. The one caveat is
    # admin status, which a connected client caches from the list pushed to it on connect.
    $note = ($kind === 'admins')
        ? ' Players already connected keep their previous admin status until they reconnect.'
        : '';

    echo json_encode([
        'success' => true,
        'message' => "$label saved.$note"
    ]);
}

/**
 * Per-world options: vanilla flag, password, crossplay, server browser listing,
 * and custom launch parameters.
 */
function getWorldOptionsJson($pdo, $world) {
    $passwordPublic = getPasswordPublic($pdo, $world);
    echo json_encode([
        'success'        => true,
        'vanilla'        => (int)getVanilla($pdo, $world),
        'password'       => getWorldPassword($pdo, $world) ?: '',
        'crossplay'      => (int)getCrossplay($pdo, $world),
        'listed'         => (int)getListed($pdo, $world),
        // NULL for a row that predates the column -- default to visible, matching
        // the column default, or the toggle would read as "off" on every old world.
        'passwordPublic' => ($passwordPublic === NULL || $passwordPublic === false) ? 1 : (int)$passwordPublic,
        'launchParams'   => getLaunchParams($pdo, $world) ?: ''
    ]);
}

/**
 * Validate a Valheim server password.
 *
 * These are Valheim's own rules -- break any of them and the server refuses to boot,
 * which surfaces to the operator as a world that restart-loops with the reason buried
 * in the world log. Rejecting here makes it a form error instead.
 */
function validateWorldPassword($password, $world) {
    if ($password === '') {
        return NULL;
    }
    if (strlen($password) < 5) {
        return 'Password must be at least 5 characters.';
    }
    if (stripos($world, $password) !== false) {
        return 'Password cannot be part of the world name.';
    }
    return NULL;
}

/**
 * Validate custom launch parameters.
 *
 * startWorld.sh splits these with globbing disabled and never evals them, so a shell
 * metacharacter cannot execute. We still reject them: they can only ever be a mistake
 * here, and letting them through would leave the safety resting entirely on one line
 * of shell staying correct forever.
 */
function validateLaunchParams($params) {
    if ($params === '') {
        return NULL;
    }
    if (preg_match('/[;&|`$<>\n\r]/', $params)) {
        return 'Launch parameters cannot contain shell metacharacters ( ; & | ` $ < > ).';
    }
    if (strlen($params) > 512) {
        return 'Launch parameters are limited to 512 characters.';
    }
    return NULL;
}

function saveWorldOptionsJson($pdo, $world, $input) {
    $vanilla        = isset($input['vanilla'])        ? (int)$input['vanilla']        : 0;
    $crossplay      = isset($input['crossplay'])      ? (int)$input['crossplay']      : 0;
    $listed         = isset($input['listed'])         ? (int)$input['listed']         : 0;
    $passwordPublic = isset($input['passwordPublic']) ? (int)$input['passwordPublic'] : 1;
    $password       = trim($input['password'] ?? '');
    $launchParams   = trim($input['launchParams'] ?? '');

    if ($err = validateWorldPassword($password, $world)) {
        echo json_encode(['success' => false, 'error' => $err]);
        return;
    }
    if ($err = validateLaunchParams($launchParams)) {
        echo json_encode(['success' => false, 'error' => $err]);
        return;
    }

    // Valheim requires a password on a server that is listed in the browser. Catch it
    // here so the operator sees why, rather than at boot.
    if ($listed && $password === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'A world listed in the server browser must have a password.'
        ]);
        return;
    }

    // Password and server-browser listing are vanilla-only -- modded worlds are gated by
    // the CITIZENS list instead. Storing them for a modded world would show settings in
    // the UI that startWorld.sh deliberately ignores.
    //
    // Crossplay is in that group TOO, for now. It makes Valheim open a PlayFab server, which
    // has no host:port -- and the PhValheim client reaches a modded world through QuickConnect,
    // whose config is host:port. So a modded crossplay world is unreachable by the client.
    // Forced off here as well as hidden in the UI, because the endpoint is reachable directly.
    // Revisit when the client can launch with -joincode.
    if (!$vanilla) {
        $password = '';
        $listed = 0;
        $crossplay = 0;
    }

    $wasVanilla = (int)getVanilla($pdo, $world);

    setVanilla($pdo, $world, $vanilla);
    setWorldPassword($pdo, $world, $password);
    setCrossplay($pdo, $world, $crossplay);
    setListed($pdo, $world, $listed);
    setPasswordPublic($pdo, $world, $passwordPublic);
    setLaunchParams($pdo, $world, $launchParams);

    // Switching a modded world to vanilla means ZERO mods. Clear the selection here as
    // well as gating the UI -- otherwise the mods stay in the database, the Mods column
    // keeps showing them, and flipping back later silently resurrects a mod list the
    // operator thinks they removed.
    // Says where the reminder now lives. The old wording told you a restart was needed and then
    // vanished with the dialog, while the public card had already started advertising the new
    // setting -- so the only lasting evidence pointed the wrong way.
    $message = 'World options saved. They take effect when the world restarts; until then it is '
             . 'marked "restart pending" in the Worlds table and players still see its current settings.';
    if ($vanilla && !$wasVanilla) {
        deleteAllWorldMods($pdo, $world);
        $message = 'World is now vanilla. Its mod selection has been cleared — run an Update to rebuild it without mods.';
    } elseif (!$vanilla && $wasVanilla) {
        $message = 'World is now modded. Use Edit Mods to choose mods, then run an Update.';
    }

    echo json_encode([
        'success' => true,
        'vanilla' => $vanilla,
        'message' => $message
    ]);
}

/**
 * Fetch SteamID from vanity URL
 */
function fetchSteamIDJson($vanityURL) {
    global $steamAPIKey;
    $apiKey = $steamAPIKey;
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'Steam API key not configured']);
        return;
    }

    $url = "https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?key=$apiKey&vanityurl=" . urlencode($vanityURL);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Failed to contact Steam API']);
        return;
    }

    $data = json_decode($response, true);

    if (!isset($data['response']) || $data['response']['success'] != 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid username or private profile']);
        return;
    }

    $steamid = $data['response']['steamid'];

    # Valheim 1.0 matches on the PlatformUserID display form, so the id an operator is
    # about to paste into an access list has to carry the V_ prefix. `steamid` stays the
    # BARE id for anything that wants the raw Steam value; `accessId` is what belongs in
    # permittedlist/adminlist/bannedlist. Derived with canonicalAccessId() rather than
    # string-concatenating 'V_' here, so there is one definition of the format.
    echo json_encode([
        'success'  => true,
        'steamid'  => $steamid,
        'accessId' => canonicalAccessId($steamid) ?? $steamid
    ]);
}

/**
 * Returns mods with names for a specific world
 */
function getWorldModsWithNamesJson($pdo, $world) {
    $mods = getAllWorldMods($pdo, $world);
    // Filter out empty strings and "placeholder" entries
    $mods = array_filter($mods, function($mod) {
        return !empty($mod) && $mod !== 'placeholder';
    });
    $mods = array_unique($mods);

    $modList = [];
    foreach ($mods as $uuid) {
        $name = getModNameByUuid($pdo, $uuid);
        if ($name) {
            $modList[] = ['uuid' => $uuid, 'name' => $name];
        }
    }

    // Sort by name
    usort($modList, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    echo json_encode([
        'success' => true,
        'world' => $world,
        'mods' => $modList,
        'count' => count($modList)
    ]);
}

/**
 * Returns mod UUIDs for a specific world
 */
function getWorldModUUIDsJson($pdo, $world) {
    $mods = getAllWorldMods($pdo, $world);
    // Filter out empty strings and "placeholder" entries
    $mods = array_filter($mods, function($mod) {
        return !empty($mod) && $mod !== 'placeholder';
    });

    // Remove duplicates (selected mods + their dependencies may overlap)
    $mods = array_unique($mods);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'modUUIDs' => array_values($mods)
    ]);
}

/**
 * Returns all worlds (for dropdown)
 */
function getAllWorldsJson($pdo) {
    $stmt = $pdo->query("SELECT name FROM worlds ORDER BY name");
    $worlds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'worlds' => $worlds
    ]);
}

/**
 * Get contents of custom_configs and custom_plugins folders for a world
 */
function getWorldFolderContentsJson($world) {
    $basePath = '/opt/stateful/games/valheim/worlds/' . $world;
    $results = [
        'success' => true,
        'world' => $world,
        'configs' => [],
        'plugins' => []
    ];

    $configsPath = $basePath . '/custom_configs';
    $pluginsPath = $basePath . '/custom_plugins';

    if (is_dir($configsPath)) {
        $files = scandir($configsPath);
        $results['configs'] = array_values(array_filter($files, function($f) {
            return $f !== '.' && $f !== '..';
        }));
    }

    if (is_dir($pluginsPath)) {
        $files = scandir($pluginsPath);
        $results['plugins'] = array_values(array_filter($files, function($f) {
            return $f !== '.' && $f !== '..';
        }));
    }

    echo json_encode($results);
}

/**
 * Returns all latest-version mods with resolved dependency UUIDs
 */
/**
 * The picker's catalogue: every mod from every enabled source, with its resolved
 * dependencies and source marker.
 *
 * Mods are identified by `mods.id`, not by the source's uuid. Hexium mirrors Thunderstore
 * packages carrying their original uuid4 -- 600 collide -- so a uuid cannot tell the two
 * catalogues' copies apart, which is exactly the conflation the old `moduuid` key would
 * have produced here.
 */
function getAllModsWithDepsJson($pdo) {
    $sources = catalogSources($pdo);
    $enabled = array_keys(array_filter($sources, fn($s) => $s['enabled']));

    $mods = catalogMods($pdo, $enabled ?: null);
    $deps = catalogDeps($pdo);
    $missing = catalogMissingDeps($pdo);

    foreach ($mods as &$m) {
        $m['deps'] = $deps[$m['id']] ?? [];
        $m['missing_deps'] = $missing[$m['id']] ?? [];
    }
    unset($m);

    echo json_encode([
        'success' => true,
        'mods' => $mods,
        'count' => count($mods),
        'sources' => array_values($sources),
        'stats' => catalogStats($pdo)
    ]);
}

/** Every published version of one mod, for the version selector. */
function getModVersionsJson($pdo, $modId) {
    $versions = modVersions($pdo, $modId);
    if (!$versions) {
        echo json_encode(['success' => false, 'error' => 'no versions for that mod']);
        return;
    }
    echo json_encode([
        'success' => true,
        'modId' => (int)$modId,
        'versions' => $versions,
        'count' => count($versions)
    ]);
}

/**
 * Returns the selected and dependency mod UUIDs for a world
 */
function getWorldModSelectionJson($pdo, $world) {
    $sel = worldModSelection($pdo, $world);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'selected' => $sel['selected'],
        'deps' => $sel['deps'],
        // Empty means "every source the server has enabled" -- which is what every
        // pre-2.43 world wants, so it stays the default rather than an empty filter that
        // would show nothing.
        'modSources' => $sel['sources'] === '' ? [] : explode(',', $sel['sources'])
    ]);
}

/**
 * Save mod selection for an existing world (edit)
 */
function saveWorldModsJson($pdo, $world, $mods, $cloneSource, $cloneConfigs, $clonePlugins,
                          $modSources = null) {
    // Handle clone folder operations if requested
    if (!empty($cloneSource)) {
        handleCloneFolders($cloneSource, $world, $cloneConfigs, $clonePlugins);
    }

    if (is_array($modSources)) {
        saveWorldModSources($pdo, $world, $modSources);
    }

    // Writes only the operator's own picks. The dependency rows are rebuilt by
    // `worldMods.py --resolve` during the engine's update, because resolution must follow
    // the version that will actually be installed -- the pin, where there is one -- and
    // not whatever happens to be newest.
    $res = saveWorldModSelection($pdo, $world, $mods);
    if (!$res['ok']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $res['error']]);
        return;
    }

    // Set world to update mode to trigger engine processing
    updateWorld($pdo, $world);

    echo json_encode([
        'success' => true,
        'count' => $res['count'],
        // Surfaced, not swallowed: a rejected pin means the world is about to run a
        // different version from the one the operator asked for, and the old code's
        // silence about a mod it could not store is how a world ends up missing a plugin
        // with nothing in the UI to explain it.
        'warnings' => $res['warnings'],
        'message' => $res['warnings']
            ? 'Mods saved with warnings'
            : 'Mods saved successfully'
    ]);
}

/**
 * Create a new world with optional mod selection
 */
function createWorldJson($pdo, $world, $seed, $mods, $cloneSource, $cloneConfigs, $clonePlugins, $vanillaOptions = NULL, $accessOpen = 0, $accessFirstId = '', $modSources = NULL) {
    global $gameDNS, $defaultSeed;

    $isVanilla = !empty($vanillaOptions['vanilla']);

    if ($isVanilla) {
        // A vanilla world has no CustomSeed mod and Valheim's dedicated server has no
        // seed argument, so whatever we pick here can never reach the game -- Valheim
        // generates its own seed when it first creates the .fwl.
        //
        // Store nothing rather than a value we invented. Storing one would put a
        // fabricated seed on the public world card that does not match the actual
        // world, which is worse than showing nothing: it looks authoritative and is
        // wrong. The engine reads the real seed out of the .fwl after first start.
        $seed = '';
    } else {
        if (empty($seed)) {
            $seed = $defaultSeed;
        }
        if (empty($seed)) {
            $seed = (string)random_int(0, 4294967295);
        }
    }

    // Validate before creating anything, so a bad password doesn't leave a half made world
    if ($isVanilla) {
        $pw = trim($vanillaOptions['password'] ?? '');
        if ($err = validateWorldPassword($pw, $world)) {
            echo json_encode(['success' => false, 'error' => $err]);
            return;
        }
        if (!empty($vanillaOptions['listed']) && $pw === '') {
            echo json_encode([
                'success' => false,
                'error'   => 'A world listed in the server browser must have a password.'
            ]);
            return;
        }
    }

    $result = addWorld($pdo, $world, $gameDNS, $seed);

    if ($result === 0) {
        // World created successfully
        // Handle clone folder operations if requested
        if (!empty($cloneSource)) {
            handleCloneFolders($cloneSource, $world, $cloneConfigs, $clonePlugins);
        }

        // Crossplay applies to any world, modded or not.
        // Vanilla only, for now: a modded crossplay world cannot be reached by the PhValheim
        // client (QuickConnect's config is host:port; a PlayFab server has neither).
        setCrossplay($pdo, $world, ($isVanilla && !empty($vanillaOptions['crossplay'])) ? 1 : 0);

        // Set the access model EXPLICITLY. Before this, create wrote no `public` value at all
        // and the world silently inherited the column default -- which is 0, "enforce the
        // access list", with an empty list Valheim ignores. Every world was therefore born
        // open while its Access tab called it restricted. Writing the chosen value here means
        // the row says what the operator picked, not what the schema happened to default to.
        setPublic($pdo, $world, $accessOpen ? 1 : 0);

        // Seed the access list with the first player, so a restricted world is restricted the
        // moment it exists. Storing the bare SteamID64 matches what the operator typed and what
        // the Access tab shows; the V_ prefix Valheim requires is applied when the file is
        // rendered, by canonicalAccessId()/canonicalId().
        if (!$accessOpen && $accessFirstId !== '') {
            setCitizens($pdo, $world, $accessFirstId);
            $seedResult = writeAccessList($world, 'citizens', $accessFirstId);
            if (!$seedResult['ok']) {
                // Not fatal: the world exists and the database is correct, and
                // syncAccessLists.sh re-renders the file at every world start anyway.
                error_log("createWorld: could not write permittedlist.txt for '$world': " . $seedResult['error']);
            }
        }

        if ($isVanilla) {
            // A vanilla world means ZERO mods -- ignore any mod selection outright
            // rather than storing mods the build path will never install.
            setVanilla($pdo, $world, 1);
            setWorldPassword($pdo, $world, trim($vanillaOptions['password'] ?? ''));
            setListed($pdo, $world, !empty($vanillaOptions['listed']) ? 1 : 0);
            $mods = [];
        }

        // Add mods if any selected. Goes through the same validated path as an edit, so a
        // pin that does not belong to its mod is rejected at create time too rather than
        // only when the world is later edited.
        $modWarnings = [];
        if (is_array($mods) && !empty($mods)) {
            $saved = saveWorldModSelection($pdo, $world, $mods);
            if (!$saved['ok']) {
                echo json_encode([
                    'success' => false,
                    'error' => "World '$world' was created but its mods could not be saved: "
                               . $saved['error']
                ]);
                return;
            }
            $modWarnings = $saved['warnings'];
        }

        if (is_array($modSources)) {
            saveWorldModSources($pdo, $world, $modSources);
        }

        echo json_encode([
            'success' => true,
            'warnings' => $modWarnings,
            'message' => "World '$world' created"
        ]);
    } elseif ($result === 2) {
        echo json_encode([
            'success' => false,
            'error' => "World '$world' already exists"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Failed to create world '$world'"
        ]);
    }
}

/**
 * Handle cloning custom_configs and custom_plugins folders between worlds
 */
function handleCloneFolders($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins) {
    $basePath = '/opt/stateful/games/valheim/worlds';
    $sourcePath = $basePath . '/' . $sourceWorld;
    $targetPath = $basePath . '/' . $targetWorld;

    if (!is_dir($targetPath)) {
        mkdir($targetPath, 0775, true);
    }

    if ($cloneConfigs) {
        $sourceConfigs = $sourcePath . '/custom_configs';
        $targetConfigs = $targetPath . '/custom_configs';

        if (!is_dir($targetConfigs)) {
            mkdir($targetConfigs, 0775, true);
        }

        if (is_dir($sourceConfigs)) {
            exec("rsync -av --delete --exclude='ZeroBandwidth.CustomSeed.cfg' --filter='P ZeroBandwidth.CustomSeed.cfg' " . escapeshellarg($sourceConfigs . '/') . " " . escapeshellarg($targetConfigs . '/'));
        } else {
            exec("find " . escapeshellarg($targetConfigs) . " -mindepth 1 ! -name 'ZeroBandwidth.CustomSeed.cfg' -delete");
        }
    }

    if ($clonePlugins) {
        $sourcePlugins = $sourcePath . '/custom_plugins';
        $targetPlugins = $targetPath . '/custom_plugins';

        if (!is_dir($targetPlugins)) {
            mkdir($targetPlugins, 0775, true);
        }

        if (is_dir($sourcePlugins)) {
            exec("rsync -av --delete --exclude='ZeroBandwidth-CustomSeed' --filter='P ZeroBandwidth-CustomSeed' " . escapeshellarg($sourcePlugins . '/') . " " . escapeshellarg($targetPlugins . '/'));
        } else {
            exec("find " . escapeshellarg($targetPlugins) . " -mindepth 1 ! -name 'ZeroBandwidth-CustomSeed' -delete");
        }
    }
}

/**
 * Clone custom_configs and/or custom_plugins folders from one world to another
 */
function cloneWorldFoldersJson($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins) {
    $basePath = '/opt/stateful/games/valheim/worlds';
    $sourcePath = $basePath . '/' . $sourceWorld;
    $targetPath = $basePath . '/' . $targetWorld;
    $results = [];

    // Create target world folder if it doesn't exist
    if (!is_dir($targetPath)) {
        mkdir($targetPath, 0775, true);
    }

    if ($cloneConfigs) {
        $sourceConfigs = $sourcePath . '/custom_configs';
        $targetConfigs = $targetPath . '/custom_configs';

        // Create target directory if it doesn't exist
        if (!is_dir($targetConfigs)) {
            mkdir($targetConfigs, 0775, true);
        }

        if (is_dir($sourceConfigs)) {
            // Use rsync to copy all contents, excluding world-specific seed config
            // --exclude prevents copying from source, --filter protects existing files in dest from deletion
            exec("rsync -av --delete --exclude='ZeroBandwidth.CustomSeed.cfg' --filter='P ZeroBandwidth.CustomSeed.cfg' " . escapeshellarg($sourceConfigs . '/') . " " . escapeshellarg($targetConfigs . '/'));
            $results['configs'] = 'cloned';
        } else {
            // Source doesn't exist, empty the target but preserve seed config
            exec("find " . escapeshellarg($targetConfigs) . " -mindepth 1 ! -name 'ZeroBandwidth.CustomSeed.cfg' -delete");
            $results['configs'] = 'source not found';
        }
    }

    if ($clonePlugins) {
        $sourcePlugins = $sourcePath . '/custom_plugins';
        $targetPlugins = $targetPath . '/custom_plugins';

        // Create target directory if it doesn't exist
        if (!is_dir($targetPlugins)) {
            mkdir($targetPlugins, 0775, true);
        }

        if (is_dir($sourcePlugins)) {
            // Use rsync to copy all contents, excluding world-specific seed plugin
            // --exclude prevents copying from source, --filter protects existing files in dest from deletion
            exec("rsync -av --delete --exclude='ZeroBandwidth-CustomSeed' --filter='P ZeroBandwidth-CustomSeed' " . escapeshellarg($sourcePlugins . '/') . " " . escapeshellarg($targetPlugins . '/'));
            $results['plugins'] = 'cloned';
        } else {
            // Source doesn't exist, empty the target but preserve seed plugin
            exec("find " . escapeshellarg($targetPlugins) . " -mindepth 1 ! -name 'ZeroBandwidth-CustomSeed' -delete");
            $results['plugins'] = 'source not found';
        }
    }

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
}

/**
 * Returns available AI providers and their models
 */
function getAiProvidersJson($aiKeys) {
    $providerDefs = [
        'openai' => [
            'label' => 'OpenAI',
            'models' => [
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini'],
                ['id' => 'gpt-4o', 'label' => 'GPT-4o'],
            ]
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'models' => [
                ['id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash'],
                ['id' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash Lite'],
                ['id' => 'gemini-1.5-pro', 'label' => 'Gemini 1.5 Pro'],
            ]
        ],
        'claude' => [
            'label' => 'Anthropic Claude',
            'models' => [
                ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5'],
                ['id' => 'claude-sonnet-4-5-20250929', 'label' => 'Sonnet 4.5'],
            ]
        ],
    ];

    $providers = [];
    foreach ($providerDefs as $key => $def) {
        if (!empty($aiKeys[$key])) {
            $providers[$key] = $def;
        }
    }

    // Ollama: dynamically fetch models from the server
    if (!empty($aiKeys['ollama'])) {
        $ollamaModels = getOllamaModels($aiKeys['ollama']);
        if (!empty($ollamaModels)) {
            $providers['ollama'] = [
                'label' => 'Ollama',
                'models' => $ollamaModels
            ];
        }
    }

    echo json_encode(['success' => true, 'providers' => $providers]);
}

/**
 * Dispatch AI Helper request to the correct provider
 */
function aiHelperDispatch($aiKeys, $provider, $model, $message, $history, $context, $world) {
    $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'gemini' => ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-pro'],
        'claude' => ['claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929'],
    ];
    $validProviders = ['openai', 'gemini', 'claude', 'ollama'];

    // Validate provider
    if (!in_array($provider, $validProviders) || empty($aiKeys[$provider])) {
        echo json_encode(['success' => false, 'error' => "Provider '$provider' not available"]);
        return;
    }

    // Validate model (skip for ollama — models are dynamic)
    if ($provider !== 'ollama' && isset($allowedModels[$provider])) {
        if (!in_array($model, $allowedModels[$provider])) {
            $model = $allowedModels[$provider][0];
        }
    }

    $apiKey = $aiKeys[$provider];
    $systemPrompt = buildAiSystemPrompt($context, $world, $GLOBALS['pdo'] ?? null);

    // Trim history
    $trimmedHistory = [];
    if (is_array($history)) {
        $history = array_slice($history, -20);
        foreach ($history as $h) {
            if (isset($h['role']) && isset($h['content']) && in_array($h['role'], ['user', 'assistant'])) {
                $trimmedHistory[] = $h;
            }
        }
    }

    switch ($provider) {
        case 'openai':  aiHelperOpenAI($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'gemini':  aiHelperGemini($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'claude':  aiHelperClaude($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'ollama':  aiHelperOllama($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
    }
}

/**
 * Build the system prompt with optional log context
 */
function buildAiSystemPrompt($context, $world, $pdo = null) {
    $systemPrompt = "You are PhValheim AI Helper, a concise technical assistant for the PhValheim Valheim server manager. "
        . "You help admins troubleshoot server issues, understand logs, and manage mods. "
        . "Keep answers short and actionable. Use bullet points for lists. "
        . "If you see errors in logs, explain the likely cause and suggest a fix. "
        . "CRITICAL: This is a HEADLESS dedicated server. Never mention or report on anything related to fonts, UI, shaders, graphics, rendering, DepthOfField, textures, cameras, screen resolution, visual effects, materials, meshes, sprites, or any graphical/visual warnings — they are completely irrelevant on a headless server. "
        . "Also ignore mod RPC errors — these are normal networked mod communication and not actionable.";

    $logFile = null;
    $contextLabel = $context;
    $safeWorld = null;
    if (strpos($context, 'world:') === 0) {
        // World-specific log: context = "world:WorldName"
        $worldName = substr($context, 6);
        $safeWorld = preg_replace('/[^a-zA-Z0-9_-]/', '', $worldName);
        $logFile = "/opt/stateful/logs/valheimworld_{$safeWorld}.log";
        $contextLabel = "world '$safeWorld'";
    } else {
        switch ($context) {
            case 'engine':  $logFile = '/opt/stateful/logs/phvalheim.log'; break;
            // 'ts' is retained as the key so old bookmarks still resolve, but it points
            // at modSync.log -- tsSync.log is only written by pre-2.43 installs.
            case 'ts':
            case 'modsync': $logFile = '/opt/stateful/logs/modSync.log'; break;
            case 'backup':  $logFile = '/opt/stateful/logs/worldBackups.log'; break;
        }
    }

    // If world context and we have a database connection, inject expected mod list
    if ($safeWorld && $pdo) {
        try {
            $modUuids = getAllWorldMods($pdo, $safeWorld);
            $modNames = [];
            foreach ($modUuids as $uuid) {
                $uuid = trim($uuid);
                if (empty($uuid)) continue;
                $name = getModNameByUuid($pdo, $uuid);
                if ($name) {
                    $modNames[] = $name;
                }
            }
            if (!empty($modNames)) {
                $systemPrompt .= "\n\nExpected mods configured in the database for this world:\n";
                foreach ($modNames as $modName) {
                    $systemPrompt .= "- {$modName}\n";
                }
                $systemPrompt .= "\nCompare this list against [BepInEx] Loading lines in the log to identify any mods that are expected but not loaded. Do not report on unexpected mods loaded.";
            }
        } catch (Exception $e) {
            // Silently skip mod list if query fails
        }
    }

    if ($logFile && file_exists($logFile)) {
        $lines = [];
        $fp = @fopen($logFile, 'r');
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                $lines[] = $line;
                if (count($lines) > 200) {
                    array_shift($lines);
                }
            }
            fclose($fp);
        }
        if (!empty($lines)) {
            $logContent = implode('', $lines);
            $systemPrompt .= "\n\nHere are the last " . count($lines) . " lines from the {$contextLabel} log:\n```\n{$logContent}\n```";
        }
    }

    return $systemPrompt;
}

/**
 * OpenAI Chat Completions API
 */
function aiHelperOpenAI($apiKey, $model, $systemPrompt, $history, $message) {
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 1024,
        'temperature' => 0.7
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'OpenAI error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from OpenAI";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['choices'][0]['message']['content']]);
}

/**
 * Google Gemini generateContent API
 */
function aiHelperGemini($apiKey, $model, $systemPrompt, $history, $message) {
    $contents = [];
    foreach ($history as $h) {
        $geminiRole = ($h['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $h['content']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 1024,
            'temperature' => 0.7
        ]
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Gemini error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from Gemini";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['candidates'][0]['content']['parts'][0]['text']]);
}

/**
 * Anthropic Claude Messages API
 */
function aiHelperClaude($apiKey, $model, $systemPrompt, $history, $message) {
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'system' => $systemPrompt,
        'messages' => $messages,
        'max_tokens' => 1024
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Claude error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from Claude";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['content'][0]['text']]);
}

/**
 * Fetch available models from Ollama server
 */
function getOllamaModels($ollamaUrl) {
    $url = rtrim($ollamaUrl, '/') . '/api/tags';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return [];

    $data = json_decode($response, true);
    if (!isset($data['models']) || !is_array($data['models'])) return [];

    $models = [];
    foreach ($data['models'] as $m) {
        $name = $m['name'] ?? '';
        if (empty($name)) continue;
        // Use the model name as both id and label, clean up the label
        $label = explode(':', $name)[0]; // strip :latest tag for display
        $models[] = ['id' => $name, 'label' => $label];
    }

    return $models;
}

/**
 * Returns all server settings from the settings table
 */
function getServerSettingsJson($pdo) {
    $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        echo json_encode(['success' => false, 'error' => 'No settings found']);
        return;
    }

    echo json_encode([
        'success' => true,
        'settings' => [
            'basePort' => (int)$settings['basePort'],
            'defaultSeed' => $settings['defaultSeed'] ?? '',
            'gameDNS' => $settings['gameDNS'] ?? '',
            'steamAPIKey' => $settings['steamApiKey'] ?? '',
            'phvalheimClientURL' => $settings['phvalheimClientURL'] ?? '',
            'backupsToKeep' => (int)$settings['backupsToKeep'],
            'maxLogSize' => (int)$settings['maxLogSize'],
            'sessionTimeout' => (int)$settings['sessionTimeout'],
            'timezone' => $settings['timezone'] ?? 'Etc/UTC',
            'openaiApiKey' => $settings['openaiApiKey'] ?? '',
            'geminiApiKey' => $settings['geminiApiKey'] ?? '',
            'claudeApiKey' => $settings['claudeApiKey'] ?? '',
            'ollamaUrl' => $settings['ollamaUrl'] ?? '',
            'setupComplete' => (int)$settings['setupComplete'],
            'migrationNoticeShown' => (int)$settings['migrationNoticeShown'],
            'analyticsEnabled' => (int)($settings['analyticsEnabled'] ?? 1),
            // Mod catalogues (2.43). NEITHER key is required: both
            // thunderstore.io/c/valheim/api/v1/package/ and
            // valheim.hexium.gg/api/v1/package/ are public and unauthenticated. The fields
            // exist so an operator CAN supply one if a source starts demanding or
            // rate-limiting it, and the UI says as much rather than implying setup is
            // incomplete without them.
            'thunderstoreApiKey' => $settings['thunderstoreApiKey'] ?? '',
            'hexiumApiKey' => $settings['hexiumApiKey'] ?? '',
            'thunderstoreEnabled' => (int)($settings['thunderstoreEnabled'] ?? 1),
            'hexiumEnabled' => (int)($settings['hexiumEnabled'] ?? 1),
            'modSyncIntervalHours' => (int)($settings['modSyncIntervalHours'] ?? 6),
            'backupIntervalMinutes' => (int)($settings['backupIntervalMinutes'] ?? 30),
            'backupRequireActivity' => (int)($settings['backupRequireActivity'] ?? 1),
            'backupCompression' => $settings['backupCompression'] ?? 'none',
            'backupCompressionHour' => (int)($settings['backupCompressionHour'] ?? 3),
            'backupRetainAllHours' => (int)($settings['backupRetainAllHours'] ?? 24),
            'backupRetainDailyDays' => (int)($settings['backupRetainDailyDays'] ?? 7),
            'backupRetainWeeklyDays' => (int)($settings['backupRetainWeeklyDays'] ?? 30),
            'backupRetainMonthlyMonths' => (int)($settings['backupRetainMonthlyMonths'] ?? 6),
            'backupCpuPriority' => (int)($settings['backupCpuPriority'] ?? 10),
            'backupIoPriority' => $settings['backupIoPriority'] ?? 'low',
            'backupCompressionLevel' => (int)($settings['backupCompressionLevel'] ?? 0),
            // Backup disk info
            'backupPath' => '/opt/stateful/backups',
            'backupPathMounted' => isBackupPathMounted(),
            'backupDiskTotal' => getTotalDisk('/opt/stateful/backups'),
            'backupDiskUsed' => getUsedDisk('/opt/stateful/backups'),
            'backupDiskFree' => getFreeDisk('/opt/stateful/backups'),
            'backupDiskPerc' => getUsedDiskPerc('/opt/stateful/backups'),
            'backupCount' => getTotalBackupCount($pdo),
            'backupTotalSize' => getTotalBackupSize($pdo),
        ]
    ]);
}

/**
 * Save server settings
 */
function saveServerSettingsJson($pdo, $input) {
    $allowedFields = [
        'basePort' => 'int',
        'defaultSeed' => 'string',
        'gameDNS' => 'string',
        'steamAPIKey' => 'string',
        'phvalheimClientURL' => 'string',
        'backupsToKeep' => 'int',
        'maxLogSize' => 'int',
        'sessionTimeout' => 'int',
        'timezone' => 'string',
        'openaiApiKey' => 'string',
        'geminiApiKey' => 'string',
        'claudeApiKey' => 'string',
        'ollamaUrl' => 'string',
        'analyticsEnabled' => 'int',
        // Mod catalogues (2.43)
        'thunderstoreApiKey' => 'string',
        'hexiumApiKey' => 'string',
        'thunderstoreEnabled' => 'int',
        'hexiumEnabled' => 'int',
        'modSyncIntervalHours' => 'int',
        'backupIntervalMinutes' => 'int',
        'backupRequireActivity' => 'int',
        'backupCompression' => 'string',
        'backupCompressionHour' => 'int',
        'backupRetainAllHours' => 'int',
        'backupRetainDailyDays' => 'int',
        'backupRetainWeeklyDays' => 'int',
        'backupRetainMonthlyMonths' => 'int',
        'backupCpuPriority' => 'int',
        'backupIoPriority' => 'string',
        'backupCompressionLevel' => 'int',
    ];

    // Map input field names to actual DB column names where they differ
    $columnMap = ['steamAPIKey' => 'steamApiKey'];

    // Detect analytics being switched off: send one final opt-out notice before saving
    if (isset($input['analyticsEnabled']) && (int)$input['analyticsEnabled'] === 0) {
        $row = $pdo->query("SELECT analyticsEnabled FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ((int)($row['analyticsEnabled'] ?? 1) === 1) {
            // Fire in background — do not block the settings save response
            exec('nohup /opt/stateless/engine/tools/pushAnalytics.sh --disabled > /dev/null 2>&1 &');
        }
    }

    $updates = [];
    $params = [];
    foreach ($input as $key => $value) {
        if (!isset($allowedFields[$key])) continue;
        $col = $columnMap[$key] ?? $key;
        if ($allowedFields[$key] === 'int') {
            $updates[] = "$col = ?";
            $params[] = (int)$value;
        } else {
            $updates[] = "$col = ?";
            $params[] = (string)$value;
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No valid fields to update']);
        return;
    }

    $sql = "UPDATE settings SET " . implode(', ', $updates);
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    // Re-export settings to /etc/environment so engine/cron picks them up
    if ($result) {
        $settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $tz = $settings['timezone'] ?? 'Etc/UTC';
        $envVars = [
            'basePort' => $settings['basePort'],
            'defaultSeed' => $settings['defaultSeed'],
            'gameDNS' => $settings['gameDNS'],
            'steamAPIKey' => $settings['steamApiKey'],
            'phvalheimClientURL' => $settings['phvalheimClientURL'],
            'backupsToKeep' => $settings['backupsToKeep'],
            'maxLogSize' => $settings['maxLogSize'],
            'sessionTimeout' => $settings['sessionTimeout'],
            'TZ' => $tz,
        ];

        // Read existing /etc/environment and update/add our vars
        $envFile = '/etc/environment';
        $envLines = file_exists($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [];
        $envMap = [];
        foreach ($envLines as $line) {
            if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                $envMap[$m[1]] = $m[2];
            }
        }
        foreach ($envVars as $k => $v) {
            $envMap[$k] = $v;
        }
        $output = '';
        foreach ($envMap as $k => $v) {
            $output .= "$k=$v\n";
        }
        @file_put_contents($envFile, $output);

        // Apply timezone to the running system via helper script (needs sudo for /etc files)
        $tzSafe = escapeshellarg($tz);
        exec("sudo /opt/stateless/engine/tools/applyTimezone.sh $tzSafe 2>&1", $tzOutput, $tzResult);
        if ($tzResult === 0) {
            date_default_timezone_set($tz);
            // Also update MariaDB session timezone so NOW() uses new timezone
            $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
            $pdo->exec("SET time_zone = '$offset'");
        }
    }

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Settings saved successfully' : 'Failed to save settings'
    ]);
}

/**
 * Complete the setup wizard (fresh install)
 */
function completeSetupJson($pdo, $input) {
    // Save all provided settings first
    saveServerSettingsJson_internal($pdo, $input);

    // Mark setup as complete
    $stmt = $pdo->prepare("UPDATE settings SET setupComplete = 2");
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Setup complete'
    ]);
}

/**
 * Internal helper to save settings without JSON output
 */
function saveServerSettingsJson_internal($pdo, $input) {
    $allowedFields = [
        'basePort' => 'int',
        'defaultSeed' => 'string',
        'gameDNS' => 'string',
        'steamAPIKey' => 'string',
        'phvalheimClientURL' => 'string',
        'backupsToKeep' => 'int',
        'maxLogSize' => 'int',
        'sessionTimeout' => 'int',
        'timezone' => 'string',
        'openaiApiKey' => 'string',
        'geminiApiKey' => 'string',
        'claudeApiKey' => 'string',
        'ollamaUrl' => 'string',
    ];

    $columnMap = ['steamAPIKey' => 'steamApiKey'];

    $updates = [];
    $params = [];
    foreach ($input as $key => $value) {
        if (!isset($allowedFields[$key])) continue;
        $col = $columnMap[$key] ?? $key;
        if ($allowedFields[$key] === 'int') {
            $updates[] = "$col = ?";
            $params[] = (int)$value;
        } else {
            $updates[] = "$col = ?";
            $params[] = (string)$value;
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE settings SET " . implode(', ', $updates);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

/**
 * Dismiss the one-shot "What's New" modal.
 *
 * Records the RUNNING version as seen, so the modal stays gone until the next upgrade
 * changes what it is compared against. Refuses an empty version rather than writing '',
 * which would mean "never shown" and re-raise the modal on every page load.
 */
function dismissWhatsNewJson($pdo, $currentVersion) {
    $currentVersion = trim((string)$currentVersion);
    if ($currentVersion === '') {
        echo json_encode(['success' => false, 'error' => 'Running version unknown']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE settings SET whatsNewShownVersion = ?");
    $result = $stmt->execute([$currentVersion]);

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? "Release notes for v$currentVersion dismissed" : 'Failed to dismiss release notes'
    ]);
}

/**
 * Dismiss the one-time migration notice
 */
function dismissMigrationNoticeJson($pdo) {
    $stmt = $pdo->prepare("UPDATE settings SET migrationNoticeShown = 1, setupComplete = 2");
    $result = $stmt->execute();

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Migration notice dismissed' : 'Failed to dismiss notice'
    ]);
}

/**
 * Dismiss the one-time "your ids were converted to V_ form" notice on the Access tab.
 *
 * Set by migrateAccessIds.php only on a server where ids were actually converted, so a
 * fresh install never sees it.
 */
function dismissAccessIdNoticeJson($pdo) {
    $stmt = $pdo->prepare("UPDATE settings SET accessIdNoticeShown = 1");
    $result = $stmt->execute();

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Access id notice dismissed' : 'Failed to dismiss notice'
    ]);
}

function dismissAccessSwitchNoticeJson($pdo) {
    $stmt = $pdo->prepare("UPDATE settings SET accessSwitchNoticeShown = 1");
    $result = $stmt->execute();

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Access switch notice dismissed' : 'Failed to dismiss notice'
    ]);
}

/**
 * Ollama Chat API
 */
function aiHelperOllama($ollamaUrl, $model, $systemPrompt, $history, $message) {
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'stream' => false
    ]);

    $url = rtrim($ollamaUrl, '/') . '/api/chat';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Ollama error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['message']['content'])) {
        $errMsg = $data['error'] ?? "HTTP {$httpCode} from Ollama";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['message']['content']]);
}

?>
