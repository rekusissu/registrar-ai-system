<?php
// ============================================================
//  NURSE/DASHBOARD.PHP
//  Clinic Portal — ALL-IN-ONE workspace (no separate kiosk).
//  Tap-in (RFID or manual) → verify identity → Medical Profile
//  (button/modal, clinic-owned) → visit form → save.
//  Everything the clinic needs lives on this page.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

$db = Database::getInstance();

$today = date('Y-m-d');
$todayVisits  = (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) = ?", [$today]);
$totalVisits  = (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits");

$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course
     FROM students WHERE status != 'archived' ORDER BY last_name, first_name"
);

$recent = $db->fetchAll(
    "SELECT hv.id, hv.date_time, hv.reason_for_visit, hv.assessment, hv.action_taken,
            hv.record_status, s.student_number,
            CONCAT(s.first_name,' ',s.last_name) AS student_name
     FROM health_visits hv
     INNER JOIN students s ON hv.student_id = s.id
     ORDER BY COALESCE(hv.date_time, hv.created_at) DESC, hv.id DESC
     LIMIT 8"
);

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$nurseName = $_SESSION['full_name'] ?? 'Nurse';
$hour = (int) date('G');
if ($hour < 10)       { $timeGreeting = 'Good morning'; }
elseif ($hour < 16)   { $timeGreeting = 'Good afternoon'; }
elseif ($hour < 22)   { $timeGreeting = 'Good evening'; }
else                   { $timeGreeting = 'Good night'; }

$page_title = 'Clinic Dashboard';
$page_description = 'Clinic operations and student visit dashboard';
$body_page = 'clinic-dashboard';
$APP_ROOT = '../';
$thisWeek = date('Y-m-d', strtotime('monday this week'));
$thisMonth = date('Y-m-01');
$stats = [
    'today' => $todayVisits,
    'thisWeek' => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) BETWEEN ? AND ?", [$thisWeek, $today]),
    'thisMonth' => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) BETWEEN ? AND ?", [$thisMonth, $today]),
    'total' => $totalVisits,
    'pending' => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE record_status = 'Pending'"),
];
$extra_css = ['clinic-dashboard.css'];
$ACTIVE_NAV = 'nurse_dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';
?>


<main class="dashboard-main">
<div class="dashboard-container">

<!-- ── Welcome Hero (student-portal style) ─────────────────── -->
<div class="clinic-hero" style="background-image: url('<?= $APP_ROOT ?>assets/images/bestlink%20banner.jpg'); background-size: cover; background-position: center;">
    <div class="hero-avatar">
        <?= strtoupper(substr(trim($nurseName), 0, 1)) ?>
    </div>
    <div class="hero-body">
        <div class="hero-greet"><?= $timeGreeting ?></div>
        <div class="hero-title">Clinic workspace</div>
        <div class="hero-meta">
            <span class="hero-chip"><i class="fa-solid fa-hospital"></i> <?= h($nurseName) ?></span>
            <span class="hero-chip"><i class="fa-solid fa-clock"></i> <?= date('F d, Y') ?></span>
            <span class="hero-chip"><i class="fa-solid fa-calendar-day"></i> <?= $todayVisits ?> visit<?= $todayVisits === 1 ? '' : 's' ?> today</span>
        </div>
    </div>
</div>

<section class="clinic-stats" aria-labelledby="visitStatsTitle">
    <h2 id="visitStatsTitle" class="visit-stats-heading">Clinic visit statistics</h2>
    <div class="visit-stat-strip" aria-label="Visit totals">
        <div class="visit-stat is-current"><div class="visit-stat-label">Today</div><div class="visit-stat-value"><?= number_format($stats['today']) ?></div><div class="visit-stat-context">Visits recorded today</div></div>
        <div class="visit-stat is-week"><div class="visit-stat-label">This week</div><div class="visit-stat-value"><?= number_format($stats['thisWeek']) ?></div><div class="visit-stat-context">Since Monday</div></div>
        <div class="visit-stat"><div class="visit-stat-label">This month</div><div class="visit-stat-value"><?= number_format($stats['thisMonth']) ?></div><div class="visit-stat-context">Current month</div></div>
        <div class="visit-stat"><div class="visit-stat-label">All visits</div><div class="visit-stat-value"><?= number_format($stats['total']) ?></div><div class="visit-stat-context">Clinic records</div></div>
        <div class="visit-stat is-pending"><div class="visit-stat-label">Pending</div><div class="visit-stat-value"><?= number_format($stats['pending']) ?></div><div class="visit-stat-context">Need completion</div></div>
    </div>
</section>

<div class="status-strip" aria-label="Clinic workspace status">
    <div class="s-item">
        <div class="s-icon blue"><i class="fa-solid fa-id-card-clip"></i></div>
        <div><div class="s-value" id="stripState">Ready</div><div class="s-label">Student Tap-In</div></div>
    </div>
</div>

<div class="clinic-grid">
    <!-- TAP-IN -->
    <div class="tap-card">
        <div class="tap-icon"><i class="fas fa-credit-card"></i></div>
        <div class="tap-title">Student Tap-In</div>
        <div class="tap-sub">Tap the RFID student ID card on the reader</div>
        <div class="tap-status awaiting" id="tapStatus"><i class="fas fa-circle-dot" style="color:#0d9488;margin-right:6px;"></i>Awaiting card tap&hellip;</div>
        <input id="tapInput" type="text" maxlength="10" autocomplete="off" aria-label="Card UID" />

        <div class="divider">or</div>

        <label class="section-label" style="margin-top:0;">Search student by name or ID</label>
        <div class="student-search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input id="studentSearch" type="text" class="student-search-input" placeholder="Type name or student number&hellip;" autocomplete="off" />
            <div id="searchDropdown" class="search-dropdown"></div>
        </div>
    </div>
