<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document requests management (gen-2, matches students.php)
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

$totalRequests  = count($documents);
$pendingCount   = count(array_filter($documents, fn($d) => $d['status'] === 'pending'));
$processingCount= count(array_filter($documents, fn($d) => $d['status'] === 'processing'));
$releasedCount  = count(array_filter($documents, fn($d) => $d['status'] === 'released'));

$page_title = 'Document Requests';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>Document Requests</h1>
        <p>Manage student document requests (Form 137, Good Moral, Transcripts)</p>
    </div>
    <div class="header-actions">
        <a href="documents-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</a>
    </div>
</header>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-file-lines"></i></div></div><div class="stat-number"><?= $totalRequests ?></div><div class="stat-label">Total Requests</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-clock"></i></div></div><div class="stat-number"><?= $pendingCount ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-gears"></i></div></div><div class="stat-number"><?= $processingCount ?></div><div class="stat-label">Processing</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-check-double"></i></div></div><div class="stat-number"><?= $releasedCount ?></div><div class="stat-label">Released</div></div>
</div>

<!-- Search + Table -->
<div class="panel">
    <div class="search-toolbar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="docSearch" placeholder="Search by student, type, or purpose...">
        </div>
        <select id="statusFilter" class="form-control" style="width:auto;height:40px;" onchange="performSearch()">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="approved">Approved</option>
            <option value="completed">Completed</option>
            <option value="released">Released</option>
            <option value="denied">Denied</option>
        </select>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Student</th>
                <th>Document Type</th>
                <th>Purpose</th>
                <th>Request Date</th>
                <th>Fee</th>
                <th>Status</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody id="docBody">
            <?php if (empty($documents)): ?>
                <tr><td colspan="7" class="empty-state"><i class="fas fa-file-lines"></i><p>No document requests found</p><span>Create a new request to get started</span></td></tr>
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
                        data-denial="<?= htmlspecialchars($doc['denial_reason'] ?? '', ENT_QUOTES) ?>"
                        data-fee="<?= htmlspecialchars($doc['fee_amount'] ?? '', ENT_QUOTES) ?>"
                        data-receipt="<?= htmlspecialchars($doc['official_receipt'] ?? '', ENT_QUOTES) ?>">
                        <td><div class="student-info"><div class="student-avatar blue"><?= htmlspecialchars(strtoupper(substr($doc['student_name'], 0, 1))) ?></div><div><div class="student-name"><?= htmlspecialchars($doc['student_name']) ?></div><div class="student-sub"><?= htmlspecialchars($doc['student_number']) ?></div></div></div></td>
                        <td><span class="chip blue"><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></span></td>
                        <td><?= htmlspecialchars($doc['purpose'] ?? '—') ?></td>
                        <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($doc['request_date'])) ?></td>
                        <td style="font-size:13px;"><?= $doc['fee_amount'] ? '&#8369;' . number_format((float)$doc['fee_amount'], 2) : '<span style="color:#94a3b8;">—</span>' ?></td>
                        <td><span class="pill <?= htmlspecialchars($doc['status']) ?>"><?= ucfirst($doc['status']) ?></span></td>
                        <td><div class="action-group">
                            <button class="action-btn view" onclick="viewDoc(this)" title="View"><i class="fas fa-eye"></i></button>
                            <button class="action-btn edit" onclick="processDoc(this)" title="Process" <?= in_array($doc['status'], ['pending', 'processing', 'approved']) ? '' : 'disabled style="opacity:.4;cursor:not-allowed;"' ?>><i class="fas fa-check"></i></button>
                            <button class="action-btn delete" onclick="deleteDoc(this)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="showingCount"><?= count($documents) ?></strong> of <strong id="totalCount"><?= count($documents) ?></strong> requests</div>
    </div>
</div>

</div>
</main>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content"><div class="modal-header"><h2><i class="fas fa-file-lines"></i> Document Request</h2><button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button></div>
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
    <div class="modal-content"><div class="modal-header"><h2><i class="fas fa-check-circle"></i> Process Document</h2><button class="modal-close" onclick="closeModal('processModal')"><i class="fas fa-times"></i></button></div>
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
        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Fee (₱)</label><input type="number" step="0.01" min="0" id="processFee" class="form-control" placeholder="0.00"></div>
            <div class="form-group"><label>Official Receipt No.</label><input type="text" id="processReceipt" class="form-control" placeholder="e.g. OR-0001"></div>
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
const allDocs = [];
document.querySelectorAll('#docBody tr[data-id]').forEach(row => {
    allDocs.push({ ...row.dataset, element: row });
});
const showingCount = document.getElementById('showingCount');

function performSearch() {
    const q = document.getElementById('docSearch').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    let visible = 0;
    allDocs.forEach(d => {
        const hay = (d.student || '').toLowerCase() + ' ' + (d.type || '').toLowerCase() + ' ' + (d.purpose || '').toLowerCase();
        const matchQ = !q || hay.includes(q);
        const matchS = !status || d.status === status;
        const show = matchQ && matchS;
        d.element.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    showingCount.textContent = visible;
}
document.getElementById('docSearch').addEventListener('input', performSearch);

function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }

['viewModal','processModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeModal('viewModal'); closeModal('processModal'); } });

function viewDoc(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('vStudent').textContent = d.student;
    document.getElementById('vNumber').textContent = d.number;
    document.getElementById('vType').textContent = d.type;
    document.getElementById('vPurpose').textContent = d.purpose || '—';
    document.getElementById('vRecipient').textContent = d.recipient || '—';
    document.getElementById('vDate').textContent = d.date;
    document.getElementById('vStatus').innerHTML = '<span class="pill ' + d.status + '">' + d.status.charAt(0).toUpperCase() + d.status.slice(1) + '</span>';
    document.getElementById('vProcessedBy').textContent = d.processedby || '—';
    document.getElementById('vProcessedDate').textContent = d.processed || '—';
    const denialRow = document.getElementById('vDenialRow');
    if (d.status === 'denied' && d.denial) { denialRow.style.display = 'flex'; document.getElementById('vDenial').textContent = d.denial; }
    else denialRow.style.display = 'none';
    openModal('viewModal');
}

function processDoc(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('processId').value = d.id;
    document.getElementById('pStudent').textContent = d.student;
    document.getElementById('pType').textContent = d.type;
    document.getElementById('processStatus').value = 'processing';
    document.getElementById('processFee').value = d.fee || '';
    document.getElementById('processReceipt').value = d.receipt || '';
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
    const fee = document.getElementById('processFee').value;
    const receipt = document.getElementById('processReceipt').value.trim();
    const btn = document.getElementById('processSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    fetch('../api/documents.php?id=' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            status,
            denial_reason: status === 'denied' ? denialReason : undefined,
            fee_amount: fee,
            official_receipt: receipt
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Document updated.', 'success'); window.location.reload(); }
        else showToast(d.message || 'Error updating document.', 'error');
    })
    .catch(() => showToast('Network error.', 'error'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; });
}

function deleteDoc(btn) {
    const d = btn.closest('tr').dataset;
    if (!confirm('Delete document request for ' + d.student + ' (' + d.type + ')?')) return;
    fetch('../api/documents.php?id=' + d.id, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => { if (d.success) { showToast('Request deleted.', 'success'); window.location.reload(); } else showToast(d.message || 'Error deleting.', 'error'); })
    .catch(() => showToast('Network error.', 'error'));
}
</script>

<?php include '../includes/footer.php'; ?>
