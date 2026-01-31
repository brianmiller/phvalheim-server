<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '../includes/modViewerGenerator.php';

if (!empty($_GET['delete_world'])) {
        $world = $_GET['delete_world'];
        deleteWorld($pdo,$world);
        header('Location: /');
}

if (!empty($_GET['stop_world'])) {
        $world = $_GET['stop_world'];
        stopWorld($pdo,$world);
        header('Location: /');
}

if (!empty($_GET['start_world'])) {
        $world = $_GET['start_world'];
        startWorld($pdo,$world);
        header('Location: /');
}

if (!empty($_GET['update_world'])) {
        $world = $_GET['update_world'];
        updateWorld($pdo,$world);
        header('Location: /');
}

if (!empty($_GET['manual_ts_sync_start'])) {
        $manual_ts_sync_start = $_GET['manual_ts_sync_start'];
	if($manual_ts_sync_start == "go")
	{
		exec("/opt/stateless/engine/tools/tsSyncLocalParseMultithreaded.sh >> /opt/stateful/logs/tsSync.log &");
		header('Location: /');
	}
}


# http(s) detector
if(isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
    $httpScheme = "https";
} else {
    $httpScheme = "http";
}

# Time now
$timeNow = date("Y-m-d H:i:s T");

function populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme){
        $getWorlds = $pdo->query("SELECT status,mode,name,port,external_endpoint,seed,autostart,beta FROM worlds");

        foreach($getWorlds as $row)
        {
                $status = $row['status'];
                $mode = $row['mode'];
                $world = $row['name'];
                $port = $row['port'];
                $password = "hammertime";
                #$password = $row['password'];
                $external_endpoint = $row['external_endpoint'];
                $seed = $row['seed'];
                $launchString = base64_encode("launch?$world?$password?$gameDNS?$port?$phvalheimHost?$httpScheme");
		$isBeta = $row['beta'];
		$isAutoStart = $row['autostart'];
                #$logsLink = "<a href='/readLog.php?logfile=valheimworld_$world.log'>Logs</a>";

                $logsLink = "<a href=\"#\" onClick=\"window.open('/readLog.php?logfile=valheimworld_$world.log','logReader','resizable,height=750,width=1600'); return false;\">Logs</a><noscript>You need Javascript to use the previous link or use <a href=\"/readLog.php?logfile=valheimworld_$world.log\" target=\"_blank\" rel=\"noreferrer noopener\">Logs</a></noscript>";

                if ($isBeta == '1' ) {
                        $beta = " <span class='badge bg-danger ms-1'>BETA</span>";
                } else {
                        $beta = '';
                }


		# dynamic autoStart javascript
                echo "
	                <script>
          	              function autoStart_$world(cb)
                              {
              	                if($(cb).is(':checked'))
                                {
					$.getScript(\"setters.php?type=autostart&value=1&worldName=$world\");
				}
                                if(!$(cb).is(':checked'))
                                {
                                        $.getScript(\"setters.php?type=autostart&value=0&worldName=$world\");
                                }
                              }
                        </script>
                ";

		# autoStart check
		if ($isAutoStart == '1' ) {
			$autoStartSwitch = "<label class='switch'><input class='switch' checked type='checkbox' onclick='autoStart_$world(this)';><span class='slider round'></span></label>";
		} else {
			$autoStartSwitch = "<label class='switch'><input class='switch' type='checkbox' onclick='autoStart_$world(this)'><span class='slider round'></span></label>";
		}

                if ($mode == 'stopped') {
                        $editLink = "<a href='edit_world.php?world=$world'>Edit Mods</a>";
                        $startLink = "<a href='?start_world=$world'>Start</a>";
                        $stopLink = "<span class='text-muted'>Stop</span>";
                        $deleteLink = "<a href='?delete_world=$world' onclick='return confirm(\"Are you sure?\")' class='text-danger'>Delete</a>";
                        $updateLink = "<a href='?update_world=$world'>Update</a>";
                        $launchLink = "<span class='text-muted'>Launch</span>";
                } else {
                        $editLink = "<span class='text-muted'>Edit Mods</span>";
                        $startLink = "<span class='text-muted'>Start</span>";
                        $deleteLink = "<span class='text-muted'>Delete</span>";
                        $stopLink = "<span class='text-muted'>Stop</span>";
                        $updateLink = "<span class='text-muted'>Update</span>";
                }

                if ($mode == 'running') {
                        $stopLink = "<a href='?stop_world=$world'>Stop</a>";
                        $deleteLink = "<span class='text-muted'>Delete</span>";
                        $launchLink = "<a href=phvalheim://?$launchString>Launch</a>";
                } else {
                        $launchLink = "<span class='text-muted'>Launch</span>";
                }

                $editCitizensLink = "<a href='citizensEditor.php?world=$world'>Citizens</a>";
		$otherSettingsLink = "<a href='otherWorldSettings.php?worldName=$world'>Settings</a>";

                #$getAllWorldMods = getAllWorldMods($pdo,$world);
                $runningMods_head = "\n<table class='table table-sm table-borderless mb-0'>\n";
                $runningMods_foot = "</table>\n";
                $runningMods = $runningMods_head . generateToolTip($pdo,$world) . $runningMods_foot;
                $modListToolTip = "<a href='#' tabindex='0' class='text-info' data-bs-toggle='popover' data-bs-trigger='focus' data-bs-placement='bottom' data-bs-title='Running Mods' data-bs-html='true' data-bs-content='$runningMods'>(view)</a>";

		# Mode badge
		if ($mode == 'running') {
			$modeBadge = "<span class='badge bg-success'>$mode</span>";
		} elseif ($mode == 'stopped') {
			$modeBadge = "<span class='badge bg-secondary'>$mode</span>";
		} else {
			$modeBadge = "<span class='badge bg-warning'>$mode</span>";
		}

                echo "<tr>";
                echo "    <td>$modeBadge</td>";
                echo "    <td class='fw-medium'>$world</td>";
                echo "    <td><code>$external_endpoint:$port</code></td>";
                echo "    <td><code>$seed</code></td>";
                echo "    <td class='text-nowrap'>$launchLink | $startLink | $stopLink | $logsLink</td>";
                echo "    <td class='text-nowrap'>$editLink $modListToolTip | $editCitizensLink | $otherSettingsLink</td>";
                echo "    <td class='text-nowrap'>$updateLink | $deleteLink</td>";
                echo "    <td class='text-nowrap'>$autoStartSwitch$beta</td>";
                echo "</tr>";
        }
}


?>
<!DOCTYPE html>
<html lang="en">
        <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>PhValheim Admin</title>
                <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
                <link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css">
                <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
                <script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
                <script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
                <script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
                <script>
                        $(document).ready( function () {
                                $('#worlds').DataTable({

                                        "rowCallback": function( row, data, index ) {
                                            if(index%2 == 0){
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myodd');
                                            }else{
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myeven');
                                            }
                                          },

                                        lengthMenu: [
                                            [50, 75, 100, -1],
                                            [50, 75, 100, 'All'],
                                        ],

                                        "language": {
                                          "emptyTable": "<span class='text-warning'>Add your first world by clicking 'Add World' below.</span>"
                                        }
                                });

                                // Initialize Bootstrap 5 popovers
                                var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                                var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                                    return new bootstrap.Popover(popoverTriggerEl, {
                                        sanitize: false
                                    });
                                });
                        });
		</script>
        </head>

        <body>
            <div class="container-fluid px-3 px-lg-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
                    <h4 class="mb-0" style="color: var(--accent-primary);">PhValheim Admin</h4>
                    <span class="text-secondary small">v<?php echo $phvalheimVersion;?></span>
                </div>

                <!-- Worlds Table -->
                <div class="card-panel mb-4">
                    <div class="table-responsive">
                        <table id="worlds" class="table table-hover mb-0" style="width:100%;">
                            <thead>
                                <tr>
                                    <th class="alt-color">Status</th>
                                    <th class="alt-color">World</th>
                                    <th class="alt-color">Endpoint</th>
                                    <th class="alt-color">Seed</th>
                                    <th class="alt-color">Actions</th>
                                    <th class="alt-color">Configure</th>
                                    <th class="alt-color">Manage</th>
                                    <th class="alt-color">Auto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cards Row -->
                <div class="row g-4">
                    <!-- Commands Card -->
                    <div class="col-12 col-lg-4">
                        <div class="card-panel h-100">
                            <div class="card-panel-header">Commands</div>
                            <div class="d-grid gap-2">
                                <a href='new_world.php' class="btn-grid"><button class="sm-bttn w-100">Add World</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='/supervisor/' class="btn-grid"><button class="sm-bttn w-100">Service Management</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='gridphp/' onclick="return confirm('I hope you know what you\'re doing. \nAre you sure?')" class="btn-grid"><button class="sm-bttn w-100">Database Browser</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='fileBrowser.php' class="btn-grid"><button class="sm-bttn w-100">File Browser</button></a>
                                <button onclick="location.href='?manual_ts_sync_start=go'" class="sm-bttn w-100">Start Thunderstore Sync</button>
                            </div>
                        </div>
                    </div>

                    <!-- System Status Card -->
                    <div class="col-12 col-lg-4">
                        <div class="card-panel h-100">
                            <div class="card-panel-header">System Status</div>
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="alt-color" style="width: 45%;">Memory</td>
                                        <td><?php echo 'Total: ' . getTotalMemory() . ' / Free: ' . getFreeMemory() . ' / Used: ' . getUsedMemory();?></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">Storage</td>
                                        <td><?php echo 'Total: ' . getTotalDisk('/opt/stateful') . ' / Free: ' . getFreeDisk('/opt/stateful') . ' / Used: ' . getUsedDisk('/opt/stateful');?></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">CPU Model</td>
                                        <td><?php echo getCpuModel($pdo); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">CPU Utilization</td>
                                        <td><?php echo getCpuUtilization($pdo); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sync Status Card -->
                    <div class="col-12 col-lg-4">
                        <div class="card-panel h-100">
                            <div class="card-panel-header">Sync & Maintenance</div>
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="alt-color" style="width: 50%;">Last TS Sync</td>
                                        <td><?php echo getLastTsUpdated($pdo); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">Local TS Diff</td>
                                        <td><?php echo getLastTsLocalDiffExecTime($pdo); ?> <span class="pri-color sm-font-italic"><?php echo getLastTsSyncLocalExecStatus($pdo); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">Remote TS Diff</td>
                                        <td><?php echo getLastTsRemoteDiffExecTime($pdo); ?> <span class="pri-color sm-font-italic"><?php echo getLastTsSyncRemoteExecStatus($pdo); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">World Backup</td>
                                        <td><?php echo getLastWorldBackupExecTime($pdo); ?> <span class="pri-color sm-font-italic"><?php echo getLastWorldBackupExecStatus($pdo); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">Log Rotate</td>
                                        <td><?php echo getLastLogRotateExecTime($pdo); ?> <span class="pri-color sm-font-italic"><?php echo getLastLogRotateExecStatus($pdo); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="alt-color">Utilization Monitor</td>
                                        <td><?php echo getLastUtilizationMonitorExecTime($pdo); ?> <span class="pri-color sm-font-italic"><?php echo getLastUtilizationMonitorExecStatus($pdo); ?></span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Logs Card -->
                <div class="row g-4 mt-1">
                    <div class="col-12">
                        <div class="card-panel">
                            <div class="card-panel-header">System Logs</div>
                            <div class="d-flex flex-wrap gap-2">
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=phvalheim.log#bottom'><button class="sm-bttn">Engine</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=mysqld.log#bottom'><button class="sm-bttn">MySQL</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=php.log#bottom'><button class="sm-bttn">PHP</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=nginx.log#bottom'><button class="sm-bttn">NGINX</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=cron.log#bottom'><button class="sm-bttn">CRON</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=tsSync.log#bottom'><button class="sm-bttn">Thunderstore Sync</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=worldBackups.log#bottom'><button class="sm-bttn">World Backup</button></a>
                                <a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=logRotater.log#bottom'><button class="sm-bttn">Log Rotater</button></a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="py-3 mt-3 border-top text-center" style="border-color: var(--border-color) !important;">
                    <span class="alt-color">Current Time:</span> <span class="text-primary"><?php print $timeNow; ?></span>
                </div>
            </div>

        </body>
</html>
