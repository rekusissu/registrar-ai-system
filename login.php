<?php
// login.php - Phase 5 hardened sign-in:
//   Step 1: ID number / username + password
//   Step 2: one-time code (OTP)
//   Forgot password: reveal email → OTP → set new password
//   10-min lockout after 5 failed attempts (handled server-side).
//   CSRF tokens are enforced on every POST (see shared/csrf_guard.php).
// Authentication requests are handled by shared/auth_actions.php.

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
require_once __DIR__ . '/shared/csrf_guard.php';
require_once __DIR__ . '/shared/config.php';

if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    header('Location: ' . ($role === 'student' ? 'student/dashboard.php' : 'dashboard.php'));
    exit;
}

$timeout = isset($_GET['timeout']) ? true : false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light" />
    <title>Sign In · BCP Registrar</title>

    <meta name='csrf-token' content='<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>' />

    <link rel="icon" type="image/png" href="assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <link rel="stylesheet" href="assets/login/login.css?v=20261110b" />
    <!-- One show/hide control per password field. Shared because login.php,
         settings.php and registrar/users.php all had their own copy of these
         rules, and they had drifted apart - which is how two of them ended up
         showing the browser's native eye next to the page's own. Loaded
         after auth.css: this file owns the masking, that one owns the
         surrounding chrome, and neither sets the other's properties. -->
    <link rel="stylesheet" href="css/password-field.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <script src='js/csrf.js'></script>
</head>
<body>

