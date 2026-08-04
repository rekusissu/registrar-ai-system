<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/ai_client.php';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $email = trim($_POST['email']);
        $fullName = trim($_POST['full_name']);
        // Duplicate email check.
        $exists = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($exists) {
            $_SESSION['teacher_error'] = 'An account with that email already exists.';
            header('Location: teachers.php');
            exit;
        }
        $password = trim($_POST['password'] ?? '');
        if ($password === '' && !empty($_POST['auto_password'])) {
            $password = generateStrongPassword();
            $_SESSION['teacher_created_password'] = $password; // show once
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $uid = $db->insert('users', [
            'email' => $email,
            'password_hash' => $hash,
            'full_name' => $fullName,
            'role' => 'teacher',
            'rfid_uid' => trim($_POST['rfid_uid'] ?: '') ?: null,
            'is_active' => 1
        ]);

        // Optional professional profile captured at creation.
        $profileData = [
            'user_id'        => (int) $uid,
            'employee_number'=> trim($_POST['employee_number'] ?? '') ?: null,
            'designation'    => trim($_POST['designation'] ?? '') ?: 'Faculty',
            'department'     => trim($_POST['department'] ?? '') ?: null,
            'highest_degree' => trim($_POST['highest_degree'] ?? '') ?: null,
            'specialization' => trim($_POST['specialization'] ?? '') ?: null,
        ];
        if (!empty(array_filter($profileData, fn($v) => $v !== null && $v !== ''))) {
            try { $db->insert('teacher_profiles', $profileData); } catch (Exception $e) {}
        }
    } elseif ($action === 'edit') {
        $data = [
            'email' => trim($_POST['email']),
            'full_name' => trim($_POST['full_name']),
            'rfid_uid' => trim($_POST['rfid_uid'] ?: '') ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        if (!empty(trim($_POST['password'] ?? ''))) {
            $data['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        } elseif (!empty($_POST['auto_password'])) {
            $pw = generateStrongPassword();
            $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            $_SESSION['teacher_created_password'] = $pw;
        }
        $db->update('users', $data, 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->update('users', ['is_active' => 0], 'id = ?', [intval($_POST['id'])]);
    }
    header('Location: teachers.php');
    exit;
}

$teachers = $db->fetchAll("SELECT * FROM users WHERE role = 'teacher' OR role = 'staff' ORDER BY full_name");

// Adviser load: how many students each teacher advises.
$load = [];
$loadRows = $db->fetchAll("SELECT adviser_id, COUNT(*) AS cnt, COUNT(DISTINCT section) AS sec FROM students WHERE adviser_id IS NOT NULL GROUP BY adviser_id");
foreach ($loadRows as $lr) {
    $load[(int) $lr['adviser_id']] = ['students' => (int) $lr['cnt'], 'sections' => (int) $lr['sec']];
}

// Teaching load: subject assignments + units per teacher.
$teachLoad = [];
$teachLoadRows = $db->fetchAll(
    "SELECT ts.teacher_id, COUNT(*) AS cnt, COALESCE(SUM(s.units),0) AS units,
            COUNT(DISTINCT ts.section) AS sec
     FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id
     GROUP BY ts.teacher_id");
foreach ($teachLoadRows as $tl) {
    $teachLoad[(int) $tl['teacher_id']] = [
        'assignments' => (int) $tl['cnt'],
        'units'       => round((float) $tl['units'], 1),
        'sections'    => (int) $tl['sec'],
    ];
}

// Master subject catalog (for the assignment picker).
$subjects = $db->fetchAll("SELECT * FROM subjects WHERE is_active = 1 ORDER BY code");

// All users' emails + rfids to detect conflicts.
$allUsers = $db->fetchAll("SELECT id, email, rfid_uid FROM users");

$createdPassword = $_SESSION['teacher_created_password'] ?? null;
unset($_SESSION['teacher_created_password']);
$teacherError = $_SESSION['teacher_error'] ?? null;
unset($_SESSION['teacher_error']);

// Stats for the header cards.
$teacherTotal = count($teachers);
$teacherActive = count(array_filter($teachers, fn($t) => $t['is_active']));
$teacherLoadSum = array_sum(array_map(fn($l) => $l['students'], $load));
$teacherWithRfid = count(array_filter($teachers, fn($t) => !empty($t['rfid_uid'])));
$teacherWithFlags = 0;
$subjectAssignments = 0;
$subjectUnits = 0.0;
foreach ($teachers as $t) {
    $loadInfo = $load[(int)$t['id']] ?? ['students' => 0];
    $tl = $teachLoad[(int)$t['id']] ?? ['assignments' => 0, 'units' => 0.0, 'sections' => 0];
    $subjectAssignments += (int) $tl['assignments'];
    $subjectUnits += (float) $tl['units'];
    if (!empty(teacherDataQualityFlags($t, $allUsers, $loadInfo['students']))) {
        $teacherWithFlags++;
    }
}

$page_title = 'Teachers';
$APP_ROOT = '../';
$ACTIVE_NAV = 'teachers';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root{--sidebar-width:260px}
.dashboard-main{margin-left:var(--sidebar-width);padding:24px 32px;min-height:100vh;width:calc(100% - var(--sidebar-width));max-width:calc(100% - var(--sidebar-width));overflow-x:hidden;box-sizing:border-box}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid #e8eaef;gap:16px;flex-wrap:wrap}
.header .title h1{font-size:22px;font-weight:700;color:#0f172a;margin:0 0 2px}
.header .title p{font-size:13px;color:#64748b;margin:0}
.header-actions{display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:white}
.btn-primary:hover{transform:translateY(-1px)}
.btn-secondary{background:white;color:#475569;border-color:#e2e8f0}
.btn-secondary:hover{background:#f8fafc}
.btn-light{background:#f1f5f9;color:#475569}
.btn-light:hover{background:#e2e8f0}
table{width:100%;border-collapse:collapse;background:white;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0}
th{text-align:left;padding:10px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap}
td{padding:10px 14px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover{background:#f8fafc}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600}
.badge.active{background:#dcfce7;color:#16a34a}
.badge.inactive{background:#f1f5f9;color:#94a3b8}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:500px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.15)}
.modal-content h3{font-size:17px;font-weight:700;color:#0f172a;margin-bottom:14px}
.form-row{display:flex;gap:12px;flex-wrap:wrap}
.form-group{flex:1;min-width:160px;margin-bottom:12px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb}
.form-check{display:flex;align-items:center;gap:8px;margin-top:8px}
.form-check input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #f1f5f9}
code{background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px}
/* ── Stat cards (match students page) ── */
.dashboard-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:white;border-radius:14px;padding:18px 20px;border:1px solid #e2e8f0;transition:all .3s;box-shadow:0 1px 3px rgba(15,23,42,0.04)}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(15,23,42,0.06);border-color:#d8dde4}
.stat-card .stat-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
.stat-card .stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.stat-icon.blue{background:#eef4ff;color:#2563eb} .stat-icon.green{background:#dcfce7;color:#16a34a}
.stat-icon.red{background:#fee2e2;color:#dc2626}
.stat-icon.yellow{background:#fef3c7;color:#b45309} .stat-icon.purple{background:#f3e8ff;color:#7c3aed}
.stat-card .stat-number{font-size:24px;font-weight:700;color:#0f172a;line-height:1.2}
.stat-card .stat-label{color:#64748b;font-size:13px;margin-top:1px}
.stat-card .stat-trend{font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;display:inline-flex;align-items:center;gap:4px}
.stat-trend.up{color:#16a34a;background:#dcfce7}
/* ── Search + table container ── */
.search-table-container{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.04)}
.search-bar{padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;row-gap:10px}
.search-bar .search-wrapper{flex:1 1 320px;min-width:240px;position:relative;display:flex;align-items:center;height:40px}
.search-bar .search-wrapper i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;z-index:2}
.search-bar .search-wrapper input{width:100%;height:40px;padding:0 38px 0 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.search-bar .search-wrapper input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
.search-bar .search-wrapper input::placeholder{color:#94a3b8}
.search-bar .search-wrapper .search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;width:24px;height:24px;display:none;border-radius:50%;align-items:center;justify-content:center;z-index:2}
.search-bar .search-wrapper .search-clear.visible{display:flex}
.search-bar .search-wrapper .search-clear:hover{background:#f1f5f9;color:#1e293b}
.search-bar .search-actions{display:flex;gap:8px;flex-shrink:0;align-items:center;height:40px}
.search-bar .search-actions .btn{height:40px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;justify-content:center}
/* ── Table responsive ── */
.table-responsive{overflow-x:auto}
.table-responsive table{width:100%;border-collapse:collapse}
.table-responsive th{text-align:left;padding:10px 10px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#64748b;background:#fafcfd;border-bottom:2px solid #e8edf4;white-space:nowrap}
.table-responsive td{padding:10px 10px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.table-responsive tbody tr{transition:background .15s ease}
.table-responsive tbody tr:hover{background:#f8fafc}
.table-responsive tbody tr:last-child td{border-bottom:none}
/* Teacher avatar + info */
.teacher-info{display:flex;align-items:center;gap:10px}
.teacher-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;flex-shrink:0}
.teacher-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)} .teacher-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.teacher-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)} .teacher-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.teacher-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.teacher-email{display:block;font-size:11px;color:#94a3b8;margin-top:1px}
/* Action buttons */
.action-btn{width:30px;height:30px;border:none;border-radius:8px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;font-size:12px;background:transparent;color:#94a3b8}
.action-btn:hover{background:#f1f5f9;color:#1e293b;transform:scale(1.05)}
.action-btn.view{color:#2563eb} .action-btn.view:hover{background:#eef4ff}
.action-btn.edit{color:#7c3aed} .action-btn.edit:hover{background:#f3e8ff}
.action-btn.delete{color:#dc2626} .action-btn.delete:hover{background:#fee2e2}
.action-group{display:flex;gap:4px;justify-content:center}
/* Empty state */
.empty-state{text-align:center;padding:36px 20px;color:#94a3b8}
.empty-state i{font-size:44px;display:block;margin-bottom:10px;color:#e2e8f0}
.empty-state p{font-size:15px;color:#64748b;margin:0 0 4px}
/* Table footer */
.table-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid #e2e8f0;background:#fafcfd}
.table-footer .info-text{color:#64748b;font-size:13px}
.load-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600}
.load-chip.open{background:#eef4ff;color:#2563eb} .load-chip.full{background:#fef3c7;color:#b45309}
/* Responsive */
@media(max-width:992px){.dashboard-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.dashboard-main{margin-left:0;padding:16px}.dashboard-stats{grid-template-columns:repeat(2,1fr);gap:12px}.search-bar{flex-direction:column;align-items:stretch}.search-bar .search-wrapper{flex:1 1 auto;min-width:0;width:100%}.search-bar .search-actions{width:100%;justify-content:flex-end;flex-wrap:wrap}.table-responsive th,.table-responsive td{padding:8px 8px;font-size:12px}}
</style>
<main class="dashboard-main">
<?php if ($createdPassword): ?>
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#166534;">
<strong><i class="fas fa-key"></i> New password generated:</strong> <code style="font-size:14px;"><?= htmlspecialchars($createdPassword) ?></code>
<span style="color:#94a3b8;"> — share this once with the teacher. It won't be shown again.</span>
</div>
<?php endif; ?>
<?php if ($teacherError): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#991b1b;"><?= htmlspecialchars($teacherError) ?></div>
<?php endif; ?>
<header class="header"><div class="title"><h1>Teachers</h1><p>Manage teacher accounts</p></div>
<div class="header-actions"><button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Teacher</button></div></header>

<!-- Stats -->
<div class="dashboard-stats">
<div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div></div><div class="stat-number"><?= $teacherTotal ?></div><div class="stat-label">Total Teachers</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-check"></i></div></div><div class="stat-number"><?= $teacherActive ?></div><div class="stat-label">Active</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon red"><i class="fas fa-book-open"></i></div><span class="stat-trend up"><i class="fas fa-layer-group"></i> <?= $subjectAssignments ?> assignments</span></div><div class="stat-number"><?= $subjectUnits ?></div><div class="stat-label">Teaching Load (units)</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-id-card"></i></div></div><div class="stat-number"><?= $teacherWithRfid ?></div><div class="stat-label">With RFID</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-triangle-exclamation"></i></div></div><div class="stat-number"><?= $teacherWithFlags ?></div><div class="stat-label">Need Attention</div></div>
</div>

<!-- Search + Table -->
<div class="search-table-container">
<div class="search-bar">
<div class="search-wrapper">
<i class="fas fa-search"></i>
<input type="text" id="teacherSearch" placeholder="Search by name, email, role..." />
<button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
</div>
<div class="search-actions"><button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> Print</button></div>
</div>

<div class="table-responsive">
<table>
<thead><tr><th>Name</th><th>Role</th><th>RFID UID</th><th>Teaching Load</th><th>Adviser Load</th><th>Status</th><th style="text-align:center;">Flags</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody id="teacherTableBody">
<?php if (empty($teachers)): ?>
<tr><td colspan="8" class="empty-state"><i class="fas fa-chalkboard-teacher"></i><p>No teachers found</p><span>Add your first teacher to get started</span></td></tr>
<?php else: foreach ($teachers as $i => $t):
$loadInfo = $load[(int)$t['id']] ?? ['students' => 0, 'sections' => 0];
$tl = $teachLoad[(int)$t['id']] ?? ['assignments' => 0, 'units' => 0.0, 'sections' => 0];
$tFlags = teacherDataQualityFlags($t, $allUsers, $loadInfo['students']);
$tc = ['blue','green','purple','orange','pink'][$i % 5];
$initials = strtoupper(substr($t['full_name'] ?? '?',0,1).(strpos($t['full_name'] ?? ' ',' ')!==false ? substr(trim(strrchr($t['full_name'],' ')),0,1) : ''));
$activeStudents = (int) $loadInfo['students'];
$avg = $teacherTotal > 0 ? ceil($teacherLoadSum / $teacherTotal) : 0;
$loadClass = $activeStudents >= $avg && $avg > 0 ? 'full' : 'open';
?>
<tr data-teacher='<?= htmlspecialchars(json_encode($t),ENT_QUOTES,'UTF-8') ?>'>
<td><div class="teacher-info"><div class="teacher-avatar <?= $tc ?>"><?= $initials ?: '?' ?></div><div><div class="teacher-name" style="font-weight:600;font-size:13px;color:#0f172a;"><?= htmlspecialchars($t['full_name']) ?></div><span class="teacher-email"><?= htmlspecialchars($t['email']) ?></span></div></div></td>
<td><span class="badge" style="background:#f3e8ff;color:#7c3aed;"><?= htmlspecialchars(ucfirst($t['role'])) ?></span></td>
<td><?= $t['rfid_uid'] ? '<code>'.$t['rfid_uid'].'</code>' : '<span style="color:#94a3b8;">—</span>' ?></td>
<td><span class="load-chip open" title="<?= $tl['assignments'] ?> subject assignment(s) across <?= $tl['sections'] ?> section(s)"><?= $tl['assignments'] ?> subjects · <?= $tl['units'] ?> units</span></td>
<td><span class="load-chip <?= $loadClass ?>" title="<?= $loadInfo['sections'] ?> section(s)"><?= $activeStudents ?> students</span></td>
<td><span class="badge <?= $t['is_active'] ? 'active' : 'inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
<td style="text-align:center;"><?php if (!empty($tFlags)): ?><i class="fas fa-exclamation-triangle" style="color:#f59e0b;font-size:12px;" title="<?= htmlspecialchars(implode('; ', $tFlags)) ?>"></i><?php else: ?><span style="color:#16a34a;"><i class="fas fa-check-circle"></i></span><?php endif; ?></td>
<td style="text-align:center;"><div class="action-group">
<button class="action-btn view" onclick="viewTeacher(<?= (int)$t['id'] ?>)" title="View"><i class="fas fa-eye"></i></button>
<button class="action-btn edit" onclick='openEdit(<?= json_encode($t) ?>)' title="Edit"><i class="fas fa-pen"></i></button>
<form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Deactivate <?= htmlspecialchars($t['full_name']) ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="action-btn delete" title="Deactivate"><i class="fas fa-trash-alt"></i></button></form>
</div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<div class="table-footer"><div class="info-text">Showing <strong id="showingCount"><?= count($teachers) ?></strong> of <strong id="totalCount"><?= count($teachers) ?></strong> teachers</div></div>
</div>
</main>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content" style="max-width:560px;"><h3>Add Teacher</h3><form method="POST"><input type="hidden" name="action" value="add">
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" name="full_name" class="form-control" required></div><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" name="email" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" placeholder="Leave blank to auto-generate"></div><div class="form-group"><label>RFID UID (optional)</label><input type="text" name="rfid_uid" class="form-control" maxlength="10" placeholder="10-digit UID"></div></div>
<div class="form-row"><div class="form-group"><label>Employee Number</label><input type="text" name="employee_number" class="form-control" placeholder="e.g. 2026-0142"></div><div class="form-group"><label>Designation</label><select name="designation" class="form-control"><option value="Faculty">Faculty</option><option value="Part-time">Part-time</option><option value="Adjunct">Adjunct</option><option value="Admin-Faculty">Admin-Faculty</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Department</label><select name="department" class="form-control"><option value="">— Select —</option><?php foreach (getOfferedCourses() as $cn => $majors): ?><option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Highest Degree</label><input type="text" name="highest_degree" class="form-control" placeholder="e.g. MSIT"></div></div>
<div class="form-row"><div class="form-group" style="flex:2;"><label>Specialization</label><input type="text" name="specialization" class="form-control" placeholder="e.g. Data Structures, Web Development"></div></div>
<div class="form-check"><input type="checkbox" name="auto_password" id="addAutoPw" checked style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb;"><label for="addAutoPw" style="font-size:13px;cursor:pointer;">Auto-generate a strong password</label></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('addModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content" style="max-width:620px;"><h3>Edit Teacher</h3><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
<div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="editName" class="form-control" required></div><div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>New Password (leave blank to keep)</label><input type="password" name="password" class="form-control"></div><div class="form-group"><label>RFID UID</label><input type="text" name="rfid_uid" id="editRfid" class="form-control" maxlength="10"></div></div>
<div class="form-row"><div class="form-group"><label>Employee Number</label><input type="text" name="employee_number" id="editEmpNo" class="form-control"></div><div class="form-group"><label>Designation</label><select name="designation" id="editDesignation" class="form-control"><option value="Faculty">Faculty</option><option value="Part-time">Part-time</option><option value="Adjunct">Adjunct</option><option value="Admin-Faculty">Admin-Faculty</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Department</label><select name="department" id="editDepartment" class="form-control"><option value="">— Select —</option><?php foreach (getOfferedCourses() as $cn => $majors): ?><option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Highest Degree</label><input type="text" name="highest_degree" id="editDegree" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Specialization</label><input type="text" name="specialization" id="editSpecialization" class="form-control"></div></div>
<div class="form-check"><input type="checkbox" name="auto_password" id="editAutoPw" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb;"><label for="editAutoPw" style="font-size:13px;cursor:pointer;">Reset to a new auto-generated password</label></div>
<div class="form-group"><div style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_active" id="editActive" checked style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb;"><label for="editActive" style="cursor:pointer;font-size:13px;">Active</label></div></div>
<div class="form-group" style="margin-top:14px;border-top:1px solid #e8eaef;padding-top:14px;"><label style="font-size:12px;color:#475569;font-weight:600;display:block;margin-bottom:6px;">Assigned Subjects (Teaching Load)</label>
<div id="subjectPicker" class="form-control" style="height:auto;max-height:160px;overflow-y:auto;padding:8px;">Loading subjects…</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
<div class="form-group" style="flex:1;min-width:130px;margin:0;"><input type="text" id="subjectSection" class="form-control" placeholder="Section (e.g. 11001)"></div>
<div class="form-group" style="flex:1;min-width:120px;margin:0;"><input type="text" id="subjectSchedule" class="form-control" placeholder="Schedule (e.g. MWF 9:00–10:30)"></div>
</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;">Select subjects, then choose section/schedule. Changes are saved when you press Save below.</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('editModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal"><div class="modal-content" style="max-width:620px;"><div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;"><h3 style="margin:0;"><i class="fas fa-chalkboard-teacher" style="color:#2563eb;"></i> Teacher Profile</h3><button class="modal-close" style="background:none;border:none;font-size:18px;cursor:pointer;color:#94a3b8;" onclick="closeView()">&times;</button></div>
<div id="viewContent" style="font-size:14px;color:#334155;line-height:1.6;"></div>
<div id="viewProfile" style="display:none;margin-top:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;"></div>
<div id="viewAiSummary" style="display:none;margin-top:14px;background:linear-gradient(135deg,#eef4ff,#f5f3ff);border:1px solid #dbeafe;border-radius:10px;padding:12px 14px;font-size:13px;color:#1e40af;"></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeView()">Close</button></div></div></div>

<script>
// ─── SEARCH ─────────────────────────────────────────────────
const searchInput = document.getElementById('teacherSearch');
const searchClear = document.getElementById('searchClear');
const tableBody = document.getElementById('teacherTableBody');
const showingCount = document.getElementById('showingCount');
const totalCount = document.getElementById('totalCount');
let allTeacherRows = [];
document.querySelectorAll('#teacherTableBody tr').forEach(row => {
    try {
        const data = JSON.parse(row.dataset.teacher);
        if (data) allTeacherRows.push({ ...data, element: row });
    } catch(e) {}
});
function performSearch() {
    const q = searchInput.value.trim().toLowerCase();
    let visible = 0;
    allTeacherRows.forEach(t => {
        const hay = ((t.full_name||'') + ' ' + (t.email||'') + ' ' + (t.role||'')).toLowerCase();
        const show = !q || hay.includes(q);
        t.element.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    showingCount.textContent = visible;
    searchClear.classList.toggle('visible', q.length > 0);
    // Empty state handling
    let emptyRow = tableBody.querySelector('.empty-state-row');
    if (visible === 0 && allTeacherRows.length > 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr'); emptyRow.className = 'empty-state-row';
            emptyRow.innerHTML = '<td colspan="8" class="empty-state"><i class="fas fa-search"></i><p>No teachers match</p><span>Try a different search term</span></td>';
            tableBody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
    } else if (emptyRow) emptyRow.style.display = 'none';
}
searchInput.addEventListener('input', performSearch);
searchClear.addEventListener('click', () => { searchInput.value = ''; performSearch(); });

function printTable() {
    const w = window.open();
    w.document.write('<html><head><title>Teachers</title><style>table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #ddd;text-align:left;font-size:12px}th{background:#f4f4f4}</style></head><body><h2>Teachers</h2><table><tr><th>Name</th><th>Email</th><th>Role</th><th>RFID</th><th>Teaching Load</th><th>Status</th></tr>');
    allTeacherRows.forEach(t => {
        const tl = t.teaching || { assignments: 0, units: 0 };
        w.document.write('<tr><td>'+(t.full_name||'')+'</td><td>'+(t.email||'')+'</td><td>'+(t.role||'')+'</td><td>'+(t.rfid_uid||'—')+'</td><td>'+tl.assignments+' subjects ('+tl.units+' units)</td><td>'+(t.is_active?'Active':'Inactive')+'</td></tr>');
    });
    w.document.write('</table></body></html>');
    w.document.close();
    w.print();
}

function openAdd() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});

// ─── SUBJECT PICKER ────────────────────────────────────────
let SUBJECTS = []; // master catalog
const subjectPickerEl = document.getElementById('subjectPicker');

function loadSubjects(cb) {
    if (SUBJECTS.length) { if (cb) cb(); return; }
    fetch('../api/teachers.php?action=subjects').then(r => r.json()).then(d => {
        if (d.success) { SUBJECTS = d.data || []; if (cb) cb(); }
    }).catch(() => {});
}
function renderSubjectPicker(selectedIds) {
    if (!subjectPickerEl) return;
    const sel = selectedIds || [];
    subjectPickerEl.innerHTML = SUBJECTS.length
        ? SUBJECTS.map(s => {
            const checked = sel.indexOf(s.id) !== -1 ? ' checked' : '';
            return '<label style="display:flex;align-items:center;gap:8px;padding:4px 2px;cursor:pointer;font-size:13px;">'
                + '<input type="checkbox" value="'+s.id+'"'+checked+' style="accent-color:#2563eb;width:14px;height:14px;">'
                + '<code>'+s.code+'</code> <span style="color:#475569;">'+s.title+'</span>'
                + '<span style="margin-left:auto;color:#94a3b8;font-size:11px;">'+s.units+' u</span></label>';
        }).join('')
        : '<span style="color:#94a3b8;">No subjects in catalog.</span>';
}
function getSelectedSubjects() {
    const boxes = subjectPickerEl ? subjectPickerEl.querySelectorAll('input:checked') : [];
    return Array.from(boxes).map(b => parseInt(b.value, 10));
}

function openEdit(t) {
    document.getElementById('editId').value = t.id;
    document.getElementById('editName').value = t.full_name;
    document.getElementById('editEmail').value = t.email;
    document.getElementById('editRfid').value = t.rfid_uid || '';
    document.getElementById('editActive').checked = t.is_active == 1;
    // Clear profile fields first.
    ['editEmpNo','editDegree','editSpecialization','editDepartment'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    const deg = document.getElementById('editDesignation'); if (deg) deg.value = 'Faculty';
    document.getElementById('subjectSection').value = '';
    document.getElementById('subjectSchedule').value = '';

    loadSubjects(() => { renderSubjectPicker([]); });

    // Load profile + current assignments.
    fetchTeacherDetail(t.id).then(detail => {
        if (!detail) return;
        const p = detail.profile || {};
        if (p.employee_number) document.getElementById('editEmpNo').value = p.employee_number;
        if (p.designation) document.getElementById('editDesignation').value = p.designation;
        if (p.department) document.getElementById('editDepartment').value = p.department;
        if (p.highest_degree) document.getElementById('editDegree').value = p.highest_degree;
        if (p.specialization) document.getElementById('editSpecialization').value = p.specialization;
        renderSubjectPicker((detail.subjects || []).map(s => s.subject_id));
        const firstSub = (detail.subjects || [])[0];
        if (firstSub) {
            if (firstSub.section) document.getElementById('subjectSection').value = firstSub.section;
            if (firstSub.schedule) document.getElementById('subjectSchedule').value = firstSub.schedule;
        }
    });

    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});

// On add: capture profile fields so we can save them after the user row is created.
document.querySelector('#addModal form').addEventListener('submit', function(e) {
    const emp = document.querySelector('#addModal input[name=employee_number]').value;
    const des = document.querySelector('#addModal select[name=designation]').value;
    const dep = document.querySelector('#addModal select[name=department]').value;
    const deg = document.querySelector('#addModal input[name=highest_degree]').value;
    const spe = document.querySelector('#addModal input[name=specialization]').value;
    sessionStorage.setItem('teacher_profile_pending', JSON.stringify({ employee_number: emp, designation: des, department: dep, highest_degree: deg, specialization: spe }));
});

// On edit save: also save profile + subject assignments via the API.
document.querySelector('#editModal form').addEventListener('submit', function(e) {
    const uid = parseInt(document.getElementById('editId').value, 10);
    if (!uid) return; // let the normal POST proceed
    e.preventDefault();

    const profilePayload = {
        user_id: uid,
        employee_number: document.getElementById('editEmpNo').value,
        designation: document.getElementById('editDesignation').value,
        department: document.getElementById('editDepartment').value,
        highest_degree: document.getElementById('editDegree').value,
        specialization: document.getElementById('editSpecialization').value,
    };
    const assignPayload = {
        teacher_id: uid,
        subject_ids: getSelectedSubjects(),
        section: document.getElementById('subjectSection').value,
        schedule: document.getElementById('subjectSchedule').value,
    };

    Promise.all([
        fetch('../api/teachers.php?action=save_profile', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(profilePayload) }),
        fetch('../api/teachers.php?action=assign_subjects', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(assignPayload) }),
    ]).then(() => {
        // Then submit the existing user edit via the normal POST flow.
        e.target.submit();
    }).catch(() => { e.target.submit(); });
});
</script>
<script>
// Inject teacher data for the view modal.
const TEACHERS = <?= json_encode(array_map(function ($t) use ($load, $teachLoad) {
    return array_merge($t, [
        'load' => $load[(int)$t['id']] ?? ['students'=>0,'sections'=>0],
        'teaching' => $teachLoad[(int)$t['id']] ?? ['assignments'=>0,'units'=>0.0,'sections'=>0],
    ]);
}, $teachers)) ?>;

// Fetch a teacher's subject assignments + profile for the view modal.
function fetchTeacherDetail(id) {
    return fetch('../api/teachers.php?action=profile&id=' + id).then(r => r.json()).then(d => (d.success ? d.data : null)).catch(() => null);
}

function viewTeacher(id) {
    const t = TEACHERS.find(x => String(x.id) === String(id));
    if (!t) return;
    const content = document.getElementById('viewContent');
    const load = t.load || { students: 0, sections: 0 };
    const teach = t.teaching || { assignments: 0, units: 0, sections: 0 };
    content.innerHTML =
        '<div style="display:flex;gap:14px;align-items:center;margin-bottom:12px;"><div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:20px;">' + (t.full_name[0] || '?').toUpperCase() + '</div><div><div style="font-size:18px;font-weight:700;color:#0f172a;">' + (t.full_name||'') + '</div><div style="color:#64748b;font-size:12px;">' + (t.email||'') + ' · ' + (t.role||'').toUpperCase() + '</div></div></div>'
        + '<div style="background:#f8fafc;border-radius:8px;padding:12px 14px;margin-bottom:10px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;">'
        + '<div><div style="font-size:22px;font-weight:700;color:#2563eb;">' + (load.students||0) + '</div><div style="font-size:11px;color:#64748b;">Advisees</div></div>'
        + '<div><div style="font-size:22px;font-weight:700;color:#dc2626;">' + (teach.assignments||0) + '</div><div style="font-size:11px;color:#64748b;">Subjects (' + (teach.units||0) + ' units)</div></div>'
        + '<div><div style="font-size:22px;font-weight:700;color:' + (t.is_active ? '#16a34a' : '#dc2626') + ';">' + (t.is_active ? 'Active' : 'Off') + '</div><div style="font-size:11px;color:#64748b;">Status</div></div></div>'
        + '<div style="color:#64748b;font-size:13px;"><b style="color:#334155;">RFID UID:</b> ' + (t.rfid_uid ? '<code>' + t.rfid_uid + '</code>' : '<span style="color:#94a3b8;">— not set</span>') + '</div>'
        + '<div style="color:#64748b;font-size:13px;margin-top:4px;"><b style="color:#334155;">Role:</b> ' + (t.role||'').toUpperCase() + '</div>'
        + '<div id="viewSubjectSection" style="margin-top:12px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:6px;"><i class="fas fa-book-open"></i> Subjects Taught</div><div id="viewSubjects" style="font-size:13px;color:#94a3b8;">Loading…</div></div>';

    document.getElementById('viewModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Load subjects/profiles asynchronously.
    fetchTeacherDetail(id).then(detail => {
        const box = document.getElementById('viewSubjects');
        if (!detail) { box.innerHTML = '<span style="color:#94a3b8;">Subjects unavailable.</span>'; box.parentElement.style.display='none'; return; }
        box.parentElement.style.display = '';
        const subs = detail.subjects || [];
        if (!subs.length) {
            box.innerHTML = '<span style="color:#94a3b8;">No subject assignments yet.</span>';
        } else {
            box.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><tbody>' + subs.map(s =>
                '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 6px;"><code>'+ (s.code||'') +'</code></td><td style="padding:5px 6px;color:#334155;">'+ (s.title||'') +'</td><td style="padding:5px 6px;color:#64748b;">'+ (s.section||'—') +'</td><td style="padding:5px 6px;color:#94a3b8;white-space:nowrap;">'+ (s.schedule||'') +'</td></tr>'
            ).join('') + '</tbody></table>';
        }
        const p = detail.profile || {};
        if (p.department || p.designation || p.specialization) {
            const profBox = document.getElementById('viewProfile');
            if (profBox) {
                profBox.style.display = 'block';
                profBox.innerHTML = '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#2563eb;margin-bottom:6px;"><i class="fas fa-id-badge"></i> Profile</div>'
                    + '<div style="font-size:13px;color:#475569;line-height:1.7;">'
                    + (p.employee_number ? '<b>Employee No:</b> '+p.employee_number+'<br>' : '')
                    + (p.designation ? '<b>Designation:</b> '+p.designation+'<br>' : '')
                    + (p.department ? '<b>Department:</b> '+p.department+'<br>' : '')
                    + (p.highest_degree ? '<b>Highest Degree:</b> '+p.highest_degree+'<br>' : '')
                    + (p.specialization ? '<b>Specialization:</b> '+p.specialization+'<br>' : '')
                    + (p.years_teaching ? '<b>Years Teaching:</b> '+p.years_teaching+'<br>' : '')
                    + '</div>';
            }
        }
    });

    // AI summary (non-blocking, cached).
    const aiSum = document.getElementById('viewAiSummary');
    aiSum.style.display = 'block';
    aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span> <span style="color:#64748b;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Generating...</span>';
    fetch('../api/ai-tools.php?action=teacher_profile', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: t.id }) })
    .then(r => r.json()).then(d => {
        if (d.success && d.data && d.data.summary) {
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#334155;">' + d.data.summary + '</p>';
        } else {
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>';
        }
    }).catch(() => { aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>'; });
}

    document.getElementById('viewModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    // AI summary (non-blocking, cached).
    const aiSum = document.getElementById('viewAiSummary');
    aiSum.style.display = 'block';
    aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span> <span style="color:#64748b;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Generating...</span>';
    fetch('../api/ai-tools.php?action=teacher_profile', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: t.id }) })
    .then(r => r.json()).then(d => {
        if (d.success && d.data && d.data.summary) {
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#334155;">' + d.data.summary + '</p>';
        } else {
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>';
        }
    }).catch(() => { aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>'; });
}
function closeView() { document.getElementById('viewModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeView(); });
</script>
<?php include '../includes/footer.php'; ?>