<?php
/**
 * Hugin's Vibes (2.59) -- the page. docs/HUGINS-VIBES-DESIGN.md section 10.
 *
 * Server-renders the shell and the initial state (vibesApiState, the SAME function the API's
 * `state` action answers with) into a JSON <script>; js/vibes.js reads it and drives everything
 * from there. The markup here is static scaffolding only: every string that can come from the
 * model, a file, a git log or an operator is rendered with DOM calls in vibes.js, never as HTML.
 *
 * Why so much static markup: the Settings modal is eight tabs of labelled fields. Keeping them
 * as HTML with data-setting="dotted.path" attributes means the JS binds values by walking the
 * DOM, and a new knob is one <label> here plus a default in includes/vibes.php -- no form code.
 *
 * Trust boundary: 8081, like every admin page (see admin/index.php). Opened from the sidebar in a
 * new tab, so it links phvalheimStyles.css first and css/vibes.css adds the room's own look.
 */
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/hugin.php';
require_once '/opt/stateless/nginx/www/includes/vibes.php';
define('VIBES_API_LIBRARY', true);
require_once '/opt/stateless/nginx/www/admin/vibesAPI.php';

try { $vibesInitial = vibesApiState($pdo); }
catch (Throwable $e) {
	// The page must still render: an exception here becomes a banner, not a white screen.
	error_log('hugins_vibes: state failed: ' . $e->getMessage());
	$vibesInitial = ['installed' => false, 'noticeShown' => false, 'error' => 'The state could not be read: ' . $e->getMessage(),
	                 'pak' => ['present' => false, 'compatible' => false, 'problem' => ''], 'libs' => ['present' => false],
	                 'ai' => ['ok' => false, 'reason' => 'unknown'], 'disk' => ['free' => null, 'required' => 0, 'ok' => false],
	                 'jobs' => [], 'runner' => ['ok' => false, 'missing' => []], 'projectCount' => 0, 'worldsWithServerFiles' => [], 'root' => VIBES_ROOT];
}
$cssV = @filemtime('/opt/stateless/nginx/www/css/vibes.css') ?: time();
$jsV  = @filemtime('/opt/stateless/nginx/www/js/vibes.js') ?: time();
$themeV = @filemtime('/opt/stateless/nginx/www/css/phvalheimStyles.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Hugin's Vibes - PhValheim</title>
	<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
	<link rel="stylesheet" href="/css/phvalheimStyles.css?v=<?php echo $themeV; ?>">
	<link rel="stylesheet" href="/css/vibes.css?v=<?php echo $cssV; ?>">
</head>
<body class="vb-body" data-vb-state="booting">
	<div class="vb-aurora" aria-hidden="true"></div>

	<!-- ============================== Header ============================== -->
	<header class="vb-header">
		<div class="vb-brand">
			<?php // The ORIGINAL drawing on this page; vibes.js clones it (as aiHuginNode does) and
			      // flips its state class while a turn runs. One bird, one source. ?>
			<?php echo huginSvg('idle vb-hugin-src', 34); ?>
			<div class="vb-brand-text">
				<h1>Hugin&rsquo;s Vibes</h1>
				<span class="vb-brand-sub">PhValheim &middot; mod forge</span>
			</div>
		</div>
		<div class="vb-chips" id="vbChips" aria-live="polite"></div>
		<div class="vb-header-actions"><button type="button" class="vb-btn vb-btn-ghost" id="vbToggleLibrary" aria-expanded="true">Vibes</button><button type="button" class="vb-btn vb-btn-ghost" id="vbToggleForge" aria-expanded="true">Forge</button><button type="button" class="vb-btn vb-btn-ghost" id="vbToggleNotes" aria-expanded="true">Notes</button>
			<button type="button" class="vb-btn vb-btn-ghost vb-only-narrow" id="vbBtnLibrary" title="Open the library">&#9776; Library</button>
			<button type="button" class="vb-btn vb-btn-ghost" id="vbBtnSettings" title="Settings">&#9881; Settings</button>
			<a class="vb-btn vb-btn-ghost" href="https://brianmiller.github.io/phvalheim-server/" target="_blank" rel="noopener noreferrer" title="PhValheim Field Guide — setup, modding and publishing">Field Guide</a>
			<a class="vb-btn vb-btn-ghost" href="index.php" title="Back to the dashboard">Dashboard</a>
		</div>
	</header>

	<!-- A dismissible banner: pak update / pak missing / base mismatch (state 7), runner missing. -->
	<div class="vb-banner" id="vbBanner" hidden>
		<span class="vb-banner-icon" aria-hidden="true"></span>
		<span class="vb-banner-text" id="vbBannerText"></span>
		<button type="button" class="vb-btn vb-btn-sm vb-btn-accent" id="vbBannerAction" hidden></button>
		<button type="button" class="vb-banner-close" id="vbBannerClose" title="Dismiss">&times;</button>
	</div>

	<!-- ============================== Main ============================== -->
	<main class="vb-main" id="vbMain">
		<aside class="vb-library" id="vbLibrary" aria-label="Library">
			<div class="vb-lib-top">
				<input type="search" class="vb-input vb-lib-search" id="vbLibSearch" placeholder="Search Vibes&hellip;" autocomplete="off">
				<button type="button" class="vb-btn vb-btn-accent" id="vbBtnNewVibe" title="Start a new mod">+ New Vibe</button><button type="button" class="vb-btn vb-btn-ghost" id="vbBtnImport">+ Import</button>
			</div>
			<div class="vb-lib-list" id="vbLibList" role="list"></div>
			<div class="vb-lib-foot" id="vbLibFoot"></div>
		</aside>
		<div class="vb-library-scrim" id="vbLibraryScrim" hidden></div>

		<section class="vb-workspace" id="vbWorkspace" aria-label="Workspace">
			<!-- One of: welcome (nothing picked), no-libs card (state 4), empty library (state 5). -->
			<div class="vb-ws-empty" id="vbWsEmpty"></div>

			<div class="vb-ws-project" id="vbWsProject" hidden>
				<div class="vb-ws-head">
					<div class="vb-ws-title">
						<h2 id="vbPName"></h2>
						<span class="vb-pill vb-pill-ns" id="vbPNs"></span>
						<span class="vb-pill vb-pill-ver" id="vbPVer"></span>
						<span class="vb-pill" id="vbPStatus"></span>
					</div>
					<div class="vb-ws-job" id="vbWsJob" hidden>
						<span class="vb-ring" id="vbWsRing"><span class="vb-ring-fill"></span></span>
						<span class="vb-ws-job-text" id="vbWsJobText"></span>
						<button type="button" class="vb-btn vb-btn-sm vb-btn-danger-ghost" id="vbWsStop">Stop</button>
					</div>
				</div>
				<nav class="vb-tabs" id="vbTabs" role="tablist">
					<button type="button" class="vb-tab active" data-tab="vibe" role="tab">Vibe</button>
					<button type="button" class="vb-tab" data-tab="code" role="tab">Code</button>
					<button type="button" class="vb-tab" data-tab="build" role="tab">Build <span class="vb-tab-badge" id="vbTabBuildBadge" hidden></span></button>
					<button type="button" class="vb-tab" data-tab="package" role="tab">Package</button>
					<button type="button" class="vb-tab" data-tab="history" role="tab">History</button>
					<button type="button" class="vb-tab vb-only-narrow" data-tab="context" role="tab">Context</button>
				</nav>

				<div class="vb-panels">
					<!-- Vibe: transcript + forge rail + composer -->
					<section class="vb-panel active" data-panel="vibe">
						<div class="vb-vibe-grid">
							<div class="vb-transcript-wrap">
								<div class="vb-transcript" id="vbTranscript" aria-live="polite"></div>
								<button type="button" class="vb-btn vb-btn-sm vb-jump" id="vbJumpLatest" hidden>↓ Latest activity</button><div class="vb-live-strip"><span id="vbPet"></span><span id="vbLiveStatus" role="status">Ready to forge</span><span id="vbUsage" title="Context is an estimate; usage is reported by the provider."></span></div><form class="vb-composer" id="vbComposer">
									<textarea class="vb-input vb-composer-text" id="vbComposerText" rows="3" placeholder="Tell Hugin what the mod should do&hellip;  (Ctrl/Cmd+Enter sends)"></textarea>
									<div class="vb-composer-bar">
										<span class="vb-composer-hint" id="vbComposerHint"></span>
										<div class="vb-composer-model" id="vbComposerModel" title="Model for this Vibe (stored verbatim)">
											<input type="text" class="vb-input vb-input-sm" id="vbModelInput" role="combobox" aria-autocomplete="list" aria-controls="vbModelList" aria-expanded="false" placeholder="provider default model" autocomplete="off">
											<div id="vbModelList" class="vb-model-menu" role="listbox" hidden></div>
										</div>
										<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" id="vbComposerLimits" title="Adjust limits for future turns across all Vibes">Turn limits</button>
										<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" id="vbComposerStop" hidden>Stop</button>
										<button type="submit" class="vb-btn vb-btn-accent" id="vbComposerSend">Send</button>
									</div>
								</form>
							</div>
							<aside class="vb-forge" id="vbForge" aria-label="Forge rail">
								<div class="vb-forge-head"><span>forge</span><span class="vb-forge-meta" id="vbForgeMeta"></span></div>
								<div class="vb-forge-list" id="vbForgeList"></div>
							</aside>
						</div>
					</section>

					<!-- Code: tree + viewer/editor -->
					<section class="vb-panel" data-panel="code">
						<div class="vb-code-grid">
							<div class="vb-tree" id="vbTree"></div>
							<div class="vb-viewer">
								<div class="vb-viewer-head">
									<span class="vb-viewer-path" id="vbViewerPath">Pick a file</span>
									<span class="vb-viewer-meta" id="vbViewerMeta"></span>
									<span class="vb-viewer-actions">
										<button type="button" class="vb-btn vb-btn-sm vb-btn-ghost" id="vbViewerEdit" hidden>Edit</button>
										<button type="button" class="vb-btn vb-btn-sm vb-btn-ghost" id="vbViewerCancel" hidden>Cancel</button>
										<button type="button" class="vb-btn vb-btn-sm vb-btn-accent" id="vbViewerSave" hidden>Save &amp; commit</button>
									</span>
								</div>
								<div class="vb-viewer-body" id="vbViewerBody"></div>
								<textarea class="vb-input vb-viewer-editor" id="vbViewerEditor" hidden spellcheck="false"></textarea>
							</div>
						</div>
					</section>

					<!-- Build -->
					<section class="vb-panel" data-panel="build">
						<div class="vb-build-bar">
							<label class="vb-field-inline">Configuration
								<select class="vb-input vb-input-sm" id="vbBuildConfig"><option>Release</option><option>Debug</option></select>
							</label>
							<button type="button" class="vb-btn vb-btn-accent" id="vbBtnBuild" data-job="build">&#9881; Build</button>
							<span class="vb-build-summary" id="vbBuildSummary"></span>
						</div>
						<div class="vb-build-result" id="vbBuildResult"></div>
						<details class="vb-details" id="vbBuildLogWrap"><summary>Raw dotnet output</summary><pre class="vb-pre" id="vbBuildLog"></pre></details>
					</section>

					<!-- Package -->
					<section class="vb-panel" data-panel="package">
						<div id="vbPackageFeedback" class="vb-operation" role="status">Package your green build to create downloadable ZIPs.</div><div class="vb-pkg-grid">
							<div class="vb-card">
								<h3>Manifest</h3>
								<div class="vb-kv" id="vbManifest"></div>
								<div class="vb-row-actions">
									<button type="button" class="vb-btn vb-btn-ghost" id="vbBtnPackage" data-job="package">&#9112; Package</button>
									<button type="button" class="vb-btn vb-btn-accent" id="vbBtnPublish" data-job="publish">&#8679; Publish to catalogue</button>
								</div>
								<p class="vb-help" id="vbPublishHelp"></p>
							</div>
							<div class="vb-card">
								<h3>In the catalogue</h3>
								<div id="vbCatalog"></div>
								<p class="vb-help">Add the mod to a world's list from the dashboard; the world picks it up on its next Update.</p>
							</div>
							<div class="vb-card">
								<h3>Disk</h3>
								<div class="vb-kv" id="vbDiskKv"></div><div id="vbDownloads" class="vb-row-actions"></div><p class="vb-help">Thunderstore and Hexium use the same compatible ZIP format.</p><div class="vb-row-actions"><button type="button" class="vb-btn vb-btn-accent" data-hosted="thunderstore">Publish to Thunderstore</button><button type="button" class="vb-btn vb-btn-accent" data-hosted="hexium">Publish to Hexium</button></div><div id="vbHostedResults"></div>
								<div class="vb-row-actions">
									<a class="vb-btn vb-btn-ghost" id="vbBtnExport" href="#" download>&#8681; Export repo (.tar.gz)</a>
									<button type="button" class="vb-btn vb-btn-danger-ghost" id="vbBtnDeleteProject">Delete Vibe&hellip;</button>
								</div>
							</div>
						</div>
					</section>

					<!-- History -->
					<section class="vb-panel" data-panel="history">
						<div id="vbScm" class="vb-operation"></div><div class="vb-hist-grid">
							<div class="vb-hist-list-wrap">
								<div class="vb-hist-bar">
									<span class="vb-hist-remotes" id="vbRemotes"></span>
									<span class="vb-row-actions" id="vbPushButtons"></span>
								</div>
								<div class="vb-hist-list" id="vbGitLog"></div>
							</div>
							<div class="vb-diff" id="vbDiff"><div class="vb-muted">Pick a commit to see its diff.</div></div>
						</div>
					</section>

					<!-- Context (narrow layouts only; the right rail moves in here) -->
					<section class="vb-panel" data-panel="context"><div id="vbContextSlot"></div></section>
				</div>
			</div>
		</section>

		<aside class="vb-context" id="vbContext" aria-label="Context">
			<div class="vb-context-inner" id="vbContextInner">
				<section class="vb-ctx-block">
					<h3>Project notes <span class="vb-ctx-sub">Hugin reads these every turn</span></h3>
					<textarea class="vb-input vb-notes" id="vbNotes" rows="7" placeholder="Decisions, open bugs, TODO&hellip;"></textarea>
					<div class="vb-row-actions vb-right"><button type="button" class="vb-btn vb-btn-sm vb-btn-ghost" id="vbNotesSave" disabled>Save notes</button></div>
				</section>
				<section class="vb-ctx-block">
					<h3>Sessions <button type="button" class="vb-btn vb-btn-xs vb-btn-ghost" id="vbNewSession">+ New</button></h3>
					<div class="vb-sessions" id="vbSessions"></div>
				</section>
				<section class="vb-ctx-block"><h3>Build</h3><div class="vb-kv" id="vbCtxBuild"></div></section>
				<section class="vb-ctx-block"><h3>Published</h3><div class="vb-kv" id="vbCtxPublished"></div></section>
				<section class="vb-ctx-block"><h3>Disk</h3><div class="vb-kv" id="vbCtxDisk"></div></section>
			</div>
		</aside>
	</main>

	<div class="vb-toasts" id="vbToasts" aria-live="assertive"></div>

	<!-- ============================== Intro / install modal (states 1, 2, 3, 8) ============================== -->
	<div class="mods-modal-overlay vb-modal-overlay" id="vbIntro">
		<div class="mods-modal vb-modal vb-intro" role="dialog" aria-modal="true" aria-labelledby="vbIntroTitle">
			<div class="mods-modal-body vb-intro-body" id="vbIntroBody">
				<div class="vb-intro-hero">
					<?php echo huginSvg('idle hugin-hello', 92); ?>
					<h2 id="vbIntroTitle">I can build mods now.</h2>
					<p class="vb-intro-sub" id="vbIntroSub">Describe a Valheim mod; I write it, compile it here, and publish it to your own catalogue.</p>
				</div>
				<div class="vb-intro-text">
					<p><strong>What it is.</strong> A workbench for vibe-coding your own BepInEx mods. You talk, I read the game's code, write C#, build, and fix what the compiler says. Nothing here touches your worlds: you add a finished mod to a world's list on the dashboard, exactly as with any catalogue mod.</p>
					<p><strong>What gets downloaded.</strong> A <em>Build Pak</em> &mdash; the .NET SDK, git, decompiler and BepInEx references. This is <strong>an additional download, separate from the PhValheim image and from Valheim</strong>. It is stored on your data volume at <code id="vbIntroRoot">/opt/stateful/vibes</code> and can be removed again from Settings &rarr; Danger zone.</p>
					<p><strong>What it needs.</strong> An AI provider (the one Hugin already uses) and free disk for the pak plus room to build.</p>
				</div>
				<ul class="vb-checks" id="vbIntroChecks">
					<li class="vb-check" id="vbCheckPak"><span class="vb-dot"></span><span class="vb-check-text">Looking up the Build Pak release&hellip;</span></li>
					<li class="vb-check" id="vbCheckDisk"><span class="vb-dot"></span><span class="vb-check-text">Checking free space&hellip;</span></li>
					<li class="vb-check" id="vbCheckAi"><span class="vb-dot"></span><span class="vb-check-text">Checking the AI provider&hellip;</span></li>
					<?php // Filled by renderChecks() when the newest install/uninstall job (state.lastJob) failed;
					      // the fast-failure case that used to leave the intro looking untouched. ?>
					<li class="vb-check" id="vbCheckLast" hidden><span class="vb-dot"></span><span class="vb-check-text"></span></li>
				</ul>
				<label class="vb-checkbox vb-intro-decompile"><input type="checkbox" id="vbIntroDecompile" checked> Also decompile Valheim's code so I can read it <span class="vb-muted">(recommended, +~150 MB)</span></label>
				<div class="vb-intro-actions">
					<button type="button" class="vb-btn vb-btn-accent vb-btn-lg" id="vbIntroInstall" disabled>Download &amp; Install</button>
					<button type="button" class="vb-btn vb-btn-ghost vb-btn-lg" id="vbIntroLater">Not now</button>
				</div>
			</div>

			<!-- State 3: the same dialog becomes a progress sheet -->
			<div class="mods-modal-body vb-install-sheet" id="vbInstallSheet" hidden>
				<div class="vb-sheet-head">
					<?php echo huginSvg('working vb-sheet-hugin', 48); ?>
					<div><h2 id="vbSheetTitle">Installing Hugin&rsquo;s Vibes</h2><p class="vb-muted" id="vbSheetStep">Starting&hellip;</p></div>
				</div>
				<div class="vb-progress"><div class="vb-progress-fill" id="vbSheetBar"></div></div>
				<div class="vb-sheet-detail" id="vbSheetDetail"></div>
				<ol class="vb-steps" id="vbSheetSteps"></ol>
				<div class="vb-sheet-error" id="vbSheetError" hidden></div>
				<details class="vb-details"><summary>Details (job log)</summary><pre class="vb-pre vb-pre-tall" id="vbSheetLog"></pre></details>
				<div class="vb-intro-actions" id="vbSheetActions">
					<button type="button" class="vb-btn vb-btn-accent" id="vbSheetRetry" hidden>Retry</button>
					<button type="button" class="vb-btn vb-btn-ghost" id="vbSheetCopy">Copy log</button>
					<button type="button" class="vb-btn vb-btn-ghost" id="vbSheetClose" hidden>Close</button>
					<button type="button" class="vb-btn vb-btn-danger-ghost" id="vbSheetCancel">Cancel install</button>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================== New Vibe ============================== -->
	<div class="mods-modal-overlay vb-modal-overlay" id="vbNewVibe">
		<div class="mods-modal vb-modal vb-modal-sm" role="dialog" aria-modal="true">
			<div class="mods-modal-header"><h3 class="mods-modal-title">New Vibe</h3><button type="button" class="mods-modal-close" data-close>&times;</button></div>
			<form class="mods-modal-body vb-form" id="vbNewVibeForm">
				<label class="vb-field">Mod name <span class="vb-muted">PascalCase, no spaces &mdash; becomes the DLL and the catalogue name</span>
					<input type="text" class="vb-input" id="vbNvName" required pattern="[A-Za-z][A-Za-z0-9_]{1,95}" placeholder="NoMovementPenalty" autocomplete="off"></label>
				<label class="vb-field">Namespace <span class="vb-muted">the catalogue owner; fixed once published</span>
					<input type="text" class="vb-input" id="vbNvNs" pattern="[A-Za-z0-9_]{1,80}" placeholder="MyServer" autocomplete="off"></label>
				<label class="vb-field">What should it do? <span class="vb-muted">one sentence, up to 250 characters</span>
					<textarea class="vb-input" id="vbNvDesc" rows="3" maxlength="250" placeholder="Removes the movement speed penalty from carried weight."></textarea></label>
				<label class="vb-field">Template <select class="vb-input" id="vbNvTemplate"></select></label>
				<p class="vb-help" id="vbNvTemplateHelp"></p>
				<p class="vb-inline-error" id="vbNvError" hidden></p>
				<div class="vb-row-actions vb-right">
					<button type="button" class="vb-btn vb-btn-ghost" data-close>Cancel</button>
					<button type="submit" class="vb-btn vb-btn-accent" id="vbNvCreate">Create</button>
				</div>
			</form>
		</div>
	</div>

	<!-- ============================== Delete one Vibe ============================== -->
	<div class="mods-modal-overlay vb-modal-overlay" id="vbDeleteVibe">
		<div class="mods-modal vb-modal vb-modal-sm" role="dialog" aria-modal="true">
			<div class="mods-modal-header"><h3 class="mods-modal-title">Delete this Vibe</h3><button type="button" class="mods-modal-close" data-close>&times;</button></div>
			<div class="mods-modal-body vb-form">
				<p id="vbDvText"></p>
				<ul class="vb-list" id="vbDvList"></ul>
				<p class="vb-help">Published versions already in the catalogue stay there until you unpublish them. <a id="vbDvExport" href="#" download>Export the repo first</a>.</p>
				<label class="vb-field">Type the Vibe's name to confirm <input type="text" class="vb-input" id="vbDvConfirm" autocomplete="off"></label>
				<p class="vb-inline-error" id="vbDvError" hidden></p>
				<div class="vb-row-actions vb-right">
					<button type="button" class="vb-btn vb-btn-ghost" data-close>Keep it</button>
					<button type="button" class="vb-btn vb-btn-danger" id="vbDvDelete" disabled>Delete</button>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================== Generic confirm ============================== -->
	<div class="mods-modal-overlay vb-modal-overlay" id="vbConfirm">
		<div class="mods-modal vb-modal vb-modal-sm" role="dialog" aria-modal="true">
			<div class="mods-modal-header"><h3 class="mods-modal-title" id="vbConfirmTitle">Are you sure?</h3><button type="button" class="mods-modal-close" data-close>&times;</button></div>
			<div class="mods-modal-body vb-form">
				<p id="vbConfirmText"></p>
				<div class="vb-row-actions vb-right">
					<button type="button" class="vb-btn vb-btn-ghost" data-close>Cancel</button>
					<button type="button" class="vb-btn vb-btn-accent" id="vbConfirmOk">Continue</button>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================== Settings ============================== -->
	<div class="mods-modal-overlay vb-modal-overlay" id="vbSettings">
		<div class="mods-modal vb-modal vb-modal-lg vb-settings" role="dialog" aria-modal="true">
			<div class="mods-modal-header"><h3 class="mods-modal-title">Hugin&rsquo;s Vibes &middot; Settings</h3><button type="button" class="mods-modal-close" data-close>&times;</button></div>
			<div class="vb-settings-grid">
				<nav class="vb-settings-nav" id="vbSettingsNav">
					<button type="button" class="active" data-stab="general">General</button>
					<button type="button" data-stab="limits">Turn limits</button>
					<button type="button" data-stab="dev">Dev Environment</button>
					<button type="button" data-stab="libs">Libraries</button>
					<button type="button" data-stab="assets">Assets</button>
					<button type="button" data-stab="publishing">Publishing</button><button type="button" data-stab="github">GitHub</button>
					<button type="button" data-stab="gitlab">GitLab</button>
					<button type="button" data-stab="advanced">Advanced</button>
					<button type="button" class="vb-danger-text" data-stab="danger">Danger zone</button>
				</nav>
				<div class="mods-modal-body vb-settings-body">
					<form class="vb-stab active vb-form" data-stab="general" id="vbSettingsFormGeneral">
						<label class="vb-field">Author namespace <span class="vb-muted">letters, digits, underscores. Becomes the catalogue owner of every mod and <strong>must not change after publishing</strong>.</span>
							<input type="text" class="vb-input" data-setting="author.namespace" pattern="[A-Za-z0-9_]*" autocomplete="off"></label>
						<label class="vb-field">Display name <input type="text" class="vb-input" data-setting="author.display" autocomplete="off"></label>
						<label class="vb-field">Website URL <span class="vb-muted">goes into every manifest's website_url</span><input type="url" class="vb-input" data-setting="author.url" autocomplete="off"></label>
						<label class="vb-field">Default template <select class="vb-input" data-setting="defaults.template" id="vbSetTemplate"></select></label>
						<label class="vb-field">Default AI provider <span class="vb-muted">empty = the server's default provider</span>
							<select class="vb-input" data-setting="defaults.provider_id" data-type="int-or-null" id="vbSetProvider"></select></label>
						<label class="vb-field">Default model <span class="vb-muted">free text; sent to the provider verbatim, never validated</span>
							<input type="text" class="vb-input" data-setting="defaults.model" list="vbSetModelList" autocomplete="off"><datalist id="vbSetModelList"></datalist></label>
						<div class="vb-row-actions vb-right"><button type="submit" class="vb-btn vb-btn-accent">Save</button></div>
					</form>

					<form class="vb-stab vb-form" data-stab="limits">
						<h4>Turn limits</h4>
						<p class="vb-help">These limits apply to the next turn across all Vibes. A running turn keeps the limits it started with.</p>
						<div class="vb-row-actions" aria-label="Limit presets">
							<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" data-limit-preset="standard">Standard · 100 rounds / 15 min</button>
							<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" data-limit-preset="long">Long task · 200 rounds / 30 min</button>
							<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" data-limit-preset="slow">Slow model · 100 rounds / 60 min</button>
						</div>
						<p class="vb-help">Presets fill the fields below. Review them, then Save limits. Higher limits can increase model usage and cost.</p>
						<label class="vb-field">Tool rounds per turn <span class="vb-muted">One model response and its requested actions. Default 100; range 1–200.</span><input type="number" class="vb-input" data-setting="agent.max_rounds" data-type="int" min="1" max="200" required></label>
						<label class="vb-field">Turn time limit (minutes) <span class="vb-muted">Total time budget for the turn. Default 15; range 0.5–120.</span><input type="number" class="vb-input" data-setting="agent.turn_timeout_s" data-type="minutes" min="0.5" max="120" step="any" required></label>
						<label class="vb-field">Model response limit (minutes) <span class="vb-muted">Maximum wait for one reply, including streaming. Default 15; range 0.5–120.</span><input type="number" class="vb-input" data-setting="agent.model_timeout_s" data-type="minutes" min="0.5" max="120" step="any" required></label>
						<p class="vb-help" id="vbLimitsSummary" role="status"></p>
						<p class="vb-help">A tool-round limit is not a timeout. Model calls obey the remaining turn time, which is also checked between tools. Saved files remain; send “Continue” to resume after a stop. Changes apply to the next turn.</p>
						<div class="vb-row-actions vb-right"><button type="submit" class="vb-btn vb-btn-accent">Save limits</button></div>
					</form>

					<form class="vb-stab vb-form" data-stab="dev">
						<div class="vb-card vb-card-flat">
							<h4>Build Pak</h4>
							<div class="vb-kv" id="vbSetPak"></div>
							<div class="vb-row-actions">
								<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" id="vbSetPakCheck">Check for updates</button>
								<button type="button" class="vb-btn vb-btn-accent vb-btn-sm" id="vbSetPakUpdate" data-job="pak-update" hidden>Update</button>
								<button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" id="vbSetPakRedownload" data-job="pak-update">Re-download</button>
							</div>
							<p class="vb-help" id="vbSetPakHelp"></p>
						</div>
						<label class="vb-field">Configuration <select class="vb-input" data-setting="defaults.configuration"><option>Release</option><option>Debug</option></select></label>
						<label class="vb-field">dotnet verbosity <select class="vb-input" data-setting="advanced.dotnet_verbosity"><option>quiet</option><option>minimal</option><option>normal</option><option>detailed</option></select></label>
						<label class="vb-field">Context budget (tokens) <input type="number" class="vb-input" data-setting="agent.context_budget_tokens" data-type="int" min="4000" max="400000" step="1000"></label>
						<label class="vb-switch"><input type="checkbox" data-setting="agent.auto_build" data-type="bool"><span class="vb-switch-track"></span> Build after every turn that changed code</label>
						<label class="vb-switch"><input type="checkbox" data-setting="agent.auto_commit" data-type="bool"><span class="vb-switch-track"></span> Commit after every green build</label>
						<label class="vb-switch vb-switch-danger"><input type="checkbox" data-setting="agent.unrestricted_shell" data-type="bool"><span class="vb-switch-track"></span> Unrestricted shell</label>
						<p class="vb-help vb-danger-text">Off, Hugin may only run the allow-listed build tools inside the project. On, he can run any command this container's phvalheim user can &mdash; including ones that touch your worlds' files. Leave this off unless you are debugging the toolchain yourself.</p>
						<div class="vb-row-actions vb-right"><button type="submit" class="vb-btn vb-btn-accent">Save</button></div>
					</form>

					<form class="vb-stab vb-form" data-stab="libs">
						<div class="vb-card vb-card-flat"><h4>Harvested game libraries</h4><div class="vb-kv" id="vbSetLibs"></div></div>
						<label class="vb-field">Source world <span class="vb-muted">the world whose dedicated-server files are read; empty = the first that has them</span>
							<select class="vb-input" data-setting="libs.source_world" id="vbSetSourceWorld"></select></label>
						<label class="vb-switch"><input type="checkbox" data-setting="libs.decompile" data-type="bool"><span class="vb-switch-track"></span> Decompile Valheim's assemblies so Hugin can read the game's source (+~150 MB)</label>
						<label class="vb-switch"><input type="checkbox" data-setting="libs.publicize" data-type="bool"><span class="vb-switch-track"></span> Publicize assemblies (lets mods reach private members without reflection)</label>
						<div class="vb-row-actions">
							<button type="submit" class="vb-btn vb-btn-accent">Save</button>
							<button type="button" class="vb-btn vb-btn-ghost" id="vbSetReharvest" data-job="harvest">&#8635; Re-harvest</button>
						</div>
						<h4>Files</h4>
						<div class="vb-file-list" id="vbSetLibFiles"></div>
					</form>

					<div class="vb-stab vb-form" data-stab="assets">
						<form id="vbAssetsLimitForm">
							<label class="vb-field">Upload limit (MB) <span class="vb-muted" id="vbAssetsLimitNote"></span><input type="number" class="vb-input" data-setting="assets.max_upload_mb" data-type="int" min="1" max="1024"></label>
							<div class="vb-row-actions vb-right"><button type="submit" class="vb-btn vb-btn-accent vb-btn-sm">Save</button></div>
						</form>
						<div class="vb-dropzone" id="vbDropzone" tabindex="0">
							<input type="file" id="vbAssetFile" multiple hidden>
							<span>Drop files here or <u>choose</u> &mdash; icons, textures, audio, reference zips. Hugin sees them under <code>assets/</code>.</span>
						</div>
						<div class="vb-asset-list" id="vbAssetList"></div>
					</div>

					<form class="vb-stab vb-form" data-stab="publishing"><h3>Hosted catalogues</h3><p class="vb-help">Publishing uploads a public release. Use a service-account API token for your team. Tokens stay on this server.</p><h4>Thunderstore</h4><label class="vb-field">Team / namespace<input class="vb-input" data-setting="thunderstore.team"></label><label class="vb-field">API token<input type="password" autocomplete="off" class="vb-input" data-setting="thunderstore.token"></label><label class="vb-field">Categories (comma separated identifiers)<input class="vb-input" data-setting="thunderstore.categories"></label><h4>Hexium</h4><label class="vb-field">Team / namespace<input class="vb-input" data-setting="hexium.team"></label><label class="vb-field">API token<input type="password" autocomplete="off" class="vb-input" data-setting="hexium.token"></label><label class="vb-field">Categories (comma separated identifiers)<input class="vb-input" data-setting="hexium.categories"></label><button type="submit" class="vb-btn vb-btn-accent">Save</button></form><form class="vb-stab vb-form" data-stab="github">
						<label class="vb-switch"><input type="checkbox" data-setting="github.enabled" data-type="bool"><span class="vb-switch-track"></span> Push Vibes to GitHub</label>
						<label class="vb-field">Personal access token <span class="vb-muted">needs <code>repo</code>; shown masked, leave as is to keep it</span><input type="password" class="vb-input" data-setting="github.token" autocomplete="off"></label>
						<label class="vb-field">Owner / organisation <input type="text" class="vb-input" data-setting="github.owner" autocomplete="off"></label>
						<label class="vb-field">Default visibility <select class="vb-input" data-setting="github.visibility"><option>private</option><option>public</option><option>internal</option></select></label>
						<label class="vb-field">API base <input type="url" class="vb-input" data-setting="github.api" autocomplete="off"></label>
						<p class="vb-scm-result" id="vbGitHubResult"></p>
						<div class="vb-row-actions vb-right">
							<button type="button" class="vb-btn vb-btn-ghost" data-scm-test="github">Test connection</button>
							<button type="submit" class="vb-btn vb-btn-accent">Save</button>
						</div>
					</form>

					<form class="vb-stab vb-form" data-stab="gitlab">
						<label class="vb-switch"><input type="checkbox" data-setting="gitlab.enabled" data-type="bool"><span class="vb-switch-track"></span> Push Vibes to GitLab</label>
						<label class="vb-field">Host <input type="url" class="vb-input" data-setting="gitlab.host" autocomplete="off"></label>
						<label class="vb-field">Personal access token <span class="vb-muted">needs <code>api</code>; shown masked, leave as is to keep it</span><input type="password" class="vb-input" data-setting="gitlab.token" autocomplete="off"></label>
						<label class="vb-field">Group <span class="vb-muted">e.g. my-group/valheim</span><input type="text" class="vb-input" data-setting="gitlab.group" autocomplete="off"></label>
						<label class="vb-field">Default visibility <select class="vb-input" data-setting="gitlab.visibility"><option>private</option><option>public</option><option>internal</option></select></label>
						<p class="vb-scm-result" id="vbGitLabResult"></p>
						<div class="vb-row-actions vb-right">
							<button type="button" class="vb-btn vb-btn-ghost" data-scm-test="gitlab">Test connection</button>
							<button type="submit" class="vb-btn vb-btn-accent">Save</button>
						</div>
					</form>

					<form class="vb-stab vb-form" data-stab="advanced">
						<label class="vb-field">Build Pak repository <span class="vb-muted">owner/repo on GitHub</span><input type="text" class="vb-input" data-setting="buildpak.repo" autocomplete="off"></label>
						<label class="vb-field">Channel <select class="vb-input" data-setting="buildpak.channel"><option>stable</option><option>beta</option></select></label>
						<label class="vb-field">Build Pak override URL <span class="vb-muted">https:// or file:///opt/stateful/&hellip; &mdash; replaces the GitHub lookup entirely (air-gapped servers, local testing)</span><input type="text" class="vb-input" data-setting="buildpak.override_url" autocomplete="off"></label>
						<label class="vb-switch"><input type="checkbox" data-setting="buildpak.auto_check" data-type="bool"><span class="vb-switch-track"></span> Check for Build Pak updates daily</label>
						<label class="vb-switch"><input type="checkbox" data-setting="advanced.allow_prerelease_pak" data-type="bool"><span class="vb-switch-track"></span> Allow pre-release Build Paks</label>
						<label class="vb-switch"><input type="checkbox" data-setting="advanced.nuget_online" data-type="bool"><span class="vb-switch-track"></span> Allow NuGet to reach nuget.org (off = pak cache only)</label>
						<div class="vb-field-row">
							<label class="vb-field">Keep transcripts (days, 0 = forever) <input type="number" class="vb-input" data-setting="advanced.keep_transcripts_days" data-type="int" min="0" max="3650"></label>
							<label class="vb-field">Log level <select class="vb-input" data-setting="advanced.log_level"><option>debug</option><option>info</option><option>warn</option></select></label>
						</div>
						<div class="vb-row-actions">
							<button type="button" class="vb-btn vb-btn-ghost" id="vbSetExport">Export settings</button>
							<button type="button" class="vb-btn vb-btn-ghost" id="vbSetImport">Import&hellip;</button>
							<input type="file" id="vbSetImportFile" accept="application/json" hidden>
							<button type="button" class="vb-btn vb-btn-ghost" id="vbSetGc" data-job="gc" title="Prune bin/ obj/ and stale downloads">Run cleanup (gc)</button>
							<span class="vb-spacer"></span>
							<button type="submit" class="vb-btn vb-btn-accent">Save</button>
						</div>
					</form>

					<div class="vb-stab vb-form" data-stab="danger">
						<div class="vb-card vb-card-danger">
							<h4>Delete Hugin&rsquo;s Vibes</h4>
							<p>Removes the Build Pak and the harvested game libraries from <code id="vbDzRoot">/opt/stateful/vibes</code> and marks Vibes as not installed. Your worlds are not touched. Choose what to keep:</p>
							<label class="vb-switch"><input type="checkbox" id="vbDzKeepProjects" checked><span class="vb-switch-track"></span> Keep projects (the git repositories and transcripts)</label>
							<label class="vb-switch"><input type="checkbox" id="vbDzKeepCatalog" checked><span class="vb-switch-track"></span> Keep the published packages in the local catalogue</label>
							<label class="vb-switch"><input type="checkbox" id="vbDzKeepAssets" checked><span class="vb-switch-track"></span> Keep uploaded assets</label>
							<label class="vb-switch"><input type="checkbox" id="vbDzWipeSettings"><span class="vb-switch-track"></span> Also reset these settings (tokens included)</label>
							<div class="vb-dz-summary" id="vbDzSummary"></div>
							<div class="vb-dz-worlds" id="vbDzWorlds"></div>
							<label class="vb-field">Type <strong>DELETE</strong> to confirm <input type="text" class="vb-input" id="vbDzConfirm" autocomplete="off" placeholder="DELETE"></label>
							<div class="vb-row-actions vb-right"><button type="button" class="vb-btn vb-btn-danger" id="vbDzDelete" disabled>Delete Hugin&rsquo;s Vibes</button></div>
							<div class="vb-dz-progress" id="vbDzProgress" hidden>
								<div class="vb-progress"><div class="vb-progress-fill" id="vbDzBar"></div></div>
								<p class="vb-muted" id="vbDzStep"></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php // JSON_HEX_TAG keeps a "</script>" inside any string from ending the block early. ?>
	<div class="mods-modal-overlay vb-modal-overlay" id="vbImport" role="dialog" aria-modal="true" aria-labelledby="vbImportTitle"><div class="mods-modal vb-modal vb-modal-sm"><div class="mods-modal-body vb-form"><h2 id="vbImportTitle">Import a Vibe</h2><form id="vbImportForm" class="vb-form"><label class="vb-field">GitHub or GitLab repository URL<input id="vbImportUrl" class="vb-input" type="url" required placeholder="https://github.com/team/mod"></label><p class="vb-help">Public repositories work immediately. Private repositories use the matching token in Settings. Your code and history are kept; imports are never built automatically.</p><div class="vb-row-actions"><button type="submit" class="vb-btn vb-btn-accent" id="vbImportSubmit">Import</button><button type="button" class="vb-btn vb-btn-ghost" data-close="vbImport">Cancel</button></div><p id="vbImportStatus" role="status"></p></form></div></div></div><script type="application/json" id="vbInitial"><?php echo json_encode($vibesInitial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
	<script src="/js/vibes.js?v=<?php echo $jsV; ?>"></script>
</body>
</html>
