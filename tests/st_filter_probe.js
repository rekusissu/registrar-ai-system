// Screenshots the Status Tracker toolbar and drives the four-facet filter:
// Year, Program, Semester, Section.
//
//   node tests/st_filter_probe.js <port> <cookie> <outPrefix> [query]
//
// The optional query is a bare string, e.g. "year[]=1&program[]=BSIT" - the
// "?" is added here.
//
// It replaced a probe written against a strip of four year buttons and a
// single-select program dropdown. Those are gone; the claim now is that one
// panel offers four tick lists, that ticking survives a submit, and that a
// second ticked value inside one facet WIDENS rather than replaces - which is
// the whole reason these are checkboxes and not radios.
//
// Same CDP approach as modal_probe.js (no Playwright in this project).
// Writes no database rows: the page is loaded with the caller's cookie and
// the probe only reads the DOM and ticks boxes. Submitting is a GET.
// ============================================================
'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9470);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'stfilter';
const QUERY = process.argv[5] || '';
const BASE = 'http://localhost/registrar-ai-system/registrar/status-tracker.php';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const WIDTHS = [[1500, 1000, 'wide'], [1000, 900, 'narrow'], [560, 900, 'mobile']];
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-stfilter-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1500,1000', 'about:blank'],
    { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}
let failures = 0;
function fail(msg) { console.log('  FAIL: ' + msg); failures++; }

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = l.find((t) => t.type === 'page');
        } catch (_) {}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => (ws.onopen = r));
    let id = 0; const pending = new Map();
    const send = (m, p) => { const i = ++id; ws.send(JSON.stringify({ id: i, method: m, params: p }));
        return new Promise((res, rej) => pending.set(i, { res, rej })); };
    ws.onmessage = (e) => { const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); } };

    await send('Page.enable'); await send('Network.enable'); await send('Runtime.enable');
    const jsErrors = [];
    ws.addEventListener('message', (e) => {
        const m = JSON.parse(e.data);
        if (m.method === 'Runtime.exceptionThrown') {
            const d = m.params.exceptionDetails;
            jsErrors.push((d.exception && (d.exception.description || d.exception.value)) || d.text);
        }
    });
    if (COOKIE) {
        const kv = COOKIE.split(';')[0].split('=');
        await send('Network.setCookie', { name: kv[0].trim(), value: kv.slice(1).join('='),
            domain: 'localhost', path: '/' });
    }

    async function ev(expr) {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.text);
        return r.result.value;
    }
    async function shot(name) {
        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${OUT}_${name}.png`, Buffer.from(s.data, 'base64'));
        console.log(`SHOT=${OUT}_${name}.png`);
    }
    // Wait for the control rather than sleeping: this URL carries a
    // 50-character degree title, so a fixed wait reads a half-built document
    // and reports it as a missing control.
    async function go(url, settle) {
        // A falsy url means "wait for the current document": used after clicking
        // something that navigates, where the reload is already in flight
        // and there is no URL left to ask for.
        if (url) await send('Page.navigate', { url });
        const sel = settle || '.st-facets';
        for (let i = 0; i < 60; i++) {
            await sleep(150);
            if (await ev(`document.readyState === 'complete' && !!document.querySelector('${sel}')`).catch(() => false)) {
                await sleep(200);
                return;
            }
        }
    }

    // ── The toolbar, at three widths ───────────────────────────
    for (const [w, h, tag] of WIDTHS) {
        await send('Emulation.setDeviceMetricsOverride',
            { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 });
        await go(BASE + (QUERY ? '?' + QUERY : ''));

        const geo = JSON.parse(await ev(`(function(){
            var bar = document.querySelector('.st-dirbar');
            var r = bar.getBoundingClientRect();
            var btn = document.querySelector('.st-facets-btn').getBoundingClientRect();
            return JSON.stringify({
                barH: Math.round(r.height),
                filterRight: Math.round(btn.right),
                viewport: window.innerWidth,
                overflowX: document.documentElement.scrollWidth - window.innerWidth,
                // The year strip and the program select are both supposed to be
                // gone. Asserted rather than assumed - a leftover strip is
                // invisible in a passing screenshot.
                yearStrip: document.querySelectorAll('.st-year').length,
                progsel: document.querySelectorAll('.st-progsel').length,
                facets: document.querySelectorAll('.st-facets').length,
            });
        })()`));
        console.log(`  ${tag} ${w}px: ` + JSON.stringify(geo));

        if (geo.yearStrip !== 0) fail(`${tag}: the 1/2/3/4 year strip is still in the DOM`);
        if (geo.progsel !== 0) fail(`${tag}: the old program select is still in the DOM`);
        if (geo.facets !== 1) fail(`${tag}: expected one filter control, found ${geo.facets}`);
        if (geo.overflowX > 1) fail(`${tag}: page overflows by ${geo.overflowX}px`);
        if (geo.filterRight > geo.viewport + 1) fail(`${tag}: the filter runs past the viewport`);
        await shot(tag);
    }

    // ── The panel ─────────────────────────────────────────────
    await send('Emulation.setDeviceMetricsOverride',
        { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
    await go(BASE);

    const shape = JSON.parse(await ev(`(function(){
        var box = document.getElementById('stFacets');
        box.open = true;
        var sets = {};
        Array.prototype.forEach.call(box.querySelectorAll('.st-facet'), function (f) {
            sets[f.querySelector('legend').textContent.trim()] = f.querySelectorAll('input[type=checkbox]').length;
        });
        var p = document.querySelector('.st-facets-panel').getBoundingClientRect();
        return JSON.stringify({
            open: box.open,
            facets: sets,
            panelW: Math.round(p.width),
            panelOnScreen: (function () {
                var l = Math.round(p.left), r = Math.round(p.right), b = Math.round(p.bottom);
                return { left: l, right: r, bottom: b, vw: window.innerWidth, vh: window.innerHeight };
            })(),
            // Every box must be inside a <form>, or Apply posts nothing.
            inForm: !!box.closest('form'),
            names: Array.prototype.map.call(box.querySelectorAll('input[type=checkbox]'), function (i) { return i.name; })
                       .filter(function (v, i, a) { return a.indexOf(v) === i; }),
        });
    })()`));
    console.log('  panel: ' + JSON.stringify(shape));
    if (!shape.open) fail('the panel did not open');
    if (!shape.inForm) fail('the filter panel is not inside the form, so Apply posts nothing');
    const box = shape.panelOnScreen;
    if (box.left < 0 || box.right > box.vw + 1 || box.bottom > box.vh + 1) {
        fail(`the panel runs off screen: ${JSON.stringify(box)}`);
    }
    // Every facet must be PRESENT, with a caption, whatever the roster holds.
    // "Section" having no options is a fact about the data, not a broken
    // control - it renders "None recorded yet" and stays tickable the moment
    // a section exists.
    ['Year', 'Program', 'Semester', 'Section'].forEach((f) => {
        if (!(f in shape.facets)) fail(`the ${f} facet is missing from the panel`);
    });
    await shot('panel');

    // Tick two years. If these were radios the second click would clear the
    // first, and the whole point of a facet is that 1st OR 2nd year is a
    // question worth asking.
    const before = await ev(`document.querySelectorAll('.st-facet-opt input:checked').length`);
    const ticked = await ev(`(function(){
        var boxes = document.querySelectorAll('input[name="year[]"]');
        boxes[0].click(); boxes[1].click();
        return Array.prototype.filter.call(boxes, function (b) { return b.checked; })
            .map(function (b) { return b.value; }).join(',');
    })()`);
    const after = await ev(`document.querySelectorAll('.st-facet-opt input:checked').length`);
    console.log('  ticked years: ' + ticked);
    if (before !== 0) fail('the panel came up with boxes already ticked');
    if (after !== 2) fail(`two years ticked left ${after} ticked - these behave like radios`);
    // The badge is the only thing saying a filter is in force once the panel
    // is closed. It only appears after a submit, which is correct: ticking is
    // not filtering yet.
    if (await ev(`!!document.querySelector('.st-facets-badge')`)) {
        fail('the count badge appeared before anything was applied');
    }
    await shot('panel_ticked');

    // Apply. This navigates, so it is the last thing done to this document.
    await ev("document.querySelector('.st-facets-apply').click()");
    await go(null, '.st-facets');   // wait for the reload to land

    const applied = JSON.parse(await ev(`(function(){
        return JSON.stringify({
            url: location.search,
            checked: Array.prototype.filter.call(
                document.querySelectorAll('.st-facet-opt input'), function (i) { return i.checked; })
                .map(function (i) { return i.name + '=' + i.value; }),
            badge: (document.querySelector('.st-facets-badge') || {}).textContent,
            open: document.getElementById('stFacets').open,
            rows: document.querySelectorAll('.st-table-wrap tbody tr').length,
            matched: (document.querySelector('.st-pager') || {}).textContent || '',
        });
    })()`));
    console.log('  applied: ' + JSON.stringify(applied));
    if (!/year/.test(decodeURIComponent(applied.url))) fail('the years did not reach the URL');
    if (applied.checked.length !== 2) fail(`came back with ${applied.checked.length} boxes ticked, not 2`);
    if (!applied.badge) fail('no count badge after applying, so the filter in force is invisible');
    // The panel stays open on a filtered load, so the reader can see what is
    // narrowing the table instead of having to reopen the control to find out.
    if (!applied.open) fail('the panel closed itself on a filtered load');
    await shot('applied');

    // Reset is only rendered when something is ticked, and it must clear all
    // four facets without dropping the search or the status filter.
    const reset = await ev(`(function(){
        var a = document.querySelector('.st-facets-reset');
        return a ? a.getAttribute('href') : null;
    })()`);
    console.log('  reset href: ' + reset);
    if (reset === null) fail('no Reset link on a filtered view');
    else if (/year|program|semester|section/.test(reset)) fail(`Reset still carries facets: ${reset}`);

    console.log(jsErrors.length ? '  JS ERRORS: ' + jsErrors.join(' | ') : '  js errors: none');
    if (jsErrors.length) failures++;

    console.log(failures ? `RESULT=FAIL (${failures})` : 'RESULT=PASS');
    done(failures ? 1 : 0);
})().catch((e) => { console.error('RESULT=ERR ' + e.message); done(1); });
