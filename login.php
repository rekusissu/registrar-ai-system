<?php
// login.php - Phase 5 hardened sign-in:
//   Step 1: ID number / username + password
//   Step 2: one-time code (OTP)
//   Forgot password: reveal email → OTP → set new password
//   10-min lockout after 5 failed attempts (handled server-side).
//   CSRF tokens are enforced on every POST (see shared/csrf_guard.php).

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
require_once __DIR__ . '/shared/csrf_guard.php';

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
    <title>Sign In – BCP Registrar System</title>

    <meta name='csrf-token' content='<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>' />

    <meta name="loader-logo" content="assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />

    <link rel="stylesheet" href="css/auth.css" />
    <link rel="stylesheet" href="css/page-loader.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <style>
        /* Custom Checkbox Styling */
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .checkbox-wrapper input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            min-width: 18px;
            min-height: 18px;
            border: 2px solid #cbd5e1;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .checkbox-wrapper input[type="checkbox"]:hover {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .checkbox-wrapper input[type="checkbox"]:checked {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .checkbox-wrapper input[type="checkbox"]:checked::after {
            content: '✓';
            color: white;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .checkbox-wrapper label {
            margin: 0;
            font-size: 13px;
            color: #64748b;
            cursor: pointer;
            line-height: 1.4;
            user-select: none;
        }

        .checkbox-wrapper a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .checkbox-wrapper a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }
    </style>

    <script src='js/csrf.js'></script>
    <script src="js/page-loader.js"></script>
</head>
<body>

<div class="outer">
    <div class="card">
        <!-- Left Panel -->
        <div class="left">
            <div class="left-top">
                <img src="assets/images/BCP_LOGO.png" alt="BCP Logo" class="left-logo" />
                <p class="left-school">Bestlink College of the Philippines</p>
            </div>
            <div class="left-body">
                <h1>Registrar Management System</h1>
                <p class="subtitle">AI-Powered Records Management</p>
                <p><span>Registrar</span> is the process of managing student records, documents, RFID cards, and academic history.</p>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right">
            <img class="bcp-logo" src="assets/images/BCP_LOGO.png" alt="BCP Logo" />
            <h2 id="formTitle">Sign In Account</h2>

            <?php if ($timeout): ?>
                <div class="auth-error" id="timeoutMsg" style="display:block;">
                    <i class="fa-solid fa-clock"></i> Your session has expired. Please log in again.
                </div>
            <?php endif; ?>

            <div class="auth-error" id="authError" style="display:none;"></div>
            <div class="auth-success" id="authSuccess" style="display:none;"></div>

            <!-- STEP 1: ID / username + password -->
            <form id="step1Form" style="width:100%">
                <div class="form-group">
                    <label><i class="fa-solid fa-id-badge"></i> ID Number / Username</label>
                    <input type="text" id="credential" autocomplete="username" />
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Password</label>
                    <input type="password" id="password" autocomplete="current-password" />
                </div>

                <div class="checkbox-wrapper">
                    <input type="checkbox" id="agreeTerms" />
                    <label for="agreeTerms">
                        I agree to the <a href="terms-and-conditions.php" target="_blank">Terms and Conditions</a> *
                    </label>
                </div>

                <button type="submit" class="btn-signin" id="btnSignin">
                    Sign In <i class="fa-solid fa-arrow-right"></i>
                </button>

                <div class="register-link" style="text-align:center;">
                    <a href="#" id="forgotLink"><i class="fa-solid fa-circle-question"></i> Forgot password?</a>
                </div>
            </form>

            <!-- STEP 2: OTP -->
            <form id="otpForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    We sent a one-time code to <span id="otpMasked" style="color:#2563eb;font-weight:600;"></span>
                    <span id="otpResentMsg" style="display:block;color:#16a34a;"></span>
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-key"></i> One-Time Code</label>
                    <input type="text" id="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                           placeholder="6-digit code" style="letter-spacing:6px;text-align:center;font-size:1.2rem;font-weight:700;" />
                </div>

                <button type="submit" class="btn-signin" id="btnVerify">
                    Verify &amp; Continue <i class="fa-solid fa-check"></i>
                </button>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:.78rem;color:#64748b;">
                    <span class="resend" id="resendOtp"><i class="fa-solid fa-rotate"></i> Resend code</span>
                    <a href="#" id="backToLogin" style="color:#2563eb;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i> Back</a>
                </div>
            </form>

            <!-- FORGOT: email (this is where the email field appears) -->
            <form id="forgotForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    Enter the email registered to your account and we'll send a reset code.
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email</label>
                    <input type="email" id="forgotEmail" autocomplete="email" placeholder="you@bestlink.edu.ph" />
                </div>

                <button type="submit" class="btn-signin" id="btnForgot">
                    Send Reset Code <i class="fa-solid fa-paper-plane"></i>
                </button>

                <div style="text-align:center;margin-top:14px;font-size:.78rem;">
                    <a href="#" id="backToLogin2" style="color:#2563eb;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
                </div>
            </form>

            <!-- RESET PASSWORD: new password step -->
            <form id="resetForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    Set a new password for your account.
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> New Password</label>
                    <input type="password" id="newPassword" autocomplete="new-password" placeholder="At least 6 characters" />
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Confirm Password</label>
                    <input type="password" id="confirmPassword" autocomplete="new-password" placeholder="Repeat new password" />
                </div>

                <button type="submit" class="btn-signin" id="btnReset">
                    Save New Password <i class="fa-solid fa-check"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let session = { user_id: null, purpose: 'login', otp: null, status: 'idle' };

