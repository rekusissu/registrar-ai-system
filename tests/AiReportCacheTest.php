<?php
// ============================================================
//  TESTS/AIREPORTCACHETEST.PHP
//  Guards the reuse key for generated AI reports.
//
//  The key is the whole safety argument. A hit means "this analysis was
//  written from exactly the figures on screen right now", and every way
//  that can become a lie is a way a registrar reads a stale analysis as
//  a current one. So the tests pin the two directions separately: what
//  MUST share a key (identical inputs) and what must NOT (any change to
//  the period, the model, or a single figure).
//
//  APP_ROOT is pointed at a scratch directory so these tests never read
//  or write the real report cache.
// ============================================================

use PHPUnit\Framework\TestCase;

final class AiReportCacheTest extends TestCase
{
    private static $scratch;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../shared/ai_report_cache.php';

        // Redirect by EXPLICIT override, not by defining APP_ROOT first.
        //
        // APP_ROOT is defined by shared/config.php, which several tests load
        // as a side effect. Setting it "if not already defined" therefore
        // loses the race depending on execution order, and this class would
        // write its fixtures into the LIVE storage/ai-insights directory -
        // where a fixture is indistinguishable from a real report to the code
        // that serves it. The override is unconditional, so order cannot
        // matter.
        self::$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ai-report-cache-test-' . getmypid();
        aiReportCacheSetRoot(self::$scratch);

        // Prove the redirection took effect rather than assuming it, and fail
        // loudly here instead of silently poisoning the real cache.
        $resolved = (string) aiReportCacheDir();
        self::assertStringStartsWith(
            self::$scratch,
            $resolved,
            'The cache test must not write to the live cache directory.'
        );
    }

    public static function tearDownAfterClass(): void
    {
        $dir = self::$scratch . DIRECTORY_SEPARATOR . 'ai-insights';
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
        @rmdir(self::$scratch);

        // Hand the cache back to the real location for anything that runs
        // after this class.
        aiReportCacheSetRoot(null);
    }

    private function period(string $month = '2026-04'): array
    {
        return ['start' => $month . '-01 00:00:00', 'end' => $month . '-28 23:59:59', 'label' => $month];
    }

    private function facts(array $over = []): array
    {
        return $over + ['enrolled' => 120, 'docs' => 40, 'cards' => 90];
    }

    public function testIdenticalInputsShareAKey(): void
    {
        self::assertSame(
            aiReportCacheKey($this->period(), $this->facts()),
            aiReportCacheKey($this->period(), $this->facts()),
            'The same figures must produce the same key, or nothing is ever reused.'
        );
    }

    /**
     * The load-bearing case: one changed figure must invalidate everything.
     * A cache that served a hit here would show last period's reading under
     * this period's numbers.
     *
     * @dataProvider changedFactProvider
     */
    public function testAnyChangedFigureInvalidatesTheKey(string $label, array $facts): void
    {
        self::assertNotSame(
            aiReportCacheKey($this->period(), $this->facts()),
            aiReportCacheKey($this->period(), $facts),
            "Changing {$label} did not invalidate the report key."
        );
    }

    public function changedFactProvider(): array
    {
        return [
            'enrolment' => ['enrolment', ['enrolled' => 121]],
            'documents' => ['the document count', ['docs' => 41]],
            'cards'     => ['the card count', ['cards' => 91]],
'zero'      => ['a figure going to zero', ['docs' => 0]],
        ];
    }


public function testADifferentPeriodNeverSharesAKey(): void
    {
        self::assertNotSame(
            aiReportCacheKey($this->period('2026-04'), $this->facts()),
            aiReportCacheKey($this->period('2026-05'), $this->facts()),
            'Identical figures under a different period must not be reused.'
        );
    }

    public function testADifferentModelNeverSharesAKey(): void
    {
        // The badge on screen names the model that answered, so a report
        // written by one model is not interchangeable with another.
        self::assertNotSame(
            aiReportCacheKey($this->period(), $this->facts(), 'space-bunny-free'),
            aiReportCacheKey($this->period(), $this->facts(), 'mimo-v2.5-free'),
        );
    }

    public function testAnUnencodableFactsSetRefusesTheKey(): void
    {
        // json_encode returns false on invalid UTF-8. Hashing that would give
        // one shared key for every such failure, so the caller must be told
        // "no key" and fall through to always calling the model.
        self::assertSame('', aiReportCacheKey($this->period(), ['name' => "\xB1\x31"]));
    }

    public function testAStoredReportIsReturnedVerbatim(): void
    {
        $key  = aiReportCacheKey($this->period(), $this->facts(['enrolled' => 1200]));
        $text = "## 1. Executive Summary\nThe period closed.\n## 7. Cross-Module Findings\n- one action";
        aiReportCacheSet($key, [
            'report'       => $text,
            'model'        => 'space-bunny-free',
            'generated_at' => '2026-04-30 09:00:00',
        ]);

        $hit = aiReportCacheGet($key);
        self::assertIsArray($hit);
        self::assertSame($text, $hit['report'], 'The stored report must come back byte-for-byte.');
        self::assertSame('space-bunny-free', $hit['model']);
    }

    public function testAFailedReportIsNeverStored(): void
    {
        // The user has already waited a minute when this returns. Caching the
        // failure would strand the period with no way to retry.
        $key = aiReportCacheKey($this->period(), $this->facts(['enrolled' => 7]));
        aiReportCacheSet($key, ['report' => '', 'ai_error' => 'gateway timeout']);
        self::assertNull(aiReportCacheGet($key), 'An empty report must not be stored.');
    }

    public function testAMissingKeyIsAMiss(): void
    {
        self::assertNull(aiReportCacheGet('does-not-exist'));
        self::assertNull(aiReportCacheGet(''), 'An empty key must never read a file.');
    }

    public function testATruncatedFileIsTreatedAsAMiss(): void
    {
        $key = aiReportCacheKey($this->period(), $this->facts(['enrolled' => 555]));
        $dir = aiReportCacheDir();
        self::assertNotNull($dir);
        file_put_contents($dir . DIRECTORY_SEPARATOR . $key . '.json', '{"report":"cut off');

        // Serving half a report is indistinguishable, to the reader, from the
        // model having produced half a report. It must be rejected instead.
        self::assertNull(aiReportCacheGet($key), 'A truncated cache file must read as a miss.');
    }
}
