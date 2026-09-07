<?php
// ============================================================
//  NURSE/STUDENT-LOOKUP.PHP
//  Student Lookup — Search students, view profile + medical history.
//  Search by name, student number, or course.
//  Click result → detail panel with info + medical profile + visits.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// ── Server-side search: pre-load students matching query ──────
$q = trim($_GET['q'] ?? '');
$students = [];
if ($q !== '') {
    $like = "%$q%";
    $students = $db->fetchAll(
        "SELECT id, student_number, first_name, middle_name, last_name, photo, course, year_level, section
         FROM students
         WHERE status != 'archived'
           AND (student_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?
                OR CONCAT(first_name,' ',last_name) LIKE ? OR course LIKE ?)
         ORDER BY last_name, first_name
         LIMIT 20",
        [$like, $like, $like, $like, $like]
    );
}

$page_title = 'Student Lookup';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_lookup';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.sl-search-panel{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:24px 28px;margin-bottom:20px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.sl-search-wrap{position:relative;max-width:700px;margin:0 auto}
.sl-search-wrap i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:18px;pointer-events:none}
.sl-search-wrap input{width:100%;padding:14px 18px 14px 50px;border:2px solid #e2e8f0;border-radius:14px;font-size:16px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.sl-search-wrap input:focus{border-color:#0d9488;box-shadow:0 0 0 4px rgba(13,148,136,.1);background:#fff}
.sl-search-hint{text-align:center;font-size:12px;color:#94a3b8;margin-top:10px}
.sl-results{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
.sl-result-card{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #f1f5f9;border-radius:14px;padding:14px 18px;cursor:pointer;transition:all .15s;box-shadow:0 2px 8px rgba(15,23,42,.03)}
.sl-result-card:hover{border-color:#0d9488;box-shadow:0 4px 16px rgba(13,148,136,.12);transform:translateY(-1px)}
.sl-result-card.active{border-color:#0d9488;background:#f0fdfa;box-shadow:0 4px 16px rgba(13,148,136,.15)}
.sl-photo{width:48px;height:48px;min-width:48px;border-radius:50%;object-fit:cover;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800}
.sl-photo img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.sl-info{flex:1;min-width:0}
.sl-name{font-size:15px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sl-meta{font-size:12px;color:#64748b;margin-top:2px;display:flex;gap:12px;flex-wrap:wrap}
.sl-meta span{display:inline-flex;align-items:center;gap:4px}
.sl-empty{text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px}
.sl-detail{display:none;background:#fff;border:1px solid #f1f5f9;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,23,42,.06);margin-bottom:20px}
.sl-detail.active{display:block}
.sl-detail-header{display:flex;align-items:center;gap:20px;padding:28px 30px;border-bottom:1px solid #f1f5f9;background:linear-gradient(135deg,#f0fdfa 0%,#fff 100%)}
.sl-detail-avatar{width:72px;height:72px;min-width:72px;border-radius:50%;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;border:3px solid #fff;box-shadow:0 4px 16px rgba(13,148,136,.25)}
.sl-detail-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.sl-detail-name{font-size:22px;font-weight:800;color:#0f172a;margin:0}
.sl-detail-sub{font-size:13px;color:#64748b;margin-top:4px;display:flex;gap:16px;flex-wrap:wrap}
.sl-detail-body{padding:24px 30px}
.sl-two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:768px){.sl-two-col{grid-template-columns:1fr}}
.sl-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sl-info-item{padding:10px 14px;background:#f8fafc;border-radius:10px}
.sl-info-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:4px}
.sl-info-value{font-size:14px;font-weight:600;color:#0f172a}
.sl-medical-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px 22px}
.sl-medical-title{font-size:14px;font-weight:800;color:#0d9488;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.sl-medical-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:500px){.sl-medical-grid{grid-template-columns:1fr}}
.sl-med-item{padding:10px 14px;background:#fff;border-radius:10px;border:1px solid #f1f5f9}
.sl-med-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:3px}
.sl-med-value{font-size:13px;font-weight:600;color:#0f172a;word-break:break-word}
.sl-med-value.empty{color:#cbd5e1;font-style:italic}
.sl-section-title{font-size:15px;font-weight:800;color:#0f172a;margin:20px 0 12px;display:flex;align-items:center;gap:8px}
.sl-vtable{width:100%;border-collapse:collapse}
.sl-vtable th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #f1f5f9;white-space:nowrap}
.sl-vtable td{padding:10px 12px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.sl-vtable tbody tr:hover{background:#f0fdfa}
.sl-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.sl-pill.recorded{background:#dcfce7;color:#16a34a}
.sl-pill.pending{background:#fef3c7;color:#d97706}
.sl-pill.cancelled{background:#fee2e2;color:#dc2626}
.sl-pill.default{background:#f1f5f9;color:#64748b}
.sl-loading{text-align:center;padding:30px;color:#94a3b8}
.sl-back{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f1f5f9;border:none;border-radius:10px;font-size:13px;font-weight:600;color:#475569;cursor:pointer;transition:all .15s;margin-bottom:16px;text-decoration:none}
.sl-back:hover{background:#e2e8f0;color:#0f172a}
</style>

<main class="dashboard-main">
<div class="dashboard-container" style="padding:24px;max-width:1000px;margin:0 auto;">

<a href="<?= $APP_ROOT ?>nurse/dashboard.php" class="sl-back">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
</a>

<!-- ── Search Panel ──────────────────────────────────────── -->
<div class="sl-search-panel">
    <form method="get" action="" id="slSearchForm">
        <div class="sl-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="slQ" name="q" placeholder="Search by name, student number, or course…" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" />
        </div>
    </form>
    <div class="sl-search-hint">Type at least 2 characters to search</div>
</div>

<!-- ── Results ───────────────────────────────────────────── -->
<div id="slResults" class="sl-results"></div>

<!-- ── Detail Panel ──────────────────────────────────────── -->
<div id="slDetail" class="sl-detail">
    <div id="slDetailHeader" class="sl-detail-header"></div>
    <div id="slDetailBody" class="sl-detail-body"></div>
</div>

</div>
</main>

<script>
var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).getAttribute
    ? document.querySelector('meta[name=csrf-token]').getAttribute('content') : '';
var STUDENTS_DATA = <?= json_encode($students) ?>;
</script>
<script>
(function(){
    'use strict';
    function el(id){ return document.getElementById(id); }
    function esc(s){ var d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }
    function ord(name){
        if(!name) return '?';
        var parts=name.trim().split(/\s+/);
        return parts.length>1 ? (parts[0][0]+parts[parts.length-1][0]).toUpperCase() : name.substring(0,2).toUpperCase();
    }
    function fmt(v){
        if(!v) return '—';
        var d=new Date(String(v).replace(' ','T'));
        return isNaN(d)?v:d.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
    }
    function fullName(s){ return ((s.first_name||'')+' '+(s.middle_name? s.middle_name+' ':'')+(s.last_name||'')).trim(); }
    var data = window.STUDENTS_DATA || [];
    var currentStudent = null;

    function renderResults(list){
        var box = el('slResults');
        if(!list || list.length===0){
            box.innerHTML = '<div class="sl-empty"><i class="fas fa-search" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4;"></i>No students found.</div>';
            return;
        }
        box.innerHTML = list.map(function(s){
            var name = fullName(s);
            var photoHtml = s.photo ? '<img src="<?= $APP_ROOT ?>' + esc(s.photo.replace(/^\.\.?\//,'')) + '" alt="">' : esc(ord(name));
            var isActive = currentStudent && currentStudent.id === s.id;
            return '<div class="sl-result-card'+(isActive?' active':'')+'" data-id="'+esc(String(s.id))+'" onclick="window.slSelect('+esc(String(s.id))+')">'
                + '<div class="sl-photo">'+photoHtml+'</div>'
                + '<div class="sl-info"><div class="sl-name">'+esc(name)+'</div>'
                + '<div class="sl-meta">'
                + '<span><i class="fas fa-hashtag"></i> '+esc(s.student_number||'—')+'</span>'
                + '<span><i class="fas fa-graduation-cap"></i> '+esc(s.course||'—')+'</span>'
                + '<span><i class="fas fa-layer-group"></i> Y'+esc(String(s.year_level||'—'))+(s.section?' · '+esc(s.section):'')+'</span>'
                + '</div></div></div>';
        }).join('');
    }
    renderResults(data);

    var debounce = null;
    el('slQ').addEventListener('input', function(){
        clearTimeout(debounce);
        var q = this.value.trim().toLowerCase();
        if(q.length < 1){ renderResults(data); return; }
        debounce = setTimeout(function(){
            if(q.length >= 2){ window.location.search = '?q=' + encodeURIComponent(q); }
            else {
                var filtered = data.filter(function(s){
                    var name = fullName(s).toLowerCase();
                    return name.indexOf(q)!==-1 || (s.student_number||'').toLowerCase().indexOf(q)!==-1 || (s.course||'').toLowerCase().indexOf(q)!==-1;
                });
                renderResults(filtered);
            }
        }, 300);
    });
    el('slQ').addEventListener('keydown', function(e){
        if(e.key==='Enter'){
            clearTimeout(debounce);
            var q = this.value.trim();
            if(q.length >= 1) window.location.search = '?q=' + encodeURIComponent(q);
        }
    });

    /* ── Select student ──────────────────────────────────── */
    window.slSelect = function(id){
        var s = null;
        for(var i=0;i<data.length;i++){ if(data[i].id==id){s=data[i]; break;} }
        if(!s) return;
        currentStudent = s;
        var cards = document.querySelectorAll('.sl-result-card');
        for(var j=0;j<cards.length;j++){
            cards[j].classList.toggle('active', cards[j].getAttribute('data-id')==id);
        }
        var name = fullName(s);
        var photoUrl = s.photo ? '<?= $APP_ROOT ?>' + s.photo.replace(/^\.\.?\//,'') : '';
        var photoHtml = photoUrl ? '<img src="'+esc(photoUrl)+'" alt="">' : esc(ord(name));
        el('slDetailHeader').innerHTML =
            '<div class="sl-detail-avatar">'+photoHtml+'</div>'
            + '<div><h2 class="sl-detail-name">'+esc(name)+'</h2>'
            + '<div class="sl-detail-sub">'
            + '<span><i class="fas fa-hashtag"></i> '+esc(s.student_number||'—')+'</span>'
            + '<span><i class="fas fa-graduation-cap"></i> '+esc(s.course||'—')+'</span>'
            + '<span><i class="fas fa-layer-group"></i> Year '+esc(String(s.year_level||'—'))+(s.section?' · Section '+esc(s.section):'')+'</span>'
            + '</div></div>';
        el('slDetail').classList.add('active');
        var body = el('slDetailBody');
        body.innerHTML = '<div class="sl-loading"><i class="fas fa-spinner fa-spin"></i> Loading medical profile…</div>';
        fetch('../api/clinic.php?action=visits&student_id=' + s.id)
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(!d.success||!d.data||d.data.length===0){
                    body.innerHTML = infoGrid(s)+medCard(null)+visitSection([]);
                    return;
                }
                body.innerHTML = infoGrid(s)+medCard(d.data[0])+visitSection(d.data.slice(0,10));
                el('slDetail').scrollIntoView({behavior:'smooth',block:'start'});
            })
            .catch(function(){
                body.innerHTML = infoGrid(s)+'<div style="color:#ef4444;padding:20px;"><i class="fas fa-exclamation-triangle"></i> Error loading data.</div>';
            });
    };

    /* ── Section renderers ───────────────────────────────── */
    function infoGrid(s){
        var name = fullName(s);
        return '<div class="sl-info-grid" style="margin-bottom:20px;">'
            +'<div class="sl-info-item"><div class="sl-info-label">Full Name</div><div class="sl-info-value">'+esc(name)+'</div></div>'
            +'<div class="sl-info-item"><div class="sl-info-label">Student Number</div><div class="sl-info-value">'+esc(s.student_number||'—')+'</div></div>'
            +'<div class="sl-info-item"><div class="sl-info-label">Course</div><div class="sl-info-value">'+esc(s.course||'—')+'</div></div>'
            +'<div class="sl-info-item"><div class="sl-info-label">Year &amp; Section</div><div class="sl-info-value">Year '+esc(String(s.year_level||'—'))+(s.section?' · '+esc(s.section):'')+'</div></div>'
            +'</div>';
    }

    function medCard(v){
        var fields=[
            {l:'Blood Type',k:'blood_type'},{l:'Allergies',k:'allergies'},
            {l:'Height',k:'height'},{l:'Weight',k:'weight'},
            {l:'Pre-existing Conditions',k:'pre_existing_conditions'},
            {l:'Immunization Records',k:'immunization_records'}
        ];
        var grid=fields.map(function(f){
            var val=v?(v[f.k]||''):'';
            var cls=val?'sl-med-value':'sl-med-value empty';
            return '<div class="sl-med-item"><div class="sl-med-label">'+esc(f.l)+'</div>'
                +'<div class="'+cls+'">'+(val?esc(val):'Not recorded')+'</div></div>';
        }).join('');
        var ts=v?(v.date_time||v.created_at||''):'';
        return '<div class="sl-medical-card" style="margin-top:16px;">'
            +'<div class="sl-medical-title"><i class="fas fa-notes-medical"></i> Medical Profile'
            +(ts?'<span style="font-weight:400;font-size:11px;color:#94a3b8;margin-left:auto;">Last updated: '+fmt(ts)+'</span>':'')
            +'</div><div class="sl-medical-grid">'+grid+'</div></div>';
    }

    function visitSection(visits){
        if(!visits||visits.length===0)
            return '<div class="sl-section-title"><i class="fas fa-clock-rotate-left"></i> Visit History</div>'
                +'<div style="color:#94a3b8;font-size:13px;">No visits recorded.</div>';
        var rows=visits.map(function(v){
            var st=(v.record_status||'').toLowerCase();
            var pc=st==='recorded'?'recorded':st==='pending'?'pending':st==='cancelled'?'cancelled':'default';
            return '<tr><td>'+fmt(v.date_time||v.created_at)+'</td><td>'+esc(v.reason_for_visit||'—')+'</td>'
                +'<td>'+(v.temperature?esc(v.temperature)+'°C':'—')+'</td>'
                +'<td>'+(v.blood_pressure?esc(v.blood_pressure):'—')+'</td>'
                +'<td>'+esc(v.assessment||'—')+'</td>'
                +'<td><span class="sl-pill '+pc+'">'+esc(v.record_status||'—')+'</span></td></tr>';
        }).join('');
        return '<div class="sl-section-title"><i class="fas fa-clock-rotate-left"></i> Visit History '
            +'<span style="font-weight:400;font-size:12px;color:#94a3b8;margin-left:8px;">(Last '+visits.length+')</span></div>'
            +'<div style="overflow-x:auto;"><table class="sl-vtable"><thead><tr>'
            +'<th>Date</th><th>Reason</th><th>Temp</th><th>BP</th><th>Assessment</th><th>Status</th>'
            +'</tr></thead><tbody>'+rows+'</tbody></table></div>';
    }
})();
</script>
<?php include '../includes/footer.php'; ?>

