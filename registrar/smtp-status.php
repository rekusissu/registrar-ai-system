<?php
// ============================================================
//  REGISTRAR/SMTP-STATUS.PHP
//  SMTP diagnostic page (admin only). Shows whether email is
//  configured, where the credentials come from, and offers two
//  live tests: SMTP connection + a real test email. Passwords
//  are never printed.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$hasVendor   = is_file(__DIR__ . '/../vendor/autoload.php');
$hasPhpMailer = false;
if ($hasVendor) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $hasPhpMailer = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

/** Never print the password — scrub it from error text. */
function smtpRedact(string $text): string {
    return defined('SMTP_PASS') && SMTP_PASS !== '' ? str_replace(SMTP_PASS, '********', $text) : $text;
}

/** Connect + authenticate only (no message is sent). */
function smtpRunConnectionTest(): array {
    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return ['ok' => false, 'message' => 'SMTP is not configured (SMTP_HOST is empty).'];
    }
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return ['ok' => false, 'message' => 'PHPMailer is not installed (vendor/autoload.php missing).'];
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 15;
        $ok = $mail->smtpConnect();
        if ($ok) {
            $mail->smtpClose();
            return ['ok' => true, 'message' => 'Connected and authenticated to ' . SMTP_HOST . ':' . SMTP_PORT . ' successfully.'];
        }
        return ['ok' => false, 'message' => smtpRedact('Connection/auth failed: ' . $mail->ErrorInfo)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => smtpRedact('Exception: ' . $e->getMessage())];
    }
}

/** Send a real test email to the configured MAIL_FROM address. */
function smtpRunSendTest(): array {
    if (!defined('EMAIL_CONFIGURED') || !EMAIL_CONFIGURED) {
        return ['ok' => false, 'message' => 'Email is not configured — add SMTP credentials via env vars or shared/email_secret.local.'];
    }
    if (!function_exists('sendEmail')) {
        $mailClient = __DIR__ . '/../shared/mail_client.php';
        if (is_file($mailClient)) require_once $mailClient;
    }
    if (!function_exists('sendEmail')) {
        return ['ok' => false, 'message' => 'shared/mail_client.php could not be loaded.'];
    }
    $to = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : (SMTP_USER ?? '');
    if ($to === '') {
        return ['ok' => false, 'message' => 'No sender address configured (MAIL_FROM / SMTP_USER).'];
    }
    $ok = sendEmail(
        ['email' => $to, 'name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Registrar'],
        'Registrar SMTP Test - ' . date('Y-m-d H:i'),
        '<p>This is a test email from the Registrar system to verify SMTP delivery.</p>'
    );
    return $ok
        ? ['ok' => true, 'message' => 'Test email sent to ' . htmlspecialchars($to) . '. Check the inbox (and spam folder).']
        : ['ok' => false, 'message' => 'Test email could not be sent. See the PHP error log for details (lines starting with "mail:").'];
}

// ---- handle POST actions (CSRF enforced) ----
$actionResult = null;
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'test_connection') {
        $actionResult = smtpRunConnectionTest();
    } elseif ($action === 'test_send') {
        $actionResult = smtpRunSendTest();
    } else {
        $actionResult = ['ok' => false, 'message' => 'Unknown action.'];
    }
}

// ---- diagnostics ----
$envSet  = getenv('SMTP_HOST') !== false && getenv('SMTP_HOST') !== '';
$fileSet = is_file(__DIR__ . '/../shared/email_secret.local');
$credSource = $envSet ? 'Environment variables (getenv)' : ($fileSet ? 'shared/email_secret.local' : 'Not found (falling back to empty)');
$configured = defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED;
$hasOpenssl = extension_loaded('openssl');

