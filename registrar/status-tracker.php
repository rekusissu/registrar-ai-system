<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student Status Tracker â€” AI-powered decision console.
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
$STATUS_META = [
    'inactive'    => ['color'=>'#94a3b8','bg'=>'#f1f5f9','icon'=>'fas fa-user-slash'],
    'enrolled'    => ['color'=>'#2563eb','bg'=>'#eff6ff','icon'=>'fas fa-user-plus'],
    'active'      => ['color'=>'#16a34a','bg'=>'#f0fdf4','icon'=>'fas fa-user-check'],
    'dropped'     => ['color'=>'#dc2626','bg'=>'#fef2f2','icon'=>'fas fa-user-xmark'],
    'graduated'   => ['color'=>'#7c3aed','bg'=>'#f5f3ff','icon'=>'fas fa-graduation-cap'],
    'alumni'      => ['color'=>'#0891b2','bg'=>'#ecfeff','icon'=>'fas fa-users'],
    'probation'   => ['color'=>'#d97706','bg'=>'#fffbeb','icon'=>'fas fa-exclamation-triangle'],
    'at-risk'     => ['color'=>'#ef4444','bg'=>'#fef2f2','icon'=>'fas fa-shield-halved'],
    'loa'         => ['color'=>'#6366f1','bg'=>'#eef2ff','icon'=>'fas fa-pause-circle'],
    'transferred' => ['color'=>'#0d9488','bg'=>'#f0fdfa','icon'=>'fas fa-right-left'],
    'archived'    => ['color'=>'#6b7280','bg'=>'#f9fafb','icon'=>'fas fa-box-archive'],
];

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';

$counts = [];
foreach ($ALL_STATUSES as $s) {
    $counts[$s] = in_array($s, $DB_STATUSES, true) ? (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = ?", [$s]) : 0;
}

$totalStudents    = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$monthStart       = date('Y-m-01 00:00:00');
$prevMonthStart   = date('Y-m-01 00:00:00', strtotime('-1 month'));
$changesThisMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ?", [$monthStart]);
$changesPrevMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ? AND created_at < ?", [$prevMonthStart, $monthStart]);
$changesLast7d    = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$attentionNeeded  = $counts['at-risk'] + $counts['probation'];

$distData = [];
foreach ($DB_STATUSES as $s) { $distData[$s] = $totalStudents > 0 ? round(($counts[$s] / $totalStudents) * 100, 1) : 0; }

$activityFeed = $db->fetchAll("SELECT st.*, s.first_name, s.last_name, s.student_number, s.status AS current_student_status, u.full_name AS changed_by_name FROM status_tracker st JOIN students s ON s.id = st.student_id LEFT JOIN users u ON u.id = st.changed_by ORDER BY st.created_at DESC LIMIT 20");

$sql = "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, s.status, s.photo, MAX(st.created_at) AS last_change FROM students s LEFT JOIN status_tracker st ON st.student_id = s.id";
$params = []; $where = [];
if ($filterStatus !== '' && in_array($filterStatus, $ALL_STATUSES, true)) { $where[] = "s.status = ?"; $params[] = $filterStatus; }
if ($search !== '') { $where[] = "(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)"; $like = "%{$search}%"; $params = array_merge($params, [$like, $like, $like, $like]); }
if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
$sql .= " GROUP BY s.id ORDER BY last_change DESC, s.id DESC";
$students = $db->fetchAll($sql, $params);


$page_title = 'Status Tracker';
$page_description = 'Student status monitoring and activity tracker';
$body_page = 'status-tracker';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'tracker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<link rel="stylesheet" href="../css/status-tracker.css">
<main class="dashboard-main">
<header class="header">
    <div class="title">
      <div class="st-kicker"><i class="fas fa-chart-line"></i> Registrar intelligence</div>
      <h1>Status Tracker</h1>
      <p>Monitor student status changes, review activity, and identify students who need attention.</p>
    </div>
</header>
<div class="st-wrap">
<!-- KPI Cards -->
<div class="st-kpi-strip">
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--brand-50);color:var(--brand-500)"><i class="fas fa-users"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($totalStudents) ?></div>
      <div class="st-kpi-label">Total Students</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--success-100);color:var(--success-600)"><i class="fas fa-arrow-right-arrow-left"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesThisMonth) ?>
        <?php $delta = $changesThisMonth - $changesPrevMonth; if ($delta !== 0): ?>
          <span class="st-delta <?= $delta > 0 ? 'up' : 'down' ?>"><?= $delta > 0 ? '+' : '' ?><?= $delta ?></span>
        <?php endif; ?>
      </div>
      <div class="st-kpi-label">Changes This Month</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--danger-100);color:var(--danger-600)"><i class="fas fa-triangle-exclamation"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($attentionNeeded) ?></div>
      <div class="st-kpi-label">Attention Needed</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#eef2ff;color:var(--purple-500)"><i class="fas fa-calendar-week"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesLast7d) ?></div>
      <div class="st-kpi-label">Last 7 Days</div>
    </div>
  </div>
