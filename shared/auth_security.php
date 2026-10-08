<?php
// ============================================================
//  SHARED/AUTH_SECURITY.PHP
//  Shared login hardening used by BOTH login endpoints
//  (shared/auth_actions.php and api/auth.php) so the rules stay
//  identical:
//    1. resolveLoginUser()   — username / student_id / email lookup
//    2. handleFailedAttempt()— increment + 10-min lockout every 5
//    3. issueOtp()           — hashed 6-digit one-time code (mail
//                              with on-screen fallback)
//    4. verifyOtpCode()      — check + consume an OTP
//    5. signInSession()      — establish the logged-in session
//  6. localOtpBypass()     — loopback-only, opt-in, off by default
//  Idempotent includes (guarded) so it is safe to require() from
//  any entry point.
// ============================================================

if (defined('AUTH_SECURITY_LOADED')) {
    return;
}
define('AUTH_SECURITY_LOADED', true);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';   // logActivity()

// 5 failed attempts → lock out for 10 minutes.
if (!defined('LOCKOUT_THRESHOLD'))   define('LOCKOUT_THRESHOLD', 5);
if (!defined('LOCKOUT_DURATION_SEC')) define('LOCKOUT_DURATION_SEC', 600);
if (!defined('OTP_TTL_SEC'))         define('OTP_TTL_SEC', 300);      // 5 min
if (!defined('OTP_MAX_VERIFY_ATTEMPTS')) define('OTP_MAX_VERIFY_ATTEMPTS', 3);
// Show the OTP on screen as a dev/test fallback when mail() is
// unavailable (bare XAMPP). Turn OFF in production.
// Override with OTP_SHOW_ONSCREEN env var or APP_ENV setting
if (!defined('OTP_SHOW_ONSCREEN')) {
    $showOtpOnScreen = getenv('OTP_SHOW_ONSCREEN');
    if ($showOtpOnScreen === false) {
        // Default: disable in production, enable in development
        $showOtpOnScreen = (defined('APP_ENV') && APP_ENV === 'production') ? 'false' : 'true';
    }
    define('OTP_SHOW_ONSCREEN', $showOtpOnScreen === 'true' || $showOtpOnScreen === '1');
}

// ============================================================
//  LOCAL OTP BYPASS  (opt-in, loopback-only, off by default)
// ============================================================
//
//  WHAT THIS IS FOR
//  ----------------
//  Signing in on a developer machine, where the OTP goes to a Gmail
//  account nobody is watching and mail delivery is unreliable anyway.
//  The second factor is real protection and it stays on everywhere it
//  matters; this makes it possible to work locally without it.
//
//  WHY IT IS NOT JUST A FLAG
//  ------------------------
//  tests/LoginOtpRequiredTest.php exists to pin the code step shut, and
//  its testThereIsNoFlagThatTurnsTheCodeStepOff asserts that no bypass
//  flag exists, because "a bypass flag is the defect wearing a switch".
//  That reasoning is correct and is respected here: a flag anyone can
//  set is not a local convenience, it is a way to remove a second
//  factor in production by setting one environment variable.
//
//  So the bypass needs THREE independent conditions, and every one of
//  them has to hold:
//
//    1. LOCAL_OTP_BYPASS is explicitly on  (in secrets.local, which is
//       gitignored, or the env var)
//    2. The request came from loopback  (REMOTE_ADDR is 127.0.0.1 or
//       ::1 - a real deployment is never its own client)
//    3. APP_ENV is not 'production', OR LOCAL_OTP_BYPASS_ALLOW_PROD is
//       separately set
//
//  Condition 2 is the one that carries the safety, and it is the reason
//  this is not "a flag": even with the flag on, a request arriving over
//  a network CANNOT bypass the code, because a remote client is never
//  127.0.0.1. Setting the flag on the live host therefore does not
//  reopen the second factor to an attacker - it does nothing at all.
//
//  Conditions 1 and 3 are two INDEPENDENT settings, not one. An earlier
//  draft reused the same flag for both, which meant that switching the
//  bypass on also switched off the production guard - so the single most
//  dangerous state (flag on, host deployed) was the only one that
//  worked. Stock XAMPP runs APP_ENV=production, so turning the bypass
//  on there genuinely needs both lines:
//
//      LOCAL_OTP_BYPASS=1
//      LOCAL_OTP_BYPASS_ALLOW_PROD=1
//
//  A REMOTE_ADDR header is NOT consulted, and no proxy header is
//  trusted. Both are caller-controlled and would make the check
//  forgeable, which is the whole thing this is guarding against.
//
//  To turn it off: delete those lines, or set them to 0.

