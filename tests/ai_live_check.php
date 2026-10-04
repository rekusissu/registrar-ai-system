<?php
// End-to-end through the app's own client, with no key - the exact state a
// fresh deploy with no OPENCODE_API_KEY set would be in.
//
// This is the call api/ai-insights-report.php makes. If it returns text, the
// gateway URL, the model id, the request shape and the bearer header are all
// correct together, which reading the config cannot prove.
//
//   php tests/ai_live_check.php
//
// Needs no credential for the primary model, so it runs anywhere. If it prints
// "no answer", the fallback chain is the thing to check - every entry other
// than the primary returns 403 without a key.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/ai_client.php';

printf("gateway : %s\n", AI_API_URL);
printf("model   : %s\n", AI_MODEL);
printf("key     : %s\n\n", AI_API_KEY === '' ? 'none set (testing anonymous)' : 'set');

$start  = microtime(true);
$result = aiGenerate(
    'You are a test harness. Reply with the single word: ONLINE',
    'Are you there?',
    ['max_tokens' => 300, 'forceRefresh' => true]
);
$took = round((microtime(true) - $start) * 1000);

printf("reply   : %s\n", $result === '' ? '(empty)' : "'" . $result . "'");
printf("elapsed : %d ms\n", $took);

if ($result === '') {
    echo 'aiLastError: ' . aiLastError() . "\n";
    echo "RESULT: no answer\n";
    exit(1);
}

// forceRefresh was set, so this cannot be a cached reply from an earlier run.
echo "RESULT : ok - a live completion, not a cache hit\n";