<?php
// ============================================================
//  REGISTRAR/STUDENTS.PHP
//  Student list with merged table + search, modal filter
//  FULLY RESPONSIVE & MOBILE-ADAPTIVE
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Fetch students
$students = $db->fetchAll("SELECT * FROM students ORDER BY id DESC");

// Stats
$totalStudents = count($students);
$activeStudents = count(array_filter($students, fn($s) => $s['status'] === 'active'));
$atRiskStudents = count(array_filter($students, fn($s) => $s['status'] === 'at-risk' || $s['status'] === 'probation'));
$graduatedStudents = count(array_filter($students, fn($s) => $s['status'] === 'graduated'));

// Courses for filter
$courses = $db->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");

$page_title = 'Students';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* ============================================================
   STUDENTS PAGE STYLES - FULLY FIXED
   ============================================================ */

/* ── CSS Variables ── */
:root {
    --sidebar-width: 260px;
    --sidebar-collapsed-width: 72px;
}

/* ── Dashboard Main ── */
.dashboard-main {
    margin-left: var(--sidebar-width);
    padding: 24px 32px;
    min-height: 100vh;
    width: calc(100% - var(--sidebar-width));
    max-width: calc(100% - var(--sidebar-width));
    overflow-x: hidden;
    transition: margin-left 0.3s ease, width 0.3s ease, max-width 0.3s ease;
}

/* ── Sidebar Collapsed State ── */
.sidebar.collapsed ~ .dashboard-main,
body.sidebar-collapsed .dashboard-main {
    margin-left: var(--sidebar-collapsed-width);
    width: calc(100% - var(--sidebar-collapsed-width));
    max-width: calc(100% - var(--sidebar-collapsed-width));
}

/* ── Stats ── */
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card-premium {
    background: white;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

.stat-card-premium:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
    border-color: #d8dde4;
}

.stat-card-premium .stat-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.stat-card-premium .stat-icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}

