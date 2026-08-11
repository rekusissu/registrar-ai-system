<?php
// ============================================================
//  REGISTRAR/CLEARANCE.PHP
//  Clearance workflow (admin/registrar). Issue/update per-student
//  clearances with a printable slip. Gen-2 design (students.php).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar'); // admin bypasses all via requireRole

$page_title = 'Clearance';
$APP_ROOT = '../';
$ACTIVE_NAV = 'clearance';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title"><h1>Clearance</h1><p>Issue and manage student clearances</p></div>
</header>

<!-- Stats -->
<div class="stats-grid" id="statsGrid">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-users"></i></div></div><div class="stat-number" id="statTotal">0</div><div class="stat-label">Total Students</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-clock"></i></div></div><div class="stat-number" id="statPending">0</div><div class="stat-label">Pending</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div></div><div class="stat-number" id="statPartial">0</div><div class="stat-label">Partial</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div></div><div class="stat-number" id="statCleared">0</div><div class="stat-label">Cleared</div></div>
</div>

<!-- Search + Filter + Table -->
<div class="panel">
    <div class="search-toolbar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="clearanceSearch" placeholder="Search by name or student number...">
        </div>
        <select id="statusFilter" class="form-control" style="width:auto;height:40px;" onchange="loadClearances()">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="partial">Partial</option>
            <option value="cleared">Cleared</option>
        </select>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr><th>Student</th><th>Course</th><th>Year</th><th>Status</th><th>Issued</th><th>Issued By</th><th style="text-align:center;">Actions</th></tr>
        </thead>
        <tbody id="clearanceBody">
        <tr><td colspan="7" class="empty-state"><i class="fas fa-circle-notch fa-spin"></i><p>Loading...</p></td></tr>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="showingCount">0</strong> of <strong id="totalCount">0</strong> students</div>
    </div>
</div>

</div>
</main>

<!-- Issue / Update Modal -->
<div class="modal-overlay" id="issueModal"><div class="modal-content" style="max-width:520px;"><div class="modal-header"><h2><i class="fas fa-file-signature"></i> <span id="issueTitle">Issue Clearance</span></h2><button class="modal-close" onclick="closeModal('issueModal')"><i class="fas fa-times"></i></button></div>
<form id="issueForm"><input type="hidden" id="issueStudentId" value=""><input type="hidden" id="issueClearanceId" value=""><div class="modal-body">
    <div style="font-size:15px;font-weight:600;color:#0f172a;margin-bottom:4px;" id="issueStudentName"></div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px;" id="issueStudentMeta"></div>
    <div class="form-group"><label>Status</label><select id="issueStatus" class="form-control">
        <option value="pending">Pending</option>
        <option value="partial">Partial</option>
        <option value="cleared">Cleared</option>
    </select></div>
    <div class="form-group"><label>Notes</label><textarea id="issueNotes" class="form-control" rows="3" placeholder="Optional notes (e.g. awaiting library return)"></textarea></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" onclick="closeModal('issueModal')">Cancel</button>
    <button type="button" class="btn btn-secondary" id="issuePrintBtn" style="display:none;" onclick="printSlip()"><i class="fas fa-print"></i> Print Slip</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
</div></form></div></div>

<script>
let clearances = [];

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function loadClearances() {
    const body = document.getElementById('clearanceBody');
    body.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fas fa-circle-notch fa-spin"></i><p>Loading...</p></td></tr>';
    const params = new URLSearchParams();
    const q = document.getElementById('clearanceSearch').value.trim();
    if (q) params.set('q', q);
    const status = document.getElementById('statusFilter').value;
    if (status) params.set('status', status);
    try {
        const res = await fetch('../api/clearance.php?' + params.toString());
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'Error');
        clearances = d.data || [];
        renderClearances(clearances);
        renderStats(d.stats || {});
    } catch(e) {
        body.innerHTML = '<tr><td colspan="7" class="empty-state"><p>' + esc(e.message || 'Failed to load.') + '</p></td></tr>';
    }
}

