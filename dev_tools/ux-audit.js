// UX layout audit for the admin UI.
//
//   1. start a container from the image you want to test, seed a world or two
//   2. docker run --rm --network host -v $PWD/dev_tools:/work -w /work \
//        mcr.microsoft.com/playwright:v1.47.0-jammy \
//        bash -c "npm i --silent playwright@1.47.0 && node ux-audit.js http://localhost:<adminPort> /work/out"
//
// The playwright npm version MUST match the image tag or the browser binary is missing.
//
// TWO TRAPS, both of which cost me a wrong conclusion while writing this:
//
//   * PHP OPcache serves a stale compile after `docker cp`. Restart php-fpm8
//     between deploy and audit or every change looks like a no-op.
//   * The overlap check must be layer-aware. Before that, an open modal legitimately
//     drawn over the sidebar reported ~20 "overlaps" per view that were pure z-index
//     layering. Measuring only the topmost layer took dashboard@1280 from 12 overlaps
//     to 0 without a single line of CSS changing.
//
// Note on smallTargets: it flags anything under 30px, which is the wrong bar for a
// checkbox whose <label for=...> is also clickable. Treat it as a prompt to look, not
// as a defect count.
//
// UX audit: screenshot the admin UIs and MEASURE layout faults.
//
// Screenshots alone are a weak oracle -- I would be judging my own impression of a picture.
// So every view is also probed in-page for four measurable faults:
//   1. horizontal overflow of the page or any scrollable box
//   2. an element sticking out past its parent's right edge (the "overflow" Brian sees)
//   3. two interactive controls whose boxes intersect (the "overlap" Brian sees)
//   4. controls below the ~44px comfortable hit target
//
// Usage: node audit.js <baseUrl> <outDir>
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.argv[2] || 'http://localhost:18381';
const OUT  = process.argv[3] || '/out';

const PROBE = () => {
  const vis = el => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0';
  };
  const name = el => {
    const id = el.id ? '#' + el.id : '';
    const cls = (typeof el.className === 'string' && el.className) ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    const txt = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 32);
    return `${el.tagName.toLowerCase()}${id}${cls}${txt ? ` "${txt}"` : ''}`;
  };

  const openOverlay = [...document.querySelectorAll('.mods-modal-overlay, .modal')]
    .find(o => { const s = getComputedStyle(o); return s.display !== 'none' && s.visibility !== 'hidden'; });
  const inOverlay = el => el.closest('.mods-modal-overlay, .modal');
  const all = [...document.querySelectorAll('body *')].filter(vis).filter(el =>
    openOverlay ? openOverlay.contains(el) : !inOverlay(el));
  const out = { layer: openOverlay ? (openOverlay.id || 'overlay') : 'page', pageScroll: null, clipped: [], escapes: [], overlaps: [], smallTargets: [] };

  if (document.documentElement.scrollWidth > window.innerWidth + 1) {
    out.pageScroll = { scrollWidth: document.documentElement.scrollWidth, viewport: window.innerWidth };
  }

  for (const el of all) {
    const s = getComputedStyle(el);
    // 1/2. content wider than its own box, and the box actually clips or scrolls it
    if (el.scrollWidth - el.clientWidth > 2 && el.clientWidth > 0 && /hidden|auto|scroll/.test(s.overflowX)) {
      out.clipped.push({ el: name(el), scrollWidth: el.scrollWidth, clientWidth: el.clientWidth });
    }
    // element sticking out past its parent's content box
    const p = el.parentElement;
    if (p && p !== document.body) {
      const r = el.getBoundingClientRect(), pr = p.getBoundingClientRect();
      const ps = getComputedStyle(p);
      if (ps.overflow === 'visible' && r.width > 0 && pr.width > 0 && r.right > pr.right + 2) {
        out.escapes.push({ el: name(el), by: Math.round(r.right - pr.right), parent: name(p) });
      }
    }
  }

  // 3. intersecting interactive controls
  const ctrls = all.filter(e => /^(input|select|textarea|button|a)$/i.test(e.tagName) || e.classList.contains('switch') || e.classList.contains('action-btn'));
  for (let i = 0; i < ctrls.length; i++) {
    for (let j = i + 1; j < ctrls.length; j++) {
      const a = ctrls[i], b = ctrls[j];
      if (a.contains(b) || b.contains(a)) continue;
      const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
      const ox = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
      const oy = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
      if (ox > 2 && oy > 2) out.overlaps.push({ a: name(a), b: name(b), overlap: `${Math.round(ox)}x${Math.round(oy)}` });
    }
  }

  // 4. small hit targets
  for (const el of ctrls) {
    const r = el.getBoundingClientRect();
    if (el.type === 'hidden' || el.tagName === 'A') continue;
    if (r.height > 0 && r.height < 30) out.smallTargets.push({ el: name(el), h: Math.round(r.height), w: Math.round(r.width) });
  }

  out.overlaps = out.overlaps.slice(0, 25);
  out.escapes = out.escapes.slice(0, 25);
  out.smallTargets = out.smallTargets.slice(0, 25);
  return out;
};

