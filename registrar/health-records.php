<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$records = $db->fetchAll("SELECT h.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number, s.course, s.year_level FROM health_records h JOIN students s ON h.student_id = s.id ORDER BY s.last_name");
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE status != 'archived' ORDER BY last_name, first_name");
// Students that already have a health record (so we don't offer them as "new")
$hasRecord = array_column($records, 'student_id');
$page_title = 'Health Records';
$APP_ROOT = '../';
$ACTIVE_NAV = 'health';
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
.badge-blood { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#fee2e2; color:#dc2626; }
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
<header class="header"><div class="title"><h1>Health Records</h1><p>Student health information</p></div>
<div class="header-actions">
<span style="font-size:13px;color:#64748b;"><?= count($records) ?> records</span>
<button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Record</button>
</div></header>
<table>
<thead><tr><th>Student</th><th>Blood</th><th>Height</th><th>Weight</th><th>Allergies</th><th>Conditions</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($records)): ?>
<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No health records found.</td></tr>
<?php else: foreach ($records as $r): ?>
<tr data-id="<?= (int)$r['id'] ?>" data-student-id="<?= (int)$r['student_id'] ?>"
    data-blood="<?= htmlspecialchars($r['blood_type'] ?? '', ENT_QUOTES) ?>"
    data-height="<?= htmlspecialchars($r['height'] ?? '', ENT_QUOTES) ?>"
    data-weight="<?= htmlspecialchars($r['weight'] ?? '', ENT_QUOTES) ?>"
    data-allergies="<?= htmlspecialchars($r['allergies'] ?? '', ENT_QUOTES) ?>"
    data-conditions="<?= htmlspecialchars($r['pre_existing_conditions'] ?? '', ENT_QUOTES) ?>"
    data-immun="<?= htmlspecialchars($r['immunization_records'] ?? '', ENT_QUOTES) ?>"
    data-notes="<?= htmlspecialchars($r['notes'] ?? '', ENT_QUOTES) ?>">
<td><strong><?= htmlspecialchars($r['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['student_number']) ?> · <?= htmlspecialchars($r['course'] ?? '') ?></span></td>
<td><span class="badge-blood"><?= htmlspecialchars($r['blood_type'] ?? '—') ?></span></td>
<td><?= $r['height'] ? $r['height'].' cm' : '—' ?></td>
<td><?= $r['weight'] ? $r['weight'].' kg' : '—' ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($r['allergies'] ?? 'None') ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($r['pre_existing_conditions'] ?? 'None') ?></td>
<td><div class="action-group">
<button class="action-btn edit" onclick="openEdit(this)" title="Edit"><i class="fas fa-pen"></i></button>
</div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="healthModal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="healthModalTitle"><i class="fas fa-heartbeat" style="color:#dc2626;"></i> Add Health Record</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="healthId">
            <div class="form-group">
                <label>Student <span style="color:#dc2626;">*</span></label>
                <select id="healthStudent" class="form-control" required>
                    <option value="">Select a student</option>
                    <?php foreach ($students as $st): ?>
                        <?php if (in_array($st['id'], $hasRecord)) continue; ?>
                        <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'] . ' — ' . $st['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Blood Type</label><select id="healthBlood" class="form-control"><option value="">—</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option><option>O+</option><option>O-</option></select></div>
                <div class="form-group"><label>Height (cm)</label><input type="number" step="0.01" id="healthHeight" class="form-control" placeholder="e.g., 165"></div>
                <div class="form-group"><label>Weight (kg)</label><input type="number" step="0.01" id="healthWeight" class="form-control" placeholder="e.g., 60"></div>
                <div class="form-group"><label>Immunizations</label><input type="text" id="healthImmun" class="form-control" placeholder="e.g., COVID-19, Flu"></div>
            </div>
            <div class="form-group"><label>Allergies</label><textarea id="healthAllergies" class="form-control" rows="2" placeholder="List allergies or None"></textarea></div>
            <div class="form-group"><label>Pre-existing Conditions</label><textarea id="healthConditions" class="form-control" rows="2" placeholder="List conditions or None"></textarea></div>
            <div class="form-group"><label>Notes</label><textarea id="healthNotes" class="form-control" rows="2" placeholder="Additional notes"></textarea></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="healthSubmit" onclick="saveRecord()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
function closeModal() { document.getElementById('healthModal').classList.remove('active'); document.body.style.overflow = ''; }
function openModal() { document.getElementById('healthModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
document.getElementById('healthModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

function openAdd() {
    document.getElementById('healthModalTitle').innerHTML = '<i class="fas fa-heartbeat" style="color:#dc2626;"></i> Add Health Record';
    document.getElementById('healthId').value = '';
    document.getElementById('healthStudent').value = '';
    document.getElementById('healthBlood').value = '';
    document.getElementById('healthHeight').value = '';
    document.getElementById('healthWeight').value = '';
    document.getElementById('healthImmun').value = '';
    document.getElementById('healthAllergies').value = '';
    document.getElementById('healthConditions').value = '';
    document.getElementById('healthNotes').value = '';
    openModal();
}

function openEdit(btn) {
    const tr = btn.closest('tr'); const d = tr.dataset;
    document.getElementById('healthModalTitle').innerHTML = '<i class="fas fa-pen" style="color:#dc2626;"></i> Edit Health Record';
    document.getElementById('healthId').value = d.id;
    // Student is fixed when editing (record belongs to one student)
    document.getElementById('healthStudent').innerHTML = '<option value="' + d.studentId + '" selected>Student #' + d.studentId + '</option>';
    document.getElementById('healthStudent').value = d.studentId;
    document.getElementById('healthBlood').value = d.blood;
    document.getElementById('healthHeight').value = d.height;
    document.getElementById('healthWeight').value = d.weight;
    document.getElementById('healthImmun').value = d.immun;
    document.getElementById('healthAllergies').value = d.allergies;
    document.getElementById('healthConditions').value = d.conditions;
    document.getElementById('healthNotes').value = d.notes;
    openModal();
}

function saveRecord() {
    const studentId = document.getElementById('healthStudent').value;
    if (!studentId) { alert('Select a student.'); return; }
    const btn = document.getElementById('healthSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    fetch('../api/students.php?action=save-health', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            student_id: studentId,
            blood_type: document.getElementById('healthBlood').value,
            height: document.getElementById('healthHeight').value,
            weight: document.getElementById('healthWeight').value,
            immunization_records: document.getElementById('healthImmun').value,
            allergies: document.getElementById('healthAllergies').value,
            pre_existing_conditions: document.getElementById('healthConditions').value,
            notes: document.getElementById('healthNotes').value
        })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'Error saving.'); })
    .catch(() => alert('Network error.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; });
}
</script>
<?php include '../includes/footer.php'; ?>
