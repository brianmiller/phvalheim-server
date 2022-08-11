<?php

include '/opt/stateful/config/phvalheim-frontend.conf';
#include 'includes/config.php';
include 'includes/db_sets.php';
include 'includes/db_gets.php';

function populateModList($pdo,$world) {

        $getAllMods = getAllMods($pdo,$world);
        foreach ($getAllMods as $row) {
		$modUUID = $row['moduuid'];
		$modName = $row['name'];
		$modOwner = $row['owner'];
		$modExistCheck = modExistCheck($pdo,$world,$modUUID);
		if ($modExistCheck) {
			print "<tr>";
			print "<td><li><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' checked/></input></li></td>\n";
			print "<td>$modOwner";
			print "<td>$modName";
		} else {
			print "<tr>";
			print "<td><li><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' /></input></li></td>\n";
			print "<td>$modOwner";
			print "<td>$modName";
		}
        }
}


if (!empty($_GET['world'])) {
	$world = $_GET['world'];
}

if(isset($_POST['submit'])) {
	$world = $_POST['world'];

	deleteAllWorldMods($pdo,$world);
	$thunderstore_mods = $_POST['thunderstore_mods'];
        foreach ($thunderstore_mods as $mod) {
                addModToWorld($pdo,$world,$mod);
        }

	#set database to "update" after editing world
	updateWorld($pdo,$world);


}

?>

<!DOCTYPE HTML>
<html>
	<head>
		<link rel="stylesheet" type="text/css" href="css/jquery.dataTables.css">
		<script type="text/javascript" charset="utf8" src="js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="js/jquery.dataTables.js"></script>
		<link rel="stylesheet" type="text/css" href="css/multicheckbox.css">

		<script>
			$(document).ready( function () {
	    			$('#new_world.disabled').DataTable();
	    		} );
		</script>
	</head>

	<body>
		<form name="edit_world" method="post" action="edit_world.php">
			<table style="margin-top: 45px;" align=center border=0 id="edit_world" class="display">
			    <thead>
				<th>World Name</th>
				<th>Seed</td>
				<th>Thunderstore Mods</th>
				<th>Custom Mods</th>
			    </thead>
			    <tbody>
				<td><input type="text" value="<?php echo $world;?>" name="world" hidden readonly><?php echo $world ?></input></td>
				<td><p name="seed"><?php echo getSeed($pdo,$world);?></p></td>
	                        <td><div id="modlist" class="dropdown-check-list" tabindex="100">
        	                        <span class="anchor">Mods</span>
	                                <ul class="items">


				   <table border=1>
				     <th colspan=2>Mod Author</th>
				     <th>Mod Name</th>
	                                        <?php populateModList($pdo,$world); ?>
				   </table>




	                                </ul>
        	                </div></td>
				<td><input type="text" name="custom_mods"></td>
			    </tbody>
			    <tfoot>
				<td colspan=5 align=center>
					<a href='index.php'><button type="button">Back</button></a>
					<button name='submit' type="submit">Save</button>
				</td>
			    </tfoot>
			</table>
		</form>
                <script type="text/javascript">
                        var checkList = document.getElementById('modlist');
                        checkList.getElementsByClassName('anchor')[0].onclick = function(evt) {
                                if (checkList.classList.contains('visible'))
                                        checkList.classList.remove('visible');
                                else
                                        checkList.classList.add('visible');
                                }
                </script>
	</body>
</html>
