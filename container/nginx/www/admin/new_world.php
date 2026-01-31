<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';


function populateModList($pdo,$getAllModsLatestVersion) {
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

                print "<tr>\n";
                print "<td style='width:1px;'><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' class='form-check-input' /></td>\n";
                print "<td><a target='_blank' href='$modURL'>$modName</a></td>\n";
                print "<td>$modOwner</td>\n";
		print "<td>$modLastUpdated</td>\n";
                print "<td>$modVersion</td>\n";
	}
	print '<script type="text/javascript">document.body.classList.add("scroll");</script>';
}


if (!empty($_POST)) {
  $world = $_POST['world'];

  if (!empty($_POST['seed'])) {
        $seed = $_POST['seed'];
  } else {
        $seed = $defaultSeed;
  }

  # add new world to database
  $addWorld = addWorld($pdo,$world,$gameDNS,$seed);
  if ($addWorld == 0) {
        $msg = "<span class='text-success'>World '$world' created...</span>";


	# add mods, if any are selected
	if (!empty($_POST['thunderstore_mods'])) {
  	  $thunderstore_mods = $_POST['thunderstore_mods'];
          foreach ($thunderstore_mods as $mod) {
                  addModToWorld($pdo,$world,$mod);
          }
	}

        # go back to admin home after creation
        header("Location: index.php");
  }

  if ($addWorld == 2) {
        $msg = "<span class='text-warning'>World '$world' already exists...</span>";
  }

}

$getAllModsLatestVersion = getAllModsLatestVersion($pdo);

?>

<!DOCTYPE HTML>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>New World - PhValheim Admin</title>
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


                        // execute this when form is submitted
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
				<h4 class="mb-0" style="color: var(--accent-primary);">Create New World</h4>
				<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
			</div>

			<form id="new_world" name="new_world" method="post" action="new_world.php" onSubmit="onFormSubmit();">

				<!-- World Settings Card -->
				<div class="card-panel mb-4">
					<div class="card-panel-header">World Settings</div>
					<div class="row g-3">
						<div class="col-12 col-md-6">
							<label for="world" class="form-label alt-color">World Name</label>
							<input type="text" class="form-control" maxlength="30" name="world" id="world" required placeholder="Enter world name">
							<div class="form-text text-secondary">Alphanumeric characters only, max 30 characters</div>
						</div>
						<div class="col-12 col-md-6">
							<label for="seed" class="form-label alt-color">World Seed</label>
							<input type="text" class="form-control" name="seed" id="seed" maxlength="10" placeholder="<?php echo $defaultSeed ?>">
							<div class="form-text text-secondary">Leave empty for default seed</div>
						</div>
					</div>
					<?php if (!empty($msg)): ?>
					<div class="mt-3 text-center"><?php echo $msg ?></div>
					<?php endif; ?>
				</div>

				<!-- Mod Selection Card -->
				<div class="card-panel mb-4">
					<div class="card-panel-header">Select Mods (Optional)</div>
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
								<?php populateModList($pdo,$getAllModsLatestVersion); ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Action Buttons -->
				<div class="d-flex justify-content-center gap-3 mb-4">
					<a href='index.php'><button class="sm-bttn" type="button">Cancel</button></a>
					<button id='submit_button' name='submit' class="sm-bttn" type="submit" onClick='onSubmitClick();' style="background-color: var(--success-dark); border-color: var(--success);">Create World</button>
				</div>

			</form>
		</div>

		<script>
			/* restrict special chars in input field */
			$('#world').bind('input', function() {
			  var c = this.selectionStart,
			      r = /[^a-zA-Z0-9\\s]/gi,
			      v = $(this).val();
			  if(r.test(v)) {
			    $(this).val(v.replace(r, ''));
			    c--;
			  }
			  this.setSelectionRange(c, c);
			});

			$('#seed').bind('input', function() {
			  var c = this.selectionStart,
			      r = /[^a-zA-Z0-9\\s]/gi,
			      v = $(this).val();
			  if(r.test(v)) {
			    $(this).val(v.replace(r, ''));
			    c--;
			  }
			  this.setSelectionRange(c, c);
			});

		</script>
	</body>
</html>
