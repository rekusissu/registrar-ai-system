<?php
// ============================================================
//  REGISTRAR/RFID-KIOSK.PHP
//  Kiosk scan page — public, tap to scan
//  Station change via authorized card tap (no input field)
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

$db = Database::getInstance();

// Security
$allowedIps = ['127.0.0.1', '::1', '192.168.', '10.'];
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$ipOk = false;
foreach ($allowedIps as $prefix) {
    if (strpos($remoteIp, $prefix) === 0) { $ipOk = true; break; }
}
$validToken = defined('KIOSK_ACCESS_TOKEN') ? KIOSK_ACCESS_TOKEN : 'kiosk-tap-2024';
$tokenOk = isset($_GET['token']) && $_GET['token'] === $validToken;
if (!$ipOk && !$tokenOk) {
    require_once __DIR__ . '/../shared/security_headers.php';
    require_once __DIR__ . '/../shared/session_config.php';
    if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
}

$readers = $db->fetchAll("SELECT * FROM card_readers WHERE status = 'active' ORDER BY name");

$currentReaderId = 0;
$localConfig = __DIR__ . '/../shared/config.local.php';
if (file_exists($localConfig)) { include_once $localConfig;
    if (defined('SCANNER_READER_ID') && SCANNER_READER_ID) $currentReaderId = SCANNER_READER_ID; }
if (isset($_COOKIE['kiosk_reader_id'])) {
    $cookieId = intval($_COOKIE['kiosk_reader_id']);
    if ($cookieId > 0) $currentReaderId = $cookieId; }
$currentReader = null;
if ($currentReaderId > 0) {
    $currentReader = $db->fetchOne("SELECT * FROM card_readers WHERE id = ? AND status = 'active'", [$currentReaderId]); }

$page_title = 'RFID Kiosk';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BCP RFID Scanner</title>
    <meta name='csrf-token' content='<?= csrfToken() ?>' />
    <script src='../js/csrf.js'></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
