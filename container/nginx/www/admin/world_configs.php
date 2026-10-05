<?php
/**
 * The mod config editor (2.55).
 *
 * ONE page, TWO entry points. With ?mod=<id> it shows that mod's config, which is where the
 * Config icon in the mod picker lands. Without it, it shows every config the world has --
 * which is the "dedicated editor listing all installed mods and their configs" view, for free,
 * from the same code. Building them as two pages would have meant two renderers for one
 * payload.
 *
 * The form is generated from the config file's OWN metadata. BepInEx writes `# Setting type:`,
 * `# Default value:` and `# Acceptable values:` above every entry, so a typed widget per
 * setting needs no per-mod schema registry and nothing hardcoded about any particular mod.
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/modconfigs.php';

$world = $_GET['world'] ?? '';
$modFilter = isset($_GET['mod']) && $_GET['mod'] !== '' ? (int)$_GET['mod'] : null;

if ($world === '') {
	header('Location: index.php?msg=' . urlencode('No world specified.'));
	exit;
}

// A vanilla world runs no mods, so it has no mod configs and never will. Same reasoning as
// edit_world.php: this page is reachable by URL, and rendering an empty editor would look like
// the configs had gone missing rather than never having existed.
if (getVanilla($pdo, $world) == 1) {
	header('Location: index.php?msg=' . urlencode("'$world' is a vanilla world and has no mod configs."));
	exit;
}

$payload = modConfigEditorPayload($pdo, $world);
?>
<!DOCTYPE HTML>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Mod Configs - PhValheim Admin</title>
		<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
		<link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
		<style>
			.cfg-file-card   { background: var(--bg-secondary); border: 1px solid var(--border-color);
			                   border-radius: 6px; margin-bottom: 18px; }
			.cfg-file-head   { padding: 10px 14px; border-bottom: 1px solid var(--border-color);
			                   display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
			.cfg-file-name   { font-weight: 600; }
			.cfg-section     { padding: 8px 14px; background: var(--bg-tertiary);
			                   border-top: 1px solid var(--border-color); font-size: 0.85rem;
			                   text-transform: uppercase; letter-spacing: 0.04em; }
			.cfg-entry       { padding: 10px 14px; border-top: 1px solid var(--border-color);
			                   display: grid; grid-template-columns: minmax(0,1fr) 260px auto;
			                   gap: 12px; align-items: start; }
			/* min-width:0 is the actual fix for the overflow, not the wrapping below. A grid
			   item defaults to min-width:auto, which refuses to shrink below its content, so
			   one long unbroken default value (AzuClock documents a whole HTML template as
			   its default) pushed the row wider than the card and spilled out of it. */
			.cfg-entry > div { min-width: 0; }
			.cfg-entry-key   { font-family: monospace; overflow-wrap: anywhere; }
			.cfg-entry-desc  { font-size: 0.82rem; opacity: 0.75; margin-top: 3px;
			                   overflow-wrap: anywhere; }
			.cfg-entry-meta  { font-size: 0.76rem; opacity: 0.6; margin-top: 3px;
			                   overflow-wrap: anywhere; }
			/* Clamped to three lines with the full text on hover and in the title attribute.
			   Wrapping alone turned a 600-character default into a 12-line row that buried
			   the setting it belonged to. */
			.cfg-entry-meta code { white-space: pre-wrap; overflow-wrap: anywhere;
			                       display: -webkit-box; -webkit-line-clamp: 3;
			                       -webkit-box-orient: vertical; overflow: hidden;
			                       max-width: 100%; vertical-align: bottom; }
			.cfg-entry-meta code:hover { -webkit-line-clamp: unset; overflow: visible; }
			.cfg-dirty       { outline: 2px solid var(--warning); }
			.cfg-sticky-bar  { position: sticky; bottom: 0; background: var(--bg-secondary);
			                   border-top: 1px solid var(--border-color); padding: 10px 14px;
			                   display: flex; align-items: center; gap: 12px; z-index: 5; }
			@media (max-width: 900px) { .cfg-entry { grid-template-columns: 1fr; } }
		</style>
	</head>
	<body>
		<div class="container-fluid" style="max-width:1200px;padding:20px;">

			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
				<h3 style="margin:0;">Mod Configs</h3>
				<span class="badge bg-secondary"><?php echo htmlspecialchars($world); ?></span>
				<div style="margin-left:auto;display:flex;gap:8px;">
					<a class="btn btn-sm btn-outline-secondary" href="edit_world.php?world=<?php echo urlencode($world); ?>">Back to Mods</a>
					<a class="btn btn-sm btn-outline-secondary" href="index.php">Dashboard</a>
				</div>
			</div>

			<!--
				The apply-point, stated per side, because "restart the world to apply" is only
				true for the server and saying it flatly sent an operator chasing a setting
				that could never have taken effect.

				A mod that runs on PLAYERS' clients reads its config from the client payload
				zip, and that zip is only rebuilt by packageClient(). Before 2.55 that meant a
				full world UPDATE -- stop, steamcmd validate, purge, reinstall every mod. The
				Save & apply to players button below saves this page's edits and THEN sets
				mode='repackage', which rebuilds the payload from the staging tree WITHOUT
				stopping the world or touching a mod. It used to repackage only, from whatever
				was already saved, which made it a button named for an intent it did not carry
				out.
			-->
			<p style="opacity:.8;font-size:.9rem;">
				Changes are stored per setting and re-applied every time this world starts or
				updates, so they survive a mod update instead of being wiped by it.
			</p>
			<p style="opacity:.8;font-size:.9rem;">
				<b>Server-side mods:</b> restart the world.
				<b>Mods that run on players&rsquo; clients</b> (anything they see or interact
				with &mdash; HUDs, clocks, inventory tweaks): use
				<b>Save &amp; apply to players</b>, which saves your edits and rebuilds the
				client payload without stopping the world or disconnecting anyone. Players
				receive it the next time they launch through PhValheim. <b>Save changes</b> on
				its own stores the settings but does not reach them.
			</p>

			<!--
				Shown for every world that reaches this page -- the vanilla check at the top
				already redirected the only kind of world this cannot apply to.

				NOT gated on "this world has overrides". Resetting the last override is itself
				a change players need, and a count-based gate would hide the button in exactly
				that case: the operator would clear a setting and have no way to ship the
				clearing.
			-->
			<div style="margin:0 0 16px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
				<button type="button" class="btn btn-sm btn-primary" id="btn-apply-players"
				        onclick="applyToPlayers()">Save &amp; apply to players</button>
				<?php
				// This note used to read "The world keeps running." unconditionally, which was
				// wrong twice over. It printed the same sentence for a STOPPED world -- a promise
				// about a state the world is not in -- and it never said WHAT this applies, so it
				// read as though the mod list could be changed under a live world. It cannot:
				// adding or removing mods rebuilds the modpack and still requires a stopped world.
				// Only the settings on this page can be applied without stopping anything.
				$wcMode = getWorldMode($pdo, $world);
				?>
				<span id="apply-players-note" style="opacity:.75;font-size:.84rem;">
					Rebuilds the client payload for <b><?php echo htmlspecialchars($world); ?></b>
					with the settings on this page. <b>Mod settings only</b> &mdash; adding or
					removing mods is still done from Edit Mods, with the world stopped.
					<?php if ($wcMode === 'running'): ?>
						This world stays up and nobody is disconnected.
					<?php elseif ($wcMode === 'stopped'): ?>
						This world is stopped and stays stopped; players get the change next time they launch.
					<?php else: ?>
						This world is busy (<?php echo htmlspecialchars($wcMode); ?>) &mdash; wait for it to finish before applying.
					<?php endif; ?>
				</span>
			</div>