$page_title = 'SMTP Status';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'smtp';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title">
                <h1>SMTP Status</h1>
                <p>Diagnose email/SMTP configuration and delivery</p>
            </div>
        </header>

        <?php if ($actionResult): ?>
        <div class="panel" style="margin-bottom:18px;border-left:4px solid <?= $actionResult['ok'] ? '#16a34a' : '#dc2626' ?>;">
            <div style="display:flex;gap:10px;align-items:center;padding:14px 16px;">
                <i class="fas <?= $actionResult['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="font-size:18px;color:<?= $actionResult['ok'] ? '#16a34a' : '#dc2626' ?>;"></i>
                <div>
                    <div style="font-weight:600;color:#0f172a;"><?= $actionResult['ok'] ? 'Success' : 'Failed' ?></div>
                    <div style="font-size:13px;color:#475569;"><?= htmlspecialchars($actionResult['message']) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
            <div class="stat-card">
                <div class="stat-top"><div class="stat-icon <?= $configured ? 'green' : 'red' ?>"><i class="fas fa-envelope"></i></div></div>
                <div class="stat-number"><?= $configured ? 'Configured' : 'Not configured' ?></div>
                <div class="stat-label">EMAIL_CONFIGURED</div>
            </div>
            <div class="stat-card">
                <div class="stat-top"><div class="stat-icon blue"><i class="fas fa-server"></i></div></div>
                <div class="stat-number" style="font-size:18px;"><?= htmlspecialchars(defined('SMTP_HOST') ? SMTP_HOST : '') ?></div>
                <div class="stat-label">Host : <?= intval(SMTP_PORT ?? 587) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-top"><div class="stat-icon purple"><i class="fas fa-key"></i></div></div>
                <div class="stat-number" style="font-size:18px;">
                    <?= defined('SMTP_PASS') && SMTP_PASS !== '' ? 'Set (' . strlen(SMTP_PASS) . ' chars)' : 'Missing' ?>
                </div>
                <div class="stat-label">Password (never shown)</div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:18px;">
            <div class="panel-title" style="padding:14px 16px;"><i class="fas fa-circle-info" style="color:#2563eb;"></i> Configuration</div>
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                        <tr><td style="width:240px;color:#64748b;">Credential source</td><td><?= htmlspecialchars($credSource) ?></td></tr>
                        <tr><td style="color:#64748b;">SMTP user</td><td><?= htmlspecialchars(defined('SMTP_USER') ? SMTP_USER : '') ?></td></tr>
                        <tr><td style="color:#64748b;">MAIL_FROM</td><td><?= htmlspecialchars(defined('MAIL_FROM') ? MAIL_FROM : '') ?></td></tr>
                        <tr><td style="color:#64748b;">MAIL_FROM_NAME</td><td><?= htmlspecialchars(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '') ?></td></tr>
                        <tr><td style="color:#64748b;">PHPMailer (vendor)</td><td><?= $hasPhpMailer ? 'Installed' : 'Missing' ?></td></tr>
                        <tr><td style="color:#64748b;">OpenSSL extension</td><td><?= $hasOpenssl ? 'Enabled' : 'Disabled (STARTTLS needs it)' ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title" style="padding:14px 16px;"><i class="fas fa-vial-circle-check" style="color:#2563eb;"></i> Test SMTP</div>
            <div style="padding:16px;">
                <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="action" value="test_connection" class="btn btn-secondary"><i class="fas fa-plug"></i> Test Connection</button>
                    <button type="submit" name="action" value="test_send" class="btn btn-primary" <?= $configured ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> Send Test Email</button>
                    <?php if (!$configured): ?>
                    <span style="font-size:12px;color:#b45309;"><i class="fas fa-triangle-exclamation"></i> Add SMTP credentials first (env vars or shared/email_secret.local), then reload.</span>
                    <?php endif; ?>
                </form>
                <p style="font-size:12px;color:#94a3b8;margin-top:12px;">"Test Connection" only connects and authenticates. "Send Test Email" delivers one message to <?= htmlspecialchars(defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'MAIL_FROM') ?>.</p>
            </div>
        </div>

    </div>
</main>
<?php include '../includes/footer.php'; ?>