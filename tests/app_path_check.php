<?php
// ============================================================
//  APP PATH RESOLUTION CHECK
//
//    php tests/app_path_check.php
//
//  app_url() produces every asset and API URL in the app, so when it
//  resolves wrong the pages look fine and nothing loads: no QR image, and
//  buttons that do nothing because the fetch 404s.
//
//  Each case runs in its OWN PROCESS because app_base_path() caches its
//  answer in a static - calling it twice in one process returns the first
//  answer for both, which is exactly the kind of false pass this file
//  exists to prevent.
// ============================================================
$root = dirname(__DIR__);

// [label, SCRIPT_NAME, SCRIPT_FILENAME (null = unset), DOCUMENT_ROOT (null = unset), expected base]
$cases = [
    // ── XAMPP / Apache, app in a subdirectory. The good case. ──────────
    ['subdir, DOCUMENT_ROOT set',
        '/registrar-ai-system/student/documents.php',
        'C:/xampp/htdocs/registrar-ai-system/student/documents.php',
        'C:/xampp/htdocs',
        '/registrar-ai-system'],
    ['subdir, queue page',
        '/registrar-ai-system/queue/monitor.php',
        'C:/xampp/htdocs/registrar-ai-system/queue/monitor.php',
        'C:/xampp/htdocs',
        '/registrar-ai-system'],

    // ── Hosting: app at the document root ───────────────────────────────
    ['app AT docroot, DOCUMENT_ROOT set',
        '/student/documents.php',
        '/var/www/html/student/documents.php',
        '/var/www/html',
        ''],
    ['app AT docroot, registrar page',
        '/registrar/documents.php',
        '/var/www/html/registrar/documents.php',
        '/var/www/html',
        ''],

    // ── The failures. DOCUMENT_ROOT absent is normal on nginx/php-FPM and
    //    on some LiteSpeed hosts, and the depth used to come only from
    //    SCRIPT_FILENAME, which those setups do not always provide. ─────
    ['no DOCUMENT_ROOT, SCRIPT_FILENAME present',
        '/student/documents.php',
        '/var/www/html/student/documents.php',
        null,
        ''],
    ['no DOCUMENT_ROOT, no SCRIPT_FILENAME',
        '/student/documents.php',
        null,
        null,
        ''],
    ['no DOCUMENT_ROOT, no SCRIPT_FILENAME, subdir mount',
        '/registrar-ai-system/student/documents.php',
        null,
        null,
        '/registrar-ai-system'],
    ['no DOCUMENT_ROOT, no SCRIPT_FILENAME, registrar',
        '/registrar/documents.php',
        null,
        null,
        ''],
    ['no DOCUMENT_ROOT, no SCRIPT_FILENAME, queue',
        '/queue/monitor.php',
        null,
        null,
        ''],

    // ── Root pages must keep working on every shape ────────────────────
    ['root page, subdir mount',
        '/registrar-ai-system/login.php',
        'C:/xampp/htdocs/registrar-ai-system/login.php',
        'C:/xampp/htdocs',
        '/registrar-ai-system'],
    ['root page, app AT docroot',
        '/login.php',
        '/var/www/html/login.php',
        '/var/www/html',
        ''],
    ['root page, no DOCUMENT_ROOT',
        '/login.php',
        null,
        null,
        ''],
];

// PLACEHOLDER_RUNNER
$pass = 0; $fail = 0;

/**
 * Runs one deployment shape in a fresh process.
 *
 * A shim rather than a require of app_path.php directly: each case needs a
 * clean static, and APP_ROOT has to be defined before that file is read.
 */
function run_case(string $root, string $sname, ?string $sfile, ?string $docroot): ?array {
    $shim = sys_get_temp_dir() . '/apppath_' . md5($sname . '|' . $sfile . '|' . $docroot) . '.php';
    $php  = "<?php\ndefine('APP_ROOT', " . var_export($root . '/', true) . ");\n"
          . "\$_SERVER['SCRIPT_NAME'] = " . var_export($sname, true) . ";\n";
    if ($sfile !== null)   $php .= "\$_SERVER['SCRIPT_FILENAME'] = " . var_export($sfile, true) . ";\n";
    if ($docroot !== null) $php .= "\$_SERVER['DOCUMENT_ROOT'] = " . var_export($docroot, true) . ";\n";
    $php .= "require " . var_export($root . '/shared/app_path.php', true) . ";\n"
          . "echo json_encode(['base' => app_base_path(), 'api' => app_url('/api'),"
          . " 'qr' => app_url('/assets/images/gcash-qr.png')]);\n";
    file_put_contents($shim, $php);

    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($shim) . ' 2>&1');
    @unlink($shim);

    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
}

foreach ($cases as $case) {
    [$label, $sname, $sfile, $docroot, $want] = $case;
    $got = run_case($root, $sname, $sfile, $docroot);

    if ($got === null) {
        $fail++;
        printf("  FAIL %-46s (no output)\n", $label);
        continue;
    }

    $prefix = $want === '' ? '' : $want;
    $baseOk = $got['base'] === $want;
    $apiOk  = $got['api'] === $prefix . '/api';
    $qrOk   = $got['qr']  === $prefix . '/assets/images/gcash-qr.png';

    if ($baseOk && $apiOk && $qrOk) {
        $pass++;
        printf("  OK   %-46s base=%s\n", $label, var_export($got['base'], true));
    } else {
        $fail++;
        printf("  FAIL %-46s\n", $label);
        printf("         base got %s, want %s\n", var_export($got['base'], true), var_export($want, true));
        if (!$apiOk) printf("         api  got %s\n", var_export($got['api'], true));
        if (!$qrOk)  printf("         qr   got %s\n", var_export($got['qr'], true));
    }
}

// A base of "/student" or "/registrar" is the specific regression: the page's
// own directory mistaken for the app root, which made every asset and API URL
// on that page one level too deep.
foreach (['student', 'registrar', 'queue', 'api', 'ai'] as $dir) {
    $got = run_case($root, "/$dir/page.php", null, null);
    $base = $got['base'] ?? '?';
    if ($base === "/$dir") {
        $fail++;
        printf("  FAIL no server variables: /%s/page.php -> %s\n", $dir, var_export($base, true));
    } else {
        $pass++;
        printf("  OK   no server variables: /%s/page.php -> %s\n", $dir, var_export($base, true));
    }
}

echo "\n" . ($fail === 0 ? "OK - $pass check(s) passed.\n"
                        : "FAILED - $fail of " . ($pass + $fail) . " check(s).\n");
exit($fail === 0 ? 0 : 1);