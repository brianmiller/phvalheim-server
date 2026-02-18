<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

if (!empty($_GET['logfile'])) {
        $logFile = $_GET['logfile'];
}

// AJAX endpoint for fetching log content
if (!empty($_GET['fetch']) && $_GET['fetch'] === 'content') {
    header('Content-Type: text/html; charset=utf-8');
    $useExclusions = empty($_GET['noExclusions']) || $_GET['noExclusions'] !== '1';
    $exclusionsToUse = $useExclusions ? $logExclusions : array();
    echo getFormattedLogContent($logFile, $exclusionsToUse, $logHighlight, $logHighlightError, $logHighlightWarn, $logHighlightNotice, $logHighlightGreen, $logHighlightErrorDarker, $logHighlightWarnDarker, $logHighlightNoticeDarker, $logHighlightGreenDarker, $logHighlightMagenta, $logHighlightMagentaDarker, $logHighlightCyan, $logHighlightCyanDarker);
    exit;
}

function getFormattedLogContent($logFile, $logExclusions, $logHighlight, $logHighlightError, $logHighlightWarn, $logHighlightNotice, $logHighlightGreen, $logHighlightErrorDarker, $logHighlightWarnDarker, $logHighlightNoticeDarker, $logHighlightGreenDarker, $logHighlightMagenta, $logHighlightMagentaDarker, $logHighlightCyan, $logHighlightCyanDarker) {
    $logPath = "/opt/stateful/logs/$logFile";
    if (!file_exists($logPath)) {
        return "<span style='color: var(--danger);'>Log file not found: $logFile</span>";
    }

    $rawOutput = file_get_contents($logPath);

    // Strip ANSI escape codes
    $rawOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

    // Filter out excluded lines
    $lines = explode("\n", $rawOutput);
    $filteredLines = array();
    foreach ($lines as $line) {
        $excluded = false;
        foreach ($logExclusions as $logExclusion) {
            if (stripos($line, $logExclusion) !== false) {
                $excluded = true;
                break;
            }
        }
        if (!$excluded) {
            $filteredLines[] = $line;
        }
    }

    $logOutput = nl2br(implode("\n", $filteredLines));

    // Put log lines into an array for highlighting
    $logArray = explode("\n", $logOutput);
    $result = '';

    foreach ($logArray as $key => $logEntry) {
        // Steam flaky install message - do this first before any HTML wrapping
        if (preg_match('/Failed to install app.*896660.*Missing configuration/i', $logEntry)) {
            $logEntry = preg_replace('/<br\s*\/?>\s*$/', '', $logEntry) . " <strong><---- Steam is flaky, there is no misconfiguration. We'll retry. If all 5 attempts fail, you will need to click Update again.</strong>";
        }

        foreach ($logHighlight as $keyword => $alertType) {
            if (stripos($logEntry, $keyword) !== false) {
                if ($alertType == "error") {
                    $logEntry = "<p style='background:$logHighlightError;color:$logHighlightErrorDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                }
                if ($alertType == "warn") {
                    $logEntry = "<p style='background:$logHighlightWarn;color:$logHighlightWarnDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                }
                if ($alertType == "notice") {
                    $logEntry = "<p style='background:$logHighlightNotice;color:$logHighlightNoticeDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                }
                if ($alertType == "magenta") {
                    $logEntry = "<p style='background:$logHighlightMagenta;color:$logHighlightMagentaDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                }
                if ($alertType == "cyan") {
                    $logEntry = "<p style='background:$logHighlightCyan;color:$logHighlightCyanDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                }
            }
        }

        // Ready for connections message
        if (preg_match('/Game server connected/i', $logEntry)) {
            $logEntry = "<br><p style='background:$logHighlightGreen;color:$logHighlightGreenDarker;border-radius:0.25rem;padding:0.25rem 0.75rem;margin:0.25rem 0;font-weight:500;'>Valheim World is online and ready for players.</p>";
        }

        // Remove error messages
        if (preg_match('/[S_API FAIL] Tried to access Steam interface(.*)/i', $logEntry)) {
            $logEntry = "";
        }
        if (preg_match('/ILocalize(.*)/i', $logEntry)) {
            $logEntry = "";
        }

        // World completely stopped message
        if (preg_match('/Net scene destroyed/i', $logEntry)) {
            $logEntry = "<br><p style='background:$logHighlightNotice;color:$logHighlightNoticeDarker;border-radius:0.25rem;padding:0.25rem 0.75rem;margin:0.25rem 0;font-weight:500;'>Valheim world sucessfully stopped.</p>";
        }

        $result .= $logEntry;
    }

    return $result;
}

?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Log Viewer - <?php echo htmlspecialchars($logFile); ?></title>
		<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
		<link rel="stylesheet" type="text/css" href="/css/readLog.css?v=<?php echo time()?>">
		<link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">

		<style>
			body {
				font-family: var(--font-mono, ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace);
				background: var(--bg-primary, #0f172a);
				color: var(--text-primary, #e2e8f0);
				padding: 1rem;
				font-size: 0.8125rem;
				line-height: 1.4;
			}

			.log-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 1rem;
				padding-bottom: 0.75rem;
				border-bottom: 1px solid var(--border-color, #475569);
			}

			.log-title {
				font-size: 1rem;
				font-weight: 600;
				color: var(--accent-primary, #22d3ee);
			}

			.log-status {
				display: flex;
				align-items: center;
				gap: 0.5rem;
				font-size: 0.75rem;
			}

			.live-dot {
				width: 8px;
				height: 8px;
				background: var(--success, #4ade80);
				border-radius: 50%;
				animation: pulse-dot 2s ease-in-out infinite;
			}

			.live-dot.paused {
				background: var(--text-muted, #64748b);
				animation: none;
			}

			@keyframes pulse-dot {
				0%, 100% { opacity: 1; transform: scale(1); }
				50% { opacity: 0.5; transform: scale(0.9); }
			}

			.log-container {
				background: var(--bg-secondary, #1e293b);
				border: 1px solid var(--border-color, #475569);
				border-radius: 0.5rem;
				padding: 1rem;
				overflow-x: auto;
				max-width: 100%;
				max-height: calc(100vh - 200px);
				overflow-y: auto;
			}

			.log-content {
				white-space: pre-wrap;
				word-wrap: break-word;
			}

			.log-controls {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 1rem;
				padding: 1rem 0;
				border-top: 1px solid var(--border-color, #334155);
				margin-top: 1rem;
			}

			.legend-item {
				display: inline-flex;
				align-items: center;
				padding: 0.25rem 0.75rem;
				border-radius: 0.25rem;
				font-size: 0.75rem;
				font-weight: 500;
			}

			.control-btn {
				font-family: inherit;
				font-size: 0.8125rem;
				padding: 0.5rem 1rem;
				color: var(--accent-secondary, #a78bfa);
				background-color: var(--bg-secondary, #1e293b);
				border: 1px solid var(--accent-primary, #22d3ee);
				border-radius: 0.375rem;
				cursor: pointer;
				transition: all 0.2s ease;
			}

			.control-btn:hover {
				color: var(--success, #4ade80);
				background-color: var(--bg-tertiary, #334155);
			}

			.control-btn.active {
				background-color: var(--success-dark, #166534);
				border-color: var(--success, #4ade80);
				color: var(--success, #4ade80);
			}

			.ai-analyze-btn {
				color: var(--accent-secondary, #a78bfa) !important;
				border-color: var(--accent-secondary, #a78bfa) !important;
				margin-left: auto;
			}

			.ai-analyze-btn:hover {
				background: linear-gradient(135deg, rgba(167, 139, 250, 0.2), rgba(34, 211, 238, 0.2)) !important;
				color: var(--accent-secondary-hover, #c4b5fd) !important;
			}

			.control-group {
				display: flex;
				gap: 0.5rem;
			}

			@media (max-width: 768px) {
				body {
					padding: 0.5rem;
					font-size: 0.75rem;
				}

				.log-container {
					padding: 0.75rem;
				}

				.log-controls {
					flex-direction: column;
					align-items: flex-start;
				}

				.legend-item {
					font-size: 0.6875rem;
					padding: 0.125rem 0.5rem;
				}
			}
		</style>
	</head>

	<body>
		<div class="log-header">
			<span class="log-title"><?php echo htmlspecialchars($logFile); ?></span>
			<div class="log-status">
				<span class="live-dot" id="liveDot"></span>
				<span id="statusText">Live</span>
			</div>
		</div>

		<div class="log-container" id="logContainer">
			<div class="log-content" id="logContent">Loading...</div>
		</div>

		<div class="log-controls">
			<div class="control-group">
				<button class="control-btn active" id="liveBtn" onclick="toggleLive()">
					<span id="liveBtnText">⏸ Pause</span>
				</button>
				<button class="control-btn" onclick="scrollToBottom()">↓ Go to Bottom</button>
				<button class="control-btn" onclick="scrollToTop()">↑ Go to Top</button>
				<button class="control-btn" id="exclusionsBtn" onclick="toggleExclusions()">
					<span id="exclusionsBtnText">🔍 Show All</span>
				</button>
<?php if (!empty($aiKeys['openai']) || !empty($aiKeys['gemini']) || !empty($aiKeys['claude']) || !empty($aiKeys['ollama'])): ?>
				<button class="control-btn ai-analyze-btn" id="aiAnalyzeBtn" onclick="analyzeWithAi()">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px;">
						<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
					</svg>
					Analyze with AI
				</button>
<?php endif; ?>
			</div>

			<div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
				<span class="legend-item" style="background: <?php echo $logHighlightError;?>;color: <?php echo $logHighlightErrorDarker;?>">Error</span>
				<span class="legend-item" style="background: <?php echo $logHighlightWarn;?>;color: <?php echo $logHighlightWarnDarker;?>">Warning</span>
				<span class="legend-item" style="background: <?php echo $logHighlightNotice;?>;color: <?php echo $logHighlightNoticeDarker;?>">Notice</span>
				<span class="legend-item" style="background: <?php echo $logHighlightGreen;?>;color: <?php echo $logHighlightGreenDarker;?>">Ready</span>
			</div>
		</div>

		<script>
		const logFile = '<?php echo addslashes($logFile); ?>';
		let isLive = true;
		let pollInterval = null;
		let autoScroll = true;
		let noExclusions = false;
		const POLL_RATE = 2000; // 2 seconds

		// Initialize
		document.addEventListener('DOMContentLoaded', function() {
			fetchLog();
			startPolling();
		});

		function startPolling() {
			if (pollInterval) clearInterval(pollInterval);
			pollInterval = setInterval(fetchLog, POLL_RATE);
		}

		function stopPolling() {
			if (pollInterval) {
				clearInterval(pollInterval);
				pollInterval = null;
			}
		}

		async function fetchLog() {
			if (!isLive) return;

			try {
				const url = `readLog.php?logfile=${encodeURIComponent(logFile)}&fetch=content&noExclusions=${noExclusions ? '1' : '0'}&_=${Date.now()}`;
				const response = await fetch(url);
				const content = await response.text();

				const logContent = document.getElementById('logContent');
				const container = document.getElementById('logContainer');

				// Check if user is scrolled to bottom before update
				const isAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 50;

				logContent.innerHTML = content;

				// Auto-scroll to bottom if user was at bottom
				if (isAtBottom && autoScroll) {
					scrollToBottom();
				}
			} catch (error) {
				console.error('Failed to fetch log:', error);
			}
		}

		function toggleLive() {
			isLive = !isLive;
			const btn = document.getElementById('liveBtn');
			const btnText = document.getElementById('liveBtnText');
			const dot = document.getElementById('liveDot');
			const statusText = document.getElementById('statusText');

			if (isLive) {
				btnText.textContent = '⏸ Pause';
				btn.classList.add('active');
				dot.classList.remove('paused');
				statusText.textContent = 'Live';
				startPolling();
				fetchLog();
			} else {
				btnText.textContent = '▶ Resume';
				btn.classList.remove('active');
				dot.classList.add('paused');
				statusText.textContent = 'Paused';
				stopPolling();
			}
		}

		function toggleExclusions() {
			noExclusions = !noExclusions;
			const btn = document.getElementById('exclusionsBtn');
			const btnText = document.getElementById('exclusionsBtnText');

			if (noExclusions) {
				btnText.textContent = '🔍 Hide Filtered';
				btn.classList.add('active');
			} else {
				btnText.textContent = '🔍 Show All';
				btn.classList.remove('active');
			}
			fetchLog();
		}

		function scrollToBottom() {
			const container = document.getElementById('logContainer');
			container.scrollTop = container.scrollHeight;
		}

		function scrollToTop() {
			const container = document.getElementById('logContainer');
			container.scrollTop = 0;
		}

		// Detect manual scrolling to disable auto-scroll temporarily
		document.getElementById('logContainer').addEventListener('scroll', function() {
			const container = this;
			autoScroll = container.scrollHeight - container.scrollTop - container.clientHeight < 50;
		});

		// Analyze with AI — map log filename to AI context value
		function analyzeWithAi() {
			let context = 'none';
			let worldLabel = 'unknown';

			if (logFile === 'phvalheim.log') {
				context = 'engine';
				worldLabel = 'PhValheim Engine';
			} else if (logFile === 'tsSync.log') {
				context = 'ts';
				worldLabel = 'ThunderStore Sync';
			} else if (logFile === 'worldBackups.log') {
				context = 'backup';
				worldLabel = 'World Backups';
			} else if (logFile.startsWith('valheimworld_') && logFile.endsWith('.log')) {
				const worldName = logFile.replace('valheimworld_', '').replace('.log', '');
				context = 'world:' + worldName;
				worldLabel = worldName;
			}

			const displayLabel = "Analyzing world '" + worldLabel + "'...";
			const prompt = 'You are a Valheim server log analyzer. Your task is to identify mod-related errors that occur after the most recent server start.\n\nFOCUS ONLY ON:\n- Mod loading failures\n- Missing dependencies\n- NullReferenceException in mod code\n- Assembly loading errors\n- Mod configuration errors\n- Errors that prevent the world from starting\n\nCOMPLETELY IGNORE:\n- Graphics, shaders, rendering, cameras, depth, textures, fonts, UI\n- The createDirectory /root/.config error\n- ZoneSystem, DungeonDB, RPC registration messages\n- Audio warnings\n- Any warning that does not affect mod loading or server startup\n\nINSTRUCTIONS:\n1. Read the entire log\n2. Find the most recent server start marker\n3. Only analyze entries after that point\n4. List mod errors in the order they appear\n5. If a mod fails early in startup, mark it: PRIMARY INVESTIGATION AREA - MAY PREVENT WORLD START\n6. Output as HTML bullet points\n7. End with one sentence stating whether critical mod errors exist\n\nOUTPUT FORMAT:\n<ul>\n<li><strong>ModName</strong> - Brief error description</li>\n</ul>\n<p><strong>Overall Health:</strong> One sentence summary</p>\n\nKeep responses concise and focused only on actionable mod issues.';

			// Try to call the opener (admin dashboard)
			if (window.opener && !window.opener.closed && typeof window.opener.openAiHelperWithContext === 'function') {
				window.opener.openAiHelperWithContext(context, prompt, displayLabel);
				window.opener.focus();
			} else {
				// Fallback: open admin dashboard with context and prompt params
				window.open('/?aiContext=' + encodeURIComponent(context) + '&aiPrompt=' + encodeURIComponent(prompt) + '&aiLabel=' + encodeURIComponent(displayLabel), '_blank');
			}
		}
		</script>
	</body>
</html>
