<?php
include '../includes/db_sets.php';
include '../includes/db_gets.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

if (isset($_GET['msg']))
{
	$msg = $_GET['msg'];
} else {
	$msg = "";
}

if (isset($_GET['world']))
{
	$world = $_GET['world'];
        # make the html look prettier replace spaces with new lines... this is just for ease of reading, we don't store carriage returns in the database, see below
	$currentCitizens = getCitizens($pdo,$world);
        $currentCitizens = str_replace(' ', PHP_EOL, $currentCitizens);
}


if (isset($_GET['citizens'],$_GET['world']))
{
	$citizens = $_GET['citizens'];
	$world = $_GET['world'];

	# trim and clean up new input, we don't store carriage returns in the database
	$citizens = str_replace("\r\n", " ", $citizens);
	$citizens = preg_replace('!\s+!', ' ', $citizens);

	setCitizens($pdo,$world,$citizens);
	$currentCitizens = getCitizens($pdo,$world);
	
	# make the html look prettier replace spaces with new lines... this is just for ease of reading, we don't store carriage returns in the database, see above
	$currentCitizens = str_replace(' ', PHP_EOL, $currentCitizens);

	# write changes to disk
	file_put_contents("/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt","// List permitted players ID ONE per line\n".$currentCitizens);
}

# read current allow list, used only to display on page for review
$currentAllowListFile = file_get_contents("/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt");

?>



<!DOCTYPE HTML>
<html>
        <head>

		<meta charset="UTF-8">
	 	<title>PhValheim Citzens Editor</title>
		<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.2.0/css/font-awesome.min.css?refreshcss=<?php echo rand(100, 1000)?>'>
		<link rel="stylesheet" href="/css/phvalheimStyles.css?refreshcss=<?php echo rand(100, 1000)?>">

                <!-- Google Fonts -->
                <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic?refreshcss=<?php echo rand(100, 1000)?>">

                <!-- CSS Reset -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.css?refreshcss=<?php echo rand(100, 1000)?>">

	</head>

	<body>

		<p class='pri-color' style='margin-top: 1%;' align='center'><label class='alt-color'>Note:</label> <i><b>Only</b> listed SteamIDs will be able to join this world.</i></p>
		<p class='pri-color' style='margin-top: -0.7%;font-size: 14px;' align='center'><label class='alt-color'>Note:</label> <i>If left empty, anyone can join.</i></p>
		<form action='citizensEditor.php'>
			<table style="margin-top: 45px;" align='center' border='0' class='outline'>


				<th colspan='2' class='bottom_line'>
					<?php print "World: $world";?>
				</th>


				<tr>
					<th>
						<p class='outline alt-color'>Editor</p>
					</th>
					<th>
						<p class='outline alt-color'>Current List on Disk</p>
					</th>
				</tr>


				<tr>
					<th>
						<textarea class='outline textarea' style='resize: none;' cols='15' rows='20' name='citizens'><?php print $currentCitizens;?></textarea>
					</th>
					<td>
						<textarea class='disabled outline textarea' style='resize: none;' cols='40' rows='20' name='citizens' disabled><?php print $currentAllowListFile;?></textarea>
					</td>
				</tr>


				<tr>
					<td align='center' style='text-align: center;' colspan='2'>
						<table align='center' style='text-align: center;' border=0>
							<td style='padding: 0 5px 0;' align='center' style='text-align: center;'>
								<a href='index.php'><button class='sm-bttn' type="button">Back</button></a>
							<td align='center' style='text-align: center;'>
								<input class='sm-bttn' type="submit" value="Save">
						</table>
					</td>
				</tr>


				<tr>
					<td colspan='2'>
						<div style='text-align: center;'><?php print $msg;?></div>
					</td>
				</tr>

				<input type='hidden' name='world' value='<?php echo $world;?>'></input>
				<input type='hidden' name='msg' value='saved'></input>
			</table>
		</form>
	<body>
</html>