</div>


<!-- AI Command Bar -->
<div class="st-ai-bar">
  <div class="st-ai-bar-label"><i class="fas fa-robot"></i> AI Tools</div>
  <button class="st-ai-btn" onclick="runAI('report')" id="btnAIReport"><i class="fas fa-file-lines"></i> AI Report</button>
  <button class="st-ai-btn" onclick="runAI('anomalies')" id="btnAIAnomalies"><i class="fas fa-magnifying-glass-chart"></i> Scan Anomalies</button>
  <button class="st-ai-btn" onclick="runAI('risks')" id="btnAIRisks"><i class="fas fa-shield-halved"></i> Risk Assessment</button>
  <button class="st-ai-btn" onclick="runAI('recommendations')" id="btnAIRecs"><i class="fas fa-lightbulb"></i> Recommendations</button>
  <div class="st-ai-sep"></div>
  <button class="st-ai-btn st-ai-run-all" onclick="runAI('all')" id="btnAIAll"><i class="fas fa-bolt"></i> Run All</button>
</div>

<!-- AI Output Panel -->
<div class="st-ai-output" id="aiOutput">
  <div class="st-ai-output-hdr">
    <div class="st-ai-output-title" id="aiOutputTitle"><i class="fas fa-robot"></i> <span id="aiOutputLabel">Output</span> <span class="st-ai-output-badge" id="aiOutputCount" style="display:none">0</span></div>
    <button class="st-ai-output-close" onclick="closeAIOutput()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="st-ai-output-body" id="aiOutputBody"></div>
</div>

<!-- Status Distribution -->
<div class="st-dist">
  <div class="st-dist-title">Status Distribution</div>
  <div class="st-dist-bar" id="distBar"></div>
  <div class="st-dist-legend" id="distLegend"></div>
</div>

<!-- Filters -->
<div class="st-filters">
  <a href="?" class="st-pill <?= $filterStatus === '' ? 'active' : '' ?>">All</a>
  <?php foreach ($DB_STATUSES as $s): ?>
    <a href="?status=<?= $s ?>" class="st-pill <?= $filterStatus === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <span class="st-pill-count"><?= $counts[$s] ?></span></a>
  <?php endforeach; ?>
  <div class="st-search">
    <i class="fas fa-search"></i>
    <input type="text" id="searchInput" placeholder="Search students..." value="<?= htmlspecialchars($search) ?>">
  </div>
</div>

