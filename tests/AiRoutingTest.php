<?php
// ============================================================
//  TESTS/AIROUTINGTEST.PHP
//  Which transport a request goes out on.
//
//  aiIsGeminiModel() is a substring test, and that is exactly how a routed
//  gateway gets silently bypassed: an aggregator serves models named
//  "gemini/gemini-3-flash" and "openai/gpt-5" behind one URL, so the name
//  alone cannot say who is meant to answer. When it guesses wrong the
//  request leaves the router, reaches Google with no key, and fails with a
//  401 that names neither the router nor the configuration.
// ============================================================

use PHPUnit\Framework\TestCase;
/**
 * Which transport a request goes out on.
 *
 * aiIsGeminiModel() is a substring test, and that is exactly how a routed
 * gateway gets silently bypassed: an aggregator serves models named
 * "gemini/gemini-3-flash" and "openai/gpt-5" behind one URL, so the name
 * alone cannot say who is meant to answer. When it guesses wrong the
 * request leaves the router, reaches Google with no key, and fails with a
 * 401 that names neither the router nor the configuration.
 */
final class AiRoutingTest extends TestCase
{
    /**
     * Evaluate the rule under a given provider/url, in a child process.
     *
     * AI_PROVIDER and AI_API_URL are constants, so they cannot be varied
     * inside one PHP process. Re-declaring the function per case would be
     * testing a copy rather than the real thing, so each case runs the real
     * file with the constants defined by a bootstrap.
     *
     * @dataProvider routingProvider
     */
    public function testTransportChoice(bool $expectGemini, string $provider, string $url, string $model, string $why): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'airt') . '.php';
        file_put_contents($bootstrap, sprintf(
            "<?php define('AI_PROVIDER', %s); define('AI_API_URL', %s);"
            . " require %s; echo aiIsGeminiModel(%s) ? 'gemini' : 'openai';",
            var_export($provider, true),
            var_export($url, true),
            var_export(__DIR__ . '/../shared/ai_client.php', true),
            var_export($model, true)
        ));

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootstrap) . ' 2>&1');
        @unlink($bootstrap);

        self::assertSame(
            $expectGemini ? 'gemini' : 'openai',
            trim((string) $out),
            $why
        );
    }

    public function routingProvider(): array
    {
        $zen = 'https://opencode.ai/zen/v1/chat/completions';

        return [
            'zen still routes gemini by name' => [
                true, 'zen', $zen, 'gemini-3-flash',
                'The default gateway is unchanged: a Gemini-named model on Zen still uses the native path.',
            ],
            'gemini provider always wins' => [
                true, 'gemini', $zen, 'gemini-3-flash',
                'AI_PROVIDER=gemini must route to Google whatever the model is called.',
            ],
            'aggregator host with gemini id stays on the aggregator' => [
                false, 'zen', 'https://router.example/v1/chat/completions', 'gemini/gemini-3-flash',
                'A Gemini-named model served by an aggregator must NOT be hijacked into '
                . 'Google - that bypasses the router and fails with an unrelated 401.',
            ],
            'aggregator host, non-gemini id' => [
                false, 'zen', 'https://router.example/v1/chat/completions', 'openai/gpt-5',
                'Unrelated model on an aggregator: OpenAI-compatible path.',
            ],
            'explicit aggregator provider wins over the name' => [
                false, '9router', $zen, 'gemini-3-flash',
                'An explicit non-Gemini provider settles it even if the URL still says Zen.',
            ],
            'openrouter gemini id stays on openrouter' => [
                false, 'zen', 'https://openrouter.ai/api/v1/chat/completions', 'gemini/gemini-3-flash',
                'OpenRouter serves its own Gemini-branded ids; sending those to Google would '
                . 'bypass OpenRouter and double-charge or simply fail.',
            ],
        ];
    }

    /**
     * Which key is sent, in priority order.
     *
     * The order decides whose credential actually leaves the office. When
     * the router is configured through the generic AI_API_KEY, a leftover
     * OPENCODE_API_KEY would be sent to 9Router instead - a 401 from the
     * router that reads as "my key is wrong" when in fact the wrong key was
     * chosen before the right one was ever consulted.
     *
     * Run in a child process: these are environment lookups and the module
     * defines constants, so one process can only see one configuration.
     *
     * @dataProvider keyPriorityProvider
     */
    public function testKeyLookupPriority(string $expect, array $env, string $why): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'aik') . '.php';
        file_put_contents($bootstrap, sprintf(
            "<?php foreach (%s as \$k => \$v) { putenv(\$k . '=' . \$v); \$_ENV[\$k] = \$v; \$_SERVER[\$k] = \$v; }"
            . " require %s; echo AI_API_KEY === '' ? '(none)' : AI_API_KEY;",
            var_export($env, true),
            var_export(__DIR__ . '/../shared/config.php', true)
        ));

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootstrap) . ' 2>&1');
        @unlink($bootstrap);

        self::assertSame($expect, trim((string) $out), $why);
    }

    public function keyPriorityProvider(): array
    {
        return [
            'router key wins over everything' => [
                'sk-router',
                [
                    'NINEROUTER_KEY'    => 'sk-router',
                    'OPENCODE_API_KEY'  => 'sk-opencode',
                    'OPENROUTER_API_KEY'=> 'sk-openrouter',
                    'AI_API_KEY'        => 'sk-generic',
                ],
                'NINEROUTER_KEY must be first: a 9Router key named OPENCODE_API_KEY '
                . 'would otherwise be sent to the router, or the router key would '
                . 'be shadowed by a leftover gateway key.',
            ],
            'opencode still works alone' => [
                'sk-opencode',
                ['OPENCODE_API_KEY' => 'sk-opencode'],
                'The existing Zen setup must not change.',
            ],
            'openrouter still works alone' => [
                'sk-openrouter',
                ['OPENROUTER_API_KEY' => 'sk-openrouter'],
                'The old OpenRouter path must not change.',
            ],
            'generic still works alone' => [
                'sk-generic',
                ['AI_API_KEY' => 'sk-generic'],
                'The provider-agnostic fallback must not change.',
            ],
            'no key is honest about it' => [
                '(none)',
                [],
                'With nothing set the key must be empty, not a stray leftover, so a '
                . 'failure is reported as a missing key rather than a wrong one.',
            ],
        ];
    }

    /**
     * One configured model string, two gateways that want it spelled
     * differently.
     *
     * OpenCode Zen addresses models bare ("mimo-v2.5-free"); an aggregator
     * addresses them by provider ("oc/mimo-v2.5-free"). Both spellings are
     * the OpenCode CONFIG form's tail, and both appear in real setups - the
     * default in this file uses the prefixed one so it survives being moved
     * behind a router.
     *
     * Getting this wrong is a 404 or 400 from the gateway that reads like a
     * missing model rather than a naming mismatch, so it is worth pinning.
     *
     * @dataProvider modelPrefixProvider
     */
    public function testModelPrefixFollowsTheGateway(string $expect, string $url, string $model, string $why): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'ainm') . '.php';
        file_put_contents($bootstrap, sprintf(
            "<?php define('AI_API_URL', %s); require %s; echo aiNormalizeModel(%s);",
            var_export($url, true),
            var_export(__DIR__ . '/../shared/ai_client.php', true),
            var_export($model, true)
        ));

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootstrap) . ' 2>&1');
        @unlink($bootstrap);

        self::assertSame($expect, trim((string) $out), $why);
    }

    public function modelPrefixProvider(): array
    {
        $zen = 'https://opencode.ai/zen/v1/chat/completions';
        $router = 'https://router.example/v1/chat/completions';
        $openrouter = 'https://openrouter.ai/api/v1/chat/completions';

        return [
            'oc/ stripped for Zen direct' => [
                'mimo-v2.5-free', $zen, 'oc/mimo-v2.5-free',
                'Zen takes bare ids; sending oc/... there is a 404.',
            ],
            'opencode/ stripped for Zen direct' => [
                'mimo-v2.5-free', $zen, 'opencode/mimo-v2.5-free',
                'The long spelling of the same mistake must be handled too.',
            ],
            'bare left alone for Zen direct' => [
                'mimo-v2.5-free', $zen, 'mimo-v2.5-free',
                'An already-correct id must pass through untouched.',
            ],
            'oc/ KEPT through a router' => [
                'oc/mimo-v2.5-free', $router, 'oc/mimo-v2.5-free',
                'Stripping the prefix at an aggregator removes the very part that '
                . 'says which provider to use - the router would not know who to call.',
            ],
            'bare KEPT through a router' => [
                'mimo-v2.5-free', $router, 'mimo-v2.5-free',
                'Whatever the operator typed is the router\'s business, not ours. '
                . 'ai_preflight.php reports ids the router does not serve.',
            ],
            'openrouter/ stripped for OpenRouter' => [
                'inclusionai/ling-3.0-flash-vl:free', $openrouter,
                'openrouter/inclusionai/ling-3.0-flash-vl:free',
                'The pre-existing OpenRouter rule must survive the change.',
            ],
            'oc/ untouched on OpenRouter' => [
                'oc/mimo-v2.5-free', $openrouter, 'oc/mimo-v2.5-free',
                'Only the opencode.ai host strips this prefix; other gateways '
                . 'legitimately use two-letter namespaces of their own.',
            ],
            'prefix mid-name is not stripped' => [
                'vendor/opencode-tuned-7b', $zen, 'vendor/opencode-tuned-7b',
                'Stripping is anchored to the start: a model that merely contains '
                . '"opencode" later must survive.',
            ],
            'empty stays empty' => [
                '', $zen, '',
                'An empty model must not become something routable.',
            ],
        ];
    }
}
