<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';


function populateEnabledModList($pdo,$world,$getAllModsLatestVersion) {
        foreach ($getAllModsLatestVersion as $row) {
		$modUUID = $row['moduuid'];
		$modName = $row['name'];
		$modName = substr($modName,0,64);
		$modNameLen = strlen($modName);

		if ($modNameLen == 64) {
			$modName = $modName . "...";
		}

		$modOwner = $row['owner'];
		$modURL = $row['url'];

		$modLastUpdated = $row['version_date_created'];

		$modVersion = $row['version'];
		$modVersion = str_replace("\"","",$modVersion);

		$modSelectedCheck = modSelectedCheck($pdo,$world,$modUUID);

		if ($modSelectedCheck) {
			print "<tr>\n";
			print "  <td style='width:1px;'><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' class='form-check-input' checked></td>\n";
			print "  <td><a target='_blank' href='$modURL'>$modName</a></td>\n";
			print "  <td>$modOwner</td>\n";
			print "  <td>$modLastUpdated</td>\n";
			print "  <td>$modVersion</td>\n";
			print "\n";
		}
	}
	print '<script type="text/javascript">document.body.classList.add("scroll");</script>';
}


function populateDepModList($pdo,$world,$getAllModsLatestVersion) {
        foreach ($getAllModsLatestVersion as $row) {
                $modUUID = $row['moduuid'];
                $modName = $row['name'];
                $modName = substr($modName,0,64);
                $modNameLen = strlen($modName);

                if ($modNameLen == 64) {
                        $modName = $modName . "...";
                }

                $modOwner = $row['owner'];
                $modURL = $row['url'];

                $modLastUpdated = $row['version_date_created'];

                $modVersion = $row['version'];
                $modVersion = str_replace("\"","",$modVersion);

                #$modExistCheck = modExistCheck($pdo,$world,$modUUID);
                $modIsDep = modIsDep($pdo,$world,$modUUID);

                if ($modIsDep) {
                        print "<tr>\n";
                        print "  <td style='width:1px;'><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' class='form-check-input' checked disabled></td>\n";
                        print "  <td><a target='_blank' href='$modURL'>$modName</a> <span class='badge bg-info'>dependency</span></td>\n";
                        print "  <td>$modOwner</td>\n";
                        print "  <td>$modLastUpdated</td>\n";
                        print "  <td>$modVersion</td>\n";
			print "\n";
                }
        }
}


function populateDisabledModList($pdo,$world,$getAllModsLatestVersion) {
        foreach ($getAllModsLatestVersion as $row) {
                $modUUID = $row['moduuid'];
                $modName = $row['name'];
		$modName = substr($modName,0,64);
                $modNameLen = strlen($modName);

                if ($modNameLen == 64) {
                        $modName = $modName . "...";
                }

                $modOwner = $row['owner'];
		$modURL = $row['url'];

		$modLastUpdated = $row['version_date_created'];

                $modVersion = $row['version'];
                $modVersion = str_replace("\"","",$modVersion);
		$modSelectedCheck = modSelectedCheck($pdo,$world,$modUUID);
		$modIsDep = modIsDep($pdo,$world,$modUUID);

                if (!$modSelectedCheck && !$modIsDep) {
                        print "<tr>\n";
                        print "  <td style='width:1px;'><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' class='form-check-input'></td>\n";
                        print "  <td><a target='_blank' href='$modURL'>$modName</a></td>\n";
                        print "  <td>$modOwner</td>\n";
			print "  <td>$modLastUpdated</td>\n";
                        print "  <td>$modVersion</td>\n";
			print "\n";
                }
	}
}


if (!empty($_GET['world'])) {
	$world = $_GET['world'];
}

if(isset($_POST['submit'])) {
	$world = $_POST['world'];

	# remove all mods from this world, clean slate
	deleteAllWorldMods($pdo,$world);

	# update database with new selected mod list
	$thunderstore_mods = $_POST['thunderstore_mods'];

        foreach ($thunderstore_mods as $mod) {
                addModToWorld($pdo,$world,$mod);
        }

	# set database to "update" after editing world
	updateWorld($pdo,$world);

	# go back to admin home after save
	header('Location: index.php');

}


$getAllModsLatestVersion = getAllModsLatestVersion($pdo,$world);

?>

