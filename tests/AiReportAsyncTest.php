<?php

use PHPUnit\Framework\TestCase;

/**
 * Generating the AI Insight report must not hold a request open for a minute.
 *
 * The report has always cost 40-70 seconds. What it must never do is make a
 * single HTTP request last that long, because the failure that reached the
 * office was not PHP's: the HOST answered 503 "Gateway timeout" with an HTML
 * page. ai-insights-report.php only ever emits 401/403/405/500, so a 503
 * cannot have come from it - it came from a proxy in front of PHP, sitting on
 * its own clock.
 *
 * Raising max_execution_time cannot fix that. It moves PHP's limit, not the
 * proxy's, and .user.ini was already at 180 when the 503s started, so the
 * limit being raised was never the problem.
 *
 * So the long work moved off the request: the POST answers "pending" in about
 * a second, generation continues after fastcgi_finish_request(), and the
 * browser polls a GET that is a cache read. These tests pin that shape, and
 * pin the two things that would silently put the wait back.
 */
final class AiReportAsyncTest extends TestCase
{
    private const ENDPOINT = __DIR__ . '/../api/ai-insights-report.php';
    private const CLIENT   = __DIR__ . '/../js/insights.js';

    private function src(string $path): string
    {
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * The response must be handed over BEFORE the model is called.
     *
     * This is the whole fix. If fastcgi_finish_request() moves after the call
     * - or is never reached - the request is 40-70 seconds long again and the
     * proxy times it out exactly as before, while every other test here still
     * passes.
     */
    public function testTheResponseIsFlushedBeforeTheModelIsCalled(): void
    {
        $src = $this->src(self::ENDPOINT);
        $fin = strpos($src, 'fastcgi_finish_request');
        $gen = strpos($src, 'aiGenerate($systemPrompt, $userPrompt');

        self::assertNotFalse($gen, 'The endpoint no longer calls the model.');
        self::assertNotFalse($fin, 'The request must be detached from the long work.');
        self::assertLessThan(
            $gen, $fin,
            'fastcgi_finish_request() must run BEFORE the model is called. After it the '
            . 'request is still open while the model thinks, which is the 60s the proxy rejects.'
        );
    }

    /** A cache miss must answer 'pending', not wait for the model. */
    public function testACacheMissAnswersPendingRatherThanWaiting(): void
    {
        self::assertStringContainsString(
            "'status'  => 'pending'",
            $this->src(self::ENDPOINT),
            'A cache miss must tell the browser the work is under way. If it returns the '
            . 'report synchronously the request is a minute long and the 503 returns.'
        );
    }

    /**
     * The poll must be a cache read.
     *
     * If the GET rebuilt the report it would cost the same 40-70 seconds, and
     * the client would be retrying the exact request that was timing out - the
     * bug wearing a fix's clothes.
     */
    public function testThePollOnlyReadsTheCache(): void
    {
        $src   = $this->src(self::ENDPOINT);
        $poll  = strpos($src, '$isPoll');
        self::assertNotFalse($poll);
        $block = substr($src, $poll, 2200);

        self::assertStringContainsString('aiReportCacheGet(', $block,
            'The poll reads the finished report out of the cache.');
        self::assertStringNotContainsString('aiGenerate(', $block,
            'The poll must never generate. That would make every retry cost a minute.');
    }

    /**
     * A miss must answer 'pending', never an error.
     *
     * The browser cannot distinguish "still working" from "failed". Reporting
     * a miss as an error shows a failure banner for a report that is thirty
     * seconds from being ready.
     */
    public function testAPollMissIsPendingNotAnError(): void
    {
        $src   = $this->src(self::ENDPOINT);
        $poll  = strpos($src, '$isPoll');
        $block = substr($src, $poll, 2200);

        self::assertStringContainsString("['status' => 'pending']", $block);
        self::assertStringContainsString("'success' => true", $block);
    }

    /** The client must poll, and must act on a ready answer. */
    public function testTheClientPollsUntilTheReportIsReady(): void
    {
        $js = $this->src(self::CLIENT);

        self::assertStringContainsString('startPolling(', $js);
        self::assertStringContainsString("status === 'ready'", $js,
            'The client must act on a ready poll.');
        self::assertStringContainsString('setInterval', $js,
            'Polling has to be on a timer, or the page waits for nothing.');
    }

    /**
     * Polling must be bounded.
     *
     * An unbounded poll is a spinner that never resolves and a report that is
     * never shown: the worst version of this feature. The wait must give up,
     * and must say that it gave up.
     */
    public function testPollingGivesUpRatherThanSpinningForever(): void
    {
        $js = $this->src(self::CLIENT);

        self::assertStringContainsString('POLL_GIVE_UP_MS', $js);
        self::assertMatchesRegularExpression(
            '/waited\s*>\s*POLL_GIVE_UP_MS/',
            $js,
            'The give-up must be tested against the clock, or it is decoration.'
        );
        self::assertStringContainsString('has not finished yet', $js,
            'Giving up must be reported honestly, not as a silent stall.');
    }

    /**
     * "Regenerate" stays synchronous on purpose.
     *
     * $force bypasses the cache on both sides, so a forced run would poll a
     * key nothing is ever written to and spin to the give-up for nothing. The
     * cost is one slow request on a rare, deliberate action, which beats a
     * spinner that resolves to nothing.
     */
    public function testForcedRegenerationIsNotDetached(): void
    {
        self::assertStringContainsString(
            '$canDetach && !$force',
            $this->src(self::ENDPOINT),
            'A forced run must stay synchronous; polling it would look for a key '
            . 'nothing writes and always time out.'
        );
    }

    /**
     * A genuine gateway failure must still be explained.
     *
     * The 503 advice is gone from the slow path because the wait is bounded
     * in the app now. But a real 5xx from somewhere else must not be swallowed.
     */
    public function testTheClientStillExplainsAGenuineGatewayFailure(): void
    {
        self::assertStringContainsString(
            'describeFailedResponse(',
            $this->src(self::CLIENT),
            'A real 5xx must still be explained rather than swallowed.'
        );
    }
}