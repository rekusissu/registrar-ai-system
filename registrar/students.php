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
// Per-year-level counts for the Year 1-4 cards.
$yearLevelCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($students as $s) {
    $yl = (int) ($s['year_level'] ?? 0);
    if (isset($yearLevelCounts[$yl])) { $yearLevelCounts[$yl]++; }
}

$thisMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$lastMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$trendTotal = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100) : ($thisMonth > 0 ? 100 : 0);

// Courses for filter
$courses = $db->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");

// ─── OFFERED COURSES & MAJORS ────────────────────────────────
// Single source of truth (shared with the Masterlist via shared/functions.php).
$offeredCourses = getOfferedCourses();

// Advisers for the adviser dropdown (active staff accounts)
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'staff' AND is_active = 1 ORDER BY full_name");

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
.dashboard-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card { background:white; border-radius:14px; padding:18px 20px; border:1px solid #e2e8f0; transition:all .3s; box-shadow:0 1px 3px rgba(15,23,42,0.04); }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 6px 20px rgba(15,23,42,0.06); border-color:#d8dde4; }
.stat-card .stat-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
.stat-card .stat-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0; }
.stat-icon.blue{background:#eef4ff;color:#2563eb} .stat-icon.green{background:#dcfce7;color:#16a34a} .stat-icon.teal{background:#ccfbf1;color:#0d9488}
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

/* Table */
.table-responsive{overflow-x:auto}
.table-responsive table{width:100%;border-collapse:collapse}
.table-responsive th{text-align:left;padding:10px 10px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#64748b;background:#fafcfd;border-bottom:2px solid #e8edf4;white-space:nowrap}
.table-responsive td{padding:10px 10px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.table-responsive tbody tr{transition:background .15s ease}
.table-responsive tbody tr:hover{background:#f8fafc}
.table-responsive tbody tr:last-child td{border-bottom:none}
.table-responsive tbody tr.archived{opacity:.5;background:#f8fafc}

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
.empty-state{text-align:center;padding:30px 20px;color:#94a3b8;min-height:200px}
.empty-state i{font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px}
.empty-state p{font-size:15px;font-weight:500;color:#94a3b8}
.empty-state span{font-size:13px;color:#cbd5e1}
#emptyState{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:50vh;margin:0 auto;padding:40px 20px}

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
/* Delete icon */
.delete-icon{width:60px;height:60px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:#dc2626}

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
<button class="btn btn-primary" onclick="openReceiveModal()"><i class="fas fa-inbox"></i> Receive Student</button>
</div>
</header>

<!-- Stats -->
<div class="dashboard-stats">
<div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-users"></i></div></div><div class="stat-number"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-seedling"></i></div></div><div class="stat-number"><?= $yearLevelCounts[1] ?></div><div class="stat-label">Freshmen</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-book"></i></div></div><div class="stat-number"><?= $yearLevelCounts[2] ?></div><div class="stat-label">Sophomore</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div></div><div class="stat-number"><?= $yearLevelCounts[3] ?></div><div class="stat-label">Junior</div></div>
<div class="stat-card"><div class="stat-top"><div class="stat-icon teal"><i class="fas fa-award"></i></div></div><div class="stat-number"><?= $yearLevelCounts[4] ?></div><div class="stat-label">Senior</div></div>
</div>

<!-- Search + Table -->
<div class="search-table-container">
<div class="search-bar">
<div class="search-wrapper">
<i class="fas fa-search"></i>
<input type="text" id="studentSearch" placeholder="Search by name, ID, program..." />
<button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
</div>
<div class="search-actions">
<button class="btn btn-secondary" id="filterToggle"><i class="fas fa-sliders"></i> Filter</button>
</div>
</div>

<div class="table-responsive" id="studentTableWrap">
<table id="studentTable">
<thead>
<tr><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>RFID</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
</thead>
<tbody id="studentTableBody">
<?php if (!empty($students)): ?>
<?php
$avatarColors = ['blue','green','purple','orange','pink'];
foreach ($students as $i => $s):
$initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
$ac = $avatarColors[$i % count($avatarColors)];
$hasRfid = isset($rfidMap[$s['id']]);
$rfidStatus = $hasRfid && $rfidMap[$s['id']]['status'] === 'active' ? 'active' : ($hasRfid ? 'inactive' : 'none');
?>
<tr data-student='<?= htmlspecialchars(json_encode($s),ENT_QUOTES,'UTF-8') ?>' class="<?= $s['status']==='archived'?'archived':'' ?>">
<td class="student-id" style="font-weight:600;font-size:12px;"><?= htmlspecialchars($s['student_number'] ?: '—') ?></td>
<td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($s['first_name']." ".$s['last_name']) ?></div><div class="student-email"><?= htmlspecialchars($s['email'] ?? '') ?></div></div></div></td>
<td><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['section'] ?? '—') ?></td>
<td><a href="../registrar/rfid-cards.php?search=<?= urlencode($s['student_number']) ?>" class="rfid-chip <?= $rfidStatus ?>"><i class="fas fa-<?= $rfidStatus==='active'?'check-circle':'credit-card' ?>"></i> <?= $rfidStatus==='active'?($rfidMap[$s['id']]['card_uid']):($rfidStatus==='none'?'—':$rfidMap[$s['id']]['status']) ?></a></td>
<td><span class="status-badge <?= $s['status']??'active' ?>"><span class="status-dot <?= $s['status']??'active' ?>"></span><?= ucfirst($s['status']??'Active') ?></span></td>
<td><div class="action-group"><button class="action-btn view" onclick="viewStudent(<?= (int)$s['id'] ?>)" title="View"><i class="fas fa-eye"></i></button><button class="action-btn edit" onclick="editStudent(<?= (int)$s['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<div class="empty-state" id="emptyState" style="<?= empty($students) ? 'display:flex' : 'display:none' ?>">
  <i class="fas fa-user-graduate"></i>
  <p>No students found</p>
  <span>Add your first student to get started</span>
</div>
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
<div class="form-group"><label>Status</label><select id="filterStatus" class="form-control"><option value="">All Status</option><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div>
<div class="form-group"><label>Year Level</label><select id="filterYear" class="form-control"><option value="">All Year</option><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option></select></div>
<div class="form-group"><label>Program</label><select id="filterCourse" class="form-control"><option value="">All Programs</option><?php foreach($courses as $c): ?><option value="<?= htmlspecialchars($c['course']) ?>"><?= htmlspecialchars($c['course']) ?></option><?php endforeach; ?></select></div>
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
<div class="vp-id" id="vDbId" style="font-size:11px;color:#94a3b8;margin-top:2px;">—</div>
<div id="vLastScan" style="font-size:12px;color:#64748b;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;"></div>
<div id="vAiSummary" style="display:none;margin-top:12px;background:linear-gradient(135deg,#eef4ff,#f5f3ff);border:1px solid #dbeafe;border-radius:10px;padding:12px 14px;font-size:13px;color:#1e40af;"></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9">
<div class="view-item"><div class="lbl">Date of Birth</div><div class="val" id="vBirthDate">—</div></div>
<div class="view-item"><div class="lbl">Sex</div><div class="val" id="vGender">—</div></div>
<div class="view-item"><div class="lbl">Civil Status</div><div class="val" id="vCivilStatus">—</div></div>
<div class="view-item"><div class="lbl">Nationality</div><div class="val" id="vNationality">—</div></div>
<div class="view-item"><div class="lbl">Religion</div><div class="val" id="vReligion">—</div></div>
<div class="view-item"><div class="lbl">Place of Birth</div><div class="val" id="vBirthPlace">—</div></div>
<div class="view-item"><div class="lbl">Email Address</div><div class="val" id="vEmail">—</div></div>
<div class="view-item"><div class="lbl">Mobile Number</div><div class="val" id="vContact">—</div></div>
<div class="view-item" style="grid-column:span 2;"><div class="lbl">Home Address</div><div class="val" id="vAddress">—</div></div>
</div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-school"></i> Previous School</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
<div class="view-item" style="grid-column:span 2;"><div class="lbl">Name of Previous School</div><div class="val" id="vPrevSchool">—</div></div>
<div class="view-item"><div class="lbl">School Year Graduated</div><div class="val" id="vSYGraduated">—</div></div>
<div class="view-item"><div class="lbl">Last Year Level Completed</div><div class="val" id="vLastYearCompleted">—</div></div>
</div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Family Information</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
<div class="view-item"><div class="lbl">Father's Name</div><div class="val" id="vFather">—</div></div>
<div class="view-item"><div class="lbl">Mother's Name</div><div class="val" id="vMother">—</div></div>
</div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user-shield"></i> Guardian Information</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
<div class="view-item"><div class="lbl">Guardian Name</div><div class="val" id="vGuardianName">—</div></div>
<div class="view-item"><div class="lbl">Relationship</div><div class="val" id="vGuardianRel">—</div></div>
<div class="view-item"><div class="lbl">Address</div><div class="val" id="vGuardianAddress">—</div></div>
<div class="view-item"><div class="lbl">Contact Number</div><div class="val" id="vGuardianContact">—</div></div>
<div class="view-item"><div class="lbl">Email Address</div><div class="val" id="vGuardianEmail">—</div></div>
</div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:14px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-phone-flip"></i> Emergency Contact</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
<div class="view-item"><div class="lbl">Emergency Contact Person</div><div class="val" id="vEmergencyName">—</div></div>
<div class="view-item"><div class="lbl">Relationship</div><div class="val" id="vEmergencyRel">—</div></div>
<div class="view-item"><div class="lbl">Address</div><div class="val" id="vEmergencyAddress">—</div></div>
<div class="view-item"><div class="lbl">Contact Number</div><div class="val" id="vEmergencyContact">—</div></div>
</div>
<div id="vRfidSection" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;text-align:center;display:flex;gap:8px;justify-content:center;"><a id="vRfidLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-credit-card"></i> RFID Card</a> <a id="vScanLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-clock-rotate-left"></i> Scan Logs</a></div>
</div>
<!-- Tab: Documents -->
<div class="vtab-content" id="tabDocuments" style="display:none;"><div id="vDocuments" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Academic -->
<div class="vtab-content" id="tabAcademic" style="display:none;"><div id="vAcademic" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Health -->
<div class="vtab-content" id="tabHealth" style="display:none;"><div id="vHealth" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="resendWelcomeEmail()" id="resendWelcomeBtn"><i class="fas fa-envelope"></i> Resend Welcome Email</button> <button class="btn btn-primary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Data Quality Panel Modal -->
<div class="modal-overlay" id="qualityModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-shield-halved"></i> Data Quality</h2><button class="modal-close" onclick="closeQualityPanel()"><i class="fas fa-times"></i></button></div><div class="modal-body">
<div id="qualityLoading" style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Analyzing records...</div>
<div id="qualityContent" style="display:none;"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeQualityPanel()">Close</button><button id="qualityRefreshBtn" type="button" class="btn btn-secondary" onclick="openQualityPanel(true)"><i class="fas fa-rotate"></i> Refresh</button></div></div></div>

<!-- Add Modal (inline, with guardian) -->
<div class="modal-overlay" id="addModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-plus-circle"></i> Enroll New Student</h2><div style="display:flex;gap:8px;align-items:center;"><button class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="openPasteModal()"><i class="fas fa-magic"></i> Paste to Fill</button><button class="modal-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button></div></div><form id="addForm"><div class="modal-body">
<div class="form-row"><div class="form-group"><label>Academic Status</label><select id="addStatus" class="form-control"><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="loa">LOA</option><option value="graduated">Graduated</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="addMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="addLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="addSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN (optional)</label><input type="text" id="addLrn" class="form-control" placeholder="12-digit Learner Reference No." maxlength="12"></div><div class="form-group"><label>Gender</label><select id="addGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="addCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date <span style="color:#dc2626;">*</span></label><input type="date" id="addBirthDate" class="form-control" required></div><div class="form-group"><label>Place of Birth</label><input type="text" id="addBirthPlace" class="form-control" placeholder="City, Province"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="addNationality" class="form-control" value="Filipino"></div><div class="form-group"><label>Religion</label><input type="text" id="addReligion" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Father's Name</label><input type="text" id="addFather" class="form-control" placeholder="Full name of father"></div><div class="form-group"><label>Mother's Name</label><input type="text" id="addMother" class="form-control" placeholder="Full name of mother"></div></div>
<div class="form-row"><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" id="addEmail" class="form-control" placeholder="student@school.edu.ph" required></div><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="addContact" class="form-control" placeholder="09XXXXXXXXX" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="addAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course <span style="color:#dc2626;">*</span></label><div class="course-select-wrap"><select id="addCourse" class="form-control" required><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level</label><select id="addYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="addMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="addMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="addSchoolYear" class="form-control" placeholder="2026-2027" value="2026-2027"></div><div class="form-group"><label>Semester</label><select id="addSemester" class="form-control"><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div><div class="form-group"><label>Section <button type="button" style="background:none;border:none;color:#2563eb;cursor:pointer;font-size:11px;padding:0;" onclick="suggestSection()"><i class="fas fa-magic"></i> Suggest</button></label><input type="text" id="addSection" class="form-control" placeholder="e.g. 11001"></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-school"></i> Previous School</div>
<div class="form-row"><div class="form-group"><label>Name of Previous School</label><input type="text" id="addPrevSchool" class="form-control" placeholder="Enter previous school name"></div></div>
<div class="form-row"><div class="form-group"><label>School Year Graduated</label><input type="text" id="addSYGraduated" class="form-control" placeholder="e.g. 2025-2026"></div><div class="form-group"><label>Last Year Level Completed</label><input type="text" id="addLastYearCompleted" class="form-control" placeholder="e.g. Grade 12"></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian / Parent</div>
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="addGuardianName" class="form-control" required></div><div class="form-group"><label>Relationship</label><select id="addGuardianRel" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="addGuardianContact" class="form-control" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div><div class="form-group"><label>Email (optional)</label><input type="email" id="addGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAddModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enroll</button></div></form></div></div>

<!-- Auto-created Student Portal Account Modal -->
<div class="modal-overlay" id="acctModal"><div class="modal-content" style="max-width:520px;"><div class="modal-header"><h2><i class="fas fa-user-graduate"></i> Student Portal Account Created</h2><button class="modal-close" onclick="closeAcctModal()"><i class="fas fa-times"></i></button></div><div class="modal-body" style="padding:20px;">
<p style="font-size:13px;color:#64748b;margin-bottom:16px;">A student portal account was automatically created. Share these credentials with the student so they can log in to the <strong>Student Portal</strong>. They can change the password after first login.</p>
<div id="acctEmailNote" style="display:none;background:#fef3c7;border:1px solid #fde047;color:#92400e;border-radius:8px;padding:10px 12px;font-size:12px;margin-bottom:14px;"><i class="fas fa-triangle-exclamation"></i> The welcome email could not be sent (SMTP not configured) — please share the credentials below with the student manually.</div>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
<div class="form-group" style="margin-bottom:12px;"><label>Username</label><div style="display:flex;gap:8px;align-items:center;"><code id="acctEmail" style="flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;"></code><button type="button" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="copyAcct('acctEmail')">Copy</button></div></div>
<div class="form-group" style="margin-bottom:12px;"><label>Temporary Password</label><div style="display:flex;gap:8px;align-items:center;"><code id="acctPassword" style="flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;"></code><button type="button" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="copyAcct('acctPassword')">Copy</button></div></div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAcctModal()" style="margin-right:auto;border:none;background:none;color:#94a3b8;">Don't show again</button><button type="button" class="btn btn-primary" onclick="closeAcctModal()"><i class="fas fa-check"></i> Done</button></div></div></div>

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
<div class="form-row"><div class="form-group" style="flex:0 0 160px;"><label>Student ID (Enrollment Dept)</label><input type="text" id="editStudentNumber" class="form-control" placeholder="Assigned by enrollment" style="font-size:12px;"></div><div class="form-group"><label>Academic Status</label><select id="editStatus" class="form-control"><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="editMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="editLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="editSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN</label><input type="text" id="editLrn" class="form-control" placeholder="12-digit LRN" maxlength="12"></div><div class="form-group"><label>Gender</label><select id="editGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="editCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="editBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="editBirthPlace" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="editNationality" class="form-control"></div><div class="form-group"><label>Religion</label><input type="text" id="editReligion" class="form-control"></div><div class="form-group"><label>Father's Name</label><input type="text" id="editFather" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Mother's Name</label><input type="text" id="editMother" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" id="editEmail" class="form-control" required></div><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="editContact" class="form-control" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="editAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course</label><div class="course-select-wrap"><select id="editCourse" class="form-control"><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level</label><select id="editYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="editMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="editMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="editSchoolYear" class="form-control" placeholder="2026-2027"></div><div class="form-group"><label>Semester</label><select id="editSemester" class="form-control"><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-school"></i> Previous School</div>
<div class="form-row"><div class="form-group"><label>Name of Previous School</label><input type="text" id="editPrevSchool" class="form-control" placeholder="Enter previous school name"></div></div>
<div class="form-row"><div class="form-group"><label>School Year Graduated</label><input type="text" id="editSYGraduated" class="form-control" placeholder="e.g. 2025-2026"></div><div class="form-group"><label>Last Year Level Completed</label><input type="text" id="editLastYearCompleted" class="form-control" placeholder="e.g. Grade 12"></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian</div>
<div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" id="editGuardianName" class="form-control"></div><div class="form-group"><label>Relationship</label><select id="editGuardianRel" class="form-control"><option value="">Select</option><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="editGuardianContact" class="form-control" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div><div class="form-group"><label>Email</label><input type="email" id="editGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeEditModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Receive Student Modal (enrollment intake) -->
<div class="modal-overlay" id="receiveModal"><div class="modal-content" style="max-width:900px;"><div class="modal-header"><h2><i class="fas fa-inbox"></i> Receive Student</h2><button class="modal-close" onclick="closeReceiveModal()"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Applicants from the Enrollment System. Run a <strong>Duplication Check</strong> first, then <strong>Accept</strong> (or <strong>Re-enroll</strong> for returning students).</p>
<div id="receiveTableWrap">
<p style="text-align:center;color:#94a3b8;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Loading applicants...</p>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="loadEnrollments()"><i class="fas fa-rotate"></i> Refresh</button><button class="btn btn-light" onclick="closeReceiveModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<script>
// ─── DATA ────────────────────────────────────────────────────
const searchInput = document.getElementById('studentSearch');
const searchClear = document.getElementById('searchClear');
const tableBody = document.getElementById('studentTableBody');
const showingCount = document.getElementById('showingCount');
const totalCount = document.getElementById('totalCount');
let allStudents = [];

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
    const emptyState = document.getElementById('emptyState');
    const tblWrap = document.getElementById('studentTableWrap');
    if (visible === 0) {
        if (tblWrap) tblWrap.querySelector('table').style.display = 'none';
        if (emptyState) {
            emptyState.style.display = 'flex';
            emptyState.querySelector('p').textContent = 'No students found';
            emptyState.querySelector('span').textContent = (allStudents.length > 0) ? 'Try adjusting search or filters' : 'Add your first student to get started';
            emptyState.querySelector('i').className = (allStudents.length > 0) ? 'fas fa-search' : 'fas fa-user-graduate';
        }
    } else {
        if (tblWrap) tblWrap.querySelector('table').style.display = '';
        if (emptyState) emptyState.style.display = 'none';
    }
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

// ─── FILTER MODAL ────────────────────────────────────────────
document.getElementById('filterToggle').addEventListener('click', () => { document.getElementById('filterModal').classList.add('active'); document.body.style.overflow = 'hidden'; });
function closeFilterModal() { document.getElementById('filterModal').classList.remove('active'); document.body.style.overflow = ''; }
function applyFilters() { performSearch(); closeFilterModal(); }
function clearFilters() { document.getElementById('filterStatus').value = ''; document.getElementById('filterYear').value = ''; document.getElementById('filterCourse').value = ''; document.getElementById('filterSection').value = ''; performSearch(); }
document.getElementById('filterModal').addEventListener('click', function(e) { if (e.target === this) closeFilterModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeFilterModal(); closeViewModal(); closeEditModal(); }});

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
    if (!action) { showToast('Select an action first.', 'warning'); return; }
    const ids = Array.from(document.querySelectorAll('.student-cb:checked')).map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm('Change status of ' + ids.length + ' student(s) to "' + action + '"?')) return;
    fetch('../api/students.php?action=bulk-status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, status: action })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Network error.', 'error'));
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
        document.getElementById('vStudentId').textContent = s.student_number || 'ID not yet assigned';
        document.getElementById('vDbId').textContent = 'Record #' + s.id;
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vCivilStatus').textContent = s.civil_status||'—';
        document.getElementById('vBirthDate').textContent = s.birth_date?new Date(s.birth_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}):'—';
        document.getElementById('vBirthPlace').textContent = s.place_of_birth||'—';
        document.getElementById('vNationality').textContent = s.nationality||'—';
        document.getElementById('vReligion').textContent = s.religion||'—';
        document.getElementById('vEmail').textContent = s.email||'—';
        document.getElementById('vContact').textContent = s.contact_number||'—';
        document.getElementById('vAddress').textContent = s.address||'—';
        // Previous school info
        document.getElementById('vPrevSchool').textContent = s.previous_school||'—';
        document.getElementById('vSYGraduated').textContent = s.school_year_graduated||'—';
        document.getElementById('vLastYearCompleted').textContent = s.last_year_level_completed||'—';
        // Family
        document.getElementById('vFather').textContent = s.father_name || '—';
        document.getElementById('vMother').textContent = s.mother_name || '—';
        // Guardian + Emergency Contact
        fetch('../api/students.php?action=guardians&student_id='+s.id).then(r=>r.json()).then(gd=>{
            if(gd.success&&gd.data){
                const primary = gd.data.find(g=>g.is_primary)||gd.data[0];
                const emergency = gd.data.find(g=>g.is_emergency);
                if(primary){
                    document.getElementById('vGuardianName').textContent = primary.full_name||'—';
                    document.getElementById('vGuardianRel').textContent = primary.relationship||'—';
                    document.getElementById('vGuardianAddress').textContent = primary.address||'—';
                    document.getElementById('vGuardianContact').textContent = primary.contact_number||'—';
                    document.getElementById('vGuardianEmail').textContent = primary.email||'—';
                }
                if(emergency){
                    document.getElementById('vEmergencyName').textContent = emergency.full_name||'—';
                    document.getElementById('vEmergencyRel').textContent = emergency.relationship||'—';
                    document.getElementById('vEmergencyAddress').textContent = emergency.address||'—';
                    document.getElementById('vEmergencyContact').textContent = emergency.contact_number||'—';
                }
            }
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
    }).catch(() => showToast('Failed to load.', 'error'));
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
    .then(r=>r.json()).then(d=>{ if(d.success) window.location.reload(); else showToast(d.message || 'Upload failed.', 'error'); })
    .catch(()=>showToast('Upload failed.', 'error'));
}
async function resendWelcomeEmail() {
    if (!currentViewId) return;
    const btn = document.getElementById('resendWelcomeBtn');
    if (!confirm('Resend the portal welcome email? This resets the temporary password.')) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...'; }
    try {
        const res = await fetch('../api/students.php?action=resend_welcome_email', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ student_id: currentViewId }) });
        const d = await res.json();
        if (d.success) showToast(d.message || 'Welcome email sent.', 'success');
        else showToast(d.message || 'Could not send the email.', 'error');
    } catch (e) { showToast('Network error.', 'error'); }
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Resend Welcome Email'; }
}