<?php if (isset($payload['error'])): ?>
			<div class="alert alert-danger"><?php echo $payload['error']; ?></div>

<?php elseif (!$payload['generated']): ?>
			<!--
				The third state, and it is a real one rather than an empty list.

				Most BepInEx mods do not ship a .cfg file. The file is written by the mod on its
				first Config.Bind(), which happens the first time the world actually loads it.
				So a world whose mods have just been installed has nothing to edit yet, and that
				is NOT the same as "this mod has no settings" or "everything is at its default".
				Saying either of those would be inventing an answer out of missing data.
			-->
			<div class="alert alert-info">
				<b>No mod configs have been generated yet.</b><br>
				Mods write their config files the first time the world loads them. Start
				<b><?php echo htmlspecialchars($world); ?></b> once, then come back here and
				every setting its mods expose will be listed with its documented default.
			</div>

<?php else: ?>
	<?php
	$files = $payload['files'];
	if ($modFilter !== null) {
		$files = array_values(array_filter($files, function ($f) use ($modFilter) {
			return $f['mod_id'] === $modFilter;
		}));
	}
	?>
	<?php if ($modFilter !== null): ?>
			<div style="margin-bottom:12px;">
				<a class="btn btn-sm btn-outline-info" href="world_configs.php?world=<?php echo urlencode($world); ?>">
					Showing one mod &mdash; show all configs for this world
				</a>
			</div>
	<?php endif; ?>

	<?php if (!empty($payload['stale'])): ?>
			<!--
				Override rows that match nothing in the installed version. Shown, never hidden:
				when a mod renames or drops a setting, the row can never apply again, and the
				operator is the only one who can decide between deleting it and setting the new
				key. Quietly dropping them would let this page imply an override is in force
				when it is not.
			-->
			<div class="alert alert-warning">
				<b>These saved settings no longer exist in the installed version of their mod.</b>
				They are not being applied. Either the mod renamed them or it removed them.
				<ul style="margin:8px 0 0 0;">
				<?php foreach ($payload['stale'] as $s): ?>
					<li>
						<code><?php echo htmlspecialchars($s['cfg_file']); ?></code> &rarr;
						<code>[<?php echo htmlspecialchars($s['section']); ?>] <?php echo htmlspecialchars($s['ckey']); ?></code>
						= <code><?php echo htmlspecialchars($s['cvalue']); ?></code>
						<button class="btn btn-sm btn-outline-danger" style="padding:0 6px;margin-left:6px;"
						        onclick="dropStale(<?php echo htmlspecialchars(json_encode([$s['cfg_file'], $s['section'], $s['ckey']])); ?>)">forget</button>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
	<?php endif; ?>

	<?php if (!$files): ?>
			<div class="alert alert-secondary">
				No config files are attributed to that mod. It may not write a config, or its
				config may be listed under <b>Unattributed</b> on the
				<a href="world_configs.php?world=<?php echo urlencode($world); ?>">all-configs view</a>
				&mdash; a config file is named for the plugin&rsquo;s GUID, which does not always
				resemble the mod&rsquo;s catalogue name.
			</div>
	<?php endif; ?>

	<?php foreach ($files as $fi => $f): ?>
			<div class="cfg-file-card" data-file="<?php echo htmlspecialchars($f['file']); ?>">
				<div class="cfg-file-head">
					<span class="cfg-file-name"><?php
						echo htmlspecialchars($f['plugin'] ?: $f['file']);
					?></span>
					<?php if ($f['mod_name']): ?>
						<span class="badge bg-info"><?php echo htmlspecialchars($f['mod_name']); ?></span>
					<?php else: ?>
						<span class="badge bg-secondary" title="This config file could not be matched to a mod in the catalogue. Engine-installed plugins and any DLL you dropped into custom_plugins/ have no catalogue entry, so their configs land here.">Unattributed</span>
					<?php endif; ?>
					<code style="font-size:.78rem;opacity:.65;"><?php echo htmlspecialchars($f['file']); ?></code>
					<span class="badge bg-dark" data-modcount="<?php echo htmlspecialchars($f['file']); ?>">
						<?php echo (int)$f['modified_count']; ?> modified
					</span>
					<span style="margin-left:auto;display:flex;gap:6px;">
						<button class="btn btn-sm btn-outline-secondary"
						        onclick="openPaste(<?php echo htmlspecialchars(json_encode($f['file'])); ?>)">Paste a config&hellip;</button>
						<button class="btn btn-sm btn-outline-danger"
						        onclick="resetFile(<?php echo htmlspecialchars(json_encode($f['file'])); ?>)">Reset all to defaults</button>
					</span>
				</div>

				<?php
				$lastSection = null;
				foreach ($f['entries'] as $e):
					if ($e['section'] !== $lastSection):
						$lastSection = $e['section'];
				?>
					<div class="cfg-section"><?php echo htmlspecialchars($e['section'] !== '' ? $e['section'] : '(no section)'); ?></div>
				<?php endif; ?>
					<div class="cfg-entry">
						<div>
							<span class="cfg-entry-key"><?php echo htmlspecialchars($e['key']); ?></span>
							<?php if ($e['modified']): ?>
								<span class="badge bg-warning text-dark" style="margin-left:6px;">modified</span>
							<?php endif; ?>
							<?php if ($e['origin'] === 'legacy-review'): ?>
								<span class="badge bg-danger" style="margin-left:4px;"
								      title="Imported from custom_configs/ during the 2.55 upgrade. That file documented no default for this setting, so PhValheim could not tell whether the value was yours or the mod's. Confirm it or reset it.">needs review</span>
							<?php elseif ($e['origin'] === 'legacy'): ?>
								<span class="badge bg-secondary" style="margin-left:4px;"
								      title="Imported from your custom_configs/ during the 2.55 upgrade.">imported</span>
							<?php endif; ?>
							<?php if ($e['locked']): ?>
								<span class="badge bg-dark" style="margin-left:4px;"
								      title="Managed by PhValheim itself. Editing it here would be overwritten on the next world update.">managed</span>
							<?php endif; ?>
							<?php if ($e['description']): ?>
								<div class="cfg-entry-desc"><?php echo htmlspecialchars($e['description']); ?></div>
							<?php endif; ?>
							<div class="cfg-entry-meta">
								<?php echo htmlspecialchars($e['type'] ?: 'unknown type'); ?>
								<?php if ($e['has_default']): ?>
									&middot; default <code title="<?php echo htmlspecialchars($e['default']); ?>"><?php echo htmlspecialchars($e['default'] !== '' ? $e['default'] : '(empty)'); ?></code>
								<?php else: ?>
									&middot; <span title="This mod did not document a default for this setting, so PhValheim cannot tell you whether the current value differs from one.">no documented default</span>
								<?php endif; ?>
								<?php if ($e['range']): ?>
									&middot; <?php echo htmlspecialchars($e['range'][0]); ?>&hellip;<?php echo htmlspecialchars($e['range'][1]); ?>
								<?php endif; ?>
							</div>
						</div>

						<div>
							<?php
							// The widget is chosen from the file's own declared type. Everything
							// unrecognised falls through to a text box rather than being coerced:
							// a mod can register a custom TomlTypeConverter for any type it likes,
							// and guessing a widget for one would silently constrain a value the
							// mod accepts.
							$common = 'class="form-control form-control-sm cfg-input"'
								. ' data-file="' . htmlspecialchars($f['file']) . '"'
								. ' data-section="' . htmlspecialchars($e['section']) . '"'
								. ' data-key="' . htmlspecialchars($e['key']) . '"'
								. ' data-default="' . htmlspecialchars((string)$e['default']) . '"'
								. ' data-modid="' . htmlspecialchars((string)($f['mod_id'] ?? '')) . '"'
								. ' data-original="' . htmlspecialchars($e['value']) . '"'
								. ($e['locked'] ? ' disabled' : '');

							$type = strtolower((string)$e['type']);
							if ($e['acceptable']) {
								echo "<select $common>";
								$found = false;
								foreach ($e['acceptable'] as $opt) {
									$sel = ($opt === $e['value']) ? ' selected' : '';
									if ($sel) { $found = true; }
									echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>'
									   . htmlspecialchars($opt) . '</option>';
								}
								// The live value is not in the mod's own list of acceptable
								// values. Kept and marked rather than snapped to a legal one:
								// silently changing a value the operator is looking at is worse
								// than showing them that it is out of range.
								if (!$found) {
									echo '<option value="' . htmlspecialchars($e['value']) . '" selected>'
									   . htmlspecialchars($e['value']) . ' (not an accepted value)</option>';
								}
								echo '</select>';
							} elseif ($type === 'boolean') {
								$v = strtolower($e['value']);
								echo "<select $common>"
								   . '<option value="true"'  . ($v === 'true'  ? ' selected' : '') . '>true</option>'
								   . '<option value="false"' . ($v === 'false' ? ' selected' : '') . '>false</option>'
								   . ($v !== 'true' && $v !== 'false'
										? '<option value="' . htmlspecialchars($e['value']) . '" selected>'
										  . htmlspecialchars($e['value']) . ' (not true/false)</option>'
										: '')
								   . '</select>';
							} elseif (in_array($type, ['int32', 'int64', 'single', 'double', 'decimal', 'byte', 'sbyte', 'int16', 'uint16', 'uint32', 'uint64'], true)) {
								$step = in_array($type, ['single', 'double', 'decimal'], true) ? 'any' : '1';
								$minmax = $e['range']
									? ' min="' . htmlspecialchars($e['range'][0]) . '" max="' . htmlspecialchars($e['range'][1]) . '"'
									: '';
								echo "<input type=\"number\" step=\"$step\"$minmax $common value=\""
								   . htmlspecialchars($e['value']) . '">';
							} else {
								echo "<input type=\"text\" $common value=\""
								   . htmlspecialchars($e['value']) . '">';
							}
							?>
						</div>

						<div style="display:flex;gap:4px;align-items:center;">
							<?php if (!$e['locked']): ?>
								<label style="font-size:.76rem;opacity:.8;display:flex;align-items:center;gap:4px;"
								       title="Keep this value on the server only. It will not be written into the client payload players download. Use it for anything you would not hand to a player, such as an API key or a webhook URL.">
									<input type="checkbox" class="cfg-serveronly"
									       data-file="<?php echo htmlspecialchars($f['file']); ?>"
									       data-section="<?php echo htmlspecialchars($e['section']); ?>"
									       data-key="<?php echo htmlspecialchars($e['key']); ?>"
									       <?php echo $e['server_only'] ? 'checked' : ''; ?>>
									server only
								</label>
								<?php if ($e['overridden']): ?>
									<button class="btn btn-sm btn-outline-danger" style="padding:0 6px;"
									        title="Delete this override so the mod's own default applies again, including any future change to that default."
									        onclick="resetOne(this)"
									        data-file="<?php echo htmlspecialchars($f['file']); ?>"
									        data-section="<?php echo htmlspecialchars($e['section']); ?>"
									        data-key="<?php echo htmlspecialchars($e['key']); ?>">reset</button>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
	<?php endforeach; ?>

	<?php if ($files): ?>
			<div class="cfg-sticky-bar">
				<button id="cfgSave" class="btn btn-primary btn-sm" onclick="saveAll()" disabled>Save changes</button>
				<span id="cfgDirtyCount" style="opacity:.8;font-size:.88rem;">No changes</span>
				<span id="cfgMsg" style="margin-left:auto;font-size:.88rem;"></span>
			</div>
	<?php endif; ?>
