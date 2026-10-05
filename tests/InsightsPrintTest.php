<?php

use PHPUnit\Framework\TestCase;

/**
 * The AI Insight print document is one file with three parts, and the
 * Export-as-CSV option is gone.
 *
 * The printout is a formal document: a title (the BCP letterhead), the body
 * of the analysis (sections 1-6), and a conclusion (section 7 — cross-module
 * findings and recommended actions) under its own heading. The endpoint that
 * writes the report enforces exactly that seven-section shape
 * (api/ai-insights-report.php requires '## 1.' … '## 7.' before it will
 * serve a report at all), so the print path lifts section 7 out rather than
 * leaving the reader to notice that the last of seven undifferentiated
 * headings was the conclusions.
 *
 * "Export as CSV" is deliberately gone. A flat CSV of the measured figures
 * invites the reader to treat counts as analysis — the exact mistake the
 * on-screen figures block exists to prevent. The button, its handler and
 * its markup must all stay gone: a half-removed export menu offers a choice
 * that does nothing.
 *
 * These are assertions about source, so they are cheap to write and cheap to
 * fail. They are here because the print document is assembled from three
 * files that drift independently — js/insights.js builds it,
 * js/bcp-letterhead.js styles it, ai/insights.php offers the menu — and
 * nothing at runtime reports which one broke.
 */
final class InsightsPrintTest extends TestCase
{
    private const JS = __DIR__ . '/../js/insights.js';
    private const LETTERHEAD = __DIR__ . '/../js/bcp-letterhead.js';
    private const PAGE = __DIR__ . '/../ai/insights.php';
    private const ENDPOINT = __DIR__ . '/../api/ai-insights-report.php';

    private static function read(string $path): string
    {
        $src = file_get_contents($path);
        self::assertIsString($src);
        return $src;
    }

    /**
     * The printed sheet is a ONE-PAGE summary, and says what it is.
     *
     * It used to print the whole seven-section report, which ran to two or
     * three pages. The office asked for one page. There was also an older
     * version that fitted one page by assembling "AI Registrar Summary",
     * "Detected Trends" and "Patterns Observed" from string concatenation -
     * and that was removed for a real reason: it read exactly like analysis
     * while being a template, so a gateway failure produced something
     * indistinguishable from a successful model call. Printing that under
     * the letterhead, on a sheet a registrar signs, is the worst possible
     * place for it.
     *
     * So the one page must hold only what is real. These assertions pin that
     * choice, because the tempting shortcut back to a fitting page is a
     * template, and it looks like a fix.
     */
    public function testThePrintoutIsTheOnePageSummaryNotTheWholeReport(): void
    {
        $js = self::read(self::JS);

        self::assertStringContainsString(
            'splitReportSections(state.report)',
            $js,
            'The print path must parse the report into sections before rendering it.'
        );

        // Sections 2 to 6 are the per-module detail. They must NOT be on
        // the sheet - that is what made it two or three pages - and they
        // must still be on screen.
        self::assertStringNotContainsString(
            'reportBodyHtml(parts.body)',
            $js,
            'Printing sections 1-6 is what made this run long. The brief prints the '
            . 'figures, the Executive Summary and the conclusion only.'
        );

        self::assertStringContainsString(
            'parts.summary',
            $js,
            'The sheet prints the model\'s own Executive Summary - by contract the one '
            . 'section written to be read alone.'
        );
        self::assertStringContainsString(
            '<h2 class="doc-h">Executive Summary</h2>',
            $js,
            'The summary needs a literal heading, or the sheet opens on figures.'
        );
        self::assertStringContainsString(
            '<h2 class="doc-h">Conclusion and Recommended Actions</h2>',
            $js,
            'The conclusion must carry a heading that names what it is.'
        );
        self::assertStringContainsString(
            'doc-body doc-conclusion',
            $js,
            'The conclusion must be its own block in the printed document.'
        );

        // The figures are printed, but as a ledger - never as analysis.
        self::assertStringContainsString(
            'briefFiguresHtml(state.figures)',
            $js,
            'The measured figures belong on the sheet, in their own treatment.'
        );
        self::assertStringContainsString(
            '<h2 class="doc-h">Measured Figures</h2>',
            $js,
            'They must be labelled as figures so nobody reads counts as analysis.'
        );
    }

