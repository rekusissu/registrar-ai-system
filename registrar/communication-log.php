<?php
// ============================================================
//  REGISTRAR/COMMUNICATION-LOG.PHP
//  Audit trail for the Emergency & Contacts module.
//
//  Every message the module sends to a contact lands in
//  communication_log: test verifications, forwarded invoices,
//  grade snapshots, transcripts, and emergency blasts. The
//  Registrar can filter by type / status / keyword, resend an
//  invoice to a specific contact, and fire an Emergency Blast
//  to every verified emergency-flagged contact.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// ── Filters ────────────────────────────────────────────────────
$fType   = trim((string) ($_GET['type'] ?? ''));
$fStatus = trim((string) ($_GET['status'] ?? ''));
$fQ      = trim((string) ($_GET['q'] ?? ''));

$where  = [];
$params = [];
if (in_array($fType, ['test', 'invoice', 'snapshot', 'transcript', 'emergency', 'resend'], true)) {
    $where[] = 'cl.message_type = ?';
    $params[] = $fType;
}
if (in_array($fStatus, ['sent', 'failed', 'verified'], true)) {
    $where[] = 'cl.status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where[] = '(s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_number LIKE ? OR cl.recipient_email LIKE ? OR cl.ref LIKE ?)';
    $like = '%' . $fQ . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$logs = $db->fetchAll(
    "SELECT cl.*, s.student_number,
            CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.middle_name,''),' ',COALESCE(s.last_name,'')) AS student_name
       FROM communication_log cl
       LEFT JOIN students s ON s.id = cl.student_id
       $whereSql
      ORDER BY cl.id DESC
      LIMIT 300",
    $params
);

// Students that have verified emergency-flagged contacts (for the blast filter).
$blastStudents = $db->fetchAll(
    "SELECT DISTINCT s.id, s.student_number,
            CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,'')) AS student_name
       FROM contact_recipients cr
       JOIN students s ON s.id = cr.student_id
      WHERE cr.verified = 1 AND cr.send_emergency = 1
      ORDER BY s.last_name, s.first_name"
);

// ── Overview counters (whole table, not just the page of 300) ──
$agg = $db->fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(status = 'sent')      AS sent,
            SUM(status = 'verified')  AS verified,
            SUM(status = 'failed')    AS failed,
            SUM(message_type = 'emergency') AS blasts
       FROM communication_log"
);
$statTotal    = (int) ($agg['total'] ?? 0);
$statSent     = (int) ($agg['sent'] ?? 0);
$statVerified = (int) ($agg['verified'] ?? 0);
$statFailed   = (int) ($agg['failed'] ?? 0);
$statBlasts   = (int) ($agg['blasts'] ?? 0);

$typeChip = [
    'test'       => ['#eef4ff', '#2563eb'],
    'invoice'    => ['#f3e8ff', '#7c3aed'],
    'snapshot'   => ['#dcfce7', '#16a34a'],
    'transcript' => ['#ccfbf1', '#0d9488'],
    'emergency'  => ['#fee2e2', '#dc2626'],
    'resend'     => ['#ffedd5', '#ea580c'],
];
$statusBadge = [
    'sent'     => ['badge-success', 'Sent'],
    'failed'   => ['badge-danger',  'Failed'],
    'verified' => ['badge-primary', 'Verified'],
];

