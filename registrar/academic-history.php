<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$rows = $db->fetchAll("SELECT a.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number, s.course FROM academic_history a JOIN students s ON a.student_id = s.id ORDER BY a.created_at DESC");
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE status != 'archived' ORDER BY last_name, first_name");
$page_title = 'Academic History';
$APP_ROOT = '../';
$ACTIVE_NAV = 'academic';
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
table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
td { padding:10px 14px; font-size:13px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }
.gwa-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eef4ff; color:#2563eb; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
.btn { display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(37,99,235,0.3); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; }
.action-group { display:flex; gap:6px; justify-content:center; }
.action-btn { width:32px; height:32px; border:none; border-radius:8px; cursor:pointer; font-size:13px; color:#64748b; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; font-family:inherit; transition:all .15s ease; }
.action-btn:hover { background:#e2e8f0; color:#1e293b; transform:translateY(-1px); }
.action-btn.edit:hover { background:#eef4ff; color:#2563eb; }
.action-btn.delete:hover { background:#fee2e2; color:#dc2626; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.active { display:flex; }
.modal-content { background:white; border-radius:20px; padding:28px 32px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.15); animation:modalSlide .3s ease; }
@keyframes modalSlide { from { opacity:0; transform:translateY(20px) scale(.95); } to { opacity:1; transform:translateY(0) scale(1); } }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.modal-header h3 { font-size:17px; font-weight:700; color:#0f172a; margin:0; }
.modal-close { width:34px; height:34px; border:none; background:#f1f5f9; border-radius:50%; cursor:pointer; font-size:15px; color:#94a3b8; }
.modal-close:hover { background:#e2e8f0; color:#1e293b; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; color:#475569; margin-bottom:4px; font-weight:600; }
.form-control { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; font-family:inherit; outline:none; background:white; color:#1e293b; box-sizing:border-box; }
.form-control:focus { border-color:#2563eb; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; padding-top:14px; border-top:1px solid #f1f5f9; }
@media(max-width:600px){ .form-row { grid-template-columns:1fr; } }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Academic History</h1><p>Previous schools and GWA records</p></div>
<div class="header-actions">
<span style="font-size:13px;color:#64748b;"><?= count($rows) ?> records</span>
<button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Record</button>
</div></header>
<table>
<thead><tr><th>Student</th><th>School</th><th>Year</th><th>Grade Level</th><th>GWA</th><th>Remarks</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No academic history records found.</td></tr>
<?php else: foreach ($rows as $r): ?>
<tr data-id="<?= (int)$r['id'] ?>" data-student-id="<?= (int)$r['student_id'] ?>"
    data-school="<?= htmlspecialchars($r['school_name'], ENT_QUOTES) ?>"
    data-year="<?= htmlspecialchars($r['school_year'] ?? '', ENT_QUOTES) ?>"
    data-grade="<?= htmlspecialchars($r['grade_level'] ?? '', ENT_QUOTES) ?>"
    data-gwa="<?= htmlspecialchars($r['gwa'] ?? '', ENT_QUOTES) ?>"
    data-subjects="<?= htmlspecialchars($r['subjects_completed'] ?? '', ENT_QUOTES) ?>"
    data-remarks="<?= htmlspecialchars($r['remarks'] ?? '', ENT_QUOTES) ?>">
<td><strong><?= htmlspecialchars($r['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['student_number']) ?></span></td>
<td><?= htmlspecialchars($r['school_name']) ?></td>
<td><?= htmlspecialchars($r['school_year'] ?? '—') ?></td>
<td><?= htmlspecialchars($r['grade_level'] ?? '—') ?></td>
<td><?= $r['gwa'] ? '<span class="gwa-badge">'.$r['gwa'].'</span>' : '—' ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($r['remarks'] ?? '—') ?></td>
<td><div class="action-group">
<button class="action-btn edit" onclick="openEdit(this)" title="Edit"><i class="fas fa-pen"></i></button>
<button class="action-btn delete" onclick="deleteRecord(this)" title="Delete"><i class="fas fa-trash-alt"></i></button>
</div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="acadModal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="acadModalTitle"><i class="fas fa-school" style="color:#2563eb;"></i> Add Academic Record</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="acadId">
            <div class="form-group">
                <label>Student <span style="color:#dc2626;">*</span></label>
                <select id="acadStudent" class="form-control" required>
                    <option value="">Select a student</option>
                    <?php foreach ($students as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'] . ' — ' . $st['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>School Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="acadSchool" class="form-control" placeholder="e.g., Bestlink College of the Philippines" required>
            </div>
            <div class="form-row">
                <div class="form-group"><label>School Year</label><input type="text" id="acadYear" class="form-control" placeholder="e.g., 2024-2025"></div>
                <div class="form-group"><label>Grade Level</label><input type="text" id="acadGrade" class="form-control" placeholder="e.g., Grade 12"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>GWA</label><input type="number" step="0.01" min="1" max="5" id="acadGwa" class="form-control" placeholder="e.g., 1.50"></div>
                <div class="form-group"><label>Subjects Completed</label><input type="number" id="acadSubjects" class="form-control" placeholder="e.g., 8"></div>
            </div>
            <div class="form-group"><label>Remarks</label><textarea id="acadRemarks" class="form-control" rows="2" placeholder="Notes"></textarea></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="acadSubmit" onclick="saveRecord()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
function closeModal() { document.getElementById('acadModal').classList.remove('active'); document.body.style.overflow = ''; }
function openModal() { document.getElementById('acadModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
document.getElementById('acadModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

function openAdd() {
    document.getElementById('acadModalTitle').innerHTML = '<i class="fas fa-school" style="color:#2563eb;"></i> Add Academic Record';
    document.getElementById('acadId').value = '';
    document.getElementById('acadStudent').value = '';
    document.getElementById('acadSchool').value = '';
    document.getElementById('acadYear').value = '';
    document.getElementById('acadGrade').value = '';
    document.getElementById('acadGwa').value = '';
    document.getElementById('acadSubjects').value = '';
    document.getElementById('acadRemarks').value = '';
    openModal();
}

function openEdit(btn) {
    const tr = btn.closest('tr'); const d = tr.dataset;
    document.getElementById('acadModalTitle').innerHTML = '<i class="fas fa-pen" style="color:#2563eb;"></i> Edit Academic Record';
    document.getElementById('acadId').value = d.id;
    document.getElementById('acadStudent').value = d.studentId;
    document.getElementById('acadSchool').value = d.school;
    document.getElementById('acadYear').value = d.year;
    document.getElementById('acadGrade').value = d.grade;
    document.getElementById('acadGwa').value = d.gwa;
    document.getElementById('acadSubjects').value = d.subjects;
    document.getElementById('acadRemarks').value = d.remarks;
    openModal();
}

function saveRecord() {
    const studentId = document.getElementById('acadStudent').value;
    const school = document.getElementById('acadSchool').value.trim();
    if (!studentId || !school) { alert('Select a student and enter the school name.'); return; }
    const btn = document.getElementById('acadSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    fetch('../api/students.php?action=save-academic', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: document.getElementById('acadId').value,
            student_id: studentId,
            school_name: school,
            school_year: document.getElementById('acadYear').value,
            grade_level: document.getElementById('acadGrade').value,
            gwa: document.getElementById('acadGwa').value,
            subjects_completed: document.getElementById('acadSubjects').value,
            remarks: document.getElementById('acadRemarks').value
        })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'Error saving.'); })
    .catch(() => alert('Network error.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; });
}

function deleteRecord(btn) {
    const d = btn.closest('tr').dataset;
    if (!confirm('Delete academic record for ' + d.school + '?')) return;
    fetch('../api/students.php?action=delete-academic', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: d.id })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'Error deleting.'); })
    .catch(() => alert('Network error.'));
}
</script>
<?php include '../includes/footer.php'; ?>
