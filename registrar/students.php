<?php
// ============================================================
//  REGISTRAR/STUDENTS.PHP
//  Student management — inline view/edit, bulk actions, RFID
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';

$db = Database::getInstance();

// Fetch students
$students = $db->fetchAll("SELECT * FROM students ORDER BY id DESC");

// Stats with real MoM trends
$totalStudents = count($students);
$activeStudents = count(array_filter($students, fn($s) => $s['status'] === 'active'));
$atRiskStudents = count(array_filter($students, fn($s) => $s['status'] === 'at-risk' || $s['status'] === 'probation'));
$graduatedStudents = count(array_filter($students, fn($s) => $s['status'] === 'graduated'));

$thisMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$lastMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$trendTotal = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100) : ($thisMonth > 0 ? 100 : 0);

// Courses for filter
$courses = $db->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");

// ─── OFFERED COURSES & MAJORS ────────────────────────────────
// Single source of truth (shared with the Masterlist via shared/functions.php).
$offeredCourses = getOfferedCourses();

// Teachers/advisers for the adviser dropdown (role teacher or staff, active)
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('teacher','staff') AND is_active = 1 ORDER BY full_name");

// RFID cards lookup for indicator
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status FROM rfid_cards");
$rfidMap = [];
foreach ($rfidCards as $rc) {
    if ($rc['student_id']) $rfidMap[$rc['student_id']] = $rc;
}

$page_title = 'Students';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';
include '../includes/header.php';
include '../includes/sidebar.php';
?><style>
:root { --sidebar-width:260px; --sidebar-collapsed-width:72px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; transition:margin-left .3s,width .3s,max-width .3s; }
.sidebar.collapsed~.dashboard-main,body.sidebar-collapsed .dashboard-main { margin-left:var(--sidebar-collapsed-width); width:calc(100% - var(--sidebar-collapsed-width)); max-width:calc(100% - var(--sidebar-collapsed-width)); }

