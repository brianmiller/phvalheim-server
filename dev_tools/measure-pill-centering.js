// Measure where the INK actually sits inside each access pill, on the real public page.
//
// Why pixels and not geometry: every previous attempt at this was measured from the text NODE's
// bounding box, which INCLUDES the trailing letter-spacing gap being compensated for. That box
// cannot see the error it creates, so it reported "centred" while the glyphs sat left of centre.
// Two later attempts scanned pixels but reported 0.00px for every pill, because they thresholded
// against a hardcoded colour and the muted pill's #333-on-#aaa did not clear it, and because the
// rounded corners read as "ink".
//
// So: sample the pill's own background as the most common colour in a middle horizontal band
// (which misses the corners entirely), call anything far from it ink, and report the gap on each
// side. A CONTROL pill with a deliberate 3px text-indent is measured too -- if that one does not
// come back skewed, the measurement is broken and every other number here is worthless.
//
// Usage:
//   docker run --rm --network host -v "$PWD/..":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 pngjs >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node measure-pill-centering.js'

const { chromium } = require('playwright');
const { PNG } = require('pngjs');

const URL = process.env.PHV_URL || 'http://localhost:8080/';
const SCALE = 8;           // deviceScaleFactor: 1 CSS px = 8 device px, so 1/8 px is resolvable
// No absolute colour threshold. A dimmed (offline-card) pill renders its text only ~21 colour
// units from its own background, so a fixed cutoff of 40 found NO ink and the first run reported
// "measurement failed" for all nine pills. Instead: score each column by how far its pixels sit
// from the background, then call a column ink if it clears this fraction of the peak column.
// Adapts to whatever contrast the pill happens to have.
const INK_FRACTION = 0.2;

// padL/padR (CSS px) bound the scan to the pill's CONTENT box. Without this the scan always
// returned left=right=0: at mid-height the pill's own outer edge against the card background is
// a colour step of ~21, comparable to the text's ~31, so the border antialiasing scored as ink
// and every pill looked perfectly centred no matter where the glyphs were. Inset a further 1px
// inside the padding so a glyph that slightly overflows is still seen.
function inkExtent(buf, padL, padR) {
  const png = PNG.sync.read(buf);
  const { width: W, height: H, data } = png;
  const xLo = Math.max(0, Math.round((padL - 1) * SCALE));
  const xHi = Math.min(W - 1, W - 1 - Math.round((padR - 1) * SCALE));
  const at = (x, y) => {
    const i = (y * W + x) * 4;
    return [data[i], data[i + 1], data[i + 2], data[i + 3]];
  };

  // Middle band only: no rounded corners in it, and it crosses every uppercase glyph.
  const y0 = Math.floor(H * 0.35), y1 = Math.ceil(H * 0.65);

  // Background = most common colour in the band.
  const freq = new Map();
  for (let y = y0; y < y1; y++)
    for (let x = xLo; x <= xHi; x++) {
      const k = at(x, y).join(',');
      freq.set(k, (freq.get(k) || 0) + 1);
    }
  let bg = null, best = -1;
  for (const [k, n] of freq) if (n > best) { best = n; bg = k.split(',').map(Number); }

  const dist = (p) => Math.hypot(p[0] - bg[0], p[1] - bg[1], p[2] - bg[2]);

  // Per-column difference energy against the pill's own background.
  const energy = new Array(W).fill(0);
  for (let x = xLo; x <= xHi; x++)
    for (let y = y0; y < y1; y++) energy[x] += dist(at(x, y));

  const peak = Math.max(...energy.slice(xLo, xHi + 1));
  if (peak <= 0) return null;
  const cut = peak * INK_FRACTION;

  let min = Infinity, max = -Infinity;
  for (let x = xLo; x <= xHi; x++) if (energy[x] >= cut) { if (x < min) min = x; if (x > max) max = x; }

  if (min === Infinity) return null;

  // VERTICAL too -- "isn't centred" need not mean horizontally. Inset 1.5px top and bottom so
  // the pill's own top/bottom edge antialiasing does not score as ink (there is no vertical
  // padding, but cap height leaves ~3.5px clear at each end).
  const yLo = Math.round(1.5 * SCALE), yHi = H - 1 - Math.round(1.5 * SCALE);
  const rowE = new Array(H).fill(0);
  for (let y = yLo; y <= yHi; y++)
    for (let x = xLo; x <= xHi; x++) rowE[y] += dist(at(x, y));
  const rPeak = Math.max(...rowE.slice(yLo, yHi + 1));
  let tMin = Infinity, tMax = -Infinity;
  for (let y = yLo; y <= yHi; y++) if (rowE[y] >= rPeak * INK_FRACTION) { if (y < tMin) tMin = y; if (y > tMax) tMax = y; }

  return { W, H, bg, inkLeft: min, inkRight: max, inkTop: tMin, inkBot: tMax, contrast: peak / (y1 - y0) };
}

