// Oracle for "Error loading mod configs": the picker called escapeHtml(), a name never
// defined in index.php, so the first mod row threw ReferenceError and the modal's own catch
// reported it as a load failure. A string grep cannot see an undefined name; this can.
// Scans only code -- string and template literals are stripped first, because CSS var(--x)
// and prose like "settings (" inside them otherwise read as calls.
//
// THREE false positives this has produced, each fixed here rather than in the product:
//   1. `<?php echo json_encode(...) ?>` -- index.php is PHP that EMITS JavaScript, so PHP
//      function calls sit inside the script. PHP blocks are stripped first now.
//   2. `const go = cfgMigContinue;` -- a const bound to an existing function is a definition,
//      but the binding scan only recognised const-equals-function-or-paren. It now records
//      any const/let/var binding.
//   3. The body scan's crude "to the next top-level function" delimiter overran into
//      top-level code between functions, attributing its calls to the function above. It now
//      stops at the first line that is neither indented nor blank.
// A probe that cries wolf gets ignored, which is worse than not having it.
const fs = require('fs');
const FNS = ['showConfigsModal', 'filterConfigMods', 'closeConfigsModal', 'modsButtonHtml',
             'showModsHub', 'renderModsHubCards', 'loadModsHubInstalled', 'closeModsHub', 'renderConfigCoverage', 'openConfigsModal',
             'restoreConfigsFromHash', 'cfgHashWorld', 'showConfigMigration',
             'renderConfigMigration', 'deleteConfigMigrationBackups', 'closeConfigMigration', 'renderConfigMigrationContinue',
             'continueAfterConfigMigration'];
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

// index.php is PHP emitting JavaScript. Its `<?php ... ?>` blocks are not part of the script
// the browser runs, so their calls must not be audited as if they were.
function stripPhp(s) {
  return s.replace(/<\?php[\s\S]*?\?>/g, ' ').replace(/<\?=[\s\S]*?\?>/g, ' ');
}

function audit(raw) {
  const src = stripPhp(raw);
  const defined = new Set();
  for (const m of src.matchAll(/function\s+([A-Za-z_$][\w$]*)/g)) defined.add(m[1]);
  // ANY const/let/var binding, not only one initialised to a function literal: a name bound
  // to an existing function (`const go = cfgMigContinue`) is just as defined, and treating it
  // as undefined produced a false alarm.
  for (const m of src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/g)) defined.add(m[1]);
  // Parameters count as defined inside the function that declares them.
  for (const m of src.matchAll(/function\s+[A-Za-z_$][\w$]*\s*\(([^)]*)\)/g)) {
    for (const p of m[1].split(',')) {
      const n = p.trim().split(/[\s=]/)[0];
      if (/^[A-Za-z_$][\w$]*$/.test(n)) defined.add(n);
    }
  }
  const bad = [];
  for (const fn of FNS) {
    const i = src.indexOf('function ' + fn);
    if (i < 0) { bad.push(fn + '(): NOT DEFINED'); continue; }
    const rest = src.slice(i + 1);
    // End the body at the next 4-space-indented `function`. Two cleverer delimiters were
    // tried and both were worse: cutting at the first UNindented line swallowed everything
    // down to the page's markup, and cutting at any 4-space declaration cut the body short
    // enough that the control could no longer see the escapeHtml() bug it exists to catch.
    // The overrun this was meant to fix was PHP's json_encode sitting between two functions,
    // and stripPhp() above already removes that.
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
