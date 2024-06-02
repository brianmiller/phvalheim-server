<?php
include '../includes/db_sets.php';

if (!empty($_GET['type'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$world = $_GET['worldName'];

	if ($type == "autostart") {
		setAutoStart($pdo,$world,$value);
	}

}

?>
