<?php
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
#include 'adminSec.php';

#header("Refresh:1");

if (isset($_GET['msg']))
{
	$msg = $_GET['msg'];
} else {
	$msg = "";
}

if (isset($_GET['worldName']))
{
	$worldName = $_GET['worldName'];
	$worldSeed = getSeed($pdo,$worldName);
	if (!empty($worldSeed))
	{
		$worldMd5 = getMd5($pdo,$worldName);
		$dateDeployed = getDateDeployed($pdo,$worldName);
		$dateUpdated = getDateUpdated($pdo,$worldName);
		$hideSeed = GetHideSeed($pdo,$worldName);
	} else {
		print "ERROR: invalid world";
		$worldName = "invalid";
	}

} else {
        $worldName = NULL;
}


function populateJsHtml($worldName,$hideSeed) {

	# dynamic hideSeed javascript
	echo "
		<script>
			function hideSeedSwitch_$worldName(cb)
			{
				if($(cb).is(':checked'))
	                       	{
	                        	$.getScript(\"setters.php?type=hideseed&value=1&worldName=$worldName\");
	                        }
	                       	if(!$(cb).is(':checked'))
	                        {
	                        	$.getScript(\"setters.php?type=hideseed&value=0&worldName=$worldName\");
	                        }
	                }
		</script>
	";

}


# hideSeed check
if ($hideSeed == '1' ) {
	$hideSeedSwitch = "<input class='switch' checked type='checkbox' onclick='hideSeedSwitch_$worldName(this)';><span class='slider round'></span>";
} else {
	$hideSeedSwitch = "<input class='switch' type='checkbox' onclick='hideSeedSwitch_$worldName(this)'><span class='slider round'></span>";
}


?>


<!DOCTYPE HTML>
<html>
        <head>

		<meta charset="UTF-8">
	 	<title>World Settings</title>
		<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css'>
		<link rel="stylesheet" href="/css/phvalheimStyles.css">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>

                <!-- Google Fonts -->
                <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">

                <!-- CSS Reset -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css">

	</head>

	<body>
			<?php populateJsHtml($worldName,$hideSeed);?>

			<table class='outline settings' align='center' border='0'>

				<th colspan='2' class='bottom_line settings' style='padding: 10px 0 10px;'>
				Settings for <label class='pri-color'> <?php print $worldName; ?> </label>
				</th>


				<tr>
				<!-- read only -->

				<td>
					<label class=''>MD5:</label>
				</td>
				<td>
					<label class=''><?php print $worldMd5; ?></label>
				</td>
				<tr>
                                <td>
                                	<label class=''>Seed:</label>
                                </td>
                                <td>
                                	<label class=''><?php print $worldSeed; ?></label>
				</td>
                                <tr>
                                <td>
                                	<label class=''>Date Deployed:</label>
                                </td>
                                <td>
                                	<label class=''><?php print $dateDeployed; ?></label>
				</td>
                                <tr>
                                <td>
                                	<label class=''>Date Updated:</label>
                               	</td>
                                <td>
                                	<label class=''><?php print $dateUpdated; ?></label>
				</td>


				<!-- writable -->
				<tr>

                                <td>
                                	<label class=''>Hide seed from public UI:</label>
                                </td>
                                <td>
                                	<label class='switch'><?php print $hideSeedSwitch; ?></label>
                                </td>			

				</tr>

				<tr>

                                <td align='center' style='text-align: center;' colspan='2'>
                                        <table align='center' style='text-align: center;' border=0>
                                                <td style='padding: 10px 5px 5px;' align='center' style='text-align: center;'>
                                                        <a href='index.php'><button class='sm-bttn' type="button">Done</button></a>
                                        </table>
                                </td>
				<!-- <th colspan='2' class='bottom_line_solid'> -->

	<body>
</html>