function renderStats(stats) {
    document.getElementById('statTotal').textContent   = stats.total || 0;
    document.getElementById('statPending').textContent = stats.pending || 0;
    document.getElementById('statPartial').textContent = stats.partial || 0;
    document.getElementById('statCleared').textContent = stats.cleared || 0;
}

function statusPill(status) {
    const map = { pending: ['inactive', 'fa-clock', 'Pending'], partial: ['warning', 'fa-hourglass-half', 'Partial'], cleared: ['active', 'fa-check-circle', 'Cleared'] };
    const m = map[status] || map.pending;
    return '<span class="pill ' + m[0] + '"><i class="fas ' + m[1] + '"></i> ' + m[2] + '</span>';
}

function renderClearances(rows) {
    const body = document.getElementById('clearanceBody');
    document.getElementById('showingCount').textContent = rows.length;
    document.getElementById('totalCount').textContent = rows.length;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fas fa-file-circle-check"></i><p>No students found</p><span>Try adjusting your search or filters</span></td></tr>';
        return;
    }
    body.innerHTML = rows.map((r, i) => {
        const cs = r.clearance_status || 'pending';
        return '<tr>' +
            '<td><div class="student-info"><div class="student-avatar ' + (['blue','green','purple','orange','pink'][i % 5]) + '">' + esc((r.student_name || '?').charAt(0)) + '</div><div><div class="student-name">' + esc(r.student_name) + '</div><div class="student-sub">' + esc(r.student_number) + '</div></div></div></td>' +
            '<td style="font-size:13px;">' + esc(r.course || '—') + '</td>' +
            '<td style="font-size:13px;">' + (r.year_level ? 'Year ' + esc(r.year_level) : '—') + '</td>' +
            '<td>' + statusPill(cs) + '</td>' +
            '<td style="font-size:12px;color:#64748b;white-space:nowrap;">' + (r.issued_at ? esc(r.issued_at) : '—') + '</td>' +
            '<td style="font-size:13px;">' + (r.issued_by ? esc(r.issued_by) : '<span style="color:#94a3b8;">—</span>') + '</td>' +
            '<td><div class="action-group">' +
                '<button class="action-btn edit" onclick="openIssueModal(' + i + ')" title="Issue / Update"><i class="fas fa-file-signature"></i></button>' +
                (cs === 'cleared' ? '<button class="action-btn" style="color:#2563eb;" onclick="printSlipFrom(' + i + ')" title="Print Slip"><i class="fas fa-print"></i></button>' : '') +
            '</div></td>' +
        '</tr>';
    }).join('');
}

function openIssueModal(idx) {
    const r = clearances[idx];
    if (!r) return;
    document.getElementById('issueStudentId').value = r.student_id;
    document.getElementById('issueClearanceId').value = r.clearance_id || '';
    document.getElementById('issueTitle').textContent = r.clearance_id ? 'Update Clearance' : 'Issue Clearance';
    document.getElementById('issueStudentName').textContent = r.student_name + ' — ' + r.student_number;
    document.getElementById('issueStudentMeta').textContent = (r.course || '') + (r.year_level ? ' · Year ' + r.year_level : '');
    document.getElementById('issueStatus').value = r.clearance_status || 'pending';
    document.getElementById('issueNotes').value = r.notes || '';
    document.getElementById('issuePrintBtn').style.display = (r.clearance_status === 'cleared') ? '' : 'none';
    openModal('issueModal');
}

async function submitJson(url, method, body) {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await res.json();
}