    /**
     * The crest sits BESIDE the college name, on the left.
     *
     * Stacking the crest above the name was tried and reverted. It is the
     * conventional crest-over-name masthead, but it costs a whole extra row
     * of height, and on the one-page brief height is the scarcest thing on
     * the sheet. Beside, the crest and the three name lines share one row.
     *
     * So this pins the beside layout - and, more usefully, the two traps
     * that come with it and that both fail silently.
     */
    public function testTheCrestIsBesideTheCollegeNameOnTheLeft(): void
    {
        $js = self::read(self::LETTERHEAD);

        // The crest and the name block are siblings inside one .lh-top row.
        // If they stop being siblings the crest drifts to its own line and
        // the sheet quietly loses a row of height.
        self::assertStringContainsString(
            '<div class="letterhead"><div class="lh-top">',
            $js,
            'The crest and the name lines must share one .lh-top row.'
        );

        $logo  = strpos($js, '<div class="lh-logo">');
        $lines = strpos($js, '<div class="lh-lines">');
        self::assertNotFalse($logo);
        self::assertNotFalse($lines);
        // assertLessThan($expected, $actual) asserts $actual < $expected, so the
// crest's offset is the ACTUAL here. Reading these the other way round
        // would assert the crest comes AFTER the name, which is the opposite
        // of the layout and would still pass on the wrong markup.
        self::assertLessThan($lines, $logo, 'The crest must be emitted before the name lines.');

        // All three name lines, inside the name block.
        $name = substr($js, $lines, 700);
        self::assertStringContainsString('BESTLINK COLLEGE OF THE PHILIPPINES', $name);
        self::assertStringContainsString('College of Computer Studies', $name);
        self::assertStringContainsString('1071 Brgy. Kaligayahan', $name);

        // TRAP 1: the crest column must be a FIXED width. With auto columns
        // the column takes the image's intrinsic width and height:100%
        // against an auto row is circular - the shield renders three times
        // too big, which is the failure the original layout was built to avoid.
        self::assertStringContainsString(
            '.lh-top { display:grid; grid-template-columns:76px auto;',
            $js,
            'The crest column width must stay pinned at a fixed size, never auto.'
        );
        self::assertStringNotContainsString(
            'grid-template-columns: auto',
            $js,
            'An auto crest column combined with height:100% renders the shield three '
            . 'times too big.'
        );

        // TRAP 2: height:100% is REQUIRED beside the name. The row is
        // stretched by the text column, and the crest fills that height to
        // sit level with the name lines. Strip it as "dead CSS" and the
        // shield collapses to its intrinsic height at the top of the row.
        self::assertStringContainsString(
            '.lh-logo img { display:block; width:100%; height:100%;',
            $js,
            'height:100% is what makes the crest sit level with the name block. '
            . 'It is not a leftover from the stacked layout and must not be removed.'
        );

        // And a missing logo must emit nothing at all. An empty .lh-logo
        // column left a 76px hole beside the name that looked deliberate,
        // which is how a missing crest went unnoticed in a preview.
        self::assertStringNotContainsString(
            "'<div class=\"lh-logo\"></div>'",
            $js,
            'A missing logo must emit nothing at all, not an empty column.'
        );
    }

    /**
     * The module headings in the figures ledger have to read as headings.
     *
     * They were 7.5pt in a mid grey. At that weight they looked like
     * captions, so the ledger read as one flat list of counts and a reader
     * could not tell which module any row belonged to. Bold black is not
     * decoration here; it is the thing that separates the groups.
     */
    public function testTheFiguresLedgerModuleHeadingsAreBoldAndNotGrey(): void
    {
        $css = self::read(self::LETTERHEAD);

        self::assertStringContainsString(
            '.doc-figures tr.g th',
            $css,
            'The module group heading must have its own rule.'
        );

        $at  = strpos($css, '.doc-figures tr.g th');
        $end = strpos($css, '.doc-figures td {');
        self::assertNotFalse($at);
        self::assertNotFalse($end);
        self::assertGreaterThan($at, $end);
        $rule = substr($css, $at, $end - $at);

        self::assertStringContainsString(
            'font-weight: 700',
            $rule,
            'The module heading must be bold, or the figures below it read as one flat list.'
        );
        self::assertStringNotContainsString(
            '#475569',
            $rule,
            'A grey heading prints as a caption. It must be black like everything else '
            . 'on the sheet.'
        );
        self::assertStringNotContainsString(
            '7.5pt',
            $rule,
            '7.5pt is caption size. The heading has to outweigh the 9pt rows.'
        );
    }

