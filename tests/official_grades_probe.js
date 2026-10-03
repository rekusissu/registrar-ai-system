// Renders registrar/academic-history.php and runs the REAL printGradeTemplate
// through the REAL BCPPrint, capturing the document body instead of printing
// it. This is the check the screenshot cannot make: whether the official
// record repeats a term, drops one, or prints an empty one is a question
// about the built markup, not about how it looks.
//
//   node tests/official_grades_probe.js [term]
//
// Usage from the repo root, after:
//   php tests/_render_academic_history.php "2025-2026 1st"
'use strict';

const fs = require('fs');
const path = require('path');

const htmlPath = path.join(__dirname, '..', 'ah_render.html');
if (!fs.existsSync(htmlPath)) {
    console.error('  render ah_render.html first (php tests/_render_academic_history.php)');
    process.exit(1);
}
const html = fs.readFileSync(htmlPath, 'utf8');

// The payload the page ships to the browser.
const payload = html.match(/const AH\s*=\s*(\{[\s\S]*?\});/);
if (!payload) { console.error('  could not find the AH payload'); process.exit(1); }
const AH = JSON.parse(payload[1]);

// The page's own inline <script>, which holds printGradeTemplate and helpers.
const blocks = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const page = blocks.find(b => b.includes('function printGradeTemplate'));
if (!page) { console.error('  could not find the print script'); process.exit(1); }

// BCPPrint, reduced to capture. The real module is loaded in a browser and
// writes into an iframe; here we only need headerHtml and a printDocument
// that keeps the body so it can be inspected.
let captured = null;
global.BCPPrint = {
    letterheadCss: () => '',
    headerHtml: (o) => '<div class="letterhead">' + (o && o.title ? o.title : '') + '</div>',
    footerHtml: () => '',
    esc: (s) => String(s == null ? '' : s),
    printDocument: (opts) => { captured = (opts && opts.body) || ''; return true; },
};

// A DOM stub complete enough for the page's own event wiring. The script is
// evaluated WHOLE and unmodified, so the functions under test are the shipped
// ones and not a reconstruction that could drift from them.
const noop = () => {};
const fakeEl = () => ({
    addEventListener: noop, removeEventListener: noop, setAttribute: noop,
    getAttribute: () => null, classList: { add: noop, remove: noop, contains: () => false },
    querySelector: () => null, querySelectorAll: () => [], appendChild: noop,
    style: {}, dataset: {}, hidden: false,
});
global.document = {
    getElementById: () => null, querySelector: () => null, querySelectorAll: () => [],
    addEventListener: noop, removeEventListener: noop, createElement: fakeEl,
    body: fakeEl(), readyState: 'complete',
};
// node 24 defines `navigator` as a getter-only global, so it cannot be
// assigned. It is passed in as a parameter instead, which shadows the global
// for the evaluated script without touching it.
global.window = {
    location: { href: 'http://localhost/registrar/academic-history.php' },
    addEventListener: noop,
};
const fakeNavigator = { userAgent: 'probe' };

// Evaluate the page's script verbatim, then lift printGradeTemplate out.
//
// No AH parameter: the page declares `const AH = {...}` itself, and passing
// one in as well is a redeclaration. The parsed payload above is used only for
// this probe's own assertions.
new Function('navigator', page + '\n; globalThis.__print = printGradeTemplate;'
    + '\n; globalThis.__official = printOfficial;')
    (fakeNavigator);

if (typeof global.__print !== 'function') { console.error('  printGradeTemplate not found'); process.exit(1); }

// Print the first student that actually has a record.
const row = AH.rows.find(r => r.career && r.career.length);
if (!row) { console.error('  no student has records'); process.exit(1); }
global.__print(row.id);

if (!captured) { console.error('  printDocument was not called'); process.exit(1); }

// Dump the built document so it can be read, not just counted. Several of the
// checks below answer "how many"; this answers "what does it say", which is
// what a duplicate term or a stray flag actually is.
const dump = path.join(__dirname, '..', 'ah_print_body.html');
fs.writeFileSync(dump, captured);
console.log('  document body  : ' + path.basename(dump) + ' (' + captured.length + ' bytes)\n');

// -- What did it actually print? ----------------------------------------
// The class is no longer the whole opening tag: the heading now carries a
// page-break style, so a pattern anchored on `class="gt-term-head">` matches
// nothing and every count reads zero. Match the attribute run up to the ">".
const headsOf = b => [...b.matchAll(/<div class="gt-term-head"([^>]*)>([\s\S]*?)<\/div>/g)]
    .map(m => ({ page: /page-break-before/.test(m[1]), text: m[2].replace(/<[^>]+>/g, '').trim() }));

const heads = headsOf(captured);
const grids = (captured.match(/class="gt-grid"/g) || []).length;
const flags = (captured.match(/class="gt-flag"/g) || []).length;
const rowsPrinted = (captured.match(/<tr>/g) || []).length;

console.log('  student        : ' + row.name);
console.log('  career terms   : ' + row.career.length);
console.log('  term headings  : ' + heads.length);
heads.forEach((h, i) => console.log('      ' + (i + 1) + '. ' + h.text));
console.log('  subject grids  : ' + grids);
console.log('  table rows     : ' + rowsPrinted);
console.log('  gwa flags      : ' + flags);

