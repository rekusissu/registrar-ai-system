<?php
// ============================================================
//  TESTS/AI_PREFLIGHT.PHP
//  Run this ON THE HOST, from the app root, before trusting the
//  AI Insight page. It answers three questions that the page
//  itself cannot answer without costing a report:
//
//    1. Did the environment variable I set actually reach PHP?
//    2. Did the config resolve it, and to what?
//    3. Can PHP reach the gateway from THIS machine - which is the
//       whole question when the gateway is a tunnel from someone
//       else's laptop.
//
//   php tests/ai_preflight.php
//
//  Never prints the key itself - only whether one was found, and its
//  last four characters so a wrong key can be told from a stale one.
// ============================================================

require_once __DIR__ . '/../shared/config.php';

function line(string $k, string $v): void {
    printf("  %-16s %s\n", $k . ':', $v);
}

// ── 1. What did PHP actually receive? ───────────────────────────────────────
//
// Checked separately from the resolved value on purpose. "The key is
// empty" and "the key variable is not set" are different faults with
// different fixes: the first is a typo in the value, the second is the
// wrong setting in the hosting panel, or the panel not exporting it.
echo "\n[1] Environment variables seen by PHP\n";

$names = ['NINEROUTER_KEY', 'OPENCODE_API_KEY', 'OPENROUTER_API_KEY', 'AI_API_KEY',
          'AI_API_URL', 'AI_MODEL', 'AI_MODELS', 'AI_PROVIDER'];
$anyEnv = false;
foreach ($names as $n) {
    $viaGetenv = function_exists('getenv') ? getenv($n) : false;
    $hasGetenv = ($viaGetenv !== false && $viaGetenv !== '');
    $viaServer = $_SERVER[$n] ?? ($_ENV[$n] ?? null);
    $hasServer = ($viaServer !== null && $viaServer !== '');
    $seen = $hasGetenv || $hasServer;

    if ($seen) {
        $anyEnv = true;
    }

    if (str_ends_with($n, '_KEY')) {
        line($n, $seen ? 'set' : 'NOT SET');
    } else {
        line($n, $seen ? ($hasGetenv ? (string) $viaGetenv : (string) $viaServer) : '(not set)');
    }
}

if (!$anyEnv) {
    echo "\n  !! None of the AI variables reached PHP at all.\n";
    echo "     On cPanel these are set under 'Environment Variables', which lands in\n";
    echo "     \$_SERVER. If they are there but invisible here, the panel has not\n";
    echo "     exported them to PHP - put them in .htaccess with SetEnv instead:\n";
    echo "       SetEnv NINEROUTER_KEY \"sk-...\"\n";
}

// ── 2. What did the config make of it? ──────────────────────────────────────
echo "\n[2] Resolved by shared/config.php\n";
line('gateway', AI_API_URL);
line('provider', (string) AI_PROVIDER);
line('model', (string) AI_MODEL);
line('chain', implode(', ', array_map('strval', (array) AI_MODELS)));
line('key', AI_API_KEY === ''
    ? 'EMPTY - no request will be authenticated'
    : 'loaded (' . strlen(AI_API_KEY) . ' chars, ends ...' . substr(AI_API_KEY, -4) . ')');
// ── 3. Can this machine reach the gateway? ──────────────────────────────────
//
// The decisive test when the gateway is a Cloudflare tunnel from a laptop
// somewhere else. It proves the URL resolves, the certificate is valid, the
// key is accepted, and the configured model ids are ones the router actually
// serves - four failures that look identical on the Insights page and are
// fixed in completely different places.
echo "\n[3] Live reachability\n";

$modelsUrl = preg_replace('#/chat/completions/?$#', '/models', AI_API_URL);
if ($modelsUrl === AI_API_URL) {
    echo "  AI_API_URL does not end in /chat/completions; skipping model listing.\n";
} else {
    $ch = curl_init($modelsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        // A tunnel with a bad certificate must FAIL here, not warn and
        // continue: sending the key over an unverified TLS connection is
        // the one outcome worse than no report at all.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => AI_API_KEY !== ''
            ? ['Authorization: Bearer ' . AI_API_KEY]
            : [],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    line('GET /models', (string) $code);

    if ($body === false) {
        line('error', $err);
        echo "  !! This server cannot reach the gateway.\n";
        echo "     If the gateway is a tunnel from another machine, that machine must\n";
        echo "     be switched on, running 9Router, with the tunnel up. Until it is,\n";
        echo "     every report will fail (the measured figures still show).\n";
    } elseif ($code === 401 || $code === 403) {
        echo "  !! The gateway rejected the key.\n";
        echo "     Check NINEROUTER_KEY is the ROUTER's key (Dashboard -> Keys), not a\n";
        echo "     provider key - the router validates it before it forwards anywhere.\n";
    } elseif ($code >= 200 && $code < 300) {
        $ids = [];
        $json = json_decode((string) $body, true);
        if (isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $m) {
                if (isset($m['id'])) {
                    $ids[] = (string) $m['id'];
                }
            }
        }
        line('models', count($ids) . ' available');

        $missing = array_values(array_diff(array_map('strval', (array) AI_MODELS), $ids));
        if ($missing) {
            echo "  !! Configured but NOT served by this gateway:\n";
            foreach ($missing as $m) {
                echo "       - {$m}\n";
            }
            echo "     Each one costs a wasted round trip before the chain moves on.\n";
        } else {
            echo "  OK  every configured model is served by this gateway.\n";
        }

        if ($ids && count($ids) <= 45) {
            echo "\n  Full list, for choosing a chain:\n";
            foreach (array_slice($ids, 0, 45) as $id) {
                echo "     {$id}\n";
            }
        }
    } else {
        line('body', substr((string) $body, 0, 200));
    }
}

echo "\n";