    /**
     * The sheet must not claim to BE the analysis.
     *
     * It is a summary of a seven-section document. Without a line saying so,
     * a reader who filed the sheet has no idea a fuller report exists - and
     * an official document that understates what it is invites the reader to
     * treat it as the whole finding.
     */
    public function testTheSheetSaysItIsASummaryAndWhereTheFullReportIs(): void
    {
        $js = self::read(self::JS);

        self::assertStringContainsString(
            'AI INSIGHT SUMMARY',
            $js,
            'The title must say summary. "AI INSIGHT REPORT" on a one-page brief overstates it.'
        );
        self::assertStringContainsString(
            'Full analysis: seven sections',
            $js,
            'The sheet must name the full analysis and where it lives.'
        );
    }

    /**
     * The one page is achieved by printing less, not by printing smaller.
     *
     * Truncating prose mid-sentence would produce a mangled document that
     * fits; scaling the type down to fit seven sections would produce an
     * unreadable one. The lever the brief actually uses is the vertical
     * rhythm, opt-in per document so the grade template keeps the roomier
     * spacing.
     */
    public function testOnePageIsAchievedByTighteningRhythmNotByShrinkingType(): void
    {
        $js  = self::read(self::JS);
        $css = self::read(self::LETTERHEAD);

        self::assertStringContainsString(
            "bodyClass: 'brief'",
            $js,
            'The brief must ask for the compact rhythm explicitly.'
        );
        self::assertStringContainsString(
            '.brief {',
            $css,
            'The compact rhythm has to exist in the stylesheet.'
        );

        // The body type is unchanged: a reader who cannot read the sheet
        // without glasses has not been given a summary.
        self::assertStringNotContainsString(
            '.brief { font-size:',
            $css,
            'The brief must not shrink the body type to reach one page.'
        );

        // printDocument() has to apply it, and per document - a global
        // class would take the grade template down with it.
        self::assertStringContainsString(
            "opts.bodyClass ? ' class=\"' + esc(opts.bodyClass) + '\"' : ''",
            $css,
            'printDocument() must apply a caller-supplied body class.'
        );
    }

    /**
     * The ledger renders the SAME figures the screen shows.
     *
     * Two surfaces read side by side, one of them printed and filed, is
     * exactly where a recomputed number goes unnoticed. So the ledger takes
     * the server's string rather than building its own.
     */
    public function testTheFiguresLedgerRendersTheServerStringRatherThanRecomputing(): void
    {
        $js = self::read(self::JS);

        self::assertStringContainsString(
            'state.figures = meta.figures || null',
            $js,
            'The figures must be held on state so the print path uses the server\'s own string.'
        );
        self::assertStringContainsString(
            'function briefFiguresHtml(markdown)',
            $js,
            'The ledger takes the figures markdown as its input.'
        );

        // One module heading per group, and a two-column ledger. Emitting the
        // heading per row would print "Students" five times down the ledger;
        // one column of every figure is what pushed the sheet to a second
        // page in the first place.
        $at  = strpos($js, 'function briefFiguresHtml');
        // reportBodyHtml() used to follow the ledger and was the natural end
        // marker. It has been deleted, so the ledger is now bounded by the
        // function that comes after it. If that function is renamed this
        // substring silently matches nothing and substr() below returns
        // false-to-empty, which the assertion would report as a missing
        // group row - a confusing message for a rename. So the marker is
        // checked to be a real offset first.
        $end = strpos($js, 'function showLoading');
        self::assertNotFalse($at);
        self::assertNotFalse($end);
        self::assertGreaterThan($at, $end, 'The ledger must be followed by another function.');
        $ledger = substr($js, $at, $end - $at);

        self::assertStringContainsString(
            'tr class="g"',
            $ledger,
            'A module heading must be marked as a group row, once per group.'
        );
        self::assertStringContainsString(
            'column(groups.slice(0, cut))',
            $ledger,
            'The ledger must render in two balanced columns.'
        );

        // The sheet drops the long breakdowns and the per-programme detail,
        // and says where they are. That is an editorial cut, so the cut has
        // to be visible in the code rather than implied by a length limit.
        self::assertStringContainsString(
            'if (nested) return;',
            $ledger,
            'The nested per-programme lines stay on screen; they wrap far too much for a sheet.'
        );
        self::assertStringContainsString(
            'if (/^By\\b/.test(text)) return;',
            $ledger,
            'The "By status / stage / type" breakdowns stay on screen; they are the '
            . 'longest rows on the page.'
        );
    }

