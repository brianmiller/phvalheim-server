<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';


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

if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
        $httpScheme = "https";
} else {
        $httpScheme = "http";
}


# Time now
#date_default_timezone_set('UTC');
$timeNow = date("Y-m-d H:i:s T");


function populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme){
	$getWorlds = $pdo->query("SELECT status,mode,name,port,external_endpoint,seed FROM worlds");

	#### BEGIN: runningMod toolTip generator
        function generateToolTip($pdo,$getAllWorldMods) {
	        foreach ($getAllWorldMods as $runningModUuid) {
			$runningModName = getModNameByUuid($pdo,$runningModUuid);
                        $runningModUrl = getModUrlByUuid($pdo,$runningModUuid);

                        if (!empty($runningModName)) {
                      	  $toolTipContent = " <tr style=\"line-height:5px;\"><td style=\"\"><li><a target=\"_blank\" href=\"$runningModUrl\">$runningModName</a></li></td>\n$toolTipContent";
                        }
                }      
                return $toolTipContent;
        }

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
		#$logsLink = "<a href='/readLog.php?logfile=valheimworld_$world.log'>Logs</a>";

		$logsLink = "<a href=\"#\" onClick=\"window.open('/readLog.php?logfile=valheimworld_$world.log','logReader','resizable,height=750,width=1600'); return false;\">Logs</a><noscript>You need Javascript to use the previous link or use <a href=\"/readLog.php?logfile=valheimworld_$world.log\" target=\"_blank\" rel=\"noreferrer noopener\">Logs</a></noscript>";


		if ($mode == 'stopped') {
			$editLink = "<a disabled href='edit_world.php?world=$world'>Edit Mods</a>";
			$startLink = "<a href='?start_world=$world'>Start</a>";
			$stopLink = "<font color=lightgrey>Stop</font>";
			$deleteLink = "<a href='?delete_world=$world' onclick='return confirm(\"Are you sure?\")'>Delete</a>";
			$updateLink = "<a href='?update_world=$world'>Update</a>";
			$launchLink = "<font color=lightgrey>Launch</font>";
		} else {
			$editLink = "<font color=lightgrey>Edit Mods</font>";
			$startLink = "<font color=lightgrey>Start</font>";
			$deleteLink = "<font color=lightgrey>Delete</font>";
			$stopLink = "<font color=lightgrey>Stop</font>";
			$updateLink = "<font color=lightgrey>Update</font>";
		}

                if ($mode == 'running') {
			$stopLink = "<a href='?stop_world=$world'>Stop</a>";
			$deleteLink = "<font color=lightgrey>Delete</font>";
			$launchLink = "<a href=phvalheim://?$launchString>Launch</a>";
		} else {
			$launchLink = "<font color=lightgrey>Launch</font>";
		}

		$editCitizensLink = "<a href='citizensEditor.php?world=$world'>Edit Citizens</a>";

		$getAllWorldMods = getAllWorldMods($pdo,$world);	
		$runningMods_head = "\n<table border=\"0\" style=\"\">\n";
		$runningMods_foot = "</table>\n";
		$runningMods = $runningMods_head . generateToolTip($pdo,$getAllWorldMods) . $runningMods_foot;
		$modListToolTip = "<a href='#' class='' style='box-shadow:none;border:none;outline:none;' data-trigger='focus' data-toggle='popover' data-placement='bottom' title='Running Mods' data-html='true' data-content='$runningMods'</a>(<label class='alt-color'>view</label>)</a>";
		#### END: runningMod toolTip generator


		echo "<tr>";
		echo "    <td>$mode</td>";
		echo "    <td>$world</td>";
		echo "    <td>$external_endpoint:$port</td>";
		echo "	  <td>$seed</td>";
		echo "    <td>$launchLink | $startLink | $stopLink | $logsLink | $editLink $modListToolTip | $editCitizensLink | $updateLink | $deleteLink</td>";
		echo "</tr>";
	}
}