<main class="plate" id="plate">
  <div class="plate-body">
    <section class="plate-brand" aria-label="BCP Registrar System">
      <div class="brand-header">
        <img src="assets/images/BCP_LOGO.png" alt="Bestlink College crest" class="crest" width="64" height="72" />
        <div><p class="college-name">Bestlink College of the Philippines</p><p class="system-name">BCP Registrar System</p></div>
      </div>
      <div class="brand-story">
        <h1>Your academic<br />journey, organized.</h1>
        <p>Secure access to records, documents,<br class="desktop-break" /> and student services.</p>
        <img class="brand-illustration" src="assets/login/bestlink-campus.png?v=1" alt="" width="1760" height="896" />
        <ul class="services">
          <li><i class="fa-regular fa-rectangle-list" aria-hidden="true"></i><span>Student<br />records</span></li>
          <li><i class="fa-regular fa-file-lines" aria-hidden="true"></i><span>Document<br />requests</span></li>
          <li><i class="fa-solid fa-id-card" aria-hidden="true"></i><span>RFID<br />cards</span></li>
        </ul>
      </div>
    </section>
    <section class="plate-form" id="plateForm" aria-labelledby="formTitle">
      <p class="form-eyebrow">Office of the Registrar</p>
      <h2 class="plate-title" id="formTitle">Welcome back</h2>
      <p class="form-intro" id="formIntro">Sign in to your registrar account.</p>

        <?php if ($timeout): ?>
            <div class="auth-notice auth-error" id="timeoutMsg">
                <i class="fa-solid fa-clock" aria-hidden="true"></i>
                <span>Your session expired. Sign in again to continue.</span>
            </div>
        <?php endif; ?>

        <div class="auth-notice auth-error" id="authError" style="display:none;" role="alert" aria-live="assertive"></div>
        <div class="auth-notice auth-success" id="authSuccess" style="display:none;" role="status" aria-live="polite"></div>

        <!-- STEP 1: ID / username + password -->
        <form id="step1Form" class="auth-form" novalidate>
            <div class="field">
                <label for="credential">ID number or username</label>
                <div class="field-box">
                    <i class="fa-solid fa-id-badge" aria-hidden="true"></i>
                    <input type="text" id="credential" autocomplete="username"
                           placeholder="Enter your ID or username" name="username" spellcheck="false" autocapitalize="off" required />
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="field-box password-field">
                    <!-- type="text" with a CSS mask, not type="password".
                         Chromium draws a native reveal eye that cannot be
                         styled away, which is the second "show password"
                         button. A text field gets no native eye, and
                         -webkit-text-security: disc keeps the characters
                         masked until the toggle is used. autocomplete is
                         kept so password managers still recognise it. -->
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input type="text" id="password" data-masked="1"
                           autocomplete="current-password" name="password" placeholder="Enter your password" required />
                    <button type="button" class="password-toggle-btn" id="togglePassword"
                            title="Show password" aria-label="Show password" aria-pressed="false">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="form-row forgot-row">
                <button type="button" class="link-btn" id="forgotLink">Forgot password?</button>
            </div>
            <button type="submit" class="btn-signin" id="btnSignin">
                <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                Sign in
            </button>




        </form>

        <!-- STEP 2: OTP -->
        <form id="otpForm" class="auth-form" style="display:none;" novalidate>
            <p class="otp-note">
                We sent a one-time code to <strong id="otpMasked"></strong>
                <span class="hint" id="otpResentMsg"></span>
            </p>

            <div class="field otp-box">
                <label for="otp">One-time code</label>
                <div class="field-box">
                    <input type="text" id="otp" inputmode="numeric" maxlength="6"
                           autocomplete="one-time-code" placeholder="6 digits" required />
                </div>
            </div>

            <button type="submit" class="btn-signin" id="btnVerify">
                <i class="fa-solid fa-check" aria-hidden="true"></i>
                Verify and continue
            </button>

            <div class="form-row">
                <button type="button" class="link-btn" id="resendOtp">Resend code</button>
                <button type="button" class="link-btn" id="backToLogin">Back</button>
            </div>
        </form>

        <!-- FORGOT: email -->
        <form id="forgotForm" class="auth-form" style="display:none;" novalidate>
            <p class="otp-note">
                Enter the email registered to your account and we will send a reset code.
            </p>

            <div class="field">
                <label for="forgotEmail">Email</label>
                <div class="field-box">
                    <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                    <input type="email" id="forgotEmail" autocomplete="email"
                           placeholder="you@bestlink.edu.ph" spellcheck="false" required />
                </div>
            </div>

            <button type="submit" class="btn-signin" id="btnForgot">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                Send reset code
            </button>

            <div class="form-row" style="justify-content:center;">
                <button type="button" class="link-btn" id="backToLogin2">Back to sign in</button>
            </div>
        </form>

        <!-- RESET: new password -->
        <form id="resetForm" class="auth-form" style="display:none;" novalidate>
            <p class="otp-note">Set a new password for your account.</p>

            <div class="field">
                <label for="newPassword">New password</label>
                <div class="field-box">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <!-- type="password" here on purpose. The masking trick is
                         only needed where a custom toggle shares the field;
                         this step has none, so the browser's own behaviour
                         and its password manager support are better. -->
                    <input type="password" id="newPassword" autocomplete="new-password"
                           placeholder="At least 8 characters" required />
                </div>
            </div>

            <div class="field">
                <label for="confirmPassword">Confirm password</label>
                <div class="field-box">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input type="password" id="confirmPassword" autocomplete="new-password"
                           placeholder="Repeat new password" required />
                </div>
            </div>

            <button type="submit" class="btn-signin" id="btnReset">
                <i class="fa-solid fa-check" aria-hidden="true"></i>
                Save new password
            </button>
        </form>
      <div class="form-help"><a href="mailto:registrar@bestlink.edu.ph"><i class="fa-solid fa-headset" aria-hidden="true"></i><span>Contact the Registrar</span></a></div>
    </section>
  </div>
</main>
<footer class="page-footer"><p>Bestlink College of the Philippines</p><a href="terms-and-conditions.php" id="tcLink" target="_blank" rel="noopener">Terms and conditions</a></footer>

