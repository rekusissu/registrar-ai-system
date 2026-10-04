<?php
// ============================================================
//  SHARED/AI_CLIENT.PHP
//  OpenAI-compatible client for AI API calls.
//  Works with OpenAI directly (api.openai.com) or any
//  OpenAI-compatible gateway (OpenRouter, Ollama, etc.).
//  Also supports Google Gemini (generateContent) when
//  AI_PROVIDER=gemini or the model name contains 'gemini'.
//  All responses are cached to reduce API calls.
//
//  Usage:
//    require_once __DIR__ . '/ai_client.php';
//    $text = aiGenerate('You are an assistant.', 'Summarize...');
//    $json = aiGenerateJson('Extract fields', '...', $fallback);
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

if (defined('AI_CLIENT_LOADED')) {
    return;
}
define('AI_CLIENT_LOADED', true);

/**
 * Normalize a model name for the configured gateway before it is sent.
 *
 * THE SAME MODEL HAS TWO NAMES, DEPENDING ON WHO IS SERVING IT.
 *
 * OpenCode Zen's HTTP API takes BARE ids - "mimo-v2.5-free", with no prefix.
 * But that string is also the OpenCode CONFIG form's tail, and aggregators
 * namespace every model by the provider serving it: 9Router, for one, uses
 * "openai/gpt-5" and "cc/claude-opus-4-7", so its OpenCode provider is very
 * likely "oc/mimo-v2.5-free".
 *
 * So an operator configuring this app has to type one value that may have to
 * be spelled two different ways depending on AI_API_URL, and picking wrong
 * fails with a 404 or a 400 from the gateway that reads like a broken model
 * rather than a naming mismatch.
 *
 * Hence: strip the prefix ONLY when talking to OpenCode directly, and leave
 * it alone when an aggregator is in front. Both spellings then work against
 * whichever gateway is actually configured, and the same AI_MODELS value can
 * be moved between the two without editing.
 *
 * OpenRouter is handled the same way for the same reason, and predates this:
 * "openrouter/inclusionai/ling-3.0-flash-vl:free" is rejected with HTTP 400.
 */
function aiNormalizeModel(string $model): string {
    $model = trim($model);
    if ($model === '') {
        return '';
    }

    $host = strtolower((string) (parse_url(AI_API_URL, PHP_URL_HOST) ?: ''));

    if ($host === 'openrouter.ai' || strpos($host, '.openrouter.ai') !== false) {
        $model = preg_replace('#^openrouter/+#i', '', $model);
    } elseif ($host === 'opencode.ai' || strpos($host, '.opencode.ai') !== false) {
        // Bare ids only on this host. Guarded to the start of the string so a
        // model legitimately containing "opencode" later in its name is safe.
        $model = preg_replace('#^(?:oc|opencode)/+#i', '', $model);
    }

    return $model;
}

/**
 * Send a prompt to the AI provider (OpenRouter gateway or Gemini),
 * cached in `ai_cache`. Returns the assistant text, or '' on failure.
 *
 * @param string $systemPrompt  System/task instruction
 * @param string $userPrompt    User content
 * @param array  $opts          {model?, max_tokens?, temperature?, forceRefresh?}
 * @return string
 */
