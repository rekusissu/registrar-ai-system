<?php
// ============================================================
//  REGISTRAR/ENROLLMENT.PHP
//  Student enrollment management per school year
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$activeSY = $db->fetchOne("SELECT * FROM school_years WHERE is_active = 1 LIMIT 1");
$syId = $activeSY ? $activeSY['id'] : 0;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $studentId = intval($_POST['student_id'] ?? 0);

    if ($action === 'enroll' && $studentId && $syId) {
        $existing = $db->fetchOne("SELECT id FROM enrollments WHERE student_id = ? AND school_year_id = ?", [$studentId, $syId]);
        if (!$existing) {
            $db->insert('enrollments', [
                'student_id' => $studentId,
                'school_year_id' => $syId,
                'year_level' => intval($_POST['year_level'] ?? 1),
                'section' => $_POST['section'] ?? null,
                'status' => 'enrolled',
                'enrolled_date' => date('Y-m-d')
            ]);
        }
    } elseif ($action === 'unenroll' && $studentId && $syId) {
        $db->delete('enrollments', 'student_id = ? AND school_year_id = ?', [$studentId, $syId]);
    }

    header('Location: enrollment.php');
    exit;
}

$enrolledIds = $db->fetchColumn("SELECT GROUP_CONCAT(student_id) FROM enrollments WHERE school_year_id = ?", [$syId]) ?: '';
$enrolledArr = $enrolledIds ? explode(',', $enrolledIds) : [];

$students = $db->fetchAll("SELECT * FROM students ORDER BY course, year_level, last_name");
$enrollments = $db->fetchAll("SELECT e.*, s.first_name, s.last_name, s.student_number, s.course, s.year_level as syear FROM enrollments e JOIN students s ON e.student_id = s.id WHERE e.school_year_id = ? ORDER BY s.course, s.last_name", [$syId]);

$page_title = 'Enrollment';
$APP_ROOT = '../';
$ACTIVE_NAV = 'enrollment';
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
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; color:#0f172a; }
.btn-sm { padding:5px 12px; font-size:12px; }

.sy-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:999px; font-size:12px; font-weight:600; background:#eef4ff; color:#2563eb; margin-bottom:18px; }

table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
td { padding:10px 14px; font-size:13px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; }
.badge.enrolled { background:#dcfce7; color:#16a34a; }
.badge.pending { background:#fef3c7; color:#b45309; }
.badge.dropped { background:#fee2e2; color:#dc2626; }

.search-box { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
.search-box input { flex:1; min-width:200px; padding:9px 14px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; outline:none; }
.search-box input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
.search-box select { padding:9px 14px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; outline:none; cursor:pointer; }

@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
</style>
<main class="dashboard-main">
<header class="header">
<div class="title"><h1>Enrollment</h1><p>Manage student enrollments per school year</p></div>
<div class="header-actions">
<?php if ($activeSY): ?><a href="students.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Enroll New</a><?php endif; ?>
</div>
</header>

<?php if ($activeSY): ?>
<div class="sy-badge"><i class="fas fa-calendar"></i> <?= htmlspecialchars($activeSY['school_year']) ?> — <?= htmlspecialchars($activeSY['semester']) ?> Semester</div>
<?php else: ?>
<div class="sy-badge" style="background:#fef3c7;color:#b45309;"><i class="fas fa-exclamation-triangle"></i> No active school year. Set one in School Year page.</div>
<?php endif; ?>

<div class="search-box">
<input type="text" id="searchInput" placeholder="Search student name or ID..." oninput="filterTable()">
<select id="courseFilter" onchange="filterTable()"><option value="">All Courses</option><?php
$courses = $db->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");
foreach ($courses as $c) echo '<option value="'.htmlspecialchars($c['course']).'">'.htmlspecialchars($c['course']).'</option>';
?></select>
</div>

<!-- Enrolled Table -->
<table>
<thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody id="enrolledTable">
<?php if (empty($enrollments)): ?>
<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No enrollments for this school year.</td></tr>
<?php else: foreach ($enrollments as $e): ?>
<tr class="enrolled-row" data-name="<?= strtolower(htmlspecialchars($e['first_name'].' '.$e['last_name'].' '.$e['student_number'])) ?>" data-course="<?= strtolower(htmlspecialchars($e['course'] ?? '')) ?>">
<td style="font-weight:600;font-size:12px;"><?= htmlspecialchars($e['student_number']) ?></td>
<td><strong><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></strong></td>
<td><?= htmlspecialchars($e['course'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($e['year_level'] ?? $e['syear'] ?? '') ?></td>
<td><?= htmlspecialchars($e['section'] ?? '—') ?></td>
<td><span class="badge <?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span></td>
<td style="text-align:center;">
<form method="POST" style="display:inline;" onsubmit="return confirm('Remove this enrollment?')"><input type="hidden" name="action" value="unenroll"><input type="hidden" name="student_id" value="<?= (int)$e['student_id'] ?>"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-user-minus"></i> Remove</button></form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<div style="margin-top:22px;">
<h3 style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:10px;"><i class="fas fa-user-plus" style="color:#2563eb;"></i> Enroll Student</h3>
<table>
<thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody id="unenrolledTable">
<?php
$notEnrolled = array_filter($students, fn($s) => $s['status'] !== 'archived' && !in_array($s['id'], $enrolledArr));
if (empty($notEnrolled)): ?>
<tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">All students are enrolled.</td></tr>
<?php else: foreach ($notEnrolled as $s): ?>
<tr class="unenrolled-row" data-name="<?= strtolower(htmlspecialchars($s['first_name'].' '.$s['last_name'].' '.$s['student_number'])) ?>" data-course="<?= strtolower(htmlspecialchars($s['course'] ?? '')) ?>">
<td style="font-weight:600;font-size:12px;"><?= htmlspecialchars($s['student_number']) ?></td>
<td><strong><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></strong></td>
<td><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
<td style="text-align:center;">
<form method="POST" style="display:inline;">
<input type="hidden" name="action" value="enroll">
<input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
<input type="hidden" name="year_level" value="<?= (int)($s['year_level'] ?? 1) ?>">
<input type="hidden" name="section" value="<?= htmlspecialchars($s['section'] ?? '') ?>">
<button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-user-check"></i> Enroll</button>
</form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<script>
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const c = document.getElementById('courseFilter').value.toLowerCase();
    document.querySelectorAll('.enrolled-row, .unenrolled-row').forEach(r => {
        let show = true;
        if (q && !r.dataset.name.includes(q)) show = false;
        if (c && r.dataset.course !== c) show = false;
        r.style.display = show ? '' : 'none';
    });
}
</script>
</main>
<?php include '../includes/footer.php'; ?>