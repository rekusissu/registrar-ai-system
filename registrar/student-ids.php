<?php
// ============================================================
//  REGISTRAR/STUDENT-IDS.PHP
//  Student ID card management (school / library / cafeteria IDs)
//  with QR code generation.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$ids = $db->fetchAll("
    SELECT si.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
           s.student_number, s.course, s.year_level, s.photo
    FROM student_ids si
    LEFT JOIN students s ON si.student_id = s.id
    ORDER BY si.id DESC
");
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course, photo FROM students WHERE status != 'archived' ORDER BY last_name, first_name");

$page_title = 'Student IDs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'studentids';
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
.badge { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600; }
.badge.active { background:#dcfce7; color:#16a34a; }
.badge.inactive { background:#f1f5f9; color:#64748b; }
.badge.lost { background:#fee2e2; color:#dc2626; }
.idtype-chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#eef4ff; color:#2563eb; }
.qr-thumb { width:36px; height:36px; border:1px solid #e2e8f0; border-radius:8px; object-fit:contain; background:white; }
.action-group { display:flex; gap:6px; justify-content:center; }
.action-btn { width:32px; height:32px; border:none; border-radius:8px; cursor:pointer; font-size:13px; color:#64748b; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; font-family:inherit; transition:all .15s ease; }
.action-btn:hover { background:#e2e8f0; color:#1e293b; transform:translateY(-1px); }
.action-btn.view:hover { background:#eef4ff; color:#2563eb; }
.action-btn.edit:hover { background:#fef3c7; color:#b45309; }
.action-btn.delete:hover { background:#fee2e2; color:#dc2626; }
.btn { display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(37,99,235,0.3); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.active { display:flex; }
.modal-content { background:white; border-radius:20px; padding:28px 32px; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.15); animation:modalSlide .3s ease; }
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
/* ID card preview */
.idcard { width:340px; margin:0 auto; border-radius:16px; overflow:hidden; border:1px solid #dbeafe; background:linear-gradient(160deg,#eff6ff,#dbeafe); font-family:inherit; }
.idcard-head { background:linear-gradient(135deg,#1d4ed8,#2563eb); color:white; padding:12px 16px; display:flex; align-items:center; gap:10px; }
.idcard-head img { width:28px; height:28px; border-radius:6px; }
.idcard-head .school { font-size:13px; font-weight:700; letter-spacing:.3px; }
.idcard-head .school small { display:block; font-size:9px; font-weight:400; opacity:.85; }
.idcard-body { padding:16px; text-align:center; }
.idcard-photo { width:72px; height:72px; border-radius:50%; object-fit:cover; background:#cbd5e1; border:3px solid white; box-shadow:0 2px 8px rgba(0,0,0,.15); }
.idcard-name { font-size:16px; font-weight:700; color:#0f172a; margin-top:8px; }
.idcard-meta { font-size:11px; color:#475569; margin-top:2px; }
.idcard-id { font-size:13px; font-weight:700; color:#1d4ed8; margin-top:6px; letter-spacing:.5px; }
.idcard-qr { margin-top:10px; }
.idcard-qr img { width:88px; height:88px; background:white; padding:4px; border-radius:8px; border:1px solid #e2e8f0; }
.idcard-foot { background:white; padding:8px; text-align:center; font-size:9px; color:#94a3b8; border-top:1px solid #e2e8f0; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} .form-row{grid-template-columns:1fr} }
</style>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Student IDs</h1>
            <p>Issue and manage student ID cards with QR codes</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Issue ID</button>
        </div>
    </header>

    <table>
        <thead>
            <tr>
                <th>Student</th>
                <th>ID Number</th>
                <th>Type</th>
                <th>Issued</th>
                <th>Expiry</th>
                <th>Status</th>
                <th>QR</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($ids)): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">No student IDs issued yet.</td></tr>
            <?php else: foreach ($ids as $i): ?>
                <tr data-id="<?= (int)$i['id'] ?>"
                    data-student-id="<?= (int)$i['student_id'] ?>"
                    data-number="<?= htmlspecialchars($i['student_number'], ENT_QUOTES) ?>"
                    data-name="<?= htmlspecialchars($i['student_name'], ENT_QUOTES) ?>"
                    data-idnumber="<?= htmlspecialchars($i['id_number'], ENT_QUOTES) ?>"
                    data-idtype="<?= htmlspecialchars($i['id_type'], ENT_QUOTES) ?>"
                    data-issued="<?= htmlspecialchars($i['issue_date'] ?? '', ENT_QUOTES) ?>"
                    data-expiry="<?= htmlspecialchars($i['expiry_date'] ?? '', ENT_QUOTES) ?>"
                    data-status="<?= htmlspecialchars($i['status'], ENT_QUOTES) ?>"
                    data-qr="<?= htmlspecialchars($i['qr_code_path'] ?? '', ENT_QUOTES) ?>"
                    data-photo="<?= htmlspecialchars($i['photo'] ?? '', ENT_QUOTES) ?>"
                    data-course="<?= htmlspecialchars($i['course'] ?? '', ENT_QUOTES) ?>"
                    data-year="<?= htmlspecialchars($i['year_level'] ?? '', ENT_QUOTES) ?>">
                    <td><strong><?= htmlspecialchars($i['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($i['student_number']) ?></span></td>
                    <td><code style="background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px;"><?= htmlspecialchars($i['id_number']) ?></code></td>
                    <td><span class="idtype-chip"><?= ucfirst(str_replace('_', ' ', $i['id_type'])) ?></span></td>
                    <td><?= $i['issue_date'] ? date('M d, Y', strtotime($i['issue_date'])) : '—' ?></td>
                    <td><?= $i['expiry_date'] ? date('M d, Y', strtotime($i['expiry_date'])) : '—' ?></td>
                    <td><span class="badge <?= $i['status'] ?>"><?= ucfirst($i['status']) ?></span></td>
                    <td><?= $i['qr_code_path'] ? '<img class="qr-thumb" src="' . htmlspecialchars($i['qr_code_path']) . '">' : '—' ?></td>
                    <td><div class="action-group">
                        <button class="action-btn view" onclick="viewCard(this)" title="View ID Card"><i class="fas fa-id-card"></i></button>
                        <button class="action-btn edit" onclick="editStatus(this)" title="Update Status"><i class="fas fa-pen"></i></button>
                        <button class="action-btn delete" onclick="deleteId(this)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                    </div></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</main>

<!-- Issue ID Modal -->
<div class="modal-overlay" id="issueModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-id-card" style="color:#2563eb;"></i> Issue Student ID</h3><button class="modal-close" onclick="closeModal('issueModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>Student <span style="color:#dc2626;">*</span></label>
                <select id="issueStudent" class="form-control" required>
                    <option value="">Select a student</option>
                    <?php foreach ($students as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'] . ' — ' . $st['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>ID Type</label>
                    <select id="issueType" class="form-control">
                        <option value="school_id">School ID</option>
                        <option value="library">Library</option>
                        <option value="cafeteria">Cafeteria</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="issueStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>ID Number (leave blank to auto-generate)</label><input type="text" id="issueNumber" class="form-control" placeholder="e.g., 2026-0001"></div>
                <div class="form-group"><label>Expiry Date</label><input type="date" id="issueExpiry" class="form-control"></div>
            </div>
            <p style="font-size:11px;color:#64748b;margin:0;"><i class="fas fa-info-circle"></i> A QR code will be generated and saved automatically.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('issueModal')">Cancel</button>
            <button class="btn btn-primary" id="issueSubmit" onclick="submitIssue()"><i class="fas fa-save"></i> Issue ID</button>
        </div>
    </div>
</div>

<!-- View ID Card Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3><i class="fas fa-id-card" style="color:#2563eb;"></i> Student ID Card</h3><button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="idcard">
                <div class="idcard-head">
                    <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP">
                    <div class="school">BESTLINK COLLEGE OF THE PHILIPPINES<small>Official Student ID</small></div>
                </div>
                <div class="idcard-body">
                    <img class="idcard-photo" id="cardPhoto" src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="photo">
                    <div class="idcard-name" id="cardName">—</div>
                    <div class="idcard-meta" id="cardCourse">—</div>
                    <div class="idcard-id" id="cardNumber">—</div>
                    <div class="idcard-qr"><img id="cardQr" src="" alt="QR"></div>
                    <div style="font-size:10px;color:#475569;margin-top:6px;" id="cardType">School ID</div>
                </div>
                <div class="idcard-foot">This ID is property of Bestlink College of the Philippines. If found, return to the Registrar's Office.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
            <button class="btn btn-primary" onclick="printCard()"><i class="fas fa-print"></i> Print ID</button>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-pen" style="color:#b45309;"></i> Update ID</h3><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="editId">
            <div class="detail-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;font-size:12px;">Student</span><span style="font-weight:600;" id="editStudent">—</span></div>
            <div class="detail-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;font-size:12px;">ID Number</span><span style="font-weight:600;" id="editNumber">—</span></div>
            <div class="form-row" style="margin-top:12px;">
                <div class="form-group"><label>Status</label><select id="editStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option><option value="lost">Lost</option></select></div>
                <div class="form-group"><label>Expiry Date</label><input type="date" id="editExpiry" class="form-control"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitEdit()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
['issueModal','viewModal','editModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeModal('issueModal'); closeModal('viewModal'); closeModal('editModal'); } });

function openAdd() {
    document.getElementById('issueStudent').value = '';
    document.getElementById('issueType').value = 'school_id';
    document.getElementById('issueStatus').value = 'active';
    document.getElementById('issueNumber').value = '';
    document.getElementById('issueExpiry').value = '';
    openModal('issueModal');
}

function submitIssue() {
    const studentId = document.getElementById('issueStudent').value;
    if (!studentId) { alert('Select a student.'); return; }
    const btn = document.getElementById('issueSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Issuing...';
    fetch('../api/student-ids.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            student_id: studentId,
            id_type: document.getElementById('issueType').value,
            status: document.getElementById('issueStatus').value,
            id_number: document.getElementById('issueNumber').value,
            expiry_date: document.getElementById('issueExpiry').value
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) window.location.reload();
        else alert(d.message || 'Error issuing ID.');
    })
    .catch(() => alert('Network error.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Issue ID'; });
}

function viewCard(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('cardPhoto').src = d.photo ? d.photo : '../assets/images/BCP_LOGO.png';
    document.getElementById('cardName').textContent = d.name;
    document.getElementById('cardCourse').textContent = (d.course ? d.course : '') + (d.year ? ' · Year ' + d.year : '');
    document.getElementById('cardNumber').textContent = d.idnumber;
    document.getElementById('cardQr').src = d.qr ? d.qr : '';
    document.getElementById('cardType').textContent = d.idtype.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
    currentCardData = { name: d.name, photo: d.photo, course: d.course, idnumber: d.idnumber, qr: d.qr, idtype: d.idtype };
    openModal('viewModal');
}

function editStatus(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('editId').value = d.id;
    document.getElementById('editStudent').textContent = d.name;
    document.getElementById('editNumber').textContent = d.idnumber;
    document.getElementById('editStatus').value = d.status;
    document.getElementById('editExpiry').value = d.expiry || '';
    openModal('editModal');
}

// Print a clean official ID card sheet (school logo, photo, QR, signature line)
function printCard() {
    const d = currentCardData || {};
    const w = window.open('', '_blank', 'width=600,height=800');
    const photo = d.photo || '../assets/images/BCP_LOGO.png';
    const name = d.name || '—';
    const course = d.course || '';
    const id = d.idnumber || '';
    const qr = d.qr || '';
    const type = (d.idtype || 'school_id').replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
    const css = ''
        + '@page { size: 86mm 54mm; margin: 0; } '
        + 'body { margin: 0; font-family: Arial, sans-serif; -webkit-print-color-adjust: exact; } '
        + '.card { width: 86mm; height: 54mm; border-radius: 4mm; overflow: hidden; '
        + 'background: linear-gradient(160deg,#eff6ff,#dbeafe); border: 1.2mm solid #dbeafe; '
        + 'box-sizing: border-box; position: relative; } '
        + '.h { background: linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; padding: 3mm 4mm; '
        + 'display:flex; align-items:center; gap:3mm; } '
        + '.h img { width: 8mm; height: 8mm; border-radius: 1.5mm; } '
        + '.h .s { font-size: 4.2mm; font-weight: 700; letter-spacing: .2mm; line-height:1.1; } '
        + '.h .s small { display:block; font-size:2.6mm; font-weight:400; opacity:.85; } '
        + '.b { display:flex; align-items:center; gap:4mm; padding: 3mm 4mm; } '
        + '.b img.p { width: 14mm; height: 17mm; object-fit: cover; border-radius: 1.5mm; '
        + 'border: .6mm solid #fff; box-shadow: 0 1mm 2mm rgba(0,0,0,.2); } '
        + '.i { flex:1; } '
        + '.i .n { font-size: 4.4mm; font-weight: 700; color:#0f172a; } '
        + '.i .m { font-size: 2.8mm; color:#475569; margin-top:.6mm; } '
        + '.i .d { font-size: 3.4mm; font-weight: 700; color:#1d4ed8; letter-spacing:.4mm; margin-top:1mm; } '
        + '.i .t { font-size:2.6mm; color:#64748b; text-transform:uppercase; letter-spacing:.3mm; margin-top:.6mm; } '
        + '.qr { text-align:center; } '
        + '.qr img { width: 16mm; height: 16mm; background:#fff; padding:1mm; border-radius:1.5mm; border:.3mm solid #e2e8f0; } '
        + '.f { background:#fff; border-top:.3mm solid #e2e8f0; padding:1mm 4mm; text-align:center; '
        + 'font-size:2.2mm; color:#64748b; } '
        + '@media print { .card { margin: 0; border: none; } body { -webkit-print-color-adjust: exact; } }';
    w.document.write('<html><head><title>Student ID — ' + name + '</title><style>' + css + '</style></head><body>');
    w.document.write('<div class="card">');
    w.document.write('<div class="h"><img src="../assets/images/BCP_LOGO.png" alt="BCP"><div class="s">BESTLINK COLLEGE OF THE PHILIPPINES<small>Official ' + type + '</small></div></div>');
    w.document.write('<div class="b"><img class="p" src="' + photo + '" alt="photo"><div class="i"><div class="n">' + name + '</div><div class="m">' + course + '</div><div class="d">' + id + '</div><div class="t">' + type + '</div></div>' + (qr ? '<div class="qr"><img src="' + qr + '" alt="QR"></div>' : '') + '</div>');
    w.document.write('<div class="f">This ID is property of Bestlink College of the Philippines. If found, return to the Registrar\'s Office.</div>');
    w.document.write('</div></body></html>');
    w.document.close();
    setTimeout(() => { w.focus(); w.print(); }, 250);
}

// Hold the currently-viewed card for print
let currentCardData = null;

function submitEdit() {
    const id = document.getElementById('editId').value;
    fetch('../api/student-ids.php?action=update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id,
            status: document.getElementById('editStatus').value,
            expiry_date: document.getElementById('editExpiry').value
        })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'Error updating.'); })
    .catch(() => alert('Network error.'));
}

function deleteId(btn) {
    const d = btn.closest('tr').dataset;
    if (!confirm('Delete ID ' + d.idnumber + ' for ' + d.name + '?')) return;
    fetch('../api/student-ids.php?action=delete', {
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