<!-- Two Panel Layout -->
<div class="st-panels">
  <!-- Student Table -->
  <div class="st-table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Student ID</th>
          <th>Course</th>
          <th>Year</th>
          <th>Status</th>
          <th>Risk</th>
          <th>Last Changed</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($students)): ?>
          <tr><td colspan="8" class="st-empty" style="text-align:center;padding:48px;color:var(--text-subtle)"><i class="fas fa-users-slash" style="font-size:32px;display:block;margin-bottom:12px"></i>No students found</td></tr>
        <?php else: ?>
          <?php $rowNum = 0; foreach ($students as $s):
            $rowNum++;
            $initials = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
            $sm = $STATUS_META[$s['status']] ?? $STATUS_META['inactive'];
          ?>
          <tr style="cursor:pointer" onclick="openStudentModal(<?= $s['id'] ?>,'<?= htmlspecialchars(addslashes($s['first_name'].' '.$s['last_name'])) ?>','<?= htmlspecialchars($s['student_number']) ?>')">
            <td style="font-weight:600;font-size:12px;color:var(--text-faint)"><?= $rowNum ?></td>
            <td>
              <div class="st-t-info">
                <div class="st-t-av" style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>"><?= $initials ?></div>
                <div>
                  <div class="st-t-name"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                  <?php if (!empty($s['middle_name'])): ?>
                    <div class="st-t-num"><?= htmlspecialchars($s['middle_name']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="font-weight:600;font-size:12px;white-space:nowrap"><?= htmlspecialchars($s['student_number']) ?></td>
            <td style="white-space:nowrap"><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
            <td style="white-space:nowrap"><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
            <td>
              <div class="st-badge" style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>">
                <span class="st-badge-dot" style="background:<?= $sm['color'] ?>"></span>
                <?= ucfirst($s['status']) ?>
              </div>
            </td>
            <td style="text-align:center">
              <span class="st-rdot loading" data-student-id="<?= $s['id'] ?>"></span>
            </td>
            <td style="font-size:12px;color:var(--text-faint);white-space:nowrap">
              <?php if (!empty($s['last_change'])): ?>
                <?= date('M d, Y', strtotime($s['last_change'])) ?>
              <?php else: ?>
                <span style="color:var(--text-subtle)">â€”</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Activity Timeline -->
  <div class="st-tl">
    <div class="st-tl-h"><i class="fas fa-clock-rotate-left"></i> Recent Activity</div>
    <?php if (empty($activityFeed)): ?>
      <div class="st-empty"><i class="fas fa-inbox"></i>No activity yet</div>
    <?php else: ?>
    <div class="st-tl-b">
      <?php foreach ($activityFeed as $af):
        $meta = $STATUS_META[$af['current_status']] ?? $STATUS_META['inactive'];
      ?>
      <div class="st-tl-i">
        <div class="st-tl-dot" style="background:<?= $meta['color'] ?>"></div>
        <div class="st-tl-nm"><?= htmlspecialchars($af['first_name'].' '.$af['last_name']) ?></div>
        <div class="st-tl-chg">
          <span class="st-badge" style="background:<?= ($STATUS_META[$af['previous_status']]??$STATUS_META['inactive'])['bg'] ?>;color:<?= ($STATUS_META[$af['previous_status']]??$STATUS_META['inactive'])['color'] ?>;padding:2px 6px;font-size:10px"><?= ucfirst($af['previous_status']) ?></span>
          <i class="fas fa-arrow-right" style="color:var(--text-subtle);font-size:10px;margin:0 4px"></i>
          <span class="st-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;padding:2px 6px;font-size:10px"><?= ucfirst($af['current_status']) ?></span>
        </div>
        <?php if (!empty($af['reason'])): ?>
          <div class="st-tl-rsn"><?= htmlspecialchars($af['reason']) ?></div>
        <?php endif; ?>
        <div class="st-tl-time"><i class="fas fa-user" style="font-size:9px"></i> <?= htmlspecialchars($af['changed_by_name'] ?? 'System') ?> &middot; <?= date('M d, g:i A', strtotime($af['created_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div><!-- /st-panels -->
</div><!-- /st-wrap -->
</main><!-- /dashboard-main -->

<!-- History Modal -->
<div class="st-modal-overlay" id="studentModal">
  <div class="st-modal">
    <div class="st-modal-header">
      <div class="st-modal-header-info">
        <div id="modalAvatar" class="st-modal-header-avatar" style="background:var(--brand-500)"></div>
        <div>
          <div class="st-modal-header-name" id="modalName"></div>
          <div class="st-modal-header-num" id="modalNumber"></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button class="st-btn-sm st-btn-apply" id="btnModalAIProfile" onclick="runModalAIProfile()"><i class="fas fa-robot"></i> Run Full AI Profile</button>
        <button class="st-modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
      </div>
    </div>
    <div class="st-modal-ai" id="modalAI">
      <div class="st-modal-ai-lbl"><i class="fas fa-robot"></i> AI Brief</div>
      <div class="st-modal-ai-txt" id="modalAIText">Loading...</div>
      <div class="st-modal-ai-rec" id="modalAIRec"></div>
    </div>
    <div class="st-modal-timeline">
      <div class="st-modal-timeline-h"><i class="fas fa-clock-rotate-left"></i> Status History</div>
      <div id="modalTimeline">
        <div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="st-toast" id="toast"></div>

<script>
'use strict';
(function(){
const STATUS_META=<?= json_encode($STATUS_META) ?>;
const ALL_STATUSES=<?= json_encode($ALL_STATUSES) ?>;
const DB_STATUSES=<?= json_encode($DB_STATUSES) ?>;
const SEARCH_DELAY=400;
let searchTimer=null;

/* --- Distribution Bar --- */
(function(){
const distData=<?= json_encode($distData) ?>;
const bar=document.getElementById('distBar');
const legend=document.getElementById('distLegend');
if(!bar)return;
DB_STATUSES.forEach(s=>{
const pct=distData[s]||0;
const meta=STATUS_META[s]||{color:'#94a3b8'};
const seg=document.createElement('div');
seg.className='st-dist-seg';
seg.style.width=pct+'%';
seg.style.background=meta.color;
seg.title=s+': '+pct+'%';
bar.appendChild(seg);
const item=document.createElement('div');
item.className='st-dist-item';
item.innerHTML='<span class="st-dist-dot" style="background:'+meta.color+'"></span>'+s.charAt(0).toUpperCase()+s.slice(1)+' ('+pct+'%)';
legend.appendChild(item);
});
})();

/* --- Helpers --- */
function escapeHTML(str){const d=document.createElement('div');d.textContent=str;return d.innerHTML;}
function toast(msg,type){
const el=document.getElementById('toast');
if(!el)return;
el.textContent=msg;
el.className='st-toast '+(type||'')+' show';
setTimeout(()=>el.classList.remove('show'),3000);
}
window.toast=toast;

/* --- AI Command Bar --- */
let activeAITab=null;

function setActiveTab(tab){
document.querySelectorAll('.st-ai-btn').forEach(b=>b.classList.remove('active'));
if(tab==='all'){document.getElementById('btnAIAll')?.classList.add('active');return;}
const btnMap={report:'btnAIReport',anomalies:'btnAIAnomalies',risks:'btnAIRisks',recommendations:'btnAIRecs'};
const btn=document.getElementById(btnMap[tab]);
if(btn)btn.classList.add('active');
}

function showAILoading(label){
const out=document.getElementById('aiOutput');
const title=document.getElementById('aiOutputLabel');
const body=document.getElementById('aiOutputBody');
const badge=document.getElementById('aiOutputCount');
if(!out)return;
out.classList.add('show');
title.textContent=label||'Output';
badge.style.display='none';
body.innerHTML='<div class="st-ai-output-loading"><i class="fas fa-spinner fa-spin"></i> Running AI analysis...</div>';
}

function closeAIOutput(){
const o=document.getElementById('aiOutput');
if(o)o.classList.remove('show');
document.querySelectorAll('.st-ai-btn').forEach(b=>b.classList.remove('active'));
activeAITab=null;
}
window.closeAIOutput=closeAIOutput;

function renderRecCards(recs){
if(!recs||!recs.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No recommendations</div>';
let h='';recs.forEach((rec,i)=>{
const sv=rec.severity||'low';
const cls=sv==='high'?'sv-high':sv==='med'?'sv-med':'sv-low';
h+='<div class="st-rec '+cls+'" id="rec-'+i+'"><div class="st-rec-info"><div class="st-rec-title">'+escapeHTML(rec.type||'Status Recommendation')+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(rec.student_name)+' <span class="st-ai-src">'+escapeHTML(rec.student_number||'')+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(rec.reason)+'</div>';
h+='<div class="st-rec-acts"><button class="st-btn-apply" onclick="applyRec('+i+','+rec.student_id+',\''+escapeHTML(rec.recommended_status)+'\')">Apply</button>';
h+='<button class="st-btn-dismiss" onclick="dismissRec('+i+')">Dismiss</button></div></div></div>';
});return h;
}

function renderAnomCards(anoms){
if(!anoms||!anoms.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No anomalies</div>';
let h='';anoms.forEach((a,i)=>{
const msg=a.label||a.message||JSON.stringify(a);
h+='<div class="st-rec sv-med" id="anom-'+i+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-magnifying-glass-chart"></i> Anomaly</div>';
h+='<div class="st-rec-reason">'+escapeHTML(msg)+'</div></div></div>';
});return h;
}

function renderRiskCards(risks){
if(!risks||typeof risks!=='object'||!Object.keys(risks).length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No risk data</div>';
let h='';Object.keys(risks).forEach(id=>{
const risk=risks[id];const level=risk.risk||'low';
const cls=level==='high'?'sv-high':level==='medium'?'sv-med':'sv-low';
h+='<div class="st-rec '+cls+'" id="risk-'+id+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-shield-halved"></i> Student #'+escapeHTML(id)+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(risk.name||'Student #'+id)+' <span class="st-ai-src">Risk: '+escapeHTML(level)+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(risk.reason||'No reason')+'</div></div></div>';
});return h;
}

async function fetchAIEndpoint(action,body){
let url='../api/ai-tools.php?action='+action;
const opts={method:'GET'};
if(body){opts.method='POST';opts.headers={'Content-Type':'application/json'};opts.body=JSON.stringify(body);}
const r=await fetch(url,opts);
if(!r.ok)throw new Error('API error');
return await r.json();
}

async function runAI(tab){
activeAITab=tab;setActiveTab(tab);
const label=tab==='all'?'All AI Tools':tab.charAt(0).toUpperCase()+tab.slice(1);
showAILoading(label);
const badge=document.getElementById('aiOutputCount');
const body=document.getElementById('aiOutputBody');
try{
if(tab==='all'){
const results=await Promise.allSettled([
fetchAIEndpoint('report'),
fetchAIEndpoint('status_recommendations'),
fetchAIEndpoint('status_anomalies'),
fetchAIEndpoint('status_risks',{student_ids:[]})
]);
let html='',count=0;
const labels=['Report','Recommendations','Anomalies','Risks'];
results.forEach((res,i)=>{
let content='';let cnt=0;
if(res.status==='fulfilled'){
const d=res.value;
if(i===0){const t=d.data?.report||d.report||'';content='<div class="st-ai-out-text">'+escapeHTML(t)+'</div>';cnt=t?1:0;}
else if(i===1){const r=d.data?.recommendations||d.recommendations||[];content=renderRecCards(r);cnt=r.length;}
else if(i===2){const a=d.data?.anomalies||d.anomalies||[];content=renderAnomCards(a);cnt=a.length;}
else if(i===3){const r=d.data?.risks||d.risks||{};content=renderRiskCards(r);cnt=Object.keys(r).length;}
}
html+='<div class="st-ai-section"><div class="st-ai-section-hdr">'+labels[i]+' ('+cnt+')</div>'+content+'</div>';
count+=cnt;
});
body.innerHTML=html;badge.textContent=count;badge.style.display=count>0?'inline-block':'none';
}else{
const epMap={report:'report',anomalies:'status_anomalies',risks:'status_risks',recommendations:'status_recommendations'};
const postBody=tab==='risks'?{student_ids:[]}:undefined;
const data=await fetchAIEndpoint(epMap[tab]||'status_recommendations',postBody);
let html='',count=0;
if(tab==='report'){const t=data.data?.report||data.report||'';html='<div class="st-ai-out-text">'+escapeHTML(t)+'</div>';count=t?1:0;}
else if(tab==='recommendations'){const r=data.data?.recommendations||data.recommendations||[];html=renderRecCards(r);count=r.length;}
else if(tab==='anomalies'){const a=data.data?.anomalies||data.anomalies||[];html=renderAnomCards(a);count=a.length;}
else if(tab==='risks'){const r=data.data?.risks||data.risks||{};html=renderRiskCards(r);count=Object.keys(r).length;}
body.innerHTML=html;badge.textContent=count;badge.style.display=count>0?'inline-block':'none';
}
}catch(e){
body.innerHTML='<div style="text-align:center;padding:24px;color:var(--text-subtle)"><i class="fas fa-exclamation-circle"></i> Unable to load AI data.</div>';
}
}
window.runAI=runAI;

function applyRec(idx,studentId,status){
const btn=document.querySelector('#rec-'+idx+' .st-btn-apply');
if(!btn)return;btn.textContent='Applying...';btn.classList.add('applying');
fetch('../api/students.php?action=bulk-status',{method:'POST',headers:{'Content-Type':'application/json'},
body:JSON.stringify({ids:[parseInt(studentId)],status:status})
}).then(r=>{if(r.ok){btn.textContent='Applied';btn.classList.remove('applying');btn.classList.add('applied');
toast('Status updated to '+status,'success');setTimeout(()=>location.reload(),1500);}else throw new Error();
}).catch(()=>{btn.textContent='Apply';btn.classList.remove('applying');toast('Failed to update status','error');});
}
window.applyRec=applyRec;

function dismissRec(idx){
const card=document.getElementById('rec-'+idx);
if(card){card.style.opacity='0';setTimeout(()=>card.remove(),300);}
}
window.dismissRec=dismissRec;

/* --- Risk Dots Loader --- */
async function loadRisks(){
const dots=document.querySelectorAll('.st-rdot.loading');
if(!dots.length)return;
try{
const r=await fetch('../api/ai-tools.php?action=status_risks',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({student_ids:[]})});
if(!r.ok)throw new Error();
const data=await r.json();
const risks=data.data?.risks||data.risks||{};
dots.forEach(dot=>{
const id=dot.dataset.studentId;
if(risks[id]){const level=risks[id].risk||'low';
dot.className='st-rdot '+(level==='high'?'high':level==='medium'?'med':'low');
dot.title=risks[id].reason||level;
}else{dot.classList.remove('loading');dot.classList.add('unk');}
});
}catch(e){dots.forEach(dot=>{dot.classList.remove('loading');dot.classList.add('unk');});}
}

/* --- Search Debounce --- */
const searchInput=document.getElementById('searchInput');
if(searchInput){
searchInput.addEventListener('input',function(){
clearTimeout(searchTimer);
searchTimer=setTimeout(()=>{
const q=this.value.trim();
const url=new URL(window.location);
if(q){url.searchParams.set('q',q);}else{url.searchParams.delete('q');}
url.searchParams.delete('page');
window.location=url.toString();
},SEARCH_DELAY);
});
}

/* --- Student Modal --- */
window.openStudentModal=async function(id,name,number){
const modal=document.getElementById('studentModal');
window._currentModalStudentId=id;
document.getElementById('modalName').textContent=name;
document.getElementById('modalNumber').textContent=number;
const av=document.getElementById('modalAvatar');
if(av){const parts=name.split(' ');const initials=(parts[0]?parts[0][0]:'')+(parts[1]?parts[1][0]:'');av.textContent=initials.toUpperCase();}
document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
modal.classList.add('show');
document.body.style.overflow='hidden';
fetchStudentBrief(id);
fetchStudentHistory(id);
};

async function fetchStudentBrief(id){
try{
const r=await fetch('../api/ai-tools.php?action=profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})});
if(!r.ok)throw new Error();
const d=await r.json();
const brief=d.data?.summary||d.ai_brief||d.aiBrief||'No AI brief available.';
const aiEl=document.getElementById('modalAI');
if(aiEl){aiEl.querySelector('.st-modal-ai-txt').textContent=brief;
const rec=d.ai_recommendation||d.aiRecommendation||'';
aiEl.querySelector('.st-modal-ai-rec').textContent=rec?'Recommendation: '+rec:'';}
}catch(e){const aiEl=document.getElementById('modalAI');
if(aiEl)aiEl.querySelector('.st-modal-ai-txt').textContent='Unable to load AI brief.';}
}

async function runModalAIProfile(){
const btn=document.getElementById('btnModalAIProfile');
if(btn){btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Running...';btn.disabled=true;}
try{
const r=await fetch('../api/ai-tools.php?action=profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:window._currentModalStudentId||0})});
if(!r.ok)throw new Error();
const d=await r.json();
const brief=d.data?.summary||d.ai_brief||d.aiBrief||'No AI brief.';
const rec=d.ai_recommendation||d.aiRecommendation||'';
const aiEl=document.getElementById('modalAI');
if(aiEl){aiEl.querySelector('.st-modal-ai-txt').textContent=brief;
aiEl.querySelector('.st-modal-ai-rec').textContent=rec?'Recommendation: '+rec:'';}
toast('AI Profile generated','success');
}catch(e){toast('Failed to generate AI profile','error');}
if(btn){btn.innerHTML='<i class="fas fa-robot"></i> Run Full AI Profile';btn.disabled=false;}
}
window.runModalAIProfile=runModalAIProfile;

async function fetchStudentHistory(id){
try{
const r=await fetch('../api/status-history.php?student_id='+id);
if(!r.ok)throw new Error();
const d=await r.json();
const history=d.data||d.history||[];
const container=document.getElementById('modalTimeline');
if(history.length===0){container.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> No status history</div>';return;}
let html='';
history.forEach(h=>{
const meta=STATUS_META[h.current_status]||STATUS_META['inactive'];
const prevMeta=STATUS_META[h.previous_status]||STATUS_META['inactive'];
html+='<div class="st-tl-i"><div class="st-tl-dot" style="background:'+meta.color+'"></div><div class="st-tl-chg">';
html+='<span class="st-badge" style="background:'+prevMeta.bg+';color:'+prevMeta.color+';padding:2px 6px;font-size:10px">'+(h.previous_status||'N/A')+'</span>';
html+=' <i class="fas fa-arrow-right" style="color:var(--text-subtle);font-size:10px"></i> ';
html+='<span class="st-badge" style="background:'+meta.bg+';color:'+meta.color+';padding:2px 6px;font-size:10px">'+h.current_status+'</span>';
html+='</div>';
if(h.reason)html+='<div class="st-tl-rsn">'+escapeHTML(h.reason)+'</div>';
html+='<div class="st-tl-time">'+escapeHTML(h.changed_by_name||'System')+' \u00b7 '+new Date(h.created_at).toLocaleString()+'</div></div>';
});
container.innerHTML=html;
}catch(e){document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Failed to load history</div>';}
}

window.closeModal=function(){
document.getElementById('studentModal').classList.remove('show');
document.body.style.overflow='';
};
document.getElementById('studentModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

/* --- Init --- */
loadRisks();

})();
</script>

