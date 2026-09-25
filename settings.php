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
$body_page = 'settings';   // scopes the registrar-blue layer below
if ($isStudent) { $extra_css = ['student.css']; }

include 'includes/header.php';
include 'includes/sidebar.php';

$userName  = $me['full_name'] ?? $_SESSION['full_name'] ?? 'User';
$userRole  = $currentRole !== '' ? $currentRole : 'Staff';
$userEmail = $me['email'] ?? '';
// Friendly role label. 'registrar' is the real value in this system and is
// not a word to show a user verbatim.
$roleLabels = [
    'admin' => 'Administrator', 'registrar' => 'Registrar',
    'staff' => 'Staff',        'student'  => 'Student',
];
$roleLabel = $roleLabels[strtolower($userRole)] ?? ucfirst($userRole);
// Initials for the identity avatar; falls back to a single letter.
$initials = '';
foreach (preg_split('/\s+/', trim($userName)) as $part) {
    if ($part !== '') { $initials .= strtoupper(mb_substr($part, 0, 1)); }
}
$initials = substr($initials, 0, 2) ?: '?';
$fmtDate = function ($v) {
    if (empty($v)) return null;
    $t = strtotime($v);
    return $t ? date('M j, Y', $t) : null;
};
$memberSince = $fmtDate($me['created_at'] ?? null);
$lastUpdated = $fmtDate($me['updated_at'] ?? null);
?>
<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header set-header">
        <div>
            <div class="set-kicker"><i class="fas fa-user-gear"></i> Your account</div>
            <h1>Settings</h1>
            <p><?= $isStudent
                    ? 'Your sign-in details and password. Your student record is managed from your profile page.'
                    : 'Your sign-in details and password. Name, role and staff ID are set by an administrator.' ?></p>
        </div>
    </header>

    <style>
    /* ============================================================
       ACCOUNT SETTINGS — registrar-blue layer.
       Scoped to body[data-page="settings"] so it cannot leak into
       any other page that also renders .panel / .card-title.

       The identity card is the loud element: on an account page the
       person is the subject, so the name leads and the facts sit
       beneath it. Read-only values are shown as text, not disabled
       inputs — a disabled field reads as "broken", and these are
       values rather than a failed edit.
       ============================================================ */
    body[data-page="settings"]{background:#f5f7fb;color:#0f172a}
    body[data-page="settings"] .dashboard-main{background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%)}
    body[data-page="settings"] .set-header{
        margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;
        background:linear-gradient(120deg,#eff6ff,#fff 68%);
        box-shadow:0 10px 30px rgba(37,99,235,.08);
        /* Override the base .header flex rule so this fills the row
           instead of collapsing to its text width. */
        display:block;width:100%;
    }
    body[data-page="settings"] .set-header h1{
        margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554;
    }
    body[data-page="settings"] .set-header p{
        margin:0;max-width:620px;font-size:12.5px;line-height:1.5;color:#64748b;
    }
    body[data-page="settings"] .set-kicker{
        display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;
        font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
    }
    /* Two real columns, not a full-width card over two half-width ones.
       The old version left the entire area beside System Information
       empty. Here System Information spans both rows on the right, so
       the grid fills the container and the two left cards size to the
       space they actually share.

       Students have no identity card, so they get a one-row template
       instead — a fixed "ident" row would leave a blank gap. */
    .set-grid{
        display:grid;width:100%;gap:16px;align-items:stretch;
        grid-template-columns:1.4fr 1fr;
    }
    body[data-page="settings"] .set-grid.has-ident{grid-template-areas:"ident sys" "pw sys"}
    body[data-page="settings"] .set-grid.no-ident {grid-template-areas:"pw sys"}
    .set-grid .is-wide { grid-column:1 / -1; grid-area:ident; }
    .set-card-area-pw { grid-area:pw; }
    .set-card-area-sys{ grid-area:sys; }
    /* Cards stretch to the full height of their row. */
    body[data-page="settings"] .set-card{display:flex;flex-direction:column}
    body[data-page="settings"] .set-card form{display:flex;flex-direction:column;flex:1}
    body[data-page="settings"] .set-card form .form-group:last-of-type{margin-bottom:auto}
    body[data-page="settings"] .set-submit{margin-top:14px}
    body[data-page="settings"] .set-card{
        position:relative;background:#fff;border:1px solid #dbeafe;border-radius:16px;
        box-shadow:0 6px 22px rgba(15,23,42,.04);padding:20px;overflow:hidden;margin:0;
    }
    body[data-page="settings"] .set-card::before{
        content:'';position:absolute;top:0;left:0;right:0;height:3px;background:#dbeafe;
    }
    body[data-page="settings"] .set-card.accent-blue::before{background:#1d4ed8}
    body[data-page="settings"] .set-card.accent-emerald::before{background:#059669}
    body[data-page="settings"] .set-card.accent-slate::before{background:#64748b}
    body[data-page="settings"] .set-card-head{display:flex;align-items:flex-start;gap:13px}
    body[data-page="settings"] .set-card-icon{
        width:40px;height:40px;border-radius:11px;display:flex;align-items:center;
        justify-content:center;font-size:16px;flex-shrink:0;
    }
    body[data-page="settings"] .set-card-icon.blue    { background:#eff6ff; color:#2563eb; }
    body[data-page="settings"] .set-card-icon.emerald { background:#ecfdf5; color:#059669; }
    body[data-page="settings"] .set-card-icon.slate   { background:#f1f5f9; color:#475569; }
    body[data-page="settings"] .set-card-title{margin:0;font-size:15px;font-weight:800;color:#0d1b2e;letter-spacing:-.2px}
    body[data-page="settings"] .set-card-sub{margin:3px 0 0;font-size:12px;line-height:1.45;color:#64748b}

    /* ── Identity card ───────────────────────────────────────
       Name leads at display size, role sits beside it as a quiet
       pill, and the facts run underneath as a 3-up list. */
    body[data-page="settings"] .set-identity{
        display:flex;align-items:center;gap:16px;margin:18px 0 0;padding:0 0 18px;
        border-bottom:1px solid #e2e8f0;
    }
    body[data-page="settings"] .set-avatar{
        width:60px;height:60px;border-radius:16px;flex:0 0 60px;display:flex;align-items:center;
        justify-content:center;color:#fff;font-weight:800;font-size:21px;letter-spacing:.5px;
        background:linear-gradient(135deg,#2563eb,#1b3fa8);
        box-shadow:0 6px 16px rgba(37,99,235,.25);
    }
    body[data-page="settings"] .set-id-name{
        margin:0;font-size:21px;font-weight:800;line-height:1.2;color:#0f172a;
        letter-spacing:-.4px;overflow-wrap:anywhere;
    }
    body[data-page="settings"] .set-id-meta{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap}
    body[data-page="settings"] .set-role{
        display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;
        background:#e0ecff;color:#1d4ed8;font-size:10.5px;font-weight:800;
        letter-spacing:.05em;text-transform:uppercase;
    }
    body[data-page="settings"] .set-role i{font-size:9px}
    body[data-page="settings"] .set-id-note{font-size:11.5px;color:#94a3b8}
    body[data-page="settings"] .set-facts{
        display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;margin:18px 0 0;
        background:#e2e8f0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;
    }
    body[data-page="settings"] .set-fact{background:#fff;padding:12px 14px;min-width:0}
    body[data-page="settings"] .set-fact dt{
        font-size:9.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
        color:#64748b;margin:0 0 4px;
    }
    body[data-page="settings"] .set-fact dd{
        margin:0;font-size:13.5px;font-weight:600;color:#0f172a;line-height:1.35;
        overflow-wrap:anywhere;font-variant-numeric:tabular-nums;
    }
    body[data-page="settings"] .set-fact dd.is-empty{color:#cbd5e1;font-weight:500}

    /* ── Password ────────────────────────────────────────────── */
    body[data-page="settings"] .pw-field-wrap { position:relative; }
    body[data-page="settings"] .pw-field-wrap .form-control { padding-right:40px; }
    body[data-page="settings"] .pw-toggle {
        position:absolute; right:8px; top:50%; transform:translateY(-50%);
        background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px;
        padding:6px; line-height:1; border-radius:6px;
    }
    body[data-page="settings"] .pw-toggle:hover { color:#2563eb; background:#eef4ff; }
    /* The toggle is a real control, so it needs a visible focus ring. */
    body[data-page="settings"] .pw-toggle:focus-visible{outline:2px solid #2563eb;outline-offset:1px}
    /* Bars plus a word. Colour alone would leave the strength
       unreadable to anyone who cannot separate the hues. */
    body[data-page="settings"] .strength-row{display:flex;align-items:center;gap:9px;margin-top:8px}
    body[data-page="settings"] .strength-bar { display:flex; gap:4px; flex:1; }
    body[data-page="settings"] .strength-bar i { height:4px; flex:1; border-radius:3px; background:#e2e8f0; transition:background .2s ease; }
    body[data-page="settings"] .strength-label{
        font-size:11px;font-weight:700;color:#94a3b8;white-space:nowrap;min-width:52px;text-align:right;
    }
    body[data-page="settings"] .strength-label[data-level="1"]{color:#b91c1c}
    body[data-page="settings"] .strength-label[data-level="2"]{color:#b45309}
    body[data-page="settings"] .strength-label[data-level="3"]{color:#1d4ed8}
    body[data-page="settings"] .strength-label[data-level="4"]{color:#047857}
    body[data-page="settings"] .field-error {
        font-size:12px; color:#b91c1c; margin-top:6px; font-weight:600; display:none;
    }
    body[data-page="settings"] .set-submit{
        display:flex;align-items:center;gap:9px;margin-top:4px;width:100%;
        justify-content:center;height:40px;
    }
    body[data-page="settings"] .set-hint{font-size:11.5px;color:#94a3b8;margin:10px 0 0;line-height:1.5}

    /* ── System information ───────────────────────────────────── */
    body[data-page="settings"] .sys-list{margin:16px 0 0;padding:0}
    body[data-page="settings"] .sys-row {
        display:flex; justify-content:space-between; align-items:baseline; gap:14px;
        padding:9px 0; border-bottom:1px solid #f1f5f9; font-size:13px;
    }
    body[data-page="settings"] .sys-row:last-child { border-bottom:none; padding-bottom:0; }
    body[data-page="settings"] .sys-row dt{color:#64748b;flex:0 0 auto}
    body[data-page="settings"] .sys-row dd{
        margin:0;font-weight:600;color:#0f172a;text-align:right;min-width:0;
        overflow-wrap:anywhere;font-variant-numeric:tabular-nums;
    }
    body[data-page="settings"] .set-foot{
        margin:16px 0 0;padding:11px 16px;border:1px solid #e2e8f0;border-radius:12px;
        background:#f8faff;font-size:11.5px;color:#64748b;line-height:1.5;
    }
    body[data-page="settings"] .set-foot i{color:#94a3b8;margin-right:5px}

    @media (max-width: 980px) {
        /* Stack to one column. The named areas are dropped so the cards
           fall back to source order, which is Profile → Password →
           System. Students lose the identity card entirely, so their
           own template has no "ident" row to leave empty. */
        .set-grid{grid-template-columns:1fr;grid-template-areas:none}
        .set-grid .is-wide { grid-column:auto; }
        body[data-page="settings"] .set-card-area-pw,
        body[data-page="settings"] .set-card-area-sys{grid-area:auto}
        body[data-page="settings"] .set-facts{grid-template-columns:1fr 1fr}
    }
    @media (max-width: 560px) {
        body[data-page="settings"] .set-header{padding:21px 18px;border-radius:16px}
        body[data-page="settings"] .set-header h1{font-size:25px}
        body[data-page="settings"] .set-card{padding:16px}
        body[data-page="settings"] .set-identity{flex-direction:column;align-items:flex-start;gap:12px}
        body[data-page="settings"] .set-facts{grid-template-columns:1fr}
        body[data-page="settings"] .set-avatar{width:52px;height:52px;flex:0 0 52px;font-size:19px}
        body[data-page="settings"] .set-id-name{font-size:19px}
    }
    </style>

    <div class="set-grid <?= $isStudent ? 'no-ident' : 'has-ident' ?>">

        <?php if (!$isStudent): ?>
        <!-- Identity. Read-only values, so they are text rather than
             disabled inputs — a greyed field reads as broken.
             Staff only; students manage their record in
             student/profile.php. -->
        <section class="set-card accent-blue is-wide" aria-labelledby="setIdentityTitle">
            <div class="set-card-head">
                <div class="set-card-icon blue"><i class="fas fa-user"></i></div>
                <div>
                    <h3 class="set-card-title" id="setIdentityTitle">Profile</h3>
                    <p class="set-card-sub">Your account details</p>
                </div>
            </div>
            <div class="set-identity">
                <div class="set-avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
                <div>
                    <h4 class="set-id-name"><?= htmlspecialchars($userName) ?></h4>
                    <div class="set-id-meta">
                        <span class="set-role"><i class="fas fa-id-badge"></i><?= htmlspecialchars($roleLabel) ?></span>
                        <span class="set-id-note">Staff account</span>
                    </div>
                </div>
            </div>
            <dl class="set-facts">
                <div class="set-fact">
                    <dt>Email</dt>
                    <dd class="<?= $userEmail === '' ? 'is-empty' : '' ?>"><?= $userEmail !== '' ? htmlspecialchars($userEmail) : 'Not set' ?></dd>
                </div>
                <div class="set-fact">
                    <dt>Staff ID</dt>
                    <dd class="<?= empty($me['username']) ? 'is-empty' : '' ?>"><?= !empty($me['username']) ? htmlspecialchars($me['username']) : 'Not set' ?></dd>
                </div>
                <div class="set-fact">
                    <dt>Member since</dt>
                    <dd class="<?= $memberSince === null ? 'is-empty' : '' ?>"><?= $memberSince ?? 'Unknown' ?></dd>
                </div>
            </dl>
        </section>
        <?php endif; ?>

        <!-- Security / Change Password -->
        <section class="set-card accent-emerald set-card-area-pw<?= $isStudent ? ' is-wide' : '' ?>" aria-labelledby="setPwTitle">
            <div class="set-card-head">
                <div class="set-card-icon emerald"><i class="fas fa-shield-halved"></i></div>
                <div>
                    <h3 class="set-card-title" id="setPwTitle">Change Password</h3>
                    <p class="set-card-sub">Use at least 6 characters. A longer mix of letters, numbers and symbols is stronger.</p>
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
                    <div class="strength-row">
                    <div class="strength-bar" id="strengthBar" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <span class="strength-label" id="strengthLabel" role="status" aria-live="polite">Enter a password</span>
                </div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="pw-field-wrap">
                        <input type="password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required autocomplete="new-password" />
                        <button type="button" class="pw-toggle" data-target="confirmPassword" title="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <div class="field-error" id="pwMismatch">Passwords do not match.</div>
                </div>
                <button type="submit" class="btn btn-primary set-submit" id="passwordBtn"><i class="fas fa-key"></i> Change Password</button>
                <p class="set-hint"><i class="fas fa-circle-info"></i>Changing your password does not sign you out. Other devices stay signed in until their session ends.</p>
            </form>
        </section>

        <!-- System Information -->
        <section class="set-card accent-slate set-card-area-sys" aria-labelledby="setSysTitle">
            <div class="set-card-head">
                <div class="set-card-icon slate"><i class="fas fa-circle-info"></i></div>
                <div>
                    <h3 class="set-card-title" id="setSysTitle">System Information</h3>
                    <p class="set-card-sub">Version details for support</p>
                </div>
            </div>
            <dl class="sys-list">
                <div class="sys-row"><dt>Application</dt><dd>BCP Registrar System</dd></div>
                <div class="sys-row"><dt>Version</dt><dd>1.0.0</dd></div>
                <div class="sys-row"><dt>Environment</dt><dd><?= defined('APP_ENV') ? htmlspecialchars(APP_ENV) : 'Development' ?></dd></div>
                <div class="sys-row"><dt>PHP</dt><dd><?= htmlspecialchars(phpversion()) ?></dd></div>
            </dl>
        </section>

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
// The bars alone encode strength by colour, which is unreadable for
// anyone who cannot separate the hues, so the same value is written
// out as a word and announced politely.
const strengthBar = document.getElementById('strengthBar');
const strengthLabel = document.getElementById('strengthLabel');
const strengthColors = ['#dc2626', '#f59e0b', '#2563eb', '#059669'];
const strengthWords = ['', 'Weak', 'Fair', 'Good', 'Strong'];
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
    if (v === '') {
        strengthLabel.textContent = 'Enter a password';
        strengthLabel.removeAttribute('data-level');
    } else {
        strengthLabel.textContent = strengthWords[score];
        strengthLabel.setAttribute('data-level', score);
    }
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