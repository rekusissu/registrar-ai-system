<?php
// ============================================================
//  REGISTRAR/MASTERLIST.PHP
//  Masterlist generator — filter, sort, view student profile,
//  bulk actions, export (CSV/Excel/PDF), print
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();
$maxPerSection = defined('MAX_STUDENTS_PER_SECTION') ? (int) MAX_STUDENTS_PER_SECTION : 50;

// ─── FILTERS ────────────────────────────────────────────────
$filterCourse     = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$filterYear       = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';
$filterSchoolYear = isset($_GET['school_year']) ? trim((string) $_GET['school_year']) : '';
$filterSemester   = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';
$filterSection    = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
$filterStatus     = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

// Only students with an assigned section appear in the masterlist view.
$sql = "SELECT * FROM students WHERE section IS NOT NULL AND TRIM(section) != ''";
$params = [];
if ($filterCourse !== '') {
    $sql .= " AND TRIM(course) = ?";
    $params[] = $filterCourse;
}
if ($filterYear !== '' && is_numeric($filterYear)) {
    $sql .= " AND year_level = ?";
    $params[] = (int) $filterYear;
}
if ($filterSchoolYear !== '') {
    $sql .= " AND school_year = ?";
    $params[] = $filterSchoolYear;
}
if ($filterSemester !== '') {
    $sql .= " AND semester = ?";
    $params[] = $filterSemester;
}
if ($filterSection !== '') {
    $sql .= " AND TRIM(section) = ?";
    $params[] = $filterSection;
}
if ($filterStatus !== '') {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC";

$students = $db->fetchAll($sql, $params);

// Adviser name lookup (users.id → full_name)
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('teacher','staff') ORDER BY full_name");
$adviserNames = [];
foreach ($advisers as $ad) { $adviserNames[(int)$ad['id']] = $ad['full_name']; }

// Attach adviser names to student rows for display
foreach ($students as &$row) {
    $row['adviser_name'] = !empty($row['adviser_id']) ? ($adviserNames[(int)$row['adviser_id']] ?? null) : null;
}
unset($row);

$groups = filterAssignedMasterlistGroups(groupStudentsForMasterlist($students));

$unassignedCount = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM students WHERE section IS NULL OR TRIM(section) = ''"
);

// Dropdown data
$courses = $db->fetchAll(
    "SELECT DISTINCT TRIM(course) AS course FROM students
     WHERE course IS NOT NULL AND TRIM(course) != ''
     ORDER BY course"
);
$years = $db->fetchAll(
    "SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level"
);
$schoolYears = $db->fetchAll(
    "SELECT DISTINCT school_year FROM students WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC"
);
$statusOptions = ['active', 'probation', 'at-risk', 'loa', 'graduated', 'transferred', 'dropped'];

// Section summaries (all students, not the filtered view) for the target-section picker
$sectionSummaries = $db->fetchAll(
    "SELECT TRIM(course) AS course, year_level, semester, TRIM(section) AS section, COUNT(*) AS count
     FROM students
     WHERE section IS NOT NULL AND TRIM(section) != ''
     GROUP BY TRIM(course), year_level, semester, TRIM(section)
     ORDER BY TRIM(course), year_level, section"
);

// Lightweight candidate list for the assign-existing-students modal
$assignableStudents = $db->fetchAll(
    "SELECT id, first_name, last_name, student_number, course, year_level, semester, section, status
     FROM students ORDER BY last_name, first_name"
);

// Offered courses (shared with students.php)
$offeredCourses = function_exists('getOfferedCourses') ? getOfferedCourses() : $courses;

// RFID lookup for profile modal (student_id → card info)
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status, expiry_date FROM rfid_cards");
$rfidMap = [];
foreach ($rfidCards as $rc) {
    if ($rc['student_id']) $rfidMap[$rc['student_id']] = $rc;
}