function aiGenerate($systemPrompt, $userPrompt, array $opts = []) {
    $db = Database::getInstance();
    $ttl      = (int)   ($opts['ttl']      ?? AI_CACHE_TTL);
    $maxTok   = (int)   ($opts['max_tokens'] ?? 1024);
    $temp     = (float) ($opts['temperature'] ?? 0.2);
    $force    = !empty($opts['forceRefresh']);

    // Models to try, in order. If $opts['model'] is set, use only it;
    // otherwise use the configured fallback list (AI_MODELS, defaulting to
    // AI_MODEL). This handles transient overloads on a single backend.
    if (!empty($opts['model'])) {
        $models = [(string) $opts['model']];
    } elseif (defined('AI_MODELS') && is_array(AI_MODELS) && count(AI_MODELS) > 0) {
        $models = array_map('strval', AI_MODELS);
        if (!in_array((string) AI_MODEL, $models, true)) {
            array_unshift($models, (string) AI_MODEL);
        }
    } else {
        $models = [(string) AI_MODEL];
    }

    // Strips any "openrouter/" prefix a stale env var may have injected
    // (the gateway 400s those). Cheap and idempotent for correct models.
    $models = array_map('aiNormalizeModel', $models);

    $lastResponse = null;
    $usedModel    = null;

    foreach ($models as $model) {
        $cacheKey = 'ai:' . $model . ':' . md5($systemPrompt . "\0" . $userPrompt);

        if (!$force) {
            $cached = $db->fetchOne(
                "SELECT response FROM ai_cache
                 WHERE prompt_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
                [$cacheKey]
            );
            if ($cached && isset($cached['response'])) {
                return $cached['response'];
            }
        }

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'max_tokens'  => $maxTok,
            'temperature' => $temp,
        ];

        $response = aiHttpChat($payload);
        if ($response === null) {
            error_log('ai_client: model "' . $model . '" failed, trying next.');
            continue; // try next model
        }

        $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));

        // TRUNCATED REPLY = FAILURE, not a shorter answer.
        //
        // finish_reason 'length' means the model hit max_tokens and stopped
        // mid-sentence. This matters more now than it used to: the current
        // primary, stealth/space-bunny-alpha, has MANDATORY reasoning, and
        // reasoning tokens are drawn from the same max_tokens budget as the
        // answer. So a budget sized for the report can be partly consumed
        // before the model starts writing it, and the caller gets a report
        // that stops halfway with no indication that it did.
        //
        // Shipping that is the same class of bug this codebase has been
        // removing all session: output that looks complete and is not. It
        // fails over to the next model instead, and says so in the log.
        $finish = (string) ($response['choices'][0]['finish_reason'] ?? '');
        if ($finish === 'length') {
            error_log(sprintf(
                'ai_client: model "%s" truncated at max_tokens=%d; trying next model.',
                $model, $maxTok
            ));
            continue;
        }

        if ($text === '') {
            error_log('ai_client: model "' . $model . '" returned empty content, trying next.');
            continue;
        }

        $lastResponse = $text;
        $usedModel    = $model;
        break;
    }

    if ($lastResponse === null) {
        if (aiLastError() === '') {
            aiClientNote('Every configured AI model failed to return content.');
        }
        return '';
    }

    // Cache the successful result.
    try {
        $db->insert('ai_cache', [
            'prompt_hash' => 'ai:' . $usedModel . ':' . md5($systemPrompt . "\0" . $userPrompt),
            'prompt'      => mb_substr($userPrompt, 0, 4000),
            'response'    => $lastResponse,
            'model'       => $usedModel,
            'created_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
        ]);
    } catch (Exception $e) {
        // Cache write failure should not break the caller.
    }

    return $lastResponse;
}

/**
 * Send a vision-capable request with an image to the AI provider.
 * Uses a model from AI_MODELS that supports vision (gemini, minimax-m3, kimi).
 * Returns the assistant text, or '' on failure. Cached like aiGenerate.
 *
 * @param string $systemPrompt
 * @param string $userText     Text prompt accompanying the image
 * @param string $imageBase64  Raw image bytes (base64-encoded)
 * @param string $mimeType     e.g. image/png, image/jpeg
 * @param array  $opts
 * @return string
 */
function aiGenerateVision(string $systemPrompt, string $userText, string $imageBase64, string $mimeType = 'image/png', array $opts = []) {
    $db = Database::getInstance();
    $ttl  = (int)   ($opts['ttl']  ?? AI_CACHE_TTL);
    $maxTok = (int) ($opts['max_tokens'] ?? 800);
    $temp = (float) ($opts['temperature'] ?? 0.2);
    $force = !empty($opts['forceRefresh']);

    $models = (defined('AI_MODELS') && is_array(AI_MODELS) && count(AI_MODELS) > 0)
        ? array_map('strval', AI_MODELS)
        : [(string) AI_MODEL];

    // Prefer a vision-capable model first (gemini, minimax-m3, and kimi have vision).
    $preferred = array_filter($models, fn($m) => strpos($m, 'gemini') !== false || strpos($m, 'minimax') !== false || strpos($m, 'kimi') !== false);
    $models = array_merge(array_values($preferred), array_values(array_diff($models, $preferred)));

    // Same "openrouter/" prefix defense as aiGenerate().
    $models = array_map('aiNormalizeModel', $models);

    $dataUri = 'data:' . $mimeType . ';base64,' . $imageBase64;
    $cacheKey = 'vis:' . md5($systemPrompt . "\0" . $userText . "\0" . md5($imageBase64));

    if (!$force) {
        $cached = $db->fetchOne(
            "SELECT response FROM ai_cache WHERE prompt_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
            [$cacheKey]
        );
        if ($cached && isset($cached['response'])) {
            return $cached['response'];
        }
    }

    foreach ($models as $model) {
        $payload = [
            'model' => $model,
            'messages' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ]],
            'max_tokens'  => $maxTok,
            'temperature' => $temp,
        ];

        $response = aiHttpChat($payload);
        if ($response === null) {
            error_log('ai_client: vision model "' . $model . '" failed, trying next.');
            continue;
        }
        $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            error_log('ai_client: vision model "' . $model . '" returned empty, trying next.');
            continue;
        }

        try {
            $db->insert('ai_cache', [
                'prompt_hash' => $cacheKey,
                'prompt'      => mb_substr($userText, 0, 4000),
                'response'    => $text,
                'model'       => $model,
                'created_at'  => date('Y-m-d H:i:s'),
                'expires_at'  => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
            ]);
        } catch (Exception $e) {}

        return $text;
    }

    return '';
}

