<?php
/*
 * One world's log (2.56): PhValheim's side and Valheim's side, together or apart.
 *
 *   Activity  what PhValheim did to this world -- its operations (start, update, ...) with an
 *             outcome, and every event under them, from the engine, the tools, the operator
 *             and Hugin. Nothing from Valheim.
 *   Valheim   the game's own stdout, this boot only by default (from the offset the engine
 *             recorded when it started the process), or the whole current file.
 *   Both      the two merged by time, so an update reads as one story: the command, the mod
 *             install, the package, then Valheim loading the world.
 *
 * Replaces the per-world "Logs" window that showed only the raw Valheim file. Everything is
 * rendered with DOM calls; Valheim lines and event text are never trusted as HTML.
 */
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
$world = (string)($_GET['world'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]+$/', $world)) {
	http_response_code(400);
	echo 'A world name is required.';
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Logs - <?php echo htmlspecialchars($world); ?> - PhValheim</title>
	<link rel="icon" href="/favicon.ico">
	<style>
		:root { --bg: #0f1419; --bg2: #161c24; --bg3: #1e2733; --border: #2a3441; --text: #e2e8f0; --muted: #8b95a5; --dim: #5e6878;
		        --ok: #34d399; --warn: #fbbf24; --err: #f87171; --info: #60a5fa; --hugin: #a78bfa; --accent: #22d3ee; --valheim: #cbd5e1; }
		* { box-sizing: border-box; }
		body { margin: 0; background: var(--bg); color: var(--text); font: 13px/1.45 ui-monospace, "Cascadia Code", "JetBrains Mono", Menlo, Consolas, monospace; height: 100vh; display: flex; flex-direction: column; }
		header { display: flex; align-items: center; gap: 1rem; padding: 0.6rem 1rem; background: var(--bg2); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
		h1 { font-size: 1rem; margin: 0; font-weight: 600; } h1 span { color: var(--accent); }
		.tabs { display: flex; gap: 0.25rem; }
		.tab { padding: 0.35rem 0.9rem; border: 1px solid var(--border); border-radius: 6px; background: transparent; color: var(--muted); cursor: pointer; font: inherit; }
		.tab.active { background: var(--bg3); color: var(--text); border-color: var(--accent); }
		.opts { display: flex; gap: 0.9rem; align-items: center; margin-left: auto; flex-wrap: wrap; }
		label.chk { color: var(--muted); display: flex; align-items: center; gap: 0.3rem; cursor: pointer; }
		button.btn { background: var(--bg3); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.3rem 0.8rem; cursor: pointer; font: inherit; }
		button.btn:hover { border-color: var(--accent); }
		#ops { display: flex; gap: 0.4rem; padding: 0.45rem 1rem; background: var(--bg2); border-bottom: 1px solid var(--border); overflow-x: auto; }
		.op { flex: 0 0 auto; border: 1px solid var(--border); border-radius: 6px; padding: 0.3rem 0.55rem; cursor: pointer; background: var(--bg); font-size: 0.8rem; }
		.op:hover, .op.sel { border-color: var(--accent); }
		.op .m { color: var(--muted); }
		.pill { display: inline-block; font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.02rem 0.4rem; border-radius: 999px; color: #0f1419; margin-left: 0.35rem; }
		.pill.ok { background: var(--ok); } .pill.failed { background: var(--err); } .pill.interrupted { background: var(--warn); } .pill.open { background: var(--info); }
		.op .s { color: var(--text); font-size: 0.78rem; margin-top: 0.1rem; }
		#ops .lbl { align-self: center; color: var(--dim); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; padding-right: 0.3rem; }
		/* Operation boundaries inside the stream: where a command began and how it ended. */
		.ln.opmark { margin-top: 0.35rem; }
		.ln.opmark .msg { font-weight: 600; color: var(--muted); }
		.ln.opmark.begin { border-left: 2px solid var(--info); } .ln.opmark.begin .msg { color: var(--info); }
		.ln.opmark.ok { border-left: 2px solid var(--ok); margin-bottom: 0.35rem; } .ln.opmark.ok .msg { color: var(--ok); }
		.ln.opmark.failed { border-left: 2px solid var(--err); margin-bottom: 0.35rem; } .ln.opmark.failed .msg { color: var(--err); }
		.ln.opmark.interrupted { border-left: 2px solid var(--warn); margin-bottom: 0.35rem; } .ln.opmark.interrupted .msg { color: var(--warn); }
		.ln.opmark.open { border-left: 2px solid var(--info); } .ln.opmark.open .msg { color: var(--info); font-weight: 400; }
		main { flex: 1; overflow: auto; padding: 0.5rem 0.75rem; }
		.ln { display: grid; grid-template-columns: 9.5rem 6.5rem 1fr; gap: 0.6rem; padding: 0.1rem 0.3rem; border-radius: 4px; align-items: baseline; }
		.ln:hover { background: var(--bg3); }
		.ln .t { color: var(--dim); white-space: nowrap; }
		.ln .src { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--muted); }
		.ln .msg { white-space: pre-wrap; word-break: break-word; }
		.ln.event .msg { color: var(--text); } .ln.event .src { color: var(--accent); }
		.ln.src-admin .src, .ln.src-hugin .src { color: var(--hugin); }
		.ln.valheim .msg { color: var(--valheim); } .ln.valheim .src { color: var(--dim); }
		.ln.warn .msg { color: var(--warn); } .ln.error .msg { color: var(--err); } .ln.debug .msg { color: var(--dim); }
		.ln.event { border-left: 2px solid var(--accent); } .ln.valheim { border-left: 2px solid transparent; }
		.empty { color: var(--muted); padding: 1.5rem; text-align: center; }
		footer { padding: 0.35rem 1rem; border-top: 1px solid var(--border); background: var(--bg2); color: var(--muted); font-size: 0.78rem; display: flex; gap: 1.5rem; flex-wrap: wrap; }
	</style>
</head>
<body>
	<header>
		<h1>World <span id="worldName"></span></h1>
		<div class="tabs">
			<button class="tab" data-tab="activity" onclick="setMode('activity')" title="What PhValheim did to this world">Activity</button>
			<button class="tab" data-tab="valheim" onclick="setMode('valheim')" title="The game's own log">Valheim</button>
			<button class="tab active" data-tab="both" onclick="setMode('both')" title="Both, merged by time">Both</button>
		</div>
		<div class="opts">
			<label class="chk" id="bootOpt"><input type="checkbox" id="thisBoot" checked> This boot only</label>
			<label class="chk"><input type="checkbox" id="follow" checked> Follow</label>
			<button class="btn" onclick="load()">Refresh</button>
			<button class="btn" onclick="window.open('readLog.php?logfile=' + encodeURIComponent('valheimworld_' + WORLD + '.log') + '#bottom', '_blank')" title="The raw Valheim log file in the plain viewer">Raw file</button>
		</div>
	</header>
	<div id="ops"><span class="op m" style="cursor:default;border:none">Loading operations...</span></div>
	<main id="pane"><div class="empty">Loading...</div></main>
	<footer>
		<span id="statusLine"></span>
		<span id="valheimLine"></span>
	</footer>
	<script>
	const WORLD = <?php echo json_encode($world); ?>;
	const api = 'adminAPI.php?action=';
	let mode = 'both', selectedOp = null, timer = null, loading = false;
	document.getElementById('worldName').textContent = WORLD;

	function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
	function fmtT(t) { if (!t) return ''; const d = new Date(t * 1000); return d.toLocaleDateString(undefined, {month: 'short', day: '2-digit'}) + ' ' + d.toLocaleTimeString(undefined, {hour12: false}); }
	function fmtBytes(n) { if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(1) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
	function ago(t) { const s = Math.max(0, Math.round(Date.now() / 1000 - t)); return s < 60 ? s + 's ago' : s < 3600 ? Math.round(s / 60) + 'm ago' : Math.round(s / 3600) + 'h ago'; }

	// An operation is one command the engine carried out. It is shown by its VERB and how it
	// ended -- never by the world's final mode, which read as the command's outcome ("update ..
	// stopped" looked like an update that had stopped).
	const KIND_LABEL = { start: 'Start', stop: 'Stop', update: 'Update', create: 'Create', delete: 'Delete', repackage: 'Repackage' };
	function kindLabel(k) { k = String(k || ''); return KIND_LABEL[k] || (k.charAt(0).toUpperCase() + k.slice(1)); }
	function opState(o) { return o.open ? 'open' : (o.outcome || 'unknown'); }
	function opStateLabel(o) { return o.open ? 'in progress' : o.outcome === 'ok' ? 'done' : (o.outcome || 'unknown'); }
	function opDuration(o) { if (!o.finished_t) return 'started ' + ago(o.started_t); const s = Math.max(1, o.finished_t - o.started_t); return s < 120 ? s + 's' : Math.round(s / 60) + ' min'; }
	function opMark(o, phase) {
		// The stream line that opens or closes an operation.
		if (phase === 'begin') return { icon: '\u25B6', text: kindLabel(o.kind) + ' began' + (o.source && o.source !== 'phvalheim' ? ' (' + o.source + ')' : '') };
		if (o.open) return { icon: '\u2026', text: kindLabel(o.kind) + ' still in progress' };
		const icon = o.outcome === 'ok' ? '\u2714' : o.outcome === 'failed' ? '\u2716' : '\u26A0';
		return { icon: icon, text: kindLabel(o.kind) + ' ' + opStateLabel(o) + (o.summary ? ': ' + o.summary : '') + ' \u00B7 ' + opDuration(o) };
	}
	// Insert a begin marker before the first event of each operation and an end marker after
	// its last, so an update reads as one story with a start and a verdict instead of a run of
	// lines whose grouping is only visible as a chip at the top.
	function withOpMarkers(rows, ops) {
		if (!ops || !ops.length) return rows;
		const byId = {}; ops.forEach(o => { byId[o.op_id] = o; });
		const first = {}, last = {};
		rows.forEach((x, i) => { if (x.kind === 'event' && x.op_id && byId[x.op_id]) { if (first[x.op_id] === undefined) first[x.op_id] = i; last[x.op_id] = i; } });
		const out = [];
		rows.forEach((x, i) => {
			if (x.kind === 'event' && x.op_id && first[x.op_id] === i) { const o = byId[x.op_id]; out.push({ kind: 'opmark', phase: 'begin', op: o, t: o.started_t || x.t }); }
			out.push(x);
			if (x.kind === 'event' && x.op_id && last[x.op_id] === i) { const o = byId[x.op_id]; out.push({ kind: 'opmark', phase: 'end', op: o, t: o.finished_t || x.t }); }
		});
		return out;
	}

	function setMode(m) {
		mode = m;
		document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === m));
		document.getElementById('bootOpt').style.visibility = m === 'activity' ? 'hidden' : 'visible';
		load();
	}

	function renderLine(x) {
		if (x.kind === 'opmark') {
			const m = opMark(x.op, x.phase);
			const row = el('div', 'ln opmark ' + (x.phase === 'begin' ? 'begin' : opState(x.op)));
			row.appendChild(el('span', 't', fmtT(x.t)));
			row.appendChild(el('span', 'src', ''));
			row.appendChild(el('span', 'msg', m.icon + ' ' + m.text));
			return row;
		}
		const row = el('div', 'ln ' + x.kind + ' ' + x.level + ' src-' + x.source + (selectedOp && x.op_id === selectedOp ? ' sel' : ''));
		row.appendChild(el('span', 't', fmtT(x.t)));
		row.appendChild(el('span', 'src', x.kind === 'valheim' ? 'valheim' : x.source));
		row.appendChild(el('span', 'msg', x.text));
		return row;
	}

	function renderOps(ops) {
		const bar = document.getElementById('ops'); bar.innerHTML = '';
		if (!ops.length) { bar.appendChild(el('span', 'op m', 'No operations recorded for this world yet. One appears here each time PhValheim starts, stops, updates or repackages it (recorded from 2.56 on).')).style.cursor = 'default'; return; }
		bar.appendChild(el('span', 'lbl', 'Recent'));
		for (const o of ops) {
			const b = el('div', 'op' + (selectedOp === o.op_id ? ' sel' : ''));
			b.appendChild(el('span', '', kindLabel(o.kind)));
			b.appendChild(el('span', 'pill ' + opState(o), opStateLabel(o)));
			b.appendChild(el('div', 'm', fmtT(o.started_t) + ' \u00B7 ' + opDuration(o) + ' \u00B7 ' + o.event_count + ' events' + (o.error_count ? ', ' + o.error_count + ' warn/err' : '')));
			if (o.summary) b.appendChild(el('div', 's', o.summary));
			b.title = 'Show only this operation\'s events';
			b.onclick = () => { selectedOp = selectedOp === o.op_id ? null : o.op_id; if (selectedOp) setMode('activity'); else load(); };
			bar.appendChild(b);
		}
	}

	async function load() {
		if (loading) return; loading = true;
		const pane = document.getElementById('pane');
		const p = new URLSearchParams({ world: WORLD, mode: mode, boot: document.getElementById('thisBoot').checked ? '1' : '0',
		                                max_bytes: document.getElementById('follow').checked ? '400000' : '2000000' });
		try {
			let url = api + 'getWorldTimeline&' + p.toString();
			const r = await fetch(url, { cache: 'no-store' });
			const d = await r.json();
			if (!d.success) { pane.innerHTML = ''; pane.appendChild(el('div', 'empty', d.error || 'Could not load.')); return; }
			let rows = d.timeline;
			if (selectedOp) rows = rows.filter(x => x.kind === 'event' && x.op_id === selectedOp);
			const ev = rows.filter(x => x.kind === 'event').length, vl = rows.length - ev;
			if (mode !== 'valheim') rows = withOpMarkers(rows, d.operations || []);
			const atBottom = pane.scrollHeight - pane.scrollTop - pane.clientHeight < 60 || pane.querySelector('.empty');
			pane.innerHTML = '';
			if (!rows.length) {
				pane.appendChild(el('div', 'empty', mode === 'valheim' ? (d.valheim.exists ? 'Nothing in the Valheim log for this boot yet.' : 'This world has no Valheim log yet; it has never been started.')
				                                  : mode === 'activity' ? 'No PhValheim activity recorded for this world yet.' : 'Nothing to show yet.'));
			} else {
				const frag = document.createDocumentFragment();
				rows.forEach(x => frag.appendChild(renderLine(x)));
				pane.appendChild(frag);
			}
			if (atBottom) pane.scrollTop = pane.scrollHeight;
			renderOps(d.operations || []);
			document.getElementById('statusLine').textContent = (mode === 'valheim' ? vl + ' Valheim lines' : mode === 'activity' ? ev + ' events' : ev + ' events, ' + vl + ' Valheim lines') + ' · updated ' + new Date().toLocaleTimeString();
			document.getElementById('valheimLine').textContent = mode === 'activity' ? '' : (d.valheim.exists
				? 'Valheim log ' + fmtBytes(d.valheim.size) + (d.this_boot && d.valheim.from_offset ? ', shown from this boot (offset ' + fmtBytes(d.valheim.from_offset) + ')' : d.this_boot ? ', no session marker yet so the whole file is shown' : ', whole file') + (d.valheim.truncated ? ', older part cut to keep this fast' : '')
				: 'No Valheim log file yet.');
		} catch (e) { console.error(e); }
		finally { loading = false; }
	}

	function restartFollow() {
		if (timer) clearInterval(timer); timer = null;
		if (document.getElementById('follow').checked) timer = setInterval(load, 4000);
	}
	document.getElementById('thisBoot').addEventListener('change', load);
	document.getElementById('follow').addEventListener('change', () => { restartFollow(); load(); });
	load();
	restartFollow();
	</script>
</body>
</html>
