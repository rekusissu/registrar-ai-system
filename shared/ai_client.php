<?php
// ============================================================
//  SHARED/AI_CLIENT.PHP
//  OpenAI-compatible client for the local 9Router gateway.
//  Fronts several free models via one Bearer key. All responses
//  are cached in the `ai_cache` table (prompt_hash, TTL).
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
 * Send a prompt to the 9Router gateway (OpenAI chat/completions format),
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
 * Send a vision-capable request with an image to the gateway.
 * Uses a model from AI_MODELS that supports vision (minimax-m3 does).
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

    // Prefer a vision-capable model first (minimax-m3 has vision).
    $preferred = array_filter($models, fn($m) => strpos($m, 'minimax') !== false || strpos($m, 'kimi') !== false);
    $models = array_merge(array_values($preferred), array_values(array_diff($models, $preferred)));

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
 * Low-level HTTP call to the gateway. Returns decoded JSON array, or null
 * on failure. Logs the failure to error_log.
 */
function aiHttpChat(array $payload) {
    $url = AI_API_URL;

    $headers = ['Content-Type: application/json'];
    if (defined('AI_API_KEY') && AI_API_KEY !== '') {
        $headers[] = 'Authorization: Bearer ' . AI_API_KEY;
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
        return null;
    }

    if ($status < 200 || $status >= 300) {
        error_log('ai_client: HTTP ' . $status . ' from gateway: ' . mb_substr($buffer, 0, 500));
        return null;
    }

    // If the gateway streamed (SSE), extract the final content fragment.
    $decoded = aiParseStreamedBody($buffer);

    if (!is_array($decoded)) {
        error_log('ai_client: non-JSON response from gateway');
        return null;
    }

    return $decoded;
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
