// Build a printable preview of the one-page brief, using the REAL
// rendering code from js/bcp-letterhead.js, so the page count is a
// measurement rather than a claim.
//
//   node tests/preview_brief.js
//   then open ai/_brief_preview.html and print it (Ctrl+P)
//
// The report here is written to the PROMPT CONTRACT'S CEILING - seven
// sections, 700-1000 words, the Executive Summary at its longest and the
// conclusion with its full set of actions. If the brief fits this, it fits
// anything shorter, which is the only direction that matters.
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');

// Load the letterhead for real, so the preview carries the same CSS the
// printed sheet will - including the .brief rhythm.
global.window = { location: { href: 'http://localhost/registrar-ai-system/ai/insights.php' } };
global.document = {
    createElement: () => ({ style: {}, setAttribute() {}, addEventListener() {} }),
    body: { appendChild() {} },
};
// Indirect eval, plus an explicit hand-off. The file opens with 'use
// strict', and under strict mode a var declared inside an eval belongs to
// that eval's own scope — so BCPPrint never reaches globalThis on its own.
// The trailing assignment is what actually hands it over; without it the
// preview would fall back to no CSS and report a confident, meaningless
// page count.
(0, eval)(fs.readFileSync(path.join(root, 'js/bcp-letterhead.js'), 'utf8')
    + '\n;globalThis.BCPPrint = BCPPrint;');
