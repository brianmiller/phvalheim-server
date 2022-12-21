<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';


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

function populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme){
	$getWorlds = $pdo->query("SELECT status,mode,name,port,external_endpoint,seed FROM worlds");
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
			$deleteLink = "<a href='?delete_world=$world'>Delete</a>";
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

		echo "<tr>";
		echo "    <td>$mode</td>";
		echo "    <td>$world</td>";
		echo "    <td>$external_endpoint:$port</td>";
		echo "	  <td>$seed</td>";
		echo "    <td>$launchLink | $startLink | $stopLink | $logsLink | $editLink | $editCitizensLink | $updateLink | $deleteLink</td>";
		echo "</tr>";
	}
}


?>
<!DOCTYPE html>
<html>
	<head>
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css">
		<link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
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
				            [10, 25, 50, -1],
					    [10, 25, 50, 'All'],
				        ],
				});
			});
		</script>
	</head>

	<body>
		<table id="worlds" class="display" border=0>
		    <thead>
      		        <tr>
			    <th>Engine Mode</th>
			    <th>World</th>
			    <th>External Endpoint</th>
			    <th>Seed</th>
			    <th>Controls</th>
		        </tr>
		    </thead>
		    <tbody>
			<?php populateTable($pdo,$phvalheimHost,$gameDNS,$httpScheme); ?>
		    </tbody>
		    <tfoot>
			<form>
				<td colspan=5 align=center>
					<a href='new_world.php'><button>Add World</button></a>
					<a target='_blank' rel="noopener noreferrer" href='/supervisor/'><button>Service Management</button></a>
					<a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=phvalheim.log#bottom'><button>Engine Logs</button></a>
					<a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=mysqld.log#bottom'><button>MySQL Logs</button></a>
					<a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=php.log#bottom'><button>PHP Logs</button></a>
					<a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=nginx.log#bottom'><button>NGINX Logs</button></a>
					<a target='_blank' rel="noopener noreferrer" href='readLog.php?logfile=cron.log#bottom'><button>CRON Logs</button></a>
					<a target='_blank' rel="noopener noreferrer" href='fileBrowser.php'><button>File Browser</button></a>
				</td>
			</form>
					<tr>
					<td colspan='5' style='text-align:right;'>
						<div>v<?php echo $phvalheimVersion;?></div>
					</td>
		    </tfoot>
		</table>
	</body>
</html>
