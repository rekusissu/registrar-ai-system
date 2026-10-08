// Extract each page's inline script with PHP stubbed, and report where the
// parser stops. Run after any edit to a page with an inline <script>.
const fs = require('fs');
const { execFileSync } = require('child_process');

const files = process.argv.slice(2);
if (!files.length) {
  console.error('usage: node _scriptcheck.js <file.php> ...');
  process.exit(2);
}

let bad = 0;
for (const f of files) {
  const L = fs.readFileSync(f, 'utf8').split(/\r?\n/);
  const k = L.findIndex((l) => /<script/.test(l));
  if (k < 0) { console.log(f.padEnd(34) + ' no inline script'); continue; }
  const e = L.findIndex((x, q) => q > k && /<\/script>/.test(x));
  const js = L.slice(k + 1, e).join('\n')
    .replace(/<\?[\s\S]*?\?>/g, '0')
    .replace(/<\?=[\s\S]*?\?>/g, '0');
  const tmp = process.env.TEMP + '\\_scriptcheck_' + Math.random().toString(36).slice(2) + '.js';
  fs.writeFileSync(tmp, js);
  let msg;
  try {
    execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' });
    msg = 'parses OK';
  } catch (err) {
    bad++;
    const out = String(err.stderr || '');
    const m = out.match(/_scriptcheck_\w+\.js:(\d+)/);
    const lineNo = m ? parseInt(m[1], 10) : 0;
    const fileLine = lineNo ? k + 1 + lineNo : 0;
    const ctx = out.split('\n').slice(2, 6).map((s) => s.trim()).join(' | ');
    msg = 'PARSE ERROR at extracted line ' + lineNo
        + (fileLine ? ' (file line ' + fileLine + ')' : '') + '  ' + ctx;
    if (fileLine) {
      for (let q = Math.max(0, fileLine - 5); q < Math.min(L.length, fileLine + 3); q++) {
        msg += '\n        ' + (q + 1) + ' ' + L[q];
      }
    }
  }
  try { fs.unlinkSync(tmp); } catch (e) { /* best effort */ }
  console.log(f.padEnd(34) + msg);
}
process.exit(bad ? 1 : 0);