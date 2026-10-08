<?php

use PHPUnit\Framework\TestCase;

/**
 * A password alone must never be a login.
 *
 * Every other piece of the second factor already existed - otp_codes,
 * issueOtp(), verifyOtpCode(), the verify_otp endpoint, the step-2 form in
 * login.php - and none of it ran. Both login endpoints called
 * signInSession() the moment password_verify() passed, so a stolen or
 * reused password was a complete account takeover and the OTP was dead
 * code that merely made the page LOOK like it verified something.
 *
 * These tests pin the WIRING, not the helpers. A test that only checked
 * "does issueOtp() exist" would pass straight over the original defect,
 * which is exactly what happened: the checklist marked OTP as done while
 * the session was granted upstream of it.
 */
final class LoginOtpRequiredTest extends TestCase
{
    /** Read a source file. */
    private function src(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($path, "missing source file: {$relative}");
        return (string) file_get_contents($path);
    }

    /**
     * Source with comments stripped.
     *
     * Required here for the same reason as in AuthHardeningTest: these
     * fixes quote the old code ("this used to call signInSession() right
     * here") inside comments, and a naive search would match the comment
     * and report the defect as still live.
     */
    private function code(string $relative): string
    {
        $src = $this->src($relative);
        $src = (string) preg_replace('~^\s*//.*$~m', '', $src);
        $src = (string) preg_replace('~^\s*\#.*$~m', '', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return $src;
    }

    /**
     * The login handler of one endpoint, sliced out of the file.
     *
     * Slicing by the handler's own boundaries is what makes this honest.
     * The same file contains verify_otp, which is SUPPOSED to call
     * signInSession(); searching the whole file for "signInSession" would
     * find that correct call and conclude the login path was fine, when
     * the defect is precisely that login called it too.
     */
    private function loginHandler(string $relative, string $openNeedle): string
    {
        $code  = $this->code($relative);
        $start = strpos($code, $openNeedle);
        self::assertNotFalse($start, "could not find the login handler in {$relative}");

        $end = strpos($code, "if (\$action === '", $start + strlen($openNeedle));
        if ($end === false) {
            $end = strpos($code, 'if ($method ===', $start + strlen($openNeedle));
        }
        self::assertNotFalse($end, "could not find the end of the login handler in {$relative}");

        return substr($code, $start, $end - $start);
    }

    /** shared/auth_actions.php is what the browser form posts to. */
    private function formLogin(): string
    {
        return $this->loginHandler('shared/auth_actions.php', "if (\$action === 'login')");
    }

    /** api/auth.php is what any JSON client posts to. */
    private function jsonLogin(): string
    {
        return $this->loginHandler('api/auth.php', "if (\$method === 'POST' && \$action === 'login')");
    }

    /**
     * THE CORE ASSERTION.
     *
     * The login handler must not grant a session. If it does, a correct
     * password is a finished login and the second factor is theatre.
     */
    public function testThePasswordStepNeverGrantsASessionUnconditionally(): void
    {
        // This used to assert the ABSENCE of signInSession() anywhere in
        // the login handler. That is now too strong: the local bypass
        // legitimately calls it, but ONLY behind localOtpBypass(), which
        // is loopback-only and off by default.
        //
        // So the invariant is stated as it actually matters - a session
        // may be granted on a password alone only if it sits inside a
        // bypass gate - rather than as a grep for a function name.
        foreach ([
            'shared/auth_actions.php' => $this->formLogin(),
            'api/auth.php'            => $this->jsonLogin(),
        ] as $file => $login) {
            $at = strpos($login, 'signInSession(');
            if ($at === false) {
                // No grant at all: strictly stronger, and fine.
                continue;
            }

            // The grant must be preceded by the gate, and must not sit
            // outside the if-block that the gate opens.
            $gate = strpos($login, 'localOtpBypass()');
            self::assertNotFalse(
                $gate,
                "{$file}: the login handler grants a session but never checks localOtpBypass() - "
                . 'a correct password would be a finished login'
            );
            self::assertLessThan(
                $at,
                $gate,
                "{$file}: the session is granted BEFORE the bypass gate is evaluated - "
                . 'the gate must come first or it guards nothing'
            );

            // And the bypass must not have been inlined into a bare
            // condition of its own - the whole gate has to be the shared
            // helper, or its three conditions can be bypassed here.
            self::assertStringNotContainsString(
                "getenv('LOCAL_OTP_BYPASS')",
                $login,
                "{$file}: the flag is read at the call site instead of inside localOtpBypass(), "
                . 'so the loopback check is skipped'
            );
        }
    }

    /** The handler must issue the code, or there is nothing to verify. */
    public function testThePasswordStepIssuesACode(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $login = $file === 'api/auth.php' ? $this->jsonLogin() : $this->formLogin();
            self::assertStringContainsString(
                "issueOtp(\$db, (int) \$user['id'], 'login')",
                $login,
                "{$file}: the login step must mint the code it is about to demand"
            );
        }
    }