<!DOCTYPE HTML>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Edit World - PhValheim Admin</title>
		<link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css">
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
		<link rel="stylesheet" type="text/css" href="/css/multicheckbox.css">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
                <script>
			// begin document load
                        $(document).ready( function () {
				// begin datatables
                                $('#modtable').DataTable({

                                        "rowCallback": function( row, data, index ) {
                                            if(index%2 == 0){
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myodd');
                                            }else{
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myeven');
                                            }
                                          },

                                        lengthMenu: [
                                            [20, 50, 75, -1],
                                            [20, 50, 75, 'All'],
                                        ],

                                        columnDefs: [
                                         { orderable: false, targets: [ 0, 1, 2, 3, 4 ] },
                                        ],
                                }); // end data tables

				// remove loading spinner after php populates table
                                document.getElementById("spinner").style.display = "none";

                        }); // end document load


			// execute this when the form is submitted
                        function onFormSubmit() {
				// clears search filter and changes list range to all items. This is needed for POST
				$('#modtable').DataTable().page.len('-1').draw();
				$('#modtable').DataTable().search( '' ).draw();
                        }


			// execute this when the submit button is clicked
			function onSubmitClick() {
				// disable scroll bar
				document.body.classList.add("noscroll");
				// display loading spinner
				document.getElementById("spinner").style.display = "flex";
			}

			// only allow the form to be submitted once per page load
			var form_enabled = true;
			$().ready(function(){
			       $('#edit_world').on('submit', function(){
			               if (form_enabled) {
			                       form_enabled = false;
			                       return true;
			               }

			               return false;
			        });
			});

                </script>

	</head>

	<body>
		<div id="spinner" class="loading style-2" style="display:none;"><div class="loading-wheel"></div></div>

		<div class="container-fluid px-3 px-lg-4">
			<!-- Page Header -->
			<div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
				<h4 class="mb-0" style="color: var(--accent-primary);">Edit World Mods</h4>
				<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
			</div>

			<form id="edit_world" name="edit_world" method="post" action="edit_world.php" onSubmit="onFormSubmit()">

				<!-- World Info Card -->
				<div class="card-panel mb-4">
					<div class="card-panel-header">World Information</div>
					<div class="row g-3">
						<div class="col-6 col-md-3">
							<label class="form-label text-secondary small">World Name</label>
							<div class="fw-medium"><?php echo $world ?></div>
						</div>
						<div class="col-6 col-md-3">
							<label class="form-label text-secondary small">Seed</label>
							<div><code><?php print getSeed($pdo,$world);?></code></div>
						</div>
						<div class="col-6 col-md-3">
							<label class="form-label text-secondary small">Date Deployed</label>
							<div><?php print getDateDeployed($pdo,$world);?></div>
						</div>
						<div class="col-6 col-md-3">
							<label class="form-label text-secondary small">Date Updated</label>
							<div><?php print getDateUpdated($pdo,$world);?></div>
						</div>
					</div>
					<hr class="my-3" style="border-color: var(--border-color);">
					<div class="row">
						<div class="col-6">
							<span class="alt-color">Mods Selected:</span> <span class="badge bg-info"><?php print getSelectedModCountOfWorld($pdo,$world);?></span>
						</div>
						<div class="col-6">
							<span class="alt-color">Mods Running:</span> <span class="badge bg-success"><?php print getTotalModCountOfWorld($pdo,$world);?></span>
						</div>
					</div>
				</div>

				<!-- Mod Selection Card -->
				<div class="card-panel mb-4">
					<div class="card-panel-header">Mod Selection</div>
					<div class="table-responsive">
						<table id="modtable" class="table table-hover mb-0" style="width:100%;">
							<thead>
								<tr>
									<th class="alt-color" style="width: 50px;">Select</th>
									<th class="alt-color">Name</th>
									<th class="alt-color">Author</th>
									<th class="alt-color">Last Updated</th>
									<th class="alt-color">Version</th>
								</tr>
							</thead>
							<tbody>
								<?php echo '<script type="text/javascript">document.getElementById("spinner").style.display = "flex";</script>'; ?>
								<?php echo '<script type="text/javascript">document.body.classList.add("noscroll");</script>'; ?>
								<?php populateEnabledModList($pdo,$world,$getAllModsLatestVersion); ?>
								<?php populateDepModList($pdo,$world,$getAllModsLatestVersion); ?>
								<?php populateDisabledModList($pdo,$world,$getAllModsLatestVersion); ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Action Buttons -->
				<div class="d-flex justify-content-center gap-3 mb-4">
					<a href='index.php'><button class="sm-bttn" type="button">Cancel</button></a>
					<button name='submit' id='submit_button' class="sm-bttn" type="submit" onClick='onSubmitClick();' style="background-color: var(--success-dark); border-color: var(--success);">Save Changes</button>
					<input type="hidden" value="<?php echo $world?>" name="world">
				</div>

			</form>
		</div>
	</body>
</html>
