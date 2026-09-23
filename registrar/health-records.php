<?php
// ============================================================
//  REGISTRAR/HEALTH-RECORDS.PHP
//  Health Record Log - REGISTRAR PORTAL (VIEW-ONLY).
//  All health data is created by the CLINIC (nurse portal).
//  The registrar can only search, filter by date, and view the
//  synced clinic visit log. There is intentionally NO add/edit/
//  delete here - adding records is the clinic's job.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Health Record Log';
$APP_ROOT = '../';
$ACTIVE_NAV = 'health';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title"><h1>Health Record Log</h1>
    <p>View-only records synced from the Clinic Portal - record creation is the clinic's job</p></div>
    <div class="header-actions">
        <button class="btn btn-light" onclick="loadHealthLog()"><i class="fas fa-rotate"></i> Refresh</button>
    </div>
</header>

<!-- Filters: search + date range only -->
<div class="panel" style="padding:16px 18px;margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <div class="search-wrap" style="flex:1;min-width:200px;">
            <i class="fas fa-search"></i>
            <input type="text" id="hlogQ" placeholder="Search name or student ID">
        </div>
        <input type="date" id="hlogFrom" class="form-control" style="width:auto;" title="Date from">
        <span style="color:#94a3b8;">-</span>
        <input type="date" id="hlogTo" class="form-control" style="width:auto;" title="Date to">
    </div>
</div>

<div class="stats-grid" style="margin-bottom:18px;">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-file-medical"></i></div></div>
        <div class="stat-number" id="statCount">0</div><div class="stat-label">Filtered Records</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-graduate"></i></div></div>
        <div class="stat-number" id="statStudents">0</div><div class="stat-label">Distinct Students</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-check-circle"></i></div></div>
        <div class="stat-number" id="statRecorded">0</div><div class="stat-label">Recorded</div></div>
</div>

<div class="panel">
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr>
            <th style="width:90px;">Record ID</th>
            <th style="width:120px;">Student ID</th>
            <th>Student Name</th>
            <th style="width:170px;">Visit Date</th>
            <th>Reason for Visit</th>
            <th style="width:110px;">Status</th>
            <th style="width:90px;text-align:center;">Action</th>
        </tr>
        </thead>
        <tbody id="hlogBody">
            <tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">Loading health record log...</td></tr>
        </tbody>
    </table>
    </div>
    <div class="table-footer"><div class="info-text" id="hlogInfo"></div></div>
</div>

</div>
</main>

<!-- Record View Modal -->
<div class="modal-overlay" id="hrViewModal"><div class="modal-content" style="max-width:520px;">
    <div class="modal-header">
        <h3><i class="fas fa-file-medical" style="color:#2563eb;"></i> Health Record</h3>
        <button class="modal-close" onclick="closeHrView()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="hrViewBody" style="font-size:13px;color:#1e293b;"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeHrView()">Close</button>
    </div>
</div></div>

