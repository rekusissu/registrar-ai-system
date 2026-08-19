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
.badge-blood { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#fee2e2; color:#dc2626; }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Health Records</h1><p>Student health information</p></div>
<div class="header-actions">
<span style="font-size:13px;color:#64748b;"><?= count($records) ?> records</span>
<button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Record</button>
</div></header>
<table class="table">
<thead><tr><th>Student</th><th>Blood</th><th>Height</th><th>Weight</th><th>Allergies</th><th>Conditions</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($records)): ?>
<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No health records found.</td></tr>
<?php else: foreach ($records as $r): ?>
<tr data-id="<?= (int)$r['id'] ?>" data-student-id="<?= (int)$r['student_id'] ?>"
    data-student-name="<?= htmlspecialchars($r['student_name'], ENT_QUOTES) ?>"
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
<button class="action-btn view" onclick="openVisits(this)" title="Clinic Visits"><i class="fas fa-notes-medical"></i></button>
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

<!-- Clinic Visits Modal (timeline) -->
<div class="modal-overlay" id="visitsModal">
    <div class="modal-content wide" style="max-width:640px;">
        <div class="modal-header"><h3><i class="fas fa-notes-medical" style="color:#2563eb;"></i> <span id="visitsTitle">Clinic Visits</span></h3><button class="modal-close" onclick="closeVisitsModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>Student</label>
                <select id="visitTable" class="form-control" style="border:0;background:none;font-weight:700;color:#0f172a;padding:0;" disabled>
                </select>
            </div>
            <div style="display:flex;gap:12px;margin-bottom:8px;">
                <input type="date" id="visitDate" class="form-control" style="flex:1;">
                <input type="text" id="visitComplaint" class="form-control" style="flex:2;" placeholder="Complaint (e.g. fever, headache)" />
            </div>
            <div style="display:flex;gap:12px;margin-bottom:8px;">
                <input type="text" id="visitDiag" class="form-control" style="flex:1;" placeholder="Diagnosis" />
                <input type="text" id="visitTemp" class="form-control" style="flex:0 0 110px;" placeholder="Temp (°C)" />
                <input type="text" id="visitBP" class="form-control" style="flex:0 0 120px;" placeholder="BP (e.g. 120/80)" />
            </div>
            <div style="display:flex;gap:12px;margin-bottom:8px;">
                <input type="text" id="visitTreatment" class="form-control" style="flex:1;" placeholder="Treatment given" />
                <input type="text" id="visitMedication" class="form-control" style="flex:1;" placeholder="Medication" />
            </div>
            <div style="display:flex;gap:12px;margin-bottom:12px;">
                <input type="text" id="visitPhysician" class="form-control" style="flex:1;" placeholder="Attending physician / clinic staff" />
            </div>
            <div style="margin-bottom:14px;display:flex;gap:8px;justify-content:flex-end;">
                <button class="btn btn-primary btn-sm" onclick="logVisit()"><i class="fas fa-plus"></i> Log Visit</button>
            </div>
            <div id="visitList" style="max-height:340px;overflow-y:auto;"></div>
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

