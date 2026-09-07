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
$totalStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status IN ('active','enrolled')");

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
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* ── Hero (student-portal match) ────────────────────────── */
.clinic-hero{display:flex;align-items:center;gap:24px;color:#fff;border-radius:20px;padding:42px 48px;box-shadow:0 20px 60px rgba(0,0,0,.25);position:relative;overflow:hidden;min-height:130px;background-size:cover;background-position:center}
.clinic-hero::after{content:'';position:absolute;right:-80px;top:-80px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.12) 0%,transparent 70%)}
.clinic-hero::before{content:'';position:absolute;right:40px;bottom:-120px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%)}
.clinic-hero>*{position:relative;z-index:2}
.hero-avatar{width:88px;height:88px;min-width:88px;border-radius:50%;background:rgba(255,255,255,.18);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:800;color:#fff;border:3.5px solid rgba(255,255,255,.6);box-shadow:0 8px 32px rgba(0,0,0,.2)}
.hero-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.hero-body{flex:1;min-width:0}.hero-greet{font-size:11.5px;text-transform:uppercase;letter-spacing:1.4px;color:rgba(255,255,255,.95);font-weight:700;margin-bottom:8px;text-shadow:0 2px 4px rgba(0,0,0,.8),0 0 20px rgba(0,0,0,.6)}
.hero-title{font-size:32px;font-weight:800;letter-spacing:-.6px;margin:0 0 10px;color:#fff;line-height:1.15;text-shadow:0 3px 6px rgba(0,0,0,.9),0 0 25px rgba(0,0,0,.7)}.hero-meta{display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}
.hero-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:9999px;background:rgba(0,0,0,.25);border:1.5px solid rgba(255,255,255,.3);color:#fff;font-size:13px;font-weight:700;backdrop-filter:blur(10px);transition:all .2s;white-space:nowrap;text-shadow:0 2px 4px rgba(0,0,0,.8)}
.hero-chip:hover{background:rgba(0,0,0,.35);border-color:rgba(255,255,255,.5);transform:translateY(-2px)}

/* ── Status Strip ────────────────────────────────────────── */
.status-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:18px}
@media(max-width:860px){.status-strip{grid-template-columns:repeat(2,1fr)}}
.s-item{background:#fff;border:1px solid #f1f5f9;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:14px;box-shadow:0 4px 16px rgba(15,23,42,.04);transition:box-shadow .15s}
.s-item:hover{box-shadow:0 6px 22px rgba(15,23,42,.07)}
.s-icon{width:42px;height:42px;min-width:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px}
.s-icon.green{background:#dcfce7;color:#16a34a}.s-icon.blue{background:#dbeafe;color:#2563eb}
.s-icon.purple{background:#f3e8ff;color:#9333ea}.s-icon.amber{background:#fef3c7;color:#d97706}
.s-value{font-size:18px;font-weight:800;color:#0f172a;line-height:1}
.s-label{font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* ── Tap Card ────────────────────────────────────────────── */
.clinic-grid{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start}
@media(max-width:1000px){.clinic-grid{grid-template-columns:1fr}}
.tap-card{background:#fff;border:1px solid #ccfbf1;border-radius:18px;padding:24px;box-shadow:0 8px 28px rgba(13,148,136,.08);position:relative;overflow:hidden}
.tap-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#0d9488,#14b8a6,#5eead4)}
.tap-icon{width:64px;height:64px;margin:0 auto 12px;border-radius:20px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#99f6e4,#5eead4);color:#0f766e;box-shadow:0 6px 20px rgba(13,148,136,.15)}
.tap-icon i{font-size:28px}.tap-title{text-align:center;font-weight:800;font-size:17px;color:#0f172a}
.tap-sub{text-align:center;font-size:12px;color:#94a3b8;margin-top:4px}
.tap-status{margin-top:14px;padding:12px 14px;border-radius:12px;background:#f0fdfa;border:1px dashed #5eead4;text-align:center;font-weight:700;font-size:13px;color:#134e4a}
#tapInput{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
.divider{display:flex;align-items:center;gap:10px;margin:18px 0 14px;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.6px;font-weight:700}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Student Search ──────────────────────────────────────── */
.student-search-wrap{position:relative}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;z-index:2}
.student-search-input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;font-weight:500;color:#0f172a;background:#f8fafc;transition:border-color .15s,box-shadow .15s;outline:none;box-sizing:border-box}
.student-search-input::placeholder{color:#94a3b8;font-weight:400}
.student-search-input:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff}
.search-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 36px rgba(15,23,42,.12);max-height:260px;overflow-y:auto;z-index:50;display:none}
.search-dropdown.open{display:block}
.search-item{padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:12px;border-bottom:1px solid #f1f5f9;transition:background .1s}
.search-item:last-child{border-bottom:none}
.search-item:hover,.search-item.active{background:#f0fdfa}
.search-item .si-avatar{width:36px;height:36px;min-width:36px;border-radius:10px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
.search-item .si-info{flex:1;min-width:0}
.search-item .si-name{font-size:13px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.search-item .si-meta{font-size:11px;color:#64748b;margin-top:1px}
.search-empty{padding:14px;text-align:center;color:#94a3b8;font-size:13px;font-weight:500}

/* ── Workspace Panel ─────────────────────────────────────── */
.ws-panel{background:#fff;border:1px solid #f1f5f9;border-radius:18px;padding:22px;box-shadow:0 8px 24px rgba(15,23,42,.05);min-height:300px}
.student-chip-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.avatar{width:56px;height:56px;min-width:56px;border-radius:16px;font-weight:800;font-size:20px;display:flex;align-items:center;justify-content:center;background:#0d9488;color:#fff;overflow:hidden;position:relative}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:16px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:16px}
.info-cell{background:#f0fdfa;border-radius:12px;padding:10px 14px}
.info-cell .k{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#0f766e;font-weight:700}
.info-cell .v{font-size:14px;font-weight:600;color:#134e4a;margin-top:2px}
.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;background:#f1f5f9;color:#475569}
.pill.active{background:#d1fae5;color:#065f46}

/* ── Buttons / Form ──────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:12px;font-family:inherit;font-size:14px;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-primary{background:#0d9488;color:#fff}.btn-primary:hover{background:#0f766e}
.btn-light{background:#f1f5f9;color:#334155}.btn-light:hover{background:#e2e8f0}
.btn-outline{background:#fff;border:1px solid #99f6e4;color:#0f766e}.btn-outline:hover{background:#f0fdfa}
.btn:disabled{opacity:.55;cursor:not-allowed}
.section-title{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px;color:#0f172a;margin-top:24px;padding-bottom:8px;border-bottom:2px solid #f1f5f9}
.section-label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#64748b;font-weight:700;margin:16px 0 8px}
.form-control{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:500;outline:none}
.form-control:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.1)}
textarea.form-control{resize:vertical;min-height:60px}
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
.other-input{display:none;margin-top:8px}.other-input.show{display:block}
.visit-list .visit-item{border:1px solid #f1f5f9;border-radius:12px;padding:10px 14px;margin-bottom:8px;background:#fafcfd}
#formMsg div{padding:12px 16px;border-radius:12px;font-weight:600;font-size:13px;margin-top:8px}

/* ── Profile Modal ───────────────────────────────────────── */
.profile-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.profile-modal-overlay.active{display:flex}
.profile-modal{background:#fff;border-radius:18px;width:100%;max-width:560px;max-height:88vh;overflow-y:auto;padding:22px 24px}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

<!-- ── Welcome Hero (student-portal style) ─────────────────── -->
<div class="clinic-hero" style="background-image: url('<?= $APP_ROOT ?>assets/images/bestlink%20banner.jpg'); background-size: cover; background-position: center;">
    <div class="hero-avatar">
        <?= strtoupper(substr(trim($nurseName), 0, 1)) ?>
    </div>
    <div class="hero-body">
        <div class="hero-greet"><?= $timeGreeting ?></div>
        <div class="hero-title"><?= h($nurseName) ?> 👋</div>
        <div class="hero-meta">
            <span class="hero-chip"><i class="fa-solid fa-hospital"></i> Clinic Portal</span>
            <span class="hero-chip"><i class="fa-solid fa-clock"></i> <?= date('F d, Y') ?></span>
            <span class="hero-chip"><i class="fa-solid fa-calendar-day"></i> <?= $todayVisits ?> visit<?= $todayVisits === 1 ? '' : 's' ?> today</span>
        </div>
    </div>
</div>

<div class="status-strip">
    <div class="s-item">
        <div class="s-icon green"><i class="fa-solid fa-calendar-day"></i></div>
        <div><div class="s-value"><?= $todayVisits ?></div><div class="s-label">Visits Today</div></div>
    </div>
    <div class="s-item">
        <div class="s-icon blue"><i class="fa-solid fa-notes-medical"></i></div>
        <div><div class="s-value"><?= $totalVisits ?></div><div class="s-label">Total Clinic Records</div></div>
    </div>
    <div class="s-item">
        <div class="s-icon purple"><i class="fa-solid fa-user-graduate"></i></div>
        <div><div class="s-value"><?= $totalStudents ?></div><div class="s-label">Active Students</div></div>
    </div>
    <div class="s-item">
        <div class="s-icon amber"><i class="fa-solid fa-credit-card"></i></div>
        <div><div class="s-value" id="stripState">Ready</div><div class="s-label">Tap-In Status</div></div>
    </div>
</div>

<div class="clinic-grid" style="margin-top:20px;">
    <!-- ─── TAP-IN ─── -->
    <div class="tap-card">
        <div class="tap-icon"><i class="fas fa-credit-card"></i></div>
        <div class="tap-title">Student Tap-In</div>
        <div class="tap-sub">Tap the RFID student ID card on the reader</div>
        <div class="tap-status" id="tapStatus"><i class="fas fa-circle-dot" style="color:#0d9488;margin-right:6px;"></i>Awaiting card tap…</div>
        <input id="tapInput" type="text" maxlength="10" autocomplete="off" aria-label="Card UID" />

        <div class="divider">or</div>

        <label class="section-label" style="margin-top:0;">Search student by name or ID</label>
        <div class="student-search-wrap">
            <i class="fas fa-magnifying-glass search-icon"></i>
            <input id="studentSearch" type="text" class="student-search-input" placeholder="Type name or student number…" autocomplete="off" />
            <div id="searchDropdown" class="search-dropdown"></div>
        </div>
    </div>
<!-- ─── WORKSPACE ─── -->
    <div class="ws-panel" id="wsPanel">
        <div id="wsEmpty" style="text-align:center;color:#94a3b8;padding:60px 10px;">
            <i class="fas fa-hand-pointer" style="font-size:34px;display:block;margin-bottom:12px;color:#cbd5e1;"></i>
            Tap a card or select a student to begin.
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
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
                <button type="button" class="btn btn-outline" onclick="openProfile()"><i class="fas fa-id-card-clip"></i> Medical Profile</button>
                <button type="button" class="btn btn-primary" onclick="startAssessment()"><i class="fas fa-user-check"></i> Verify &amp; Start Assessment</button>
                <button type="button" class="btn btn-light" onclick="resetWorkspace()"><i class="fas fa-rotate"></i> New Scan</button>
            </div>
        </div>

        <div id="wsForm" style="display:none;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="font-weight:800;font-size:16px;color:#0f172a;"><i class="fas fa-clipboard-check" style="color:#2563eb;"></i> Clinic Visit — <span id="formStudentName"></span></div>
                <button type="button" class="btn btn-light" style="padding:8px 14px;font-size:12px;" onclick="openProfile()"><i class="fas fa-id-card-clip"></i> Medical Profile</button>
            </div>

            <div class="section-title"><i class="fas fa-heart-pulse" style="color:#dc2626;"></i> Vitals</div>
            <div class="form-row">
                <div class="form-group"><label>Temperature (°C)</label><input type="number" step="0.1" id="temperature" class="form-control" placeholder="e.g., 36.8"></div>
                <div class="form-group"><label>Blood Pressure</label><input type="text" id="bloodPressure" class="form-control" placeholder="e.g., 120/80"></div>
            </div>

            <div class="section-title"><i class="fas fa-clipboard-list" style="color:#2563eb;"></i> This Visit</div>

            <div class="section-label">Reason for Visit <span style="color:#dc2626;">*</span></div>
            <select id="reasonSelect" class="form-control" style="width:100%;">
                <option value="">— Select a reason —</option>
                <?php foreach (['Headache','Fever','Stomachache','Menstrual Cramps','Dizziness','Minor Injury','Other'] as $r): ?>
                    <option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
            </select>
            <div class="other-input" id="reasonOther"><input type="text" id="reasonOtherInput" class="form-control" placeholder="Enter the reason for visit…"></div>

            <div class="section-label">Nurse's Assessment / Initial Diagnosis</div>
            <select id="assessSelect" class="form-control" style="width:100%;">
                <option value="">— Select assessment —</option>
                <?php foreach (['Headache','Fever','Dysmenorrhea','Possible Dehydration','Minor Abrasion','Other'] as $a): ?>
                    <option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?>
            </select>
            <div class="other-input" id="assessOther"><input type="text" id="assessOtherInput" class="form-control" placeholder="Enter the assessment / diagnosis…"></div>

            <div class="section-label">Action Taken <span style="color:#dc2626;">*</span></div>
            <select id="actionSelect" class="form-control" style="width:100%;">
                <option value="">— Select action —</option>
                <?php foreach (['Rest','Hydration','First Aid','Medication Administered','Referred to Physician','Sent Home','Other'] as $a): ?>
                    <option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?>
            </select>
            <div class="other-input" id="actionOther"><input type="text" id="actionOtherInput" class="form-control" placeholder="Enter the action performed…"></div>

            <div class="section-label">Nurse's Notes</div>
            <textarea id="nurseNotes" class="form-control" rows="3" style="width:100%;" placeholder="Any additional observations or notes…"></textarea>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;">
                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveVisit()" style="padding:12px 26px;"><i class="fas fa-save"></i> Save Health Record</button>
                <button type="button" class="btn btn-light" onclick="resetVisitFields()" style="padding:12px 20px;">Clear Visit Fields</button>
            </div>
            <div id="formMsg"></div>
        </div>
    </div>
</div>
<!-- ─── VISIT HISTORY (selected student) ─── -->
<div class="ws-panel" id="historyPanel" style="margin-top:20px;display:none;">
    <div style="font-weight:700;font-size:15px;color:#0f172a;margin-bottom:10px;"><i class="fas fa-timeline" style="color:#0d9488;"></i> Visit History — <span id="historyStudentName"></span></div>
    <div class="visit-list" id="visitList"><div style="color:#94a3b8;font-size:14px;">Loading…</div></div>
</div>

<!-- ─── RECENT RECORDS ─── -->
<div class="ws-panel" style="margin-top:20px;">
    <div style="font-weight:700;font-size:15px;color:#0f172a;margin-bottom:10px;"><i class="fas fa-clock-rotate-left" style="color:#0d9488;"></i> Recent Clinic Records</div>
    <?php if (empty($recent)): ?>
        <div style="color:#94a3b8;padding:14px 2px;">No clinic visits recorded yet.</div>
    <?php else: ?>
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
        var hits = students.filter(function(s){
            return (s.name||'').toLowerCase().indexOf(query) !== -1
                || (s.student_number||'').toLowerCase().indexOf(query) !== -1
                || (s.course||'').toLowerCase().indexOf(query) !== -1;
        }).slice(0, 8);
        if(hits.length === 0){
            searchDD.innerHTML = '<div class="search-empty">No students found for "' + esc(query) + '"</div>';
        } else {
            searchDD.innerHTML = hits.map(function(s, i){
                var initials = (s.name||'?').trim().split(/\s+/).map(function(w){ return w.charAt(0); }).join('').slice(0,2).toUpperCase();
                return '<div class="search-item" data-id="'+s.id+'" data-idx="'+i+'">'
                    + '<div class="si-avatar">'+initials+'</div>'
                    + '<div class="si-info"><div class="si-name">'+esc(s.name)+'</div>'
                    + '<div class="si-meta">'+esc(s.student_number)+' · '+esc(s.course)+'</div></div></div>';
            }).join('');
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
        el('wsForm').scrollIntoView({behavior:'smooth', block:'start'});
    };

    window.resetWorkspace = function(){
        current = null;
        el('wsStudent').style.display = 'none';
        el('wsForm').style.display = 'none';
        el('wsEmpty').style.display = 'block';
        el('historyPanel').style.display = 'none';
        resetVisitFields(true);
        if(searchInput) searchInput.value = '';
        if(searchDD) searchDD.classList.remove('open');
        setTapStatus('Awaiting card tap…', false);
        if(tap) tap.focus();
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
        ['nurseNotes','temperature','bloodPressure'].forEach(function(id){ var f = el(id); if(f) f.value=''; });
        var box = el('formMsg'); if(box) box.innerHTML='';
        if(!silent) msg('Visit fields cleared.', true);
    };

    // ── SAVE HEALTH RECORD ──
    window.saveVisit = function(){
        if(!current){ msg('No student selected. Tap a card or select a student first.', false); return; }
        var reason = resolveValue('reasonSelect','reasonOtherInput');
        var assessment = resolveValue('assessSelect','assessOtherInput');
        var action = resolveValue('actionSelect','actionOtherInput');
        if(!reason){ msg('Please select/enter a reason for visit.', false); return; }
        if(!action){ msg('Please select/enter an action taken.', false); return; }

        var btn = el('saveBtn');
        if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

        fetch('../api/clinic.php?action=save-visit', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token':CSRF },
            body: JSON.stringify({
                student_id: current.id,
                reason_for_visit: reason,
                assessment: assessment,
                action_taken: action,
                nurse_notes: el('nurseNotes') ? el('nurseNotes').value.trim() : '',
                temperature: el('temperature') ? el('temperature').value : '',
                blood_pressure: el('bloodPressure') ? el('bloodPressure').value.trim() : '',
                blood_type: el('bloodType') ? el('bloodType').value : '',
                height: el('height') ? el('height').value : '',
                weight: el('weight') ? el('weight').value : '',
                allergies: el('allergies') ? el('allergies').value.trim() : '',
                pre_existing_conditions: el('conditions') ? el('conditions').value.trim() : '',
                immunization_records: el('immunizations') ? el('immunizations').value.trim() : ''
            })
        }).then(function(r){
            return r.json().then(function(d){ return {status:r.status, data:d}; });
        })
        .then(function(res){
            if(res.data && res.data.success){
                msg('Health record saved and synced to the registrar portal.', true);
                resetVisitFields(true);
                loadHistory();
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