function closeViewModal() { viewModal.classList.remove('active'); document.body.style.overflow = ''; }
viewModal.addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

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
        document.getElementById('editPrevSchool').value = s.previous_school || '';
        document.getElementById('editSYGraduated').value = s.school_year_graduated || '';
        document.getElementById('editLastYearCompleted').value = s.last_year_level_completed || '';
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
    }).catch(() => showToast('Failed to load.', 'error'));
}
function closeEditModal() { document.getElementById('editModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const ec = document.getElementById('editContact').value;
    if (!ph11(ec)) { showToast('Student contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const egn = document.getElementById('editGuardianName').value.trim();
    if (egn !== '' && !ph11(document.getElementById('editGuardianContact').value)) { showToast('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const ee = document.getElementById('editEmail').value.trim();
    if (!ee || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ee)) { showToast('Email is required and must be a valid address.', 'warning'); return; }
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
                previous_school: document.getElementById('editPrevSchool').value,
                school_year_graduated: document.getElementById('editSYGraduated').value,
                last_year_level_completed: document.getElementById('editLastYearCompleted').value,
                guardian_name: document.getElementById('editGuardianName').value,
                guardian_relationship: document.getElementById('editGuardianRel').value,
                guardian_contact: document.getElementById('editGuardianContact').value,
                guardian_email: document.getElementById('editGuardianEmail').value,
                student_number: document.getElementById('editStudentNumber').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Student record updated.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { showToast(d.message || 'Failed to update.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    } catch(e) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
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

function ph11(v) {
    let d = String(v || '').replace(/\D/g, '');
    if (d.length === 12 && d.startsWith('63')) d = '0' + d.slice(2);
    if (d.length === 13 && d.startsWith('63')) d = '0' + d.slice(2);
    return /^09\d{9}$/.test(d);
}

document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const ac = document.getElementById('addContact').value;
    if (!ph11(ac)) { showToast('Student contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const agn = document.getElementById('addGuardianName').value.trim();
    if (agn !== '' && !ph11(document.getElementById('addGuardianContact').value)) { showToast('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const ae = document.getElementById('addEmail').value.trim();
    if (!ae || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ae)) { showToast('Email is required and must be a valid address.', 'warning'); return; }
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
                previous_school: document.getElementById('addPrevSchool').value,
                school_year_graduated: document.getElementById('addSYGraduated').value,
                last_year_level_completed: document.getElementById('addLastYearCompleted').value,
                guardian_name: document.getElementById('addGuardianName').value,
                guardian_relationship: document.getElementById('addGuardianRel').value,
                guardian_contact: document.getElementById('addGuardianContact').value,
                guardian_email: document.getElementById('addGuardianEmail').value
            })
        });
        const d = await res.json();
        if (d.success) {
            const acct = d.data && d.data.portal_account;
            if (acct) {
                // Show portal credentials in a modal so the registrar can
                // hand them to the student before reloading the page.
                document.getElementById('acctEmail').textContent = acct.username || acct.email;
                document.getElementById('acctPassword').textContent = acct.password;
                document.getElementById('acctEmailNote').style.display = acct.email_sent ? 'none' : '';
                document.getElementById('acctModal').classList.add('active');
                document.getElementById('addModal').classList.remove('active');
            } else {
                showToast('Student created successfully.', 'success');
                setTimeout(() => window.location.reload(), 800);
            }
        }
        else { showToast(d.message || 'Failed to add student.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
    } catch(e) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
});

// ─── AUTO PORTAL ACCOUNT MODAL ───────────────────────────────
function closeAcctModal() {
    document.getElementById('acctModal').classList.remove('active');
    setTimeout(() => window.location.reload(), 300);
}
function copyAcct(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).catch(() => {});
}

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
    if (!hasFile && !text) { showToast('Upload a file or paste some text first.', 'warning'); return; }
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
        if (!d.success) { showToast(d.message || 'Extraction failed.', 'error'); return; }
        pasteData = d.data || {};
        const keys = ['first_name','middle_name','last_name','gender','birth_date','place_of_birth','nationality','religion','email','contact_number','address','course','year_level','guardian_name','guardian_relationship','previous_school','school_year_graduated','last_year_level_completed'];
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
    } catch(e) { showToast('Extraction error: ' + e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic"></i> Extract'; }
}

function applyPaste() {
    if (!pasteData) return;
    const map = {
        first_name: 'addFirstName', middle_name: 'addMiddleName', last_name: 'addLastName',
        gender: 'addGender', birth_date: 'addBirthDate', place_of_birth: 'addBirthPlace',
        nationality: 'addNationality', religion: 'addReligion', email: 'addEmail',
        contact_number: 'addContact', address: 'addAddress', course: 'addCourse',
        year_level: 'addYearLevel', guardian_name: 'addGuardianName', guardian_relationship: 'addGuardianRel',
        previous_school: 'addPrevSchool', school_year_graduated: 'addSYGraduated', last_year_level_completed: 'addLastYearCompleted'
    };
    for (const k in map) {
        const el = document.getElementById(map[k]);
        if (el && pasteData[k] !== undefined && pasteData[k] !== null && pasteData[k] !== '') {
            el.value = pasteData[k];
        }
    }
    refreshMajorOptions('add');
    closePasteModal();
    showToast('Form pre-filled from extracted data.', 'success');
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
    }).then(() => { showToast('Course standardized.', 'success'); openQualityPanel(true); }).catch(() => showToast('Error applying.', 'error'));
}
function applyStdById(id, to, btn) {
    if (!confirm('Apply course change?')) return;
    btn.disabled = true;
    aiToolsPost('apply_std', { id, to }).then(d => {
        if (d.success) { showToast('Course updated.', 'success'); btn.parentElement.remove(); }
        else { showToast(d.message || 'Failed.', 'error'); btn.disabled = false; }
    }).catch(() => { showToast('Error.', 'error'); btn.disabled = false; });
}

// ─── MERGE DUPLICATES ───────────────────────────────────────
function mergeDupes(idA, idB, btn) {
    const which = confirm('Merge these duplicates?\n\nKeep A (record ' + idA + ') and remove B (record ' + idB + ')?\n\nClick OK to keep the FIRST record, or Cancel to keep the SECOND.');
    const keeperId = which ? idA : idB;
    const removeId = which ? idB : idA;
    if (!confirm('Keep record ' + keeperId + ' and delete record ' + removeId + '? This moves all related records (documents, guardians, RFID, etc.) to the keeper. This cannot be undone.')) return;
    btn.disabled = true;
    aiToolsPost('merge', { keeper_id: keeperId, remove_id: removeId }).then(d => {
        if (d.success) { showToast('Records merged.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { showToast(d.message || 'Merge failed.', 'error'); btn.disabled = false; }
    }).catch(() => { showToast('Merge error.', 'error'); btn.disabled = false; });
}

// ─── SECTION SUGGESTION ─────────────────────────────────────
function suggestSection() {
    const course = document.getElementById('addCourse').value;
    const year = document.getElementById('addYearLevel').value;
    const sem = document.getElementById('addSemester').value;
    if (!course || !year) { showToast('Choose a course and year level first.', 'warning'); return; }
    const btn = event.target.closest('button');
    if (btn) btn.disabled = true;
    aiPost('suggest_section', { course, year_level: year, semester: sem }).then(d => {
        if (d.success && d.data && d.data.suggestion) {
            document.getElementById('addSection').value = d.data.suggestion;
            showToast('Section ' + d.data.suggestion, 'success');
        } else {
            showToast(d.message || 'Could not suggest a section.', 'error');
        }
    }).catch(() => showToast('Error suggesting section.', 'error'))
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
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Error.', 'error'));
}


// ─── RESTORE ─────────────────────────────────────────────────
function restoreStudent(id, name) {
    if (!confirm('Restore ' + name + '?')) return;
    fetch('../api/students.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: 'active' }) })
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Error.', 'error'));
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

function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function fmtDate(v) {
    if (!v) return '—';
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return '—';
    const d = new Date(+m[1], +m[2] - 1, +m[3]);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric'});
}

// ─── RECEIVE STUDENT MODAL (enrollment intake) ──────────────
const receiveModal = document.getElementById('receiveModal');
const receiveWrap = document.getElementById('receiveTableWrap');
if (receiveModal) receiveModal.addEventListener('click', function(e) { if (e.target === this) closeReceiveModal(); });

function openReceiveModal() {
    receiveModal.classList.add('active');
    document.body.style.overflow = 'hidden';
    loadEnrollments();
}
function closeReceiveModal() {
    receiveModal.classList.remove('active');
    document.body.style.overflow = '';
}

async function enrollApi(action, body) {
    const res = await fetch('../api/enrollments.php?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    if (!res.ok) return { success: false, message: 'Server error (' + res.status + '). Please try again.' };
    return await res.json().catch(() => ({ success: false, message: 'Unexpected response from the server.' }));
}

async function loadEnrollments() {
    receiveWrap.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Loading applicants...</p>';
    try {
        const res = await fetch('../api/enrollments.php?action=list');
        const d = await res.json().catch(() => null);
        if (!d || typeof d.success !== 'boolean') {
            receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">Unexpected response from the server. Please try again.</p>';
            return;
        }
        if (!d.success) { receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">' + (d.message || 'Failed to load.') + '</p>'; return; }
        const rows = d.data || [];
        if (!rows.length) {
            receiveWrap.innerHTML = '<div class="empty-state" style="display:flex;flex-direction:column;align-items:center;padding:40px 20px;"><i class="fas fa-inbox"></i><p>No applicants from the Enrollment System</p><span>Applicants will appear here when the Enrollment System sends them.</span></div>';
            return;
        }
        let html = '<div class="table-responsive"><table><thead><tr><th>Name</th><th>Enrollment No.</th><th>Sex</th><th>Birth Date</th><th>Course</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead><tbody>';
        rows.forEach(e => {
            const name = esc([e.first_name, e.middle_name, e.last_name, e.name_suffix].filter(Boolean).join(' '));
            const bd = fmtDate(e.birth_date);
            const statusBadge = e.status === 'pending' ? '<span class="status-badge active"><span class="status-dot active"></span>Pending</span>'
                : '<span class="status-badge ' + e.status + '"><span class="status-dot ' + e.status + '"></span>' + ucfirst(e.status) + '</span>';
            html += '<tr data-enrollment=\'' + JSON.stringify({ id: e.id, first_name: e.first_name, last_name: e.last_name, birth_date: e.birth_date, student_number: e.student_number }).replace(/'/g, '&#39;') + '\'>';
            html += '<td style="font-weight:600;color:#0f172a;font-size:13px;">' + name + '</td>';
            html += '<td style="font-family:\'JetBrains Mono\',monospace;font-size:12px;">' + esc(e.student_number || '—') + '</td>';
            html += '<td>' + esc(e.gender || '—') + '</td>';
            html += '<td>' + bd + '</td>';
            html += '<td>' + esc(e.course || '—') + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td style="text-align:center;"><div class="action-group" style="justify-content:center;flex-wrap:wrap;gap:4px;">';
            html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;" onclick="checkDuplicate(' + e.id + ')"><i class="fas fa-clone"></i> Duplicate Check</button>';
            if (e.status === 'pending') {
                html += '<button class="btn btn-primary" style="height:30px;padding:0 14px;font-size:11px;display:none;" id="acceptBtn_' + e.id + '" onclick="acceptEnrollment(' + e.id + ')"><i class="fas fa-check"></i> Accept</button>';
                html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;display:none;" id="reEnrollBtn_' + e.id + '" onclick="reenrollEnrollment(' + e.id + ')"><i class="fas fa-rotate"></i> Re-enroll</button>';
                html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;display:none;" id="viewDupBtn_' + e.id + '" onclick="viewDuplicate(' + e.id + ')"><i class="fas fa-eye"></i> View Existing</button>';
                html += '<div id="dupStatus_' + e.id + '" style="width:100%;margin-top:4px;font-size:11px;color:#94a3b8;"><i class="fas fa-circle-info"></i> Not checked yet</div>';
            }
            html += '</div></td></tr>';
        });
        html += '</tbody></table></div>';
        receiveWrap.innerHTML = html;
    } catch (err) {
        receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">Failed to load applicants.</p>';
    }
}

// Dup-check state cache: enrollment_id → {exists, student}
const dupState = {};
async function checkDuplicate(id) {
    const btn = event && event.target && event.target.tagName === 'BUTTON' ? event.target : (event && event.currentTarget);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...'; }
    try {
        const d = await enrollApi('duplicate-check', { enrollment_id: id });
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clone"></i> Duplicate Check'; }
        if (!d.success) { showToast(d.message || 'Duplicate check failed.', 'error'); return; }
        dupState[id] = d;
        const reBtn = document.getElementById('reEnrollBtn_' + id);
        const viewBtn = document.getElementById('viewDupBtn_' + id);
        const acceptBtn = document.getElementById('acceptBtn_' + id);
        const statusEl = document.getElementById('dupStatus_' + id);
        if (d.exists) {
            showToast('Student already exists.', 'info');
            if (reBtn) reBtn.style.display = 'inline-flex';
            if (viewBtn) viewBtn.style.display = 'inline-flex';
            if (acceptBtn) acceptBtn.style.display = 'none';
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> <span style="color:#f59e0b;font-weight:600;">Duplicate found</span> — returning student';
        } else {
            showToast('No existing record.', 'success');
            if (reBtn) reBtn.style.display = 'none';
            if (viewBtn) viewBtn.style.display = 'none';
            if (acceptBtn) acceptBtn.style.display = 'inline-flex';
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> <span style="color:#10b981;font-weight:600;">No duplicate</span> — new student';
        }
    } catch (err) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clone"></i> Duplicate Check'; }
        showToast('Duplicate check failed.', 'error');
    }
}

async function acceptEnrollment(id) {
    if (!confirm('Accept this student into the registrar records?')) return;
    try {
        const d = await enrollApi('accept', { enrollment_id: id });
        if (!d.success) { showToast(d.message || 'Failed.', 'error'); return; }
        const num = d.data && (d.data.student_number || '');
        showToast('Student accepted' + (num ? ' — ' + num : '') + '.', 'success');
        loadEnrollments();
    } catch (err) {
        showToast('Failed to accept student.', 'error');
    }
}

async function reenrollEnrollment(id) {
    const st = dupState[id] && dupState[id].student;
    const studentId = st && st.id;
    if (!studentId) { showToast('No existing record selected. Run Duplicate Check first.', 'error'); return; }
    if (!confirm('Re-enroll this student with their existing student number?')) return;
    try {
        const d = await enrollApi('re-enroll', { enrollment_id: id, student_id: studentId });
        if (!d.success) { showToast(d.message || 'Failed.', 'error'); return; }
        showToast('Student re-enrolled successfully.', 'success');
        loadEnrollments();
    } catch (err) {
        showToast('Failed to re-enroll student.', 'error');
    }
}

function viewDuplicate(id) {
    const st = dupState[id] && dupState[id].student;
    if (st && st.id) viewStudent(st.id);
}

// ─── INIT ────────────────────────────────────────────────────
performSearch();
(function() {
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    if (!success) return;
    const msgs = { added: ['Student Added','Created successfully.'], updated: ['Student Updated','Record updated.'], archived: ['Student Deleted','Record archived.'] };
    if (msgs[success]) showToast(msgs[success][1], 'success');
    const url = new URL(window.location.href); url.searchParams.delete('success'); window.history.replaceState({}, '', url.toString());
})();
</script>
<?php include '../includes/footer.php'; ?>