// ─── CLINIC VISITS ─────────────────────────────────────────
let currentVisitStudent = null;
function openVisits(btn) {
    const d = btn.closest('tr').dataset;
    currentVisitStudent = d.studentId;
    document.getElementById('visitsTitle').textContent = 'Clinic Visits — ' + (d.studentName || 'Student #' + d.studentId);
    const tbl = document.getElementById('visitTable');
    tbl.innerHTML = '<option value="' + d.studentId + '">' + (d.studentName || 'Student #' + d.studentId) + '</option>';
    document.getElementById('visitDate').value = new Date().toISOString().slice(0,10);
    ['visitComplaint','visitDiag','visitTemp','visitBP','visitTreatment','visitMedication','visitPhysician'].forEach(id => document.getElementById(id).value = '');
    loadVisits();
    document.getElementById('visitsModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeVisitsModal() { document.getElementById('visitsModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('visitsModal').addEventListener('click', function(e) { if (e.target === this) closeVisitsModal(); });

function loadVisits() {
    if (!currentVisitStudent) return;
    fetch('../api/students.php?action=visits&student_id=' + currentVisitStudent)
    .then(r => r.json())
    .then(d => {
        const el = document.getElementById('visitList');
        const visits = (d.success && d.data) ? d.data : [];
        if (!visits.length) {
            el.innerHTML = '<div class="empty-mini">No clinic visits recorded for this student yet.</div>';
            return;
        }
        el.innerHTML = visits.map(v => {
            const dt = v.visit_date ? new Date(v.visit_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—';
            const temp = v.temperature ? v.temperature.toFixed ? v.temperature.toFixed(1) + '°C' : v.temperature + '°C' : '';
            return '<div style="border:1px solid #f1f5f9;border-radius:10px;padding:10px 14px;margin-bottom:8px;background:#fafcfd;">'
                + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">'
                + '<div style="font-weight:700;font-size:13px;color:#0f172a;">' + dt + '<span style="font-weight:500;color:#64748b;margin-left:8px;">' + (v.complaint || '—') + '</span></div>'
                + '<button class="action-btn delete" onclick="deleteVisit(' + v.id + ',this)" title="Delete"><i class="fas fa-trash-alt"></i></button>'
                + '</div>'
                + '<div style="font-size:12px;color:#64748b;margin-top:4px;">'
                + (v.temperature ? '<span style="margin-right:10px;"><i class="fas fa-temperature-high"></i> ' + (v.temperature.toFixed ? v.temperature.toFixed(1) : v.temperature) + '°C</span>' : '')
                + (v.blood_pressure ? '<span style="margin-right:10px;"><i class="fas fa-heart-pulse"></i> ' + v.blood_pressure + '</span>' : '')
                + (v.diagnosis ? '<span style="margin-right:10px;"><i class="fas fa-stethoscope"></i> ' + v.diagnosis + '</span>' : '')
                + (v.treatment ? '<span><i class="fas fa-medkit"></i> ' + v.treatment + '</span>' : '')
                + (v.physician ? '<div style="margin-top:3px;"><i class="fas fa-user-doctor"></i> ' + v.physician + '</div>' : '')
                + '</div></div>';
        }).join('');
    }).catch(() => { document.getElementById('visitList').innerHTML = '<div class="empty-mini">Error loading visits.</div>'; });
}

function logVisit() {
    if (!currentVisitStudent) return;
    const payload = {
        student_id: currentVisitStudent,
        visit_date: document.getElementById('visitDate').value,
        complaint: document.getElementById('visitComplaint').value,
        diagnosis: document.getElementById('visitDiag').value,
        temperature: document.getElementById('visitTemp').value,
        blood_pressure: document.getElementById('visitBP').value,
        treatment: document.getElementById('visitTreatment').value,
        medication: document.getElementById('visitMedication').value,
        physician: document.getElementById('visitPhysician').value
    };
    fetch('../api/students.php?action=add-visit', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    }).then(r => r.json()).then(d => {
        if (d.success) { clearVisitForm(); loadVisits(); }
        else alert(d.message || 'Error logging visit.');
    }).catch(() => alert('Network error.'));
}
function clearVisitForm() {
    ['visitComplaint','visitDiag','visitTemp','visitBP','visitTreatment','visitMedication','visitPhysician'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('visitDate').value = new Date().toISOString().slice(0,10);
}
function deleteVisit(id, btn) {
    if (!confirm('Delete this clinic visit?')) return;
    fetch('../api/students.php?action=delete-visit', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
    }).then(r => r.json()).then(d => { if (d.success) loadVisits(); else alert(d.message || 'Delete failed.'); }).catch(() => alert('Network error.'));
}
</script>
<?php include '../includes/footer.php'; ?>
