// Document-request wizard browser probe.
//
// Walks the wizard the way a student does - pick a document, fill the
// details, look at the uploads, review, tick the boxes - and reports
// what actually happened at each step.
//
// Static reading cannot answer the questions that matter here. A
// screen that renders empty, a total that never updates, a Next button
// wired to nothing: all of them look fine in the source and are broken
// in the browser.
//
//   node tests/doc_wizard_probe.js
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');
const http = require('node:http');
const { execFileSync } = require('node:child_process');

const PHP = 'C:/xampp/php/php.exe';
const ROOT = path.resolve(__dirname, '..');
const PAGE = 'http://localhost/registrar-ai-system/student/document-request.php';
const OUT = path.join(os.tmpdir(), 'doc-wizard-probe');

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

// ── 1. Mint a session for a real student, and read its cookie ──
//
// Puppeteer is launched with no cookie of its own, so it would land on
// the login page and every step below would fail for the wrong reason.
function makeSession() {
    const php = path.join(ROOT, 'tests', 'wizard_session_helper.php');
    const out = execFileSync(PHP, [php], { encoding: 'utf8' });
    const data = JSON.parse(out.trim().split(/\r?\n/).pop());
    return data;
}

const puppeteer = require('puppeteer');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-'));

// The state fixtures from tools/_seed_states.php are deliberately
// persistent, so a human can see every status on screen. One of them is
// a Draft for this student, and the wizard resumes that draft instead of
// opening a blank one - which is correct behaviour, and makes every
// "clean slate" assertion below fail for the wrong reason.
//
// Cleared here rather than worked around, because a check that passes
// with or without a pre-existing draft is not checking anything.
function clearFixtures(tracking) {
    const php = path.join(ROOT, 'tests', 'clear_doc_fixtures.php');
    const args = tracking ? [php, tracking] : [php];
    try {
        const out = execFileSync(PHP, args, { encoding: 'utf8' });
        return out.trim();
    } catch (e) {
        return 'could not clear fixtures: ' + e.message;
    }
}

// The request this run filed, so it can be removed again.
//
// Without this the probe leaks one real row per run into the database,
// where it then appears on the registrar desk as a genuine student
// request - and, because the desk is a shared queue, it quietly changes
// what a human sees when they go to look. The tracking number is the
// handle: it is on the confirmation screen and it is unique, so the
// cleanup cannot touch a row the probe did not create.

let session;
try {
    console.log('  ' + clearFixtures());
    session = makeSession();
} catch (e) {
    console.error('RESULT=NO_SESSION ' + e.message);
    process.exit(1);
}

