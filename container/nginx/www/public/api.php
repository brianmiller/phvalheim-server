<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';

if (!empty($_GET)) {
  $mode = $_GET['mode'];
  $world = $_GET['world'];


  if ($mode == "getMD5") {
  	print getMD5($pdo,$world);
  }
  


}



?>
