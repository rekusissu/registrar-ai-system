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
 * OpenRouter model IDs never carry an "openrouter/" prefix. A stale or
 * hand-typed AI_MODEL like "openrouter/inclusionai/ling-3.0-flash-vl:free"
 * is rejected by the gateway with HTTP 400 ("is not a valid model ID"),
 * which makes every AI call fail. Strip it defensively whenever the API
 * URL points at OpenRouter.
 */
function aiNormalizeModel(string $model): string {
    $model = trim($model);
    $host  = strtolower((string) parse_url(AI_API_URL, PHP_URL_HOST) ?: '');
    if ($host === 'openrouter.ai' || strpos($host, '.openrouter.ai') !== false) {
        $model = preg_replace('#^openrouter/+#i', '', $model);
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
        error_log('ai_client: curl error: ' . $err);
        aiClientNote('Could not reach the AI gateway: ' . $err);
        return null;
    }

    if ($status < 200 || $status >= 300) {
        error_log('ai_client: HTTP ' . $status . ' from gateway: ' . mb_substr($buffer, 0, 500));
        $detail = trim(mb_substr($buffer, 0, 200));
        aiClientNote('AI gateway returned HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : ''));
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
 */
function aiIsGeminiModel(string $model): bool {
    return $model !== '' && stripos($model, 'gemini') !== false;
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