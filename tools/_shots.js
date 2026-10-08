// tools/_shots.js -- screenshot the document-request surfaces as they are
// today, so a redesign is aimed at what is actually on screen rather than
// at what the markup implies.
//
//   node tools/_shots.js <outDir>
'use strict';

const { execFileSync } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PHP = 'C:/xampp/php/php.exe';
const ROOT = path.resolve(__dirname, '..');
const BASE = 'http://localhost/registrar-ai-system';
const OUT = process.argv[2] || path.join(os.tmpdir(), 'doc-shots-before');

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
];
const browserPath = CANDIDATES.find((p) => fs.existsSync(p));
if (!browserPath) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

fs.mkdirSync(OUT, { recursive: true });

function session(page) {
    const php = path.join(ROOT, 'tests', page);
    const out = execFileSync(PHP, [php], { encoding: 'utf8' });
    return JSON.parse(out.trim().split(/\r?\n/).pop());
}

const puppeteer = require('puppeteer');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'shots-'));

(async () => {
    const browser = await puppeteer.launch({
        executablePath: browserPath,
        userDataDir: profile,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        headless: 'new',
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });

    const shots = [
        ['wizard', 'wizard_session_helper.php', '/student/document-request.php', 1],
        ['wizard-details', 'wizard_session_helper.php', '/student/document-request.php', 2],
        ['wizard-review', 'wizard_session_helper.php', '/student/document-request.php', 4],
        ['list', 'wizard_session_helper.php', '/student/documents.php', null],
        ['track', 'wizard_session_helper.php', '/student/document-track.php', null],
        ['desk', 'desk_session_helper.php', '/registrar/documents.php', null],
    ];

    for (const [label, helper, url, step] of shots) {
        let s;
        try { s = session(helper); } catch (e) { console.log('skip ' + label + ': ' + e.message); continue; }
        await page.goto('about:blank');
        const cname = s.name;
        const cvalue = s.id;
        if (!cname || !cvalue) { console.log('skip ' + label + ': no session'); continue; }
        await page.setCookie({ name: cname, value: cvalue, domain: 'localhost', path: '/' });

        let target = BASE + url;
        if (url.includes('document-request.php')) {
            target += '?step=' + step;
        }
        if (url.includes('document-track.php')) {
            try {
                const id = execFileSync(PHP, [path.join(ROOT, 'tools', '_newest_request_id.php')], { encoding: 'utf8' }).trim();
                if (/^\d+$/.test(id)) target += '?id=' + id;
            } catch (e) { /* fall through to whatever the page defaults to */ }
        }
        try {
            await page.goto(target, { waitUntil: 'networkidle2', timeout: 45000 });
        } catch (e) {
            console.log('skip ' + label + ': ' + e.message);
            continue;
        }
        await new Promise((r) => setTimeout(r, 900));
        const file = path.join(OUT, label + '.png');
        await page.screenshot({ path: file, fullPage: false });
        console.log('shot ' + file);
    }

    await browser.close();
    fs.rmSync(profile, { recursive: true, force: true });
    console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });