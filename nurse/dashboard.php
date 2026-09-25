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
$ACTIVE_NAV = 'nurse_dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>

/* ================================================================
   CLINIC DASHBOARD — Visit Statistics
   A calm clinical reporting layer above the operational workspace.
   ================================================================ */
.clinic-stats{--clinic-teal:#0f766e;--clinic-teal-bright:#14b8a6;margin-top:18px}
.visit-stats-heading{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.visit-stat-strip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}
.visit-stat{position:relative;padding:20px 22px;border-right:1px solid #e2e8f0}
.visit-stat:last-child{border-right:0}
.visit-stat::after{content:"";position:absolute;left:22px;right:22px;bottom:0;height:3px;background:#ccfbf1}
.visit-stat.is-current::after,.visit-stat.is-week::after{background:linear-gradient(90deg,var(--clinic-teal),var(--clinic-teal-bright))}
.visit-stat.is-pending::after{background:#d97706}
.visit-stat-label{font-size:11px;font-weight:700;letter-spacing:.06em;color:#64748b;text-transform:uppercase}
.visit-stat-value{margin-top:7px;font-size:30px;line-height:1;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums}
.visit-stat-context{margin-top:7px;font-size:11px;color:#94a3b8}
.visit-stat.is-pending .visit-stat-value{color:#b45309}
@media(max-width:1000px){.visit-stat-strip{grid-template-columns:repeat(3,1fr)}.visit-stat{border-bottom:1px solid #e2e8f0}}
@media(max-width:700px){.visit-stat-strip{grid-template-columns:repeat(2,1fr)}.visit-stat{border-right:1px solid #e2e8f0}.visit-stat:last-child{grid-column:1/-1}}
@media(max-width:480px){.visit-stat-strip{grid-template-columns:1fr}.visit-stat{border-right:0}.visit-stat:last-child{grid-column:auto}}
@media(prefers-reduced-motion:reduce){.clinic-stats *{transition:none!important;animation:none!important}}

/* ================================================================
   NURSE DASHBOARD — Warm Clinical Design
   ================================================================ */

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
.s-item{background:#fff;border:1px solid #ebe8e3;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:14px;box-shadow:0 4px 16px rgba(15,23,42,.04);transition:box-shadow .15s}
.s-item:hover{box-shadow:0 6px 22px rgba(15,23,42,.07)}
.s-icon{width:42px;height:42px;min-width:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px}
.s-icon.green{background:#dcfce7;color:#16a34a}.s-icon.blue{background:#dbeafe;color:#2563eb}
.s-icon.purple{background:#f3e8ff;color:#9333ea}.s-icon.amber{background:#fef3c7;color:#d97706}
.s-value{font-size:18px;font-weight:800;color:#0f172a;line-height:1}
.s-label{font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* Clinic Grid */
.clinic-grid{display:grid;grid-template-columns:360px 1fr;gap:22px;align-items:start;transition:grid-template-columns .35s cubic-bezier(.4,0,.2,1)}
.clinic-grid.assessment-active{grid-template-columns:1fr}
.clinic-grid.assessment-active .tap-card{display:none}
@media(max-width:1000px){.clinic-grid{grid-template-columns:1fr}}
/* Tap-In Card */
.tap-card{background:#fff;border:1px solid #ebe8e3;border-radius:20px;padding:32px 28px;text-align:center;position:relative;box-shadow:0 2px 8px rgba(0,0,0,.03)}
.tap-card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#0d9488,#2dd4bf,#5eead4);border-radius:20px 20px 0 0}
.tap-icon{width:88px;height:88px;margin:0 auto 18px;border-radius:50%;background:linear-gradient(135deg,#ccfbf1,#99f6e4);display:flex;align-items:center;justify-content:center;color:#0f766e;position:relative;box-shadow:0 8px 30px rgba(13,148,136,.12)}
.tap-icon::before{content:"";position:absolute;inset:-8px;border-radius:50%;border:2px dashed rgba(13,148,136,.18);animation:tapRing 20s linear infinite}
.tap-icon::after{content:"";position:absolute;inset:-16px;border-radius:50%;border:1.5px solid rgba(13,148,136,.06)}
@keyframes tapRing{to{transform:rotate(360deg)}}
.tap-icon i{font-size:32px}
.tap-title{font-weight:800;font-size:17px;color:#0f172a;margin-bottom:4px}
.tap-sub{font-size:13px;color:#718096;margin-bottom:18px}
.tap-status{margin-top:0;padding:11px 16px;border-radius:12px;background:#f0fdfa;border:1px dashed #5eead4;text-align:center;font-weight:600;font-size:13px;color:#115e59;display:flex;align-items:center;justify-content:center;gap:8px}
.tap-status.awaiting{animation:tapGlow 2.5s ease-in-out infinite}
@keyframes tapGlow{0%,100%{background:#f0fdfa;border-color:#5eead4}50%{background:#ccfbf1;border-color:#0d9488}}
#tapInput{display:block;width:100%;margin-top:14px;padding:13px 16px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:15px;font-family:"JetBrains Mono",monospace;text-align:center;letter-spacing:2px;font-weight:600;color:#0f172a;background:#fafaf8;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
#tapInput:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 4px rgba(13,148,136,.1);background:#fff}
#tapInput::placeholder{color:#a0aec0;letter-spacing:0;font-weight:400;font-family:inherit}
.divider{display:flex;align-items:center;gap:14px;margin:20px 0 14px;font-size:12px;font-weight:500;color:#a0aec0}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:#ebe8e3}

/* Search */
.section-label{font-size:12px;font-weight:600;color:#718096;margin-bottom:8px}
.student-search-wrap{position:relative}
.student-search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;pointer-events:none}
.student-search-input{width:100%;padding:11px 14px 11px 38px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;color:#0f172a;background:#fafaf8;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.student-search-input:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 4px rgba(13,148,136,.1);background:#fff}
.student-search-input::placeholder{color:#a0aec0}
.search-dropdown{display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.1);z-index:100;max-height:240px;overflow-y:auto}
.search-dropdown.open{display:block}
.search-item{padding:11px 16px;cursor:pointer;font-size:13px;color:#334155;display:flex;align-items:center;gap:10px;transition:background .1s}
.search-item:hover,.search-item.active{background:#f0fdfa}
.search-item .si-name{font-weight:600;color:#0f172a}
.search-item .si-sub{font-size:11px;color:#94a3b8}

/* Workspace Panel */
.ws-panel{background:#fff;border:1px solid #ebe8e3;border-radius:20px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.03);min-height:300px}
.student-chip-row{display:flex;align-items:center;gap:16px;margin-bottom:18px}
.avatar{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#ccfbf1,#99f6e4);color:#0f766e;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0;overflow:hidden}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.info-cell{padding:11px 14px;background:#fafaf8;border-radius:11px;border:1px solid #f0ede9}
.info-cell .k{font-size:11px;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px}
.info-cell .v{font-size:14px;font-weight:700;color:#0f172a}
/* Stepper */
.eval-stepper{display:none;align-items:center;justify-content:center;padding:26px 0 14px;gap:0}
.eval-stepper.visible{display:flex}
.eval-step{display:flex;align-items:center;gap:8px}
.eval-step-num{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;border:2px solid #e2e8f0;color:#a0aec0;background:#fff;transition:all .3s;flex-shrink:0}
.eval-step.active .eval-step-num{background:#0d9488;border-color:#0d9488;color:#fff;box-shadow:0 0 0 5px rgba(13,148,136,.12)}
.eval-step.done .eval-step-num{background:#059669;border-color:#059669;color:#fff}
.eval-step-label{font-size:13px;font-weight:600;color:#a0aec0;white-space:nowrap;transition:color .3s}
.eval-step.active .eval-step-label{color:#0f766e;font-weight:700}
.eval-step.done .eval-step-label{color:#059669}
.eval-step-line{width:44px;height:2.5px;background:#ebe8e3;margin:0 4px;transition:background .3s;flex-shrink:0;border-radius:2px}
.eval-step-line.done{background:#059669}
@media(max-width:640px){.eval-step-label{display:none}.eval-step-line{width:24px}}

/* Eval Panes */
.eval-pane{display:none}.eval-pane.active{display:block;animation:paneIn .25s ease}
@keyframes paneIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.eval-pane-head{display:flex;align-items:center;gap:12px;margin-bottom:22px;padding-bottom:14px;border-bottom:2px solid #f0ede9}
.eval-pane-head h3{font-size:17px;font-weight:800;color:#0f172a;margin:0;letter-spacing:-.2px}
.pane-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.eval-nav{display:flex;justify-content:space-between;align-items:center;margin-top:24px;padding-top:18px;border-top:1px solid #f0ede9}
.btn-step-back{background:none;border:1.5px solid #e2e8f0;color:#4a5568;padding:10px 20px;border-radius:11px;font-family:inherit;font-weight:600;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .15s}
.btn-step-back:hover{background:#fafaf8;border-color:#cbd5e1}
.btn-step-next{background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border:none;padding:11px 24px;border-radius:11px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .15s;box-shadow:0 4px 16px rgba(13,148,136,.25)}
.btn-step-next:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(13,148,136,.35)}

/* Medical History Card */
.eval-history-card{background:#fafaf8;border:1px solid #f0ede9;border-radius:14px;padding:16px 18px;margin-top:18px}
.eval-history-card h4{font-size:12px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin:0 0 10px;display:flex;align-items:center;gap:7px}
.eval-history-card .eh-row{font-size:13px;color:#4a5568;padding:3px 0;line-height:1.6}
.eval-history-card .eh-row strong{color:#0f172a}
.eval-history-card .eh-empty{font-size:13px;color:#a0aec0;font-style:italic}

/* AI Panel */
.ai-panel{background:#fff;border:1.5px solid #ebe8e3;border-radius:16px;overflow:hidden;margin-top:20px;transition:border-color .2s}
.ai-panel.collapsed .ai-panel-body{display:none}
.ai-panel-header{display:flex;align-items:center;gap:10px;padding:14px 20px;background:linear-gradient(135deg,rgba(13,148,136,.04),rgba(13,148,136,.08));border-bottom:1px solid #f0ede9}
.ai-title{font-size:13px;font-weight:700;color:#0f172a;flex:1;display:flex;align-items:center;gap:8px}
.ai-title i{color:#0d9488}
.ai-panel-body{padding:20px}
.ai-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border:none;border-radius:11px;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s;box-shadow:0 4px 16px rgba(13,148,136,.25)}
.ai-btn:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(13,148,136,.35)}
.ai-loading{display:none;text-align:center;padding:28px 0}
.ai-loading.active{display:block}
.spinner{width:32px;height:32px;border:3px solid #e2e8f0;border-top-color:#0d9488;border-radius:50%;animation:ndSpin .7s linear infinite;margin:0 auto 12px}
@keyframes ndSpin{to{transform:rotate(360deg)}}
.ai-loading-text{font-size:13px;color:#718096}
.ai-result{display:none}
.ai-result.active{display:block}
.ai-urgency{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:9999px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.ai-urgency.low{background:#ecfdf5;color:#059669}
.ai-urgency.medium{background:#fffbeb;color:#d97706}
.ai-urgency.high{background:#fef2f2;color:#e11d48}
.ai-summary{font-size:13px;color:#718096;margin:12px 0;font-style:italic;line-height:1.6}
.ai-recommendation{font-size:14px;color:#1e293b;line-height:1.7;background:#fafaf8;border:1px solid #f0ede9;border-radius:12px;padding:14px 16px;margin:12px 0}
.ai-warnings{margin:10px 0;padding:0;list-style:none}
.ai-warnings li{font-size:13px;color:#92400e;padding:5px 0 5px 22px;position:relative;line-height:1.5}
.ai-warnings li::before{content:"";position:absolute;left:0;top:12px;width:10px;height:10px;border-radius:50%;background:#fbbf24}
.ai-referral{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:11px;background:#fef2f2;color:#991b1b;font-size:13px;font-weight:700;margin-top:8px}
.ai-confidence{font-size:11px;color:#a0aec0;text-transform:uppercase;letter-spacing:.4px;font-weight:600}
/* Other Toggle */
.other-input{display:none;margin-top:8px}
.other-input.show{display:block}
.other-input input{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:11px;font-size:14px;font-family:inherit;color:#0f172a;background:#fafaf8;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.other-input input:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 4px rgba(13,148,136,.1);background:#fff}

/* Section Titles */
.section-title{font-size:13px;font-weight:700;color:#0f172a;margin:20px 0 10px;display:flex;align-items:center;gap:8px}

/* Messages */
.ok,.err{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:11px;font-size:13px;font-weight:500;margin-top:14px}
.ok{background:#ecfdf5;color:#065f46}
.err{background:#fef2f2;color:#991b1b}

/* Visit History */
.visit-list{display:flex;flex-direction:column;gap:10px}
.visit-item{padding:14px 16px;background:#fafaf8;border:1px solid #f0ede9;border-radius:12px;border-left:3px solid #0d9488}

/* Pill */
.pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600}
.pill.recorded,.pill.active{background:#ecfdf5;color:#059669}
.pill.pending{background:#fffbeb;color:#d97706}
.pill.cancelled{background:#fef2f2;color:#e11d48}

/* Table */
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th{text-align:left;padding:11px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#718096;background:#fafaf8;border-bottom:1px solid #ebe8e3}
.table th:first-child{border-radius:11px 0 0 0}.table th:last-child{border-radius:0 11px 0 0}
.table td{padding:12px 14px;font-size:13px;color:#4a5568;border-bottom:1px solid #f0ede9;vertical-align:middle}
.table tbody tr:hover{background:#fafaf8}
.table tbody tr:last-child td{border-bottom:none}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 20px;border-radius:11px;font-size:14px;font-weight:600;font-family:inherit;border:1.5px solid transparent;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap;line-height:1.2}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}
.btn-primary{background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border-color:#0f766e;box-shadow:0 2px 8px rgba(13,148,136,.18)}
.btn-primary:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 18px rgba(13,148,136,.3)}
.btn-light{background:#f1f5f9;color:#4a5568;border-color:transparent}
.btn-light:hover:not(:disabled){background:#e2e8f0;color:#1e293b}
.btn-outline{background:transparent;color:#0d9488;border-color:#0d9488}
.btn-outline:hover:not(:disabled){background:#f0fdfa}
.btn i{font-size:13px}

/* Forms */
.form-row{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 16px}
.form-group{margin-bottom:0;min-width:0}
.form-group label{display:block;font-size:12px;font-weight:600;color:#718096;margin-bottom:5px}
.form-control{width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:11px;font-size:14px;font-family:inherit;color:#0f172a;background:#fafaf8;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.form-control:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 4px rgba(13,148,136,.1);background:#fff}
.form-control::placeholder{color:#a0aec0}
select.form-control{appearance:none;-webkit-appearance:none;padding-right:38px;cursor:pointer}

/* Profile Modal */
.profile-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);z-index:9000;align-items:center;justify-content:center;padding:24px}
.profile-modal-overlay.active{display:flex}
.profile-modal{background:#fff;border-radius:20px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px 30px;box-shadow:0 24px 64px rgba(0,0,0,.2);animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}

/* Responsive */
@media(max-width:640px){.ws-panel{padding:20px 16px}.tap-card{padding:24px 20px}.info-grid{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}.btn{padding:10px 16px;font-size:13px}}
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
        <div class="hero-title"><?= h($nurseName) ?> &#x1f44b;</div>
        <div class="hero-meta">
            <span class="hero-chip"><i class="fa-solid fa-hospital"></i> Clinic Portal</span>
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

<div class="clinic-grid" style="margin-top:20px;">
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
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;">
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