function report(label, buf, padL, padR, extra = '') {
  const r = inkExtent(buf, padL, padR);
  if (!r) { console.log(`  ${label.padEnd(26)} NO INK FOUND (measurement failed)`); return null; }
  const leftGap = r.inkLeft / SCALE;
  const rightGap = (r.W - 1 - r.inkRight) / SCALE;
  const skew = (leftGap - rightGap) / 2;           // + = ink sits right, - = ink sits left
  const dir = Math.abs(skew) < 0.0625 ? 'centred' : (skew > 0 ? 'ink RIGHT of centre' : 'ink LEFT of centre');
  const topGap = r.inkTop / SCALE, botGap = (r.H - 1 - r.inkBot) / SCALE;
  const vSkew = (topGap - botGap) / 2;   // + = ink sits LOW
  console.log(
    `  ${label.padEnd(13)} w=${(r.W / SCALE).toFixed(1).padStart(5)} ` +
    `L=${leftGap.toFixed(2)} R=${rightGap.toFixed(2)} Hskew=${skew >= 0 ? '+' : ''}${skew.toFixed(2)}  |  ` +
    `T=${topGap.toFixed(2)} B=${botGap.toFixed(2)} Vskew=${vSkew >= 0 ? '+' : ''}${vSkew.toFixed(2)}` +
    `${Math.abs(vSkew) >= 0.25 ? (vSkew > 0 ? ' <-- ink LOW' : ' <-- ink HIGH') : ''}${extra}`
  );
  return skew;
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: SCALE, viewport: { width: 1400, height: 1000 } });
  // The 5s poll rewrites the cards; let it not race the screenshots.
  await page.route('**/api.php*', r => r.abort());
  await page.goto(URL, { waitUntil: 'networkidle' });

  const pills = await page.$$('.vanilla-badge');
  console.log(`\nFound ${pills.length} pill(s) on ${URL}\n`);
  if (!pills.length) { console.log('No pills -- is the dev bypass on and a world visible?'); await browser.close(); process.exit(1); }

  console.log('MEASURED (real pills):');
  const seen = [];
  for (const p of pills) {
    const label = (await p.innerText()).trim().toLowerCase();
    const cls = await p.getAttribute('class');
    const muted = /vanilla-badge-muted/.test(cls) ? ' [muted]' : '';
    const pad = await p.evaluate(el => { const s = getComputedStyle(el); return [parseFloat(s.paddingLeft), parseFloat(s.paddingRight)]; });
    const skew = report(label, await p.screenshot(), pad[0], pad[1], muted);
    seen.push({ label, skew });
  }

  // Computed padding, to tie any skew back to the rule that causes it.
  const box = await page.$eval('.vanilla-badge', el => {
    const s = getComputedStyle(el);
    return { pl: s.paddingLeft, pr: s.paddingRight, fs: s.fontSize, ls: s.letterSpacing, ta: s.textAlign, jc: s.justifyContent };
  });
  console.log(`\ncomputed: padding-left=${box.pl} padding-right=${box.pr} font-size=${box.fs} letter-spacing=${box.ls} justify=${box.jc}`);

  // CONTROL: same pill, deliberately shoved 3px right. The measurement MUST see it.
  await page.$eval('.vanilla-badge', el => { el.style.textIndent = '3px'; });
  console.log('\nCONTROL (same pill + text-indent:3px -- must report ~+1.5px):');
  const ctl = report('control +3px indent', await pills[0].screenshot(), parseFloat(box.pl), parseFloat(box.pr));

  await browser.close();

  const ok = ctl !== null && ctl > 0.9;
  console.log(`\nCONTROL ${ok ? 'PASSED' : 'FAILED'} -- the measurement ${ok ? 'can' : 'CANNOT'} detect miscentering.`);
  if (!ok) { console.log('Every number above is therefore untrustworthy.'); process.exit(1); }

  const off = seen.filter(s => s.skew !== null && Math.abs(s.skew) >= 0.0625);
  console.log(off.length ? `\n${off.length} pill(s) off centre by >1/16px: ${off.map(s => `${s.label} (${s.skew.toFixed(3)})`).join(', ')}`
                         : '\nAll pills centred within 1/16px.');
})();
