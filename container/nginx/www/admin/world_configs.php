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
				Apply to players button below sets mode='repackage' instead, which rebuilds the
				payload from the staging tree WITHOUT stopping the world or touching a mod.
			-->
			<p style="opacity:.8;font-size:.9rem;">
				Changes are stored per setting and re-applied every time this world starts or
				updates, so they survive a mod update instead of being wiped by it.
			</p>
			<p style="opacity:.8;font-size:.9rem;">
				<b>Server-side mods:</b> restart the world.
				<b>Mods that run on players&rsquo; clients</b> (anything they see or interact
				with &mdash; HUDs, clocks, inventory tweaks): use <b>Apply to players</b>, which
				rebuilds the client payload without stopping the world or disconnecting anyone.
				Players receive it the next time they launch through PhValheim. Saving alone
				will not reach them.
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
				        onclick="applyToPlayers()">Apply to players</button>
				<span id="apply-players-note" style="opacity:.75;font-size:.84rem;">
					Rebuilds the client payload for <b><?php echo htmlspecialchars($world); ?></b>.
					The world keeps running.
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

		function saveAll() {
			var items = Object.keys(dirty).map(function (k) { return dirty[k]; });
			var msg = document.getElementById('cfgMsg');
			msg.textContent = 'Saving\u2026';
			post('saveModConfigs', { world: WORLD, items: items }).then(function (r) {
				if (!r.ok) { msg.textContent = r.error || 'Save failed.'; return; }
				var bits = [];
				if (r.saved)   { bits.push(r.saved + ' saved'); }
				if (r.removed) { bits.push(r.removed + ' reset'); }
				// Refusals are REPORTED, not swallowed. A save that silently dropped a locked
				// row would leave the operator believing a value took effect.
				if (r.refused && r.refused.length) { bits.push(r.refused.length + ' refused'); }
				msg.textContent = bits.join(', ') + '. ' + (r.note || '') ;
				if (r.refused && r.refused.length) { alert('Not saved:\n\n' + r.refused.join('\n')); }
				dirty = {};
				setTimeout(function () { location.reload(); }, 700);
			});
		}

		// "Apply to players" -- sets mode='repackage', which rebuilds the client payload
		// without stopping the world.
		//
		// Reads r.success, NOT r.ok. repackageWorldNow is a world-lifecycle action and matches
		// updateWorldNow's shape, while the config endpoints on this page return {ok:...}.
		// Reading r.ok here would be undefined on every successful call, so every repackage
		// that worked would report a failure.
		function applyToPlayers() {
			var btn  = document.getElementById('btn-apply-players');
			var note = document.getElementById('apply-players-note');

			// A repackage ships what is SAVED. Unsaved edits on this page are invisible to it,
			// and an operator who clicked Apply with a half-edited form would reasonably
			// believe those edits had gone out.
			if (Object.keys(dirty).length) {
				if (!confirm('You have unsaved changes on this page.\n\n' +
				             'Apply to players rebuilds the payload from what is already ' +
				             'SAVED, so those unsaved edits will not reach anyone.\n\n' +
				             'Continue anyway?')) { return; }
			}

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
