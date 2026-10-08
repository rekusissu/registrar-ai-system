<?php
// tests/otp_login_check.php - prove a real login over HTTP is signed in
// without a code, from loopback, and that the same login from a
// non-loopback address still demands one.
//
// A unit test can prove localOtpBypass() returns the right thing; only a
// real request proves the login page honours it end to end.
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    $h = ob_get_level() > 0 ? ob_get_clean() : '';
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE], true)) {
        $h .= "\nFATAL: " . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n";
    }
    if ($h !== '') echo $h;
});

require __DIR__ . '/../shared/database.php';

// Guard: this file changes a real account's password, briefly, in the
// middle of the run. That is acceptable against the developer's own
// throwaway database and unacceptable against anything else, and the test
// did not previously distinguish the two.
//
// The check is on DB_HOST rather than on an opt-in environment variable,
// because a variable has to be remembered and a host cannot be forgotten:
// if the database is not on this machine, refuse. The web target below is
// already hardcoded to localhost, so the host was the only remaining way to
// point this at somebody else's data.
//
// If this ever needs to run against a remote throwaway database, the fix is
// to add an explicit opt-in flag -- not to delete the check.
if (!in_array(strtolower((string) DB_HOST), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "refusing to run: DB_HOST is '" . DB_HOST
        . "', and this test resets a real account password while it runs.\n"
        . "It is only safe against a local throwaway database.\n");
    exit(1);
}

$db = Database::getInstance();

$user = $db->fetchOne(
    "SELECT id, email, role FROM users WHERE role = 'registrar' AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$user) { fwrite(STDERR, "no active registrar account to log in as\n"); exit(1); }

// The password is not readable from here, so this resets a throwaway
// password, logs in with it, then puts the old hash back. The account
// chosen is the registrar one because it is the least valuable on the
// box; the hash is restored exactly as it was.
$userId = (int) $user['id'];
$row    = $db->fetchOne('SELECT password_hash, login_attempts, locked_until FROM users WHERE id = ?', [$userId]);
$saved  = $row;

$password = 'LocalOnly!Test' . random_int(1000, 9999);
$db->update('users', [
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'login_attempts' => 0,
    'locked_until'  => null,
], 'id = ?', [$userId]);

// Always put the real hash back, whatever happens below.
register_shutdown_function(function () use ($db, $userId, $saved) {
    $db->update('users', [
        'password_hash'  => $saved['password_hash'],
        'login_attempts' => $saved['login_attempts'],
        'locked_until'   => $saved['locked_until'],
    ], 'id = ?', [$userId]);
});

$BASE = 'http://localhost/registrar-ai-system';
$jar  = sys_get_temp_dir() . '/otplogin-' . getmypid() . '.txt';
@unlink($jar);

/** GET, for the pages that only render. */
function get(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = (string) curl_exec($ch);
    $hs  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = substr($raw, $hs);
    curl_close($ch);
    return ['status' => $st, 'body' => $body, 'json' => json_decode($body, true)];
}

function post(string $url, array $fields, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = (string) curl_exec($ch);
    $hs  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = substr($raw, $hs);
    curl_close($ch);
    return ['status' => $st, 'body' => $body, 'json' => json_decode($body, true)];
}

/** Fetch a page and read the CSRF token out of its meta tag. */
function fetchToken(string $url, string $jar): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    return preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        ? $m[1] : '';
}

// The login POST is CSRF-protected like every other write, so the page
// is fetched first to obtain a token bound to this session. Without it
// the endpoint answers "Invalid or missing CSRF token" and the login
// never runs - which would look exactly like the bypass failing.
$csrf = fetchToken("$BASE/login.php", $jar);
echo 'csrf token: ' . ($csrf !== '' ? substr($csrf, 0, 10) . '... (' . strlen($csrf) . ' chars)' : 'NOT FOUND') . "\n";
if ($csrf === '') {
    fwrite(STDERR, "could not read a CSRF token from login.php\n");
    exit(1);
}
echo "\n";

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($d !== '' ? "  <- $d" : '') . "\n"; }
}

echo "logging in as {$user['email']} (role {$user['role']}) over HTTP from loopback\n\n";

// ── 1. The form endpoint ─────────────────────────────────────
echo "== login.php (form) ==\n";
// login.php only RENDERS the page; its JS posts to shared/auth_actions.php.
// Posting to the page itself returns the HTML, which is not the answer
// and looks exactly like the bypass not working.
$r = post("$BASE/shared/auth_actions.php", [
    'action'     => 'login',
    'username'   => (string) $user['email'],
    'password'   => $password,
    'csrf_token' => $csrf,
], $jar);

check('the login endpoint answers', $r['status'] === 200, "status {$r['status']} " . substr($r['body'], 0, 200));
if ($r['json'] === null) {
    echo "  ---- raw body ----\n";
    echo '  ' . substr($r['body'], 0, 600) . "\n";
    echo "  ---- end ----\n";
}
check('it did not report a bad credential',
    stripos((string) ($r['json']['message'] ?? ''), 'invalid') === false,
    (string) ($r['json']['message'] ?? ''));

$step = (string) ($r['json']['data']['step'] ?? '');
check('the response says the login is complete', $step === 'complete',
    "step was '" . $step . "' msg='" . (string) ($r['json']['message'] ?? '') . "'");
check('the response flags the bypass', ($r['json']['data']['local_bypass'] ?? false) === true);
check('it carries a redirect', !empty($r['json']['data']['redirect']),
    json_encode($r['json']['data']['redirect'] ?? null));

// ── 2. The session really works ──────────────────────────────
//
// Probed with a REGISTRAR page, not a student one. This account is a
// registrar and has no linked student row, so student/documents.php
// answers "no student account linked" whether or not the session is
// valid - which is a check that passes for the wrong reason.
echo "\n== the session it granted actually works ==\n";
// GET, not POST: the desk is a render-only page and answers a POST
// with a 400 of its own, which is unrelated to the session.
$r = get("$BASE/registrar/documents.php", $jar);
check('a guarded registrar page is reachable with that session',
    $r['status'] === 200, "status {$r['status']}");
check('it is not the login page',
    stripos($r['body'], 'Sign In') === false && stripos($r['body'], 'id="loginForm"') === false,
    'the session did not carry - redirected to login');
check('the desk actually rendered for this user',
    stripos($r['body'], 'Document Requests') !== false,
    'no desk content in the response');

// And the session must NOT be a pre-login one. The API endpoint below
// is role-gated and reached its own validation ("student id and
// document type are required") rather than refusing on auth - which is
// exactly what proves the session was accepted and the caller got past
// the guard into business logic.
$r = get("$BASE/api/documents.php", $jar);
$authRefused = stripos((string) $r['body'], 'Unauthorized') !== false
    || stripos((string) $r['body'], 'Forbidden') !== false;
check('a staff API endpoint does not refuse this session',
    $authRefused === false, substr($r['body'], 0, 160));

// ── 3. The same login from a non-loopback address ───────────
echo "\n== a non-loopback caller still gets the code step ==\n";
// Simulated by asking the SHARED decision function rather than by
// forging REMOTE_ADDR over HTTP - REMOTE_ADDR cannot be set by a
// client, which is the property that makes the gate safe. So this
// checks the gate directly.
require_once __DIR__ . '/../shared/auth_security.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check('a remote address does not bypass', localOtpBypass() === false);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check('loopback does bypass (control)', localOtpBypass() === true);

@unlink($jar);
echo "\n----------------------------------------\n";
echo "passed: $pass   failed: $fail\n";
echo "the registrar's real password hash has been restored\n";
exit($fail === 0 ? 0 : 1);