<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';

$world = '';
if (!empty($_GET['world'])) {
	$world = $_GET['world'];
}

$allWorlds = $pdo->query("SELECT name FROM worlds WHERE name != '$world' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

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
			.dataTables_length, .dataTables_filter {
				padding-top: 0.5rem;
			}
			.dep-badge {
				position: relative;
				cursor: help;
			}
			.dep-badge .dep-tooltip {
				display: none;
				position: absolute;
				top: calc(100% + 2px);
				left: 0;
				background: var(--bg-secondary);
				border: 1px solid var(--border-color);
				padding: 0.75rem 1rem;
				border-radius: 4px;
				white-space: nowrap;
				z-index: 1050;
				font-weight: normal;
				font-size: 0.85rem;
				line-height: 1.6;
				color: var(--text-primary);
				box-shadow: 0 4px 12px rgba(0,0,0,0.4);
			}
			.dep-badge .dep-tooltip::before {
				content: '';
				position: absolute;
				bottom: 100%;
				left: 0;
				right: 0;
				height: 8px;
			}
			.dep-badge:hover .dep-tooltip {
				display: block;
			}
			.dep-badge .dep-tooltip a {
				color: var(--accent-primary);
				text-decoration: none;
			}
			.dep-badge .dep-tooltip a:hover {
				text-decoration: underline;
			}
			#modProcessingOverlay {
				display: none;
				position: absolute;
				top: 0; left: 0; right: 0; bottom: 0;
				background: rgba(0,0,0,0.45);
				z-index: 1040;
				justify-content: center;
				align-items: center;
				border-radius: 4px;
			}
			#modProcessingOverlay .processing-content {
				display: flex;
				align-items: center;
				gap: 0.6rem;
				color: var(--text-primary);
				font-size: 0.9rem;
				font-weight: 500;
			}
			#modProcessingOverlay .processing-spinner {
				width: 18px;
				height: 18px;
				border: 2px solid var(--border-color);
				border-top-color: var(--accent-primary);
				border-radius: 50%;
				animation: proc-spin 0.6s linear infinite;
			}
			@keyframes proc-spin {
				to { transform: rotate(360deg); }
			}
		</style>
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
	</head>

	<body>
		<div id="spinner" class="loading style-2"><div class="loading-wheel"></div></div>

		<div class="container-fluid px-3 px-lg-4">
			<!-- Page Header -->
			<div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
				<h4 class="mb-0" style="color: var(--accent-primary);">Edit World Mods</h4>
				<div class="d-flex gap-2">
					<button id="submit_button" class="sm-bttn" type="button" onclick="submitSaveWorld();" style="background-color: var(--success-dark); border-color: var(--success);">Save Changes</button>
					<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
				</div>
			</div>

			<!-- World Info Card -->
			<div class="card-panel mb-4">
				<div class="card-panel-header">World Information</div>
				<div class="row g-3">
					<div class="col-6 col-md-3">
						<label class="form-label text-secondary small">World Name</label>
						<div class="fw-medium"><?php echo htmlspecialchars($world) ?></div>
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
						<span class="alt-color">Mods Selected:</span> <span id="selectedModCount" class="badge bg-info"><?php print getSelectedModCountOfWorld($pdo,$world);?></span>
					</div>
					<div class="col-6">
						<span class="alt-color">Mods Running:</span> <span id="totalModCount" class="badge bg-success"><?php print getTotalModCountOfWorld($pdo,$world);?></span>
					</div>
				</div>
			</div>

			<!-- Mod Selection Card -->
			<div class="card-panel mb-4" style="position: relative;">
				<div id="modProcessingOverlay"><div class="processing-content"><div class="processing-spinner"></div>Processing...</div></div>
				<div class="card-panel-header">Mod Selection</div>
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
					<div style="font-size: 0.875rem; color: var(--warning);">Warning: Cloning will remove all previously enabled mods and replace them with the selected world's mods.</div>
				</div>
				<?php endif; ?>
				<!-- Selected Mods Table -->
				<div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; background-color: var(--bg-tertiary); border-bottom: 2px solid var(--accent-primary);">
					<span style="font-weight: 600; color: var(--text-primary);">Selected Mods</span>
					<span class="badge bg-info" id="activeModCount">0</span>
				</div>
				<div class="table-responsive" style="margin-bottom: 1.5rem;">
					<table id="modtable-active" class="table table-hover mb-0" style="width:100%;"></table>
				</div>

				<!-- Available Mods Table -->
				<div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; background-color: var(--bg-tertiary); border-bottom: 2px solid var(--accent-primary);">
					<span style="font-weight: 600; color: var(--text-primary);">Available Mods</span>
					<span class="badge bg-secondary" id="availableModCount">0</span>
				</div>
				<div class="table-responsive">
					<table id="modtable-available" class="table table-hover mb-0" style="width:100%;"></table>
				</div>
			</div>

			<!-- Action Buttons -->
			<div class="d-flex justify-content-center gap-3 mb-4">
				<a href='index.php'><button class="sm-bttn" type="button">Cancel</button></a>
				<button id="submit_button_bottom" class="sm-bttn" type="button" onclick="submitSaveWorld();" style="background-color: var(--success-dark); border-color: var(--success);">Save Changes</button>
			</div>

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
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="background-color: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color);">Exit</button>
							<button type="button" id="cloneSaveButton" class="btn btn-success" style="background-color: var(--success-dark); border-color: var(--success); color: white;">Save</button>
						</div>
					</div>
				</div>
			</div>
			<style>
				.modal-backdrop { z-index: 2099 !important; }
			</style>
		</div>

		<script>
			// Global state
			var worldName = '<?php echo addslashes($world); ?>';
			var allModsData = [];
			var depMap = {};           // moduuid -> [dep uuids]
			var reverseDepMap = {};    // moduuid -> [mods that depend on it]
			var checkedSet = {};       // moduuid -> true for ALL checked mods
			var activeTable = null;    // DataTable for selected mods (top)
			var availableTable = null; // DataTable for available mods (bottom)
			var pendingCloneData = null;
			var cloneModalInstance = null;
			var submitting = false;

			// Build dependency maps from mod data
			function buildDepMaps(mods) {
				depMap = {};
				reverseDepMap = {};
				mods.forEach(function(mod) {
					depMap[mod.moduuid] = mod.deps || [];
					(mod.deps || []).forEach(function(depUuid) {
						if (!reverseDepMap[depUuid]) reverseDepMap[depUuid] = [];
						reverseDepMap[depUuid].push(mod.moduuid);
					});
				});
			}

			// Get all dependencies recursively for a mod (with cycle detection)
			function getAllDeps(uuid, visited) {
				if (!visited) visited = {};
				if (visited[uuid]) return [];
				visited[uuid] = true;
				var deps = depMap[uuid] || [];
				var allDeps = [];
				deps.forEach(function(depUuid) {
					allDeps.push(depUuid);
					allDeps = allDeps.concat(getAllDeps(depUuid, visited));
				});
				return allDeps;
			}

			// Handle mod checkbox change
			function handleModCheck(uuid, isChecked) {
				if (isChecked) {
					checkedSet[uuid] = true;
					// Auto-check all recursive dependencies
					var deps = getAllDeps(uuid);
					deps.forEach(function(depUuid) {
						checkedSet[depUuid] = true;
					});
				} else {
					// Just uncheck this mod - no cascading
					delete checkedSet[uuid];
				}
				// Show processing overlay, defer rebuild so browser paints it first
				$('#modProcessingOverlay').css('display', 'flex');
				setTimeout(function() {
					rebuildTables();
					$('#modProcessingOverlay').css('display', 'none');
				}, 0);
			}

			// Build mod info lookup for tooltips
			var modInfoMap = {};
			function buildModInfoMap() {
				modInfoMap = {};
				allModsData.forEach(function(mod) {
					modInfoMap[mod.moduuid] = { name: mod.name, url: mod.url };
				});
			}

			// Build tooltip HTML showing which checked mods depend on this one
			function buildDepTooltip(uuid) {
				var dependents = (reverseDepMap[uuid] || []).filter(function(rid) { return !!checkedSet[rid]; });
				if (dependents.length === 0) return '';
				var lines = dependents.map(function(rid) {
					var info = modInfoMap[rid];
					if (!info) return '';
					var name = info.name.length > 40 ? info.name.substring(0, 40) + '...' : info.name;
					return '<a href="' + info.url + '" target="_blank">' + $('<span>').text(name).html() + '</a>';
				}).filter(function(l) { return l !== ''; });
				return '<span class="dep-tooltip">Required by:<br>' + lines.join('<br>') + '</span>';
			}

			// Rebuild both tables from checkedSet state
			function rebuildTables() {
				// Compute which unchecked mods are needed as deps of checked mods
				var neededDeps = {};
				Object.keys(checkedSet).forEach(function(uuid) {
					getAllDeps(uuid).forEach(function(depUuid) {
						if (!checkedSet[depUuid]) {
							neededDeps[depUuid] = true;
						}
					});
				});

				// Compute which checked mods serve as deps of other checked mods
				var isDepOfChecked = {};
				Object.keys(checkedSet).forEach(function(uuid) {
					(depMap[uuid] || []).forEach(function(depUuid) {
						if (checkedSet[depUuid]) {
							isDepOfChecked[depUuid] = true;
						}
					});
				});

				// Build rows for each table
				var activeRows = [];
				var availableRows = [];

				allModsData.forEach(function(mod) {
					var uuid = mod.moduuid;
					var modName = mod.name.length > 64 ? mod.name.substring(0, 64) + '...' : mod.name;
					var escapedName = $('<span>').text(modName).html();
					var nameHtml = '<a target="_blank" href="' + mod.url + '">' + escapedName + '</a>';

					// Badge logic with hover tooltip
					if (!checkedSet[uuid] && neededDeps[uuid]) {
						nameHtml += ' <span class="badge bg-warning text-dark dep-badge">dependency (deselected)' + buildDepTooltip(uuid) + '</span>';
					} else if (checkedSet[uuid] && isDepOfChecked[uuid]) {
						nameHtml += ' <span class="badge bg-info dep-badge">dependency' + buildDepTooltip(uuid) + '</span>';
					}

					var isChecked = !!checkedSet[uuid];
					var checkbox = '<input type="checkbox" class="form-check-input mod-checkbox" value="' + uuid + '" data-uuid="' + uuid + '"' + (isChecked ? ' checked' : '') + '>';

					var row = [checkbox, nameHtml, $('<span>').text(mod.owner).html(), mod.version_date_created, mod.version];

					if (isChecked || neededDeps[uuid]) {
						activeRows.push(row);
					} else {
						availableRows.push(row);
					}
				});

				// Destroy existing DataTables if they exist
				if (activeTable) {
					activeTable.destroy();
					$('#modtable-active').empty();
				}
				if (availableTable) {
					availableTable.destroy();
					$('#modtable-available').empty();
				}

				// Shared DataTable config
				var tableConfig = {
					scrollY: '400px',
					scrollCollapse: true,
					paging: true,
					lengthMenu: [[20, 50, 75, -1], [20, 50, 75, 'All']],
					columnDefs: [{ orderable: false, targets: [0] }],
					columns: [
						{ title: 'Select', width: '50px', className: 'alt-color' },
						{ title: 'Name', className: 'alt-color' },
						{ title: 'Author', className: 'alt-color' },
						{ title: 'Last Updated', className: 'alt-color' },
						{ title: 'Version', className: 'alt-color' }
					],
					rowCallback: function(row, data, index) {
						$(row).removeClass('myodd myeven').addClass(index % 2 === 0 ? 'myodd' : 'myeven');
					}
				};

				activeTable = $('#modtable-active').DataTable($.extend(true, {}, tableConfig, { data: activeRows }));
				availableTable = $('#modtable-available').DataTable($.extend(true, {}, tableConfig, { data: availableRows }));

				// Update count badges
				$('#activeModCount').text(activeRows.length);
				$('#availableModCount').text(availableRows.length);

				// Update World Information card counts
				var checkedCount = Object.keys(checkedSet).length;
				$('#selectedModCount').text(checkedCount);
				$('#totalModCount').text(checkedCount);
			}

			// Track unsaved changes
			function markChanged() {
				$('#submit_button, #submit_button_bottom').addClass('btn-unsaved-changes');
			}

			// Get all checked mod UUIDs (state-driven, no DOM dependency)
			function getSelectedMods() {
				return Object.keys(checkedSet);
			}

			// Load mod table and world selection via AJAX
			$(document).ready(function() {
				document.body.classList.add("noscroll");

				var modsPromise = $.ajax({
					url: 'adminAPI.php?action=getAllModsWithDeps',
					method: 'GET',
					dataType: 'json'
				});

				var selectionPromise = $.ajax({
					url: 'adminAPI.php?action=getWorldModSelection&world=' + encodeURIComponent(worldName),
					method: 'GET',
					dataType: 'json'
				});

				Promise.all([modsPromise, selectionPromise]).then(function(results) {
					var modsData = results[0];
					var selData = results[1];

					if (!modsData.success || !selData.success) {
						alert('Error loading data');
						return;
					}

					allModsData = modsData.mods;
					buildDepMaps(allModsData);
					buildModInfoMap();

					// Initialize checkedSet from both selected and dep lists
					checkedSet = {};
					(selData.selected || []).forEach(function(uuid) { checkedSet[uuid] = true; });
					(selData.deps || []).forEach(function(uuid) { checkedSet[uuid] = true; });

					// Build and display both tables
					rebuildTables();

					// Hide spinner
					document.getElementById("spinner").style.display = "none";
					document.body.classList.remove("noscroll");
					document.body.classList.add("scroll");

				}).catch(function(err) {
					alert('Failed to load data');
					console.error(err);
					document.getElementById("spinner").style.display = "none";
					document.body.classList.remove("noscroll");
				});

				// Delegated event handler for both tables
				$(document).on('change', '#modtable-active .mod-checkbox, #modtable-available .mod-checkbox', function() {
					var uuid = $(this).data('uuid');
					var isChecked = $(this).prop('checked');
					handleModCheck(uuid, isChecked);
					markChanged();
				});
			});

			// Submit world save via AJAX
			function submitSaveWorld() {
				if (submitting) return;

				submitting = true;
				document.body.classList.add("noscroll");
				document.getElementById("spinner").style.display = "flex";

				var selectedMods = getSelectedMods();

				var payload = {
					world: worldName,
					mods: selectedMods
				};

				// Add clone data if present
				if (pendingCloneData) {
					payload.cloneSourceWorld = pendingCloneData.selectedWorld;
					payload.cloneConfigs = pendingCloneData.cloneConfigs;
					payload.clonePlugins = pendingCloneData.clonePlugins;
				}

				$.ajax({
					url: 'adminAPI.php?action=saveWorldMods',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify(payload),
					dataType: 'json'
				}).done(function(data) {
					if (data.success) {
						window.location.href = 'index.php';
					} else {
						alert(data.error || 'Failed to save mods');
						document.getElementById("spinner").style.display = "none";
						document.body.classList.remove("noscroll");
						submitting = false;
					}
				}).fail(function() {
					alert('Server error while saving mods');
					document.getElementById("spinner").style.display = "none";
					document.body.classList.remove("noscroll");
					submitting = false;
				});
			}

			// Toggle mod list visibility in clone modal
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

			// Clone mods from another world
			$(document).on('click', '#copyButton', function() {
				var selectedWorld = $('#copyFromWorld').val();
				var cloneConfigs = $('#cloneCustomConfigs').is(':checked');
				var clonePlugins = $('#cloneCustomPlugins').is(':checked');

				if (!selectedWorld) {
					alert('Please select a world to clone from');
					return;
				}

				$('#copyButton').prop('disabled', true).text('Loading...');

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

					pendingCloneData = {
						selectedWorld: selectedWorld,
						targetWorld: worldName,
						cloneConfigs: cloneConfigs,
						clonePlugins: clonePlugins,
						modUUIDs: modsData.mods.map(function(m) { return m.uuid; })
					};

					$('#cloneSummarySource').text(selectedWorld);
					$('#cloneSummaryModCount').text(modsData.count);

					var modListHtml = modsData.mods.map(function(m) {
						return '<div style="padding: 0.25rem 0; border-bottom: 1px solid var(--border-color);">' + m.name + '</div>';
					}).join('');
					$('#cloneSummaryModList').html(modListHtml || '<em>No mods</em>').hide();
					$('#toggleModList').text('View List');

					if (cloneConfigs || clonePlugins) {
						$('#cloneSummaryFoldersSection').show();
						if (cloneConfigs && foldersData) {
							$('#cloneSummaryConfigs').show();
							var filteredConfigs = foldersData.configs.filter(function(f) { return f.indexOf('ZeroBandwidth.CustomSeed.cfg') === -1; });
							var configsHtml = filteredConfigs.length > 0 ?
								filteredConfigs.map(function(f) {
									return '<div style="padding: 0.25rem 0;">' + f + '</div>';
								}).join('') : '<em>Empty directory</em>';
							$('#configsList').html(configsHtml).hide();
						} else {
							$('#cloneSummaryConfigs').hide();
						}
						if (clonePlugins && foldersData) {
							$('#cloneSummaryPlugins').show();
							var filteredPlugins = foldersData.plugins.filter(function(f) { return f.indexOf('ZeroBandwidth-CustomSeed') === -1; });
							var pluginsHtml = filteredPlugins.length > 0 ?
								filteredPlugins.map(function(f) {
									return '<div style="padding: 0.25rem 0;">' + f + '</div>';
								}).join('') : '<em>Empty directory</em>';
							$('#pluginsList').html(pluginsHtml).hide();
						} else {
							$('#cloneSummaryPlugins').hide();
						}
					} else {
						$('#cloneSummaryFoldersSection').hide();
					}

					cloneModalInstance = new bootstrap.Modal(document.getElementById('cloneSummaryModal'));
					cloneModalInstance.show();

				}).catch(function(err) {
					alert('Error fetching data from server');
					console.error(err);
				}).finally(function() {
					$('#copyButton').prop('disabled', false).text('Clone');
				});
			});

			// Handle clone Save button
			$(document).on('click', '#cloneSaveButton', function() {
				if (!pendingCloneData) return;

				var data = pendingCloneData;

				// Replace checkedSet with cloned mods
				checkedSet = {};
				data.modUUIDs.forEach(function(uuid) {
					checkedSet[uuid] = true;
				});

				rebuildTables();

				if (cloneModalInstance) {
					cloneModalInstance.hide();
				}

				markChanged();

				// Auto-submit after clone
				setTimeout(function() {
					submitSaveWorld();
				}, 100);
			});
		</script>
	</body>
</html>
