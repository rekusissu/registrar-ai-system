<?php
// ============================================================
//  REGISTRAR/STUDENTS.PHP
//  Student management — inline view/edit, bulk actions, RFID
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

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
.table-responsive tbody tr.deleted{opacity:.5;background:#f8fafc}

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
.status-badge.deleted{background:#f8fafc;color:#94a3b8}
.status-dot{width:6px;height:6px;border-radius:50%;display:inline-block}
.status-dot.active{background:#16a34a} .status-dot.probation{background:#b45309} .status-dot.at-risk{background:#dc2626}
.status-dot.graduated{background:#2563eb} .status-dot.loa{background:#7c3aed} .status-dot.transferred{background:#db2777}
.status-dot.dropped{background:#dc2626} .status-dot.deleted{background:#94a3b8}

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
.empty-state{text-align:center;padding:30px 20px;color:#94a3b8}
.empty-state i{font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px}
.empty-state p{font-size:15px;font-weight:500;color:#94a3b8}
.empty-state span{font-size:13px;color:#cbd5e1}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.15);animation:modalSlide .3s ease}
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
select.form-control{cursor:pointer;appearance:auto;-webkit-appearance:auto}

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
<tr><th style="width:30px;"><div class="cb-wrap"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></div></th><th>ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Gender</th><th>RFID</th><th>Status</th><th style="text-align:center;">Actions</th></tr>
</thead>
<tbody id="studentTableBody">
<?php if (empty($students)): ?>
<tr><td colspan="10" class="empty-state"><i class="fas fa-user-graduate"></i><p>No students found</p><span>Add your first student to get started</span></td></tr>
<?php else:
$avatarColors = ['blue','green','purple','orange','pink'];
foreach ($students as $i => $s):
$initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
$ac = $avatarColors[$i % count($avatarColors)];
$hasRfid = isset($rfidMap[$s['id']]);
$rfidStatus = $hasRfid && $rfidMap[$s['id']]['status'] === 'active' ? 'active' : ($hasRfid ? 'inactive' : 'none');
?>
<tr data-student='<?= htmlspecialchars(json_encode($s),ENT_QUOTES,'UTF-8') ?>' class="<?= $s['status']==='deleted'?'deleted':'' ?>">
<td><div class="cb-wrap"><input type="checkbox" class="student-cb" value="<?= (int)$s['id'] ?>" onchange="updateBulkBar()"></div></td>
<td class="student-id" style="font-weight:600;font-size:12px;"><?= htmlspecialchars($s['student_number']) ?></td>
<td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($s['first_name']." ".$s['last_name']) ?></div><div class="student-email"><?= htmlspecialchars($s['email'] ?? '') ?></div></div></div></td>
<td><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
<td><?= htmlspecialchars($s['section'] ?? '—') ?></td>
<td><?= htmlspecialchars(($s['gender'] ?? '') ?: '—') ?></td>
<td><a href="../registrar/rfid-cards.php?search=<?= urlencode($s['student_number']) ?>" class="rfid-chip <?= $rfidStatus ?>"><i class="fas fa-<?= $rfidStatus==='active'?'check-circle':'credit-card' ?>"></i> <?= $rfidStatus==='active'?($rfidMap[$s['id']]['card_uid']):($rfidStatus==='none'?'—':$rfidMap[$s['id']]['status']) ?></a></td>
<td><div class="quick-status-wrap"><button class="status-badge <?= $s['status']??'active' ?>" onclick="toggleQuickMenu(<?= (int)$s['id'] ?>)"><span class="status-dot <?= $s['status']??'active' ?>"></span><?= ucfirst($s['status']??'Active') ?></button><div class="quick-status-menu" id="qsm_<?= (int)$s['id'] ?>"><?php foreach(['active','probation','at-risk','graduated','loa','transferred','dropped'] as $st): ?><button onclick="quickStatus(<?= (int)$s['id'] ?>,'<?= $st ?>')" class="<?= ($s['status']??'active')===$st?'active':'' ?>"><?= ucfirst($st) ?></button><?php endforeach; ?></div></div></td>
<td><div class="action-group"><button class="action-btn view" onclick="viewStudent(<?= (int)$s['id'] ?>)" title="View"><i class="fas fa-eye"></i></button><button class="action-btn edit" onclick="editStudent(<?= (int)$s['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button><?php if ($s['status']==='deleted'): ?><button class="action-btn restore" onclick="restoreStudent(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['first_name']." ".$s['last_name'],ENT_QUOTES) ?>')" title="Restore"><i class="fas fa-undo"></i></button><?php else: ?><button class="action-btn delete" onclick="confirmDelete(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['first_name']." ".$s['last_name'],ENT_QUOTES) ?>')" title="Delete"><i class="fas fa-trash-alt"></i></button><?php endif; ?></div></td>
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
<div class="form-group"><label>Status</label><select id="filterStatus" class="form-control"><option value="">All Status</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option></select></div>
<div class="form-group"><label>Year Level</label><select id="filterYear" class="form-control"><option value="">All Year</option><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option></select></div>
<div class="form-group"><label>Course</label><select id="filterCourse" class="form-control"><option value="">All Courses</option><?php foreach($courses as $c): ?><option value="<?= htmlspecialchars($c['course']) ?>"><?= htmlspecialchars($c['course']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Section</label><input type="text" id="filterSection" class="form-control" placeholder="Enter section..." /></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="closeFilterModal()">Cancel</button><button class="btn btn-secondary" onclick="clearFilters()">Clear All</button><button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-check"></i> Apply</button></div>
</div>
</div>

<!-- View Modal (full profile) -->
<div class="modal-overlay" id="viewModal"><div class="modal-content" style="max-width:520px;"><div class="modal-header"><h2><i class="fas fa-id-card"></i> Student Profile</h2><button class="modal-close" onclick="closeViewModal()"><i class="fas fa-times"></i></button></div><div class="modal-body"><div class="view-profile"><div class="big-avatar blue" id="vAvatar">JD</div><div class="vp-name" id="vName">—</div><div class="vp-id" id="vStudentId">—</div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9">
<div class="view-item"><div class="lbl">Status</div><div class="val" id="vStatus">—</div></div>
<div class="view-item"><div class="lbl">Gender</div><div class="val" id="vGender">—</div></div>
<div class="view-item"><div class="lbl">Birth Date</div><div class="val" id="vBirthDate">—</div></div>
<div class="view-item"><div class="lbl">Place of Birth</div><div class="val" id="vBirthPlace">—</div></div>
<div class="view-item"><div class="lbl">Nationality</div><div class="val" id="vNationality">—</div></div>
<div class="view-item"><div class="lbl">Religion</div><div class="val" id="vReligion">—</div></div>
<div class="view-item"><div class="lbl">Course</div><div class="val" id="vCourse">—</div></div>
<div class="view-item"><div class="lbl">Year / Section</div><div class="val" id="vYearSection">—</div></div>
<div class="view-item"><div class="lbl">Email</div><div class="val" id="vEmail">—</div></div>
<div class="view-item"><div class="lbl">Contact</div><div class="val" id="vContact">—</div></div>
<div class="view-item" style="grid-column:span 2;"><div class="lbl">Address</div><div class="val" id="vAddress">—</div></div>
</div>
<div id="vRfidSection" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;text-align:center;"><a id="vRfidLink" href="#" class="btn btn-secondary"><i class="fas fa-credit-card"></i> View RFID Card</a> <a id="vScanLink" href="#" class="btn btn-secondary"><i class="fas fa-clock-rotate-left"></i> Scan Logs</a></div>
</div><div class="modal-footer"><button class="btn btn-primary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Edit Modal (full fields) -->
<div class="modal-overlay" id="editModal"><div class="modal-content" style="max-width:600px;"><div class="modal-header"><h2><i class="fas fa-pen"></i> Edit Student</h2><button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button></div><form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
<div class="form-row"><div class="form-group"><label>Student ID</label><input type="text" id="editStudentNumber" class="form-control" readonly style="background:#f8fafc;"></div><div class="form-group"><label>Status</label><select id="editStatus" class="form-control"><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="editMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="editLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Gender</label><select id="editGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="editBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="editBirthPlace" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="editNationality" class="form-control"></div><div class="form-group"><label>Religion</label><input type="text" id="editReligion" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Course</label><input type="text" id="editCourse" class="form-control"></div><div class="form-group"><label>Year Level</label><select id="editYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group"><label>Section</label><input type="text" id="editSection" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email</label><input type="email" id="editEmail" class="form-control"></div><div class="form-group"><label>Contact</label><input type="text" id="editContact" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="editAddress" class="form-control" rows="2" required></textarea></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeEditModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Add Modal (inline, full fields) -->
<div class="modal-overlay" id="addModal"><div class="modal-content" style="max-width:600px;"><div class="modal-header"><h2><i class="fas fa-plus-circle"></i> Add Student</h2><button class="modal-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button></div><form id="addForm"><div class="modal-body">
<div class="form-row"><div class="form-group" style="flex:0 0 160px;"><label>Student ID</label><input type="text" id="addStudentNumber" class="form-control" placeholder="Auto-generated" style="background:#f8fafc;" readonly></div><div class="form-group"><label>Status</label><select id="addStatus" class="form-control"><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="addMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="addLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Gender</label><select id="addGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="addBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="addBirthPlace" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="addNationality" class="form-control"></div><div class="form-group"><label>Religion</label><input type="text" id="addReligion" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Course</label><input type="text" id="addCourse" class="form-control"></div><div class="form-group"><label>Year Level</label><select id="addYearLevel" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group"><label>Section</label><input type="text" id="addSection" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email</label><input type="email" id="addEmail" class="form-control"></div><div class="form-group"><label>Contact</label><input type="text" id="addContact" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="addAddress" class="form-control" rows="2" required></textarea></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAddModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Student</button></div></form></div></div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal"><div class="modal-content" style="max-width:420px;text-align:center;"><div class="delete-icon"><i class="fas fa-trash-alt"></i></div><h3 style="font-size:19px;font-weight:700;color:#0f172a;margin-bottom:4px;">Delete Student</h3><p id="deleteMessage" style="color:#64748b;font-size:14px;margin-bottom:18px;">This cannot be undone.</p><div class="modal-footer" style="justify-content:center;"><button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button><button class="btn btn-danger" id="confirmDeleteBtn" style="background:#dc2626;color:white;border:none;padding:9px 18px;border-radius:10px;font-weight:600;cursor:pointer;"><i class="fas fa-trash-alt"></i> Delete</button></div></div></div>

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
function viewStudent(id) {
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        const name = s.first_name + ' ' + s.last_name;
        const initials = (s.first_name||'')[0] + (s.last_name||'')[0];
        const colors = ['blue','green','purple','orange','pink'];
        const c = colors[Math.abs((s.first_name||'a').charCodeAt(0)) % colors.length];
        document.getElementById('vAvatar').className = 'big-avatar ' + c;
        document.getElementById('vAvatar').textContent = initials.toUpperCase();
        document.getElementById('vName').textContent = name;
        document.getElementById('vStudentId').textContent = s.student_number;
        document.getElementById('vStatus').innerHTML = '<span class="status-badge ' + (s.status||'active') + '"><span class="status-dot ' + (s.status||'active') + '"></span>' + ucfirst(s.status||'Active') + '</span>';
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vBirthDate').textContent = s.birth_date ? new Date(s.birth_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : '—';
        document.getElementById('vBirthPlace').textContent = s.place_of_birth || '—';
        document.getElementById('vNationality').textContent = s.nationality || '—';
        document.getElementById('vReligion').textContent = s.religion || '—';
        document.getElementById('vCourse').textContent = s.course || '—';
        document.getElementById('vYearSection').textContent = (s.year_level?s.year_level+' Year':'') + (s.section?' — '+s.section:'');
        document.getElementById('vEmail').textContent = s.email || '—';
        document.getElementById('vContact').textContent = s.contact_number || '—';
        document.getElementById('vAddress').textContent = s.address || '—';
        const rfidSec = document.getElementById('vRfidSection');
        <?php if (!empty($rfidMap)): ?>
        const hasRfid = <?= json_encode(array_keys($rfidMap)) ?>.includes(String(s.id));
        <?php else: ?>
        const hasRfid = false;
        <?php endif; ?>
        if (hasRfid) { rfidSec.style.display = 'block'; document.getElementById('vRfidLink').href = 'rfid-cards.php?search='+encodeURIComponent(s.student_number); document.getElementById('vScanLink').href = 'rfid-scan-logs.php?search='+encodeURIComponent(s.student_number); }
        else rfidSec.style.display = 'none';
        viewModal.classList.add('active'); document.body.style.overflow = 'hidden';
    }).catch(() => alert('Failed to load.'));
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
        document.getElementById('editGender').value = s.gender || '';
        document.getElementById('editBirthDate').value = s.birth_date || '';
        document.getElementById('editBirthPlace').value = s.place_of_birth || '';
        document.getElementById('editNationality').value = s.nationality || '';
        document.getElementById('editReligion').value = s.religion || '';
        document.getElementById('editCourse').value = s.course || '';
        document.getElementById('editYearLevel').value = s.year_level || '';
        document.getElementById('editSection').value = s.section || '';
        document.getElementById('editEmail').value = s.email || '';
        document.getElementById('editContact').value = s.contact_number || '';
        document.getElementById('editAddress').value = s.address || '';
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
                gender: document.getElementById('editGender').value,
                birth_date: document.getElementById('editBirthDate').value,
                place_of_birth: document.getElementById('editBirthPlace').value,
                nationality: document.getElementById('editNationality').value,
                religion: document.getElementById('editReligion').value,
                status: document.getElementById('editStatus').value,
                course: document.getElementById('editCourse').value,
                year_level: document.getElementById('editYearLevel').value,
                section: document.getElementById('editSection').value,
                email: document.getElementById('editEmail').value,
                contact_number: document.getElementById('editContact').value,
                address: document.getElementById('editAddress').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Updated', 'Student record updated.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
});

// ─── ADD MODAL ───────────────────────────────────────────────
function openAddModal() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; document.getElementById('addForm').reset(); }
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
                gender: document.getElementById('addGender').value,
                birth_date: document.getElementById('addBirthDate').value,
                place_of_birth: document.getElementById('addBirthPlace').value,
                nationality: document.getElementById('addNationality').value,
                religion: document.getElementById('addReligion').value,
                status: document.getElementById('addStatus').value,
                course: document.getElementById('addCourse').value,
                year_level: document.getElementById('addYearLevel').value,
                section: document.getElementById('addSection').value,
                email: document.getElementById('addEmail').value,
                contact_number: document.getElementById('addContact').value,
                address: document.getElementById('addAddress').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Added', 'Student created successfully.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
});

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
    const msgs = { added: ['Student Added','Created successfully.'], updated: ['Student Updated','Record updated.'], deleted: ['Student Deleted','Record deleted.'] };
    if (msgs[success]) showToast(msgs[success][0], msgs[success][1], 'success');
    const url = new URL(window.location.href); url.searchParams.delete('success'); window.history.replaceState({}, '', url.toString());
})();
</script>
<?php include '../includes/footer.php'; ?>