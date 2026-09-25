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
$body_page = 'audit-logs';            // scopes the admin-blue layer
$extra_css = ['admin.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="adm-head">
    <div>
        <div class="adm-kicker"><i class="fas fa-receipt"></i> Record of changes</div>
        <h1>Audit logs</h1>
        <p>Every action recorded in the system, newest first. Use this to trace who changed a record and when.</p>
    </div>
    <div class="adm-head-actions">
        <span class="adm-chip">
            <i class="fas fa-clock-rotate-left"></i>
            Newest entries first
        </span>
    </div>
</header>

<!-- Filters -->
<div class="adm-panel">
    <div class="adm-panel-title"><i class="fas fa-filter"></i> Narrow the log</div>
    <div class="adm-filters">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="logSearch" placeholder="Search action, table, or IP...">
        </div>
        <label class="adm-filters-label" for="userFilter">User</label>
        <select id="userFilter" class="form-control"><option value="">All users</option></select>
        <label class="adm-filters-label" for="actionFilter">Action</label>
        <select id="actionFilter" class="form-control"><option value="">All actions</option></select>
        <label class="adm-filters-label" for="fromDate">From</label>
        <input type="date" id="fromDate" class="form-control">
        <span class="adm-filters-label">to</span>
        <input type="date" id="toDate" class="form-control" aria-label="To date">
        <button class="btn btn-primary" onclick="loadLogs(1)"><i class="fas fa-filter"></i> Apply</button>
    </div>
</div>

<!-- Table -->
<div class="adm-panel">
    <div class="adm-panel-title"><i class="fas fa-list"></i> Activity</div>
    <div class="table-responsive">
    <table class="table">
        <thead>
        <tr><th>#</th><th>Action</th><th>User</th><th>Table</th><th>Record</th><th>IP Address</th><th>Timestamp</th><th style="text-align:center;">Details</th></tr>
        </thead>
        <tbody id="logBody">
            <tr><td colspan="8" style="padding:0;"><div class="adm-empty">
                <i class="fas fa-circle-notch fa-spin"></i>
                <p>Loading the log...</p>
            </div></td></tr>
        </tbody>
    </table>
    </div>

    <div class="adm-panel-foot">
        <div>Showing <strong id="showingFrom">0</strong>&ndash;<strong id="showingTo">0</strong> of <strong id="totalCount">0</strong> entries</div>
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

/* Split a timestamp into a day and a time so the column reads as a
   ledger rather than one long string. Falls back to the raw value if
   the server sent something unparseable. */
function fmtWhen(value) {
    if (!value) return { day: '—', time: '' };
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return { day: String(value), time: '' };
    const opts = { day: { year: 'numeric', month: 'short', day: 'numeric' },
                   time: { hour: '2-digit', minute: '2-digit' } };
    return { day: d.toLocaleDateString('en-GB', opts.day),
             time: d.toLocaleTimeString('en-GB', opts.time) };
}

/* Actions carry meaning, so colour them by verb. Everything used to
   render as the same green "active" pill, which made a DELETE look
   identical to a login. */
function actionChip(label) {
    const s = String(label || '').toLowerCase();
    let tone = 'v-read', icon = 'fa-circle-dot';
    if (/delete|remove|archive|purge|revoke|wipe/.test(s))        { tone = 'v-delete'; icon = 'fa-trash-can'; }
    else if (/create|add|insert|register|enroll|upload/.test(s))  { tone = 'v-create'; icon = 'fa-plus'; }
    else if (/login|logout|auth|password|otp|verify|sign/.test(s)){ tone = 'v-auth';   icon = 'fa-right-to-bracket'; }
    else if (/update|edit|change|status|approve|deny|set|enable|disable|assign|transfer/.test(s)) { tone = 'v-update'; icon = 'fa-pen'; }
    return '<span class="act ' + tone + '"><i class="fas ' + icon + '"></i>' + esc(label || 'unknown') + '</span>';
}

async function loadLogs(page) {
    currentPage = page;
    const body = document.getElementById('logBody');
    body.innerHTML = '<tr><td colspan="8" style="padding:0;"><div class="adm-empty"><i class="fas fa-circle-notch fa-spin"></i><p>Loading the log...</p></div></td></tr>';

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
        if (!d.success) { body.innerHTML = '<tr><td colspan="8" style="padding:0;"><div class="adm-empty"><i class="fas fa-triangle-exclamation"></i><p>Could not load the log</p><span>' + esc(d.message || 'Try again in a moment.') + '</span></div></td></tr>'; return; }
        renderLogs(d.data || [], d.meta || {});
    } catch(e) {
        body.innerHTML = '<tr><td colspan="8" style="padding:0;"><div class="adm-empty"><i class="fas fa-triangle-exclamation"></i><p>Could not reach the log service</p><span>Check the connection and try again.</span></div></td></tr>';
    }
}

function renderLogs(rows, meta) {
    const body = document.getElementById('logBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" style="padding:0;"><div class="adm-empty"><i class="fas fa-inbox"></i><p>No entries match these filters</p><span>Widen the date range or clear the user and action filters.</span></div></td></tr>';
    } else {
        body.innerHTML = rows.map(r => {
            const who = r.user_name ? esc(r.user_name) : (r.user_email ? esc(r.user_email) : '<span style="color:#94a3b8;">System</span>');
            const dash = '<span style="color:#cbd5e1;">—</span>';
            return '<tr>' +
                '<td class="mono">' + r.id + '</td>' +
                '<td>' + actionChip(r.action_label || r.action) + '</td>' +
                '<td>' + who + '</td>' +
                '<td><code style="font-size:11.5px;color:#6d28d9;">' + esc(r.table_name || '—') + '</code></td>' +
                '<td class="mono">' + (r.record_id ? '#' + r.record_id : dash) + '</td>' +
                '<td class="mono">' + esc(r.ip_address || '—') + '</td>' +
                '<td><div class="audit-when"><b>' + esc(fmtWhen(r.created_at).day) + '</b><span>' + esc(fmtWhen(r.created_at).time) + '</span></div></td>' +
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