html,body { height:100%; }
body {
    font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
    background:linear-gradient(145deg,#f0f4ff,#e8eef9,#f4f8ff);
    min-height:100vh; display:flex; align-items:center; justify-content:center;
    color:#1e293b;
}
.kiosk { width:100%; max-width:580px; padding:24px 20px 32px; text-align:center; }

.top-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; }
.school-brand { display:flex; align-items:center; gap:10px; }
.school-brand img { width:38px; height:38px; object-fit:contain; border-radius:8px; }
.school-brand .name { text-align:left; }
.school-brand .name .college { font-size:13px; font-weight:700; color:#0f172a; line-height:1.1; }
.school-brand .name .sub { font-size:10px; color:#94a3b8; }

.station-trigger {
    display:flex; align-items:center; gap:5px; padding:6px 14px; border-radius:999px;
    font-size:11px; font-weight:600; cursor:pointer; border:none; font-family:inherit;
    background:#eef4ff; color:#2563eb; transition:all .2s;
}
.station-trigger:hover { background:#dbeafe; }
.station-trigger.none { background:#fef3c7; color:#b45309; }

/* Modal - no auto-focus trap */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px; pointer-events:none; }
.modal-overlay.active { display:flex; }
.modal-box { background:white; border-radius:20px; padding:28px 24px; max-width:400px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,0.15); text-align:center; pointer-events:all; }
.modal-box select, .modal-box button, .modal-box .tap-target { cursor:pointer; }
.wide-select { width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:14px; font-family:inherit; color:#1e293b; outline:none; background:white; margin-bottom:14px; cursor:pointer; }

.auth-step { margin:14px 0; padding:14px; border-radius:12px; background:#f8fafc; border:1px dashed #e2e8f0; }
.auth-step p { font-size:12px; color:#64748b; margin-bottom:8px; }
.auth-step .tap-target {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 24px; border-radius:12px;
    background:#eef4ff; color:#2563eb; border:2px solid #bfdbfe;
    font-weight:600; font-size:14px; cursor:pointer;
    transition:all .2s; min-width:180px;
}
.auth-step .tap-target:hover { background:#dbeafe; }
.auth-step .tap-target.listening { background:#fef9c3; border-color:#facc15; color:#92400e; animation:pulseBg 1s infinite; }
@keyframes pulseBg { 0%,100%{background:#fef9c3} 50%{background:#fef3c7} }
.auth-step .tap-target.success { background:#dcfce7; border-color:#86efac; color:#16a34a; }
.auth-step .tap-target i { font-size:18px; }
.auth-hide-input { position:fixed; left:-9999px; opacity:0; }

.auth-success { display:none; font-size:13px; color:#16a34a; font-weight:600; margin-top:8px; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
.modal-actions button { padding:8px 18px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; border:none; font-family:inherit; }
.btn-cancel { background:#f1f5f9; color:#475569; }
.btn-cancel:hover { background:#e2e8f0; }
.btn-confirm { background:#2563eb; color:white; }
.btn-confirm:hover { background:#1d4ed8; }
.btn-confirm:disabled { opacity:0.5; cursor:not-allowed; }

/* Main scan ready */
.ready { display:block; }
.ready.hide { display:none; }
.ready-icon { width:88px; height:88px; margin:0 auto 22px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:32px; color:#2563eb; box-shadow:0 4px 20px rgba(37,99,235,0.12); position:relative; }
.ready-icon::after{content:'';position:absolute;inset:-5px;border-radius:50%;border:2px solid rgba(37,99,235,0.15);animation:ripple 2s ease-out infinite;}
@keyframes ripple{0%{transform:scale(0.92);opacity:1}100%{transform:scale(1.4);opacity:0}}
.ready h2{font-size:20px;font-weight:700;color:#0f172a;margin-bottom:4px;}
.ready p{font-size:14px;color:#64748b;}
.ready .hint{font-size:12px;color:#94a3b8;margin-top:14px;display:flex;align-items:center;justify-content:center;gap:6px;}

#cardUid{position:fixed;left:-9999px;opacity:0;}

.result{display:none;}
.result.show{display:block;animation:slideUp .4s ease;}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.status-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 20px;border-radius:999px;font-size:13px;font-weight:700;margin-bottom:16px;}
.status-badge.granted{background:#dcfce7;color:#16a34a;}
.status-badge.denied{background:#fee2e2;color:#dc2626;}
.profile-card{background:white;border-radius:20px;padding:28px 24px 20px;box-shadow:0 4px 24px rgba(0,0,0,0.06);border:1px solid #e2e8f0;}
.avatar{width:76px;height:76px;border-radius:50%;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;color:white;border:3px solid #f1f5f9;}
.avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.name{font-size:24px;font-weight:700;color:#0f172a;}
.id-num{font-size:13px;color:#64748b;margin-top:4px;}
.course-badge{display:inline-block;padding:4px 14px;border-radius:999px;background:#eef4ff;color:#2563eb;font-size:12px;font-weight:600;margin-top:10px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;}
.info-block{text-align:left;}
.info-block .lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.3px;}
.info-block .val{font-size:14px;font-weight:600;color:#1e293b;margin-top:2px;}
.scan-footer{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:#94a3b8;margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9;}
.denied-icon{width:76px;height:76px;border-radius:50%;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:#94a3b8;font-size:28px;}
.kiosk-footer{margin-top:24px;font-size:12px;color:#94a3b8;display:flex;align-items:center;justify-content:center;gap:6px;}
.kiosk-footer .dot{width:6px;height:6px;border-radius:50%;background:#16a34a;animation:pulse 1.5s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.2}}
</style>
</head>
<body>
<div class="kiosk">
    <div class="top-bar">
        <div class="school-brand">
            <img src="../assets/images/BCP_LOGO.png" alt="BCP">
            <div class="name">
                <div class="college">Bestlink College of the Philippines</div>
                <div class="sub">RFID Access System</div>
            </div>
        </div>
        <button class="station-trigger <?= $currentReader ? '' : 'none' ?>" id="stationBtn" onclick="openStationModal()">
            <i class="fas fa-<?= $currentReader ? 'wifi' : 'exclamation-triangle' ?>"></i>
            <?= $currentReader ? htmlspecialchars($currentReader['name']) : 'Set station' ?>
        </button>
    </div>

    <input type="text" id="cardUid" autocomplete="off" inputmode="numeric" />

    <div class="ready" id="readyState">
        <div class="ready-icon"><i class="fas fa-credit-card"></i></div>
        <h2>Tap Your Card</h2>
        <p>Hold your RFID card near the reader</p>
        <div class="hint"><i class="fas fa-wave-square"></i> 125kHz · NFC</div>
    </div>

    <div class="result" id="result">
        <div class="status-badge" id="statusBadge"><i class="fas fa-check-circle"></i> <span id="statusText">Access Granted</span></div>
        <div class="profile-card">
            <div class="avatar blue" id="avatarEl">JD</div>
            <div class="name" id="studentName">—</div>
            <div class="id-num" id="studentId">—</div>
            <div class="course-badge" id="studentCourse">—</div>
            <div class="info-grid">
                <div class="info-block"><div class="lbl">Time</div><div class="val" id="tapTime">—</div></div>
                <div class="info-block"><div class="lbl">Date</div><div class="val" id="tapDate">—</div></div>
                <div class="info-block"><div class="lbl">Year Level</div><div class="val" id="studentYear">—</div></div>
                <div class="info-block"><div class="lbl">Station</div><div class="val" id="stationVal">—</div></div>
            </div>
            <div class="scan-footer" id="scanFooter"></div>
        </div>
    </div>

    <div class="kiosk-footer">
        <span class="dot"></span> <span>Ready</span> <span>·</span>
        <span id="lastAction" style="color:#64748b;">Awaiting tap</span>
    </div>
</div>

<!-- Station Modal -->
<div class="modal-overlay" id="stationModal">
    <div class="modal-box">
        <h3>Change Station</h3>
        <p style="margin-bottom:18px;">Select the reader location for this kiosk</p>

        <select class="wide-select" id="readerSelect" style="margin-bottom:16px;">
            <option value="">— Select station —</option>
            <?php foreach ($readers as $r): ?>
                <option value="<?= (int)$r['id'] ?>" data-type="<?= htmlspecialchars($r['reader_type']) ?>" <?= $currentReader && $currentReader['id'] == $r['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['name']) ?> — <?= htmlspecialchars($r['location']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="auth-step">
            <p><i class="fas fa-shield-alt"></i> Tap your authorized card to verify</p>
            <div class="tap-target" id="tapAuthorizeBtn" onclick="startListening()">
                <i class="fas fa-credit-card"></i>
                <span id="tapBtnText">Tap to authorize</span>
            </div>
            <input type="text" id="authCapture" class="auth-hide-input" autocomplete="off" inputmode="numeric" />
            <div class="auth-success" id="authSuccess"><i class="fas fa-check-circle"></i> Authorized as <span id="authName"></span></div>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeStationModal()">Cancel</button>
            <button class="btn-confirm" id="confirmBtn" disabled onclick="confirmStation()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
(function() {
    // Main scan
    var inp=document.getElementById('cardUid'), ready=document.getElementById('readyState'), res=document.getElementById('result'),
        sb=document.getElementById('statusBadge'), st=document.getElementById('statusText'), av=document.getElementById('avatarEl'),
        sn=document.getElementById('studentName'), sid=document.getElementById('studentId'), sc=document.getElementById('studentCourse'),
        sy=document.getElementById('studentYear'), tt=document.getElementById('tapTime'), td=document.getElementById('tapDate'),
        sv=document.getElementById('stationVal'), sf=document.getElementById('scanFooter'), la=document.getElementById('lastAction');
    var cols=['blue','green','purple','orange','pink'];
    function col(s){var h=0;for(var i=0;i<(s||'').length;i++){h=((h<<5)-h)+s.charCodeAt(i);h|=0;}return cols[Math.abs(h)%cols.length];}
    function init(s){var p=(s||'').trim().split(' ');return((p[0]||'')[0]||'')+((p[1]||'')[0]||'');}
    inp.focus();
    document.addEventListener('click',function(){if(!document.querySelector('.modal-overlay.active'))inp.focus();});

    function show(ok,data){
        ready.classList.add('hide');res.classList.add('show');
        var n=new Date();tt.textContent=n.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        td.textContent=n.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
        var evtIcon='', evtLabel='';
        if(data&&data.event_type){evtLabel=data.event_type==='entry'?'Entry':'Exit';evtIcon=data.event_type==='entry'?'fa-right-to-bracket':'fa-right-from-bracket';}
        if(data&&data.actual_reader){sv.textContent=data.actual_reader.name+' ('+data.actual_reader.location+')';sf.innerHTML=(evtIcon?'<i class="fas '+evtIcon+'" style="color:'+(data.event_type==='entry'?'#2563eb':'#b45309')+';"></i> '+evtLabel+' · ':'')+'<i class="fas fa-hard-hat"></i> '+data.actual_reader.name+' · '+data.actual_reader.location;}
        else{sv.textContent='—';sf.innerHTML='';}
        la.textContent=ok&&data&&data.student?((data.student.first_name||'')+' '+(data.student.last_name||'')).trim()+' '+(evtLabel||'tapped'):(data&&data.message?data.message:'Denied');
        if(ok&&data&&data.student){
            var s=data.student,n=((s.first_name||'')+' '+(s.last_name||'')).trim()||'Student';
            sb.className='status-badge granted';sb.innerHTML='<i class="fas fa-check-circle"></i> <span>Access Granted</span>';
            av.className='avatar '+col(n);av.textContent=init(n);sn.textContent=n;
            sid.textContent=s.student_number||'—';sc.textContent=s.course||'—';sy.textContent=s.year_level?s.year_level+' Year':'—';
        }else{
            sb.className='status-badge denied';sb.innerHTML='<i class="fas fa-times-circle"></i> <span>Access Denied</span>';
            av.className='denied-icon';av.innerHTML='<i class="fas fa-user-slash"></i>';sn.textContent='—';sid.textContent='—';sc.textContent='—';sy.textContent='—';
        }
        setTimeout(function(){res.classList.remove('show');ready.classList.remove('hide');inp.value='';inp.focus();la.textContent='Awaiting tap';},5000);
    }
    function getKioskReaderId(){var c=document.cookie.split('; ').find(r=>r.startsWith('kiosk_reader_id='));return c?parseInt(c.split('=')[1])||0:0;}

    // Entry/exit toggle for "both" readers — alternates per card UID
    var lastEvent = {}; // card_uid -> 'entry' or 'exit'

    inp.addEventListener('input',function(){
        this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);
        if(this.value.trim().length!==10)return;
        la.textContent='Processing…';
        var uid=this.value.trim();
        var evt='entry';
        var rid=getKioskReaderId();
        // If reader type is "both", toggle entry/exit per card
        var rSel=document.getElementById('readerSelect');
        if(rSel&&rSel.options[rSel.selectedIndex]){
            var rType=rSel.options[rSel.selectedIndex].dataset.type;
            if(rType==='both'){
                evt=lastEvent[uid]==='exit'?'entry':'exit';
            }
        }
        lastEvent[uid]=evt;
        var payload={card_uid:uid,event_type:evt};
        if(rid)payload.reader_id=rid;
        fetch('../api/rfid-scan.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
        .then(function(r){return r.json();}).then(function(d){show(d.success,d);}).catch(function(){show(false,{message:'Network error'});});
    });

    // Station modal functionality removed - kiosk now directly scans cards to api/rfid-scan.php
</script>
</body>
</html>
