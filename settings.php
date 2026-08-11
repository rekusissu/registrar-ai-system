<?php
// ============================================================
//  SETTINGS.PHP  (Root)
//  System settings page — update own profile + change password.
//  Admin-only. Wired to api/settings.php.
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
requireRole('admin');

require_once __DIR__ . '/shared/database.php';
$db = Database::getInstance();
$me = $db->fetchOne("SELECT id, email, full_name, role, created_at, updated_at FROM users WHERE id = ?", [$_SESSION['user_id']]);

$page_title = 'Settings';
$page_description = 'System Settings';
$APP_ROOT = './';
$ACTIVE_NAV = 'settings';

include 'includes/header.php';
include 'includes/sidebar.php';

$userName = $me['full_name'] ?? $_SESSION['full_name'] ?? 'Admin User';
$userEmail = $me['email'] ?? '';
$userRole = $me['role'] ?? $_SESSION['role'] ?? 'Admin';
?>
<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header">
        <div class="title">
            <h1>Settings</h1>
            <p>System configuration and preferences</p>
        </div>
        <div class="user-info">
            <span class="role"><?= htmlspecialchars(ucfirst($userRole)) ?></span>
            <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
        </div>
    </header>

    <!-- Settings Content -->
    <div class="settings-grid" style="display: grid; grid-template-columns: 1fr; gap: 20px; max-width: 800px;">

        <!-- Profile Settings -->
        <div class="panel">
            <div class="panel-toolbar">
                <div><h3 class="card-title" style="margin:0;"><i class="fas fa-user" style="color:var(--primary-500);"></i> Profile Settings</h3>
                <p class="card-subtitle" style="margin:4px 0 0;font-size:13px;color:#64748b;">Update your personal information</p></div>
            </div>
            <form id="profileForm" style="margin-top:16px;">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="profileName" class="form-control" value="<?= htmlspecialchars($userName) ?>" required />
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="profileEmail" class="form-control" value="<?= htmlspecialchars($userEmail) ?>" disabled />
                    <small style="color:#94a3b8;font-size:12px;">Email cannot be changed here.</small>
                </div>
                <button type="submit" class="btn btn-primary" id="profileBtn"><i class="fas fa-save"></i> Update Profile</button>
            </form>
        </div>

        <!-- Security Settings -->
        <div class="panel">
            <div class="panel-toolbar">
                <div><h3 class="card-title" style="margin:0;"><i class="fas fa-shield-halved" style="color:var(--primary-500);"></i> Security</h3>
                <p class="card-subtitle" style="margin:4px 0 0;font-size:13px;color:#64748b;">Change your password</p></div>
            </div>
            <form id="passwordForm" style="margin-top:16px;">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" id="currentPassword" class="form-control" placeholder="Enter current password" required />
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" id="newPassword" class="form-control" placeholder="Enter new password (min 6 characters)" required minlength="6" />
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required minlength="6" />
                </div>
                <button type="submit" class="btn btn-primary" id="passwordBtn"><i class="fas fa-key"></i> Change Password</button>
            </form>
        </div>

        <!-- System Information -->
        <div class="panel">
            <div class="panel-toolbar">
                <div><h3 class="card-title" style="margin:0;"><i class="fas fa-circle-info" style="color:var(--primary-500);"></i> System Information</h3>
                <p class="card-subtitle" style="margin:4px 0 0;font-size:13px;color:#64748b;">Application details and version</p></div>
            </div>
            <div style="margin-top:16px;">
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span style="color:#64748b;">Application Name</span>
                    <span style="font-weight:500;">BCP Registrar System</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span style="color:#64748b;">Version</span>
                    <span style="font-weight:500;">1.0.0</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span style="color:#64748b;">Environment</span>
                    <span style="font-weight:500;"><?= defined('APP_ENV') ? APP_ENV : 'Development' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;">
                    <span style="color:#64748b;">PHP Version</span>
                    <span style="font-weight:500;"><?= phpversion() ?></span>
                </div>
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

document.getElementById('profileForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('profileBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('api/settings.php', 'PUT', { section: 'profile', full_name: document.getElementById('profileName').value.trim() });
        if (d.success) { showToast('Profile updated.', 'success'); }
        else { showToast(d.message, 'error'); }
    } catch(err) { showToast('Network error.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Update Profile';
});

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const cur = document.getElementById('currentPassword').value;
    const nw  = document.getElementById('newPassword').value;
    const cf  = document.getElementById('confirmPassword').value;
    if (nw !== cf) { showToast('New passwords do not match.', 'error'); return; }
    const btn = document.getElementById('passwordBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('api/settings.php', 'PUT', { section: 'password', current_password: cur, new_password: nw });
        if (d.success) {
            showToast('Password updated. Use it on your next login.', 'success');
            this.reset();
        } else {
            showToast(d.message, 'error');
        }
    } catch(err) { showToast('Network error.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Change Password';
});
</script>

<?php include 'includes/footer.php'; ?>
