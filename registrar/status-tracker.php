<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student Status Tracker — per-student table with inline
//  status changer, filter/search, and history modal.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();

$ALL_STATUSES = ['inactive','enrolled','active','dropped','graduated','alumni','probation','at-risk','loa','transferred','archived'];
$DB_STATUSES  = ['active','probation','at-risk','loa','enrolled','graduated','transferred','dropped'];

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';

$counts = [];
foreach ($ALL_STATUSES as $s) {
    $counts[$s] = in_array($s, $DB_STATUSES, true)
        ? (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = ?", [$s])
        : 0;
}

$sql = "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
               s.course, s.year_level, s.status,
               MAX(st.created_at) AS last_change
        FROM students s
        LEFT JOIN status_tracker st ON st.student_id = s.id";
$params = [];
$where  = [];

if ($filterStatus !== '' && in_array($filterStatus, $ALL_STATUSES, true)) {
    $where[]  = "s.status = ?";
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = "(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)";
    $like    = "%{$search}%";
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " GROUP BY s.id ORDER BY last_change DESC, s.id DESC";
$students = $db->fetchAll($sql, $params);

$page_title = 'Status Tracker';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'tracker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Student Status Tracker</h1>
                <p>Manage student statuses and view change history</p>
            </div>
            <div class="header-actions">
                <a href="students.php" class="btn btn-secondary"><i class="fas fa-user-graduate"></i> Students</a>
            </div>
        </header>
        <!-- 6 Stat Cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(6,1fr);">
            <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:#f1f5f9;color:#64748b;"><i class="fas fa-user-slash"></i></div></div><div class="stat-number"><?= $counts['inactive'] ?></div><div class="stat-label">Inactive</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-user-plus"></i></div></div><div class="stat-number"><?= $counts['enrolled'] ?></div><div class="stat-label">Enrolled</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-check"></i></div></div><div class="stat-number"><?= $counts['active'] ?></div><div class="stat-label">Active</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon red"><i class="fas fa-user-xmark"></i></div></div><div class="stat-number"><?= $counts['dropped'] ?></div><div class="stat-label">Dropped</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-graduation-cap"></i></div></div><div class="stat-number"><?= $counts['graduated'] ?></div><div class="stat-label">Graduated</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-user-group"></i></div></div><div class="stat-number"><?= $counts['alumni'] ?></div><div class="stat-label">Alumni</div></div>
        </div>
        <!-- Filter Bar -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:20px;">
            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;width:100%;">
                <select name="status" class="form-control" style="flex:1;min-width:160px;max-width:220px;" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <?php foreach ($ALL_STATUSES as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="flex:2;min-width:220px;max-width:400px;position:relative;">
                    <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search by name or Student ID..." style="padding-left:36px;" value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn btn-secondary" style="white-space:nowrap;"><i class="fas fa-search"></i> Search</button>
            </form>
        </div>
        <!-- Student Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table" id="statusTable">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Program &amp; Year Level</th>
                            <th>Date Updated</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:40px 20px;color:#94a3b8;">
                                <i class="fas fa-users" style="font-size:32px;display:block;margin-bottom:10px;color:#cbd5e1;"></i>
                                No students found.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $s):
                                $fullName = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
                                $sClass = in_array($s['status'], ['active','at-risk','probation','graduated','loa','transferred','dropped','archived'], true) ? $s['status'] : 'enrolled';
                                $dateStr = $s['last_change'] ? date('M d, Y', strtotime($s['last_change'])) : '—';
                                $yearLabels = ['1'=>'1st Year','2'=>'2nd Year','3'=>'3rd Year','4'=>'4th Year'];
                                $yearText = isset($yearLabels[$s['year_level']]) ? $yearLabels[$s['year_level']] : ($s['year_level'] ? 'Year '.$s['year_level'] : '');
                                $program = trim(($s['course'] ?? '') . ($yearText ? ' — '.$yearText : ''));
                            ?>
                            <tr data-student-id="<?= $s['id'] ?>" data-status="<?= htmlspecialchars($s['status']) ?>">
                                <td><span style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($s['student_number']) ?></span></td>
                                <td><span style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($fullName) ?></span></td>
                                <td><span style="font-size:13px;color:#475569;"><?= htmlspecialchars($program) ?: '—' ?></span></td>
                                <td><span style="font-size:13px;color:#64748b;"><?= $dateStr ?></span></td>
                                <td><span class="pill <?= $sClass ?>"><?= ucfirst(htmlspecialchars($s['status'])) ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <select class="form-control status-select" style="width:auto;min-width:130px;font-size:12px;padding:4px 8px;" data-student-id="<?= $s['id'] ?>" data-current-status="<?= htmlspecialchars($s['status']) ?>">
                                            <?php foreach ($ALL_STATUSES as $st): ?>
                                                <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-secondary btn-sm history-btn" data-student-id="<?= $s['id'] ?>" data-student-name="<?= htmlspecialchars($fullName) ?>" data-student-number="<?= htmlspecialchars($s['student_number']) ?>" style="font-size:12px;padding:4px 10px;" title="View History">
                                            <i class="fas fa-clock-rotate-left"></i> View
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- History Modal -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h2><i class="fas fa-clock-rotate-left" style="color:#2563eb;"></i> Status Change History</h2>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="historyBody"></div>
    </div>
</div>

<style>
.timeline{position:relative;padding-left:24px}
.timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:#e2e8f0}
.tl-item{position:relative;margin-bottom:14px;background:#fafcfd;border:1px solid #f1f5f9;border-radius:10px;padding:12px 16px}
.tl-item::before{content:'';position:absolute;left:-22px;top:16px;width:12px;height:12px;border-radius:50%;background:#fff;border:3px solid #2563eb}
.tl-item.active::before{border-color:#16a34a}.tl-item.enrolled::before{border-color:#2563eb}
.tl-item.at-risk::before{border-color:#dc2626}.tl-item.probation::before{border-color:#b45309}
.tl-item.graduated::before{border-color:#7c3aed}.tl-item.loa::before{border-color:#7c3aed}
.tl-item.transferred::before{border-color:#db2777}.tl-item.dropped::before{border-color:#dc2626}
.tl-item.archived::before{border-color:#94a3b8}.tl-item.inactive::before{border-color:#64748b}
.tl-item.alumni::before{border-color:#b45309}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)!important}}
@media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr)!important}}
</style>

<script>
(function(){
'use strict';
/* Status change via dropdown */
document.querySelectorAll('.status-select').forEach(function(sel){
    sel.addEventListener('change',function(){
        var sid=this.dataset.studentId,old=this.dataset.currentStatus,next=this.value,row=this.closest('tr'),self=this;
        if(old===next)return;
        if(!confirm('Change status to "'+cap(next)+'"?')){self.value=old;return;}
        self.disabled=true;
        fetch('../api/students.php?action=bulk-status',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({ids:[parseInt(sid)],status:next})})
        .then(function(r){return r.json();}).then(function(d){
            self.disabled=false;
            if(d.success){
                self.dataset.currentStatus=next;
                var pill=row.querySelector('.pill');
                if(pill){['active','at-risk','probation','graduated','loa','transferred','dropped','archived','enrolled','inactive','alumni'].forEach(function(c){pill.classList.remove(c);});pill.classList.add(sc(next));pill.textContent=cap(next);}
                var dc=row.querySelectorAll('td')[3];
                if(dc){var now=new Date();dc.innerHTML='<span style="font-size:13px;color:#64748b;">'+now.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+'</span>';}
                row.dataset.status=next;toast('Status updated to '+cap(next),'success');
            }else{self.value=old;toast(d.message||'Update failed','error');}
        }).catch(function(){self.disabled=false;self.value=old;toast('Network error','error');});
    });
});
/* View History Modal */
document.querySelectorAll('.history-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
        var sid=this.dataset.studentId,name=this.dataset.studentName,number=this.dataset.studentNumber;
        openModal();
        var body=document.getElementById('historyBody');
        body.innerHTML='<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:20px;"></i><p style="margin-top:8px;">Loading history...</p></div>';
        fetch('../api/status-history.php?student_id='+sid)
        .then(function(r){if(!r.ok)throw 0;return r.json();})
        .then(function(d){renderHistory(d.data||[]);})
        .catch(function(){body.innerHTML='<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-clock-rotate-left" style="font-size:24px;display:block;margin-bottom:8px;color:#cbd5e1;"></i><p>No status history endpoint found.</p></div>';});
    });
});
function renderHistory(entries){
    var body=document.getElementById('historyBody');
    if(!entries.length){body.innerHTML='<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-clock-rotate-left" style="font-size:24px;display:block;margin-bottom:8px;color:#cbd5e1;"></i><p>No status changes recorded yet.</p></div>';return;}
    var h='<div class="timeline">';
    entries.forEach(function(e){
        var prev=e.previous_status||'\u2014',curr=e.current_status||'unknown';
        var dt=e.created_at?new Date(e.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}):'\u2014';
        h+='<div class="tl-item '+sc(curr)+'"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;"><div>';
        if(prev!=='\u2014')h+='<span class="pill '+sc(prev)+'">'+cap(prev)+'</span> <i class="fas fa-arrow-right" style="font-size:10px;color:#94a3b8;margin:0 6px;"></i>';
        h+='<span class="pill '+sc(curr)+'">'+cap(curr)+'</span></div>';
        h+='<div style="font-size:12px;color:#64748b;"><i class="fas fa-calendar-day"></i> '+dt+'</div></div>';
        if(e.reason)h+='<div style="font-size:13px;color:#475569;margin-top:6px;"><i class="fas fa-comment"></i> '+esc(e.reason)+'</div>';
        if(e.changed_by_name)h+='<div style="font-size:11px;color:#94a3b8;margin-top:4px;">By '+esc(e.changed_by_name)+'</div>';
        h+='</div>';
    });h+='</div>';body.innerHTML=h;
}
function sc(s){return['active','at-risk','probation','graduated','loa','transferred','dropped','archived'].indexOf(s)!==-1?s:'enrolled';}
function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}
function esc(s){if(!s)return'';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
window.openModal=function(){var m=document.getElementById('historyModal');if(m){m.classList.add('active');document.body.style.overflow='hidden';}};
window.closeModal=function(){var m=document.getElementById('historyModal');if(m){m.classList.remove('active');document.body.style.overflow='';}};
document.getElementById('historyModal').addEventListener('click',function(e){if(e.target===this)closeModal();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&document.getElementById('historyModal').classList.contains('active'))closeModal();});
window.toast=function(msg,type){var c=document.getElementById('toastContainer');if(!c)return;var t=document.createElement('div');t.className='toast '+(type||'info');t.innerHTML='<span>'+esc(msg)+'</span>';c.appendChild(t);setTimeout(function(){t.classList.add('show');},10);setTimeout(function(){t.classList.remove('show');setTimeout(function(){t.remove();},300);},3500);};
})();
</script>

<?php include '../includes/footer.php'; ?>