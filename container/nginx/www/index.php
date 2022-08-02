<?php
include 'includes/config.php';
include 'includes/db_sets.php';

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

function populateTable($pdo,$phvalheimHost,$alias,$domain){
	$getWorlds = $pdo->query("SELECT status,mode,name,port,external_endpoint FROM worlds");
	foreach($getWorlds as $row)
	{
		$status = $row['status'];
		$mode = $row['mode'];
		$world = $row['name'];
		$port = $row['port'];
		$password = "hammertime";
		#$password = $row['password'];
		$external_endpoint = $row['external_endpoint'];
		$launchString = base64_encode("launch?$world?$password?$alias.$domain?$port?$phvalheimHost");
		#$logsLink = "<a href='/readLog.php?logfile=valheimworld_$world.log'>Logs</a>";

		$logsLink = "<a href=\"#\" onClick=\"window.open('/readLog.php?logfile=valheimworld_$world.log','logReader','resizable,height=750,width=1600'); return false;\">Logs</a><noscript>You need Javascript to use the previous link or use <a href=\"/readLog.php?logfile=valheimworld_$world.log\" target=\"_blank\" rel=\"noreferrer noopener\">Logs</a></noscript>";


		if ($mode == 'stopped') {
			$editLink = "<a disabled href='edit_world.php?world=$world'>Edit</a>";
			$startLink = "<a href='?start_world=$world'>Start</a>";
			$stopLink = "<font color=lightgrey>Stop</font>";
			$deleteLink = "<a href='?delete_world=$world'>Delete</a>";
			$updateLink = "<a href='?update_world=$world'>Update</a>";
			$launchLink = "<font color=lightgrey>Launch</font>";
		} else {
			$editLink = "<font color=lightgrey>Edit</font>";
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

		echo "<tr>";
		echo "    <td>$mode</td>";
		echo "    <td>$world</td>";
		echo "    <td>$external_endpoint:$port</td>";
		echo "    <td>$launchLink | $startLink | $stopLink | $logsLink | $editLink | $updateLink | $deleteLink</td>";
		echo "</tr>";
	}
}


?>


<html>
	<head>
		<link rel="stylesheet" type="text/css" href="css/jquery.dataTables.css">
		<script type="text/javascript" charset="utf8" src="js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="js/jquery.dataTables.js"></script>
		<script>
			$(document).ready( function () {
	    			$('#worlds').DataTable();
	    		} );
		</script>
	</head>

	<body>
		<table id="worlds" class="display">
		    <thead>
      		        <tr>
			    <th>Engine Mode</th>
			    <th>World</th>
			    <th>External Endpoint</th>
			    <th>Controls</th>
		        </tr>
		    </thead>
		    <tbody>
			<?php populateTable($pdo,$phvalheimHost,$alias,$domain); ?>
		    </tbody>
		    <tfoot>
			<form>
				<td colspan=4 align=center><a href='new_world.php'><button>Add</button></a></td>
			</form>
		    </tfoot>
		</table>
	</body>
</html>
