<?php
// ============================================================
//  TESTS/DOCSTATUSGROUPSTEST.PHP
//  The document chart must chart the workflow the system HAS,
//  not the one it used to have.
//
//  document_requests.document_status is an 8-value enum — the v2
//  workflow (registrar_ai.sql, migrations/document_online_lifecycle.sql).
//  aiInsightDocStatusGroup() collapses those 8 into 5 chart series and
//  falls back to 'Processing' for anything the map does not name. A
//  status added to the enum without a matching entry here is therefore
//  not dropped — it is silently drawn in the WRONG series, and the
//  Document Transaction Overview reports the old workflow while looking
//  current. That is exactly what happened to Awaiting_Payment and
//  Shipped: the two stages every online and courier request passes
//  through were both being charted as "Processing".
//
//  The enum is parsed from registrar_ai.sql (the canonical schema) so a
//  schema change without a chart update fails HERE instead of on a
//  registrar's screen. The five series names are pinned because
//  $seriesColors in shared/analytics.php and js/insights.js hardcode
//  them.
// ============================================================

use PHPUnit\Framework\TestCase;

final class DocStatusGroupsTest extends TestCase
{
    private static $enum;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../shared/analytics.php';

        $sql = file_get_contents(__DIR__ . '/../registrar_ai.sql');
        self::assertNotFalse(
            $sql,
            'registrar_ai.sql must be readable — it is the canonical schema this test contracts against.'
        );

        $found = preg_match(
            "/`document_status`\\s+enum\\s*\\(([^)]+)\\)/",
            (string) $sql,
            $m
        );
        self::assertSame(
            1,
            $found,
            'registrar_ai.sql must declare document_requests.document_status as an enum.'
        );

        preg_match_all("/'([^']+)'/", $m[1], $vals);
        self::$enum = $vals[1];
    }

    public function testEnumIsTheEightStageV2Workflow(): void
    {
        self::assertSame(
            ['Filed', 'Pending_Clearance', 'Awaiting_Payment', 'Processing',
             'Ready', 'Shipped', 'Claimed', 'Rejected'],
            self::$enum,
            'The document_status enum changed. Update aiInsightDocStatusGroups() and $seriesColors to match before adjusting this expectation.'
        );
    }

    public function testEveryEnumValueHasExactlyOneChartGroup(): void
    {
        foreach (self::$enum as $status) {
            $owners = [];
            foreach (aiInsightDocStatusGroups() as $group => $members) {
                if (in_array($status, $members, true)) {
                    $owners[] = $group;
                }
            }
            self::assertCount(
                1,
                $owners,
                "document_status '$status' must belong to exactly one chart series; found: " . json_encode($owners)
            );
        }
    }

    public function testChartSeriesNamesMatchTheColourMap(): void
    {
        self::assertSame(
            ['Pending', 'Processing', 'Ready', 'Claimed', 'Rejected'],
            array_keys(aiInsightDocStatusGroups()),
            '$seriesColors in aiInsightBuild() and the chart palette in js/insights.js hardcode these five series names.'
        );
    }

    public function testOnlineLifecycleStatusesAreNotMisfiledAsProcessing(): void
    {
        // The regression this test exists for: both stages every online
        // and courier request passes through fell through to the
        // 'Processing' fallback and were drawn in the wrong colour.
        self::assertSame('Pending', aiInsightDocStatusGroup('Filed'));
        self::assertSame('Pending', aiInsightDocStatusGroup('Awaiting_Payment'));
        self::assertSame('Ready',   aiInsightDocStatusGroup('Shipped'));
        self::assertSame('Processing', aiInsightDocStatusGroup('Processing'));
        self::assertSame('Claimed', aiInsightDocStatusGroup('Claimed'));
        self::assertSame('Rejected', aiInsightDocStatusGroup('Rejected'));
    }
}