<?php endif; ?>

			<!-- Paste-a-config. This is what replaces reaching into the filesystem: operators
			     share .cfg files, and the pre-2.55 way to use one was to drop it whole into
			     custom_configs/. Pasting is strictly better because the whole file is never
			     adopted -- only the keys that actually differ are offered, and they are shown
			     before anything is stored. -->
			<div class="modal" tabindex="-1" id="pasteModal">
				<div class="modal-dialog modal-lg">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title">Paste a config file</h5>
							<button type="button" class="btn-close" onclick="closePaste()"></button>
						</div>
						<div class="modal-body">
							<p style="font-size:.88rem;opacity:.85;">
								Paste a <code>.cfg</code> for <code id="pasteFileName"></code>.
								Only the settings that differ from what this world has installed
								will be offered &mdash; the rest of the file is ignored, so you
								will not inherit someone else&rsquo;s defaults.
							</p>
							<textarea id="pasteText" class="form-control" rows="10"
							          style="font-family:monospace;font-size:.82rem;"></textarea>
							<div id="pasteResult" style="margin-top:12px;"></div>
						</div>
						<div class="modal-footer">
							<button class="btn btn-sm btn-outline-secondary" onclick="closePaste()">Cancel</button>
							<button class="btn btn-sm btn-primary" onclick="pasteCompare()">Compare</button>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!--
			The save summary.

			Saving used to write one line ("3 saved, 1 reset") and reload the page 700ms later,
			which is exactly where "I saved it and nothing happened" came from: the operator was
			never told that a saved value sits in the database until something pushes it, nor
			which of their changes needed pushing. This says what changed, who still has to
			receive it, and offers the one action that does it.
		-->
		<div class="modal fade" id="saveSummaryModal" tabindex="-1" aria-hidden="true" style="z-index:2100;">
			<div class="modal-dialog modal-lg modal-dialog-scrollable">
				<div class="modal-content" style="background-color: var(--bg-secondary); border-color: var(--border-color);">
					<div class="modal-header" style="border-bottom-color: var(--border-color);">
						<h5 class="modal-title" style="color: var(--text-primary);" id="ssTitle">Saved</h5>
						<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body" style="color: var(--text-primary);">
						<div id="ssVerdict"></div>
						<div id="ssChanges" style="margin-top:14px;"></div>
						<div id="ssRefused" style="margin-top:14px;"></div>
						<div id="ssProgress" style="margin-top:14px;"></div>
					</div>
					<div class="modal-footer" style="border-top-color: var(--border-color);">
						<button type="button" class="btn btn-sm btn-outline-secondary" id="ssClose"
						        data-bs-dismiss="modal">Close</button>
						<button type="button" class="btn btn-sm btn-primary" id="ssApply"
						        onclick="applyFromSummary()">Apply to players now</button>
					</div>
				</div>
			</div>
		</div>

		<script src="/js/bootstrap.min.js"></script>
		<script>
		var WORLD = <?php echo json_encode($world); ?>;
		var dirty = {};   // "file\u001Fsection\u001Fkey" -> payload item

		function keyOf(f, s, k) { return f + "\u001F" + s + "\u001F" + k; }

		function refreshBar() {
			var n = Object.keys(dirty).length;
			var save = document.getElementById('cfgSave');
			if (!save) { return; }
			save.disabled = (n === 0);
			document.getElementById('cfgDirtyCount').textContent =
				n === 0 ? 'No changes' : (n + (n === 1 ? ' change' : ' changes') + ' not saved yet');
		}

		function markDirty(el, item) {
			var k = keyOf(item.file, item.section, item.key);
			// Editing a field back to the value it already had is not a change. Without this
			// the Save button stays lit after an operator types a value and undoes it, which
			// then teaches them to ignore it.
			if (!item.reset && String(item.value) === String(el.dataset.original)) {
				delete dirty[k];
				el.classList.remove('cfg-dirty');
			} else {
				dirty[k] = item;
				el.classList.add('cfg-dirty');
			}
			refreshBar();
		}

		document.addEventListener('input', function (ev) {
			var el = ev.target;
			if (!el.classList || !el.classList.contains('cfg-input')) { return; }
			markDirty(el, {
				file: el.dataset.file, section: el.dataset.section, key: el.dataset.key,
				value: el.value, mod_id: el.dataset.modid || null,
				server_only: serverOnlyFor(el.dataset.file, el.dataset.section, el.dataset.key)
			});
		});
		document.addEventListener('change', function (ev) {
			var el = ev.target;
			if (el.classList && el.classList.contains('cfg-input')) {
				markDirty(el, {
					file: el.dataset.file, section: el.dataset.section, key: el.dataset.key,
					value: el.value, mod_id: el.dataset.modid || null,
					server_only: serverOnlyFor(el.dataset.file, el.dataset.section, el.dataset.key)
				});
			}
			if (el.classList && el.classList.contains('cfg-serveronly')) {
				// Flipping server-only is itself a change to the stored row, so it has to join
				// the dirty set with the setting's CURRENT value -- not be applied on its own.
				// Sending it alone would store the row with an empty value.
				var input = document.querySelector('.cfg-input[data-file="' + cssEsc(el.dataset.file) +
					'"][data-section="' + cssEsc(el.dataset.section) + '"][data-key="' + cssEsc(el.dataset.key) + '"]');
				if (input) {
					dirty[keyOf(el.dataset.file, el.dataset.section, el.dataset.key)] = {
						file: el.dataset.file, section: el.dataset.section, key: el.dataset.key,
						value: input.value, mod_id: input.dataset.modid || null,
						server_only: el.checked ? 1 : 0
					};
					input.classList.add('cfg-dirty');
					refreshBar();
				}
			}
		});

		function cssEsc(s) { return String(s).replace(/["\\]/g, '\\$&'); }

		function serverOnlyFor(f, s, k) {
			var cb = document.querySelector('.cfg-serveronly[data-file="' + cssEsc(f) +
				'"][data-section="' + cssEsc(s) + '"][data-key="' + cssEsc(k) + '"]');
			return cb && cb.checked ? 1 : 0;
		}

		function resetOne(btn) {
			dirty[keyOf(btn.dataset.file, btn.dataset.section, btn.dataset.key)] = {
				file: btn.dataset.file, section: btn.dataset.section, key: btn.dataset.key,
				reset: true
			};
			btn.disabled = true;
			btn.textContent = 'will reset';
			refreshBar();
		}

		function post(action, body) {
			return fetch('adminAPI.php?action=' + action, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			}).then(function (r) { return r.json(); });
		}

		// Config keys and values come from mod authors and from the operator, so they can
		// legitimately contain < & and quotes. Everything interpolated into the summary below
		// goes through this.
		function esc(v) {
			if (v === null || v === undefined) { return ''; }
			return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
			                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
		}

		var saveSummaryModal = null;
		var reloadOnSummaryClose = false;

		// A stored value and "no stored value" are different things, and the difference is the
		// whole point of the per-key store: no override means the setting tracks the mod's own
		// default, including when a later version changes it. An empty string is a real value.
		function valCell(v, whenNull) {
			if (v === null || v === undefined) {
				return '<span style="opacity:.7;font-style:italic;">' + whenNull + '</span>';
			}
			if (v === '') {
				return '<span style="opacity:.7;font-style:italic;">(empty)</span>';
			}
			return '<code style="overflow-wrap:anywhere;">' + esc(v) + '</code>';
		}

		function reachBadge(reach) {
			if (reach === 'players') {
				return '<span class="badge bg-warning text-dark" title="This mod runs on players&rsquo; '
				     + 'clients, so they need a rebuilt payload before they see it.">players</span>';
			}
			if (reach === 'server') {
				return '<span class="badge bg-secondary" title="Server-side only. Players never '
				     + 'need to receive this.">server</span>';
			}
			return '<span class="badge bg-info text-dark" title="PhValheim could not match this '
			     + 'config file to one of this world&rsquo;s mods, so it cannot tell whether '
			     + 'anything on the player&rsquo;s side reads it. It is included in the push '
			     + 'because that is the safe choice.">unknown</span>';
		}

		function renderSaveSummary(r) {
			var t = r.tally || { players: 0, server: 0, unknown: 0 };
			var changes = r.changes || [];
			var pushCount = t.players + t.unknown;

			document.getElementById('ssTitle').textContent =
				changes.length === 1 ? 'Saved 1 change' : 'Saved ' + changes.length + ' changes';

			// ---- the verdict: does anything still have to happen? ----
			var v = '';
			if (r.needsPush) {
				v += '<div class="alert alert-warning" style="margin-bottom:10px;">'
				  +  '<b>Players do not have these changes yet.</b><br>'
				  +  pushCount + ' of these ' + (pushCount === 1 ? 'affects a mod' : 'affect mods')
				  +  ' that players run. Saving stored the change; players receive it only once the '
				  +  'client payload is rebuilt. Press <b>Apply to players now</b> &mdash; '
				  // Same correction as the static note above the button: do not promise "keeps
				  // running" to a world that is stopped. r.worldMode is already in this payload.
				  +  (r.worldMode === 'running'
				        ? 'the world stays up and nobody is disconnected.'
				        : 'this world is stopped, so players pick the change up next launch.')
				  +  '</div>';
			} else if (changes.length) {
				v += '<div class="alert alert-success" style="margin-bottom:10px;">'
				  +  '<b>Nothing to push.</b> None of these changes alter what players download.</div>';
			}

			if (t.server > 0) {
				// A stopped world needs no action at all, and saying "restart to apply" to
				// someone whose world is already stopped is the kind of instruction that makes
				// an operator distrust the rest of the message.
				var running = (r.worldMode === 'running');
				v += '<div style="font-size:.88rem;opacity:.9;margin-bottom:6px;">'
				  +  '<b>' + t.server + ' server-side ' + (t.server === 1 ? 'setting' : 'settings')
				  +  '.</b> Mods read their config when they load, so '
				  +  (running
				       ? 'restart <b>' + esc(WORLD) + '</b> from the <a href="index.php">dashboard</a> '
				         + 'for these to take effect.'
				       : '<b>' + esc(WORLD) + '</b> is ' + esc(r.worldMode || 'not running')
						 + ' &mdash; it will pick these up the next time it starts. Nothing to do.')
				  +  '</div>';
			}

			if (t.unknown > 0) {
				v += '<div style="font-size:.88rem;opacity:.9;">'
				  +  '<b>' + t.unknown + ' ' + (t.unknown === 1 ? 'setting is' : 'settings are')
				  +  ' in a config file PhValheim could not match to one of this world&rsquo;s '
				  +  'mods</b>, so it cannot say whether anything on the player&rsquo;s side reads '
				  +  'them. They are included in the push, because an unnecessary push costs one '
				  +  'small download while a missing one loses your change silently.</div>';
			}
			document.getElementById('ssVerdict').innerHTML = v;

			// ---- what actually changed ----
			var c = '';
			if (changes.length) {
				c += '<table class="table table-sm table-dark" style="font-size:.86rem;margin-bottom:0;">'
				  +  '<thead><tr><th>Setting</th><th>Was</th><th>Now</th><th>Needed by</th></tr></thead><tbody>';
				changes.forEach(function (ch) {
					c += '<tr>'
					  +  '<td><div><code>' + esc(ch.key) + '</code></div>'
					  +  '<div style="opacity:.65;font-size:.8rem;">' + esc(ch.file)
					  +  (ch.section ? ' &middot; [' + esc(ch.section) + ']' : '')
					  +  (ch.mod ? ' &middot; ' + esc(ch.mod) : '') + '</div></td>'
					  +  '<td>' + valCell(ch.from, 'mod default') + '</td>'
					  +  '<td>' + (ch.action === 'reset'
					        ? valCell(null, 'back to mod default')
					        : valCell(ch.to, 'mod default')) + '</td>'
					  +  '<td>' + reachBadge(ch.reach) + '</td>'
					  +  '</tr>';
				});
				c += '</tbody></table>';
			} else {
				c = '<div class="alert alert-secondary" style="margin-bottom:0;">'
				  + 'Nothing changed. Every setting you submitted already held that value.</div>';
			}
			document.getElementById('ssChanges').innerHTML = c;

			// ---- refusals are REPORTED, never swallowed ----
			// A save that silently dropped a locked row would leave the operator believing a
			// value took effect. This used to be a browser alert() stacked on top of the page.
			var ref = '';
			if (r.refused && r.refused.length) {
				ref = '<div class="alert alert-danger" style="margin-bottom:0;"><b>'
				    + r.refused.length + ' not saved:</b><ul style="margin:6px 0 0 0;">';
				r.refused.forEach(function (x) { ref += '<li>' + esc(x) + '</li>'; });
				ref += '</ul></div>';
			}
			document.getElementById('ssRefused').innerHTML = ref;
			document.getElementById('ssProgress').innerHTML = '';

			// The Apply button exists only when there is something to apply. Offering it for a
			// purely server-side change would invite a 573 MB rebuild that changes nothing for
			// anyone.
			var applyBtn = document.getElementById('ssApply');
			applyBtn.style.display = r.needsPush ? '' : 'none';
			applyBtn.disabled = false;

			if (!saveSummaryModal) {
				saveSummaryModal = new bootstrap.Modal(document.getElementById('saveSummaryModal'));
				// Reload when the summary closes, not on a timer after the save. The old
				// 700ms reload would have torn this modal down while the operator was reading
				// it -- and the page has to re-render anyway to show the new stored values.
				document.getElementById('saveSummaryModal')
					.addEventListener('hidden.bs.modal', function () {
						if (reloadOnSummaryClose) { location.reload(); }
					});
			}
			reloadOnSummaryClose = true;
			saveSummaryModal.show();
		}

		function saveAll() {
			var items = Object.keys(dirty).map(function (k) { return dirty[k]; });
			var msg = document.getElementById('cfgMsg');
			msg.textContent = 'Saving\u2026';
			post('saveModConfigs', { world: WORLD, items: items }).then(function (r) {
				if (!r.ok) { msg.textContent = r.error || 'Save failed.'; return; }
				var bits = [];
				if (r.saved)   { bits.push(r.saved + ' saved'); }
				if (r.removed) { bits.push(r.removed + ' reset'); }
				if (r.refused && r.refused.length) { bits.push(r.refused.length + ' refused'); }
				msg.textContent = bits.join(', ') + '.';
				dirty = {};
				renderSaveSummary(r);
			});
		}

		// Apply from inside the summary. Shares applyToPlayers()' contract -- r.success, not
		// r.ok -- and reports into the modal rather than the page behind it.
		function applyFromSummary() {
			var btn  = document.getElementById('ssApply');
			var prog = document.getElementById('ssProgress');
			btn.disabled = true;
			prog.innerHTML = '<div class="alert alert-info" style="margin-bottom:0;">'
			               + 'Rebuilding the client payload&hellip; this takes a moment for a large modpack.</div>';

			post('repackageWorldNow', { world: WORLD }).then(function (r) {
				if (!r.success) {
					btn.disabled = false;
					prog.innerHTML = '<div class="alert alert-danger" style="margin-bottom:0;">'
					               + esc(r.error || 'Could not start a repackage.') + '</div>';
					return;
				}
				prog.innerHTML = '<div class="alert alert-success" style="margin-bottom:0;">'
				               + '<b>Repackaging started.</b> Players receive it the next time they '
				               + 'launch through PhValheim. The world status on the '
				               + '<a href="index.php">dashboard</a> shows when it finishes.</div>';
				document.getElementById('ssApply').style.display = 'none';
				document.getElementById('ssClose').textContent = 'Done';
			});
		}

		// "Save & apply to players" -- SAVES this page's edits, then sets mode='repackage',
		// which rebuilds the client payload without stopping the world.
		//
		// It used to repackage ONLY, from whatever was already saved, and warn via confirm()
		// that unsaved edits would not reach anyone. That is a button whose name describes
		// the operator's intent and whose behaviour does not: the one thing they wanted
		// applied was the thing on screen. So it saves first, every time.
		//
		// Both halves are the paths already used elsewhere on this page, not reimplementations:
		// the save is saveModConfigs + renderSaveSummary (so refusals are still reported and
		// the operator still gets a receipt of what changed), and the apply is the summary
		// modal's own button, invoked for them. That also preserves the needsPush gate --
		// a purely server-side change does not trigger a client rebuild that changes nothing
		// for anyone, and the modal says so instead.
		//
		// Reads r.success, NOT r.ok. repackageWorldNow is a world-lifecycle action and matches
		// updateWorldNow's shape, while the config endpoints on this page return {ok:...}.
		// Reading r.ok here would be undefined on every successful call, so every repackage
		// that worked would report a failure.
		function applyToPlayers() {
			var btn  = document.getElementById('btn-apply-players');
			var note = document.getElementById('apply-players-note');

			// Nothing to save: go straight to the repackage. Re-shipping the saved state is a
			// legitimate thing to want -- it is how an operator recovers from a payload that
			// was built before their last save.
			if (!Object.keys(dirty).length) { repackageOnly(btn, note); return; }

			btn.disabled = true;
			note.innerHTML = 'Saving&hellip;';

			var items = Object.keys(dirty).map(function (k) { return dirty[k]; });
			post('saveModConfigs', { world: WORLD, items: items }).then(function (r) {
				btn.disabled = false;
				if (!r.ok) {
					note.innerHTML = '<span style="color:var(--danger)">' +
					                 esc(r.error || 'Save failed — nothing was applied.') + '</span>';
					return;
				}
				note.innerHTML = '';
				dirty = {};
				document.getElementById('cfgSave').disabled = true;
				renderSaveSummary(r);
				// Hand straight off to the summary's own Apply. When needsPush is false that
				// button is hidden and this does nothing, which is the correct outcome.
				if (r.needsPush) { applyFromSummary(); }
			});
		}

		// The repackage half on its own, reporting into the page rather than the modal.
		function repackageOnly(btn, note) {
			btn.disabled = true;
			note.innerHTML = 'Rebuilding the client payload… this takes a moment for a large modpack.';

			post('repackageWorldNow', { world: WORLD }).then(function (r) {
				if (!r.success) {
					btn.disabled = false;
					note.innerHTML = '<span style="color:var(--danger)">' +
					                 (r.error || 'Could not start a repackage.') + '</span>';
					return;
				}
				note.innerHTML = 'Repackaging started. Players receive it the next time they ' +
				                 'launch through PhValheim. The world status on the ' +
				                 '<a href="index.php">dashboard</a> shows when it finishes.';
				// Deliberately not polled from here. The engine picks the mode up within two
				// seconds and the dashboard already renders it; a second poller on this page
				// would be a third place that has to agree what 'repackaging' means, which is
				// how 2.53's hammertime bug happened.
			});
		}

		function resetFile(file) {
			if (!confirm('Delete every saved setting for ' + file + '?\n\n' +
			             'That mod goes back to its own defaults, including any future change ' +
			             'to those defaults.')) { return; }
			post('resetModConfigFile', { world: WORLD, file: file }).then(function (r) {
				if (!r.ok) { alert(r.error || 'Reset failed.'); return; }
				location.reload();
			});
		}

		function dropStale(triple) {
			post('saveModConfigs', { world: WORLD, items: [
				{ file: triple[0], section: triple[1], key: triple[2], reset: true }
			]}).then(function () { location.reload(); });
		}

		var pasteFile = null;
		function openPaste(file) {
			pasteFile = file;
			document.getElementById('pasteFileName').textContent = file;
			document.getElementById('pasteText').value = '';
			document.getElementById('pasteResult').innerHTML = '';
			document.getElementById('pasteModal').style.display = 'block';
			document.getElementById('pasteModal').classList.add('show');
		}
		function closePaste() {
			document.getElementById('pasteModal').classList.remove('show');
			document.getElementById('pasteModal').style.display = 'none';
		}

		function pasteCompare() {
			var out = document.getElementById('pasteResult');
			out.textContent = 'Comparing\u2026';
			post('diffPastedModConfig', {
				world: WORLD, file: pasteFile, text: document.getElementById('pasteText').value
			}).then(function (r) {
				if (!r.ok) { out.innerHTML = '<div class="alert alert-danger">' + r.error + '</div>'; return; }
				if (!r.changes.length) {
					out.innerHTML = '<div class="alert alert-success">Every setting in that file ' +
						'already matches what this world has. Nothing to apply.</div>';
					return;
				}
				var h = '<p style="font-size:.88rem;"><b>' + r.changes.length +
					'</b> setting(s) differ and would be saved:</p><ul style="font-size:.85rem;">';
				r.changes.forEach(function (c) {
					h += '<li><code>[' + c.section + '] ' + c.key + '</code>: ' +
					     '<code>' + c.installed + '</code> &rarr; <code>' + c.value + '</code></li>';
				});
				h += '</ul>';
				if (r.unknown.length) {
					h += '<div class="alert alert-warning" style="font-size:.84rem;">' +
						r.unknown.length + ' setting(s) in that file do not exist in the version ' +
						'this world has installed, so they cannot be applied: ' +
						r.unknown.map(function (u) { return '<code>' + u.key + '</code>'; }).join(', ') +
						'</div>';
				}
				h += '<button class="btn btn-sm btn-primary" onclick=\'applyPasted(' +
				     JSON.stringify(r.changes).replace(/'/g, '&#39;') + ')\'>Save these ' +
				     r.changes.length + '</button>';
				out.innerHTML = h;
			});
		}

		function applyPasted(changes) {
			var items = changes.map(function (c) {
				return { file: pasteFile, section: c.section, key: c.key, value: c.value };
			});
			post('saveModConfigs', { world: WORLD, items: items }).then(function (r) {
				if (!r.ok) { alert(r.error || 'Save failed.'); return; }
				closePaste();
				location.reload();
			});
		}
		</script>
	</body>
</html>