    /** One print document, assembled in one place. */
    public function testThereIsExactlyOnePrintDocumentCallSite(): void
    {
        self::assertSame(
            1,
            substr_count(self::read(self::JS), 'BCPPrint.printDocument('),
            'Exactly one print-document call — the brief prints as ONE file. A second '
            . 'call site would mean a second, differently-shaped document somewhere.'
        );
    }

    /**
     * The endpoint enforces the seven-section shape; the print path keys its
     * split on that number. If either side drifts, the conclusion either
     * vanishes from the sheet or prints inside the body again.
     */
    public function testSectionSevenIsTheConclusionByContract(): void
    {
        $endpoint = self::read(self::ENDPOINT);
        $js = self::read(self::JS);

        self::assertStringContainsString(
            "'## 7.'",
            $endpoint,
            'The endpoint requires the seventh section before serving a report.'
        );
        self::assertStringContainsString(
            'Cross-Module Findings and Recommended Actions',
            $endpoint,
            'Section 7 is named by the prompt contract.'
        );
        self::assertMatchesRegularExpression(
            '/num\s*===\s*7/',
            $js,
            'The print path must lift out section 7 by its number — that is the conclusion.'
        );
    }

    /** A conclusion block the stylesheet knows nothing about is not a block. */
    public function testLetterheadStylesTheConclusionBlock(): void
    {
        $lh = self::read(self::LETTERHEAD);

        self::assertMatchesRegularExpression(
            '/\.doc-conclusion\s*\{[^}]*border-top/',
            $lh,
            'The conclusion is separated from the body by a rule; without it the two read as one.'
        );
    }

    /** Model output is HTML-escaped in the print path, as on screen. */
    public function testPrintBodyStillEscapesModelOutput(): void
    {
        $js = self::read(self::JS);

        self::assertMatchesRegularExpression(
            '/inlineFormat\(escapeHtml\(/',
            $js,
            'The printable renderer must escape model output before formatting it.'
        );
        self::assertStringContainsString(
            'function sectionLinesHtml(lines)',
            $js,
            'The printable renderer exists as its own function (markdownToHtml is the screen view).'
        );
    }

    /**
     * CSV export is gone from the script and the markup. Comments are stripped
     * first: a note explaining why CSV was removed may still name it, and a
     * test that cannot tell a comment from code is not a test of anything.
     */
    public function testExportCsvIsRemovedEverywhere(): void
    {
        $strip = static function (string $s): string {
            return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $s);
        };

        $js = $strip(self::read(self::JS));
        $page = $strip(self::read(self::PAGE));

        self::assertDoesNotMatchRegularExpression(
            '/exportCsv/i',
            $js,
            'The CSV export handler must be gone from js/insights.js, not merely unwired.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/text\/csv/i',
            $js,
            'The CSV blob type must be gone with the handler.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/exportCsv|Export CSV/i',
            $page,
            'The Export CSV menu entry must be gone from ai/insights.php.'
        );

        // The exports that remain must still be wired, or the menu offers
        // choices that do nothing — the failure mode CSV removal risks.
        self::assertStringContainsString('exportPdf', $js, 'PDF export must survive the removal of CSV.');
        self::assertStringContainsString('exportTxt', $js, 'TXT export must survive the removal of CSV.');
        self::assertStringContainsString('exportPdf', $page, 'The menu must still offer PDF.');
        self::assertStringContainsString('exportTxt', $page, 'The menu must still offer TXT.');
    }
}
