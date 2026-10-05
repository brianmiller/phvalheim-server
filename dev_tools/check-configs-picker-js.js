// Oracle for "Error loading mod configs": the picker called escapeHtml(), a name never
// defined in index.php, so the first mod row threw ReferenceError and the modal's own catch
// reported it as a load failure. A string grep cannot see an undefined name; this can.
// Scans only code -- string and template literals are stripped first, because CSS var(--x)
// and prose like "settings (" inside them otherwise read as calls.
const fs = require('fs');
const FNS = ['showConfigsModal', 'filterConfigMods', 'closeConfigsModal', 'modsButtonHtml',
             'showModsHub', 'renderModsHubCards', 'loadModsHubInstalled', 'closeModsHub', 'renderConfigCoverage', 'openConfigsModal',
             'restoreConfigsFromHash', 'cfgHashWorld', 'showConfigMigration',
             'renderConfigMigration', 'deleteConfigMigrationBackups', 'closeConfigMigration'];
const GLOBALS = new Set(['fetch','encodeURIComponent','decodeURIComponent','parseInt','String',
 'Number','JSON','Math','Set','Map','Array','Object','setTimeout','console','confirm','alert']);

// Template literals must NOT be blanked wholesale: ${...} interpolations are code, and the
// escapeHtml() bug lived in exactly one of them. Keep the interpolations, drop the text --
// which also drops CSS var(--danger) and prose like "settings (" that read as calls.
function stripLiterals(s) {
  let out = '', i = 0;
  while (i < s.length) {
    const c = s[i];
    if (c === '\\') { i += 2; continue; }
    if (c === "'" || c === '"') {
      const q = c; i++;
      while (i < s.length && s[i] !== q) { if (s[i] === '\\') i++; i++; }
      i++; out += ' '; continue;
    }
    if (c === '`') {
      i++;
      let depth;
      while (i < s.length && s[i] !== '`') {
        if (s[i] === '\\') { i += 2; continue; }
        if (s[i] === '$' && s[i + 1] === '{') {
          i += 2; depth = 1;
          const start = i;
          while (i < s.length && depth > 0) {
            if (s[i] === '{') depth++;
            else if (s[i] === '}') depth--;
            if (depth > 0) i++;
          }
          out += ' ' + stripLiterals(s.slice(start, i)) + ' ';
          i++; continue;
        }
        i++;
      }
      i++; continue;
    }
    out += c; i++;
  }
  return out;
}

function audit(src) {
  const defined = new Set();
  for (const m of src.matchAll(/function\s+([A-Za-z_$][\w$]*)/g)) defined.add(m[1]);
  for (const m of src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\(|function)/g)) defined.add(m[1]);
  const bad = [];
  for (const fn of FNS) {
    const i = src.indexOf('function ' + fn);
    if (i < 0) { bad.push(fn + '(): NOT DEFINED'); continue; }
    const rest = src.slice(i + 1);
    const j = rest.indexOf('\n    function ');
    const body = stripLiterals(j < 0 ? rest : rest.slice(0, j));
    for (const m of body.matchAll(/(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(/g)) {
      const n = m[1];
      if (GLOBALS.has(n) || defined.has(n) || n === fn) continue;
      if (/^(if|for|while|switch|return|catch|function|typeof|await|new)$/.test(n)) continue;
      bad.push(fn + '() calls undefined ' + n + '()');
    }
  }
  return bad;
}

const src = fs.readFileSync(process.argv[2], 'utf8');
let bad = audit(src);
if (process.argv[3] === '--control') {
  // Reintroduce the real bug. A check that cannot see it is not a check.
  const broke = audit(src.replace(/escapeHtmlBasic\(m\.name\)/, 'escapeHtml(m.name)'));
  if (broke.some(b => b.includes('escapeHtml'))) {
    console.log('  PASS  CONTROL: the audit catches a reintroduced escapeHtml()');
  } else {
    console.log('  FAIL  CONTROL: the audit cannot see the original bug'); process.exit(1);
  }
}
if (bad.length) { console.log('  FAIL  Configs picker JS calls undefined names:'); bad.forEach(b => console.log('        ' + b)); process.exit(1); }
console.log('  PASS  every name the Configs picker calls is defined');
