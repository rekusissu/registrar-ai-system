<?php

use PHPUnit\Framework\TestCase;

/**
 * The local OTP bypass must be unusable from anywhere but this machine.
 *
 * The bypass exists so signing in on a developer box does not require an
 * email round-trip. That is a real convenience, and it is also a way to
 * remove a second factor - so what matters is not that the feature
 * exists but that it cannot be reached by anyone who is not sitting at
 * this machine.
 *
 * The rule being pinned: localOtpBypass() is true only when
 *
 *   1. LOCAL_OTP_BYPASS is explicitly on, AND
 *   2. REMOTE_ADDR is loopback (127.0.0.1 / ::1), AND
 *   3. APP_ENV is not production, OR LOCAL_OTP_BYPASS_ALLOW_PROD is
 *      separately set
 *
 * Condition 2 is the load-bearing one. REMOTE_ADDR is the socket peer,
 * which a caller cannot set, so a remote request can never satisfy it -
 * which is why this is not "a flag" and why the original test's blanket
 * ban was replaced rather than simply deleted.
 *
 * These are DECISIONAL tests, not source greps: they call the function
 * with each REMOTE_ADDR and read the answer. A grep cannot tell whether
 * the gate actually holds.
 */
final class LocalOtpBypassTest extends TestCase
{
    /** @var array<string,string> server vars to restore after each test */
    private array $backup = [];

