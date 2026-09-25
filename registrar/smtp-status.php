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

/** Which transport will actually carry a send right now. */
function smtpActiveTransport(): string {
    if (defined('BREVO_CONFIGURED') && BREVO_CONFIGURED) {
        $sender = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');
        return $sender !== '' ? 'Brevo API' : 'Brevo API (no sender address)';
    }
    if (defined('GMAIL_API_CONFIGURED') && GMAIL_API_CONFIGURED) return 'Gmail API';
    if (defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== '') {
        return class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'SMTP' : 'SMTP (PHPMailer not installed)';
    }
    return 'None';
}

/** Connect + authenticate only (no message is sent). */
function smtpRunConnectionTest(): array {
    $transport = smtpActiveTransport();
    // The old test only spoke SMTP, so a working Brevo-only setup reported
    // "SMTP is not configured" and looked broken when it was not.
    if (strpos($transport, 'Brevo') === 0) {
        $sender = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');
        if ($sender === '') {
            return ['ok' => false, 'message' => 'Brevo API key is present, but no sender address is set. Add MAIL_FROM to an address verified in Brevo.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'The PHP cURL extension is not enabled on this host. Brevo needs cURL — enable php_curl.'];
        }
        return ['ok' => true, 'message' => 'Email is sent via the Brevo API (not SMTP). No SMTP connection test applies. Sender: ' . $sender . '. Use "Send Test Email" to verify delivery.'];
    }
    if (strpos($transport, 'Gmail') === 0) {
        return ['ok' => true, 'message' => 'Email is sent via the Gmail API (not SMTP). No SMTP connection test applies. Use "Send Test Email" to verify delivery.'];
    }
    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return ['ok' => false, 'message' => 'No email transport is configured. Set BREVO_API_KEY (simplest) or the SMTP_* variables.'];
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
// The old check only looked at SMTP_HOST, so a Brevo-only deployment
// reported "Not found (falling back to empty)" while mail was in fact
// configured and working. Probe every transport that can enable email.
$brevoSet = env('BREVO_API_KEY') !== '' && env('BREVO_API_KEY') !== false;
$envSet   = $brevoSet || (env('SMTP_HOST') !== '' && env('SMTP_HOST') !== false);
$fileSet  = is_file(__DIR__ . '/../shared/email_secret.local');
$credSource = $envSet ? 'Environment variables' : ($fileSet ? 'shared/email_secret.local' : 'Not found (falling back to empty)');
$configured = defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED;
$hasOpenssl = extension_loaded('openssl');
$hasCurl    = function_exists('curl_init');
$transport  = smtpActiveTransport();
$mailFrom   = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');

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
                        <tr><td style="color:#64748b;">Active transport</td><td><strong><?= htmlspecialchars($transport) ?></strong></td></tr>
                        <tr><td style="color:#64748b;">Sender address</td><td><?= $mailFrom !== ''
                                ? htmlspecialchars($mailFrom) . ($transport === 'Brevo API' ? ' <span style="color:#64748b;">(must be verified in Brevo)</span>' : '')
                                : '<span style="color:#dc2626;font-weight:600;">Not set — Brevo will reject every send. Set MAIL_FROM.</span>' ?></td></tr>
                        <tr><td style="color:#64748b;">cURL extension</td><td><?= $hasCurl ? 'Enabled' : '<span style="color:#dc2626;font-weight:600;">Disabled — the Brevo transport cannot work without it</span>' ?></td></tr>
                        <tr><td style="color:#64748b;">SMTP user</td><td><?= htmlspecialchars(defined('SMTP_USER') ? SMTP_USER : '') ?></td></tr>
                        <tr><td style="color:#64748b;">MAIL_FROM</td><td><?= htmlspecialchars(defined('MAIL_FROM') ? MAIL_FROM : '') ?></td></tr>
                        <tr><td style="color:#64748b;">MAIL_FROM_NAME</td><td><?= htmlspecialchars(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '') ?></td></tr>
                        <tr><td style="color:#64748b;">PHPMailer (vendor)</td><td><?= $hasPhpMailer ? 'Installed' : 'Missing' ?></td></tr>
                        <tr><td style="color:#64748b;">OpenSSL extension</td><td><?= $hasOpenssl ? 'Enabled' : 'Disabled (STARTTLS needs it)' ?></td></tr>
                        <tr><td style="color:#64748b;">Brevo API</td><td><?= defined('BREVO_CONFIGURED') && BREVO_CONFIGURED ? '<span style="color:#16a34a;font-weight:600;">Active — emails sent via Brevo</span>' : '<span style="color:#94a3b8;">Not configured</span>' ?></td></tr>
                        <tr><td style="color:#64748b;">Gmail API (OAuth2)</td><td><?= defined('GMAIL_API_CONFIGURED') && GMAIL_API_CONFIGURED ? '<span style="color:#16a34a;font-weight:600;">Active — emails sent via Gmail API</span>' : '<span style="color:#94a3b8;">Not configured</span>' ?></td></tr>
                        <tr><td style="color:#64748b;">Gmail API Client ID</td><td><?= defined('GMAIL_API_CLIENT_ID') && GMAIL_API_CLIENT_ID !== '' ? 'Set' : 'Not set' ?></td></tr>
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