/**
 * Ask the model for a JSON object and parse it. Falls back to $fallback
 * on any failure (network, model error, or unparseable JSON).
 *
 * @return array
 */
function aiGenerateJson($systemPrompt, $userPrompt, array $fallback = [], array $opts = []) {
    $jsonPrompt = $userPrompt . "\n\nRespond with ONLY a single valid JSON object. No markdown, no code fences, no commentary.";
    $raw = aiGenerate($systemPrompt, $jsonPrompt, $opts);

    if ($raw === '') {
        return $fallback;
    }

    // Strip markdown code fences if the model wrapped the JSON.
    $clean = trim($raw);
    if (strncmp($clean, '```', 3) === 0) {
        $clean = preg_replace('/^```[a-zA-Z]*\s*/', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
    }

    $decoded = json_decode($clean, true);
    return is_array($decoded) ? $decoded : $fallback;
}

/**
 * Record why the last AI call failed, so the UI can explain a
 * fallback report instead of silently showing rule-based text.
 */
function aiClientNote(string $message): void {
    $GLOBALS['AI_LAST_ERROR'] = $message;
}

/** Human-readable reason for the last failed AI call ('' when none). */
function aiLastError(): string {
    return (string) ($GLOBALS['AI_LAST_ERROR'] ?? '');
}

/**
 * Turns a recognisable gateway refusal into something an operator can act on.
 *
 * THE CASE THIS EXISTS FOR.
 *
 * Every model in the default chain ends in "-free". OpenCode serves its
 * anonymous free tier ONLY to requests carrying the OpenCode client's own
 * User-Agent, so any third-party HTTP client - including this one - is
 * refused with 403 FreeTierError / 429 FreeUsageLimitError.
 *
 * Two things make the raw response actively unhelpful:
 *
 *  1. The body is JSON, so the banner showed a wall of
 *     {"type":"error",...} to a registrar who cannot read it, naming
 *     neither the cause nor the remedy.
 *  2. The obvious remedy is WRONG. The first thing almost everyone does
 *     is add an API key, because "not authenticated" is the reflex
 *     reading of a 403 - and a key does not help here. The gate is the
 *     User-Agent, not the credential: the tier is granted to the
 *     OpenCode app regardless of whether a key is presented. Hours get
 *     spent re-entering the key in the hosting panel before someone
 *     checks what the message actually says.
 *
 * The fix is to use a PAID model, which the API key does unlock. Saying
 * so, in the failure itself, is the whole point of this function.
 *
 * Returns '' for anything unrecognised, so the caller keeps its generic
 * HTTP message and no detail is lost.
 */
function aiExplainGatewayError(int $status, string $body): string
{
    $isFreeTierGate = stripos($body, 'FreeTierError') !== false
        || stripos($body, 'FreeUsageLimitError') !== false
        || stripos($body, 'free tier can only be used from within OpenCode') !== false;

    if (!$isFreeTierGate) {
        return '';
    }

    // Only the free-tier ids are affected. If the office has since moved to
    // paid models and still sees this, the real problem is different - most
    // likely no credits on the account - and claiming otherwise would send
    // them chasing the wrong fix.
    $chain = defined('AI_MODELS') ? (array) AI_MODELS : [];
    $onlyFree = true;
    foreach ($chain as $m) {
        if (stripos((string) $m, '-free') === false) {
            $onlyFree = false;
            break;
        }
    }

    if (!$onlyFree) {
        return 'The gateway refused the request as out-of-free-tier access (HTTP '
            . $status . '). Check that the OpenCode Zen account has credits and '
            . 'that OPENCODE_API_KEY is the key for that account.';
    }

    return 'Every configured model is a free-tier model (HTTP ' . $status . '). '
        . 'OpenCode serves its free tier only to its own app, so these cannot be '
        . 'called from outside OpenCode - and adding an API key does NOT change '
        . 'that, because the gate is the client, not the credential. To get an '
        . 'analysis, add credits to the OpenCode Zen account, set OPENCODE_API_KEY, '
        . 'and point AI_MODELS at paid models such as gemini-3.5-flash-lite, '
        . 'qwen3.8-flash or claude-haiku-4-5.';
}

/**
 * Low-level HTTP call to the AI provider. Returns decoded JSON array, or null
 * on failure. Logs the failure to error_log and remembers it for the UI.
 *
 * Routes to the Gemini API (generateContent) when AI_PROVIDER='gemini' or
 * the requested model name contains 'gemini'; otherwise the OpenRouter /
 * OpenAI-compatible gateway is used.
 */
function aiHttpChat(array $payload) {
    if (aiIsGeminiProvider() || aiIsGeminiModel((string) ($payload['model'] ?? ''))) {
        return aiCallGemini($payload);
    }

    $url = AI_API_URL;

    $headers = ['Content-Type: application/json'];
    if (defined('AI_API_KEY') && AI_API_KEY !== '') {
        $headers[] = 'Authorization: Bearer ' . AI_API_KEY;
    }
    // OpenRouter attribution headers (optional, but they identify the
    // app on the OpenRouter dashboard and keep free-tier routing happy).
    if (strpos((string) $url, 'openrouter.ai') !== false) {
        $headers[] = 'HTTP-Referer: ' . (function_exists('app_url') ? app_url('/') : 'http://localhost/');
        $headers[] = 'X-Title: BCP Registrar System';
    }

    $ch = curl_init($url);

    // Buffer the streamed/regular body via this callback.
    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        // MEASURED, NOT GUESSED. A full seven-section Insights report against
        // the current primary takes 40-65s wall clock, because that model
        // reasons MANDATORILY and the reasoning pass is most of the time. This
        // was 60, which is BELOW the observed worst case - so a slow report was
        // aborted by curl rather than returned, and the caller saw an error
        // that named neither the gateway nor the timeout.
        //
        // Turning the reasoning off was tried as a way to make this shorter and
        // does not work: include_reasoning=false came back in 40.0s against
        // 41.8s for the default, and reasoning_effort is advertised but
        // rejected with HTTP 400. The time is the model and a loaded free tier,
        // not a setting. So the budget has to fit the real cost.
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            return strlen($data);
        },
    ]);

    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        error_log('ai_client: curl error: ' . $err);
        aiClientNote('Could not reach the AI gateway: ' . $err);
        return null;


    }

    if ($status < 200 || $status >= 300) {
        error_log('ai_client: HTTP ' . $status . ' from gateway: ' . mb_substr($buffer, 0, 500));
        $detail = trim(mb_substr($buffer, 0, 200));
        $explain = aiExplainGatewayError($status, $buffer);
        aiClientNote($explain !== ''
            ? $explain
            : 'AI gateway returned HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : ''));
        return null;
    }

    // If the gateway streamed (SSE), extract the final content fragment.
    $decoded = aiParseStreamedBody($buffer);

    if (!is_array($decoded)) {
        error_log('ai_client: non-JSON response from gateway');
        aiClientNote('AI gateway returned a response that could not be parsed.');
        return null;
    }

    return $decoded;
}