$page_title = 'Masterlist';
$APP_ROOT = '../';
$ACTIVE_NAV = 'masterlist';
$assignedSuccess = isset($_GET['assigned']) && $_GET['assigned'] === '1';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1><i class="fas fa-table-list" style="color:#2563eb;margin-right:8px;"></i>Masterlist</h1>
            <p>Search, filter, and manage enrolled students (max <?= (int) $maxPerSection ?> students per section)</p>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-primary" id="btnCreateSection" title="Create a section manually, then add or assign students to it">
                <i class="fas fa-plus-circle"></i> Create Section
            </button>
            <button type="button" class="btn btn-secondary" id="btnAutoAssign" title="Fill existing sections, create new ones only when needed (<?= (int) $maxPerSection ?> max each)">
                <i class="fas fa-wand-magic-sparkles"></i> Auto-assign
            </button>
            <button class="btn btn-secondary" onclick="openGenerateModal()">
                <i class="fas fa-sliders"></i> Generate
            </button>
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <div class="export-wrap" style="position:relative;">
                <button class="btn btn-primary" id="exportBtn"><i class="fas fa-download"></i> Export</button>
                <div class="export-menu" id="exportMenu" style="position:absolute;top:100%;right:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:160px;padding:4px;margin-top:4px;display:none;">
                    <a href="#" onclick="exportCSV()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-csv"></i> Export CSV</a>
                    <a href="#" onclick="exportExcel()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-excel"></i> Export Excel</a>
                    <a href="#" onclick="window.print()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-pdf"></i> Export PDF</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($assignedSuccess): ?>
        <div class="card" style="margin-bottom: 16px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">
            <i class="fas fa-check-circle"></i> Sections updated. Each course/year block now has up to <?= (int) $maxPerSection ?> students per section.
        </div>
    <?php endif; ?>

    <!-- Toolbar: search bar + filter button -->
    <div class="card" style="margin-bottom: 16px; padding: 12px 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div style="flex:1; min-width:220px; position:relative;">
            <i class="fas fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;"></i>
            <input type="text" id="masterlistSearch" name="q" class="form-control" style="padding-left:38px;" placeholder="Search by name, student no., course…">
        </div>
        <button type="button" class="btn btn-secondary" id="aiSearchBtn" style="white-space:nowrap;display:inline-flex;align-items:center;gap:6px;" title="Ask AI to build the filters for you - e.g. 'at-risk BSIT 3rd year'">
            <i class="fas fa-wand-magic-sparkles" style="color:#7c3aed;"></i> AI
        </button>
        <button type="button" class="btn btn-primary" onclick="openFilterSearchModal()" style="display:inline-flex;align-items:center;gap:8px;">
            <i class="fas fa-sliders"></i> Filter
            <?php if ($filterCourse !== '' || $filterYear !== '' || $filterSchoolYear !== '' || $filterSemester !== '' || $filterSection !== '' || $filterStatus !== ''): ?>
                <span style="background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;">Active</span>
            <?php endif; ?>
        </button>
        <?php if (!empty($groups)): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
            <input type="checkbox" id="selectAllPage" style="width:16px;height:16px;accent-color:#2563eb;"> Select all shown
        </label>
        <span style="font-size:13px;color:#64748b;">Showing <strong id="showingCount"><?= count($students) ?></strong> student(s)</span>
        <?php endif; ?>
    </div>

    <!-- AI interpretation banner (below the search bar) -->
    <div id="aiInterpretation" style="display:none;padding:10px 14px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:16px;">
        <i class="fas fa-brain" style="color:#2563eb;"></i>
        <span id="aiExplanation" style="color:#1e40af;margin-left:8px;font-size:13px;"></span>
    </div>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar" style="display:none;padding:10px 16px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:12px;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <span style="font-size:13px;font-weight:600;color:#1d4ed8;" id="bulkCount">0 selected</span>
        <button class="btn btn-secondary btn-sm" onclick="exportSelectedCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button class="btn btn-secondary btn-sm" onclick="printSelected()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-danger btn-sm" onclick="bulkArchive()"><i class="fas fa-archive"></i> Archive</button>
        <a class="btn btn-secondary btn-sm" href="rfid-cards.php"><i class="fas fa-credit-card"></i> Assign RFID</a>
    </div>

    <div id="masterlistContent">
        <?php if (empty($groups)): ?>
            <div class="card">
                <div style="padding: 48px 24px; text-align: center; color: #64748b;">
                    <i class="fas fa-layer-group" style="font-size:40px;color:#e2e8f0;display:block;margin-bottom:14px;"></i>
                    <?php if ($unassignedCount > 0 && $filterCourse === '' && $filterYear === '' && $filterSchoolYear === '' && $filterSemester === '' && $filterSection === '' && $filterStatus === ''): ?>
                        <p style="font-size:16px;font-weight:600;color:#334155;margin:0 0 8px;">No sections yet</p>
                        <p style="margin:0 0 16px;max-width:420px;margin-left:auto;margin-right:auto;line-height:1.5;">
                            The masterlist view appears only after sections are created. Use <strong>Create Section</strong> or <strong>Auto-assign</strong> to assign students.
                        </p>
                        <p style="font-size:13px;color:#94a3b8;margin:0 0 20px;">
                            <i class="fas fa-user-clock"></i> <?= $unassignedCount ?> student(s) without a section yet
                        </p>
                        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                            <button type="button" class="btn btn-primary" onclick="document.getElementById('btnCreateSection').click()"><i class="fas fa-plus-circle"></i> Create Section</button>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('btnAutoAssign').click()"><i class="fas fa-wand-magic-sparkles"></i> Auto-assign</button>
                        </div>
                    <?php else: ?>
                        <p style="font-size:16px;font-weight:600;color:#334155;margin:0 0 8px;">No sections found</p>
                        <p style="margin:0;">No sections match your filters, or no students have been assigned to a section yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group):
                $count = count($group['students']);
                $overCap = $count > $maxPerSection;
                $sectionTitle = htmlspecialchars($group['course']) . ' — Year ' . htmlspecialchars($group['year_level'])
                    . ' — Section ' . htmlspecialchars($group['section']);
                $groupAdviser = '';
                $groupFirst = null;
                foreach ($group['students'] as $st) {
                    if ($groupFirst === null) $groupFirst = $st;
                    if (!empty($st['adviser_name'])) { $groupAdviser = $st['adviser_name']; break; }
                }
                $gCourse  = $groupFirst['course']  ?? $group['course'];
                $gYear    = $groupFirst['year_level'] ?? $group['year_level'];
                $gSem     = $groupFirst['semester'] ?? '';
                $gSy      = $groupFirst['school_year'] ?? '';
                $gAdviserId = $groupFirst['adviser_id'] ?? '';
            ?>
                <div class="card masterlist-section-block" style="margin-bottom: 16px;">
                    <div style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">
                            <i class="fas fa-users" style="color: #2563eb; margin-right: 8px;"></i><?= $sectionTitle ?>
                        </h2>
                        <span class="badge <?= $overCap ? 'badge-warning' : 'badge-success' ?>" style="font-size: 12px;">
                            <?= $count ?> / <?= (int) $maxPerSection ?> students
                            <?php if ($overCap): ?> — over capacity<?php endif; ?>
                        </span>
                        <?php if ($groupAdviser !== ''): ?>
                            <span style="font-size: 12px; color: #475569;"><i class="fas fa-chalkboard-user" style="color: #7c3aed; margin-right: 6px;"></i>Adviser: <strong><?= htmlspecialchars($groupAdviser) ?></strong></span>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" style="padding:5px 12px;font-size:12px;" onclick='openSectionWorkspace(<?= json_encode(['course' => $gCourse, 'year_level' => $gYear, 'semester' => $gSem, 'school_year' => $gSy, 'adviser_id' => $gAdviserId, 'section' => $group['section']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-user-plus"></i> Add Student</button>
                        <button class="btn btn-secondary btn-sm" style="padding:5px 12px;font-size:12px;" onclick='openEditSection(<?= json_encode(['course' => $gCourse, 'year_level' => $gYear, 'semester' => $gSem, 'school_year' => $gSy, 'adviser_id' => $gAdviserId, 'section' => $group['section']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit section"><i class="fas fa-pen"></i> Edit</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="masterlist-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            <thead>
                                <tr style="background: #1a2d4a; color: white;">
                                    <th style="padding: 10px 12px; text-align: center; width: 34px;"><input type="checkbox" class="block-select-all" style="width:15px;height:15px;accent-color:#2563eb;" title="Select this block"></th>
                                    <th style="padding: 10px 12px; text-align: left;">#</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer;" data-sort="student_number"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Student ID</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer;" data-sort="name"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Name</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer;" data-sort="course"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Course</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer;" data-sort="year_level"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Year</th>
                                    <th style="padding: 10px 12px; text-align: left;">S.Y.</th>
                                    <th style="padding: 10px 12px; text-align: left;">Sem</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer;" data-sort="section"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Section</th>
                                    <th style="padding: 10px 12px; text-align: left;">Adviser</th>
                                    <th style="padding: 10px 12px; text-align: left;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($group['students'] as $student): ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;" data-student-id="<?= (int)$student['id'] ?>">
                                        <td style="padding: 8px 12px; text-align: center;"><input type="checkbox" class="student-cb" value="<?= (int)$student['id'] ?>" style="width:15px;height:15px;accent-color:#2563eb;"></td>
                                        <td style="padding: 8px 12px;"><?= $i++ ?></td>
                                        <td style="padding: 8px 12px; font-weight:600; font-size:12px;"><?= htmlspecialchars($student['student_number']) ?></td>
                                        <td style="padding: 8px 12px;"><a href="javascript:void(0)" onclick="viewStudent(<?= (int)$student['id'] ?>)" style="color:#2563eb;font-weight:600;text-decoration:none;cursor:pointer;"><?= htmlspecialchars($student['last_name']) ?>, <?= htmlspecialchars($student['first_name']) ?></a></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['school_year'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['semester'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['section'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($student['adviser_name'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px;">
                                            <span class="badge badge-<?= $student['status'] === 'active' ? 'success' : ($student['status'] === 'at-risk' || $student['status'] === 'probation' ? 'warning' : 'neutral') ?>">
                                                <?= ucfirst($student['status'] ?? 'Active') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($groups)): ?>
    <div class="card" style="margin-top: 8px;">
        <div class="table-footer">
            <div class="info-text">
                Total: <strong><?= count($students) ?></strong> students in <strong><?= count($groups) ?></strong> section group(s)
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Filter Masterlist Modal -->
<div class="modal-overlay" id="filterSearchModal">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-filter" style="color:#2563eb;"></i> Filter Masterlist</h2><button class="modal-close" onclick="closeFilterSearchModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <form method="get" action="masterlist.php" id="filterSearchForm">
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group"><label>Course</label>
                        <select name="course" id="filterCourse" class="form-control">
                            <option value="">All courses</option>
                            <?php foreach ($courses as $row): ?>
                                <option value="<?= htmlspecialchars($row['course']) ?>" <?= $filterCourse === $row['course'] ? 'selected' : '' ?>><?= htmlspecialchars($row['course']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                
                    <div class="form-group"><label>Year</label>
                        <select name="year_level" id="filterYear" class="form-control">
                            <option value="">All years</option>
                            <?php foreach ($years as $row): ?>
                                <option value="<?= (int) $row['year_level'] ?>" <?= $filterYear === (string) $row['year_level'] ? 'selected' : '' ?>>Year <?= (int) $row['year_level'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>School Year</label>
                        <select name="school_year" id="filterSchoolYear" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($schoolYears as $row): ?>
                                <option value="<?= htmlspecialchars($row['school_year']) ?>" <?= $filterSchoolYear === $row['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($row['school_year']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Semester</label>
                        <select name="semester" id="filterSemester" class="form-control">
                            <option value="">All</option>
                            <option value="1st" <?= $filterSemester === '1st' ? 'selected' : '' ?>>1st Sem</option>
                            <option value="2nd" <?= $filterSemester === '2nd' ? 'selected' : '' ?>>2nd Sem</option>
                            <option value="summer" <?= $filterSemester === 'summer' ? 'selected' : '' ?>>Summer</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Section</label>
                        <input type="text" name="section" id="filterSection" class="form-control" placeholder="A" value="<?= htmlspecialchars($filterSection) ?>">
                    </div>
                    <div class="form-group"><label>Status</label>
                        <select name="status" id="filterStatus" class="form-control">
                            <option value="">All statuses</option>
                            <?php foreach ($statusOptions as $st): ?>
                                <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFilterSearchModal()">Cancel</button>
            <?php if ($filterCourse !== '' || $filterYear !== '' || $filterSchoolYear !== '' || $filterSemester !== '' || $filterSection !== '' || $filterStatus !== ''): ?>
                <a class="btn btn-light" href="masterlist.php">Clear</a>
            <?php endif; ?>
            <button type="submit" form="filterSearchForm" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
        </div>
    </div>
</div>

<!-- Generate Masterlist Modal -->
<div class="modal-overlay" id="generateModal">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-sliders" style="color:#2563eb;"></i> Generate Masterlist</h2><button class="modal-close" onclick="closeGenerateModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <form id="generateForm">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group"><label>School Year</label><select id="genSchoolYear" class="form-control"><option value="">All</option><?php foreach ($schoolYears as $row): ?><option value="<?= htmlspecialchars($row['school_year']) ?>"><?= htmlspecialchars($row['school_year']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Semester</label><select id="genSemester" class="form-control"><option value="">All</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div>
                    <div class="form-group"><label>Course</label><select id="genCourse" class="form-control"><option value="">All courses</option><?php foreach ($courses as $row): ?><option value="<?= htmlspecialchars($row['course']) ?>"><?= htmlspecialchars($row['course']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Year Level</label><select id="genYear" class="form-control"><option value="">All years</option><?php foreach ($years as $row): ?><option value="<?= (int)$row['year_level'] ?>">Year <?= (int)$row['year_level'] ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Section</label><input type="text" id="genSection" class="form-control" placeholder="A, B, C…"></div>
                    <div class="form-group"><label>Status</label><select id="genStatus" class="form-control"><option value="">All statuses</option><?php foreach ($statusOptions as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
                </div>
                <p style="font-size:12px;color:#94a3b8;margin-top:8px;"><i class="fas fa-info-circle"></i> Course serves as the department filter. Leave fields blank to include all.</p>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeGenerateModal()">Cancel</button>
            <button class="btn btn-primary" onclick="applyGenerate()"><i class="fas fa-table-list"></i> Generate</button>
        </div>
    </div>
</div>

<!-- Student Profile Modal (tabbed) -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content" style="max-width:620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-id-card" style="color:#2563eb;"></i> Student Profile</h2><button class="modal-close" onclick="closeViewModal()"><i class="fas fa-times"></i></button></div>
        <div style="display:flex;gap:4px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;">
            <button class="vtab active" onclick="switchVTab(this,'profile')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#2563eb;cursor:pointer;border-bottom:2px solid #2563eb;font-family:inherit;"><i class="fas fa-user"></i> Profile</button>
            <button class="vtab" onclick="switchVTab(this,'academic')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-school"></i> Academic</button>
            <button class="vtab" onclick="switchVTab(this,'health')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-heartbeat"></i> Health</button>
            <button class="vtab" onclick="switchVTab(this,'documents')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-file"></i> Documents</button>
            <button class="vtab" onclick="switchVTab(this,'rfid')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-credit-card"></i> RFID</button>
        </div>
        <div class="modal-body">
            <div class="vtab-content active" id="tabProfile">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Name</div><div class="val" id="vName" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Student No.</div><div class="val" id="vStudentId" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Course</div><div class="val" id="vCourse" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Year / Section</div><div class="val" id="vYearSection" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">S.Y. / Sem</div><div class="val" id="vSchoolYearSem" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Status</div><div class="val" id="vStatus" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Gender</div><div class="val" id="vGender" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Adviser</div><div class="val" id="vAdviser" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Email</div><div class="val" id="vEmail" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Contact</div><div class="val" id="vContact" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Address</div><div class="val" id="vAddress" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                </div>
            </div>
            <div class="vtab-content" id="tabAcademic" style="display:none;"><div id="vAcademic" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabHealth" style="display:none;"><div id="vHealth" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabDocuments" style="display:none;"><div id="vDocuments" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabRfid" style="display:none;"><div id="vRfid" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary" onclick="closeViewModal()">Close</button></div>
    </div>
</div>

<!-- Create Section Modal -->
<div class="modal-overlay" id="createSectionModal">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-plus-circle" style="color:#2563eb;"></i> Create Section</h2><button class="modal-close" onclick="closeCreateSectionModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group"><label>Course</label><select id="csCourse" class="form-control"><option value="">Select course</option><?php foreach (array_keys($offeredCourses) as $cname): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Year Level</label><select id="csYear" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                <div class="form-group"><label>Semester</label><select id="csSemester" class="form-control"><option value="">Select</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div>
                <div class="form-group"><label>School Year</label><input type="text" id="csSchoolYear" class="form-control" placeholder="2026-2027" value="<?= date('Y') . '-' . (date('Y') + 1) ?>" list="csSyOptions"><datalist id="csSyOptions"><?php foreach ($schoolYears as $row): ?><option value="<?= htmlspecialchars($row['school_year']) ?>"><?php endforeach; ?></datalist></div>
                <div class="form-group"><label>Adviser</label><select id="csAdviser" class="form-control"><option value="">None</option><?php foreach ($advisers as $ad): ?><option value="<?= (int)$ad['id'] ?>"><?= htmlspecialchars($ad['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Section Code</label><div style="display:flex;gap:8px;align-items:center;"><div id="csCode" style="font-size:16px;font-weight:700;color:#2563eb;min-width:80px;">—</div><input type="text" id="csCodeOverride" class="form-control" placeholder="Override code" style="max-width:120px;" title="Optional: set a specific code"></div></div>
            </div>
            <p id="csError" style="color:#dc2626;font-size:13px;margin-top:8px;display:none;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCreateSectionModal()">Cancel</button>
            <button class="btn btn-primary" id="csCreateBtn" onclick="createSectionAndOpen()"><i class="fas fa-user-check"></i> Create Section & Assign Students</button>
        </div>
    </div>
</div>

<!-- Edit Section Modal -->
<div class="modal-overlay" id="editSectionModal">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-pen" style="color:#b45309;"></i> Edit Section</h2><button class="modal-close" onclick="closeEditSection()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <p id="esEditInfo" style="font-size:13px;color:#64748b;margin-bottom:14px;">Editing section <strong id="esOldSection">—</strong>. Changes apply to <strong id="esStudentCount">0</strong> student(s) in this section.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group"><label>Section Code</label><input type="text" id="esSection" class="form-control" placeholder="11001"></div>
                <div class="form-group"><label>School Year</label><input type="text" id="esSchoolYear" class="form-control" placeholder="2026-2027" list="csSyOptions"></div>
                <div class="form-group"><label>Course</label><select id="esCourse" class="form-control"><option value="">Select course</option><?php foreach (array_keys($offeredCourses) as $cname): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Year Level</label><select id="esYear" class="form-control"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                <div class="form-group"><label>Semester</label><select id="esSemester" class="form-control"><option value="">Select</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div>
                <div class="form-group"><label>Adviser</label><select id="esAdviser" class="form-control"><option value="">None</option><?php foreach ($advisers as $ad): ?><option value="<?= (int)$ad['id'] ?>"><?= htmlspecialchars($ad['full_name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <p id="esError" style="color:#dc2626;font-size:13px;margin-top:8px;display:none;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEditSection()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditSection()"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- Section Workspace Modal -->
<div class="modal-overlay" id="sectionWorkspaceModal">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-users" style="color:#2563eb;"></i> <span id="wsTitle">Section</span></h2><button class="modal-close" onclick="closeSectionWorkspace()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <div style="flex:1;min-width:200px;position:relative;"><i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i><input type="text" id="wsAssignSearch" class="form-control" style="padding-left:34px;" placeholder="Search students…"></div>
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#475569;cursor:pointer;"><input type="checkbox" id="wsIncludeOthers" style="width:15px;height:15px;accent-color:#2563eb;"> Include already-assigned</label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">
                <div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;color:#64748b;margin-bottom:3px;font-weight:600;">Assign to section</label><input type="text" id="wsTargetSection" class="form-control" readonly></div>
                <button class="btn btn-primary" onclick="assignSelectedToSection()"><i class="fas fa-user-check"></i> Assign Selected</button>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-info-circle"></i> Showing students without a section. Check "Include already-assigned" to see everyone.</p>
            <div id="wsAssignList" style="max-height:320px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;"></div>
        </div>
    </div>
</div>

<script>
const MAX_PER_SECTION = <?= (int) $maxPerSection ?>;
const RFID_MAP = <?= json_encode(array_map(fn($c) => ['card_uid' => $c['card_uid'], 'status' => $c['status'], 'expiry_date' => $c['expiry_date']], $rfidMap)) ?>;
const ADVISER_NAMES = <?= json_encode($adviserNames) ?>;
const OFFERED_COURSES = <?= json_encode(array_keys($offeredCourses)) ?>;
const SECTION_SUMMARIES = <?= json_encode($sectionSummaries) ?>;
const ASSIGNABLE_STUDENTS = <?= json_encode($assignableStudents) ?>;

// ─── AUTO-ASSIGN ─────────────────────────────────────────────
document.getElementById('btnAutoAssign')?.addEventListener('click', async function () {
    if (!confirm('Assign section codes (11001, 12001, 21001…) by course, year, and semester? Each section will have at most ' + MAX_PER_SECTION + ' students.')) return;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning…';
    try {
        const response = await fetch('../api/masterlist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assign_sections', max_per_section: MAX_PER_SECTION })
        });
        const data = await response.json();
        if (!data.success) { alert(data.message || 'Failed to assign sections.'); return; }
        const params = new URLSearchParams(window.location.search);
        params.set('assigned', '1');
        window.location.href = 'masterlist.php?' + params.toString();
    } catch (err) {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Auto-assign';
    }
});

// ─── SEARCH (client-side) ────────────────────────────────────
const searchInput = document.getElementById('masterlistSearch');
searchInput?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#masterlistContent .masterlist-table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = !q || text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('showingCount').textContent = visible;
});

// ─── SORT (within each section block) ────────────────────────
document.querySelectorAll('#masterlistContent .masterlist-table th[data-sort]').forEach(th => {
    th.addEventListener('click', function () {
        const key = this.dataset.sort;
        const tbody = this.closest('table').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const dir = this._dir === 'asc' ? 'desc' : 'asc';
        this._dir = dir;
        rows.forEach(r => r._key = rowSortKey(r, key));
        rows.sort((a, b) => (a._key < b._key ? -1 : a._key > b._key ? 1 : 0) * (dir === 'asc' ? 1 : -1));
        rows.forEach(r => tbody.appendChild(r));
        // re-number
        tbody.querySelectorAll('tr').forEach((r, idx) => { const cells = r.querySelectorAll('td'); if (cells.length > 1) cells[1].textContent = idx + 1; });
    });
});
function rowSortKey(row, key) {
    const cells = row.querySelectorAll('td');
    const idx = { name: 3, student_number: 2, course: 4, year_level: 5, section: 8 }[key] ?? 2;
    const v = cells[idx] ? cells[idx].textContent.trim() : '';
    if (key === 'year_level') return String(parseInt(v) || 0).padStart(3, '0');
    return v.toLowerCase();
}

// ─── GENERATE MODAL ──────────────────────────────────────────
function openGenerateModal() {
    document.getElementById('generateModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeGenerateModal() {
    document.getElementById('generateModal').classList.remove('active');
    document.body.style.overflow = '';
}
function applyGenerate() {
    const p = new URLSearchParams();
    const set = (id, name) => { const v = document.getElementById(id).value; if (v) p.set(name, v); };
    set('genSchoolYear', 'school_year');
    set('genSemester', 'semester');
    set('genCourse', 'course');
    set('genYear', 'year_level');
    set('genSection', 'section');
    set('genStatus', 'status');
    window.location.href = 'masterlist.php?' + p.toString();
}
document.getElementById('generateModal').addEventListener('click', function (e) { if (e.target === this) closeGenerateModal(); });

// ─── EXPORT DROPDOWN ─────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    document.getElementById('exportMenu').style.display = document.getElementById('exportMenu').style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function () { document.getElementById('exportMenu').style.display = 'none'; });

// ─── BULK SELECT ─────────────────────────────────────────────
document.getElementById('selectAllPage')?.addEventListener('change', function () {
    document.querySelectorAll('#masterlistContent .student-cb').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});
document.querySelectorAll('.block-select-all').forEach(cb => {
    cb.addEventListener('change', function () {
        this.closest('table').querySelectorAll('.student-cb').forEach(rowCb => rowCb.checked = this.checked);
        updateBulkBar();
    });
});
document.querySelectorAll('#masterlistContent .student-cb').forEach(cb => cb.addEventListener('change', updateBulkBar));
function updateBulkBar() {
    const checked = document.querySelectorAll('#masterlistContent .student-cb:checked').length;
    const bar = document.getElementById('bulkBar');
    bar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulkCount').textContent = checked + ' selected';
    document.getElementById('selectAllPage').checked = checked > 0 && checked === document.querySelectorAll('#masterlistContent .student-cb').length;
}
function selectedRows() {
    return Array.from(document.querySelectorAll('#masterlistContent .student-cb:checked'))
        .map(cb => cb.closest('tr'));
}
function exportSelectedCSV() { exportCSV(selectedRows()); }
function printSelected() { printRows(selectedRows()); }
function bulkArchive() {
    const rows = selectedRows();
    if (!rows.length) return;
    if (!confirm('Archive ' + rows.length + ' selected student(s)? This can be undone by restoring.')) return;
    const ids = rows.map(r => r.dataset.studentId);
    fetch('../api/students.php?action=bulk-status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, status: 'archived' })
    }).then(r => r.json()).then(d => {
        if (d.success) { alert(d.message); window.location.reload(); }
        else alert(d.message);
    }).catch(() => alert('Error.'));
}

// ─── EXPORT CSV ──────────────────────────────────────────────
function collectRowData(rows) {
    const out = [];
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length < 11) return;
        const t = i => cols[i].textContent.trim();
        out.push([t(2), t(3), t(4), t(5), t(6), t(7), t(8), t(9), t(10)]);
    });
    return out;
}
function exportCSV(rows) {
    rows = rows || Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    let csv = 'Student ID,Name,Course,Year,S.Y.,Semester,Section,Adviser,Status\n';
    const escape = v => '"' + String(v).replace(/"/g, '""') + '"';
    collectRowData(rows).forEach(r => csv += r.map(escape).join(',') + '\n');
    downloadBlob(new Blob([csv], { type: 'text/csv' }), 'masterlist-by-section.csv');
}
function exportExcel() {
    const rows = Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1"><tr><th>#</th><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>S.Y.</th><th>Sem</th><th>Section</th><th>Adviser</th><th>Status</th></tr>';
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length < 11) return;
        const cells = Array.from(cols).slice(0, 11).map(c => '<td>' + String(c.textContent.trim()).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>');
        html += '<tr>' + cells.join('') + '</tr>';
    });
    html += '</table></body></html>';
    downloadBlob(new Blob([html], { type: 'application/vnd.ms-excel' }), 'masterlist.xls');
}
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ─── PRINT (Official Masterlist sheet w/ logo + signature) ────
function printRows(rows) {
    const w = window.open();
    const sy = document.getElementById('filterSchoolYear') ? document.getElementById('filterSchoolYear').value : '';
    w.document.write('<!DOCTYPE html><html><head><title>Masterlist</title><style>');
    w.document.write('@page { size: A4 landscape; margin: 12mm; }');
    w.document.write('body { font-family: Arial, sans-serif; font-size: 11px; color: #0f172a; -webkit-print-color-adjust: exact; }');
    w.document.write('.letterhead { display:flex; align-items:center; gap:12px; border-bottom:3px double #1a2d4a; padding-bottom:8px; margin-bottom:10px; }');
    w.document.write('.letterhead img { width:52px; height:52px; object-fit:contain; }');
    w.document.write('.lh-text { flex:1; text-align:center; }');
    w.document.write('.lh-text .school { font-size:15px; font-weight:700; letter-spacing:.3px; }');
    w.document.write('.lh-text .sub { font-size:10px; color:#475569; margin-top:2px; }');
    w.document.write('.lh-text .title { font-size:12px; font-weight:700; margin-top:6px; }');
    w.document.write('h3.group { margin:14px 0 6px; font-size:12px; background:#1a2d4a; color:#fff; padding:5px 8px; border-radius:3px; }');
    w.document.write('table { width:100%; border-collapse:collapse; margin-bottom:6px; }');
    w.document.write('th,td { padding:5px 7px; border:1px solid #999; text-align:left; font-size:10px; }');
    w.document.write('th { background:#eef2f7; color:#0f172a; font-weight:700; }');
    w.document.write('.sig { display:flex; justify-content:space-between; margin-top:26px; padding-top:6px; }');
    w.document.write('.sig .box { text-align:center; width:44%; }');
    w.document.write('.sig .line { border-top:1px solid #0f172a; margin-top:28px; padding-top:4px; font-size:10px; }');
    w.document.write('</style></head><body>');
    w.document.write('<div class="letterhead"><img src="../assets/images/BCP_LOGO.png" alt="BCP" onerror="this.style.display=\'none\'">' +
        '<div class="lh-text"><div class="school">BESTLINK COLLEGE OF THE PHILIPPINES</div>' +
        '<div class="sub">812 A. Luna St., Barangay Tatalon, Quezon City · registrar@bestlink.edu.ph</div>' +
        '<div class="title">OFFICIAL MASTERLIST OF STUDENTS' + (sy ? ' — S.Y. ' + sy : '') + '</div></div></div>');

    if (rows && rows.length) {
        rows.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length < 11) return;
            const t = i => cols[i].textContent.trim();
            const course = t(4), year = t(5), section = t(6), name = t(3), sn = t(2), gender = t(7), status = t(8);
            w.document.write('<h3>' + course + ' — Year ' + (year || '—') + ' · Section ' + (section || '—') + '</h3>');
            w.document.write('<table><tr><th>#</th><th>Student No.</th><th>Name</th><th>Gender</th><th>Status</th></tr>');
            // Single row per matched row
            w.document.write('<tr><td>1</td><td>' + sn + '</td><td>' + name + '</td><td>' + gender + '</td><td>' + status + '</td></tr></table>');
        });
        if (!rows.length) w.document.write('<p>No records to print.</p>');
    } else {
        w.document.write('<p>No records to print.</p>');
    }
    w.document.write('<div class="sig"><div class="box"><div class="line">Prepared by:<br>Registrar</div></div>' +
        '<div class="box"><div class="line">Approved by:<br>School Head / President</div></div></div>');
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}

// ─── VIEW STUDENT PROFILE ────────────────────────────────────
let currentViewId = null;
function viewStudent(id) {
    currentViewId = id;
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        document.getElementById('vName').textContent = s.first_name + ' ' + s.last_name;
        document.getElementById('vStudentId').textContent = s.student_number;
        document.getElementById('vCourse').textContent = s.course || '—';
        document.getElementById('vYearSection').textContent = (s.year_level ? s.year_level + ' Year' : '') + (s.section ? ' — ' + s.section : '');
        document.getElementById('vSchoolYearSem').textContent = (s.school_year ? s.school_year : '—') + (s.semester ? ' — ' + s.semester : '');
        document.getElementById('vStatus').innerHTML = '<span class="badge badge-' + (s.status === 'active' ? 'success' : s.status === 'at-risk' || s.status === 'probation' ? 'warning' : 'neutral') + '">' + ucfirst(s.status || 'Active') + '</span>';
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vAdviser').textContent = (s.adviser_id && ADVISER_NAMES[s.adviser_id]) ? ADVISER_NAMES[s.adviser_id] : '—';
        document.getElementById('vEmail').textContent = s.email || '—';
        document.getElementById('vContact').textContent = s.contact_number || '—';
        document.getElementById('vAddress').textContent = s.address || '—';
        // Reset tabs
        document.querySelectorAll('#viewModal .vtab').forEach(t => { t.style.borderBottomColor = 'transparent'; t.style.color = '#64748b'; });
        document.querySelector('#viewModal .vtab').style.borderBottomColor = '#2563eb';
        document.querySelector('#viewModal .vtab').style.color = '#2563eb';
        document.querySelectorAll('#viewModal .vtab-content').forEach(t => t.style.display = 'none');
        document.getElementById('tabProfile').style.display = '';
        loadAcademic(s.id);
        loadHealth(s.id);
        loadDocuments(s.id);
        loadRfid(s.id);
        document.getElementById('viewModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }).catch(() => alert('Failed to load.'));
}
function switchVTab(btn, tab) {
    document.querySelectorAll('#viewModal .vtab').forEach(t => { t.style.borderBottomColor = 'transparent'; t.style.color = '#64748b'; });
    btn.style.borderBottomColor = '#2563eb';
    btn.style.color = '#2563eb';
    document.querySelectorAll('#viewModal .vtab-content').forEach(t => t.style.display = 'none');
    document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).style.display = '';
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeViewModal(); });

