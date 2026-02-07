<?php

# Populate required variables from environment
$phvalheimVersion = getenv('phvalheimVersion');
#$phvalheimHost = getenv('phvalheimHost');
$phvalheimHost = $_SERVER['HTTP_HOST'];
$defaultSeed = getenv('defaultSeed');
$gameDNS = getenv('gameDNS');
$basePort = getenv('basePort');
$phvalheimClientURL = getenv('phvalheimClientURL');
$steamAPIKey = getenv('steamAPIKey');
$backupsToKeep = getenv('backupsToKeep');
$sessionTimeout = getenv('sessionTimeout') ?: 2592000;
?>
