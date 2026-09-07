<?php
// ============================================================
//  REGISTRAR/HEALTH-RECORDS.PHP
//  Health Record Log — REGISTRAR PORTAL (VIEW-ONLY).
//  All health data is created by the CLINIC (nurse portal).
//  The registrar can only search, filter, and view the synced
//  clinic visit log. There is intentionally NO add/edit/delete
//  here — adding records is the clinic's job.
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
    <div class="title"><h1><i class="fas fa-heartbeat" style="color:#dc2626;"></i> Health Record Log</h1>
    <p>View-only records synced from the Clinic Portal — record creation is the clinic's job</p></div>
    <div class="header-actions">
        <button class="btn btn-light" onclick="loadHealthLog()"><i class="fas fa-rotate"></i> Refresh</button>
    </div>
</header>

<div class="panel" style="padding:16px 18px;margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <div class="search-wrap" style="flex:1;min-width:200px;">
            <i class="fas fa-search"></i>
            <input type="text" id="hlogQ" placeholder="Search name, student ID, or reason…">
        </div>
        <input type="date" id="hlogFrom" class="form-control" style="width:auto;" title="Date from">
        <span style="color:#94a3b8;">→</span>
        <input type="date" id="hlogTo" class="form-control" style="width:auto;" title="Date to">
        <select id="hlogReason" class="form-control" style="width:auto;"><option value="">All reasons</option></select>
        <select id="hlogStatus" class="form-control" style="width:auto;">
            <option value="">All statuses</option>
            <option value="Recorded">Recorded</option>
            <option value="Pending">Pending</option>
            <option value="Cancelled">Cancelled</option>
        </select>
        <button class="btn btn-primary" onclick="loadHealthLog()"><i class="fas fa-filter"></i> Filter</button>
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
        <tr><th>Student ID</th><th>Student</th><th>Program</th><th>Year / Section</th>
            <th>Date &amp; Time</th><th>Reason</th><th>Assessment</th><th>Action</th>
            <th>Vitals</th><th>Recorded By</th><th>Status</th></tr>
        </thead>
        <tbody id="hlogBody">
            <tr><td colspan="11" style="text-align:center;padding:28px;color:#94a3b8;">Loading health record log…</td></tr>
        </tbody>
    </table>
    </div>
    <div class="table-footer"><div class="info-text" id="hlogInfo"></div></div>
</div>

</div>
</main>
<script>
// Health Record Log — read-only feed from the Clinic Portal
(function(){
    'use strict';
    function el(id){ return document.getElementById(id); }
    function esc(t){ return String(t==null?'':t).replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function ord(n){ n=Number(n); if(!n) return '—'; var s=['th','st','nd','rd'], v=n%100; return n+(s[(v-20)%10]||s[v]||s[0]); }
    function fmt(dt){ if(!dt) return '—'; return new Date(dt.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
    function statusClass(s){ var v=String(s||'').toLowerCase(); return v==='recorded'?'active':(v==='pending'?'warning':'inactive'); }

    var reasonBuilt = false;
    window.loadHealthLog = function(){
        var params = {
            q: el('hlogQ').value.trim(),
            date_from: el('hlogFrom').value,
            date_to: el('hlogTo').value,
            reason: el('hlogReason').value,
            status: el('hlogStatus').value
        };
        var parts=[];
        Object.keys(params).forEach(function(k){ if(params[k]!=='') parts.push(k+'='+encodeURIComponent(params[k])); });
        fetch('../api/clinic.php?action=log'+(parts.length?'&'+parts.join('&'):''))
            .then(function(r){ return r.json(); })
            .then(function(d){
                var rows = (d.success && d.data) ? d.data : [];
                var body = el('hlogBody');
                if(!rows.length){
                    body.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:28px;color:#94a3b8;">No health record log matches your filters.</td></tr>';
                }else{
                    body.innerHTML = rows.map(function(r){
                        var vitals=[];
                        if(r.temperature) vitals.push(r.temperature+'°C');
                        if(r.blood_pressure) vitals.push(r.blood_pressure);
                        return '<tr>' +
                            '<td style="font-size:12px;">'+esc(r.student_number)+'</td>' +
                            '<td><strong>'+esc(r.student_name)+'</strong></td>' +
                            '<td style="font-size:12px;">'+esc(r.course||'—')+'</td>' +
                            '<td style="font-size:12px;">'+ord(r.year_level)+' · '+(r.section?esc(r.section):'—')+'</td>' +
                            '<td style="font-size:12px;">'+fmt(r.date_time)+'</td>' +
                            '<td>'+esc(r.reason_for_visit)+'</td>' +
                            '<td style="font-size:12px;">'+esc(r.assessment||'—')+'</td>' +
                            '<td style="font-size:12px;">'+esc(r.action_taken)+'</td>' +
                            '<td style="font-size:12px;">'+(vitals.length?esc(vitals.join(' · ')):'—')+'</td>' +
                            '<td style="font-size:12px;">'+esc(r.recorded_by_name||'—')+'</td>' +
                            '<td><span class="pill '+statusClass(r.record_status)+'">'+esc(r.record_status)+'</span></td>' +
                        '</tr>';
                    }).join('');
                }
                var distinct = {};
                rows.forEach(function(r){ if(r.student_number) distinct[r.student_number]=1; });
                el('statCount').textContent = rows.length;
                el('statStudents').textContent = Object.keys(distinct).length;
                el('statRecorded').textContent = rows.filter(function(r){ return String(r.record_status)==='Recorded'; }).length;
                el('hlogInfo').textContent = 'Showing ' + rows.length + ' of ' + rows.length + ' records (view-only)';
                if(!reasonBuilt){
                    var opts=['<option value="">All reasons</option>'], seen={};
                    rows.forEach(function(r){ if(r.reason_for_visit && !seen[r.reason_for_visit]){ seen[r.reason_for_visit]=1; opts.push('<option value="'+esc(r.reason_for_visit)+'">'+esc(r.reason_for_visit)+'</option>'); } });
                    el('hlogReason').innerHTML = opts.join('');
                    reasonBuilt=true;
                }
            })
            .catch(function(){ el('hlogBody').innerHTML='<tr><td colspan="11" style="text-align:center;padding:28px;color:#94a3b8;">Network error loading the log.</td></tr>'; });
    };

    el('hlogQ').addEventListener('keyup', function(e){ if(e.key==='Enter') loadHealthLog(); });
    ['hlogFrom','hlogTo','hlogStatus'].forEach(function(id){ var n=el(id); if(n) n.addEventListener('change', loadHealthLog); });
    loadHealthLog();
})();
</script>
<?php include '../includes/footer.php'; ?>