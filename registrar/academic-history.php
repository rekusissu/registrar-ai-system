<?php
// ============================================================
//  REGISTRAR/ACADEMIC-HISTORY.PHP
//  Student-level academic history viewer + enrollment intake
//  import. Records are read-only here; they come from the
//  enrollment dashboard (previous school) or other modules.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

// All academic records, newest first per student.
$records = $db->fetchAll("
    SELECT a.*, CONCAT(s.first_name,' ',s.last_name) AS student_name,
           s.student_number, s.course
    FROM academic_history a
    JOIN students s ON a.student_id = s.id
    WHERE s.status != 'archived'
    ORDER BY s.last_name, s.first_name, a.created_at DESC
");

// Group by student for the student-level table.
$studentRows = [];
foreach ($records as $r) {
    $sid = (int) $r['student_id'];
    if (!isset($studentRows[$sid])) {
        $studentRows[$sid] = [
            'student_id'     => $sid,
            'student_number' => (string) $r['student_number'],
            'student_name'   => (string) $r['student_name'],
            'course'         => (string) ($r['course'] ?? ''),
            'records'        => [],
        ];
    }
    $studentRows[$sid]['records'][] = $r;
}

// Per-record subject grades for the read-only view modal.
$recordIds = array_map('intval', array_column($records, 'id'));
$gradesByRecord = [];
if ($recordIds) {
    $in = implode(',', $recordIds);
    foreach ($db->fetchAll("SELECT * FROM academic_grades WHERE academic_history_id IN ($in) ORDER BY id ASC") as $g) {
        $gradesByRecord[(int) $g['academic_history_id']][] = $g;
    }
}

// JSON payload for the view modal (records + grades per student).
$acadData = [];
foreach ($studentRows as $sid => $s) {
    $recs = [];
    foreach ($s['records'] as $r) {
        $recs[] = [
            'id'       => (int) $r['id'],
            'school'   => (string) $r['school_name'],
            'year'     => (string) ($r['school_year'] ?? ''),
            'grade'    => (string) ($r['grade_level'] ?? ''),
            'gwa'      => $r['gwa'] !== null ? (string) $r['gwa'] : '',
            'semester' => (string) ($r['semester'] ?? ''),
            'remarks'  => (string) ($r['remarks'] ?? ''),
            'grades'   => array_map(fn($g) => [
                'subject' => (string) ($g['subject'] ?? ''),
                'units'   => (string) ($g['units'] ?? ''),
                'grade'   => (string) ($g['grade'] ?? ''),
                'remarks' => (string) ($g['remarks'] ?? ''),
            ], $gradesByRecord[(int) $r['id']] ?? []),
        ];
    }
    $acadData[$sid] = $recs;
}

// All students (for the Receive Record list, regardless of current records).
$allStudents = $db->fetchAll("
    SELECT s.id, s.student_number,
           CONCAT(s.first_name,' ',s.last_name) AS student_name
    FROM students s
    WHERE s.status != 'archived'
    ORDER BY s.last_name, s.first_name
");

$receiveData = array_map(fn($s) => [
    'id'     => (int) $s['id'],
    'number' => (string) ($s['student_number'] ?? ''),
    'name'   => (string) $s['student_name'],
    'count'  => isset($studentRows[(int) $s['id']]) ? count($studentRows[(int) $s['id']]['records']) : 0,
], $allStudents);

// ── Metric strip ──────────────────────────────────
// The registrar's job on this page is chasing students whose previous-school
// records haven't been imported yet, so "awaiting" is a real figure rather
// than a vanity count. Coverage shows whether it's a rounding error or half
// the roster. Arrow functions auto-capture, so no explicit `use` is needed.
$statStudents = count($studentRows);
$statRecords  = count($records);
$statSchools  = count(array_unique(
    array_filter(array_map(fn($r) => trim((string) $r['school_name']), $records))
));
$statPool     = count($receiveData);
$statAwaiting = count(array_filter($receiveData, fn($s) => $s['count'] === 0));
$coveragePct  = $statPool > 0
    ? (int) round((($statPool - $statAwaiting) / $statPool) * 100)
    : 0;

/** Two-letter initials from a name, for the student avatar tile. */
function ah_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    $sub = fn($s) => function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    $up  = fn($s) => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $init = $up($sub($parts[0]));
    if (count($parts) > 1) $init .= $up($sub(end($parts)));
    return $init;
}

$page_title = 'Academic History';
$body_page = 'academic';
$APP_ROOT = '../';
$ACTIVE_NAV = 'academic';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* ============================================================
   Academic History — registrar-blue layer.
   Mirrors registrar/guardians.php and registrar/rfid-cards.php:
   same header, same metric strip, same panel/table/modals.
   Scoped to body[data-page="academic"] so nothing leaks into the
   other registrar pages.
   ============================================================ */
body[data-page="academic"]{background:#f5f7fb;color:#0f172a}

/* ── Page header ───────────────────────────────── */
body[data-page="academic"] .header{
    display:flex;align-items:flex-end;justify-content:space-between;gap:20px;
    flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;
    border:1px solid #c7d7fe;border-radius:19px;
    background:linear-gradient(120deg,#eff6ff,#fff 68%);
    box-shadow:0 10px 30px rgba(37,99,235,.08);
}
body[data-page="academic"] .header .title h1{
    margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554;
}
body[data-page="academic"] .header .title p{
    margin:0;max-width:620px;font-size:12.5px;line-height:1.5;color:#64748b;
}
.ah-kicker{
    display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;
    font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
body[data-page="academic"] .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

/* ── Metric strip ───────────────────────────────── */
body[data-page="academic"] .ah-stats{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;
    margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
}
body[data-page="academic"] .ah-stat{
    position:relative;background:transparent;border:0;border-radius:0;padding:17px 20px;
    border-right:1px solid #e2e8f0;box-shadow:none;transition:none;
}
body[data-page="academic"] .ah-stat:last-child{border-right:0}
body[data-page="academic"] .ah-stat::after{
    content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe;
}
body[data-page="academic"] .ah-stat:hover{transform:none;box-shadow:none;border-color:transparent}
body[data-page="academic"] .ah-stat .ah-stat-top{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    margin:0 0 6px;min-height:16px;
}
body[data-page="academic"] .ah-stat .ah-stat-number{
    font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;
    font-variant-numeric:tabular-nums;
}
body[data-page="academic"] .ah-stat .ah-stat-label{
    color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;
    text-transform:uppercase;margin-top:2px;line-height:1.3;
}
body[data-page="academic"] .ah-stat-badge{
    font-size:10px;font-weight:600;padding:1px 7px;border-radius:9999px;
    display:inline-flex;align-items:center;gap:4px;
}
body[data-page="academic"] .ah-stat-badge.warn{color:#b45309;background:#fef3c7}
/* Each accent needs the same specificity as the base ::after rule; an
   unprefixed .ak-students (0-1-0) loses and every cell goes pale blue. */
body[data-page="academic"] .ah-stat.ak-students::after{background:#1d4ed8}
body[data-page="academic"] .ah-stat.ak-records::after{background:#16a34a}
body[data-page="academic"] .ah-stat.ak-schools::after{background:#6366f1}
body[data-page="academic"] .ah-stat.ak-awaiting::after{background:#d97706}

/* ── Panel + toolbar ───────────────────────────── */
body[data-page="academic"] .ah-panel{padding:0;overflow:hidden}
body[data-page="academic"] .ah-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
    padding:15px 20px;background:#f8faff;border-bottom:1px solid #e2e8f0;
}
body[data-page="academic"] .ah-toolbar-title{
    display:flex;align-items:center;gap:10px;font-size:15px;font-weight:800;
    color:#0d1b2e;letter-spacing:-.3px;
}
body[data-page="academic"] .ah-toolbar-title i{color:#2563eb}
body[data-page="academic"] .ah-pill{
    padding:2px 9px;border-radius:999px;background:#e0ecff;color:#1d4ed8;
    font-size:11px;font-weight:800;font-variant-numeric:tabular-nums;
}
body[data-page="academic"] .ah-toolbar .search-wrap{
    position:relative;flex:1 1 320px;min-width:220px;max-width:420px;margin-left:auto;
}
body[data-page="academic"] .ah-toolbar .search-wrap i{
    position:absolute;left:13px;top:50%;transform:translateY(-50%);
    color:#94a3b8;font-size:14px;pointer-events:none;
}
body[data-page="academic"] .ah-toolbar .search-wrap input{
    width:100%;height:38px;padding:0 13px 0 36px;box-sizing:border-box;
    border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;
    outline:none;background:#fff;transition:all .2s ease;
}
body[data-page="academic"] .ah-toolbar .search-wrap input:focus{
    border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1);background:#fff;
}

/* ── Table ──────────────────────────────────────── */
body[data-page="academic"] .ah-table{
    width:100%;border-collapse:collapse;background:#fff;margin:0;
    table-layout:fixed;min-width:760px;
}
body[data-page="academic"] .ah-table th{
    font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;
    background:#fbfcfe;padding:13px 18px;text-align:left;
    border-bottom:1px solid #eef2f7;font-weight:800;
}
body[data-page="academic"] .ah-table td{
    padding:11px 16px;border-top:1px solid #f1f5f9;color:#334155;
    font-size:12.5px;vertical-align:top;
}
body[data-page="academic"] .ah-table td:last-child{vertical-align:middle;text-align:center}
body[data-page="academic"] .ah-table tbody tr{transition:background .14s ease}
body[data-page="academic"] .ah-table tbody tr:hover{background:#f8fbff}
body[data-page="academic"] .ah-table thead th:nth-child(1){width:34%}
body[data-page="academic"] .ah-table thead th:nth-child(2){width:22%}
body[data-page="academic"] .ah-table thead th:nth-child(3){width:30%}
body[data-page="academic"] .ah-table thead th:nth-child(4){width:14%;text-align:center}
body[data-page="academic"] .ah-table th .ah-h{display:inline-flex;align-items:center;gap:6px}
body[data-page="academic"] .ah-table th .ah-h i{font-size:10px}
body[data-page="academic"] .ah-table th:nth-child(2) .ah-h{color:#1d4ed8}
body[data-page="academic"] .ah-table th:nth-child(2) .ah-h i{color:#2563eb}
body[data-page="academic"] .ah-table th:nth-child(3) .ah-h{color:#4f46e5}
body[data-page="academic"] .ah-table th:nth-child(3) .ah-h i{color:#6366f1}
body[data-page="academic"] .ah-table th:last-child .ah-h{color:#64748b}
body[data-page="academic"] .ah-table th:last-child .ah-h i{color:#94a3b8}

body[data-page="academic"] .ah-who{display:flex;align-items:center;gap:10px}
body[data-page="academic"] .ah-avatar{
    display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;
    border-radius:11px;font-size:12.5px;font-weight:800;color:#fff;
    background:linear-gradient(140deg,#2563eb,#1d4ed8);
}
body[data-page="academic"] .ah-name{font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.3}
body[data-page="academic"] .ah-num{
    margin-top:1px;font-family:'JetBrains Mono',ui-monospace,monospace;
    font-size:11px;color:#64748b;
}
body[data-page="academic"] .ah-count{
    display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;
    background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;
    font-variant-numeric:tabular-nums;
}
body[data-page="academic"] .ah-count i{font-size:9.5px}
body[data-page="academic"] .ah-course{margin-top:4px;font-size:11px;color:#94a3b8}
body[data-page="academic"] .ah-school{font-size:12.5px;color:#0f172a;font-weight:600;line-height:1.4}

/* Empty state — says what happened and what to do next. */
body[data-page="academic"] .ah-empty{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:8px;min-height:300px;text-align:center;padding:20px;
}
body[data-page="academic"] .ah-empty i{
    display:grid;place-items:center;width:52px;height:52px;margin-bottom:4px;
    border-radius:16px;background:#eff6ff;color:#2563eb;font-size:20px;
}
body[data-page="academic"] .ah-empty p{margin:0;font-size:16px;font-weight:700;color:#334155}
body[data-page="academic"] .ah-empty span{margin:0;color:#64748b;font-size:13px}
body[data-page="academic"] .ah-empty strong{color:#1d4ed8}

body[data-page="academic"] .ah-table .action-btn{
    width:30px;height:30px;border-radius:9px;border:1px solid #e2e8f0;
    background:#fff;color:#475569;transition:all .18s ease;
}
body[data-page="academic"] .ah-table .action-btn:hover{
    background:#2563eb;border-color:#2563eb;color:#fff;
    box-shadow:0 5px 14px rgba(37,99,235,.28);transform:translateY(-1px);
}
body[data-page="academic"] .table-footer{padding:11px 18px;background:#f8faff;border-top:1px solid #e2e8f0}
body[data-page="academic"] .table-footer .info-text{font-size:12px;color:#64748b}
body[data-page="academic"] .table-footer .info-text strong{color:#0f172a;font-variant-numeric:tabular-nums}

/* ── Modals ──────────────────────────────────────
   Pinned gradient header, independently scrolling body, pinned footer —
   the same shell as the Manage Contacts / RFID modals. */
body[data-page="academic"] .modal-content.ah-modal{
    box-sizing:border-box;display:flex;flex-direction:column;padding:0;
    max-width:680px;max-height:calc(100vh - 40px);overflow:hidden;
    border:1px solid #dbeafe;border-radius:19px;
    box-shadow:0 26px 64px rgba(15,23,42,.24);
}
body[data-page="academic"] .modal-header.ah-modal-head{
    flex:0 0 auto;display:flex;align-items:center;gap:12px;margin:0;padding:18px 22px;
    background:linear-gradient(120deg,#eff6ff,#fff 70%);
    border-bottom:1px solid #dbeafe;border-radius:0;
}
body[data-page="academic"] .modal-header.ah-modal-head h2{
    display:flex;align-items:center;gap:10px;margin:0;
    font-size:16px;font-weight:700;letter-spacing:-.02em;color:#172554;
}
body[data-page="academic"] .modal-header.ah-modal-head h2 i{
    display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;
    border-radius:10px;background:linear-gradient(140deg,#2563eb,#1d4ed8);
    color:#fff;font-size:14px;box-shadow:0 6px 16px rgba(37,99,235,.26);
}
body[data-page="academic"] .modal-close{
    display:grid;place-items:center;width:32px;height:32px;margin-left:auto;
    border:1px solid #dbeafe;border-radius:9px;background:#fff;color:#64748b;
}
body[data-page="academic"] .modal-close:hover{background:#f1f5f9;color:#0f172a}
body[data-page="academic"] .modal-body{
    flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;
    margin:0;padding:20px 22px;background:#fff;
}
body[data-page="academic"] .modal-footer{
    flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:9px;
    margin:0;padding:14px 22px;background:#f8faff;border-top:1px solid #e2e8f0;
}
body[data-page="academic"] .ah-note{
    display:flex;gap:9px;margin:0 0 14px;padding:11px 13px;border-radius:11px;
    background:#eff6ff;border:1px solid #dbeafe;color:#334155;
    font-size:12.5px;line-height:1.55;
}
body[data-page="academic"] .ah-note i{color:#2563eb;margin-top:1px}
body[data-page="academic"] .ah-note strong{color:#1d4ed8}
body[data-page="academic"] .ah-no-match{text-align:center;color:#94a3b8;padding:24px;font-size:13px}

body[data-page="academic"] .ah-modal .acad-search{position:relative;margin-bottom:10px}
body[data-page="academic"] .ah-modal .acad-search i{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    color:#94a3b8;font-size:13px;pointer-events:none;
}
body[data-page="academic"] .ah-modal .acad-search input{
    width:100%;height:38px;box-sizing:border-box;padding:0 12px 0 34px;
    border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;
    outline:none;background:#fff;
}
body[data-page="academic"] .ah-modal .acad-search input:focus{
    border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1);
}
body[data-page="academic"] .ah-modal .acad-search input::placeholder{color:#94a3b8}
/* ── Receive modal: a worklist, not a directory ─────
   Split into two groups, backlog first. The amber left rail,
   tinted row and amber avatar mark the students who actually
   need work; rows that are already on file stay flat and
   quiet so the contrast reads as the backlog. */
body[data-page="academic"] #receiveList{
    max-height:52vh;overflow-y:auto;border:1px solid #eef2f7;border-radius:12px;
    background:#fff;
}
/* Sticky so the group a row belongs to is always in view. */
body[data-page="academic"] .rv-group{
    position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:8px;
    padding:9px 14px;background:#f8fafc;border-bottom:1px solid #eef2f7;
    font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;
}
body[data-page="academic"] .rv-group.is-awaiting{background:#fffbeb;color:#b45309}
body[data-page="academic"] .rv-group-n{
    padding:1px 7px;border-radius:999px;background:#e2e8f0;color:#475569;
    font-size:10px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:0;
}
body[data-page="academic"] .rv-group.is-awaiting .rv-group-n{background:#fde68a;color:#92400e}
body[data-page="academic"] .receive-row{
    display:flex;align-items:center;gap:11px;padding:10px 14px;
    border-bottom:1px solid #f1f5f9;border-left:3px solid transparent;
    background:#fff;transition:background .14s ease;
}
body[data-page="academic"] .receive-row:last-child{border-bottom:none}
body[data-page="academic"] .receive-row:hover{background:#f8fbff}
body[data-page="academic"] .receive-row.is-awaiting{background:#fffdf7;border-left-color:#f59e0b}
body[data-page="academic"] .receive-row.is-awaiting:hover{background:#fffbeb}
body[data-page="academic"] .rv-avatar{
    display:grid;place-items:center;width:30px;height:30px;flex:0 0 30px;
    border-radius:10px;font-size:11.5px;font-weight:800;color:#fff;
    background:linear-gradient(140deg,#64748b,#475569);
}
body[data-page="academic"] .receive-row.is-awaiting .rv-avatar{
    background:linear-gradient(140deg,#f59e0b,#d97706);
}
body[data-page="academic"] .rv-who{flex:1;min-width:0}
body[data-page="academic"] .rv-name{font-size:13px;font-weight:700;color:#0f172a;line-height:1.3}
body[data-page="academic"] .rv-sub{
    margin-top:1px;font-family:'JetBrains Mono',ui-monospace,monospace;
    font-size:10.5px;color:#94a3b8;
}
body[data-page="academic"] .rv-status{
    flex:0 0 auto;padding:3px 9px;border-radius:999px;white-space:nowrap;
    font-size:10.5px;font-weight:800;font-variant-numeric:tabular-nums;
    background:#f1f5f9;color:#64748b;
}
body[data-page="academic"] .rv-status.is-awaiting{background:#fef3c7;color:#b45309}
body[data-page="academic"] .rv-import{
    flex:0 0 auto;height:30px;min-width:84px;padding:0 12px;
    font-size:11.5px;border-radius:8px;justify-content:center;
}
body[data-page="academic"] .rv-import:disabled{opacity:.65;cursor:progress}

/* Student header inside the read-only view */
body[data-page="academic"] .ah-view-head{
    display:flex;align-items:center;gap:12px;margin-bottom:14px;
    padding-bottom:14px;border-bottom:1px solid #eef2f7;
}
body[data-page="academic"] .ah-view-name{font-size:15px;font-weight:800;color:#0f172a}
body[data-page="academic"] .ah-view-sub{font-size:12px;color:#64748b;margin-top:2px}

/* One record card = one school year. */
body[data-page="academic"] .ah-record{
    border:1px solid #eef2f7;border-radius:13px;padding:14px 16px;margin-bottom:10px;
    background:#fbfcfe;
}
body[data-page="academic"] .ah-record:last-child{margin-bottom:0}
body[data-page="academic"] .ah-record-head{
    display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;
}
body[data-page="academic"] .ah-record-school{font-size:13.5px;font-weight:800;color:#0f172a}
body[data-page="academic"] .gwa-badge{
    display:inline-flex;padding:3px 10px;border-radius:999px;
    font-size:11.5px;font-weight:800;background:#e0ecff;color:#1d4ed8;
    font-variant-numeric:tabular-nums;white-space:nowrap;
}
body[data-page="academic"] .ah-row{display:flex;gap:10px;font-size:12.5px;padding:4px 0;line-height:1.5}
body[data-page="academic"] .ah-row span:first-child{color:#64748b}
body[data-page="academic"] .ah-remarks{color:#475569}
body[data-page="academic"] .ah-grades{margin-top:9px;border-top:1px dashed #e2e8f0;padding-top:9px}
body[data-page="academic"] .ah-grades table{width:100%;border-collapse:collapse;font-size:12px}
body[data-page="academic"] .ah-grades th{
    text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;
    color:#94a3b8;padding:4px 6px;font-weight:800;
}
body[data-page="academic"] .ah-grades td{padding:5px 6px;border-top:1px solid #f1f5f9;color:#334155}
body[data-page="academic"] .ah-none{
    padding:26px;text-align:center;color:#64748b;background:#f8fafc;border-radius:13px;
    font-size:13px;line-height:1.6;
}
body[data-page="academic"] .ah-none strong{color:#1d4ed8}

@media (max-width:1200px){
    body[data-page="academic"] .ah-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    body[data-page="academic"] .ah-stat:nth-child(2n){border-right:0}
    body[data-page="academic"] .ah-stat:nth-child(-n+2){border-bottom:1px solid #e2e8f0}
}
@media (max-width:640px){
    body[data-page="academic"] .ah-stats{grid-template-columns:1fr}
    body[data-page="academic"] .ah-stat{border-right:0}
    body[data-page="academic"] .ah-stat + .ah-stat{border-bottom:1px solid #e2e8f0}
    body[data-page="academic"] .header{padding:21px 18px}
    body[data-page="academic"] .ah-toolbar{flex-direction:column;align-items:stretch}
    /* The search box is `flex:1 1 320px` — a WIDTH basis. Once the toolbar
       flips to a column, that same basis applies to height and inflates the
       wrapper to 320px tall. Reset it to a normal block. */
    body[data-page="academic"] .ah-toolbar .search-wrap{
        flex:0 0 auto;width:100%;max-width:none;margin-left:0;
    }
    body[data-page="academic"] .ah-record-head{flex-direction:column;align-items:flex-start;gap:6px}
}
@media (prefers-reduced-motion:reduce){
    body[data-page="academic"] .ah-stat{transition:none}
    body[data-page="academic"] .ah-table tbody tr{transition:none}
    body[data-page="academic"] .receive-row{transition:none}
}
</style>

<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header">
        <div class="title">
            <div class="ah-kicker"><i class="fa-solid fa-school"></i> Previous schools</div>
            <h1>Academic History</h1>
            <p>Previous schools and academic records, received from the enrollment intake</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openReceive()"><i class="fas fa-inbox"></i> Receive Record</button>
        </div>
    </header>

    <!-- ── Metric strip ────────────────────────────────────
         Same shell as the other registrar list pages: one connected
         panel, hairline dividers, an inset accent underline per figure,
         and a badge in the top row. "Awaiting records" is the actionable
         one, so it carries the coverage badge. -->
    <div class="ah-stats">
        <div class="ah-stat ak-students">
            <div class="ah-stat-top"></div>
            <div class="ah-stat-number"><?= $statStudents ?></div>
            <div class="ah-stat-label">Students with records</div>
        </div>
        <div class="ah-stat ak-records">
            <div class="ah-stat-top"></div>
            <div class="ah-stat-number"><?= $statRecords ?></div>
            <div class="ah-stat-label">Records on file</div>
        </div>
        <div class="ah-stat ak-schools">
            <div class="ah-stat-top"></div>
            <div class="ah-stat-number"><?= $statSchools ?></div>
            <div class="ah-stat-label">Schools represented</div>
        </div>
        <div class="ah-stat ak-awaiting">
            <div class="ah-stat-top">
                <span class="ah-stat-badge warn"><?= $coveragePct ?>% covered</span>
            </div>
            <div class="ah-stat-number"><?= $statAwaiting ?></div>
            <div class="ah-stat-label">Awaiting records</div>
        </div>
    </div>

    <div class="panel ah-panel">
        <div class="ah-toolbar">
            <div class="ah-toolbar-title">
                <i class="fa-solid fa-graduation-cap"></i> Students with records
                <span class="ah-pill"><?= $statStudents ?></span>
            </div>
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="acadSearch" placeholder="Search by student name or number...">
            </div>
        </div>

        <div class="table-responsive" style="overflow-x:auto;">
        <table class="ah-table">
            <thead><tr>
                <th>Student</th>
                <th><span class="ah-h"><i class="fa-solid fa-file-lines"></i> Records</span></th>
                <th><span class="ah-h"><i class="fa-solid fa-school"></i> Latest School</span></th>
                <th style="text-align:center;"><span class="ah-h"><i class="fa-solid fa-eye"></i> View</span></th>
            </tr></thead>
            <tbody id="acadBody">
            <?php if (empty($studentRows)): ?>
                <tr class="empty-state-row"><td colspan="4"><div class="ah-empty">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <p>No academic history records yet</p>
                    <span>Use <strong>Receive Record</strong> to import them from the enrollment intake</span>
                </div></td></tr>
            <?php else: foreach ($studentRows as $s): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($s['student_number'] . ' ' . $s['student_name']), ENT_QUOTES) ?>"
                    data-sid="<?= (int) $s['student_id'] ?>">
                    <td>
                        <div class="ah-who">
                            <span class="ah-avatar"><?= ah_initials($s['student_name']) ?></span>
                            <div>
                                <div class="ah-name"><?= htmlspecialchars($s['student_name']) ?></div>
                                <div class="ah-num"><?= htmlspecialchars($s['student_number']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="ah-count"><i class="fa-solid fa-file-lines"></i> <?= count($s['records']) ?></span>
                        <?= $s['course'] ? '<div class="ah-course">' . htmlspecialchars($s['course']) . '</div>' : '' ?>
                    </td>
                    <td><div class="ah-school"><?= htmlspecialchars($s['records'][0]['school_name']) ?></div></td>
                    <td style="text-align:center;">
                        <button class="action-btn view" title="View academic history" onclick="openView(<?= (int) $s['student_id'] ?>)"><i class="fas fa-eye"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <div class="table-footer">
            <span class="info-text" id="acadCount"><?= $statStudents ?> of <?= $statStudents ?> students</span>
        </div>
    </div>

</div>
</main>

<!-- Receive Record Modal -->
<div class="modal-overlay" id="receiveModal"><div class="modal-content ah-modal">
    <div class="modal-header ah-modal-head">
        <h2><i class="fa-solid fa-inbox"></i> Receive Academic Records</h2>
        <button class="modal-close" onclick="closeModal('receiveModal')" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div class="ah-note">
            <i class="fas fa-circle-info"></i>
            <div>Import pulls a student's previous-school records from the enrollment dashboard. Students with nothing on file are listed first.</div>
        </div>
        <div class="acad-search">
            <i class="fas fa-search"></i>
            <input type="text" id="receiveSearch" placeholder="Search student name or number...">
        </div>
        <div id="receiveList"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('receiveModal')">Close</button>
    </div>
</div></div>

<!-- View (read-only) Modal -->
<div class="modal-overlay" id="viewModal"><div class="modal-content ah-modal">
    <div class="modal-header ah-modal-head">
        <h2><i class="fa-solid fa-school"></i> Academic History</h2>
        <button class="modal-close" onclick="closeModal('viewModal')" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="viewHead"></div>
        <div id="viewRecords"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
    </div>
</div></div>

<script>
const ACAD = <?= json_encode($acadData ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const RECEIVE_LIST = <?= json_encode($receiveData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function closeModal(id) { const el = document.getElementById(id); if (el) { el.classList.remove('active'); document.body.style.overflow = ''; } }
function openModal(id) { const el = document.getElementById(id); if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; } }
['receiveModal','viewModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal('receiveModal'); closeModal('viewModal'); } });

// Table search
(function () {
    const input = document.getElementById('acadSearch');
    const count = document.getElementById('acadCount');
    if (!input) return;
    const rows = Array.from(document.querySelectorAll('#acadBody tr[data-search]'));
    const total = rows.length;
    function apply() {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(r => {
            const hit = !q || r.dataset.search.indexOf(q) !== -1;
            r.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        if (count) count.textContent = shown + ' of ' + total + ' students';
    }
    input.addEventListener('input', apply);
    apply();
})();

// Receive Record modal: student list + Import
function openReceive() {
    renderReceiveList(document.getElementById('receiveSearch').value);
    openModal('receiveModal');
}
// Two-letter initials, matching the server-side ah_initials().
function rvInitials(name) {
    const p = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return p[0][0].toUpperCase() + (p.length > 1 ? p[p.length - 1][0].toUpperCase() : '');
}

// This list is a worklist, not a directory: the students with no records
// on file are the work, so they lead. Status is stated in words because a
// bare "3" reads the same whether it means "three on file" or "nothing yet".
// Re-importing over existing records is a maintenance action, so its button
// is secondary — the primary weight stays with the backlog.
function renderReceiveList(q) {
    q = (q || '').trim().toLowerCase();
    const wrap = document.getElementById('receiveList');
    const filtered = RECEIVE_LIST.filter(s => !q || (s.number + ' ' + s.name).toLowerCase().indexOf(q) !== -1);
    if (!filtered.length) {
        wrap.innerHTML = '<div class="ah-no-match">No students match "' + esc(q) + '".</div>';
        return;
    }

    const groups = [
        { cls: 'is-awaiting', label: 'Needs records', rows: filtered.filter(s => !s.count) },
        { cls: '',           label: 'On file',       rows: filtered.filter(s =>  s.count) },
    ];

    wrap.innerHTML = groups.filter(g => g.rows.length).map(g =>
        '<div class="rv-group ' + g.cls + '">'
        + esc(g.label)
        + '<span class="rv-group-n">' + g.rows.length + '</span></div>'
        + g.rows.map(s =>
            '<div class="receive-row' + (s.count ? '' : ' is-awaiting') + '">'
            + '<span class="rv-avatar">' + esc(rvInitials(s.name)) + '</span>'
            + '<div class="rv-who">'
            + '<div class="rv-name">' + esc(s.name) + '</div>'
            + '<div class="rv-sub">' + esc(s.number) + '</div></div>'
            + '<span class="rv-status' + (s.count ? '' : ' is-awaiting') + '">'
            + (s.count ? s.count + ' on file' : 'Needs import') + '</span>'
            + '<button class="btn btn-sm rv-import ' + (s.count ? 'btn-secondary' : 'btn-primary') + '"'
            + ' onclick="importRecords(' + s.id + ', this)">'
            + '<i class="fas fa-file-import"></i> Import</button>'
            + '</div>'
        ).join('')
    ).join('');
}
document.getElementById('receiveSearch').addEventListener('input', function () { renderReceiveList(this.value); });

async function importRecords(studentId, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
    try {
        const res = await fetch('../api/students.php?action=import-academic', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: studentId })
        });
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'Import failed.');
        showToast(d.message || 'Imported.', 'success');
        setTimeout(() => window.location.reload(), 800);
    } catch (err) {
        btn.disabled = false; btn.innerHTML = orig;
        showToast(err.message || 'Import failed.', 'error');
    }
}

// View (read-only) academic history
function openView(studentId) {
    const recs = ACAD[studentId] || [];
    const head = document.getElementById('viewHead');
    const body = document.getElementById('viewRecords');
    const student = RECEIVE_LIST.find(s => s.id === studentId);
    const name = esc(student ? student.name : 'Student');
    const number = esc(student ? student.number : '');

    head.innerHTML = '<div class="ah-view-head">'
        + '<div><div class="ah-view-name">' + name + '</div>'
        + '<div class="ah-view-sub">' + number
        + (recs.length ? ' &middot; ' + recs.length + ' record(s)' : ' &middot; No records yet')
        + '</div></div></div>';

    if (!recs.length) {
        body.innerHTML = '<div class="ah-none">No academic history on file.<br>Use <strong>Receive Record</strong> to import from the enrollment dashboard.</div>';
    } else {
        body.innerHTML = recs.map(r =>
        '<div class="ah-record">'
        + '<div class="ah-record-head">'
        + '<span class="ah-record-school">' + esc(r.school) + '</span>'
        + (r.gwa ? '<span class="gwa-badge">GWA ' + esc(r.gwa) + '</span>' : '')
        + '</div>'
        + (r.year || r.grade || r.semester
            ? '<div class="ah-row"><span>' + esc([r.year, r.grade, r.semester].filter(Boolean).join(' &middot; ')) + '</span></div>' : '')
        + (r.remarks ? '<div class="ah-row ah-remarks"><span>' + esc(r.remarks) + '</span></div>' : '')
        + (r.grades.length ? '<div class="ah-grades"><table><thead><tr><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr></thead><tbody>'
            + r.grades.map(g => '<tr><td>' + esc(g.subject) + '</td><td>' + esc(g.units) + '</td><td>' + esc(g.grade) + '</td><td>' + esc(g.remarks) + '</td></tr>').join('')
            + '</tbody></table></div>' : '')
        + '</div>'
    ).join('');
    }
    openModal('viewModal');
}

</script>

<?php include '../includes/footer.php'; ?>