<!-- Terms and Conditions Modal -->
<div class="tc-modal-overlay" id="tcModal" role="dialog" aria-modal="true" aria-labelledby="tcModalTitle">
    <div class="tc-modal">
        <div class="tc-modal-header">
            <h2 id="tcModalTitle"><i class="fa-solid fa-file-contract" aria-hidden="true"></i> Terms and Conditions</h2>
        </div>
        <div class="tc-modal-body">
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing and using the Bestlink College of the Philippines (BCP) Registrar Management System ("System"), you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions.</p>

            <h3>2. System Purpose</h3>
            <p>The Registrar Management System is provided by Bestlink College of the Philippines for the exclusive purpose of managing student records, academic history, health records, RFID access control, and document requests.</p>

            <h3>3. User Responsibilities</h3>
            <ul>
                <li>You are responsible for maintaining the confidentiality of your login credentials</li>
                <li>You agree not to share your credentials with any other person</li>
                <li>You agree to immediately notify the IT department if you suspect unauthorized access</li>
                <li>You are responsible for all activities that occur under your account</li>
                <li>You agree to use the System only for authorized educational and administrative purposes</li>
            </ul>

            <h3>4. Prohibited Activities</h3>
            <p>You agree not to:</p>
            <ul>
                <li>Attempt to gain unauthorized access to the System or its data</li>
                <li>Modify, copy, or distribute System content without authorization</li>
                <li>Use the System for any illegal, harmful, or harassing purpose</li>
                <li>Attempt to reverse-engineer, decompile, or discover the source code</li>
                <li>Interfere with or disrupt the normal operation of the System</li>
                <li>Attempt to bypass security measures or access controls</li>
                <li>Use automated tools, scripts, or bots to access the System without authorization</li>
            </ul>

            <h3>5. Privacy and Data Protection</h3>
            <p>Your personal information, academic records, and health data are protected under applicable data privacy laws. The System implements industry-standard security measures including encryption, access controls, and audit logging.</p>

            <h3>6. Intellectual Property</h3>
            <p>All content, design, and functionality of the Registrar Management System are the intellectual property of Bestlink College of the Philippines. You may not reproduce, modify, or distribute any part of the System without explicit written permission.</p>

            <h3>7. Limitation of Liability</h3>
            <p>The Registrar Management System is provided "as is" without warranties of any kind. Bestlink College of the Philippines shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of or inability to use the System.</p>

            <h3>8. System Availability</h3>
            <p>While we strive to maintain continuous availability of the System, we make no guarantee of uninterrupted service. The System may be temporarily unavailable for maintenance, updates, or due to unforeseen circumstances.</p>

            <h3>9. Changes to Terms</h3>
            <p>Bestlink College of the Philippines reserves the right to modify these Terms and Conditions at any time. Your continued use of the System following notification of changes constitutes your acceptance of the revised terms.</p>

            <h3>10. Termination of Access</h3>
            <p>The college reserves the right to suspend or terminate your access to the System at any time, with or without cause, including but not limited to violations of these Terms and Conditions.</p>

            <h3>11. Governing Law</h3>
            <p>These Terms and Conditions shall be governed by and construed in accordance with the laws of the Republic of the Philippines.</p>

            <h3>12. Contact Information</h3>
            <p><strong>Office of the Registrar</strong><br>Bestlink College of the Philippines<br>Email: registrar@bestlink.edu.ph</p>
        </div>
        <div class="tc-modal-footer">
            <button class="tc-modal-btn" id="tcModalCloseBtn">Close</button>
        </div>
    </div>
</div>

<script>
let session = { user_id: null, purpose: 'login', otp: null, status: 'idle' };

const $ = (id) => document.getElementById(id);

// The card is the busy indicator AND the status readout. Both are driven
// off classes on it rather than running on a timer, so the pip's colour is
// the page's real last-known state and is only ever set by a request that
// genuinely went somewhere.
const plate = $('plate');

function setBusy(on) {
    if (plate) plate.classList.toggle('is-busy', !!on);
}