<!-- ─── WORKSPACE ─── -->
    <div class="ws-panel" id="wsPanel">
        <div id="wsEmpty" class="workspace-empty">
            <div class="workspace-empty-icon"><i class="fas fa-hand-pointer"></i></div>
            <strong>Identify a student to begin</strong>
            <span>Tap an RFID card or search by name or student number.</span>
        </div>

        <div id="wsStudent" style="display:none;">
            <span class="pill active" style="margin-bottom:14px;"><i class="fas fa-check-circle"></i> Student Identified — Verify Identity</span>
            <div class="student-chip-row">
                <div class="avatar" id="wsAvatar">?</div>
                <div>
                    <div style="font-weight:800;font-size:18px;color:#0f172a;" id="wsName">—</div>
                    <div style="font-size:12px;color:#64748b;" id="wsCard">—</div>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-cell"><div class="k">Student ID</div><div class="v" id="wsId">—</div></div>
                <div class="info-cell"><div class="k">Program</div><div class="v" id="wsProgram">—</div></div>
                <div class="info-cell"><div class="k">Year Level</div><div class="v" id="wsYear">—</div></div>
                <div class="info-cell"><div class="k">Section</div><div class="v" id="wsSection">—</div></div>
            </div>
            <div class="identity-actions">
                <button type="button" class="btn btn-outline" onclick="openProfile()"><i class="fas fa-id-card-clip"></i> Medical Profile</button>
                <button type="button" class="btn btn-primary" onclick="startAssessment()"><i class="fas fa-user-check"></i> Verify &amp; Start Assessment</button>
                <button type="button" class="btn btn-light" onclick="resetWorkspace()"><i class="fas fa-rotate"></i> New Scan</button>
            </div>
        </div>

        <div id="wsForm" style="display:none;">
            <div class="eval-stepper visible" id="evalStepper">
                <div class="eval-step active" data-step="1"><div class="eval-step-num">1</div><div class="eval-step-label">Patient Info</div></div>
                <div class="eval-step-line"></div>
                <div class="eval-step" data-step="2"><div class="eval-step-num">2</div><div class="eval-step-label">Assessment</div></div>
                <div class="eval-step-line"></div>
                <div class="eval-step" data-step="3"><div class="eval-step-num">3</div><div class="eval-step-label">Notes &amp; Save</div></div>
            </div>
            <div class="eval-pane active" data-pane="1">
                <div class="eval-pane-head"><div class="pane-icon" style="background:#f0fdfa;color:#0d9488;"><i class="fas fa-user-check"></i></div><div><h3>Patient Information</h3><div style="font-size:12px;color:#94a3b8;margin-top:2px;">Verify student and record visit reason</div></div></div>
                <div class="section-title"><i class="fas fa-clipboard-list" style="color:#2563eb;"></i> This Visit &mdash; <span id="formStudentName"></span></div>
                <div class="section-label">Reason for Visit <span style="color:#dc2626;">*</span></div>
                <select id="reasonSelect" class="form-control" style="width:100%;"><option value="">— Select a reason —</option><?php foreach (['Headache','Fever','Stomachache','Menstrual Cramps','Dizziness','Minor Injury','Other'] as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?></select>
                <div class="other-input" id="reasonOther"><input type="text" id="reasonOtherInput" class="form-control" placeholder="Enter the reason for visit…"></div>
                <div class="form-row" style="margin-top:14px">
                    <div class="form-group"><label>Visit type</label><select id="visitType" class="form-control"><option>First aid</option><option>Illness</option><option>Injury</option><option>Medication</option><option>Follow-up</option><option>Other</option></select></div>
                    <div class="form-group"><label>Onset / incident time</label><input type="datetime-local" id="onsetAt" class="form-control"></div>
                </div>
                <div class="form-group"><label>Affected area / incident details</label><textarea id="incidentDetails" class="form-control" rows="2" placeholder="Where did it happen? What body area is affected?"></textarea></div>
                <div class="form-group"><label>Symptoms and observations</label><textarea id="symptoms" class="form-control" rows="2" placeholder="Primary complaint, other symptoms, appearance, breathing, bleeding, mobility…"></textarea></div>
                <div class="form-group"><label>Pain scale (0–10)</label><input type="number" min="0" max="10" id="painScore" class="form-control" style="max-width:120px" placeholder="0"></div>
                <div class="section-title"><i class="fas fa-triangle-exclamation" style="color:#b45309;"></i> Safety check</div>
                <div class="form-row">
                    <div class="form-group"><label><input type="checkbox" id="flagBreathing"> Difficulty breathing</label></div>
                    <div class="form-group"><label><input type="checkbox" id="flagChestPain"> Chest pain</label></div>
                    <div class="form-group"><label><input type="checkbox" id="flagUnconscious"> Loss of consciousness</label></div>
                    <div class="form-group"><label><input type="checkbox" id="flagAllergy"> Severe allergic reaction</label></div>
                    <div class="form-group"><label><input type="checkbox" id="flagBleeding"> Uncontrolled bleeding</label></div>
                    <div class="form-group"><label><input type="checkbox" id="flagConfusion"> Confusion / altered awareness</label></div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:20px;"><button type="button" class="btn-step-next" onclick="goPane(2)">Next: Assessment <i class="fas fa-arrow-right"></i></button></div>
            </div>
            <div class="eval-pane" data-pane="2">
                <div class="eval-pane-head"><div class="pane-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-stethoscope"></i></div><div><h3>Clinical Assessment</h3><div style="font-size:12px;color:#94a3b8;margin-top:2px;">Record vitals, diagnosis, and action taken</div></div></div>
                <div class="section-title"><i class="fas fa-heart-pulse" style="color:#dc2626;"></i> Vitals</div>
                <div class="form-row">
                    <div class="form-group"><label>Temperature (°C)</label><input type="number" step="0.1" id="temperature" class="form-control" placeholder="e.g., 36.8"></div>
                    <div class="form-group"><label>Blood Pressure</label><input type="text" id="bloodPressure" class="form-control" placeholder="e.g., 120/80"></div>
                    <div class="form-group"><label>Pulse (bpm)</label><input type="number" id="pulse" class="form-control" placeholder="e.g., 80"></div>
                    <div class="form-group"><label>Respiratory rate</label><input type="number" id="respiratoryRate" class="form-control" placeholder="e.g., 18"></div>
                    <div class="form-group"><label>Oxygen saturation (%)</label><input type="number" min="0" max="100" id="oxygenSaturation" class="form-control" placeholder="e.g., 98"></div>
                </div>
                <div class="form-group"><label>Current medications / treatment given today</label><textarea id="currentMedications" class="form-control" rows="2" placeholder="Medication name, dose, time, response…"></textarea></div>
                <div class="section-title"><i class="fas fa-clipboard-check" style="color:#0d9488;"></i> Disposition &amp; follow-up</div>
                <div class="form-group"><label>Disposition <span style="color:#dc2626;">*</span></label><select id="disposition" class="form-control"><option value="">— Select outcome —</option><option>Returned to class</option><option>Observed / rest</option><option>Sent home</option><option>Sent to physician</option><option>Emergency referral</option><option>Transferred to external facility</option><option>Parent / guardian notified</option><option>No follow-up required</option></select></div>
                <div class="form-group"><label>Return precautions / education given</label><textarea id="returnPrecautions" class="form-control" rows="2" placeholder="Instructions given to student and guardian, warning signs to watch for…"></textarea></div>
                <div class="form-group"><label>Follow-up plan</label><textarea id="followUpPlan" class="form-control" rows="2" placeholder="When and why should the student return or be rechecked?"></textarea></div>
                <div class="section-title"><i class="fas fa-stethoscope" style="color:#2563eb;"></i> Diagnosis &amp; Action</div>
                <div class="section-label">Nurse's Assessment / Initial Diagnosis</div>
                <select id="assessSelect" class="form-control" style="width:100%;"><option value="">— Select assessment —</option><?php foreach (['Headache','Fever','Dysmenorrhea','Possible Dehydration','Minor Abrasion','Other'] as $a): ?><option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?></select>
                <div class="other-input" id="assessOther"><input type="text" id="assessOtherInput" class="form-control" placeholder="Enter the assessment / diagnosis…"></div>
                <div class="section-label">Action Taken <span style="color:#dc2626;">*</span></div>
                <select id="actionSelect" class="form-control" style="width:100%;"><option value="">— Select action —</option><?php foreach (['Rest','Hydration','First Aid','Medication Administered','Referred to Physician','Sent Home','Other'] as $a): ?><option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?></select>
                <div class="other-input" id="actionOther"><input type="text" id="actionOtherInput" class="form-control" placeholder="Enter the action performed…"></div>
                <div class="eval-nav">
                    <button type="button" class="btn-step-back" onclick="goPane(1)"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="btn-step-next" onclick="goPane(3)">Next: Notes <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            <div class="eval-pane" data-pane="3">
                <div class="eval-pane-head"><div class="pane-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-pen-to-square"></i></div><div><h3>Notes &amp; AI Review</h3><div style="font-size:12px;color:#94a3b8;margin-top:2px;">Add final notes, review AI recommendations, and save</div></div></div>
                <div class="section-label" style="margin-top:0;">Nurse's Notes</div>
                <textarea id="nurseNotes" class="form-control" rows="3" style="width:100%;" placeholder="Any additional observations or notes…"></textarea>
                <div class="save-actions">
                    <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveVisit(false)" style="padding:12px 26px;"><i class="fas fa-save"></i> Save complete visit</button>
                     <button type="button" class="btn btn-outline" onclick="saveVisit(true)" style="padding:12px 20px;"><i class="fas fa-file-pen"></i> Save draft</button>

                    <button type="button" class="btn btn-light" onclick="resetVisitFields()" style="padding:12px 20px;">Clear Fields</button>
                </div>
                <div id="formMsg"></div>
                <div class="eval-nav">
                    <button type="button" class="btn-step-back" onclick="goPane(2)"><i class="fas fa-arrow-left"></i> Back</button>
                    <div></div>
                </div>
            </div>
            <div class="ai-panel collapsed" id="aiPanel">
                <div class="ai-panel-header">
                    <div class="ai-title"><i class="fas fa-robot"></i> AI Clinical Assistant</div>
                    <button type="button" class="btn btn-light" style="padding:5px 12px;font-size:11px;" onclick="getAiRecommendation()"><i class="fas fa-bolt"></i> Analyze</button>
                </div>
                <div class="ai-panel-body">
                    <div id="aiPrompt" style="text-align:center;color:#94a3b8;font-size:13px;padding:20px 0;">
                        <i class="fas fa-wand-magic-sparkles" style="font-size:22px;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
                        Fill in the visit fields, then click <strong>Analyze</strong> for AI clinical recommendations.
                    </div>
                    <div class="ai-loading" id="aiLoading"><div class="spinner"></div><div class="ai-loading-text">Analyzing clinical data…</div></div>
                    <div class="ai-result" id="aiResult">
                        <div class="ai-urgency" id="aiUrgency"></div>
                        <div class="ai-summary" id="aiSummary"></div>
                        <div class="ai-recommendation" id="aiRecommendation"></div>
                        <ul class="ai-warnings" id="aiWarnings"></ul>
                        <div class="ai-referral" id="aiReferral"></div>
                        <div class="ai-confidence" id="aiConfidence"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- ─── VISIT HISTORY (selected student) ─── -->
<div class="ws-panel clinic-data-panel" id="historyPanel" style="display:none;">
    <div class="clinic-panel-heading"><i class="fas fa-timeline"></i> Visit history <span>— <span id="historyStudentName"></span></span></div>
    <div class="visit-list" id="visitList"><div style="color:#94a3b8;font-size:14px;">Loading…</div></div>
</div>

<!-- ─── RECENT RECORDS ─── -->
<div class="ws-panel clinic-data-panel">
    <div class="clinic-panel-heading"><i class="fas fa-clock-rotate-left"></i> Recent clinic records</div>
    <?php if (empty($recent)): ?>
        <div style="color:#94a3b8;padding:14px 2px;">No clinic visits recorded yet.</div>
    <?php else: ?>
        <div class="recent-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Date &amp; Time</th><th>Reason</th><th>Assessment</th><th>Action</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><strong><?= h($r['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= h($r['student_number']) ?></span></td>
                    <td style="font-size:13px;"><?= $r['date_time'] ? date('M d, Y g:i A', strtotime($r['date_time'])) : '—' ?></td>
                    <td><?= h($r['reason_for_visit'] ?? '—') ?></td>
                    <td><?= h($r['assessment'] ?? '—') ?></td>
                    <td><?= h($r['action_taken'] ?? '—') ?></td>
                    <td><span class="pill <?= strtolower(h($r['record_status'])) ?>"><?= h($r['record_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

</div>
</main>

<!-- ─── MEDICAL PROFILE MODAL (clinic-owned; saved with the visit) ─── -->
<div class="profile-modal-overlay" id="profileModal">
    <div class="profile-modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="font-weight:800;font-size:16px;color:#0f172a;"><i class="fas fa-id-card-clip" style="color:#0d9488;"></i> Medical Profile</div>
            <button type="button" class="btn btn-light" style="padding:6px 12px;" onclick="closeProfile()"><i class="fas fa-times"></i></button>
        </div>
        <div style="font-size:12px;color:#94a3b8;margin-bottom:14px;">Pre-filled from the student's latest visit (current profile on file). Saved together with the visit record.</div>

        <div class="section-label" style="margin-top:0;">Blood Type</div>
        <select id="bloodType" class="form-control" style="width:100%;max-width:200px;">
            <option value="">— Not on file —</option>
            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $b): ?>
                <option value="<?= h($b) ?>"><?= h($b) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="form-row" style="margin-top:14px;">
            <div class="form-group"><label>Height (cm)</label><input type="number" step="0.01" id="height" class="form-control" placeholder="e.g., 165"></div>
            <div class="form-group"><label>Weight (kg)</label><input type="number" step="0.01" id="weight" class="form-control" placeholder="e.g., 60"></div>
        </div>

        <div class="form-group" style="margin-top:14px;"><label>Allergies</label><textarea id="allergies" class="form-control" rows="2" placeholder="List allergies, or None"></textarea></div>
        <div class="form-group"><label>Pre-existing Conditions</label><textarea id="conditions" class="form-control" rows="2" placeholder="List conditions, or None"></textarea></div>
        <div class="form-group"><label>Immunizations</label><textarea id="immunizations" class="form-control" rows="2" placeholder="e.g., COVID-19, Flu"></textarea></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button type="button" class="btn btn-light" onclick="closeProfile()">Close</button>
            <button type="button" class="btn btn-primary" onclick="closeProfile()"><i class="fas fa-check"></i> Use This Profile</button>
        </div>
    </div>
</div>
<script>
(function(){
    'use strict';
    var CSRF = (document.querySelector('meta[name=csrf-token]')||{}).getAttribute ?
               document.querySelector('meta[name=csrf-token]').getAttribute('content') : '';
    var APP_ROOT = '<?= $APP_ROOT ?>';
    var current = null; // identified student
    var tap = document.getElementById('tapInput');
    var tapStatus = document.getElementById('tapStatus');

    function el(id){ return document.getElementById(id); }
    function esc(t){ return String(t==null?'':t).replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function ord(n){ n=Number(n); if(!n) return '—'; var s=['th','st','nd','rd'], v=n%100; return n+(s[(v-20)%10]||s[v]||s[0])+' Year'; }

    function msg(text, ok, detail){
        var box = el('formMsg');
        if(!box) return;
        box.innerHTML = '<div class="'+(ok?'ok':'err')+'"><i class="fas '+(ok?'fa-circle-check':'fa-circle-exclamation')+'"></i> '
            + esc(text) + (detail ? ' <span style="font-weight:400;opacity:.8;">('+esc(detail)+')</span>' : '') + '</div>';
        if(ok){ setTimeout(function(){ box.innerHTML=''; }, 6000); }
    }

    function setTapStatus(text, ok){
        if(!tapStatus) return;
        tapStatus.innerHTML = '<i class="fas '+(ok?'fa-circle-check':'fa-circle-dot')+'" style="color:'+(ok?'#059669':'#0d9488')+';margin-right:6px;"></i>'+esc(text);
        tapStatus.classList.toggle('awaiting', !ok);
        var strip = el('stripState');
        if(strip) strip.textContent = ok ? 'Identified' : 'Ready';
    }

    // ── Identify (RFID tap or manual student_id) ──
    function identify(payload){
        setTapStatus('Processing…', false);
        fetch('../api/clinic.php?action=identify', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
            body:JSON.stringify(payload)
        }).then(function(r){ return r.json().then(function(d){ return {status:r.status, data:d}; }); })
        .then(function(res){
            if(res.data && res.data.success){ showStudent(res.data.data); }
            else{
                setTapStatus(res.data && res.data.message ? res.data.message : 'Not recognized', false);
                msg((res.data && res.data.message) || 'Identification failed.', false, 'HTTP '+res.status);
            }
        })
        .catch(function(err){ setTapStatus('Network error', false); msg('Network error during tap-in.', false, String(err)); });
    }

    function showStudent(s){
        current = s;
        el('wsEmpty').style.display = 'none';
        el('wsForm').style.display = 'none';
        el('wsStudent').style.display = 'block';
        if(s.photo){
            var photoSrc = s.photo;
            if (/^(https?:|data:|blob:)/i.test(photoSrc)) { /* absolute, keep */ }
            else if (photoSrc.startsWith('../') || photoSrc.startsWith('/')) { photoSrc = APP_ROOT + photoSrc.replace(/^\.\.\//, ''); }
            else { photoSrc = APP_ROOT + photoSrc.replace(/^\.\//, ''); }
            el('wsAvatar').innerHTML = '<img src="'+esc(photoSrc)+'" alt="Student photo" style="width:100%;height:100%;object-fit:cover;border-radius:16px;">';
        }
        else { el('wsAvatar').textContent = s.name ? s.name.trim().charAt(0).toUpperCase() : '?'; }
        el('wsName').textContent = s.name || '—';
        el('wsCard').textContent = s.card_uid ? ('Card UID: ' + s.card_uid) : 'Manual selection';
        el('wsId').textContent = s.student_number || '—';
        el('wsProgram').textContent = s.program || '—';
        el('wsYear').textContent = ord(s.year_level);
        el('wsSection').textContent = s.section || '—';
        el('formStudentName').textContent = s.name || '—';
        el('historyStudentName').textContent = s.name || '—';
        setTapStatus('Student identified: ' + (s.student_number || ''), true);
        prefillProfile(s.profile);
        loadHistory();
        el('wsPanel').scrollIntoView({behavior:'smooth', block:'start'});
    }

    // ── RFID tap input (10-digit UID auto-submit) ──
    if(tap){
        function focusTap(){ tap.focus(); }
        focusTap();
        document.addEventListener('click', function(e){
            var tag = (e.target.tagName || '').toLowerCase();
            if(tag === 'input' || tag === 'textarea' || tag === 'select') return;
            focusTap();
        });
        tap.addEventListener('input', function(){
            this.value = this.value.replace(/[^0-9]/g,'').slice(0,10);
            if(this.value.trim().length === 10){
                var uid = this.value.trim();
                this.value = '';
                identify({card_uid: uid});
            }
        });
    }

    // ── Student Search ──
    var students = <?= json_encode($students) ?>;
    var searchInput = el('studentSearch');
    var searchDD = el('searchDropdown');
    var searchIdx = -1;

    function renderSearch(query){
        if(!searchDD) return;
        query = (query||'').toLowerCase().trim();
        if(query.length < 1){ searchDD.classList.remove('open'); searchDD.innerHTML=''; searchIdx=-1; return; }
        var allHits = students.filter(function(s){
            return (s.name||'').toLowerCase().indexOf(query) !== -1
                || (s.student_number||'').toLowerCase().indexOf(query) !== -1
                || (s.course||'').toLowerCase().indexOf(query) !== -1;
        });
        var hits = allHits.slice(0, 8);
        if(hits.length === 0){
            searchDD.innerHTML = '<div class="search-empty">No students found for "' + esc(query) + '"</div>';
        } else {
            var html = hits.map(function(s, i){
                var initials = (s.name||'?').trim().split(/\s+/).map(function(w){ return w.charAt(0); }).join('').slice(0,2).toUpperCase();
                return '<div class="search-item" data-id="'+s.id+'" data-idx="'+i+'">'
                    + '<div class="si-avatar">'+initials+'</div>'
                    + '<div class="si-info"><div class="si-name">'+esc(s.name)+'</div>'
                    + '<div class="si-meta">'+esc(s.student_number)+' · '+esc(s.course)+'</div></div></div>';
            }).join('');
            if(allHits.length > 8){
                html += '<div class="search-empty" style="font-size:11px;border-top:1px solid #f1f5f9;">Showing 8 of '+allHits.length+' results</div>';
            }
            searchDD.innerHTML = html;
        }
        searchDD.classList.add('open');
        searchIdx = -1;
    }

    if(searchInput){
        searchInput.addEventListener('input', function(){ renderSearch(this.value); });
        searchInput.addEventListener('focus', function(){ renderSearch(this.value); });
        searchInput.addEventListener('keydown', function(e){
            var items = searchDD ? searchDD.querySelectorAll('.search-item') : [];
            if(e.key === 'ArrowDown'){
                e.preventDefault();
                searchIdx = Math.min(searchIdx + 1, items.length - 1);
                items.forEach(function(it,i){ it.classList.toggle('active', i === searchIdx); });
            } else if(e.key === 'ArrowUp'){
                e.preventDefault();
                searchIdx = Math.max(searchIdx - 1, 0);
                items.forEach(function(it,i){ it.classList.toggle('active', i === searchIdx); });
            } else if(e.key === 'Enter'){
                e.preventDefault();
                if(searchIdx >= 0 && items[searchIdx]){
                    pickStudent(parseInt(items[searchIdx].dataset.id, 10));
                } else if(items.length === 1){
                    pickStudent(parseInt(items[0].dataset.id, 10));
                }
            } else if(e.key === 'Escape'){
                searchDD.classList.remove('open');
            }
        });
        searchDD.addEventListener('click', function(e){
            var item = e.target.closest('.search-item');
            if(item) pickStudent(parseInt(item.dataset.id, 10));
        });
        document.addEventListener('click', function(e){
            if(!e.target.closest('.student-search-wrap') && searchDD) searchDD.classList.remove('open');
        });
    }

    function pickStudent(id){
        if(!id) return;
        if(searchInput) searchInput.value = '';
        if(searchDD) searchDD.classList.remove('open');
        identify({student_id: id});
    }

    // ── Workspace state ──
    window.startAssessment = function(){
        if(!current) return;
        el('wsStudent').style.display = 'none';
        el('wsForm').style.display = 'block';
        goPane(1);
        el('wsForm').scrollIntoView({behavior:'smooth', block:'start'});
    };

    window.resetWorkspace = function(){
        current = null;
        el('wsStudent').style.display = 'none';
        el('wsForm').style.display = 'none';
        el('wsEmpty').style.display = 'block';
        el('historyPanel').style.display = 'none';
        goPane(1);
        resetVisitFields(true);
        if(searchInput) searchInput.value = '';
        if(searchDD) searchDD.classList.remove('open');
        setTapStatus('Awaiting card tap…', false);
        if(tap) tap.focus();
    };

    // ── Stepper / Pane navigation ──
    window.goPane = function(n){
        var panes = document.querySelectorAll('#wsForm .eval-pane');
        var steps = document.querySelectorAll('#wsForm .eval-step');
        var lines = document.querySelectorAll('#wsForm .eval-step-line');
        panes.forEach(function(p){ p.classList.toggle('active', parseInt(p.dataset.pane) === n); });
        steps.forEach(function(s){
            var sn = parseInt(s.dataset.step);
            s.classList.remove('active','done');
            if(sn === n) s.classList.add('active');
            else if(sn < n) s.classList.add('done');
        });
        lines.forEach(function(l, i){ l.classList.toggle('done', i < n - 1); });
        // Show AI panel only on pane 3
        var ai = el('aiPanel');
        if(ai) ai.classList.toggle('collapsed', n !== 3);
    };

    // ── AI Aid Recommendation ──
    function showAiPanel(){
        var p = el('aiPanel');
        if(p) p.classList.remove('collapsed');
    }
    window.getAiRecommendation = function(){
        showAiPanel();
        var prompt = el('aiPrompt'), loading = el('aiLoading'), result = el('aiResult');
        if(prompt) prompt.style.display = 'none';
        if(loading) loading.classList.add('active');
        if(result) result.classList.remove('active');

        var payload = {
            reason_for_visit:       resolveValue('reasonSelect','reasonOtherInput'),
            assessment:             resolveValue('assessSelect','assessOtherInput'),
            temperature:            el('temperature') ? el('temperature').value : '',
            blood_pressure:         el('bloodPressure') ? el('bloodPressure').value : '',
            allergies:              el('allergies') ? el('allergies').value : '',
            pre_existing_conditions:el('conditions') ? el('conditions').value : '',
            immunization_records:   el('immunizations') ? el('immunizations').value : '',
            height:                 el('height') ? el('height').value : '',
            weight:                 el('weight') ? el('weight').value : '',
            nurse_notes:            el('nurseNotes') ? el('nurseNotes').value : ''
        };
        Object.assign(payload, expandedPayload());

        fetch('../api/clinic-ai-recommend.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json().then(function(d){ return {status:r.status, data:d}; }); })
        .then(function(res){
            if(loading) loading.classList.remove('active');
            if(res.data && res.data.success){
                var d = res.data.data;
                var urgEl = el('aiUrgency');
                if(urgEl){
                    urgEl.className = 'ai-urgency ' + esc(d.urgency);
                    urgEl.innerHTML = '<i class="fas fa-circle"></i> ' + esc(d.urgency.toUpperCase());
                }
                var confEl = el('aiConfidence');
                if(confEl) confEl.textContent = 'Confidence: ' + esc(d.confidence);
                var sumEl = el('aiSummary');
                if(sumEl) sumEl.textContent = d.summary || '';
                var recEl = el('aiRecommendation');
                if(recEl) recEl.textContent = d.recommendation || '';
                var warnEl = el('aiWarnings');
                if(warnEl){
                    if(d.key_warnings && d.key_warnings.length > 0){
                        warnEl.innerHTML = d.key_warnings.map(function(w){ return '<li>' + esc(w) + '</li>'; }).join('');
                    } else { warnEl.innerHTML = ''; }
                }
                var refEl = el('aiReferral');
                if(refEl){
                    refEl.innerHTML = d.referral_needed
                        ? '<div class="ai-referral"><i class="fas fa-triangle-exclamation"></i> Physician referral recommended</div>'
                        : '';
                }
                if(result) result.classList.add('active');
            } else {
                if(prompt) prompt.style.display = 'block';
                var errMsg = (res.data && res.data.message) ? res.data.message : 'AI recommendation failed.';
                showToast(errMsg, 'error');
            }
        })
        .catch(function(){
            if(loading) loading.classList.remove('active');
            if(prompt) prompt.style.display = 'block';
            showToast('Network error while fetching AI recommendation.', 'error');
        });
    };

    // Reset AI panel when workspace resets
    var origReset = window.resetWorkspace;
    window.resetWorkspace = function(){
        if(origReset) origReset();
        var p = el('aiPanel'), prompt = el('aiPrompt'), loading = el('aiLoading'), result = el('aiResult');
        if(p) p.classList.add('collapsed');
        if(prompt) prompt.style.display = '';
        if(loading) loading.classList.remove('active');
        if(result) result.classList.remove('active');
    };

    // ── Medical Profile modal ──
    function prefillProfile(p){
        p = p || {};
        var set = function(id, v){ var e2 = el(id); if(e2) e2.value = (v === null || v === undefined) ? '' : v; };
        set('bloodType', p.blood_type || '');
        set('height', p.height || '');
        set('weight', p.weight || '');
        set('allergies', p.allergies || '');
        set('conditions', p.pre_existing_conditions || '');
        set('immunizations', p.immunization_records || '');
    }
    window.openProfile = function(){ el('profileModal').classList.add('active'); document.body.style.overflow='hidden'; };
    window.closeProfile = function(){ el('profileModal').classList.remove('active'); document.body.style.overflow=''; };
    el('profileModal').addEventListener('click', function(e){ if(e.target === this) closeProfile(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeProfile(); });
// ── "Other" free-text toggles ──
    function wireOther(selectId, otherId, inputId){
        var sel = el(selectId), oth = el(otherId), inp = el(inputId);
        if(!sel) return;
        sel.addEventListener('change', function(){
            var isOther = sel.value === 'Other';
            if(oth){ oth.classList.toggle('show', isOther); }
            if(inp && !isOther){ inp.value=''; }
        });
    }
    wireOther('reasonSelect','reasonOther','reasonOtherInput');
    wireOther('assessSelect','assessOther','assessOtherInput');
    wireOther('actionSelect','actionOther','actionOtherInput');

    function resolveValue(selectId, otherInputId){
        var sel = el(selectId); if(!sel) return '';
        if(sel.value === 'Other'){
            var inp = el(otherInputId);
            var custom = inp ? inp.value.trim() : '';
            return custom !== '' ? custom : 'Other';
        }
        return sel.value;
    }

    window.resetVisitFields = function(silent){
        ['reasonSelect','assessSelect','actionSelect'].forEach(function(id){ var s2 = el(id); if(s2) s2.value=''; });
        ['reasonOtherInput','assessOtherInput','actionOtherInput'].forEach(function(id){ var i2 = el(id); if(i2) i2.value=''; });
        ['reasonOther','assessOther','actionOther'].forEach(function(id){ var o2 = el(id); if(o2) o2.classList.remove('show'); });
        ['nurseNotes','temperature','bloodPressure','pulse','respiratoryRate','oxygenSaturation','visitType','onsetAt','incidentDetails','symptoms','painScore','currentMedications','disposition','returnPrecautions','followUpPlan'].forEach(function(id){ var f = el(id); if(f) f.value=''; });
        ['flagBreathing','flagChestPain','flagUnconscious','flagAllergy','flagBleeding','flagConfusion'].forEach(function(id){ var f = el(id); if(f) f.checked=false; });
        var box = el('formMsg'); if(box) box.innerHTML='';
        if(!silent) msg('Visit fields cleared.', true);
    };

    function flagPayload(){
        return ['flagBreathing','flagChestPain','flagUnconscious','flagAllergy','flagBleeding','flagConfusion']
            .map(function(id){ return el(id) && el(id).checked ? id.replace('flag','') : ''; }).filter(Boolean);
    }
    function expandedPayload(){
        return {
            visit_type: el('visitType') ? el('visitType').value : '',
            onset_at: el('onsetAt') ? el('onsetAt').value : '',
            incident_details: el('incidentDetails') ? el('incidentDetails').value.trim() : '',
            symptoms: el('symptoms') ? el('symptoms').value.trim() : '',
            pain_score: el('painScore') ? el('painScore').value : '',
            red_flags: flagPayload(),
            pulse: el('pulse') ? el('pulse').value : '',
            respiratory_rate: el('respiratoryRate') ? el('respiratoryRate').value : '',
            oxygen_saturation: el('oxygenSaturation') ? el('oxygenSaturation').value : '',
            current_medications: el('currentMedications') ? el('currentMedications').value.trim() : '',
            disposition: el('disposition') ? el('disposition').value : '',
            return_precautions: el('returnPrecautions') ? el('returnPrecautions').value.trim() : '',
            follow_up_plan: el('followUpPlan') ? el('followUpPlan').value.trim() : ''
        };
    }

    // ── SAVE HEALTH RECORD ──
    window.saveVisit = function(draft){
        if(!current){ msg('No student selected. Tap a card or select a student first.', false); return; }
        var reason = resolveValue('reasonSelect','reasonOtherInput');
        var assessment = resolveValue('assessSelect','assessOtherInput');
        var action = resolveValue('actionSelect','actionOtherInput');
        var disposition = el('disposition') ? el('disposition').value : '';
        if(!draft && !reason){ msg('Please select/enter a reason for visit.', false); return; }
        if(!draft && !action){ msg('Please select/enter an action taken.', false); return; }
        if(!draft && !disposition){ msg('Please select the visit disposition.', false); return; }

        var btn = el('saveBtn');
        if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

        fetch('../api/clinic.php?action=save-visit', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token':CSRF },
            body: JSON.stringify(Object.assign({
                student_id: current.id,
                reason_for_visit: reason || (draft ? 'Draft intake' : ''),
                assessment: assessment,
                action_taken: action || (draft ? 'Draft pending completion' : ''),
                nurse_notes: el('nurseNotes') ? el('nurseNotes').value.trim() : '',
                temperature: el('temperature') ? el('temperature').value : '',
                blood_pressure: el('bloodPressure') ? el('bloodPressure').value.trim() : '',
                blood_type: el('bloodType') ? el('bloodType').value : '',
                height: el('height') ? el('height').value : '',
                weight: el('weight') ? el('weight').value : '',
                allergies: el('allergies') ? el('allergies').value.trim() : '',
                pre_existing_conditions: el('conditions') ? el('conditions').value.trim() : '',
                immunization_records: el('immunizations') ? el('immunizations').value.trim() : '',
                save_mode: draft ? 'draft' : 'complete'
            }, expandedPayload()))
        }).then(function(r){
            return r.json().then(function(d){ return {status:r.status, data:d}; });
        })
        .then(function(res){
            if(res.data && res.data.success){
                showToast(res.data.message || (draft ? 'Draft saved.' : 'Visit saved.'), 'success');
                msg(res.data.message || (draft ? 'Draft saved.' : 'Health record saved and synced to the registrar portal.'), true);
                if(!draft){ resetVisitFields(true); loadHistory(); }
            } else {
                var m = (res.data && res.data.message) ? res.data.message : 'Save failed.';
                if(res.status === 419) m = 'Session/CSRF token expired — please reload the page.';
                if(res.status === 401) m = 'Your session expired — please log in again.';
                if(res.status === 403) m = 'You do not have permission to save records (nurse role required).';
                msg(m, false, 'HTTP ' + res.status);
            }
        })
        .catch(function(err){ msg('Network error while saving.', false, String(err)); })
        .finally(function(){
            if(btn){ btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Health Record'; }
        });
    };
// ── Visit history for the selected student ──
    function loadHistory(){
        if(!current){ return; }
        el('historyPanel').style.display = 'block';
        fetch('../api/clinic.php?action=visits&student_id=' + current.id)
            .then(function(r){ return r.json(); })
            .then(function(d){
                var box = el('visitList');
                if(!box) return;
                if(!d.success || !d.data || d.data.length === 0){
                    box.innerHTML = '<div style="color:#94a3b8;font-size:14px;">No clinic visits recorded yet.</div>';
                    return;
                }
                box.innerHTML = d.data.map(function(v){
                    var dt = v.date_time ? new Date(v.date_time.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                    var vitals = [];
                    if(v.temperature) vitals.push(v.temperature + '°C');
                    if(v.blood_pressure) vitals.push(v.blood_pressure);
                    return '<div class="visit-item">'
                        + '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">'
                        + '<strong style="font-size:13px;color:#0f172a;">'+dt+'</strong>'
                        + '<span class="pill '+String(v.record_status||'').toLowerCase()+'">'+(v.record_status||'Recorded')+'</span>'
                        + '</div>'
                        + '<div style="font-size:12px;color:#64748b;margin-top:4px;">'
                        + '<div><i class="fas fa-comment-medical"></i> Reason: '+(v.reason_for_visit||'—')+'</div>'
                        + (v.assessment ? '<div><i class="fas fa-stethoscope"></i> Assessment: '+v.assessment+'</div>' : '')
                        + '<div><i class="fas fa-hand-holding-medical"></i> Action: '+(v.action_taken||'—')+'</div>'
                        + (vitals.length ? '<div><i class="fas fa-heart-pulse"></i> Vitals: '+vitals.join(' · ')+'</div>' : '')
                        + (v.nurse_notes ? '<div><i class="fas fa-pen"></i> '+v.nurse_notes+'</div>' : '')
                        + '</div></div>';
                }).join('');
            })
            .catch(function(){});
    }
})();
</script>
<?php include '../includes/footer.php'; ?>
</div>