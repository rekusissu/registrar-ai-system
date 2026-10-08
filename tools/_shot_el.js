// tools/_shot_el.js -- screenshot one element by selector, on a page that
// needs a session.
//
// The ledger table sits below a long stack of furniture, so a viewport
// shot of the top of the page never shows it. This clips to the element.
//
//   node tools/_shot_el.js <out.png> <session-helper.php> <url-path> <css-selector>
'use strict';

const { execFileSync } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PHP = 'C:/xampp/php/php.exe';
const ROOT = path.resolve(__dirname, '..');
const BASE = 'http://localhost/registrar-ai-system';

const [, , out, helper, urlPath, selector] = process.argv;
if (!out || !helper || !urlPath || !selector) {
    console.error('usage: node _shot_el.js <out.png> <helper.php> <url-path> <css-selector>');
    process.exit(2);
}

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
];
const browserPath = CANDIDATES.find((p) => fs.existsSync(p));
if (!browserPath) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const s = JSON.parse(execFileSync(PHP, [path.join(ROOT, 'tests', helper)], { encoding: 'utf8' })
    .trim().split(/\r?\n/).pop());

const puppeteer = require('puppeteer');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'shotel-'));

(async () => {
    const browser = await puppeteer.launch({
        executablePath: browserPath,
        userDataDir: profile,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        headless: 'new',
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1200, deviceScaleFactor: 1 });
    await page.goto('about:blank');
    await page.setCookie({ name: s.name, value: s.id, domain: 'localhost', path: '/' });
    await page.goto(BASE + urlPath, { waitUntil: 'networkidle2', timeout: 45000 });
    await new Promise((r) => setTimeout(r, 800));

    const el = await page.$(selector);
    if (!el) { console.error('selector not found: ' + selector); process.exit(1); }
    fs.mkdirSync(path.dirname(out), { recursive: true });
    await el.screenshot({ path: out });
    console.log('shot ' + out);

    await browser.close();
    fs.rmSync(profile, { recursive: true, force: true });
})().catch((e) => { console.error(e); process.exit(1); });