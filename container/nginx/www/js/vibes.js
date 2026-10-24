/* =====================================================================================
   Hugin's Vibes (2.59) -- the page's one module. docs/HUGINS-VIBES-DESIGN.md section 10.

   Vanilla ES2020, no framework, no bundler, no CDN. The page (admin/hugins_vibes.php) ships
   static scaffolding plus the initial state as JSON; everything that moves is here.

   Rules this file lives by:
     - Every string that came from the model, a file, git, or the operator is put in the DOM
       with textContent / createElement. innerHTML never sees model or file text.
     - A failure always reaches the operator as a sentence: toast + inline where it applies.
     - A button that starts a job disables itself with the reason, and comes back on done/error.
     - Sections are banner-commented; functions stay short. Comments say WHY.

   The module is assembled from several IIFEs (one per section) that extend `Vibes`; the last
   one boots. That is a concession to how this file is written and shipped (appended in parts),
   not an architecture: there is still exactly one namespace.
   ===================================================================================== */
'use strict';
const Vibes = {};

/* =====================================================================================
   1. Utilities, store, API client, toasts, modals, Hugin
   ===================================================================================== */
(() => {
	const $ = (id) => document.getElementById(id);
	const qs = (sel, root) => (root || document).querySelector(sel);
	const qsa = (sel, root) => Array.from((root || document).querySelectorAll(sel));

	/** DOM builder: h('div.cls#id', {attr}, child, 'text', [more]) -- never HTML strings. */
	function h(spec, attrs, ...children) {
		const m = /^([a-z0-9-]+)((?:[.#][\w-]+)*)$/i.exec(spec) || [spec, spec, ''];
		const el = document.createElement(m[1]);
		for (const part of (m[2].match(/[.#][\w-]+/g) || [])) {
			if (part[0] === '.') el.classList.add(part.slice(1)); else el.id = part.slice(1);
		}
		if (attrs && typeof attrs === 'object' && !(attrs instanceof Node) && !Array.isArray(attrs)) {
			for (const [k, v] of Object.entries(attrs)) {
				if (v === null || v === undefined || v === false) continue;
				if (k === 'class') el.className = v;
				else if (k === 'text') el.textContent = v;
				else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
				else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
				else if (k === 'dataset') Object.assign(el.dataset, v);
				else el.setAttribute(k, v === true ? '' : v);
			}
		} else if (attrs !== undefined) children.unshift(attrs);
		append(el, children);
		return el;
	}
	function append(el, kids) {
		for (const c of kids) {
			if (c === null || c === undefined || c === false) continue;
			if (Array.isArray(c)) append(el, c);
			else if (c instanceof Node) el.appendChild(c);
			else el.appendChild(document.createTextNode(String(c)));
		}
		return el;
	}
	function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

	function fmtBytes(b) {
		if (b === null || b === undefined || isNaN(b)) return '?';
		let n = Number(b); const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (i === 0 ? n : n >= 100 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
	}
	function fmtMs(ms) { ms = Number(ms) || 0; return ms < 1000 ? ms + ' ms' : (ms / 1000).toFixed(ms < 10000 ? 1 : 0) + ' s'; }
	function fmtAgo(when) {
		if (!when) return '';
		const t = typeof when === 'number' ? when * 1000 : Date.parse(String(when).replace(' ', 'T') + (String(when).includes('Z') ? '' : 'Z'));
		if (isNaN(t)) return String(when);
		const d = (Date.now() - t) / 1000;
		if (d < 45) return 'just now';
		if (d < 3600) return Math.round(d / 60) + ' min ago';
		if (d < 86400) return Math.round(d / 3600) + ' h ago';
		return Math.round(d / 86400) + ' d ago';
	}
	function fmtTime(t) { const d = typeof t === 'number' ? new Date(t * 1000) : new Date(t); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
	function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
	function reducedMotion() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

	/* ---- Store: one object, one subscribe --------------------------------------------- */
	const S = {
		state: null,          // the server's `state` answer (installed, pak, libs, ai, disk, jobs...)
		projects: [],
		project: null,        // the full `project` answer for the selected Vibe
		session: null,        // selected hv_sessions row
		settings: null,       // the `settings` answer (masked)
		filter: '',
		pageState: 'booting', // 1..8 as a short word, see router
		dismissedBanner: '',
		busy: new Map(),      // button -> original label while a job runs
	};
	const listeners = new Set();
	function emit() { for (const fn of listeners) { try { fn(S); } catch (e) { console.error(e); } } }

	/* ---- API client ------------------------------------------------------------------- */
	class ApiError extends Error { constructor(msg, status, body) { super(msg); this.status = status; this.body = body || {}; } }
	async function api(action, params, opts) {
		opts = opts || {};
		const url = new URL('vibesAPI.php', location.href);
		url.searchParams.set('action', action);
		const init = { method: opts.method || 'GET', headers: {} };
		if (init.method === 'GET') { for (const [k, v] of Object.entries(params || {})) if (v !== undefined && v !== null) url.searchParams.set(k, v); }
		else if (opts.form instanceof FormData) init.body = opts.form;
		else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(params || {}); }
		let res, text;
		try { res = await fetch(url, init); text = await res.text(); }
		catch (e) { throw new ApiError('The server could not be reached (' + e.message + ').', 0); }
		let body;
		try { body = JSON.parse(text); }
		catch (e) {
			// nginx answers 413/502/504 with HTML; turn that into a sentence instead of a parse error.
			const why = res.status === 413 ? 'the request was too large for the web server (nginx client_max_body_size)' : 'the server answered with something that is not JSON (HTTP ' + res.status + ')';
			throw new ApiError(action + ' failed: ' + why + '.', res.status, {});
		}
		if (!res.ok || body.success === false) throw new ApiError(body.error || ('HTTP ' + res.status), res.status, body);
		return body;
	}
	const post = (action, params) => api(action, params, { method: 'POST' });

	/* ---- Toasts ----------------------------------------------------------------------- */
	function toast(kind, text, ms) {
		const root = $('vbToasts');
		const el = h('div.vb-toast.' + kind, h('span.vb-dot.' + ({ ok: 'ok', err: 'bad', warn: 'warn', info: 'wait' }[kind] || '')), h('span.t', text),
			h('button.x', { type: 'button', title: 'Dismiss', onclick: () => leave() }, '×'));
		let timer = setTimeout(leave, ms || (kind === 'err' ? 9000 : 4500));
		function leave() { clearTimeout(timer); el.classList.add('leaving'); setTimeout(() => el.remove(), reducedMotion() ? 0 : 200); }
		root.appendChild(el);
		while (root.children.length > 5) root.firstChild.remove();
		return el;
	}
	/** Every failure path funnels here: the sentence reaches the operator, the details the console. */
	function fail(e, prefix) {
		const msg = (prefix ? prefix + ': ' : '') + (e && e.message ? e.message : String(e));
		console.error(e);
		toast('err', msg);
		return msg;
	}

	/* ---- Modals: the theme's .mods-modal-overlay, toggled with .show ------------------ */
	const modalStack = [];
	function openModal(id) { const el = $(id); if (!el) return; el.classList.add('show'); if (!modalStack.includes(id)) modalStack.push(id); const f = qs('input:not([type=hidden]):not([disabled]), textarea, select, button.vb-btn-accent', el); if (f && id !== 'vbSettings') setTimeout(() => f.focus(), 30); }
	function closeModal(id) { const el = $(id); if (!el) return; el.classList.remove('show'); const i = modalStack.indexOf(id); if (i >= 0) modalStack.splice(i, 1); }
	function closeTopModal() {
		const id = modalStack[modalStack.length - 1];
		if (!id) return false;
		if (id === 'vbIntro') return false;      // the intro is a gate, not a dialog; it closes by its own buttons
		closeModal(id); return true;
	}
	function wireModals() {
		for (const ov of qsa('.vb-modal-overlay')) {
			ov.addEventListener('click', (e) => { if (e.target === ov && ov.id !== 'vbIntro') closeModal(ov.id); });
			for (const b of qsa('[data-close]', ov)) b.addEventListener('click', () => closeModal(ov.id));
		}
	}
	/** A yes/no with real copy. Resolves true/false. */
	function confirmDialog(title, text, okLabel) {
		return new Promise((resolve) => {
			$('vbConfirmTitle').textContent = title; $('vbConfirmText').textContent = text;
			const ok = $('vbConfirmOk'); ok.textContent = okLabel || 'Continue';
			const done = (v) => { ok.onclick = null; closeModal('vbConfirm'); resolve(v); };
			ok.onclick = () => done(true);
			for (const b of qsa('#vbConfirm [data-close]')) b.onclick = () => done(false);
			openModal('vbConfirm');
		});
	}

	/* ---- Hugin: clone the ONE server-rendered drawing, never redraw him ---------------- */
	function huginNode(state, size) {
		const src = qs('.vb-hugin-src');
		if (!src) return h('span');
		const el = src.cloneNode(true);
		el.setAttribute('class', 'ai-hugin ' + (state || 'idle'));
		el.setAttribute('width', size || 34); el.setAttribute('height', size || 34);
		if (size) { el.style.width = size + 'px'; el.style.height = size + 'px'; el.style.flex = '0 0 ' + size + 'px'; }
		return el;
	}
	let headerTimer = null;
	function setHugin(state) {
		const src = qs('.vb-hugin-src'); if (!src) return;
		clearTimeout(headerTimer);
		src.setAttribute('class', 'ai-hugin vb-hugin-src ' + state);
		// done/error are one-shot poses: he settles back to idle after they have played.
		if (state === 'done' || state === 'error') headerTimer = setTimeout(() => src.setAttribute('class', 'ai-hugin vb-hugin-src idle'), 1200);
	}

	/* ---- Busy buttons: disabled WITH the reason, restored on done ---------------------- */
	function setBusy(btn, reason) {
		if (!btn) return;
		if (!S.busy.has(btn)) S.busy.set(btn, { html: Array.from(btn.childNodes).map(n => n.cloneNode(true)), title: btn.title });
		btn.disabled = true; btn.title = reason || 'Working…';
		clear(btn); btn.appendChild(h('span.vb-spin')); btn.appendChild(document.createTextNode(' ' + (reason || 'Working…')));
	}
	function clearBusy(btn) {
		if (!btn || !S.busy.has(btn)) return;
		const o = S.busy.get(btn); S.busy.delete(btn);
		clear(btn); for (const n of o.html) btn.appendChild(n); btn.title = o.title; btn.disabled = false;
	}

	Object.assign(Vibes, { $, qs, qsa, h, append, clear, fmtBytes, fmtMs, fmtAgo, fmtTime, debounce, reducedMotion,
		S, emit, subscribe: (fn) => listeners.add(fn), api, post, ApiError, toast, fail,
		openModal, closeModal, closeTopModal, wireModals, confirmDialog, huginNode, setHugin, setBusy, clearBusy });
})();

/* =====================================================================================
   2. Router: the eight states of section 10.3, header chips, the banner
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, S, toast, fail, api, openModal, closeModal } = Vibes;

	/** Which of the eight states are we in? One function, so the page and the tests agree. */
	function pickState(st) {
		const open = st.jobs || [];
		if (open.some(j => j.kind === 'install')) return 'installing';                 // 3
		if (open.some(j => j.kind === 'uninstall')) return 'uninstalling';             // 8 in progress
		if (!st.installed) {
			// 8: "Welcome back" when kept projects prove Vibes was here before. noticeShown is NOT
			// the signal: "Not now" sets it too, and the page must then show the ordinary modal.
			if ((st.projectCount || 0) > 0) return 'deleted';
			return st.ai && st.ai.ok ? 'intro' : 'intro-no-ai';                          // 1 / 2
		}
		if (!st.libs || !st.libs.present) return 'no-libs';                                // 4
		if ((st.projectCount || 0) === 0) return 'empty';                                   // 5
		return 'working';                                                                    // 6 (7 is a banner on top)
	}

	async function refreshState(quiet) {
		try { S.state = (await api('state')); applyState(); }
		catch (e) { if (!quiet) fail(e, 'Could not read the state'); }
		return S.state;
	}

	function applyState() {
		const st = S.state;
		S.pageState = pickState(st);
		document.body.dataset.vbState = S.pageState;
		renderChips(st);
		renderBanner(st);
		const intro = ['intro', 'intro-no-ai', 'deleted', 'installing', 'uninstalling'].includes(S.pageState);
		if (intro) { Vibes.intro.show(S.pageState); } else { closeModal('vbIntro'); }
		Vibes.library.render();
		Vibes.workspace.renderEmptyState();
		Vibes.jobs.syncOpen(st.jobs || []);
		Vibes.emit();
	}

	/* ---- Header chips: pak, game version, model, disk ---------------------------------- */
	function chip(cls, label, value, title) { return h('span.vb-chip.' + cls, { title: title || '' }, label + ' ', h('b', value)); }
	function renderChips(st) {
		const root = clear($('vbChips'));
		if (!st.installed) { root.appendChild(chip('warn', 'Vibes', 'not installed')); return; }
		const pak = st.pak || {};
		root.appendChild(chip(pak.compatible ? 'ok' : 'bad', 'Build Pak', pak.version || 'missing', pak.problem || 'The toolchain Hugin compiles with'));
		if (pak.available && pak.available !== pak.version) root.appendChild(chip('warn', 'update', pak.available, 'A newer Build Pak is available'));
		const libs = st.libs || {};
		root.appendChild(chip(libs.present ? 'ok' : 'warn', 'Valheim', libs.gameVersion || (libs.gameBuild ? 'build ' + libs.gameBuild : (libs.present ? 'harvested' : 'no libraries')), libs.present ? 'Harvested from ' + (libs.sourceWorld || '?') : 'No game libraries harvested yet'));
		const ai = st.ai || {};
		root.appendChild(chip(ai.ok ? 'ok' : 'bad', 'Model', ai.ok ? ai.model : 'none', ai.ok ? ai.provider : ai.reason));
		const d = st.disk || {};
		root.appendChild(chip(d.ok ? 'ok' : 'warn', 'Disk', fmtBytes(d.free) + ' free', 'Free space on the data volume'));
		const open = (st.jobs || []).filter(j => j.kind !== 'turn');
		if (open.length) root.appendChild(chip('busy', open[0].kind, open[0].step || open[0].status, 'Job ' + open[0].id));
	}

	/* ---- Banner (state 7 + runner missing). One action that fixes it, dismissible ------- */
	function renderBanner(st) {
		const b = $('vbBanner'), txt = $('vbBannerText'), act = $('vbBannerAction');
		let kind = '', text = '', action = null, key = '';
		const runner = st.runner || {};
		if (!runner.ok) {
			kind = 'error'; key = 'runner';
			text = 'The runner library is missing' + (runner.missing && runner.missing.length ? ' (' + runner.missing.slice(0, 4).join(', ') + (runner.missing.length > 4 ? ', …' : '') + ')' : '') + '. This server image is incomplete: Vibes can show its state but cannot build or publish until the image is rebuilt or updated.';
		} else if (st.error) {
			kind = 'error'; key = 'state'; text = st.error;
		} else if (st.installed && st.pak && st.pak.problem) {
			kind = st.pak.present ? 'error' : 'warn'; key = 'pak:' + st.pak.problem; text = st.pak.problem;
			action = { label: st.pak.present ? 'Update Build Pak' : 'Re-download Build Pak', run: (btn) => Vibes.jobs.start(btn, 'pakUpdate', {}, 'Updating the Build Pak…') };
		} else if (st.installed && st.pak && st.pak.available && st.pak.available !== st.pak.version) {
			kind = 'info'; key = 'avail:' + st.pak.available; text = 'Build Pak ' + st.pak.available + ' is available (you have ' + st.pak.version + ').';
			action = { label: 'Update', run: (btn) => Vibes.jobs.start(btn, 'pakUpdate', {}, 'Updating the Build Pak…') };
		}
		if (!text || S.dismissedBanner === key) { b.hidden = true; return; }
		b.className = 'vb-banner ' + kind; txt.textContent = text;
		act.hidden = !action;
		if (action) { act.textContent = action.label; act.onclick = () => action.run(act); }
		$('vbBannerClose').onclick = () => { S.dismissedBanner = key; b.hidden = true; };
		b.hidden = false;
	}

	Vibes.router = { pickState, refreshState, applyState };
})();

/* =====================================================================================
   3. Intro modal (states 1, 2, 8) and the install sheet (state 3)
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, S, api, post, toast, fail, openModal, closeModal, setBusy, clearBusy } = Vibes;
	let pakInfo = null, pakErr = null, pollTimer = null, checking = false;

	function show(pageState) {
		openModal('vbIntro');
		const sheet = $('vbInstallSheet'), body = $('vbIntroBody');
		if (pageState === 'installing' || pageState === 'uninstalling') {
			body.hidden = true; sheet.hidden = false;
			const job = (S.state.jobs || []).find(j => j.kind === 'install' || j.kind === 'uninstall');
			if (job) Vibes.installSheet.follow(job);
			return;
		}
		body.hidden = false; sheet.hidden = true;
		$('vbIntroTitle').textContent = pageState === 'deleted' ? 'Welcome back.' : 'I can build mods now.';
		$('vbIntroSub').textContent = pageState === 'deleted' ? 'Hugin’s Vibes was removed from this server. Install it again whenever you like; your kept projects are still here.' : 'Describe a Valheim mod; I write it, compile it here, and publish it to your own catalogue.';
		$('vbIntroRoot').textContent = S.state.root || '/opt/stateful/vibes';
		$('vbIntroDecompile').checked = S.state.decompileDefault !== false;
		renderChecks();
		if (!pakInfo && !pakErr && !checking) lookupPak(false);
		// Section 10.3 state 2: the provider is re-checked live every 5 s while the modal is open.
		clearInterval(pollTimer);
		pollTimer = setInterval(async () => { if (!$('vbIntro').classList.contains('show')) { clearInterval(pollTimer); return; } await Vibes.router.refreshState(true); }, 5000);
	}

	async function lookupPak(force) {
		checking = true; renderChecks();
		try { pakInfo = await api('pakInfo', force ? { force: 1 } : {}); pakErr = null; }
		catch (e) { pakInfo = null; pakErr = e.message; }
		checking = false; renderChecks();
	}

	function setCheck(id, status, ...content) {
		const li = $(id); li.className = 'vb-check ' + status;
		const dot = li.querySelector('.vb-dot'); dot.className = 'vb-dot ' + (status === 'ok' ? 'ok' : status === 'bad' ? 'bad' : 'wait');
		const t = clear(li.querySelector('.vb-check-text')); Vibes.append(t, content);
	}

	/** The three gates. Install is enabled only when the provider AND the disk pass. */
	function renderChecks() {
		const st = S.state, ai = st.ai || {}, disk = (pakInfo && pakInfo.disk) || st.disk || {};
		const size = pakInfo && pakInfo.sizeBytes ? fmtBytes(pakInfo.sizeBytes) : null;
		if (checking) setCheck('vbCheckPak', 'wait', 'Looking up the Build Pak release…');
		else if (pakInfo) setCheck('vbCheckPak', 'ok', h('b', 'Build Pak ' + (pakInfo.version || '')), ' · ', size || 'size unknown', pakInfo.prerelease ? ' · pre-release' : '', h('br'), h('span.vb-muted', 'From ' + (pakInfo.url || st.pakRepo || 'GitHub') + ' → ' + (st.root || '/opt/stateful/vibes') + '/buildpak/'));
		else setCheck('vbCheckPak', 'bad', h('b', 'Build Pak release not reachable.'), ' Size unknown until the release is reachable. ', h('span.vb-muted', pakErr || ''), ' ', h('a', { href: '#', onclick: (e) => { e.preventDefault(); lookupPak(true); } }, 'Retry'));
		const need = disk.required ? fmtBytes(disk.required) : '?';
		if (disk.free === null || disk.free === undefined) setCheck('vbCheckDisk', 'bad', 'Free space could not be read on ', h('b', st.root || '/opt/stateful/vibes'), '.');
		else setCheck('vbCheckDisk', disk.ok ? 'ok' : 'bad', h('b', fmtBytes(disk.free) + ' free'), ' on the data volume · ', need + ' required', ' (2 × pak + 1 GiB)', disk.ok ? '' : ' — free up space before installing.');
		if (ai.ok) setCheck('vbCheckAi', 'ok', h('b', 'AI provider: ' + ai.provider), ' · ' + ai.model);
		else setCheck('vbCheckAi', 'bad', h('b', 'Hugin’s Vibes needs an AI provider.'), ' Add one in ', h('a', { href: 'index.php', target: '_blank', rel: 'noopener' }, 'Server Settings → AI Helper'), '. ', h('span.vb-muted', ai.reason || ''));
		const ok = !!(ai.ok && disk.ok) && !(S.state.jobs || []).length;
		const btn = $('vbIntroInstall'); btn.disabled = !ok;
		btn.title = ok ? 'Download the Build Pak and set everything up' : !ai.ok ? 'Needs an AI provider first' : !disk.ok ? 'Not enough free space' : 'Another job is running';
	}

	async function install() {
		const btn = $('vbIntroInstall');
		setBusy(btn, 'Starting…');
		try {
			const r = await post('install', { decompile: $('vbIntroDecompile').checked, pakSizeBytes: pakInfo && pakInfo.sizeBytes ? pakInfo.sizeBytes : undefined });
			toast('ok', 'Installing Hugin’s Vibes (job ' + r.jobId + ').');
			await Vibes.router.refreshState(true);
		} catch (e) { fail(e, 'Install did not start'); }
		clearBusy(btn); renderChecks();
	}

	async function later() {
		try { await post('dismissNotice', {}); } catch (e) { /* closing matters more than the flag */ }
		closeModal('vbIntro'); clearInterval(pollTimer);
		S.state.noticeShown = true;
		// Without Vibes installed there is no workspace to show; the page says so in its place.
		Vibes.workspace.renderEmptyState();
	}

	function wire() { $('vbIntroInstall').onclick = install; $('vbIntroLater').onclick = later; }
	Vibes.intro = { show, wire, renderChecks, lookupPak, get pakInfo() { return pakInfo; } };
})();

/* ---- The install / uninstall progress sheet (state 3): survives a reload via the job row --- */
(() => {
	const { $, h, clear, S, post, toast, fail, api, closeModal } = Vibes;
	let stream = null, job = null, steps = [], logLines = [];

	function follow(j) {
		if (job && job.id === j.id && stream) return;
		stop(); job = j; steps = []; logLines = [];
		$('vbSheetTitle').textContent = j.kind === 'uninstall' ? 'Removing Hugin’s Vibes' : 'Installing Hugin’s Vibes';
		$('vbSheetStep').textContent = j.step || 'Starting…'; $('vbSheetDetail').textContent = '';
		$('vbSheetError').hidden = true; $('vbSheetRetry').hidden = true; $('vbSheetClose').hidden = true; $('vbSheetCancel').hidden = false;
		clear($('vbSheetSteps')); $('vbSheetLog').textContent = '';
		setBar(j.progress);
		const hug = $('vbInstallSheet').querySelector('.ai-hugin'); if (hug) hug.setAttribute('class', 'ai-hugin vb-sheet-hugin working');
		stream = new Vibes.Stream({ job: j.id }, onEvent, 0);
	}
	function stop() { if (stream) stream.close(); stream = null; }
	function setBar(p) {
		const bar = $('vbSheetBar'); bar.classList.remove('indeterminate', 'fail', 'done');
		if (p === -1 || p === null || p === undefined) { bar.classList.add('indeterminate'); bar.style.width = ''; }
		else bar.style.width = Math.max(0, Math.min(100, Number(p))) + '%';
	}
	function stepItem(label, cls) {
		let li = steps.find(s => s.label === label);
		if (!li) { li = { label, el: h('li', label) }; steps.push(li); $('vbSheetSteps').appendChild(li.el); }
		for (const s of steps) s.el.className = s === li ? cls : (s.el.className === 'fail' ? 'fail' : 'done');
		return li;
	}
	function onEvent(ev) {
		if (ev.type !== 'job') logLines.push(JSON.stringify(ev)); $('vbSheetLog').textContent = logLines.slice(-400).join('\n');
		if (ev.type === 'status') {
			if (ev.step) { $('vbSheetStep').textContent = ev.step; stepItem(ev.step, 'active'); }
			if (ev.detail) $('vbSheetDetail').textContent = ev.detail;
			setBar(ev.progress);
		} else if (ev.type === 'error') finish(false, ev.error);
		else if (ev.type === 'done') finish(true);
		else if (ev.type === 'job' && ev.status && !['queued', 'running'].includes(ev.status)) {
			if (ev.status === 'done') finish(true); else finish(false, (ev.job && ev.job.error) || ('The job ended as ' + ev.status + '.'));
		}
	}
	async function finish(ok, error) {
		stop();
		const hug = $('vbInstallSheet').querySelector('.ai-hugin'); if (hug) hug.setAttribute('class', 'ai-hugin vb-sheet-hugin ' + (ok ? 'done' : 'error'));
		$('vbSheetCancel').hidden = true;
		if (ok) {
			for (const s of steps) s.el.className = 'done';
			$('vbSheetBar').classList.remove('indeterminate'); $('vbSheetBar').classList.add('done'); $('vbSheetBar').style.width = '100%';
			$('vbSheetStep').textContent = job.kind === 'uninstall' ? 'Removed.' : 'Installed. Hugin is ready.';
			toast('ok', job.kind === 'uninstall' ? 'Hugin’s Vibes was removed.' : 'Hugin’s Vibes is installed.');
			setTimeout(async () => { await Vibes.router.refreshState(true); if (!['installing', 'uninstalling'].includes(S.pageState)) closeModal('vbIntro'); }, 900);
		} else {
			const cur = steps.find(s => s.el.className === 'active'); if (cur) cur.el.className = 'fail';
			$('vbSheetBar').classList.remove('indeterminate'); $('vbSheetBar').classList.add('fail');
			$('vbSheetError').hidden = false; $('vbSheetError').textContent = (cur ? 'Failed at “' + cur.label + '”: ' : 'Failed: ') + (error || 'unknown error');
			$('vbSheetRetry').hidden = job.kind !== 'install'; $('vbSheetClose').hidden = false;
			toast('err', 'The ' + job.kind + ' failed: ' + (error || 'unknown error'));
			await Vibes.router.refreshState(true);
		}
	}
	function wire() {
		$('vbSheetCopy').onclick = async () => { try { await navigator.clipboard.writeText(logLines.join('\n')); toast('ok', 'Log copied.'); } catch (e) { fail(e, 'Could not copy'); } };
		$('vbSheetRetry').onclick = async () => { try { const r = await post('install', { decompile: $('vbIntroDecompile').checked }); toast('info', 'Retrying (job ' + r.jobId + ').'); await Vibes.router.refreshState(true); } catch (e) { fail(e, 'Retry did not start'); } };
		$('vbSheetClose').onclick = () => { $('vbInstallSheet').hidden = true; $('vbIntroBody').hidden = false; Vibes.intro.renderChecks(); };
		$('vbSheetCancel').onclick = async () => {
			if (!job || !(await Vibes.confirmDialog('Cancel the ' + job.kind + '?', 'The job stops at its current step. Install is resumable: running it again skips what already finished.', 'Cancel it'))) return;
			try { await post('cancel', { jobId: job.id }); } catch (e) { fail(e, 'Could not cancel'); }
		};
	}
	Vibes.installSheet = { follow, stop, wire };
})();

/* =====================================================================================
   4. Library (left rail): search, cards with a progress ring, + New Vibe
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, fmtAgo, S, api, toast, fail, openModal } = Vibes;

	async function load() {
		try { S.projects = (await api('projects')).projects || []; }
		catch (e) { S.projects = []; if (S.state && S.state.installed) fail(e, 'Could not list the Vibes'); }
		render();
	}

	function render() {
		const root = clear($('vbLibList'));
		const st = S.state || {};
		const list = S.projects.filter(p => !S.filter || (p.name + ' ' + p.namespace + ' ' + p.description).toLowerCase().includes(S.filter));
		$('vbBtnNewVibe').disabled = !st.installed || !(st.libs && st.libs.present);
		$('vbBtnNewVibe').title = !st.installed ? 'Install Hugin’s Vibes first' : !(st.libs && st.libs.present) ? 'Harvest the game libraries first' : 'Start a new mod';
		if (!list.length) {
			root.appendChild(h('div.vb-lib-empty', S.projects.length ? 'No Vibe matches “' + S.filter + '”.' : st.installed ? 'No Vibes yet. Press + New Vibe, or pick a starter idea.' : 'Nothing here until Hugin’s Vibes is installed.'));
		}
		for (const p of list) root.appendChild(card(p));
		$('vbLibFoot').textContent = S.projects.length + ' vibe' + (S.projects.length === 1 ? '' : 's') + ' · ' + fmtBytes(S.projects.reduce((a, p) => a + (p.size || 0), 0));
	}

	function card(p) {
		const active = S.project && S.project.id === p.id;
		const el = h('div.vb-lib-card' + (active ? '.active' : ''), { role: 'listitem', tabindex: 0, dataset: { id: p.id },
			onclick: () => Vibes.workspace.select(p.id), onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); Vibes.workspace.select(p.id); } } },
			h('div.vb-lib-card-name', { title: p.namespace + '-' + p.name }, p.name),
			p.openJob ? ring(p.openJob) : h('span.vb-lib-card-ver', p.version || ''),
			h('div.vb-lib-card-meta',
				h('span.vb-pill.' + (p.status || 'draft'), p.status || 'draft'),
				p.worlds && p.worlds.length ? h('span', p.worlds.length + ' world' + (p.worlds.length === 1 ? '' : 's')) : null,
				p.openJob ? h('span', { title: p.openJob.step || '' }, p.openJob.kind + '…') : h('span', fmtAgo(p.updated))));
		return el;
	}
	function ring(job) {
		const r = h('span.vb-ring', { title: job.kind + (job.step ? ': ' + job.step : '') });
		if (job.progress === -1 || !job.progress) r.classList.add('indeterminate'); else r.style.setProperty('--p', job.progress);
		return r;
	}

	function wire() {
		$('vbLibSearch').addEventListener('input', Vibes.debounce((e) => { S.filter = e.target.value.trim().toLowerCase(); render(); }, 120));
		$('vbBtnNewVibe').onclick = () => Vibes.newVibe.open();
		$('vbBtnLibrary').onclick = () => toggleDrawer();
		$('vbLibraryScrim').onclick = () => toggleDrawer(false);
	}
	function toggleDrawer(force) {
		const lib = $('vbLibrary'), open = force === undefined ? !lib.classList.contains('open') : force;
		lib.classList.toggle('open', open); $('vbLibraryScrim').hidden = !open;
	}
	Vibes.library = { load, render, wire, toggleDrawer };
})();

/* =====================================================================================
   5. SSE client: EventSource on vibesStream.php, resuming from the last seq it rendered
   ===================================================================================== */
(() => {
	class Stream {
		/** target = {job:id} | {session:id}; onEvent gets every parsed frame; after = last seq seen. */
		constructor(target, onEvent, after) {
			this.target = target; this.onEvent = onEvent; this.after = after || 0;
			this.closed = false; this.retry = 0; this.es = null;
			this.open();
		}
		url() {
			const u = new URL('vibesStream.php', location.href);
			if (this.target.job) u.searchParams.set('job', this.target.job); else u.searchParams.set('session', this.target.session);
			u.searchParams.set('after', this.after);
			return u.toString();
		}
		open() {
			if (this.closed) return;
			this.es = new EventSource(this.url());
			this.es.onmessage = (m) => {
				let ev; try { ev = JSON.parse(m.data); } catch (e) { return; }
				if (ev.seq) this.after = Math.max(this.after, ev.seq);
				this.retry = 0;
				if (ev.type === 'eos') { this.es.close(); this.es = null; this.onEvent(ev); return; }
				this.onEvent(ev);
			};
			// The browser reconnects on its own but from the ORIGINAL url (old `after`), which would
			// replay frames. So we close and reopen ourselves with the current seq, backing off.
			this.es.onerror = () => {
				if (this.closed || !this.es) return;
				this.es.close(); this.es = null;
				const wait = Math.min(15000, 500 * Math.pow(2, this.retry++));
				setTimeout(() => this.open(), wait);
			};
		}
		close() { this.closed = true; if (this.es) { this.es.close(); this.es = null; } }
	}
	Vibes.Stream = Stream;
})();

/* =====================================================================================
   6. Jobs: start one from a button (disabled WITH the reason), track the open ones
   ===================================================================================== */
(() => {
	const { $, S, post, toast, fail, setBusy, clearBusy } = Vibes;
	const watchers = new Map();   // jobId -> {stream, btn, kind, projectId}
	let pollTimer = null;

	/** POST an action that answers {jobId}; the button sleeps until the job is over. */
	async function start(btn, action, params, reason, onDone) {
		if (btn) setBusy(btn, reason || 'Working…');
		let r;
		try { r = await post(action, params || {}); }
		catch (e) { clearBusy(btn); fail(e, labelFor(action) + ' did not start'); return null; }
		toast('info', labelFor(action) + ' started (job ' + r.jobId + ').');
		track(r.job || { id: r.jobId, kind: action, project_id: params && params.id }, btn, onDone);
		await Vibes.router.refreshState(true);
		return r;
	}
	function labelFor(a) { return ({ build: 'Build', package: 'Package', publish: 'Publish', unpublish: 'Unpublish', push: 'Push', harvest: 'Harvest', pakUpdate: 'Build Pak update', gc: 'Cleanup', uninstall: 'Removal', install: 'Install' })[a] || a; }

	/** Follow a job's log until done/error, then release the button and refresh what it touched. */
	function track(job, btn, onDone) {
		if (watchers.has(job.id)) { if (btn) watchers.get(job.id).btn = btn; return; }
		const w = { job, btn, lastStatus: null };
		w.stream = new Vibes.Stream({ job: job.id }, (ev) => onJobEvent(w, ev), 0);
		watchers.set(job.id, w);
		w.onDone = onDone;
		Vibes.workspace.showJob(job);
	}
	function onJobEvent(w, ev) {
		if (ev.type === 'status') { w.job.step = ev.step || w.job.step; w.job.progress = ev.progress; Vibes.workspace.showJob(w.job); w.lastStatus = ev; }
		else if (ev.type === 'build' && S.project && S.project.id === w.job.project_id) Vibes.buildTab.applyBuildEvent(ev);
		else if (ev.type === 'error') finish(w, false, ev.error);
		else if (ev.type === 'done') finish(w, true);
		else if (ev.type === 'job' && ev.status && !['queued', 'running'].includes(ev.status)) finish(w, ev.status === 'done', (ev.job && ev.job.error) || ('ended as ' + ev.status));
	}
	async function finish(w, ok, error) {
		if (!watchers.has(w.job.id)) return;
		w.stream.close(); watchers.delete(w.job.id);
		clearBusy(w.btn);
		if (ok) toast('ok', labelFor(w.job.kind) + ' finished' + (w.lastStatus && w.lastStatus.step ? ': ' + w.lastStatus.step : '') + '.');
		else toast('err', labelFor(w.job.kind) + ' failed: ' + (error || 'unknown error'));
		Vibes.workspace.showJob(null, w.job);
		if (w.onDone) { try { await w.onDone(ok); } catch (e) { console.error(e); } }
		await Vibes.router.refreshState(true);
		await Vibes.library.load();
		if (S.project && S.project.id === w.job.project_id) await Vibes.workspace.reload();
	}

	/** Called with the server's open jobs on every state refresh: adopt the ones we are not watching. */
	function syncOpen(open) {
		for (const j of open) if (!['install', 'uninstall', 'turn'].includes(j.kind) && !watchers.has(j.id)) track(j, null);
		clearInterval(pollTimer);
		if (open.length) pollTimer = setInterval(() => Vibes.router.refreshState(true), 5000);
	}
	function openFor(projectId) { for (const w of watchers.values()) if (w.job.project_id === projectId) return w.job; return (S.state.jobs || []).find(j => j.project_id === projectId) || null; }

	/** Buttons with data-job="kind" on the current project. */
	function wire() {
		for (const btn of Vibes.qsa('[data-job]')) {
			const kind = btn.dataset.job;
			btn.addEventListener('click', () => {
				const needsProject = ['build', 'package', 'publish'].includes(kind);
				if (needsProject && !S.project) return toast('warn', 'Pick a Vibe first.');
				const params = needsProject ? { id: S.project.id } : {};
				if (kind === 'build') params.configuration = $('vbBuildConfig').value;
				if (kind === 'harvest' && S.settings) { const w = Vibes.qs('[data-setting="libs.source_world"]').value; if (w) params.world = w; }
				const action = kind === 'pak-update' ? 'pakUpdate' : kind;
				const reasons = { build: 'Building…', package: 'Packaging…', publish: 'Publishing…', harvest: 'Harvesting…', 'pak-update': 'Updating the pak…', gc: 'Cleaning up…' };
				if (kind === 'publish') return Vibes.packageTab.publish(btn);
				start(btn, action, params, reasons[kind]);
			});
		}
	}
	Vibes.jobs = { start, track, syncOpen, openFor, wire, labelFor };
})();

/* =====================================================================================
   7. Workspace: selecting a Vibe, the head, the tabs, the empty states, the context rail
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, fmtAgo, S, api, post, toast, fail, huginNode } = Vibes;
	let notesDirty = false;

	/** The card that stands in for the workspace in states 4, 5, "not installed" and "nothing picked". */
	function renderEmptyState() {
		const st = S.state || {}, root = clear($('vbWsEmpty'));
		if (S.project) { root.hidden = true; $('vbWsProject').hidden = false; return; }
		root.hidden = false; $('vbWsProject').hidden = true;
		if (!st.installed) {
			root.appendChild(h('div.vb-empty', huginNode('idle', 72), h('h2', 'Hugin’s Vibes is not installed'), h('p', 'This room is empty until the Build Pak is downloaded. Nothing else on your server changes.'),
				h('button.vb-btn.vb-btn-accent', { type: 'button', onclick: () => Vibes.intro.show(S.pageState) }, 'Show the introduction')));
			return;
		}
		if (S.pageState === 'no-libs') {
			const worlds = st.worldsWithServerFiles || [];
			root.appendChild(h('div.vb-empty', huginNode('idle', 72), h('h2', 'No game libraries yet'),
				h('p', 'To read Valheim’s code and compile against it I need the dedicated server files of at least one world. None has them yet — two ways to fix that:'),
				h('div.vb-remedies',
					h('div.vb-remedy', h('h4', 'Create a world'), h('p', 'Adding a world on the dashboard downloads the dedicated server files as part of creating it. Then come back and press Harvest.'), h('a.vb-btn.vb-btn-accent.vb-btn-sm', { href: 'new_world.php', target: '_blank', rel: 'noopener' }, 'Add World')),
					h('div.vb-remedy', h('h4', 'Download the server files'), h('p', worlds.length ? 'Worlds with server files: ' + worlds.join(', ') + '. Run the harvest to copy their assemblies here.' : 'Run Update on an existing world from the dashboard; that fetches the dedicated server files. Then harvest.'),
						h('button.vb-btn.vb-btn-ghost.vb-btn-sm', { type: 'button', disabled: !worlds.length, title: worlds.length ? '' : 'No world has server files yet', onclick: (e) => Vibes.jobs.start(e.currentTarget, 'harvest', worlds.length ? { world: worlds[0] } : {}, 'Harvesting…') }, '↻ Harvest now')))));
			return;
		}
		if (!S.projects.length) {
			const starters = [
				['NoMovementPenalty', 'Carry weight without the slowdown.', 'Remove the movement speed penalty from carried weight entirely, keeping everything else about encumbrance.'],
				['FastPortals', 'Portals that do not make you wait.', 'Make portal travel instant: skip the fade-in delay and the "connecting" wait, configurable in BepInEx config.'],
				['StaminaTweaks', 'Your stamina, your rules.', 'Add config entries for stamina regen delay and regen rate multipliers, applied via Harmony to the player’s stamina logic.'],
			];
			root.appendChild(h('div.vb-empty', huginNode('idle', 72), h('h2', 'What shall we build?'), h('p', 'Describe a mod in a sentence and I take it from there. Or start from one of these:'),
				h('div.vb-starters', starters.map(([name, tag, desc]) => h('button.vb-starter', { type: 'button', onclick: () => Vibes.newVibe.open({ name, description: desc }) }, h('b', name), h('span', tag), h('span.vb-muted', desc))))));
			return;
		}
		root.appendChild(h('div.vb-empty', huginNode('idle', 72), h('h2', 'Pick a Vibe'), h('p', 'Choose one from the library, or start a new one.')));
	}

	async function select(id) {
		if (S.project && S.project.id === id) return;
		Vibes.vibeTab.detach();
		S.project = { id, loading: true }; S.session = null;
		Vibes.library.render(); Vibes.library.toggleDrawer(false);
		await reload();
		Vibes.tabs.show('vibe');
	}
	async function reload() {
		if (!S.project) return;
		try { S.project = (await api('project', { id: S.project.id })).project; }
		catch (e) { fail(e, 'Could not open the Vibe'); S.project = null; renderEmptyState(); Vibes.library.render(); return; }
		renderHead(); renderContext(); renderEmptyState();
		Vibes.codeTab.render(); Vibes.buildTab.render(); Vibes.packageTab.render(); Vibes.historyTab.render();
		const sessions = S.project.sessions || [];
		const keep = S.session && sessions.find(s => s.id === S.session.id);
		await Vibes.vibeTab.openSession(keep || sessions[0] || null);
		showJob(S.project.openJob || Vibes.jobs.openFor(S.project.id));
		Vibes.emit();
	}
	function deselect() { Vibes.vibeTab.detach(); S.project = null; S.session = null; renderEmptyState(); Vibes.library.render(); }

	function renderHead() {
		const p = S.project;
		$('vbPName').textContent = p.name; $('vbPNs').textContent = p.namespace; $('vbPVer').textContent = 'v' + (p.version || '0.1.0');
		const st = $('vbPStatus'); st.textContent = p.status || 'draft'; st.className = 'vb-pill ' + (p.status || 'draft');
		document.title = p.name + ' · Hugin’s Vibes - PhValheim';
	}

	/** The job strip in the head + the composer lock. job=null clears it (finished=the job that ended). */
	function showJob(job, finished) {
		if (job && S.project && job.project_id !== S.project.id && job.kind !== 'turn') job = null;
		const strip = $('vbWsJob');
		if (!job || !S.project) { strip.hidden = true; Vibes.vibeTab.setLocked(null); return; }
		strip.hidden = false;
		const ring = $('vbWsRing'); ring.classList.toggle('indeterminate', !job.progress || job.progress < 0); if (job.progress > 0) ring.style.setProperty('--p', job.progress);
		$('vbWsJobText').textContent = job.kind + (job.step ? ' · ' + job.step : '');
		$('vbWsStop').onclick = async () => { try { await post('cancel', { jobId: job.id }); toast('warn', 'Stopping ' + job.kind + '…'); } catch (e) { fail(e, 'Could not stop'); } };
		Vibes.vibeTab.setLocked(job);
	}

	/* ---- Context rail: notes, sessions, build, published, disk ------------------------- */
	function kv(root, rows) {
		clear(root);
		for (const [k, v, cls] of rows) { root.appendChild(h('span.k', k)); root.appendChild(h('span.v' + (cls ? '.' + cls : ''), v === null || v === undefined || v === '' ? '—' : String(v))); }
	}
	function renderContext() {
		const p = S.project, lb = p.lastBuild;
		kv($('vbCtxBuild'), lb ? [['Result', lb.ok ? '✓ ok' : '✕ failed', lb.ok ? 'ok' : 'bad'], ['Errors', (lb.errors || []).length], ['Warnings', lb.warnings || 0], ['When', fmtAgo(lb.at)], ['Took', Vibes.fmtMs(lb.ms)]] : [['Result', 'never built', 'muted']]);
		const cat = p.catalog || [];
		kv($('vbCtxPublished'), cat.length ? [['Latest', cat[0].version], ['Versions', cat.length], ['Worlds', (p.worlds || []).length ? p.worlds.join(', ') : 'none']] : [['Published', 'not yet', 'muted']]);
		kv($('vbCtxDisk'), [['Project', fmtBytes(p.size)], ['GUID', p.guid]]);
		if (!notesDirty) loadNotes();
		renderSessions();
	}
	async function loadNotes() {
		try { $('vbNotes').value = (await api('notes', { id: S.project.id })).content || ''; notesDirty = false; $('vbNotesSave').disabled = true; }
		catch (e) { $('vbNotes').value = ''; }
	}
	async function saveNotes() {
		const btn = $('vbNotesSave'); btn.disabled = true;
		try { const r = await post('saveNotes', { id: S.project.id, content: $('vbNotes').value }); notesDirty = false; toast('ok', 'Notes saved' + (r.commit && r.commit.ok && r.commit.hash ? ' and committed (' + r.commit.hash.slice(0, 7) + ')' : '') + '.'); }
		catch (e) { fail(e, 'Notes not saved'); btn.disabled = false; }
	}
	function renderSessions() {
		const root = clear($('vbSessions')), list = (S.project && S.project.sessions) || [];
		if (!list.length) root.appendChild(h('div.vb-muted', 'No sessions yet — your first message starts one.'));
		for (const s of list) {
			const active = S.session && S.session.id === s.id;
			root.appendChild(h('div.vb-session' + (active ? '.active' : '') + (s.status === 'running' ? '.running' : ''), { onclick: () => Vibes.vibeTab.openSession(s) },
				h('span.t', s.title || 'Untitled session'),
				h('button.rn', { type: 'button', title: 'Rename', onclick: (e) => { e.stopPropagation(); rename(s); } }, '✎'),
				h('span.m', s.turns + ' turn' + (s.turns === 1 ? '' : 's') + ' · ' + fmtAgo(s.updated))));
		}
	}
	async function rename(s) {
		const title = prompt('Session title', s.title || '');
		if (title === null) return;
		try { await post('renameSession', { session: s.id, title }); await reload(); } catch (e) { fail(e, 'Rename failed'); }
	}
	async function newSession() {
		if (!S.project) return;
		try { const r = await post('newSession', { project: S.project.id }); S.project.sessions = [r.session].concat(S.project.sessions || []); await Vibes.vibeTab.openSession(r.session); renderSessions(); }
		catch (e) { fail(e, 'Could not start a session'); }
	}

	/** Below 1200 px the context rail lives inside the Context tab; above it, in the right column. */
	function placeContext() {
		const inner = $('vbContextInner'), narrow = window.innerWidth < 1200;
		const target = narrow ? $('vbContextSlot') : $('vbContext');
		if (inner.parentElement !== target) target.appendChild(inner);
		if (!narrow && Vibes.tabs.current() === 'context') Vibes.tabs.show('vibe');
	}

	function wire() {
		$('vbNotes').addEventListener('input', () => { notesDirty = true; $('vbNotesSave').disabled = false; });
		$('vbNotesSave').onclick = saveNotes;
		$('vbNewSession').onclick = newSession;
		window.addEventListener('resize', Vibes.debounce(placeContext, 100));
		placeContext();
	}
	Vibes.workspace = { renderEmptyState, select, reload, deselect, showJob, renderContext, renderSessions, wire, placeContext };
})();

/* ---- Tabs ------------------------------------------------------------------------------ */
(() => {
	const { $, qsa } = Vibes;
	function show(name) {
		for (const t of qsa('#vbTabs .vb-tab')) t.classList.toggle('active', t.dataset.tab === name);
		for (const p of qsa('.vb-panels .vb-panel')) p.classList.toggle('active', p.dataset.panel === name);
	}
	function current() { const t = Vibes.qs('#vbTabs .vb-tab.active'); return t ? t.dataset.tab : 'vibe'; }
	function wire() { for (const t of qsa('#vbTabs .vb-tab')) t.addEventListener('click', () => show(t.dataset.tab)); }
	Vibes.tabs = { show, current, wire };
})();

/* =====================================================================================
   8. The Vibe tab: transcript, forge rail, composer. Events of section 7.3 drive it all.
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtMs, fmtTime, S, api, post, toast, fail, huginNode, setHugin } = Vibes;
	let stream = null, lastSeq = 0, running = false, cur = null, chips = new Map(), turnNo = 0;

	const TOOL_ICON = { read_file: '☰', write_file: '✎', edit_file: '✎', list_files: '☷', search: '⌕', grep: '⌕', build: '⚙', dotnet: '⚙', shell: '›_', run: '›_', git: '⎇', note: '✍', decompiled: '⌘', read_game_source: '⌘', search_game_source: '⌕', manifest: '☷', package: '⚑' };
	function iconFor(name) { for (const k of Object.keys(TOOL_ICON)) if ((name || '').toLowerCase().includes(k)) return TOOL_ICON[k]; return '•'; }
	function shortArg(args) {
		if (!args || typeof args !== 'object') return '';
		const v = args.path || args.file || args.pattern || args.query || args.command || args.cmd || args.text || Object.values(args)[0];
		return v === undefined ? '' : String(v).replace(/\s+/g, ' ').slice(0, 80);
	}

	function detach() { if (stream) stream.close(); stream = null; running = false; cur = null; chips.clear(); setHugin('idle'); }

	async function openSession(s) {
		detach();
		S.session = s;
		clear($('vbTranscript')); clear($('vbForgeList')); lastSeq = 0; turnNo = 0; $('vbForgeMeta').textContent = '';
		Vibes.workspace.renderSessions();
		if (!s) { $('vbTranscript').appendChild(emptyHint()); $('vbForgeList').appendChild(h('div.vb-forge-empty', 'Tool calls tick in here while Hugin works: reads, edits, builds, commits.')); $('vbModelInput').value = (S.project && S.project.model) || ''; return; }
		try {
			const r = await api('transcript', { session: s.id, after: 0 });
			for (const ev of r.events || []) handle(ev, false);
			if (!(r.events || []).length) $('vbTranscript').appendChild(emptyHint());
			if (!$('vbForgeList').children.length) $('vbForgeList').appendChild(h('div.vb-forge-empty', 'Tool calls tick in here while Hugin works: reads, edits, builds, commits.'));
			if (r.job && r.job.kind === 'turn' && ['queued', 'running'].includes(r.job.status)) beginRun(r.job);
		} catch (e) { fail(e, 'Could not load the transcript'); }
		$('vbModelInput').value = (S.project && S.project.model) || '';
		stream = new Vibes.Stream({ session: s.id }, (ev) => handle(ev, true), lastSeq);
		scrollEnd();
	}
	function emptyHint() {
		return h('div.vb-msg.system', h('div.vb-bubble', 'New session. Hugin knows this project’s files, its notes and the game libraries. Describe what you want; he reads, writes, builds and fixes.'));
	}
	function scrollEnd() { const t = $('vbTranscript'); t.scrollTop = t.scrollHeight; }

	/* ---- Composer -------------------------------------------------------------------- */
	function setLocked(job) {
		const hint = $('vbComposerHint'), send = $('vbComposerSend'), ta = $('vbComposerText'), stop = $('vbComposerStop');
		const st = S.state || {};
		let reason = '';
		if (!st.installed) reason = 'Vibes is not installed.';
		else if (!(st.ai && st.ai.ok)) reason = 'No AI provider: ' + (st.ai && st.ai.reason || '');
		else if (!(st.runner && st.runner.ok)) reason = 'The runner library is missing; turns cannot run.';
		else if (job && job.kind === 'turn') reason = 'Hugin is working on this Vibe' + (job.step ? ': ' + job.step : '') + '…';
		else if (job) reason = 'A ' + job.kind + ' job is running on this Vibe' + (job.step ? ' (' + job.step + ')' : '') + '. Wait for it before sending.';
		send.disabled = !!reason; ta.disabled = !!reason && !(job && job.kind === 'turn'); // during a turn you may type ahead
		hint.textContent = reason || 'Ctrl/Cmd+Enter sends · Esc closes dialogs'; hint.className = 'vb-composer-hint' + (job ? ' busy' : reason ? ' err' : '');
		send.title = reason || 'Send to Hugin';
		stop.hidden = !(job && job.kind === 'turn');
		stop.onclick = async () => { try { await post('cancel', { jobId: job.id }); toast('warn', 'Stopping Hugin…'); } catch (e) { fail(e, 'Could not stop'); } };
	}
	async function send() {
		const ta = $('vbComposerText'), text = ta.value.trim();
		if (!text || !S.project) return;
		if (!S.session) { try { const r = await post('newSession', { project: S.project.id }); S.project.sessions = [r.session].concat(S.project.sessions || []); await openSession(r.session); } catch (e) { return fail(e, 'Could not start a session'); } }
		const model = $('vbModelInput').value.trim();
		if (model !== (S.project.model || '')) { try { await post('updateProject', { id: S.project.id, model }); S.project.model = model; } catch (e) { fail(e, 'Model not saved'); } }
		$('vbComposerSend').disabled = true;
		try {
			const r = await post('turn', { session: S.session.id, text });
			ta.value = '';
			// The runner writes the `user` event into the transcript; we show it at once so the
			// send feels instant, and skip the echo when it arrives (same text, next user event).
			appendUser(text, Date.now() / 1000, true); pendingEcho = text;
			if (r.session) { S.session = r.session; const i = (S.project.sessions || []).findIndex(s => s.id === r.session.id); if (i >= 0) S.project.sessions[i] = r.session; Vibes.workspace.renderSessions(); }
			beginRun(r.job || { id: r.jobId, kind: 'turn', project_id: S.project.id });
		} catch (e) { fail(e, 'Hugin did not take the message'); setLocked(Vibes.jobs.openFor(S.project.id)); }
	}
	let pendingEcho = null;
	function beginRun(job) {
		running = true; turnNo++; cur = null; chips.clear();
		for (const e of $('vbForgeList').querySelectorAll('.vb-forge-empty')) e.remove();
		setHugin('thinking'); setLocked(job); Vibes.workspace.showJob(job);
		$('vbForgeList').appendChild(h('div.vb-forge-turn', 'turn ' + turnNo + ' · ' + fmtTime(Date.now())));
		Vibes.library.load();
	}
	function endRun(ok) {
		running = false; if (cur) finalizeAssistant();
		setHugin(ok ? 'done' : 'error'); setLocked(null); Vibes.workspace.showJob(null);
		$('vbComposerText').focus();
		Vibes.workspace.reload();
	}

	/* ---- Event handling: one function per event type, the same for history and live --- */
	function handle(ev, live) {
		if (ev.seq) lastSeq = Math.max(lastSeq, ev.seq);
		switch (ev.type) {
			case 'user':      finalizeAssistant(); /* a bubble left open by an earlier turn (one that ended in an error) must not swallow this turn's tokens */ if (pendingEcho !== null && ev.text === pendingEcho) { pendingEcho = null; break; } appendUser(ev.text, ev.t, live); if (!live) { turnNo++; $('vbForgeList').appendChild(h('div.vb-forge-turn', 'turn ' + turnNo + (ev.t ? ' · ' + fmtTime(ev.t) : ''))); } break;
			case 'token':     if (live && !running) beginRun(Vibes.jobs.openFor(S.project.id) || { kind: 'turn', project_id: S.project.id }); appendToken(ev.text); if (live) setHugin('speaking'); break;
			case 'assistant': if (cur) { cur.text = ev.text; finalizeAssistant(); } else appendAssistant(ev.text, ev.t); break;
			case 'tool_call': if (live) setHugin('working'); addChip(ev); break;
			case 'tool_result': updateChip(ev); if (live) setHugin('thinking'); break;
			case 'file_changed': addFileChange(ev); break;
			case 'build':     addBuild(ev); if (S.project) Vibes.buildTab.applyBuildEvent(ev); break;
			case 'commit':    $('vbForgeList').appendChild(h('div', h('span.vb-fcommit', h('code', String(ev.hash || '').slice(0, 7)), ' ', ev.message || 'commit'))); break;
			case 'status':    $('vbForgeList').appendChild(h('div.vb-fstatus', ev.step || ev.detail || '')); break;
			case 'error':     finalizeAssistant(); appendError(ev.error, ev.t); if (live) endRun(false); break;
			case 'done':      if (ev.usage) $('vbForgeMeta').textContent = (ev.usage.in || 0) + '→' + (ev.usage.out || 0) + ' tok' + (ev.rounds ? ' · ' + ev.rounds + ' rounds' : ''); if (live) endRun(true); break;
			case 'job':       if (live && running && ev.status && !['queued', 'running'].includes(ev.status)) { if (ev.status !== 'done') appendError((ev.job && ev.job.error) || ('The turn ended as ' + ev.status + '.'), Date.now() / 1000); endRun(ev.status === 'done'); } else if (live && !running && ev.status === 'running' && ev.job && ev.job.kind === 'turn') beginRun(ev.job); break;
			default: break;
		}
		if (live) scrollEnd();
		forgeScroll();
	}
	function forgeScroll() { const f = $('vbForgeList'); f.scrollTop = f.scrollHeight; }

	/* ---- Bubbles ---------------------------------------------------------------------- */
	function appendUser(text, t, live) { $('vbTranscript').appendChild(h('div.vb-msg.user', h('div.vb-msg-avatar', 'you'), h('div', h('div.vb-bubble', text), t ? h('div.vb-msg-time', fmtTime(t)) : null))); if (live) scrollEnd(); }
	function appendAssistant(text, t) {
		const body = h('div.vb-bubble.vb-md');
		Vibes.markdown.render(text || '', body);
		$('vbTranscript').appendChild(h('div.vb-msg.assistant', h('div.vb-msg-avatar', huginNode('idle', 22)), h('div', body, t ? h('div.vb-msg-time', fmtTime(t)) : null)));
	}
	function appendError(text, t) { $('vbTranscript').appendChild(h('div.vb-msg.error', h('div.vb-msg-avatar', huginNode('error', 22)), h('div', h('div.vb-bubble', text || 'Something went wrong.'), t ? h('div.vb-msg-time', fmtTime(t)) : null))); }
	function appendToken(text) {
		if (!cur) {
			cur = { text: '', body: h('div.vb-bubble.vb-md'), pre: h('span') };
			cur.body.appendChild(cur.pre); cur.body.appendChild(h('span.vb-cursor'));
			$('vbTranscript').appendChild(h('div.vb-msg.assistant', h('div.vb-msg-avatar', huginNode('speaking', 22)), h('div', cur.body)));
		}
		cur.text += text || ''; cur.pre.textContent = cur.text;   // plain while streaming; markdown once final
	}
	function finalizeAssistant() { if (!cur) return; clear(cur.body); Vibes.markdown.render(cur.text, cur.body); cur = null; }

	/* ---- Forge rail --------------------------------------------------------------------- */
	function addChip(ev) {
		const el = h('div.vb-fchip.pending', { dataset: { id: ev.id || '' } },
			h('span.vb-fchip-icon', iconFor(ev.name)),
			h('span.vb-fchip-name', h('span.vb-dot.pending'), ev.name || 'tool'),
			h('span.vb-fchip-arg', { title: shortArg(ev.args) }, shortArg(ev.args)),
			h('span.vb-fchip-ms', '…'));
		if (ev.id) chips.set(ev.id, el);
		$('vbForgeList').appendChild(el);
	}
	function updateChip(ev) {
		const el = chips.get(ev.id); if (!el) return;
		el.classList.remove('pending'); el.classList.add(ev.ok === false ? 'fail' : 'ok');
		el.querySelector('.vb-dot').className = 'vb-dot ' + (ev.ok === false ? 'bad' : 'ok');
		el.querySelector('.vb-fchip-ms').textContent = ev.ms !== undefined ? fmtMs(ev.ms) : '';
		if (ev.preview) { const p = h('div.vb-fchip-preview', String(ev.preview).slice(0, 600)); el.appendChild(p); el.title = 'Click to show or hide the result'; p.hidden = ev.ok !== false; el.onclick = () => { p.hidden = !p.hidden; }; }
	}
	function addFileChange(ev) {
		$('vbForgeList').appendChild(h('div.vb-fchange', h('span', ev.kind === 'create' ? '+' : ev.kind === 'delete' ? '−' : '✎'),
			h('span.path', { title: 'Open in Code', onclick: () => Vibes.codeTab.openFile(ev.path) }, ev.path),
			h('span.vb-adds', '+' + (ev.adds || 0)), h('span.vb-dels', '−' + (ev.dels || 0))));
	}
	function addBuild(ev) {
		const errs = ev.errors || [];
		const card = h('div.vb-fbuild' + (ev.ok ? '' : '.fail'), h('div.vb-fbuild-head', h('span', ev.ok ? '✓ build ok' : '✕ build failed'), h('span', errs.length + ' err · ' + (ev.warnings || 0) + ' warn · ' + fmtMs(ev.ms))));
		for (const e of errs.slice(0, 8)) card.appendChild(h('button.vb-fbuild-err', { type: 'button', title: 'Open in Code at line ' + e.line, onclick: () => Vibes.codeTab.openFile(e.file, e.line) }, h('b', e.code || 'error'), ' ' + (e.file || '') + ':' + (e.line || '') + ' ', h('span.vb-muted', String(e.message || '').slice(0, 140))));
		if (errs.length > 8) card.appendChild(h('div.vb-muted', '… ' + (errs.length - 8) + ' more in the Build tab'));
		$('vbForgeList').appendChild(card);
	}

	async function loadModels() {
		const dl = clear($('vbModelList')), st = S.state || {};
		const pid = (S.project && S.project.provider_id) || (st.ai && st.ai.id);
		if (!pid) return;
		try { for (const m of (await api('aiModels', { provider: pid })).models || []) dl.appendChild(h('option', { value: m.id }, m.label && m.label !== m.id ? m.label : null)); } catch (e) { /* the field is free text anyway */ }
	}

	function wire() {
		$('vbComposer').addEventListener('submit', (e) => { e.preventDefault(); send(); });
		$('vbComposerText').addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); } });
		Vibes.subscribe(() => { if (S.project && !S.project.loading) loadModels(); });
	}
	Vibes.vibeTab = { openSession, detach, setLocked, send, wire, handle };
})();

