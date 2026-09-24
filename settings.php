<?php
// ============================================================
//  SETTINGS.PHP  (Root)
//  Account settings for every logged-in role:
//    - Staff roles: view profile info + change password
//    - Students:    change password only (their record lives in
//                   student/profile.php)
//  Wired to api/settings.php (authenticated, role-agnostic).
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
requireLogin();

require_once __DIR__ . '/shared/database.php';
$db = Database::getInstance();
$me = $db->fetchOne("SELECT id, email, full_name, role, username, created_at, updated_at FROM users WHERE id = ?", [$_SESSION['user_id']]);

$currentRole = $me['role'] ?? $_SESSION['role'] ?? '';
$isStudent   = ($currentRole === 'student');

$page_title = 'Settings';
$page_description = 'Account Settings';
$APP_ROOT = './';
$ACTIVE_NAV = 'settings';
if ($isStudent) { $extra_css = ['student.css']; }

include 'includes/header.php';
include 'includes/sidebar.php';

$userName  = $me['full_name'] ?? $_SESSION['full_name'] ?? 'User';
$userRole  = $currentRole !== '' ? $currentRole : 'Staff';
?>
<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header">
        <div class="title">
            <h1>Settings</h1>
            <p>Manage your account, security, and sign-in preferences</p>
        </div>
    </header>

    <style>
    .settings-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:20px; width:100%; }
    .settings-grid .is-wide { grid-column:1 / -1; }
    .settings-grid .panel { position:relative; overflow:hidden; }
    .settings-grid .panel::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
    .settings-grid .accent-blue::before { background:#2563eb; }
    .settings-grid .accent-emerald::before { background:#059669; }
    .settings-grid .accent-slate::before { background:#64748b; }
    .settings-grid .panel { margin:0; }
    .settings-card-header { display:flex; align-items:center; gap:14px; }
    .settings-card-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
    .settings-card-icon.blue    { background:#eff6ff; color:#2563eb; }
    .settings-card-icon.emerald { background:#ecfdf5; color:#059669; }
    .settings-card-icon.slate   { background:#f1f5f9; color:#475569; }
    .pw-field-wrap { position:relative; }
    .pw-field-wrap .form-control { padding-right:40px; }
    .pw-toggle { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px; padding:6px; line-height:1; }
    .pw-toggle:hover { color:#2563eb; }
    .strength-bar { display:flex; gap:4px; margin-top:8px; }
    .strength-bar i { height:4px; flex:1; border-radius:3px; background:#e2e8f0; transition:background .2s ease; }
    .field-error { font-size:12px; color:#dc2626; margin-top:6px; font-weight:600; display:none; }
    .sys-row { display:flex; justify-content:space-between; align-items:center; padding:10px 2px; border-bottom:1px solid #f1f5f9; font-size:13px; }
    .sys-row:last-child { border-bottom:none; }
    .sys-row span:first-child { color:#64748b; }
    .sys-row span:last-child { font-weight:600; color:#0f172a; }
    @media (max-width: 900px) { .settings-grid { grid-template-columns:1fr; } .settings-grid .is-wide { grid-column:auto; } }
    </style>

    <div class="settings-grid">

        <?php if (!$isStudent): ?>
        <!-- Profile Settings (display-only) -->
        <div class="panel accent-blue" style="padding:24px;">
            <div class="settings-card-header">
                <div class="settings-card-icon blue"><i class="fas fa-user"></i></div>
                <div>
                    <h3 class="card-title" style="margin:0;font-size:15px;">Profile Settings</h3>
                    <p class="card-subtitle" style="margin:2px 0 0;font-size:12.5px;color:#64748b;">Your account information</p>
                </div>
            </div>
            <div style="margin-top:18px;">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($userName) ?>" disabled />
                </div>
                <div class="form-group">
                    <label>Staff ID Number</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($me['username'] ?? 'N/A') ?>" disabled />
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Security / Change Password -->
        <div class="panel accent-emerald" style="padding:24px;">
            <div class="settings-card-header">
                <div class="settings-card-icon emerald"><i class="fas fa-shield-halved"></i></div>
                <div>
                    <h3 class="card-title" style="margin:0;font-size:15px;">Change Password</h3>
                    <p class="card-subtitle" style="margin:2px 0 0;font-size:12.5px;color:#64748b;">Keep your account secure - at least 6 characters</p>
                </div>
            </div>
            <form id="passwordForm" style="margin-top:18px;">
                <div class="form-group">
                    <label>Current Password</label>
                    <div class="pw-field-wrap">
                        <input type="password" id="currentPassword" class="form-control" placeholder="Enter current password" required autocomplete="current-password" />
                        <button type="button" class="pw-toggle" data-target="currentPassword" title="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <div class="pw-field-wrap">
                        <input type="password" id="newPassword" class="form-control" placeholder="Enter new password" required minlength="6" autocomplete="new-password" />
                        <button type="button" class="pw-toggle" data-target="newPassword" title="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <div class="strength-bar" id="strengthBar"><i></i><i></i><i></i><i></i></div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="pw-field-wrap">
                        <input type="password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required autocomplete="new-password" />
                        <button type="button" class="pw-toggle" data-target="confirmPassword" title="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <div class="field-error" id="pwMismatch">Passwords do not match.</div>
                </div>
                <button type="submit" class="btn btn-primary" id="passwordBtn" style="margin-top:4px;"><i class="fas fa-key"></i> Change Password</button>
            </form>
        </div>

        <!-- System Information -->
        <div class="panel accent-slate<?= $isStudent ? '' : ' is-wide' ?>" style="padding:24px;">
            <div class="settings-card-header">
                <div class="settings-card-icon slate"><i class="fas fa-circle-info"></i></div>
                <div>
                    <h3 class="card-title" style="margin:0;font-size:15px;">System Information</h3>
                    <p class="card-subtitle" style="margin:2px 0 0;font-size:12.5px;color:#64748b;">Application details and version</p>
                </div>
            </div>
            <div style="margin-top:16px;">
                <div class="sys-row"><span>Application Name</span><span>BCP Registrar System</span></div>
                <div class="sys-row"><span>Version</span><span>1.0.0</span></div>
                <div class="sys-row"><span>Environment</span><span><?= defined('APP_ENV') ? htmlspecialchars(APP_ENV) : 'Development' ?></span></div>
                <div class="sys-row"><span>PHP Version</span><span><?= htmlspecialchars(phpversion()) ?></span></div>
            </div>
        </div>

    </div>

</div>
</main>

<script>
async function submitJson(url, method, body) {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await res.json();
}

// --- Password show/hide toggles ---
document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const icon = btn.querySelector('i');
        if (!input || !icon) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.classList.toggle('fa-eye', !show);
        icon.classList.toggle('fa-eye-slash', show);
    });
});

// --- Live password strength meter (0-4) ---
const strengthBar = document.getElementById('strengthBar');
const strengthColors = ['#dc2626', '#f59e0b', '#2563eb', '#059669'];
function refreshStrength() {
    const v = document.getElementById('newPassword').value;
    let score = 0;
    if (v.length >= 6) score++;
    if (v.length >= 10) score++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
    if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) score++;
    [...strengthBar.children].forEach((bar, i) => {
        bar.style.background = i < score ? strengthColors[score - 1] : '#e2e8f0';
    });
}
document.getElementById('newPassword').addEventListener('input', () => { refreshStrength(); if (confirmInput.value !== '') validateMatch(); });

// --- Confirm-match validation ---
const confirmInput = document.getElementById('confirmPassword');
const mismatchEl = document.getElementById('pwMismatch');
function validateMatch() {
    const nw = document.getElementById('newPassword').value;
    const ok = confirmInput.value === '' || nw === confirmInput.value;
    mismatchEl.style.display = ok ? 'none' : 'block';
    return ok;
}
confirmInput.addEventListener('input', validateMatch);

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!validateMatch()) { showToast('Passwords do not match.', 'error'); return; }
    const btn = document.getElementById('passwordBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('api/settings.php', 'PUT', {
            section: 'password',
            current_password: document.getElementById('currentPassword').value,
            new_password: document.getElementById('newPassword').value
        });
        if (d.success) {
            showToast('Password updated. Use it on your next login.', 'success');
            this.reset();
            refreshStrength();
            mismatchEl.style.display = 'none';
        } else {
            showToast(d.message || 'Failed to update password.', 'error');
        }
    } catch(err) { showToast('Network error.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Change Password';
});
</script>

<?php include 'includes/footer.php'; ?>