// ============================================================
//  GEMINI SUPPORT
//  Functions to call Google Gemini API directly.
//  Used when AI_PROVIDER is 'gemini' or model contains 'gemini'.
// ============================================================

/**
 * Check if AI_PROVIDER is set to 'gemini'.
 */
function aiIsGeminiProvider(): bool {
    return defined('AI_PROVIDER') && AI_PROVIDER === 'gemini';
}

/**
 * Check if a model name indicates Gemini.
 *
 * AN EXPLICIT AI_PROVIDER ALWAYS WINS OVER THE NAME.
 *
 * This is a substring test, so `gemini-3-flash` "looks like" Gemini. That
 * was harmless when the only OpenAI-compatible gateway was Zen, which
 * hosted no Gemini models. It stops being harmless the moment the office
 * routes through an aggregator - 9Router, OpenRouter, or anything else
 * that fronts many providers behind one URL - because the model name
 * reaches us namespaced by whatever the aggregator calls it, and a
 * perfectly good `gemini-3-flash` served by that aggregator would be
 * torn out of the chain and sent to Google's API instead, with no key,
 * for no stated reason.
 *
 * So the heuristic only runs when nobody has said which provider they
 * meant. Setting AI_PROVIDER=openai (or '9router', 'aggregator', or
 * anything that is not 'gemini') pins every request to the
 * OpenAI-compatible path, which is what an aggregator expects.
 */