/* =====================================================================================
   9. Markdown -> DOM. Headings, bold/italic, inline code, fenced code (with language label),
      lists, links, blockquotes, rules. Everything else is text. No innerHTML anywhere.
   ===================================================================================== */
(() => {
	const { h } = Vibes;

	function render(src, root) {
		const lines = String(src || '').replace(/\r\n?/g, '\n').split('\n');
		let i = 0, para = [];
		const flush = () => { if (para.length) { root.appendChild(h('p', inline(para.join(' ')))); para = []; } };
		while (i < lines.length) {
			const line = lines[i];
			let m;
			if ((m = /^\s*(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/.exec(line))) {           // fenced code
				flush(); const fence = m[1], lang = m[2].toLowerCase(); const buf = []; i++;
				while (i < lines.length && !lines[i].startsWith(fence)) buf.push(lines[i++]);
				i++; root.appendChild(codeBlock(buf.join('\n'), lang)); continue;
			}
			if ((m = /^(#{1,6})\s+(.*)$/.exec(line))) { flush(); root.appendChild(h('h' + m[1].length, inline(m[2].replace(/\s#+\s*$/, '')))); i++; continue; }
			if (/^\s*([-*_])\s*\1\s*\1[\s\1]*$/.test(line)) { flush(); root.appendChild(h('hr')); i++; continue; }
			if (/^\s*>/.test(line)) { flush(); const buf = []; while (i < lines.length && /^\s*>/.test(lines[i])) buf.push(lines[i++].replace(/^\s*>\s?/, '')); const bq = h('blockquote'); render(buf.join('\n'), bq); root.appendChild(bq); continue; }
			if (/^\s*([-*+]|\d+[.)])\s+/.test(line)) { flush(); i = list(lines, i, root); continue; }
			if (line.trim() === '') { flush(); i++; continue; }
			para.push(line.trim()); i++;
		}
		flush();
		return root;
	}

	/** One list (ordered or not) with nesting by indentation; returns the next line index. */
	function list(lines, i, root) {
		const first = /^(\s*)([-*+]|\d+[.)])\s+/.exec(lines[i]);
		const indent = first[1].length, ordered = /\d/.test(first[2]);
		const ul = h(ordered ? 'ol' : 'ul'); root.appendChild(ul);
		let li = null;
		while (i < lines.length) {
			const m = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(lines[i]);
			if (m && m[1].length === indent) { li = h('li', inline(m[3])); ul.appendChild(li); i++; continue; }
			if (m && m[1].length > indent && li) { i = list(lines, i, li); continue; }
			if (!m && lines[i].trim() !== '' && /^\s+/.test(lines[i]) && li) { li.appendChild(document.createTextNode(' ' + lines[i].trim())); i++; continue; }
			break;
		}
		return i;
	}

	/** Inline: code spans first (they shield their contents), then links, bold, italic. */
	function inline(text) {
		const out = [];
		const re = /(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)|\[([^\]]+)\]\(((?:https?:\/\/|\/|#)[^)\s]+)\)|\*\*([^*]+)\*\*|__([^_]+)__|\*([^*\n]+)\*|_([^_\n]+)_/g;
		let last = 0, m;
		while ((m = re.exec(text))) {
			if (m.index > last) out.push(text.slice(last, m.index));
			if (m[1]) out.push(h('code', m[2]));
			else if (m[3]) out.push(h('a', { href: m[4], target: '_blank', rel: 'noopener noreferrer' }, m[3]));
			else if (m[5] || m[6]) out.push(h('strong', m[5] || m[6]));
			else out.push(h('em', m[7] || m[8]));
			last = m.index + m[0].length;
		}
		if (last < text.length) out.push(text.slice(last));
		return out;
	}

	function codeBlock(code, lang) {
		const pre = h('pre', h('code', Vibes.highlight.fragment(code, lang)));
		const copy = h('button.vb-code-copy', { type: 'button', title: 'Copy' }, 'copy');
		copy.onclick = async () => { try { await navigator.clipboard.writeText(code); copy.textContent = 'copied'; setTimeout(() => { copy.textContent = 'copy'; }, 1200); } catch (e) { Vibes.toast('err', 'Could not copy.'); } };
		return h('div.vb-code', h('div.vb-code-head', h('span', lang || 'text'), copy), pre);
	}
	Vibes.markdown = { render, inline, codeBlock };
})();

/* =====================================================================================
   10. Syntax highlighting: a tokeniser per language (never a regex-replace over markup).
       Each tokeniser walks the source once and yields [cls|null, text] runs.
   ===================================================================================== */
(() => {
	const { h } = Vibes;
	const CS_KW = new Set(('abstract as base bool break byte case catch char checked class const continue decimal default delegate do double else enum event explicit extern false finally fixed float for foreach goto if implicit in int interface internal is lock long namespace new null object operator out override params private protected public readonly ref return sbyte sealed short sizeof stackalloc static string struct switch this throw true try typeof uint ulong unchecked unsafe ushort using var virtual void volatile while get set value yield async await nameof when where partial record init with global').split(' '));
	const isIdStart = (c) => /[A-Za-z_@$]/.test(c), isId = (c) => /[\w$]/.test(c);

	function* csharp(s) {
		let i = 0; const n = s.length;
		while (i < n) {
			const c = s[i], d = s[i + 1];
			if (c === '/' && d === '/') { const j = s.indexOf('\n', i); const e = j < 0 ? n : j; yield ['cm', s.slice(i, e)]; i = e; continue; }
			if (c === '/' && d === '*') { const j = s.indexOf('*/', i + 2); const e = j < 0 ? n : j + 2; yield ['cm', s.slice(i, e)]; i = e; continue; }
			if (c === '#' && (i === 0 || s[i - 1] === '\n' || /^\s*$/.test(s.slice(s.lastIndexOf('\n', i) + 1, i)))) { const j = s.indexOf('\n', i); const e = j < 0 ? n : j; yield ['pre', s.slice(i, e)]; i = e; continue; }
			if (c === '"' || c === '\'' || ((c === '@' || c === '$') && s[i + 1] === '"') || (c === '$' && s[i + 1] === '@' && s[i + 2] === '"')) {
				let j = i; while (s[j] !== '"' && s[j] !== '\'') j++;
				const q = s[j], verbatim = s.slice(i, j).includes('@'); j++;
				while (j < n) { if (s[j] === '\\' && !verbatim) { j += 2; continue; } if (s[j] === q) { if (verbatim && s[j + 1] === q) { j += 2; continue; } j++; break; } if (s[j] === '\n' && q === '\'') break; j++; }
				yield ['str', s.slice(i, j)]; i = j; continue;
			}
			if (/\d/.test(c) || (c === '.' && /\d/.test(d))) { let j = i + 1; while (j < n && /[\w.]/.test(s[j])) j++; yield ['num', s.slice(i, j)]; i = j; continue; }
			if (isIdStart(c)) {
				let j = i + 1; while (j < n && isId(s[j])) j++;
				const w = s.slice(i, j);
				if (c === '@' || CS_KW.has(w)) yield ['kw', w];
				else if (s[j] === '(' ) yield ['fn', w];
				else if (/^[A-Z]/.test(w) && (s[i - 1] === '[' || s.slice(j).match(/^\s*[<.(\s]?/) )) yield [/^[A-Z]/.test(w) && s[i - 1] === '[' ? 'attr' : 'ty', w];
				else yield [null, w];
				i = j; continue;
			}
			if (/[{}()[\];,.<>=+\-*/%!&|^~?:]/.test(c)) { yield ['punc', c]; i++; continue; }
			let j = i + 1; while (j < n && !/[\w"'/#{}()[\];,.<>=+\-*/%!&|^~?:@$]/.test(s[j]) && !/\d/.test(s[j])) j++;
			yield [null, s.slice(i, j)]; i = j;
		}
	}

	function* json(s) {
		let i = 0; const n = s.length;
		while (i < n) {
			const c = s[i];
			if (c === '"') { let j = i + 1; while (j < n && s[j] !== '"') { if (s[j] === '\\') j++; j++; } j++; let k = j; while (k < n && /\s/.test(s[k])) k++; yield [s[k] === ':' ? 'key' : 'str', s.slice(i, j)]; i = j; continue; }
			if (c === '/' && s[i + 1] === '/') { const j = s.indexOf('\n', i); const e = j < 0 ? n : j; yield ['cm', s.slice(i, e)]; i = e; continue; }
			if (/[-\d]/.test(c)) { let j = i + 1; while (j < n && /[\d.eE+-]/.test(s[j])) j++; yield ['num', s.slice(i, j)]; i = j; continue; }
			if (/[a-z]/.test(c)) { let j = i; while (j < n && /[a-z]/.test(s[j])) j++; yield ['kw', s.slice(i, j)]; i = j; continue; }
			if (/[{}[\]:,]/.test(c)) { yield ['punc', c]; i++; continue; }
			let j = i + 1; while (j < n && !/["{}[\]:,\-\da-z/]/.test(s[j])) j++; yield [null, s.slice(i, j)]; i = j;
		}
	}

	function* xml(s) {
		let i = 0; const n = s.length;
		while (i < n) {
			if (s.startsWith('<!--', i)) { const j = s.indexOf('-->', i); const e = j < 0 ? n : j + 3; yield ['cm', s.slice(i, e)]; i = e; continue; }
			if (s[i] === '<') {
				let j = i + 1; while (j < n && /[\w:./?!-]/.test(s[j])) j++;
				yield ['tag', s.slice(i, j)]; i = j;
				while (i < n && s[i] !== '>') {                                        // attributes
					if (/\s/.test(s[i])) { let k = i; while (k < n && /\s/.test(s[k])) k++; yield [null, s.slice(i, k)]; i = k; continue; }
					if (s[i] === '"' || s[i] === '\'') { const q = s[i]; let k = s.indexOf(q, i + 1); k = k < 0 ? n : k + 1; yield ['str', s.slice(i, k)]; i = k; continue; }
					if (s[i] === '=' || s[i] === '/' || s[i] === '?') { yield ['punc', s[i]]; i++; continue; }
					let k = i; while (k < n && !/[\s=>"'/?]/.test(s[k])) k++; if (k === i) k++; yield ['attr', s.slice(i, k)]; i = k;
				}
				if (i < n) { yield ['tag', '>']; i++; }
				continue;
			}
			let j = s.indexOf('<', i); if (j < 0) j = n;
			const text = s.slice(i, j); const m = /\$\([\w]+\)/g; let last = 0, mm;
			while ((mm = m.exec(text))) { if (mm.index > last) yield [null, text.slice(last, mm.index)]; yield ['num', mm[0]]; last = mm.index + mm[0].length; }
			if (last < text.length) yield [null, text.slice(last)];
			i = j;
		}
	}

	function* markdown(s) {
		for (const line of s.split(/(?<=\n)/)) {
			if (/^#{1,6}\s/.test(line)) { yield ['h', line]; continue; }
			if (/^\s*(```|~~~)/.test(line)) { yield ['pre', line]; continue; }
			if (/^\s*>/.test(line)) { yield ['cm', line]; continue; }
			const re = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*|_[^_\n]+_)|(\[[^\]\n]+\]\([^)\n]+\))|(^\s*(?:[-*+]|\d+\.)\s)/gm;
			let last = 0, m;
			while ((m = re.exec(line))) {
				if (m.index > last) yield [null, line.slice(last, m.index)];
				yield [m[1] ? 'str' : m[2] ? 'b' : m[3] ? 'em' : m[4] ? 'link' : 'punc', m[0]];
				last = m.index + m[0].length;
			}
			if (last < line.length) yield [null, line.slice(last)];
		}
	}

	function* plain(s) { yield [null, s]; }
	const LANGS = { csharp, cs: csharp, 'c#': csharp, json, jsonc: json, xml, csproj: xml, props: xml, html: xml, markdown, md: markdown };
	function tokens(code, lang) { return (LANGS[(lang || '').toLowerCase()] || plain)(String(code || '')); }

	/** A fragment of spans for a code block. */
	function fragment(code, lang) {
		const f = document.createDocumentFragment();
		for (const [cls, text] of tokens(code, lang)) f.appendChild(cls ? h('span.tk-' + cls, text) : document.createTextNode(text));
		return f;
	}

	/** A numbered table for the viewer: tokens may span lines, so each run is split on '\n'. */
	function table(code, lang) {
		const tbl = h('table.vb-hl'), tb = h('tbody'); tbl.appendChild(tb);
		let ln = 1, tr = row(ln), cell = tr.lastChild;
		for (const [cls, text] of tokens(code, lang)) {
			const parts = String(text).split('\n');
			parts.forEach((part, idx) => {
				if (idx > 0) { tb.appendChild(tr); ln++; tr = row(ln); cell = tr.lastChild; }
				if (part) cell.appendChild(cls ? h('span.tk-' + cls, part) : document.createTextNode(part));
			});
		}
		tb.appendChild(tr);
		return tbl;
		function row(num) { return h('tr', { dataset: { line: num } }, h('td.ln', String(num)), h('td.lc')); }
	}
	Vibes.highlight = { fragment, table, tokens };
})();

/* =====================================================================================
   11. Unified diff -> DOM
   ===================================================================================== */
(() => {
	const { h, clear } = Vibes;
	function render(text, root) {
		clear(root);
		if (!text || !text.trim()) { root.appendChild(h('div.vb-muted', 'No changes.')); return; }
		let a = 0, b = 0;
		for (const line of text.replace(/\r/g, '').split('\n')) {
			if (line.startsWith('diff --git')) { const m = / b\/(.*)$/.exec(line); root.appendChild(h('div.vb-diff-file', m ? m[1] : line)); continue; }
			if (/^(index |--- |\+\+\+ |new file|deleted file|similarity|rename |old mode|new mode|Binary)/.test(line)) { root.appendChild(h('div.vb-diff-meta', line)); continue; }
			const hm = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/.exec(line);
			if (hm) { a = +hm[1]; b = +hm[2]; root.appendChild(h('div.vb-diff-hunk', line)); continue; }
			if (line.startsWith('+')) { root.appendChild(h('div.vb-diff-line.add', h('span.n', ''), h('span.n', String(b++)), h('span.t', line))); continue; }
			if (line.startsWith('-')) { root.appendChild(h('div.vb-diff-line.del', h('span.n', String(a++)), h('span.n', ''), h('span.t', line))); continue; }
			if (line === '\\ No newline at end of file') { root.appendChild(h('div.vb-diff-meta', line)); continue; }
			root.appendChild(h('div.vb-diff-line.ctx', h('span.n', String(a++)), h('span.n', String(b++)), h('span.t', line || ' ')));
		}
	}
	Vibes.diff = { render };
})();

/* =====================================================================================
   12. Code tab: the tree, the viewer, operator edits (saved through saveFile = committed)
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, fmtAgo, S, api, post, toast, fail } = Vibes;
	let file = null, editing = false;

	function render() {
		const root = clear($('vbTree')), tree = (S.project && S.project.tree) || [];
		if (!tree.length) { root.appendChild(h('div.vb-muted', { style: { padding: '0.6rem' } }, S.project && S.project.runnerMissing && S.project.runnerMissing.length ? 'The runner library is missing; the tree cannot be read.' : 'No files.')); return; }
		const sorted = tree.slice().sort((x, y) => x.path.localeCompare(y.path));
		for (const f of sorted) {
			const depth = f.path.split('/').length - 1, name = f.path.split('/').pop();
			const el = h('div.vb-tree-item.' + f.kind + (file && file.path === f.path ? '.active' : ''), { style: { paddingLeft: (0.5 + depth * 0.9) + 'rem' }, title: f.path, dataset: { path: f.path } },
				h('span.ico', f.kind === 'dir' ? '▾' : icon(name)), h('span', name), f.kind === 'file' ? h('span.sz', fmtBytes(f.size)) : null);
			if (f.kind === 'file') el.onclick = () => openFile(f.path);
			root.appendChild(el);
		}
		if (!file) viewerEmpty();
	}
	function icon(name) { const e = name.split('.').pop().toLowerCase(); return ({ cs: 'C#', json: '{}', md: 'M↓', csproj: '<>', props: '<>', png: '▣', txt: '≡' })[e] || '·'; }
	function viewerEmpty() {
		clear($('vbViewerBody')).appendChild(h('div.vb-viewer-note', 'Pick a file in the tree. C#, JSON, csproj and Markdown are highlighted; small text files can be edited here and every save is a commit.'));
		$('vbViewerPath').textContent = 'Pick a file'; $('vbViewerMeta').textContent = '';
		for (const id of ['vbViewerEdit', 'vbViewerSave', 'vbViewerCancel']) $(id).hidden = true;
	}

	async function openFile(path, line) {
		if (!S.project) return;
		Vibes.tabs.show('code'); cancelEdit();
		$('vbViewerPath').textContent = path; $('vbViewerMeta').textContent = 'loading…';
		for (const el of Vibes.qsa('#vbTree .vb-tree-item')) el.classList.toggle('active', el.dataset.path === path);
		try {
			file = await api('file', { id: S.project.id, path });
			const body = clear($('vbViewerBody'));
			$('vbViewerMeta').textContent = fmtBytes(file.size) + ' · ' + file.lang + (file.truncated ? ' · showing the first 512 KB' : '') + ' · ' + fmtAgo(file.mtime);
			if (file.binary) { body.appendChild(h('div.vb-viewer-note', 'Binary file (' + fmtBytes(file.size) + ').')); $('vbViewerEdit').hidden = true; return; }
			body.appendChild(Vibes.highlight.table(file.content, file.lang));
			$('vbViewerEdit').hidden = !!file.truncated;
			if (line) jump(line);
		} catch (e) { fail(e, 'Could not open ' + path); viewerEmpty(); }
	}
	function jump(line) {
		const tr = Vibes.qs('#vbViewerBody tr[data-line="' + Number(line) + '"]');
		if (!tr) return;
		tr.classList.add('flash'); tr.scrollIntoView({ block: 'center', behavior: Vibes.reducedMotion() ? 'auto' : 'smooth' });
		setTimeout(() => tr.classList.remove('flash'), 2600);
	}

	function startEdit() {
		if (!file || file.binary) return;
		editing = true; const ta = $('vbViewerEditor'); ta.value = file.content; ta.hidden = false; $('vbViewerBody').hidden = true;
		$('vbViewerEdit').hidden = true; $('vbViewerSave').hidden = false; $('vbViewerCancel').hidden = false; ta.focus();
	}
	function cancelEdit() {
		editing = false; $('vbViewerEditor').hidden = true; $('vbViewerBody').hidden = false;
		$('vbViewerSave').hidden = true; $('vbViewerCancel').hidden = true; if (file && !file.binary && !file.truncated) $('vbViewerEdit').hidden = false;
	}
	async function save() {
		const btn = $('vbViewerSave'); Vibes.setBusy(btn, 'Saving…');
		try {
			const r = await post('saveFile', { id: S.project.id, path: file.path, content: $('vbViewerEditor').value });
			toast('ok', file.path + ' saved' + (r.commit ? (r.commit.ok ? (r.commit.hash ? ' and committed (' + r.commit.hash.slice(0, 7) + ')' : ', nothing to commit') : ' but the commit failed: ' + r.commit.error) : '') + '.');
			Vibes.clearBusy(btn); cancelEdit(); await openFile(file.path); Vibes.historyTab.render(true);
		} catch (e) { Vibes.clearBusy(btn); fail(e, 'Not saved'); }
	}
	function wire() { $('vbViewerEdit').onclick = startEdit; $('vbViewerCancel').onclick = cancelEdit; $('vbViewerSave').onclick = save; }
	Vibes.codeTab = { render, openFile, wire, get editing() { return editing; } };
})();

/* =====================================================================================
   13. Build tab
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtMs, fmtAgo, S } = Vibes;
	function render() {
		const p = S.project; if (!p) return;
		$('vbBuildConfig').value = (S.settings && S.settings.settings && S.settings.settings.defaults.configuration) || (p.lastBuild && p.lastBuild.configuration) || 'Release';
		show(p.lastBuild);
	}
	function applyBuildEvent(ev) { show({ ok: ev.ok, errors: ev.errors || [], warnings: ev.warnings, ms: ev.ms, at: Date.now() / 1000, diagnostics: ev.diagnostics, log: ev.log }); }
	function show(lb) {
		const sum = $('vbBuildSummary'), res = clear($('vbBuildResult')), badge = $('vbTabBuildBadge');
		if (!lb) { sum.textContent = 'Never built.'; sum.className = 'vb-build-summary'; badge.hidden = true; $('vbBuildLogWrap').hidden = true; res.appendChild(h('div.vb-muted', 'Press Build, or let Hugin build after his turns (Settings → Dev Environment).')); return; }
		const errs = lb.errors || [], diags = lb.diagnostics || [];
		const warns = diags.filter(d => d.severity === 'warning');
		sum.textContent = (lb.ok ? '✓ ok' : '✕ failed') + ' · ' + errs.length + ' error' + (errs.length === 1 ? '' : 's') + ' · ' + (lb.warnings || warns.length || 0) + ' warnings · ' + fmtMs(lb.ms) + (lb.configuration ? ' · ' + lb.configuration : '') + (lb.at ? ' · ' + fmtAgo(lb.at) : '');
		sum.className = 'vb-build-summary ' + (lb.ok ? 'ok' : 'fail');
		badge.hidden = false; badge.textContent = lb.ok ? '✓' : String(errs.length); badge.className = 'vb-tab-badge' + (lb.ok ? ' ok' : '');
		for (const e of errs) res.appendChild(row(e, false));
		for (const w of warns.slice(0, 50)) res.appendChild(row(w, true));
		if (lb.ok && !errs.length && !warns.length) res.appendChild(h('div.vb-muted', 'Clean build.'));
		$('vbBuildLogWrap').hidden = !lb.log; if (lb.log) $('vbBuildLog').textContent = String(lb.log).slice(-60000);
	}
	function row(e, warn) {
		return h('button.vb-build-err' + (warn ? '.warn' : ''), { type: 'button', title: e.file ? 'Open ' + e.file + ' at line ' + e.line : '', onclick: () => { if (e.file) Vibes.codeTab.openFile(e.file, e.line); } },
			h('span.code', e.code || (warn ? 'warning' : 'error')), h('span.loc', (e.file || '') + (e.line ? ':' + e.line + (e.col ? ':' + e.col : '') : '')), h('span.msg', e.message || ''));
	}
	Vibes.buildTab = { render, applyBuildEvent };
})();

/* =====================================================================================
   14. Package tab: manifest, catalogue versions, publish/unpublish, export, delete
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, fmtAgo, S, post, toast, fail } = Vibes;
	function kv(root, rows) { clear(root); for (const [k, v, cls] of rows) { root.appendChild(h('span.k', k)); root.appendChild(h('span.v' + (cls ? '.' + cls : ''), v === null || v === undefined || v === '' ? '—' : String(v))); } }
	function render() {
		const p = S.project; if (!p) return;
		const m = p.manifest || {};
		kv($('vbManifest'), [['Package', p.namespace + '-' + p.name], ['Version', m.version_number || p.version], ['GUID', p.guid], ['Description', m.description || p.description], ['Dependencies', (m.dependencies || []).join(', ') || 'none'], ['Website', m.website_url || '']]);
		$('vbPublishHelp').textContent = p.last_build_ok ? 'Publishing packages the last green build as ' + p.namespace + '-' + p.name + '-' + p.version + ' into your local catalogue (source “vibes”).' : 'Publish needs a green build first.';
		$('vbBtnPublish').disabled = !p.last_build_ok; $('vbBtnPublish').title = p.last_build_ok ? '' : 'Build first';
		const cat = clear($('vbCatalog'));
		if (!(p.catalog || []).length) cat.appendChild(h('div.vb-muted', 'Not in the catalogue yet.'));
		for (const v of p.catalog || []) {
			cat.appendChild(h('div.vb-cat-row', h('span', 'v' + v.version + (v.latest ? ' ★' : '')),
				h('span.worlds', (v.worlds || []).length ? 'used by ' + v.worlds.join(', ') : 'no world uses it', v.present === false ? ' · file missing' : '', ' · ' + fmtBytes(v.size) + ' · ' + fmtAgo(v.published_at)),
				h('button.vb-btn.vb-btn-xs.vb-btn-danger-ghost', { type: 'button', onclick: (e) => unpublish(e.currentTarget, v) }, 'Unpublish')));
		}
		kv($('vbDiskKv'), [['Project dir', fmtBytes(p.size)], ['Path', p.dir || '']]);
		const ex = $('vbBtnExport'); ex.href = 'vibesAPI.php?action=exportProject&id=' + p.id;
	}
	async function publish(btn) {
		const p = S.project; if (!p) return;
		const used = (p.worlds || []);
		if (used.length && !(await Vibes.confirmDialog('Publish ' + p.name + ' ' + p.version + '?', used.length + ' world' + (used.length === 1 ? ' uses' : 's use') + ' this mod (' + used.join(', ') + '). They pick the new version up on their next Update; players get it with their next sync.', 'Publish'))) return;
		Vibes.jobs.start(btn, 'publish', { id: p.id }, 'Publishing…');
	}
	async function unpublish(btn, v) {
		const p = S.project;
		if (!(await Vibes.confirmDialog('Unpublish v' + v.version + '?', (v.worlds || []).length ? 'Worlds using it: ' + v.worlds.join(', ') + '. They keep the installed copy until their next Update, which will then fail to find it. Remove it from their mod lists first.' : 'No world uses this version. It is removed from the catalogue and its zip deleted.', 'Unpublish'))) return;
		Vibes.jobs.start(btn, 'unpublish', { id: p.id, version: v.version }, 'Unpublishing…');
	}
	function wire() { $('vbBtnDeleteProject').onclick = () => Vibes.deleteVibe.open(); }
	Vibes.packageTab = { render, publish, wire };
})();

/* =====================================================================================
   15. History tab: git log, diff, push buttons per enabled SCM
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtAgo, S, api, fail } = Vibes;
	let picked = null;
	async function render(refetch) {
		const p = S.project; if (!p) return;
		if (refetch) { try { p.gitLog = (await api('gitLog', { id: p.id, n: 50 })).log || []; } catch (e) { /* keep what we had */ } }
		const root = clear($('vbGitLog'));
		if (!(p.gitLog || []).length) root.appendChild(h('div.vb-muted', { style: { padding: '0.8rem' } }, 'No commits yet.'));
		for (const c of p.gitLog || []) {
			root.appendChild(h('div.vb-commit' + (picked === c.hash ? '.active' : ''), { onclick: () => showDiff(c) },
				h('span.vb-commit-hash', c.short || String(c.hash).slice(0, 7)), h('span.vb-commit-msg', { title: c.message }, c.message), h('span.vb-commit-meta', (c.author || '') + ' · ' + fmtAgo(c.date))));
		}
		const scm = (S.state && S.state.scm) || {};
		const rem = clear($('vbRemotes')), push = clear($('vbPushButtons'));
		const parts = [];
		if (p.github_remote) parts.push('GitHub: ' + p.github_remote); if (p.gitlab_remote) parts.push('GitLab: ' + p.gitlab_remote);
		rem.textContent = parts.join(' · ') || (scm.github || scm.gitlab ? 'Not pushed yet.' : 'No SCM enabled (Settings → GitHub / GitLab).');
		for (const which of ['github', 'gitlab']) if (scm[which]) push.appendChild(h('button.vb-btn.vb-btn-sm.vb-btn-ghost', { type: 'button', onclick: (e) => Vibes.jobs.start(e.currentTarget, 'push', { id: p.id, remote: which }, 'Pushing…') }, '↑ Push to ' + (which === 'github' ? 'GitHub' : 'GitLab')));
	}
	async function showDiff(c) {
		picked = c.hash; render();
		const root = $('vbDiff'); clear(root).appendChild(h('div.vb-skeleton', { style: { height: '4rem', margin: '1rem' } }));
		try { const r = await api('gitDiff', { id: S.project.id, ref: c.hash }); Vibes.diff.render(r.diff, root); if (r.truncated) root.appendChild(h('div.vb-diff-meta', '… diff truncated at 400 KB')); }
		catch (e) { clear(root).appendChild(h('div.vb-muted', e.message)); }
	}
	Vibes.historyTab = { render };
})();

/* =====================================================================================
   16. Settings modal: fields are bound by data-setting="dotted.path"; each tab saves itself
   ===================================================================================== */
(() => {
	const { $, h, clear, qs, qsa, fmtBytes, fmtAgo, S, api, post, toast, fail, openModal, setBusy, clearBusy } = Vibes;

	const getPath = (o, p) => p.split('.').reduce((a, k) => (a && a[k] !== undefined ? a[k] : undefined), o);
	const setPath = (o, p, v) => { const ks = p.split('.'); let c = o; for (const k of ks.slice(0, -1)) c = c[k] = c[k] || {}; c[ks[ks.length - 1]] = v; };

	async function open(tab) {
		openModal('vbSettings');
		if (tab) showTab(tab);
		await load();
	}
	async function load() {
		try { S.settings = await api('settings'); } catch (e) { return fail(e, 'Could not load the settings'); }
		bind(S.settings.settings);
		renderPak(); renderLibs(); renderAssets(); renderDanger();
		$('vbDzRoot').textContent = (S.state && S.state.root) || '/opt/stateful/vibes';
	}
	/** Fill every data-setting field from the document; selects get their options first. */
	function bind(doc) {
		const tpl = clear($('vbSetTemplate'));
		for (const t of S.settings.templates || []) tpl.appendChild(h('option', { value: t.key, title: t.description }, t.label || t.key));
		if (!(S.settings.templates || []).length) tpl.appendChild(h('option', { value: doc.defaults.template }, doc.defaults.template));
		const prov = clear($('vbSetProvider')); prov.appendChild(h('option', { value: '' }, 'Server default'));
		for (const p of S.settings.providers || []) prov.appendChild(h('option', { value: p.id }, p.label + (p.enabled ? '' : ' (disabled)') + (p.is_default ? ' ★' : '')));
		const sw = clear($('vbSetSourceWorld')); sw.appendChild(h('option', { value: '' }, 'First world with server files'));
		for (const w of S.settings.worldsWithServerFiles || []) sw.appendChild(h('option', { value: w }, w));
		for (const el of qsa('[data-setting]')) {
			const v = getPath(doc, el.dataset.setting);
			if (el.type === 'checkbox') el.checked = !!v;
			else el.value = v === null || v === undefined ? '' : String(v);
		}
		$('vbAssetsLimitNote').textContent = 'PHP itself accepts at most ' + fmtBytes(S.settings.phpUploadMax) + ' per file; the effective limit is ' + fmtBytes(S.settings.uploadMaxBytes) + '.';
		loadModelList(doc.defaults.provider_id);
	}
	async function loadModelList(pid) {
		const dl = clear($('vbSetModelList'));
		pid = pid || (S.state.ai && S.state.ai.id); if (!pid) return;
		try { for (const m of (await api('aiModels', { provider: pid })).models || []) dl.appendChild(h('option', { value: m.id }, m.label !== m.id ? m.label : null)); } catch (e) { /* free text */ }
	}
	/** Read ONE form's fields into a partial document (so a tab saves only what it shows). */
	function collect(form) {
		const out = {};
		for (const el of qsa('[data-setting]', form)) {
			let v = el.type === 'checkbox' ? el.checked : el.value;
			if (el.dataset.type === 'int') v = v === '' ? 0 : parseInt(v, 10);
			if (el.dataset.type === 'int-or-null') v = v === '' ? null : parseInt(v, 10);
			setPath(out, el.dataset.setting, v);
		}
		return out;
	}
	async function save(form) {
		const btn = qs('button[type=submit]', form); setBusy(btn, 'Saving…');
		try {
			const r = await post('saveSettings', { settings: collect(form) });
			S.settings.settings = r.settings; bind(r.settings); toast('ok', 'Settings saved.');
			await Vibes.router.refreshState(true);
		} catch (e) { fail(e, 'Settings not saved'); }
		clearBusy(btn);
	}

	/* ---- Dev Environment: the pak ---------------------------------------------------- */
	function renderPak() {
		const st = S.state || {}, pak = st.pak || {}, m = S.settings.pak || {};
		const rows = [['Installed', pak.version || 'none', pak.compatible ? 'ok' : pak.present ? 'bad' : 'muted'], ['Format / base', m.pakFormat ? m.pakFormat + ' / ' + m.base : '—'], ['Built', m.builtAt ? fmtAgo(m.builtAt) : '—'], ['Available', pak.available || 'unknown', pak.available && pak.available !== pak.version ? 'ok' : 'muted'], ['Last check', pak.lastCheck ? fmtAgo(pak.lastCheck) : 'never']];
		if (m.components) for (const [k, v] of Object.entries(m.components)) rows.push([k, v]);
		const root = clear($('vbSetPak')); for (const [k, v, cls] of rows) { root.appendChild(h('span.k', k)); root.appendChild(h('span.v' + (cls ? '.' + cls : ''), String(v))); }
		$('vbSetPakUpdate').hidden = !(pak.available && pak.available !== pak.version);
		$('vbSetPakHelp').textContent = pak.problem || '';
		for (const id of ['vbSetPakUpdate', 'vbSetPakRedownload', 'vbSetReharvest', 'vbSetGc']) { $(id).disabled = !st.installed; $(id).title = st.installed ? '' : 'Install Hugin’s Vibes first'; }
	}
	async function checkPak(btn) {
		setBusy(btn, 'Checking…');
		try { const r = await api('pakInfo', { force: 1 }); toast(r.version && S.state.pak && r.version !== S.state.pak.version ? 'info' : 'ok', 'Newest Build Pak: ' + (r.version || '?') + ' (' + fmtBytes(r.sizeBytes) + ')' + (S.state.pak && r.version === S.state.pak.version ? ' — you have it.' : '.')); await Vibes.router.refreshState(true); renderPak(); }
		catch (e) { fail(e, 'Release lookup failed'); }
		clearBusy(btn);
	}

	/* ---- Libraries -------------------------------------------------------------------- */
	function renderLibs() {
		const l = S.settings.libs, root = clear($('vbSetLibs'));
		const rows = l ? [['Game version', l.gameVersion || (l.gameBuild ? 'Steam build ' + l.gameBuild : null)], ['Source world', l.sourceWorld], ['Harvested', fmtAgo(l.harvestedAt)], ['Decompiled', (S.state.libs && S.state.libs.decompiled) ? 'yes' : 'no'], ['Files', Object.keys(l.files || {}).length]] : [['Libraries', 'not harvested yet', 'muted']];
		for (const [k, v, cls] of rows) { root.appendChild(h('span.k', k)); root.appendChild(h('span.v' + (cls ? '.' + cls : ''), v === null || v === undefined ? '—' : String(v))); }
		const files = clear($('vbSetLibFiles'));
		for (const f of S.settings.libFiles || []) files.appendChild(h('div.vb-file-row', h('span', '▤'), h('span', f.name), h('span.vb-muted', fmtBytes(f.size)), h('span')));
		if (!(S.settings.libFiles || []).length) files.appendChild(h('div.vb-muted', 'No assemblies yet.'));
	}

	/* ---- Assets ----------------------------------------------------------------------- */
	async function renderAssets() {
		let r; try { r = await api('assets'); } catch (e) { return; }
		const root = clear($('vbAssetList'));
		if (!(r.assets || []).length) root.appendChild(h('div.vb-muted', 'No assets uploaded.'));
		for (const a of r.assets) {
			const thumb = a.image ? h('img.vb-asset-thumb', { src: 'vibesAPI.php?action=asset&name=' + encodeURIComponent(a.name), alt: '' }) : h('span.vb-asset-thumb', a.name.split('.').pop().slice(0, 4));
			root.appendChild(h('div.vb-asset', thumb, h('span.nm', { title: a.name }, a.name, h('br'), h('span.mt', fmtBytes(a.size) + ' · ' + fmtAgo(a.mtime) + ' · used by ' + a.usedBy + ' project' + (a.usedBy === 1 ? '' : 's'))),
				h('a.vb-btn.vb-btn-xs.vb-btn-ghost', { href: 'vibesAPI.php?action=asset&name=' + encodeURIComponent(a.name), target: '_blank', rel: 'noopener' }, 'Open'),
				h('button.vb-btn.vb-btn-xs.vb-btn-danger-ghost', { type: 'button', onclick: () => deleteAsset(a) }, 'Delete')));
		}
	}
	async function upload(files) {
		const max = S.settings ? S.settings.uploadMaxBytes : Infinity;
		for (const f of files) {
			if (f.size > max) { toast('err', f.name + ' is ' + fmtBytes(f.size) + '; the limit is ' + fmtBytes(max) + '.'); continue; }
			const fd = new FormData(); fd.append('file', f, f.name);
			try { await api('uploadAsset', null, { method: 'POST', form: fd }); toast('ok', f.name + ' uploaded.'); }
			catch (e) { if (e.status === 409 && (await Vibes.confirmDialog('Replace ' + f.name + '?', 'An asset with this name exists. Replace it?', 'Replace'))) { fd.append('overwrite', '1'); try { await api('uploadAsset', null, { method: 'POST', form: fd }); toast('ok', f.name + ' replaced.'); } catch (e2) { fail(e2, 'Upload failed'); } } else fail(e, 'Upload failed'); }
		}
		renderAssets();
	}
	async function deleteAsset(a) {
		if (!(await Vibes.confirmDialog('Delete ' + a.name + '?', a.usedBy ? a.usedBy + ' project(s) mention this file; their builds may break.' : 'No project mentions this file.', 'Delete'))) return;
		try { await post('deleteAsset', { name: a.name }); toast('ok', a.name + ' deleted.'); renderAssets(); } catch (e) { fail(e, 'Delete failed'); }
	}

	/* ---- SCM tests -------------------------------------------------------------------- */
	async function testScm(which, btn) {
		const form = btn.closest('form'), out = $(which === 'github' ? 'vbGitHubResult' : 'vbGitLabResult');
		setBusy(btn, 'Testing…'); out.textContent = '';
		try { const r = await post(which === 'github' ? 'testGitHub' : 'testGitLab', { settings: collect(form) }); out.className = 'vb-scm-result ok'; out.textContent = 'Connected as ' + r.login + (r.url ? ' — ' + r.url : ''); }
		catch (e) { out.className = 'vb-scm-result err'; out.textContent = e.message; }
		clearBusy(btn);
	}

	/* ---- Export / import ---------------------------------------------------------------- */
	function exportSettings() {
		const blob = new Blob([JSON.stringify(S.settings.settings, null, 2)], { type: 'application/json' });
		const a = h('a', { href: URL.createObjectURL(blob), download: 'hugins-vibes-settings.json' }); document.body.appendChild(a); a.click(); a.remove();
		toast('info', 'Exported. Tokens are masked in the export; re-enter them after an import.');
	}
	async function importSettings(file) {
		try {
			const doc = JSON.parse(await file.text());
			if (!doc || typeof doc !== 'object') throw new Error('not a settings object');
			const r = await post('saveSettings', { settings: doc }); S.settings.settings = r.settings; bind(r.settings); toast('ok', 'Settings imported.');
		} catch (e) { fail(e, 'Import failed'); }
	}

	/* ---- Danger zone -------------------------------------------------------------------- */
	function renderDanger() {
		const st = S.state || {}, root = clear($('vbDzSummary'));
		const keep = { projects: $('vbDzKeepProjects').checked, catalog: $('vbDzKeepCatalog').checked, assets: $('vbDzKeepAssets').checked, settings: !$('vbDzWipeSettings').checked };
		const ul = h('ul');
		ul.appendChild(h('li', 'Build Pak ' + (st.pak && st.pak.version ? st.pak.version : '') + ' and the harvested libraries: removed'));
		ul.appendChild(h('li', 'Projects (' + (S.projects || []).length + '): ' + (keep.projects ? 'kept' : 'DELETED, including every transcript')));
		ul.appendChild(h('li', 'Published packages in the catalogue: ' + (keep.catalog ? 'kept' : 'DELETED — worlds using them lose the mod on their next Update')));
		ul.appendChild(h('li', 'Uploaded assets: ' + (keep.assets ? 'kept' : 'DELETED')));
		ul.appendChild(h('li', 'Settings and SCM tokens: ' + (keep.settings ? 'kept' : 'RESET')));
		root.appendChild(h('div', 'What happens:')); root.appendChild(ul);
		const worlds = []; for (const p of S.projects || []) for (const w of p.worlds || []) if (!worlds.includes(w)) worlds.push(w);
		$('vbDzWorlds').textContent = worlds.length ? '⚠ Worlds using Vibes mods: ' + worlds.join(', ') + '. They keep what is installed until their next Update.' : 'No world uses a Vibes mod.';
		const can = $('vbDzConfirm').value === 'DELETE' && st.installed;
		$('vbDzDelete').disabled = !can; $('vbDzDelete').title = !st.installed ? 'Not installed' : can ? '' : 'Type DELETE to enable';
	}
	async function deleteVibes() {
		const btn = $('vbDzDelete'); setBusy(btn, 'Starting…');
		try {
			const r = await post('uninstall', { confirm: 'DELETE', keep_projects: $('vbDzKeepProjects').checked, keep_catalog: $('vbDzKeepCatalog').checked, keep_assets: $('vbDzKeepAssets').checked, wipe_settings: $('vbDzWipeSettings').checked });
			$('vbDzProgress').hidden = false; $('vbDzStep').textContent = 'Queued…'; $('vbDzBar').classList.add('indeterminate');
			new Vibes.Stream({ job: r.jobId }, (ev) => {
				if (ev.type === 'status') { $('vbDzStep').textContent = ev.step + (ev.detail ? ' · ' + ev.detail : ''); if (ev.progress >= 0) { $('vbDzBar').classList.remove('indeterminate'); $('vbDzBar').style.width = ev.progress + '%'; } }
				else if (ev.type === 'done' || (ev.type === 'job' && ev.status === 'done')) { $('vbDzStep').textContent = 'Removed.'; $('vbDzBar').classList.remove('indeterminate'); $('vbDzBar').style.width = '100%'; Vibes.closeModal('vbSettings'); Vibes.workspace.deselect(); Vibes.router.refreshState(true); }
				else if (ev.type === 'error' || (ev.type === 'job' && ev.status && !['queued', 'running', 'done'].includes(ev.status))) { $('vbDzStep').textContent = 'Failed: ' + (ev.error || (ev.job && ev.job.error) || ev.status); $('vbDzBar').classList.add('fail'); clearBusy(btn); Vibes.router.refreshState(true); }
			}, 0);
			await Vibes.router.refreshState(true);
		} catch (e) { fail(e, 'Removal did not start'); clearBusy(btn); }
	}

	function showTab(name) {
		for (const b of qsa('#vbSettingsNav button')) b.classList.toggle('active', b.dataset.stab === name);
		for (const p of qsa('.vb-settings-body .vb-stab')) p.classList.toggle('active', p.dataset.stab === name);
	}
	function wire() {
		$('vbBtnSettings').onclick = () => open();
		for (const b of qsa('#vbSettingsNav button')) b.addEventListener('click', () => showTab(b.dataset.stab));
		for (const f of qsa('.vb-settings-body form')) f.addEventListener('submit', (e) => { e.preventDefault(); save(f); });
		$('vbSetPakCheck').onclick = (e) => checkPak(e.currentTarget);
		$('vbSetProvider').addEventListener('change', (e) => loadModelList(e.target.value || null));
		for (const b of qsa('[data-scm-test]')) b.addEventListener('click', (e) => testScm(b.dataset.scmTest, e.currentTarget));
		$('vbSetExport').onclick = exportSettings;
		$('vbSetImport').onclick = () => $('vbSetImportFile').click();
		$('vbSetImportFile').addEventListener('change', (e) => { if (e.target.files[0]) importSettings(e.target.files[0]); e.target.value = ''; });
		const dz = $('vbDropzone');
		dz.onclick = () => $('vbAssetFile').click();
		dz.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('vbAssetFile').click(); } });
		$('vbAssetFile').addEventListener('change', (e) => { upload(Array.from(e.target.files)); e.target.value = ''; });
		for (const ev of ['dragenter', 'dragover']) dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('drag'); });
		for (const ev of ['dragleave', 'drop']) dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove('drag'); });
		dz.addEventListener('drop', (e) => upload(Array.from(e.dataTransfer.files)));
		for (const id of ['vbDzKeepProjects', 'vbDzKeepCatalog', 'vbDzKeepAssets', 'vbDzWipeSettings']) $(id).addEventListener('change', renderDanger);
		$('vbDzConfirm').addEventListener('input', renderDanger);
		$('vbDzDelete').onclick = deleteVibes;
	}
	Vibes.settings = { open, load, wire, showTab };
})();

/* =====================================================================================
   17. New Vibe and Delete Vibe dialogs
   ===================================================================================== */
(() => {
	const { $, h, clear, fmtBytes, S, api, post, toast, fail, openModal, closeModal, setBusy, clearBusy } = Vibes;
	let templates = null;

	async function openNew(prefill) {
		const st = S.state || {};
		if (!st.installed) return toast('warn', 'Install Hugin’s Vibes first.');
		if (!(st.libs && st.libs.present)) return toast('warn', 'Harvest the game libraries first (the card in the workspace tells you how).');
		$('vbNvName').value = (prefill && prefill.name) || ''; $('vbNvDesc').value = (prefill && prefill.description) || '';
		$('vbNvNs').value = (st.author && st.author.namespace) || ''; $('vbNvError').hidden = true;
		const sel = clear($('vbNvTemplate'));
		if (!templates) { try { templates = (await api('templates')).templates || []; } catch (e) { templates = []; $('vbNvError').hidden = false; $('vbNvError').textContent = e.message; } }
		for (const t of templates) sel.appendChild(h('option', { value: t.key }, t.label || t.key));
		if (!templates.length) sel.appendChild(h('option', { value: 'basic-plugin' }, 'basic-plugin'));
		const def = S.settings && S.settings.settings ? S.settings.settings.defaults.template : null; if (def && templates.some(t => t.key === def)) sel.value = def;
		describeTemplate();
		openModal('vbNewVibe');
	}
	function describeTemplate() { const t = (templates || []).find(x => x.key === $('vbNvTemplate').value); $('vbNvTemplateHelp').textContent = t ? t.description : ''; }
	async function create(e) {
		e.preventDefault();
		const btn = $('vbNvCreate'), err = $('vbNvError'); err.hidden = true;
		const name = $('vbNvName').value.trim(), ns = $('vbNvNs').value.trim();
		if (!/^[A-Za-z][A-Za-z0-9_]{1,95}$/.test(name)) { err.hidden = false; err.textContent = 'The name must be PascalCase: letters, digits and underscores, starting with a letter.'; return; }
		if (!ns) { err.hidden = false; err.textContent = 'Choose a namespace (it becomes the catalogue owner).'; return; }
		setBusy(btn, 'Creating…');
		try {
			const r = await post('createProject', { name, namespace: ns, description: $('vbNvDesc').value.trim(), template: $('vbNvTemplate').value });
			closeModal('vbNewVibe'); toast('ok', name + ' created.');
			await Vibes.router.refreshState(true); await Vibes.library.load(); await Vibes.workspace.select(r.project.id);
			$('vbComposerText').value = $('vbNvDesc').value.trim() ? 'Build this: ' + $('vbNvDesc').value.trim() : ''; $('vbComposerText').focus();
		} catch (e2) { err.hidden = false; err.textContent = e2.message; fail(e2, 'Not created'); }
		clearBusy(btn);
	}

	function openDelete() {
		const p = S.project; if (!p) return;
		$('vbDvText').textContent = 'This removes the Vibe “' + p.namespace + '-' + p.name + '” from this server:';
		const ul = clear($('vbDvList'));
		ul.appendChild(h('li', 'the git repository (' + fmtBytes(p.size) + ', ' + (p.gitLog || []).length + ' commits)'));
		ul.appendChild(h('li', (p.sessions || []).length + ' session transcript' + ((p.sessions || []).length === 1 ? '' : 's')));
		ul.appendChild(h('li', 'its build output and notes'));
		if ((p.catalog || []).length) ul.appendChild(h('li', (p.catalog || []).length + ' published version(s) stay in the catalogue' + ((p.worlds || []).length ? ' (used by ' + p.worlds.join(', ') + ')' : '')));
		$('vbDvExport').href = 'vibesAPI.php?action=exportProject&id=' + p.id;
		$('vbDvConfirm').value = ''; $('vbDvDelete').disabled = true; $('vbDvError').hidden = true;
		openModal('vbDeleteVibe');
	}
	async function doDelete() {
		const p = S.project, btn = $('vbDvDelete'); setBusy(btn, 'Deleting…');
		try { await post('deleteProject', { id: p.id, confirm: $('vbDvConfirm').value }); closeModal('vbDeleteVibe'); toast('ok', p.name + ' deleted.'); Vibes.workspace.deselect(); await Vibes.router.refreshState(true); await Vibes.library.load(); }
		catch (e) { $('vbDvError').hidden = false; $('vbDvError').textContent = e.message; fail(e, 'Not deleted'); }
		clearBusy(btn);
	}
	function wire() {
		$('vbNewVibeForm').addEventListener('submit', create);
		$('vbNvTemplate').addEventListener('change', describeTemplate);
		$('vbDvConfirm').addEventListener('input', () => { $('vbDvDelete').disabled = !S.project || $('vbDvConfirm').value !== S.project.name; });
		$('vbDvDelete').onclick = doDelete;
	}
	Vibes.newVibe = { open: openNew, wire };
	Vibes.deleteVibe = { open: openDelete };
})();

/* =====================================================================================
   18. Keyboard, boot
   ===================================================================================== */
(() => {
	const { $, S } = Vibes;
	function keys() {
		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { if (Vibes.closeTopModal()) e.preventDefault(); else if ($('vbLibrary').classList.contains('open')) Vibes.library.toggleDrawer(false); }
			if ((e.ctrlKey || e.metaKey) && e.key === ',') { e.preventDefault(); Vibes.settings.open(); }
			if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && Vibes.codeTab.editing && document.activeElement === $('vbViewerEditor')) { e.preventDefault(); $('vbViewerSave').click(); }
		});
	}
	async function boot() {
		let initial = {};
		try { initial = JSON.parse($('vbInitial').textContent); } catch (e) { console.error('initial state unreadable', e); }
		S.state = initial;
		Vibes.wireModals(); Vibes.intro.wire(); Vibes.installSheet.wire(); Vibes.library.wire(); Vibes.workspace.wire(); Vibes.tabs.wire();
		Vibes.vibeTab.wire(); Vibes.codeTab.wire(); Vibes.packageTab.wire(); Vibes.jobs.wire(); Vibes.settings.wire(); Vibes.newVibe.wire(); keys();
		Vibes.router.applyState();
		if (initial.installed) await Vibes.library.load(); else Vibes.library.render();
		// A slow heartbeat keeps the chips, the banner and the open-job list honest when another
		// tab (or Hugin himself) changes things; the SSE streams carry everything time-critical.
		setInterval(() => Vibes.router.refreshState(true), 20000);
		document.body.dataset.vbReady = '1';
	}
	Vibes.boot = boot;
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
