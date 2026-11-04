/* Remote Access: observed state only. No simulated checks or saved-secret echo. */
(() => {
    'use strict';
    let overlay, lastFocus, timer, feedbackTimer, inflight = false, busy = false, csrf = '', data = null;
    let initialized = false, lastReply = 0, dirty = false, epoch = 0, statusMessage = '', transportError = '';
    let tab = 'website', choice = '', step = 1, network = null, networkError = '', networkBusy = false, networkTime = 0, networkViewKey = '';
    const $ = id => document.getElementById(id);
    const escape = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const labels = {local:'Local player website',provider:'Provider connection',dns:'Website DNS',tls:'HTTPS certificate',route:'Correct website',transfer:'Small transfer'};
    const official = {
        cloudflare:'https://developers.cloudflare.com/tunnel/get-started/',
        tailscale:'https://tailscale.com/docs/features/tailscale-funnel'
    };
    async function copy(value) {
        try { await navigator.clipboard.writeText(value); message('Copied.'); }
        catch (_) { window.prompt('Copy this value:', value); }
    }
    function message(value, error=false) {
        if (!$('raMessage')) return;
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
                  <button data-choice="tailscale"><span class="ra-choice-icon" aria-hidden="true">⌘</span><strong>Tailscale</strong><span>Public website · free hostname</span></button>
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
                  <div id="raSwitchNotice" class="ra-note" hidden><p id="raSwitchText"></p><button id="raViewActive"></button></div>
                  <input id="raProvider" type="hidden" value="cloudflare">
                  <div id="raHostnameFields" hidden><label for="raHostname">Website name</label><input id="raHostname" type="text" maxlength="63" autocomplete="off" placeholder="my-valheim"><p class="ra-muted" id="raHostnameHelp">Letters, numbers and hyphens. Tailscale supplies the rest of the address.</p><button id="raSaveHostname">Save name</button></div>
                  <div id="raPrepare"><div id="raInstructions"></div><button id="raNext" class="ra-primary">I have my token →</button></div>
                  <div id="raSetup" hidden>
                    <div id="raCloudFields"><label for="raUrlInput">Website URL</label><input id="raUrlInput" type="url" placeholder="https://play.example.net" autocomplete="off" maxlength="253">
                      <label for="raToken">Tunnel token <span id="raTokenSaved" class="ra-muted"></span></label><input id="raToken" type="password" autocomplete="new-password" maxlength="4096" placeholder="Paste the connector token"></div>
                    <div id="raTailFields" hidden><p>Sign in once to create a public website link. Players do not need Tailscale.</p><div id="raSignInLink"></div><button id="raSignIn">Sign in to Tailscale</button>
                      <details><summary>Use an auth key instead</summary><p>Create a single-use key in <a href="https://login.tailscale.com/admin/settings/keys" target="_blank" rel="noopener noreferrer">Tailscale settings</a>. It is removed after enrollment.</p><label for="raAuthKey">Enrollment key</label><input id="raAuthKey" type="password" autocomplete="new-password" maxlength="400"></details>
                      <div id="raTailReady" hidden><p><strong>Device signed in.</strong> Publish your website, then complete any Tailscale approval shown here.</p></div>
                    </div>
                    <p id="raAudience" class="ra-muted"></p>
                    <div class="ra-actions"><button id="raEditInstructions">← Setup instructions</button><button id="raConnect">Connect and test</button></div>
                    <details id="raSaveOptions"><summary>Save for later</summary><button id="raSave">Save without connecting</button></details>
                  </div>
                  <div id="raConnection" hidden><div class="ra-status" id="raSummary" aria-live="polite">Loading connection status…</div><p id="raProgress" role="status" aria-live="polite"></p><div id="raApproval"></div><div id="raUrl"></div>
                    <div class="ra-actions"><button id="raReconnect" class="ra-primary">Reconnect</button><button id="raTest">Test connection</button><button id="raCancel">Cancel test</button><button id="raDisconnect">Disconnect</button><button id="raEdit">Edit settings</button></div>
                    <details id="raTestDetails"><summary id="raTestSummary">Website checks</summary><div id="raChecks"></div><div id="raDevice"></div></details>
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
        ['raUrlInput','raToken','raAuthKey','raHostname'].forEach(id => $(id).addEventListener('input', () => { dirty = true; buttons(); }));
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
        $('raViewActive').onclick = () => choose(data?.config?.provider);
        $('raNext').onclick = () => { step = 2; layout(); $('raUrlInput').focus(); };
        $('raEditInstructions').onclick = () => { step = 1; layout(); };
        $('raEdit').onclick = () => { step = 2; layout(); };
        $('raRefreshDirect').onclick = () => loadNetwork(true);
        $('raRefreshGames').onclick = () => loadNetwork(true);
        $('raWorld').onchange = renderNetwork;
        $('raSave').onclick = () => perform(async () => { await save(); step = 3; layout(); message('Settings saved. Access is off.'); });
        const publish = () => perform(async () => {
            if (choice === 'tailscale' && data?.config?.provider === 'tailscale') {
                if (dirty) { await request('hostname', {hostname:$('raHostname').value.trim()}); dirty = false; }
            } else if (!data?.config?.enabled) await save();
            await request('connect', {publicAccess:true}); dirty = false; step = 3; layout(); message('');
        }, 'Connecting and publishing your website…');
        $('raConnect').onclick = publish;
        $('raReconnect').onclick = publish;
        $('raSaveHostname').onclick = () => perform(async () => {
            await request('hostname', {hostname:$('raHostname').value.trim()}); dirty = false; render();
            message('Name saved. Reconnect to apply it and publish the new address.');
        }, 'Saving website name…');
        $('raSignIn').onclick = () => perform(async () => {
            if (data?.config?.provider !== 'tailscale' || dirty) await save();
            await request('enroll', {authKey:$('raAuthKey').value.trim()}); $('raAuthKey').value = '';
            message('Waiting for the Tailscale sign-in link. Website access is still off.');
        });
        $('raTest').onclick = () => perform(async () => { await request('test'); $('raTestDetails').open = true; message(''); }, 'Starting website checks…');
        $('raCancel').onclick = () => perform(() => request('cancelTest'), 'Cancelling website checks…');
        $('raDisconnect').onclick = () => perform(async () => { await request('disconnect'); step = 3; dirty = false; render(); message('Website disconnected. Your sign-in and settings are saved.'); }, 'Disconnecting the public website…');
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
        $('raUrlInput').value = data?.config?.url || ''; $('raHostname').value = data?.config?.hostname || ''; message(''); return true;
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
            $('raUrlInput').value = value === 'cloudflare' && data?.config?.provider === value ? data.config.url || '' : '';
            step = providerStep(value);
            if (value === 'tailscale' && step === 1) step = 2;
            instructions();
        } else loadNetwork();
        render(); layout();
    }
    function providerStep(provider) {
        if (provider === 'tailscale' && data?.config?.provider === provider && !data?.config?.enabled && (data?.enrolling || data?.authUrl)) return 2;
        return data?.config?.provider === provider ? 3 : 1;
    }
    function layout() {
        $('raWebsite').hidden = tab !== 'website'; $('raGames').hidden = tab !== 'games';
        ['website','games'].forEach(t => { const el = $(t === 'website' ? 'raWebsiteTab' : 'raGamesTab'); el.setAttribute('aria-selected', String(tab === t)); el.tabIndex = tab === t ? 0 : -1; });
        $('raChoices').hidden = !!choice; $('raChosen').hidden = !choice;
        $('raDirect').hidden = choice !== 'direct'; $('raProviderFlow').hidden = !choice || choice === 'direct';
        $('raMethodTitle').textContent = {direct:'Direct connection',cloudflare:'Cloudflare · Public website',tailscale:'Tailscale · Public website'}[choice] || '';
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
        $('raAudience').textContent = cf ? 'Connect makes the player website public immediately. Existing player permissions still apply.' : 'Publish makes the player website public through Tailscale Funnel. Players need no Tailscale app. Existing player permissions still apply.';
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
        await request('save', {provider:$('raProvider').value, url:$('raUrlInput').value.trim(), token:$('raToken').value.trim(), hostname:$('raHostname').value.trim() || data?.config?.hostname});
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
    async function perform(fn, progress='Saving connection settings…') {
        if (busy) return;
        if (!data?.available || !csrf) { message('Waiting for the built-in Remote Access service. Retry when live status returns.', true); return; }
        busy = true; buttons();
        try { message(progress); await fn(); if ($('raMessage').textContent === progress) message(''); }
        catch (e) { message(e.message || 'Connection operation failed. Retry.', true); }
        finally { busy = false; buttons(); }
    }
    function serverNow() {
        return (data?.now || Date.now()/1000) + Math.max(0, Date.now() - lastReply)/1000;
    }
    function testWait() {
        return Math.max(0, Math.ceil((data?.testAvailableAt || (data?.test?.started || 0) + 10) - serverNow()));
    }
    function testFeedback() {
        if (choice !== data?.config?.provider || !data?.config?.enabled || transportError || Date.now() - lastReply > 15000 || serverNow() - (data.observed?.time || 0) > 15) return;
        const test = data?.test;
        if (test?.state === 'Running') {
            const current = Object.entries(test.steps || {}).find(([,v]) => v.state === 'Checking');
            const passed = Object.values(test.steps || {}).filter(v => v.state === 'Passed').length;
            $('raProgress').textContent = current ? `${passed} of 6 checks passed · ${labels[current[0]]}: ${current[1].message || 'Checking…'}` : `Starting website checks… ${passed} of 6 passed.`;
        } else if (test?.state === 'Retrying public website') {
            const current = Object.entries(test.steps || {}).find(([,v]) => v.state === 'Retrying');
            const wait = testWait();
            const remaining = Math.max(0, Math.ceil((data.testRetryUntil || 0) - serverNow()));
            $('raProgress').textContent = `${current ? labels[current[0]] : 'Public website'} is not ready. ${wait ? `Next attempt in ${wait}s.` : 'Retrying now…'}${remaining ? ` Will keep trying for up to ${remaining}s.` : ''}`;
        }
    }
    function buttons() {
        ['raBack','raNext','raEdit','raEditInstructions'].forEach(id => $(id).disabled = busy);
        overlay.querySelectorAll('[data-choice]').forEach(button => button.disabled = busy);
        const available = data?.available && !!csrf && !transportError, enabled = data?.config?.enabled, enrolling = data?.enrolling;
        ['raSave','raConnect','raSignIn','raForget','raReconnect','raSaveHostname'].forEach(id => $(id).disabled = busy || !available);
        $('raSave').disabled ||= enabled || enrolling;
        $('raSignIn').disabled ||= enabled;
        $('raConnect').textContent = choice === 'tailscale' ? 'Publish website' : 'Connect and test';
        $('raConnect').disabled ||= enabled || ($('raProvider').value === 'tailscale' && data?.observed?.details?.backend !== 'Running');
        $('raTailReady').hidden = data?.observed?.details?.backend !== 'Running';
        $('raSignIn').hidden = !!data?.authUrl || data?.observed?.details?.backend === 'Running';
        $('raConnect').hidden = choice === 'tailscale' && data?.observed?.details?.backend !== 'Running';
        const testing = ['Running','Retrying public website'].includes(data?.test?.state);
        const wait = testWait();
        $('raTest').hidden = !enabled;
        $('raTest').textContent = testing ? 'Checking website…' : wait ? `Test again in ${wait}s` : 'Test connection';
        $('raCancel').hidden = !['Running','Retrying public website'].includes(data?.test?.state);
        $('raDisconnect').hidden = !enabled && !enrolling;
        $('raEdit').hidden = enabled || enrolling || choice === 'tailscale';
        $('raReconnect').hidden = enabled || enrolling || choice !== 'tailscale' || data?.config?.provider !== 'tailscale';
        $('raReconnect').textContent = data?.config?.enrolled ? 'Reconnect' : 'Publish website';
        $('raHostnameFields').hidden = choice !== 'tailscale' || enabled || enrolling;
        $('raSaveHostname').hidden = data?.config?.provider !== 'tailscale' || !dirty;
        $('raSaveHostname').disabled ||= !dirty;
        $('raHostname').disabled = busy || !available || enabled || enrolling;
        const mismatch = (enabled || enrolling) && data?.config?.provider !== choice;
        $('raSwitchNotice').hidden = !mismatch || !choice || choice === 'direct';
        $('raViewActive').disabled = busy;
        $('raSetup').hidden ||= mismatch;
        $('raPrepare').hidden ||= mismatch;
        if (mismatch && choice && choice !== 'direct') {
            $('raConnection').hidden = true;
            $('raHostnameFields').hidden = true;
            $('raStepLabel').textContent = '';
            const active = data.config.provider === 'tailscale' ? 'Tailscale' : 'Cloudflare';
            const selected = choice === 'tailscale' ? 'Tailscale' : 'Cloudflare';
            $('raSwitchText').textContent = `${active} is active. Disconnect it before setting up ${selected}.`;
            $('raViewActive').textContent = `View ${active} connection`;
        }
        // Enrollment does not publish the website. Keep a cancel control in its setup step.
        if (enrolling && step === 2 && choice === 'tailscale') {
            $('raConnection').hidden = false; $('raEdit').hidden = true;
        }
        ['raUrlInput','raToken','raAuthKey'].forEach(id => $(id).disabled = busy || !available || enabled || enrolling);
        $('raTest').disabled = busy || !available || !enabled || testing || wait > 0;
        $('raCancel').disabled = busy || !available || !['Running','Retrying public website'].includes(data?.test?.state);
        $('raDisconnect').disabled = busy || !available || (!enabled && !enrolling);
        testFeedback();
    }
    function renderHeader() {
        const group = $('remoteConnectionPills');
        if (!group) return;
        if (!group.children.length) {
            for (const provider of ['tailscale', 'cloudflare']) {
                const button = document.createElement('button');
                button.type = 'button'; button.id = 'raPill-' + provider; button.className = 'ra-connection-pill';
                const icon = provider === 'tailscale'
                    ? '<svg viewBox="0 0 18 18" aria-hidden="true"><g fill="currentColor"><circle cx="4" cy="4" r="2"/><circle cx="9" cy="4" r="2"/><circle cx="14" cy="4" r="2" opacity=".35"/><circle cx="4" cy="9" r="2"/><circle cx="9" cy="9" r="2"/><circle cx="14" cy="9" r="2"/><circle cx="4" cy="14" r="2" opacity=".35"/><circle cx="9" cy="14" r="2"/><circle cx="14" cy="14" r="2" opacity=".35"/></g></svg>'
                    : '<svg viewBox="0 0 20 18" aria-hidden="true"><path d="M5 14h11a3 3 0 0 0 0-6 5 5 0 0 0-9.7-1.6A4 4 0 0 0 5 14Z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
                button.innerHTML = icon + '<span class="ra-pill-name">' + (provider === 'tailscale' ? 'Tailscale' : 'Cloudflare') + '</span><span class="ra-pill-dot"></span><span class="ra-pill-state"></span>';
                button.onclick = () => window.showRemoteAccessModal(provider);
                group.append(button);
            }
        }
        for (const provider of ['tailscale', 'cloudflare']) {
            const button = $('raPill-' + provider), name = provider === 'tailscale' ? 'Tailscale' : 'Cloudflare';
            const same = data?.config?.provider === provider;
            const stale = !!transportError || (data && (Date.now() - lastReply > 15000 || serverNow() - (data.observed?.time || 0) > 15));
            let state = 'off', label = 'Off', detail = 'Website access is off. Open Remote Access to configure ' + name + '.';
            if (!data || stale || !data.available) {
                state = 'unknown'; label = !data && !transportError ? 'Loading' : 'Unknown'; detail = 'Live connection status is unavailable. Open Remote Access for details.';
            } else if (same && (data.config.enabled || data.enrolling)) {
                detail = data.health?.detail || 'Waiting for live website verification.';
                if (data.authUrl) { state = 'warn'; label = 'Approval'; }
                else if (data.health?.state === 'warn' || data.health?.state === 'fail') { state = 'warn'; label = 'Attention'; }
                else if (data.health?.state === 'ok') { state = 'live'; label = 'Live'; }
                else { state = 'pending'; label = data.observed?.state === 'Connected' ? 'Checking' : 'Connecting'; }
            }
            button.dataset.state = state;
            button.querySelector('.ra-pill-state').textContent = label;
            button.title = name + ': ' + detail;
            button.setAttribute('aria-label', name + ': ' + label + '. ' + detail);
        }
    }
    function render() {
        renderHeader();
        if (!data || !overlay?.classList.contains('show')) return;
        const focused = overlay.contains(document.activeElement) ? document.activeElement.id : '';
        if (!initialized && data.available) {
            if (!choice && data.config.provider) choice = data.config.provider;
            if (choice === data.config.provider && !dirty) step = providerStep(choice);
            $('raProvider').value = choice && choice !== 'direct' ? choice : data.config.provider || 'cloudflare';
            if (!dirty) $('raUrlInput').value = choice === 'cloudflare' && data.config.provider === choice ? data.config.url || '' : '';
            initialized = true; instructions();
        }
        $('raTokenSaved').textContent = data.config?.tokenSaved ? '(saved)' : '';
        const observed = data.observed || {}, age = Math.max(0, Math.round((data.now || Date.now()/1000) - (observed.time || 0)));
        const stale = !!transportError || Date.now() - lastReply > 15000 || age > 15;
        $('raSummary').textContent = !data.available ? 'Remote Access service unavailable' : stale ? 'Live status unavailable — last observation is stale' : `${observed.state || 'Unknown'} · ${data.config?.provider === 'cloudflare' ? 'Public website' : data.config?.provider === 'tailscale' ? 'Public website' : 'No provider configured'}`;
        $('raLastChecked').textContent = data.available ? `Last observation: ${new Date((observed.time || 0)*1000).toLocaleTimeString()}${stale ? ' — stale' : ''}` : '';
        const providerError = choice === data.config?.provider ? data.error : '';
        if (!data.available) {
            statusMessage = 'Cloudflare/Tailscale service is unavailable. Direct and game setup are still available.'; message(statusMessage, true);
        } else {
            if ((transportError || providerError) && !busy) { statusMessage = transportError || providerError; message(statusMessage, true); }
            else if (!providerError && statusMessage) {
                if ($('raMessage').textContent === statusMessage) message('');
                statusMessage = '';
            }
        }
        if (!dirty) $('raHostname').value = data.config?.hostname || '';
        const sameProvider = choice === data.config?.provider;
        const active = sameProvider && data.config?.enabled;
        const state = observed.state || '';
        const verified = data.test?.state === 'URL verified from this server' && data.test.revision === data.config?.revision && Date.now()/1000 - data.test.started <= 300;
        $('raProgress').textContent = !sameProvider ? '' : stale ? 'Waiting for live status. Displayed connection information may be out of date.' : !active && !data.enrolling ? data.config?.enrolled ? 'Website is offline. Your saved device will be reused when you reconnect.' : 'Website is offline. Publish when you are ready to connect.' : data.authUrl ? 'Action needed: complete the Tailscale approval below. This page updates automatically.' : state === 'Updating hostname' ? 'Applying your website name. Waiting for Tailscale to return the assigned address…' : state === 'Connected' ? verified ? 'Website verified. Share the link with your players.' : data.test?.state === 'Retrying public website' ? 'Public website starting. Retrying DNS and HTTPS checks automatically…' : data.test?.state === 'Running' ? 'Published. Checking the public website…' : data.test?.state === 'Check failed' ? 'Published, but a website check failed. Open Website checks for the reason and retry.' : data.test?.state === 'Cancelled' ? 'Website is published. Checks cancelled; automatic verification resumes shortly.' : data.test ? 'Website is published. Checks refresh automatically every four minutes.' : 'Published. Website checks will start automatically.' : data.enrolling && !active ? 'Waiting for sign-in. Your website is still offline.' : 'Restoring your saved device and publishing the website…';
        $('raHostnameHelp').textContent = data.config?.enrolled ? 'Changes the public link after reconnecting. Your device and sign-in are kept.' : 'Letters, numbers and hyphens. Tailscale supplies the rest of the address.';
        const url = sameProvider ? data.config?.url : '';
        $('raUrl').innerHTML = url ? `<p><a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(url)}</a> <button type="button" id="raCopyUrl">Copy URL</button>${data.config.enabled ? '' : ' — website is off'}</p>` : '';
        if ($('raCopyUrl')) $('raCopyUrl').onclick = () => copy(url);
        const authUrl = sameProvider && choice === 'tailscale' ? data.authUrl : '';
        const approval = authUrl && data.config?.enabled;
        $('raApproval').innerHTML = approval ? `<p><a class="ra-primary" href="${escape(authUrl)}" target="_blank" rel="noopener noreferrer">${observed.details?.backend === 'Running' ? 'Enable public access in Tailscale' : 'Continue in Tailscale'}</a></p><p class="ra-muted">Complete the approval in Tailscale. This window updates automatically.</p>` : '';
        $('raSignInLink').innerHTML = authUrl && !approval ? `<p><a class="ra-primary" href="${escape(authUrl)}" target="_blank" rel="noopener noreferrer">Continue in Tailscale</a> <button type="button" id="raCopyLogin">Copy sign-in link</button></p>` : '';
        if ($('raCopyLogin')) $('raCopyLogin').onclick = () => copy(data.authUrl);
        const test = data.test, expired = test && (Date.now()/1000 - test.started > 300 || test.revision !== data.config?.revision);
        $('raTestSource').textContent = test ? `${expired ? 'Stale test — run it again' : test.state}. Test source: ${test.source}. ${new Date(test.started*1000).toLocaleString()}. Outside-network access is not independently verified.` : 'No test has been run. Connector health alone does not verify your website.';
        $('raTestDetails').hidden = !test;
        $('raTestSummary').textContent = ['Running','Retrying public website'].includes(test?.state) ? 'Checking website…' : test?.state === 'Check failed' ? 'Website checks — needs attention' : verified && !expired ? 'Website checks — all passed' : 'Website checks';
        $('raChecks').hidden = !test;
        $('raChecks').innerHTML = Object.entries(labels).map(([key,label]) => {
            const step = test?.steps?.[key] || {state:'Not tested',message:''};
            return `<div class="ra-check"><strong>${label}</strong><span>${escape(expired ? 'Stale' : step.state === 'Waiting' ? 'Queued' : step.state)}</span>${step.state === 'Waiting' ? '<p>Starts after the preceding checks pass.</p>' : ''}${step.message && step.state !== 'Passed' ? `<p>${escape(step.message)}</p>` : ''}</div>`;
        }).join('');
        $('raDevice').innerHTML = test?.deviceUrl && !expired ? `<p><a href="${escape(test.deviceUrl)}" target="_blank" rel="noopener noreferrer">Test from another device</a> <button type="button" id="raCopyTest">Copy test link</button></p><p>${escape(test.device || 'Not tested')}. For an outside-network check, use a phone on mobile data. This link expires five minutes after the test started.</p>` : '';
        if ($('raCopyTest')) $('raCopyTest').onclick = () => copy(test.deviceUrl);
        $('raDetails').innerHTML = Object.entries(observed.details || {}).map(([key,value]) => `<dt>${escape(key)}</dt><dd>${escape(Array.isArray(value) ? value.join(', ') : value ?? 'Unavailable')}</dd>`).join('');
        $('raEvents').innerHTML = (data.events || []).slice().reverse().map(e => `<li>${escape(new Date(e.time*1000).toLocaleTimeString())}: ${escape(e.message)}</li>`).join('');
        layout();
        if (focused && $(focused) && document.activeElement === document.body) $(focused).focus({preventScroll:true});
    }
    async function poll() {
        clearTimeout(timer);
        if (inflight || document.hidden) return;
        inflight = true;
        const startedEpoch = epoch;
        try {
            const response = await fetch('remoteAccessAPI.php', {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(12000)});
            const next = await response.json();
            if (!response.ok || !next.success) throw new Error(next.error || 'Live status unavailable.');
            if (!busy && epoch === startedEpoch) { transportError = ''; data = next; csrf = next.csrf || csrf; lastReply = Date.now(); render(); }
        } catch (e) { transportError = e.message || 'Live status unavailable.'; statusMessage = transportError; message(statusMessage, true); if (data) render(); else { renderHeader(); if (overlay?.classList.contains('show')) { $('raSummary').textContent = 'Live status unavailable'; buttons(); } } }
        finally {
            inflight = false;
            if (overlay?.classList.contains('show') && (tab === 'games' || choice === 'direct')) loadNetwork();
            timer = setTimeout(poll, overlay?.classList.contains('show') && (['Running','Retrying public website'].includes(data?.test?.state) || data?.enrolling) ? 2000 : 5000);
        }
    }
    function close() {
        if (dirty && !confirm('Discard unsaved connection changes? Active connections and accepted tests will continue.')) return;
        clearTimeout(timer); clearInterval(feedbackTimer); overlay.classList.remove('show'); dirty = false; initialized = false; choice = ''; step = 1; tab = 'website';
        $('raToken').value = ''; $('raAuthKey').value = ''; lastFocus?.focus(); timer = setTimeout(poll, 5000);
    }
    window.showRemoteAccessModal = provider => {
        if (!overlay) make(); lastFocus = document.activeElement;
        overlay.classList.add('show'); message('');
        if (['tailscale','cloudflare'].includes(provider)) { tab = 'website'; choose(provider); }
        render(); layout(); $('raClose').focus(); poll();
        clearInterval(feedbackTimer); feedbackTimer = setInterval(() => { if (data) buttons(); }, 1000);
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { renderHeader(); poll(); } else clearTimeout(timer); });
    renderHeader(); poll();
})();
