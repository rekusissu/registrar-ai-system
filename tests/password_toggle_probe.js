// ============================================================
//  TESTS/PASSWORD_TOGGLE_PROBE.JS
//
//    node tests/password_toggle_probe.js <debugPort> <url> [cookie]
//
//  Reports whether each password toggle button carries both icon classes.
//
//  The double eye is an <i> holding fa-eye AND fa-eye-slash at once. Font
//  Awesome draws one glyph per element, so on some versions that stacks as a
//  visible pair and on others the toggle simply stops responding - the
//  handlers read that same class list to decide which way the field faces, so
//  a button holding both is a button whose state is ambiguous.
//
//  Reading the rendered DOM is the only way to catch it: the source markup is
//  correct and only the toggle handler produces the bad state.
//
//  Speaks DevTools over a WebSocket to an installed browser, so the project
//  gains no dependency. Node 22+ ships a global WebSocket.
// ============================================================
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9466);
const PAGE = process.argv[3] || 'http://localhost/registrar-ai-system/login.php';
const COOKIE = process.argv[4] || '';

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'pwtoggle-cdp-'));
const child = spawn(browser, [
    '--headless=new', `--remote-debugging-port=${PORT}`,
    '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore' });

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(250);
        try {
            const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = list.find((t) => t.type === 'page');
        } catch (e) { /* still starting */ }
    }
    if (!target) { child.kill(); console.error('RESULT=NO_TARGET'); process.exit(1); }

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    let id = 0;
    const pending = new Map();
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        ws.send(JSON.stringify({ id: n, method, params }));
    });
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
        }
    };
    await new Promise((r) => { ws.onopen = r; });

    await send('Page.enable');
    await send('Runtime.enable');
    if (COOKIE) {
        const kv = COOKIE.split(';')[0].split('=');
        await send('Network.enable');
        await send('Network.setCookie', {
            name: kv[0].trim(), value: kv.slice(1).join('='),
            domain: 'localhost', path: '/',
        });
    }
    await send('Page.navigate', { url: PAGE });
    await sleep(1800);

    const expr = `(() => {
        const out = [];
        const sels = ['.pw-toggle', '.password-toggle', '.password-toggle-btn', '[data-password-toggle]'];
        const seen = new Set();
        for (const sel of sels) {
            document.querySelectorAll(sel).forEach((btn) => {
                if (seen.has(btn)) return;
                seen.add(btn);
                const icon = btn.querySelector('i');
                if (!icon) return;
                const cls = icon.className || '';
                // Whole tokens only. indexOf('fa-eye') is true for
                // "fa-eye-slash" too, so a substring test reports a perfectly
                // correct icon as carrying both classes - which is exactly the
                // false positive this probe started with.
                const toks = cls.split(/\s+/).filter(Boolean);
                const wrap = btn.closest('.pw-field-wrap, .password-field-shell, .password-field, .form-group') || btn.parentElement;
                const inp = wrap ? wrap.querySelector('input') : null;
                out.push({
                    sel: sel,
                    cls: cls,
                    both: toks.indexOf('fa-eye') !== -1 && toks.indexOf('fa-eye-slash') !== -1,
                    input: inp ? (inp.id || inp.name || '(unnamed)') : null,
                    // The browser draws its OWN reveal eye inside a
                    // type="password" field. That is the double the user sees,
                    // and it is only present when the field is a real password
                    // input AND the page adds its own toggle button.
                    native: inp ? inp.type === 'password' : false,
                    inputType: inp ? inp.type : null,
                });
            });
        }
        return JSON.stringify(out);
    })()`;

    // Read at rest BEFORE clicking anything.
    const rBefore = await send('Runtime.evaluate', { expression: expr, returnByValue: true });
    const rows = JSON.parse(rBefore.result.value || '[]');

    // Click every toggle once and look again. This is the state that matters:
    // the two-class list is only ever produced AFTER a handler runs, so a
    // check that reads the page at rest cannot see the bug at all.
    await send('Runtime.evaluate', {
        expression: `(() => {
            const seen = new Set();
            ['.pw-toggle','.password-toggle','.password-toggle-btn','[data-password-toggle]']
              .forEach(sel => document.querySelectorAll(sel).forEach(b => {
                  if (seen.has(b)) return; seen.add(b); b.click();
              }));
            return 'clicked';
        })()`,
        returnByValue: true,
    });
    await sleep(700);

    const rAfter = await send('Runtime.evaluate', { expression: expr, returnByValue: true });
    const after = JSON.parse(rAfter.result.value || '[]');

    let bad = 0;
    if (!rows.length) {
        console.log('RESULT=NO_TOGGLE  (' + PAGE + ')');
    } else {
        const report = (list, when) => {
            for (const row of list) {
                if (row.both) bad++;
                // A real type="password" input makes Chrome and Firefox draw
                // their own reveal eye inside the field. That is invisible to
                // the DOM but sits in the same corner as the page's own button,
                // so the user sees two eyes. login.php already works around it
                // by making its field type="text" with a CSS mask; every other
                // page still uses type="password" and so carries both.
                const note = row.native ? '  <-- NATIVE EYE TOO' : '';
                if (row.native) bad++;
                console.log('  ' + (row.both ? 'DOUBLE' : 'ok    ') + ' [' + when + '] ' + row.sel
                    + '  input=' + row.input + ' type=' + row.inputType + '  class="' + row.cls + '"' + note);
            }
        };
        report(rows, 'rest');
        report(after, 'after click');
        console.log(bad
            ? 'RESULT=DOUBLE_ICON ' + bad + ' bad class list(s)'
            : 'RESULT=OK ' + rows.length + ' toggle(s), none carries both classes');
    }

    ws.close();
    child.kill();
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* best effort */ }
    process.exit(bad ? 1 : 0);
})().catch((e) => {
    console.error('RESULT=ERROR ' + e.message);
    child.kill();
    process.exit(1);
});