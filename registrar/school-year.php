<?php
// ============================================================
//  REGISTRAR/SCHOOL-YEAR.PHP
//  School year & semester management
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set-active') {
        $db->query("UPDATE school_years SET is_active = 0");
        $db->update('school_years', ['is_active' => 1], 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'toggle-enrollment') {
        $sy = $db->fetchOne("SELECT id, enrollment_open FROM school_years WHERE id = ?", [intval($_POST['id'])]);
        if ($sy) $db->update('school_years', ['enrollment_open' => $sy['enrollment_open'] ? 0 : 1], 'id = ?', [$sy['id']]);
    } elseif ($action === 'add') {
        $db->insert('school_years', [
            'school_year' => $_POST['school_year'],
            'semester' => $_POST['semester'],
            'is_active' => 0,
            'enrollment_open' => 0
        ]);
    }
    header('Location: school-year.php');
    exit;
}

$schoolYears = $db->fetchAll("SELECT * FROM school_years ORDER BY created_at DESC");

$page_title = 'School Year';
$APP_ROOT = '../';
$ACTIVE_NAV = 'schoolyear';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root { --sidebar-width:260px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; box-sizing:border-box; }
.header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid #e8eaef; gap:16px; flex-wrap:wrap; }
.header .title h1 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 2px; }
.header .title p { font-size:13px; color:#64748b; margin:0; }
.header-actions { display:flex; align-items:center; gap:8px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; border:1.5px solid transparent; text-decoration:none; font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:11px 16px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; }
td { padding:11px 16px; font-size:14px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }
.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:600; }
.badge.active { background:#dcfce7; color:#16a34a; }
.badge.inactive { background:#f1f5f9; color:#94a3b8; }
.badge.open { background:#eef4ff; color:#2563eb; }
.form-inline { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.form-inline input, .form-inline select { padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; outline:none; }
.form-inline input:focus, .form-inline select:focus { border-color:#2563eb; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
</style>
<main class="dashboard-main">
<header class="header">
<div class="title"><h1>School Year</h1><p>Manage academic year and semesters</p></div>
<div class="header-actions">
<button class="btn btn-primary" onclick="document.getElementById('addForm').style.display='block'"><i class="fas fa-plus"></i> Add</button>
</div>
</header>

<form method="POST" id="addForm" style="display:none;background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;margin-bottom:16px;">
<input type="hidden" name="action" value="add">
<div class="form-inline">
<input type="text" name="school_year" placeholder="e.g. 2025-2026" required style="min-width:150px;">
<select name="semester"><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select>
<button type="submit" class="btn btn-primary" style="padding:8px 18px;"><i class="fas fa-save"></i> Save</button>
<button type="button" class="btn btn-light" onclick="this.closest('form').style.display='none'" style="padding:8px 18px;">Cancel</button>
</div>
</form>

<table>
<thead><tr><th>School Year</th><th>Semester</th><th>Status</th><th>Enrollment</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php foreach ($schoolYears as $sy): ?>
<tr>
<td><strong><?= htmlspecialchars($sy['school_year']) ?></strong></td>
<td><?= htmlspecialchars($sy['semester']) ?></td>
<td><span class="badge <?= $sy['is_active'] ? 'active' : 'inactive' ?>"><?= $sy['is_active'] ? 'Active' : 'Inactive' ?></span></td>
<td><span class="badge <?= $sy['enrollment_open'] ? 'open' : 'inactive' ?>"><?= $sy['enrollment_open'] ? 'Open' : 'Closed' ?></span></td>
<td style="text-align:center;">
<form method="POST" style="display:inline;"><input type="hidden" name="action" value="set-active"><input type="hidden" name="id" value="<?= (int)$sy['id'] ?>"><button type="submit" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;" <?= $sy['is_active'] ? 'disabled' : '' ?>><i class="fas fa-check"></i> Set Active</button></form>
<form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle-enrollment"><input type="hidden" name="id" value="<?= (int)$sy['id'] ?>"><button type="submit" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-<?= $sy['enrollment_open'] ? 'lock' : 'lock-open' ?>"></i> <?= $sy['enrollment_open'] ? 'Close' : 'Open' ?></button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</main>
<?php include '../includes/footer.php'; ?>