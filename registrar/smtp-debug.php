<?php
// smtp-debug.php — upload to registrar/ on your server and visit in browser.
// Dumps every SMTP-related value PHP can see. Delete after debugging.
header('Content-Type: text/plain; charset=utf-8');

echo "=== SMTP Environment Diagnostic ===\n\n";

// 1. PHP runtime info
echo "--- PHP Runtime ---\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "variables_order: '" . ini_get('variables_order') . "'\n";
echo "getenv() available: " . (function_exists('getenv') ? 'yes' : 'NO — disabled in php.ini') . "\n";
echo "apache_getenv() available: " . (function_exists('apache_getenv') ? 'yes' : 'no (not Apache/mod_php)') . "\n";
echo "\n";

// 2. Every SMTP key in every possible PHP superglobal / function
$keys = ['SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS','MAIL_FROM','MAIL_FROM_NAME','APP_ENV','DB_HOST','DB_USER'];

echo "--- SMTP / relevant vars by source ---\n";
foreach ($keys as $k) {
    echo "[$k]\n";
    $ge = getenv($k);
    echo "  getenv():      " . var_export($ge, true) . "  (type: " . gettype($ge) . ")\n";
    echo "  \$_ENV:        " . var_export(isset($_ENV[$k]) ? $_ENV[$k] : null, true) . "\n";
    echo "  \$_SERVER:     " . var_export(isset($_SERVER[$k]) ? $_SERVER[$k] : null, true) . "\n";
    if (function_exists('apache_getenv')) {
        $ag = @apache_getenv($k, true);
        echo "  apache_getenv: " . var_export($ag, true) . "\n";
    } else {
        echo "  apache_getenv: (n/a)\n";
    }
    echo "\n";
}

// 3. email_secret.local on the server
echo "--- email_secret.local on server ---\n";
$esPath = __DIR__ . '/../shared/email_secret.local';
echo "Path: $esPath\n";
echo "Exists: " . (is_file($esPath) ? 'YES' : 'NO — file not present on server') . "\n";
if (is_file($esPath)) {
    $lines = file($esPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "Lines (" . count($lines) . "):\n";
    foreach ($lines as $ln) echo "  | $ln\n";
    $parsed = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        $p = array_pad(explode('=', $ln, 2), 2, '');
        $parsed[trim($p[0])] = trim($p[1]);
    }
    echo "\nParsed:\n";
    foreach ($keys as $k) echo "  $k = " . var_export($parsed[$k] ?? '(missing)', true) . "\n";
} else {
    echo "\nFILE MISSING — emailSecretFromLocal() returns '' for every key.\n\n";
}

// 4. Load config and report final state
echo "--- After loading shared/config.php ---\n";
require_once __DIR__ . '/../shared/config.php';

$defHost  = defined('SMTP_HOST')  ? SMTP_HOST  : null;
$defUser  = defined('SMTP_USER')  ? SMTP_USER  : null;
$defPass  = defined('SMTP_PASS')  ? SMTP_PASS  : null;
$defFrom  = defined('MAIL_FROM')  ? MAIL_FROM  : null;
$defName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : null;
$defConf  = defined('EMAIL_CONFIGURED') ? EMAIL_CONFIGURED : null;

echo "SMTP_HOST:      " . var_export($defHost, true) . "\n";
echo "SMTP_PORT:      " . var_export(defined('SMTP_PORT') ? SMTP_PORT : null, true) . " (int)\n";
echo "SMTP_USER:      " . var_export($defUser, true) . "\n";
echo "SMTP_PASS:      " . (($defPass !== null && $defPass !== '') ? str_repeat('*', min(20, strlen($defPass))) . " (len=" . strlen($defPass) . ")" : var_export($defPass, true)) . "\n";
echo "MAIL_FROM:      " . var_export($defFrom, true) . "\n";
echo "MAIL_FROM_NAME: " . var_export($defName, true) . "\n";
echo "EMAIL_CONFIGURED: " . var_export($defConf, true) . "\n";
echo "\n";

// 5. Dependencies
echo "--- Dependencies ---\n";
echo "openssl:     " . (extension_loaded('openssl') ? 'loaded' : 'MISSING') . "\n";
$hasV = is_file(__DIR__ . '/../vendor/autoload.php');
echo "vendor/autoload.php: " . ($hasV ? 'present' : 'MISSING') . "\n";
if ($hasV) {
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "PHPMailer:   " . (class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'available' : 'NOT FOUND') . "\n";
}

// 6. Summary
echo "\n=== RESULT ===\n";
if ($defConf) {
    echo "PASS: SMTP is configured.\n";
    echo "  Host: $defHost\n";
    echo "  Port: " . (defined('SMTP_PORT') ? SMTP_PORT : '?') . "\n";
    echo "  User: $defUser\n";
    echo "  Pass: " . str_repeat('*', min(20, strlen($defPass))) . " (len: " . strlen($defPass) . ")\n";
    echo "  From: $defFrom\n";
} else {
    echo "FAIL: EMAIL_CONFIGURED = false\n";
    $missing = [];
    if (!$defHost)  $missing[] = "SMTP_HOST";
    if (!$defUser)  $missing[] = "SMTP_USER";
    if (!$defPass)  $missing[] = "SMTP_PASS";
    echo "  Missing: " . implode(', ', $missing) . "\n";
    echo "\n  WHY IT FAILS ON SHARED HOSTING:\n";
    echo "  - cPanel 'Environment Variables' often don't reach PHP-FPM's getenv().\n";
    echo "  - The env() helper in shared/config.php checks getenv(), \$_ENV, \$_SERVER, apache_getenv().\n";
    echo "  - Check the sections above to see which source (if any) has your values.\n";
    echo "\n  BULLETPROOF FIX — create this file ON THE SERVER:\n";
    echo "    path: registrar-ai-system/shared/email_secret.local\n";
    echo "    content:\n";
    echo "      SMTP_HOST=smtp.gmail.com\n";
    echo "      SMTP_PORT=587\n";
    echo "      SMTP_USER=roldantiu89@gmail.com\n";
    echo "      SMTP_PASS=<your 16-char Gmail App Password>\n";
    echo "      MAIL_FROM=roldantiu89@gmail.com\n";
    echo "      MAIL_FROM_NAME=BCP Registrar System\n";
    echo "\n  Gmail App Password: https://myaccount.google.com/apppasswords\n";
    echo "  (requires 2-Factor Auth enabled on the Google account first)\n";
}

echo "\n=== END ===\n";
