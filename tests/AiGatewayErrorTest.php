<?php
// ============================================================
//  TESTS/AIGATEWAYERRORTEST.PHP
//  The Insights page shows the gateway's own words when a report
//  cannot be generated. For the one failure this deployment hits
//  most - the free tier is closed to third-party clients - those
//  words were a raw JSON blob that named neither the cause nor the
//  remedy, and whose obvious remedy (add an API key) is wrong.
//
//  These tests pin the translation, because the message is the only
//  thing standing between a registrar and an afternoon of guessing.
// ============================================================

use PHPUnit\Framework\TestCase;

final class AiGatewayErrorTest extends TestCase
{
    /** The exact body OpenCode Zen returned on the live report attempt. */
    private const REAL_FREE_TIER_BODY = '{"type":"error","error":{"type":"FreeTierError",'
        . '"message":"Error from provider (Console): OpenCode\'s free tier can only be used '
        . 'from within OpenCode"}}';

    protected function setUp(): void
    {
        // ai_client.php pulls in shared/config.php, which defines the REAL
        // chain - all "-free" ids. Defining AI_MODELS here would collide with
        // it, and stubbing it would defeat the point: the branch under test is
        // chosen by inspecting the chain the office actually ships.
        require_once __DIR__ . '/../shared/ai_client.php';

        self::assertStringContainsString('-free', (string) AI_MODEL,
            'This test only means something while the default model is free-tier.');
    }

    public function testFreeTierRefusalExplainsItself(): void
    {
        $msg = aiExplainGatewayError(403, self::REAL_FREE_TIER_BODY);

        self::assertNotSame('', $msg, 'The free-tier refusal must be recognised, not passed through raw.');
        self::assertStringNotContainsString('{"type"', $msg,
            'Raw gateway JSON must not reach the registrar.');
        self::assertStringNotContainsString('FreeTierError', $msg,
            'An internal error class name is not an explanation.');
    }

    public function testItNamesTheActualCauseAndTheActualFix(): void
    {
        $msg = aiExplainGatewayError(403, self::REAL_FREE_TIER_BODY);

        self::assertStringContainsString('free-tier', $msg);
        // The gate is the client, not the credential. Saying only "add a key"
        // would send the operator straight back to the hosting panel, which
        // is exactly where they have already been.
        self::assertMatchesRegularExpression(
            '/does NOT change|not fix/i',
            $msg,
            'The message must warn that adding an API key does not fix this.'
        );
        self::assertStringContainsString('AI_MODELS', $msg,
            'The remedy is a config change; the message must name the setting.');
        self::assertStringContainsString('OPENCODE_API_KEY', $msg);
    }

    public function testTheRateLimitVariantIsCaughtToo(): void
    {
        // The same gate is reported as 429 FreeUsageLimitError by some paths.
        $msg = aiExplainGatewayError(429, '{"type":"error","error":{"type":"FreeUsageLimitError","message":"Rate limit exceeded."}}');
        self::assertNotSame('', $msg, 'The 429 variant of the same gate must also be recognised.');
    }

    public function testAnUnrelatedErrorFallsThroughUntouched(): void
    {
        // Losing detail on an error we do not recognise would be worse than
        // the JSON blob.
        self::assertSame('', aiExplainGatewayError(500, 'upstream exploded'));
        self::assertSame('', aiExplainGatewayError(401, 'invalid api key'));
        self::assertSame('', aiExplainGatewayError(429, 'slow down'));
    }
}