function loadAcademic(sid) {
    fetch('../api/students.php?action=academic&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vAcademic');
        if (!d.success || !d.data || !d.data.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No academic history found.</p>'; return; }
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>School</td><td>Year</td><td>GWA</td></tr>' + d.data.map(a => '<tr style="border-bottom:1px solid #f1f5f9;"><td>' + (a.school_name || '') + '</td><td>' + (a.school_year || '') + '</td><td>' + (a.gwa || '—') + '</td></tr>').join('') + '</table>';
    }).catch(() => {});
}
function loadHealth(sid) {
    fetch('../api/students.php?action=health&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vHealth');
        if (!d.success || !d.data) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No health record.</p>'; return; }
        const h = d.data;
        el.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><div><div class="lbl" style="font-size:10px;color:#94a3b8;">Blood Type</div><div class="val" style="font-weight:600;">' + (h.blood_type || '—') + '</div></div><div><div class="lbl" style="font-size:10px;color:#94a3b8;">Height / Weight</div><div class="val" style="font-weight:600;">' + (h.height ? h.height + 'cm' : '—') + ' / ' + (h.weight ? h.weight + 'kg' : '—') + '</div></div><div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;">Allergies</div><div class="val">' + (h.allergies || 'None') + '</div></div><div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;">Conditions</div><div class="val">' + (h.pre_existing_conditions || 'None') + '</div></div></div>';
    }).catch(() => {});
}
function loadDocuments(sid) {
    fetch('../api/students.php?action=documents&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vDocuments');
        if (!d.success || !d.data || !d.data.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No document requests.</p>'; return; }
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Type</td><td>Status</td><td>Date</td></tr>' + d.data.map(dr => '<tr style="border-bottom:1px solid #f1f5f9;"><td>' + ucfirst((dr.document_type || '').replace('_', ' ')) + '</td><td><span class="badge badge-' + (dr.status === 'pending' ? 'warning' : dr.status === 'completed' || dr.status === 'approved' ? 'success' : 'neutral') + '">' + ucfirst(dr.status || '') + '</span></td><td>' + (dr.request_date ? new Date(dr.request_date).toLocaleDateString() : '') + '</td></tr>').join('') + '</table>';
    }).catch(() => {});
}
function loadRfid(sid) {
    fetch('../api/rfid.php?student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vRfid');
        if (!d.success || !d.data || !d.data.length) {
            el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No RFID card assigned. <a href="rfid-cards.php" style="color:#2563eb;">Assign a card →</a></p>';
            return;
        }
        const card = d.data[0];
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Card UID</td><td>Status</td><td>Expiry</td></tr><tr><td><code>' + card.card_uid + '</code></td><td><span class="badge badge-' + (card.status === 'active' ? 'success' : 'warning') + '">' + ucfirst(card.status) + '</span></td><td>' + (card.expiry_date || '—') + '</td></tr></table><p style="margin-top:10px;"><a href="rfid-scan-logs.php?search=' + encodeURIComponent(card.card_uid) + '" class="btn btn-secondary btn-sm"><i class="fas fa-clock-rotate-left"></i> View Scan Logs</a></p>';
    }).catch(() => {});
}

