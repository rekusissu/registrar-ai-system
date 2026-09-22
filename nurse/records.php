<?php
// ============================================================
//  NURSE/RECORDS.PHP
//  Health Record Log — NURSE PORTAL (with EDIT capability).
//  View, search, filter clinic visit log.
//  Edit assessment, action, vitals, status per record.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Health Record Log';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_records';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.filter-panel{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:16px 18px;margin-bottom:18px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.search-wrap{flex:1;min-width:200px;position:relative}
.search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none}
.search-wrap input{width:100%;padding:10px 14px 10px 40px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.search-wrap input:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff}
.filter-panel select,.filter-panel input[type="date"]{padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .15s,box-shadow .15s}
.filter-panel select:focus,.filter-panel input[type="date"]:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12)}
.btn-filter{padding:10px 20px;border:none;border-radius:12px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;box-shadow:0 4px 12px rgba(13,148,136,.25)}
.btn-filter:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(13,148,136,.35)}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
@media(max-width:700px){.stats-grid{grid-template-columns:1fr}}
.stat-card{background:#fff;border:1px solid #f1f5f9;border-radius:14px;padding:18px 20px;box-shadow:0 4px 16px rgba(15,23,42,.04);transition:box-shadow .15s}
.stat-card:hover{box-shadow:0 6px 22px rgba(15,23,42,.07)}
.stat-top{display:flex;align-items:center;margin-bottom:10px}
.stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px}
.stat-icon.blue{background:#dbeafe;color:#2563eb}
.stat-icon.green{background:#dcfce7;color:#16a34a}
.stat-icon.purple{background:#f3e8ff;color:#9333ea}
.stat-number{font-size:26px;font-weight:800;color:#0f172a;line-height:1}
.stat-label{font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.panel{background:#fff;border:1px solid #f1f5f9;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.table{width:100%;border-collapse:collapse}
.table th{background:#f8fafc;padding:12px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #f1f5f9;white-space:nowrap}
.table td{padding:12px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.table tbody tr:hover{background:#f0fdfa}
.table-footer{padding:12px 18px;display:flex;align-items:center;justify-content:space-between}
.info-text{font-size:12px;color:#94a3b8;font-weight:500}
.pill{display:inline-block;padding:4px 12px;border-radius:9999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.pill.active{background:#dcfce7;color:#16a34a}
.pill.warning{background:#fef3c7;color:#d97706}
.pill.inactive{background:#fee2e2;color:#dc2626}
.btn-edit{width:32px;height:32px;border:none;border-radius:8px;background:#f0fdfa;color:#0d9488;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:13px;transition:all .15s;border:1.5px solid #ccfbf1}
.btn-edit:hover{background:#0d9488;color:#fff;border-color:#0d9488;transform:scale(1.08)}
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.modal-overlay.active{display:flex}
.modal-box{background:#fff;border-radius:18px;width:100%;max-width:600px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25)}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #f1f5f9}
.modal-header h3{margin:0;font-size:17px;font-weight:800;color:#0f172a}
.modal-close{width:32px;height:32px;border:none;border-radius:8px;background:#f1f5f9;color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:background .15s}
.modal-close:hover{background:#e2e8f0;color:#0f172a}
.modal-body{padding:20px 24px}
.modal-footer{padding:16px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff}
.form-group textarea{resize:vertical;min-height:80px;font-family:inherit}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:500px){.form-row{grid-template-columns:1fr}}
.btn-save{padding:10px 24px;border:none;border-radius:12px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;box-shadow:0 4px 12px rgba(13,148,136,.25)}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(13,148,136,.35)}
.btn-save:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-cancel{padding:10px 24px;border:1.5px solid #e2e8f0;border-radius:12px;background:#fff;color:#64748b;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s}
.btn-cancel:hover{background:#f8fafc;border-color:#cbd5e1;color:#0f172a}
.toast{position:fixed;bottom:24px;right:24px;padding:14px 22px;border-radius:12px;font-size:13px;font-weight:600;color:#fff;z-index:2000;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(20px);opacity:0;transition:all .3s;pointer-events:none}
.toast.show{transform:translateY(0);opacity:1;pointer-events:auto}
.toast.success{background:#16a34a}
.toast.error{background:#dc2626}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

<div style="margin-bottom:14px;">
    <a href="dashboard.php" style="font-size:13px;font-weight:600;color:#0d9488;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<header class="header">
    <div class="title">
        <h1>Health Record Log</h1>
        <p>View and manage all clinic visit records — edit assessment, vitals, and status</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-light" onclick="loadHealthLog()"><i class="fas fa-rotate"></i> Refresh</button>
    </div>
</header>

<div class="filter-panel">
    <div class="filter-row">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="hlogQ" placeholder="Search name, student ID, or reason…">
        </div>
        <input type="date" id="hlogFrom" title="Date from">
        <span style="color:#94a3b8;">→</span>
        <input type="date" id="hlogTo" title="Date to">
        <select id="hlogStatus">
            <option value="">All statuses</option>
            <option value="Recorded">Recorded</option>
            <option value="Pending">Pending</option>
            <option value="Cancelled">Cancelled</option>
        </select>
        <button class="btn-filter" onclick="loadHealthLog()"><i class="fas fa-filter"></i> Filter</button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon blue"><i class="fas fa-file-medical"></i></div></div>
        <div class="stat-number" id="statCount">0</div>
        <div class="stat-label">Filtered Records</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-graduate"></i></div></div>
        <div class="stat-number" id="statStudents">0</div>
        <div class="stat-label">Distinct Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon purple"><i class="fas fa-check-circle"></i></div></div>
        <div class="stat-number" id="statRecorded">0</div>
        <div class="stat-label">Recorded</div>
    </div>
</div>

<div class="panel">
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Date &amp; Time</th>
            <th>Reason</th>
            <th>Assessment</th>
            <th>Action</th>
            <th>Vitals</th>
            <th>Status</th>
            <th style="text-align:center;">Edit</th>
        </tr>
        </thead>
        <tbody id="hlogBody">
            <tr><td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">Loading health record log…</td></tr>
        </tbody>
    </table>
    </div>
    <div class="table-footer">
        <div class="info-text" id="hlogInfo"></div>
    </div>
</div>

</div>
</main>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-pen-to-square" style="color:#0d9488;margin-right:8px;"></i> Edit Visit Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="modalStudentInfo" style="background:#f0fdfa;border-radius:12px;padding:14px 16px;margin-bottom:18px;border:1px solid #ccfbf1;">
                <div style="font-size:14px;font-weight:700;color:#0f172a;" id="modalStudentName">—</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;" id="modalStudentId">—</div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;" id="modalVisitDate">—</div>
            </div>
            <input type="hidden" id="editVisitId">
            <input type="hidden" id="editStudentId">
            <input type="hidden" id="editReasonForVisit">
            <div class="form-group">
                <label>Assessment</label>
                <textarea id="editAssessment" placeholder="Clinical assessment notes…"></textarea>
            </div>
            <div class="form-group">
                <label>Action Taken</label>
                <textarea id="editActionTaken" placeholder="Actions taken during the visit…"></textarea>
            </div>
            <div class="form-group">
                <label>Nurse Notes</label>
                <textarea id="editNurseNotes" placeholder="Additional nurse notes…"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Temperature (°C)</label>
                    <input type="text" id="editTemperature" placeholder="e.g. 36.8">
                </div>
                <div class="form-group">
                    <label>Blood Pressure</label>
                    <input type="text" id="editBloodPressure" placeholder="e.g. 120/80">
                </div>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select id="editStatus">
                    <option value="Recorded">Recorded</option>
                    <option value="Pending">Pending</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" id="saveBtn" onclick="saveVisit()"><i class="fas fa-check"></i> Save Changes</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<script>
(function(){
    'use strict';
    function el(id){ return document.getElementById(id); }
    function esc(t){ return String(t==null?'':t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function ord(n){ n=Number(n); if(!n) return '—'; var s=['th','st','nd','rd'], v=n%100; return n+(s[(v-20)%10]||s[v]||s[0]); }
    function fmt(dt){ if(!dt) return '—'; return new Date(dt.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
    function statusClass(s){ var v=String(s||'').toLowerCase(); return v==='recorded'?'active':(v==='pending'?'warning':'inactive'); }
    var CSRF=(document.querySelector('meta[name=csrf-token]')||{}).getAttribute?document.querySelector('meta[name=csrf-token]').getAttribute('content'):'';
    var _rows=[];
    function showToast(msg,type){
        var t=el('toast');t.textContent=msg;t.className='toast '+(type||'success')+' show';
        setTimeout(function(){t.className='toast';},3000);
    }
    window.loadHealthLog=function(){
        var params={q:el('hlogQ').value.trim(),date_from:el('hlogFrom').value,date_to:el('hlogTo').value,status:el('hlogStatus').value};
        var parts=[];
        Object.keys(params).forEach(function(k){if(params[k]!=='')parts.push(k+'='+encodeURIComponent(params[k]));});
        fetch('../api/clinic.php?action=log'+(parts.length?'&'+parts.join('&'):''))
        .then(function(r){return r.json();})
        .then(function(d){
            var rows=(d.success&&d.data)?d.data:[];_rows=rows;
            var body=el('hlogBody');
            if(!rows.length){
                body.innerHTML='<tr><td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">No records match filters.</td></tr>';
            }else{
                body.innerHTML=rows.map(function(r,idx){
                    var vitals=[];
                    if(r.temperature)vitals.push(r.temperature+'°C');
                    if(r.blood_pressure)vitals.push(r.blood_pressure);
                    return '<tr>'+
                        '<td style="font-size:12px;">'+esc(r.student_number)+'</td>'+
                        '<td><strong>'+esc(r.student_name)+'</strong></td>'+
                        '<td style="font-size:12px;">'+fmt(r.date_time)+'</td>'+
                        '<td>'+esc(r.reason_for_visit)+'</td>'+
                        '<td style="font-size:12px;">'+esc(r.assessment||'—')+'</td>'+
                        '<td style="font-size:12px;">'+esc(r.action_taken||'—')+'</td>'+
                        '<td style="font-size:12px;">'+(vitals.length?esc(vitals.join(' · ')):'—')+'</td>'+
                        '<td><span class="pill '+statusClass(r.record_status)+'">'+esc(r.record_status||'Pending')+'</span></td>'+
                        '<td style="text-align:center;"><button class="btn-edit" title="Edit" onclick="editVisit('+idx+')"><i class="fas fa-pen"></i></button></td>'+
                    '</tr>';
                }).join('');
            }
            var distinct={};
            rows.forEach(function(r){if(r.student_number)distinct[r.student_number]=1;});
            el('statCount').textContent=rows.length;
            el('statStudents').textContent=Object.keys(distinct).length;
            el('statRecorded').textContent=rows.filter(function(r){return String(r.record_status)==='Recorded';}).length;
            el('hlogInfo').textContent='Showing '+rows.length+' record'+(rows.length===1?'':'s');
        })
        .catch(function(){el('hlogBody').innerHTML='<tr><td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">Network error.</td></tr>';});
    };

    window.editVisit=function(idx){
        var r=_rows[idx];if(!r)return;
        el('editVisitId').value=r.id||idx;
        el('editStudentId').value=r.student_id||'';
        el('editReasonForVisit').value=r.reason_for_visit||'';
        el('editAssessment').value=r.assessment||'';
        el('editActionTaken').value=r.action_taken||'';
        el('editNurseNotes').value=r.nurse_notes||'';
        el('editTemperature').value=r.temperature||'';
        el('editBloodPressure').value=r.blood_pressure||'';
        el('editStatus').value=r.record_status||'Pending';
        el('modalStudentName').textContent=r.student_name||'—';
        el('modalStudentId').textContent='ID: '+(r.student_number||'—')+(r.course?' · '+r.course:'');
        el('modalVisitDate').textContent='Visit: '+fmt(r.date_time);
        el('editModal').classList.add('active');
        document.body.style.overflow='hidden';
    };

    window.closeModal=function(){
        el('editModal').classList.remove('active');
        document.body.style.overflow='';
    };

    window.saveVisit=function(){
        var btn=el('saveBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
        var payload={
            student_id:el('editStudentId').value,
            reason_for_visit:el('editReasonForVisit').value,
            assessment:el('editAssessment').value.trim(),
            action_taken:el('editActionTaken').value.trim(),
            nurse_notes:el('editNurseNotes').value.trim(),
            temperature:el('editTemperature').value.trim(),
            blood_pressure:el('editBloodPressure').value.trim(),
            record_status:el('editStatus').value
        };
        fetch('../api/clinic.php?action=save-visit',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
            body:JSON.stringify(payload)
        })
        .then(function(r){return r.json();})
        .then(function(d){
            if(d.success){showToast('Visit record saved.','success');closeModal();loadHealthLog();}
            else{showToast(d.message||d.error||'Save failed.','error');}
        })
        .catch(function(){showToast('Network error.','error');})
        .finally(function(){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Save Changes';});
    };

    el('editModal').addEventListener('click',function(e){if(e.target===this)closeModal();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&el('editModal').classList.contains('active'))closeModal();});
    el('hlogQ').addEventListener('keyup',function(e){if(e.key==='Enter')loadHealthLog();});
    ['hlogFrom','hlogTo','hlogStatus'].forEach(function(id){var n=el(id);if(n)n.addEventListener('change',loadHealthLog);});
    loadHealthLog();
})();
</script>
<?php include '../includes/footer.php'; ?>