const $ = (id) => document.getElementById(id);

function showForm(which) {
    $('step1Form').style.display    = which === 'step1'   ? '' : 'none';
    $('otpForm').style.display      = which === 'otp'     ? '' : 'none';
    $('forgotForm').style.display   = which === 'forgot'  ? '' : 'none';
    $('resetForm').style.display    = which === 'reset'   ? '' : 'none';

    const titles = {
        step1:  'Sign In Account',
        otp:    'One-Time Code',
        forgot: 'Forgot Password',
        reset:  'Reset Password'
    };
    $('formTitle').textContent = titles[which] || 'Sign In Account';
}

function showError(msg) {
    const e = $('authError');
    e.innerHTML = msg;
    e.style.display = 'block';
    $('authSuccess').style.display = 'none';
}

function showSuccess(msg) {
    const s = $('authSuccess');
    s.textContent = msg;
    s.style.display = 'block';
    $('authError').style.display = 'none';
}

async function post(action, body) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(body || {})) fd.append(k, v);
    const res = await fetch('shared/auth_actions.php', { method: 'POST', body: fd });
    return res.json();
}

// ── Step 1: ID/username + password (Direct Login) ──
$('step1Form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const credential = $('credential').value.trim();
    const password = $('password').value;
    const agreeTerms = $('agreeTerms').checked;
    const btn = $('btnSignin');

    $('authError').style.display = 'none';
    $('authSuccess').style.display = 'none';

    if (!credential || !password) {
        showError('Please enter your ID number / username and password.');
        return;
    }

    if (!agreeTerms) {
        showError('You must agree to the Terms and Conditions to continue.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Signing in… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('login', { username: credential, password });
        if (data.success) {
            showSuccess('Login successful! Redirecting…');
            window.location.href = data.data?.redirect || 'dashboard.php';
        } else {
            showError(data.message || 'Invalid ID / username or password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
    }
});

// ── Step 2: verify OTP ──
$('otpForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const otp = $('otp').value.trim();
    const btn = $('btnVerify');

    if (!otp) { showError('Please enter the one-time code.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Verifying… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('verify_otp', { user_id: session.user_id, otp, purpose: session.purpose });
        if (data.success && data.data && data.data.step === 'reset_password') {
            session.user_id = data.data.user_id;
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
        btn.innerHTML = 'Verify & Continue <i class="fa-solid fa-check"></i>';
    }
});

// ── Resend code ──
$('resendOtp').addEventListener('click', async function (e) {
    e.preventDefault();
    this.innerHTML = 'Sending…';
    try {
        const data = await post('resend_otp', { user_id: session.user_id, purpose: session.purpose });
        if (data.success) {
            if (data.data?.otp) { session.otp = data.data.otp; $('otp').value = data.data.otp; }
            $('otpResentMsg').textContent = (data.data?.otp ? '⚠ Dev mode: your code is ' + data.data.otp : 'A new code was sent.');
            $('authError').style.display = 'none';
        } else {
            showError(data.message || 'Unable to resend.');
        }
    } catch (err) {
        showError('Unable to resend the code.');
    } finally {
        this.innerHTML = '<i class="fa-solid fa-rotate"></i> Resend code';
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

    if (!email) { showError('Please enter your email address.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Sending… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('forgot', { email });
        if (data.success && data.data && data.data.step === 'otp') {
            session.user_id = data.data.user_id;
            session.purpose = 'reset';
            $('otpMasked').textContent = data.data.masked_email || 'your email';
            $('otpResentMsg').textContent = data.data.otp ? ('⚠ Dev mode: your code is ' + data.data.otp) : '';
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
        btn.innerHTML = 'Send Reset Code <i class="fa-solid fa-paper-plane"></i>';
    }
});

// ── Reset password (after reset OTP) ──
$('resetForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const np = $('newPassword').value;
    const cp = $('confirmPassword').value;
    const btn = $('btnReset');

    if (np.length < 6) { showError('Password must be at least 6 characters.'); return; }
    if (np !== cp) { showError('Passwords do not match.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Saving… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('reset_password', { user_id: session.user_id, new_password: np, confirm_password: cp });
        if (data.success) {
            showSuccess('Password reset. You can now sign in.');
            setTimeout(() => { showForm('step1'); $('authSuccess').style.display = 'none'; }, 1800);
        } else {
            showError(data.message || 'Unable to reset password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save New Password <i class="fa-solid fa-check"></i>';
    }
});

// ── Back links ──
$('backToLogin').addEventListener('click', function (e) { e.preventDefault(); session.purpose = 'login'; showForm('step1'); });
$('backToLogin2').addEventListener('click', function (e) { e.preventDefault(); session.purpose = 'login'; showForm('step1'); });
</script>

</body>
</html>
