<?php
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include 'adminSec.php';

if (isset($_GET['msg']))
{
	$msg = $_GET['msg'];
} else {
	$msg = "";
}

$adminMsg = '';

if (isset($_GET['addGlobalAdmin']))
{
	$addGlobalAdmin = $_GET['addGlobalAdmin'];
	$adminMsg = addGlobalAdmin($pdo,$addGlobalAdmin);

}

if (isset($_GET['deleteGlobalAdmin']))
{
	$deleteGlobalAdmin = $_GET['deleteGlobalAdmin'];
	$adminMsg = deleteGlobalAdmin($pdo,$deleteGlobalAdmin);
}

function populateAdminList($pdo) {
        $getGlobalAdmins = getGlobalAdmins($pdo);
        foreach ($getGlobalAdmins as $row) {
		print "<option>$row</option>";
        }
}

?>


<!DOCTYPE HTML>
<html>
        <head>

		<meta charset="UTF-8">
	 	<title>PhValheim System Settings</title>
		<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css'>
		<link rel="stylesheet" href="/css/phvalheimStyles.css">

                <!-- Google Fonts -->
                <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">

                <!-- CSS Reset -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css">

	</head>

	<body>

			<table class='outline settings' align='center' border='0'>

				<th colspan='2' class='bottom_line settings' style='padding: 10px 0 10px;'>
					Settings
				</th>


				<tr>
					<th>
						<label class='alt-color settings'>PhValheim Admins</label>
					</th>
					<th>
						<table align='center' style='text-align: center;' border=0>

							<th class='alt-color'>New admin's SteamID</th>
							<th class='right_line_solid'></th>
							<th class='alt-color'>Admin to delete</th>
	
							<tr>

							<td>
                                                                <form id='addGlobalAdminForm' action="settings.php">
                                                                        <input type="text" maxlength='17' size='17' name="addGlobalAdmin" id="addGlobalAdmin" onfocus="this.value=''" value="7656xxxxxxxxxxxxx"></input>
                                                                </form>
							</td>

							<td class='right_line_solid'></td>

                                                        <td>
                                                                <form id='deleteGlobalAdminForm' action="settings.php">
                                                                        <select name="deleteGlobalAdmin" id="deleteGlobalAdmin">
										<?php populateAdminList($pdo)?>
                                                                        </select>
                                                                </form>
                                                        </td>


							<tr>							

                                                        <td style='padding: 5px 5px 0;' align='center' style='text-align: center;'>
                                                                <button class='sm-bttn' type="submit" form='addGlobalAdminForm'>Add</button>
                                                        </td>

							<td class='right_line_solid'></td>

							<td style='padding: 5px 5px 0;' align='center' style='text-align: center;'>
								<button class='sm-bttn' type="submit" form='deleteGlobalAdminForm'>Delete</button>
							</td>
	
							<tr>

							<td style='padding: 5px 5px 0;' colspan='3'>
								<label><?php print $adminMsg?></label>
							</td>


						</table>
					</th>
				</tr>


				<tr>
				<!-- <th colspan='2' class='bottom_line_solid'> -->

	<body>
</html>