const dupes = heads.filter((h, i) => heads.findIndex(o => o.text === h.text) !== i);
console.log('  duplicate head : ' + (dupes.length ? dupes.map(d => d.text).join(' | ') : 'none'));

const cumulative = (captured.match(/Cumulative GWA<\/span><b>([^<]*)/) || [])[1];
const units = (captured.match(/Total units earned<\/span><b>([^<]*)/) || [])[1];
console.log('  cumulative gwa : ' + (cumulative || 'MISSING'));
console.log('  total units    : ' + (units || 'MISSING'));

// -- The crest -----------------------------------------------------------
// The letterhead logo is the one thing on this document that has to be
// fetched, and it is fetched into an about:blank iframe where a relative
// path cannot resolve. Checked here against the file that actually exists
// on disk, because "the img tag is present" says nothing about whether the
// browser can load it.
const logoPath = AH.logoPath || '';
const resolved = logoPath
    ? new URL(logoPath, global.window.location.href).pathname
    : '';
const onDisk = path.join(__dirname, '..', 'assets', 'images', 'BCP_LOGO.png');
const logoOk = fs.existsSync(onDisk) && /assets\/images\/BCP_LOGO\.png$/.test(resolved);
console.log('  logoPath       : ' + logoPath);
console.log('  resolves to    : ' + resolved);
console.log('  file on disk   : ' + (fs.existsSync(onDisk) ? 'yes' : 'NO'));
console.log('  crest will load: ' + (logoOk ? 'yes' : 'NO -- the letterhead prints blank'));
if (!logoOk) { process.exit(1); }

// Subjects present vs subjects on file: a term heading with no grid means the
// document listed a term it could not show grades for.
const expected = Object.values(row.careerSubjects || {}).reduce((n, a) => n + a.length, 0);
console.log('  subjects onfile: ' + expected + '  printed in grids: ' + (rowsPrinted - grids));

// -- The three print scopes ---------------------------------------------
// Each is run for real. The one that matters most is "all", because its
// pagination depends on the terms being RE-SORTED by semester first - the
// payload interleaves them by school year, and without the sort a "two page"
// request produces three page breaks and four pages.
function runScope(scope) {
    captured = null;
    global.__official(row.id, scope);
    const b = captured || '';
    const heads = headsOf(b);
    return {
        body: b,
        heads,
        breaks: heads.filter(h => h.page).length,
        rows: (b.match(/<tr>/g) || []).length,
        cumulative: /Cumulative GWA/.test(b),
        partialNote: /not the student'?s complete academic record/.test(b),
    };
}

const all3 = runScope('all');
console.log('\n  -- scope: all --');
console.log('  term headings  : ' + all3.heads.length);
all3.heads.forEach(h => console.log('      ' + (h.page ? '[PAGE BREAK] ' : '') + h.text));
console.log('  page breaks    : ' + all3.breaks + '  (want 1 - one per semester after the first)');
console.log('  cumulative GWA : ' + (all3.cumulative ? 'present' : 'MISSING'));

const s1 = runScope('1st');
const s2 = runScope('2nd');
console.log('\n  -- scope: 1st / 2nd --');
console.log('  1st headings   : ' + s1.heads.length + ' -> ' + s1.heads.map(h => h.text).join(' | '));
console.log('  2nd headings   : ' + s2.heads.length + ' -> ' + s2.heads.map(h => h.text).join(' | '));
console.log('  1st rows       : ' + s1.rows + '   2nd rows: ' + s2.rows);
console.log('  1st cumulative : ' + (s1.cumulative ? 'present (wrong)' : 'absent (right)'));
console.log('  1st marked part: ' + (s1.partialNote ? 'yes' : 'NO'));

// Expected: one term each side, one page break in "all", no cumulative on the
// partial prints. Anything else and the chooser is lying about what it does.
const scopeOk = all3.heads.length === 2
    && all3.breaks === 1
    && all3.cumulative
    && s1.heads.length === 1
    && s2.heads.length === 1
    && !s1.cumulative
    && !s2.cumulative
    && s1.partialNote && s2.partialNote;
console.log('\n  all three scopes: ' + (scopeOk ? 'correct' : '*** WRONG ***'));
if (!scopeOk) { process.exit(1); }

// -- No letter grades -----------------------------------------------------
// The A / B+ / C / F column was removed. Assert it stays removed: a document
// showing both a letter and a numeric rating asserts two answers to one
// question, and the numeric one is the only one termGwa() ever averaged.
const letters = (captured.match(/<td>\s*[A-F][+-]?\s*<\/td>/g) || []).length
    + ((runScope('1st').body.match(/<td>\s*[A-F][+-]?\s*<\/td>/g) || []).length);
console.log('\n  letter-grade cells: ' + letters + (letters === 0 ? '  (none, correct)' : '  *** PRESENT ***'));
if (letters !== 0) { process.exit(1); }

process.exit(dupes.length ? 1 : 0);