const BCPPrint = globalThis.BCPPrint;
if (!BCPPrint) {
    console.error('bcp-letterhead.js did not expose BCPPrint — the preview would not');
    console.error('be using the real CSS, so its page count would be meaningless.');
    process.exit(1);
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function inlineFormat(s) { return s; }

// The same helpers openPrintView() uses, same behaviour.
function splitReportSections(report) {
    const body = [];
    const out = { body, summary: null, conclusion: null };
    let current = null;
    String(report).split('\n').forEach((line) => {
        const numbered = /^##\s+(\d+)\.\s*(.+)$/.exec(line);
        const heading = /^##\s+(.+)$/.exec(line);
        if (numbered || heading) {
            current = numbered
                ? { num: parseInt(numbered[1], 10), title: numbered[2].trim(), lines: [] }
                : { num: 0, title: heading[1].trim(), lines: [] };
            if (current.num === 7) out.conclusion = current;
            else if (current.num === 1) out.summary = current;
            else body.push(current);
        } else if (current) current.lines.push(line);
    });
    return out;
}

function briefFiguresHtml(markdown) {
    const groups = [];
    let current = null;
    String(markdown).split('\n').forEach((raw) => {
        const line = raw.trim();
        if (!line) return;
        const group = /^\*\*(.+)\*\*$/.exec(line);
        if (group) {
            current = { section: group[1].replace(/[:.]+$/, ''), rows: [] };
            groups.push(current);
            return;
        }
        const item = /^-\s+(.+)$/.exec(line);
        if (!item || !current) return;
        const nested = /^\s/.test(raw);
        const text = item[1].replace(/^\s*-\s*/, '');
        if (/^Counts for /i.test(text)) return;
        if (nested) return;
        if (/^By\b/.test(text)) return;
        const cut = text.indexOf(':');
        current.rows.push(cut === -1
            ? { label: text, value: '' }
            : { label: text.slice(0, cut).trim(), value: text.slice(cut + 1).trim() });
    });
    const kept = groups.filter((g) => g.rows.length);
    if (!kept.length) return '';
    const total = kept.reduce((n, g) => n + g.rows.length + 1, 0);
    let run = 0, cut = kept.length;
    for (let i = 0; i < kept.length; i++) {
        run += kept[i].rows.length + 1;
        if (run >= total / 2) { cut = i + 1; break; }
    }
    const column = (list) => {
        let h = '<table class="doc-figures"><tbody>';
        list.forEach((g) => {
            h += '<tr class="g"><th colspan="2">' + inlineFormat(esc(g.section)) + '</th></tr>';
            g.rows.forEach((r) => {
                h += '<tr><td>' + inlineFormat(esc(r.label)) + '</td>'
                    + '<td class="v">' + inlineFormat(esc(r.value)) + '</td></tr>';
            });
        });
        return h + '</tbody></table>';
    };
    return '<div class="doc-figures-wrap">'
        + column(kept.slice(0, cut)) + column(kept.slice(cut)) + '</div>';
}

function sectionLinesHtml(lines) {
    let html = '', inList = false;
    lines.forEach((raw) => {
        const line = raw.trim();
        if (/^[-*]\s+/.test(line)) {
            if (!inList) { html += '<ul>'; inList = true; }
            html += '<li>' + inlineFormat(esc(line.replace(/^[-*]\s+/, ''))) + '</li>';
        } else {
            if (inList) { html += '</ul>'; inList = false; }
            if (line) html += '<p>' + inlineFormat(esc(line)) + '</p>';
        }
    });
    if (inList) html += '</ul>';
    return html;
}

// ── A report at the contract's ceiling ─────────────────────────
// Section 1 is deliberately held to what the prompt contract actually asks
// for - four to six sentences. An earlier draft of this preview used three
// long paragraphs, over 350 words, and that is not what section 1 is ever
// going to contain. A preview built on an inflated summary measures a
// document that cannot exist.
const para = 'The figure moved against the same period last year, and the movement is '
    + 'concentrated in one module rather than spread evenly across the office, which is '
    + 'what makes it worth acting on this term rather than at the next review. ';
const s = (n, title, repeat) => '## ' + n + '. ' + title + '\n\n' + para.repeat(repeat) + '\n\n';

const report = s(1, 'Executive Summary', 3)
    + s(2, 'Student Population and Registration', 2)
    + s(3, 'Programme Mix', 2)
    + s(4, 'Document Services', 2)
    + s(5, 'RFID and Campus Cards', 2)
    + s(6, 'Queue Operations', 2)
    + '## 7. Cross-Module Findings and Recommended Actions\n'
    + '- Document backlog against queue peaks; card coverage against enrolment.\n'
    + '- Coverage gaps sit where registration and card issuance are run by different offices.\n'
    + '- Request types cluster in one week of each term.\n'
    + '- Clearance waiting time tracks adviser endorsement, not payment.\n\n'
    + '1. Clear the adviser endorsement queue weekly, in one sitting.\n'
    + '2. Issue the card at the point of registration rather than separately.\n'
    + '3. Publish the weekly request volume so the desk can plan.\n\n'
    + '- The largest uncertainty is which queue timestamps were entered by hand.\n';

// ── The figures, at a realistic size ───────────────────────────
const figures = [
    'Counts for October 2026, compared with September 2026. Every figure below is a direct count from the database.',
    '',
    '**Students**',
    '- On record: 151',
    '- Active or enrolled: 125 (83%)',
    '- New registrations: 12 (previous period: 8)',
    '- By status: enrolled 49, active 40, graduate 22, alumni 17, dropped 23',
    '- Programmes on offer: 8',
    '  - Bachelor of Science in Information Technology (BSIT): 40 students (4 new this period)',
    '  - Bachelor of Science in Computer Engineering (BSCpE): 30 students (2 new this period)',
    '  - Bachelor of Science in Accounting Information System (BSAIS): 25 students (2 new this period)',
    '  - Bachelor of Science in Business Administration (BSBA): 20 students (1 new this period)',
    '  - Bachelor of Science in Tourism Management (BSTM): 18 students (1 new this period)',
    '',
    '**Document services**',
    '- Requests: 100 (previous period: 64; +36)',
    '- Not yet ready: 55',
    '- Ready, shipped or claimed: 27',
    '- Rejected: 3',
    '- By workflow stage: Filed 30, Pending_Clearance 15, Processing 15, Ready 12, Awaiting_Payment 10, Shipped 8, Claimed 7, Rejected 3',
    '- By document type: transcript 24, certificate 20, good_moral 18, clearance 19, form137 19',
    '',
    '**RFID / campus cards**',
    '- Cards on record: 96',
    '- Active: 71 (74%)',
    '- Expired: 12; lost: 4; inactive: 9',
    '- Issued this period: 6 (previous period: 3)',
    '',
    '**Queue operations**',
    '- Served: 240 (previous period: 198)',
    '- Cancelled or no-show: 31',
    '- Walk-ins: 46',
].join('\n');

const parts = splitReportSections(report);
const periodLabel = 'October 2026';

// A real logo URL, relative to the PREVIEW's own location in ai/. An empty
// string here is what made the first preview come out with no crest at all
// - the letterhead handles a missing logo gracefully, so nothing errored
// and nothing said the crest was missing. It only showed up by looking.
//
// Two different bases on purpose. logoUrl is resolved by the browser from
// ai/_brief_preview.html, so it needs the '../'. The existence check runs
// from this script's own directory and must NOT carry it, or it looks for
// the logo one level above the project.
const logoUrl = '../assets/images/BCP_LOGO.png';
if (!fs.existsSync(path.join(root, 'assets/images/BCP_LOGO.png'))) {
    console.error('BCP_LOGO.png not found — the preview would print with no crest,');
    console.error('which is exactly the failure this script exists to catch.');
    process.exit(1);
}

const body = ''
    + BCPPrint.headerHtml({ logoUrl: logoUrl, title: 'AI INSIGHT SUMMARY — ' + periodLabel.toUpperCase() })
    + '<div class="meta">Reporting period: ' + esc(periodLabel)
    + ' (compared with September 2026) &middot; Generated: ' + new Date().toLocaleString() + '</div>'
    + '<h2 class="doc-h">Measured Figures</h2>' + briefFiguresHtml(figures)
    + '<div class="doc-body"><h2 class="doc-h">Executive Summary</h2>'
    + sectionLinesHtml(parts.summary.lines) + '</div>'
    + '<div class="doc-body doc-conclusion"><h2 class="doc-h">Conclusion and Recommended Actions</h2>'
    + sectionLinesHtml(parts.conclusion.lines) + '</div>'
    + '<div class="sig"><div class="box"><div class="line">Prepared by:<br>Registrar</div></div>'
    + '<div class="box"><div class="line">Noted by:<br>School Head / President</div></div></div>'
    + '<div class="foot-note">Generated by: Registrar Information System<br>'
    + 'AI-generated information is provided for administrative reference.<br>'
    + 'Full analysis: seven sections, available from the AI Insight page.</div>';

const doc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
    + '<title>One-page brief preview</title><style>'
    + BCPPrint.letterheadCss()
    + '</style></head><body class="brief">'
    + body
    + '</body></html>';

const target = path.join(root, 'ai/_brief_preview.html');
fs.writeFileSync(target, doc, 'utf8');

const words = (s) => String(s).trim().split(/\s+/).filter(Boolean).length;
console.log('report words        :', words(report));
console.log('summary words       :', words(parts.summary.lines.join(' ')));
console.log('conclusion words    :', words(parts.conclusion.lines.join(' ')));
console.log('figures ledger rows :', (briefFiguresHtml(figures).match(/<tr>/g) || []).length);

// ── An ESTIMATE of the printed height, not a measurement ───────
// There is no headless browser in this project, so the page count cannot be
// read off directly. This is a character-width estimate under stated
// assumptions, and it is printed so a wrong assumption is visible rather
// than buried.
//
// It exists because "trust me, it is one page" is exactly the kind of claim
// that should not be taken on faith, and because the previous two-page
// version of this sheet was shipped on exactly that kind of claim.
const PT_PER_MM = 2.8346;
const A4_H = 297 * PT_PER_MM;
const USABLE = A4_H - (12 + 2) * PT_PER_MM;   // the .brief top/bottom padding
const COL_PT = (210 - 28) * PT_PER_MM;          // A4 width less .brief padding
const BODY_PT = 10.5, BODY_LH = 1.45;

// Average glyph advance for the body face, as a fraction of font size.
// Calibrated against the serif stack in the letterhead; errs high, which is
// the safe direction - an over-estimate will not claim a fit that fails.
const AVG_ADV = 0.47;
const linesFor = (text, widthPt) =>
    Math.max(1, Math.ceil(String(text).length * (BODY_PT * AVG_ADV) / widthPt));

let h = 0;
// The crest beside the name block is one grid row, and its height is set
// by the three name lines beside it - not by the crest on top of them.
// Stacking them was tried and reverted: it added a whole row of height,
// which on the one-page brief is the scarcest space on the sheet.
h += 96;                                    // crest + name + unit + address, one row
h += 30;                                    // the .meta period line
h += 28;                                    // "Measured Figures" heading
// Ledger: two columns, so the taller column sets the height.
const ledgerRows = (briefFiguresHtml(figures).match(/<tr>/g) || []).length;
h += Math.ceil(ledgerRows / 2) * (9 * 1.35 + 3);
h += 28;                                    // "Executive Summary" heading
h += linesFor(parts.summary.lines.join(' '), COL_PT) * (BODY_PT * BODY_LH) + 14;
h += 38;                                    // conclusion top rule + heading
h += linesFor(parts.conclusion.lines.join(' '), COL_PT) * (BODY_PT * BODY_LH) + 14;
h += 55;                                    // signature block
h += 35;                                    // foot-note

const pct = Math.round((h / USABLE) * 100);
console.log('');
console.log('estimated height    :', Math.round(h) + 'pt of', Math.round(USABLE) + 'pt usable A4');
console.log('                    ', pct + '% of one page');
console.log('');
if (pct > 100) {
    console.log('ESTIMATE SAYS IT OVERFLOWS. Do not ship it as-is.');
} else {
    console.log('Estimate says it fits. Still open the preview and confirm —');
    console.log('this is arithmetic, not a page count.');
}
console.log('Open', target, 'and press Ctrl+P.');
console.log('If it runs past one page, the fix is fewer figures, not smaller type.');