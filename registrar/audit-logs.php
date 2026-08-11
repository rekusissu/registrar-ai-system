<?php
// ============================================================
//  REGISTRAR/AUDIT-LOGS.PHP
//  Audit Logs viewer (admin-only). Filters by user/action/date,
//  paginated, with a details modal for old/new value diffs.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');

$page_title = 'Audit Logs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'audit';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title"><h1>Audit Logs</h1><p>Complete action history across the system</p></div>
</header>

<!-- Filters -->
<div class="panel">
    <div class="search-toolbar" style="flex-wrap:wrap;gap:10px;">
        <div class="search-wrap" style="flex:1;min-width:200px;">
            <i class="fas fa-search"></i>
            <input type="text" id="logSearch" placeholder="Search action, table, or IP...">
        </div>
        <select id="userFilter" class="form-control" style="width:auto;height:40px;"><option value="">All users</option></select>
        <select id="actionFilter" class="form-control" style="width:auto;height:40px;"><option value="">All actions</option></select>
        <input type="date" id="fromDate" class="form-control" style="width:auto;height:40px;" title="From date">
        <span style="color:#94a3b8;font-size:12px;">to</span>
        <input type="date" id="toDate" class="form-control" style="width:auto;height:40px;" title="To date">
        <button class="btn btn-primary" onclick="loadLogs(1)"><i class="fas fa-filter"></i> Apply</button>
    </div>
</div>

<!-- Table -->
<div class="panel">
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr><th>#</th><th>Action</th><th>User</th><th>Table</th><th>Record</th><th>IP Address</th><th>Timestamp</th><th style="text-align:center;">Details</th></tr>
        </thead>
        <tbody id="logBody">
            <tr><td colspan="8" class="empty-state"><i class="fas fa-circle-notch fa-spin"></i><p>Loading logs...</p></td></tr>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="showingFrom">0</strong>–<strong id="showingTo">0</strong> of <strong id="totalCount">0</strong> entries</div>
        <div class="pagination" id="pagination"></div>
    </div>
</div>

</div>
</main>

<!-- Details Modal -->
<div class="modal-overlay" id="detailModal"><div class="modal-content" style="max-width:640px;"><div class="modal-header"><h2><i class="fas fa-receipt"></i> Log Details</h2><button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
    <div id="detailMeta" style="margin-bottom:16px;"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" id="detailValues"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('detailModal')">Close</button></div></div></div>

<script>
let currentPage = 1;

async function loadMeta() {
    try {
        const res = await fetch('../api/audit-logs.php?meta=1');
        const d = await res.json();
        if (!d.success || !d.data) return;
        const userSel = document.getElementById('userFilter');
        (d.data.users || []).forEach(u => {
            if (!u.user_id) return;
            const opt = document.createElement('option');
            opt.value = u.user_id;
            opt.textContent = u.full_name || ('User #' + u.user_id);
            userSel.appendChild(opt);
        });
        const actionSel = document.getElementById('actionFilter');
        (d.data.actions || []).forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.action;
            opt.textContent = a.action;
            actionSel.appendChild(opt);
        });
    } catch(e) {}
}

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function loadLogs(page) {
    currentPage = page;
    const body = document.getElementById('logBody');
    body.innerHTML = '<tr><td colspan="8" class="empty-state"><i class="fas fa-circle-notch fa-spin"></i><p>Loading logs...</p></td></tr>';

    const params = new URLSearchParams({ page });
    const q = document.getElementById('logSearch').value.trim();
    if (q) params.set('q', q);
    const user = document.getElementById('userFilter').value;
    if (user) params.set('user', user);
    const action = document.getElementById('actionFilter').value;
    if (action) params.set('action', action);
    const from = document.getElementById('fromDate').value;
    if (from) params.set('from', from);
    const to = document.getElementById('toDate').value;
    if (to) params.set('to', to);

    try {
        const res = await fetch('../api/audit-logs.php?' + params.toString());
        const d = await res.json();
        if (!d.success) { body.innerHTML = '<tr><td colspan="8" class="empty-state"><p>' + esc(d.message || 'Error loading logs.') + '</p></td></tr>'; return; }
        renderLogs(d.data || [], d.meta || {});
    } catch(e) {
        body.innerHTML = '<tr><td colspan="8" class="empty-state"><p>Failed to load logs.</p></td></tr>';
    }
}

