<?php

// ============================================================
//  TESTS/AcademicHistoryReadOnlyTest.php
//  Grades are Faculty-owned since 2026-10-02; the Registrar reads them.
//
//  These tests guard a BOUNDARY, not a behaviour. A read-only page that
//  quietly regains a save path is the failure this file exists to catch, and
//  nothing else in the suite would notice: the editor worked perfectly
//  before, so re-adding it would not break a single existing test.
//
//  Static source assertions are the right tool here. A browser probe
//  (tests/academic_readonly_probe.js) proves the rendered page is read-only
//  too, but it needs a live server; these run everywhere.
// ============================================================

use PHPUnit\Framework\TestCase;

final class AcademicHistoryReadOnlyTest extends TestCase
{
    private const PAGE   = __DIR__ . '/../registrar/academic-history.php';
    private const API    = __DIR__ . '/../api/students.php';
    private const LETTER = __DIR__ . '/../js/bcp-letterhead.js';

    private static function source(string $file): string
    {
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    // ── The page ─────────────────────────────────────────────────

    /**
     * The editor's JavaScript must be GONE, not merely unused.
     *
     * Checked as "function no longer declared" rather than "never called",
     * because a dormant save function is a live save function the day
     * someone wires a button back to it.
     */
    public function testGradeEditingFunctionsAreRemoved(): void
    {
        $js = self::source(self::PAGE);

        foreach ([
            'saveGrades', 'addGradeRow', 'removeGradeRow',
            'readGrid', 'updatePreview', 'previewGwa',
        ] as $fn) {
            self::assertStringNotContainsString(
                "function {$fn}(",
                $js,
                "{$fn}() still exists on the Academic History page. Grades are "
                . 'Faculty-owned; the Registrar must not be able to write them.'
            );
        }
    }

    /** The save path that actually reaches the server. */
    public function testPageNoLongerPostsToTheSaveEndpoint(): void
    {
        $html = preg_replace('#/\*.*?\*/|//[^\n]*|<!--.*?-->#s', '', self::source(self::PAGE));
        self::assertStringNotContainsString(
            'save-academic',
            $html,
            'The page still references the save-academic endpoint.'
        );
    }

    /**
     * No form controls in the grade grid.
     *
     * data-f is the marker every editable cell carried; its absence is the
     * machine-checkable version of "the grid is not a form".
     *
     * The whole FILE is not checked for these names: the header comment
     * deliberately names the functions that were removed, so that a reader
     * arriving in two years knows what used to be here. Comments are prose;
     * only live markup is the boundary.
     */
    public function testGradeGridHasNoEditorMarkup(): void
    {
        // Markup only — strip the comment blocks first.
        $html = preg_replace('#/\*.*?\*/|//[^\n]*|<!--.*?-->#s', '', self::source(self::PAGE));

        self::assertStringNotContainsString('data-f=', $html,
            'An editor cell is still present in the grade grid.');
        self::assertStringNotContainsString('id="btnSaveGrades"', $html,
            'The Save term button is still in the markup.');
        self::assertStringNotContainsString('addGradeRow()', $html);
        self::assertStringNotContainsString('removeGradeRow(', $html);
    }

    /**
     * No form controls in the ledger or the record.
     *
     * This used to assert against #gradeModal. The redesign removed the
     * dialog entirely — the record moved out of the row and into the sheet —
     * so there is no dialog left to scope the check to. The
     * assertion gets STRONGER in exchange: it covers the whole ledger and
     * the record markup, rather than one container.
     *
     * The page keeps inputs, and must: the term box and the find box are how
     * a registrar reaches the student they want. Those live outside the
     * ledger, so scoping to the table is correct rather than a convenience.
     */
    public function testLedgerAndRecordContainNoFormControls(): void
    {
        $html = self::source(self::PAGE);

        // Isolate the roster table.
        //
        // It used to be found as <table class="reg-ledger">, which is unique
        // because that class existed only here. It is now the house .table,
        // shared with every panel on every registrar page — and the page has
        // two of them, because the empty-state branch renders its own table.
        // Scoping by the header row instead finds the roster in both branches
        // and cannot drift when the styling is changed.
        self::assertSame(
            1,
            preg_match_all(
                '/<thead>\s*<tr>\s*<th scope="col">Student<\/th>.*?<\/table>/s',
                $html,
                $m
            ),
            'Could not isolate the roster markup to check it.'
        );
        $ledger = $m[0][0];

        self::assertStringContainsString('class="ah-record"', $html,
            'The record container is missing from the page.');

        foreach (['<input', '<select', '<textarea', 'data-f='] as $tag) {
            self::assertStringNotContainsString($tag, $ledger,
                "The ledger still contains a {$tag} control. It is a read-only "
                . 'view of a Faculty-owned record.');
        }

        // The record is rendered by recordHtml() in JS, so the same rule
        // has to hold there. The function that follows it changed name when the
        // record moved out of the row and into the sheet — openRecord() replaced
        // toggleRecord(), which expanded and collapsed in place.
        $js = self::source(self::PAGE);
        $start = strpos($js, 'function recordHtml(');
        $end = strpos($js, 'function openRecord(');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $record = substr($js, $start, $end - $start);
        foreach (['<input', '<select', '<textarea', 'data-f='] as $tag) {
            self::assertStringNotContainsString($tag, $record,
                "recordHtml() renders a {$tag} control into the printed record.");
        }
    }

    /**
     * A row carries exactly one control, and it is not chrome.
     *
     * "Remove all the buttons" was a design instruction, but deleting the
     * <button> element outright would break keyboard and screen-reader users.
     * The rule enforced here is that a row carries no button CHROME: no .btn
     * class, no icon font, no bordered control from the bootstrap set.
     *
     * The control used to be a chevron on the student's name (.ah-open), which
     * expanded the record in a row beneath. It is now a text View button
     * (.ah-view) in its own column, and the record opens in a sheet. The rule is
     * unchanged and still passes: .ah-view carries none of the chrome listed
     * above, so the ledger reads as data with a step, not as a table of buttons.
     */
    public function testRowsCarryNoButtonChrome(): void
    {
        $html = self::source(self::PAGE);

        // No bootstrap button classes inside the roster. preg_match_all
        // returns the number of MATCHES, so 1 means "found exactly the one
        // roster" — asserting 0 here (as an earlier draft did) fails on a
        // perfectly healthy page, which is a test that teaches the reader
        // to ignore it.
        //
        // Scoped by the header row rather than by a bespoke class: the roster
        // now wears the house .table, so the class no longer identifies it.
        self::assertSame(
            1,
            preg_match_all(
                '/<thead>\s*<tr>\s*<th scope="col">Student<\/th>.*?<\/table>/s',
                $html,
                $m
            ),
            'Could not isolate the roster markup to check it.'
        );
        foreach (['btn btn-light', 'btn btn-primary', 'fa-eye', 'fa-pen',
                  'fa-print', 'fa-floppy-disk'] as $chrome) {
            self::assertStringNotContainsString($chrome, $m[0][0],
                "The roster still carries button chrome ('{$chrome}').");
        }

        // The forward step is a plain button, styled by .ah-view: a border, no
        // bootstrap .btn class, no icon font, so it stays below the chrome list
        // above even though the roster now shows a real control on each row.
        self::assertStringContainsString('class="ah-view"', $html);
        // And the record is no longer in a <tr>, so nothing in the page should
        // still be trying to expand one.
        self::assertStringNotContainsString('function toggleRecord(', $html);
        self::assertStringNotContainsString('class="ah-open"', $html);
    }

    /** The replacement action is present, so the page is still useful. */
    public function testPrintableTemplateActionExists(): void
    {
        $html = self::source(self::PAGE);
        self::assertStringContainsString('function printGradeTemplate(', $html);
        // It must go through the shared letterhead, not a private copy.
        self::assertStringContainsString('BCPPrint.printDocument(', $html);
        self::assertStringContainsString('BCPPrint.headerHtml(', $html);
    }
    // ── The API ──────────────────────────────────────────────────

    /**
     * The write endpoints must refuse, and must say why.
     *
     * 404 is the wrong answer here: it reads as "unknown action" and looks
     * like a bug in whatever called it. 409 plus a reason tells an
     * integrator the endpoint exists, that the boundary moved, and where the
     * data comes from now.
     */
    public function testWriteEndpointsAreRefusedWithAReason(): void
    {
        $api = self::source(self::API);

        self::assertStringContainsString("'save-academic'", $api);
        self::assertStringContainsString("'delete-academic'", $api);
        self::assertStringContainsString('http_response_code(409)', $api,
            'Grade writes must be refused with 409, not left to run.');
        self::assertStringContainsString('Faculty Management', $api,
            'The refusal must name the owning office.');
    }

    /** Nothing may still insert into or delete from the grade tables. */
    public function testApiNoLongerWritesToGradeTables(): void
    {
        $api = self::source(self::API);

        self::assertDoesNotMatchRegularExpression(
            '/insert\(\s*[\'"]academic_grades[\'"]/i',
            $api,
            'The API still inserts into academic_grades.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/delete\(\s*[\'"]academic_(grades|history)[\'"]/i',
            $api,
            'The API still deletes academic grade records.'
        );
    }

    /** The read path must survive the boundary change. */
    public function testApiStillServesGrades(): void
    {
        self::assertStringContainsString(
            "=== 'grades'",
            self::source(self::API),
            'The read endpoint for grades was removed; the page cannot refresh.'
        );
    }

    // ── The shared letterhead ─────────────────────────────────────

    /**
     * printDocument() must wait for the crest before printing.
     *
     * This is the AI Insight logo regression, pinned. The original code
     * called d.close(); w.print() back to back; the browser printed before
     * the <img> had fetched and the letterhead came out with no logo. An
     * <img> is fetched asynchronously and nothing in document.write waits
     * for it, so the wait has to be explicit — and it lives with the
     * letterhead because the letterhead is the only thing carrying an image.
     */
    public function testPrintWaitsForImagesToLoad(): void
    {
        $js = self::source(self::LETTER);

        self::assertStringContainsString('whenImagesReady', $js,
            'printDocument() must wait for images before printing.');
        self::assertStringContainsString("addEventListener('load'", $js);
        self::assertStringContainsString('!img.complete', $js,
            'An already-complete image must be detected, or the wait hangs on '
            . 'a cached crest until the timeout.');

        // The deadline is a safety net, not the mechanism: a broken image
        // must never stop the document printing.
        self::assertStringContainsString('setTimeout(go, 1500)', $js);
    }

    /** Both consumers must share the one letterhead. */
    public function testLetterheadIsSharedNotDuplicated(): void
    {
        $insights = self::source(__DIR__ . '/../js/insights.js');

        self::assertStringContainsString('BCPPrint.printDocument(', $insights,
            'The AI Insight report no longer uses the shared letterhead.');
        self::assertStringContainsString('BCPPrint.headerHtml(', $insights);

        // Neither file may carry its own copy of the rules.
        foreach (['.lh-school {', '.letterhead {'] as $rule) {
            self::assertStringNotContainsString(
                $rule,
                $insights,
                "The report has its own copy of '{$rule}' — the letterhead has "
                . 'been re-inlined and can drift again.'
            );
        }
    }

    /** The letterhead prints on A4 with no browser furniture. */
    public function testPrintStylingSuppressesBrowserFurniture(): void
    {
        $js = self::source(self::LETTER);

        // Browsers draw their own date/URL/page-number header ONLY into a top
        // page margin. With the top margin at zero it has nowhere to go and is
        // omitted - which is the whole point, and is why a repeating header
        // was removed rather than re-bandaged. Bottom stays a real margin
        // because the fixed footer needs a band to sit in.
        self::assertStringContainsString('@page { size: A4 portrait; margin: 0 0 22mm 0; }', $js);
        self::assertStringContainsString('position:fixed', $js,
            'The footer must be position:fixed to repeat on every page.');
    }

    /**
     * The letterhead prints ONCE. There is no repeating header.
     *
     * A position:fixed running header was added and then removed. It was meant
     * to identify pages 2+, and it broke the sheet in three ways: page 1 then
     * carried the running header AND the crest stacked above each other, a
     * duplicated masthead; reserving its band forced a @page TOP margin, which
     * is exactly what invites the browser's own header back in; and pages 2+
     * gained lines that identified nothing the footer did not.
     *
     * So the rule is now structural: there is exactly one header, and it is in
     * the flow. This asserts both halves - the CSS is gone AND nothing writes
     * it - because restoring either alone gives a different broken sheet.
     */
    public function testTheLetterheadPrintsOnceWithNoRepeatingHeader(): void
    {
        $js = self::source(self::LETTER);

        self::assertStringNotContainsString(
            '.rhead',
            $js,
            'The fixed running header is what duplicated the masthead on page 1 and '
            . 'forced a top margin. The crest letterhead already prints once, in the flow.'
        );
        self::assertStringNotContainsString(
            'runningHeaderHtml',
            $js,
            'Nothing may write a second header into the document.'
        );

        // The crest is still there, in the flow, so page 1 is identified.
        self::assertStringContainsString('.letterhead {', $js);
        self::assertStringContainsString('function headerHtml(', $js);
    }

    /**
     * The repeating footer must have a band of its own.
     *
     * The footer is position:fixed, and browsers position a fixed element
     * against the PAGE box rather than the content box - so it cannot reserve
     * its own space. Only an @page margin can, and only a BOTTOM margin does.
     *
     * The TOP margin is asserted to be ZERO here, and that is load-bearing,
     * not incidental. A zero top margin is what stops the browser printing its
     * own date/URL header into a band we happen to have opened up.
     */
    public function testFooterRepeatsWithRoomForItAndTheTopBandStaysClosed(): void
    {
        $js = self::source(self::LETTER);

        preg_match('/@page\s*\{[^}]*margin:\s*([^;}]+)/', $js, $m);
        self::assertNotEmpty($m, 'No @page margin found in the letterhead styles.');
        $sides = preg_split('/\s+/', trim($m[1]));
        self::assertCount(4, $sides);

        // CSS shorthand order is top / right / bottom / LEFT.
        $topMm = (float) rtrim($sides[0], 'mmpx');
        $footerBandMm = (float) rtrim($sides[2], 'mmpx');

        self::assertSame(
            0.0, $topMm,
            'The top margin must stay zero. Opening it is what invited the '
            . "browser's own header back in, and it only existed to hold a "
            . 'repeating header that has since been removed.'
        );
        self::assertGreaterThan(
            14.0, $footerBandMm,
            'The bottom band must clear the footer: 10mm offset plus a two-line '
            . '10pt block. Anything under that prints the text through it.'
        );
    }

    /**
     * The footer is fixed, so something must reserve space beneath the text
     * on every page — not just the last one.
     *
     * This regressed silently. The stylesheet used to be `margin: 0` all
     * round, relying on the body's padding-bottom to keep the last page's
     * footer clear. That works on the final page and nowhere else: padding
     * reserves room once, at the end of the flow, while every intermediate
     * page fills its box and the fixed footer sits on top of the text.
     *
     * It went unnoticed because these documents used to be one page. The AI
     * Insight report is seven sections of 700-1000 words, so it is two or
     * three, and body text then ran through the footer on pages 2 and 3.
     */
    public function testFixedFooterHasReservedSpaceOnEveryPage(): void
    {
        $js = self::source(self::LETTER);

        // A fixed element is only safe if the page box stops above it.
        preg_match('/@page\s*\{[^}]*margin:\s*([^;}]+)/', $js, $m);
        self::assertNotEmpty($m, 'No @page margin found in the letterhead styles.');

        $sides = preg_split('/\s+/', trim($m[1]));
        self::assertCount(
            4,
            $sides,
            'The @page margin must give all four sides explicitly. A two- or '
            . 'three-value shorthand reuses the top value for the sides it '
            . 'omits, which would move the browser header back in.'
        );

        // CSS shorthand order is top / right / bottom / LEFT, so the bottom
        // band is index 2. Indexing 3 here checks the left margin, which is
        // legitimately 0 and would flag a correct stylesheet as broken.
        self::assertNotSame(
            '0',
            $sides[2],
            'The bottom @page margin is 0 again, so the fixed footer will '
            . 'overlap the body text on every page after the first. Reserve '
            . 'the band here; body padding-bottom only ever covers the last page.'
        );
        self::assertGreaterThan(0.0, (float) $sides[2],
            'The reserved footer band must be a real length.');

        // And the footer must actually sit inside that reserved band.
        self::assertMatchesRegularExpression(
            '/\.footer\s*\{[^}]*position:fixed[^}]*bottom:\s*(\d+)mm/',
            $js,
            'The footer must be position:fixed with an explicit bottom offset '
            . 'in mm so it can be checked against the reserved band.'
        );
        preg_match('/\.footer\s*\{[^}]*bottom:\s*(\d+)mm/', $js, $f);
        $footerBottom = (float) ($f[1] ?? 0);
        $reservedMm   = (float) rtrim($sides[2], 'mmpx');

        self::assertGreaterThan(
            $footerBottom,
            $reservedMm,
            'The reserved @page bottom band (' . $reservedMm . 'mm) must be '
            . 'DEEPER than the footer\'s offset from the page edge ('
            . $footerBottom . 'mm), otherwise the band does not clear the '
            . 'footer and the collision returns.'
        );

        // Clearance has to be measured against the footer's HEIGHT, not just
        // its offset. A band of 12mm against a footer sitting 10mm up but
        // 5mm tall still overlaps: the text would land inside it. Comparing
        // offset to offset passes that case, so the geometry is computed here.
        preg_match('/\.footer\s*\{[^}]*font-size:\s*([\d.]+)pt/', $js, $fs);
        preg_match('/\.footer\s*\{[^}]*line-height:\s*([\d.]+)/', $js, $lh);

        $fontPt  = (float) ($fs[1] ?? 0);
        $lineMul = (float) ($lh[1] ?? 0);

        if ($fontPt > 0 && $lineMul > 0) {
            // 1pt = 25.4/72 mm. One line is the worst case; the footer is a
            // single centred line of text.
            $footerHeightMm = ($fontPt * $lineMul) * (25.4 / 72);
            $occupiesToMm   = $footerBottom + $footerHeightMm;

            self::assertGreaterThan(
                $occupiesToMm,
                $reservedMm,
                'The footer occupies the last ' . round($occupiesToMm, 1)
                . 'mm of the page (' . $footerBottom . 'mm offset + '
                . round($footerHeightMm, 1) . 'mm line), but only '
                . $reservedMm . 'mm is reserved. The last '
                . round($occupiesToMm - $reservedMm, 1) . 'mm of every page '
                . 'will run underneath the footer.'
            );
        } else {
            self::fail(
                'Could not read the footer font-size/line-height, so the '
                . 'reserved band cannot be checked against its height. Give '
                . '.footer an explicit font-size in pt and a numeric line-height.'
            );
        }
    }
}