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

	# clone folders if requested
	if (!empty($_POST['cloneSourceWorld'])) {
		$sourceWorld = $_POST['cloneSourceWorld'];
		$cloneConfigs = !empty($_POST['cloneConfigsFlag']) && $_POST['cloneConfigsFlag'] === '1';
		$clonePlugins = !empty($_POST['clonePluginsFlag']) && $_POST['clonePluginsFlag'] === '1';

		$basePath = '/opt/stateful/games/valheim/worlds';
		$sourcePath = $basePath . '/' . $sourceWorld;
		$destPath = $basePath . '/' . $world;

		// Create destination world folder if it doesn't exist
		if (!is_dir($destPath)) {
			mkdir($destPath, 0775, true);
		}

		if ($cloneConfigs) {
			$sourceConfigs = $sourcePath . '/custom_configs';
			$destConfigs = $destPath . '/custom_configs';

			// Create destination directory if it doesn't exist
			if (!is_dir($destConfigs)) {
				mkdir($destConfigs, 0775, true);
			}

			if (is_dir($sourceConfigs)) {
				// Use rsync to copy all contents (including hidden files) and delete anything not in source
				exec("rsync -av --delete " . escapeshellarg($sourceConfigs . '/') . " " . escapeshellarg($destConfigs . '/'));
			} else {
				// Source doesn't exist, just empty the destination
				exec("find " . escapeshellarg($destConfigs) . " -mindepth 1 -delete");
			}
		}

		if ($clonePlugins) {
			$sourcePlugins = $sourcePath . '/custom_plugins';
			$destPlugins = $destPath . '/custom_plugins';

			// Create destination directory if it doesn't exist
			if (!is_dir($destPlugins)) {
				mkdir($destPlugins, 0775, true);
			}

			if (is_dir($sourcePlugins)) {
				// Use rsync to copy all contents (including hidden files) and delete anything not in source
				exec("rsync -av --delete " . escapeshellarg($sourcePlugins . '/') . " " . escapeshellarg($destPlugins . '/'));
			} else {
				// Source doesn't exist, just empty the destination
				exec("find " . escapeshellarg($destPlugins) . " -mindepth 1 -delete");
			}
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
$allWorlds = $pdo->query("SELECT name FROM worlds ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

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
		<style>
			@keyframes pulse-glow {
				0%, 100% {
					box-shadow: 0 0 0 0 var(--success);
					opacity: 1;
				}
				50% {
					box-shadow: 0 0 10px 3px var(--success);
					opacity: 0.8;
				}
			}
			.btn-unsaved-changes {
				animation: pulse-glow 2s infinite;
			}
		</style>
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

								// Track form changes for unsaved changes indicator
								var hasChanges = false;
								var $createButton = $('#submit_button');

								// Track changes on all form inputs and checkboxes
								$('#new_world').on('change', 'input, select, textarea', function() {
									hasChanges = true;
									$createButton.addClass('btn-unsaved-changes');
								});

								// Remove pulsing when form is submitted
								$('#new_world').on('submit', function() {
									hasChanges = false;
									$createButton.removeClass('btn-unsaved-changes');
								});

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
				<div class="d-flex gap-2">
					<button id='submit_button' name='submit_button' form='new_world' class="sm-bttn" type="submit" onClick='onSubmitClick();' style="background-color: var(--success-dark); border-color: var(--success);">Create World</button>
					<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
				</div>
			</div>

			<form id="new_world" name="new_world" method="post" action="new_world.php" onSubmit="onFormSubmit();">
				<!-- Hidden fields for clone options -->
				<input type="hidden" id="cloneSourceWorld" name="cloneSourceWorld" value="">
				<input type="hidden" id="cloneConfigsFlag" name="cloneConfigsFlag" value="0">
				<input type="hidden" id="clonePluginsFlag" name="clonePluginsFlag" value="0">

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
					<?php if (!empty($allWorlds)): ?>
					<div class="mb-4 p-3" style="background-color: var(--bg-tertiary); border-radius: 4px;">
						<label for="copyFromWorld" class="form-label alt-color" style="margin-bottom: 0.75rem; display: block;">Clone mods from another world (optional)</label>
						<div class="d-flex align-items-center" style="margin-bottom: 0.75rem; gap: 0.5rem;">
							<select class="form-select" id="copyFromWorld" style="background-color: var(--bg-input); color: var(--text-primary); border-color: var(--border-color); flex: 1;">
								<option value="">-- Select a world to clone from --</option>
								<?php foreach ($allWorlds as $w): ?>
									<option value="<?php echo htmlspecialchars($w); ?>"><?php echo htmlspecialchars($w); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="copyButton" style="background-color: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color); border: 1px solid; padding: 0.375rem 0.75rem; cursor: pointer; border-radius: 0.25rem; white-space: nowrap;">Clone</button>
						</div>
						<div style="margin-bottom: 0.75rem;">
							<label style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-primary); cursor: pointer; margin-bottom: 0.5rem;">
								<input type="checkbox" id="cloneCustomConfigs" style="cursor: pointer;">
								<span>Also clone custom_configs folder</span>
							</label>
							<label style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-primary); cursor: pointer;">
								<input type="checkbox" id="cloneCustomPlugins" style="cursor: pointer;">
								<span>Also clone custom_plugins folder</span>
							</label>
						</div>
						<div style="font-size: 0.875rem; color: var(--warning);">Warning: Cloning will replace all mod selections with the selected world's mods.</div>
					</div>
					<?php endif; ?>
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
					<button id='submit_button' name='submit_button' class="sm-bttn" type="submit" onClick='onSubmitClick();' style="background-color: var(--success-dark); border-color: var(--success);">Create World</button>
				</div>

			</form>

			<!-- Clone Summary Modal -->
			<div class="modal fade" id="cloneSummaryModal" tabindex="-1" aria-hidden="true" style="z-index: 2100;">
				<div class="modal-dialog modal-lg">
					<div class="modal-content" style="background-color: var(--bg-secondary); border-color: var(--border-color);">
						<div class="modal-header" style="border-bottom-color: var(--border-color);">
							<h5 class="modal-title" style="color: var(--text-primary);">Clone Summary</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(1.5);"></button>
						</div>
						<div class="modal-body" style="color: var(--text-primary);">
							<p style="margin-bottom: 1rem;"><strong>Source World:</strong> <span id="cloneSummarySource" style="color: var(--accent-primary);"></span></p>

							<!-- Mods Section -->
							<div style="margin-bottom: 1rem; padding: 1rem; background-color: var(--bg-tertiary); border-radius: 4px;">
								<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
									<strong>Mods to Clone: <span id="cloneSummaryModCount" style="color: var(--accent-primary);"></span></strong>
									<button type="button" class="btn btn-sm" id="toggleModList" style="background-color: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-color);">View List</button>
								</div>
								<div id="cloneSummaryModList" style="display: none; max-height: 200px; overflow-y: auto; margin-top: 0.5rem; padding: 0.5rem; background-color: var(--bg-input); border-radius: 4px; font-size: 0.875rem;"></div>
							</div>

							<!-- Folders Section -->
							<div id="cloneSummaryFoldersSection" style="display: none;">
								<div id="cloneSummaryConfigs" style="margin-bottom: 1rem; padding: 1rem; background-color: var(--bg-tertiary); border-radius: 4px; display: none;">
									<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
										<strong>custom_configs</strong>
										<button type="button" class="btn btn-sm toggle-folder-list" data-target="configsList" style="background-color: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-color);">View Files</button>
									</div>
									<div id="configsList" style="display: none; max-height: 150px; overflow-y: auto; margin-top: 0.5rem; padding: 0.5rem; background-color: var(--bg-input); border-radius: 4px; font-size: 0.875rem;"></div>
								</div>
								<div id="cloneSummaryPlugins" style="margin-bottom: 1rem; padding: 1rem; background-color: var(--bg-tertiary); border-radius: 4px; display: none;">
									<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
										<strong>custom_plugins</strong>
										<button type="button" class="btn btn-sm toggle-folder-list" data-target="pluginsList" style="background-color: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-color);">View Files</button>
									</div>
									<div id="pluginsList" style="display: none; max-height: 150px; overflow-y: auto; margin-top: 0.5rem; padding: 0.5rem; background-color: var(--bg-input); border-radius: 4px; font-size: 0.875rem;"></div>
								</div>
								<div style="padding: 0.75rem; background-color: var(--warning); border-radius: 4px; color: #000;">
									<strong>Warning:</strong> The selected folders will be completely cleared before cloning. Any existing content will be replaced.
								</div>
							</div>
						</div>
						<div class="modal-footer" style="border-top-color: var(--border-color);">
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="background-color: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color);">Continue Editing</button>
							<button type="button" id="cloneSaveButton" class="btn btn-success" style="background-color: var(--success-dark); border-color: var(--success); color: white;">Create World</button>
						</div>
					</div>
				</div>
			</div>
			<style>
				.modal-backdrop { z-index: 2099 !important; }
			</style>
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

			// Store clone operation data and modal instance
			var pendingCloneData = null;
			var cloneModalInstance = null;

			// Toggle mod list visibility
			$(document).on('click', '#toggleModList', function() {
				var $list = $('#cloneSummaryModList');
				if ($list.is(':visible')) {
					$list.slideUp();
					$(this).text('View List');
				} else {
					$list.slideDown();
					$(this).text('Hide List');
				}
			});

			// Toggle folder list visibility
			$(document).on('click', '.toggle-folder-list', function() {
				var targetId = $(this).data('target');
				var $list = $('#' + targetId);
				if ($list.is(':visible')) {
					$list.slideUp();
					$(this).text('View Files');
				} else {
					$list.slideDown();
					$(this).text('Hide Files');
				}
			});

			// Clone mods from existing world
			$(document).on('click', '#copyButton', function() {
				var selectedWorld = $('#copyFromWorld').val();
				var cloneConfigs = $('#cloneCustomConfigs').is(':checked');
				var clonePlugins = $('#cloneCustomPlugins').is(':checked');

				if (!selectedWorld) {
					alert('Please select a world to clone from');
					return;
				}

				// Show loading state
				$('#copyButton').prop('disabled', true).text('Loading...');

				// Fetch mods with names and folder contents
				var modsPromise = $.ajax({
					url: 'adminAPI.php?action=getWorldModsWithNames&world=' + encodeURIComponent(selectedWorld),
					method: 'GET',
					dataType: 'json'
				});

				var foldersPromise = (cloneConfigs || clonePlugins) ? $.ajax({
					url: 'adminAPI.php?action=getWorldFolderContents&world=' + encodeURIComponent(selectedWorld),
					method: 'GET',
					dataType: 'json'
				}) : Promise.resolve(null);

				Promise.all([modsPromise, foldersPromise]).then(function(results) {
					var modsData = results[0];
					var foldersData = results[1];

					if (!modsData.success) {
						alert('Error loading mods: ' + (modsData.error || 'Unknown error'));
						return;
					}

					// Store data for Save button
					pendingCloneData = {
						selectedWorld: selectedWorld,
						cloneConfigs: cloneConfigs,
						clonePlugins: clonePlugins,
						modUUIDs: modsData.mods.map(function(m) { return m.uuid; })
					};

					// Update modal content
					$('#cloneSummarySource').text(selectedWorld);
					$('#cloneSummaryModCount').text(modsData.count);

					// Build mod list
					var modListHtml = modsData.mods.map(function(m) {
						return '<div style="padding: 0.25rem 0; border-bottom: 1px solid var(--border-color);">' + m.name + '</div>';
					}).join('');
					$('#cloneSummaryModList').html(modListHtml || '<em>No mods</em>').hide();
					$('#toggleModList').text('View List');

					// Handle folder sections
					if (cloneConfigs || clonePlugins) {
						$('#cloneSummaryFoldersSection').show();

						if (cloneConfigs && foldersData) {
							$('#cloneSummaryConfigs').show();
							var configsHtml = foldersData.configs.length > 0 ?
								foldersData.configs.map(function(f) {
									return '<div style="padding: 0.25rem 0;">' + f + '</div>';
								}).join('') : '<em>Empty folder</em>';
							$('#configsList').html(configsHtml).hide();
						} else {
							$('#cloneSummaryConfigs').hide();
						}

						if (clonePlugins && foldersData) {
							$('#cloneSummaryPlugins').show();
							var pluginsHtml = foldersData.plugins.length > 0 ?
								foldersData.plugins.map(function(f) {
									return '<div style="padding: 0.25rem 0;">' + f + '</div>';
								}).join('') : '<em>Empty folder</em>';
							$('#pluginsList').html(pluginsHtml).hide();
						} else {
							$('#cloneSummaryPlugins').hide();
						}
					} else {
						$('#cloneSummaryFoldersSection').hide();
					}

					// Show modal
					cloneModalInstance = new bootstrap.Modal(document.getElementById('cloneSummaryModal'));
					cloneModalInstance.show();

				}).catch(function(err) {
					alert('Error fetching data from server');
					console.error(err);
				}).finally(function() {
					$('#copyButton').prop('disabled', false).text('Clone');
				});
			});

			// Handle Save button in modal - submit form to create world
			$(document).on('click', '#cloneSaveButton', function() {
				if (!pendingCloneData) return;

				var data = pendingCloneData;

				// Store clone source and folder options in hidden fields
				$('#cloneSourceWorld').val(data.selectedWorld);
				$('#cloneConfigsFlag').val(data.cloneConfigs ? '1' : '0');
				$('#clonePluginsFlag').val(data.clonePlugins ? '1' : '0');

				// Check all the mods that are being cloned
				var table = $('#modtable').DataTable();
				var modSet = new Set(data.modUUIDs);

				table.rows().every(function() {
					var row = this.node();
					var checkbox = $(row).find('input[type="checkbox"]');
					if (checkbox.length) {
						var uuid = checkbox.val();
						checkbox.prop('checked', modSet.has(uuid));
					}
				});

				// Close modal and submit form
				if (cloneModalInstance) {
					cloneModalInstance.hide();
				}
				pendingCloneData = null;

				// Trigger the form submission (same as Create World button)
				onFormSubmit();
				onSubmitClick();
				// Submit the form
				var form = document.querySelector('form#new_world');
				if (form) {
					form.submit();
				} else {
					console.error('Form not found');
				}
			});

		</script>
	</body>
</html>