document.getElementById('issueForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    const sid = document.getElementById('issueStudentId').value;
    const cid = document.getElementById('issueClearanceId').value;
    try {
        const d = cid
            ? await submitJson('../api/clearance.php', 'PUT', { id: cid, status: document.getElementById('issueStatus').value, notes: document.getElementById('issueNotes').value })
            : await submitJson('../api/clearance.php', 'POST', { student_id: sid, notes: document.getElementById('issueNotes').value });
        if (d.success) { showToast(d.message, 'success'); closeModal('issueModal'); loadClearances(); }
        else { showToast(d.message, 'error'); }
    } catch(e) { showToast('Network error.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save';
});

function printSlipFrom(idx) {
    const r = clearances[idx];
    if (!r) return;
    const sid = r.student_id;
    const name = r.student_name;
    const num = r.student_number;
    const course = r.course || '';
    const issued = r.issued_at || '';
    const by = r.issued_by || '';
    openSlipWindow(name, num, course, issued, by, r.notes || '');
}

function printSlip() {
    const name = document.getElementById('issueStudentName').textContent;
    const sid = document.getElementById('issueStudentId').value;
    const meta = document.getElementById('issueStudentMeta').textContent;
    // fetch full slip payload for accurate issued_at / issued_by
    fetch('../api/clearance.php?id=' + sid + '&slip=1').then(r => r.json()).then(d => {
        if (d.success && d.data) {
            const s = d.data;
            openSlipWindow(s.student_name, s.student_number, s.course, s.issued_at, s.issued_by, s.notes || '');
        } else {
            openSlipWindow(name, sid, meta, '', '', '');
        }
    }).catch(() => openSlipWindow(name, sid, meta, '', '', ''));
}

function openSlipWindow(name, num, course, issued, by, notes) {
    const w = window.open('', '_blank', 'width=640,height=760');
    if (!w) { alert('Allow pop-ups to print the clearance slip.'); return; }
    w.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Clearance Slip</title>' +
        '<style>' +
        'body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;margin:40px;}h1{font-size:22px;color:#1a3a8c;margin:0 0 4px;}' +
        '.muted{color:#64748b;font-size:12px;margin-bottom:24px;}.box{border:2px solid #1a3a8c;border-radius:12px;padding:24px;margin-bottom:24px;}' +
        '.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;}' +
        '.row:last-child{border-bottom:none;}.label{color:#64748b;}.stamp{margin-top:40px;text-align:right;font-size:13px;color:#334155;}' +
        '.stamp .name{font-weight:700;margin-top:40px;border-top:1px solid #94a3b8;padding-top:8px;width:260px;margin-left:auto;text-align:center;}' +
        '</style></head><body>' +
        '<div style="text-align:center;margin-bottom:8px;"><img src="../assets/images/BCP_LOGO.png" style="height:56px;" alt="BCP"></div>' +
        '<h1 style="text-align:center;">CERTIFICATE OF CLEARANCE</h1>' +
        '<p class="muted" style="text-align:center;">Bestlink College of the Philippines — Registrar\'s Office</p>' +
        '<div class="box">' +
        '<div class="row"><span class="label">Student Name</span><span>' + esc(name) + '</span></div>' +
        '<div class="row"><span class="label">Student Number</span><span>' + esc(num) + '</span></div>' +
        '<div class="row"><span class="label">Course</span><span>' + esc(course || '—') + '</span></div>' +
        '<div class="row"><span class="label">Status</span><span><strong>Cleared</strong></span></div>' +
        '<div class="row"><span class="label">Issued</span><span>' + esc(issued || new Date().toLocaleString()) + '</span></div>' +
        (notes ? '<div class="row"><span class="label">Notes</span><span>' + esc(notes) + '</span></div>' : '') +
        '</div>' +
        '<div class="stamp"><div>This certifies that the above-named student has no unsettled obligations.</div>' +
        '<div class="name">' + esc(by || 'Registrar') + '</div><div style="font-size:11px;color:#94a3b8;">Registrar\'s Office</div></div>' +
        '<div style="margin-top:32px;text-align:center;"><button onclick="window.print()" style="padding:10px 28px;background:#1a3a8c;color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer;">Print</button></div>' +
        '</body></html>'
    );
    w.document.close();
}

function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('issueModal').addEventListener('click', function(e) { if (e.target === this) closeModal('issueModal'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal('issueModal'); });

document.getElementById('clearanceSearch').addEventListener('input', debounce);
function debounce() {
    clearTimeout(this._t);
    this._t = setTimeout(() => loadClearances(), 250);
}

loadClearances();
</script>

<?php include '../includes/footer.php'; ?>
