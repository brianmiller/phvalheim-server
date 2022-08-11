<?php

include '/opt/stateful/config/phvalheim-frontend.conf';
#include 'includes/config.php';
include 'includes/db_sets.php';
include 'includes/db_gets.php';


if (!empty($_POST)) {
  $mods = $_POST['mod'];
  $world = "Bolverk";
  #add mod to world
#  addModToWorld($pdo,$world,$mods);

#print "<br>";
#print "FOOOOOOOOOOOOOO";
#print "<br>";
#print "$mods";
	foreach ($mods as $mod) {
		addModToWorld($pdo,$world,$mod);
	}
  #delete mod from world
  #deleteModFromWorld($pdo,$world,$mods);
}


function populateModList($pdo) {
	$getAllMods = getAllMods($pdo);	
	foreach ($getAllMods as $row) {
		print "<li><input name='mod[]' value='" . $row['moduuid'] . "' type='checkbox' />" . $row['name'] . "</input></li>\n";
	}
}


?>

<!DOCTYPE HTML>
<html>
        <head>
		<link rel="stylesheet" type="text/css" href="css/multicheckbox.css">
	</head>

	<body>
		<form name="mods" method="post" action="mods.php">
			<div id="list1" class="dropdown-check-list" tabindex="100">
				<span class="anchor">Mods</span>
				<ul class="items">
					<?php populateModList($pdo); ?>
				</ul>
			</div>
			<button type="submit">Add</button>
		</form>

		<script type="text/javascript">
			var checkList = document.getElementById('list1');
			checkList.getElementsByClassName('anchor')[0].onclick = function(evt) {
				if (checkList.classList.contains('visible'))
					checkList.classList.remove('visible');
				else
					checkList.classList.add('visible');
				}
		</script>

	</body>
</html>


