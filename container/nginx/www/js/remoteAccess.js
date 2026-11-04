/* Remote Access: observed state only. No simulated checks or saved-secret echo. */
(() => {
    'use strict';
    let overlay, lastFocus, timer, inflight = false, busy = false, csrf = '', data = null;
    let initialized = false, lastReply = 0, dirty = false, epoch = 0, statusMessage = '', transportError = '';
    let tab = 'website', choice = '', step = 1, network = null, networkError = '', networkBusy = false, networkTime = 0, networkViewKey = '';
    const $ = id => document.getElementById(id);
    const escape = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const labels = {local:'Local player website',provider:'Provider connection',dns:'Website DNS',tls:'HTTPS certificate',route:'Correct website',transfer:'Small transfer'};
    const official = {
        cloudflare:'https://developers.cloudflare.com/tunnel/get-started/',
        tailscale:'https://tailscale.com/docs/features/tailscale-serve'
    };
    async function copy(value) {
        try { await navigator.clipboard.writeText(value); message('Copied.'); }
        catch (_) { window.prompt('Copy this value:', value); }
    }
    function message(value, error=false) {
        $('raMessage').textContent = value;
        $('raMessage').classList.toggle('ra-error', error);
    }
    function make() {
        overlay = document.createElement('div');
        overlay.id = 'remoteAccessOverlay';
        overlay.className = 'mods-modal-overlay';
        overlay.innerHTML = `<div class="mods-modal ra-modal" role="dialog" aria-modal="true" aria-labelledby="raTitle" tabindex="-1">
          <div class="mods-modal-header"><h2 id="raTitle" class="mods-modal-title">Remote Access</h2><button type="button" class="mods-modal-close" id="raClose" aria-label="Close Remote Access">&times;</button></div>
          <div class="ra-tabs" role="tablist" aria-label="Remote Access settings">
            <button id="raWebsiteTab" role="tab" aria-selected="true" aria-controls="raWebsite">Website</button>
            <button id="raGamesTab" role="tab" aria-selected="false" aria-controls="raGames" tabindex="-1">Game connections</button>
          </div>
          <div class="mods-modal-body">
            <div id="raMessage" role="status" aria-live="polite"></div>
            <section id="raWebsite" role="tabpanel" aria-labelledby="raWebsiteTab">
              <div id="raChoices"><h3>How should players reach your website?</h3><p class="ra-muted">Choose a method. Game connections are set up separately.</p>
                <div class="ra-choices">
                  <button data-choice="direct"><span class="ra-choice-icon" aria-hidden="true">↔</span><strong>Direct connection</strong><span>Your router &amp; firewall</span></button>
                  <button data-choice="cloudflare"><span class="ra-choice-icon" aria-hidden="true">☁</span><strong>Cloudflare</strong><span>Public website · your domain</span></button>
                  <button data-choice="tailscale"><span class="ra-choice-icon" aria-hidden="true">⌘</span><strong>Tailscale</strong><span>Private website · your devices</span></button>
                </div>
                <p class="ra-muted ra-built-in">Cloudflare and Tailscale are built in. No extra installation.</p>
              </div>
              <div id="raChosen" hidden><div class="ra-heading"><button id="raBack">← Methods</button><strong id="raMethodTitle"></strong><span id="raStepLabel" class="ra-muted"></span></div>
                <div id="raDirect" hidden>
                  <p>Use your existing public IP or DDNS hostname.</p>
                  <ol class="ra-steps"><li><strong>Publish the player website.</strong> In Docker, map a host TCP port to container port <code>8080</code>.</li>
                    <li><strong>Set up HTTPS.</strong> Route your domain through a reverse proxy to that host port. Forward TCP <code>443</code> on your router to the proxy and allow it through the host firewall.</li>
                    <li><strong>Try it from outside.</strong> Open the website on a phone with Wi-Fi off. Then test a client download.</li></ol>
                  <div class="ra-note">Keep admin port <strong>8081</strong> private. Website HTTPS does not carry game traffic.</div>
                  <div id="raLocalCheck" class="ra-status"></div><button id="raRefreshDirect">Check local website</button>
                  <details><summary>Port mapping, DNS &amp; troubleshooting</summary><p>The host port may differ from 8080; use the port shown in your Docker, Unraid, or TrueNAS configuration. Host networking uses the service port directly.</p><p>Point DNS/DDNS at your public IP. For plain HTTP, forward your chosen external TCP port to the website's host port; HTTP is unencrypted. HTTPS is recommended for player sign-in and downloads.</p><p>Double NAT may need forwarding on both routers. CGNAT usually prevents inbound access; ask your ISP for a public address or choose Cloudflare. PhValheim cannot inspect or change your router rules.</p><a href="https://brianmiller.github.io/phvalheim-server/reference/networking/" target="_blank" rel="noopener noreferrer">Reverse proxy examples</a></details>
                </div>
                <div id="raProviderFlow" hidden>
                  <input id="raProvider" type="hidden" value="cloudflare">
                  <div id="raPrepare"><div id="raInstructions"></div><button id="raNext" class="ra-primary">I have my token →</button></div>
                  <div id="raSetup" hidden>
                    <div id="raCloudFields"><label for="raUrlInput">Website URL</label><input id="raUrlInput" type="url" placeholder="https://play.example.net" autocomplete="off" maxlength="253">
                      <label for="raToken">Tunnel token <span id="raTokenSaved" class="ra-muted"></span></label><input id="raToken" type="password" autocomplete="new-password" maxlength="4096" placeholder="Paste the connector token"></div>
                    <div id="raTailFields" hidden><p>Sign in with your own Tailscale account to add this server.</p><div id="raSignInLink"></div><button id="raSignIn">Sign in to Tailscale</button>
                      <details><summary>Use an auth key instead</summary><p>Create a single-use key in <a href="https://login.tailscale.com/admin/settings/keys" target="_blank" rel="noopener noreferrer">Tailscale settings</a>. It is removed after enrollment.</p><label for="raAuthKey">Enrollment key</label><input id="raAuthKey" type="password" autocomplete="new-password" maxlength="400"></details>
                      <div id="raTailReady" hidden><p><strong>Device signed in.</strong> Enable MagicDNS and HTTPS in <a href="https://login.tailscale.com/admin/dns" target="_blank" rel="noopener noreferrer">Tailscale DNS settings</a>. Allow your players' devices to reach this device on HTTPS port 443.</p></div>
                    </div>
                    <p id="raAudience" class="ra-muted"></p>
                    <div class="ra-actions"><button id="raEditInstructions">← Setup instructions</button><button id="raConnect">Connect and test</button></div>
                    <details id="raSaveOptions"><summary>Save for later</summary><button id="raSave">Save without connecting</button></details>
                  </div>
                  <div id="raConnection" hidden><div class="ra-status" id="raSummary" aria-live="polite">Loading connection status…</div><div id="raUrl"></div>
                    <div class="ra-actions"><button id="raTest">Test connection</button><button id="raCancel">Cancel test</button><button id="raDisconnect">Disconnect</button><button id="raEdit">Edit settings</button></div>
                    <div id="raChecks"></div><div id="raDevice"></div>
                    <details><summary>Connection details &amp; activity</summary><p id="raLastChecked"></p><p id="raTestSource"></p><dl id="raDetails"></dl><ul id="raEvents"></ul></details>
                    <details><summary>Remove saved connection</summary><p>Forget removes local credentials. Remove unused tunnels, DNS records, or devices in your provider account separately.</p><button id="raForget">Disconnect and forget</button></details>
                  </div>
                </div>
              </div>
            </section>
            <section id="raGames" role="tabpanel" aria-labelledby="raGamesTab" hidden>
              <h3>Connect players to a world</h3><p class="ra-muted">Website tunnels do not forward Valheim's UDP traffic.</p>
              <div id="raNetworkMessage" role="status"></div>
              <label for="raWorld">World</label><select id="raWorld"></select>
              <div id="raWorldInfo"></div>
              <details><summary>Steam / UDP vs. crossplay</summary><p><strong>Steam / direct UDP:</strong> forward the world's assigned port pair. Players connect to your public game address and game port.</p><p><strong>Crossplay:</strong> Valheim uses the PlayFab relay, so inbound UDP forwarding is not required. Use the join code or server list. Crossplay cannot be joined using a local or loopback IP address. Outbound internet access is still required.</p><p>Changing crossplay takes effect when the world restarts. The mode above uses running-session evidence when available.</p><a href="https://www.valheimgame.com/support/a-guide-to-dedicated-servers/" target="_blank" rel="noopener noreferrer">Valheim's dedicated server guide</a></details>
              <div class="ra-actions"><button id="raRefreshGames">Refresh world settings</button></div>
              <p class="ra-muted" id="raNetworkTime"></p>
            </section>
          </div></div>`;
        document.body.appendChild(overlay);
        $('raClose').onclick = close;
        overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
        overlay.addEventListener('keydown', e => {
            if (e.key === 'Escape') { e.stopPropagation(); close(); }
            if (e.key === 'Tab') {
                const items = [...overlay.querySelectorAll('button,input,select,a[href],summary,[tabindex="0"]')].filter(x => !x.disabled && x.getClientRects().length);
                const first = items[0], last = items[items.length-1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
        ['raUrlInput','raToken','raAuthKey'].forEach(id => $(id).addEventListener('input', () => { dirty = true; }));
        overlay.querySelectorAll('[data-choice]').forEach(button => button.onclick = () => choose(button.dataset.choice));
        $('raWebsiteTab').onclick = () => selectTab('website');
        $('raGamesTab').onclick = () => selectTab('games');
        overlay.querySelector('.ra-tabs').onkeydown = e => {
            if (['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) {
                e.preventDefault(); selectTab(tab === 'website' ? 'games' : 'website');
                $(tab === 'website' ? 'raWebsiteTab' : 'raGamesTab').focus();
            }
        };
        $('raBack').onclick = () => { if (leaveForm()) { choice = ''; layout(); } };
        $('raNext').onclick = () => { step = 2; layout(); $('raUrlInput').focus(); };
        $('raEditInstructions').onclick = () => { step = 1; layout(); };
        $('raEdit').onclick = () => { step = 2; layout(); };
        $('raRefreshDirect').onclick = () => loadNetwork(true);
        $('raRefreshGames').onclick = () => loadNetwork(true);
        $('raWorld').onchange = renderNetwork;
        $('raSave').onclick = () => perform(async () => { await save(); step = 3; layout(); message('Settings saved. Access is off.'); });
        $('raConnect').onclick = () => perform(async () => {
            if (!data?.config?.enabled && ($('raProvider').value === 'cloudflare' || dirty || data?.config?.provider !== $('raProvider').value)) await save();
            await request('connect'); dirty = false; step = 3; layout(); message('Connection requested. Live checks are running.');
        });
        $('raSignIn').onclick = () => perform(async () => {
            if (data?.config?.provider !== 'tailscale' || dirty) await save();
            await request('enroll', {authKey:$('raAuthKey').value.trim()}); $('raAuthKey').value = '';
            message('Waiting for the Tailscale sign-in link. Website access is still off.');
        });
        $('raTest').onclick = () => perform(() => request('test'));
        $('raCancel').onclick = () => perform(() => request('cancelTest'));
        $('raDisconnect').onclick = () => perform(() => request('disconnect'));
        $('raForget').onclick = () => {
            if (confirm('Disconnect and delete local credentials? You may also need to remove the tunnel or device in your provider account.')) {
                perform(async () => { await request('forget'); dirty = false; choice = ''; step = 1; initialized = true; $('raToken').value = ''; $('raAuthKey').value = ''; $('raUrlInput').value = ''; render(); });
            }
        };
        document.addEventListener('visibilitychange', () => { if (!document.hidden && overlay.classList.contains('show')) poll(); });
        instructions();
        buttons();
    }
    function leaveForm() {
        if (dirty && !confirm('Discard unsaved connection changes?')) return false;
        dirty = false; $('raToken').value = ''; $('raAuthKey').value = '';
        $('raUrlInput').value = data?.config?.url || ''; message(''); return true;
    }
    function selectTab(value) {
        tab = value; layout();
        if (tab === 'games') loadNetwork();
    }
    function choose(value) {
        if (!leaveForm()) return;
        choice = value;
        if (value !== 'direct') {
            $('raProvider').value = value;
            step = data?.config?.provider === value ? 3 : 1;
            if (value === 'tailscale' && step === 1) step = 2;
            instructions();
        } else loadNetwork();
        layout();
    }
    function layout() {
        $('raWebsite').hidden = tab !== 'website'; $('raGames').hidden = tab !== 'games';
        ['website','games'].forEach(t => { const el = $(t === 'website' ? 'raWebsiteTab' : 'raGamesTab'); el.setAttribute('aria-selected', String(tab === t)); el.tabIndex = tab === t ? 0 : -1; });
        $('raChoices').hidden = !!choice; $('raChosen').hidden = !choice;
        $('raDirect').hidden = choice !== 'direct'; $('raProviderFlow').hidden = !choice || choice === 'direct';
        $('raMethodTitle').textContent = {direct:'Direct connection',cloudflare:'Cloudflare · Public website',tailscale:'Tailscale · Private website'}[choice] || '';
        $('raStepLabel').textContent = choice === 'cloudflare' && step < 3 ? `Step ${step} of 2` : '';
        $('raPrepare').hidden = choice !== 'cloudflare' || step !== 1;
        $('raSetup').hidden = step !== 2;
        $('raConnection').hidden = step !== 3;
        $('raEditInstructions').hidden = choice !== 'cloudflare';
        buttons();
    }
    function instructions() {
        const cf = $('raProvider').value === 'cloudflare';
        $('raCloudFields').hidden = !cf; $('raTailFields').hidden = cf;
        $('raInstructions').innerHTML = `<ol class="ra-steps"><li><strong>Create a tunnel.</strong> Open <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">Cloudflare</a> → Networking → Tunnels. Copy the connector token. PhValheim runs the connector for you.</li><li><strong>Add a website route.</strong> Choose your domain and subdomain. Set service type to HTTP and paste this origin:</li></ol><div class="ra-copy-row"><code>${escape(data?.origin || 'http://127.0.0.1:18080')}</code><button id="raCopyOrigin">Copy origin</button></div><p class="ra-muted">No installation command is needed. <a href="${official.cloudflare}" target="_blank" rel="noopener noreferrer">Cloudflare setup guide</a></p>`;
        $('raCopyOrigin').onclick = () => copy(data?.origin || 'http://127.0.0.1:18080');
        $('raAudience').textContent = cf ? 'Connect makes the player website public immediately. Existing player permissions still apply.' : 'Private website only. Players need Tailscale and permission in your tailnet.';
    }
    async function loadNetwork(force=false) {
        if (networkBusy || (!force && Date.now()-networkTime < 10000)) return;
        networkBusy = true; renderNetwork();
        try {
            const response = await fetch('adminAPI.php?action=getRemoteNetwork', {credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error('World settings unavailable. Retry in a moment.');
            network = result; networkError = ''; networkTime = Date.now();
        } catch (_) { networkError = 'Could not refresh network settings. Retry; any settings below are from the last successful check.'; }
        finally { networkBusy = false; renderNetwork(); }
    }
    function renderNetwork() {
        const stale = !!networkError || (network && Date.now()-networkTime > 30000);
        $('raNetworkMessage').textContent = networkError || (stale ? 'Displayed settings are stale. Refresh to check again.' : '');
        $('raRefreshGames').disabled = networkBusy; $('raRefreshDirect').disabled = networkBusy;
        $('raLocalCheck').textContent = !network ? (networkError || 'Checking local website…') : stale ? 'Local website check is stale. Check again.' : network.website.responding ? `Local website responding (HTTP ${network.website.httpStatus}). Internet access is not verified.` : `Local website did not respond successfully (HTTP ${network.website.httpStatus || 'unavailable'}). Check Server Health.`;
        if (!network) { $('raWorldInfo').textContent = networkBusy ? 'Loading world settings…' : ''; return; }
        const selected = $('raWorld').value;
        const options = network.worlds.map(w => `<option value="${escape(w.name)}">${escape(w.name)}</option>`).join('');
        if ($('raWorld').innerHTML !== options) { $('raWorld').innerHTML = options; if (network.worlds.some(w => w.name === selected)) $('raWorld').value = selected; }
        $('raWorld').disabled = !network.worlds.length;
        const w = network.worlds.find(w => w.name === $('raWorld').value);
        $('raNetworkTime').textContent = `Settings checked ${new Date(network.checkedAt*1000).toLocaleTimeString()}. Router and internet reachability are not tested.`;
        if (!w) { networkViewKey = ''; $('raWorldInfo').innerHTML = '<p>Create a world to see its assigned game ports here.</p>'; return; }
        const viewKey = JSON.stringify([w, network.gameDNS, network.range]);
        if (viewKey === networkViewKey) return;
        networkViewKey = viewKey;
        const pair = w.port ? `${w.port}–${w.queryPort}` : null;
        const conflicts = network.range?.conflicts || [];
        const warning = network.range?.error || (conflicts.some(c => c.name === w.name) ? 'This world’s port pair is outside the configured container range. Review Server Settings before forwarding.' : '');
        $('raWorldInfo').innerHTML = `<div class="ra-world-heading"><strong>${w.crossplay ? 'Crossplay relay' : 'Direct UDP'}${pair && !w.crossplay ? ' · '+escape(pair) : ''}</strong><span class="ra-muted">${escape(w.mode)} · ${escape(w.basis)}</span></div>
          ${w.mode === 'running' && w.basis === 'Saved settings' ? '<p class="ra-muted">Running network mode is not confirmed yet; showing saved settings.</p>' : ''}
          ${w.restartPending ? '<div class="ra-note">Saved crossplay settings differ. Restart the world to apply them.</div>' : ''}
          ${warning ? `<p class="ra-error">${escape(warning)}</p>` : ''}
          ${w.crossplay ? '<p>No inbound game port forwarding is needed. Start the world, then share its join code or use the server list.</p><p class="ra-muted">The relay still needs outbound internet access. Local/loopback IP joins are not supported.</p>' : pair ? `<p>Forward this UDP pair to your server’s LAN IP, then test a game join from another network.</p><details><summary>Set up UDP forwarding</summary><ol class="ra-steps"><li><strong>Publish UDP ${escape(pair)}.</strong> Map both ports in Docker / Unraid / TrueNAS to the same container ports. With host networking, skip this mapping.</li><li><strong>Forward both UDP ports.</strong> In your router, forward external ${escape(pair)} to ${escape(pair)} on the server’s LAN IP. Reserve that IP and allow the pair through its host firewall.</li><li><strong>Join from outside your network.</strong> Start the world and use your public game address with port <strong>${w.port}</strong>. A successful website test does not verify UDP.</li></ol><button id="raCopyPorts">Copy forwarding rule</button></details>` : '<p>This world has no valid port pair assigned yet. Wait for the engine to assign ports; check Server Health if it persists.</p>'}
          <div class="ra-note">Game address: <strong>${escape(network.gameDNS || 'Not configured — set Game DNS in Server Settings')}</strong>${network.gameDNS && !w.crossplay && w.port ? ':'+w.port : ''}</div>
          <details><summary>Firewall &amp; game address help</summary><p>Use a public IP or DNS/DDNS name for the game address. With Cloudflare DNS, game records must be DNS-only; the website proxy does not carry game UDP.</p><p>Keep game ports the same at the router, host, and container. Forward each direct world's own pair, not just the base port. Host/container mappings cannot be detected here.</p><p>Double NAT requires rules on each router. CGNAT usually blocks direct inbound connections: use crossplay or obtain a public IP from your ISP. A local UDP check cannot prove internet access; test an actual join from another network.</p>${network.range?.enabled ? `<p>Container game range: ${escape(network.range.start)}–${escape(network.range.end)}. This is configured allocation, not proof of published ports.</p>` : ''}</details>`;
        if ($('raCopyPorts')) $('raCopyPorts').onclick = () => copy(`UDP ${w.port}-${w.queryPort}: router external ports → server LAN IP, same host ports → container ports ${w.port}-${w.queryPort}. Allow both through the host firewall.`);
    }
    async function save() {
        await request('save', {provider:$('raProvider').value, url:$('raUrlInput').value.trim(), token:$('raToken').value.trim()});
        $('raToken').value = ''; dirty = false;
    }
    async function request(action, fields={}) {
        epoch++;
        const response = await fetch('remoteAccessAPI.php', {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-PHV-CSRF':csrf}, body:JSON.stringify({action,...fields}), signal:AbortSignal.timeout(15000)});
        const next = await response.json();
        if (!response.ok || !next.success) throw new Error(next.error || 'Connection operation failed.');
        transportError = ''; data = next; lastReply = Date.now(); render(); return next;
    }
    async function perform(fn) {
        if (busy) return;
        if (!data?.available || !csrf) { message('Waiting for the built-in Remote Access service. Retry when live status returns.', true); return; }
        busy = true; buttons();
        try { message('Working…'); await fn(); if ($('raMessage').textContent === 'Working…') message('Action completed. Current connection status is shown below.'); }
        catch (e) { message(e.message || 'Connection operation failed. Retry.', true); }
        finally { busy = false; buttons(); }
    }
    function buttons() {
        const available = data?.available && !!csrf && !transportError, enabled = data?.config?.enabled, enrolling = data?.enrolling;
        ['raSave','raConnect','raSignIn','raForget'].forEach(id => $(id).disabled = busy || !available);
        $('raSave').disabled ||= enabled || enrolling;
        $('raSignIn').disabled ||= enabled;
        $('raConnect').disabled ||= enabled || ($('raProvider').value === 'tailscale' && data?.observed?.details?.backend !== 'Running');
        $('raTailReady').hidden = data?.observed?.details?.backend !== 'Running';
        $('raSignIn').hidden = !!data?.authUrl || data?.observed?.details?.backend === 'Running';
        $('raConnect').hidden = choice === 'tailscale' && data?.observed?.details?.backend !== 'Running';
        $('raTest').hidden = !enabled || data?.test?.state === 'Running';
        $('raCancel').hidden = data?.test?.state !== 'Running';
        $('raDisconnect').hidden = !enabled && !enrolling;
        $('raEdit').hidden = enabled || enrolling;
        const mismatch = (enabled || enrolling) && data?.config?.provider !== choice;
        $('raSetup').hidden ||= mismatch;
        $('raPrepare').hidden ||= mismatch;
        if (mismatch && choice && choice !== 'direct') {
            $('raConnection').hidden = false;
            $('raStepLabel').textContent = 'Disconnect the active provider to switch';
        }
        // Enrollment does not publish the website. Keep a cancel control in its setup step.
        if (enrolling && step === 2 && choice === 'tailscale') {
            $('raConnection').hidden = false; $('raEdit').hidden = true;
        }
        ['raUrlInput','raToken','raAuthKey'].forEach(id => $(id).disabled = busy || !available || enabled || enrolling);
        $('raTest').disabled = busy || !available || !enabled || data?.test?.state === 'Running';
        $('raCancel').disabled = busy || !available || data?.test?.state !== 'Running';
        $('raDisconnect').disabled = busy || !available || (!enabled && !enrolling);
    }
    function render() {
        if (!data) return;
        const focused = overlay.contains(document.activeElement) ? document.activeElement.id : '';
        if (!initialized && data.available) {
            $('raProvider').value = data.config.provider || 'cloudflare';
            if (!choice && data.config.provider) { choice = data.config.provider; step = 3; }
            $('raUrlInput').value = data.config.url || ''; initialized = true; instructions();
        }
        $('raTokenSaved').textContent = data.config?.tokenSaved ? '(saved)' : '';
        const observed = data.observed || {}, age = Math.max(0, Math.round((data.now || Date.now()/1000) - (observed.time || 0)));
        const stale = !!transportError || Date.now() - lastReply > 15000 || age > 15;
        $('raSummary').textContent = !data.available ? 'Remote Access service unavailable' : stale ? 'Live status unavailable — last observation is stale' : `${observed.state || 'Unknown'} · ${data.config?.provider === 'cloudflare' ? 'Public website' : data.config?.provider === 'tailscale' ? 'Private website' : 'No provider configured'}`;
        $('raLastChecked').textContent = data.available ? `Last observation: ${new Date((observed.time || 0)*1000).toLocaleTimeString()}${stale ? ' — stale' : ''}` : '';
        if (!data.available) {
            statusMessage = 'Cloudflare/Tailscale service is unavailable. Direct and game setup are still available.'; message(statusMessage, true);
        } else {
            if ((transportError || data.error) && !busy) { statusMessage = transportError || data.error; message(statusMessage, true); }
            else if (!data.error && statusMessage) {
                if ($('raMessage').textContent === statusMessage) message('');
                statusMessage = '';
            }
        }
        const url = data.config?.url;
        $('raUrl').innerHTML = url ? `<p><a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(url)}</a> <button type="button" id="raCopyUrl">Copy URL</button>${data.config.enabled ? '' : ' — website is off'}</p>` : '';
        if ($('raCopyUrl')) $('raCopyUrl').onclick = () => copy(url);
        $('raSignInLink').innerHTML = data.authUrl ? `<p><a class="ra-primary" href="${escape(data.authUrl)}" target="_blank" rel="noopener noreferrer">Continue in Tailscale</a> <button type="button" id="raCopyLogin">Copy sign-in link</button></p>` : '';
        if ($('raCopyLogin')) $('raCopyLogin').onclick = () => copy(data.authUrl);
        const test = data.test, expired = test && (Date.now()/1000 - test.started > 300 || test.revision !== data.config?.revision);
        $('raTestSource').textContent = test ? `${expired ? 'Stale test — run it again' : test.state}. Test source: ${test.source}. ${new Date(test.started*1000).toLocaleString()}. Outside-network access is not independently verified.` : 'No test has been run. Connector health alone does not verify your website.';
        $('raChecks').hidden = !test;
        $('raChecks').innerHTML = Object.entries(labels).map(([key,label]) => {
            const step = test?.steps?.[key] || {state:'Not tested',message:''};
            return `<div class="ra-check"><strong>${label}</strong><span>${escape(expired ? 'Stale' : step.state)}</span>${step.message && step.state !== 'Passed' ? `<p>${escape(step.message)}</p>` : ''}</div>`;
        }).join('');
        $('raDevice').innerHTML = test?.deviceUrl && !expired ? `<p><a href="${escape(test.deviceUrl)}" target="_blank" rel="noopener noreferrer">Test from another device</a> <button type="button" id="raCopyTest">Copy test link</button></p><p>${escape(test.device || 'Not tested')}. ${data.config?.provider === 'tailscale' ? 'Use another authorized Tailscale device.' : 'For an outside-network check, use a phone on mobile data.'} This link expires five minutes after the test started.</p>` : '';
        if ($('raCopyTest')) $('raCopyTest').onclick = () => copy(test.deviceUrl);
        $('raDetails').innerHTML = Object.entries(observed.details || {}).map(([key,value]) => `<dt>${escape(key)}</dt><dd>${escape(Array.isArray(value) ? value.join(', ') : value ?? 'Unavailable')}</dd>`).join('');
        $('raEvents').innerHTML = (data.events || []).slice().reverse().map(e => `<li>${escape(new Date(e.time*1000).toLocaleTimeString())}: ${escape(e.message)}</li>`).join('');
        layout();
        if (focused && $(focused) && document.activeElement === document.body) $(focused).focus({preventScroll:true});
    }
    async function poll() {
        clearTimeout(timer);
        if (inflight || document.hidden || !overlay?.classList.contains('show')) return;
        inflight = true;
        const startedEpoch = epoch;
        try {
            const response = await fetch('remoteAccessAPI.php', {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(12000)});
            const next = await response.json();
            if (!response.ok || !next.success) throw new Error(next.error || 'Live status unavailable.');
            if (!busy && epoch === startedEpoch) { transportError = ''; data = next; csrf = next.csrf || csrf; lastReply = Date.now(); render(); }
        } catch (e) { transportError = e.message || 'Live status unavailable.'; statusMessage = transportError; message(statusMessage, true); if (data) render(); else { $('raSummary').textContent = 'Live status unavailable'; buttons(); } }
        finally {
            inflight = false;
            if (tab === 'games' || choice === 'direct') loadNetwork();
            if (overlay?.classList.contains('show')) timer = setTimeout(poll, data?.test?.state === 'Running' || data?.enrolling ? 2000 : 5000);
        }
    }
    function close() {
        if (dirty && !confirm('Discard unsaved connection changes? Active connections and accepted tests will continue.')) return;
        clearTimeout(timer); overlay.classList.remove('show'); dirty = false; initialized = false; choice = ''; step = 1; tab = 'website';
        $('raToken').value = ''; $('raAuthKey').value = ''; lastFocus?.focus();
    }
    window.showRemoteAccessModal = () => {
        if (!overlay) make(); lastFocus = document.activeElement;
        overlay.classList.add('show'); layout(); $('raClose').focus(); poll();
    };
})();