/* Stats */
.dashboard-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.stat-card { background:white; border-radius:14px; padding:18px 20px; border:1px solid #e2e8f0; transition:all .3s; box-shadow:0 1px 3px rgba(15,23,42,0.04); }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 6px 20px rgba(15,23,42,0.06); border-color:#d8dde4; }
.stat-card .stat-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
.stat-card .stat-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0; }
.stat-icon.blue{background:#eef4ff;color:#2563eb} .stat-icon.green{background:#dcfce7;color:#16a34a}
.stat-icon.yellow{background:#fef3c7;color:#b45309} .stat-icon.purple{background:#f3e8ff;color:#7c3aed}
.stat-card .stat-trend{font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;display:inline-flex;align-items:center;gap:4px}
.stat-trend.up{color:#16a34a;background:#dcfce7} .stat-trend.down{color:#dc2626;background:#fee2e2} .stat-trend.neutral{color:#64748b;background:#f1f5f9}
.stat-card .stat-number{font-size:24px;font-weight:700;color:#0f172a;line-height:1.2}
.stat-card .stat-label{color:#64748b;font-size:13px;margin-top:1px}

/* Search + Table container */
.search-table-container{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.04)}
.search-bar{padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;row-gap:10px}
.search-bar .search-wrapper{flex:1 1 320px;min-width:240px;max-width:100%;position:relative;display:flex;align-items:center;height:40px}
.search-bar .search-wrapper i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;z-index:2}
.search-bar .search-wrapper input{width:100%;height:40px;padding:0 38px 0 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.search-bar .search-wrapper input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
.search-bar .search-wrapper input::placeholder{color:#94a3b8}
.search-bar .search-wrapper .search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;width:24px;height:24px;display:none;border-radius:50%;align-items:center;justify-content:center;z-index:2}
.search-bar .search-wrapper .search-clear.visible{display:flex}
.search-bar .search-wrapper .search-clear:hover{background:#f1f5f9;color:#1e293b}
.search-bar .search-actions{display:flex;gap:8px;flex-wrap:nowrap;flex-shrink:0;align-items:center;height:40px}
.search-bar .search-actions .btn{height:40px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;justify-content:center}

/* Bulk bar */
.bulk-bar{display:none;padding:10px 20px;background:#eef4ff;border-bottom:1px solid #bfdbfe;align-items:center;gap:12px;flex-wrap:wrap}
.bulk-bar.show{display:flex}
.bulk-bar .count{font-size:13px;font-weight:600;color:#1d4ed8}
.bulk-bar .bulk-actions{display:flex;gap:8px;margin-left:auto}

/* Table */
.table-responsive{overflow-x:auto}
.table-responsive table{width:100%;border-collapse:collapse}
.table-responsive th{text-align:left;padding:10px 10px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#64748b;background:#fafcfd;border-bottom:2px solid #e8edf4;white-space:nowrap}
.table-responsive td{padding:10px 10px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.table-responsive tbody tr{transition:background .15s ease}
.table-responsive tbody tr:hover{background:#f8fafc}
.table-responsive tbody tr:last-child td{border-bottom:none}
.table-responsive tbody tr.archived{opacity:.5;background:#f8fafc}

/* Checkbox */
.cb-wrap{display:flex;align-items:center;justify-content:center}
.cb-wrap input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}

/* Student info */
.student-info{display:flex;align-items:center;gap:10px}
.student-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;flex-shrink:0}
.student-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)} .student-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.student-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)} .student-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.student-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.student-name{font-weight:600;color:#0f172a;font-size:13px}
.student-email{font-size:11px;color:#94a3b8}

/* RFID chip */
.rfid-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;text-decoration:none}
.rfid-chip.active{background:#dcfce7;color:#16a34a}
.rfid-chip.none{background:#f1f5f9;color:#94a3b8}

/* Status badges */
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;white-space:nowrap;border:none;cursor:pointer;font-family:inherit}
.status-badge.active{background:#dcfce7;color:#16a34a}
.status-badge.probation{background:#fef3c7;color:#b45309}
.status-badge.at-risk{background:#fee2e2;color:#dc2626}
.status-badge.graduated{background:#dbeafe;color:#2563eb}
.status-badge.loa{background:#f3e8ff;color:#7c3aed}
.status-badge.transferred{background:#fce7f3;color:#db2777}
.status-badge.dropped{background:#fef2f2;color:#dc2626}
.status-badge.archived{background:#f1f5f9;color:#64748b}
.status-dot{width:6px;height:6px;border-radius:50%;display:inline-block}
.status-dot.active{background:#16a34a} .status-dot.probation{background:#b45309} .status-dot.at-risk{background:#dc2626}
.status-dot.graduated{background:#2563eb} .status-dot.loa{background:#7c3aed} .status-dot.transferred{background:#db2777}
.status-dot.dropped{background:#dc2626} .status-dot.archived{background:#94a3b8}

.status-card .status-archived{background:#f1f5f9;color:#64748b}

/* Quick status dropdown */
.quick-status-wrap{position:relative;display:inline-block}
.quick-status-menu{display:none;position:absolute;top:100%;left:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:150px;padding:4px;margin-top:4px}
.quick-status-menu.show{display:block}
.quick-status-menu button{display:block;width:100%;padding:8px 12px;border:none;background:none;font-size:12px;font-weight:600;text-align:left;cursor:pointer;border-radius:6px;font-family:inherit}
.quick-status-menu button:hover{background:#f1f5f9}
.quick-status-menu button.active{background:#eef4ff;color:#2563eb}

/* Action buttons */
.action-group{display:flex;gap:3px;justify-content:center}
.action-btn{width:30px;height:30px;border:none;border-radius:8px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;font-size:12px;background:transparent;color:#94a3b8}
.action-btn:hover{background:#f1f5f9;color:#1e293b;transform:scale(1.05)}
.action-btn.view{color:#2563eb} .action-btn.view:hover{background:#eef4ff}
.action-btn.edit{color:#b45309} .action-btn.edit:hover{background:#fef3c7}
.action-btn.delete{color:#dc2626} .action-btn.delete:hover{background:#fee2e2}
.action-btn.restore{color:#16a34a} .action-btn.restore:hover{background:#dcfce7}

/* Table footer */
.table-footer{padding:10px 20px;background:#fafcfd;border-top:1px solid #e8edf4;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.table-footer .info-text{font-size:13px;color:#64748b}
.table-footer .info-text strong{color:#0f172a}

/* Empty state */
.vtab.active{border-bottom-color:#2563eb !important;color:#2563eb !important;}
.vtab-content.active{display:block}
.empty-state{text-align:center;padding:30px 20px;color:#94a3b8}
.empty-state i{font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px}
.empty-state p{font-size:15px;font-weight:500;color:#94a3b8}
.empty-state span{font-size:13px;color:#cbd5e1}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.15);animation:modalSlide .3s ease;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}
.modal-content::-webkit-scrollbar{width:5px}.modal-content::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
@keyframes modalSlide{from{opacity:0;transform:translateY(20px) scale(0.96)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.modal-header h2{font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px}
.modal-header h2 i{color:#2563eb}
.modal-close{width:34px;height:34px;border:none;background:#f1f5f9;border-radius:50%;cursor:pointer;font-size:15px;color:#94a3b8;transition:all .2s;display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:#e2e8f0;color:#1e293b}
.modal-body{margin-bottom:16px}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e8edf4}
.modal-footer .btn{min-width:100px;justify-content:center}

/* View modal profile */
.view-profile{display:flex;flex-direction:column;align-items:center;padding:12px 0}
.view-profile .big-avatar{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:28px;margin-bottom:10px}
.view-profile .big-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)} .view-profile .big-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.view-profile .big-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)} .view-profile .big-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.view-profile .big-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.view-profile .vp-name{font-size:20px;font-weight:700;color:#0f172a}
.view-profile .vp-id{font-size:13px;color:#64748b}

.view-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9}
.view-item{text-align:left}
.view-item .lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px}
.view-item .val{font-size:14px;font-weight:600;color:#1e293b;margin-top:2px}

/* Edit modal forms */
.form-row{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.form-group{flex:1;min-width:160px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
select.form-control{cursor:pointer;appearance:auto;-webkit-appearance:auto;}
/* ─── Course selection: scrollable dropdown ─────────────────── */
.course-select-wrap{ position:relative; }
/* Hide the native select's default arrow; it's replaced by a styled
   scrollable list, but the select itself stays functional (keeps value,
   required, and form submission working). */
.course-select-wrap select.form-control{
  appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 6l4 4 4-4'/%3E%3C/svg%3E");
  background-position:right 12px center;
  background-repeat:no-repeat;
  background-size:14px;
  padding-right:32px;
  cursor:pointer;
}
/* Scrollable list: max 5 options (~5*34px) tall, then scrolls */
.course-select-list{
  position:absolute;top:100%;left:0;right:0;z-index:100;
  margin-top:2px;background:#fff;border:1.5px solid #e2e8f0;
  border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.1);
  max-height:170px;overflow-y:auto;
}
.course-select-list .cs-option{
  padding:7px 12px;font-size:13px;color:#1e293b;cursor:pointer;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  border-bottom:1px solid #f1f5f9;
}
.course-select-list .cs-option:last-child{border-bottom:none;}
.course-select-list .cs-option:hover{ background:#eef4ff; color:#2563eb; }
.course-select-list .cs-option.active{ background:#eef4ff; color:#2563eb; font-weight:600; }
.modal-overlay select.form-control,
.modal-overlay select { cursor:pointer !important; appearance:auto !important; -webkit-appearance:auto !important; }
/* bulk bar select inside page (not modal) — force native */
.bulk-bar select.form-control { appearance:auto !important; -webkit-appearance:auto !important; cursor:pointer !important; }

/* Delete icon */
.delete-icon{width:60px;height:60px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:#dc2626}

/* Export dropdown */
.export-wrap{position:relative}
.export-menu{display:none;position:absolute;top:100%;right:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:160px;padding:4px;margin-top:4px}
.export-menu.show{display:block}
.export-menu a{display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px}
.export-menu a:hover{background:#f1f5f9}

/* Responsive */
@media(max-width:992px){.dashboard-main{padding:20px}.dashboard-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.search-bar .search-wrapper{flex:1 1 240px;min-width:200px}}
@media(max-width:768px){
.dashboard-main{margin-left:0;padding:16px;width:100%;max-width:100%}
.dashboard-stats{grid-template-columns:1fr 1fr;gap:12px}
.stat-card{padding:14px 16px}.stat-card .stat-number{font-size:20px}.stat-card .stat-icon{width:34px;height:34px;font-size:14px}
.search-bar{flex-direction:column;flex-wrap:nowrap;align-items:stretch;gap:10px}
.search-bar .search-wrapper{flex:1 1 auto;min-width:0;max-width:100%;width:100%}
.search-bar .search-actions{width:100%;height:auto;justify-content:flex-end;flex-wrap:wrap}
.search-bar .search-actions .btn{height:38px;justify-content:center}
.table-responsive table{min-width:600px}
.table-responsive th,.table-responsive td{padding:8px 8px;font-size:12px}
.student-avatar{width:28px;height:28px;font-size:10px}
.student-email{display:none}
}
@media(max-width:480px){
.dashboard-main{padding:12px}
.dashboard-stats{grid-template-columns:1fr}
.stat-card .stat-number{font-size:18px}
.search-bar{padding:10px 14px;gap:8px}
.search-bar .search-wrapper{height:38px}
.search-bar .search-wrapper input{height:38px;font-size:13px}
.search-bar .search-actions{gap:6px}
.search-bar .search-actions .btn{padding:0 14px;font-size:12px;height:36px}
.table-responsive th,.table-responsive td{padding:6px 6px;font-size:11px}
}
.quality-legend{position:relative;display:inline-flex;margin-left:3px;}
.quality-legend .quality-legend-box{display:none;position:absolute;top:20px;left:50%;transform:translateX(-50%);z-index:50;background:#0f172a;color:#f8fafc;font-size:12px;line-height:1.6;padding:10px 12px;border-radius:8px;white-space:nowrap;box-shadow:0 8px 24px rgba(15,23,42,.2);font-weight:500;text-align:left;}
.quality-legend:hover .quality-legend-box{display:block;}
.quality-legend-box::before{content:'';position:absolute;top:-5px;left:50%;transform:translateX(-50%);border:6px solid transparent;border-bottom-color:#0f172a;}
</style>
<main class="dashboard-main">
<header class="header">
<div class="title"><h1>Students</h1><p>Manage all student records</p></div>
<div class="header-actions">
<div class="export-wrap">
<button class="btn btn-secondary" id="exportBtn"><i class="fas fa-download"></i> Export</button>
<div class="export-menu" id="exportMenu">
<a href="#" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</a>
<a href="#" onclick="exportFiltered()"><i class="fas fa-filter-circle-dollar"></i> Export Filtered</a>
</div>
</div>
<button class="btn btn-secondary" onclick="openQualityPanel()"><i class="fas fa-shield-halved"></i> Data Quality</button>
<button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Student</button>
</div>
</header>

<!-- Stats -->
<div class="dashboard-stats">
<div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-users"></i></div><span class="stat-trend <?= $trendTotal>=0?'up':'down' ?>"><i class="fas fa-arrow-<?= $trendTotal>=0?'up':'down' ?>"></i> <?= abs($trendTotal) ?>%</span></div><div class="stat-number"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $totalStudents ? round($activeStudents/$totalStudents*100) : 0 ?>%</span></div><div class="stat-number"><?= $activeStudents ?></div><div class="stat-label">Active</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-triangle-exclamation"></i></div><span class="stat-trend neutral"><i class="fas fa-minus"></i> <?= $totalStudents ? round($atRiskStudents/$totalStudents*100) : 0 ?>%</span></div><div class="stat-number"><?= $atRiskStudents ?></div><div class="stat-label">At Risk</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-graduation-cap"></i></div><span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $totalStudents ? round($graduatedStudents/$totalStudents*100) : 0 ?>%</span></div><div class="stat-number"><?= $graduatedStudents ?></div><div class="stat-label">Graduated</div></div>
</div>

<!-- Search + Table -->
<div class="search-table-container">
<div class="search-bar">
<div class="search-wrapper">
<i class="fas fa-search"></i>
<input type="text" id="studentSearch" placeholder="Search by name, ID, course..." />
<button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
</div>
<div class="search-actions">
<button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> Print</button>
<button class="btn btn-secondary" id="filterToggle"><i class="fas fa-sliders"></i> Filter</button>
<button class="btn btn-secondary" id="resetBtn"><i class="fas fa-rotate-right"></i> Reset</button>
</div>
</div>

<!-- Bulk bar -->
<div class="bulk-bar" id="bulkBar">
<span class="count" id="bulkCount">0 selected</span>
<select class="form-control" style="width:auto;display:inline-block;padding:6px 10px;font-size:12px;" id="bulkActionSelect"><option value="">Bulk action...</option><option value="active">Set Active</option><option value="at-risk">Set At Risk</option><option value="probation">Set Probation</option><option value="graduated">Set Graduated</option><option value="loa">Set LOA</option><option value="transferred">Set Transferred</option><option value="dropped">Set Dropped</option></select>
<button class="btn btn-secondary" style="height:32px;padding:0 12px;font-size:12px;" onclick="applyBulkAction()"><i class="fas fa-check"></i> Apply</button>
</div>

<div class="table-responsive">
<table>
<thead>
<tr><th style="width:30px;"><div class="cb-wrap"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></div></th><th>ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Gender</th><th>RFID</th><th>Status</th><th style="text-align:center;">Quality <span class="quality-legend" title=""><i class="fas fa-circle-info" style="cursor:help;"></i><span class="quality-legend-box">Quality score = % of required student fields filled.
<span style="color:#22c55e;">●</span> 85–100% &nbsp; Complete
<span style="color:#f59e0b;">●</span> 60–84% &nbsp; Some fields missing
<span style="color:#ef4444;">●</span> &lt;60% &nbsp; Many fields missing
<span style="color:#f59e0b;">⚠</span> Anomaly worth checking (hover the row)</span></span></th><th style="text-align:center;">Actions</th></tr>
</thead>
<tbody id="studentTableBody">
<?php if (empty($students)): ?>
<tr><td colspan="11" class="empty-state"><i class="fas fa-user-graduate"></i><p>No students found</p><span>Add your first student to get started</span></td></tr>
<?php else:
$avatarColors = ['blue','green','purple','orange','pink'];
foreach ($students as $i => $s):
$initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
$ac = $avatarColors[$i % count($avatarColors)];
$hasRfid = isset($rfidMap[$s['id']]);
$rfidStatus = $hasRfid && $rfidMap[$s['id']]['status'] === 'active' ? 'active' : ($hasRfid ? 'inactive' : 'none');
// Data quality score + anomaly flags (deterministic)
$qScore = studentQualityScore($s);
$qAnoms = studentAnomalies($s);
$qDotClass = $qScore >= 85 ? 'good' : ($qScore >= 60 ? 'warn' : 'bad');
?>
<tr data-student='<?= htmlspecialchars(json_encode($s),ENT_QUOTES,'UTF-8') ?>' class="<?= $s['status']==='archived'?'archived':'' ?>">
<td><div class="cb-wrap"><input type="checkbox" class="student-cb" value="<?= (int)$s['id'] ?>" onchange="updateBulkBar()"></div></td>
<td class="student-id" style="font-weight:600;font-size:12px;"><?= htmlspecialchars($s['student_number']) ?></td>
<td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($s['first_name']." ".$s['last_name']) ?></div><div class="student-email"><?= htmlspecialchars($s['email'] ?? '') ?></div></div></div></td>
<td><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['section'] ?? '—') ?></td>
<td><?= htmlspecialchars(($s['gender'] ?? '') ?: '—') ?></td>
<td><a href="../registrar/rfid-cards.php?search=<?= urlencode($s['student_number']) ?>" class="rfid-chip <?= $rfidStatus ?>"><i class="fas fa-<?= $rfidStatus==='active'?'check-circle':'credit-card' ?>"></i> <?= $rfidStatus==='active'?($rfidMap[$s['id']]['card_uid']):($rfidStatus==='none'?'—':$rfidMap[$s['id']]['status']) ?></a></td>
<td><div class="quick-status-wrap"><button class="status-badge <?= $s['status']??'active' ?>" onclick="toggleQuickMenu(<?= (int)$s['id'] ?>)"><span class="status-dot <?= $s['status']??'active' ?>"></span><?= ucfirst($s['status']??'Active') ?></button><div class="quick-status-menu" id="qsm_<?= (int)$s['id'] ?>"><?php $statuses=['active','probation','at-risk','graduated','loa','transferred','dropped']; if($s['status']==='archived')$statuses[]='archived'; foreach($statuses as $st): ?><button onclick="quickStatus(<?= (int)$s['id'] ?>,'<?= $st ?>')" class="<?= ($s['status']??'active')===$st?'active':'' ?>"><?= ucfirst($st) ?></button><?php endforeach; ?></div></div></td>
<td style="text-align:center;">
<?php
$qAnomLabels = array_map(function ($k) {
    return [
        'age_mismatch' => 'Age doesn\'t match year level',
        'future_birthdate' => 'Birth date is in the future',
        'no_address' => 'Missing address',
        'no_contact' => 'Missing contact number',
        'no_gender' => 'Missing gender',
        'course_nonstandard' => 'Course name not standardized',
    ][$k] ?? $k;
}, $qAnoms);
$qTitle = 'Quality ' . $qScore . '%' . (!empty($qAnoms) ? ' — ' . implode('; ', $qAnomLabels) : ' — all key fields filled');
?>
<span class="q-dot q-<?= $qDotClass ?>" title="<?= htmlspecialchars($qTitle) ?>" style="display:inline-block;width:10px;height:10px;border-radius:50%;<?= $qScore>=85?'background:#22c55e':($qScore>=60?'background:#f59e0b':'background:#ef4444') ?>;"></span>
<?php if (!empty($qAnoms)): ?><i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-left:4px;font-size:11px;" title="<?= htmlspecialchars(implode('; ', $qAnomLabels)) ?>"></i><?php endif; ?>
</td>
<td><div class="action-group"><button class="action-btn view" onclick="viewStudent(<?= (int)$s['id'] ?>)" title="View"><i class="fas fa-eye"></i></button><button class="action-btn edit" onclick="editStudent(<?= (int)$s['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button><?php if ($s['status']==='archived'): ?><button class="action-btn restore" onclick="restoreStudent(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['first_name']." ".$s['last_name'],ENT_QUOTES) ?>')" title="Restore"><i class="fas fa-undo"></i></button><?php else: ?><button class="action-btn delete" onclick="confirmDelete(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['first_name']." ".$s['last_name'],ENT_QUOTES) ?>')" title="Delete"><i class="fas fa-trash-alt"></i></button><?php endif; ?></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="table-footer">
<div class="info-text">Showing <strong id="showingCount"><?= count($students) ?></strong> of <strong id="totalCount"><?= count($students) ?></strong> students</div>
</div>
</div>
</main>

<!-- Filter Modal -->
<div class="modal-overlay" id="filterModal">
<div class="modal-content">
<div class="modal-header"><h2><i class="fas fa-sliders"></i> Filter Students</h2><button class="modal-close" onclick="closeFilterModal()"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<div class="form-group"><label>Status</label><select id="filterStatus" class="form-control"><option value="">All Status</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div>
<div class="form-group"><label>Year Level</label><select id="filterYear" class="form-control"><option value="">All Year</option><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option></select></div>
<div class="form-group"><label>Course</label><select id="filterCourse" class="form-control"><option value="">All Courses</option><?php foreach($courses as $c): ?><option value="<?= htmlspecialchars($c['course']) ?>"><?= htmlspecialchars($c['course']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Section</label><input type="text" id="filterSection" class="form-control" placeholder="Enter section..." /></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="closeFilterModal()">Cancel</button><button class="btn btn-secondary" onclick="clearFilters()">Clear All</button><button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-check"></i> Apply</button></div>
</div>
</div>

<!-- View Modal (tabbed) -->
<div class="modal-overlay" id="viewModal"><div class="modal-content" style="max-width:600px;"><div class="modal-header"><h2><i class="fas fa-id-card"></i> Student Profile</h2><button class="modal-close" onclick="closeViewModal()"><i class="fas fa-times"></i></button></div>
<div style="display:flex;gap:4px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:0;">
<button class="vtab active" onclick="switchVTab(this,'profile')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#2563eb;cursor:pointer;border-bottom:2px solid #2563eb;font-family:inherit;"><i class="fas fa-user"></i> Profile</button>
<button class="vtab" onclick="switchVTab(this,'documents')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-file"></i> Documents</button>
<button class="vtab" onclick="switchVTab(this,'academic')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-school"></i> Academic</button>
<button class="vtab" onclick="switchVTab(this,'health')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-heartbeat"></i> Health</button>
</div>
<div class="modal-body">
<!-- Tab: Profile -->
<div class="vtab-content active" id="tabProfile">
<div class="view-profile">
<div class="big-avatar blue" id="vAvatar" style="position:relative;"><span id="vAvatarText">JD</span></div>
<input type="file" id="photoInput" accept="image/*" style="display:none">
<button class="btn btn-secondary" style="margin:-4px auto 10px;padding:4px 12px;font-size:11px;" onclick="document.getElementById('photoInput').click()"><i class="fas fa-camera"></i> Change Photo</button>
<div class="vp-name" id="vName">—</div>
<div class="vp-id" id="vStudentId">—</div>
<div id="vLastScan" style="font-size:12px;color:#64748b;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;"></div>
<div id="vAiSummary" style="display:none;margin-top:12px;background:linear-gradient(135deg,#eef4ff,#f5f3ff);border:1px solid #dbeafe;border-radius:10px;padding:12px 14px;font-size:13px;color:#1e40af;"></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9">
<div class="view-item"><div class="lbl">Status</div><div class="val" id="vStatus">—</div></div>
<div class="view-item"><div class="lbl">LRN</div><div class="val" id="vLrn">—</div></div>
<div class="view-item"><div class="lbl">Gender</div><div class="val" id="vGender">—</div></div>
<div class="view-item"><div class="lbl">Civil Status</div><div class="val" id="vCivilStatus">—</div></div>
<div class="view-item"><div class="lbl">Birth Date</div><div class="val" id="vBirthDate">—</div></div>
<div class="view-item"><div class="lbl">Place of Birth</div><div class="val" id="vBirthPlace">—</div></div>
<div class="view-item"><div class="lbl">Nationality</div><div class="val" id="vNationality">—</div></div>
<div class="view-item"><div class="lbl">Religion</div><div class="val" id="vReligion">—</div></div>
<div class="view-item"><div class="lbl">Course</div><div class="val" id="vCourse">—</div></div>
<div class="view-item"><div class="lbl">Year / Section</div><div class="val" id="vYearSection">—</div></div>
<div class="view-item"><div class="lbl">School Year / Sem</div><div class="val" id="vSchoolYearSem">—</div></div>
<div class="view-item"><div class="lbl">Adviser</div><div class="val" id="vAdviser">—</div></div>
<div class="view-item"><div class="lbl">Email</div><div class="val" id="vEmail">—</div></div>
<div class="view-item"><div class="lbl">Contact</div><div class="val" id="vContact">—</div></div>
<div class="view-item"><div class="lbl">Father</div><div class="val" id="vFather">—</div></div>
<div class="view-item"><div class="lbl">Mother</div><div class="val" id="vMother">—</div></div>
<div class="view-item" style="grid-column:span 2;"><div class="lbl">Address</div><div class="val" id="vAddress">—</div></div>
</div>
<div id="vGuardianSection" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:6px;">Guardian</div><div id="vGuardianInfo" style="font-size:13px;color:#475569;"></div></div>
<div id="vRfidSection" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;text-align:center;display:flex;gap:8px;justify-content:center;"><a id="vRfidLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-credit-card"></i> RFID Card</a> <a id="vScanLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-clock-rotate-left"></i> Scan Logs</a></div>
</div>
<!-- Tab: Documents -->
<div class="vtab-content" id="tabDocuments" style="display:none;"><div id="vDocuments" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Academic -->
<div class="vtab-content" id="tabAcademic" style="display:none;"><div id="vAcademic" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Health -->
<div class="vtab-content" id="tabHealth" style="display:none;"><div id="vHealth" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
</div>
<div class="modal-footer"><button class="btn btn-primary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Data Quality Panel Modal -->
<div class="modal-overlay" id="qualityModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-shield-halved"></i> Data Quality</h2><button class="modal-close" onclick="closeQualityPanel()"><i class="fas fa-times"></i></button></div><div class="modal-body">
<div id="qualityLoading" style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Analyzing records...</div>
<div id="qualityContent" style="display:none;"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeQualityPanel()">Close</button><button id="qualityRefreshBtn" type="button" class="btn btn-secondary" onclick="openQualityPanel(true)"><i class="fas fa-rotate"></i> Refresh</button></div></div></div>

<!-- Add Modal (inline, with guardian) -->
<div class="modal-overlay" id="addModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-plus-circle"></i> Enroll New Student</h2><div style="display:flex;gap:8px;align-items:center;"><button class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="openPasteModal()"><i class="fas fa-magic"></i> Paste to Fill</button><button class="modal-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button></div></div><form id="addForm"><div class="modal-body">
<div class="form-row"><div class="form-group" style="flex:0 0 160px;"><label>Student ID</label><input type="text" id="addStudentNumber" class="form-control" placeholder="Auto" style="background:#f8fafc;font-size:12px;" readonly></div><div class="form-group"><label>Academic Status</label><select id="addStatus" class="form-control"><option value="active">Active</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="addMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="addLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="addSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN (optional)</label><input type="text" id="addLrn" class="form-control" placeholder="12-digit Learner Reference No." maxlength="12"></div><div class="form-group"><label>Gender</label><select id="addGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="addCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="addBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="addBirthPlace" class="form-control" placeholder="City, Province"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="addNationality" class="form-control" value="Filipino"></div><div class="form-group"><label>Religion</label><input type="text" id="addReligion" class="form-control"></div><div class="form-group"><label>Father's Name</label><input type="text" id="addFather" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Mother's Name</label><input type="text" id="addMother" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email</label><input type="email" id="addEmail" class="form-control" placeholder="student@school.edu.ph"></div><div class="form-group"><label>Contact No.</label><input type="text" id="addContact" class="form-control" placeholder="0917xxx"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="addAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course <span style="color:#dc2626;">*</span></label><div class="course-select-wrap"><select id="addCourse" class="form-control" required><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level</label><select id="addYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="addMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="addMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="addSchoolYear" class="form-control" placeholder="2026-2027" value="2026-2027"></div><div class="form-group"><label>Semester</label><select id="addSemester" class="form-control"><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div><div class="form-group"><label>Section <button type="button" style="background:none;border:none;color:#2563eb;cursor:pointer;font-size:11px;padding:0;" onclick="suggestSection()"><i class="fas fa-magic"></i> Suggest</button></label><input type="text" id="addSection" class="form-control" placeholder="e.g. 11001"></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian / Parent</div>
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="addGuardianName" class="form-control" required></div><div class="form-group"><label>Relationship</label><select id="addGuardianRel" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No.</label><input type="text" id="addGuardianContact" class="form-control"></div><div class="form-group"><label>Email (optional)</label><input type="email" id="addGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAddModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enroll</button></div></form></div></div>

<!-- AI Document Reader / Paste-to-Fill Modal -->
<div class="modal-overlay" id="pasteModal"><div class="modal-content" style="max-width:680px;"><div class="modal-header"><h2><i class="fas fa-file-import"></i> AI Document Reader</h2><button class="modal-close" onclick="closePasteModal()"><i class="fas fa-times"></i></button></div><div class="modal-body">
<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Download the form template, have the student fill it out, then <strong>drop the file here</strong> (PDF, Word, or text). AI extracts the details for you to review and apply.</p>
<div style="display:flex;gap:10px;margin-bottom:12px;"><a href="../api/student-template.php" class="btn btn-secondary" style="cursor:pointer;"><i class="fas fa-file-word"></i> Download Word Template</a></div>
<div id="pasteDropzone" style="border:2px dashed #cbd5e1;border-radius:12px;padding:26px 16px;text-align:center;color:#64748b;background:#f8fafc;cursor:pointer;transition:all .15s;margin-bottom:8px;">
<i class="fas fa-cloud-arrow-up" style="font-size:26px;display:block;margin-bottom:8px;color:#94a3b8;"></i>
<div style="font-size:13px;"><strong>Drag &amp; drop a file here</strong> or <span style="color:#2563eb;text-decoration:underline;">click to browse</span></div>
<div style="font-size:12px;color:#94a3b8;margin-top:4px;">PDF, DOCX, TXT, or image (PNG/JPG) · up to 15 MB</div>
<input type="file" id="pasteFile" accept=".pdf,.docx,.txt,.png,.jpg,.jpeg,.webp" style="display:none;">
</div>
<div id="pasteFileName" style="font-size:12px;color:#16a34a;margin-bottom:8px;"></div>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;color:#94a3b8;font-size:12px;"><span style="flex:1;border-top:1px solid #e2e8f0;"></span> or paste text <span style="flex:1;border-top:1px solid #e2e8f0;"></span></div>
<textarea id="pasteText" class="form-control" rows="5" placeholder="Paste student info text here..." style="margin-bottom:12px;"></textarea>
<div id="pastePreview" style="display:none;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:14px;margin-bottom:12px;"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closePasteModal()">Cancel</button><button id="pasteExtractBtn" type="button" class="btn btn-primary" onclick="extractPaste()"><i class="fas fa-magic"></i> Extract</button><button id="pasteApplyBtn" type="button" class="btn btn-primary" style="display:none;" onclick="applyPaste()"><i class="fas fa-check"></i> Apply to Form</button></div></div></div>

<!-- Edit Modal (same structure) -->
<div class="modal-overlay" id="editModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-pen"></i> Edit Student</h2><button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button></div><form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
<div class="form-row"><div class="form-group" style="flex:0 0 160px;"><label>Student ID</label><input type="text" id="editStudentNumber" class="form-control" readonly style="background:#f8fafc;font-size:12px;"></div><div class="form-group"><label>Academic Status</label><select id="editStatus" class="form-control"><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="editMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="editLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="editSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN</label><input type="text" id="editLrn" class="form-control" placeholder="12-digit LRN" maxlength="12"></div><div class="form-group"><label>Gender</label><select id="editGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="editCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="editBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="editBirthPlace" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="editNationality" class="form-control"></div><div class="form-group"><label>Religion</label><input type="text" id="editReligion" class="form-control"></div><div class="form-group"><label>Father's Name</label><input type="text" id="editFather" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Mother's Name</label><input type="text" id="editMother" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email</label><input type="email" id="editEmail" class="form-control"></div><div class="form-group"><label>Contact No.</label><input type="text" id="editContact" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="editAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course</label><div class="course-select-wrap"><select id="editCourse" class="form-control"><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level</label><select id="editYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="editMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="editMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="editSchoolYear" class="form-control" placeholder="2026-2027"></div><div class="form-group"><label>Semester</label><select id="editSemester" class="form-control"><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian</div>
<div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" id="editGuardianName" class="form-control"></div><div class="form-group"><label>Relationship</label><select id="editGuardianRel" class="form-control"><option value="">Select</option><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No.</label><input type="text" id="editGuardianContact" class="form-control"></div><div class="form-group"><label>Email</label><input type="email" id="editGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeEditModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal"><div class="modal-content" style="max-width:420px;text-align:center;"><div class="delete-icon"><i class="fas fa-trash-alt"></i></div><h3 style="font-size:19px;font-weight:700;color:#0f172a;margin-bottom:4px;">Deactivate Student</h3><p id="deleteMessage" style="color:#64748b;font-size:14px;margin-bottom:18px;">This can be undone by restoring later.</p><div class="modal-footer" style="justify-content:center;"><button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button><button class="btn btn-danger" id="confirmDeleteBtn" style="background:#dc2626;color:white;border:none;padding:9px 18px;border-radius:10px;font-weight:600;cursor:pointer;"><i class="fas fa-trash-alt"></i> Delete</button></div></div></div>

<script>
// ─── DATA ────────────────────────────────────────────────────
const searchInput = document.getElementById('studentSearch');
const searchClear = document.getElementById('searchClear');
const resetBtn = document.getElementById('resetBtn');
const tableBody = document.getElementById('studentTableBody');
const showingCount = document.getElementById('showingCount');
const totalCount = document.getElementById('totalCount');
let allStudents = [];
let deleteTarget = null;

// id → name map of advisers, injected from PHP
const ADVISER_MAP = <?= json_encode(array_column($advisers, 'full_name', 'id')) ?>;

document.querySelectorAll('#studentTableBody tr').forEach(row => {
    try {
        const data = JSON.parse(row.dataset.student);
        if (data) allStudents.push({ ...data, element: row });
    } catch(e) {}
});

// ─── SEARCH & FILTER ─────────────────────────────────────────
function updateTable(students) {
    allStudents.forEach(s => { if (s.element) s.element.style.display = 'none'; });
    let visible = 0;
    students.forEach(s => { if (s.element) { s.element.style.display = ''; visible++; } });
    showingCount.textContent = visible;
    let emptyRow = tableBody.querySelector('.empty-state-row');
    if (visible === 0 && allStudents.length > 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr'); emptyRow.className = 'empty-state-row';
            emptyRow.innerHTML = '<td colspan="10" class="empty-state"><i class="fas fa-search"></i><p>No students found</p><span>Try adjusting search or filters</span></td>';
            tableBody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
    } else if (emptyRow) emptyRow.style.display = 'none';
    updateBulkBar();
}

function performSearch() {
    const query = searchInput.value.trim().toLowerCase();
    const status = document.getElementById('filterStatus')?.value || '';
    const year = document.getElementById('filterYear')?.value || '';
    const course = document.getElementById('filterCourse')?.value || '';
    const section = document.getElementById('filterSection')?.value?.toLowerCase() || '';
    let filtered = allStudents;
    if (query) filtered = filtered.filter(s => (s.first_name||'').toLowerCase().includes(query)||(s.last_name||'').toLowerCase().includes(query)||(s.student_number||'').toLowerCase().includes(query)||(s.course||'').toLowerCase().includes(query));
    if (status) filtered = filtered.filter(s => s.status === status);
    if (year) filtered = filtered.filter(s => String(s.year_level) === year);
    if (course) filtered = filtered.filter(s => s.course === course);
    if (section) filtered = filtered.filter(s => (s.section||'').toLowerCase().includes(section));
    updateTable(filtered);
    searchClear.classList.toggle('visible', query.length > 0);
}
searchInput.addEventListener('input', performSearch);
searchClear.addEventListener('click', () => { searchInput.value = ''; performSearch(); });
resetBtn.addEventListener('click', () => {
    searchInput.value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterYear').value = '';
    document.getElementById('filterCourse').value = '';
    document.getElementById('filterSection').value = '';
    performSearch();
    closeFilterModal();
});

// ─── FILTER MODAL ────────────────────────────────────────────
document.getElementById('filterToggle').addEventListener('click', () => { document.getElementById('filterModal').classList.add('active'); document.body.style.overflow = 'hidden'; });
function closeFilterModal() { document.getElementById('filterModal').classList.remove('active'); document.body.style.overflow = ''; }
function applyFilters() { performSearch(); closeFilterModal(); }
function clearFilters() { document.getElementById('filterStatus').value = ''; document.getElementById('filterYear').value = ''; document.getElementById('filterCourse').value = ''; document.getElementById('filterSection').value = ''; performSearch(); }
document.getElementById('filterModal').addEventListener('click', function(e) { if (e.target === this) closeFilterModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeFilterModal(); closeDeleteModal(); closeViewModal(); closeEditModal(); }});

// ─── CHECKBOX BULK ───────────────────────────────────────────
function toggleSelectAll() {
    const checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.student-cb').forEach(cb => cb.checked = checked);
    updateBulkBar();
}
function updateBulkBar() {
    const checked = document.querySelectorAll('.student-cb:checked').length;
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked + ' selected';
    bar.classList.toggle('show', checked > 0);
}
function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    if (!action) { alert('Select an action first.'); return; }
    const ids = Array.from(document.querySelectorAll('.student-cb:checked')).map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm('Change status of ' + ids.length + ' student(s) to "' + action + '"?')) return;
    fetch('../api/students.php?action=bulk-status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, status: action })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); }).catch(() => alert('Error.'));
}

// ─── VIEW MODAL (full profile) ──────────────────────────────
const viewModal = document.getElementById('viewModal');
var currentViewId = null;

function viewStudent(id) {
    currentViewId = id;
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        const name = s.first_name + ' ' + s.last_name;
        const initials = (s.first_name||'')[0] + (s.last_name||'')[0];
        const colors = ['blue','green','purple','orange','pink'];
        const c = colors[Math.abs((s.first_name||'a').charCodeAt(0)) % colors.length];
        const avatarEl = document.getElementById('vAvatar');
        const avatarText = document.getElementById('vAvatarText');
        avatarEl.className = 'big-avatar ' + c;
        if (s.photo) { avatarEl.style.background = 'transparent'; avatarEl.style.backgroundImage = 'url('+s.photo+')'; avatarEl.style.backgroundSize = 'cover'; avatarText.style.display = 'none'; }
        else { avatarEl.style.backgroundImage = ''; avatarText.style.display = ''; avatarText.textContent = initials.toUpperCase(); }
        document.getElementById('vName').textContent = name;
        document.getElementById('vStudentId').textContent = s.student_number;
        document.getElementById('vStatus').innerHTML = '<span class="status-badge '+(s.status||'active')+'"><span class="status-dot '+(s.status||'active')+'"></span>'+ucfirst(s.status||'Active')+'</span>';
        document.getElementById('vLrn').textContent = s.lrn || '—';
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vCivilStatus').textContent = s.civil_status||'—';
        document.getElementById('vBirthDate').textContent = s.birth_date?new Date(s.birth_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}):'—';
        document.getElementById('vBirthPlace').textContent = s.place_of_birth||'—';
        document.getElementById('vNationality').textContent = s.nationality||'—';
        document.getElementById('vReligion').textContent = s.religion||'—';
        document.getElementById('vCourse').textContent = s.course||'—';
        document.getElementById('vYearSection').textContent = (s.year_level?s.year_level+' Year':'')+(s.section?' — '+s.section:'');
        document.getElementById('vSchoolYearSem').textContent = (s.school_year?s.school_year:'—')+(s.semester?' — '+s.semester:'');
        document.getElementById('vAdviser').textContent = (s.adviser_id && ADVISER_MAP[s.adviser_id]) ? ADVISER_MAP[s.adviser_id] : '—';
        document.getElementById('vEmail').textContent = s.email||'—';
        document.getElementById('vContact').textContent = s.contact_number||'—';
        document.getElementById('vFather').textContent = s.father_name || '—';
        document.getElementById('vMother').textContent = s.mother_name || '—';
        document.getElementById('vAddress').textContent = s.address||'—';
        // Guardian
        fetch('../api/students.php?action=guardian&student_id='+s.id).then(r=>r.json()).then(gd=>{
            const gs=document.getElementById('vGuardianSection'),gi=document.getElementById('vGuardianInfo');
            if(gd.success&&gd.data){gs.style.display='block';gi.innerHTML=(gd.data.full_name||'')+(gd.data.relationship?' <span style=\"color:#94a3b8\">('+gd.data.relationship+')</span>':'')+'<br>'+(gd.data.contact_number?'<i class=\"fas fa-phone\" style=\"color:#94a3b8\"></i> '+gd.data.contact_number:'')+(gd.data.email?' <i class=\"fas fa-envelope\" style=\"color:#94a3b8\"></i> '+gd.data.email:'');}
        }).catch(()=>{});
        // Last scan
        fetch('../api/students.php?action=lastscan&student_id='+s.id).then(r=>r.json()).then(sd=>{
            const el=document.getElementById('vLastScan');
            if(sd.success&&sd.data){const ls=sd.data;const ei=ls.event_type==='entry'?'fa-right-to-bracket':ls.event_type==='exit'?'fa-right-from-bracket':'fa-circle';el.innerHTML='<span style=\"display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;\"><i class=\"fas '+ei+'\" style=\"color:'+(ls.event_type==='entry'?'#2563eb':'#b45309')+';\"></i> <strong>'+ucfirst(ls.event_type||'scan')+'</strong> <span style=\"color:#94a3b8\">·</span> '+(ls.scanned_at?new Date(ls.scanned_at).toLocaleString():'')+' <span style=\"color:#94a3b8\">·</span> '+(ls.location||'')+' <span class=\"status-badge '+(ls.status||'')+'\" style=\"font-size:10px;padding:1px 8px;\">'+ucfirst(ls.status||'')+'</span></span>';}
        }).catch(()=>{});
        // RFID
        const rfidSec = document.getElementById('vRfidSection');
        <?php if (!empty($rfidMap)): ?>
        const hasRfid = <?= json_encode(array_keys($rfidMap)) ?>.includes(String(s.id));
        <?php else: ?>
        const hasRfid = false;
        <?php endif; ?>
        if (hasRfid) { rfidSec.style.display = 'flex'; document.getElementById('vRfidLink').href = 'rfid-cards.php?search='+encodeURIComponent(s.student_number); document.getElementById('vScanLink').href = 'rfid-scan-logs.php?search='+encodeURIComponent(s.student_number); }
        else rfidSec.style.display = 'none';
        // Load other tabs
        loadDocuments(s.id);
        loadAcademic(s.id);
        loadHealth(s.id);
        // AI profile summary — non-blocking, cached, graceful on slowness.
        const aiSum = document.getElementById('vAiSummary');
        aiSum.style.display = 'block';
        aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span> <span style="color:#64748b;font-size:12px;margin-left:4px;"><i class="fas fa-spinner fa-spin"></i> Generating...</span>';
        let summaryTimedOut = false;
        const summaryTimer = setTimeout(() => {
            if (!summaryTimedOut) {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">Summary is taking a moment — it will appear when ready.</p>';
            }
        }, 4000);
        aiToolsPost('profile', { id: s.id }).then(d => {
            clearTimeout(summaryTimer);
            summaryTimedOut = true;
            if (d.success && d.data && d.data.summary) {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#334155;">' + d.data.summary + '</p>';
                aiSum.style.display = 'block';
            } else {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable for this student.</p>';
                aiSum.style.display = 'block';
            }
        }).catch(() => {
            clearTimeout(summaryTimer);
            summaryTimedOut = true;
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>';
            aiSum.style.display = 'block';
        });
        // Reset to profile tab
        document.querySelectorAll('.vtab').forEach(t=>t.classList.remove('active'));
        document.querySelector('.vtab[data-tab="profile"]')?.classList.add('active');
        document.querySelectorAll('.vtab-content').forEach(t=>t.style.display='none');
        document.getElementById('tabProfile').style.display = '';
        viewModal.classList.add('active'); document.body.style.overflow = 'hidden';
    }).catch(() => alert('Failed to load.'));
}

function switchVTab(btn, tab) {
    document.querySelectorAll('.vtab').forEach(t=>{t.style.borderBottomColor='transparent';t.style.color='#64748b'});
    btn.style.borderBottomColor='#2563eb';btn.style.color='#2563eb';
    document.querySelectorAll('.vtab-content').forEach(t=>t.style.display='none');
    document.getElementById('tab'+tab.charAt(0).toUpperCase()+tab.slice(1)).style.display='';
}

function loadDocuments(sid) {
    fetch('../api/students.php?action=documents&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vDocuments');
        if(!d.success||!d.data||!d.data.length){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No document requests.</p>';return;}
        el.innerHTML='<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Type</td><td>Status</td><td>Date</td></tr>'+d.data.map(dr=>'<tr style="border-bottom:1px solid #f1f5f9;"><td>'+ucfirst(dr.document_type.replace('_',' '))+'</td><td><span class="status-badge '+(dr.status||'')+'" style="font-size:10px;">'+ucfirst(dr.status||'')+'</span></td><td>'+(dr.request_date?new Date(dr.request_date).toLocaleDateString():'')+'</td></tr>').join('')+'</table>';
    }).catch(()=>{});
}
function loadAcademic(sid) {
    fetch('../api/students.php?action=academic&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vAcademic');
        if(!d.success||!d.data||!d.data.length){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No academic history found.</p>';return;}
        el.innerHTML='<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>School</td><td>Year</td><td>GWA</td></tr>'+d.data.map(a=>'<tr style="border-bottom:1px solid #f1f5f9;"><td>'+a.school_name+'</td><td>'+(a.school_year||'')+'</td><td>'+(a.gwa||'—')+'</td></tr>').join('')+'</table>';
    }).catch(()=>{});
}
function loadHealth(sid) {
    fetch('../api/students.php?action=health&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vHealth');
        if(!d.success||!d.data){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No health record.</p>';return;}
        const h=d.data;
        el.innerHTML='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><div class="view-item"><div class="lbl">Blood Type</div><div class="val">'+(h.blood_type||'—')+'</div></div><div class="view-item"><div class="lbl">Height / Weight</div><div class="val">'+(h.height?h.height+'cm':'—')+' / '+(h.weight?h.weight+'kg':'—')+'</div></div><div class="view-item" style="grid-column:span 2;"><div class="lbl">Allergies</div><div class="val">'+(h.allergies||'None')+'</div></div><div class="view-item" style="grid-column:span 2;"><div class="lbl">Pre-existing Conditions</div><div class="val">'+(h.pre_existing_conditions||'None')+'</div></div></div>';
    }).catch(()=>{});
}
function uploadPhoto() {
    const input = document.getElementById('photoInput');
    if (!input.files[0] || !currentViewId) return;
    const fd = new FormData();
    fd.append('photo', input.files[0]);
    fd.append('student_id', currentViewId);
    fetch('../api/students.php?action=upload-photo', { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{ if(d.success) window.location.reload(); else alert(d.message); })
    .catch(()=>alert('Upload failed.'));
}
function closeViewModal() { viewModal.classList.remove('active'); document.body.style.overflow = ''; }
viewModal.addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

function printTable() {
    const w = window.open(); w.document.write('<html><head><style>table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #ddd;text-align:left;font-size:12px}th{background:#f4f4f4}</style></head><body><h2>Student List</h2><table><tr><th>ID</th><th>Name</th><th>Course</th><th>Year</th><th>Status</th></tr>');
    allStudents.filter(s=>s.element&&s.element.style.display!=='none').forEach(s=>{
        w.document.write('<tr><td>'+(s.student_number||'')+'</td><td>'+(s.first_name||'')+' '+(s.last_name||'')+'</td><td>'+(s.course||'')+'</td><td>'+(s.year_level||'')+'</td><td>'+(s.status||'')+'</td></tr>');
    });
    w.document.write('</table></body></html>'); w.document.close(); w.print();
}

// ─── EDIT MODAL (full fields) ───────────────────────────────
function editStudent(id) {
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        document.getElementById('editId').value = s.id;
        document.getElementById('editStudentNumber').value = s.student_number;
        document.getElementById('editStatus').value = s.status || 'active';
        document.getElementById('editFirstName').value = s.first_name;
        document.getElementById('editMiddleName').value = s.middle_name || '';
        document.getElementById('editLastName').value = s.last_name;
        document.getElementById('editSuffix').value = s.name_suffix || '';
        document.getElementById('editLrn').value = s.lrn || '';
        document.getElementById('editGender').value = s.gender || '';
        document.getElementById('editCivilStatus').value = s.civil_status || '';
        document.getElementById('editBirthDate').value = s.birth_date || '';
        document.getElementById('editBirthPlace').value = s.place_of_birth || '';
        document.getElementById('editNationality').value = s.nationality || '';
        document.getElementById('editReligion').value = s.religion || '';
        document.getElementById('editFather').value = s.father_name || '';
        document.getElementById('editMother').value = s.mother_name || '';
        document.getElementById('editCourse').value = s.course || '';
        refreshMajorOptions('edit');
        document.getElementById('editMajor').value = s.major || '';
        document.getElementById('editYearLevel').value = s.year_level || '';
        document.getElementById('editSchoolYear').value = s.school_year || '';
        document.getElementById('editSemester').value = s.semester || '';
        document.getElementById('editEmail').value = s.email || '';
        document.getElementById('editContact').value = s.contact_number || '';
        document.getElementById('editAddress').value = s.address || '';
        // Load guardian
        document.getElementById('editGuardianName').value = '';
        document.getElementById('editGuardianRel').value = '';
        document.getElementById('editGuardianContact').value = '';
        document.getElementById('editGuardianEmail').value = '';
        fetch('../api/students.php?action=guardian&student_id='+id).then(r=>r.json()).then(gd=>{
            if(gd.success&&gd.data){document.getElementById('editGuardianName').value=gd.data.full_name||'';document.getElementById('editGuardianRel').value=gd.data.relationship||'';document.getElementById('editGuardianContact').value=gd.data.contact_number||'';document.getElementById('editGuardianEmail').value=gd.data.email||'';}
        }).catch(()=>{});
        document.getElementById('editModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }).catch(() => alert('Failed to load.'));
}
function closeEditModal() { document.getElementById('editModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('editId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const res = await fetch('../api/students.php?id=' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('editFirstName').value,
                middle_name: document.getElementById('editMiddleName').value,
                last_name: document.getElementById('editLastName').value,
                name_suffix: document.getElementById('editSuffix').value,
                lrn: document.getElementById('editLrn').value,
                father_name: document.getElementById('editFather').value,
                mother_name: document.getElementById('editMother').value,
                gender: document.getElementById('editGender').value,
                civil_status: document.getElementById('editCivilStatus').value,
                birth_date: document.getElementById('editBirthDate').value,
                place_of_birth: document.getElementById('editBirthPlace').value,
                nationality: document.getElementById('editNationality').value,
                religion: document.getElementById('editReligion').value,
                status: document.getElementById('editStatus').value,
                course: document.getElementById('editCourse').value,
                major: document.getElementById('editMajor').value || null,
                year_level: document.getElementById('editYearLevel').value,
                school_year: document.getElementById('editSchoolYear').value,
                semester: document.getElementById('editSemester').value,
                email: document.getElementById('editEmail').value,
                contact_number: document.getElementById('editContact').value,
                address: document.getElementById('editAddress').value,
                guardian_name: document.getElementById('editGuardianName').value,
                guardian_relationship: document.getElementById('editGuardianRel').value,
                guardian_contact: document.getElementById('editGuardianContact').value,
                guardian_email: document.getElementById('editGuardianEmail').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Updated', 'Student record updated.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
});

// ─── COURSE & MAJOR DROPDOWN (native select) ─────────────────
// Course data is rendered directly into the <select> options by PHP.
const COURSE_MAJORS = <?= json_encode($offeredCourses) ?>;

/**
 * Refresh the Major dropdown for a given prefix (add/edit).
 * Reads the selected course value from the native #<prefix>Course select.
 */
function refreshMajorOptions(prefix) {
    const course = document.getElementById(prefix + 'Course').value;
    const majorGroup = document.getElementById(prefix + 'MajorGroup');
    const majorEl = document.getElementById(prefix + 'Major');
    if (!majorGroup || !majorEl) return;
    const majors = (COURSE_MAJORS[course] || []);
    if (majors.length > 0) {
        majorGroup.style.display = '';
        majorEl.innerHTML = '<option value="">Select major</option>' +
            majors.map(m => '<option value="' + m.replace(/"/g, '&quot;') + '">' + m + '</option>').join('');
    } else {
        majorGroup.style.display = 'none';
        majorEl.innerHTML = '<option value="">Select major</option>';
    }
}

// Wire the course selects so changing the course refreshes the Major dropdown
['add', 'edit'].forEach(prefix => {
    const cEl = document.getElementById(prefix + 'Course');
    if (cEl) cEl.addEventListener('change', () => refreshMajorOptions(prefix));
});

// ─── Course select: scrollable custom list ────────────────────
// Builds a styled, scrollable option list (max ~5 rows) for the course
// <select>, syncs the hidden select value so form submission and the
// Major dropdown logic keep working unchanged.
['add', 'edit'].forEach(prefix => {
    const wrap = document.querySelector(`#${prefix}Modal .course-select-wrap`);
    const sel = document.getElementById(prefix + 'Course');
    const list = wrap ? wrap.querySelector('.course-select-list') : null;
    if (!wrap || !sel || !list) return;

    function renderOptions() {
        const opts = Array.from(sel.options);
        list.innerHTML = opts.map((o, i) =>
            '<div class="cs-option" data-idx="' + i + '">' +
              o.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;') +
            '</div>'
        ).join('');
        list.querySelectorAll('.cs-option').forEach(opt => {
            opt.addEventListener('click', () => {
                const idx = parseInt(opt.dataset.idx, 10);
                sel.selectedIndex = idx;
                sel.dispatchEvent(new Event('change'));
                list.style.display = 'none';
            });
        });
    }
    function syncActive() {
        list.querySelectorAll('.cs-option').forEach(o =>
            o.classList.toggle('active', parseInt(o.dataset.idx, 10) === sel.selectedIndex));
    }

    renderOptions();

    // The select is the visual trigger — mousedown stops the native
    // dropdown from opening, then click toggles the styled list.
    sel.addEventListener('mousedown', e => e.preventDefault());
    sel.addEventListener('click', () => {
        const open = list.style.display === 'block';
        // close any other open course list
        document.querySelectorAll('.course-select-list').forEach(l => { if (l !== list) l.style.display = 'none'; });
        list.style.display = open ? 'none' : 'block';
        if (list.style.display === 'block') syncActive();
    });
    sel.addEventListener('change', () => { list.style.display = 'none'; refreshMajorOptions(prefix); });
    // Clicking outside closes it
    document.addEventListener('click', e => {
        if (!wrap.contains(e.target)) list.style.display = 'none';
    });
});

// ─── NOTE: Section is auto-generated by the Masterlist module
// ("Auto-assign sections"), so it is not a manual form field.

// ─── ADD MODAL ───────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('addForm').reset();
    refreshMajorOptions('add');
}
function closeAddModal() { document.getElementById('addModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) closeAddModal(); });

document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const res = await fetch('../api/students.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('addFirstName').value,
                middle_name: document.getElementById('addMiddleName').value,
                last_name: document.getElementById('addLastName').value,
                name_suffix: document.getElementById('addSuffix').value,
                lrn: document.getElementById('addLrn').value,
                father_name: document.getElementById('addFather').value,
                mother_name: document.getElementById('addMother').value,
                gender: document.getElementById('addGender').value,
                civil_status: document.getElementById('addCivilStatus').value,
                birth_date: document.getElementById('addBirthDate').value,
                place_of_birth: document.getElementById('addBirthPlace').value,
                nationality: document.getElementById('addNationality').value,
                religion: document.getElementById('addReligion').value,
                status: document.getElementById('addStatus').value,
                course: document.getElementById('addCourse').value,
                major: document.getElementById('addMajor').value || null,
                year_level: document.getElementById('addYearLevel').value,
                school_year: document.getElementById('addSchoolYear').value,
                semester: document.getElementById('addSemester').value,
                section: document.getElementById('addSection').value,
                email: document.getElementById('addEmail').value,
                contact_number: document.getElementById('addContact').value,
                address: document.getElementById('addAddress').value,
                guardian_name: document.getElementById('addGuardianName').value,
                guardian_relationship: document.getElementById('addGuardianRel').value,
                guardian_contact: document.getElementById('addGuardianContact').value,
                guardian_email: document.getElementById('addGuardianEmail').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Added', 'Student created successfully.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
});

// ─── AI ASSIST ───────────────────────────────────────────────
async function aiPost(action, body) {
    const res = await fetch('../api/ai-assist.php?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return await res.json();
}

// Batch AI tools (data quality, standardization, duplicate scan) live
// in api/ai-tools.php, not ai-assist.php.
async function aiToolsPost(action, body) {
    const res = await fetch('../api/ai-tools.php?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return await res.json();
}

// Document reader / paste-to-fill modal
function openPasteModal() {
    document.getElementById('pasteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('pasteText').value = '';
    document.getElementById('pasteFile').value = '';
    document.getElementById('pasteFileName').textContent = '';
    document.getElementById('pastePreview').style.display = 'none';
    document.getElementById('pasteApplyBtn').style.display = 'none';
    document.getElementById('pasteExtractBtn').style.display = '';
}
function closePasteModal() { document.getElementById('pasteModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('pasteModal').addEventListener('click', function(e) { if (e.target === this) closePasteModal(); });

// Drag & drop dropzone
(function() {
    const dz = document.getElementById('pasteDropzone');
    const fileInput = document.getElementById('pasteFile');
    const nameEl = document.getElementById('pasteFileName');
    function showName() { nameEl.textContent = fileInput.files.length ? 'Selected: ' + fileInput.files[0].name : ''; }
    dz.addEventListener('click', function() { fileInput.click(); });
    fileInput.addEventListener('change', showName);
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, function(e) {
        e.preventDefault(); e.stopPropagation();
        dz.style.borderColor = '#2563eb'; dz.style.background = '#eef4ff';
    }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, function(e) {
        e.preventDefault(); e.stopPropagation();
        dz.style.borderColor = ''; dz.style.background = '';
    }));
    dz.addEventListener('drop', function(e) {
        const files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length) {
            fileInput.files = files;
            showName();
        }
    });
})();

let pasteData = null;
async function extractPaste() {
    const fileEl = document.getElementById('pasteFile');
    const text = document.getElementById('pasteText').value.trim();
    const hasFile = fileEl.files && fileEl.files.length > 0;
    if (!hasFile && !text) { alert('Upload a file or paste some text first.'); return; }
    const btn = document.getElementById('pasteExtractBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Extracting...';
    try {
        let d;
        if (hasFile) {
            const fd = new FormData();
            fd.append('file', fileEl.files[0]);
            const res = await fetch('../api/ai-assist.php?action=extract_doc', {
                method: 'POST', body: fd
            });
            d = await res.json();
        } else {
            d = await aiPost('paste_fill', { text });
        }
        if (!d.success) { alert(d.message || 'Extraction failed.'); return; }
        pasteData = d.data || {};
        const keys = ['first_name','middle_name','last_name','gender','birth_date','place_of_birth','nationality','religion','email','contact_number','address','course','year_level','guardian_name','guardian_relationship'];
        let html = '<div style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:8px;">Extracted — review before applying</div>';
        let found = 0;
        keys.forEach(k => {
            const v = pasteData[k];
            if (v !== undefined && v !== null && v !== '') { found++; html += '<div style="font-size:13px;color:#334155;padding:2px 0;"><b style="color:#475569;display:inline-block;width:130px;">' + k.replace(/_/g,' ') + ':</b> ' + (typeof v === 'string' ? v : v) + '</div>'; }
        });
        if (found === 0) html += '<p style="color:#94a3b8;font-size:13px;">No fields recognized. Try more complete text.</p>';
        document.getElementById('pastePreview').innerHTML = html;
        document.getElementById('pastePreview').style.display = 'block';
        document.getElementById('pasteApplyBtn').style.display = '';
    } catch(e) { alert('Extraction error: ' + e.message); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic"></i> Extract'; }
}

function applyPaste() {
    if (!pasteData) return;
    const map = {
        first_name: 'addFirstName', middle_name: 'addMiddleName', last_name: 'addLastName',
        gender: 'addGender', birth_date: 'addBirthDate', place_of_birth: 'addBirthPlace',
        nationality: 'addNationality', religion: 'addReligion', email: 'addEmail',
        contact_number: 'addContact', address: 'addAddress', course: 'addCourse',
        year_level: 'addYearLevel', guardian_name: 'addGuardianName', guardian_relationship: 'addGuardianRel'
    };
    for (const k in map) {
        const el = document.getElementById(map[k]);
        if (el && pasteData[k] !== undefined && pasteData[k] !== null && pasteData[k] !== '') {
            el.value = pasteData[k];
        }
    }
    refreshMajorOptions('add');
    closePasteModal();
    showToast('Applied', 'Form pre-filled from extracted data.', 'success');
}

// Duplicate check on name blur (deterministic, no LLM)
function checkDuplicateHint() {
    const fn = document.getElementById('addFirstName').value.trim();
    const ln = document.getElementById('addLastName').value.trim();
    const bd = document.getElementById('addBirthDate').value;
    if (!fn || !ln) return;
    aiPost('check_duplicate', { first_name: fn, last_name: ln, birth_date: bd })
    .then(d => {
        if (d.success && d.data && d.data.length) {
            const hit = d.data[0];
            let msg = 'Possible duplicate: ' + hit.name + ' (' + (hit.student_number||'') + '). Enrol anyway?';
            if (hit.score >= 0.9 && hit.birth_date === bd) {
                msg = 'Likely duplicate of ' + hit.name + ' (' + hit.student_number + ').';
            }
            if (!confirm(msg)) return;
        }
    }).catch(() => {});
}
document.getElementById('addFirstName').addEventListener('blur', checkDuplicateHint);
document.getElementById('addLastName').addEventListener('blur', checkDuplicateHint);

// Course auto-standardize on blur (deterministic)
function standardizeCourse() {
    const el = document.getElementById('addCourse');
    const val = el.value.trim();
    if (!val) return;
    fetch('../api/ai-assist.php?action=suggest_field', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ field: 'course', value: val, context: 'student enrollment' })
    }).then(r => r.json()).then(d => {
        if (d.success && d.data && d.data.suggested && d.data.suggested !== val) {
            if (confirm('Standardize course to "' + d.data.suggested + '"?')) {
                el.value = d.data.suggested;
                refreshMajorOptions('add');
            }
        }
    }).catch(() => {});
}
document.getElementById('addCourse').addEventListener('blur', standardizeCourse);

// ─── DATA QUALITY PANEL ─────────────────────────────────────
let qualityData = null;
function openQualityPanel(force) {
    const modal = document.getElementById('qualityModal');
    const loading = document.getElementById('qualityLoading');
    const content = document.getElementById('qualityContent');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    loading.style.display = '';
    content.style.display = 'none';
    aiToolsPost('quality').then(d => {
        loading.style.display = 'none';
        if (!d.success || !d.data) { content.innerHTML = '<p style="color:#dc2626;">Failed to analyze.</p>'; content.style.display='block'; return; }
        qualityData = d.data;
        renderQualityPanel();
    }).catch(() => { loading.style.display='none'; content.innerHTML='<p style="color:#dc2626;">Error analyzing data.</p>'; content.style.display='block'; });
}
function closeQualityPanel() { document.getElementById('qualityModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('qualityModal').addEventListener('click', function(e) { if (e.target === this) closeQualityPanel(); });

function renderQualityPanel() {
    const d = qualityData;
    let html = '<div style="margin-bottom:14px;"><b style="font-size:14px;color:#0f172a;">' + d.total_students + ' student records</b></div>';
    // Issue summary
    const issues = d.issue_counts || {};
    const issueKeys = Object.keys(issues);
    if (issueKeys.length) {
        html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:6px;">Data Issues</div><div style="margin-bottom:12px;">';
        issueKeys.forEach(k => {
            html += '<div style="display:flex;justify-content:space-between;padding:5px 8px;background:#fef2f2;border-radius:6px;margin-bottom:4px;font-size:13px;color:#991b1b;"><span>' + k + '</span><b>' + issues[k] + '</b></div>';
        });
        html += '</div>';
    } else {
        html += '<p style="color:#16a34a;font-size:13px;"><i class="fas fa-check-circle"></i> No data issues found.</p>';
    }
    // Non-standard courses
    const nsc = d.non_standard_courses || [];
    if (nsc.length) {
        html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin:10px 0 6px;">Non-standard course names</div>';
        nsc.forEach(c => {
            const btn = c.standardized
                ? '<button class="btn btn-secondary" style="padding:3px 10px;font-size:11px;margin-left:6px;" onclick="applyStd(\'' + c.raw.replace(/'/g,"\\'") + '\',\'' + c.standardized.replace(/'/g,"\\'") + '\')">Fix → ' + c.standardized + '</button>'
                : '<span style="color:#94a3b8;font-size:11px;margin-left:6px;">(no confident match)</span>';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;background:#fffbeb;border-radius:6px;margin-bottom:4px;font-size:13px;color:#92400e;"><span><b>' + c.raw + '</b> × ' + c.count + '</span>' + btn + '</div>';
        });
    }
    html += '<div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;">';
    html += '<button class="btn btn-secondary" style="margin-right:6px;" onclick="runDupScan()"><i class="fas fa-clone"></i> Scan for Duplicates</button>';
    html += '<button class="btn btn-secondary" onclick="runStandardizeAll()"><i class="fas fa-magic"></i> Standardize All Courses</button>';
    html += '</div><div id="qualityResult" style="margin-top:12px;"></div>';
    document.getElementById('qualityContent').innerHTML = html;
    document.getElementById('qualityContent').style.display = 'block';
}

function runDupScan() {
    const box = document.getElementById('qualityResult');
    box.innerHTML = '<p style="color:#64748b;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Scanning...</p>';
    aiToolsPost('scan_dupes').then(d => {
        if (!d.success) { box.innerHTML = '<p style="color:#dc2626;">Scan failed.</p>'; return; }
        const pairs = (d.data && d.data.pairs) || [];
        if (!pairs.length) { box.innerHTML = '<p style="color:#16a34a;font-size:13px;"><i class="fas fa-check-circle"></i> No likely duplicates found.</p>'; return; }
        let html = '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">Potential duplicates</div>';
        pairs.forEach(p => {
            html += '<div style="background:#f8fafc;border-radius:6px;padding:8px;margin-bottom:6px;font-size:13px;color:#334155;">'
                + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;"><span><b>' + p.a.name + '</b> (' + p.a.sn + ') <span style="color:#94a3b8;">vs</span> <b>' + p.b.name + '</b> (' + p.b.sn + ') <span style="color:#64748b;font-size:11px;">· score ' + p.score + '</span></span>'
                + '<button class="btn btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="mergeDupes(' + p.a.id + ',' + p.b.id + ',this)"><i class="fas fa-code-merge"></i> Merge</button></div></div>';
        });
        box.innerHTML = html;
    }).catch(() => { box.innerHTML = '<p style="color:#dc2626;">Scan error.</p>'; });
}

function runStandardizeAll() {
    const box = document.getElementById('qualityResult');
    box.innerHTML = '<p style="color:#64748b;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Drafting standardization...</p>';
    aiToolsPost('standardize').then(d => {
        if (!d.success) { box.innerHTML = '<p style="color:#dc2626;">Failed.</p>'; return; }
        const changes = (d.data && d.data.changes) || [];
        if (!changes.length) { box.innerHTML = '<p style="color:#16a34a;font-size:13px;"><i class="fas fa-check-circle"></i> All course names already standardized.</p>'; return; }
        let html = '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">' + changes.length + ' course change(s) ready</div>';
        changes.forEach(c => {
            html += '<div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:13px;color:#334155;"><span>' + c.from + ' <span style="color:#94a3b8;">→</span> <b>' + c.to + '</b></span><button class="btn btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="applyStdById(' + c.id + ',\'' + c.to.replace(/'/g,"\\'") + '\',this)">Apply</button></div>';
        });
        box.innerHTML = html;
    }).catch(() => { box.innerHTML = '<p style="color:#dc2626;">Error.</p>'; });
}

function applyStd(from, to) {
    if (!confirm('Change "' + from + '" → "' + to + '"?')) return;
    aiToolsPost('standardize').then(d => {
        if (!d.success) return;
        const changes = (d.data && d.data.changes) || [];
        const match = changes.filter(c => c.from === from).map(c => c.id);
        const chain = match.map(id => aiToolsPost('apply_std', { id, to }));
        return Promise.all(chain);
    }).then(() => { showToast('Updated', 'Course standardized.', 'success'); openQualityPanel(true); }).catch(() => alert('Error applying.'));
}
function applyStdById(id, to, btn) {
    if (!confirm('Apply course change?')) return;
    btn.disabled = true;
    aiToolsPost('apply_std', { id, to }).then(d => {
        if (d.success) { showToast('Updated', 'Course updated.', 'success'); btn.parentElement.remove(); }
        else { alert(d.message || 'Failed.'); btn.disabled = false; }
    }).catch(() => { alert('Error.'); btn.disabled = false; });
}

// ─── MERGE DUPLICATES ───────────────────────────────────────
function mergeDupes(idA, idB, btn) {
    const which = confirm('Merge these duplicates?\n\nKeep A (record ' + idA + ') and remove B (record ' + idB + ')?\n\nClick OK to keep the FIRST record, or Cancel to keep the SECOND.');
    const keeperId = which ? idA : idB;
    const removeId = which ? idB : idA;
    if (!confirm('Keep record ' + keeperId + ' and delete record ' + removeId + '? This moves all related records (documents, guardians, RFID, etc.) to the keeper. This cannot be undone.')) return;
    btn.disabled = true;
    aiToolsPost('merge', { keeper_id: keeperId, remove_id: removeId }).then(d => {
        if (d.success) { showToast('Merged', 'Records merged.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { alert(d.message || 'Merge failed.'); btn.disabled = false; }
    }).catch(() => { alert('Merge error.'); btn.disabled = false; });
}

// ─── SECTION SUGGESTION ─────────────────────────────────────
function suggestSection() {
    const course = document.getElementById('addCourse').value;
    const year = document.getElementById('addYearLevel').value;
    const sem = document.getElementById('addSemester').value;
    if (!course || !year) { alert('Choose a course and year level first.'); return; }
    const btn = event.target.closest('button');
    if (btn) btn.disabled = true;
    aiPost('suggest_section', { course, year_level: year, semester: sem }).then(d => {
        if (d.success && d.data && d.data.suggestion) {
            document.getElementById('addSection').value = d.data.suggestion;
            showToast('Suggested', 'Section ' + d.data.suggestion, 'success');
        } else {
            alert(d.message || 'Could not suggest a section.');
        }
    }).catch(() => alert('Error suggesting section.'))
      .finally(() => { if (btn) btn.disabled = false; });
}

// ─── GUARDIAN AUTO-FILL ─────────────────────────────────────
function guardianAutoFill() {
    const ln = document.getElementById('addLastName').value.trim();
    const g = document.getElementById('addGuardianName');
    if (!ln || g.value.trim()) return; // only fill if guardian name is empty
    // Common PH convention: guardian shares the student's surname.
    g.value = ln;
}
document.getElementById('addLastName').addEventListener('blur', guardianAutoFill);

// ─── QUICK STATUS ────────────────────────────────────────────
function toggleQuickMenu(id) { document.getElementById('qsm_'+id).classList.toggle('show'); }
document.addEventListener('click', e => { if (!e.target.closest('.quick-status-wrap')) document.querySelectorAll('.quick-status-menu').forEach(m => m.classList.remove('show')); });

function quickStatus(id, status) {
    fetch('../api/students.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }) })
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); }).catch(() => alert('Error.'));
}

// ─── DELETE / RESTORE ────────────────────────────────────────
const deleteModal = document.getElementById('deleteModal');
function confirmDelete(id, name) { deleteTarget = id; document.getElementById('deleteMessage').textContent = 'Delete ' + name + '? This can be undone by restoring.'; deleteModal.classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeDeleteModal() { deleteModal.classList.remove('active'); document.body.style.overflow = ''; deleteTarget = null; }
document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!deleteTarget) return;
    this.disabled = true; this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    try {
        const r = await fetch('../api/students.php?id=' + deleteTarget, { method: 'DELETE' });
        const d = await r.json();
        if (d.success) window.location.reload();
        else alert(d.message);
    } catch(e) { alert('Error.'); }
    finally { this.disabled = false; this.innerHTML = '<i class="fas fa-trash-alt"></i> Delete'; closeDeleteModal(); }
});
deleteModal.addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });

function restoreStudent(id, name) {
    if (!confirm('Restore ' + name + '?')) return;
    fetch('../api/students.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: 'active' }) })
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); }).catch(() => alert('Error.'));
}

// ─── EXPORT ─────────────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('exportMenu').classList.toggle('show');
});
document.addEventListener('click', function() { document.getElementById('exportMenu').classList.remove('show'); });

function exportCSV() {
    exportStudents(allStudents);
}
function exportFiltered() {
    const visible = allStudents.filter(s => s.element && s.element.style.display !== 'none');
    exportStudents(visible);
}
function exportStudents(list) {
    let csv = "Student ID,Last Name,First Name,Middle Name,Course,Year Level,Section,Gender,Email,Contact,Status\n";
    list.forEach(s => {
        csv += (s.student_number||'')+','+(s.last_name||'')+','+(s.first_name||'')+','+(s.middle_name||'')+','+(s.course||'')+','+(s.year_level||'')+','+(s.section||'')+','+(s.gender||'')+','+(s.email||'')+','+(s.contact_number||'')+','+(s.status||'active')+'\n';
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'students_export.csv'; a.click();
    URL.revokeObjectURL(a.href);
}

// ─── TOAST ───────────────────────────────────────────────────
function ensureToastContainer() { let c = document.querySelector('.toast-container'); if (!c) { c = document.createElement('div'); c.className = 'toast-container'; document.body.appendChild(c); } return c; }
function showToast(title, message, type) {
    const c = ensureToastContainer(); const t = document.createElement('div'); t.className = 'toast ' + (type||'info');
    t.innerHTML = '<i class="fas ' + (type==='success'?'fa-circle-check':type==='error'?'fa-circle-xmark':'fa-circle-info') + ' toast-icon"></i><div class="toast-content"><div class="toast-title"></div><div class="toast-message"></div></div><button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>';
    t.querySelector('.toast-title').textContent = title; t.querySelector('.toast-message').textContent = message;
    t.querySelector('.toast-close').addEventListener('click', () => { t.classList.add('hiding'); setTimeout(() => t.remove(), 300); });
    c.appendChild(t); setTimeout(() => { t.classList.add('hiding'); setTimeout(() => t.remove(), 300); }, 4000);
}
function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

// ─── INIT ────────────────────────────────────────────────────
performSearch();
(function() {
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    if (!success) return;
    const msgs = { added: ['Student Added','Created successfully.'], updated: ['Student Updated','Record updated.'], archived: ['Student Deleted','Record archived.'] };
    if (msgs[success]) showToast(msgs[success][0], msgs[success][1], 'success');
    const url = new URL(window.location.href); url.searchParams.delete('success'); window.history.replaceState({}, '', url.toString());
})();
</script>
<?php include '../includes/footer.php'; ?>