function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

let wsContext = null;

// ─── CREATE SECTION MODAL ────────────────────────────────────
document.getElementById('btnCreateSection').addEventListener('click', openCreateSectionModal);

function openCreateSectionModal() {
    document.getElementById('csCourse').value = '';
    document.getElementById('csYear').value = '';
    document.getElementById('csSemester').value = '';
    document.getElementById('csCodeOverride').value = '';
    document.getElementById('csError').style.display = 'none';
    document.getElementById('csCode').textContent = '—';
    document.getElementById('createSectionModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeCreateSectionModal() {
    document.getElementById('createSectionModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('createSectionModal').addEventListener('click', function (e) { if (e.target === this) closeCreateSectionModal(); });

// Live "next section code" preview when course/year/semester change
['csCourse', 'csYear', 'csSemester'].forEach(id => {
    document.getElementById(id).addEventListener('change', refreshSectionCode);
});
async function refreshSectionCode() {
    const course = document.getElementById('csCourse').value;
    const year = document.getElementById('csYear').value;
    const sem = document.getElementById('csSemester').value;
    const codeEl = document.getElementById('csCode');
    if (!course || !year || !sem) { codeEl.textContent = '—'; return; }
    try {
        const r = await fetch('../api/masterlist.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'next_section', course, year_level: parseInt(year), semester: sem })
        });
        const d = await r.json();
        if (d.success) codeEl.textContent = d.code;
    } catch (e) {}
}

function createSectionAndOpen() {
    const course = document.getElementById('csCourse').value;
    const year = document.getElementById('csYear').value;
    const sem = document.getElementById('csSemester').value;
    const schoolYear = document.getElementById('csSchoolYear').value.trim();
    const adviserId = document.getElementById('csAdviser').value;
    const override = document.getElementById('csCodeOverride').value.trim();
    const errorEl = document.getElementById('csError');

    if (!course || !year || !sem) {
        errorEl.textContent = 'Please select course, year level, and semester.';
        errorEl.style.display = 'block';
        return;
    }
    let code = override || document.getElementById('csCode').textContent;
    if (code === '—' || code === '') {
        errorEl.textContent = 'Could not determine the section code.';
        errorEl.style.display = 'block';
        return;
    }
    closeCreateSectionModal();
    openSectionWorkspace({
        course, year_level: parseInt(year), semester: sem,
        school_year: schoolYear, adviser_id: adviserId || '', section: code
    });
}

// ─── EDIT SECTION ────────────────────────────────────────────
let editSectionContext = null;

function openEditSection(ctx) {
    editSectionContext = ctx;
    document.getElementById('esOldSection').textContent = ctx.section;
    document.getElementById('esSection').value = ctx.section;
    document.getElementById('esSchoolYear').value = ctx.school_year || '';
    document.getElementById('esCourse').value = ctx.course || '';
    document.getElementById('esYear').value = ctx.year_level || '';
    document.getElementById('esSemester').value = ctx.semester || '';
    document.getElementById('esAdviser').value = ctx.adviser_id || '';
    // Estimate student count from ASSIGNABLE_STUDENTS (may be filtered view; fine as an estimate)
    const cnt = ASSIGNABLE_STUDENTS.filter(s =>
        (s.course || '') === ctx.course && String(s.year_level || '') === String(ctx.year_level || '') && (s.section || '') === ctx.section
    ).length;
    document.getElementById('esStudentCount').textContent = cnt || '?';
    document.getElementById('esError').style.display = 'none';
    document.getElementById('editSectionModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeEditSection() {
    document.getElementById('editSectionModal').classList.remove('active');
    document.body.style.overflow = '';
    editSectionContext = null;
}
document.getElementById('editSectionModal').addEventListener('click', function (e) { if (e.target === this) closeEditSection(); });

async function saveEditSection() {
    if (!editSectionContext) return;
    const ctx = editSectionContext;
    const newSection = document.getElementById('esSection').value.trim();
    const newCourse = document.getElementById('esCourse').value;
    const newYear = document.getElementById('esYear').value;
    const newSem = document.getElementById('esSemester').value;
    const newSy = document.getElementById('esSchoolYear').value.trim();
    const newAdviser = document.getElementById('esAdviser').value;
    const errEl = document.getElementById('esError');

    if (!newSection || !newCourse || !newYear || !newSem) {
        errEl.textContent = 'Section code, course, year, and semester are required.';
        errEl.style.display = 'block';
        return;
    }
    if (!confirm('Update section ' + ctx.section + ' to ' + newSection + '? This changes all students in the section.')) return;

    const btn = event.target;
    btn.disabled = true;
    try {
        const r = await fetch('../api/masterlist.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'edit_section',
                old_course: ctx.course, old_year_level: ctx.year_level, old_semester: ctx.semester, old_section: ctx.section,
                course: newCourse, year_level: newYear, semester: newSem, section: newSection, school_year: newSy, adviser_id: newAdviser || null
            })
        });
        const d = await r.json();
        if (d.success) {
            alert(d.message);
            window.location.reload();
        } else {
            errEl.textContent = d.message || 'Failed to update section.';
            errEl.style.display = 'block';
            btn.disabled = false;
        }
    } catch (e) {
        alert('Network error.');
        btn.disabled = false;
    }
}