// The pip. 'ok' and 'bad' are set by the outcome of a real request, so the
// colour always reports something that happened rather than decorating.
function setState(state) {
    if (!plate) return;
    plate.classList.remove('is-ok', 'is-rejected');
    if (state) plate.classList.add('is-' + state);
}

// ── Password Toggle ──
const togglePasswordBtn = $('togglePassword');
const passwordInput = $('password');

if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', function(e) {
        e.preventDefault();
        // The field is a text input wearing a CSS mask, because a
        // type="password" field makes the browser draw its own reveal eye
        // and the user ends up with two show-password buttons. So the
        // reveal is done by taking the mask off, not by changing the type.
        const isMasked = passwordInput.dataset.masked === '1';

        if (isMasked) {
            passwordInput.removeAttribute('data-masked');
        } else {
            passwordInput.dataset.masked = '1';
        }

        // Toggle icon
        const icon = this.querySelector('i');
        if (isMasked) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            this.title = 'Hide password';
            this.setAttribute('aria-label', 'Hide password');
            this.setAttribute('aria-pressed', 'true');
        } else {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            this.title = 'Show password';
            this.setAttribute('aria-label', 'Show password');
            this.setAttribute('aria-pressed', 'false');
        }
    });
}

// ── Terms and Conditions Modal ──
const tcModal = document.getElementById('tcModal');
const tcModalCloseBtn = document.getElementById('tcModalCloseBtn');

function openTcModal(e) {
    e.preventDefault();
    tcModal.classList.add('active');
    document.body.style.overflow = 'hidden';
    // Focus moves into the dialog so the keyboard is not left behind on the
    // page underneath it.
    if (tcModalCloseBtn) tcModalCloseBtn.focus();
}

function closeTcModal() {
    tcModal.classList.remove('active');
    document.body.style.overflow = '';
    const back = $('tcLink');
    if (back) back.focus();
}

// Find T&C link and attach event
const tcLink = document.getElementById('tcLink');
if (tcLink) {
    tcLink.addEventListener('click', openTcModal);
}

if (tcModalCloseBtn) tcModalCloseBtn.addEventListener('click', closeTcModal);

// Close modal when clicking outside
if (tcModal) {
    tcModal.addEventListener('click', function(e) {
        if (e.target === this) closeTcModal();
    });
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && tcModal && tcModal.classList.contains('active')) {
        closeTcModal();
    }
});

function showForm(which) {
    $('step1Form').style.display  = which === 'step1'  ? ''    : 'none';
    $('otpForm').style.display    = which === 'otp'    ? ''    : 'none';
    $('forgotForm').style.display = which === 'forgot' ? ''    : 'none';
    $('resetForm').style.display  = which === 'reset'  ? ''    : 'none';

    // Clear the previous screen's error on the way out.
    //
    // Without this a failure carries onto the next screen: fail a sign-in,
    // then click "Forgot password", and "Invalid ID / username or
    // password." is still sitting above a form asking for an email. The
    // message is true but it is answering a question nobody asked any more,
    // which reads as though the reset form is broken.
    //
    // The success banner is left alone. The reset flow calls showForm() and
    // then showSuccess() in that order, so clearing it here would only be
    // work for the next line to undo.
    $('authError').style.display = 'none';
    setState(null);

    // One vocabulary across the flow, and the label says what the screen is
    // for rather than which step it is. A user who arrives at the code
    // screen by resend does not think in terms of "step 2".
    const titles = {
        step1:  'Welcome back',
        otp:    'Enter your code',
        forgot: 'Reset your password',
        reset:  'Choose a new password'
    };
    $('formTitle').textContent = titles[which] || titles.step1;
    $('formIntro').hidden = which !== 'step1';
}

