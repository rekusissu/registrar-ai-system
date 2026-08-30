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
.gwa-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eef4ff; color:#2563eb; }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Academic History</h1><p>Previous schools and GWA records</p></div>
<div class="header-actions">
<span style="font-size:13px;color:#64748b;"><?= count($rows) ?> records</span>
<button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Record</button>
</div></header>
<table class="table">
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
    data-semester="<?= htmlspecialchars($r['semester'] ?? '', ENT_QUOTES) ?>"
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
                <select id="acadStudent" class="form-control" data-searchable required>
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
                <div class="form-group"><label>Semester</label><select id="acadSemester" class="form-control"><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select></div>
            </div>
            <div class="form-group"><label>Remarks</label><textarea id="acadRemarks" class="form-control" rows="2" placeholder="Notes"></textarea></div>

            <hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:6px;">Quick-fill from Form 137 (AI scan)</div>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="file" id="acadScanFile" accept="image/*" class="form-control" style="flex:1;padding:6px 10px;">
                <button type="button" class="btn btn-light" id="acadScanBtn" onclick="scanForm137()"><i class="fas fa-magic"></i> Scan</button>
            </div>
            <div id="acadScanStatus" style="font-size:12px;color:#64748b;margin-top:6px;"></div>

            <hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:6px;">Per-Subject Grades (Form 137)</div>
            <div id="gradeRows"></div>
            <div style="display:flex;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-light btn-sm" onclick="addGradeRow()"><i class="fas fa-plus"></i> Add Subject</button>
            </div>
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
    document.getElementById('acadSemester').value = '';
    document.getElementById('acadRemarks').value = '';
    document.getElementById('gradeRows').innerHTML = '';
    resetScan();
    openModal();
}

// ─── GRADE ROWS (Subsystem 3) ──────────────────────────────
let gradeSeq = 0;
function gradeRow(g, seq) {
    const id = seq || (++gradeSeq);
    return '<div class="g-row" data-key="' + id + '" style="display:grid;grid-template-columns:1.4fr .5fr .6fr 1fr auto;gap:6px;margin-bottom:6px;align-items:center;">'
        + '<input class="form-control g-subject" placeholder="Subject" value="' + esc(g ? g.subject : '') + '">'
        + '<input class="form-control g-units" placeholder="Units" value="' + esc(g ? g.units : '') + '">'
        + '<input class="form-control g-grade" placeholder="Grade" value="' + esc(g ? g.grade : '') + '">'
        + '<input class="form-control g-remarks" placeholder="Remarks" value="' + esc(g ? g.remarks : '') + '">'
        + '<button type="button" class="action-btn delete" onclick="this.closest(\'.g-row\').remove()" title="Remove"><i class="fas fa-trash-alt"></i></button>'
        + '</div>';
}
function addGradeRow(g) {
    document.getElementById('gradeRows').insertAdjacentHTML('beforeend', gradeRow(g, null));
}
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function openEdit(btn) {
    const tr = btn.closest('tr'); const d = tr.dataset;
    document.getElementById('acadModalTitle').innerHTML = '<i class="fas fa-pen" style="color:#2563eb;"></i> Edit Academic Record';
    document.getElementById('acadId').value = d.id;
    document.getElementById('acadStudent').value = d.studentId;
    document.getElementById('acadSchool').value = d.school;
    document.getElementById('acadYear').value = d.year;
    document.getElementById('acadGrade').value = d.grade;
    document.getElementById('acadGwa').value = d.gwa;
    document.getElementById('acadSemester').value = d.semester || '';
    document.getElementById('acadRemarks').value = d.remarks;
    document.getElementById('gradeRows').innerHTML = '';
    resetScan();
    // Load existing grades for this record
    fetch('../api/students.php?action=grades&record_id=' + d.id).then(r => r.json()).then(gd => {
        const grades = (gd.success && gd.data) ? gd.data : [];
        grades.forEach(g => addGradeRow(g));
    }).catch(() => {});
    openModal();
}

function saveRecord() {
    const studentId = document.getElementById('acadStudent').value;
    const school = document.getElementById('acadSchool').value.trim();
    if (!studentId || !school) { alert('Select a student and enter the school name.'); return; }
    // Collect grades
    const grades = Array.from(document.querySelectorAll('#gradeRows .g-row')).map(row => ({
        subject: row.querySelector('.g-subject').value,
        units: row.querySelector('.g-units').value,
        grade: row.querySelector('.g-grade').value,
        remarks: row.querySelector('.g-remarks').value
    })).filter(g => g.subject.trim() !== '');
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
            semester: document.getElementById('acadSemester').value,
            remarks: document.getElementById('acadRemarks').value,
            grades: grades
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

// ── FORM 137 AI SCAN ──
function resetScan() {
    const fileEl = document.getElementById('acadScanFile');
    if (fileEl) fileEl.value = '';
    const status = document.getElementById('acadScanStatus');
    if (status) status.innerHTML = '';
}

async function scanForm137() {
    const fileEl = document.getElementById('acadScanFile');
    if (!fileEl.files || !fileEl.files.length) { alert('Choose a Form 137 scan or photo first.'); return; }
    const btn = document.getElementById('acadScanBtn');
    const status = document.getElementById('acadScanStatus');
    btn.disabled = true;
    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning with AI...';
    try {
        const fd = new FormData();
        fd.append('file', fileEl.files[0]);
        const res = await fetch('../api/ai-assist.php?action=extract_form137', { method: 'POST', body: fd });
        const d = await res.json();
        if (!d.success) { status.innerHTML = '<span style="color:#dc2626;">' + esc(d.message || 'Scan failed.') + '</span>'; return; }
        const data = d.data || {};
        if (data.school_name) document.getElementById('acadSchool').value = data.school_name;
        if (data.school_year) document.getElementById('acadYear').value = data.school_year;
        if (data.grade_level) document.getElementById('acadGrade').value = data.grade_level;
        if (data.semester) {
            const sel = document.getElementById('acadSemester');
            const match = Array.from(sel.options).find(o => o.value === data.semester);
            if (match) sel.value = data.semester;
        }
        if (data.gwa !== undefined && data.gwa !== null && data.gwa !== '') document.getElementById('acadGwa').value = data.gwa;
        if (data.remarks) document.getElementById('acadRemarks').value = data.remarks;
        document.getElementById('gradeRows').innerHTML = '';
        (data.subjects || []).forEach(s => addGradeRow({ subject: s.subject, units: s.units != null ? s.units : '', grade: s.grade, remarks: s.remarks }));
        const n = (data.subjects || []).length;
        status.innerHTML = '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Scanned ' + n + ' subject(s) — review before saving.</span>';
    } catch (e) {
        status.innerHTML = '<span style="color:#dc2626;">Scan failed: ' + esc(e.message) + '</span>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic"></i> Scan';
    }
}
</script>
<?php include '../includes/footer.php'; ?>
