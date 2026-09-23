<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student Status Tracker — AI-powered decision console.
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
$studentIds = array_map(fn($s) => (int) $s['id'], $students);

$page_title = 'Status Tracker';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'tracker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.st-wrap{padding:24px;max-width:1400px;margin:0 auto}
.st-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.st-kpi{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;align-items:flex-start;gap:14px}
.st-kpi-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.st-kpi-num{font-size:28px;font-weight:700;line-height:1.1}
.st-kpi-label{font-size:13px;color:#64748b;margin-top:2px}
.st-kpi-sub{font-size:12px;color:#94a3b8;margin-top:4px}
.st-delta{font-size:12px;font-weight:600;padding:2px 6px;border-radius:4px;display:inline-block;margin-left:6px}
.st-delta.up{color:#16a34a;background:#f0fdf4}
.st-delta.down{color:#dc2626;background:#fef2f2}
.st-ai-panel{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:24px;overflow:hidden}
.st-ai-hdr{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;border-bottom:1px solid #f1f5f9}
.st-ai-hdr h3{font-size:15px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
.st-ai-badge{background:#3b82f6;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600}
.st-ai-body{padding:16px 20px}
.st-ai-load{text-align:center;padding:24px;color:#94a3b8}
.st-rec-list{display:flex;flex-direction:column;gap:10px}
.st-rec{border:1px solid #e2e8f0;border-radius:8px;padding:14px;display:flex;gap:12px;align-items:flex-start;transition:opacity .3s}
.st-rec.sv-high{border-left:3px solid #dc2626}
.st-rec.sv-med{border-left:3px solid #f59e0b}
.st-rec.sv-low{border-left:3px solid #22c55e}
.st-rec-info{flex:1}
.st-rec-title{font-size:13px;color:#64748b;margin-bottom:2px}
.st-rec-name{font-size:14px;font-weight:600;color:#1e293b}
.st-rec-reason{font-size:12px;color:#64748b;margin-top:4px;line-height:1.4}
.st-rec-acts{display:flex;gap:8px;margin-top:8px}
.st-btn-apply{background:#3b82f6;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer;font-weight:500}
.st-btn-apply:hover{background:#2563eb}
.st-btn-apply.applying{opacity:.5;pointer-events:none}
.st-btn-apply.applied{background:#16a34a;pointer-events:none}
.st-btn-dismiss{background:transparent;color:#94a3b8;border:1px solid #e2e8f0;padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer}
.st-btn-dismiss:hover{color:#64748b;border-color:#cbd5e1}
.st-btn-dismiss.dismissed{opacity:.3;pointer-events:none}
.st-ai-src{font-size:11px;color:#94a3b8;margin-left:auto}
.st-apply-all{background:#1e293b;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;font-weight:500;margin-left:auto}
.st-apply-all:hover{background:#334155}
.st-anom{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:24px;display:none}
.st-anom-bar{display:flex;align-items:center;gap:8px;font-size:13px;color:#991b1b}
.st-dist{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:20px;margin-bottom:24px}
.st-dist-title{font-size:14px;font-weight:600;margin-bottom:12px}
.st-dist-bar{display:flex;height:12px;border-radius:6px;overflow:hidden;background:#f1f5f9}
.st-dist-seg{height:100%;transition:width .5s}
.st-dist-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:12px}
.st-dist-item{display:flex;align-items:center;gap:5px;font-size:12px;color:#64748b}
.st-dist-dot{width:8px;height:8px;border-radius:50%}
.st-filters{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.st-pill{padding:6px 14px;border-radius:20px;font-size:13px;font-weight:500;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;text-decoration:none;transition:all .2s}
.st-pill:hover,.st-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
.st-search{margin-left:auto;position:relative}
.st-search input{padding:7px 12px 7px 32px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;width:220px;outline:none}
.st-search input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.st-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px}
.st-panels{display:grid;grid-template-columns:1fr 340px;gap:24px}
.st-table-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.04)}
.st-table-wrap table{width:100%;border-collapse:collapse}
.st-table-wrap th{text-align:left;padding:10px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#64748b;background:#fafcfd;border-bottom:2px solid #e8edf4;white-space:nowrap}
.st-table-wrap td{padding:10px 14px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.st-table-wrap tbody tr{transition:background .15s ease}
.st-table-wrap tbody tr:hover{background:#f8fafc}
.st-table-wrap tbody tr:last-child td{border-bottom:none}
.st-table-wrap .st-t-info{display:flex;align-items:center;gap:10px}
.st-table-wrap .st-t-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;flex-shrink:0}
.st-table-wrap .st-t-name{font-weight:600;color:#0f172a;font-size:13px}
.st-table-wrap .st-t-num{font-size:11px;color:#94a3b8}
.st-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;gap:4px;white-space:nowrap;border:none}
.st-rdot{width:8px;height:8px;border-radius:50%;margin-left:6px;display:inline-block;flex-shrink:0}
.st-rdot.loading{background:#e2e8f0;animation:pulse 1s infinite}
.st-rdot.low{background:#22c55e}
.st-rdot.med{background:#f59e0b}
.st-rdot.high{background:#ef4444}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.st-tl{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:20px;max-height:calc(100vh - 220px);overflow-y:auto;position:sticky;top:80px}
.st-tl-h{font-size:14px;font-weight:600;margin-bottom:16px}
.st-tl-b{position:relative;padding-left:20px}
.st-tl-i{position:relative;padding-bottom:16px}
.st-tl-i:last-child{padding-bottom:0}
.st-tl-dot{position:absolute;left:-20px;top:4px;width:10px;height:10px;border-radius:50%;border:2px solid #fff}
.st-tl-nm{font-size:13px;font-weight:600;color:#1e293b}
.st-tl-chg{font-size:12px;color:#64748b;margin-top:2px}
.st-tl-time{font-size:11px;color:#94a3b8;margin-top:2px}
.st-tl-rsn{font-size:11px;color:#64748b;background:#f8fafc;padding:4px 8px;border-radius:4px;margin-top:4px;border:1px solid #f1f5f9}
.st-empty{text-align:center;padding:48px;color:#94a3b8}
.st-empty i{font-size:32px;margin-bottom:12px;display:block}
.st-modal-o{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);z-index:1000;display:none;align-items:center;justify-content:center}
.st-modal-o.show{display:flex}
.st-modal{background:#fff;border-radius:16px;width:560px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.st-modal-h{padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.st-modal-x{background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;padding:4px}
.st-modal-ai{background:#f8fafc;border-radius:8px;padding:14px;margin:16px 24px;border:1px solid #e2e8f0}
.st-modal-ai-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#3b82f6;font-weight:600;margin-bottom:6px}
.st-modal-ai-txt{font-size:13px;color:#475569;line-height:1.5}
.st-modal-ai-rec{font-size:12px;color:#16a34a;margin-top:6px;font-weight:500}
.st-modal-tl{padding:0 24px 24px}
.st-modal-empty{padding:24px;text-align:center;color:#94a3b8}
.st-toast{position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;z-index:9999;transform:translateY(80px);opacity:0;transition:all .3s;display:flex;align-items:center;gap:8px}
.st-toast.show{transform:translateY(0);opacity:1}
.st-toast.success{background:#16a34a}
.st-toast.error{background:#dc2626}
@media(max-width:1024px){.st-grid4{grid-template-columns:repeat(2,1fr)}.st-panels{grid-template-columns:1fr}.st-tl{position:static;max-height:none}}
@media(max-width:640px){.st-grid4{grid-template-columns:1fr}.st-filters{flex-direction:column;align-items:stretch}.st-search{margin-left:0}}
</style>
<main class="dashboard-main">
<header class="header">
  <div class="title">
    <h1><i class="fas fa-chart-line" style="color:#2563eb;margin-right:8px"></i>Status Tracker</h1>
    <p>AI-powered status monitoring, recommendations, and student risk assessment</p>
  </div>
</header>
<div class="st-wrap">
<!-- KPI Cards -->
<div class="st-grid4">
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-users"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($totalStudents) ?></div>
      <div class="st-kpi-label">Total Students</div>
      <div class="st-kpi-sub">All registered students</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-arrow-right-arrow-left"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesThisMonth) ?>
        <?php $delta = $changesThisMonth - $changesPrevMonth; if ($delta !== 0): ?>
          <span class="st-delta <?= $delta > 0 ? 'up' : 'down' ?>"><?= $delta > 0 ? '+' : '' ?><?= $delta ?></span>
        <?php endif; ?>
      </div>
      <div class="st-kpi-label">Changes This Month</div>
      <div class="st-kpi-sub">vs last month (<?= number_format($changesPrevMonth) ?>)</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-triangle-exclamation"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($attentionNeeded) ?></div>
      <div class="st-kpi-label">Attention Needed</div>
      <div class="st-kpi-sub">At-risk + Probation students</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-calendar-week"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesLast7d) ?></div>
      <div class="st-kpi-label">Last 7 Days</div>
      <div class="st-kpi-sub">Recent status changes</div>
    </div>
  </div>
</div>

<!-- AI Recommendations Panel -->
<div class="st-ai-panel" id="aiPanel">
  <div class="st-ai-hdr" onclick="toggleAI()">
    <h3><i class="fas fa-robot" style="color:#3b82f6"></i> AI Recommendations <span class="st-ai-badge" id="aiCount">0</span></h3>
    <div style="display:flex;align-items:center;gap:8px">
      <button class="st-apply-all" id="btnApplyAll" onclick="event.stopPropagation();applyAll()" style="display:none">Apply All</button>
      <i class="fas fa-chevron-down" id="aiChevron" style="color:#94a3b8;transition:transform .3s"></i>
    </div>
  </div>
  <div class="st-ai-body" id="aiBody">
    <div class="st-ai-load" id="aiLoad"><i class="fas fa-spinner fa-spin"></i> Loading AI recommendations...</div>
    <div class="st-rec-list" id="aiRecs" style="display:none"></div>
  </div>
</div>

<!-- Anomaly Alerts -->
<div class="st-anom" id="anomalyAlert">
  <div class="st-anom-bar"><i class="fas fa-bell"></i> <span id="anomalyText"></span></div>
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
    <a href="?status=<?= $s ?>" class="st-pill <?= $filterStatus === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <span style="opacity:.6;font-size:11px"><?= $counts[$s] ?></span></a>
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
          <tr><td colspan="8" class="st-empty" style="text-align:center;padding:48px;color:#94a3b8"><i class="fas fa-users-slash" style="font-size:32px;display:block;margin-bottom:12px"></i>No students found</td></tr>
        <?php else: ?>
          <?php $rowNum = 0; foreach ($students as $s):
            $rowNum++;
            $initials = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
            $sm = $STATUS_META[$s['status']] ?? $STATUS_META['inactive'];
          ?>
          <tr style="cursor:pointer" onclick="openStudentModal(<?= $s['id'] ?>,'<?= htmlspecialchars(addslashes($s['first_name'].' '.$s['last_name'])) ?>','<?= htmlspecialchars($s['student_number']) ?>')">
            <td style="font-weight:600;font-size:12px;color:#64748b"><?= $rowNum ?></td>
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
                <span style="width:6px;height:6px;border-radius:50%;display:inline-block;background:<?= $sm['color'] ?>"></span>
                <?= ucfirst($s['status']) ?>
              </div>
            </td>
            <td style="text-align:center">
              <span class="st-rdot loading" data-student-id="<?= $s['id'] ?>"></span>
            </td>
            <td style="font-size:12px;color:#64748b;white-space:nowrap">
              <?php if (!empty($s['last_change'])): ?>
                <?= date('M d, Y', strtotime($s['last_change'])) ?>
              <?php else: ?>
                <span style="color:#cbd5e1">—</span>
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
          <i class="fas fa-arrow-right" style="color:#94a3b8;font-size:10px;margin:0 4px"></i>
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
<div class="st-modal-o" id="studentModal">
  <div class="st-modal">
    <div class="st-modal-h">
      <div>
        <div id="modalName" style="font-size:16px;font-weight:600"></div>
        <div id="modalNumber" style="font-size:12px;color:#64748b;margin-top:2px"></div>
      </div>
      <button class="st-modal-x" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="st-modal-ai" id="modalAI">
      <div class="st-modal-ai-lbl"><i class="fas fa-robot"></i> AI Brief</div>
      <div class="st-modal-ai-txt" id="modalAIText">Loading...</div>
      <div class="st-modal-ai-rec" id="modalAIRec"></div>
    </div>
    <div class="st-modal-tl">
      <div style="font-size:14px;font-weight:600;margin-bottom:12px"><i class="fas fa-clock-rotate-left"></i> Status History</div>
      <div id="modalTimeline">
        <div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="st-toast" id="toast"></div>

<script>
(function(){
const STATUS_META=<?= json_encode($STATUS_META) ?>;
const ALL_STATUSES=<?= json_encode($ALL_STATUSES) ?>;
const DB_STATUSES=<?= json_encode($DB_STATUSES) ?>;
const STUDENT_IDS=<?= json_encode($studentIds) ?>;
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

/* --- AI Recommendations --- */
async function loadRecommendations(){
try{
const r=await fetch('../api/ai-tools.php?action=status_recommendations');
if(!r.ok)throw new Error('API error');
const data=await r.json();
renderRecs(data.data?.recommendations||data.recommendations||[]);
}catch(e){
document.getElementById('aiLoad').innerHTML='<span style="color:#94a3b8"><i class="fas fa-exclamation-circle"></i> Unable to load recommendations</span>';
}}

function renderRecs(recs){
const container=document.getElementById('aiRecs');
const load=document.getElementById('aiLoad');
const badge=document.getElementById('aiCount');
const btnAll=document.getElementById('btnApplyAll');
load.style.display='none';
container.style.display='flex';
container.innerHTML='';
badge.textContent=recs.length;
btnAll.style.display=recs.length>1?'inline-block':'none';
if(recs.length===0){
container.innerHTML='<div style="text-align:center;padding:16px;color:#94a3b8;font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No recommendations at this time</div>';
return;
}
recs.forEach((rec,i)=>{
const sv=rec.severity||'low';
const svClass=sv==='high'?'sv-high':sv==='med'?'sv-med':'sv-low';
const card=document.createElement('div');
card.className='st-rec '+svClass;
card.id='rec-'+i;
card.innerHTML='<div class="st-rec-info"><div class="st-rec-title">'+(rec.type||'Status Recommendation')+'</div><div class="st-rec-name">'+rec.student_name+' <span class="st-ai-src">'+(rec.student_number||'')+'</span></div><div class="st-rec-reason">'+rec.reason+'</div><div class="st-rec-acts"><button class="st-btn-apply" onclick="window._stApplyRec('+i+',\''+rec.student_id+'\',\''+rec.recommended_status+'\')">Apply</button><button class="st-btn-dismiss" onclick="window._stDismissRec('+i+')">Dismiss</button></div></div>';
container.appendChild(card);
});
window._stRecs=recs;
}

window._stApplyRec=async function(idx,studentId,status){
const btn=document.querySelector('#rec-'+idx+' .st-btn-apply');
btn.textContent='Applying...';
btn.classList.add('applying');
try{
const r=await fetch('../api/students.php?action=bulk-status',{
method:'POST',headers:{'Content-Type':'application/json'},
body:JSON.stringify({ids:[parseInt(studentId)],status:status})
});
if(r.ok){
btn.textContent='Applied';
btn.classList.remove('applying');
btn.classList.add('applied');
toast('Status updated to '+status,'success');
setTimeout(()=>location.reload(),1500);
}else{throw new Error();}
}catch(e){
btn.textContent='Apply';
btn.classList.remove('applying');
toast('Failed to update status','error');
}};

window._stDismissRec=function(idx){
const card=document.getElementById('rec-'+idx);
if(card){card.style.opacity='0';setTimeout(()=>card.remove(),300);}
const badge=document.getElementById('aiCount');
const remaining=document.querySelectorAll('.st-rec').length-1;
badge.textContent=Math.max(0,remaining);
if(remaining<=0)document.getElementById('btnApplyAll').style.display='none';
};

window.applyAll=async function(){
const btn=document.getElementById('btnApplyAll');
btn.textContent='Applying...';
btn.disabled=true;
const cards=document.querySelectorAll('.st-rec .st-btn-apply:not(.applied):not(.applying)');
for(const btnApply of cards){btnApply.click();await new Promise(r=>setTimeout(r,300));}
setTimeout(()=>location.reload(),2000);
};

/* --- Toggle AI Panel --- */
window.toggleAI=function(){
const body=document.getElementById('aiBody');
const chevron=document.getElementById('aiChevron');
const isHidden=body.style.display==='none';
body.style.display=isHidden?'block':'none';
chevron.style.transform=isHidden?'rotate(180deg)':'rotate(0)';
};

/* --- Anomaly Alerts --- */
async function loadAnomalies(){
try{
const r=await fetch('../api/ai-tools.php?action=status_anomalies');
if(!r.ok)return;
const data=await r.json();
const anom=data.data?.anomalies||data.anomalies||[];
if(anom.length>0){
const el=document.getElementById('anomalyAlert');
const txt=document.getElementById('anomalyText');
txt.textContent=anom.length+' anomal'+(anom.length>1?'ies':'y')+' detected: '+anom.map(a=>a.label||a.message||a).join('; ');
el.style.display='block';
}}catch(e){}
}

/* --- Student Risk Dots --- */
async function loadRisks(){
if(!STUDENT_IDS.length)return;
try{
const r=await fetch('../api/ai-tools.php?action=student_risks',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({student_ids:STUDENT_IDS.slice(0,20)})});
if(!r.ok)return;
const data=await r.json();
const risks=data.data?.risks||data.risks||{};
if(typeof risks==='object'){
Object.keys(risks).forEach(id=>{
const risk=risks[id];
const dot=document.querySelector('.st-rdot[data-student-id="'+id+'"]');
if(dot){
const level=risk.risk||'low';
dot.className='st-rdot '+(level==='high'?'high':level==='medium'?'med':'low');
dot.title=risk.reason||level;
}});

document.querySelectorAll('.st-rdot.loading').forEach(dot=>{dot.classList.remove('loading');dot.classList.add('unk');});
}catch(e){
document.querySelectorAll('.st-rdot.loading').forEach(dot=>{dot.classList.remove('loading');dot.classList.add('unk');});
}}

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
document.getElementById('modalName').textContent=name;
document.getElementById('modalNumber').textContent=number;
document.getElementById('modalAIText').textContent='Loading...';
document.getElementById('modalAIRec').textContent='';
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
const brief=d.data?.summary||d.ai_brief||d.aiBrief||'No AI brief available for this student.';
document.getElementById('modalAIText').textContent=brief;
const rec=d.ai_recommendation||d.aiRecommendation||'';
document.getElementById('modalAIRec').textContent=rec?'Recommendation: '+rec:'';
}catch(e){
document.getElementById('modalAIText').textContent='Unable to load AI brief.';
}}

async function fetchStudentHistory(id){
try{
const r=await fetch('../api/status-history.php?student_id='+id);
if(!r.ok)throw new Error();
const d=await r.json();
const history=d.data||d.history||[];
const container=document.getElementById('modalTimeline');
if(history.length===0){
container.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> No status history</div>';
return;
}
let html='';
history.forEach(h=>{
const meta=STATUS_META[h.current_status]||STATUS_META['inactive'];
const prevMeta=STATUS_META[h.previous_status]||STATUS_META['inactive'];
html+='<div class="st-tl-i"><div class="st-tl-dot" style="background:'+meta.color+'"></div><div class="st-tl-chg">';
html+='<span class="st-badge" style="background:'+prevMeta.bg+';color:'+prevMeta.color+';padding:2px 6px;font-size:10px">'+(h.previous_status||'N/A')+'</span>';
html+=' <i class="fas fa-arrow-right" style="color:#94a3b8;font-size:10px"></i> ';
html+='<span class="st-badge" style="background:'+meta.bg+';color:'+meta.color+';padding:2px 6px;font-size:10px">'+h.current_status+'</span>';
html+='</div>';
if(h.reason)html+='<div class="st-tl-rsn">'+h.reason+'</div>';
html+='<div class="st-tl-time">'+(h.changed_by_name||'System')+' &middot; '+new Date(h.created_at).toLocaleString()+'</div></div>';
});
container.innerHTML=html;
}catch(e){
document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Failed to load history</div>';
}}

window.closeModal=function(){
const modal=document.getElementById('studentModal');
modal.classList.remove('show');
document.body.style.overflow='';
};

document.getElementById('studentModal').addEventListener('click',function(e){
if(e.target===this)closeModal();
});

/* --- Toast --- */
window.toast=function(msg,type){
const el=document.getElementById('toast');
el.textContent=msg;
el.className='st-toast '+(type||'')+' show';
setTimeout(()=>el.classList.remove('show'),3000);
};

/* --- Init --- */
loadRecommendations();
loadAnomalies();
loadRisks();

})();
</script>