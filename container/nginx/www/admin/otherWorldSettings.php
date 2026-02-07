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
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>World Settings - PhValheim Admin</title>
		<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
		<link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
	</head>

	<body>
		<?php populateJsHtml($worldName,$hideSeed);?>

		<div class="container-fluid px-3 px-lg-4">
			<!-- Page Header -->
			<div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
				<h4 class="mb-0" style="color: var(--accent-primary);">World Settings</h4>
				<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
			</div>

			<!-- Settings Card -->
			<div class="card-panel" style="max-width: 600px; margin: 0 auto;">
				<div class="card-panel-header">Settings for <span class="pri-color"><?php print $worldName; ?></span></div>

				<!-- Read-only Information -->
				<div class="mb-4">
					<h6 class="text-secondary mb-3">World Information</h6>
					<table class="table table-sm table-borderless mb-0">
						<tbody>
							<tr>
								<td class="alt-color" style="width: 40%;">MD5 Hash</td>
								<td><code class="small"><?php print $worldMd5; ?></code></td>
							</tr>
							<tr>
								<td class="alt-color">Seed</td>
								<td><code><?php print $worldSeed; ?></code></td>
							</tr>
							<tr>
								<td class="alt-color">Date Deployed</td>
								<td><?php print $dateDeployed; ?></td>
							</tr>
							<tr>
								<td class="alt-color">Date Updated</td>
								<td><?php print $dateUpdated; ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<hr style="border-color: var(--border-color);">

				<!-- Configurable Settings -->
				<div class="mt-4">
					<h6 class="text-secondary mb-3">Privacy Settings</h6>
					<div class="d-flex justify-content-between align-items-center p-3" style="background: var(--bg-primary); border-radius: 0.375rem;">
						<div>
							<span class="d-block">Hide seed from public UI</span>
							<small class="text-secondary">When enabled, the world seed will not be visible on the public player interface.</small>
						</div>
						<label class='switch ms-3'><?php print $hideSeedSwitch; ?></label>
					</div>
				</div>

				<!-- Done Button -->
				<div class="text-center mt-4">
					<a href='index.php'><button class='sm-bttn'>Done</button></a>
				</div>
			</div>
		</div>
	</body>
</html>
