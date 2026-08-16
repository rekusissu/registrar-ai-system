<?php
// login.php - Updated to use email instead of username

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
require_once __DIR__ . '/shared/csrf_guard.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
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
    <meta name='csrf-token' content='<?= csrfToken() ?>' />
    <script src='js/csrf.js'></script>
    
    <meta name="loader-logo" content="assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />
    
    <link rel="stylesheet" href="css/auth.css" />
    <link rel="stylesheet" href="css/page-loader.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    
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
            <h2>Sign In Account</h2>
            
            <?php if ($timeout): ?>
                <div class="auth-error" style="display:block;">
                    <i class="fa-solid fa-clock"></i> Your session has expired. Please log in again.
                </div>
            <?php endif; ?>

            <div class="auth-error" id="authError" style="display:none;"></div>

            <form id="signinForm" style="width:100%">
                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email</label>
                    <input type="email" id="email" autocomplete="email" />
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Password</label>
                    <input type="password" id="password" autocomplete="current-password" />
                </div>

                <button type="submit" class="btn-signin" id="btnSignin">
                    Sign In <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('signinForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const errorBox = document.getElementById('authError');
    const btn = document.getElementById('btnSignin');

    errorBox.style.display = 'none';

    if (!email || !password) {
        errorBox.textContent = 'Please enter your email and password.';
        errorBox.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Signing in… <i class="fa-solid fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.set('action', 'login');
    fd.set('email', email);
    fd.set('password', password);

    try {
        const response = await fetch('shared/auth_actions.php', {
            method: 'POST',
            body: fd
        });
        const data = await response.json();

        if (data.success) {
            window.location.href = 'dashboard.php';
        } else {
            errorBox.textContent = data.message || 'Invalid email or password.';
            errorBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
        }
    } catch (error) {
        errorBox.textContent = 'Request failed. Please try again.';
        errorBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
    }
});
</script>

</body>
</html>