function renderLogs(rows, meta) {
    const body = document.getElementById('logBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" class="empty-state"><i class="fas fa-inbox"></i><p>No logs found</p><span>Try adjusting your filters</span></td></tr>';
    } else {
        body.innerHTML = rows.map(r => {
            const who = r.user_name ? esc(r.user_name) : (r.user_email ? esc(r.user_email) : '<span style="color:#94a3b8;">System</span>');
            return '<tr>' +
                '<td style="color:#94a3b8;font-size:12px;">' + r.id + '</td>' +
                '<td><span class="pill active">' + esc(r.action_label || r.action) + '</span></td>' +
                '<td>' + who + '</td>' +
                '<td><code style="font-size:12px;color:#7c3aed;">' + esc(r.table_name || '—') + '</code></td>' +
                '<td style="font-size:12px;">' + (r.record_id ? '#' + r.record_id : '—') + '</td>' +
                '<td style="font-size:12px;color:#64748b;">' + esc(r.ip_address || '—') + '</td>' +
                '<td style="font-size:12px;color:#64748b;white-space:nowrap;">' + esc(r.created_at) + '</td>' +
                '<td style="text-align:center;"><button class="action-btn edit" onclick="viewDetail(' + r.id + ')" title="View details"><i class="fas fa-eye"></i></button></td>' +
            '</tr>';
        }).join('');
    }

    document.getElementById('showingFrom').textContent = rows.length ? ((meta.page - 1) * meta.limit + 1) : 0;
    document.getElementById('showingTo').textContent = rows.length ? ((meta.page - 1) * meta.limit + rows.length) : 0;
    document.getElementById('totalCount').textContent = meta.total || 0;

    // Pagination
    const pag = document.getElementById('pagination');
    if (!meta.pages || meta.pages <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    if (meta.page > 1) html += '<button class="page-btn" onclick="loadLogs(' + (meta.page - 1) + ')"><i class="fas fa-chevron-left"></i></button>';
    for (let p = 1; p <= meta.pages; p++) {
        if (p === meta.page || (p >= meta.page - 2 && p <= meta.page + 2) || p === 1 || p === meta.pages) {
            html += '<button class="page-btn ' + (p === meta.page ? 'active' : '') + '" onclick="loadLogs(' + p + ')">' + p + '</button>';
        } else if (p === 2 || p === meta.pages - 1) {
            html += '<span class="page-btn" style="background:none;cursor:default;">…</span>';
        }
    }
    if (meta.page < meta.pages) html += '<button class="page-btn" onclick="loadLogs(' + (meta.page + 1) + ')"><i class="fas fa-chevron-right"></i></button>';
    pag.innerHTML = html;
}

// Keep a row cache so viewDetail doesn't need another fetch
let logCache = {};
async function viewDetail(id) {
    if (logCache[id] === undefined) {
        const res = await fetch('../api/audit-logs.php');
        const d = await res.json();
        (d.data || []).forEach(r => logCache[r.id] = r);
    }
    const r = logCache[id];
    if (!r) return;
    document.getElementById('detailMeta').innerHTML =
        '<div style="font-size:14px;font-weight:600;color:#0f172a;margin-bottom:4px;">' + esc(r.action_label || r.action) + '</div>' +
        '<div style="font-size:12px;color:#64748b;">' + (r.user_name ? esc(r.user_name) : 'System') + ' · ' + esc(r.table_name || '—') + ' · ' + (r.record_id ? 'Record #' + r.record_id : '') + '</div>' +
        '<div style="font-size:12px;color:#94a3b8;">' + esc(r.created_at) + ' · IP ' + esc(r.ip_address || '—') + '</div>';

    let html = '';
    html += '<div><div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">OLD VALUES</div>' + formatValues(r.old_values) + '</div>';
    html += '<div><div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">NEW VALUES</div>' + formatValues(r.new_values) + '</div>';
    document.getElementById('detailValues').innerHTML = html;
    openModal('detailModal');
}

function formatValues(vals) {
    if (!vals) return '<div class="kv-empty">—</div>';
    if (typeof vals === 'string') {
        try { vals = JSON.parse(vals); } catch(e) {}
    }
    if (typeof vals !== 'object') return '<div style="font-size:13px;color:#0f172a;">' + esc(vals) + '</div>';
    const entries = Object.entries(vals);
    if (!entries.length) return '<div class="kv-empty">—</div>';
    return '<div style="background:#f8fafc;border-radius:10px;padding:10px;font-size:12px;">' +
        entries.map(([k, v]) => {
            const vs = v && typeof v === 'object' ? JSON.stringify(v) : v;
            return '<div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid #eef2f7;">' +
                '<span style="color:#64748b;min-width:110px;">' + esc(k) + '</span>' +
                '<span style="color:#0f172a;word-break:break-word;">' + esc(vs ?? '') + '</span></div>';
        }).join('') + '</div>';
}

function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('detailModal').addEventListener('click', function(e) { if (e.target === this) closeModal('detailModal'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal('detailModal'); });

document.getElementById('logSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadLogs(1); });

loadMeta();
loadLogs(1);
</script>

<?php include '../includes/footer.php'; ?>