function aiIsGeminiModel(string $model): bool {
    if ($model === '' || stripos($model, 'gemini') === false) {
        return false;
    }

    // Asked for Gemini directly: honour it.
    if (aiIsGeminiProvider()) {
        return true;
    }

    // An explicit non-Gemini provider settles it. This matters once the
    // office routes through an aggregator - 9Router, OpenRouter, anything
    // that fronts many providers behind one URL - because model ids there
    // arrive namespaced by whoever serves them, and a perfectly good
    // `gemini/gemini-3-flash` served by that aggregator would otherwise be
    // torn out of the chain and sent to Google's API with no key, for no
    // stated reason.
    $provider = strtolower(trim((string) (defined('AI_PROVIDER') ? AI_PROVIDER : '')));
    if ($provider !== '' && $provider !== 'zen') {
        return false;
    }

    // Still undecided, so fall back to the name. But only when the
    // configured endpoint can actually serve Gemini: if AI_API_URL points
    // anywhere else, that URL is the single source of truth about who we
    // are talking to, and it outranks a substring in the model name.
    $host = strtolower((string) (parse_url((string) AI_API_URL, PHP_URL_HOST) ?: ''));
    if ($host !== '') {
        $geminiHosts = ['opencode.ai', 'generativelanguage.googleapis.com'];
        if (!in_array($host, $geminiHosts, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Call Gemini API directly.
 * Converts OpenAI-format payload to Gemini format and calls the Gemini API.
 *
 * @param array $payload  OpenAI-format payload
 * @return array|null     OpenAI-compatible response or null
 */
function aiCallGemini(array $payload): ?array {
    $apiKey = '';
    if (defined('AI_GEMINI_API_KEY') && AI_GEMINI_API_KEY !== '') {
        $apiKey = AI_GEMINI_API_KEY;
    } elseif (defined('AI_API_KEY') && AI_API_KEY !== '') {
        $apiKey = AI_API_KEY;
    }

    if ($apiKey === '') {
        error_log('ai_client: Gemini API key not configured');
        aiClientNote('Gemini API key not configured (set GEMINI_API_KEY / AI_GEMINI_API_KEY).');
        return null;
    }

    $model = str_replace('google/', '', (string) ($payload['model'] ?? 'gemini-2.0-flash'));
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
        . ':generateContent';

    $geminiPayload = aiConvertToGeminiPayload($payload);

    $ch = curl_init($url);

    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($geminiPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            return strlen($data);
        },
    ]);

    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        error_log('ai_client: Gemini curl error: ' . $err);
        aiClientNote('Could not reach Gemini API: ' . $err);
        return null;
    }

    if ($status < 200 || $status >= 300) {
        error_log('ai_client: Gemini HTTP ' . $status . ': ' . mb_substr($buffer, 0, 500));
        $detail = trim(mb_substr($buffer, 0, 200));
        aiClientNote('Gemini API returned HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : ''));
        return null;
    }

    $decoded = json_decode($buffer, true);
    if (!is_array($decoded)) {
        error_log('ai_client: Gemini returned non-JSON response');
        aiClientNote('Gemini API returned a response that could not be parsed');
        return null;
    }

    return aiParseGeminiResponse($decoded);
}

/**
 * Convert OpenAI-format chat payload to Gemini generateContent format.
 * Handles plain-string content and OpenAI vision content arrays.
 *
 * @param array $payload  OpenAI-format payload with 'messages' key
 * @return array          Gemini generateContent request body
 */
function aiConvertToGeminiPayload(array $payload): array {
    $messages = $payload['messages'] ?? [];

    $contents = [];
    $systemParts = [];

    foreach ($messages as $msg) {
        $role = $msg['role'] ?? 'user';
        $content = $msg['content'] ?? '';

        // System messages go to systemInstruction, everything else to contents.
        if ($role === 'system') {
            $systemParts[] = ['text' => is_string($content) ? $content : json_encode($content)];
            continue;
        }

        $geminiRole = $role === 'assistant' ? 'model' : 'user';

        $parts = [];
        if (is_string($content)) {
            $parts[] = ['text' => $content];
        } elseif (is_array($content)) {
            // OpenAI vision format:
            // [{type:'text',text:...},{type:'image_url',image_url:{url:'data:...'}}]
            foreach ($content as $part) {
                if (!is_array($part)) {
                    $parts[] = ['text' => (string) $part];
                    continue;
                }
                if (($part['type'] ?? '') === 'image_url') {
                    $url = $part['image_url']['url'] ?? '';
                    if (is_string($url) && strpos($url, 'data:') === 0) {
                        $mime = 'image/png';
                        if (preg_match('#^data:([^;,]+);#', $url, $m)) $mime = $m[1];
                        $b64 = (string) substr($url, strpos($url, ';base64,') + 8);
                        $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $b64]];
                    } elseif (is_string($url) && $url !== '') {
                        $parts[] = ['fileData' => ['fileUri' => $url, 'mimeType' => 'image/png']];
                    }
                } else {
                    $parts[] = ['text' => (string) ($part['text'] ?? '')];
                }
            }
        }

        if (!empty($parts)) {
            $contents[] = ['role' => $geminiRole, 'parts' => $parts];
        }
    }

    $body = [
        'generationConfig' => [
            'maxOutputTokens' => $payload['max_tokens'] ?? 1024,
            'temperature'     => $payload['temperature'] ?? 0.2,
        ],
    ];

    if (!empty($systemParts)) {
        $body['systemInstruction'] = ['parts' => $systemParts];
    }

    if (!empty($contents)) {
        $body['contents'] = $contents;
    }

    return $body;
}

