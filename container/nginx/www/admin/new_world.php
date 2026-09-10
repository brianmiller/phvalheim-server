<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';

$allWorlds = $pdo->query("SELECT name FROM worlds ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE HTML>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>New World - PhValheim Admin</title>
		<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
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
				display: flex;
				gap: 3px;
				align-items: center;
				height: 18px;
			}
			#modProcessingOverlay .processing-spinner span {
				width: 5px;
				height: 5px;
				border-radius: 50%;
				background: var(--accent-primary);
				animation: squiggle-wave 1.4s ease-in-out infinite;
			}
			#modProcessingOverlay .processing-spinner span:nth-child(2) { animation-delay: 0.12s; }
			#modProcessingOverlay .processing-spinner span:nth-child(3) { animation-delay: 0.24s; }
			#modProcessingOverlay .processing-spinner span:nth-child(4) { animation-delay: 0.36s; }
			#modProcessingOverlay .processing-spinner span:nth-child(5) { animation-delay: 0.48s; }
			@keyframes squiggle-wave {
				0%, 100% { transform: translateY(0) scale(1); opacity: 0.4; }
				50% { transform: translateY(-8px) scale(1.3); opacity: 1; }
			}
			/* Dependency removal modal tree */
			.dep-removal-item {
				padding: 0.375rem 0;
				border-bottom: 1px solid var(--border-light);
			}
			.dep-removal-item:last-child {
				border-bottom: none;
			}
			.dep-removal-row {
				display: flex;
				align-items: center;
				gap: 0.5rem;
			}
			.dep-removal-row label {
				display: flex;
				align-items: center;
				gap: 0.5rem;
				cursor: pointer;
				color: var(--text-primary);
				margin: 0;
			}
			.dep-removal-row label a {
				color: var(--accent-secondary);
				text-decoration: none;
			}
			.dep-removal-row label a:hover {
				color: var(--success);
				text-decoration: underline;
			}
			.dep-removal-children {
				padding-left: 1.5rem;
				border-left: 1px dashed var(--border-color);
				margin-left: 0.5rem;
				margin-top: 0.25rem;
			}
			.dep-removal-children.collapsed {
				display: none;
			}
			.tooltip {
				z-index: 2200 !important;
			}
			.tooltip .tooltip-inner {
				max-width: 450px;
				max-height: 150px;
				overflow-y: auto;
				background: var(--bg-secondary);
				border: 1px solid var(--border-color);
				color: var(--text-primary);
				text-align: left;
				padding: 0.75rem 1rem;
				font-size: 0.85rem;
				line-height: 1.6;
				box-shadow: 0 4px 12px rgba(0,0,0,0.4);
				border-radius: 4px;
				scrollbar-width: thin;
				scrollbar-color: var(--border-color) transparent;
			}
			.tooltip .tooltip-inner::-webkit-scrollbar {
				width: 6px;
			}
			.tooltip .tooltip-inner::-webkit-scrollbar-track {
				background: transparent;
				border-radius: 3px;
			}
			.tooltip .tooltip-inner::-webkit-scrollbar-thumb {
				background-color: var(--border-color);
				border-radius: 3px;
			}
			.tooltip .tooltip-inner::-webkit-scrollbar-thumb:hover {
				background-color: var(--text-muted);
			}
			.tooltip .tooltip-arrow::before {
				border-right-color: var(--border-color) !important;
			}
			#depRemovalTree {
				scrollbar-width: thin;
				scrollbar-color: var(--border-color) transparent;
			}
			#depRemovalTree::-webkit-scrollbar {
				width: 6px;
			}
			#depRemovalTree::-webkit-scrollbar-track {
				background: transparent;
				border-radius: 3px;
			}
			#depRemovalTree::-webkit-scrollbar-thumb {
				background-color: var(--border-color);
				border-radius: 3px;
			}
			#depRemovalTree::-webkit-scrollbar-thumb:hover {
				background-color: var(--text-muted);
			}
		</style>
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
	</head>

	<body>
		<div id="spinner" class="loading style-2">
			<div class="loading-dots"><span></span><span></span><span></span><span></span><span></span></div>
			<div class="loading-eye"></div>
		</div>
		<script>
			var sauronTimer = setTimeout(function() {
				var dots = document.querySelector('#spinner .loading-dots');
				var eye = document.querySelector('#spinner .loading-eye');
				if (dots && eye) { dots.style.display = 'none'; eye.style.display = 'block'; }
			}, 4000);
		</script>

		<div class="container-fluid px-3 px-lg-4">
			<!-- Page Header -->
			<div class="d-flex justify-content-between align-items-center py-3 mb-3 border-bottom" style="border-color: var(--accent-primary) !important;">
				<h4 class="mb-0" style="color: var(--accent-primary);">Create New World</h4>
				<div class="d-flex gap-2">
					<!-- The primary action lives in the sticky bar at the bottom of the form.
					     Having "Create World" here as well meant two primary buttons on one
					     page, and the top one sat above the form it submits. -->
					<a href='index.php'><button class="sm-bttn" type="button">Back to Dashboard</button></a>
				</div>
			</div>

			<!-- World Settings Card -->
			<div class="card-panel mb-4">
				<div class="card-panel-header">World Settings</div>
				<div class="row g-3">
					<div class="col-12 col-md-6">
						<label for="world" class="form-label alt-color">World Name</label>
						<input type="text" class="form-control" maxlength="30" name="world" id="world" required placeholder="Enter world name">
						<div class="form-text text-secondary">Alphanumeric characters only, max 30 characters</div>
					</div>
					<div class="col-12 col-md-6" id="seedField">
						<label class="form-label alt-color">World Seed</label>
						<div id="seedInputRow" style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
							<input type="text" class="form-control" name="seed" id="seed" maxlength="10" placeholder="Generated seed" style="flex:1;">
							<button type="button" class="action-btn primary" id="seedGenerateBtn" onclick="document.getElementById('seed').value=Array.from({length:10},()=>'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'[Math.floor(Math.random()*62)]).join('')">Generate</button>
						</div>
						<div class="d-flex align-items-center mb-2" style="gap: 1rem;">
							<div class="form-check">
								<input class="form-check-input" type="radio" name="seedType" id="seedTypeRandom" value="random" checked onchange="toggleSeedMode('random')">
								<label class="form-check-label" for="seedTypeRandom">Random</label>
							</div>
							<div class="form-check">
								<input class="form-check-input" type="radio" name="seedType" id="seedTypeCustom" value="custom" onchange="toggleSeedMode('custom')">
								<label class="form-check-label" for="seedTypeCustom">Custom</label>
							</div>
						</div>
					</div>
					<div class="col-12 col-md-6" id="vanillaSeedNotice" style="display:none;">
						<label class="form-label alt-color">World Seed</label>
						<div class="form-text text-secondary">
							Vanilla worlds always generate a random seed. Choosing your own requires a mod,
							and Valheim itself has no seed option &mdash; the seed is fixed when the world
							is first created.
						</div>
					</div>
				</div>
				<div class="row g-3 mt-1">
					<div class="col-12">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" id="worldCrossplay">
							<label class="form-check-label alt-color" for="worldCrossplay"><strong>Enable crossplay</strong></label>
							<div class="form-text text-secondary">
								Allow Xbox / Microsoft Store players to join. Works on modded and unmodded
								worlds alike &mdash; though players on those platforms cannot install mods,
								so a heavily modded world may not be joinable for them.
							</div>
						</div>
					</div>
					<!--
						Who can join. This used to be implicit: the create path never set `public`
						at all, so every world inherited the column default of 0 -- "use the access
						list" -- with an empty list. Valheim ignores an empty permitted list, so the
						world came up OPEN while its Access tab called it restricted. Making it an
						explicit choice is what stops a world being born in a state nobody picked.
					-->
					<div class="col-12">
						<label class="form-label alt-color"><strong>Who can join</strong></label>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="accessModel" id="accessRestricted" value="restricted" checked>
							<label class="form-check-label" for="accessRestricted">Only players on the access list</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="accessModel" id="accessOpen" value="open">
							<label class="form-check-label" for="accessOpen">Anyone who can reach the server</label>
						</div>
						<div class="form-text text-secondary">
							The access list starts empty, so a restricted world lets nobody in until you add
							players in <em>Settings &rarr; Access</em>. It defaults to restricted deliberately:
							a world that is accidentally locked is a nuisance, one that is accidentally open
							is not.
						</div>
					</div>
					<div class="col-12">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" id="vanillaWorld" onchange="toggleVanillaWorld(this.checked)">
							<label class="form-check-label alt-color" for="vanillaWorld"><strong>Vanilla world (no mods)</strong></label>
							<div class="form-text text-secondary">
								Runs stock Valheim with zero mods and no BepInEx. Players join with the normal
								Valheim client, so no PhValheim client install is needed.
							</div>
						</div>
					</div>
					<div class="col-12" id="vanillaOptions" style="display:none;">
						<div class="card-panel" style="padding:1rem;">
							<div class="row g-3">
								<div class="col-12 col-md-6">
									<label class="form-label alt-color" for="vanillaPassword">Server Password</label>
									<input type="text" class="form-control" id="vanillaPassword" maxlength="64" placeholder="(no password)">
									<div class="form-text text-secondary">Minimum 5 characters, and cannot appear inside the world name.</div>
								</div>
								<div class="col-12 col-md-6 d-flex flex-column justify-content-center">
									<div class="form-check">
										<input class="form-check-input" type="checkbox" id="vanillaListed">
										<label class="form-check-label" for="vanillaListed">List in the public server browser</label>
									</div>
								</div>
								<div class="col-12">
									<div class="form-text text-warning">
										Note: a custom seed needs a mod, so a vanilla world always generates a random one.
										Valheim also requires a password before a world can be listed in the server browser.
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div id="formMsg" class="mt-3 text-center" style="display:none;"></div>
			</div>

			<!-- Mod Selection Card -->
			<div class="card-panel mb-4" id="modSelectionCard" style="position: relative;">
				<div id="modProcessingOverlay"><div class="processing-content"><div class="processing-spinner"><span></span><span></span><span></span><span></span><span></span></div>Processing...</div></div>
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
				<div id="modSelectionArea">
				<!-- Two DataTables used to be stacked, each with its own Show/Search controls,
				     so the page carried two sets of table chrome and ~9,000 available mods
				     pushed the action buttons far below the fold. One at a time instead. -->
				<!-- ALL is the landing tab. Opening on "Selected" showed "No data available
				     in table" for a world being created -- it has nothing selected yet by
				     definition -- and hid the whole catalogue behind a tab nobody had a
				     reason to click. It read as "the mod list is empty".
				     All contains the selected mods too, pinned to the top, so Selected is a
				     filtered view rather than the only place the selection shows up. -->
				<div class="pv-tabbar" id="modTabBar">
					<button type="button" class="pv-tab" data-modtab="modPaneSelected" onclick="switchModTab('modPaneSelected', this)">
						Selected <span class="badge bg-info" id="activeModCount">0</span>
					</button>
					<button type="button" class="pv-tab active" data-modtab="modPaneAll" onclick="switchModTab('modPaneAll', this)">
						All <span class="badge bg-secondary" id="allModCount">0</span>
					</button>
				</div>

				<div class="mod-pane" id="modPaneSelected" style="display:none;">
					<div class="table-responsive">
						<table id="modtable-active" class="table table-hover mb-0" style="width:100%;"></table>
					</div>
				</div>

				<div class="mod-pane" id="modPaneAll">
					<div class="table-responsive">
						<table id="modtable-all" class="table table-hover mb-0" style="width:100%;"></table>
					</div>
				</div>
				</div>
			</div>

			<!-- Shown INSTEAD of the mod card for a vanilla world. It lives outside that
			     card so hiding the card hides every mod control with it -- the header, the
			     clone-from-another-world block and both tables -- rather than leaving an
			     inert "Select Mods (Optional)" panel wrapped around a notice. -->
			<div class="card-panel mb-4" id="vanillaNoModsNotice" style="display:none;">
				<div class="card-panel-header">Mods</div>
				<div style="padding: 1rem; color: var(--text-secondary);">
					This is a vanilla world &mdash; no mods will be installed.
				</div>
			</div>

			<!-- Action Buttons -- sticky so the primary action stays reachable without
			     scrolling past ~9,000 available mods. -->
			<div class="pv-stickybar">
				<a href='index.php'><button class="sm-bttn" type="button">Cancel</button></a>
				<button id="submit_button_bottom" class="sm-bttn" type="button" onclick="submitCreateWorld();" style="background-color: var(--success-dark); border-color: var(--success);">Create World</button>
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
							<button type="button" id="cloneSaveButton" class="btn btn-success" style="background-color: var(--success-dark); border-color: var(--success); color: white;">Create World</button>
						</div>
					</div>
				</div>
			</div>
			<!-- Dependency Removal Modal -->
			<div class="modal fade" id="depRemovalModal" tabindex="-1" aria-hidden="true" style="z-index: 2100;">
				<div class="modal-dialog modal-xl">
					<div class="modal-content" style="background-color: var(--bg-secondary); border-color: var(--border-color);">
						<div class="modal-header" style="border-bottom-color: var(--border-color);">
							<h5 class="modal-title" style="color: var(--text-primary);">Remove Mod &amp; Dependencies</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(1.5);"></button>
						</div>
						<div class="modal-body" style="color: var(--text-primary);">
							<p style="margin-bottom: 1rem;">You are removing <strong><span id="depRemovalModName" style="color: var(--accent-primary);"></span></strong>.</p>
							<div id="depRemovalTree" style="max-height: 250px; overflow-y: auto; padding: 0.75rem 1rem; background-color: var(--bg-tertiary); border-radius: 4px;"></div>
						</div>
						<div class="modal-footer" style="border-top-color: var(--border-color);">
							<button type="button" class="btn btn-secondary" id="depRemovalCancel" style="background-color: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color);">Cancel</button>
							<button type="button" class="btn btn-danger" id="depRemovalConfirm" style="background-color: var(--danger-dark); border-color: var(--danger); color: white;">Remove Selected</button>
						</div>
					</div>
				</div>
			</div>
			<style>
				.modal-backdrop { z-index: 2099 !important; }
			</style>
		</div>

		<script>
			var seedChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
			function generateSeed() {
				return Array.from({length:10}, function() { return seedChars[Math.floor(Math.random()*62)]; }).join('');
			}
			function toggleSeedMode(mode) {
				var input = document.getElementById('seed');
				var btn = document.getElementById('seedGenerateBtn');
				if (mode === 'random') {
					input.value = generateSeed();
					input.readOnly = true;
					input.placeholder = 'Generated seed';
					btn.style.display = '';
				} else {
					input.value = '';
					input.readOnly = false;
					input.placeholder = 'Enter seed';
					btn.style.display = 'none';
				}
			}
			// Generate initial random seed on load
			document.addEventListener('DOMContentLoaded', function() { toggleSeedMode('random'); });

			// Global state
			var allModsData = [];
			var depMap = {};           // moduuid -> [dep uuids]
			var reverseDepMap = {};    // moduuid -> [mods that depend on it]
			var checkedSet = {};       // moduuid -> true for ALL checked mods
			var activeTable = null;    // DataTable for selected mods (top)
			var allTable = null; // DataTable for available mods (bottom)
			var pendingCloneData = null;
			var cloneModalInstance = null;
			var depRemovalModalInstance = null;
			var depRemovalTargetUuid = null;
			var submitting = false;

			// Cookie helpers for persisting settings
			function setCookie(name, value, days) {
				var expires = '';
				if (days) {
					var d = new Date();
					d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
					expires = '; expires=' + d.toUTCString();
				}
				document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
			}
			function getCookie(name) {
				var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
				return match ? decodeURIComponent(match[2]) : null;
			}

			// Restrict special chars in input fields
			$('#world').on('input', function() {
				var c = this.selectionStart,
					r = /[^a-zA-Z0-9]/gi,
					v = $(this).val();
				if(r.test(v)) {
					$(this).val(v.replace(r, ''));
					c--;
				}
				this.setSelectionRange(c, c);
			});

			$('#seed').on('input', function() {
				var c = this.selectionStart,
					r = /[^a-zA-Z0-9]/gi,
					v = $(this).val();
				if(r.test(v)) {
					$(this).val(v.replace(r, ''));
					c--;
				}
				this.setSelectionRange(c, c);
			});

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

			// HTML-escape without jQuery DOM creation
			function escapeHtml(str) {
				return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
			}

			// Get all dependencies recursively for a mod (cached, with cycle detection)
			var allDepsCache = {};
			function getAllDeps(uuid, visited) {
				var isTopLevel = !visited;
				if (isTopLevel && allDepsCache.hasOwnProperty(uuid)) return allDepsCache[uuid];
				if (!visited) visited = {};
				if (visited[uuid]) return [];
				visited[uuid] = true;
				var deps = depMap[uuid] || [];
				var allDeps = [];
				deps.forEach(function(depUuid) {
					allDeps.push(depUuid);
					allDeps = allDeps.concat(getAllDeps(depUuid, visited));
				});
				if (isTopLevel) allDepsCache[uuid] = allDeps;
				return allDeps;
			}

			// Get checked mods that directly depend on this uuid
			function getCheckedReverseDeps(uuid) {
				return (reverseDepMap[uuid] || []).filter(function(rid) {
					return !!checkedSet[rid];
				});
			}

			// Get checked forward deps that no other checked mod (besides uuid) needs
			// Never remove BepInEx mod loader in removal operations
			function isBepInEx(uuid) {
				var info = modInfoMap[uuid];
				return info && /^BepInExPack/i.test(info.name);
			}

			function getOrphanedForwardDeps(uuid) {
				var deps = getAllDeps(uuid);
				return deps.filter(function(depUuid) {
					if (isBepInEx(depUuid)) return false;
					if (!checkedSet[depUuid]) return false;
					var otherDependents = (reverseDepMap[depUuid] || []).filter(function(rid) {
						return rid !== uuid && !!checkedSet[rid];
					});
					return otherDependents.length === 0;
				});
			}

			// Build HTML tree of checked dependents for the removal modal
			function buildDepRemovalTree(uuid, visited) {
				if (!visited) visited = {};
				var directDeps = getCheckedReverseDeps(uuid);
				if (directDeps.length === 0) return '';

				var html = '';
				directDeps.forEach(function(depUuid) {
					if (visited[depUuid] || isBepInEx(depUuid)) return;
					visited[depUuid] = true;

					var info = modInfoMap[depUuid] || { name: depUuid, url: '#' };
					var escapedName = $('<span>').text(info.name).html();
					// Reverse deps (mods that depend on this one)
					var reverseChildHtml = buildDepRemovalTree(depUuid, visited);
					// Forward deps (this mod's own dependencies)
					var forwardChildUuids = (depMap[depUuid] || []).filter(function(dUuid) {
						return !!checkedSet[dUuid] && !visited[dUuid] && !isBepInEx(dUuid);
					});
					var forwardChildHtml = forwardChildUuids.length > 0 ? buildForwardDepTree(forwardChildUuids, visited) : '';
					var childHtml = reverseChildHtml + forwardChildHtml;

					// Build dependency count badge with hover tooltip
					var modDeps = (depMap[depUuid] || []).map(function(dUuid) {
						var dInfo = modInfoMap[dUuid];
						return dInfo ? dInfo.name : null;
					}).filter(Boolean);
					var depBadgeHtml = '';
					if (modDeps.length > 0) {
						var tooltipLines = modDeps.map(function(n) { return $('<span>').text(n).html(); });
						var tooltipHtml = tooltipLines.join('<br>');
						depBadgeHtml = ' <span class="badge bg-secondary" style="font-size: 0.7rem; cursor: help; vertical-align: middle;" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-html="true" data-bs-title="' + tooltipHtml.replace(/"/g, '&quot;') + '">' + modDeps.length + '</span>';
					}

					html += '<div class="dep-removal-item">';
					html += '<div class="dep-removal-row">';
					html += '<label>';
					html += '<input type="checkbox" class="form-check-input dep-removal-check" data-uuid="' + depUuid + '" checked>';
					html += ' <a href="' + info.url + '" target="_blank">' + escapedName + '</a>';
					html += '</label>';
					html += depBadgeHtml;
					html += '</div>';
					if (childHtml) {
						html += '<div class="dep-removal-children">' + childHtml + '</div>';
					}
					html += '</div>';
				});

				return html;
			}

			// Build recursive tree of forward dependencies for the removal modal
			function buildForwardDepTree(uuids, visited) {
				if (!visited) visited = {};
				var html = '';
				uuids.forEach(function(depUuid) {
					if (visited[depUuid] || isBepInEx(depUuid)) return;
					visited[depUuid] = true;

					var depInfo = modInfoMap[depUuid] || { name: depUuid, url: '#' };

					// Recurse into this mod's checked forward deps
					var childUuids = (depMap[depUuid] || []).filter(function(dUuid) {
						return !!checkedSet[dUuid] && !visited[dUuid] && !isBepInEx(dUuid);
					});
					var childHtml = childUuids.length > 0 ? buildForwardDepTree(childUuids, visited) : '';

					// Dep count badge with tooltip
					var modDeps = (depMap[depUuid] || []).map(function(dUuid) {
						var dInfo = modInfoMap[dUuid];
						return dInfo ? dInfo.name : null;
					}).filter(Boolean);
					var depBadgeHtml = '';
					if (modDeps.length > 0) {
						var tooltipLines = modDeps.map(function(n) { return $('<span>').text(n).html(); });
						var tooltipHtml = tooltipLines.join('<br>');
						depBadgeHtml = ' <span class="badge bg-secondary" style="font-size: 0.7rem; cursor: help; vertical-align: middle;" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-html="true" data-bs-title="' + tooltipHtml.replace(/"/g, '&quot;') + '">' + modDeps.length + '</span>';
					}

					html += '<div class="dep-removal-item">';
					html += '<div class="dep-removal-row">';
					html += '<label>';
					html += '<input type="checkbox" class="form-check-input dep-removal-check" data-uuid="' + depUuid + '" checked>';
					html += ' <a href="' + depInfo.url + '" target="_blank">' + escapeHtml(depInfo.name) + '</a>';
					html += '</label>';
					html += depBadgeHtml;
					html += '</div>';
					if (childHtml) {
						html += '<div class="dep-removal-children">' + childHtml + '</div>';
					}
					html += '</div>';
				});
				return html;
			}

			// Show the dependency removal modal
			function showDepRemovalModal(uuid) {
				depRemovalTargetUuid = uuid;

				var info = modInfoMap[uuid] || { name: uuid, url: '#' };
				$('#depRemovalModName').text(info.name);

				var reverseDeps = getCheckedReverseDeps(uuid);
				var orphanedDeps = getOrphanedForwardDeps(uuid);

				var html = '';

				// Section 1: mods that depend on this one
				if (reverseDeps.length > 0) {
					html += '<p style="margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">These selected mods depend on it:</p>';
					var visited = {};
					visited[uuid] = true;
					html += buildDepRemovalTree(uuid, visited);
				}

				// Section 2: orphaned forward deps as a tree
				if (orphanedDeps.length > 0) {
					if (reverseDeps.length > 0) {
						html += '<hr style="border-color: var(--border-color); margin: 0.75rem 0;">';
					}
					html += '<p style="margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">These dependencies are no longer needed:</p>';
					var fwdVisited = {};
					fwdVisited[uuid] = true;
					html += buildForwardDepTree(orphanedDeps, fwdVisited);
				}

				$('#depRemovalTree').html(html);

				// Initialize hoverable Bootstrap tooltips on dep count badges
				$('#depRemovalTree [data-bs-toggle="tooltip"]').each(function() {
					var el = this;
					new bootstrap.Tooltip(el, { container: 'body', trigger: 'manual', html: true });
					$(el).on('mouseenter', function() {
						clearTimeout(el._tipTimeout);
						bootstrap.Tooltip.getInstance(el).show();
					}).on('mouseleave', function() {
						el._tipTimeout = setTimeout(function() {
							var tip = bootstrap.Tooltip.getInstance(el);
							if (tip) tip.hide();
						}, 250);
					});
				});
				// Keep tooltip open when cursor moves into it
				$(document).off('mouseenter.depTip mouseleave.depTip', '.tooltip');
				$(document).on('mouseenter.depTip', '.tooltip', function() {
					var id = $(this).attr('id');
					var trigger = document.querySelector('[aria-describedby="' + id + '"]');
					if (trigger) clearTimeout(trigger._tipTimeout);
				}).on('mouseleave.depTip', '.tooltip', function() {
					var id = $(this).attr('id');
					var trigger = document.querySelector('[aria-describedby="' + id + '"]');
					if (trigger) {
						trigger._tipTimeout = setTimeout(function() {
							var tip = bootstrap.Tooltip.getInstance(trigger);
							if (tip) tip.hide();
						}, 250);
					}
				});

				if (!depRemovalModalInstance) {
					depRemovalModalInstance = new bootstrap.Modal(document.getElementById('depRemovalModal'));
				}
				depRemovalModalInstance.show();
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
					$('#modProcessingOverlay').css('display', 'flex');
					setTimeout(function() {
						rebuildTables();
						$('#modProcessingOverlay').css('display', 'none');
					}, 0);
				} else {
					// Check if any checked mods depend on this one, or if it has orphaned deps
					var checkedDependents = getCheckedReverseDeps(uuid);
					var orphanedDeps = getOrphanedForwardDeps(uuid);
					if (checkedDependents.length > 0 || orphanedDeps.length > 0) {
						// Show modal — don't modify checkedSet yet
						showDepRemovalModal(uuid);
					} else {
						// No dependents or orphaned deps — just remove
						delete checkedSet[uuid];
						$('#modProcessingOverlay').css('display', 'flex');
						setTimeout(function() {
							rebuildTables();
							$('#modProcessingOverlay').css('display', 'none');
						}, 0);
					}
				}
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
					return '<a href="' + info.url + '" target="_blank">' + escapeHtml(name) + '</a>';
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

				// Build rows for each table.
				//
				// "All" holds EVERY mod, selected ones included -- it is a browser, not a
				// leftovers pile. Selected rows are collected separately and concatenated
				// in front so they sit at the top; within each group the API's alphabetical
				// order is preserved (tableConfig sets order: [] so DataTables does not
				// re-sort and undo this).
				var activeRows = [];
				var allSelectedRows = [];
				var allOtherRows = [];

				allModsData.forEach(function(mod) {
					var uuid = mod.moduuid;
					var modName = mod.name.length > 64 ? mod.name.substring(0, 64) + '...' : mod.name;
					var nameHtml = '<a target="_blank" href="' + mod.url + '">' + escapeHtml(modName) + '</a>';

					// Badge logic with hover tooltip
					if (!checkedSet[uuid] && neededDeps[uuid]) {
						nameHtml += ' <span class="badge bg-warning text-dark dep-badge">dependency (deselected)' + buildDepTooltip(uuid) + '</span>';
					} else if (checkedSet[uuid] && isDepOfChecked[uuid]) {
						nameHtml += ' <span class="badge bg-info dep-badge">dependency' + buildDepTooltip(uuid) + '</span>';
					}

					var isChecked = !!checkedSet[uuid];
					var checkbox = '<input type="checkbox" class="form-check-input mod-checkbox" value="' + uuid + '" data-uuid="' + uuid + '"' + (isChecked ? ' checked' : '') + '>';

					var row = [checkbox, nameHtml, escapeHtml(mod.owner), mod.version_date_created, mod.version];

					// A needed-but-unchecked dependency counts as part of the selection --
					// that is what the Selected tab already shows, and it carries the
					// "dependency (deselected)" warning badge -- so it pins to the top too.
					if (isChecked || neededDeps[uuid]) {
						activeRows.push(row);
						allSelectedRows.push(row);
					} else {
						allOtherRows.push(row);
					}
				});

				var allRows = allSelectedRows.concat(allOtherRows);

				if (activeTable && allTable) {
					// Reuse existing DataTables — avoids expensive destroy/recreate
					activeTable.clear().rows.add(activeRows).draw();
					allTable.clear().rows.add(allRows).draw();
				} else {
					// First call: create tables
					var tableConfig = {
					// No initial sort: the row order is meaningful here (selected first), and
					// DataTables' default [[0,'asc']] would re-sort by the checkbox column
					// and scatter them. A user clicking a header still sorts normally.
					order: [],
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

					var savedActiveLen = parseInt(getCookie('phv_active_pageLen'), 10) || 20;
					var savedAllLen = parseInt(getCookie('phv_all_pageLen'), 10) || 20;
					activeTable = $('#modtable-active').DataTable($.extend(true, {}, tableConfig, { data: activeRows, pageLength: savedActiveLen }));
					allTable = $('#modtable-all').DataTable($.extend(true, {}, tableConfig, { data: allRows, pageLength: savedAllLen }));

					// Persist page length changes to cookies
					$('#modtable-active').on('length.dt', function(e, settings, len) {
						setCookie('phv_active_pageLen', len, 365);
					});
					$('#modtable-all').on('length.dt', function(e, settings, len) {
						setCookie('phv_all_pageLen', len, 365);
					});
				}

				// Update count badges
				$('#activeModCount').text(Object.keys(checkedSet).length);
				$('#allModCount').text(allRows.length);
			}

			// Track unsaved changes
			function markChanged() {
				$('#submit_button, #submit_button_bottom').addClass('btn-unsaved-changes');
			}

			// Get all checked mod UUIDs (state-driven, no DOM dependency)
			// A vanilla world has no mods and no custom seed, so hide both rather than
			// letting someone pick mods that will be silently dropped at create time.
			function toggleVanillaWorld(checked) {
				$('#vanillaOptions').toggle(checked);
				// Hide the WHOLE mod card, not just the tables inside it. Hiding only
				// #modSelectionArea left the "Select Mods (Optional)" header and the
				// clone-from-another-world control on screen for a world that can hold
				// no mods at all.
				$('#modSelectionCard').toggle(!checked);
				$('#vanillaNoModsNotice').toggle(checked);

				// Custom seeds are implemented by the ZeroBandwidth-CustomSeed BepInEx mod
				// and Valheim itself has no seed argument, so a vanilla world always gets a
				// random seed. Hide the whole control rather than leaving a field that
				// silently does nothing.
				$('#seedField').toggle(!checked);
				$('#vanillaSeedNotice').toggle(checked);
				if (checked) {
					$('#seedTypeRandom').prop('checked', true);
					toggleSeedMode('random');
				} else if (window.jQuery && $.fn.dataTable) {
					// A DataTable measured while its container was display:none comes back
					// with collapsed column widths. Re-measure whatever is visible now.
					$.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
				}
			}

			function getSelectedMods() {
				return Object.keys(checkedSet);
			}

			// Load mod table via AJAX
			$(document).ready(function() {
				document.body.classList.add("noscroll");

				$.ajax({
					url: 'adminAPI.php?action=getAllModsWithDeps',
					method: 'GET',
					dataType: 'json'
				}).done(function(data) {
					if (!data.success) {
						alert('Error loading mods');
						return;
					}

					allModsData = data.mods;
					buildDepMaps(allModsData);
					buildModInfoMap();

					// Start with empty checkedSet (new world)
					checkedSet = {};

					// Build tables (active will be empty, available will have all mods)
					rebuildTables();

					// Hide spinner
					clearTimeout(sauronTimer); document.getElementById("spinner").style.display = "none";
					document.body.classList.remove("noscroll");
					document.body.classList.add("scroll");

				}).fail(function() {
					alert('Failed to load mod list');
					clearTimeout(sauronTimer); document.getElementById("spinner").style.display = "none";
					document.body.classList.remove("noscroll");
				});

				// Delegated event handler for both tables
				$(document).on('change', '#modtable-active .mod-checkbox, #modtable-all .mod-checkbox', function() {
					var uuid = $(this).data('uuid');
					var isChecked = $(this).prop('checked');
					handleModCheck(uuid, isChecked);
					if (isChecked) markChanged();
				});

				// Dependency removal modal: toggle children when parent unchecked
				$(document).on('change', '.dep-removal-check', function() {
					var $item = $(this).closest('.dep-removal-item');
					var $children = $item.children('.dep-removal-children');
					if ($children.length) {
						if ($(this).prop('checked')) {
							$children.removeClass('collapsed');
							$children.find('.dep-removal-check').prop('checked', true);
						} else {
							$children.addClass('collapsed');
							$children.find('.dep-removal-check').prop('checked', false);
						}
					}
				});

				// Dependency removal modal: Cancel
				$(document).on('click', '#depRemovalCancel', function() {
					if (depRemovalModalInstance) depRemovalModalInstance.hide();
					depRemovalTargetUuid = null;
					$('#modProcessingOverlay').css('display', 'flex');
					setTimeout(function() {
						rebuildTables();
						$('#modProcessingOverlay').css('display', 'none');
					}, 0);
				});

				// Dependency removal modal: dismiss via X/ESC/backdrop
				$('#depRemovalModal').on('hidden.bs.modal', function() {
					// Dispose Bootstrap tooltips to prevent orphaned elements
					$('#depRemovalTree [data-bs-toggle="tooltip"]').each(function() {
						var tip = bootstrap.Tooltip.getInstance(this);
						if (tip) tip.dispose();
					});
					if (depRemovalTargetUuid !== null) {
						depRemovalTargetUuid = null;
						$('#modProcessingOverlay').css('display', 'flex');
						setTimeout(function() {
							rebuildTables();
							$('#modProcessingOverlay').css('display', 'none');
						}, 0);
					}
				});

				// Dependency removal modal: Remove Selected
				$(document).on('click', '#depRemovalConfirm', function() {
					delete checkedSet[depRemovalTargetUuid];
					$('#depRemovalTree .dep-removal-check:checked').each(function() {
						delete checkedSet[$(this).data('uuid')];
					});
					depRemovalTargetUuid = null;
					if (depRemovalModalInstance) depRemovalModalInstance.hide();
					markChanged();
					$('#modProcessingOverlay').css('display', 'flex');
					setTimeout(function() {
						rebuildTables();
						$('#modProcessingOverlay').css('display', 'none');
					}, 0);
				});
			});

			// Submit world creation via AJAX
			// Show one mod table at a time (they used to be stacked).
			// DataTables measures column widths at draw time and gets them wrong for a table
			// inside a display:none container, so re-adjust whichever table just became
			// visible. Using the tables() API avoids depending on the instance variables.
			function switchModTab(paneId, btn) {
				document.querySelectorAll('#modTabBar .pv-tab').forEach(function (t) { t.classList.remove('active'); });
				if (btn) { btn.classList.add('active'); }
				document.querySelectorAll('.mod-pane').forEach(function (p) { p.style.display = 'none'; });
				var pane = document.getElementById(paneId);
				if (pane) { pane.style.display = 'block'; }
				if (window.jQuery && $.fn.dataTable) {
					$.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
				}
			}

			function submitCreateWorld() {
				if (submitting) return;

				var worldName = $('#world').val().trim();
				if (!worldName) {
					$('#formMsg').html("<span class='text-warning'>Please enter a world name</span>").show();
					return;
				}

				submitting = true;
				document.body.classList.add("noscroll");
				document.getElementById("spinner").style.display = "flex";

				var selectedMods = getSelectedMods();

				var isVanilla = $('#vanillaWorld').is(':checked');

				var payload = {
					world: worldName,
					seed: $('#seed').val().trim(),
					// A vanilla world means ZERO mods. Send an empty list rather than
					// relying on the operator having cleared the mod table.
					mods: isVanilla ? [] : selectedMods,
					vanilla: isVanilla ? 1 : 0,
					password: isVanilla ? $('#vanillaPassword').val().trim() : '',
					// Crossplay applies to any world, so it is NOT gated on isVanilla.
					crossplay: $('#worldCrossplay').is(':checked') ? 1 : 0,
					listed: (isVanilla && $('#vanillaListed').is(':checked')) ? 1 : 0,
					// Sent as the CITIZENS access flag (worlds.public), NOT Valheim's -public
					// server browser argument -- that is `listed` above. Same names, opposite
					// meanings; conflating them would publish every open world.
					accessOpen: $('#accessOpen').is(':checked') ? 1 : 0
				};

				// Add clone data if present
				if (pendingCloneData) {
					payload.cloneSourceWorld = pendingCloneData.selectedWorld;
					payload.cloneConfigs = pendingCloneData.cloneConfigs;
					payload.clonePlugins = pendingCloneData.clonePlugins;
				}

				$.ajax({
					url: 'adminAPI.php?action=createWorld',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify(payload),
					dataType: 'json'
				}).done(function(data) {
					if (data.success) {
						window.location.href = 'index.php';
					} else {
						$('#formMsg').html("<span class='text-warning'>" + (data.error || 'Failed to create world') + "</span>").show();
						clearTimeout(sauronTimer); document.getElementById("spinner").style.display = "none";
						document.body.classList.remove("noscroll");
						submitting = false;
					}
				}).fail(function() {
					$('#formMsg').html("<span class='text-danger'>Server error while creating world</span>").show();
					clearTimeout(sauronTimer); document.getElementById("spinner").style.display = "none";
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

			// Clone mods from existing world
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
					submitCreateWorld();
				}, 100);
			});
		</script>
	</body>
</html>