<script>
(function(){
    'use strict';
    function el(id){ return document.getElementById(id); }
    function esc(t){ return String(t==null?'':t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function ord(n){ n=Number(n); if(!n) return '-'; var s=['th','st','nd','rd'], v=n%100; return n+(s[(v-20)%10]||s[v]||s[0]); }
    function fmt(dt){ if(!dt) return '-'; var d=new Date(String(dt).replace(' ','T')); if(isNaN(d.getTime())) return esc(dt); return d.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
    function fmtDateOnly(dt){ if(!dt) return '-'; var d=new Date(String(dt).replace(' ','T')); if(isNaN(d.getTime())) return esc(dt); return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
    function statusClass(status){
        if(status === 'Recorded') return 'active';
        if(status === 'Pending') return 'warning';
        return 'inactive';
    }

    // Detail modal
    var currentHr = null;
    window.closeHrView = function(){
        el('hrViewModal').classList.remove('active');
        document.body.style.overflow = '';
    };
    document.getElementById('hrViewModal').addEventListener('click', function(e){ if(e.target===this) closeHrView(); });

    window.viewHealthRecord = function(id){
        var row = Array.prototype.find.call(document.querySelectorAll('#hlogBody tr[data-id]'), function(tr){ return tr.dataset.id === String(id); });
        if(!row) return;
        var r = JSON.parse(row.dataset.record);
        currentHr = r;
        var vitals = [];
        if(r.temperature) vitals.push(esc(r.temperature) + ' C');
        if(r.blood_pressure) vitals.push(esc(r.blood_pressure));
        var tpe = r.record_status === 'Recorded' ? 'green' : (r.record_status === 'Pending' ? 'yellow' : 'red');
        var rows = [
            ['Student ID', r.student_number],
            ['Student Name', r.student_name],
            ['Year Level', ord(r.year_level)],
            ['Program', r.course],
            ['Visit Date', fmt(r.date_time)]
        ];
        if(r.section) rows.push(['Section', r.section]);
        if(r.reason_for_visit) rows.push(['Reason for Visit', r.reason_for_visit]);
        if(r.assessment) rows.push(['Assessment', r.assessment]);
        if(r.action_taken) rows.push(['Action Taken', r.action_taken]);
        if(vitals.length) rows.push(['Vitals', vitals.join(' / ')]);
        if(r.recorded_by_name) rows.push(['Recorded By', r.recorded_by_name]);
        rows.push(['Status', '<span class="pill ' + tpe + '">' + esc(r.record_status) + '</span>']);

        el('hrViewBody').innerHTML = rows.map(function(pair){
            return '<div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #f1f5f9;">'
                + '<span style="color:#64748b;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">' + pair[0] + '</span>'
                + '<span style="font-weight:600;text-align:right;word-break:break-word;">' + pair[1] + '</span></div>';
        }).join('');
        el('hrViewModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    // Log feed
    window.loadHealthLog = function(){
        var params = {
            q: el('hlogQ').value.trim(),
            date_from: el('hlogFrom').value,
            date_to: el('hlogTo').value
        };
        var parts=[];
        Object.keys(params).forEach(function(k){ if(params[k]!=='') parts.push(k+'='+encodeURIComponent(params[k])); });
        fetch('../api/clinic.php?action=log'+(parts.length?'&'+parts.join('&'):''))
            .then(function(r){ return r.json(); })
            .then(function(d){
                var rows = (d.success && d.data) ? d.data : [];
                var body = el('hlogBody');
                if(!rows.length){
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">No health record log matches your filters.</td></tr>';
                }else{
                    body.innerHTML = rows.map(function(r){
                        var payload = JSON.stringify(r).replace(/\\/g,'\\\\').replace(/'/g,'&#39;').replace(/"/g,'&quot;');
                        var status = r.record_status || 'Pending';
                        var studentNumber = esc(r.student_number || '') || '&mdash;';
                        var reason = esc(r.reason_for_visit || '') || '&mdash;';
                        return '<tr data-id="' + r.id + '" data-record=\'' + payload + '\'>' +
                            '<td style="font-family:\'JetBrains Mono\',monospace;font-size:12px;">#' + esc(r.id) + '</td>' +
                            '<td style="font-family:\'JetBrains Mono\',monospace;font-size:12px;">' + studentNumber + '</td>' +
                            '<td><strong>' + esc(r.student_name || 'Unknown student') + '</strong>' +
                                (r.course || r.section ? '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">' + esc(r.course || '') + (r.course && r.section ? ' &middot; ' : '') + esc(r.section || '') + '</div>' : '') +
                            '</td>' +
                            '<td style="white-space:nowrap;">' + fmt(r.date_time) + '</td>' +
                            '<td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(r.reason_for_visit || '') + '">' + reason + '</td>' +
                            '<td><span class="pill ' + statusClass(status) + '">' + esc(status) + '</span></td>' +
                            '<td style="text-align:center;"><button class="action-btn view" onclick="viewHealthRecord(' + r.id + ')" title="View record"><i class="fas fa-eye"></i></button></td>' +
                        '</tr>';
                    }).join('');
                }
                var distinct = {};
                rows.forEach(function(r){ if(r.student_number) distinct[r.student_number]=1; });
                el('statCount').textContent = rows.length;
                el('statStudents').textContent = Object.keys(distinct).length;
                el('statRecorded').textContent = rows.filter(function(r){ return String(r.record_status)==='Recorded'; }).length;
                el('hlogInfo').textContent = 'Showing ' + rows.length + ' record(s) (view-only)';
            })
            .catch(function(){ el('hlogBody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:28px;color:#dc2626;">Network error loading the log.</td></tr>'; });
    };

    el('hlogQ').addEventListener('keyup', function(e){ if(e.key==='Enter') loadHealthLog(); });
    ['hlogFrom','hlogTo'].forEach(function(id){ var n=el(id); if(n) n.addEventListener('change', loadHealthLog); });
    loadHealthLog();
})();
</script>

<?php include '../includes/footer.php'; ?>
