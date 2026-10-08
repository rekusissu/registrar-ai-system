// tests/desk_probe.js - drive the registrar desk in a real browser.
//
// The desk is the surface a clerk works against all day, and the new
// paperwork panel only means anything if it actually appears on the rows
// that have uploads. Static reading cannot tell a panel that renders
// from one that is silently empty.
//
//   node tests/desk_probe.js
'use strict';

const path = require('node:path');
const os = require('node:os');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const PHP = 'C:/xampp/php/php.exe';
const PAGE = 'http://localhost/registrar-ai-system/registrar/documents.php';
const OUT = path.join(os.tmpdir(), 'desk-probe');

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

function seedFixtures() {
    return execFileSync(PHP, [path.join(ROOT, 'tools', '_seed_states.php')], { encoding: 'utf8' }).trim();
}
function clearFixtures() {
    return execFileSync(PHP, [path.join(ROOT, 'tests', 'clear_doc_fixtures.php')], { encoding: 'utf8' }).trim();
}

// A registrar session, minted in a child so it is genuinely independent.
function session() {
    const out = execFileSync(PHP, [path.join(ROOT, 'tests', 'desk_session_helper.php')], { encoding: 'utf8' });
    return JSON.parse(out.trim().split(/\r?\n/).pop());
}

const puppeteer = require('puppeteer');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dk-'));

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    console.log('  ' + seedFixtures().split('\n')[0]);

    let sess;
    try { sess = session(); }
    catch (e) { console.error('RESULT=NO_SESSION ' + e.message); process.exit(1); }

    const b = await puppeteer.launch({
        executablePath: browser,
        headless: 'new',
        userDataDir: profile,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1600, height: 1100 },
    });
    const page = await b.newPage();

    const errors = [];
    const failed = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) failed.push(r.status() + ' ' + r.url()); });

    await b.setCookie({ name: sess.name, value: sess.id, domain: 'localhost', path: '/' });

    const results = [];
    const step = (l, ok, d) => {
        results.push({ l, ok });
        console.log((ok ? '  ok   ' : '  FAIL ') + l + (ok || !d ? '' : '  <- ' + d));
    };

    try {
        await page.goto(PAGE, { waitUntil: 'networkidle2', timeout: 45000 });
        step('desk loads', true);

        // Counted against what the database actually holds, not a literal.
        // The desk is a shared queue for EVERY student, so the pre-existing
        // real rows are legitimately on it too - a hardcoded 10 would be
        // asserting something about the developer's database rather than
        // about the code, and would fail for the wrong reason the moment
        // anyone used the system.
        // parseInt, not the raw string: execFileSync hands back text, and
        // 14 === "14" is false, so an unconverted value would report a
        // mismatch on two identical counts.
        const expectedRows = parseInt(execFileSync(PHP,
            [path.join(ROOT, 'tools', '_count_desk_rows.php')], { encoding: 'utf8' })
            .trim().split(/\r?\n/).pop(), 10);
        const rows = await page.$$eval('tr[data-doc]', (els) => els.length);
        step('every non-draft row is on the desk',
            rows === expectedRows,
            rows + ' rows on the page, ' + expectedRows + ' non-draft rows in the database');

        // -- Drafts must be absent, not merely hidden ------------------
        const statuses = await page.$$eval('tr[data-doc]', (els) =>
            els.map((e) => e.dataset.status));
        step('no Draft appears on the desk', !statuses.includes('Draft'), statuses.join(','));

        // -- Cancelled must be VISIBLE and labelled --------------------
        step('a cancelled request is shown', statuses.includes('Cancelled'), statuses.join(','));

        // -- Expand a row that has paperwork --------------------------
        // The seed has no uploads, so attach one first through the real
        // endpoint rather than reaching into the database.
        const target = await page.evaluate(() => {
            const tr = [...document.querySelectorAll('tr[data-doc]')]
                .find((e) => e.dataset.status === 'Filed');
            return tr ? tr.dataset.doc : null;
        });
        step('found a Filed request to attach to', target !== null, String(target));

        // Upload via the wizard endpoint using the registrar's session is
        // not allowed (ownership), so the paperwork panel is exercised by
        // seeding an attachment row for this request directly.
        execFileSync(PHP, [path.join(ROOT, 'tests', 'desk_attach_fixture.php'), String(target)], { encoding: 'utf8' });
        await page.reload({ waitUntil: 'networkidle2', timeout: 45000 });

        await page.evaluate((id) => {
            const btn = document.querySelector('tr[data-doc="' + id + '"] .dq-view');
            if (btn) btn.click();
        }, target);
        await page.waitForFunction((id) => {
            const el = document.getElementById('detail-' + id);
            return el && el.style.display !== 'none';
        }, { timeout: 8000 }, target);
        step('the row detail expands', true);

        // Scroll the expanded panel into view before the shot. The desk
        // opens at the top of a long page, so a screenshot taken without
        // this captures the header and charts and none of the thing this
        // probe exists to check.
        await page.evaluate((id) => {
            document.getElementById('detail-' + id)
                ?.scrollIntoView({ block: 'center' });
        }, target);
        await new Promise((r) => setTimeout(r, 300));
        await page.screenshot({ path: path.join(OUT, '01-detail.png') });

        // The paperwork panel on its own, so it can be judged as a
        // component rather than as part of a 1000px screenshot.
        const panel = await page.$('#detail-' + target + ' .dq-paper');
        if (panel) {
            await panel.screenshot({ path: path.join(OUT, '01b-paperwork-panel.png') });
        }

        const detail = await page.$eval('#detail-' + target, (el) => el.textContent);

        step('the paperwork panel is present', detail.includes('Paperwork'));
        step('it names the outstanding requirement', /Valid School ID/.test(detail),
            detail.slice(0, 200));
        // The count lives in the panel HEAD's pill, which is a sibling of
        // the pill text rather than part of it - so matched against the
        // head specifically rather than the whole panel.
        const headTxt = await page.$eval('#detail-' + target + ' .dq-paper-head',
            (el) => el.textContent);
        step('the panel head says how many are outstanding',
            /\d+\s*outstanding/.test(headTxt), JSON.stringify(headTxt));
        step('an uploaded file is listed',
            /desk-fixture/.test(await page.$eval('#detail-' + target, (el) => el.innerHTML)));
        step('the student\'s note is shown', /Their note/.test(detail));
        step('the promised release date is shown', /Promised to student/.test(detail));
        // The panel has no Delivery row. Asserted as absence: a panel that
        // still printed one would look finished and be wrong.
        step('the panel states no delivery method', !/Delivery|Courier/i.test(detail),
            detail.replace(/\s+/g, ' ').slice(0, 200));

        // An image must render as a thumbnail, not just a link.
        const imgCount = await page.$$eval('#detail-' + target + ' .dq-file.is-img img', (els) => els.length);
        step('an uploaded image renders as a thumbnail', imgCount >= 1, imgCount + ' thumbnails');

        // The thumbnail must actually load, not 404.
        const imgOk = await page.evaluate((id) => {
            const img = document.querySelector('#detail-' + id + ' .dq-file.is-img img');
            return img ? img.complete && img.naturalWidth > 0 : false;
        }, target);
        step('the thumbnail loads (authorised, not a 404)', imgOk);

        // -- Outstanding paperwork shows on the ROW --------------------
        const rowText = await page.$eval('tr[data-doc="' + target + '"]', (el) => el.textContent);
        step('the row itself flags outstanding paperwork',
            /requirement.*outstanding/i.test(rowText), rowText.slice(0, 160));

        // -- Optional items must not read as outstanding ---------------
        // An optional requirement nobody supplied used to render "not
        // supplied" in the same amber as a genuinely required document,
        // so a settled request looked like it was still waiting on two
        // things while the summary above said one. Asserted by STYLE,
        // not by text: the words alone cannot tell amber from grey.
        const optionalRows = await page.$$eval('#detail-' + target + ' .dq-check-row.is-optional',
            (els) => els.map((e) => ({
                amber: !!e.querySelector('.dq-check-missing'),
                grey: !!e.querySelector('.dq-check-skipped'),
            })));
        step('the checklist has optional items to check',
            optionalRows.length > 0, JSON.stringify(optionalRows));
        step('an unmet OPTIONAL item is not styled as a shortfall',
            optionalRows.length > 0 && optionalRows.every((r) => !r.amber && r.grey),
            JSON.stringify(optionalRows));

        // And the summary line must agree with the rows beneath it.
        const askLine = await page.$eval('#detail-' + target + ' .rq-ask', (el) => el.textContent);
        step('the "ask the student to bring" line names only REQUIRED items',
            !/Library Clearance|Accounting Clearance/.test(askLine),
            askLine.trim().slice(0, 200));

        await page.screenshot({ path: path.join(OUT, '02-row.png'), fullPage: false });

        // -- No courier leg -----------------------------------------
        //
        // Asserted as counts, not as a click. A probe that clicks a button
        // that is not there throws; a probe that asks "is there a dispatch
        // button" on a page without one passes forever, which is how a dead
        // control survives a green probe.
        const desk = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tr[data-doc]')];
            return {
                rails: rows.map((r) => r.querySelectorAll('.dq-station').length),
                dispatchButtons: rows.filter((r) => /Mark dispatched/.test(r.textContent)).length,
                deliverButtons: rows.filter((r) => /Mark delivered/.test(r.textContent)).length,
                shippedRows: rows.filter((r) => r.dataset.status === 'Shipped').length,
            };
        });
        step('every rail is four stations', desk.rails.length > 0 &&
            desk.rails.every((n) => n === 4), desk.rails.join(','));
        step('no row offers "Mark dispatched"', desk.dispatchButtons === 0,
            desk.dispatchButtons + ' button(s)');
        step('no row offers "Mark delivered"', desk.deliverButtons === 0,
            desk.deliverButtons + ' button(s)');

        // -- Cancelled rows --------------------------------------------
        const cancelRow = await page.evaluate(() => {
            const tr = [...document.querySelectorAll('tr[data-doc]')]
                .find((e) => e.dataset.status === 'Cancelled');
            if (!tr) return null;
            tr.querySelector('.dq-view')?.click();
            return tr.dataset.doc;
        });
        if (cancelRow) {
            await page.waitForFunction((id) => {
                const el = document.getElementById('detail-' + id);
                return el && el.style.display !== 'none';
            }, { timeout: 8000 }, cancelRow);
            const cd = await page.$eval('#detail-' + cancelRow, (el) => el.textContent);
            step('a cancelled request states why', /Cancelled by the student/i.test(cd), cd.slice(0, 200));
            step('it quotes the student\'s reason',
                /Found a certified copy elsewhere/.test(cd), cd.slice(0, 300));

            // The refund warning is conditional on paid_at. The Cancelled
            // fixture IS paid by design, so here the correct assertion is
            // that it is PRESENT - money went in and the request was
            // withdrawn, which is the case a clerk must not miss.
            step('a PAID cancellation warns that a refund is owed',
                /already paid/i.test(cd) && /refund/i.test(cd),
                JSON.stringify(cd.match(/.{0,60}refund.{0,60}/i) || cd.slice(0, 200)));
            await page.evaluate((id) => {
                document.querySelector('#detail-' + id + ' .dq-note.is-cancel')
                    ?.scrollIntoView({ block: 'center' });
            }, cancelRow);
            await new Promise((r) => setTimeout(r, 300));
            await page.screenshot({ path: path.join(OUT, '05-cancelled-paid.png') });

            // And the other direction, which is what makes the pairing
            // meaningful: with the payment removed the warning must go.
            // A warning shown for an unpaid cancellation would be
            // inventing a problem the office does not have.
            execFileSync(PHP, [path.join(ROOT, 'tests', 'desk_mark_paid.php'), String(cancelRow), 'off'], { encoding: 'utf8' });
            await page.reload({ waitUntil: 'networkidle2', timeout: 45000 });
            await page.evaluate((id) => {
                document.querySelector('tr[data-doc="' + id + '"] .dq-view')?.click();
            }, cancelRow);
            await page.waitForFunction((id) => {
                const el = document.getElementById('detail-' + id);
                return el && el.style.display !== 'none';
            }, { timeout: 8000 }, cancelRow);
            const unpaidCancel = await page.$eval('#detail-' + cancelRow, (el) => el.textContent);
            step('an UNPAID cancellation does not claim a refund is owed',
                !/refund has to be raised/i.test(unpaidCancel),
                JSON.stringify(unpaidCancel.match(/.{0,60}refund.{0,60}/i) || 'no refund text'));
            step('it still states why it was cancelled',
                /Cancelled by the student/i.test(unpaidCancel), unpaidCancel.slice(0, 200));

            // Restore the fixture's real state. Left as "unpaid" it would
            // make the next run's paid-warning assertion fail for a
            // reason that has nothing to do with the code.
            execFileSync(PHP, [path.join(ROOT, 'tests', 'desk_mark_paid.php'), String(cancelRow)], { encoding: 'utf8' });
            await page.screenshot({ path: path.join(OUT, '03-cancelled.png') });
        } else {
            step('found a cancelled row', false, 'none');
        }

        // -- Mobile ---------------------------------------------------
        await page.setViewport({ width: 400, height: 900, isMobile: true });
        await page.reload({ waitUntil: 'networkidle2', timeout: 45000 });
        const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth);
        step('no horizontal overflow at 400px', overflow <= 2, overflow + 'px');
        await page.screenshot({ path: path.join(OUT, '04-mobile.png') });

    } catch (e) {
        step('probe ran to completion', false, String(e.message || e));
        try { await page.screenshot({ path: path.join(OUT, 'error.png') }); } catch (_) {}
    }

    // The desk's student avatars 403 because studentPhotoUrl() points at
    // uploads/, which is deliberately not directly readable - that is the
    // whole point of uploads/.htaccess, and it predates this work
    // (shared/stored_file.php is untouched).
    //
    // The console messages for those are bare "Failed to load resource:
    // ... 403" with NO url in them, so they cannot be matched against a
    // path. Attributing them here from the response listener instead -
    // that is the only source that knows what was actually requested -
    // and then dropping console messages once the only failing responses
    // are the avatar ones.
    const avatarBlocked = failed.filter((f) => /uploads\/student_files/.test(f));
    const realFailed = failed.filter((f) => !/uploads\/student_files/.test(f));
    step('no failed requests', realFailed.length === 0, realFailed.slice(0, 4).join(' | '));
    if (avatarBlocked.length) {
        console.log('  note: ' + avatarBlocked.length + ' student-avatar 403s on the uploads path'
            + ' (pre-existing: uploads/.htaccess, shared/stored_file.php untouched)');
    }

    // Only a console message whose resource is NOT an avatar counts.
    // favicon noise is dropped separately: this app ships no favicon
    // route and that is not what this probe is about.
    const real = errors.filter((e) => {
        if (/favicon/i.test(e)) return false;
        if (/Failed to load resource/i.test(e)) {
            return !/403/.test(e) || realFailed.length > 0;
        }
        return true;
    });
    step('no JS errors', real.length === 0, real.slice(0, 3).join(' | '));

    // The paperwork thumbnail MUST be served through the authorised
    // endpoint, never from uploads/ - the thumbnail having loaded at all
    // is what proves it went through file-download.php.
    step('no paperwork file 403s',
        !failed.some((f) => /kind=attachment/.test(f)),
        failed.filter((f) => /kind=attachment/.test(f)).join(' | '));

    console.log('  ' + clearFixtures());
    const failedCount = results.filter((r) => !r.ok).length;
    console.log('\n----------------------------------------');
    console.log('passed: ' + (results.length - failedCount) + '   failed: ' + failedCount);
    console.log('screenshots: ' + OUT);

    await b.close();
    fs.rmSync(profile, { recursive: true, force: true });
    process.exit(failedCount === 0 ? 0 : 1);
})().catch((e) => { console.error('PROBE_CRASH ' + (e.stack || e)); process.exit(1); });