// ─── SECTION WORKSPACE ───────────────────────────────────────
function openSectionWorkspace(ctx) {
    wsContext = ctx;
    document.getElementById('wsTitle').textContent = ctx.course + ' — Year ' + ctx.year_level + ' — Sem ' + (ctx.semester || '1st') + ' — Section ' + ctx.section;

    // Lock the target to the existing section that was clicked — no new sections here.
    document.getElementById('wsTargetSection').value = ctx.section + ' — ' + ctx.course + ' · Y' + (ctx.year_level || '?') + ' · ' + (ctx.semester || '1st');

    document.getElementById('wsAssignSearch').value = '';
    document.getElementById('wsIncludeOthers').checked = false;
    renderAssignList();
    document.getElementById('sectionWorkspaceModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeSectionWorkspace() {
    document.getElementById('sectionWorkspaceModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('sectionWorkspaceModal').addEventListener('click', function (e) { if (e.target === this) closeSectionWorkspace(); });

// ── Assign Existing Students ────────────────────────────────
function renderAssignList() {
    const q = (document.getElementById('wsAssignSearch').value || '').toLowerCase().trim();
    const includeAssigned = document.getElementById('wsIncludeOthers').checked;
    const listEl = document.getElementById('wsAssignList');
    let students = ASSIGNABLE_STUDENTS;

    // Default: only students WITHOUT a section. Checkbox reveals already-assigned.
    if (!includeAssigned) {
        students = students.filter(s => !(s.section || '').trim());
    }
    // Match the workspace's course/year unless the user searched or wants everyone
    if (!q && !includeAssigned && wsContext) {
        students = students.filter(s =>
            (!(s.course || '').trim() || (s.course || '') === wsContext.course) &&
            (!s.year_level || String(s.year_level) === String(wsContext.year_level))
        );
    }
    if (q) {
        students = students.filter(s => ((s.first_name + ' ' + s.last_name + ' ' + s.student_number).toLowerCase().includes(q)));
    }
    if (!students.length) {
        listEl.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:30px;">No students without a section found.</p>';
        return;
    }
    let html = '<table style="width:100%;font-size:12px;border-collapse:collapse;"><tr style="background:#f8fafc;color:#64748b;font-weight:600;"><th style="padding:8px;"></th><th style="padding:8px;text-align:left;">Student</th><th style="padding:8px;text-align:left;">Course</th><th style="padding:8px;text-align:left;">Yr</th><th style="padding:8px;text-align:left;">Section</th></tr>';
    students.forEach(s => {
        html += '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px;"><input type="checkbox" class="ws-assign-cb" value="' + s.id + '" style="width:15px;height:15px;accent-color:#2563eb;"></td><td style="padding:6px;"><strong>' + s.first_name + ' ' + s.last_name + '</strong><br><span style="color:#94a3b8;">' + (s.student_number || '') + '</span></td><td style="padding:6px;">' + (s.course || '—') + '</td><td style="padding:6px;">' + (s.year_level || '—') + '</td><td style="padding:6px;">' + (s.section || '—') + '</td></tr>';
    });
    html += '</table>';
    listEl.innerHTML = html;
}
document.getElementById('wsAssignSearch').addEventListener('input', renderAssignList);
document.getElementById('wsIncludeOthers').addEventListener('change', renderAssignList);

async function assignSelectedToSection() {
    const ids = Array.from(document.querySelectorAll('.ws-assign-cb:checked')).map(cb => cb.value);
    if (!ids.length) { alert('Select at least one student.'); return; }
    if (!wsContext || !wsContext.section) { alert('No target section. Open this from a section card.'); return; }
    const section = wsContext.section;
    const ctx = {
        course: wsContext.course || '',
        year_level: wsContext.year_level || '',
        semester: wsContext.semester || '',
        school_year: wsContext.school_year || '',
        adviser_id: wsContext.adviser_id || ''
    };
    if (!confirm('Assign ' + ids.length + ' student(s) to section ' + section + '? Students will be moved to ' + (ctx.course || 'the section\'s course') + ' / Year ' + (ctx.year_level || '?') + '.')) return;
    const btn = event.target;
    btn.disabled = true;
    try {
        const r = await fetch('../api/masterlist.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'bulk_assign_section', ids, section, course: ctx.course, year_level: ctx.year_level, semester: ctx.semester, school_year: ctx.school_year, adviser_id: ctx.adviser_id })
        });
        const d = await r.json();
        alert(d.message || 'Assigned.');
        if (d.success) window.location.reload();
    } catch (e) {
        alert('Error.');
    } finally {
        btn.disabled = false;
    }
}

// ─── ESC CLOSE ───────────────────────────────────────────────
// ??? SEARCH & FILTER MODAL ????????????????????????????????
// SMART SEARCH: natural language -> masterlist filters
const aiSearchBtn = document.getElementById('aiSearchBtn');
const aiInterpretation = document.getElementById('aiInterpretation');
const aiExplanation = document.getElementById('aiExplanation');

function urlParamSafe(val) {
    return val !== undefined && val !== null && String(val).trim() !== '';
}

async function runAiSearch() {
    const query = searchInput.value.trim();
    if (query.length < 3) {
        alert('Type at least 3 characters for the AI search.');
        return;
    }
    aiSearchBtn.disabled = true;
    aiSearchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> AI';
    aiInterpretation.style.display = 'none';
    try {
        const res = await fetch('../api/masterlist-ai-search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query })
        });
        const data = await res.json();
        if (!data.success || !data.data) {
            throw new Error(data.message || 'AI search failed.');
        }
        const f = data.data.filter || {};
        const p = new URLSearchParams();
        if (urlParamSafe(f.course)) p.set('course', f.course);
        if (urlParamSafe(f.year_level)) p.set('year_level', f.year_level);
        if (urlParamSafe(f.school_year)) p.set('school_year', f.school_year);
        if (urlParamSafe(f.semester)) p.set('semester', f.semester);
        if (urlParamSafe(f.section)) p.set('section', f.section);
        if (urlParamSafe(f.status)) p.set('status', f.status);
        if (Array.isArray(f.keywords) && f.keywords.length) p.set('q', f.keywords.join(' '));
        aiExplanation.textContent = f.explanation || 'Filters applied.';
        aiInterpretation.style.display = 'block';
        const target = 'masterlist.php' + (p.toString() ? '?' + p.toString() : '');
        setTimeout(() => { window.location.href = target; }, 700);
    } catch (err) {
        console.error(err);
        aiExplanation.textContent = 'AI search failed. Check that the AI server is running, or use the filters below.';
        aiExplanation.style.color = '#b91c1c';
        aiInterpretation.style.background = '#fef2f2';
        aiInterpretation.style.borderColor = '#fecaca';
        aiInterpretation.style.display = 'block';
    } finally {
        aiSearchBtn.disabled = false;
        aiSearchBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles" style="color:#7c3aed;"></i> AI';
    }
}

if (aiSearchBtn) {
    aiSearchBtn.addEventListener('click', runAiSearch);
}

// Restore a search query passed via ?q=
const qParam = new URLSearchParams(window.location.search).get('q');
if (qParam && searchInput) {
    searchInput.value = qParam;
    searchInput.dispatchEvent(new Event('input'));
}

function openFilterSearchModal() {
    document.getElementById('filterSearchModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeFilterSearchModal() {
    document.getElementById('filterSearchModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('filterSearchModal').addEventListener('click', function (e) { if (e.target === this) closeFilterSearchModal(); });

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeViewModal(); closeGenerateModal(); closeFilterSearchModal(); closeCreateSectionModal(); closeSectionWorkspace(); closeEditSection(); }
});
</script>

<style>
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal-content { background: white; border-radius: 20px; padding: 28px 32px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.15); animation: modalSlide .3s ease; }
@keyframes modalSlide { from { opacity: 0; transform: translateY(20px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-close { width: 34px; height: 34px; border: none; background: #f1f5f9; border-radius: 50%; cursor: pointer; font-size: 15px; color: #94a3b8; }
.modal-close:hover { background: #e2e8f0; color: #1e293b; }
.modal-footer { display: flex; gap: 10px; justify-content: flex-end; padding-top: 14px; border-top: 1px solid #e8edf4; }
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.10); }
.bulk-bar a { text-decoration: none; }
/* Filter dropdowns should look clickable */
#filterCourse, #filterYear, #filterSchoolYear, #filterSemester, #filterStatus,
#genSchoolYear, #genSemester, #genCourse, #genYear, #genStatus,
select.form-control { cursor: pointer !important; }
@media print {
    .sidebar, .header-actions, .form-row, .btn, .bulk-bar, #masterlistSearch, #selectAllPage, .modal-overlay { display: none !important; }
    .masterlist-section-block { break-inside: avoid; page-break-inside: avoid; }
    .masterlist-table th[data-sort] i { display: none; }
    #masterlistContent { margin: 0; }
}
</style>

<?php include '../includes/footer.php'; ?>