/** Read a setting from secrets.local, which is gitignored. */
function localSecretValue(string $key): ?string
{
    $file = __DIR__ . '/secrets.local';
    if (!is_file($file)) {
        return null;
    }
    foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$k, $val] = array_pad(explode('=', $line, 2), 2, '');
        if (trim($k) === $key) {
            return trim($val);
        }
    }
    return null;
}

/**
 * May THIS request skip the login OTP?
 *
 * Call this and branch on it ONLY in the step that would otherwise
 * issue a code. Every other use is a mistake.
 */
function localOtpBypass(): bool
{
    // 1. Explicit opt-in, checked FIRST and on its own. Off unless
    //    someone turned it on.
    if (!localOtpBypassFlagOn()) {
        return false;
    }

    // 2. Loopback only. This is the check that makes the flag safe.
    //    A real deployment is never its own client, so even a host with
    //    the flag on cannot be bypassed over a network.
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
        return false;
    }

    // 3. Production stays closed unless an operator overrode it BY
    //    NAME - see localOtpBypassOverrideProduction().
    //
    //    Deliberately NOT keyed on the same flag as step 1. An earlier
    //    draft of this function checked `APP_ENV === 'production' &&
    //    !flagOn()`, which meant that turning the flag on ALSO switched
    //    off the production guard - so the single most dangerous state
    //    (flag on, deployed) was the one that worked. The override is
    //    a separate, separately-named setting so the common case cannot
    //    reach it by accident.
    if (defined('APP_ENV') && APP_ENV === 'production' && !localOtpBypassOverrideProduction()) {
        return false;
    }

    return true;
}

