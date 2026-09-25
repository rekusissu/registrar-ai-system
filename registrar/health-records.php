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
$page_description = 'View-only clinic health record log';
$body_page = 'health-record-log';
$APP_ROOT = '../';
$extra_css = ['health-records.css'];
$ACTIVE_NAV = 'health';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
<div class="dashboard-container">

<header class="health-log-header">
    <div>
        <div class="health-log-kicker"><i class="fa-solid fa-file-medical"></i> Clinic archive</div>
        <h1>Health Record Log</h1>
        <p>View-only records synced from the Clinic Portal. New records are created by clinic staff.</p>
    </div>
    <div class="header-actions">
        <span class="readonly-chip"><i class="fa-solid fa-lock"></i> View only</span>
        <button class="btn btn-light" onclick="loadHealthLog()"><i class="fas fa-rotate"></i> Refresh log</button>
    </div>
</header>

<!-- Filters: search + date range only -->
<section class="health-log-filters" aria-label="Health record filters">
    <div class="filter-field filter-search">
        <label for="hlogQ">Find a record</label>
        <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="hlogQ" placeholder="Search name or student ID"></div>
    </div>
    <div class="filter-field"><label for="hlogFrom">From</label><input type="date" id="hlogFrom" class="form-control" title="Date from"></div>
    <div class="filter-separator" aria-hidden="true">to</div>
    <div class="filter-field"><label for="hlogTo">To</label><input type="date" id="hlogTo" class="form-control" title="Date to"></div>
</section>

<section class="health-log-stats" aria-label="Health record summary">
    <div class="health-stat"><div class="health-stat-icon records"><i class="fas fa-file-medical"></i></div><div><div class="health-stat-value" id="statCount">0</div><div class="health-stat-label">Filtered records</div></div></div>
    <div class="health-stat"><div class="health-stat-icon students"><i class="fas fa-user-graduate"></i></div><div><div class="health-stat-value" id="statStudents">0</div><div class="health-stat-label">Distinct students</div></div></div>
    <div class="health-stat"><div class="health-stat-icon recorded"><i class="fas fa-circle-check"></i></div><div><div class="health-stat-value" id="statRecorded">0</div><div class="health-stat-label">Recorded</div></div></div>
</section>

<section class="health-log-table-panel">
    <div class="health-log-table-wrap">
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
    <div class="health-log-footer"><div class="info-text" id="hlogInfo"></div></div>
</section>

</div>
</main>

<!-- Record View Modal -->
<div class="modal-overlay health-record-modal" id="hrViewModal"><div class="modal-content health-record-modal-content">
    <div class="modal-header health-record-modal-header">
        <div><div class="health-modal-kicker"><i class="fas fa-file-medical"></i> Clinic record</div><h3>Health record</h3></div>
        <button class="modal-close" onclick="closeHrView()" aria-label="Close health record"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="hrViewBody"></div>
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

    function healthAttention(r){
        if(r.record_status !== 'Recorded') return 'Clinic review';
        if(!r.action_taken || !r.assessment) return 'Incomplete';
        return '';
    }

    window.runHealthRecordReview = function(){
        if(!currentHr || !currentHr.student_id) return;
        var box = el('hrAiReview');
        var button = el('hrAiReviewBtn');
        box.innerHTML = '<div style="color:#64748b;font-size:12px"><i class="fas fa-spinner fa-spin"></i> Reviewing existing clinic records…</div>';
        if(button) button.disabled = true;
        var csrf = document.querySelector('meta[name=csrf-token]');
        fetch('../api/clinic-ai-review.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf ? csrf.content : ''}, body:JSON.stringify({student_id:currentHr.student_id})})
            .then(function(res){ return res.json(); })
            .then(function(d){
                if(!d.success) throw new Error(d.message || 'Review unavailable.');
                var latest = d.data.latest_visit || {};
                var flags = (d.data.information_flags || []).map(function(flag){ return '<li>' + esc(flag) + '</li>'; }).join('');
                var latestReason = esc(latest.reason_for_visit || 'Not recorded');
                var latestAssessment = esc(latest.assessment || 'Not recorded');
                var latestAction = esc(latest.action_taken || 'Not recorded');
                box.innerHTML = '<div class="hr-review-summary"><span>Record overview</span><strong>' + esc(d.data.summary) + '</strong></div>'
                    + '<div class="hr-review-latest"><div class="hr-review-section-head"><i class="fas fa-clock-rotate-left"></i><strong>Latest visit</strong><small>' + esc(fmt(latest.date_time || latest.visit_date)) + '</small></div><div class="hr-review-facts"><div><span>Reason</span><strong>' + latestReason + '</strong></div><div><span>Assessment</span><strong>' + latestAssessment + '</strong></div><div><span>Action</span><strong>' + latestAction + '</strong></div></div></div>'
                    + (flags ? '<div class="hr-review-notes"><div class="hr-review-section-head"><i class="fas fa-circle-info"></i><strong>Documentation notes</strong></div><ul>' + flags + '</ul></div>' : '')
                    + '<div class="hr-review-disclaimer"><i class="fas fa-shield-halved"></i><span>' + esc(d.data.disclaimer) + '</span></div>';
            })
            .catch(function(err){ box.innerHTML = '<div style="font-size:12px;color:#b45309">' + esc(err.message || 'Review unavailable.') + ' Confirm details with the Clinic Portal.</div>'; })
            .finally(function(){ if(button) button.disabled = false; });
    };

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

        var attention = healthAttention(r);
        var studentName = esc(r.student_name || 'Unknown student');
        var studentId = esc(r.student_number || 'Not available');
        var program = esc(r.course || 'Not recorded');
        var section = esc(r.section || 'Not recorded');
        var visitDate = fmt(r.date_time);
        var reason = esc(r.reason_for_visit || 'Not recorded');
        var assessment = esc(r.assessment || 'Not recorded');
        var action = esc(r.action_taken || 'Not recorded');
        var recordedBy = esc(r.recorded_by_name || 'Not recorded');
        var status = esc(r.record_status || 'Pending');
        el('hrViewBody').innerHTML = '<div class="hr-summary"><div class="hr-summary-avatar"><i class="fas fa-user-graduate"></i></div><div class="hr-summary-copy"><span>Student record</span><strong>' + studentName + '</strong><small>' + studentId + ' · ' + program + ' · ' + section + '</small></div><div class="hr-summary-status"><span class="pill ' + tpe + '">' + status + '</span><small>' + visitDate + '</small></div></div>'
            + '<div class="hr-ai-panel" id="hrAiReviewPanel"><div class="hr-ai-heading"><div><span class="hr-ai-icon"><i class="fas fa-sparkles"></i></span><strong>AI record review</strong></div><button type="button" class="btn btn-light" id="hrAiReviewBtn" onclick="runHealthRecordReview()">Review</button></div><div id="hrAiReview" class="hr-ai-copy">Summarizes existing clinic records only. It does not diagnose or edit records.</div></div>'
            + '<div class="hr-detail-grid"><div class="hr-detail"><span>Reason for visit</span><strong>' + reason + '</strong></div><div class="hr-detail"><span>Assessment</span><strong>' + assessment + '</strong></div><div class="hr-detail"><span>Action taken</span><strong>' + action + '</strong></div><div class="hr-detail"><span>Vitals</span><strong>' + (vitals.length ? vitals.join(' / ') : 'Not recorded') + '</strong></div></div>'
            + '<div class="hr-record-meta"><div><span>Recorded by</span><strong>' + recordedBy + '</strong></div><div><span>Review status</span><strong>' + (attention || 'Complete record') + '</strong></div></div>';
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
