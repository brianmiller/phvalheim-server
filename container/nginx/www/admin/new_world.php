<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';


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
			print "<td>$modName";
			print "<td>$modOwner";
                } else {
                        print "<tr>";
                        print "<td><li><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' /></input></li></td>\n";
			print "<td>$modName";
			print "<td>$modOwner";
                }
        }
}


if (!empty($_POST)) {
  $world = $_POST['world'];
  $thunderstore_mods = $_POST['thunderstore_mods'];

  if (!empty($_POST['seed'])) {
	$seed = $_POST['seed'];
  } else {
	$seed = $defaultSeed;
  }

  #Add new world to database
  $addWorld = addWorld($pdo,$world,$gameDNS,$seed);
  if ($addWorld == 0) {
	$msg = "World '$world' created...";
	foreach ($thunderstore_mods as $mod) {
                addModToWorld($pdo,$world,$mod);
        }	
	
	#Go back to home after creation	
	header("Location: index.php");
  }

  if ($addWorld == 2) {
        $msg = "World '$world' already exists...";
  }

}

?>

<!DOCTYPE HTML>
<html>
	<head>
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css">
		<link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
		<link rel="stylesheet" type="text/css" href="/css/multicheckbox.css">

		<script>
			$(document).ready( function () {
	    			$('#new_world.disabled').DataTable();
	    		} );
		</script>
	</head>

	<body>
		<form name="new_world" method="post" action="new_world.php">

			<table style="margin-top: 45px;" align=center border=0 id="new_world" class="display">

			    <thead>
				<th>World Name</th>
				<th>Seed</td>
				<th>Thunderstore Mods</th>
			    </thead>

			    <tbody>
				<td><input type="text" name="world"></td>
				<td><input type="text" class="seed" value="<?php echo $defaultSeed ?>"/></td>
				<td>
					<div id="modlist" class="dropdown-check-list" tabindex="100">
        	                        	<span class="anchor">Mods</span>
		                                <ul class="items">

		                                   <table border=1>
	         	                            <th colspan=2>Mod Name</th>
	                	                    <th>Mod Author</th>
	                        	           	<?php populateModList($pdo,$world); ?>
	                                	   </table>
	
		                                </ul>
					</div>
				</td>
			    </tbody>
			
			    <tfoot>
				<td colspan=5 align=center>
					<a href='index.php'><button type="button">Back</button></a>
					<button type="submit">Save</button>
					<div class='visiblemsg' id='notification'><?php print "$msg"; ?></div>
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

                        $('.seed').focus(function() {
                            if (this.value == this.value) {
                                $(this).val("");
                                $(this).unbind();
                            }
                        });


                </script>
	</body>
</html>
