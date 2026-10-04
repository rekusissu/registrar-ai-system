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

    /** Section 7 is the conclusion by contract; the print path must say so. */
    public function testPrintDocumentSplitsBodyAndConclusion(): void
    {
        $js = self::read(self::JS);

        self::assertStringContainsString(
            'splitReportSections(state.report)',
            $js,
            'The print path must parse the report into sections before rendering it.'
        );
        self::assertStringContainsString(
            'reportBodyHtml(parts.body)',
            $js,
            'The body of the printout is sections 1-6, not the whole report.'
        );
        self::assertStringContainsString(
            '<h2 class="doc-h">Conclusion</h2>',
            $js,
            'The conclusion must carry a literal "Conclusion" heading.'
        );
        self::assertStringContainsString(
            'doc-body doc-conclusion',
            $js,
            'The conclusion must be its own block in the printed document.'
        );
        // One print document, assembled in one place: a second call site
        // would mean a second, differently-shaped document somewhere.
        self::assertSame(
            1,
            substr_count($js, 'BCPPrint.printDocument('),
            'Exactly one print-document call — the report prints as ONE file.'
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
