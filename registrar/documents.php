<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document requests management
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$documents = $db->fetchAll("
    SELECT
        dr.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number,
        u.full_name AS processed_by_name
    FROM document_requests dr
    LEFT JOIN students s ON dr.student_id = s.id
    LEFT JOIN users u ON dr.processed_by = u.id
    ORDER BY dr.id DESC
");

$page_title = 'Document Requests';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';

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
.btn { display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(37,99,235,0.3); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; }
table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
td { padding:10px 14px; font-size:13px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }
.badge { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600; }
.badge.pending { background:#fef3c7; color:#b45309; }
.badge.processing { background:#dbeafe; color:#2563eb; }
.badge.approved { background:#dcfce7; color:#16a34a; }
.badge.completed { background:#dcfce7; color:#16a34a; }
.badge.released { background:#dbeafe; color:#2563eb; }
.badge.denied { background:#fee2e2; color:#dc2626; }
.action-group { display:flex; gap:6px; justify-content:center; }
.action-btn { width:32px; height:32px; border:none; border-radius:8px; cursor:pointer; font-size:13px; color:#64748b; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; font-family:inherit; transition:all .15s ease; }
.action-btn:hover { background:#e2e8f0; color:#1e293b; transform:translateY(-1px); }
.action-btn.view:hover { background:#eef4ff; color:#2563eb; }
.action-btn.edit:hover { background:#fef3c7; color:#b45309; }
.action-btn.delete:hover { background:#fee2e2; color:#dc2626; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.active { display:flex; }
.modal-content { background:white; border-radius:20px; padding:28px 32px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.15); animation:modalSlide .3s ease; }
@keyframes modalSlide { from { opacity:0; transform:translateY(20px) scale(.95); } to { opacity:1; transform:translateY(0) scale(1); } }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.modal-header h3 { font-size:17px; font-weight:700; color:#0f172a; margin:0; }
.modal-close { width:34px; height:34px; border:none; background:#f1f5f9; border-radius:50%; cursor:pointer; font-size:15px; color:#94a3b8; }
.modal-close:hover { background:#e2e8f0; color:#1e293b; }
.modal-body { font-size:14px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; padding-top:14px; border-top:1px solid #f1f5f9; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; color:#475569; margin-bottom:4px; font-weight:600; }
.form-control { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; font-family:inherit; outline:none; background:white; color:#1e293b; box-sizing:border-box; }
.form-control:focus { border-color:#2563eb; }
.detail-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.detail-row .lbl { color:#64748b; font-size:12px; }
.detail-row .val { font-weight:600; color:#1e293b; text-align:right; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
</style>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Document Requests</h1>
            <p>Manage student document requests (Form 137, Good Moral, Transcripts)</p>
        </div>
        <div class="header-actions">
            <a href="documents-add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Request
            </a>
        </div>
    </header>

    <table>
        <thead>
            <tr>
                <th>Student</th>
                <th>Document Type</th>
                <th>Purpose</th>
                <th>Request Date</th>
                <th>Status</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documents)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No document requests found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <tr data-id="<?= (int)$doc['id'] ?>"
                        data-student="<?= htmlspecialchars($doc['student_name'], ENT_QUOTES) ?>"
                        data-number="<?= htmlspecialchars($doc['student_number'], ENT_QUOTES) ?>"
                        data-type="<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $doc['document_type'])), ENT_QUOTES) ?>"
                        data-purpose="<?= htmlspecialchars($doc['purpose'] ?? '', ENT_QUOTES) ?>"
                        data-recipient="<?= htmlspecialchars($doc['recipient'] ?? '', ENT_QUOTES) ?>"
                        data-status="<?= htmlspecialchars($doc['status'], ENT_QUOTES) ?>"
                        data-date="<?= date('M d, Y h:i A', strtotime($doc['request_date'])) ?>"
                        data-processedby="<?= htmlspecialchars($doc['processed_by_name'] ?? '', ENT_QUOTES) ?>"
                        data-processed="<?= $doc['processed_date'] ? date('M d, Y h:i A', strtotime($doc['processed_date'])) : '' ?>"
                        data-denial="<?= htmlspecialchars($doc['denial_reason'] ?? '', ENT_QUOTES) ?>">
                        <td>
                            <div>
                                <div class="font-medium" style="font-weight:600;"><?= htmlspecialchars($doc['student_name']) ?></div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($doc['student_number']) ?></div>
                            </div>
                        </td>
                        <td><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></td>
                        <td><?= htmlspecialchars($doc['purpose'] ?? '—') ?></td>
                        <td><?= date('M d, Y', strtotime($doc['request_date'])) ?></td>
                        <td><span class="badge <?= htmlspecialchars($doc['status']) ?>"><?= ucfirst($doc['status']) ?></span></td>
                        <td>
                            <div class="action-group">
                                <button class="action-btn view" onclick="viewDoc(this)" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-btn edit" onclick="processDoc(this)" title="Process" <?= in_array($doc['status'], ['pending', 'processing', 'approved']) ? '' : 'disabled style="opacity:.4;cursor:not-allowed;"' ?>>
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="action-btn delete" onclick="deleteDoc(this)" title="Delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</main>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-file-lines" style="color:#2563eb;"></i> Document Request</h3><button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="detail-row"><span class="lbl">Student</span><span class="val" id="vStudent">—</span></div>
            <div class="detail-row"><span class="lbl">Student No.</span><span class="val" id="vNumber">—</span></div>
            <div class="detail-row"><span class="lbl">Document Type</span><span class="val" id="vType">—</span></div>
            <div class="detail-row"><span class="lbl">Purpose</span><span class="val" id="vPurpose">—</span></div>
            <div class="detail-row"><span class="lbl">Recipient</span><span class="val" id="vRecipient">—</span></div>
            <div class="detail-row"><span class="lbl">Request Date</span><span class="val" id="vDate">—</span></div>
            <div class="detail-row"><span class="lbl">Status</span><span class="val" id="vStatus">—</span></div>
            <div class="detail-row"><span class="lbl">Processed By</span><span class="val" id="vProcessedBy">—</span></div>
            <div class="detail-row"><span class="lbl">Processed Date</span><span class="val" id="vProcessedDate">—</span></div>
            <div class="detail-row" id="vDenialRow" style="display:none;"><span class="lbl">Denial Reason</span><span class="val" id="vDenial" style="color:#dc2626;">—</span></div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button></div>
    </div>
</div>

<!-- Process Modal -->
<div class="modal-overlay" id="processModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-check-circle" style="color:#b45309;"></i> Process Document</h3><button class="modal-close" onclick="closeModal('processModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="processId">
            <div class="detail-row" style="margin-bottom:14px;"><span class="lbl">Student</span><span class="val" id="pStudent">—</span></div>
            <div class="detail-row" style="margin-bottom:14px;"><span class="lbl">Document</span><span class="val" id="pType">—</span></div>
            <div class="form-group">
                <label>Status</label>
                <select id="processStatus" class="form-control">
                    <option value="processing">Processing</option>
                    <option value="approved">Approved (Ready for pickup)</option>
                    <option value="released">Released (Picked up)</option>
                    <option value="denied">Deny</option>
                </select>
            </div>
            <div class="form-group" id="denialGroup" style="display:none;">
                <label>Denial Reason</label>
                <textarea id="processDenial" class="form-control" rows="2" placeholder="Reason for denial"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('processModal')">Cancel</button>
            <button class="btn btn-primary" id="processSubmit" onclick="submitProcess()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

['viewModal','processModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModal('viewModal'); closeModal('processModal'); }
});

function viewDoc(btn) {
    const tr = btn.closest('tr');
    const d = tr.dataset;
    document.getElementById('vStudent').textContent = d.student;
    document.getElementById('vNumber').textContent = d.number;
    document.getElementById('vType').textContent = d.type;
    document.getElementById('vPurpose').textContent = d.purpose || '—';
    document.getElementById('vRecipient').textContent = d.recipient || '—';
    document.getElementById('vDate').textContent = d.date;
    document.getElementById('vStatus').innerHTML = '<span class="badge ' + d.status + '">' + d.status.charAt(0).toUpperCase() + d.status.slice(1) + '</span>';
    document.getElementById('vProcessedBy').textContent = d.processedby || '—';
    document.getElementById('vProcessedDate').textContent = d.processed || '—';
    const denialRow = document.getElementById('vDenialRow');
    if (d.status === 'denied' && d.denial) { denialRow.style.display = 'flex'; document.getElementById('vDenial').textContent = d.denial; }
    else denialRow.style.display = 'none';
    openModal('viewModal');
}

function processDoc(btn) {
    const tr = btn.closest('tr');
    const d = tr.dataset;
    document.getElementById('processId').value = d.id;
    document.getElementById('pStudent').textContent = d.student;
    document.getElementById('pType').textContent = d.type;
    document.getElementById('processStatus').value = 'processing';
    document.getElementById('denialGroup').style.display = 'none';
    document.getElementById('processDenial').value = '';
    openModal('processModal');
}

document.getElementById('processStatus').addEventListener('change', function() {
    document.getElementById('denialGroup').style.display = this.value === 'denied' ? 'block' : 'none';
});

function submitProcess() {
    const id = document.getElementById('processId').value;
    const status = document.getElementById('processStatus').value;
    const denialReason = document.getElementById('processDenial').value.trim();
    const btn = document.getElementById('processSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    fetch('../api/documents.php?id=' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status, denial_reason: status === 'denied' ? denialReason : undefined })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) window.location.reload();
        else alert(d.message || 'Error updating document.');
    })
    .catch(() => alert('Network error.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; });
}

function deleteDoc(btn) {
    const tr = btn.closest('tr');
    const d = tr.dataset;
    if (!confirm('Delete document request for ' + d.student + ' (' + d.type + ')?')) return;
    fetch('../api/documents.php?id=' + d.id, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'Error deleting.'); })
    .catch(() => alert('Network error.'));
}
</script>

<?php include '../includes/footer.php'; ?>
