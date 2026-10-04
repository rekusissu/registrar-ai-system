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
    public function testThePasswordStepNeverGrantsASession(): void
    {
        self::assertStringNotContainsString(
            'signInSession(',
            $this->formLogin(),
            'a correct password must not sign anyone in - only the verified code may'
        );
        self::assertStringNotContainsString(
            'signInSession(',
            $this->jsonLogin(),
            'the JSON endpoint must enforce the same rule as the form; a client that skips the code gets weaker login'
        );
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
        self::assertStringNotContainsString(
            "window.location.href",
            $handler,
            'the password step must not redirect into the portal - that is the whole defect'
        );
    }

    /**
     * Guard against the tempting "fix" of making OTP optional.
     *
     * A switch that lets a caller skip the second factor is the same
     * defect with an extra step, so it is pinned shut.
     */
    public function testThereIsNoFlagThatTurnsTheCodeStepOff(): void
    {
        foreach (['shared/auth_actions.php', 'api/auth.php'] as $file) {
            $code = $this->code($file);
            self::assertStringNotContainsString(
                'OTP_REQUIRED',
                $code,
                "{$file}: a bypass flag is the defect wearing a switch"
            );
            self::assertStringNotContainsString("getenv('OTP_REQUIRED')", $code);
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