    protected function tearDown(): void
    {
        foreach ($this->backup as $k => $v) {
            if ($v === '__UNSET__') {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        $this->backup = [];
        parent::tearDown();
    }

    private function server(string $key, string $value): void
    {
        if (!array_key_exists($key, $_SERVER)) {
            $this->backup[$key] = '__UNSET__';
        } else {
            $this->backup[$key] = $_SERVER[$key];
        }
        $_SERVER[$key] = $value;
    }

    private function serverUnset(string $key): void
    {
        $this->backup[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : '__UNSET__';
        unset($_SERVER[$key]);
    }

    /**
     * Load auth_security.php with a chosen secrets.local.
     *
     * A separate PROCESS, for the same reason the decision tests need
     * one: the helper reads shared/secrets.local, and that file now
     * carries the real flag on a developer box. Overriding a constant
     * in-process is not possible because the file guards itself with
     * defined(), so the only honest way to test "flag off" is to give
     * the process a file that says nothing.
     *
     * Returns the JSON the child printed.
     */
    private function decide(string $remoteAddr, bool $flagOn): array
    {
        $script = <<<'PHP'
<?php
// Child process. argv: [repoRoot, remoteAddr, flagOn]
//
// The repo root arrives as an ARGUMENT rather than being derived from
// __DIR__: this file is written to the system temp directory, so
// dirname(__DIR__) resolves to %TEMP%, not to the repository. That was
// the first version's bug - it shadowed %TEMP%\shared\secrets.local,
// which does not exist, and then failed to load anything.
$root = rtrim($argv[1], '/\\');
$real = $root . '/shared/secrets.local';

// auth_security.php reads __DIR__ . '/secrets.local', so shadowing this
// exact file is what switches the flag for the child. Restored on exit,
// including the case where there was no file to begin with.
$backup = $real . '.bak-' . getmypid();
$had = is_file($real);
if ($had) { copy($real, $backup); }
file_put_contents(
    $real,
    $argv[3] === '1'
        ? "LOCAL_OTP_BYPASS=1\nLOCAL_OTP_BYPASS_ALLOW_PROD=1\n"
        : "# flag deliberately off for this test\n"
);
register_shutdown_function(function () use ($real, $backup, $had) {
    if ($had && is_file($backup)) { copy($backup, $real); @unlink($backup); }
    elseif (!$had) { @unlink($real); }
});

require_once $root . '/shared/auth_security.php';

if ($argv[2] === '__NONE__') { unset($_SERVER['REMOTE_ADDR']); }
else { $_SERVER['REMOTE_ADDR'] = $argv[2]; }

echo json_encode([
    'flag'     => localOtpBypassFlagOn(),
    'override' => localOtpBypassOverrideProduction(),
    'bypass'   => localOtpBypass(),
]);
PHP;
        $tmp = sys_get_temp_dir() . '/otpbypass-' . getmypid() . '-' . random_int(1000, 9999) . '.php';
        file_put_contents($tmp, $script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp)
             . ' ' . escapeshellarg(dirname(__DIR__))
             . ' ' . escapeshellarg($remoteAddr)
             . ' ' . escapeshellarg($flagOn ? '1' : '0');
        $out = (string) shell_exec($cmd . ' 2>NUL');
        @unlink($tmp);

        $json = json_decode(trim($out), true);
        self::assertIsArray($json, 'the child did not answer: ' . substr($out, 0, 300));
        return $json;
    }

    /** With the flag ON, only loopback is let through. */
    public function testOnlyLoopbackIsBypassedWhenTheFlagIsOn(): void
    {
        foreach (['127.0.0.1', '::1'] as $ip) {
            $r = $this->decide($ip, true);
            self::assertTrue($r['bypass'], "{$ip} is loopback and should be bypassed");
        }
    }

    /**
     * Every non-loopback caller is refused, WITH the flag on.
     *
     * This is the assertion that carries the whole feature. If it ever
     * fails, the bypass has become reachable from a network and the
     * second factor is gone in production.
     *
     * The last two are the ones a naive implementation gets wrong:
     * 127.0.0.1.evil.com passes a strpos() check, and 2130706433 is
     * loopback written as a decimal integer.
     */
    public function testNoRemoteCallerCanBypassEvenWithTheFlagOn(): void
    {
        $remote = [
            '203.0.113.10'   => 'a public address',
            '192.168.1.50'   => 'the LAN',
            '10.0.0.7'       => 'private range 10',
            '172.16.4.9'     => 'private range 172',
            '169.254.169.254' => 'cloud metadata',
            '0.0.0.0'        => 'the unspecified address',
            '::'             => 'the unspecified IPv6 address',
            '127.0.0.1.evil.com' => 'a host merely NAMED like loopback',
            '2130706433'     => 'loopback as a decimal integer',
            '0x7f000001'     => 'loopback as hex',
            ' 127.0.0.1'     => 'loopback with a leading space',
            '127.0.0.1:8080' => 'loopback with a port',
        ];
        foreach ($remote as $ip => $desc) {
            $r = $this->decide($ip, true);
            self::assertFalse(
                $r['bypass'],
                "{$desc} ({$ip}) must not bypass the code, even with the flag on"
            );
        }
    }

    /** A request with no REMOTE_ADDR is refused - unknown is not local. */
    public function testAnUnknownCallerFailsClosed(): void
    {
        foreach (['__NONE__', ''] as $case) {
            $r = $this->decide($case, true);
            self::assertFalse(
                $r['bypass'],
                'a caller with no usable REMOTE_ADDR must not be treated as local'
            );
        }
    }

    /**
     * Loopback ALONE is not a bypass.
     *
     * The other half of fails-closed: the flag is required as well. If
     * this fails, anyone who can reach the app on localhost - and on a
     * shared host that is not necessarily just you - skips the code.
     */
    public function testLoopbackAloneDoesNotBypassWhenTheFlagIsOff(): void
    {
        foreach (['127.0.0.1', '::1'] as $ip) {
            $r = $this->decide($ip, false);
            self::assertFalse(
                $r['bypass'],
                "{$ip} with the flag OFF must still have to pass the code"
            );
        }
    }

    /**
     * The production override is a DIFFERENT setting from the on/off flag.
     *
     * An earlier draft of the helper keyed the production guard on the
     * same flag, which meant switching the bypass on ALSO switched off
     * the production guard - so the most dangerous state (flag on, host
     * deployed) was the only one that worked. Pinned so that regression
     * cannot come back.
     */
    public function testTheProductionOverrideIsNotTheBypassFlagItself(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/shared/auth_security.php');

        // The override helper must read its OWN variable.
        self::assertStringContainsString(
            'LOCAL_OTP_BYPASS_ALLOW_PROD',
            $src,
            'there must be a separate production-override setting'
        );

        preg_match('/function localOtpBypassOverrideProduction\(\).*?\n\}/s', $src, $m);
        self::assertNotEmpty($m, 'could not find localOtpBypassOverrideProduction()');

        // It must not be a thin wrapper that defers to the on/off flag.
        self::assertStringNotContainsString(
            'return localOtpBypassFlagOn();',
            $m[0],
            'the production override must not BE the bypass flag - enabling the '
            . 'bypass would then also disable the production guard'
        );

        // And it must not read LOCAL_OTP_BYPASS at all.
        preg_match("/getenv\('LOCAL_OTP_BYPASS'\)/", $m[0], $unused);
        self::assertCount(
            0,
            $unused,
            'the production override must not consult LOCAL_OTP_BYPASS'
        );
    }

    /**
     * No proxy header may gate the decision.
     *
     * X-Forwarded-For and friends are set by the CALLER. Trusting one to
     * establish "this is local" would make the entire gate decorative:
     * anyone could simply send the header.
     */
    public function testNoCallerControlledHeaderCanSatisfyTheLoopbackCheck(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/shared/auth_security.php');

        foreach ([
            'X-Forwarded-For',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_FORWARDED',
        ] as $header) {
            self::assertStringNotContainsString(
                $header,
                $src,
                "{$header} is caller-controlled and must never gate a security check"
            );
        }
    }

    /**
     * The bypass is consulted only where the code would otherwise be
     * issued, and only after the password.
     *
     * Placed before the credential check it is a login without a
     * password; placed before the throttle bookkeeping it is also an
     * unthrottled brute-force. Both endpoints are checked, because they
     * drifted apart once already.
     */
    public function testTheBypassRunsAfterThePasswordAndTheThrottle(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            // Strip comments so prose about the bypass is not mistaken
            // for the call.
            $code = (string) preg_replace(['~^\s*//.*$~m', '~/\*.*?\*/~s'], '', $src);

            $pw   = strpos($code, 'password_verify(');
            $thr  = strpos($code, 'loginThrottleClear(');
            $gate = strpos($code, 'localOtpBypass()');

            self::assertNotFalse($pw, "{$file}: the credential check moved");
            self::assertNotFalse($thr, "{$file}: the throttle bookkeeping moved");
            self::assertNotFalse($gate, "{$file}: the bypass is no longer wired in");

            self::assertGreaterThan(
                $pw,
                $gate,
                "{$file}: the bypass runs BEFORE the password is verified - that is a login without a password"
            );
            self::assertGreaterThan(
                $thr,
                $gate,
                "{$file}: the bypass runs before the throttle bookkeeping, so a bypassed login is unthrottled"
            );
        }
    }

    /** A bypassed login is recorded, so it is never invisible. */
    public function testABypassedLoginIsAudited(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            self::assertStringContainsString(
                'login_local_bypass',
                $src,
                "{$file}: a bypassed login must be written to the activity log - "
                . 'an unlogged authentication that skipped a factor is the thing to avoid'
            );
        }
    }
}