?>
<!DOCTYPE html>
<html>
	<head>
		<link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css">
                <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css">
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
                                          "emptyTable": "<label style=\"color:red;\">Add your first world by clicking 'Add World' below.</label>"
                                        }
				});
			});
		</script>
	</head>

	<body>

	     <div class="center" style="width:1800px;">
		<table id="worlds" class="display outline" style="text-align:left;" border=0>
		    <thead>
      		        <tr>
			    <th class="alt-color">Engine Mode</th>
			    <th class="alt-color">World</th>
			    <th class="alt-color">External Endpoint</th>
			    <th class="alt-color">Seed</th>
			    <th class="alt-color">Controls</th>
		        </tr>
		    </thead>
		    <tbody>
			<?php populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme); ?>
		    </tbody>
                    <tfoot>
                        <tr>
                    </tfoot>
		</table>
	     </div> 

	   <table id="lowerTable" class="display center" style="width:100%;" border=0>
	      <tr>
	      <td>
	        <table id="commands" class="display outline center" style="text-align:center;width:853px;" border=0>
			<thead>
			   <tr>
				<th class="bottom_line alt-color center" colspan=4>Commands</th>
			  </tr>
			</thead>
			<tbody>
			   <tr>
				<td style="padding:5px;"><a href='new_world.php'><button class="sm-bttn">Add World</button></a></td>
				<td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='/supervisor/'><button class="sm-bttn">Service Management</button></a></td>
				<td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='gridphp/' onclick="return confirm('I hope you know what you\'re doing. \nAre you sure?')"><button class="sm-bttn">Database Browser</button></a></td>
				<td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='fileBrowser.php'><button class="sm-bttn">File Browser</button></a></td>
			</tbody>
		</table>

	     <tr>
	     <td>
		<table id="systemStats" class="display outline center" style="text-align:left;width:853px;" border=0>
			<thead>
			    <tr>
				 <th class="bottom_line alt-color center" colspan=2>Status</th>
			    </tr>
			</thead>
			<tbody>
			    <tr>

                                 <td class="alt-color">PhValheim Server Version<label class="pri-color">:</label></td>
                                 <td><?php echo $phvalheimVersion;?></td>

				 <tr>				

                                 <td class="alt-color">Memory<label class="pri-color">:</label></td>
                                 <td><?php echo 'Total: '; echo getTotalMemory(); echo ' / Free: '; echo getFreeMemory(); echo ' / Used: '; echo getUsedMemory();?></td>

                                 <tr>

                                 <td class="alt-color">Storage<label class="pri-color">:</label></td>
                                 <td><?php echo 'Total: '; echo getTotalDisk('/opt/stateful'); echo ' / Free: '; echo getFreeDisk('/opt/stateful'); echo ' / Used: '; echo getUsedDisk('/opt/stateful');?></td>

                                 <tr>

				 <td class="alt-color">CPU Model<label class="pri-color">:</label></td>
				 <td><?php echo getCpuModel($pdo); ?></td>
				
				 <tr>
		
				 <td class="alt-color">CPU Utilization<label class="pri-color">:</label></td>
				 <td><?php echo getCpuUtilization($pdo); ?></td>				
		
				 <tr>
				
				 <td class="alt-color">Last Thunderstore Sync<label class="pri-color">:</label></td>
				 <td><?php echo getLastTsUpdated($pdo); ?></td>

				 <tr>

				 <td class="alt-color">Last Local Thunderstore Diff Exec<label class="pri-color">:</label></td>
				 <td><?php echo getLastTsLocalDiffExecTime($pdo); ?> <label class="pri-color sm-font-italic"><?php echo getLastTsSyncLocalExecStatus($pdo); ?></label></td>

				 <tr>
		
				 <td class="alt-color">Last Remote Thunderstore Diff Exec<label class="pri-color">:</label></td>
				 <td><?php echo getLastTsRemoteDiffExecTime($pdo); ?> <label class="pri-color sm-font-italic"><?php echo getLastTsSyncRemoteExecStatus($pdo); ?></label></td>

				 <tr>
				
				 <td class="alt-color">Last World Backup Exec<label class="pri-color">:</label></td>
				 <td><?php echo getLastWorldBackupExecTime($pdo); ?> <label class="pri-color sm-font-italic"><?php echo getLastWorldBackupExecStatus($pdo); ?></label></td>
		
				 <tr>
		
				 <td class="alt-color">Last Log Rotate Exec<label class="pri-color">:</label></td>
				 <td><?php echo getLastLogRotateExecTime($pdo); ?> <label class="pri-color sm-font-italic"><?php echo getLastLogRotateExecStatus($pdo); ?></label></td>

				 <tr>

				 <td class="alt-color">Last Utilization Monitor Exec<label class="pri-color">:</label></td>
				 <td><?php echo getLastUtilizationMonitorExecTime($pdo); ?> <label class="pri-color sm-font-italic"><?php echo getLastUtilizationMonitorExecStatus($pdo); ?></label></td>
			</tbody>
			<tfoot>
			</tfoot>
		</table>
	      </td>

	      <tr>

              <td>

                <table id="systemLogs" class="display outline center" style="width:auto;margin-top:2px;" border=0>
                        <thead>
                            <tr>
                                 <th colspan=8 class="bottom_line alt-color" style="text-align:center;">Logs</th>
                            </tr>
                        </thead>
                        <tbody>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=phvalheim.log#bottom'><button class="sm-bttn">Engine</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=mysqld.log#bottom'><button class="sm-bttn">MySQL</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=php.log#bottom'><button class="sm-bttn">PHP</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=nginx.log#bottom'><button class="sm-bttn">NGINX</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=cron.log#bottom'><button class="sm-bttn">CRON</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=tsSync.log#bottom'><button class="sm-bttn">Thunderstore Sync</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=worldBackups.log#bottom'><button class="sm-bttn">World Backup</button></a></td>
                                 <td style="padding:5px;"><a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=logRotater.log#bottom'><button class="sm-bttn">Log Rotater</button></a></td>
                        </tbody>
                        <tfoot>
                        </tfoot>
                </table>
              </td>

               <tr>
                  <td style="padding:10px;padding-bottom:2em;"><label class="alt-color">Current Time</label><label class="pri-color">:</label> <label style="font-size: 20px;"><?php print $timeNow; ?></label></td>

	   </table>

		<style>
		  .popover-title {
		     color: var(--main-alt-color); 
		  }
		</style>

                <script>
                        $(document).ready(function(){
                          $('[data-toggle="popover"]').popover({
                            sanitize:false,
                          });
                        });
                </script>

	</body>
</html>
