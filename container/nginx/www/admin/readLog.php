<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

if (!empty($_GET['logfile'])) {
        $logFile = $_GET['logfile'];
}


function populateLogOutput($logFile,$logExclusions,$logHighlight,$logHighlightError,$logHighlightWarn,$logHighlightNotice,$logHighlightGreen,$logHighlightErrorDarker,$logHighlightWarnDarker,$logHighlightNoticeDarker,$logHighlightGreenDarker,$logHighlightMagenta,$logHighlightMagentaDarker){
	$logOutput = nl2br(file_get_contents( "/opt/stateful/logs/$logFile" ));


	#Log filters

	#Exclusions
	foreach ($logExclusions as $logExclusion) {
		$logOutput = preg_replace("/(.*)$logExclusion(.*)/i",'',$logOutput);
	}

	#Put log lines into an array. This is used for highlighting
	$logArray = explode("\n", $logOutput);

	#Highlighter
	foreach ($logArray as $key => $logEntry) {

		foreach ($logHighlight as $keyword => $alertType) {

			if (stripos($logEntry, $keyword) !== false) {

				if($alertType == "error") {
					$logEntry = "<p style='background:$logHighlightError;color:$logHighlightErrorDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
				}

                                if($alertType == "warn") {
                                        $logEntry = "<p style='background:$logHighlightWarn;color:$logHighlightWarnDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                                }

                                if($alertType == "notice") {
                                        $logEntry = "<p style='background:$logHighlightNotice;color:$logHighlightNoticeDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                                }

                                if($alertType == "magenta") {
                                        $logEntry = "<p style='background:$logHighlightMagenta;color:$logHighlightMagentaDarker;border-radius:0.25rem;padding:0.125rem 0.5rem;margin:0.125rem 0;'>$logEntry</p>";
                                }

			}

			#ready for connections message
			if (preg_match('/Game server connected/i', $logEntry))
			{
				$logEntry = "<br><p style='background:$logHighlightGreen;color:$logHighlightGreenDarker;border-radius:0.25rem;padding:0.25rem 0.75rem;margin:0.25rem 0;font-weight:500;'>Valheim World is online and ready for players.</p>";
			}

			#remove error message
                        if (preg_match('/[S_API FAIL] Tried to access Steam interface(.*)/i', $logEntry))
                        {
                                $logEntry = "";
                        }

                        #remove error message
                        if (preg_match('/ILocalize(.*)/i', $logEntry))
                        {
                                $logEntry = "";
                        }

			#world completely stopped message
                        if (preg_match('/Net scene destroyed/i', $logEntry))
                        {
                                $logEntry = "<br><p style='background:$logHighlightNotice;color:$logHighlightNoticeDarker;border-radius:0.25rem;padding:0.25rem 0.75rem;margin:0.25rem 0;font-weight:500;'>Valheim world sucessfully stopped.</p>";
                        }

		}
		print $logEntry;
	}
}

?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Log Viewer - <?php echo htmlspecialchars($logFile); ?></title>
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

			.log-container {
				background: var(--bg-secondary, #1e293b);
				border: 1px solid var(--border-color, #475569);
				border-radius: 0.5rem;
				padding: 1rem;
				overflow-x: auto;
				max-width: 100%;
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

			.refresh-btn {
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

			.refresh-btn:hover {
				color: var(--success, #4ade80);
				background-color: var(--bg-tertiary, #334155);
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

		<script>
			//scroll to bottom of page (latest log entry)
			history.scrollRestoration = "manual";
			window.onbeforeunload = function() {
				window.scrollTo(0, 0);
			};
		</script>
	</head>

	<body onload="window.location='#bottom';">
		<div class="log-container">
			<div class="log-content"><?php populateLogOutput($logFile,$logExclusions,$logHighlight,$logHighlightError,$logHighlightWarn,$logHighlightNotice,$logHighlightGreen,$logHighlightErrorDarker,$logHighlightWarnDarker,$logHighlightNoticeDarker,$logHighlightGreenDarker,$logHighlightMagenta,$logHighlightMagentaDarker);?></div>
		</div>

		<div class="log-controls">
			<button class="refresh-btn" onClick="window.location.reload();">Refresh</button>

			<div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
				<span class="legend-item" style="background: <?php echo $logHighlightError;?>;color: <?php echo $logHighlightErrorDarker;?>">Error</span>
				<span class="legend-item" style="background: <?php echo $logHighlightWarn;?>;color: <?php echo $logHighlightWarnDarker;?>">Warning</span>
				<span class="legend-item" style="background: <?php echo $logHighlightNotice;?>;color: <?php echo $logHighlightNoticeDarker;?>">Notice</span>
				<span class="legend-item" style="background: <?php echo $logHighlightGreen;?>;color: <?php echo $logHighlightGreenDarker;?>">Ready</span>
			</div>
		</div>

		<p id='bottom'></p>
	</body>

</html>
