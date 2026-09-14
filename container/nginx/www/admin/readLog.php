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
    if ($logFile === 'nginx.log') {
        $highlightExclusions[] = 'phvalheim';
    }
    echo getFormattedLogContent($logFile, $exclusionsToUse, $highlightExclusions, $logHighlight, $logHighlightError, $logHighlightWarn, $logHighlightNotice, $logHighlightGreen, $logHighlightErrorDarker, $logHighlightWarnDarker, $logHighlightNoticeDarker, $logHighlightGreenDarker, $logHighlightMagenta, $logHighlightMagentaDarker, $logHighlightCyan, $logHighlightCyanDarker, $useExclusions);
    exit;
}

function getFormattedLogContent($logFile, $logExclusions, $highlightExclusions, $logHighlight, $logHighlightError, $logHighlightWarn, $logHighlightNotice, $logHighlightGreen, $logHighlightErrorDarker, $logHighlightWarnDarker, $logHighlightNoticeDarker, $logHighlightGreenDarker, $logHighlightMagenta, $logHighlightMagentaDarker, $logHighlightCyan, $logHighlightCyanDarker, $useReplacements = true) {
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
        if (!$excluded && trim($line) !== '') {
            // ESCAPE BEFORE THIS TEXT BECOMES HTML.
            //
            // Valheim stack traces are full of angle-bracketed fragments -- assembly
            // GUIDs like <207a02655d0b483ca679ce75910d2c5e>, compiler-generated names
            // like <ZNet::SaveWorld> and <DelayedSave>. This log had 1,424 lines
            // containing one. Injected raw, the browser parses them as unknown TAGS: it
            // opens elements that never close, swallows the following lines into them,
            // and relocates the <br> separators -- which is why the viewer showed runs
            // of up to 63 blank lines while consecutive log entries were glued together
            // with no break at all. The file itself is fine; the damage was all in the
            // rendering.
            //
            // Escaping here rather than later means everything downstream -- nl2br, the
            // highlight wrapping, the message replacements -- operates on inert text,
            // and the only HTML in the output is the markup this file adds itself.
            // It also closes an injection sink: log lines carry player and mod names.
            //
            // Safe to do before the keyword matching: no entry in $logHighlight,
            // $logExclusions or $highlightExclusions contains <, > or &.
            $filteredLines[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        }
    }

    $logOutput = nl2br(implode("\n", $filteredLines));

    // Put log lines into an array for highlighting
    $logArray = explode("\n", $logOutput);
    $result = '';

    foreach ($logArray as $key => $logEntry) {
        // Steam flaky install message
        if ($useReplacements && preg_match('/Failed to install app.*896660.*Missing configuration/i', $logEntry)) {
            $ts = date('D M d H:i:s T Y');
            $logEntry = "$ts [WARN : phvalheim] Steam is having trouble. We'll retry. If all 5 attempts are unsuccessful, you will need to click Update again.";
        }

        $skipHighlight = false;
        foreach ($highlightExclusions as $highlightExclusion) {
            if (stripos($logEntry, $highlightExclusion) !== false) {
                $skipHighlight = true;
                break;
            }
        }

        foreach ($logHighlight as $keyword => $alertType) {
            if (!$skipHighlight && stripos($logEntry, $keyword) !== false) {
                $cleanEntry = preg_replace('/<br\s*\/?>\s*$/i', '', ltrim($logEntry));
                if ($alertType == "error") {
                    $logEntry = "<span style='background:$logHighlightError;color:$logHighlightErrorDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;'>$cleanEntry</span>";
                }
                if ($alertType == "warn") {
                    $logEntry = "<span style='background:$logHighlightWarn;color:$logHighlightWarnDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;'>$cleanEntry</span>";
                }
                if ($alertType == "notice") {
                    $logEntry = "<span style='background:$logHighlightNotice;color:$logHighlightNoticeDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;'>$cleanEntry</span>";
                }
                if ($alertType == "magenta") {
                    $logEntry = "<span style='background:$logHighlightMagenta;color:$logHighlightMagentaDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;'>$cleanEntry</span>";
                }
                if ($alertType == "cyan") {
                    $logEntry = "<span style='background:$logHighlightCyan;color:$logHighlightCyanDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;'>$cleanEntry</span>";
                }
                break;
            }
        }

// Remove error messages
        if (preg_match('/[S_API FAIL] Tried to access Steam interface(.*)/i', $logEntry)) {
            $logEntry = "";
        }
        if (preg_match('/ILocalize(.*)/i', $logEntry)) {
            $logEntry = "";
        }

        // Ready for connections message - after highlight loop so the banner isn't re-processed
        if ($useReplacements && preg_match('/Opened Steam server/i', $logEntry)) {
            $worldName = preg_replace('/^valheimworld_(.+)\.log$/', '$1', $logFile);
            if (preg_match('/^(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})/', $logEntry, $tsMatch)) {
                $dt = DateTime::createFromFormat('m/d/Y H:i:s', $tsMatch[1], new DateTimeZone('UTC'));
                $ts = $dt->format('D M d H:i:s') . ' UTC ' . $dt->format('Y');
            } else {
                $ts = date('D M d H:i:s T Y');
            }
            $logEntry .= "<span style='background:$logHighlightGreen;color:$logHighlightGreenDarker;border-radius:0.25rem;padding:0.125rem 0.35rem;display:block;width:fit-content;font-weight:500;'>$ts [NOTICE : phvalheim] Valheim world $worldName is online and ready for players.</span>";
        }

        // World completely stopped message
        if ($useReplacements && preg_match('/Net scene destroyed/i', $logEntry)) {
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
				line-height: 1.5;
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
<?php
// 2.45: always rendered. The button used to be gated on one of four fixed credentials
// being set, which meant an operator with no provider yet never saw that the feature
// existed. The panel it opens now runs a deterministic scan with no model at all, and
// prompts for a provider only if you ask it something.
?>
				<button class="control-btn ai-analyze-btn" id="aiAnalyzeBtn" onclick="analyzeWithAi()">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px;">
						<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
					</svg>
					Analyze with AI
				</button>
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

		// Analyze with AI.
		//
		// 2.45: this hands over the LOG we are looking at and a plain question. It no
		// longer ships a 1,400-character prompt telling the model how to read a log --
		// that prompt lived here, a second copy of it lived in index.php, and both were
		// duplicating instructions that now belong in aiSystemPrompt() server-side where
		// there is exactly one of them. It also asked for HTML output, which the panel
		// renders as Markdown.
		function analyzeWithAi() {
			let world = '';
			let question;

			if (logFile.startsWith('valheimworld_') && logFile.endsWith('.log')) {
				world = logFile.replace('valheimworld_', '').replace(/\.log$/, '');
				question = "Diagnose world '" + world + "'. Read its log since the most recent server start, "
				         + "compare what loaded against the mods configured for it, and tell me what is broken "
				         + "and exactly how to fix it.";
			} else {
				question = "Read " + logFile + " and tell me whether anything in it needs my attention. "
				         + "Quote the lines that matter.";
			}

			if (window.opener && !window.opener.closed && typeof window.opener.openAiHelperWithContext === 'function') {
				window.opener.openAiHelperWithContext(world, question);
				window.opener.focus();
			} else {
				window.open('/?aiWorld=' + encodeURIComponent(world) + '&aiAsk=' + encodeURIComponent(question), '_blank');
			}
		}
		</script>
	</body>
</html>
