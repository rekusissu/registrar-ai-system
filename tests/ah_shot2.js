// ============================================================
//  TESTS/ah_shot2.js  (design review, not part of the suite)
//
//  Screenshots the redesigned Academic History page so the layout can be
//  LOOKED at rather than inferred from the DOM.
//
//    node tests/ah_shot2.js <port> <cookie> <out.png>
//
//  Also opens the first student's record, because the record is the part
//  most likely to be wrong and the part a DOM assertion checks least.
// ============================================================

'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9670);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'ah_design';

const PAGE = 'http://localhost/registrar-ai-system/registrar/academic-history.php';
const CANDS = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
];
const browser = CANDS.find(p => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-shot2-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox',
    '--no-first-run', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--window-size=1440,1100', 'about:blank'], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));
function done(c) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(c);
}

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = l.find(t => t.type === 'page');
        } catch (_) {}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(r => (ws.onopen = r));
    let id = 0; const pending = new Map();
    const send = (m, p) => {
        const i = ++id;
        ws.send(JSON.stringify({ id: i, method: m, params: p }));
        return new Promise((res, rej) => pending.set(i, { res, rej, method: m }));
    };
    ws.onmessage = e => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            // The method name travels with the rejection. A bare CDP error is
            // two sentences about a params blob and gives the reader no way to
            // tell which of a dozen calls went wrong.
            m.error ? p.rej(new Error(p.method + ' ' + JSON.stringify(m.error)))
                    : p.res(m.result);
        }
    };
    await send('Page.enable');
    await send('Runtime.enable');

    // Collect page errors so a broken script shows up here instead of as a
    // blank region nobody can explain.
    const errors = [];
    ws.addEventListener('message', e => {
        const m = JSON.parse(e.data);
        if (m.method === 'Runtime.exceptionThrown') {
            errors.push(m.params.exceptionDetails.text + ' '
                + (m.params.exceptionDetails.exception || {}).description);
        }
    });

    const kv = COOKIE.split('=');
    // url was added as a REQUIRED field to Network.setCookie in Chrome 106.
    // Without it the call is rejected with "mandatory field missing", which
    // reads as a bug in this probe and is not one.
    await send('Network.setCookie', {
        name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/',
        url: PAGE
    });

    const ev = async x => (await send('Runtime.evaluate',
        { expression: x, returnByValue: true, awaitPromise: true })).result.value;

    await send('Page.navigate', { url: PAGE });
    await sleep(3500);

    // Fonts must be settled before capture, or Fraunces falls back and the
    // screenshot misrepresents the design.
    await ev('document.fonts.ready.then(()=>1)');
    await sleep(800);

    let shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_top.png', Buffer.from(shot.data, 'base64'));

    // Open the first record.
    // Checked the way the page itself does — through #recordSheet, which is the
    // one element that is either open or not now that the record lives in a
    // sheet. A descendant query like `tr[data-ah-detail]:not([hidden])` looked
    // reasonable against the old expanded row and reported DID_NOT_OPEN on a
    // record that was demonstrably open, which is worse than no check at all:
    // it teaches you to distrust the probe.
    const opened = await ev(`(function(){
        // Open the first student who actually HAS grades. Picking rows[0]
        // picks whoever sorts first, and "this student has no grades" is a
        // correct state that tells you nothing about whether the record
        // renders. The empty state is checked on its own below.
        var btns = Array.prototype.slice.call(
            document.querySelectorAll('tr[data-ah-row] .ah-view'));
        var b = null;
        for (var i = 0; i < btns.length; i++) {
            var r = rowById(parseInt(btns[i].dataset.view, 10));
            if (r && r.subjects && r.subjects.length) { b = btns[i]; break; }
        }
        if (!b) return 'NO_ROW_WITH_GRADES';
        b.click();
        var sheet = document.getElementById('recordSheet');
        if (!sheet) return 'NO_SHEET';
        if (sheet.hidden) return 'STILL_HIDDEN';
        var body = document.getElementById('sheetBody');
        var grid = body ? body.querySelector('.table') : null;
        var title = document.getElementById('sheetTitle');
        var sub = document.getElementById('sheetSub');
        return 'OPEN rows=' + (grid ? grid.querySelectorAll('tbody tr').length : 0)
            + ' titled=' + (title ? title.textContent.trim() : 'NO_TITLE')
            + ' sub="' + (sub ? sub.textContent.trim() : 'NO_SUB') + '"'
            + ' print=' + (body && body.querySelector('[data-print-menu]') ? 'yes' : 'no');
    })()`);
    await sleep(900);

    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_record.png', Buffer.from(shot.data, 'base64'));

    console.log('record: ' + opened);

    // The print chooser, opened from the sheet. It is the third surface this
    // page owns and a DOM check reads almost nothing about it — the whole
    // design is that the sheet glyphs stack as many sheets as they will print,
    // which only a picture shows.
    const chooser = await ev(`(function(){
        var m = document.querySelector('#sheetBody [data-print-menu]');
        if (!m) return 'NO_PRINT_BUTTON';
        m.click();
        var modal = document.getElementById('printModal');
        if (!modal) return 'NO_MODAL';
        if (!modal.classList.contains('active')) return 'NOT_ACTIVE';
        var g = Array.prototype.map.call(
            modal.querySelectorAll('.ah-sheet-glyph'),
            function (x) { return x.dataset.sheets; });
        return 'OPEN glyphs=' + JSON.stringify(g)
            + ' who="' + document.getElementById('printWho').textContent.trim() + '"'
            + ' counts=' + ['printCount1st','printCount2nd','printCountAll']
                .map(function (i) { return document.getElementById(i).textContent; })
                .join(' / ');
    })()`);
    await sleep(700);

    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_print.png', Buffer.from(shot.data, 'base64'));
    console.log('print: ' + chooser);

    // Narrow viewport. The sheet is the only fixed-position surface this page
    // owns, so it is the one that has to survive a phone. The roster sheds
    // columns at these widths; the View column must NOT be one of them, or a
    // registrar on a phone can see a student and be unable to open them.
    await ev(`(function(){
        var c = document.querySelector('#printModal .modal-close');
        if (c) c.click();
        return 1;
    })()`);
    await sleep(400);
    await send('Emulation.setDeviceMetricsOverride',
        { width: 390, height: 780, deviceScaleFactor: 2, mobile: true });
    await sleep(800);

    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_narrow.png', Buffer.from(shot.data, 'base64'));

    const narrow = await ev(`(function(){
        var panel = document.querySelector('.ah-sheet-panel');
        var view = document.querySelector('tr[data-ah-row] .ah-view');
        var body = document.getElementById('sheetBody');
        var tbl = body ? body.querySelector('.table') : null;
        var heads = tbl ? Array.prototype.map.call(
            tbl.querySelectorAll('thead th'),
            function (h) {
                return h.textContent.trim() + ':' + Math.round(h.getBoundingClientRect().right);
            }) : [];
        return JSON.stringify({
            panelW: panel ? Math.round(panel.getBoundingClientRect().width) : null,
            viewport: window.innerWidth,
            // The sheet must not be wider than the screen it is drawn on.
            fitsX: panel ? panel.getBoundingClientRect().width <= window.innerWidth : null,
            viewButtonVisible: view
                ? getComputedStyle(view).display !== 'none' : 'NO_BUTTON',
            // The record table must not run past the panel's own right edge, or
            // the last column - Result, the pass/fail - is cut off with no way
            // to reach it. Reported per column because "too wide" is not
            // actionable on its own.
            tableOverflow: tbl
                ? Math.round(tbl.scrollWidth - body.clientWidth) : null,
            heads: heads,
            panelRight: panel ? Math.round(panel.getBoundingClientRect().right) : null
        });
    })()`);
    console.log('narrow: ' + narrow);
    await send('Emulation.clearDeviceMetricsOverride');

    // The term audit. It shares .ah-dialog-head, .ah-dialog-kicker and
    // .ah-dialog-body with the print chooser, and those three had no rules at
    // all before this change - so the header it renders was never styled
    // either, and fixing one dialog fixes both. Worth a picture, because a
    // shared-class change that looks right in one place can land wrong in the
    // other.
    await ev(`(function(){
        var b = document.querySelector('[data-audit], .ah-head [class*="audit"]');
        if (b) b.click();
        return b ? 1 : 0;
    })()`);
    await sleep(800);
    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_audit.png', Buffer.from(shot.data, 'base64'));
    const audit = await ev(`(function(){
        var m = document.getElementById('auditModal');
        var head = m ? m.querySelector('.ah-dialog-head') : null;
        var close = head ? head.querySelector('.modal-close') : null;
        var who = head ? head.querySelector('.ah-dialog-kicker') : null;
        var r = close ? close.getBoundingClientRect() : null;
        return JSON.stringify({
            active: m ? m.classList.contains('active') : 'NO_MODAL',
            // The close button must sit on the SAME line as the kicker. Before
            // this change it fell below the block beside it, because the head
            // was an unstyled block and the button is inline-flex.
            closeTop: r ? Math.round(r.top) : null,
            kickerTop: who ? Math.round(who.getBoundingClientRect().top) : null,
            headDisplay: head ? getComputedStyle(head).display : null
        });
    })()`);
    console.log('audit: ' + audit);

    // Facts about the click path itself. The state below tells us the row
    // closed again, but not WHY, and a record that opens and silently
    // shuts is worse than one that never opens.
    const why = await ev(`(function(){
        var b = document.querySelector('tr[data-ah-row] .ah-view');
        var raw = b.dataset.view;
        var sheet = document.getElementById('recordSheet');

        // Which globals survived? The page script is top-level, so an
        // exception anywhere in it stops everything after that point —
        // while function DECLARATIONS are hoisted and still appear to
        // exist. That combination is exactly what made this look like
        // "the listener is registered but never called": it never was.
        function present(n) { return typeof window[n] !== 'undefined'; }
        var defined = ['rowById','ratingBand','recordHtml','openRecord',
                       'closeRecord','openDialog','closeDialog','findingHtml',
                       'openAudit','closeAudit','printGradeTemplate']
            .filter(present);

        // Re-run the inline script body with the error surfaced. Wrapping in
        // a function gives it a scope so re-declarations are harmless.
        var bodies = Array.prototype.map.call(
            document.querySelectorAll('script:not([src])'),
            function (x) { return x.textContent; });
        var mine = bodies.filter(function (t) {
            return t.indexOf('function openRecord') !== -1; })[0];

        var err = null;
        if (mine) {
            try { new Function(mine)(); } catch (e) { err = e.message; }
        }

        return JSON.stringify({
            definedGlobals: defined,
            scriptFound: !!mine,
            reRunError: err,
            rawAttr: raw,
            hiddenAfterLoad: sheet ? sheet.hidden : 'NO_SHEET'
        });
    })()`);
    console.log('why: ' + why);

    // Full state after the click, so a mismatch is visible rather than
    // reduced to one word. Includes the roster's own geometry: the sheet is
    // only worth having if opening it does not reflow the list behind it.
    const after = await ev(`(function(){
        var sheet = document.getElementById('recordSheet');
        var body = document.getElementById('sheetBody');
        var rows = document.querySelectorAll('tr[data-ah-row]');
        var first = rows[0];
        return JSON.stringify({
            sheetHidden: sheet.hidden,
            sheetDisplay: getComputedStyle(sheet).display,
            bodyLen: body ? body.innerHTML.length : -1,
            rosterRows: rows.length,
            // If the first row moved, the sheet is reflowing the term and the
            // whole reason for the drawer is gone.
            firstRowTop: first ? Math.round(first.getBoundingClientRect().top) : null
        });
    })()`);
    console.log('state: ' + after);
    console.log('js errors: ' + (errors.length ? errors.join(' | ') : 'none'));
    console.log('SHOTS=' + OUT + '_top.png,' + OUT + '_record.png,' + OUT + '_print.png');
    done(errors.length ? 1 : 0);
})().catch(e => { console.error('ERR ' + e.message); done(1); });