.stat-icon-wrap.blue { background: #eef4ff; color: #2563eb; }
.stat-icon-wrap.green { background: #dcfce7; color: #16a34a; }
.stat-icon-wrap.yellow { background: #fef3c7; color: #b45309; }
.stat-icon-wrap.purple { background: #f3e8ff; color: #7c3aed; }

.stat-card-premium .stat-trend {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.stat-trend.up { color: #16a34a; background: #dcfce7; }
.stat-trend.down { color: #dc2626; background: #fee2e2; }
.stat-trend.neutral { color: #64748b; background: #f1f5f9; }

.stat-card-premium .stat-number {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
}

.stat-card-premium .stat-label {
    color: #64748b;
    font-size: 13px;
    margin-top: 1px;
}

/* ── Search Table Container ── */
.search-table-container {
    background: white;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

/* ── Search Bar ── */
.search-bar {
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    row-gap: 10px;
}

.search-bar .search-wrapper {
    flex: 1 1 320px;
    min-width: 240px;
    max-width: 100%;
    position: relative;
    display: flex;
    align-items: center;
    height: 40px;
}

.search-bar .search-wrapper i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 14px;
    pointer-events: none;
    z-index: 2;
}

.search-bar .search-wrapper input {
    width: 100%;
    height: 40px;
    padding: 0 38px 0 38px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: all 0.2s ease;
    background: white;
    color: #1e293b;
    box-sizing: border-box;
    line-height: 1;
    display: block;
}

.search-bar .search-wrapper input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
}

.search-bar .search-wrapper input::placeholder {
    color: #94a3b8;
}

.search-bar .search-wrapper .search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    width: 24px;
    height: 24px;
    display: none;
    border-radius: 50%;
    align-items: center;
    justify-content: center;
    z-index: 2;
}

.search-bar .search-wrapper .search-clear.visible {
    display: flex;
}

.search-bar .search-wrapper .search-clear:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.search-bar .search-actions {
    display: flex;
    gap: 8px;
    flex-wrap: nowrap;
    flex-shrink: 0;
    align-items: center;
    height: 40px;
}

.search-bar .search-actions .btn {
    height: 40px;
    padding: 0 16px;
    font-size: 13px;
    box-sizing: border-box;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* ── AI Banner ── */
.ai-banner {
    padding: 10px 20px;
    background: #eef4ff;
    border-bottom: 1px solid #bfdbfe;
    display: none;
    align-items: center;
    justify-content: space-between;
}

.ai-banner.show { display: flex; }
.ai-banner .ai-content { display: flex; align-items: center; gap: 10px; }
.ai-banner .ai-content .ai-icon { color: #2563eb; font-size: 16px; }
.ai-banner .ai-content .ai-text { color: #1e40af; font-size: 13px; }
.ai-banner .ai-content .ai-text strong { color: #1d4ed8; }
.ai-banner .ai-content .ai-count { background: #dbeafe; color: #1d4ed8; padding: 1px 12px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
.ai-banner .ai-close { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px 8px; }
.ai-banner .ai-close:hover { background: rgba(0,0,0,0.05); color: #1e293b; }

/* ── Table ── */
.table-responsive {
    overflow-x: auto;
}

.table-responsive table {
    width: 100%;
    border-collapse: collapse;
}

.table-responsive th {
    text-align: left;
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    background: #fafcfd;
    border-bottom: 2px solid #e8edf4;
    white-space: nowrap;
}

.table-responsive td {
    padding: 10px 14px;
    font-size: 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.table-responsive tbody tr {
    transition: background 0.2s ease;
}

.table-responsive tbody tr:hover {
    background: #f8fafc;
}

.table-responsive tbody tr:last-child td {
    border-bottom: none;
}

/* ── Student Info ── */
.student-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.student-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 11px;
    flex-shrink: 0;
}

.student-avatar.blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
.student-avatar.green { background: linear-gradient(135deg, #16a34a, #15803d); }
.student-avatar.purple { background: linear-gradient(135deg, #7c3aed, #6d28d9); }
.student-avatar.orange { background: linear-gradient(135deg, #b45309, #92400e); }
.student-avatar.pink { background: linear-gradient(135deg, #db2777, #be185d); }

.student-name {
    font-weight: 500;
    color: #0f172a;
}
.student-email {
    font-size: 12px;
    color: #94a3b8;
}
.student-id {
    font-weight: 600;
    font-size: 13px;
    color: #0f172a;
}

/* ── Status Badges ── */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 12px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.status-badge.active     { background: #dcfce7; color: #16a34a; }
.status-badge.probation  { background: #fef3c7; color: #b45309; }
.status-badge.at-risk    { background: #fee2e2; color: #dc2626; }
.status-badge.graduated  { background: #dbeafe; color: #2563eb; }
.status-badge.loa        { background: #f3e8ff; color: #7c3aed; }
.status-badge.transferred{ background: #fce7f3; color: #db2777; }
.status-badge.dropped    { background: #fef2f2; color: #dc2626; }

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.status-dot.active     { background: #16a34a; }
.status-dot.probation  { background: #b45309; }
.status-dot.at-risk    { background: #dc2626; }
.status-dot.graduated  { background: #2563eb; }
.status-dot.loa        { background: #7c3aed; }
.status-dot.transferred{ background: #db2777; }
.status-dot.dropped    { background: #dc2626; }

/* ── Action Buttons ── */
.action-group {
    display: flex;
    gap: 4px;
    justify-content: center;
}

.action-btn {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    background: transparent;
    color: #94a3b8;
}

.action-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
    transform: scale(1.05);
}
.action-btn.view { color: #2563eb; }
.action-btn.view:hover { background: #eef4ff; }
.action-btn.edit { color: #b45309; }
.action-btn.edit:hover { background: #fef3c7; }
.action-btn.delete { color: #dc2626; }
.action-btn.delete:hover { background: #fee2e2; }

/* ── Table Footer ── */
.table-footer {
    padding: 10px 20px;
    background: #fafcfd;
    border-top: 1px solid #e8edf4;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.table-footer .info-text {
    font-size: 13px;
    color: #64748b;
}
.table-footer .info-text strong {
    color: #0f172a;
}

/* ── Empty State ── */
.empty-state {
    text-align: center;
    padding: 30px 20px;
    color: #94a3b8;
}
.empty-state i {
    font-size: 36px;
    color: #e2e8f0;
    display: block;
    margin-bottom: 8px;
}
.empty-state p {
    font-size: 15px;
    font-weight: 500;
    color: #94a3b8;
}
.empty-state span {
    font-size: 13px;
    color: #cbd5e1;
}

/* ── Modal Styles ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }

.modal-content {
    background: white;
    border-radius: 20px;
    padding: 28px 32px;
    max-width: 560px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px rgba(0,0,0,0.15);
    animation: modalSlide 0.3s ease;
}

@keyframes modalSlide {
    from { opacity: 0; transform: translateY(20px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.modal-header h2 {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-header h2 i { color: #2563eb; }

.modal-close {
    width: 34px;
    height: 34px;
    border: none;
    background: #f1f5f9;
    border-radius: 50%;
    cursor: pointer;
    font-size: 15px;
    color: #94a3b8;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-close:hover {
    background: #e2e8f0;
    color: #1e293b;
}

.modal-body { margin-bottom: 16px; }

.modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 14px;
    border-top: 1px solid #e8edf4;
}
.modal-footer .btn { min-width: 100px; justify-content: center; }

/* ── Filter Grid ── */
.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-group label {
    font-size: 13px;
    font-weight: 500;
    color: #1e293b;
}
.filter-group select,
.filter-group input {
    padding: 9px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: all 0.3s ease;
    background: white;
    color: #1e293b;
}
.filter-group select:focus,
.filter-group input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}

/* ── Delete Icon ── */
.delete-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #fee2e2;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    font-size: 26px;
    color: #dc2626;
}

/* ── View Modal Grid ── */
/* ── Buttons (inherited from components.css) ── */

/* ── MOBILE RESPONSIVE ── */
@media (max-width: 992px) {
    .dashboard-main { padding: 20px; }
    .dashboard-stats { grid-template-columns: repeat(2, 1fr); }
    .filter-grid { grid-template-columns: 1fr; }
}

/* Tablet: search input takes a smaller min-width so actions have room */
@media (max-width: 900px) {
    .search-bar .search-wrapper {
        flex: 1 1 240px;
        min-width: 200px;
    }
}

@media (max-width: 768px) {
    .dashboard-main {
        margin-left: 0;
        padding: 16px;
        width: 100%;
        max-width: 100%;
    }

    .btn {
        padding: 8px 16px;
        font-size: 13px;
    }
    .dashboard-stats { grid-template-columns: 1fr 1fr; gap: 12px; }
    .stat-card-premium { padding: 14px 16px; }
    .stat-card-premium .stat-number { font-size: 20px; }
    .stat-card-premium .stat-icon-wrap { width: 34px; height: 34px; font-size: 14px; }

    .search-bar {
        flex-direction: column;
        flex-wrap: nowrap;
        align-items: stretch;
        gap: 10px;
    }
    .search-bar .search-wrapper {
        flex: 1 1 auto;
        min-width: 0;
        max-width: 100%;
        width: 100%;
        height: 40px;
    }
    .search-bar .search-actions {
        width: 100%;
        height: auto;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .search-bar .search-actions .btn {
        height: 38px;
        justify-content: center;
    }

    .modal-content { padding: 20px 16px; }

    .table-responsive table { min-width: 600px; }
    .table-responsive th,
    .table-responsive td { padding: 8px 10px; font-size: 12px; }

    .student-avatar { width: 28px; height: 28px; font-size: 10px; }
    .action-btn { width: 26px; height: 26px; font-size: 11px; }
    .student-email { display: none; }
}

@media (max-width: 480px) {
    .dashboard-main { padding: 12px; }
    .dashboard-stats { grid-template-columns: 1fr; }
    .stat-card-premium .stat-number { font-size: 18px; }

    .modal-content { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
    .filter-grid { grid-template-columns: 1fr; }

    .search-bar { padding: 10px 14px; gap: 8px; }
    .search-bar .search-wrapper { height: 38px; }
    .search-bar .search-wrapper input {
        height: 38px;
        font-size: 13px;
        padding: 0 36px 0 36px;
    }
    .search-bar .search-wrapper i { font-size: 12px; left: 12px; }
    .search-bar .search-actions { gap: 6px; }
    .search-bar .search-actions .btn { padding: 0 14px; font-size: 12px; height: 36px; }

    .table-responsive th,
    .table-responsive td { padding: 6px 8px; font-size: 11px; }
    .student-id { font-size: 11px; }
    .student-name { font-size: 12px; }
    .status-badge { font-size: 10px; padding: 2px 8px; }
    .action-group { gap: 2px; }
}
</style>

<main class="dashboard-main">

    <!-- Header -->
    <header class="header">
        <div class="title">
            <h1>Students</h1>
            <p>Manage all student records</p>
        </div>
        <div class="header-actions">
            <a href="students-add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Student
            </a>
        </div>
    </header>

    <!-- Stats -->
    <div class="dashboard-stats">
        <div class="stat-card-premium">
            <div class="stat-top">
                <div class="stat-icon-wrap blue"><i class="fas fa-users"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 12%</span>
            </div>
            <div class="stat-number"><?= $totalStudents ?></div>
            <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-card-premium">
            <div class="stat-top">
                <div class="stat-icon-wrap green"><i class="fas fa-user-check"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 5%</span>
            </div>
            <div class="stat-number"><?= $activeStudents ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card-premium">
            <div class="stat-top">
                <div class="stat-icon-wrap yellow"><i class="fas fa-triangle-exclamation"></i></div>
                <span class="stat-trend down"><i class="fas fa-arrow-up"></i> 3%</span>
            </div>
            <div class="stat-number"><?= $atRiskStudents ?></div>
            <div class="stat-label">At Risk</div>
        </div>
        <div class="stat-card-premium">
            <div class="stat-top">
                <div class="stat-icon-wrap purple"><i class="fas fa-graduation-cap"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 8%</span>
            </div>
            <div class="stat-number"><?= $graduatedStudents ?></div>
            <div class="stat-label">Graduated</div>
        </div>
    </div>

    <!-- Merged Search + Table -->
    <div class="search-table-container">

        <!-- Search & Filter Bar -->
        <div class="search-bar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="studentSearch" placeholder="Search by name, ID, or course..." />
                <button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
            </div>
            <div class="search-actions">
                <button class="btn btn-secondary" id="filterToggle">
                    <i class="fas fa-sliders"></i> Filter
                </button>
                <button class="btn btn-secondary" id="resetBtn">
                    <i class="fas fa-rotate-right"></i> Reset
                </button>
            </div>
        </div>

        <!-- AI Banner -->
        <div class="ai-banner" id="aiBanner">
            <div class="ai-content">
                <i class="fas fa-brain ai-icon"></i>
                <span class="ai-text"><strong>AI Search:</strong> <span id="aiExplanation">Searching...</span> <span class="ai-count" id="aiCount">0 results</span></span>
            </div>
            <button class="ai-close" onclick="hideAIBanner()"><i class="fas fa-times"></i></button>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-user-graduate"></i>
                                <p>No students found</p>
                                <span>Add your first student to get started</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student):
                            $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                            $avatarColors = ['blue', 'green', 'purple', 'orange', 'pink'];
                            $avatarClass = $avatarColors[array_rand($avatarColors)];
                        ?>
                            <tr data-student='<?= json_encode($student) ?>'>
                                <td class="student-id"><?= htmlspecialchars($student['student_number']) ?></td>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar <?= $avatarClass ?>"><?= $initials ?: '?' ?></div>
                                        <div>
                                            <div class="student-name"><?= htmlspecialchars($student['first_name']) ?> <?= htmlspecialchars($student['last_name']) ?></div>
                                            <div class="student-email"><?= htmlspecialchars($student['email'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="status-badge <?= $student['status'] ?? 'active' ?>">
                                        <span class="status-dot <?= $student['status'] ?? 'active' ?>"></span>
                                        <?= ucfirst($student['status'] ?? 'Active') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <button class="action-btn view" onclick="viewStudent(<?= (int)$student['id'] ?>)" title="View Profile">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn edit" onclick="editStudent(<?= (int)$student['id'] ?>)" title="Edit">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="confirmDelete(<?= (int)$student['id'] ?>, this.dataset.name)" data-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name'], ENT_QUOTES) ?>" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer -->
        <div class="table-footer">
            <div class="info-text">
                Showing <strong id="showingCount"><?= count($students) ?></strong> of <strong id="totalCount"><?= count($students) ?></strong> students
            </div>
        </div>
    </div>

</main>

<!-- ============================================================
     FILTER MODAL
============================================================ -->
<div class="modal-overlay" id="filterModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-sliders"></i> Filter Students</h2>
            <button class="modal-close" onclick="closeFilterModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Status</label>
                    <select id="filterStatus">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="probation">Probation</option>
                        <option value="at-risk">At Risk</option>
                        <option value="graduated">Graduated</option>
                        <option value="loa">LOA</option>
                        <option value="transferred">Transferred</option>
                        <option value="dropped">Dropped</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Year Level</label>
                    <select id="filterYear">
                        <option value="">All Year</option>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Course</label>
                    <select id="filterCourse">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars($course['course']) ?>"><?= htmlspecialchars($course['course']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Section</label>
                    <input type="text" id="filterSection" placeholder="Enter section..." />
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFilterModal()">Cancel</button>
            <button class="btn btn-secondary" onclick="clearFilters()">Clear All</button>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-check"></i> Apply Filters</button>
        </div>
    </div>
</div>

<!-- ============================================================
     DELETE CONFIRMATION MODAL
============================================================ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content" style="max-width: 420px; text-align: center;">
        <div class="delete-icon"><i class="fas fa-trash-alt"></i></div>
        <h3 style="font-size: 19px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">Delete Student</h3>
        <p id="deleteMessage" style="color: #64748b; font-size: 14px; margin-bottom: 18px;">Are you sure you want to delete this student? This action cannot be undone.</p>
        <div class="modal-footer" style="justify-content: center;">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn"><i class="fas fa-trash-alt"></i> Delete</button>
        </div>
    </div>
</div>

<script>
// ─── SEARCH & FILTER ─────────────────────────────────────────────
const searchInput = document.getElementById('studentSearch');
const searchClear = document.getElementById('searchClear');
const resetBtn = document.getElementById('resetBtn');
const tableBody = document.getElementById('studentTableBody');
const showingCount = document.getElementById('showingCount');
const totalCount = document.getElementById('totalCount');

let allStudents = [];
let deleteTarget = null;

// Store all student data from DOM
document.querySelectorAll('#studentTableBody tr').forEach(row => {
    try {
        const data = JSON.parse(row.dataset.student);
        if (data) allStudents.push({ ...data, element: row });
    } catch(e) {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 5) {
            allStudents.push({
                id: row.querySelector('.action-btn.view')?.getAttribute('onclick')?.match(/\d+/)?.[0] || 0,
                student_number: cells[0]?.textContent?.trim() || '',
                first_name: cells[1]?.querySelector('.student-name')?.textContent?.split(' ')[0] || '',
                last_name: cells[1]?.querySelector('.student-name')?.textContent?.split(' ').slice(1).join(' ') || '',
                course: cells[2]?.textContent?.trim() || '',
                year_level: parseInt(cells[3]?.textContent?.trim()) || 0,
                status: cells[4]?.querySelector('.status-badge')?.className?.match(/status-badge\s+(\w+)/)?.[1] || 'active',
                email: cells[1]?.querySelector('.student-email')?.textContent?.trim() || '',
                element: row
            });
        }
    }
});

function updateTable(students) {
    allStudents.forEach(s => { if (s.element) s.element.style.display = 'none'; });

    let visible = 0;
    students.forEach(student => {
        if (student.element) {
            student.element.style.display = '';
            visible++;
        }
    });

    showingCount.textContent = visible;
    totalCount.textContent = allStudents.length;

    let emptyRow = tableBody.querySelector('.empty-state-row');
    if (visible === 0 && allStudents.length > 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.className = 'empty-state-row';
            emptyRow.innerHTML = `<td colspan="6" class="empty-state"><i class="fas fa-search"></i><p>No students found</p><span>Try adjusting your search or filters</span></td>`;
            tableBody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
}

function performSearch() {
    const query = searchInput.value.trim().toLowerCase();
    const status = document.getElementById('filterStatus')?.value || '';
    const year = document.getElementById('filterYear')?.value || '';
    const course = document.getElementById('filterCourse')?.value || '';
    const section = document.getElementById('filterSection')?.value?.toLowerCase() || '';

    let filtered = allStudents;

    if (query) {
        filtered = filtered.filter(s =>
            (s.first_name || '').toLowerCase().includes(query) ||
            (s.last_name || '').toLowerCase().includes(query) ||
            (s.student_number || '').toLowerCase().includes(query) ||
            (s.course || '').toLowerCase().includes(query)
        );
    }

    if (status) filtered = filtered.filter(s => s.status === status);
    if (year) filtered = filtered.filter(s => String(s.year_level) === year);
    if (course) filtered = filtered.filter(s => s.course === course);
    if (section) filtered = filtered.filter(s => (s.section || '').toLowerCase().includes(section));

    updateTable(filtered);
    searchClear.classList.toggle('visible', query.length > 0);
    hideAIBanner();
}

searchInput.addEventListener('input', performSearch);
searchClear.addEventListener('click', function() {
    searchInput.value = '';
    performSearch();
});

resetBtn.addEventListener('click', function() {
    searchInput.value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterYear').value = '';
    document.getElementById('filterCourse').value = '';
    document.getElementById('filterSection').value = '';
    performSearch();
    closeFilterModal();
});

// ─── FILTER MODAL ──────────────────────────────────────────────────
const filterModal = document.getElementById('filterModal');

document.getElementById('filterToggle').addEventListener('click', function() {
    filterModal.classList.add('active');
    document.body.style.overflow = 'hidden';
});

function closeFilterModal() {
    filterModal.classList.remove('active');
    document.body.style.overflow = '';
}

function applyFilters() {
    performSearch();
    closeFilterModal();
}

function clearFilters() {
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterYear').value = '';
    document.getElementById('filterCourse').value = '';
    document.getElementById('filterSection').value = '';
    performSearch();
}

filterModal.addEventListener('click', function(e) {
    if (e.target === this) closeFilterModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (filterModal.classList.contains('active')) closeFilterModal();
        if (deleteModal.classList.contains('active')) closeDeleteModal();
    }
});

// ─── VIEW STUDENT (navigate to full profile page) ───────────────────
function viewStudent(id) {
    window.location.href = 'students-view.php?id=' + id;
}

// ─── EDIT STUDENT ──────────────────────────────────────────────────
function editStudent(id) {
    window.location.href = 'students-edit.php?id=' + id;
}

// ─── DELETE STUDENT ───────────────────────────────────────────────
const deleteModal = document.getElementById('deleteModal');

function confirmDelete(id, name) {
    deleteTarget = id;
    document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete ' + name + '? This action cannot be undone.';
    deleteModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    deleteModal.classList.remove('active');
    document.body.style.overflow = '';
    deleteTarget = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!deleteTarget) return;
    
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    
    try {
        const response = await fetch('../api/students.php?id=' + deleteTarget, {
            method: 'DELETE'
        });
        const result = await response.json();
        if (result.success) {
            window.location.href = 'students.php?success=deleted';
        } else {
            alert(result.message || 'Error deleting student.');
        }
    } catch (error) {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
        closeDeleteModal();
    }
});

deleteModal.addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ─── AI BANNER ─────────────────────────────────────────────────────
function showAIBanner(explanation, count) {
    const banner = document.getElementById('aiBanner');
    document.getElementById('aiExplanation').textContent = explanation;
    document.getElementById('aiCount').textContent = count + ' results';
    banner.classList.add('show');
}

function hideAIBanner() {
    document.getElementById('aiBanner').classList.remove('show');
}

// ─── UTILITY ───────────────────────────────────────────────────────
function ucfirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// ─── INIT ──────────────────────────────────────────────────────────
performSearch();

// ─── SUCCESS TOAST ─────────────────────────────────────────────────
(function() {
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    if (!success) return;

    const messages = {
        added:   { title: 'Student Added',   message: 'The student record was created successfully.' },
        updated: { title: 'Student Updated', message: 'The student record was updated successfully.' },
        deleted: { title: 'Student Deleted', message: 'The student record was deleted successfully.' }
    };

    const info = messages[success];
    if (info) showToast(info.title, info.message, 'success');

    // Clean URL
    const url = new URL(window.location.href);
    url.searchParams.delete('success');
    window.history.replaceState({}, '', url.toString());
})();

// ─── TOAST HELPERS ─────────────────────────────────────────────────
function ensureToastContainer() {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    return container;
}

function showToast(title, message, type = 'info') {
    const container = ensureToastContainer();
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML =
        '<i class="fas ' + (type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info') + ' toast-icon"></i>' +
        '<div class="toast-content">' +
            '<div class="toast-title"></div>' +
            '<div class="toast-message"></div>' +
        '</div>' +
        '<button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>';
    toast.querySelector('.toast-title').textContent = title;
    toast.querySelector('.toast-message').textContent = message;
    toast.querySelector('.toast-close').addEventListener('click', () => removeToast(toast));
    container.appendChild(toast);
    setTimeout(() => removeToast(toast), 4000);
}

function removeToast(toast) {
    toast.classList.add('hiding');
    setTimeout(() => toast.remove(), 300);
}
</script>

<?php include '../includes/footer.php'; ?>