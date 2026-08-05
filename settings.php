<?php
// ============================================================
//  SETTINGS.PHP  (Root)
//  System settings page for the Registrar System.
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

// Check if logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// Settings is admin-only
requireRole('admin');

// Set page variables
$page_title = 'Settings';
$page_description = 'System Settings';
$APP_ROOT = './';
$ACTIVE_NAV = 'settings';

// Include header
include 'includes/header.php';

// Include sidebar
include 'includes/sidebar.php';

// User data
$userName = $_SESSION['full_name'] ?? 'Admin User';
$userRole = $_SESSION['role'] ?? 'Registrar';
?>

<!-- MAIN CONTENT -->
<main class="main">

    <!-- Header -->
    <header class="header">
        <div class="title">
            <h1>Settings</h1>
            <p>System configuration and preferences</p>
        </div>
        <div class="user-info">
            <span class="role"><?= htmlspecialchars($userRole) ?></span>
            <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
        </div>
    </header>

    <!-- Settings Content -->
    <div class="settings-grid" style="display: grid; grid-template-columns: 1fr; gap: 20px; max-width: 800px;">

        <!-- Profile Settings -->
        <div class="card">
            <h3 class="card-title"><i class="fas fa-user" style="color: var(--primary-500);"></i> Profile Settings</h3>
            <p class="card-subtitle">Update your personal information</p>
            <form style="margin-top: 16px;">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($userName) ?>" />
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" class="form-control" value="admin@bestlink.edu.ph" />
                </div>
                <button type="submit" class="btn btn-primary">Update Profile</button>
            </form>
        </div>

        <!-- Security Settings -->
        <div class="card">
            <h3 class="card-title"><i class="fas fa-shield-alt" style="color: var(--primary-500);"></i> Security</h3>
            <p class="card-subtitle">Change your password</p>
            <form style="margin-top: 16px;">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" class="form-control" placeholder="Enter current password" />
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" class="form-control" placeholder="Enter new password" />
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" class="form-control" placeholder="Confirm new password" />
                </div>
                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>

        <!-- System Information -->
        <div class="card">
            <h3 class="card-title"><i class="fas fa-info-circle" style="color: var(--primary-500);"></i> System Information</h3>
            <p class="card-subtitle">Application details and version</p>
            <div style="margin-top: 16px;">
                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                    <span style="color: #64748b;">Application Name</span>
                    <span style="font-weight: 500;">BCP Registrar System</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                    <span style="color: #64748b;">Version</span>
                    <span style="font-weight: 500;">1.0.0</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                    <span style="color: #64748b;">Environment</span>
                    <span style="font-weight: 500;"><?= APP_ENV ?? 'Development' ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                    <span style="color: #64748b;">PHP Version</span>
                    <span style="font-weight: 500;"><?= phpversion() ?></span>
                </div>
            </div>
        </div>

    </div>

</main>

<?php
// Include footer
include 'includes/footer.php';
?>