$page_title = 'Communication Log';
$APP_ROOT = '../';
$ACTIVE_NAV = 'commlog';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Communication Log</h1>
                <p>Every email the Emergency &amp; Contacts module has sent — verifications, invoices, snapshots, transcripts, and blasts.</p>
            </div>
            <div class="header-actions">
                <span class="log-count"><?= count($logs) ?> shown</span>
                <button class="btn btn-danger" onclick="openBlast()"><i class="fa-solid fa-tower-broadcast"></i> Emergency Blast</button>
            </div>
        </header>

        <!-- ── Overview stats ─────────────────────────────────── -->
        <div class="comm-stats">
            <div class="cs-card">
                <div class="cs-icon cs-blue"><i class="fa-solid fa-envelope-open-text"></i></div>
                <div>
                    <div class="cs-value"><?= $statTotal ?></div>
                    <div class="cs-label">Total messages</div>
                </div>
            </div>
            <div class="cs-card">
                <div class="cs-icon cs-green"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="cs-value"><?= $statVerified ?></div>
                    <div class="cs-label">Verified</div>
                </div>
            </div>
            <div class="cs-card">
                <div class="cs-icon cs-teal"><i class="fa-solid fa-paper-plane"></i></div>
                <div>
                    <div class="cs-value"><?= $statSent ?></div>
                    <div class="cs-label">Sent</div>
                </div>
            </div>
            <div class="cs-card">
                <div class="cs-icon cs-red"><i class="fa-solid fa-circle-xmark"></i></div>
                <div>
                    <div class="cs-value"><?= $statFailed ?></div>
                    <div class="cs-label">Failed</div>
                </div>
            </div>
            <div class="cs-card">
                <div class="cs-icon cs-purple"><i class="fa-solid fa-tower-broadcast"></i></div>
                <div>
                    <div class="cs-value"><?= $statBlasts ?></div>
                    <div class="cs-label">Emergency blasts</div>
                </div>
            </div>
        </div>

        <!-- ── Filters ─────────────────────────────────────────── -->
        <div class="filter-section">
            <form method="get" action="communication-log.php" style="display:contents;">
                <div class="filter-group">
                    <label for="fType">Type</label>
                    <select name="type" id="fType" class="form-control">
                        <option value="">All types</option>
                        <option value="test" <?= $fType === 'test' ? 'selected' : '' ?>>Test verification</option>
                        <option value="invoice" <?= $fType === 'invoice' ? 'selected' : '' ?>>Invoice</option>
                        <option value="snapshot" <?= $fType === 'snapshot' ? 'selected' : '' ?>>Grade snapshot</option>
                        <option value="transcript" <?= $fType === 'transcript' ? 'selected' : '' ?>>Transcript</option>
                        <option value="emergency" <?= $fType === 'emergency' ? 'selected' : '' ?>>Emergency blast</option>
                        <option value="resend" <?= $fType === 'resend' ? 'selected' : '' ?>>Resend</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="fStatus">Status</label>
                    <select name="status" id="fStatus" class="form-control">
                        <option value="">All statuses</option>
                        <option value="sent" <?= $fStatus === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="failed" <?= $fStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="verified" <?= $fStatus === 'verified' ? 'selected' : '' ?>>Verified</option>
                    </select>
                </div>
                <div class="filter-group" style="grid-column:span 2;">
                    <label for="fQ">Search</label>
                    <input type="text" name="q" id="fQ" value="<?= htmlspecialchars($fQ) ?>" class="form-control" placeholder="Recipient, student, or reference…">
                </div>
                <div class="filter-group filter-actions">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                        <?php if ($fType || $fStatus || $fQ): ?>
                            <a class="btn btn-light" href="communication-log.php">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- ── Log table ───────────────────────────────────────── -->
        <div class="panel log-table-panel">
            <div style="overflow-x:auto;">
                <table class="table table-hover" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Date &amp; time</th>
                            <th>Type</th>
                            <th>Recipient</th>
                            <th>Student</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Ref</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px;">No communication records match these filters.</td></tr>
                        <?php else: foreach ($logs as $l): ?>
                            <?php
                                $tColor = $typeChip[$l['message_type']] ?? ['#f1f5f9', '#475569'];
                                $sBadge = $statusBadge[$l['status']] ?? ['badge-neutral', ucfirst((string) $l['status'])];
                                $label = $l['message_type'] === 'test' ? 'Test verification' : ($l['message_type'] === 'snapshot' ? 'Grade snapshot' : ucfirst((string) $l['message_type']));
                                $detail = trim((string) ($l['detail'] ?? ''));
                            ?>
                            <tr>
                                <td style="white-space:nowrap;color:#475569;font-size:13px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $l['created_at']))) ?></td>
                                <td><span class="chip" style="background:<?= $tColor[0] ?>;color:<?= $tColor[1] ?>;"><?= htmlspecialchars($label) ?></span></td>
                                <td>
                                    <div style="font-weight:600;color:#0f172a;font-size:13.5px;"><?= htmlspecialchars((string) ($l['recipient_name'] ?? $l['recipient_email'])) ?></div>
                                    <div style="color:#64748b;font-size:12.5px;"><?= htmlspecialchars((string) $l['recipient_email']) ?></div>
                                </td>
                                <td style="color:#334155;font-size:13px;">
                                    <div><?= htmlspecialchars((string) ($l['student_name'] ?? '—')) ?></div>
                                    <div style="color:#94a3b8;font-size:12px;"><?= htmlspecialchars((string) ($l['student_number'] ?? '')) ?></div>
                                </td>
                                <td style="max-width:260px;color:#475569;font-size:13px;">
                                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string) $l['subject']) ?>"><?= htmlspecialchars((string) ($l['subject'] ?? '—')) ?></div>
                                    <?php if ($detail !== ''): ?>
                                        <div style="color:#94a3b8;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($detail) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $sBadge[0] ?>"><?= $sBadge[1] ?></span></td>
                                <td style="font-family:monospace;font-size:12px;color:#64748b;"><?= htmlspecialchars((string) ($l['ref'] ?? '—')) ?></td>
                                <td>
                                    <?php if (in_array($l['message_type'], ['invoice', 'resend'], true) && ($l['ref'] ?? '') !== '' && (int) ($l['contact_id'] ?? 0) > 0): ?>
                                        <button class="btn btn-light" style="font-size:12px;padding:5px 10px;" onclick="resendInvoice(<?= (int) $l['contact_id'] ?>, '<?= htmlspecialchars((string) $l['ref'], ENT_QUOTES) ?>', this)"><i class="fa-solid fa-paper-plane"></i> Resend</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ── Emergency Blast modal ──────────────────────────────────── -->
