<?php
/*
 * Logs (2.56): one place for everything PhValheim has to say.
 *
 *   Activity  the event log -- every action PhValheim, its tools, the operator and Hugin took,
 *             with a level, the world it concerned and the operation it belonged to. Filter by
 *             world, source, level and text; follow it live; pick an operation to see only its
 *             events. Opening this page marks the current events as seen, which is what stops
 *             the sidebar's pulse.
 *   Files     every raw log on disk (the engine's, Valheim's per world, nginx, php, cron...),
 *             with size and last write, opening in the plain viewer.
 *
 * Replaces the four sidebar links and the dashboard's "Additional Logs" card. Standalone page,
 * same palette as the viewer. Everything is rendered with DOM calls: an event's text can quote
 * a catalogue's error body verbatim.
 */
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Logs - PhValheim</title>
	<link rel="icon" href="/favicon.ico">
	<style>
		:root { --bg: #0f1419; --bg2: #161c24; --bg3: #1e2733; --border: #2a3441; --text: #e2e8f0; --muted: #8b95a5; --dim: #5e6878;
		        --ok: #34d399; --warn: #fbbf24; --err: #f87171; --info: #60a5fa; --hugin: #a78bfa; --accent: #22d3ee; }
		* { box-sizing: border-box; }
		body { margin: 0; background: var(--bg); color: var(--text); font: 13px/1.45 ui-monospace, "Cascadia Code", "JetBrains Mono", Menlo, Consolas, monospace; height: 100vh; display: flex; flex-direction: column; }
		header { display: flex; align-items: center; gap: 1rem; padding: 0.6rem 1rem; background: var(--bg2); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
		h1 { font-size: 1rem; margin: 0; font-weight: 600; letter-spacing: 0.02em; }
		.tabs { display: flex; gap: 0.25rem; }
		.tab { padding: 0.35rem 0.9rem; border: 1px solid var(--border); border-radius: 6px; background: transparent; color: var(--muted); cursor: pointer; font: inherit; }
		.tab.active { background: var(--bg3); color: var(--text); border-color: var(--accent); }
		.filters { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-left: auto; }
		select, input[type=text] { background: var(--bg3); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.3rem 0.5rem; font: inherit; }
		input[type=text] { width: 14rem; }
		label.chk { color: var(--muted); display: flex; align-items: center; gap: 0.3rem; cursor: pointer; }
		button.btn { background: var(--bg3); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.3rem 0.8rem; cursor: pointer; font: inherit; }
		button.btn:hover { border-color: var(--accent); }
		main { flex: 1; display: flex; min-height: 0; }
		#activity { flex: 1; display: flex; min-height: 0; }
		#eventPane { flex: 1; overflow: auto; padding: 0.5rem 0.75rem; }
		#opsPane { width: 21rem; border-left: 1px solid var(--border); background: var(--bg2); overflow: auto; padding: 0.6rem; }
		#opsPane h2, #files h2 { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin: 0 0 0.5rem; }
		.ev { display: grid; grid-template-columns: 9.5rem 7rem 1fr; gap: 0.6rem; padding: 0.12rem 0.3rem; border-radius: 4px; align-items: baseline; }
		.ev:hover { background: var(--bg3); }
		.ev .t { color: var(--dim); white-space: nowrap; }
		.ev .src { color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.ev .msg { white-space: pre-wrap; word-break: break-word; }
		.ev .w { color: var(--accent); margin-right: 0.4rem; }
		.ev.warn .msg { color: var(--warn); } .ev.error .msg { color: var(--err); } .ev.debug .msg { color: var(--dim); }
		.ev.src-admin .src, .ev.src-hugin .src { color: var(--hugin); }
		.op { border: 1px solid var(--border); border-radius: 6px; padding: 0.45rem 0.55rem; margin-bottom: 0.4rem; cursor: pointer; background: var(--bg); }
		.op:hover, .op.sel { border-color: var(--accent); }
		.op .k { font-weight: 600; } .op .m { color: var(--muted); font-size: 0.78rem; }
		.pill { display: inline-block; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.05rem 0.4rem; border-radius: 999px; color: #0f1419; margin-left: 0.4rem; }
		.pill.ok { background: var(--ok); } .pill.failed { background: var(--err); } .pill.interrupted { background: var(--warn); } .pill.open { background: var(--info); }
		#files { flex: 1; overflow: auto; padding: 0.75rem 1rem; }
		table { border-collapse: collapse; width: 100%; }
		th, td { text-align: left; padding: 0.35rem 0.6rem; border-bottom: 1px solid var(--border); }
		th { color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; }
		tr.f { cursor: pointer; } tr.f:hover { background: var(--bg3); }
		.empty { color: var(--muted); padding: 1.5rem; text-align: center; }
		footer { padding: 0.35rem 1rem; border-top: 1px solid var(--border); background: var(--bg2); color: var(--muted); font-size: 0.78rem; display: flex; gap: 1.5rem; }
		.hidden { display: none !important; }
	</style>
</head>
<body>
	<header>
		<h1>PhValheim Logs</h1>
		<div class="tabs">
			<button class="tab active" data-tab="activity" onclick="showTab('activity')">Activity</button>
			<button class="tab" data-tab="files" onclick="showTab('files')">Files</button>
		</div>
		<div class="filters" id="activityFilters">
			<select id="fWorld" title="World"><option value="">All worlds</option></select>
			<select id="fSource" title="Source">
				<option value="">All sources</option>
				<option value="phvalheim">Engine</option>
				<option value="updateApplier">Automatic updates</option>
				<option value="backup">Backups</option>
				<option value="restore">Restores</option>
				<option value="playerMonitor">Player monitor</option>
				<option value="activity">Activity monitor</option>
				<option value="analytics">Analytics</option>
				<option value="admin">Operator (admin UI)</option>
				<option value="hugin">Hugin</option>
			</select>
			<select id="fLevel" title="Minimum level">
				<option value="">All levels</option>
				<option value="notice">Notice and above</option>
				<option value="warn">Warnings and errors</option>
				<option value="error">Errors only</option>
			</select>
			<input type="text" id="fQ" placeholder="Search text..." title="Substring search">
			<label class="chk"><input type="checkbox" id="fFollow" checked> Follow</label>
			<button class="btn" onclick="clearOp(); loadEvents(true)">Refresh</button>
		</div>
	</header>
	<main>
		<section id="activity">
			<div id="eventPane"><div class="empty">Loading...</div></div>
			<aside id="opsPane">
				<h2>Recent operations</h2>
				<div id="opsList"><div class="empty">Loading...</div></div>
			</aside>
		</section>
		<section id="files" class="hidden">
			<h2>Log files on disk</h2>
			<table>
				<thead><tr><th>File</th><th>World</th><th>Size</th><th>Last written</th><th></th></tr></thead>
				<tbody id="filesBody"><tr><td colspan="5" class="empty">Loading...</td></tr></tbody>
			</table>
		</section>
	</main>
	<footer>
		<span id="statusLine">Loading...</span>
		<span id="seenLine"></span>
	</footer>
	<script>
	const api = 'adminAPI.php?action=';
	let lastId = 0, selectedOp = null, followTimer = null, maxSeen = 0;

	function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
	function fmtT(t) { if (!t) return ''; const d = new Date(t * 1000); return d.toLocaleDateString(undefined, {month: 'short', day: '2-digit'}) + ' ' + d.toLocaleTimeString(undefined, {hour12: false}); }
	function fmtBytes(n) { if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(1) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
	function ago(t) { const s = Math.max(0, Math.floor(Date.now() / 1000 - t)); if (s < 60) return s + 's ago'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago'; return Math.floor(s / 86400) + 'd ago'; }

	function showTab(name) {
		document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
		document.getElementById('activity').classList.toggle('hidden', name !== 'activity');
		document.getElementById('files').classList.toggle('hidden', name !== 'files');
		document.getElementById('activityFilters').classList.toggle('hidden', name !== 'activity');
		if (name === 'files') loadFiles();
	}

	function filterParams() {
		const p = new URLSearchParams();
		const w = document.getElementById('fWorld').value, s = document.getElementById('fSource').value,
		      l = document.getElementById('fLevel').value, q = document.getElementById('fQ').value.trim();
		if (w) p.set('world', w); if (s) p.set('source', s); if (l) p.set('min_level', l); if (q) p.set('q', q);
		if (selectedOp) p.set('op_id', selectedOp);
		return p;
	}

	function renderEvent(e) {
		const row = el('div', 'ev ' + e.level + ' src-' + e.source);
		row.appendChild(el('span', 't', fmtT(e.t)));
		row.appendChild(el('span', 'src', e.source));
		const msg = el('span', 'msg');
		if (e.world) msg.appendChild(el('span', 'w', '{' + e.world + '}'));
		msg.appendChild(document.createTextNode(e.message));
		row.appendChild(msg);
		return row;
	}

	async function loadEvents(reset) {
		const pane = document.getElementById('eventPane');
		const p = filterParams();
		if (!reset && lastId) p.set('since_id', String(lastId)); else p.set('limit', '300');
		try {
			const r = await fetch(api + 'getEvents&' + p.toString(), { cache: 'no-store' });
			const d = await r.json();
			if (!d.success) return;
			if (reset) { pane.innerHTML = ''; lastId = 0; }
			if (reset && d.events.length === 0) pane.appendChild(el('div', 'empty', 'No events match. PhValheim records its actions from 2.56 on; an idle server writes little.'));
			const atBottom = pane.scrollHeight - pane.scrollTop - pane.clientHeight < 40;
			for (const e of d.events) { pane.appendChild(renderEvent(e)); lastId = Math.max(lastId, e.id); maxSeen = Math.max(maxSeen, e.id); }
			if (reset || atBottom) pane.scrollTop = pane.scrollHeight;
			document.getElementById('statusLine').textContent = pane.querySelectorAll('.ev').length + ' events shown' + (selectedOp ? ' (one operation)' : '') + ', updated ' + new Date().toLocaleTimeString();
			if (d.events.length && document.visibilityState === 'visible') ack();
		} catch (err) { console.error(err); }
	}

	// Opening the page -- or seeing new events arrive while it is in front -- counts as having
	// looked. That is what clears the sidebar pulse on the dashboard.
	async function ack() {
		try {
			const r = await fetch(api + 'ackEvents', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id: maxSeen || lastId }) });
			const d = await r.json();
			if (d.success) document.getElementById('seenLine').textContent = (d.unseen_error + d.unseen_warn) ? (d.unseen_error + ' error(s), ' + d.unseen_warn + ' warning(s) still unseen under the current filter') : 'All warnings and errors seen.';
		} catch (e) {}
	}

	function renderOp(o) {
		const box = el('div', 'op' + (selectedOp === o.op_id ? ' sel' : ''));
		const head = el('div');
		head.appendChild(el('span', 'k', o.kind + ' ' + o.world));
		const state = o.open ? 'open' : (o.outcome || 'unknown');
		head.appendChild(el('span', 'pill ' + state, o.open ? 'running' : state));
		box.appendChild(head);
		box.appendChild(el('div', 'm', fmtT(o.started_t) + (o.finished_t ? ' · ' + Math.max(1, o.finished_t - o.started_t) + 's' : ' · ' + ago(o.started_t)) + ' · ' + o.event_count + ' events' + (o.error_count ? ', ' + o.error_count + ' warn/err' : '') + (o.summary ? ' · ' + o.summary : '')));
		box.onclick = () => { selectedOp = selectedOp === o.op_id ? null : o.op_id; loadOps(); loadEvents(true); };
		return box;
	}
	function clearOp() { selectedOp = null; }

	async function loadOps() {
		try {
			const w = document.getElementById('fWorld').value;
			const r = await fetch(api + 'getOperations&limit=25' + (w ? '&world=' + encodeURIComponent(w) : ''), { cache: 'no-store' });
			const d = await r.json();
			const list = document.getElementById('opsList'); list.innerHTML = '';
			if (!d.success || !d.operations.length) { list.appendChild(el('div', 'empty', 'No operations recorded yet.')); return; }
			d.operations.forEach(o => list.appendChild(renderOp(o)));
		} catch (e) { console.error(e); }
	}

	async function loadWorlds() {
		try {
			const r = await fetch(api + 'getWorlds', { cache: 'no-store' });
			const d = await r.json();
			const sel = document.getElementById('fWorld');
			(d.worlds || []).map(w => w.name).sort().forEach(n => { const o = el('option', '', n); o.value = n; sel.appendChild(o); });
			const want = new URLSearchParams(location.search).get('world');
			if (want) sel.value = want;
		} catch (e) {}
	}

	async function loadFiles() {
		const body = document.getElementById('filesBody');
		try {
			const r = await fetch(api + 'getLogFiles', { cache: 'no-store' });
			const d = await r.json();
			body.innerHTML = '';
			if (!d.success || !d.files.length) { const tr = el('tr'); const td = el('td', 'empty', 'No log files.'); td.colSpan = 5; tr.appendChild(td); body.appendChild(tr); return; }
			for (const f of d.files) {
				const tr = el('tr', 'f');
				tr.appendChild(el('td', '', f.name));
				tr.appendChild(el('td', '', f.world || ''));
				tr.appendChild(el('td', '', fmtBytes(f.size)));
				tr.appendChild(el('td', '', fmtT(f.mtime) + ' (' + ago(f.mtime) + ')'));
				const act = el('td');
				const b = el('button', 'btn', 'Open');
				b.onclick = (ev) => { ev.stopPropagation(); window.open('readLog.php?logfile=' + encodeURIComponent(f.name) + '#bottom', '_blank'); };
				act.appendChild(b);
				if (f.rotated) { const b2 = el('button', 'btn', 'Previous'); b2.style.marginLeft = '0.4rem'; b2.title = 'The rotated copy, ' + f.name + '.1'; b2.onclick = (ev) => { ev.stopPropagation(); window.open('readLog.php?logfile=' + encodeURIComponent(f.name + '.1') + '#bottom', '_blank'); }; act.appendChild(b2); }
				if (f.world) { const b3 = el('button', 'btn', 'World view'); b3.style.marginLeft = '0.4rem'; b3.onclick = (ev) => { ev.stopPropagation(); window.open('world_logs.php?world=' + encodeURIComponent(f.world), '_blank'); }; act.appendChild(b3); }
				tr.appendChild(act);
				tr.onclick = () => window.open('readLog.php?logfile=' + encodeURIComponent(f.name) + '#bottom', '_blank');
				body.appendChild(tr);
			}
		} catch (e) { console.error(e); }
	}

	function restartFollow() {
		if (followTimer) clearInterval(followTimer);
		followTimer = null;
		if (document.getElementById('fFollow').checked) followTimer = setInterval(() => { loadEvents(false); loadOps(); }, 5000);
	}

	['fWorld', 'fSource', 'fLevel'].forEach(id => document.getElementById(id).addEventListener('change', () => { clearOp(); loadOps(); loadEvents(true); }));
	document.getElementById('fQ').addEventListener('keydown', e => { if (e.key === 'Enter') { clearOp(); loadEvents(true); } });
	document.getElementById('fFollow').addEventListener('change', restartFollow);

	(async function () {
		await loadWorlds();
		await loadOps();
		await loadEvents(true);
		restartFollow();
	})();
	</script>
</body>
</html>