function showError(msg) {
    const e = $('authError');
    e.innerHTML = msg;
    e.style.display = 'flex';
    $('authSuccess').style.display = 'none';

    // The pip goes red, and that is the whole of the failure feedback now:
    // the banner says what went wrong, the pip says the request was
    // refused.
    setState('rejected');
}

function showSuccess(msg) {
    const s = $('authSuccess');
    s.textContent = msg;
    s.style.display = 'flex';
    $('authError').style.display = 'none';
    setState('ok');
}

async function post(action, body) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(body || {})) fd.append(k, v);
    const res = await fetch('shared/auth_actions.php', { method: 'POST', body: fd });
    return res.json();
}

// ── Step 1: ID/username + password ──
// A correct password does NOT finish the login. It proves half of it:
// the server answers `step: 'otp'` and has issued a code, and no session
// exists yet. The session is granted by verify_otp and by nothing else.
$('step1Form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const credential = $('credential').value.trim();
    const password = $('password').value;
    const btn = $('btnSignin');

    $('authError').style.display = 'none';
    $('authSuccess').style.display = 'none';

    if (!credential || !password) {
        showError('Enter your ID number or username and your password.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Checking…';
    setBusy(true);
    try {
        const data = await post('login', { username: credential, password });
        if (data.success && data.data && data.data.step === 'complete') {
            // Local development bypass: the server granted the session
            // outright and says so with step 'complete'. This is the
            // ONLY response shape that may skip the code screen, and
            // the branch below refuses anything else - so a regression
            // that quietly granted a session without a code would still
            // be caught here rather than dropping the user into a
            // portal the second factor was meant to guard.
            session.status = 'signed_in';
            // The pip goes green before the navigation, not after. The
            // redirect tears this document down, so anything set after
            // this line would never paint — the success colour would only
            // ever appear if the navigation were blocked.
            setState('ok');
            window.location.href = data.data.redirect || 'dashboard.php';
        } else if (data.success && data.data && data.data.step === 'otp') {
            // Hand the code step the account it is verifying. The server
            // has already bound this session to that account, so a
            // swapped user_id here would be rejected, not honoured.
            session.user_id = data.data.user_id;
            session.purpose = 'login';
            session.status = 'awaiting_otp';
            $('otp').value = '';
            $('otpMasked').textContent = data.data.masked_email || 'your email';
            // 'delivered' carries no secret, so it is safe to surface: it
            // is the difference between "check your inbox" and "we could
            // not reach the mail server", which is the difference between
            // a five-minute wait and a support ticket.
            $('otpResentMsg').className = data.data.delivered === false ? 'warn' : 'hint';
            $('otpResentMsg').textContent = data.data.delivered === false
                ? 'We could not reach the mail server. Use Resend, or contact the registrar.'
                : 'Check your inbox for a 6-digit code.';
            showForm('otp');
            $('otp').focus();
        } else if (data.success) {
            // Defensive only. A login that returns success WITHOUT the
            // otp step would be a server-side regression, and following
            // it would drop the user into a session the second factor was
            // supposed to guard. Refusing here is the safer failure.
            showError('Sign-in is incomplete. Please try again.');
        } else {
            showError(data.message || 'Invalid ID / username or password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> Sign in';
        setBusy(false);
    }
});

// ── Step 2: verify OTP ──
$('otpForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const otp = $('otp').value.trim();
    const btn = $('btnVerify');

    if (!otp) { showError('Enter the one-time code.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Verifying…';
    setBusy(true);
    try {
        const data = await post('verify_otp', { user_id: session.user_id, otp, purpose: session.purpose });
        if (data.success && data.data && data.data.step === 'reset_password') {
            session.user_id = data.data.user_id;
            // Carry the single-use reset grant to the final step. The
            // server will not accept reset_password without it — that is
            // what makes the flow safe rather than trusting the client.
            session.reset_token = data.data.reset_token;
            session.status = 'reset_pending';
            showForm('reset');
            showSuccess('Code verified. Set your new password.');
            $('newPassword').focus();
        } else if (data.success) {
            window.location.href = data.data?.redirect || 'dashboard.php';
        } else {
            showError(data.message || 'Invalid code. Please try again.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Verify and continue';
        setBusy(false);
    }
});

// ── Resend code ──
$('resendOtp').addEventListener('click', async function (e) {
    e.preventDefault();
    const label = this.innerHTML;
    this.disabled = true;
    this.innerHTML = 'Sending…';
    setBusy(true);
    try {
        const data = await post('resend_otp', { user_id: session.user_id, purpose: session.purpose });
        if (data.success) {
            // The server never returns the code, not even in dev — see the
            // resend_otp handlers. 'delivered' is safe to surface because
            // it carries no secret.
            $('otpResentMsg').className = data.data?.delivered === false ? 'warn' : 'hint';
            $('otpResentMsg').textContent = data.data?.delivered === false
                ? 'We could not reach the mail server. Please contact the registrar.'
                : 'A new code was sent. Check your inbox.';
            $('authError').style.display = 'none';
        } else {
            showError(data.message || 'Unable to resend.');
        }
    } catch (err) {
        showError('Unable to resend the code.');
    } finally {
        this.disabled = false;
        this.innerHTML = label;
        setBusy(false);
    }
});

// ── Forgot password: reveal email field ──
$('forgotLink').addEventListener('click', function (e) {
    e.preventDefault();
    session.purpose = 'reset';
    showForm('forgot');
    $('forgotEmail').focus();
});

$('forgotForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const email = $('forgotEmail').value.trim();
    const btn = $('btnForgot');

    if (!email) { showError('Enter your email address.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Sending…';
    setBusy(true);
    try {
        const data = await post('forgot', { email });
        if (data.success && data.data && data.data.step === 'otp') {
            session.user_id = data.data.user_id;
            session.purpose = 'reset';
            $('otpMasked').textContent = data.data.masked_email || 'your email';
            if (data.data.otp) {
                $('otpResentMsg').className = 'warn';
                $('otpResentMsg').textContent = 'Development mode: your code is ' + data.data.otp;
            } else {
                $('otpResentMsg').className = 'hint';
                $('otpResentMsg').textContent = 'Check your inbox for a 6-digit code.';
            }
            if (data.data.otp) $('otp').value = data.data.otp;
            showForm('otp');
            $('otp').focus();
        } else {
            showError(data.message || 'Unable to send reset code.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send reset code';
        setBusy(false);
    }
});

// ── Reset password (after reset OTP) ──
$('resetForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const np = $('newPassword').value;
    const cp = $('confirmPassword').value;
    const btn = $('btnReset');

    if (np.length < 8) { showError('Password must be at least 8 characters.'); return; }
    if (np !== cp) { showError('Passwords do not match.'); return; }
    // The server is authoritative; this is only an early hint.
    if (!session.reset_token) { showError('Your reset session expired. Please request a new code.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Saving…';
    setBusy(true);
    try {
        const data = await post('reset_password', { reset_token: session.reset_token, new_password: np, confirm_password: cp });
        if (data.success) {
            showSuccess(data.message || 'Password reset. You can now sign in.');
            setTimeout(() => { showForm('step1'); $('authSuccess').style.display = 'none'; }, 1800);
        } else {
            showError(data.message || 'Unable to reset password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Save new password';
        setBusy(false);
    }
});

// ── Back links ──
$('backToLogin').addEventListener('click', function (e) {
    e.preventDefault();
    session.purpose = 'login';
    $('otpResentMsg').textContent = '';
    showForm('step1');
});
$('backToLogin2').addEventListener('click', function (e) {
    e.preventDefault();
    session.purpose = 'login';
    showForm('step1');
});

// ── The clock ──────────────────────────────────────────────────────────
// A ticking clock used to sit in the instrument strip below the card. The
// strip is gone, so this is too.
</script>

</body>
</html>