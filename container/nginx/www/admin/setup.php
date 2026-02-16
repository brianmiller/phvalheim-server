<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';

// If setup is already complete, redirect to dashboard
if ($setupComplete >= 2 || ($setupComplete == 1 && $migrationNoticeShown == 1)) {
    header('Location: /');
    exit;
}

// If this is a migration (not fresh install), redirect to dashboard (migration notice shows there)
if ($setupComplete == 1) {
    header('Location: /');
    exit;
}

$detectedHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhValheim Setup</title>
    <link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
    <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
    <script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
    <style>
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .setup-container {
            max-width: 640px;
            width: 100%;
            padding: 2rem;
        }
        .setup-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
        }
        .setup-logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.5rem;
            display: block;
        }
        .setup-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        .setup-subtitle {
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }
        .setup-step {
            display: none;
        }
        .setup-step.active {
            display: block;
        }
        .setup-progress {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            justify-content: center;
        }
        .setup-progress-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border-color);
            transition: background 0.3s;
        }
        .setup-progress-dot.active {
            background: var(--accent-primary);
        }
        .setup-progress-dot.completed {
            background: var(--success);
        }
        .setup-field {
            margin-bottom: 1.25rem;
        }
        .setup-field label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .setup-field input {
            width: 100%;
            padding: 0.6rem 0.85rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: var(--font-mono);
        }
        .setup-field input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }
        .setup-field .field-help {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .setup-field .field-help a {
            color: var(--accent-primary);
        }
        .setup-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            gap: 1rem;
        }
        .setup-btn {
            padding: 0.6rem 1.5rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            transition: all 0.2s;
        }
        .setup-btn:hover {
            background: var(--bg-tertiary);
        }
        .setup-btn-primary {
            background: rgba(34, 211, 238, 0.1);
            color: var(--accent-primary);
            border-color: rgba(34, 211, 238, 0.3);
        }
        .setup-btn-primary:hover {
            background: rgba(34, 211, 238, 0.2);
        }
        .setup-btn-skip {
            background: transparent;
            border-color: transparent;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .setup-detected {
            background: var(--bg-tertiary);
            border-radius: 6px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .setup-detected-label {
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .setup-detected-value {
            color: var(--accent-primary);
            font-family: var(--font-mono);
            font-size: 0.9rem;
        }
        .setup-review-table {
            width: 100%;
            font-size: 0.85rem;
        }
        .setup-review-table td {
            padding: 0.4rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .setup-review-table td:first-child {
            color: var(--text-muted);
            width: 40%;
        }
        .setup-review-table td:last-child {
            color: var(--text-primary);
            font-family: var(--font-mono);
            word-break: break-all;
        }
        .setup-error {
            color: var(--danger);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: none;
        }
        .setup-step-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
        }
        .setup-step-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <img src="/images/phvalheim_favicon.svg" class="setup-logo" alt="PhValheim">
            <div class="setup-title">PhValheim Setup</div>
            <div class="setup-subtitle">v<?php echo $phvalheimVersion; ?></div>

            <div class="setup-progress">
                <div class="setup-progress-dot active" data-step="1"></div>
                <div class="setup-progress-dot" data-step="2"></div>
                <div class="setup-progress-dot" data-step="3"></div>
                <div class="setup-progress-dot" data-step="4"></div>
                <div class="setup-progress-dot" data-step="5"></div>
            </div>

            <!-- Step 1: Welcome -->
            <div class="setup-step active" data-step="1">
                <div class="setup-step-title">Welcome to PhValheim</div>
                <div class="setup-step-desc">Let's get your server configured. This will only take a minute.</div>

                <div class="setup-detected">
                    <div>
                        <div class="setup-detected-label">Detected Host</div>
                        <div class="setup-detected-value"><?php echo htmlspecialchars($detectedHost); ?></div>
                    </div>
                </div>
                <div class="field-help">This is the hostname detected from your browser. It will be used automatically for the web interface.</div>

                <div class="setup-actions">
                    <div></div>
                    <button class="setup-btn setup-btn-primary" onclick="goToStep(2)">Get Started</button>
                </div>
            </div>

            <!-- Step 2: Core Settings -->
            <div class="setup-step" data-step="2">
                <div class="setup-step-title">Core Settings</div>
                <div class="setup-step-desc">These are required for PhValheim to operate.</div>

                <div class="setup-field">
                    <label>Steam API Key</label>
                    <input type="text" id="setup-steamAPIKey" placeholder="Your Steam Web API key">
                    <div class="field-help">Required for player authentication. <a href="https://steamcommunity.com/dev/apikey" target="_blank">Get your key here</a>.</div>
                </div>

                <div class="setup-field">
                    <label>Game DNS</label>
                    <input type="text" id="setup-gameDNS" value="<?php echo htmlspecialchars(explode(':', $detectedHost)[0]); ?>" placeholder="hostname or IP for game connections">
                    <div class="field-help">The hostname or IP players use to connect their Valheim client. Often the same as your PhValheim host.</div>
                </div>

                <div class="setup-field">
                    <label>Base Port</label>
                    <input type="number" id="setup-basePort" value="25000" placeholder="25000">
                    <div class="field-help">Starting UDP port for game worlds. Must match the beginning of your Docker port range.</div>
                </div>

                <div class="setup-error" id="step2-error"></div>

                <div class="setup-actions">
                    <button class="setup-btn" onclick="goToStep(1)">Back</button>
                    <button class="setup-btn setup-btn-primary" onclick="validateStep2()">Next</button>
                </div>
            </div>

            <!-- Step 3: Defaults -->
            <div class="setup-step" data-step="3">
                <div class="setup-step-title">Defaults</div>
                <div class="setup-step-desc">These can be changed later from Server Settings.</div>

                <div class="setup-field">
                    <label>Backups to Keep</label>
                    <input type="number" id="setup-backupsToKeep" value="24" placeholder="24">
                    <div class="field-help">Number of world backups retained on disk before oldest are deleted.</div>
                </div>

                <div class="setup-field">
                    <label>Client Download URL</label>
                    <input type="text" id="setup-phvalheimClientURL" value="https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe" placeholder="URL to PhValheim Client installer">
                    <div class="field-help">Download URL shown to players for the PhValheim Client installer.</div>
                </div>

                <div class="setup-field">
                    <label>Timezone</label>
                    <select id="setup-timezone" style="width:100%;padding:0.6rem 0.85rem;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary);font-size:0.9rem;">
                        <option value="Etc/UTC" selected>(GMT)  UTC</option>
                        <option value="Pacific/Kwajalein">(GMT -12:00) Eniwetok, Kwajalein</option>
                        <option value="Pacific/Midway">(GMT -11:00) Midway Island, Samoa</option>
                        <option value="Pacific/Honolulu">(GMT -10:00) Hawaii</option>
                        <option value="Pacific/Marquesas">(GMT -9:30) Marquesas Islands</option>
                        <option value="America/Anchorage">(GMT -9:00) Alaska</option>
                        <option value="America/Los_Angeles">(GMT -8:00) Pacific Time (US &amp; Canada)</option>
                        <option value="America/Denver">(GMT -7:00) Mountain Time (US &amp; Canada)</option>
                        <option value="America/Chicago">(GMT -6:00) Central Time (US &amp; Canada), Mexico City</option>
                        <option value="America/New_York">(GMT -5:00) Eastern Time (US &amp; Canada), Bogota, Lima</option>
                        <option value="America/Caracas">(GMT -4:30) Caracas</option>
                        <option value="America/Halifax">(GMT -4:00) Atlantic Time (Canada), La Paz</option>
                        <option value="America/St_Johns">(GMT -3:30) Newfoundland</option>
                        <option value="America/Sao_Paulo">(GMT -3:00) Brazil, Buenos Aires, Georgetown</option>
                        <option value="Atlantic/South_Georgia">(GMT -2:00) Mid-Atlantic</option>
                        <option value="Atlantic/Azores">(GMT -1:00) Azores, Cape Verde Islands</option>
                        <option value="Europe/London">(GMT)  Western Europe Time, London, Lisbon, Casablanca</option>
                        <option value="Europe/Paris">(GMT +1:00) Brussels, Copenhagen, Madrid, Paris</option>
                        <option value="Europe/Kaliningrad">(GMT +2:00) Kaliningrad, South Africa</option>
                        <option value="Europe/Moscow">(GMT +3:00) Baghdad, Riyadh, Moscow, St. Petersburg</option>
                        <option value="Asia/Tehran">(GMT +3:30) Tehran</option>
                        <option value="Asia/Dubai">(GMT +4:00) Abu Dhabi, Muscat, Baku, Tbilisi</option>
                        <option value="Asia/Kabul">(GMT +4:30) Kabul</option>
                        <option value="Asia/Karachi">(GMT +5:00) Ekaterinburg, Islamabad, Karachi, Tashkent</option>
                        <option value="Asia/Kolkata">(GMT +5:30) Mumbai, Kolkata, New Delhi</option>
                        <option value="Asia/Kathmandu">(GMT +5:45) Kathmandu, Pokhara</option>
                        <option value="Asia/Dhaka">(GMT +6:00) Almaty, Dhaka, Colombo</option>
                        <option value="Asia/Yangon">(GMT +6:30) Yangon, Mandalay</option>
                        <option value="Asia/Bangkok">(GMT +7:00) Bangkok, Hanoi, Jakarta</option>
                        <option value="Asia/Singapore">(GMT +8:00) Beijing, Perth, Singapore, Hong Kong</option>
                        <option value="Australia/Eucla">(GMT +8:45) Eucla</option>
                        <option value="Asia/Tokyo">(GMT +9:00) Tokyo, Seoul, Osaka, Sapporo, Yakutsk</option>
                        <option value="Australia/Adelaide">(GMT +9:30) Adelaide, Darwin</option>
                        <option value="Australia/Sydney">(GMT +10:00) Eastern Australia, Guam, Vladivostok</option>
                        <option value="Australia/Lord_Howe">(GMT +10:30) Lord Howe Island</option>
                        <option value="Pacific/Guadalcanal">(GMT +11:00) Magadan, Solomon Islands, New Caledonia</option>
                        <option value="Pacific/Norfolk">(GMT +11:30) Norfolk Island</option>
                        <option value="Pacific/Auckland">(GMT +12:00) Auckland, Wellington, Fiji, Kamchatka</option>
                        <option value="Pacific/Chatham">(GMT +12:45) Chatham Islands</option>
                        <option value="Pacific/Apia">(GMT +13:00) Apia, Nukualofa</option>
                        <option value="Pacific/Kiritimati">(GMT +14:00) Line Islands, Tokelau</option>
                    </select>
                    <div class="field-help">Used for logs, backups, and timestamps throughout the system.</div>
                </div>

                <div class="setup-actions">
                    <button class="setup-btn" onclick="goToStep(2)">Back</button>
                    <button class="setup-btn setup-btn-primary" onclick="goToStep(4)">Next</button>
                </div>
            </div>

            <!-- Step 4: AI Helper (Optional) -->
            <div class="setup-step" data-step="4">
                <div class="setup-step-title">AI Helper</div>
                <div class="setup-step-desc">Optional. Configure AI-powered log analysis. You can skip this and set it up later.</div>

                <div class="setup-field">
                    <label>OpenAI API Key</label>
                    <input type="password" id="setup-openaiApiKey" placeholder="sk-...">
                </div>

                <div class="setup-field">
                    <label>Anthropic Claude API Key</label>
                    <input type="password" id="setup-claudeApiKey" placeholder="sk-ant-...">
                </div>

                <div class="setup-field">
                    <label>Google Gemini API Key</label>
                    <input type="password" id="setup-geminiApiKey" placeholder="AI...">
                </div>

                <div class="setup-field">
                    <label>Ollama URL</label>
                    <input type="text" id="setup-ollamaUrl" placeholder="http://192.168.1.100:11434">
                </div>

                <div class="setup-actions">
                    <button class="setup-btn" onclick="goToStep(3)">Back</button>
                    <div>
                        <button class="setup-btn setup-btn-skip" onclick="goToStep(5)">Skip</button>
                        <button class="setup-btn setup-btn-primary" onclick="goToStep(5)">Next</button>
                    </div>
                </div>
            </div>

            <!-- Step 5: Review & Save -->
            <div class="setup-step" data-step="5">
                <div class="setup-step-title">Review & Save</div>
                <div class="setup-step-desc">Verify your settings before saving.</div>

                <table class="setup-review-table" id="reviewTable">
                    <tbody></tbody>
                </table>

                <div class="setup-error" id="step5-error"></div>

                <div class="setup-actions">
                    <button class="setup-btn" onclick="goToStep(4)">Back</button>
                    <button class="setup-btn setup-btn-primary" id="saveBtn" onclick="saveSetup()">Save & Start</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentStep = 1;

    function goToStep(step) {
        document.querySelectorAll('.setup-step').forEach(el => el.classList.remove('active'));
        document.querySelector(`.setup-step[data-step="${step}"]`).classList.add('active');

        document.querySelectorAll('.setup-progress-dot').forEach(dot => {
            const dotStep = parseInt(dot.dataset.step);
            dot.classList.remove('active', 'completed');
            if (dotStep === step) dot.classList.add('active');
            else if (dotStep < step) dot.classList.add('completed');
        });

        currentStep = step;

        if (step === 5) buildReviewTable();
    }

    function validateStep2() {
        const steamKey = document.getElementById('setup-steamAPIKey').value.trim();
        const gameDNS = document.getElementById('setup-gameDNS').value.trim();
        const errorEl = document.getElementById('step2-error');

        if (!steamKey) {
            errorEl.textContent = 'Steam API Key is required.';
            errorEl.style.display = 'block';
            return;
        }
        if (!gameDNS) {
            errorEl.textContent = 'Game DNS is required.';
            errorEl.style.display = 'block';
            return;
        }
        errorEl.style.display = 'none';
        goToStep(3);
    }

    function getSettings() {
        return {
            steamAPIKey: document.getElementById('setup-steamAPIKey').value.trim(),
            gameDNS: document.getElementById('setup-gameDNS').value.trim(),
            basePort: parseInt(document.getElementById('setup-basePort').value) || 25000,
            defaultSeed: '',
            backupsToKeep: parseInt(document.getElementById('setup-backupsToKeep').value) || 24,
            phvalheimClientURL: document.getElementById('setup-phvalheimClientURL').value.trim(),
            timezone: document.getElementById('setup-timezone').value.trim() || 'Etc/UTC',
            openaiApiKey: document.getElementById('setup-openaiApiKey').value.trim(),
            claudeApiKey: document.getElementById('setup-claudeApiKey').value.trim(),
            geminiApiKey: document.getElementById('setup-geminiApiKey').value.trim(),
            ollamaUrl: document.getElementById('setup-ollamaUrl').value.trim(),
        };
    }

    function buildReviewTable() {
        const s = getSettings();
        const rows = [
            ['Steam API Key', s.steamAPIKey ? s.steamAPIKey.substring(0, 8) + '...' : '(not set)'],
            ['Game DNS', s.gameDNS || '(not set)'],
            ['Base Port', s.basePort],
            ['Backups to Keep', s.backupsToKeep],
            ['Client Download URL', s.phvalheimClientURL ? (s.phvalheimClientURL.length > 50 ? s.phvalheimClientURL.substring(0, 50) + '...' : s.phvalheimClientURL) : '(not set)'],
            ['Timezone', s.timezone || 'Etc/UTC'],
            ['OpenAI', s.openaiApiKey ? 'Configured' : 'Not set'],
            ['Claude', s.claudeApiKey ? 'Configured' : 'Not set'],
            ['Gemini', s.geminiApiKey ? 'Configured' : 'Not set'],
            ['Ollama', s.ollamaUrl || 'Not set'],
        ];

        const tbody = document.querySelector('#reviewTable tbody');
        tbody.innerHTML = rows.map(([k, v]) => `<tr><td>${k}</td><td>${v}</td></tr>`).join('');
    }

    async function saveSetup() {
        const btn = document.getElementById('saveBtn');
        const errorEl = document.getElementById('step5-error');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        errorEl.style.display = 'none';

        try {
            const response = await fetch('adminAPI.php?action=completeSetup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(getSettings())
            });

            const data = await response.json();
            if (data.success) {
                window.location.href = '/';
            } else {
                errorEl.textContent = data.error || 'Failed to save settings.';
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Save & Start';
            }
        } catch (e) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Save & Start';
        }
    }
    </script>
</body>
</html>