(async () => {
  const browser = await chromium.launch();
  const report = {};

  const views = [
    { w: 1440, h: 900, tag: '1440' },
    { w: 1280, h: 800, tag: '1280' },
    { w: 1100, h: 800, tag: '1100' },
  ];

  for (const v of views) {
    const ctx = await browser.newContext({ viewport: { width: v.w, height: v.h }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const shot = async (label, probeSel) => {
      const key = `${label}@${v.tag}`;
      try {
        await page.waitForTimeout(700);
        await page.screenshot({ path: `${OUT}/${key.replace(/[@ ]/g, '_')}.png`, fullPage: true });
        report[key] = await page.evaluate(PROBE);
      } catch (e) { report[key] = { error: String(e).slice(0, 200) }; }
    };

    // Dashboard
    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' }).catch(() => {});
    await shot('dashboard');

    // Settings modal - modded world, each tab
    for (const world of ['midgard', 'northlands']) {
      await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' }).catch(() => {});
      await page.waitForTimeout(500);
      const opened = await page.evaluate(w => {
        if (typeof showSettingsModal === 'function') { showSettingsModal(w); return true; }
        return false;
      }, world).catch(() => false);
      if (!opened) { report[`settings-${world}@${v.tag}`] = { error: 'showSettingsModal not callable' }; continue; }
      await page.waitForTimeout(1800);
      await shot(`settings-${world}`);

      // Backups tab
      await page.evaluate(() => {
        const t = document.querySelector('#settingsTabBar .backup-tab[data-tab="backupsTab"]');
        if (t) t.click();
      }).catch(() => {});
      await page.waitForTimeout(900);
      await shot(`settings-${world}-backups`);
    }

    // New world
    await page.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle' }).catch(() => {});
    await shot('new_world');

    // Edit world
    await page.goto(`${BASE}/edit_world.php?world=midgard`, { waitUntil: 'networkidle' }).catch(() => {});
    await shot('edit_world');

    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 1));

  // Compact console summary
  for (const [k, r] of Object.entries(report)) {
    if (r.error) { console.log(`${k}: ERROR ${r.error}`); continue; }
    const bits = [];
    if (r.pageScroll) bits.push(`PAGE-SCROLL ${r.pageScroll.scrollWidth}>${r.pageScroll.viewport}`);
    if (r.clipped.length) bits.push(`clipped:${r.clipped.length}`);
    if (r.escapes.length) bits.push(`escapes-parent:${r.escapes.length}`);
    if (r.overlaps.length) bits.push(`OVERLAP:${r.overlaps.length}`);
    if (r.smallTargets.length) bits.push(`small-targets:${r.smallTargets.length}`);
    console.log(`${k}: ${bits.length ? bits.join('  ') : 'clean'}`);
  }
  await browser.close();
})();