// The tracking number of the request this run files, once it has one.
// null until the submit succeeds.
let filedTracking = null;

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    const browserInstance = await puppeteer.launch({
        executablePath: browser,
        headless: 'new',
        userDataDir: profile,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--window-size=1500,1050'],
        defaultViewport: { width: 1500, height: 1050 },
    });

    const page = await browserInstance.newPage();

    const consoleErrors = [];
    const pageErrors = [];
    const failedRequests = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => pageErrors.push(String(e.message || e)));
    page.on('requestfailed', (r) => failedRequests.push(r.url() + ' :: ' + (r.failure() || {}).errorText));
    page.on('response', (r) => {
        if (r.status() >= 400) failedRequests.push(r.status() + ' ' + r.url());
    });

    await browserInstance.setCookie({
        name: session.name, value: session.id, domain: 'localhost', path: '/',
    });

    const results = [];
    const step = (label, ok, detail) => {
        results.push({ label, ok, detail: detail || '' });
        console.log((ok ? '  ok   ' : '  FAIL ') + label + (ok || !detail ? '' : '  <- ' + detail));
    };

    try {
        await page.goto(PAGE, { waitUntil: 'networkidle2', timeout: 45000 });
        step('page loads', true);
        step('no JS errors on load', pageErrors.length === 0, pageErrors.join(' | '));

        // ── Step 0: the document picker ───────────────────
        await page.waitForSelector('.dk-choice', { timeout: 10000 });
        const cardCount = await page.$$eval('.dk-choice', (els) => els.length);
        step('document cards rendered', cardCount === 7, 'got ' + cardCount);

        // Search must narrow the list, and must survive being cleared.
        await page.type('#wrDocSearch', 'enrollment');
        await page.waitForFunction(() => document.querySelectorAll('.dk-choice').length < 7, { timeout: 5000 });
        const searched = await page.$$eval('.dk-choice', (els) => els.map((e) => e.querySelector('.dk-choice-name').textContent));
        step('search narrows the list', searched.length < 7 && searched.length > 0, JSON.stringify(searched));
        step('search finds Certificate of Enrollment',
            searched.some((s) => /enrollment/i.test(s)), JSON.stringify(searched));

        // A nonsense search must show the empty state, not a blank grid.
        await page.$eval('#wrDocSearch', (el) => { el.value = ''; });
        await page.type('#wrDocSearch', 'zzzqqq');
        await page.waitForFunction(
            () => /Nothing matches/i.test(document.getElementById('wrDocGrid').textContent),
            { timeout: 5000 });
        step('nonsense search shows an empty state', true);

        await page.$eval('#wrDocSearch', (el) => { el.value = ''; });
        await page.evaluate(() => document.getElementById('wrDocSearch').dispatchEvent(new Event('input')));
        await page.waitForSelector('.dk-choice', { timeout: 5000 });

        await page.screenshot({ path: path.join(OUT, '01-picker.png') });

        // Continue must be disabled until something is chosen.
        const disabledBefore = await page.$eval('#wrPickNext', (el) => el.disabled);
        step('Continue is disabled before a choice', disabledBefore === true);

        // Pick TOR: per-page fee, and the document with a checklist.
        const torIdx = await page.$$eval('.dk-choice', (els) =>
            els.findIndex((e) => /Transcript/i.test(e.querySelector('.dk-choice-name').textContent)));
        step('Transcript of Records is in the list', torIdx >= 0, 'index ' + torIdx);

        await page.evaluate((i) => document.querySelectorAll('.dk-choice')[i].click(), torIdx);
        await page.waitForSelector('[data-screen="1"]:not([hidden])', { timeout: 5000 });
        step('picking a document advances to details', true);

        // ── Step 1: details ──────────────────────────────
        await page.waitForSelector('#wrQuote .dk-bill-a[data-total]', { timeout: 5000 });
        const total1 = await page.$eval('#wrQuote .dk-bill-a[data-total]', (el) => el.textContent.trim());
        step('a total is shown on the details step', /\d/.test(total1), total1);
        step('one TOR page is 250', /250/.test(total1), total1);

        // The quantity stepper must recalculate live.
        await page.click('#wrQtyPlus');
        await page.waitForFunction(() => {
            const el = document.querySelector('#wrQuote .dk-bill-a[data-total]');
            return el && /500/.test(el.textContent);
        }, { timeout: 5000 });
        const total2 = await page.$eval('#wrQuote .dk-bill-a[data-total]', (el) => el.textContent.trim());
        step('quantity stepper recalculates the total live', /500/.test(total2), total2);

        const qtyRows = await page.$$eval('.dk-bill-row', (els) => els.length);
        step('the breakdown shows its working', qtyRows >= 1, qtyRows + ' line(s)');

        // There is no delivery choice and no address block. Asserted as
        // counts rather than as a click: a step that clicks a selector
        // matching nothing passes silently, which is how "the courier card
        // no longer exists" passed for as long as it was written as
        // "click the courier card".
        const delivery = await page.evaluate(() => ({
            radios: document.querySelectorAll('[name=wr_fulfillment]').length,
            addressBlock: document.getElementById('wrAddressBlock') ? 1 : 0,
            addressFields: document.querySelectorAll('[id^=wrAddr]').length,
        }));
        step('no fulfillment radios exist', delivery.radios === 0, delivery.radios + ' radio(s)');
        step('no address block exists', delivery.addressBlock === 0, String(delivery.addressBlock));
        step('no address fields exist', delivery.addressFields === 0, delivery.addressFields + ' field(s)');

        const liveTotal = await page.$eval('#wrQuote .dk-bill-a[data-total]', (el) => el.textContent.trim());
        step('the live total carries no courier charge', !/650/.test(liveTotal), liveTotal);

        await page.screenshot({ path: path.join(OUT, '02-details.png') });

        // Fill in the purpose, the way a student would. Without it the
        // API correctly refuses to submit with a 422 - which is the
        // right behaviour, so the probe has to supply it.
        await page.select('#wrPurposeCode', 'employment');
        await page.waitForFunction(
            () => document.getElementById('wrPurposeCode').value === 'employment',
            { timeout: 5000 });
        step('choosing a purpose is reflected in the control', true);

        const purposeHint = await page.$eval('#wrPurposeHint', (el) => el.textContent);
        step('choosing a purpose updates the guidance below it',
            /Employment/i.test(purposeHint), purposeHint);

        await page.type('#wrPurpose', 'Applying for a marine engineering position');
        await page.type('#wrNotes', 'Please call the registrar office if anything else is needed.');

        // ── Step 2: uploads ──────────────────────────────
        // Clicked through the DOM rather than with page.click(). The
        // panel scrolled itself with behaviour:'smooth' on the step
        // change, and puppeteer's actionability check samples the
        // element's box mid-animation and decides it is not there.
        await page.evaluate(() => document.querySelector('[data-goto="2"]').click());
        await page.waitForSelector('[data-screen="2"]:not([hidden])', { timeout: 5000 });
        step('advances to uploads', true);

        const reqRows = await page.$$eval('.dk-req-row', (els) => els.length);
        step('TOR shows its requirement checklist', reqRows === 4, reqRows + ' rows');

        const warnText = await page.$eval('#wrMissingNotice', (el) => el.textContent.trim());
        step('missing requirements are called out on entry',
            /Still to attach/i.test(warnText), warnText.slice(0, 90));
        step('the notice names the School ID', /Valid School ID/.test(warnText), warnText.slice(0, 140));
        step('the notice says submitting without them is allowed',
            /submit without these/i.test(warnText), warnText.slice(0, 220));

        const uploadBtns = await page.$$eval('[data-upload]', (els) => els.length);
        step('each unmet requirement has an Upload button', uploadBtns === 4, uploadBtns + ' buttons');

        await page.screenshot({ path: path.join(OUT, '03-uploads.png') });

        // ── Step 3: review ───────────────────────────────
        await page.evaluate(() => document.querySelector('[data-screen="2"] [data-goto="3"]').click());
        await page.waitForSelector('[data-screen="3"]:not([hidden])', { timeout: 5000 });
        step('advances to review', true);

        // The purpose control must not render empty: an empty <select>
        // reads as a broken control and the student cannot tell it
        // wants an answer.
        const purposeOptions = await page.$$eval('#wrPurposeCode option', (els) => els.length);
        step('purpose select is populated, with a placeholder', purposeOptions === 7,
            purposeOptions + ' options');
        const purposeShown = await page.$eval('#wrPurposeCode', (el) => el.selectedIndex >= 0 && el.value);
        step('purpose select shows its placeholder, not blank',
            purposeShown === '' || typeof purposeShown === 'string', JSON.stringify(purposeShown));

        const summary = await page.$eval('#wrReviewSummary', (el) => el.textContent);
        step('review shows the document', /Transcript/i.test(summary), summary.slice(0, 90));
        // No delivery row to assert on, so the absence is what matters:
        // a stale row reading 'Delivery' is exactly the bug this removal
        // exists to prevent, and a positive check would have missed it.
        step('review states no delivery method',
            !/Delivery|Courier|Deliver to/i.test(summary), summary.slice(0, 160));

        const reviewTotal = await page.$eval('#wrReviewQuote .dk-bill-a[data-total]', (el) => el.textContent.trim());
        step('review total matches the details total', /500/.test(reviewTotal), reviewTotal);

        // Submit must be disabled until both boxes are ticked.
        const subDisabled = await page.$eval('#wrSubmitBtn', (el) => el.disabled);
        step('Submit is disabled before the boxes are ticked', subDisabled === true);

        await page.click('#wrCertifyTrue');
        const subStillDisabled = await page.$eval('#wrSubmitBtn', (el) => el.disabled);
        step('Submit stays disabled with only one box', subStillDisabled === true);

        await page.click('#wrCertifyPrivacy');
        await page.waitForFunction(() => !document.getElementById('wrSubmitBtn').disabled, { timeout: 5000 });
        step('Submit enables once both boxes are ticked', true);

        await page.screenshot({ path: path.join(OUT, '04-review.png') });

        // ── Submit ───────────────────────────────────────
        await page.click('#wrSubmitBtn');
        await page.waitForSelector('[data-screen="5"]:not([hidden])', { timeout: 25000 });
        step('submitting reaches the confirmation', true);

        const tracking = await page.$eval('#wrDoneTracking', (el) => el.textContent.trim());
        step('a tracking number is shown', /^DOC-\d{4}-\d{4}$/.test(tracking), tracking);

        // Kept for the cleanup at the end of the run.
        filedTracking = /^DOC-\d{4}-\d{4}$/.test(tracking) ? tracking : null;
        step('the run knows what to clean up', filedTracking !== null, tracking);

        const doneFacts = await page.$eval('#wrDoneFacts', (el) => el.textContent);
        step('confirmation states the estimated release', /Estimated release/i.test(doneFacts), doneFacts.slice(0, 140));

        const nextSteps = await page.$eval('#wrDoneNext', (el) => el.textContent);
        step('confirmation explains what happens next', /Registrar verifies/i.test(nextSteps), nextSteps.slice(0, 120));
        // "about Oct 13, 2026" is a date with a hedge bolted in front of
        // it. Where the date is known it is stated plainly.
        step('the release line states the date without hedging it',
            !/about [A-Z][a-z]{2} \d/.test(nextSteps), nextSteps.match(/about [A-Z][a-z]{2} \d[^)]*/) || '');

        // .href, not getAttribute('href'): the attribute is what the server