<div class="modal-overlay" id="blastModal">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header blast-header">
            <h2><i class="fa-solid fa-tower-broadcast"></i> Emergency Blast</h2>
            <button class="modal-close" onclick="closeBlast()"><i class="fas fa-times"></i></button>
        </div>
        <form id="blastForm">
            <div class="modal-body">
                <p style="margin:0 0 12px;color:#64748b;font-size:13.5px;">
                    Sends to every <strong>verified</strong> contact with the <strong>Emergency Alerts</strong> permission.
                </p>
                <div class="form-group">
                    <label>Subject <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="subject" id="blastSubject" required maxlength="150" placeholder="e.g. School closure — typhoon advisory">
                </div>
                <div class="form-group">
                    <label>Message <span style="color:#dc2626;">*</span></label>
                    <textarea name="message" id="blastMessage" required rows="5" placeholder="Emergency notification text…"></textarea>
                </div>
                <div class="form-group">
                    <label>Restrict to a student (optional)</label>
                    <select name="student_id" id="blastStudent" data-searchable data-placeholder="Search students…">
                        <option value="0">All students</option>
                        <?php foreach ($blastStudents as $bs): ?>
                            <option value="<?= (int) $bs['id'] ?>"><?= htmlspecialchars($bs['student_name']) ?> (<?= htmlspecialchars($bs['student_number']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeBlast()">Cancel</button>
                <button type="submit" class="btn btn-danger" id="blastBtn"><i class="fa-solid fa-tower-broadcast"></i> Send Blast</button>
            </div>
        </form>
    </div>
</div>

<style>
.chip { padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
.badge { font-weight:600; }

/* ── Header count pill ─────────────────────────── */
.log-count {
    display:inline-flex; align-items:center; gap:7px;
    padding:8px 14px; border-radius:999px;
    background:#f1f5f9; border:1.5px solid #e8ecf3; color:#64748b;
    font-size:12px; font-weight:800; letter-spacing:.3px; white-space:nowrap;
}

/* ── Overview stats ────────────────────────────── */
.comm-stats {
    display:grid; grid-template-columns:repeat(5,1fr); gap:16px;
    margin-bottom:24px;
}
.cs-card {
    background:#fff; border:1px solid #e8ecf3; border-radius:16px; padding:18px 20px;
    display:flex; align-items:center; gap:14px;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
    transition:all .25s cubic-bezier(.16,1,.3,1);
    position:relative; overflow:hidden;
}
.cs-card::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1a3a8c,#2563eb); opacity:0; transition:opacity .2s ease;
}
.cs-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(26,58,140,.12); border-color:#d4dce6; }
.cs-card:hover::before { opacity:1; }
.cs-icon {
    width:46px; height:46px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:18px;
    box-shadow:0 4px 12px rgba(15,23,42,.08);
}
.cs-blue   { background:#eff6ff; color:#2563eb; }
.cs-green  { background:#dcfce7; color:#16a34a; }
.cs-teal   { background:#ccfbf1; color:#0d9488; }
.cs-red    { background:#fee2e2; color:#dc2626; }
.cs-purple { background:#f3e8ff; color:#7c3aed; }
.cs-value { font-size:24px; font-weight:800; color:#0d1b2e; letter-spacing:-.6px; line-height:1.1; }
.cs-label { font-size:11px; color:#64748b; margin-top:4px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; }

/* ── Filter section ────────────────────────────── */
.filter-section { padding:22px 24px; }
.filter-actions { justify-content:flex-end; }

/* ── Table panel ───────────────────────────────── */
.log-table-panel { padding:0; overflow:hidden; }

/* ── Emergency Blast modal (scoped to #blastModal) ── */
#blastModal .modal-header.blast-header {
    background:linear-gradient(135deg,#1a3a8c 0%, #2563eb 100%);
    margin:-28px -32px 0; padding:22px 28px;
    border-radius:20px 20px 0 0; color:#fff;
}
#blastModal .modal-header.blast-header h2 { color:#fff; font-size:18px; }
#blastModal .modal-header.blast-header h2 i { color:#fca5a5; }
#blastModal .modal-close { background:rgba(255,255,255,.16); color:#fff; }
#blastModal .modal-close:hover { background:rgba(255,255,255,.3); color:#fff; }
#blastModal .modal-body { padding:20px 0 4px; }
#blastModal .modal-body p { color:#64748b; font-size:13.5px; }

@media (max-width:1200px) { .comm-stats { grid-template-columns:repeat(3,1fr); } }
@media (max-width:768px)  { .comm-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width:480px)  { .comm-stats { grid-template-columns:1fr; } }
</style>

<script>
const API_URL = '../api/contacts.php';

function csrfFetch(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());
}

function btnBusy(btn, busy) {
    if (!btn) return;
    btn.disabled = busy;
    if (busy) { btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Working...'; }
    else if (btn.dataset.orig) { btn.innerHTML = btn.dataset.orig; }
}

function openBlast() { document.getElementById('blastModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeBlast() { document.getElementById('blastModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('blastModal').addEventListener('click', function (e) { if (e.target === this) closeBlast(); });

document.getElementById('blastForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!confirm('Send an emergency blast to all verified Emergency Alerts contacts? This cannot be undone.')) return;
    const btn = document.getElementById('blastBtn');
    btnBusy(btn, true);
    try {
        const d = await csrfFetch(API_URL, {
            action: 'blast',
            subject: document.getElementById('blastSubject').value,
            message: document.getElementById('blastMessage').value,
            student_id: Number(document.getElementById('blastStudent').value) || 0
        });
        showToast(d.message, d.success ? 'success' : 'error');
        if (d.success) { closeBlast(); document.getElementById('blastForm').reset(); setTimeout(() => location.reload(), 900); }
        else btnBusy(btn, false);
    } catch (err) { showToast('Network error.', 'error'); btnBusy(btn, false); }
});

async function resendInvoice(contactId, ref, btn) {
    if (!confirm('Re-send this invoice (' + ref + ') to the contact?')) return;
    btnBusy(btn, true);
    try {
        const d = await csrfFetch(API_URL, { action: 'resend_invoice', contact_id: Number(contactId), ref: ref });
        showToast(d.message, d.success ? 'success' : 'error');
    } catch (err) { showToast('Network error.', 'error'); }
    btnBusy(btn, false);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
