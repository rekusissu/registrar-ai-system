// Checks the dashboard still renders after the stat cards and the recent
// activity feed were removed, and that the charts that were kept actually
// draw.
//
//   node tests/dash_probe.js <port> <cookie> <outPrefix>
//
// The page had no probe of its own, which is how a deleted block could take
// the two canvas charts with it unnoticed - the canvas element is in the
// markup either way, so a broken chart looks fine in a screenshot of the
// source. This asserts the drawn bitmap, not the tag.
//
// Reads only: the page is loaded with the caller's cookie and the probe
// never clicks, so it writes nothing.
// ============================================================
'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9690);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'dash';
const BASE = 'http://localhost/registrar-ai-system/dashboard.php';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-dash-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1500,1200', 'about:blank'],
    { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
function fail(msg) { console.log('  FAIL: ' + msg); failures++; }
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

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

    await send('Page.navigate', { url: BASE });
    for (let i = 0; i < 60; i++) {
        await sleep(150);
        if (await ev("document.readyState === 'complete' && !!document.querySelector('.dashboard-container')").catch(() => false)) break;
    }
    // Chart.js animates in; measuring the first frame can catch it at zero.
    await sleep(1500);

    const view = JSON.parse(await ev(`JSON.stringify({
        hero: document.querySelectorAll('.dash-hero').length,
        statCards: document.querySelectorAll('.stat-card').length,
        statGrid: document.querySelectorAll('.stats-grid').length,
        activity: document.querySelectorAll('.activity-item, .activity-list').length,
        panels: Array.prototype.map.call(document.querySelectorAll('.card-title'), function (t) {
            return t.textContent.trim();
        }),
        canvases: Array.prototype.map.call(document.querySelectorAll('canvas'), function (c) {
            var b = c.getBoundingClientRect();
            return { id: c.id, w: Math.round(b.width), h: Math.round(b.height),
                     px: c.width + 'x' + c.height };
        }),
        overflowX: document.documentElement.scrollWidth - window.innerWidth,
    })`));
    console.log('  ' + JSON.stringify(view));

    if (view.statCards || view.statGrid) fail('the stat cards are still on the page');
    if (view.activity) fail('the recent activity feed is still on the page');

    // The panels that were meant to stay.
    ['Enrollment Trend', 'Course Distribution', 'Student Status Overview',
     'RFID & Documents', 'Key Performance Metrics', 'Live Queue'].forEach((t) => {
        if (!view.panels.some((p) => p.includes(t))) fail(`the ${t} panel is missing`);
    });

    // A canvas tag survives a deleted chart; a drawn bitmap does not. width and
    // height are set by Chart.js from the measured box, so a chart that never
    // ran leaves them at the 300x150 default while the element is styled to
    // something else entirely.
    for (const c of view.canvases) {
        if (c.w < 40 || c.h < 40) fail(`chart ${c.id} has no size (${c.w}x${c.h})`);
        const [bw, bh] = c.px.split('x').map(Number);
        if (bw === 300 && bh === 150) fail(`chart ${c.id} never drew - still at the 300x150 default`);
        const ink = await ev(`(function(){
            var c = document.getElementById(${JSON.stringify(c.id)});
            var g = c.getContext('2d');
            var d = g.getImageData(0, 0, c.width, c.height).data;
            var n = 0;
            for (var i = 3; i < d.length; i += 4) if (d[i] > 0) n++;
            return Math.round(n / (d.length / 4) * 100);
        })()`);
        console.log(`  chart ${c.id}: ${c.w}x${c.h} css, bitmap ${c.px}, ${ink}% painted`);
        if (!ink) fail(`chart ${c.id} painted nothing`);
    }
    if (view.overflowX > 1) fail(`page overflows by ${view.overflowX}px`);

    const s = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(`${OUT}.png`, Buffer.from(s.data, 'base64'));
    console.log(`SHOT=${OUT}.png`);

    console.log(jsErrors.length ? '  JS ERRORS: ' + jsErrors.join(' | ') : '  js errors: none');
    if (jsErrors.length) failures++;
    console.log(failures ? `RESULT=FAIL (${failures})` : 'RESULT=PASS');
    done(failures ? 1 : 0);
})().catch((e) => { console.error('RESULT=ERR ' + e.message); done(1); });
