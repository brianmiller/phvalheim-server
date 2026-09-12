<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
# setHungHeads() re-validates against $PHVALHEIM_BOSSES, so the registry must be loaded
# even when a caller only pulled in db_sets.php.
require_once '/opt/stateless/nginx/www/includes/bosses.php';

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
	# world_mods FIRST, because since 2.43 that table -- not these two columns -- is what
	# the engine actually installs from. Clearing only the legacy columns (which is all
	# this did) would leave every world_mods row in place, so switching a world to vanilla
	# would report "mods purged" and then build it with its full mod list anyway.
	#
	# Both is_dep=0 and is_dep=1 go: a dependency with nothing left depending on it is not
	# something to keep installed.
	# The legacy worlds.thunderstore_mods / _deps columns are deliberately NOT touched.
	# They are a frozen record of what dbUpdate_2.43.sh migrated from, kept for rollback;
	# nothing reads or writes them any more, and clearing them here would destroy the only
	# copy of a pre-2.43 selection.
	$sth = $pdo->prepare(
		"DELETE wm FROM world_mods wm
		   JOIN worlds w ON w.id = wm.world_id
		  WHERE w.name = ?");
	$sth->execute([$world]);
}


function deleteWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='delete' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Deleting world $world...";
                } else {
                        $msg = "ERROR: Could not delete $world...";
                }
        }
}


function stopWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='stop' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Stopping world $world...";
                } else {
                        $msg = "ERROR: Could not stop $world...";
                }
        }
}


function startWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='start' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Starting world $world...";
                } else {
                        $msg = "ERROR: Could not start $world...";
                }
        }
}

function updateWorld($pdo,$world){
        if (!empty($world)){
                $sql = "UPDATE worlds SET mode='update' WHERE name='$world'";
                if ($pdo->query($sql)) {
                        $msg = "Updating world '$world'...";
                } else {
                        $msg = "ERROR: Could not update $world...";
                }
        }
}

function setCitizens($pdo,$world,$citizen){
        #$sql = "SELECT citizens FROM worlds WHERE name='$world'";
        #$result = $pdo->query($sql);
        #$row = $result->fetch(PDO::FETCH_ASSOC);
        #$currentCitizens = $row['citizens'];

	$sql = "UPDATE worlds SET citizens='$citizen' WHERE name='$world'";
        if ($pdo->query($sql)) {
		$msg = "Updating citizens for world $world...";
        } else {
                $msg = "ERROR: Could not update citizens for $world...";
        }
}

function setPublic($pdo,$world,$public){
        $sql = "UPDATE worlds SET public=$public WHERE name='$world'";
        if ($pdo->query($sql)) {
                $msg = "Setting world $world to public...";
        } else {
                $msg = "ERROR: Could not set $world to public...";
        }
}

function setAutoStart($pdo,$world,$mode){
        $sql = "UPDATE worlds SET autostart=$mode WHERE name='$world'";
        if ($pdo->query($sql)) {
                $msg = "Setting world $world to autostart...";
        } else {
                $msg = "ERROR: Could not set $world to autostart...";
        }
}

function setHideSeed($pdo,$world,$mode){
        $sql = "UPDATE worlds SET hideseed=$mode WHERE name='$world'";
        if ($pdo->query($sql)) {
                $msg = "Hiding seed for $world...";
        } else {
                $msg = "ERROR: Could not hide seed for $world...";
        }
}

function saveWorldBackupSettings($pdo, $worldName, $settings) {
        $allowedFields = [
                'backup_use_global' => 'int',
                'backup_interval_minutes' => 'int',
                'backup_require_activity' => 'int',
                'backup_retain_all_hours' => 'int',
                'backup_retain_daily_days' => 'int',
                'backup_retain_weekly_days' => 'int',
                'backup_retain_monthly_months' => 'int',
                'backup_compression' => 'string',
                'backup_compression_hour' => 'int',
                'backup_cpu_priority' => 'int',
                'backup_io_priority' => 'string',
                'backup_compression_level' => 'int',
        ];

        $updates = [];
        $params = [];
        foreach ($settings as $key => $value) {
                if (!isset($allowedFields[$key])) continue;
                $updates[] = "$key = ?";
                $params[] = $allowedFields[$key] === 'string' ? (string)$value : (int)$value;
        }

        if (empty($updates)) return false;

        $params[] = $worldName;
        $sql = "UPDATE worlds SET " . implode(', ', $updates) . " WHERE name = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
}

function deleteBackupRecord($pdo, $backupId) {
        $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
        return $stmt->execute([$backupId]);
}

function setVanilla($pdo,$world,$vanilla){
	$sth = $pdo->prepare("UPDATE worlds SET vanilla=? WHERE name=?");
	return $sth->execute([(int)$vanilla, $world]);
}

function setWorldPassword($pdo,$world,$password){
	$sth = $pdo->prepare("UPDATE worlds SET password=? WHERE name=?");
	return $sth->execute([$password === '' ? NULL : $password, $world]);
}

function setCrossplay($pdo,$world,$crossplay){
	$sth = $pdo->prepare("UPDATE worlds SET crossplay=? WHERE name=?");
	return $sth->execute([(int)$crossplay, $world]);
}

# `listed` is Valheim's -public server browser flag. NOT the same as setPublic(),
# which is the CITIZENS access-control flag.
function setListed($pdo,$world,$listed){
	$sth = $pdo->prepare("UPDATE worlds SET listed=? WHERE name=?");
	return $sth->execute([(int)$listed, $world]);
}

function setPasswordPublic($pdo,$world,$visible){
	$sth = $pdo->prepare("UPDATE worlds SET password_public=? WHERE name=?");
	return $sth->execute([(int)$visible, $world]);
}

function setLaunchParams($pdo,$world,$params){
	$sth = $pdo->prepare("UPDATE worlds SET launch_params=? WHERE name=?");
	return $sth->execute([$params === '' ? NULL : $params, $world]);
}

function setAdmins($pdo,$world,$admins){
	$sth = $pdo->prepare("UPDATE worlds SET admins=? WHERE name=?");
	return $sth->execute([$admins, $world]);
}

function setBanned($pdo,$world,$banned){
	$sth = $pdo->prepare("UPDATE worlds SET banned=? WHERE name=?");
	return $sth->execute([$banned, $world]);
}

# $hungHead MUST already be a worlds column name resolved through
# bossColumnForPrefab() in includes/bosses.php. A column name cannot be bound as a
# parameter, so this re-validates against the registry rather than trusting the caller --
# this endpoint is reachable unauthenticated by the companion mod.
function setHungHeads($pdo,$world,$hungHead) {
	global $PHVALHEIM_BOSSES;

	$known = false;
	foreach ($PHVALHEIM_BOSSES as $boss) {
		if ($boss['column'] === $hungHead) {
			$known = true;
			break;
		}
	}
	if (!$known) {
		return false;
	}

	$sth = $pdo->prepare("SELECT `$hungHead` FROM worlds WHERE name = ?");
	$sth->execute([$world]);
	$row = $sth->fetch();

	# unknown world
	if ($row === false) {
		return false;
	}

	# hung head is already seen by the database
	if ($row[$hungHead] == "1") {
		return true;
	}

	# tell the database a new head has been hung
	$sth = $pdo->prepare("UPDATE worlds SET `$hungHead` = 1 WHERE name = ?");
	return $sth->execute([$world]);
}

?>