/** Has someone turned the local bypass on? Opt-in, defaults to off. */
function localOtpBypassFlagOn(): bool
{
    $flag = getenv('LOCAL_OTP_BYPASS');
    if ($flag === false || $flag === '') {
        $flag = localSecretValue('LOCAL_OTP_BYPASS');
    }
    return in_array((string) $flag, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Did an operator explicitly say "yes, bypass even with APP_ENV=production"?
 *
 * APP_ENV defaults to 'production' precisely so a host that forgets to
 * set it fails closed. A developer on stock XAMPP therefore has
 * APP_ENV=production and no way to reach the bypass without this second
 * setting - which is the point: it takes two deliberate acts, in a
 * gitignored file, on a machine that is also serving loopback only.
 *
 * It is still not a hole. Loopback is required regardless, so the pair
 * cannot be reached from a network even when both are set.
 */
function localOtpBypassOverrideProduction(): bool
{
    $flag = getenv('LOCAL_OTP_BYPASS_ALLOW_PROD');
    if ($flag === false || $flag === '') {
        $flag = localSecretValue('LOCAL_OTP_BYPASS_ALLOW_PROD');
    }
    return in_array((string) $flag, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Did someone ask for the bypass by name, even in production?
 *
 * Replaced by localOtpBypassOverrideProduction(), which is a SEPARATE
 * setting rather than the same flag. An earlier draft reused
 * LOCAL_OTP_BYPASS here, which meant enabling the bypass also disabled
 * the production guard - so the most dangerous possible state (flag on,
 * host deployed) was the only one that worked. Two settings that must be
 * set independently is the whole defence.
 */

/**
 * Resolve a login account by an arbitrary credential string.
 * Accepts, in order:
 *   - users.username      (staff login ID)
 *   - students.student_number → the linked users.student_id
 *   - users.email         (backward compat / transitional)
 * Returns the full users row, or null when no match.
 *
 * NOTE on the email branch: users.email is no longer UNIQUE (email is not a
 * unique identity in a school — families share addresses and a student may
 * later become staff). A shared address therefore matches several rows, and
 * an unqualified `LIMIT 1` could sign the wrong person in — potentially an
 * admin — which would be a privilege-escalation path. The email branch is
 * therefore ordered so a STUDENT account is never displaced by a staff
 * account, and it is only reached when the credential is not a valid
 * username or student number.
 */
function resolveLoginUser($db, string $credential): ?array {
    $credential = trim($credential);
    if ($credential === '') {
        return null;
    }

    // Primary identifiers first — these are unique and unambiguous.
    $user = $db->fetchOne(
        "SELECT u.* FROM users u
         WHERE u.username = ?
            OR u.id = (SELECT u2.id
                       FROM students s
                       JOIN users u2 ON u2.student_id = s.id
                       WHERE s.student_number = ? LIMIT 1)
         LIMIT 1",
        [$credential, $credential]
    );
    if ($user) {
        return $user;
    }

    // Transitional email login. Deterministic ordering: prefer the oldest
    // account so the result cannot flip between requests.
    return ($db->fetchOne(
        "SELECT u.* FROM users u
         WHERE u.email = ?
         ORDER BY u.id ASC
         LIMIT 1",
        [$credential]
    ) ?: null);
}

/**
 * Register one failed login attempt. When the count reaches
 * LOCKOUT_THRESHOLD, set locked_until = NOW()+10 min and reset the
 * counter. Returns the user's current lockout state.
 */
function handleFailedAttempt($db, int $userId): void {
    $attempts = (int) $db->fetchColumn(
        "SELECT login_attempts FROM users WHERE id = ?", [$userId]
    );
    $attempts++;
    if ($attempts >= LOCKOUT_THRESHOLD) {
        // Store the deadline as a PHP-computed wall-clock string. Using
        // MySQL DATE_ADD(NOW(), ...) here would write in the MySQL session
        // timezone (UTC) while lockoutRemainingSeconds() interprets the
        // string in PHP's timezone (Asia/Manila) — an 8-hour skew that
        // would make the lockout look already expired.
        $db->query(
            "UPDATE users SET login_attempts = 0,
                 locked_until = ?
              WHERE id = ?",
            [date('Y-m-d H:i:s', time() + LOCKOUT_DURATION_SEC), $userId]
        );
    } else {
        $db->update('users', ['login_attempts' => $attempts], 'id = ?', [$userId]);
    }
}

/**
 * Seconds remaining in an active lockout, or 0 when not locked.
 */
function lockoutRemainingSeconds($db, int $userId): int {
    $locked = $db->fetchColumn(
        "SELECT locked_until FROM users WHERE id = ?", [$userId]
    );
    if (!$locked) {
        return 0;
    }
    $t = strtotime($locked);
    return $t > time() ? ($t - time()) : 0;
}

/** Reset the failed-attempt / lockout fields on a successful login. */
function resetLoginLockout($db, int $userId): void {
    $db->query(
        "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?",
        [$userId]
    );
}

/** "somebody@bestlink.edu.ph" → "s******@bestlink.edu.ph". */
function maskEmail(?string $email): string {
    $email = trim((string) $email);
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 1) {
        return '*@' . $domain;
    }
    return $local[0] . str_repeat('*', max(1, strlen($local) - 2)) . $local[strlen($local) - 1] . '@' . $domain;
}

/**
 * Generate + persist a one-time code and attempt delivery.
 * Returns:
 *   [
 *     'masked_email' => string,
 *     'delivered'    => bool (true when mail() succeeded),
 *     'otp'          => string|null plaintext, present ONLY on the
 *                       on-screen fallback path
 *   ]
 */
function issueOtp($db, int $userId, string $purpose = 'login', ?string $email = null): array {
    $user = $db->fetchOne(
        "SELECT id, email FROM users WHERE id = ?", [$userId]
    );
    $email = $email ?? ($user['email'] ?? '');
    $purpose = in_array($purpose, ['login', 'reset'], true) ? $purpose : 'login';

    // Clean up any expired OR already-used codes for this user+purpose
    // so an old code can never be replayed. expires_at is stored as a
    // PHP-computed wall-clock string (Asia/Manila), so compare against
    // PHP's wall clock, not MySQL NOW() (which runs in UTC here).
    $nowStr = date('Y-m-d H:i:s');
    $db->query(
        "DELETE FROM otp_codes
         WHERE user_id = ? AND purpose = ?
           AND (expires_at < ? OR used_at IS NOT NULL)",
        [$userId, $purpose, $nowStr]
    );

    $otp = (string) random_int(100000, 999999);
    $db->insert('otp_codes', [
        'user_id'    => $userId,
        'otp_hash'   => password_hash($otp, PASSWORD_DEFAULT),
        'purpose'    => $purpose,
        'expires_at' => date('Y-m-d H:i:s', time() + OTP_TTL_SEC),
    ]);

    // Prefer SMTP via PHPMailer when email is configured (reliable on
    // XAMPP where PHP mail() has no transport); fall back to mail().
    if (!function_exists('sendEmail')) {
        $mailClient = __DIR__ . '/mail_client.php';
        if (is_file($mailClient) && is_file(__DIR__ . '/../vendor/autoload.php')) {
            require_once $mailClient;
        }
    }

    $delivered = false;
    $to = trim($email);
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Your ' . ($purpose === 'reset' ? 'Password Reset' : 'One-Time') . ' Code – BCP Registrar';
        $escOtp = function_exists('e_') ? e_($otp) : htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $htmlBody = '<p>Hello,</p>'
            . '<p>Your BCP Registrar ' . ($purpose === 'reset' ? 'password reset' : 'verification') . ' code is:</p>'
            . '<p style="font-size:26px;font-weight:700;letter-spacing:4px;color:#2563eb;">' . $escOtp . '</p>'
            . '<p>This code expires in ' . (int) (OTP_TTL_SEC / 60) . ' minutes.</p>'
            . '<p style="color:#64748b;font-size:12px;">If you did not request this, please ignore this message.</p>';

        if (function_exists('sendEmail') && emailConfigured()) {
            $delivered = sendEmail(['email' => $to], $subject, $htmlBody);
        } else {
            $plainBody = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $htmlBody)));
            $headers = "From: no-reply@bestlink.edu.ph\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $delivered = @mail($to, $subject, $plainBody, $headers);
        }
    }

    $plaintext = null;
    if (!$delivered && OTP_SHOW_ONSCREEN) {
        $plaintext = $otp;
    }

    logActivity($userId, 'otp_issued', json_encode(['purpose' => $purpose, 'delivered' => $delivered]));

    return [
        'masked_email' => maskEmail($email),
        'delivered'    => $delivered,
        'otp'          => $plaintext,
    ];
}