// sent, which is root-relative ("/registrar-ai-system/student/..."),
// while .href is what the browser resolved it to against the current
// page. Reading the attribute and handing it to page.goto() navigates
// to a bare path with no origin, which puppeteer rejects.
const trackHref = await page.$eval('#wrTrackLink', (el) => el.href);
        step('the tracking link resolves to a real URL',
            /^https?:\/\/[^/]+\/.*document-track\.php\?id=\d+$/.test(trackHref), trackHref);
        step('the tracking link carries the request id',
            /[?&]id=\d+(&|$)/.test(trackHref), trackHref);

        await page.screenshot({ path: path.join(OUT, '05-confirmation.png') });

        // ── The tracking page, followed for real ─────────
        const res = await page.goto(trackHref, { waitUntil: 'networkidle2', timeout: 30000 });
        step('the tracking page loads', res.status() === 200, 'status ' + res.status());

        const tl = await page.$$eval('.wr-tl-item', (els) => els.map((e) => ({
            state: e.className.replace('wr-tl-item', '').trim(),
            label: ((e.querySelector('.wr-tl-label') || {}).textContent || '').trim(),
            // The note is its own element, not part of the label. Reading
            // it out of the label (as this probe first did) always gave
            // '' and made a correct page look broken.
            note: ((e.querySelector('.wr-tl-note') || {}).textContent || '').trim(),
        })));
        step('the timeline has six steps', tl.length === 6, tl.length + ' steps');
        step('submission is marked done',
            tl[0] && /done/.test(tl[0].state), JSON.stringify(tl[0] || {}));
        // This request was filed with the default payment method - pay
        // at the counter - so the payment step must NOT be the live one.
        // It used to be: the timeline said "Waiting on you" while the
        // status box above it said "With the Registrar", and the student
        // had no way to tell which to believe.
        step('payment is not the live step when paying at the counter',
            tl[1] && !/active/.test(tl[1].state), JSON.stringify(tl[1] || {}));
        step('the payment step says when it is due instead',
            tl[1] && /pay at the counter/i.test(tl[1].note), JSON.stringify(tl[1] || {}));
        // "Queued at the Registrar" is legitimately done: the filing event
