<?php
// ============================================================
//  STUDENT/DOCUMENTS.PHP
//  Student's own document requests + submit a new request.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$requests = $db->fetchAll(
    "SELECT dr.*, u.full_name AS processed_by_name
     FROM document_requests dr
     LEFT JOIN users u ON u.id = dr.processed_by
     WHERE dr.student_id = ?
     ORDER BY dr.id DESC",
    [$student['id']]
);

$statusBadge = [
    'pending'    => ['pending',    'clock'],
    'processing' => ['processing', 'gear'],
    'approved'   => ['approved',   'check'],
    'denied'     => ['denied',     'xmark'],
    'completed'  => ['completed',  'circle-check'],
    'released'   => ['released',   'truck-fast'],
];
$typeChip = [
    'form137'    => ['Form 137',        'fa-file-lines',   'blue'],
    'good_moral' => ['Good Moral',      'fa-handshake',    'green'],
    'transcript' => ['Transcript',      'fa-file-invoice', 'purple'],
    'certificate'=> ['Certificate',     'fa-certificate',  'orange'],
    'clearance'  => ['Clearance',       'fa-check-double', 'gray'],
];
$counts = array_fill_keys(['pending','processing','approved','denied','completed','released'], 0);
foreach ($requests as $r) { if (isset($counts[$r['status']])) $counts[$r['status']]++; }
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Documents</h1><p>Request and track your documents from the Registrar.</p></div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
        </header>

        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-file-lines"></i></div>
                <div>
                    <div class="s-value"><?= count($requests) ?></div>
                    <div class="s-label">Total Requests</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-clock"></i></div>
                <div>
                    <div class="s-value"><?= $counts['pending'] + $counts['processing'] ?></div>
                    <div class="s-label">In Progress</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="s-value"><?= $counts['completed'] + $counts['released'] ?></div>
                    <div class="s-label">Completed</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon red"><i class="fa-solid fa-xmark"></i></div>
                <div>
                    <div class="s-value"><?= count($requests) - ($counts['pending'] + $counts['processing'] + $counts['completed'] + $counts['released']) ?></div>
                    <div class="s-label">Others</div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="search-toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="docSearch" placeholder="Search by document, purpose, or status...">
                </div>
                <div class="panel-actions" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
                    <span class="chip blue"><i class="fa-solid fa-file-lines"></i> <?= count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?></span>
                </div>
            </div>

            <div class="table-responsive" style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr><th>Document</th><th>Purpose</th><th>Recipient</th><th>Status</th><th>Requested</th><th>Processed by</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="6" class="empty-state"><i class="fa-solid fa-file-lines"></i><p>No document requests yet</p><span>Click "New Request" above to request a document.</span></td></tr>
                    <?php else: foreach ($requests as $r):
                        $badge = $statusBadge[$r['status']] ?? ['pending', 'clock'];
                        $tc = $typeChip[$r['document_type']] ?? [ucwords(str_replace('_', ' ', $r['document_type'])), 'fa-file-lines', 'gray'];
                    ?>
                        <tr data-doc>
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><i class="fa-solid <?= $tc[1] ?>"></i></div>
                                    <div>
                                        <div class="student-name"><?= htmlspecialchars($tc[0]) ?></div>
                                        <?= !empty($r['fee_amount']) && (float)$r['fee_amount'] > 0 ? '<div class="student-sub"><i class="fa-solid fa-coins"></i> Fee: ₱' . htmlspecialchars(number_format((float)$r['fee_amount'], 2)) . '</div>' : '' ?>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
                            <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($r['recipient'] ?? '—') ?></td>
                            <td><span class="pill <?= $badge[0] ?>"><i class="fa-solid fa-<?= $badge[1] ?>"></i> <?= ucfirst($r['status']) ?></span></td>
                            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($r['request_date'])) ?></td>
                            <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($r['processed_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="info-text">Showing <strong><?= count($requests) ?></strong> of <strong><?= count($requests) ?></strong> requests</div>
            </div>
        </div>

    </div>
</main>

<!-- New Request Modal -->
<div class="modal-overlay" id="requestModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-file-circle-plus"></i> New Document Request</h2><button class="modal-close" onclick="closeRequestModal()"><i class="fas fa-times"></i></button></div>
<form id="requestForm"><div class="modal-body">
    <div class="form-group"><label>Document Type <span class="required">*</span></label>
        <select id="reqType" class="form-control" required>
            <option value="form137">Form 137</option>
            <option value="good_moral">Good Moral</option>
            <option value="transcript">Transcript</option>
            <option value="certificate">Certificate</option>
            <option value="clearance">Clearance</option>
        </select></div>
    <div class="form-group"><label>Purpose <span class="required">*</span></label><input type="text" id="reqPurpose" class="form-control" required placeholder="e.g. Job application, Transfer, Scholarship"></div>
    <div class="form-group"><label>Recipient</label><input type="text" id="reqRecipient" class="form-control" placeholder="e.g. Company / School name"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeRequestModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button></div></form></div></div>

<script>
function openRequestModal() {
    document.getElementById('requestForm').reset();
    document.getElementById('requestModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeRequestModal() {
    document.getElementById('requestModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('requestModal').addEventListener('click', function(e) { if (e.target === this) closeRequestModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeRequestModal(); });

// Client-side search
document.getElementById('docSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    const rows = document.querySelectorAll('table tbody tr[data-doc]');
    rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const match = !q || text.includes(q);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.querySelector('.table-footer .info-text').innerHTML =
        'Showing <strong>' + visible + '</strong> of <strong>' + rows.length + '</strong> requests';
});

document.getElementById('requestForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    try {
        const res = await fetch('../api/student-documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                document_type: document.getElementById('reqType').value,
                purpose: document.getElementById('reqPurpose').value,
                recipient: document.getElementById('reqRecipient').value
            })
        });
        const d = await res.json();
        if (d.success) { alert('Document request submitted.'); window.location.reload(); }
        else { alert(d.message || 'Submission failed.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
    } catch (err) {
        alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    }
});
</script>

<?php include '../includes/footer.php'; ?>