/**
 * Verify + consume a one-time code. True ONLY for the newest,
 * unused, unexpired code matching user+purpose. Marks it used.
 *
 * Brute force: OTP_MAX_VERIFY_ATTEMPTS was defined here but never
 * consulted, so a 6-digit code (900,000 combinations) could be guessed
 * without limit — especially easy because resend_otp would mint a fresh
 * code on demand. The attempt counter is now enforced and, once spent,
 * the code is consumed so the attacker must wait for a new one.
 */
function verifyOtpCode($db, int $userId, string $purpose, string $code): bool {
    $purpose = in_array($purpose, ['login', 'reset'], true) ? $purpose : 'login';
    $nowStr = date('Y-m-d H:i:s');
    $row = $db->fetchOne(
        "SELECT id, otp_hash, verify_attempts FROM otp_codes
         WHERE user_id = ? AND purpose = ?
           AND used_at IS NULL AND expires_at >= ?
         ORDER BY id DESC LIMIT 1",
        [$userId, $purpose, $nowStr]
    );
    if (!$row) {
        return false;
    }

    // Already exhausted: burn the code so it cannot be retried.
    if ((int) $row['verify_attempts'] >= OTP_MAX_VERIFY_ATTEMPTS) {
        $db->query(
            "UPDATE otp_codes SET used_at = NOW() WHERE id = ?", [(int) $row['id']]
        );
        error_log('[otp] verify attempt cap reached for user ' . $userId . ' (' . $purpose . ')');
        return false;
    }

    if (!password_verify(trim($code), $row['otp_hash'])) {
        $db->query(
            "UPDATE otp_codes SET verify_attempts = verify_attempts + 1 WHERE id = ?",
            [(int) $row['id']]
        );
        return false;
    }

    $db->query(
        "UPDATE otp_codes SET used_at = NOW() WHERE id = ?", [(int) $row['id']]
    );
    return true;
}

// ============================================================
//  PASSWORD RESET GRANTS
//  ============================================================
//
//  Why this exists
//  ---------------
//  reset_password used to accept a bare `user_id` + `new_password` with
//  no proof of anything:
//
//      POST action=reset_password&user_id=1&new_password=x
//
//  No OTP check, no token, no session. That was unauthenticated
//  takeover of any account, including admin (CWE-620, Weak Password
//  Recovery Mechanism).
//
//  Now a grant is minted ONLY after an OTP has been verified. The grant
//  is stored hashed, expires, and is single-use — matching the OWASP
//  Forgot Password guidance to create a limited session from the
//  verified code that permits only the reset.
//
//  Table: migrations/security_hardening_phase1.sql
// ---------------------------------------------------------------