// recorded the Filed status at submission time. So the live step is the
// NEXT one - being prepared - not the queue itself.
step('the queue step is done, since the request was filed',
            tl[2] && /done/.test(tl[2].state), JSON.stringify(tl[2] || {}));
step('the live step is preparation, not payment',
            tl[3] && /active/.test(tl[3].state), JSON.stringify(tl[3] || {}));
step('exactly one step is live',
            tl.filter((t) => /active/.test(t.state)).length === 1,
            tl.filter((t) => /active/.test(t.state)).length + ' active');
        step('the timeline names the tracking number',
            (await page.content()).includes(tracking), tracking);

        await page.screenshot({ path: path.join(OUT, '06-tracking.png') });

        // ── Mobile ───────────────────────────────────────
        await page.setViewport({ width: 390, height: 844, isMobile: true });
        await page.reload({ waitUntil: 'networkidle2', timeout: 30000 });
        const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth);
        step('no horizontal overflow at 390px', overflow <= 2, overflow + 'px');
        await page.screenshot({ path: path.join(OUT, '07-mobile.png') });

    } catch (e) {
        step('probe completed without throwing', false, String(e.message || e));
        try { await page.screenshot({ path: path.join(OUT, 'error.png') }); } catch (_) {}
    }

    // ── Noise that would mask a real failure ──────────────
    const realConsoleErrors = consoleErrors.filter((e) =>
        !/favicon|Failed to load resource: the server responded with a status of 404/.test(e));
    step('no unexpected console errors', realConsoleErrors.length === 0,
        realConsoleErrors.slice(0, 3).join(' | '));
    step('no uncaught page errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
    step('no failed network requests', failedRequests.length === 0,
        failedRequests.slice(0, 4).join(' | '));

    // Remove the request this run filed, before anything else - including
    // on the failure path. A probe that leaves a row behind changes what
    // the next person sees on the desk, so a failed run must not be
    // dirtier than a passing one.
    console.log('  ' + clearFixtures(filedTracking));

    const failed = results.filter((r) => !r.ok);
    console.log('\n----------------------------------------');
    console.log('passed: ' + (results.length - failed.length) + '   failed: ' + failed.length);
    console.log('screenshots: ' + OUT);

    await browserInstance.close();
    fs.rmSync(profile, { recursive: true, force: true });
    process.exit(failed.length === 0 ? 0 : 1);
})().catch((e) => {
    console.error('PROBE_CRASH ' + (e.stack || e));
    process.exit(1);
});