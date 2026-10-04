<?php
// Confirm every id in the configured chain actually exists on the gateway the
// config points at. A wrong id in a failover chain is invisible until the
// primary is down, at which point the report silently degrades.
//
// Auth is not needed: https://opencode.ai/zen/v1/models is public.
require_once __DIR__ . '/../shared/config.php';

$ch = curl_init('https://opencode.ai/zen/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$body  = curl_exec($ch);
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err   = curl_error($ch);
curl_close($ch);

echo 'gateway : ' . AI_API_URL . PHP_EOL;
echo 'models  : HTTP ' . $code . ($err ? " ($err)" : '') . PHP_EOL;

$json = json_decode((string) $body, true);
$live = array_column($json['data'] ?? [], 'id');

if (!$live) {
    echo "could not read the model list\n";
    exit(1);
}

printf("live models on this gateway: %d\n\n", count($live));

$missing = 0;
foreach (AI_MODELS as $i => $m) {
    $ok  = in_array($m, $live, true);
    $fre = substr($m, -5) === '-free';
    if (!$ok) {
        $missing++;
    }
    printf(
        "  %d. %-28s %s%s\n",
        $i + 1, $m,
        $ok ? 'resolves' : 'NOT ON THIS GATEWAY',
        $fre ? '  (free)' : '  *** NOT FREE - COSTS MONEY ***'
    );
}

printf("\n%d of %d chain entries resolve\n", count(AI_MODELS) - $missing, count(AI_MODELS));