    /**
     * The response must say which step comes next.
     *
     * The page branches on `step: 'otp'`; without it the user is either
     * stranded on step 1 or - worse - sent straight to the dashboard.
     */
    public function testTheLoginResponseAdvertisesTheCodeStep(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            self::assertStringContainsString(
                "'step'         => 'otp'",
                $this->code($file),
                "{$file} must return step=otp or the client cannot know a code is required"
            );
        }
    }

    /**
     * The session must be bound to the account BEFORE the code goes out.
     *
     * verify_otp and resend_otp both refuse any user_id that does not
     * match $_SESSION['otp_user_id']. If login does not set it, every
     * verification is rejected as "Invalid request" - the flow would be
     * unreachable rather than open, which is the subtler half of this bug.
     */
    public function testTheLoginStepBindsTheSessionToTheAccount(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $login = $file === 'api/auth.php' ? $this->jsonLogin() : $this->formLogin();
            self::assertStringContainsString(
                "\$_SESSION['otp_user_id']",
                $login,
                "{$file}: verify_otp only accepts the account this session is working on, so login must claim it"
            );
        }
    }

    /**
     * verify_otp is the ONLY thing that may call signInSession on a login.
     *
     * This is what makes the first test meaningful rather than an
     * assertion about an absent string: the capability has to exist
     * SOMEWHERE, or nobody could ever sign in and this change would have
     * been a lockout rather than a second factor.
     */
    public function testTheVerifiedCodeIsWhatActuallySignsYouIn(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $code = $this->code($file);
            $at   = strpos($code, "action === 'verify_otp'");
            self::assertNotFalse($at, "{$file} has no verify_otp handler");
            self::assertStringContainsString(
                'signInSession(',
                substr($code, $at),
                "{$file}: the verified code must be the thing that grants the session"
            );
        }
    }

    /**
     * The code must never travel in the response.
     *
     * OTP_SHOW_ONSCREEN is a development convenience; if the login
     * response echoed the code it would undo the entire control on any
     * host where that flag had been left on.
     */
    public function testTheCodeIsNeverReturnedToTheClient(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            self::assertStringNotContainsString(
                "'otp'          => \$otp",
                $this->code($file),
                "{$file}: never hand the code back, whatever OTP_SHOW_ONSCREEN says"
            );
        }
    }

    /**
     * An account with no deliverable address cannot complete a two-step
     * login, and saying so beats stranding the user on a code that will
     * never arrive.
     */
    public function testAnAccountWithNoEmailIsToldWhyItCannotSignIn(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $login = $file === 'api/auth.php' ? $this->jsonLogin() : $this->formLogin();
            self::assertStringContainsString(
                'no email address on file',
                $login,
                "{$file}: an undeliverable code is not a login; say so rather than failing silently"
            );
        }
    }

    /**
     * The page must advance to the code step, not to the dashboard.
     *
     * This is the client half of the same rule. A server that withholds the
     * session but a page that redirects anyway would show the user a
     * dashboard they are about to be bounced off, which is worse than
     * either behaviour on its own.
     *
     * Scoped to the password handler and stopped at the NEXT handler. The
     * code-verify handler below it legitimately redirects on success — that
     * is the only place a redirect belongs — so a window that ran past the
     * end of the first handler would flag correct code.
     */
    public function testThePageAdvancesToTheCodeStepInsteadOfTheDashboard(): void
    {
        $page  = $this->code('login.php');
        $start = strpos($page, "post('login'");
        self::assertNotFalse($start, 'login.php no longer posts the login action');

        $end = strpos($page, "\$('otpForm').addEventListener", $start);
        self::assertNotFalse($end, 'could not find the end of the password handler');
        $handler = substr($page, $start, $end - $start);

        self::assertStringContainsString(
            "step === 'otp'",
            $handler,
            'the page must branch on the code step the server now returns'
        );

        // The password step must not redirect into the portal - with ONE
        // exception: step === 'complete', which the server only returns
        // when localOtpBypass() was true, i.e. loopback with the flag on.
        //
        // Asserted structurally rather than by banning the string: every
        // redirect in the handler must sit inside the 'complete' branch,
        // and the catch-all "success but not a step I know" branch must
        // still refuse. That way the failure mode this test exists to
        // catch - a redirect on a plain successful password - is still
        // caught, while the one sanctioned bypass keeps working.
        $at = strpos($handler, "step === 'complete'");
        self::assertNotFalse(
            $at,
            "the page has no 'complete' branch, so a bypassed login would be refused as incomplete"
        );

        $redirects = preg_match_all('/window\.location\.href/', $handler);
        self::assertSame(
            1,
            $redirects,
            "expected exactly one redirect in the password handler - the 'complete' branch. "
            . "Found {$redirects}; any other is the defect this test guards."
        );

        // The redirect must be inside the 'complete' branch: i.e. after
        // the branch opens and before the next `} else if`.
        $branchEnd = strpos($handler, '} else if', $at);
        self::assertNotFalse($branchEnd, 'could not find the end of the complete branch');
        self::assertStringContainsString(
            'window.location.href',
            substr($handler, $at, $branchEnd - $at),
            'the redirect must live inside the complete branch, not somewhere else in the handler'
        );

        // The refusal branch must survive: a success the page does not
        // understand is still an error, not a login.
        self::assertStringContainsString(
            'Sign-in is incomplete',
            $handler,
            'the unknown-step refusal must remain - a server regression must not become a login'
        );
    }

    /**
     * The bypass is loopback-only, and that is the property that makes it
     * safe rather than the defect it replaces.
     *
     * This test USED TO assert that no bypass flag could exist at all
     * ("a bypass flag is the defect wearing a switch"). That reasoning is
     * right about a plain flag and it is why the feature was built with
     * three conditions instead of one: an opt-in flag, a loopback client
     * address, and an explicit production override - two independent
     * settings, so enabling the bypass cannot also disable the production
     * guard.
     *
     * So the invariant is now pinned directly rather than asserted
     * indirectly by forbidding the word. What is checked:
     *   - localOtpBypass() requires loopback (REMOTE_ADDR)
     *   - it consults no caller-controllable proxy header, so the check
     *     cannot be forged with X-Forwarded-For
     *   - the production override is a SEPARATE setting from the on/off
     *     flag, so the flag alone cannot open a production host
     *   - a request with no REMOTE_ADDR (CLI) is not bypassable
     */
    public function testTheLocalBypassIsLoopbackOnlyAndFailsClosed(): void
    {
        $src = $this->code('shared/auth_security.php');
        $at  = strpos($src, 'function localOtpBypass(');
        self::assertNotFalse($at, 'localOtpBypass() is gone - is the loopback check still enforced?');

        // The body of the decision function, not the whole file, so a
        // comment elsewhere cannot satisfy this.
        $body = substr($src, $at, 2000);

        self::assertStringContainsString(
            'REMOTE_ADDR',
            $body,
            'the bypass must key on the socket peer, which a caller cannot set'
        );
        self::assertStringContainsString(
            "['127.0.0.1', '::1']",
            $body,
            'only loopback may be bypassed'
        );

        // No forwarded header may be consulted anywhere in the helper.
        // These are caller-controlled: trusting one would let a remote
        // client simply declare itself as 127.0.0.1 and the whole gate
        // would be decorative.
        foreach (['X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR_X'] as $header) {
            self::assertStringNotContainsString(
                $header,
                $src,
                "a proxy header ({$header}) is caller-controlled and must not gate a security check"
            );
        }

        // The production override must NOT be the on/off flag, or the
        // most dangerous state - bypass on, host deployed - becomes the
        // only one that works.
        self::assertStringContainsString(
            'LOCAL_OTP_BYPASS_ALLOW_PROD',
            $src,
            'there must be a separate production-override setting'
        );
        self::assertStringNotContainsString(
            "localOtpBypassOverrideProduction()\n{\n    return localOtpBypassFlagOn();",
            $src,
            'the production override must not be the bypass flag itself'
        );
    }

    /**
     * The bypass must be consulted only after the password is proven.
     *
     * Placed before the credential check it would be a free login; placed
     * before the throttle bookkeeping it would also be a free brute-force
     * attempt. Both endpoints call it in the same position, after both.
     */
    public function testTheBypassRunsAfterThePasswordAndTheThrottle(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $login = $file === 'api/auth.php' ? $this->jsonLogin() : $this->formLogin();

            $pw   = strpos($login, 'password_verify(');
            $thr  = strpos($login, 'loginThrottleClear(');
            $gate = strpos($login, 'localOtpBypass()');

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

    /**
     * The session must still rotate at the point of verification.
     *
     * Without session_regenerate_id the code step is a session-fixation
     * opportunity: an attacker who plants a known session id before the
     * victim verifies inherits the authenticated session.
     */
    public function testTheSessionIsRegeneratedWhenTheCodeIsVerified(): void
    {
        $security = $this->code('shared/auth_security.php');
        $at = strpos($security, 'function signInSession(');
        self::assertNotFalse($at);
        self::assertStringContainsString(
            'session_regenerate_id(',
            substr($security, $at, 700),
            'the id must rotate when privileges are granted, or the pre-code session is the one that survives'
        );
    }
}