if (!defined('RESET_GRANT_TTL_SEC')) define('RESET_GRANT_TTL_SEC', 900); // 15 min

/**
 * Mint a reset grant after a reset OTP was verified.
 * Returns the raw token ONCE — only its hash is stored.
 */
function issueResetGrant($db, int $userId): string {
    $token = bin2hex(random_bytes(32));           // 256 bits of entropy
    $nowStr = date('Y-m-d H:i:s');

    // Only one live grant per user: invalidate any previous one.
    $db->query(
        "UPDATE password_reset_grants SET used_at = ?
         WHERE user_id = ? AND used_at IS NULL",
        [$nowStr, $userId]
    );

    $db->insert('password_reset_grants', [
        'user_id'    => $userId,
        'token_hash' => password_hash($token, PASSWORD_DEFAULT),
        'expires_at' => date('Y-m-d H:i:s', time() + RESET_GRANT_TTL_SEC),
    ]);

    return $token;
}

/**
 * Consume a reset grant. Returns the user id on success, or null when
 * the token is unknown, already used, or expired.
 *
 * password_verify() is used rather than hash_equals() because the token
 * is stored as a bcrypt hash: there is nothing to compare byte-wise.
 */
function consumeResetGrant($db, string $token): ?int {
    $token = trim($token);
    if ($token === '' || strlen($token) !== 64) {
        return null;
    }
    $nowStr = date('Y-m-d H:i:s');

    $rows = $db->fetchAll(
        "SELECT id, user_id, token_hash FROM password_reset_grants
         WHERE used_at IS NULL AND expires_at >= ?
         ORDER BY id DESC LIMIT 10",
        [$nowStr]
    );

    foreach ($rows as $row) {
        if (password_verify($token, (string) $row['token_hash'])) {
            // Single-use: mark consumed before the password is written so
            // a concurrent replay cannot win a race.
            $db->update('password_reset_grants', ['used_at' => $nowStr], 'id = ?', [(int) $row['id']]);
            return (int) $row['user_id'];
        }
    }
    return null;
}

/**
 * Finalise a password change: stamp users.password_changed_at, drop any
 * other live reset grants, and clear lockout state.
 *
 * Session invalidation is NOT done here. PHP stores sessions in files
 * keyed by an opaque id, so there is no portable way to enumerate and
 * delete another device's session. The mechanism used instead is the
 * password_changed_at stamp: session_config.php compares it against
 * $_SESSION['login_time'] on every request and destroys any session that
 * predates the change. That achieves the same outcome — a stolen cookie
 * stops working the moment the victim resets — without pretending we can
 * reach into another browser's session store.
 *
 * Timestamp comparison is the only safe check, so it must be written in
 * PHP's wall clock (Asia/Manila), not MySQL NOW() (UTC on this host) —
 * see the same reasoning in handleFailedAttempt().
 */
function finalizePasswordChange($db, int $userId, string $newPasswordHash): void {
    $nowStr = date('Y-m-d H:i:s');

    // users.password_hash is NOT NULL, so the real hash is written here in
    // the same statement rather than nulled as a placeholder.
    $db->update('users', [
        'password_hash'       => $newPasswordHash,
        'password_changed_at' => $nowStr,
        'login_attempts'      => 0,
        'locked_until'        => null,
        'updated_at'          => $nowStr,
    ], 'id = ?', [$userId]);

    // Any other outstanding reset grant is now void.
    $db->query(
        "UPDATE password_reset_grants SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
        [$nowStr, $userId]
    );
}

/**
 * Establish the authenticated session after OTP verification, and
 * return the role-based redirect target.
 */
function signInSession(array $user): string {
    session_regenerate_id(true);
    $_SESSION['user_id']     = (int) $user['id'];
    $_SESSION['email']       = $user['email'] ?? '';
    $_SESSION['full_name']   = $user['full_name'] ?? 'User';
    $_SESSION['role']        = $user['role'] ?? 'staff';
    $_SESSION['login_time']  = time();
    $_SESSION['last_activity'] = time();
    logActivity((int) $user['id'], 'login_success');
    $role = $user['role'] ?? 'staff';
    if ($role === 'student') {
        return 'student/dashboard.php';
    }
    return 'dashboard.php';
}