/**
 * Parse Gemini API response into OpenAI-compatible structure.
 *
 * @param array|string $geminiResponse  Gemini API response
 * @return array|null                    OpenAI-compatible response or null
 */
function aiParseGeminiResponse($geminiResponse): ?array {
    if (is_string($geminiResponse)) {
        $geminiResponse = json_decode($geminiResponse, true);
        if (!is_array($geminiResponse)) {
            return null;
        }
    }

    // Check for Gemini error
    if (isset($geminiResponse['error'])) {
        $error = $geminiResponse['error'];
        $msg = is_array($error) && isset($error['message']) ? $error['message'] : json_encode($error);
        error_log('ai_client: Gemini error: ' . $msg);
        aiClientNote('Gemini API error: ' . $msg);
        return null;
    }

    // Extract text: candidates[0].content.parts[0].text
    $candidates = $geminiResponse['candidates'] ?? [];
    if (empty($candidates)) {
        return null;
    }

    $first = $candidates[0] ?? null;
    $content = is_array($first) ? ($first['content'] ?? null) : null;
    $parts = is_array($content) ? ($content['parts'] ?? []) : [];
    if (empty($parts)) {
        return null;
    }

    // Combine all text parts.
    $text = '';
    foreach ($parts as $part) {
        if (is_array($part)) {
            $text .= (string) ($part['text'] ?? '');
        }
    }

    $text = trim($text);
    if ($text === '') {
        return null;
    }

    // Return OpenAI-compatible structure
    return [
        'choices' => [
            [
                'message' => [
                    'content' => $text,
                ],
            ],
        ],
    ];
}

/**
 * Parse a gateway response body that may be a JSON object, a JSON array,
 * or OpenAI-SSE streaming lines ("data: {...}" ... "data: [DONE]").
 * Returns a normalized choices/message/content structure, or null.
 */
function aiParseStreamedBody($buffer) {
    $buffer = trim((string) $buffer);

    // The gateway appends a literal "data: [DONE]" sentinel after the JSON.
    // Trim it (and anything trailing) before decoding.
    $buffer = preg_replace('/data:\s*\[DONE\]\s*$/i', '', trim($buffer));
    $buffer = trim($buffer);

    // Non-streaming JSON object/array.
    $decoded = json_decode($buffer, true);
    if (is_array($decoded)) return $decoded;

    // OpenAI SSE streaming: lines of "data: {...}" then "data: [DONE]".
    $contentParts = [];
    foreach (preg_split('/\r?\n/', $buffer) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'data:') !== 0) continue;
        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') continue;
        $chunk = json_decode($data, true);
        if (is_array($chunk) && isset($chunk['choices'][0]['delta']['content'])) {
            $contentParts[] = $chunk['choices'][0]['delta']['content'];
        }
    }

    if (!empty($contentParts)) {
        return [
            'choices' => [
                ['message' => ['content' => implode('', $contentParts)]],
            ],
        ];
    }

    return null;
}
