/* Remote Access: observed state only. No simulated checks or saved-secret echo. */
(() => {
    'use strict';
    let overlay, lastFocus, timer, inflight = false, busy = false, csrf = '', data = null;
    let initialized = false, lastReply = 0, dirty = false, epoch = 0, statusMessage = '', autoInstall = false;
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
          <div class="mods-modal-body">
            <p>Connect your player website using your own account. Game connections use your existing settings.</p>
            <div id="raMessage" role="status" aria-live="polite"></div>
            <details id="raInstall"><summary>Install the secure connector / open admin securely</summary>
              <p>Remote Access uses an optional connector container to keep tunnels away from the admin UI and your LAN. Both containers use the PhValheim image. The connector needs its own network, a private state volume, and a shared socket volume.</p>
              <p><a href="remote-access-help.html" target="_blank" rel="noopener noreferrer">Open step-by-step connector installation instructions</a>. Do not use host networking or mount the Docker socket.</p>
              <p>Before entering credentials, open admin over a secure local connection. On the Docker host use <code>http://localhost:8081</code>. From another computer use SSH forwarding, then open that same address:</p>
              <pre>ssh -L 8081:127.0.0.1:8081 USER@YOUR_SERVER</pre>
              <p>Replace USER and YOUR_SERVER with your SSH login and server. Keep the SSH window open. The future public tunnel does not secure an existing HTTP admin session.</p>
            </details>
            <div class="ra-status" id="raSummary" aria-live="polite">Loading connection status…</div>
            <p id="raLastChecked" aria-live="off"></p><div id="raUrl"></div>
            <div class="ra-actions"><button type="button" id="raTest">Test connection</button><button type="button" id="raCancel">Cancel test</button><button type="button" id="raDisconnect">Disconnect</button></div>
            <details id="raSetup" open><summary>Configure Remote Access</summary>
              <label for="raProvider">Connection type</label><select id="raProvider"><option value="cloudflare">Cloudflare — public player website</option><option value="tailscale">Tailscale — private player website</option></select>
              <div id="raInstructions"></div>
              <div id="raCloudFields"><label for="raUrlInput">Website URL</label><input id="raUrlInput" type="url" placeholder="https://play.example.net" autocomplete="off" maxlength="253">
              <label for="raToken">Tunnel token <span id="raTokenSaved"></span></label><input id="raToken" type="password" autocomplete="new-password" maxlength="4096" placeholder="Paste only the connector token"></div>
              <div id="raTailFields" hidden><div id="raSignInLink"></div><details><summary>Use an auth key instead</summary><p>Create a single-use device enrollment key in <a href="https://login.tailscale.com/admin/settings/keys" target="_blank" rel="noopener noreferrer">Tailscale settings</a>. PhValheim removes it after enrollment and keeps the device identity.</p><label for="raAuthKey">Enrollment auth key</label><input id="raAuthKey" type="password" autocomplete="new-password" maxlength="400"></details><button type="button" id="raSignIn">Sign in to Tailscale</button></div>
              <p id="raAudience"></p>
              <div class="ra-actions"><button type="button" id="raSave">Save without connecting</button><button type="button" id="raConnect">Connect and test</button></div>
            </details>
            <h3>Connection checks</h3><p id="raTestSource">No test has been run. Connector health alone does not verify your website.</p>
            <div id="raChecks"></div><div id="raDevice"></div>
            <details><summary>Connection details</summary><dl id="raDetails"></dl><h4>Recent activity</h4><ul id="raEvents"></ul></details>
            <details><summary>Remove this connection</summary><p>Forget removes local credentials. In Cloudflare, delete the tunnel and hostname route if no longer needed. In Tailscale, remove the device from Machines. Other connectors or routes in your account are unaffected.</p><p><a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">Open Cloudflare</a> · <a href="https://login.tailscale.com/admin/machines" target="_blank" rel="noopener noreferrer">Open Tailscale devices</a></p><button type="button" id="raForget">Disconnect and forget</button></details>
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
        $('raProvider').onchange = () => { dirty = true; instructions(); buttons(); };
        $('raSave').onclick = () => perform(async () => { await save(); message('Settings saved. Access is off.'); });
        $('raConnect').onclick = () => perform(async () => {
            if (!data?.config?.enabled && ($('raProvider').value === 'cloudflare' || dirty || data?.config?.provider !== $('raProvider').value)) await save();
            await request('connect'); dirty = false; message('Connection requested. Live checks are running.');
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
                perform(async () => { await request('forget'); initialized = false; dirty = false; render(); });
            }
        };
        document.addEventListener('visibilitychange', () => { if (!document.hidden && overlay.classList.contains('show')) poll(); });
        instructions();
        buttons();
    }
    function instructions() {
        const provider = $('raProvider').value, cf = provider === 'cloudflare';
        $('raCloudFields').hidden = !cf; $('raTailFields').hidden = cf;
        $('raInstructions').innerHTML = cf ? `<ol><li><strong>Create a tunnel.</strong> Open <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">Cloudflare</a> → Networking → Tunnels. Create a tunnel and copy its connector token. PhValheim runs the connector; do not run the installation command.</li><li><strong>Add your website route.</strong> Add a published application hostname on your domain. Choose HTTP and use this origin inside the secure connector: <code>${escape(data?.origin || 'http://127.0.0.1:8080')}</code> <button type="button" id="raCopyOrigin">Copy origin</button>.</li><li><strong>Connect and test.</strong> Enter the full HTTPS hostname and token below. Follow any failed checks to fix the connection.</li></ol>` : `<ol><li><strong>Sign in.</strong> Choose Sign in to Tailscale, then open the sign-in link that appears. Approve this device in your account if asked.</li><li><strong>Allow private HTTPS.</strong> Enable MagicDNS and HTTPS in <a href="https://login.tailscale.com/admin/dns" target="_blank" rel="noopener noreferrer">Tailscale DNS settings</a>. Your tailnet policy must permit the intended devices to reach this device on HTTPS port 443.</li><li><strong>Connect and test.</strong> Choose Connect and test, then open the test link from another authorized Tailscale device. Players need Tailscale; this does not make the website public.</li></ol>`;
        $('raInstructions').insertAdjacentHTML('beforeend', `<p><a href="${official[provider]}" target="_blank" rel="noopener noreferrer">Official setup guide</a></p>`);
        if ($('raCopyOrigin')) $('raCopyOrigin').onclick = () => copy(data?.origin || 'http://127.0.0.1:8080');
        $('raAudience').textContent = cf ? 'Connecting makes your player website public. Existing PhValheim player permissions still apply.' : 'Connecting shares only the player website with permitted tailnet devices. Funnel and game VPN routing are not enabled.';
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
        data = next; lastReply = Date.now(); render(); return next;
    }
    async function perform(fn) {
        if (busy) return;
        if (!data?.available || !csrf) { message('Open admin securely and wait for the connector status before entering credentials.', true); return; }
        busy = true; buttons();
        try { message('Working…'); await fn(); if ($('raMessage').textContent === 'Working…') message('Action completed. Current connection status is shown below.'); }
        catch (e) { message(e.message || 'Connection operation failed. Retry.', true); }
        finally { busy = false; buttons(); }
    }
    function buttons() {
        const available = data?.available && !!csrf, enabled = data?.config?.enabled, enrolling = data?.enrolling;
        ['raSave','raConnect','raSignIn','raForget'].forEach(id => $(id).disabled = busy || !available);
        $('raSave').disabled ||= enabled || enrolling;
        $('raSignIn').disabled ||= enabled;
        $('raConnect').disabled ||= enabled || ($('raProvider').value === 'tailscale' && data?.observed?.details?.backend !== 'Running');
        $('raProvider').disabled = busy || enabled || enrolling;
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
            $('raUrlInput').value = data.config.url || ''; initialized = true; instructions();
        }
        $('raTokenSaved').textContent = data.config?.tokenSaved ? '(token saved; paste a replacement to change it)' : '';
        const observed = data.observed || {}, age = Math.max(0, Math.round((data.now || Date.now()/1000) - (observed.time || 0)));
        const stale = Date.now() - lastReply > 15000 || age > 15;
        $('raSummary').textContent = !data.available ? 'Secure connector unavailable' : stale ? 'Live status unavailable — last observation is stale' : `${observed.state || 'Unknown'} · ${data.config?.provider === 'cloudflare' ? 'Public website' : data.config?.provider === 'tailscale' ? 'Private website' : 'No provider configured'}`;
        $('raLastChecked').textContent = data.available ? `Last observation: ${new Date((observed.time || 0)*1000).toLocaleTimeString()}${stale ? ' — stale' : ''}` : '';
        if (!data.available) {
            autoInstall = true; $('raInstall').open = true;
            statusMessage = data.error || 'Install or start the secure connector.'; message(statusMessage, true);
        } else {
            if (autoInstall) { $('raInstall').open = false; autoInstall = false; }
            if (data.error && !busy) { statusMessage = data.error; message(statusMessage, true); }
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
        $('raChecks').innerHTML = Object.entries(labels).map(([key,label]) => {
            const step = test?.steps?.[key] || {state:'Not tested',message:''};
            return `<div class="ra-check"><strong>${label}</strong><span>${escape(expired ? 'Stale' : step.state)}</span><p>${escape(step.message)}</p></div>`;
        }).join('');
        $('raDevice').innerHTML = test?.deviceUrl && !expired ? `<p><a href="${escape(test.deviceUrl)}" target="_blank" rel="noopener noreferrer">Test from another device</a> <button type="button" id="raCopyTest">Copy test link</button></p><p>${escape(test.device || 'Not tested')}. ${data.config?.provider === 'tailscale' ? 'Use another authorized Tailscale device.' : 'For an outside-network check, use a phone on mobile data.'} This link expires five minutes after the test started.</p>` : '';
        if ($('raCopyTest')) $('raCopyTest').onclick = () => copy(test.deviceUrl);
        $('raDetails').innerHTML = Object.entries(observed.details || {}).map(([key,value]) => `<dt>${escape(key)}</dt><dd>${escape(Array.isArray(value) ? value.join(', ') : value ?? 'Unavailable')}</dd>`).join('');
        $('raEvents').innerHTML = (data.events || []).slice().reverse().map(e => `<li>${escape(new Date(e.time*1000).toLocaleTimeString())}: ${escape(e.message)}</li>`).join('');
        buttons();
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
            if (!busy && epoch === startedEpoch) { data = next; csrf = next.csrf || csrf; lastReply = Date.now(); render(); }
        } catch (e) { statusMessage = e.message || 'Live status unavailable.'; message(statusMessage, true); $('raInstall').open = !csrf; if (data) render(); else { $('raSummary').textContent = 'Live status unavailable'; buttons(); } }
        finally {
            inflight = false;
            if (overlay?.classList.contains('show')) timer = setTimeout(poll, data?.test?.state === 'Running' || data?.enrolling ? 2000 : 5000);
        }
    }
    function close() {
        if (dirty && !confirm('Discard unsaved connection changes? Active connections and accepted tests will continue.')) return;
        clearTimeout(timer); overlay.classList.remove('show'); dirty = false; initialized = false;
        $('raToken').value = ''; $('raAuthKey').value = ''; lastFocus?.focus();
    }
    window.showRemoteAccessModal = () => {
        if (!overlay) make(); lastFocus = document.activeElement;
        overlay.classList.add('show'); $('raClose').focus(); poll();
    };
})();
