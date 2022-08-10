<?php

include '/opt/stateful/config/phvalheim-frontend.conf';
#include 'config.php';

#return codes: 0=world created, 1=world failed to create, 2=world exists
function addWorld($pdo,$new_world,$external_endpoint,$seed){
	if (!empty($new_world)){
		$sql = "SELECT name FROM worlds WHERE name='$new_world'";
		$result = $pdo->query($sql);
		$row = $result->fetch(PDO::FETCH_ASSOC);
		$result = $row['name'] ?? 'placeholder';
	
		if (strcmp($new_world, $result) !== 0){
		 	$update = $pdo->exec( "INSERT INTO worlds (mode,status,name,external_endpoint,seed) VALUES ('create','Down','$new_world','$external_endpoint','$seed') ");
			return 0;			
		} else {
			return 2;
		}
	}
}


function deleteAllWorldMods($pdo,$world) {
	$sql = "UPDATE worlds SET thunderstore_mods='' WHERE name='$world';";
        if ($pdo->query($sql)) {
                $msg = "Purging mods for world '$world'...";
        } else {
                $msg = "ERROR: Coult not purge mods for '$world'...";
        }
}


function addModToWorld($pdo,$world,$mods) {
	$sql = "SELECT thunderstore_mods FROM worlds WHERE name='$world'";
        $result = $pdo->query($sql);
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $previous_mods = $row['thunderstore_mods'] ?? 'placeholder';

	if (strpos($previous_mods, $mods) !== false) {
		$msg = "Mod already exists in '$world', skipping...";
	} else {
		$mods = "$previous_mods $mods";
	        $update = $pdo->exec( "UPDATE worlds SET thunderstore_mods='$mods' WHERE name='$world'" );
                if ($pdo->query($sql)) {
                        $msg = "Adding mod '$mods' to world $world...";
                } else {
                        $msg = "ERROR: Coult not add '$mods' to '$world'...";
                }
	}
}


function deleteModFromWorld($pdo,$world,$mod) {
        $sql = "SELECT thunderstore_mods FROM worlds WHERE name='$world'";
        $result = $pdo->query($sql);
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $worldMods = $row['thunderstore_mods'] ?? 'default_value';
	$updatedWorldMods = str_replace($mod,'',$worldMods);

	$sql = "UPDATE worlds SET thunderstore_mods='$updatedWorldMods' WHERE name='$world'";
        if ($pdo->query($sql)) {
        	$msg = "Updating mods for world '$world'...";
        } else {
                $msg = "ERROR: Coult not update mods for '$world'...";
        }
}


function deleteWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='delete' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Deleting world $world...";
                } else {
                        $msg = "ERROR: Coult not delete $world...";
                }
        }
}


function stopWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='stop' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Stopping world $world...";
                } else {
                        $msg = "ERROR: Coult not stop $world...";
                }
        }
}


function startWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='start' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Starting world $world...";
                } else {
                        $msg = "ERROR: Coult not start $world...";
                }
        }
}

function updateWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='update' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Updating world $world...";
                } else {
                        $msg = "ERROR: Coult not update $world...";
                }
        }
}
?>
