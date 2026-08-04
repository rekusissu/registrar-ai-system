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
    $model    = $opts['model']    ?? AI_MODEL;
    $ttl      = $opts['ttl']      ?? AI_CACHE_TTL;
    $maxTok   = $opts['max_tokens'] ?? 1024;
    $temp     = $opts['temperature'] ?? 0.2;
    $force    = !empty($opts['forceRefresh']);

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
        return '';
    }

    // Streaming returns a JSON line ending with "data: [DONE]". The parsed
    // result may already contain the full content (non-streaming fallback).
    $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));

    if ($text !== '') {
        try {
            $db->insert('ai_cache', [
                'prompt_hash' => $cacheKey,
                'prompt'      => mb_substr($userPrompt, 0, 4000),
                'response'    => $text,
                'model'       => $model,
                'created_at'  => date('Y-m-d H:i:s'),
                'expires_at'  => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
            ]);
        } catch (Exception $e) {
            // Cache write failure should not break the caller.
        }
    }

    return $text;
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
