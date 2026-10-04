// ============================================================
//  JS/BCP-LETTERHEAD.JS
//  Shared printable letterhead for Registrar documents.
//
//  A plain global (BCPPrint), not a module: the two consumers — the AI
//  Insight report and the grade template — are classic <script> pages, and
//  making either ESM just to share a stylesheet would change how its
//  existing scripts are wired.
//
//  It exists because the letterhead was duplicated inline and had already
//  drifted once. printDocument() also fixes a real bug: the original code
//  called d.close(); w.print() back to back, and the browser printed
//  before the crest had loaded — the letterhead came out with no logo. An
//  <img> is fetched asynchronously; nothing in document.write waits for it.
// ============================================================

'use strict';

var BCPPrint = (function () {

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Styles ────────────────────────────────────────────────────
    // @page margin:0 on three sides is deliberate. Browsers draw their own
    // print header and footer ("10/2/26, 11:44 AM Title" / "about:blank 2/2")
    // only into the page margin area, so with no margin they have nowhere to
    // go and are omitted. The body padding is where our own centred header
    // and the first page's footer sit.
    //
    // THE BOTTOM PAGE MARGIN IS NOT OPTIONAL, AND IT IS NOT THE SAME THING AS
    // THE BODY PADDING.
    //
    // This used to be margin:0 all round, with the footer's space reserved by
    // the body's padding-bottom. That works on the LAST page and nowhere else:
    // padding-bottom reserves room once, at the end of the document flow. Every
    // intermediate page fills its whole box, so a fixed footer pinned at
    // bottom:10mm sits ON TOP of the text.
    //
    // It stayed invisible while these documents were one page. The Insight
    // report is seven sections of 700-1000 words, so it is two or three, and
    // the collision appeared exactly then: body text running through the
    // footer on pages 2 and 3.
    //
    // A @page bottom margin reserves the band on EVERY page, which is the only
    // thing that can. Top, left and right stay 0 so the browser's own header
    // and footer are still suppressed - we are claiming the bottom strip, not
    // inviting the browser back in.
    //
    // Every colour is #000. The screen's slate greys (#64748b, #334155)
    // are tuned for a white UI background and print as washed-out,
    // low-contrast ink that reads as blurred on paper.
    function letterheadCss() {
        return [
            '@page { size: A4 portrait; margin: 16mm 0 22mm 0; }',
            // The top and bottom @page bands are the working area for the
            // running header and footer. They have to be @page margins, not
            // body padding: padding reserves room once, at the end of the flow,
            // so it clears only the LAST page and leaves every intermediate
            // page running under the fixed furniture.
            //
            // Top, left and right stay 0 so the browser's own date/URL header
            // has nowhere to go. The BOTTOM margin is the price of the
            // repeating footer, and it has a known cost: a browser prints its
            // OWN footer ("1/3", the date, the URL) into any page margin band
            // it is given. Untick "Headers and footers" in the print dialog,
            // or it appears underneath ours.
            'body { font-family: Calibri, "Segoe UI", Arial, sans-serif; font-size: 11pt;',
            '       line-height: 1.6; color: #000; margin: 0; padding: 2mm 16mm 4mm;',
            '       -webkit-print-color-adjust: exact; }',

            '.letterhead { text-align:center; border-bottom:1px solid #1a2d4a;',
            '              padding-bottom:10px; margin-bottom:22px; }',
            // The crest spans the three name lines — from the top of the
            // college name to the bottom of the address. The COLUMN WIDTH
            // must be pinned, not the height: with auto columns the column
            // sizes to the image's intrinsic width (220px) and height:100%
            // against an auto row is circular, so the crest renders three
            // times too big. A fixed column gives the wrapper an
            // unambiguous width; the row height then comes from the text
            // column, and object-fit:contain scales the shield to exactly
            // the name block without distorting it.
            '.lh-top { display:grid; grid-template-columns:76px auto; align-items:stretch;',
            '          justify-content:center; column-gap:16px; }',
            '.lh-logo img { display:block; width:100%; height:100%; object-fit:contain; }',
            // One flex column with a single gap, so the spacing between the
            // three name lines is identical by construction rather than
            // tuned per-line with individual margins.
            '.lh-lines { display:flex; flex-direction:column; justify-content:center; gap:4px; }',
            // Garamond first with a serif fallback: a machine without it
            // installed must not silently fall back to the sans body face.
            '.lh-school { font-family: Garamond, "EB Garamond", "Times New Roman", serif;',
            '              font-size:16pt; line-height:1.2; letter-spacing:.4px; }',
            '.lh-unit { font-family: Garamond, "EB Garamond", "Times New Roman", serif;',
            '           font-size:16pt; line-height:1.2; }',
            '.lh-address { font-family: Calibri, "Segoe UI", Arial, sans-serif;',
            '              font-size:9pt; line-height:1.35; }',
            '.lh-doc { font-family: Calibri, "Segoe UI", Arial, sans-serif; font-size:11pt;',
            '           line-height:1.35; margin-top:12px; font-weight:700; letter-spacing:.6px; }',

            // THE RUNNING HEADER. Repeats on every page, like the footer.
            //
            // The full crest letterhead stays in the flow on page 1 only -
            // a block in the flow prints once. This is the compact version
            // that carries the identity and the document title on pages 2+
            // (and above the crest on page 1, where both are visible and read
            // as a masthead plus a letterhead rather than as a duplicate).
            //
            // position:fixed is the only mechanism browsers give for
            // repeating content, and it positions against the PAGE box, which
            // is why @page has to reserve the band for it.
            '.rhead { position:fixed; top:0; left:0; right:0; text-align:center;',
            '         font-family: Calibri, "Segoe UI", Arial, sans-serif;',
            '         font-size:8.5pt; line-height:1.35; color:#000; padding-top:2mm; }',
            '.rhead .rh-school { font-family: Garamond, "EB Garamond", "Times New Roman", serif;',
            '                    font-size:11pt; font-weight:700; letter-spacing:.3px; }',
            '.rhead .rh-doc { margin-top:1px; font-size:8.5pt; letter-spacing:.4px;',
            '                text-transform:uppercase; }',
            '.rhead .rh-rule { margin:2mm 16mm 0; border-bottom:.5px solid #1a2d4a; }',

            // position:fixed is what makes the footer repeat on EVERY page.
            // A block at the end of the document prints once, last page only.
            '.footer { position:fixed; left:0; right:0; bottom:10mm; text-align:center;',
            '          font-family: Calibri, "Segoe UI", Arial, sans-serif; font-size:10pt;',
            '          line-height:1.4; color:#000; }',

            'h2.doc-h { font-size:12pt; font-weight:700; letter-spacing:.4px; margin:22px 0 8px;',
            '           text-align:center; color:#000; page-break-after:avoid; break-after:avoid-page; }',
            '.doc-body p { margin:0 0 12px; text-align:justify; color:#000; }',
            '.doc-body ul { margin:0 0 12px; padding-left:24px; color:#000; }',
            '.doc-body li { margin-bottom:6px; }',
            '.doc-body strong { font-weight:700; }',
            // The conclusion is the same prose as the body — it earns its
            // boundary from the rule above the heading and the heading
            // itself, not from a different ink or a box.
            '.doc-conclusion { margin-top:20px; padding-top:14px; border-top:1px solid #000; }',
            '.meta { font-size:10pt; color:#000; margin-bottom:20px; text-align:center; }',

            // LINES WERE BEING CUT OFF AT THE RIGHT EDGE.
            //
            // The measure is 16mm inside a 210mm page, and the model writes
            // long unbroken tokens - identifiers, "N/A", en-dashed ranges,
            // capitals. A word wider than the line has nowhere to go, so it
            // either overflows past the margin (invisible against the edge,
            // so it looks like the sentence was truncated) or forces the line
            // to break mid-word and leave a ragged, visibly damaged sentence.
            //
            // hyphens:auto lets long words break with a hyphen and justify
            // evenly; overflow-wrap:break-word is the backstop for tokens with
            // no break opportunity at all, and only those.
            '.doc-body p, .doc-body li, .doc-body td, .doc-body th {',
            '    hyphens:auto; -webkit-hyphens:auto; hyphenate-limit-chars:6 3 3;',
            '    overflow-wrap:break-word; }',
            // Justification without hyphenation produces rivers of white
            // space down the page. With it on, both defects go together.
            '.doc-body p { text-align:justify; }',

            // PAGE BREAKS for a document that is now routinely two or three
            // pages, which a one-page memo never had to think about.
            //
            // widows/orphans stop a heading landing as the last line of a page
            // with its text overleaf, which reads as a printing fault rather
            // than a long document.
            'p, li { orphans:2; widows:2; }',
            // Keep a short list whole. Breaking a three-bullet recommendation
            // across a page boundary separates an action from the finding it
            // answers, which is the one break that changes what the document
            // MEANS rather than just where it falls.
            '.doc-body ul { page-break-inside:avoid; break-inside:avoid-page; }',
            'h2.doc-h + p, h2.doc-h + ul { page-break-before:avoid; break-before:avoid-page; }',
            '.sig { display:flex; gap:48px; margin-top:34px;',
            '       page-break-inside:avoid; break-inside:avoid-page; }',
            '.sig .box { flex:1; }',
            '.sig .line { border-top:1px solid #000; padding-top:4px; font-size:10pt; color:#000; }',
            '.foot-note { margin-top:26px; font-size:9pt; color:#000; line-height:1.4;',
            '             text-align:center; }'
        ].join('\n');
    }

    // ── Header + footer ───────────────────────────────────────────
    // The document title is passed in rather than baked in, so one
    // letterhead serves the AI Insight report and the grade template
    // without either hardcoding the other's wording.
    function headerHtml(o) {
        o = o || {};
        var logoUrl = o.logoUrl || '';
        var img = logoUrl
            ? '<div class="lh-logo"><img src="' + esc(logoUrl) + '" alt="Bestlink College of the Philippines"></div>'
            : '<div class="lh-logo"></div>';
        return '<div class="letterhead"><div class="lh-top">' + img
            + '<div class="lh-lines">'
            + '<div class="lh-school">BESTLINK COLLEGE OF THE PHILIPPINES</div>'
            + '<div class="lh-unit">College of Computer Studies</div>'
            + '<div class="lh-address">1071 Brgy. Kaligayahan Quirino Highway, Novaliches, Quezon City</div>'
            + '</div></div>'
            + (o.title ? '<div class="lh-doc">' + esc(o.title) + '</div>' : '')
            + '</div>';
    }

    /**
     * The compact running header, repeated on every page.
     *
     * Separate from headerHtml() on purpose. That one is the full crest
     * letterhead and it belongs in the flow, so it prints once on page 1.
     * A crest and a three-line address repeated on all three pages of a
     * report would be noise, and would push the text further down each page.
     * This carries the two things a page needs to identify itself: who
     * issued it, and what it is.
     */
    function runningHeaderHtml(o) {
        o = o || {};
        return '<div class="rhead">'
            + '<div class="rh-school">BESTLINK COLLEGE OF THE PHILIPPINES</div>'
            + '<div class="rh-doc">Office of the Registrar'
            + (o.title ? ' &middot; ' + esc(o.title) : '') + '</div>'
            + '<div class="rh-rule"></div>'
            + '</div>';
    }

    function footerHtml() {
        return '<div class="footer">'
            + '1044 Bestlink Building, Brgy. Sta.Monica,Quirino Highway, Novaliches, Quezon City Philippines<br>'
            + 'Tel. Nos.  02.417.4355; 02.930.1565; http://www.bcp.edu.ph'
            + '</div>';
    }

    // ── In-tab printing ───────────────────────────────────────────
    // Writes a document into a hidden iframe and prints it from there, so
    // no pop-up opens and the user's tab is left exactly where it was.
    //
    // The frame is sized and parked off-screen rather than set to
    // visibility:hidden or 0x0: the crest needs a real layout box to load
    // and resolve, and a collapsed iframe can print without it.
    function printDocument(opts) {
        opts = opts || {};
        var frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('title', 'Printable document');
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;'
            + 'width:794px;height:1123px;border:0;';
        document.body.appendChild(frame);

        var w = frame.contentWindow;
        if (!w || !w.document) {
            frame.parentNode.removeChild(frame);
            return false;
        }

        var cleaned = false;
        function cleanup() {
            if (cleaned) return;
            cleaned = true;
            frame.removeEventListener('afterprint', cleanup);
            if (frame.parentNode) frame.parentNode.removeChild(frame);
        }
        frame.addEventListener('afterprint', cleanup);
        // afterprint is not fired by every browser (older Safari), so a
        // timeout backstops it. Generous, because a long document may keep
        // the dialog open a while.
        setTimeout(cleanup, 60000);

        var d = w.document;
        d.write('<!DOCTYPE html><html><head><meta charset="utf-8">');
        d.write('<title>' + esc(opts.title || '') + '</title><style>');
        d.write(opts.css || letterheadCss());
        // Closed explicitly. document.write alone leaves the parser to
        // recover from an unclosed <style> in <head>, which works in a real
        // window but leaves the stylesheet swallowing the markup after it.
        d.write('</style></head><body>');
        // Written immediately after the head so the fixed elements exist
        // for every page, before any content can overlap them.
        d.write(runningHeaderHtml({ title: opts.title || '' }));
        d.write(footerHtml());
        d.write(opts.body || '');
        d.write('</body></html>');
        d.close();

        // Wait for the crest before printing — this is the logo fix.
        whenImagesReady(d, function () {
            w.focus();
            w.print();
        });
        return true;
    }

    // Resolves once every <img> has finished loading, or after a short
    // deadline. The deadline matters: a broken or slow image must never
    // stop the document printing.
    function whenImagesReady(doc, done) {
        var imgs = Array.prototype.slice.call(doc.images || []);
        var pending = imgs.filter(function (img) { return !img.complete; });
        if (!pending.length) { done(); return; }

        var fired = false;
        function go() {
            if (fired) return;
            fired = true;
            imgs.forEach(function (img) {
                img.removeEventListener('load', go);
                img.removeEventListener('error', go);
            });
            done();
        }
        imgs.forEach(function (img) {
            img.addEventListener('load', go);
            img.addEventListener('error', go);
        });
        // Backstop for an image that never fires either event.
        setTimeout(go, 1500);
    }

    return {
        letterheadCss: letterheadCss,
        headerHtml: headerHtml,
        runningHeaderHtml: runningHeaderHtml,
        footerHtml: footerHtml,
        printDocument: printDocument,
        esc: esc
    };
})();
