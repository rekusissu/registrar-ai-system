<?php
// ============================================================
//  REGISTRAR/RFID-CARDS.PHP
//  RFID cards management &mdash; fully inline (CSS + JS)
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('staff');

require_once __DIR__ . '/../shared/database.php';
// The card query below selects each student's photograph from Digital File
// Storage, so this has to be loaded before the query runs, not later in the
// file next to qr_generator where it used to sit. stored_file.php guards
// against double-inclusion, so the lower require is harmless.
require_once __DIR__ . '/../shared/stored_file.php';

$db = Database::getInstance();

// Auto-expire cards past expiry
$db->query("UPDATE rfid_cards SET status = 'expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status = 'active'");

$cards = $db->fetchAll("
    SELECT
        rf.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number,
        s.course,
        s.year_level,
        s.photo AS student_photo,
        /* This subquery was written out inline here AND in
           registrar/students.php, and two hand-maintained copies are free to
           drift. It is now the one fragment from shared/stored_file.php. */
        " . studentPhotoSelectSql() . " AS photo_path,
        s.address AS student_address,
        si.id_number AS student_id_number,
        si.qr_code_path,
        si.id_type AS id_type,
        si.issue_date AS id_issue_date,
        si.expiry_date AS id_expiry_date,
        si.status AS id_status
    FROM rfid_cards rf
    LEFT JOIN students s ON rf.student_id = s.id
    LEFT JOIN student_ids si ON si.rfid_card_id = rf.id AND si.id_type = 'school_id'
    ORDER BY rf.id DESC
");

// Split cards three ways: the unassigned pool, the live table, and retired.
//
// Retired cards (lost, archived, inactive) are pulled out of the live table so
// a lost or archived card no longer sits in the middle of active ones. They are
// still rendered and still searchable, just behind a toggle, because a lost
// card's UID must stay on record - if it vanished entirely the UID would look
// free and could be re-issued to someone else.
//
// `expired` deliberately stays in the live table: an expired card is still a
// tracked card that can be renewed, and the page already has an "Expiring soon"
// stat. Move it to $RETIRED_STATUSES if that is not the intent.
$RETIRED_STATUSES = ['lost', 'archived', 'inactive'];

$poolCards = array_filter($cards, fn($c) => $c['status'] === 'available' && empty($c['student_id']));
$retiredCards = array_values(array_filter($cards, fn($c) => in_array($c['status'], $RETIRED_STATUSES, true)));
$tableCards = array_values(array_filter($cards, fn($c) => !($c['status'] === 'available' && empty($c['student_id'])) && !in_array($c['status'], $RETIRED_STATUSES, true)));

$totalCards   = count($cards);
$activeCards  = count(array_filter($cards, fn($c) => $c['status'] === 'active'));
$availableCards = count($poolCards);
$expiredCards = count(array_filter($cards, fn($c) => $c['status'] === 'expired'));
$lostCards    = count(array_filter($cards, fn($c) => $c['status'] === 'lost'));
$archivedCards = count(array_filter($cards, fn($c) => $c['status'] === 'archived'));
$inactiveCards = count(array_filter($cards, fn($c) => $c['status'] === 'inactive'));
$retiredCount = count($retiredCards);

// Percentages
$activePct    = $totalCards ? round($activeCards / $totalCards * 100) : 0;
$availablePct = $totalCards ? round($availableCards / $totalCards * 100) : 0;
$expiredPct   = $totalCards ? round($expiredCards / $totalCards * 100) : 0;
$lostPct      = $totalCards ? round($lostCards / $totalCards * 100) : 0;
$archivedPct  = $totalCards ? round($archivedCards / $totalCards * 100) : 0;

// Available card pool for assign-from-pool dropdown
$availablePool = $db->fetchAll(
    "SELECT id, card_uid, registered_at FROM rfid_cards WHERE status = 'available' AND student_id IS NULL ORDER BY card_uid ASC"
);

// Month-over-month trend
$thisMonthCards   = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE issued_date LIKE '" . date('Y-m') . "%'") ?: 0;
$lastMonthCards   = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE issued_date LIKE '" . date('Y-m', strtotime('-1 month')) . "%'") ?: 0;
$trendActive = $lastMonthCards > 0 ? round(($thisMonthCards - $lastMonthCards) / $lastMonthCards * 100) : ($thisMonthCards > 0 ? 100 : 0);

// The assign-modal student picker must mirror the Student Management module
// (registrar/students.php), which lists EVERY student row regardless of status.
// Restricting to `status = 'active'` here silently hid students whose status is
// anything else (e.g. the default "enrolled", but also probation / at-risk / loa /
// dropped / transferred / graduated), so they showed in the Students page yet came
// back "No students found" in the Assign Card search. Only soft-deleted/archived
// records are excluded so cards can't be tied to removed students.
$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name, ' ', last_name) AS name, course
     FROM students
     WHERE status IS NULL OR status NOT IN ('archived')
     ORDER BY name"
);

// Card-back data: address, emergency contacts, primary guardian
$cardExtras = [];
$idStudentIds = array_values(array_unique(array_filter(array_map(fn($c) => (int) $c['student_id'], $cards))));
if ($idStudentIds) {
    $in = implode(',', $idStudentIds);
    foreach ($db->fetchAll("SELECT id, address FROM students WHERE id IN ($in)") as $st) {
        $cardExtras[$st['id']]['address'] = (string) ($st['address'] ?? '');
    }
    foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM emergency_contacts WHERE student_id IN ($in) ORDER BY is_primary DESC, id ASC") as $e) {
        $cardExtras[$e['student_id']]['emergency'][] = [
            'name' => (string) $e['full_name'],
            'rel'  => (string) ($e['relationship'] ?? ''),
            'phone'=> (string) ($e['contact_number'] ?? ''),
        ];
    }
    foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM guardians WHERE student_id IN ($in) AND is_primary = 1 ORDER BY id ASC") as $g) {
        if (!empty($cardExtras[$g['student_id']]['emergency'])) continue;
        $cardExtras[$g['student_id']]['emergency'][] = [
            'name' => (string) $g['full_name'],
            'rel'  => (string) ($g['relationship'] ?? 'Guardian'),
            'phone'=> (string) ($g['contact_number'] ?? ''),
        ];
    }
}

require_once __DIR__ . '/../shared/qr_generator.php';
// The avatar falls back to the photograph held in Digital File Storage, and
// only when that file is actually on this server. See the note where the
// avatar is resolved. This require used to sit here, far below the cards query
// at the top of the page - but that query calls studentPhotoSelectSql(), so the
// function has to be declared before the first call, not merely before the
// first render.
require_once __DIR__ . '/../shared/stored_file.php';

$page_title = 'RFID Cards';
$page_description = 'Issue, track, and archive student RFID cards';
$body_page = 'rfid';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';

include '../includes/header.php';
include '../includes/sidebar.php';

// Deterministic avatar color per student_id
$avatarPalette = ['blue', 'green', 'purple', 'orange', 'pink'];
$avatarClasses = [];
foreach ($cards as $i => $c) {
    $studentKey = (string)($c['student_id'] ?? $c['id'] ?? $i);
    $avatarClasses[$c['id']] = $avatarPalette[abs(crc32($studentKey)) % count($avatarPalette)];
}
?>
<style>
/* ============================================================
   RFID CARDS PAGE &mdash; INLINE STYLES
   ============================================================ */

/* â”€â”€ Sidebar-aware main â”€â”€ */
:root { --sidebar-width: 260px; }
.dashboard-main {
    margin-left: var(--sidebar-width);
    padding: 24px 32px;
    min-height: 100vh;
    width: calc(100% - var(--sidebar-width));
    max-width: calc(100% - var(--sidebar-width));
    overflow-x: hidden;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease, max-width 0.3s ease;
}

/* â”€â”€ Page header â”€â”€ */
.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e8eaef;
    gap: 16px;
    flex-wrap: wrap;
}
.header .title h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 2px;
}
.header .title p {
    font-size: 13px;
    color: #64748b;
    margin: 0;
}
.header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* â”€â”€ Buttons â”€â”€ */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    border: 1.5px solid transparent;
    text-decoration: none;
    transition: all 0.2s ease;
    font-family: inherit;
    line-height: 1;
}
.btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: white;
    box-shadow: 0 1px 3px rgba(37,99,235,0.25);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(37,99,235,0.3); }
.btn-secondary { background: white; color: #475569; border-color: #e2e8f0; }
.btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
.btn-light { background: #f1f5f9; color: #475569; }
.btn-light:hover { background: #e2e8f0; color: #0f172a; }

/* â”€â”€ Stats grid (students.php style) â”€â”€ */
.rfid-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.rfid-stat-card {
    background: white;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.rfid-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
    border-color: #d8dde4;
}
.rfid-stat-card .stat-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.rfid-stat-card .stat-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.rfid-stat-card .stat-icon.blue   { background: #eef4ff; color: #2563eb; }
.rfid-stat-card .stat-icon.green  { background: #dcfce7; color: #16a34a; }
.rfid-stat-card .stat-icon.yellow { background: #fef3c7; color: #b45309; }
.rfid-stat-card .stat-icon.red    { background: #fee2e2; color: #dc2626; }
.rfid-stat-card .stat-trend {
    font-size: 11px; font-weight: 600;
    padding: 2px 10px; border-radius: 9999px;
    display: inline-flex; align-items: center; gap: 4px;
}
.rfid-stat-card .stat-trend.up   { color: #16a34a; background: #dcfce7; }
.rfid-stat-card .stat-trend.down { color: #dc2626; background: #fee2e2; }
.rfid-stat-card .stat-trend.neutral { color: #64748b; background: #f1f5f9; }
.rfid-stat-card .stat-number {
    font-size: 24px; font-weight: 700; color: #0f172a; line-height: 1.2;
}
.rfid-stat-card .stat-label {
    color: #64748b; font-size: 13px; margin-top: 1px;
}

/* â”€â”€ Search Table Container (students.php style) â”€â”€ */
.search-table-container {
    background: white;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
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
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    color: #94a3b8; font-size: 14px;
    pointer-events: none; z-index: 2;
}
.search-bar .search-wrapper input {
    width: 100%; height: 40px;
    padding: 0 38px 0 38px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: 14px; font-family: inherit;
    outline: none; transition: all 0.2s ease;
    background: white; color: #1e293b;
    box-sizing: border-box;
}
.search-bar .search-wrapper input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
}
.search-bar .search-wrapper input::placeholder { color: #94a3b8; }
.search-bar .search-wrapper .search-clear {
    position: absolute; right: 8px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: #94a3b8; cursor: pointer;
    width: 24px; height: 24px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; z-index: 2;
}
.search-bar .search-wrapper .search-clear:hover { background: #f1f5f9; color: #1e293b; }
.search-bar .search-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.search-bar .search-actions .btn { height: 40px; padding: 0 18px; border-radius: 10px; font-size: 13px; font-weight: 600; }

/* â”€â”€ Filter select (within search-bar) â”€â”€ */
.filter-select-wrapper {
    position: relative; display: flex; align-items: center;
    min-width: 130px;
}
.filter-select-wrapper select {
    width: 100%; height: 40px;
    padding: 0 36px 0 36px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: 13px; font-family: inherit;
    color: #1e293b; background: white;
    outline: none; cursor: pointer;
    box-sizing: border-box;
}
.filter-select-wrapper select:focus { border-color: #2563eb; }
.filter-select-icon {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%);
    color: #94a3b8; font-size: 13px;
    pointer-events: none; z-index: 2;
}
.filter-select-arrow {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    color: #94a3b8; font-size: 10px;
    pointer-events: none; z-index: 2;
}
.search-wrapper input {
    width: 100%; height: 42px; padding: 0 38px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: 14px; font-family: inherit;
    outline: none; background: white; color: #1e293b;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.search-wrapper input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
}
.search-clear {
    position: absolute; right: 8px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: #94a3b8; cursor: pointer;
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
}
.search-clear:hover { background: #f1f5f9; color: #1e293b; }

/* â”€â”€ Card UID pill â”€â”€ */
.card-uid-display {
    display: inline-flex; align-items: center; gap: 8px;
    font-family: 'Courier New', monospace;
    font-size: 13px; font-weight: 600;
    color: #0f172a;
    background: #f1f5f9; padding: 4px 12px; border-radius: 8px;
}
.card-uid-display .chip {
    width: 24px; height: 24px;
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; color: #64748b;
}

/* â”€â”€ Table â”€â”€ */
.rfid-table-wrapper {
    background: white;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}
.rfid-table-wrapper table { width: 100%; border-collapse: collapse; min-width: 820px; }
.rfid-table-wrapper th {
    text-align: left;
    padding: 11px 16px;
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.rfid-table-wrapper td {
    padding: 11px 16px;
    font-size: 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    white-space: nowrap;
}
.rfid-table-wrapper tbody tr:hover { background: #f8fafc; }
.rfid-table-wrapper tbody tr:last-child td { border-bottom: none; }

/* ── Table header ── */
.rfid-table-header { padding: 18px 20px 14px; border-bottom: 1px solid #f1f5f9; }
.rfid-table-header h3 { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 2px; display: flex; align-items: center; gap: 8px; }
.rfid-table-header p { font-size: 12px; color: #94a3b8; margin: 0; }
.rfid-table-count { font-size: 11px; font-weight: 600; background: #eff6ff; color: #2563eb; padding: 2px 8px; border-radius: 10px; }

/* â”€â”€ Student info cell â”€â”€ */
.student-info { display: flex; align-items: center; gap: 10px; }
.student-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 11px;
    flex-shrink: 0;
}
.student-avatar.blue   { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
.student-avatar.green  { background: linear-gradient(135deg, #16a34a, #15803d); }
.student-avatar.purple { background: linear-gradient(135deg, #7c3aed, #6d28d9); }
.student-avatar.orange { background: linear-gradient(135deg, #b45309, #92400e); }
.student-avatar.pink   { background: linear-gradient(135deg, #db2777, #be185d); }
.student-name {
    font-weight: 600; color: #0f172a;
    font-size: 14px; line-height: 1.2;
}
.student-detail {
    font-size: 12px; color: #94a3b8;
    line-height: 1.2; margin-top: 2px;
}
.unassigned-text { color: #94a3b8; font-style: italic; font-size: 13px; }

/* â”€â”€ Status badges â”€â”€ */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    background: #f1f5f9; color: #475569;
    line-height: 1.2; white-space: nowrap;
}
.status-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block; flex-shrink: 0;
}
.status-badge.active    { background: #dcfce7; color: #16a34a; }
.status-badge.expired,
.status-badge.inactive,
.status-badge.denied     { background: #fee2e2; color: #dc2626; }
.status-badge.lost      { background: #fef3c7; color: #b45309; }

/* â”€â”€ Expiry warning â”€â”€ */
.expiry-warning { color: #b45309; font-size: 12px; font-weight: 500; margin-left: 6px; }

/* â”€â”€ Action group â”€â”€ */
.action-group { display: flex; gap: 6px; justify-content: center; }
.action-btn {
    width: 32px; height: 32px;
    border: none; background: transparent;
    border-radius: 8px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    transition: all 0.2s ease;
}
.action-btn:hover { background: #f1f5f9; transform: scale(1.05); }
.action-btn.view   { color: #2563eb; } .action-btn.view:hover   { background: #eef4ff; }
.action-btn.edit   { color: #b45309; } .action-btn.edit:hover   { background: #fef3c7; }
.action-btn.delete { color: #dc2626; } .action-btn.delete:hover { background: #fee2e2; }

/* â”€â”€ Table footer â”€â”€ */
.table-footer {
    padding: 12px 18px;
    background: #fafcfd;
    border-top: 1px solid #f1f5f9;
}
.table-footer .info-text { font-size: 13px; color: #64748b; }
.table-footer .info-text strong { color: #0f172a; }

/* â”€â”€ Modals â”€â”€ */
.rfid-modal {
    max-width: 520px;
    text-align: left;
    padding: 26px 30px 22px;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.rfid-modal .rfid-modal-body-wrapper {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    margin: 0 -30px;
    padding: 0 30px;
}
.rfid-modal form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}
.rfid-modal-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px;
}
.rfid-modal-header .header-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.rfid-modal-header .header-icon.assign { background: #eef4ff; color: #2563eb; }
.rfid-modal-header .header-icon.edit   { background: #fef3c7; color: #b45309; }
.rfid-modal-header h3 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
.rfid-modal-header p  { font-size: 13px; color: #64748b; margin: 2px 0 0; }

.form-section { padding: 14px 0 4px; border-bottom: 1px dashed #f1f5f9; }
.form-section:last-of-type { border-bottom: none; }
.form-section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.form-section-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #eef4ff; color: #2563eb;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
}
.form-section-title { font-size: 13px; font-weight: 700; color: #0f172a; }
.form-section-subtitle { font-size: 11px; color: #64748b; margin-top: 1px; }
.form-row { display: flex; gap: 10px; flex-wrap: wrap; }
.form-group { margin-bottom: 12px; flex: 1; min-width: 140px; }
.form-group label {
    display: block;
    font-size: 12px;
    color: #475569;
    margin-bottom: 5px;
    font-weight: 600;
}
.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: white;
    color: #1e293b;
    box-sizing: border-box;
}
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.10); }
.form-control[readonly] { background: #f8fafc; color: #475569; }

/* Fix native dropdown in modals */
.logout-modal select.form-control,
.logout-modal select { appearance:auto !important; -webkit-appearance:auto !important; cursor:pointer !important; }

.uid-row { display: flex; gap: 8px; align-items: stretch; }
.uid-row .form-control {
    flex: 1;
    font-family: 'Courier New', monospace;
    font-size: 16px;
    letter-spacing: 1px;
}

.student-search-wrapper { position: relative; }
.student-search-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: white; border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    max-height: 220px; overflow-y: auto;
    margin-top: 4px;
    z-index: 99;
    display: none;
}
.student-search-results.show { display: block; }
.student-search-results .result-item {
    padding: 10px 14px;
    font-size: 14px; color: #1e293b;
    cursor: pointer;
}
.student-search-results .result-item:hover { background: #f1f5f9; }
.student-search-results .no-results {
    padding: 14px;
    font-size: 13px; color: #94a3b8;
    text-align: center;
}
.selected-student-chip {
    display: none;
    align-items: center; gap: 10px;
    margin-top: 8px;
    padding: 10px 14px;
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 10px;
}
.selected-student-chip.show { display: flex; }
.selected-student-chip i { color: #16a34a; }
.selected-student-chip .chip-name { font-weight: 600; color: #14532d; font-size: 14px; flex: 1; }
.selected-student-chip .chip-id   { font-size: 12px; color: #16a34a; display:block; margin-top:1px; }
.selected-student-chip .chip-clear {
    background: none; border: none;
    color: #94a3b8; cursor: pointer;
    padding: 4px;
}
.selected-student-chip .chip-clear:hover { color: #dc2626; }

.rfid-modal-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    margin-top: 18px; padding-top: 14px;
    border-top: 1px solid #f1f5f9;
}
.rfid-modal-actions .btn { padding: 9px 18px; }

/* â”€â”€ View Modal (centered) â”€â”€ */
.rfid-view-modal {
    max-width: 480px;
    text-align: left;
    padding: 26px 30px 22px;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
}
.rfid-view-body-wrapper {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    margin: 0 -30px;
    padding: 0 30px;
}
.rfid-view-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px;
}
.rfid-view-header .header-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.rfid-view-header .header-icon.view { background: #eef4ff; color: #2563eb; }
.rfid-view-header h3 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
.rfid-view-header p  { font-size: 13px; color: #64748b; margin: 2px 0 0; }

.rfid-view-body {
    display: flex; flex-direction: column; gap: 18px;
}
.rfid-view-uid {
    background: linear-gradient(135deg, #eef4ff, #dbeafe);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
}
.rfid-view-uid .uid-icon {
    width: 36px; height: 36px;
    background: white; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #2563eb;
}
.rfid-view-uid .uid-text {
    font-family: 'Courier New', monospace;
    font-size: 16px; font-weight: 700;
    color: #1e40af; letter-spacing: 1px;
}
.rfid-view-kv { list-style: none; padding: 0; margin: 0; }
.rfid-view-kv li {
    display: flex; justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dashed #f1f5f9;
    font-size: 13px;
}
.rfid-view-kv li:last-child { border-bottom: none; }
.rfid-view-kv .kv-label { color: #64748b; }
.rfid-view-kv .kv-value { color: #0f172a; font-weight: 600; text-align: right; max-width: 60%; word-break: break-word; }
.rfid-view-notes {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px; color: #1e293b;
    line-height: 1.5; min-height: 50px;
}
.rfid-view-scan-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f8fafc;
    margin-bottom: 6px;
    font-size: 12px;
}
.rfid-view-scan-row .scan-meta { color: #64748b; }
.rfid-view-status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    background: #f1f5f9; color: #475569;
    line-height: 1.2; white-space: nowrap;
}
.rfid-view-status-badge .status-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block; flex-shrink: 0;
}
.rfid-view-status-badge.active    { background: #dcfce7; color: #16a34a; }
.rfid-view-status-badge.expired,
.rfid-view-status-badge.inactive,
.rfid-view-status-badge.denied     { background: #fee2e2; color: #dc2626; }
.rfid-view-status-badge.lost      { background: #fef3c7; color: #b45309; }

/* Responsive for view modal */
@media (max-width: 768px) {
    .rfid-view-modal { max-width: 96%; padding: 22px 18px; }
}

/* â”€â”€ Toast on top of everything â”€â”€ */
/* ——— Status badges ——— */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.status-badge .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.status-badge.active    { background: #dcfce7; color: #16a34a; }
.status-badge.available { background: #e0e7ff; color: #4f46e5; }
.status-badge.expired   { background: #fee2e2; color: #dc2626; }
.status-badge.lost      { background: #fef3c7; color: #b45309; }
.status-badge.inactive  { background: #f1f5f9; color: #64748b; }
.status-badge.archived  { background: #f3f4f6; color: #6b7280; }

.ai-panel { background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px 24px; margin-top: 20px; }
.ai-panel-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.ai-panel-header .ai-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #ede9fe, #ddd6fe); display: flex; align-items: center; justify-content: center; color: #7c3aed; font-size: 16px; }
.ai-panel-header h3 { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0; }
.ai-panel-header p { font-size: 12px; color: #64748b; margin: 0; }
.ai-insights { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.ai-insight-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; }
.ai-insight-card .label { color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.ai-insight-card .value { color: #0f172a; font-size: 18px; font-weight: 700; margin-top: 4px; }
.ai-insight-card .sub { color: #64748b; font-size: 12px; margin-top: 2px; }

.ai-chat-fab { position: fixed; bottom: 24px; right: 24px; z-index: 9998; width: 52px; height: 52px; border-radius: 50%; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 4px 14px rgba(124,58,237,0.35); transition: all 0.3s ease; }
.ai-chat-fab:hover { transform: scale(1.08); }
.ai-chat-window { position: fixed; bottom: 88px; right: 24px; z-index: 9999; width: 380px; max-height: 520px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 12px 40px rgba(15,23,42,0.15); display: none; flex-direction: column; overflow: hidden; }
.ai-chat-window.open { display: flex; }
.ai-chat-head { padding: 14px 18px; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; display: flex; align-items: center; gap: 10px; }
.ai-chat-head .ai-avatar { width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 14px; }
.ai-chat-head h4 { margin: 0; font-size: 14px; font-weight: 700; flex: 1; }
.ai-chat-head p { margin: 0; font-size: 11px; opacity: 0.8; }
.ai-chat-head button { background: none; border: none; color: white; cursor: pointer; font-size: 16px; padding: 4px; opacity: 0.8; }
.ai-chat-messages { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; max-height: 360px; min-height: 200px; }
.ai-chat-msg { max-width: 85%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.5; word-break: break-word; }
.ai-chat-msg.bot { align-self: flex-start; background: #f1f5f9; color: #1e293b; border-bottom-left-radius: 4px; }
.ai-chat-msg.user { align-self: flex-end; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; border-bottom-right-radius: 4px; }
.ai-chat-msg.typing { color: #94a3b8; font-style: italic; }
.ai-chat-input { padding: 12px; border-top: 1px solid #e2e8f0; display: flex; gap: 8px; align-items: center; }
.ai-chat-input input { flex: 1; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 13px; font-family: inherit; outline: none; }
.ai-chat-input input:focus { border-color: #7c3aed; }
.ai-chat-input button { width: 38px; height: 38px; border-radius: 10px; border: none; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }

.register-textarea { width: 100%; height: 90px; max-height: 160px; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: ui-monospace, Consolas, monospace; font-size: 13px; resize: vertical; outline: none; line-height: 1.6; background: #f8fafc; transition: border-color .15s, box-shadow .15s; box-sizing: border-box; }
.register-textarea:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.10); background: #fff; }
.register-textarea::placeholder { color: #cbd5e1; font-family: inherit; }
.uid-count-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; display: flex; align-items: center; gap: 5px; }
.uid-count-hint::before { content: '\f0eb'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 11px; }
.uid-count-hint.has-count { color: #4f46e5; font-weight: 600; }
.uid-count-hint.has-count::before { content: '\f058'; color: #16a34a; }
/* RFID Distribution Assistant */
.rfid-distribution-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}.rfid-distribution-summary>div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center}.rfid-distribution-summary strong{display:block;font-family:inherit;font-size:24px;color:#14213d}.rfid-distribution-summary span{font-size:10px;color:#64748b}.rfid-distribution-list{display:flex;flex-direction:column;gap:8px}.rfid-distribution-row{display:grid;grid-template-columns:22px 150px 24px minmax(220px,1fr) minmax(180px,.7fr);align-items:center;gap:10px;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;background:#fff;cursor:pointer}.rfid-distribution-row:hover{border-color:#bfdbfe;background:#f8fbff}.rfid-distribution-row input{accent-color:#2563eb}.rfid-distribution-card,.rfid-distribution-student{display:flex;flex-direction:column;gap:2px}.rfid-distribution-card i{color:#2563eb}.rfid-distribution-card strong,.rfid-distribution-student strong{font-size:13px;color:#0f172a}.rfid-distribution-card small,.rfid-distribution-student small{font-size:11px;color:#64748b}.rfid-distribution-card strong{font-family:inherit;letter-spacing:.2px}.rfid-distribution-arrow{color:#94a3b8;text-align:center}.rfid-distribution-reason{font-size:11px;color:#64748b;line-height:1.4}.rfid-distribution-review{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fffaf1;border:1px solid #fed7aa;border-radius:9px;color:#7c2d12;font-size:12px;margin-top:7px}.rfid-distribution-ai{margin:0 0 14px;padding:12px 14px;background:#f5f8ff;border:1px solid #c7d7fe;border-left:4px solid #2563eb;border-radius:9px}.rfid-distribution-ai strong{font-size:12px;color:#1e40af}.rfid-distribution-ai p{font-size:12px;line-height:1.5;color:#475569;margin:5px 0 0}@media(max-width:760px){.rfid-distribution-summary{grid-template-columns:repeat(2,1fr)}.rfid-distribution-row{grid-template-columns:22px 1fr 1fr}.rfid-distribution-arrow{display:none}.rfid-distribution-reason{grid-column:2/4}.rfid-distribution-row{min-height:72px}}

.reg-step-panel { display: none; flex-direction: column; flex: 1; min-height: 0; }
.reg-step-panel.active { display: flex; }
.reg-step-panel .rfid-modal-body-wrapper { margin: 0 !important; padding: 0 !important; }
.reg-step-panel .rfid-modal-actions { margin-top: 14px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
/* Step indicators */
.reg-steps { display: flex; align-items: center; justify-content: center; gap: 0; padding: 14px 0 10px; border-bottom: 1px solid #f1f5f9; margin-bottom: 8px; }
.reg-step { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #94a3b8; transition: color .2s; }
.reg-step.active { color: #4f46e5; }
.reg-step.done { color: #16a34a; }
.reg-step span { width: 24px; height: 24px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; transition: all .2s; }
.reg-step.active span { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; box-shadow: 0 2px 8px rgba(79,70,229,0.3); }
.reg-step.done span { background: #16a34a; color: white; }
.reg-step-line { width: 36px; height: 2px; background: #e2e8f0; margin: 0 6px; border-radius: 2px; transition: background .2s; }
.reg-step-line.done { background: linear-gradient(90deg, #16a34a, #4f46e5); }
/* Summary grid */
.reg-summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
.reg-summary-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 10px; text-align: center; }
.reg-summary-card .rs-num { font-size: 26px; font-weight: 800; line-height: 1; }
.reg-summary-card .rs-label { font-size: 10px; color: #64748b; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600; }
.reg-summary-card.green { border-color: #bbf7d0; background: #f0fdf4; }
.reg-summary-card.green .rs-num { color: #16a34a; }
.reg-summary-card.amber { border-color: #fde68a; background: #fffbeb; }
.reg-summary-card.amber .rs-num { color: #d97706; }
.reg-summary-card.red { border-color: #fecaca; background: #fef2f2; }
.reg-summary-card.red .rs-num { color: #dc2626; }
.reg-summary-card.gray { border-color: #e2e8f0; }
.reg-summary-card.gray .rs-num { color: #94a3b8; }
.reg-preview-details { max-height: 200px; overflow-y: auto; font-size: 12px; border: 1px solid #f1f5f9; border-radius: 8px; }
.reg-preview-section { padding: 10px 14px; }
.reg-preview-section + .reg-preview-section { border-top: 1px solid #f1f5f9; }
.reg-preview-section h4 { font-size: 12px; font-weight: 700; color: #475569; margin: 0 0 6px; display: flex; align-items: center; gap: 6px; }
.reg-preview-section ul { margin: 0; padding-left: 18px; color: #64748b; line-height: 1.8; }
.reg-preview-section code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
/* Result screen */
.reg-result-box { padding: 24px 20px; text-align: center; }
.reg-result-box .result-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px; }
.reg-result-box .result-icon.success { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a; box-shadow: 0 4px 12px rgba(22,163,74,0.15); }
.reg-result-box .result-icon.partial { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; box-shadow: 0 4px 12px rgba(217,119,6,0.15); }
.reg-result-box .result-icon.error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; box-shadow: 0 4px 12px rgba(220,38,38,0.15); }
.reg-result-box h3 { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
.reg-result-box p { font-size: 13px; color: #64748b; margin: 0 0 12px; }
.reg-result-breakdown { display: flex; gap: 12px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
.reg-result-breakdown .rb-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; text-align: center; min-width: 80px; }
.reg-result-breakdown .rb-item .rb-num { font-size: 20px; font-weight: 800; }
.reg-result-breakdown .rb-item .rb-label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: .3px; }

.assign-tabs { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 2px solid #e2e8f0; }
.assign-tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; font-size: 13px; font-weight: 600; color: #64748b; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; background: none; font-family: inherit; transition: all 0.2s; }
.assign-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
.assign-tab:hover:not(.active) { color: #475569; background: #f8fafc; }
.assign-tab-pane { display: none; }
.assign-tab-pane.active { display: block; }

.toast-container { z-index: 100000 !important; }

/* â”€â”€ Responsive â”€â”€ */
@media (max-width: 1024px) {
    .dashboard-main { padding: 22px; }
}
@media (max-width: 768px) {
    .dashboard-main {
        margin-left: 0; width: 100%; max-width: 100%;
        padding: 18px 14px;
    }
    .rfid-stats { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .rfid-stat-card { padding: 14px 16px; }
    .rfid-stat-card .stat-number { font-size: 20px; }
    .rfid-stat-card .stat-icon { width: 34px; height: 34px; font-size: 14px; }
    .rfid-table-wrapper table { min-width: 700px; }
    .rfid-modal { max-width: 96%; padding: 22px 18px; }
    .search-bar { padding: 10px 14px; }
    .search-bar .search-wrapper { min-width: 200px; }
}
@media (max-width: 600px) {
    .rfid-stats { grid-template-columns: 1fr; }
    .rfid-stat-card .stat-number { font-size: 18px; }
    .header { flex-direction: column; align-items: flex-start; gap: 12px; }
    .header-actions { width: 100%; }
}
/* ── Available Card Pool (compact table) ─────── */
.pool-section { margin-top: 24px; margin-bottom: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; }
.pool-header { padding: 18px 22px 10px; }
.pool-title { display: flex; align-items: center; gap: 10px; }
.pool-title h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
.pool-count { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 22px; padding: 0 7px; border-radius: 999px; background: #ede9fe; color: #7c3aed; font-size: 12px; font-weight: 700; }
.pool-subtitle { font-size: 13px; color: #94a3b8; margin: 4px 0 0; padding-left: 32px; }
.pool-table-wrap { padding: 0 12px 12px; max-height: 280px; overflow-y: auto; }
.pool-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pool-table thead { position: sticky; top: 0; z-index: 1; }
.pool-table th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.pool-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
.pool-row { transition: background .1s; }
.pool-row:hover { background: #faf5ff; }
.pool-idx { color: #94a3b8; font-size: 12px; font-weight: 500; }
.pool-uid { font-family: ui-monospace, Consolas, monospace; font-size: 13px; font-weight: 600; color: #0f172a; letter-spacing: .3px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; }
.pool-date { color: #94a3b8; font-size: 12px; }
.pool-actions { text-align: center; }
.pool-del-btn { width: 28px; height: 28px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #94a3b8; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; transition: all .15s; }
.pool-del-btn:hover { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
</style>
<style>
.idtype-chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#eef4ff; color:#2563eb; }
.qr-thumb { width:36px; height:36px; border:1px solid #e2e8f0; border-radius:8px; object-fit:contain; background:white; cursor:pointer; transition:transform .15s,box-shadow .15s; }
.qr-thumb:hover { transform:scale(1.15); box-shadow:0 2px 8px rgba(37,99,235,.2); }
#qrModalOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:10000; align-items:center; justify-content:center; }
#qrModalOverlay.active { display:flex; }
#qrModalOverlay .qr-modal-box { background:#fff; border-radius:18px; padding:24px; text-align:center; max-width:320px; width:90%; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:modalSlide .25s ease; }
#qrModalOverlay .qr-modal-box img { width:220px; height:220px; object-fit:contain; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:12px; }
#qrModalOverlay .qr-modal-box .qr-modal-name { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:4px; }
#qrModalOverlay .qr-modal-box .qr-modal-sub { font-size:12px; color:#94a3b8; margin-bottom:14px; }
#qrModalOverlay .qr-modal-close { border:none; background:#f1f5f9; border-radius:10px; padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer; color:#475569; }
#qrModalOverlay .qr-modal-close:hover { background:#e2e8f0; }
/* â”€â”€ ID card (view modal) &mdash; 3D flip card â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
/* Card preview and print design: assets/id-card/student-id.css. */
/* ── Print-only ID card styles ──────────────────────── */
#printArea{display:none;}
@media print{
    @page{size:portrait;margin:10mm;}
    body>*{display:none !important;}
    #printArea{display:block !important;position:fixed;left:0;top:0;width:100%;}
    #printArea *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;color-adjust:exact !important;}
}
</style>

<style>
/* RFID cards ? registrar-blue system, matching Queue / Documents / Scan Logs.
   Appended after the page's own styles so it wins on specificity without
   having to rewrite rules the modals below also depend on. */
body[data-page="rfid"]{background:#f5f7fb;color:#0f172a}
body[data-page="rfid"] .dashboard-main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%);min-height:auto}

/* -- Header -- */
body[data-page="rfid"] .dashboard-main>.header{
    display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;
    margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;
    background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08);
}
body[data-page="rfid"] .dashboard-main>.header .title h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
body[data-page="rfid"] .dashboard-main>.header .title p{max-width:620px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.rc-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
/* box-sizing is load-bearing here. There is no global `* { box-sizing }` reset
   in the shared stylesheets, and Chrome's UA sheet applies border-box to
   <button> but NOT to <a>. Without this, "Scan Logs" (an anchor) is content-box:
   min-height 36px + 16px padding + 3px border = 54px, while the two <button>
   siblings measure a true 36px. Measured in Edge: 54.0 vs 36.0 vs 36.0. */
body[data-page="rfid"] .dashboard-main>.header .header-actions .btn{box-sizing:border-box;min-height:36px;font-size:12px}

/* -- Metric strip (was 6 icon cards) -- */
body[data-page="rfid"] .rfid-stats{
    display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:0;
    margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
}
body[data-page="rfid"] .rfid-stat-card{
    position:relative;background:transparent;border:0;border-radius:0;padding:17px 20px;
    border-right:1px solid #e2e8f0;box-shadow:none;transition:none;
}
body[data-page="rfid"] .rfid-stat-card:last-child{border-right:0}
body[data-page="rfid"] .rfid-stat-card::after{content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe}
body[data-page="rfid"] .rfid-stat-card:hover{transform:none;box-shadow:none;border-color:transparent}
body[data-page="rfid"] .rfid-stat-card .stat-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 6px}
body[data-page="rfid"] .rfid-stat-card .stat-icon{display:none}
body[data-page="rfid"] .rfid-stat-card .stat-label{order:1;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;margin:0;line-height:1.3}
body[data-page="rfid"] .rfid-stat-card .stat-trend{order:2;font-size:10px;padding:1px 7px}
body[data-page="rfid"] .rfid-stat-card .stat-number{order:3;font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;font-variant-numeric:tabular-nums}
/* Each accent must carry the same specificity as the base rule above.
   Unprefixed (.rc-k-total = 0-1-0) loses to body[data-page] .rfid-stat-card
   (0-2-1), which pinned every card to the same pale blue. */
body[data-page="rfid"] .rfid-stat-card.rc-k-total::after{background:#1d4ed8}
body[data-page="rfid"] .rfid-stat-card.rc-k-active::after{background:#16a34a}
body[data-page="rfid"] .rfid-stat-card.rc-k-pool::after{background:#6366f1}
body[data-page="rfid"] .rfid-stat-card.rc-k-expired::after{background:#d97706}
body[data-page="rfid"] .rfid-stat-card.rc-k-lost::after{background:#dc2626}
body[data-page="rfid"] .rfid-stat-card.rc-k-archived::after{background:#94a3b8}

/* -- Panels -- */
body[data-page="rfid"] .search-table-container,
body[data-page="rfid"] .pool-section,
body[data-page="rfid"] .ai-panel{
    border:1px solid #dbeafe;border-radius:16px;background:#fff;
    box-shadow:0 8px 24px rgba(15,23,42,.045);overflow:hidden;
}
body[data-page="rfid"] .search-table-container{margin-bottom:16px}
body[data-page="rfid"] .pool-section{margin:0 0 16px}
body[data-page="rfid"] .ai-panel{margin:0 0 16px;padding:0}
body[data-page="rfid"] .ai-panel-header{margin:0;padding:14px 18px;background:#f8faff;border-bottom:1px solid #e5e7eb}
body[data-page="rfid"] .ai-insight-card{border-radius:0;border:0;border-top:1px solid #e5e7eb!important;background:#f8faff;padding:14px 18px}
body[data-page="rfid"] .ai-panel-header .ai-icon{background:linear-gradient(140deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.25)}
body[data-page="rfid"] .ai-panel-header h3{font-size:13.5px}

/* -- Search bar -- */
body[data-page="rfid"] .search-bar{background:#fff;padding:13px 18px;border-bottom:1px solid #e5e7eb;display:flex;gap:9px;flex-wrap:wrap;align-items:center}
body[data-page="rfid"] .search-bar .search-wrapper{max-width:none}
body[data-page="rfid"] .search-bar input,
body[data-page="rfid"] .search-bar select{height:38px;border:1px solid #cbd5e1!important;border-radius:9px;background:#f8faff!important;font:13px Inter,sans-serif;padding:0 12px}
body[data-page="rfid"] .search-bar .search-wrapper input{padding:0 38px}
body[data-page="rfid"] .search-bar .filter-select-wrapper{flex:0 0 142px;min-width:142px}
body[data-page="rfid"] .search-bar .filter-select-wrapper select{padding:0 32px 0 36px;appearance:none;-webkit-appearance:none}
body[data-page="rfid"] .search-bar input:focus,
body[data-page="rfid"] .search-bar select:focus{outline:0;border-color:#2563eb!important;background:#fff!important;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
body[data-page="rfid"] .search-bar .search-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
body[data-page="rfid"] .search-bar .btn{min-height:38px;font-size:12px}
body[data-page="rfid"] .ai-panel + *{margin-top:0}

/* -- AI interpretation banner -- */
body[data-page="rfid"] #aiRfidInterpretation{border-radius:0!important;border:0!important;border-bottom:1px solid #dbeafe!important;background:#eff6ff!important;margin:0!important;padding:11px 18px!important}

/* -- Assigned cards table -- */
body[data-page="rfid"] .rfid-table-wrapper{border:0;border-radius:0;box-shadow:none;background:transparent}
body[data-page="rfid"] .rfid-table-header{padding:13px 18px;background:#fff;border-bottom:1px solid #e5e7eb}
body[data-page="rfid"] .rfid-table-header h3{font-size:13px;font-weight:700;letter-spacing:-.01em;display:flex;align-items:center;gap:8px;color:#1e293b}
body[data-page="rfid"] .rfid-table-header p{display:none}
body[data-page="rfid"] .rfid-table-header .rfid-table-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:800}
body[data-page="rfid"] .rfid-table-header h3 i{color:#2563eb;font-size:12px}
body[data-page="rfid"] .rfid-table-wrapper table{min-width:880px}
body[data-page="rfid"] .rfid-table-wrapper th{
    position:sticky;top:0;z-index:3;padding:11px 14px;background:#f8fafc;
    border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;
    letter-spacing:.05em;text-transform:uppercase;
}
body[data-page="rfid"] .rfid-table-wrapper td{padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
body[data-page="rfid"] .rfid-table-wrapper tbody tr:hover{background:#eff6ff}
body[data-page="rfid"] .rfid-table-wrap{max-height:62vh;overflow:auto}
body[data-page="rfid"] .rfid-table-wrapper .card-uid-display{
    font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12.5px;font-weight:600;
    color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;padding:4px 10px;border-radius:8px;gap:7px;
}
body[data-page="rfid"] .rfid-table-wrapper .card-uid-display .chip{background:#eff6ff;color:#1d4ed8;border-radius:6px}
body[data-page="rfid"] .rfid-table-wrapper .expiry-warning{
    display:inline-flex;align-items:center;gap:4px;margin:0 0 0 7px;padding:2px 8px;border-radius:999px;
    background:#fef3c7;color:#b45309;font-size:10.5px;font-weight:700;
}
body[data-page="rfid"] .rfid-table-wrapper .action-group{gap:4px;justify-content:flex-end}

/* -- Pool table -- */
body[data-page="rfid"] .pool-header{padding:13px 18px;background:#f8faff;border-bottom:1px solid #e5e7eb}
body[data-page="rfid"] .pool-title h3{font-size:13px;font-weight:700;letter-spacing:-.01em;color:#1e293b}
body[data-page="rfid"] .pool-title i{color:#6366f1!important;font-size:12px}
body[data-page="rfid"] .pool-count{background:#e0e7ff;color:#4338ca;font-size:11px;font-weight:800;min-width:22px;height:20px}
body[data-page="rfid"] .pool-subtitle{padding-left:0;margin:3px 0 0;font-size:12px}
body[data-page="rfid"] .pool-table-wrap{padding:0;max-height:300px;overflow:auto}
body[data-page="rfid"] .pool-table thead th{
    position:sticky;top:0;z-index:2;background:#f8fafc;padding:9px 14px;
    border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;
    letter-spacing:.05em;text-transform:uppercase;
}
body[data-page="rfid"] .pool-table td{padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:12.5px}
body[data-page="rfid"] .pool-table tbody tr:hover{background:#eff6ff}
body[data-page="rfid"] .pool-uid{font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12.5px;font-weight:600;color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;padding:3px 9px;border-radius:7px}
body[data-page="rfid"] .pool-idx{color:#94a3b8;font-size:12px;font-weight:600;width:40px}

/* -- Footer -- */
body[data-page="rfid"] .table-footer{padding:12px 18px;background:#f8faff;border-top:1px solid #e2e8f0}
body[data-page="rfid"] .table-footer .info-text{font-size:12.5px;color:#64748b}
body[data-page="rfid"] .table-footer .info-text strong{color:#0f172a;font-variant-numeric:tabular-nums}

/* -- Assign / Edit modals ---------------------------------
   Same treatment as the Documents modal: pinned blue header,
   scrolling body, pinned footer band. The page's own .rfid-modal
   keeps its flex column; these rules only re-skin it and reset the
   negative-margin/padding pairing that assumed the old 30px gutter. */
#assignModal .rfid-modal, #editModal .rfid-modal{
    box-sizing:border-box;max-width:600px;max-height:calc(100vh - 40px);padding:0;
    border:1px solid #dbeafe;border-radius:18px;overflow:hidden;
    box-shadow:0 24px 60px rgba(15,23,42,.22);
}
#assignModal .rfid-modal-header, #editModal .rfid-modal-header{
    flex:0 0 auto;align-items:center;gap:13px;margin:0;padding:18px 22px;
    background:linear-gradient(120deg,#eff6ff,#fff 70%);border-bottom:1px solid #dbeafe;
}
#assignModal .rfid-modal-header .header-icon, #editModal .rfid-modal-header .header-icon{
    width:38px;height:38px;border-radius:11px;font-size:15px;color:#fff;
    background:linear-gradient(140deg,#2563eb,#1d4ed8);
    box-shadow:0 6px 16px rgba(37,99,235,.28);
}
#assignModal .rfid-modal-header h3, #editModal .rfid-modal-header h3{
    margin:0;font-size:17px;font-weight:700;letter-spacing:-.02em;color:#172554;
}
#assignModal .rfid-modal-header p, #editModal .rfid-modal-header p{
    margin:2px 0 0;font-size:12px;color:#64748b;
}
#assignModal .rfid-modal .rfid-modal-body-wrapper, #editModal .rfid-modal .rfid-modal-body-wrapper{
    flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;
    margin:0;padding:20px 22px;background:#fff;
}

/* Section heads: small uppercase label, hairline rule to the edge. */
#assignModal .form-section, #editModal .form-section{
    padding:0 0 16px;margin:0 0 16px;border-bottom:1px solid #e2e8f0;
}
#assignModal .form-section:last-of-type, #editModal .form-section:last-of-type{
    padding-bottom:0;margin-bottom:0;border-bottom:none;
}
#assignModal .form-section-header, #editModal .form-section-header{
    display:flex;align-items:center;gap:9px;margin:0 0 10px;
}
#assignModal .form-section-icon, #editModal .form-section-icon{
    width:26px;height:26px;flex:0 0 26px;border-radius:7px;font-size:11px;
    background:#eff6ff;color:#1d4ed8;display:grid;place-items:center;
}
#assignModal .form-section-title, #editModal .form-section-title{
    font-size:10.5px;font-weight:800;letter-spacing:.075em;text-transform:uppercase;color:#1d4ed8;
}
#assignModal .form-section-subtitle, #editModal .form-section-subtitle{display:none}
#assignModal .form-group, #editModal .form-group{margin:0 0 12px}
#assignModal .form-group:last-child, #editModal .form-group:last-child{margin-bottom:0}
#assignModal .form-group>label, #editModal .form-group>label{
    display:block;margin:0 0 6px;font-size:12px;font-weight:600;color:#475569;
}
#assignModal .form-control, #editModal .form-control{
    box-sizing:border-box;width:100%;height:40px;padding:0 12px;
    border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;
    font:13px Inter,sans-serif;transition:border-color .15s ease,box-shadow .15s ease;
}
#assignModal select.form-control, #editModal select.form-control{padding-right:28px}
#assignModal .form-control:focus, #editModal .form-control:focus{
    outline:0;border-color:#2563eb;background:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.12);
}
#assignModal .form-control::placeholder, #editModal .form-control::placeholder{color:#94a3b8}
#assignModal textarea.form-control, #editModal textarea.form-control{height:auto;padding:10px 12px;line-height:1.5}
#editModal .form-control[readonly]{background:#f1f5f9;color:#64748b;cursor:default}
#editModal .form-group>label i{margin-right:5px;color:#94a3b8;font-size:11px}
/* No custom caret: registrar.css forces `appearance:auto` on selects so the
   native dropdown arrow is already drawn. Adding one produced a double arrow. */
#editModal .rc-select{position:relative}

/* Assign: tab switcher */
#assignModal .assign-tabs{
    display:flex;gap:6px;margin:0 0 14px;padding:4px;border:1px solid #dbeafe;
    border-radius:11px;background:#f8faff;
}
#assignModal .assign-tab{
    flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;
    padding:8px 10px;border:1px solid transparent;border-radius:8px;background:transparent;
    color:#475569;font:600 12.5px Inter,sans-serif;cursor:pointer;transition:all .15s ease;
}
#assignModal .assign-tab:hover{background:#eef4ff;color:#1d4ed8}
#assignModal .assign-tab.active{background:#1d4ed8;border-color:#1d4ed8;color:#fff;box-shadow:0 4px 12px rgba(29,78,216,.22)}
#assignModal .assign-tab-pane{display:none}
#assignModal .assign-tab-pane.active{display:block}

/* Edit: the card itself, as a record. Read-only, so it is visually separate
   from the fields below — you can tell at a glance what you cannot change. */
#editModal .rc-record{
    margin:0 0 16px;padding:14px 16px 12px;border:1px solid #dbeafe;border-radius:13px;
    background:linear-gradient(150deg,#eff6ff,#fff 78%);
}
#editModal .rc-record-top{display:flex;align-items:center;gap:12px}
#editModal .rc-record-chip{
    display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border-radius:10px;
    background:linear-gradient(140deg,#2563eb,#1d4ed8);color:#fff;font-size:14px;
    box-shadow:0 5px 14px rgba(37,99,235,.26);
}
#editModal .rc-record-id{min-width:0;flex:1}
#editModal .rc-record-cap{
    display:block;font-size:9.5px;font-weight:800;letter-spacing:.09em;
    text-transform:uppercase;color:#64748b;margin-bottom:2px;
}
#editModal .rc-record-uid{
    display:block;font:700 19px/1.15 'JetBrains Mono',ui-monospace,monospace;
    letter-spacing:-.02em;color:#172554;overflow-wrap:anywhere;
}
/* Tone variants are class-based (not attribute-based) and deliberately outrank
   the base rule on specificity so the pill can never render stale colours. */
#editModal .rc-record .rc-record-status{
    flex:0 0 auto;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:800;
    letter-spacing:.02em;white-space:nowrap;background:#dcfce7;color:#15803d;
}
#editModal .rc-record .rc-record-status.tone-expired{background:#fef3c7 !important;color:#b45309 !important}
#editModal .rc-record .rc-record-status.tone-lost{background:#fee2e2 !important;color:#dc2626 !important}
#editModal .rc-record .rc-record-status.tone-inactive,
#editModal .rc-record .rc-record-status.tone-archived{background:#f1f5f9 !important;color:#475569 !important}
#editModal .rc-record-student{
    display:flex;align-items:baseline;gap:7px;flex-wrap:wrap;margin:12px 0 0;padding-top:11px;
    border-top:1px solid #e0e7ff;
}
#editModal .rc-record-student #editStudentName{font-size:13.5px;font-weight:600;color:#0f172a}
#editModal .rc-record-sub{font-size:12px;color:#64748b}
#editModal .rc-record-foot{
    display:flex;align-items:center;gap:7px;margin:9px 0 0;font-size:11.5px;color:#64748b;
}
#editModal .rc-record-foot i{color:#94a3b8;font-size:10.5px;flex:0 0 auto}

/* Edit: the one decision. This replaces the old Status + Reason pair, which
   let a card be saved as status=active with reason=graduated — two controls
   describing the same fact, with no stated relationship. Each tile writes
   BOTH fields, so the pair cannot disagree. */
#editModal .rc-choices{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#editModal .rc-choice{position:relative;display:block;margin:0;cursor:pointer}
/* Seven tiles in three columns leaves an orphan in the last row with two empty
   cells beside it. Letting the final "Something else" tile span the row closes
   the hole and reads correctly as the catch-all it is. */
#editModal .rc-choice:last-child{grid-column:1/-1}
/* Kept focusable (not display:none) so the group is keyboard-navigable. */
#editModal .rc-choice-input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}
#editModal .rc-choice-body{
    display:flex;flex-direction:column;gap:3px;height:100%;padding:12px 13px;
    /* border-box is load-bearing, not tidiness. `height:100%` is the grid row
       height, and under content-box the padding and border are added on top of
       it, so every tile rendered 26px taller than its cell and overlapped the
       row below by 16px once the gap was taken off. Measured, not eyeballed. */
    box-sizing:border-box;
    border:1.5px solid #e2e8f0;border-radius:11px;background:#fff;
    transition:border-color .15s ease,background .15s ease,box-shadow .15s ease;
}
#editModal .rc-choice:hover .rc-choice-body{border-color:#93c5fd;background:#f8faff}
#editModal .rc-choice-input:focus-visible + .rc-choice-body{box-shadow:0 0 0 3px rgba(37,99,235,.28);border-color:#2563eb}
#editModal .rc-choice-input:checked + .rc-choice-body{border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 1px #2563eb inset}
#editModal .rc-choice-label{font-size:12.5px;font-weight:700;color:#0f172a;line-height:1.25}
#editModal .rc-choice-input:checked + .rc-choice-body .rc-choice-label{color:#1d4ed8}
#editModal .rc-choice-meta{font-size:11px;color:#64748b;line-height:1.25}
/* Retiring choices read heavier than "still in use" — the one option anyone
   can undo just by clicking again. */
#editModal .rc-choice.is-retiring .rc-choice-label{color:#7f1d1d}
#editModal .rc-choice.is-retiring .rc-choice-input:checked + .rc-choice-body{border-color:#dc2626;background:#fef2f2;box-shadow:0 0 0 1px #dc2626 inset}
#editModal .rc-choice.is-retiring .rc-choice-input:checked + .rc-choice-body .rc-choice-label{color:#b91c1c}
#editModal .rc-choice.is-warning .rc-choice-input:checked + .rc-choice-body{border-color:#d97706;background:#fffbeb;box-shadow:0 0 0 1px #d97706 inset}
#editModal .rc-choice.is-warning .rc-choice-input:checked + .rc-choice-body .rc-choice-label{color:#b45309}

/* Consequence: states what saving will actually do, before saving. */
#editModal .rc-effect{
    display:flex;align-items:flex-start;gap:8px;margin:14px 0 0;padding:10px 12px;
    border:1px solid #e2e8f0;border-radius:10px;background:#f8faff;
    font-size:12px;line-height:1.5;color:#475569;
}
#editModal .rc-effect i{color:#2563eb;margin-top:2px;flex:0 0 auto;font-size:11px}
/* Never show an empty bordered bar if it has not been filled in. */
#editModal .rc-effect:empty{display:none}
#editModal .rc-effect b{font-weight:700;color:#0f172a}
#editModal .rc-effect.is-retiring{background:#fef2f2;border-color:#fecaca;color:#7f1d1d}
#editModal .rc-effect.is-retiring i{color:#dc2626}
#editModal .rc-effect.is-retiring b{color:#991b1b}
#editModal .rc-effect.is-warning{background:#fffbeb;border-color:#fde68a;color:#78350f}
#editModal .rc-effect.is-warning i{color:#d97706}
#editModal .rc-effect.is-warning b{color:#92400e}

/* The reason that was already recorded. archive_reason was written by the API
   but read by nothing, so a saved reason was invisible. Shown read-only. */
#editModal .rc-previous{
    display:flex;align-items:flex-start;gap:8px;margin:0 0 14px;padding:9px 12px;
    border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;
    font-size:11.5px;line-height:1.5;color:#64748b;
}
#editModal .rc-previous i{color:#94a3b8;margin-top:2px;flex:0 0 auto}
#editModal .rc-previous b{font-weight:700;color:#334155}
#editModal .rc-previous[hidden]{display:none}

/* Fields that only make sense for some outcomes. The rhythm matches the gap
   above (14px) rather than living as an inline style, which could not be
   overridden and drifted out of step with the rest of the modal. */
#editModal .rc-conditional[hidden]{display:none}
#editModal .rc-conditional{animation:rcFade .16s ease;margin:14px 0 0 !important}
@keyframes rcFade{from{opacity:0}to{opacity:1}}
#editModal .rc-required{color:#dc2626;font-weight:700}
@media(max-width:560px){#editModal .rc-choices{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:380px){#editModal .rc-choices{grid-template-columns:1fr}}

/* Notices */
#assignModal .rfid-notice, #editModal .rfid-notice{
    flex:0 0 auto;margin:0;padding:12px 22px;font-size:12px;line-height:1.5;color:#475569;
    background:#f8faff;border-top:1px solid #e2e8f0;display:flex;align-items:flex-start;gap:8px;
}
#assignModal .rfid-notice i, #editModal .rfid-notice i{color:#2563eb;margin-top:1px;flex:0 0 auto}

/* The Student ID consequence, in the assign modal only. It is not an info tip:
   it states what pressing "Assign Card" will also produce, so it is built like
   the #editModal .rc-effect band above — a card-shaped notice with a solid
   left rule — rather than as more form furniture. The all-caps line is the
   notice, the sentence under it is the reassurance; giving them separate sizes
   keeps the caps from reading as the whole message. */
#assignModal .rfid-notice-issue{
    gap:12px;padding:13px 20px;align-items:center;
    background:linear-gradient(150deg,#eff6ff,#f8faff 78%);
    border-top:1px solid #dbeafe;border-left:4px solid #1d4ed8;
}
#assignModal .rfid-notice-issue .rn-icon{
    width:30px;height:30px;flex:0 0 30px;border-radius:9px;
    background:linear-gradient(140deg,#2563eb,#1d4ed8);color:#fff;
    display:grid;place-items:center;font-size:12px;
    box-shadow:0 5px 14px rgba(37,99,235,.26);
}
#assignModal .rfid-notice-issue .rn-icon i{margin:0;color:#fff;font-size:12px}
#assignModal .rfid-notice-issue .rn-text{display:block;min-width:0}
#assignModal .rfid-notice-issue .rn-text b{
    display:block;font-size:14px;font-weight:800;letter-spacing:.05em;
    line-height:1.25;color:#172554;
}
#assignModal .rfid-notice-issue .rn-sub{
    display:block;margin-top:3px;font-size:11.5px;line-height:1.45;color:#64748b;
}

/* Footer: pinned band */
#assignModal .rfid-modal-actions, #editModal .rfid-modal-actions{
    flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:9px;
    margin:0;padding:14px 22px;background:#f8faff;border-top:1px solid #e2e8f0;
}
#assignModal .rfid-modal-actions .btn, #editModal .rfid-modal-actions .btn{
    box-sizing:border-box;min-height:40px;padding:0 20px;font-size:13px;
    display:inline-flex;align-items:center;gap:7px;justify-content:center;
}
#assignModal #assignSubmitBtn:disabled{opacity:.55;cursor:not-allowed}

/* View switcher: two peer segments rather than one button.
   A single "Show retired" button has to relabel itself to "Show live" once you
   switch, so the reader re-reads the control on every click and the retired
   count is invisible until they go looking. Two segments keep both counts on
   screen and never change meaning. */
.rfid-view-switch{
    display:inline-flex;gap:2px;padding:2px;flex:0 0 auto;
    border:1px solid #e2e8f0;border-radius:10px;background:#f1f5f9;
}
.rfid-view-switch button{
    display:inline-flex;align-items:center;gap:7px;
    padding:6px 12px;border:0;border-radius:8px;background:transparent;
    font:600 12.5px Inter,system-ui,sans-serif;color:#64748b;cursor:pointer;
    transition:background .12s ease,color .12s ease,box-shadow .12s ease;
}
.rfid-view-switch button:hover{color:#0f172a}
.rfid-view-switch button[aria-selected="true"]{
    background:#fff;color:#0f172a;
    /* The lift reads as a card slipped into a holder - the subject matter. */
    box-shadow:0 1px 2px rgba(15,23,42,.10);
}
.rfid-view-switch button:focus-visible{
    outline:none;box-shadow:0 0 0 2px #f1f5f9,0 0 0 4px rgba(37,99,235,.45);
}
.rfid-view-switch i{font-size:11px;opacity:.65}
.rfid-view-switch .n{
    min-width:15px;text-align:right;font-variant-numeric:tabular-nums;
    font-size:11px;font-weight:700;color:#94a3b8;
}
.rfid-view-switch button[aria-selected="true"] .n{color:#334155}
/* Zero is still worth showing - it tells the registrar the group is empty
   before they click - but it should not compete for attention. */
.rfid-view-switch button.is-empty .n{opacity:.6}

/* The header becomes a row so the switch sits opposite the heading instead of
   stacking under it. The heading itself is a plain block with no display
   value, which is why the control dropped to its own line. */
body[data-page="rfid"] .rfid-table-header{
    display:flex;align-items:center;justify-content:space-between;gap:16px;
}
@media(max-width:640px){
    body[data-page="rfid"] .rfid-table-header{flex-direction:column;align-items:stretch;gap:10px}
    .rfid-view-switch{width:100%}
    .rfid-view-switch button{flex:1;justify-content:center}
}

/* -- Empty state -- */
.rc-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:220px;padding:38px 20px;text-align:center}
.rc-empty i{font-size:34px;color:#cbd5e1}
.rc-empty p{margin:0;font-size:14px;font-weight:600;color:#64748b}
.rc-empty span{font-size:12.5px;color:#94a3b8}
body[data-page="rfid"] td.rc-empty{display:table-cell;height:220px;min-height:0;vertical-align:middle;text-align:center}
body[data-page="rfid"] td.rc-empty i,
body[data-page="rfid"] td.rc-empty p,
body[data-page="rfid"] td.rc-empty span{display:block}

/* -- Responsive -- */
@media(max-width:1200px){body[data-page="rfid"] .rfid-stats{grid-template-columns:repeat(3,minmax(0,1fr))}
    body[data-page="rfid"] .rfid-stat-card:nth-child(3n){border-right:0}
    body[data-page="rfid"] .rfid-stat-card:nth-child(-n+3){border-bottom:1px solid #e2e8f0}}
@media(max-width:900px){body[data-page="rfid"] .dashboard-main>.header{flex-direction:column;align-items:flex-start}
    body[data-page="rfid"] .dashboard-main>.header .header-actions{width:100%;justify-content:flex-start}
    body[data-page="rfid"] .rfid-table-wrap{max-height:none}}
@media(max-width:600px){body[data-page="rfid"] .dashboard-main>.header{padding:21px 18px}
    body[data-page="rfid"] .dashboard-main>.header .title h1{font-size:25px}
    body[data-page="rfid"] .dashboard-main>.header .header-actions{flex-direction:column;align-items:stretch}
    body[data-page="rfid"] .dashboard-main>.header .btn{justify-content:center}
    body[data-page="rfid"] .rfid-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    body[data-page="rfid"] .rfid-stat-card:nth-child(3n){border-right:1px solid #e2e8f0}
    body[data-page="rfid"] .rfid-stat-card:nth-child(2n){border-right:0}
    body[data-page="rfid"] .rfid-stat-card{border-bottom:1px solid #e2e8f0}
    body[data-page="rfid"] .search-bar .search-actions{width:100%}
    body[data-page="rfid"] .search-bar .search-actions .btn{flex:1 1 auto;justify-content:center}}
@media(prefers-reduced-motion:reduce){body[data-page="rfid"] .rfid-stat-card{transition:none}}
</style>
<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <div class="rc-kicker"><i class="fas fa-id-card"></i> Card registry</div>
            <h1>RFID Cards</h1>
            <p>Issue cards to students, watch for expiries, and archive cards that are no longer in use.</p>
        </div>
        <div class="header-actions">
            <a href="rfid-scan-logs.php" class="btn btn-secondary">
                <i class="fas fa-clock-rotate-left"></i> Scan Logs
            </a>
            <button class="btn btn-secondary" id="openRegisterModal" onclick="openRegisterModal()">
                <i class="fas fa-layer-group"></i> Register Cards
            </button>
            <button class="btn btn-primary" id="openAssignModal" onclick="openAssignModal()">
                <i class="fas fa-plus"></i> Assign Card
            </button>
        </div>
    </header>

    <!-- Stats -->
    <div class="rfid-stats">
        <div class="rfid-stat-card rc-k-total">
            <div class="stat-top">
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $trendActive ?>%</span>
            </div>
            <div class="stat-number"><?= $totalCards ?></div>
            <div class="stat-label">Total Cards</div>
        </div>
        <div class="rfid-stat-card rc-k-active">
            <div class="stat-top"></div>
            <div class="stat-number"><?= $activeCards ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="rfid-stat-card rc-k-pool">
            <div class="stat-top"></div>
            <div class="stat-number"><?= $availableCards ?></div>
            <div class="stat-label">In Pool</div>
        </div>
        <div class="rfid-stat-card rc-k-expired">
            <div class="stat-top"></div>
            <div class="stat-number"><?= $expiredCards ?></div>
            <div class="stat-label">Expired</div>
        </div>
        <div class="rfid-stat-card rc-k-lost">
            <div class="stat-top"></div>
            <div class="stat-number"><?= $lostCards ?></div>
            <div class="stat-label">Lost</div>
        </div>
        <div class="rfid-stat-card rc-k-archived">
            <div class="stat-top"></div>
            <div class="stat-number"><?= $archivedCards ?></div>
            <div class="stat-label">Archived</div>
        </div>
    </div>

    <!-- Search + Table -->
    <div class="search-table-container">
        <div class="search-bar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="rfidSearch" placeholder="Search UID / student..." />
                <button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
            </div>
            <div class="search-actions">
                <div class="filter-select-wrapper" style="height:40px;min-width:130px;">
                    <i class="fas fa-filter filter-select-icon"></i>
                    <select id="statusFilter" style="height:40px;">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="lost">Lost</option>
                        <option value="archived">Archived</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <i class="fas fa-chevron-down filter-select-arrow"></i>
                </div>
                <button type="button" id="rfidDistributionBtn" class="btn btn-secondary" title="Prepare a reviewable card distribution plan">
                    <i class="fas fa-wand-magic-sparkles" style="color:#2563eb;"></i> Distribution Assistant
                </button>
                <button type="button" id="resetFilterBtn" class="btn btn-light"><i class="fas fa-undo"></i> Reset</button>
            </div>
        </div>

        <div id="aiRfidInterpretation" style="display:none;padding:10px 14px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:14px;">
            <i class="fas fa-brain" style="color:#2563eb;"></i>
            <span id="aiRfidExplanation" style="color:#1e40af;margin-left:8px;font-size:13px;"></span>
        </div>

    <!-- Assigned / Status Cards Table -->
    <div class="rfid-table-wrapper">
        <div class="rfid-table-header">
            <!-- No count badge here on purpose. The switch already carries both
                 counts, and the stat row above already carries the total; a third
                 number made the header read "10" under a "11 TOTAL CARDS" tile,
                 which looked like a bug rather than a scope difference. -->
            <h3><i class="fas fa-credit-card" style="color:#2563eb;"></i> Cards</h3>
            <div class="rfid-view-switch" role="tablist" aria-label="Which cards to show">
                <button type="button" role="tab" data-view="active" aria-selected="true">
                    <i class="fas fa-id-card"></i> Live
                    <span class="n" id="rfidCountActive"><?= count($tableCards) ?></span>
                </button>
                <button type="button" role="tab" data-view="retired" aria-selected="false"
                        class="<?= $retiredCount === 0 ? 'is-empty' : '' ?>">
                    <i class="fas fa-box-archive"></i> Retired
                    <span class="n" id="rfidCountRetired"><?= $retiredCount ?></span>
                </button>
            </div>
        </div>
        <div class="rfid-table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Card UID</th>
                    <th>Student</th>
                    <th>ID Number</th>
                    <th>QR</th>
                    <th>Issued</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="rfidTableBody">
                <?php if (empty($tableCards) && empty($retiredCards)): ?>
                    <tr data-empty="both">
                        <td colspan="9" class="rc-empty">
                            <i class="fas fa-credit-card"></i>
                            <p>No cards yet</p>
                            <span>Assign a card to a student to get started.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php if (empty($tableCards)): ?>
                        <tr data-empty="active">
                            <td colspan="9" class="rc-empty">
                                <i class="fas fa-credit-card"></i>
                                <p>No live cards</p>
                                <span>Cards in use appear here.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (empty($retiredCards)): ?>
                        <tr data-empty="retired">
                            <td colspan="9" class="rc-empty">
                                <i class="fas fa-box-archive"></i>
                                <p>No retired cards</p>
                                <span>Cards marked lost, archived or inactive collect here.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <?php foreach (array_merge($tableCards, $retiredCards) as $cardIndex => $card):
                        $isRetired = in_array($card['status'], $RETIRED_STATUSES, true);
                        $initials = '';
                        if (!empty($card['student_name'])) {
                            $names = explode(' ', $card['student_name']);
                            $initials = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                        }
                        $avatarClass = $avatarClasses[$card['id']] ?? 'blue';
                        // The avatar: the photograph from Digital File Storage if it
                        // is actually on this server, otherwise the initials.
                        //
                        // It used to read students.photo and nothing else, and
                        // students.photo is NULL for every student here - the
                        // pictures were uploaded through Digital File Storage,
                        // which writes to the documents table. So the table
                        // showed "RT" for a student whose photograph was sitting
                        // in the database the whole time.
                        //
                        // This now calls the shared studentPhotoUrl(), the same
                        // one registrar/students.php uses. The loop that was
                        // written out here is identical to the one that was
                        // written out there, and two identical loops in two
                        // places are one bug-fix away from being two different
                        // behaviours.
                        //
                        // The disk check is the load-bearing part: a path naming
                        // a file this host does not have yields '', so a missing
                        // photograph produces initials rather than a broken-image
                        // glyph and a 404.
                        $avatarUrl = storedFileDiskPath($card['photo_path'] ?? '')
                            ? $APP_ROOT . 'api/student-photo.php?student_id=' . (int)$card['student_id'] : '';
                    ?>
                        <tr data-card='<?= htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8') ?>'
                            data-retired="<?= $isRetired ? '1' : '0' ?>"
                            data-id="<?= (int)$card['id'] ?>"
                            data-student-id="<?= (int)($card['student_id'] ?? 0) ?>"
                            data-name="<?= htmlspecialchars($card['student_name'] ?? '', ENT_QUOTES) ?>"
                            data-photo="<?= htmlspecialchars($avatarUrl, ENT_QUOTES) ?>"
                            data-course="<?= htmlspecialchars($card['course'] ?? '', ENT_QUOTES) ?>"
                            data-year="<?= htmlspecialchars($card['year_level'] ?? '', ENT_QUOTES) ?>"
                            data-idnumber="<?= htmlspecialchars($card['student_id_number'] ?? '', ENT_QUOTES) ?>"
                            data-idtype="<?= htmlspecialchars($card['id_type'] ?? 'school_id', ENT_QUOTES) ?>"
                            data-issued="<?= htmlspecialchars($card['id_issue_date'] ?? $card['issued_date'] ?? '', ENT_QUOTES) ?>"
                            data-expiry="<?= htmlspecialchars($card['id_expiry_date'] ?? $card['expiry_date'] ?? '', ENT_QUOTES) ?>"
                            data-qr="<?= htmlspecialchars(resolveStudentQrUrl($card['qr_code_path'] ?? '', $APP_ROOT), ENT_QUOTES) ?>"
                            data-student-number="<?= htmlspecialchars($card['student_number'] ?? '', ENT_QUOTES) ?>">
                            <td style="color:#94a3b8;font-size:13px;font-weight:600;"><?= $cardIndex + 1 ?></td>
                            <td>
                                <div class="card-uid-display">
                                    <span class="chip"><i class="fas fa-microchip"></i></span>
                                    <?= htmlspecialchars($card['card_uid']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($card['student_id'])): ?>
                                    <div class="student-info">
                                        <?php // Both are always rendered; the photo simply
                                              // covers the initials. If the file is deleted
                                              // between render and paint, or the host is
                                              // momentarily unreachable, onerror uncovers
                                              // the initials rather than leaving a broken
                                              // image icon in a table a clerk reads all
                                              // day. ?>
                                        <?php if ($avatarUrl !== ''): ?>
                                            <img class="student-avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($card['student_name']) ?>" style="object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';this.onerror=null;">
                                        <?php endif; ?>
                                        <div class="student-avatar <?= $avatarClass ?>" style="<?= $avatarUrl !== '' ? 'display:none;' : '' ?>"><?= $initials ?: '?' ?></div>
                                        <div>
                                            <div class="student-name"><?= htmlspecialchars($card['student_name']) ?></div>
                                            <div class="student-detail"><?= htmlspecialchars($card['student_number']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="unassigned-text">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($card['student_id_number'])): ?>
                                    <span style="font-family:ui-monospace,Consolas,monospace;font-size:12px;font-weight:600;color:#2563eb;background:#eff6ff;padding:3px 8px;border-radius:6px;"><?= htmlspecialchars($card['student_id_number']) ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Branch on the RESOLVED url, not on qr_code_path.
                                      // The column can hold a filename that is not on this
                                      // host - uploads/ is gitignored, so a database that
                                      // arrived from elsewhere carries QR files that were
                                      // never deployed. Testing the column therefore picked
                                      // the <img src> branch and asked the browser for a
                                      // file that cannot exist, which is a 404 and a broken
                                      // image where the QR should be.
                                      //
                                      // resolveStudentQrUrl() returns '' for a file that is
                                      // not there, so the elseif below takes over and the
                                      // bundled QR library draws it from the student id.
                                      // The onerror is kept as a second line of defence for
                                      // a file deleted between render and click. ?>
                                <?php $qrUrl = resolveStudentQrUrl($card['qr_code_path'] ?? '', $APP_ROOT); ?>
                                <?php if ($qrUrl !== ''): ?>
                                    <img class="qr-thumb" src="<?= htmlspecialchars($qrUrl) ?>" alt="QR"
                                         onerror="this.onerror=null;this.classList.add('qr-auto');this.removeAttribute('data-qr-student-id');generateQrThumbFallback(this);"
                                         onclick="showQrModal(this)"
                                         data-name="<?= htmlspecialchars($card['student_name'] ?? '', ENT_QUOTES) ?>"
                                         data-id="<?= htmlspecialchars($card['student_number'] ?? '', ENT_QUOTES) ?>">
                                <?php elseif (!empty($card['student_id'])): ?>
                                    <img class="qr-thumb qr-auto" data-qr-student-id="<?= (int)$card['student_id'] ?>" data-qr-name="<?= htmlspecialchars($card['student_name'] ?? '', ENT_QUOTES) ?>" data-qr-number="<?= htmlspecialchars($card['student_number'] ?? '', ENT_QUOTES) ?>" alt="QR" style="cursor:pointer;" onclick="showQrModal(this)">
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $card['issued_date'] ? date('M d, Y', strtotime($card['issued_date'])) : '&mdash;' ?></td>
                            <td>
                                <?= $card['expiry_date'] ? date('M d, Y', strtotime($card['expiry_date'])) : '&mdash;' ?>
                                <?php
                                    $daysLeft = null;
                                    if ($card['expiry_date']) {
                                        $daysLeft = (strtotime($card['expiry_date']) - time()) / (60 * 60 * 24);
                                    }
                                    if ($card['status'] === 'active' && $daysLeft !== null && $daysLeft <= 30 && $daysLeft > 0):
                                ?>
                                    <span class="expiry-warning"><i class="fas fa-clock"></i> <?= round($daysLeft) ?> days</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= htmlspecialchars($card['status']) ?>">
                                    <span class="status-dot"></span>
                                    <?= ucfirst(htmlspecialchars($card['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <button class="action-btn view" onclick="viewIdCard(this)" title="View ID Card">
                                        <i class="fas fa-id-card"></i>
                                    </button>
                                    <?php // Archive and Delete are gone: the Edit modal is now the one
                                          // way to change a card's state, and it records a reason.
                                          // Hard delete stays available on the unassigned pool below,
                                          // which is a different operation - clearing bad stock, not
                                          // retiring a card somebody holds. ?>
                                    <button class="action-btn edit" onclick="openEditModal(<?= (int)$card['id'] ?>)" title="<?= $isRetired ? 'Edit retired card' : 'Edit' ?>">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="table-footer">
            <div class="info-text">Showing <strong id="showingCount"><?= count($tableCards) ?></strong> of <strong id="totalCount"><?= count($tableCards) ?></strong> <span id="totalCountNoun">live cards</span></div>
        </div>
</div><!-- /search-table-container -->

    <!-- Available Card Pool -->
    <?php if (!empty($poolCards)): ?>
    <div class="pool-section" id="poolSection">
        <div class="pool-header">
            <div class="pool-title">
                <i class="fas fa-box-open" style="color:#7c3aed;"></i>
                <h3>Available Card Pool <span class="pool-count"><?= count($poolCards) ?></span></h3>
            </div>
            <p class="pool-subtitle">Unregistered cards waiting to be assigned to students</p>
        </div>
        <div class="pool-table-wrap" id="poolGrid">
            <table class="pool-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Card UID</th>
                        <th>Registered</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($poolCards as $pi => $pc): ?>
                    <tr class="pool-row" data-uid="<?= htmlspecialchars($pc['card_uid']) ?>" data-id="<?= (int)$pc['id'] ?>">
                        <td class="pool-idx"><?= $pi + 1 ?></td>
                        <td><span class="pool-uid"><?= htmlspecialchars($pc['card_uid']) ?></span></td>
                        <td class="pool-date"><?= $pc['registered_at'] ? date('M d, Y', strtotime($pc['registered_at'])) : '—' ?></td>
                        <td class="pool-actions">
                            <button class="pool-del-btn" onclick="confirmDelete(<?= (int)$pc['id'] ?>, '<?= htmlspecialchars($pc['card_uid'], ENT_QUOTES) ?>')" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- RFID Distribution Assistant -->
    <div class="ai-panel" id="rfidDistributionPanel">
        <div class="ai-panel-header">
            <div class="ai-icon"><i class="fas fa-wand-magic-sparkles"></i></div>
            <div><h3>RFID Distribution Assistant</h3><p>Prepare a reviewable plan for available cards and students without an RFID card.</p></div>
            <button class="btn btn-light" style="margin-left:auto;" onclick="openRfidDistribution()"><i class="fas fa-list-check"></i> Prepare distribution</button>
        </div>
        <div class="ai-insight-card" style="border-left:4px solid #2563eb;">
            <strong style="font-size:13px;color:#1e40af;">How it works</strong>
            <div class="sub" style="margin-top:4px;line-height:1.6;">The assistant pairs available cards with eligible students, then waits for your confirmation. It never assigns cards automatically.</div>
        </div>
    </div>
</main>

﻿<!-- -- Assign Card Modal -- -->
<link rel="stylesheet" href="../assets/id-card/assign-card.css?v=20261111-1">
<div class="logout-modal-overlay" id="assignModal" role="dialog" aria-modal="true" aria-labelledby="assignTitle">
 <div class="logout-modal rfid-modal assign-dialog">
  <header class="assign-header"><div class="assign-heading-icon"><i class="fas fa-credit-card" aria-hidden="true"></i></div><div><h3 id="assignTitle">Assign RFID card</h3><p>Link a card to a student and set its validity.</p></div><button type="button" class="assign-close" onclick="closeAssignModal()" aria-label="Close assignment"><i class="fas fa-xmark"></i></button></header>
  <form id="assignCardForm"><input type="hidden" id="selectedStudentId" name="student_id"><input type="hidden" id="assignMode" value="pool"><input type="hidden" id="selectedPoolCardId">
   <div class="assign-body"><div class="assign-fields">
    <section class="assign-section"><h4><span>1</span> Select student</h4><div class="student-search-wrapper assign-student-search"><label for="studentSearchInput" class="assign-sr-only">Search name or student number</label><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="studentSearchInput" class="form-control" placeholder="Search name or student number" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="studentSearchResults" aria-autocomplete="list"><div id="studentSearchResults" class="student-search-results" role="listbox" hidden></div></div>
    <div id="selectedStudentDisplay" class="assign-selected" hidden><span id="assignStudentAvatar">—</span><div><strong id="selectedName"></strong><small id="selectedId"></small><small id="assignStudentCourse"></small></div><button type="button" onclick="clearSelectedStudent()">Change</button></div>
    </section>
    <section class="assign-section"><h4><span>2</span> Choose a card</h4><div class="assign-tabs" role="group" aria-label="Card source"><button type="button" class="assign-tab active" data-assign-source="pool" onclick="switchAssignTab('pool')" aria-pressed="true"><i class="fas fa-layer-group" aria-hidden="true"></i> Available pool</button><button type="button" class="assign-tab" data-assign-source="new" onclick="switchAssignTab('new')" aria-pressed="false"><i class="fas fa-keyboard" aria-hidden="true"></i> New UID</button></div>
    <div class="assign-tab-pane active" id="tabPool"><label for="poolCardSelect">Available RFID card</label><div class="assign-pool-row"><select id="poolCardSelect" class="form-control" onchange="onPoolCardSelect(this)"><option value="">Select an available card</option><?php foreach($availablePool as $pc): ?><option value="<?= (int)$pc['id'] ?>" data-uid="<?= htmlspecialchars($pc['card_uid'],ENT_QUOTES) ?>"><?= htmlspecialchars($pc['card_uid']) ?></option><?php endforeach; ?></select><span id="assignAvailability" class="assign-availability" hidden><i class="fas fa-circle"></i> Available</span></div><p class="assign-field-help" id="assignPoolHelp"><?= $availablePool ? 'Choose an unassigned card from inventory.' : 'No unassigned cards in the pool. Register a new card using New UID.' ?></p></div>
    <div class="assign-tab-pane" id="tabNew"><label for="cardUid">New card UID</label><div class="assign-uid-field"><input id="cardUid" class="form-control" inputmode="numeric" maxlength="10" placeholder="Enter the 10-digit card UID" autocomplete="off" aria-describedby="assignUidStatus"><span id="uidLengthBadge">0/10</span></div><p id="assignUidStatus" class="assign-field-help" role="status">Enter the UID printed by your RFID reader.</p></div>
    </section>
    <section class="assign-section assign-validity"><h4><span>3</span> Set validity</h4><div class="assign-date-row"><label for="issuedDate">Issue date<input type="date" id="issuedDate" name="issued_date" class="form-control" value="<?= date('Y-m-d') ?>" required></label><label for="expiryDate">Expiry date<input type="date" id="expiryDate" name="expiry_date" class="form-control" value="<?= date('Y-m-d',strtotime('+1 year')) ?>" required></label></div><label class="assign-notes-label" for="cardNotes">Notes <span>(optional)</span></label><textarea id="cardNotes" name="notes" class="form-control" rows="2" maxlength="1000" placeholder="e.g. Replacement for a lost card"></textarea></section>
   </div>
   <aside class="assign-summary"><h4>Assignment summary</h4><div class="assign-rfid-art" aria-label="Campus RFID card illustration"><div class="assign-art-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><strong>Bestlink College</strong><small>CAMPUS ACCESS</small></div></div><svg class="assign-coil" viewBox="0 0 120 120" fill="none" aria-hidden="true"><g stroke="#18e7ff" stroke-width="1.6" stroke-linecap="round"><path d="M49 109a50 50 0 1 1 22 0M50 101a42 42 0 1 1 20 0M51 93a34 34 0 1 1 18 0M52 85a26 26 0 1 1 16 0"/></g><g fill="#83f0ff"><circle cx="49" cy="109" r="2.2"/><circle cx="71" cy="109" r="2.2"/><circle cx="50" cy="101" r="2"/><circle cx="70" cy="101" r="2"/><circle cx="51" cy="93" r="1.8"/><circle cx="69" cy="93" r="1.8"/><circle cx="52" cy="85" r="1.7"/><circle cx="68" cy="85" r="1.7"/></g></svg><div class="assign-art-bottom"><div><small>CARD UID</small><strong id="assignPreviewUid">— — — — —</strong></div><span>RFID</span></div></div>
   <dl class="assign-summary-rows"><div><dt>Student</dt><dd id="assignSummaryStudent">Not selected</dd></div><div><dt>Card source</dt><dd id="assignSummarySource">Available pool</dd></div><div><dt>Validity</dt><dd id="assignSummaryValidity">—</dd></div></dl>
   <div class="assign-included"><i class="fas fa-check" aria-hidden="true"></i><div><strong>Student ID card included</strong><p>A student ID card and verification QR code are generated automatically.</p></div></div></aside>
   </div>
   <p id="assignFormError" class="assign-error" role="alert" hidden></p>
   <footer class="assign-footer"><span><i class="fas fa-lock" aria-hidden="true"></i> Recorded under your account.</span><div><button type="button" class="assign-cancel" onclick="closeAssignModal()">Cancel</button><button type="submit" class="assign-submit" id="assignSubmitBtn" disabled>Assign card <i class="fas fa-arrow-right" aria-hidden="true"></i></button></div></footer>
  </form>
 </div>
</div>

<div class="logout-modal-overlay" id="editModal">
    <div class="logout-modal rfid-modal">
        <div class="rfid-modal-header">
            <div class="header-icon edit"><i class="fas fa-pen"></i></div>
            <div>
                <h3>Edit RFID Card</h3>
                <p>Update status, expiry date, and notes.</p>
            </div>
        </div>

        <form id="editCardForm">
            <input type="hidden" id="editCardId" value="">
            <div class="rfid-modal-body-wrapper">

            <!-- What this card is. Read-only, so it is presented as a record
                 rather than as more form fields to scan past. -->
            <div class="rc-record">
                <div class="rc-record-top">
                    <span class="rc-record-chip"><i class="fas fa-microchip"></i></span>
                    <div class="rc-record-id">
                        <span class="rc-record-cap">Card UID</span>
                        <span id="editUid" class="rc-record-uid">&mdash;</span>
                    </div>
                    <span class="rc-record-status" id="editStatusPill">Active</span>
                </div>
                <div class="rc-record-student">
                    <span class="rc-record-cap">Assigned to</span>
                    <span id="editStudentName">&mdash;</span>
                    <span id="editStudentNumber" class="rc-record-sub"></span>
                </div>
                <div class="rc-record-foot">
                    <i class="fas fa-clock"></i>
                    <span>Last updated <span id="editUpdatedAt">&mdash;</span></span>
                </div>
            </div>

            <!-- The reason already on record. Shown only when there is one, so a
                 second visit tells you what happened last time. -->
            <div class="rc-previous" id="editPrevious" hidden>
                <i class="fas fa-rotate-left"></i>
                <span>Already recorded: <b id="editPrevReason">&mdash;</b><span id="editPrevMeta"></span></span>
            </div>

            <!-- What you can change: one decision, not two. -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-sliders"></i></div>
                    <div class="form-section-title">What happened to this card?</div>
                </div>

                <div class="rc-choices" role="radiogroup" aria-labelledby="editChoicesLabel">
                    <span id="editChoicesLabel" hidden>What happened to this card?</span>

                    <label class="rc-choice">
                        <input type="radio" name="editOutcome" value="keep" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Still in use</span>
                            <span class="rc-choice-meta">stays active</span>
                        </span>
                    </label>

                    <label class="rc-choice is-warning">
                        <input type="radio" name="editOutcome" value="lost" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Reported lost</span>
                            <span class="rc-choice-meta">marked lost</span>
                        </span>
                    </label>

                    <label class="rc-choice is-retiring">
                        <input type="radio" name="editOutcome" value="transfer" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Transferred out</span>
                            <span class="rc-choice-meta">archived</span>
                        </span>
                    </label>

                    <label class="rc-choice is-retiring">
                        <input type="radio" name="editOutcome" value="dropout" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Dropped out</span>
                            <span class="rc-choice-meta">archived</span>
                        </span>
                    </label>

                    <label class="rc-choice is-retiring">
                        <input type="radio" name="editOutcome" value="graduate" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Graduated</span>
                            <span class="rc-choice-meta">archived</span>
                        </span>
                    </label>

                    <label class="rc-choice is-warning">
                        <input type="radio" name="editOutcome" value="damaged" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Damaged / expired</span>
                            <span class="rc-choice-meta">marked expired</span>
                        </span>
                    </label>

                    <label class="rc-choice is-retiring">
                        <input type="radio" name="editOutcome" value="other" class="rc-choice-input" form="editCardForm">
                        <span class="rc-choice-body">
                            <span class="rc-choice-label">Something else</span>
                            <span class="rc-choice-meta">archived &mdash; needs a note</span>
                        </span>
                    </label>
                </div>

                <!-- Fills in when a choice is made, and says only what will
                     really happen. -->
                <div class="rc-effect" id="editEffect" role="status" aria-live="polite"></div>

                <div class="form-group rc-conditional" id="editExpiryWrap" hidden>
                    <label for="editExpiryDate">Expiry date</label>
                    <input type="date" id="editExpiryDate" class="form-control" />
                </div>

                <div class="form-group rc-conditional" id="editNotesWrap">
                    <label for="editNotes">Notes <span class="rc-required" id="editNotesRequired" hidden>*</span></label>
                    <textarea id="editNotes" class="form-control" rows="3" placeholder="Anything the next person should know about this card."></textarea>
                </div>
            </div>

            </div><!-- /rfid-modal-body-wrapper -->

            <div class="rfid-modal-actions">
                <button type="button" class="btn btn-light" data-close-modal="edit">Cancel</button>
                <button type="submit" class="btn btn-primary" id="editSubmitBtn" disabled>
                    <i class="fas fa-save"></i> <span id="editSubmitLabel">Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- â”€â”€ View Card Modal â”€â”€ -->
<div class="logout-modal-overlay" id="viewModal">
    <div class="logout-modal rfid-view-modal">
        <div class="rfid-view-header">
            <div class="header-icon view"><i class="fas fa-id-card"></i></div>
            <div>
                <h3>Card Details</h3>
                <p>RFID card information and scan history</p>
            </div>
        </div>

        <div class="rfid-view-body-wrapper">
        <div class="rfid-view-body">

            <!-- UID -->
            <div class="rfid-view-uid">
                <div class="uid-icon"><i class="fas fa-microchip"></i></div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Card UID</div>
                    <div class="uid-text" id="viewUid">&mdash;</div>
                </div>
            </div>

            <!-- Student -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <div class="form-section-title">Student</div>
                        <div class="form-section-subtitle">Assigned student information</div>
                    </div>
                </div>
                <ul class="rfid-view-kv">
                    <li><span class="kv-label">Name</span><span class="kv-value" id="viewStudent">&mdash;</span></li>
                    <li><span class="kv-label">Student #</span><span class="kv-value" id="viewStudentNumber">&mdash;</span></li>
                    <li><span class="kv-label">Student ID</span><span class="kv-value" id="viewIdNumber">&mdash;</span></li>
                    <li><span class="kv-label">Course</span><span class="kv-value" id="viewCourse">&mdash;</span></li>
                    <li><span class="kv-label">Year</span><span class="kv-value" id="viewYearLevel">&mdash;</span></li>
                </ul>
            </div>

            <!-- Card Info -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-info-circle"></i></div>
                    <div>
                        <div class="form-section-title">Card Info</div>
                        <div class="form-section-subtitle">Status and validity</div>
                    </div>
                </div>
                <ul class="rfid-view-kv">
                    <li>
                        <span class="kv-label">Status</span>
                        <span class="kv-value">
                            <span class="rfid-view-status-badge" id="viewStatusBadge">
                                <span class="status-dot"></span>
                                <span id="viewStatus">&mdash;</span>
                            </span>
                        </span>
                    </li>
                    <li><span class="kv-label">Status Reason</span><span class="kv-value" id="viewStatusReason">&mdash;</span></li>
                    <li><span class="kv-label">Last Updated</span><span class="kv-value" id="viewStatusUpdatedAt">&mdash;</span></li>
                    <li><span class="kv-label">Issued</span><span class="kv-value" id="viewIssued">&mdash;</span></li>
                    <li><span class="kv-label">Expiry</span><span class="kv-value" id="viewExpiry">&mdash;</span></li>
                </ul>
            </div>

            <!-- Card History (other cards for same student) -->
            <div class="form-section" id="viewHistorySection" style="display:none;">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-clock-rotate-left"></i></div>
                    <div>
                        <div class="form-section-title">Card History</div>
                        <div class="form-section-subtitle">Previous cards for this student</div>
                    </div>
                </div>
                <div id="viewHistory"></div>
            </div>

            <!-- Notes -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-sticky-note"></i></div>
                    <div>
                        <div class="form-section-title">Notes</div>
                        <div class="form-section-subtitle">Additional information</div>
                    </div>
                </div>
                <div class="rfid-view-notes" id="viewNotes">&mdash;</div>
            </div>

            <!-- Scans -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-clock-rotate-left"></i></div>
                    <div>
                        <div class="form-section-title">Recent Scans</div>
                        <div class="form-section-subtitle">Last 5 scan events</div>
                    </div>
                </div>
                <div id="viewScans">
                    <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">Open to load scans.</p>
                </div>
            </div>

        </div>
        </div>

        <div class="rfid-modal-actions">
            <button type="button" class="btn btn-primary" data-close-modal="view">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- â”€â”€ View ID Card Modal â”€â”€ -->
<link rel="stylesheet" href="../assets/id-card/student-id.css?v=20261110-tech-options">
<link rel="stylesheet" href="../assets/id-card/student-id-tech.css?v=20261110-9">
<link rel="stylesheet" href="../assets/id-card/student-id-collection.css?v=20261110-pearl6">
<link rel="stylesheet" href="../assets/id-card/id-studio.css?v=20261111-edit2">
<div class="modal-overlay" id="idCardModal" role="dialog" aria-modal="true" aria-labelledby="idStudioTitle">
 <div class="modal-content idstudio-dialog">
  <header class="idstudio-header"><div class="idstudio-heading"><span class="idstudio-heading-icon"><i class="fas fa-id-card" aria-hidden="true"></i></span><div><p>STUDENT CREDENTIALS</p><h3 id="idStudioTitle">ID card studio</h3></div></div><button type="button" class="idstudio-close" onclick="closeIdStudio()" aria-label="Close ID card studio"><i class="fas fa-xmark" aria-hidden="true"></i></button></header>
  <div class="modal-body idstudio-body">
   <section class="idstudio-preview" aria-label="Card preview"><div class="idstudio-preview-heading"><span><i class="fas fa-circle" aria-hidden="true"></i> Live preview</span><small id="idStudioFaceLabel" aria-live="polite">Front side</small></div><div class="idstudio-canvas">
            <div class="idcard-flip" id="cardFlip"><div class="idcard-scale"><div class="idcard-inner"><div class="idcard" aria-label="Student ID front">
 <div class="idcard-head"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div class="school">BESTLINK COLLEGE<br>OF THE PHILIPPINES<small>STUDENT IDENTIFICATION</small></div></div>
 <div class="idcard-photo-wrap"><div class="idcard-photo-frame"><img class="idcard-photo" id="cardPhoto" alt="Student portrait" style="display:none"><div class="idcard-initials" id="cardInitials" style="display:none">—</div></div></div>
 <div class="idcard-center"><div class="idcard-name" id="cardName">—</div><div class="idcard-number" id="cardNumber">—</div></div>
 <div class="idcard-program"><div class="k">PROGRAM</div><div class="v" id="cardCourse">—</div></div>
 <div class="idcard-sec"><div class="idcard-fields"><div class="idcard-field"><div class="k">ISSUED</div><div class="v" id="cardIssued">—</div></div><div class="idcard-field"><div class="k">VALID UNTIL</div><div class="v" id="cardExpiry">—</div></div></div><div class="idcard-qrbox"><img id="cardQr" alt="Student verification QR code"><div class="cap" id="cardQrCap">Scan to verify</div></div></div>
 <div class="idcard-foot"></div>
</div><div class="idcard-back" aria-label="Student ID back">
 <div class="idcard-head idcard-back-head"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div class="school">BESTLINK COLLEGE<br>OF THE PHILIPPINES<small>Registrar's Office</small></div></div>
 <div class="id-tech-wordmark" aria-hidden="true"><svg viewBox="0 0 296 76" xmlns="http://www.w3.org/2000/svg" fill="none"><g class="bcp-lettering" stroke="currentColor" stroke-width="1.25" stroke-linejoin="miter" transform="translate(45 0) scale(.82 1) skewX(-6)"><path d="M6 7H61L74 19V29L65 37L74 45V57L61 69H6Z M22 20H54L58 24V28L54 31H22Z M22 45H54L58 49V53L54 57H22Z"/><path d="M112 7H163L176 19L164 31L156 22H120L111 31V46L120 55H156L164 46L176 58L163 69H112L94 51V25Z"/><path d="M196 7H250L264 21V39L250 53H213V69H196Z M213 22V38H243L248 33V27L243 22Z"/></g><g class="bcp-circuits" stroke="currentColor" stroke-width="1"><path d="M0 18H8L16 26H24M0 32H7L15 40H22M0 58H9L17 50H22M277 26H281L289 18H296M277 40H283L291 32H296M277 50H281L289 58H296M39 75H98M160 1H204"/></g><g fill="currentColor"><circle cx="24" cy="26" r="1.7"/><circle cx="22" cy="40" r="1.7"/><circle cx="22" cy="50" r="1.7"/><circle cx="277" cy="26" r="1.7"/><circle cx="277" cy="40" r="1.7"/><circle cx="277" cy="50" r="1.7"/><circle cx="39" cy="75" r="1.5"/><path d="M4 65H9L4 71H0ZM12 65H17L12 71H7ZM283 65H288L283 71H278ZM291 65H296L291 71H286Z"/></g></svg></div>
 <div class="idcard-back-content">
 <section class="idcard-back-sec"><div class="idcard-sec-title" data-label="EMERGENCY CONTACT"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> In case of emergency, please contact</div><div id="cardBackEmg" class="idcard-emergency"></div></section>
 <section class="idcard-back-sec"><div class="idcard-sec-title" data-label="ADDRESS"><i class="fa-solid fa-house" aria-hidden="true"></i> Address</div><div class="idcard-addr" id="cardBackAddr">Not on file</div></section>
 <section class="idcard-back-sec"><div class="idcard-sec-title" data-label="CARDHOLDER GUIDELINES"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> Reminders</div><div class="idcard-reminders"><ul><li>This card is non-transferable and must be worn at all times inside the campus.</li><li>Not valid without the signature of the Registrar.</li><li>Report lost cards immediately to the Registrar's Office.</li></ul></div></section>
 <div class="idcard-sig"><div class="line"></div><div class="who">SIGNATURE OF STUDENT</div><div class="idcard-signature-note">NOT VALID WITHOUT SIGNATURE</div></div>
 </div><div class="idcard-back-foot">If found, please return this card to the Registrar's Office or call the school hotline.</div>
</div></div></div></div>

   </div><div class="idstudio-face-controls" role="group" aria-label="Card side"><button type="button" class="selected" data-card-face="front" aria-pressed="true" onclick="setIdStudioFace('front')"><i class="fas fa-id-card" aria-hidden="true"></i> Front</button><button type="button" data-card-face="back" aria-pressed="false" onclick="setIdStudioFace('back')"><i class="fas fa-address-card" aria-hidden="true"></i> Back</button></div></section>
   <aside class="idstudio-controls"><section class="idstudio-appearance"><div class="idstudio-section-heading"><h4>Card appearance</h4><span>7 designs</span></div><p class="idstudio-help">Choose a look for this student’s ID.</p>
<div class="id-design-select"><label for="idDesignSelect">Card design</label><select id="idDesignSelect"><optgroup label="Original designs"><option value="classic">Classic</option><option value="light" selected>Tech Light</option><option value="dark">Tech Dark</option></optgroup><optgroup label="New collection"><option value="arctic">Arctic Circuit</option><option value="cobalt">Cobalt Edge</option><option value="pearl">Pearl Matrix</option><option value="silver">Silver Signal</option></optgroup></select></div>
    <div class="idstudio-style-summary"><span class="idstudio-style-swatch" id="idStudioSwatch"></span><div><strong id="idStudioDesignName">Tech Light</strong><p id="idStudioDesignNote"></p></div></div>
   </section><section class="idstudio-student"><div class="idstudio-section-heading"><h4>Cardholder</h4><button type="button" class="idstudio-edit-button" id="idStudioEditButton" onclick="editIdStudioDetails()" disabled><i class="fas fa-pen" aria-hidden="true"></i> Edit details</button></div><div class="idstudio-person"><span id="idStudioInitials">—</span><div><strong id="idStudioName">—</strong><small id="idStudioNumber">—</small></div></div><dl><div><dt>Program</dt><dd id="idStudioCourse">—</dd></div><div><dt>Valid until</dt><dd id="idStudioExpiry">—</dd></div></dl></section>
   <section class="idstudio-editor" id="idStudioEditor" hidden><div class="idstudio-section-heading"><h4>Edit printed details</h4><button type="button" class="idstudio-editor-back" onclick="cancelIdStudioEdit()" aria-label="Cancel editing"><i class="fas fa-arrow-left"></i> Back</button></div><p class="idstudio-editor-note">Changes apply to this ID only. Student records, student number, QR identity, and RFID access dates stay unchanged.</p><form id="idStudioEditForm">
    <label>Printed name <input name="name" maxlength="100" required autocomplete="off"></label>
    <label>Printed program <input name="program" maxlength="120" autocomplete="off"></label>
    <div class="idstudio-edit-dates"><label>Printed issue date<input type="date" name="issued_date"></label><label>Printed expiry date<input type="date" name="expiry_date"></label></div>
    <label>Printed address<textarea name="address" maxlength="240" rows="2"></textarea></label>
    <div class="idstudio-editor-group"><h5>Emergency contact on ID</h5><label>Contact name<input name="emergency_name" maxlength="100" autocomplete="off"></label><div class="idstudio-edit-dates"><label>Relationship<input name="emergency_relationship" maxlength="50"></label><label>Phone<input type="tel" name="emergency_phone" maxlength="25"></label></div></div>
    <label>Reason for correction <textarea name="reason" maxlength="255" rows="2" placeholder="e.g. Student requested an updated emergency contact." required></textarea></label>
    <p class="idstudio-editor-preview-note"><i class="fas fa-eye"></i> Preview updates as you type. Save to keep your changes.</p><p id="idStudioEditError" class="idstudio-edit-error" role="alert"></p>
    <div class="idstudio-edit-actions"><button type="button" onclick="cancelIdStudioEdit()">Cancel</button><button type="submit" id="idStudioSaveButton">Save ID details</button></div>
   </form><button type="button" class="idstudio-reset" id="idStudioResetButton" onclick="showIdStudioReset()" hidden>Use original student details</button><div class="idstudio-reset-confirm" id="idStudioResetConfirm" hidden><p>Remove the saved ID corrections and use the current student record?</p><button type="button" onclick="resetIdStudioDetails()">Reset ID details</button><button type="button" onclick="document.getElementById('idStudioResetConfirm').hidden=true">Keep changes</button></div></section>
   <div class="idstudio-details-status" id="idStudioDetailsStatus" role="status"></div>
   <div class="idstudio-output"><i class="fas fa-print" aria-hidden="true"></i><div><strong>Print-ready ID</strong><span>Both sides · Standard card size</span></div></div>
   <div class="idstudio-actions"><button type="button" class="idstudio-print" onclick="printIdCard()"><i class="fas fa-print" aria-hidden="true"></i> Print ID</button><button type="button" class="idstudio-done" onclick="closeIdStudio()">Done</button></div>
   </aside>
  </div>
 </div>
</div>
<script src="../js/id-studio.js?v=20261111-edit2"></script>

<!-- â”€â”€ Toast container â”€â”€ -->
<div id="printArea"></div>

<div class="toast-container" id="toastContainer"></div>

<!-- â”€â”€ Delete Confirmation Modal â”€â”€ -->
<div class="logout-modal-overlay" id="deleteModal">
    <div class="logout-modal">
        <div class="logout-modal-icon" style="background: #fee2e2;">
            <i class="fas fa-trash-alt" style="color: #dc2626;"></i>
        </div>
        <h3 class="logout-modal-title">Delete RFID Card</h3>
        <p class="logout-modal-message" id="deleteMessage">Are you sure you want to delete this card? This action cannot be undone.</p>
        <div class="logout-modal-actions">
            <button class="logout-btn-cancel" id="deleteCancel" type="button">Cancel</button>
            <button class="logout-btn-confirm" id="deleteConfirm" type="button" style="background: #dc2626;">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- QR Preview Modal -->
<div id="qrModalOverlay">
    <div class="qr-modal-box">
        <img src="" alt="QR Code">
        <div class="qr-modal-name"></div>
        <div class="qr-modal-sub"></div>
        <button class="qr-modal-close" onclick="closeQrModal()"><i class="fas fa-times"></i> Close</button>
    </div>
</div>

<!-- Register Cards Modal -->
<link rel="stylesheet" href="../assets/id-card/register-cards.css?v=20261111-1">
<div class="logout-modal-overlay" id="registerModal" role="dialog" aria-modal="true" aria-labelledby="bulkTitle"><div class="logout-modal rfid-modal bulk-dialog">
<header class="bulk-header"><span class="bulk-header-icon"><i class="fas fa-layer-group" aria-hidden="true"></i></span><div><h3 id="bulkTitle">Register RFID cards</h3><p id="registerSubheader">Add new cards to your available inventory.</p></div><button type="button" class="bulk-close" onclick="closeRegisterModal()" aria-label="Close card registration"><i class="fas fa-xmark"></i></button></header>
<ol class="bulk-steps" aria-label="Registration progress"><li data-bulk-step="1" class="active" aria-current="step"><span>1</span> Enter UIDs</li><li data-bulk-step="2"><span>2</span> Review batch</li><li data-bulk-step="3"><span>3</span> Complete</li></ol>
<div class="bulk-body"><div class="bulk-main">
<section id="bulkEntryPanel" class="bulk-panel"><div class="bulk-section-heading"><h4>Card UIDs</h4><span>Maximum 1,000 cards</span></div><p class="bulk-description">Paste one UID per line, or separate with commas.</p>
<div id="bulkIssueNav" class="bulk-issue-nav" hidden><strong id="bulkIssueCount"></strong><div><span id="bulkIssuePosition"></span><button type="button" onclick="navigateBulkIssue(-1)" aria-label="Previous issue"><i class="fas fa-chevron-up"></i></button><button type="button" onclick="navigateBulkIssue(1)" aria-label="Next issue"><i class="fas fa-chevron-down"></i></button></div></div>
<div class="bulk-editor"><div id="bulkUidRows" class="bulk-uid-rows" role="group" aria-label="Card UID entries"></div><div class="bulk-editor-footer"><span id="uidCountHint" role="status">0 UIDs entered</span><button type="button" onclick="clearBulkRows()"><i class="fas fa-trash-can"></i> Clear list</button></div></div><p class="bulk-entry-help">10 digits per row. Moves to the next row automatically. Review checks current inventory.</p>
<label for="registerNotes" class="bulk-notes-label">Batch notes <span>(optional)</span></label><input id="registerNotes" type="text" maxlength="1000" placeholder="e.g. New cards received from supplier" class="bulk-notes">
</section>
<section id="bulkReviewPanel" class="bulk-panel" hidden><div class="bulk-review-heading"><h4>Review your batch</h4><p id="bulkReviewHelp">Only new, valid cards will be registered.</p></div><div id="bulkReviewMetrics" class="bulk-metrics"></div><div id="bulkSkipNotice" class="bulk-skip-notice" hidden></div><div class="bulk-table-scroll"><table class="bulk-table"><thead><tr><th>#</th><th>Card UID</th><th>Check result</th><th>Details</th></tr></thead><tbody id="bulkReviewRows"></tbody></table></div><p class="bulk-batch-note" id="bulkReviewNote"></p></section>
<section id="bulkCompletePanel" class="bulk-panel" hidden><div class="bulk-result-heading"><span><i class="fas fa-check"></i></span><div><h4 id="bulkResultTitle">Cards registered</h4><p id="bulkResultDescription"></p></div></div><div class="bulk-table-scroll"><table class="bulk-table"><thead><tr><th>#</th><th>Card UID</th><th>Status</th><th>Details</th></tr></thead><tbody id="bulkResultRows"></tbody></table></div><p class="bulk-batch-note" id="bulkResultNote"></p></section>
</div><aside class="bulk-summary"><div class="bulk-stack" aria-hidden="true"><div class="bulk-stack-layer layer-2"><div class="assign-rfid-art" aria-hidden="true"><div class="assign-art-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><strong>Bestlink College</strong><small>CAMPUS ACCESS</small></div></div><svg class="assign-coil" viewBox="0 0 120 120" fill="none" aria-hidden="true"><g stroke="#18e7ff" stroke-width="1.6" stroke-linecap="round"><path d="M49 109a50 50 0 1 1 22 0M50 101a42 42 0 1 1 20 0M51 93a34 34 0 1 1 18 0M52 85a26 26 0 1 1 16 0"/></g><g fill="#83f0ff"><circle cx="49" cy="109" r="2.2"/><circle cx="71" cy="109" r="2.2"/><circle cx="50" cy="101" r="2"/><circle cx="70" cy="101" r="2"/><circle cx="51" cy="93" r="1.8"/><circle cx="69" cy="93" r="1.8"/><circle cx="52" cy="85" r="1.7"/><circle cx="68" cy="85" r="1.7"/></g></svg><div class="assign-art-bottom"><div><small>CARD UID</small><strong class="bulk-stack-uid">— — — — —</strong></div><span>RFID</span></div></div></div><div class="bulk-stack-layer layer-1"><div class="assign-rfid-art" aria-hidden="true"><div class="assign-art-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><strong>Bestlink College</strong><small>CAMPUS ACCESS</small></div></div><svg class="assign-coil" viewBox="0 0 120 120" fill="none" aria-hidden="true"><g stroke="#18e7ff" stroke-width="1.6" stroke-linecap="round"><path d="M49 109a50 50 0 1 1 22 0M50 101a42 42 0 1 1 20 0M51 93a34 34 0 1 1 18 0M52 85a26 26 0 1 1 16 0"/></g><g fill="#83f0ff"><circle cx="49" cy="109" r="2.2"/><circle cx="71" cy="109" r="2.2"/><circle cx="50" cy="101" r="2"/><circle cx="70" cy="101" r="2"/><circle cx="51" cy="93" r="1.8"/><circle cx="69" cy="93" r="1.8"/><circle cx="52" cy="85" r="1.7"/><circle cx="68" cy="85" r="1.7"/></g></svg><div class="assign-art-bottom"><div><small>CARD UID</small><strong class="bulk-stack-uid">— — — — —</strong></div><span>RFID</span></div></div></div><div class="bulk-stack-layer layer-0"><div class="assign-rfid-art" aria-hidden="true"><div class="assign-art-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><strong>Bestlink College</strong><small>CAMPUS ACCESS</small></div></div><svg class="assign-coil" viewBox="0 0 120 120" fill="none" aria-hidden="true"><g stroke="#18e7ff" stroke-width="1.6" stroke-linecap="round"><path d="M49 109a50 50 0 1 1 22 0M50 101a42 42 0 1 1 20 0M51 93a34 34 0 1 1 18 0M52 85a26 26 0 1 1 16 0"/></g><g fill="#83f0ff"><circle cx="49" cy="109" r="2.2"/><circle cx="71" cy="109" r="2.2"/><circle cx="50" cy="101" r="2"/><circle cx="70" cy="101" r="2"/><circle cx="51" cy="93" r="1.8"/><circle cx="69" cy="93" r="1.8"/><circle cx="52" cy="85" r="1.7"/><circle cx="68" cy="85" r="1.7"/></g></svg><div class="assign-art-bottom"><div><small>CARD UID</small><strong class="bulk-stack-uid">— — — — —</strong></div><span>RFID</span></div></div></div></div><h4 id="bulkSummaryTitle">Batch overview</h4><dl class="bulk-summary-rows" id="bulkSummaryRows"></dl><div class="bulk-info" id="bulkInfo"><i class="fas fa-shield-halved" aria-hidden="true"></i><div><strong id="bulkInfoTitle">Review before registering</strong><p id="bulkInfoText">The next step checks invalid UIDs, duplicates, and cards already in inventory.</p></div></div></aside></div>
<p id="bulkError" class="bulk-error" role="alert" hidden></p>
<footer class="bulk-footer"><span id="bulkFooterNote"><i class="fas fa-lock"></i> Cards remain unassigned until you assign them.</span><div id="bulkEntryActions"><button type="button" class="bulk-secondary" onclick="closeRegisterModal()">Cancel</button><button type="button" class="bulk-primary" id="registerPreviewBtn" onclick="previewBulkRegister()" disabled>Review batch <i class="fas fa-arrow-right"></i></button></div><div id="bulkReviewActions" hidden><button type="button" class="bulk-secondary" onclick="regGoStep(1)">Back to edit</button><button type="button" class="bulk-primary" id="registerProceedBtn" onclick="submitBulkRegister()">Register cards <i class="fas fa-arrow-right"></i></button></div><div id="bulkCompleteActions" hidden><button type="button" class="bulk-secondary" onclick="startAnotherBulkBatch()">Register another batch</button><button type="button" class="bulk-primary" onclick="closeRegisterModal()">Done <i class="fas fa-check"></i></button></div></footer>
</div></div>
<!-- The standalone Archive modal and its per-row Archive button are gone.
     The Edit modal is now the single way to retire a card, and it records a
     retirement reason in archive_reason. The old modal wrote free-text values
     like "Graduated" and "Card Lost" into that column while the Edit modal
     writes tokens like "graduated" and "lost_card" - two vocabularies in one
     column. Consolidating on the Edit modal leaves a single vocabulary. -->



<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
<!-- RFID Distribution Assistant Modal -->
<div class="logout-modal-overlay" id="rfidDistributionModal">
    <div class="logout-modal rfid-modal" style="max-width:980px;width:min(980px,94vw)">
        <div class="rfid-modal-header">
            <div class="header-icon assign"><i class="fas fa-wand-magic-sparkles"></i></div>
            <div><h3>Prepare RFID distribution</h3><p>Review proposed card-to-student assignments before applying.</p></div>
        </div>
        <div class="rfid-modal-body-wrapper" id="rfidDistributionBody">
            <div class="ai-insight-card" style="text-align:center;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading available cards and students…</div>
        </div>
        <div class="rfid-modal-actions">
            <button type="button" class="btn btn-light" onclick="closeRfidDistribution()">Cancel</button>
            <button type="button" class="btn btn-primary" id="applyRfidDistributionBtn" onclick="applyRfidDistribution()" disabled><i class="fas fa-check"></i> Apply selected</button>
        </div>
    </div>
</div>

<script src="<?= $APP_ROOT ?>js/rfid-distribution.js?v=<?= is_file(__DIR__ . '/../js/rfid-distribution.js') ? filemtime(__DIR__ . '/../js/rfid-distribution.js') : time() ?>"></script>
<script>
// =================================================================
// RFID CARDS &mdash; INLINE JS
// =================================================================
let deleteTarget = null;

// â”€â”€ Delete modal â”€â”€
function confirmDelete(id, uid) {
    deleteTarget = id;
    document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete RFID card ' + uid + '? This action cannot be undone.';
    document.getElementById('deleteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('deleteCancel')?.addEventListener('click', () => {
    document.getElementById('deleteModal').classList.remove('active');
    document.body.style.overflow = '';
    deleteTarget = null;
});
document.getElementById('deleteConfirm')?.addEventListener('click', () => {
    if (!deleteTarget) return;
    fetch('../api/rfid.php?id=' + deleteTarget, { method: 'DELETE' }).then(() => window.location.reload());
});
document.getElementById('deleteModal')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        e.currentTarget.classList.remove('active');
        document.body.style.overflow = '';
        deleteTarget = null;
    }
});
['editModal','viewModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) {
            el.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// â”€â”€ Live search + status filter â”€â”€
const rfidSearchInput = document.getElementById('rfidSearch');
const rfidTableBody = document.getElementById('rfidTableBody');
const searchClearBtn = document.getElementById('searchClear');
const showingCount = document.getElementById('showingCount');
const totalCountEl = document.getElementById('totalCount');
const statusFilter = document.getElementById('statusFilter');
const resetFilterBtn = document.getElementById('resetFilterBtn');
const viewSwitch = document.querySelector('.rfid-view-switch');
const countNoun = document.getElementById('totalCountNoun');

let rfidView = 'active';

// Statuses that live in the retired group. Must match $RETIRED_STATUSES in PHP.
const RFID_RETIRED_STATUSES = ['lost', 'archived', 'inactive'];

const RFID_VIEW_COPY = {
    active:  { noun: 'live cards' },
    retired: { noun: 'retired cards' }
};

// Cards carry data-retired="1" when they are lost, archived or inactive. Both
// groups share one table body so there is a single row template and a single
// search pass; the view decides which group is visible. Retiring through the
// Edit modal sets the status, so after the reload the card has moved groups on
// its own - there is no separate archive step to forget.
function applyRfidSearch() {
    if (!rfidTableBody) return;
    const query = (rfidSearchInput?.value || '').trim().toLowerCase();
    const filterStatus = statusFilter?.value || '';
    const wantRetired = rfidView === 'retired';
    const rows = rfidTableBody.querySelectorAll('tr[data-card]');
    let visible = 0;
    let inView = 0;
    rows.forEach(row => {
        const data = (row.getAttribute('data-card') || '').toLowerCase();
        const rowRetired = row.getAttribute('data-retired') === '1';
        if (rowRetired === wantRetired) inView++;
        let match = (rowRetired === wantRetired);
        if (match && query) match = data.indexOf(query) !== -1;
        if (match && filterStatus) match = data.indexOf('"status":"' + filterStatus + '"') !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    // The empty-state rows belong to a view, so show only the relevant one.
    rfidTableBody.querySelectorAll('tr[data-empty]').forEach(r => {
        const kind = r.getAttribute('data-empty');
        const applies = kind === 'both' || (wantRetired ? kind === 'retired' : kind === 'active');
        r.style.display = applies ? '' : 'none';
    });

    if (showingCount) showingCount.textContent = visible;
    if (totalCountEl) totalCountEl.textContent = inView;
}

function setRfidView(view) {
    rfidView = view;
    // The segments are the only place the state is shown. The heading stays
    // "Cards" and the badge stays the total, so nothing else has to relabel.
    if (viewSwitch) {
        viewSwitch.querySelectorAll('button[data-view]').forEach(btn => {
            const on = btn.getAttribute('data-view') === view;
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
            btn.tabIndex = on ? 0 : -1;
        });
    }
    if (countNoun) countNoun.textContent = RFID_VIEW_COPY[view].noun;
    applyRfidSearch();
}

if (viewSwitch) {
    viewSwitch.addEventListener('click', e => {
        const btn = e.target.closest('button[data-view]');
        if (btn) setRfidView(btn.getAttribute('data-view'));
    });
    // Left/right arrows move between segments, as a tablist should.
    viewSwitch.addEventListener('keydown', e => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        const next = rfidView === 'active' ? 'retired' : 'active';
        setRfidView(next);
        const btn = viewSwitch.querySelector('button[data-view="' + next + '"]');
        if (btn) btn.focus();
    });
}
if (rfidSearchInput) rfidSearchInput.addEventListener('input', applyRfidSearch);
if (statusFilter) statusFilter.addEventListener('change', () => {
    // Picking a retired status while looking at live cards would show an empty
    // table, so follow the filter into the view that actually holds them.
    const s = statusFilter.value;
    if (RFID_RETIRED_STATUSES.includes(s) && rfidView === 'active') setRfidView('retired');
    else applyRfidSearch();
});
if (resetFilterBtn) resetFilterBtn.addEventListener('click', () => {
    if (rfidSearchInput) rfidSearchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    setRfidView('active');
    rfidSearchInput && rfidSearchInput.focus();
});
if (searchClearBtn) {
    searchClearBtn.addEventListener('click', () => {
        if (rfidSearchInput) rfidSearchInput.value = '';
        applyRfidSearch();
    });
}
// Apply once on load so the counts and the view agree with the server-rendered rows.
setRfidView('active');

// Assignment workspace handlers live in js/assign-card.js.
function closeEditModal(){document.getElementById('editModal').classList.remove('active');document.body.style.overflow='';}
function closeViewDrawer(){closeViewModal();}

const EDIT_OUTCOMES = {
    keep:     { status: 'active',   reason: 'active',      label: 'Still in use',      verb: 'Save Changes',   effect: 'keep' },
    lost:     { status: 'lost',     reason: 'lost_card',   label: 'Reported lost',     verb: 'Mark Card Lost', effect: 'warning' },
    transfer: { status: 'archived', reason: 'transferred', label: 'Transferred out',   verb: 'Archive Card',   effect: 'retiring' },
    dropout:  { status: 'archived', reason: 'dropped_out', label: 'Dropped out',       verb: 'Archive Card',   effect: 'retiring' },
    graduate: { status: 'archived', reason: 'graduated',   label: 'Graduated',         verb: 'Archive Card',   effect: 'retiring' },
    damaged:  { status: 'expired',  reason: 'damaged',     label: 'Damaged / expired', verb: 'Mark Expired',   effect: 'warning' },
    other:    { status: 'inactive', reason: 'other',       label: 'Something else',    verb: 'Archive Card',   effect: 'retiring' }
};

// Reason -> how it reads. Covers every value the old select could write, so
// rows saved before this change still show a sensible label.
const EDIT_REASON_LABELS = {
    'active': 'Active', 'graduated': 'Graduated', 'lost_card': 'Reported lost',
    'dropped_out': 'Dropped out', 'transferred': 'Transferred to another school',
    'inactive': 'Inactive', 'other': 'Other reason', 'damaged': 'Damaged'
};

const EDIT_STATUS_LABEL = {
    active: 'Active', lost: 'Lost', expired: 'Expired',
    inactive: 'Inactive', archived: 'Archived', available: 'Available'
};

// Reverse lookup: given what is stored, which tile represents it. Falls back to
// matching on status alone so rows with no reason still preselect.
function matchEditOutcome(status, reason) {
    if (reason) {
        for (const key of Object.keys(EDIT_OUTCOMES)) {
            if (EDIT_OUTCOMES[key].reason === reason) return key;
        }
    }
    if (status === 'lost') return 'lost';
    if (status === 'expired') return 'damaged';
    if (status === 'active') return 'keep';
    // archived/inactive with an unrecognised reason: still retired, so preselect
    // the generic retirement rather than a specific claim we cannot support.
    if (status === 'archived' || status === 'inactive') return 'other';
    return 'keep';
}

let editHolderName = 'the assigned student';
let editHolderAssigned = true;

function describeEditOutcome(key) {
    const o = EDIT_OUTCOMES[key];
    if (!o) return '';
    const head = '<b>' + o.label + '</b> ';

    if (o.effect === 'keep') {
        return head + (editHolderAssigned
            ? 'keeps this card active and assigned to ' + editHolderName + '.'
            : 'keeps this card active and available to assign.');
    }
    // The PUT does not cascade to student_ids, so an assigned student's ID
    // record keeps pointing at this card. Say so rather than implying a clean
    // unlink. An unassigned card has no such record, so do not claim one.
    const tail = editHolderAssigned
        ? ' The student’s ID record still points at this card.'
        : ' The card is unassigned, so there is no student record to update.';
    return head + 'marks this card <b>' + (EDIT_STATUS_LABEL[o.status] || o.status) +
           '</b> and records the reason <b>' + (EDIT_REASON_LABELS[o.reason] || o.reason) +
           '</b>.' + tail;
}

function applyEditOutcome(key) {
    const o = EDIT_OUTCOMES[key];
    if (!o) return;

    setEditStatusPill(o.status);

    const effect = document.getElementById('editEffect');
    // o.effect is 'retiring'/'warning' to match the .rc-effect.is-* CSS classes.
    effect.className = 'rc-effect' + (o.effect === 'keep' ? '' : ' is-' + o.effect);
    const icon = o.effect === 'keep'
        ? 'fas fa-check-circle'
        : (o.effect === 'warning' ? 'fas fa-exclamation-triangle' : 'fas fa-archive');
    effect.innerHTML = '<i class="' + icon + '"></i><span>' + describeEditOutcome(key) + '</span>';

    document.getElementById('editSubmitLabel').textContent = o.verb;
    const btn = document.getElementById('editSubmitBtn');
    if (btn) btn.disabled = false;

    // An expiry only means something while the card is in use.
    document.getElementById('editExpiryWrap').hidden = (o.status !== 'active');

    // "Something else" is the one choice we cannot describe on its own.
    document.getElementById('editNotesRequired').hidden = (key !== 'other');
}

// The radios sit inside the form, so one delegated listener covers all of them.
document.getElementById('editCardForm')?.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'editOutcome') {
        applyEditOutcome(e.target.value);
    }
});

async function openEditModal(id) {
    const m = document.getElementById('editModal');
    if (!m) return;
    try {
        const res = await fetch('../api/rfid.php?id=' + id);
        const json = await res.json();
        if (!json.success || !json.data) {
            showToast(json.message || 'Card not found.', 'error');
            return;
        }
        const c = json.data;
        document.getElementById('editCardId').value = c.id;
        document.getElementById('editUid').textContent = c.card_uid;
        document.getElementById('editStudentName').textContent = c.student_name || 'Unassigned';
        document.getElementById('editStudentNumber').textContent = c.student_number || '';
        editHolderAssigned = !!c.student_name;
        editHolderName = c.student_name
            ? (c.student_name + (c.student_number ? ' (' + c.student_number + ')' : ''))
            : 'nobody';

        setEditStatusPill(c.status);

        // archive_reason is what the API stores and returns. The old code read
        // c.status_reason, which never exists, so a saved reason never appeared.
        const prevReason = c.archive_reason || '';
        const prev = document.getElementById('editPrevious');
        if (prevReason) {
            document.getElementById('editPrevReason').textContent = EDIT_REASON_LABELS[prevReason] || prevReason;
            const changed = c.updated_at || c.updated_date;
            document.getElementById('editPrevMeta').textContent = changed
                ? ' — last changed ' + new Date(changed).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' })
                : '';
            prev.hidden = false;
        } else {
            prev.hidden = true;
        }

        document.getElementById('editExpiryDate').value = c.expiry_date || '';
        document.getElementById('editNotes').value = c.notes || '';
        const updated = c.updated_at || c.updated_date;
        document.getElementById('editUpdatedAt').textContent = updated ? new Date(updated).toLocaleString(undefined, { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : 'Never';

        // Preselect what is already true, then re-apply so the consequence line,
        // the status pill, the conditional fields and the Save verb are all
        // correct the moment the modal opens.
        const key = matchEditOutcome(c.status, prevReason);
        const radio = document.querySelector('input[name="editOutcome"][value="' + key + '"]');
        if (radio) radio.checked = true;
        applyEditOutcome(key);

        m.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        showToast('Failed to load card details.', 'error');
    }
}

// Keeps the record panel's status pill in step with the select, so the outcome
// of a change is visible before saving. The tone is a class so the CSS owns the
// colours; setting them from JS left the pill painting its initial green.
const EDIT_STATUS_TONE = {
    active:   '',
    expired:  'tone-expired',
    lost:     'tone-lost',
    inactive: 'tone-inactive',
    archived: 'tone-archived'
};
function setEditStatusPill(value) {
    const pill = document.getElementById('editStatusPill');
    if (!pill) return;
    const v = value || 'active';
    pill.textContent = v.charAt(0).toUpperCase() + v.slice(1);
    pill.setAttribute('data-status', v);
    pill.className = 'rc-record-status ' + (EDIT_STATUS_TONE[v] || EDIT_STATUS_TONE.active);
}
document.getElementById('editCardForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const id = document.getElementById('editCardId').value;
    if (!id) return;

    const chosen = this.querySelector('input[name="editOutcome"]:checked');
    if (!chosen) {
        showToast('Choose what happened to this card.', 'error');
        return;
    }
    const outcome = EDIT_OUTCOMES[chosen.value];
    if (!outcome) return;

    // "Something else" is only meaningful with a note explaining it.
    const notes = document.getElementById('editNotes').value.trim();
    if (chosen.value === 'other' && !notes) {
        showToast('Add a note explaining the reason.', 'error');
        document.getElementById('editNotes').focus();
        return;
    }

    const submitBtn = document.getElementById('editSubmitBtn');
    const btnLabel = document.getElementById('editSubmitLabel');
    const resting = btnLabel.textContent;
    submitBtn.disabled = true;
    btnLabel.textContent = 'Saving...';
    try {
        const res = await fetch('../api/rfid.php?id=' + id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                status: outcome.status,
                // archive_reason, per the API's allow-list. The old payload key
                // `status_reason` was filtered out server-side and never stored.
                archive_reason: outcome.reason,
                expiry_date: outcome.status === 'active' ? document.getElementById('editExpiryDate').value : '',
                notes: notes
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Saved.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(data.message || 'Update failed.', 'error');
            submitBtn.disabled = false;
            btnLabel.textContent = resting;
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
        submitBtn.disabled = false;
        btnLabel.textContent = resting;
    }
});

// â”€â”€ View modal â”€â”€
async function openViewDrawer(id) {
    const modal = document.getElementById('viewModal');
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    const setText = (sel, v) => {
        const el = modal.querySelector(sel);
        if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v;
    };

    setText('#viewUid', 'â€¦');
    setText('#viewStudent', 'Loadingâ€¦');

    try {
        const res = await fetch('../api/rfid.php?id=' + id);
        const json = await res.json();
        if (!json.success || !json.data) { showToast(json.message || 'Card not found.', 'error'); closeViewModal(); return; }
        const c = json.data;
        setText('#viewUid', c.card_uid);
        setText('#viewStudent', c.student_name || 'Unassigned');
        setText('#viewStudentNumber', c.student_number);
        setText('#viewIdNumber', c.student_id_number);
        setText('#viewCourse', c.course);
        setText('#viewYearLevel', c.year_level ? c.year_level + ' Year' : null);
        setText('#viewStatus', c.status ? c.status[0].toUpperCase() + c.status.slice(1) : null);
        const badge = document.getElementById('viewStatusBadge');
        if (badge) badge.className = 'rfid-view-status-badge ' + (c.status || '');

        // Status reason (convert underscores to spaces and capitalize)
        let reasonText = '&mdash;';
        if (c.status_reason) {
            reasonText = c.status_reason
                .split('_')
                .map(word => word[0].toUpperCase() + word.slice(1))
                .join(' ');
        }
        setText('#viewStatusReason', reasonText);

        // Last updated date
        if (c.status_updated_at) {
            const updatedDate = new Date(c.status_updated_at);
            setText('#viewStatusUpdatedAt', updatedDate.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }));
        } else {
            setText('#viewStatusUpdatedAt', null);
        }

        setText('#viewIssued', c.issued_date ? new Date(c.issued_date).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' }) : null);
        setText('#viewExpiry', c.expiry_date ? new Date(c.expiry_date).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' }) : null);
        setText('#viewNotes', c.notes || 'No notes.');

        // Card history (other cards for same student)
        const histSection = document.getElementById('viewHistorySection');
        const histDiv = document.getElementById('viewHistory');
        if (histSection && histDiv && c.student_id) {
            try {
                const hres = await fetch('../api/rfid.php?student_id=' + c.student_id);
                const hjson = await hres.json();
                const all = (hjson.success ? hjson.data : []).filter(h => parseInt(h.id) !== parseInt(id));
                if (all.length) {
                    histDiv.innerHTML = all.map(h =>
                        '<div class="rfid-view-scan-row">' +
                            '<span class="status-badge ' + (h.status || '') + '">' + (h.status ? h.status[0].toUpperCase() + h.status.slice(1) : '&mdash;') + '</span>' +
                            '<span class="scan-meta">' + (h.card_uid || '&mdash;') + (h.issued_date ? ' &middot; Issued ' + new Date(h.issued_date).toLocaleDateString() : '') + '</span>' +
                        '</div>'
                    ).join('');
                    histSection.style.display = '';
                }
            } catch (e) {}
        }

        const list = document.getElementById('viewScans');
        if (list) {
            list.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:16px;">Loading scansâ€¦</p>';
            try {
                const sres = await fetch('../api/rfid-scan.php?limit=10');
                const sjson = await sres.json();
                const scans = (sjson.success ? sjson.data : []).filter(s => s.card_uid === c.card_uid).slice(0, 5);
                if (!scans.length) {
                    list.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">No scans recorded yet.</p>';
                } else {
                    list.innerHTML = scans.map(s => {
                        const when = new Date(s.scanned_at).toLocaleString(undefined, { month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
                        const statusText = s.status ? s.status[0].toUpperCase() + s.status.slice(1) : '&mdash;';
                        return '<div class="rfid-view-scan-row">' +
                                    '<span class="status-badge ' + (s.status || '') + '">' + statusText + '</span>' +
                                    '<span class="scan-meta">' + when + ' &middot; ' + (s.location || 'Main Gate') + '</span>' +
                                '</div>';
                    }).join('');
                }
            } catch (e) {
                list.innerHTML = '<p style="color:#dc2626;font-size:13px;text-align:center;padding:16px;">Failed to load scans.</p>';
            }
        }
    } catch (err) {
        showToast('Failed to load card details.', 'error');
        closeViewModal();
    }
}

function closeViewModal() {
    const modal = document.getElementById('viewModal');
    if (modal) modal.classList.remove('active');
    document.body.style.overflow = '';
}

// â”€â”€ Universal Esc + close-on-overlay â”€â”€
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('assignModal').classList.contains('active')) closeAssignModal();
    else if (document.getElementById('editModal').classList.contains('active')) closeEditModal();
    else if (document.getElementById('viewModal').classList.contains('active')) closeViewModal();
    else if (document.getElementById('idCardModal').classList.contains('active')) { document.getElementById('idCardModal').classList.remove('active'); document.body.style.overflow = ''; }
    else if (document.getElementById('deleteModal').classList.contains('active')) {
        document.getElementById('deleteModal').classList.remove('active');
        document.body.style.overflow = '';
        deleteTarget = null;
    }
});
document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => {
        const t = el.getAttribute('data-close-modal');
        if (t === 'assign') closeAssignModal();
        else if (t === 'edit') closeEditModal();
        else if (t === 'view') closeViewModal();
    });
});

// â”€â”€ ID Card View (3D flip) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const CARD_EXTRAS = <?= json_encode($cardExtras ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const ID_DESIGNS = ['classic', 'light', 'dark', 'arctic', 'cobalt', 'pearl', 'silver'];
let currentIdDesign = 'light';
try { const savedDesign = localStorage.getItem('bcp-id-design'); currentIdDesign = ID_DESIGNS.includes(savedDesign) ? savedDesign : 'light'; } catch (_) {}
let idBuildAnimations = [];
let idBuildOverlay = null;
function stopIdConstruction() {
    idBuildAnimations.forEach(animation => animation.cancel());
    idBuildAnimations = [];
    if (idBuildOverlay) { idBuildOverlay.remove(); idBuildOverlay = null; }
}
function constructIdDesign(preview) {
    const overlay = document.createElement('div');
    overlay.className = 'id-build-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = '<div class="id-build-grid"></div><svg class="id-build-frame" viewBox="0 0 340 539"><rect x="2" y="2" width="336" height="535" rx="18" pathLength="1"/></svg><div class="id-build-outline"></div><div class="id-build-scan"></div><div class="id-build-cross-scan"></div><div class="id-build-shimmer"></div><div class="id-build-readout"><span></span><b>ASSEMBLING CREDENTIAL</b><i></i></div>';
    for (let i = 0; i < 16; i++) {
        const fragment = document.createElement('span');
        fragment.className = 'id-build-fragment';
        fragment.style.left = (15 + (i * 71) % 310) + 'px';
        fragment.style.top = (35 + (i * 113) % 465) + 'px';
        overlay.appendChild(fragment);
    }
    preview.querySelector('.idcard-scale').appendChild(overlay);
    idBuildOverlay = overlay;
    const play = (element, frames, options) => {
        const animation = element.animate(frames, { fill: 'both', ...options });
        idBuildAnimations.push(animation);
        return animation;
    };
    play(overlay, [{ opacity: 0 }, { opacity: 1, offset: .08 }, { opacity: 1, offset: .94 }, { opacity: 0 }], { duration: 3100 });
    const scanDuration = 2450;
    play(overlay.querySelector('.id-build-scan'), [
        { transform: 'translateY(0)', opacity: 1 },
        { transform: 'translateY(539px)', opacity: 1 }
    ], { duration: scanDuration, easing: 'linear' });
    // Build individual components around their real position on the card,
    // rather than their DOM order. Each piece locks in as the scan passes.
    const buildParts = [];
    preview.querySelectorAll('.idcard, .idcard-back').forEach(face => {
        const faceRect = face.getBoundingClientRect();
        const scale = faceRect.height / 539 || 1;
        [...face.children].forEach(part => {
            if (!part.getClientRects().length) return;
            const bounds = [part, ...part.querySelectorAll('*')]
                .map(node => node.getBoundingClientRect())
                .filter(rect => rect.width > 0 && rect.height > 0);
            if (!bounds.length) return;
            const top = Math.max(0, (Math.min(...bounds.map(rect => rect.top)) - faceRect.top) / scale);
            const bottom = Math.min(539, (Math.max(...bounds.map(rect => rect.bottom)) - faceRect.top) / scale);
            const finish = Math.min(scanDuration, Math.max(360, bottom / 539 * scanDuration));
            const duration = Math.min(800, Math.max(420, (bottom - top) / 539 * scanDuration));
            buildParts.push({ part, delay: Math.max(0, finish - duration), duration });
        });
    });
    // Never clip component bounds: the QR caption and ribbon deliberately
    // extend beyond their parent boxes.
    // Measure everything before starting animations so early parts cannot
    // distort the position calculations of later components.
    buildParts.forEach(({ part, delay, duration }, index) => {
        const direction = index % 2 ? 1 : -1;
        play(part, [
            { opacity: 0, transform: `translate(${direction * 12}px, 8px) scale(.97)` },
            { opacity: .8, transform: `translate(${direction * 2}px, 1px) scale(.995)`, offset: .7 },
            { opacity: 1, transform: 'translateY(0)' }
        ], { delay, duration, easing: 'cubic-bezier(.2,.7,.3,1)' });
    });
    play(overlay.querySelector('.id-build-frame rect'), [
        { strokeDashoffset: '1', opacity: .2 },
        { strokeDashoffset: '0', opacity: 1 }
    ], { duration: 950, easing: 'cubic-bezier(.2,.65,.3,1)' });
    play(overlay.querySelector('.id-build-grid'), [
        { opacity: 0, transform: 'scale(1.08)' },
        { opacity: .9, transform: 'scale(1)', offset: .25 },
        { opacity: .65, offset: .8 }, { opacity: 0 }
    ], { duration: 2900 });
    play(overlay.querySelector('.id-build-cross-scan'), [
        { transform: 'translateX(0)', opacity: 0 },
        { opacity: .7, offset: .1 },
        { transform: 'translateX(340px)', opacity: 0 }
    ], { delay: 200, duration: 1800, easing: 'linear' });
    overlay.querySelectorAll('.id-build-fragment').forEach((fragment, i) => {
        play(fragment, [
            { opacity: 0, transform: `translate(${i % 2 ? 32 : -32}px, -18px) rotate(45deg) scale(.3)` },
            { opacity: .8, offset: .35 },
            { opacity: 0, transform: 'translate(0, 0) rotate(0deg) scale(1)' }
        ], { delay: 100 + (i * 137) % 1600, duration: 650, easing: 'ease-out' });
    });
    play(overlay.querySelector('.id-build-readout i'), [
        { transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }
    ], { duration: scanDuration, easing: 'linear' });
    play(overlay.querySelector('.id-build-readout b'), [
        { opacity: 1 }, { opacity: 1, offset: .78 }, { opacity: 0 }
    ], { duration: 2600 });
    const ready = document.createElement('strong');
    ready.textContent = 'DESIGN READY';
    overlay.querySelector('.id-build-readout').appendChild(ready);
    play(ready, [{ opacity: 0 }, { opacity: 1 }], { delay: 2550, duration: 150 });
    play(overlay.querySelector('.id-build-shimmer'), [
        { transform: 'translateX(-130%) skewX(-18deg)', opacity: 0 },
        { opacity: .7, offset: .2 },
        { transform: 'translateX(450%) skewX(-18deg)', opacity: 0 }
    ], { delay: 2450, duration: 600, easing: 'ease-out' });
    const completion = play(overlay.querySelector('.id-build-outline'), [
        { opacity: 0 }, { opacity: 0, offset: .79 },
        { opacity: .85, offset: .86 }, { opacity: 0 }
    ], { duration: 3100 });
    completion.finished.then(() => {
        if (idBuildOverlay === overlay) stopIdConstruction();
    }).catch(() => {});
}
function applyIdDesign(design, animate = false) {
    const previousDesign = currentIdDesign;
    currentIdDesign = ID_DESIGNS.includes(design) ? design : 'light';
    document.querySelectorAll('#cardFlip .idcard, #cardFlip .idcard-back').forEach(face => {
        face.classList.toggle('id-tech', ['light', 'dark'].includes(currentIdDesign));
        face.classList.toggle('id-collection', ['arctic', 'cobalt', 'pearl', 'silver'].includes(currentIdDesign));
        face.dataset.design = currentIdDesign;
    });
    document.getElementById('idDesignSelect').value = currentIdDesign;
    try { localStorage.setItem('bcp-id-design', currentIdDesign); } catch (_) {}
    if (typeof refreshIdStudioDesign === 'function') refreshIdStudioDesign(currentIdDesign);
    if (typeof fitIdStudioPrintedText === 'function') fitIdStudioPrintedText();
    stopIdConstruction();
    const preview = document.getElementById('cardFlip');
    if (animate && previousDesign !== currentIdDesign && preview && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        constructIdDesign(preview);
    }}
document.getElementById('idDesignSelect').addEventListener('change', event => applyIdDesign(event.target.value, true));
applyIdDesign(currentIdDesign);
let idCardData = null;
let idPhotoRequest = 0;

function normalizePhotoPath(p) {
    if (!p) return '';
    p = p.trim();
    if (/^(https?:|data:|blob:)/i.test(p)) return p;
    if (p.startsWith('../') || p.startsWith('/')) return p;
    return '../' + p.replace(/^\.?\//, '');
}
function idInitialsOf(name) {
    return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}
function showIdInitialsFallback() {
    const img = document.getElementById('cardPhoto');
    const ini = document.getElementById('cardInitials');
    if (img) img.style.display = 'none';
    if (ini) { ini.textContent = idInitialsOf(idCardData ? idCardData.name : ''); ini.style.display = 'flex'; }
}

function generateQrDataUrl(studentId) {
    if (typeof qrcode === 'undefined') return '';
    try {
        const qr = qrcode(0, 'M');
        qr.addData('https://registrar.bcpsms2.com/verify-student.php?student_id=' + encodeURIComponent(studentId || ''));
        qr.make();
        return qr.createDataURL(8, 8);
    } catch (e) { return ''; }
}

// A stored QR file can be missing (deleted, or an older deployment wrote it
// somewhere else). Rather than show a broken image, regenerate the QR in the
// browser from the student id and use that instead.
function generateQrThumbFallback(img) {
    var tr = img.closest('tr[data-student-id]');
    var sid = tr ? tr.getAttribute('data-student-id') : null;
    var url = (sid && sid !== '0') ? generateQrDataUrl(sid) : '';
    if (url) { img.src = url; }
    else { img.style.display = 'none'; }
}

// Auto-populate QR thumbnails for rows where server QR is missing
document.querySelectorAll('img.qr-auto[data-qr-student-id]').forEach(function(img) {
    var sid = img.getAttribute('data-qr-student-id');
    var url = generateQrDataUrl(sid);
    if (url) {
        img.src = url;
        img.setAttribute('data-name', img.getAttribute('data-qr-name') || '');
        img.setAttribute('data-id', img.getAttribute('data-qr-number') || '');
    } else {
        img.style.display = 'none';
    }
});

function viewIdCard(btn) {
    const tr = btn.closest('tr');
    const d = tr.dataset;
    if (!d.studentId || d.studentId === '0') { showToast('This card has no student assigned.', 'warning'); return; }
    idCardData = {
        cardId: d.id, name: d.name, studentId: d.studentId, photo: d.photo, course: d.course,
        year: d.year, idnumber: d.idnumber, qr: d.qr, idtype: d.idtype,
        issued: d.issued, expiry: d.expiry
    };
    const img = document.getElementById('cardPhoto');
    const ini = document.getElementById('cardInitials');
    const src = normalizePhotoPath(d.photo);
    ini.style.display = 'none';
    // Attached here, not in the markup. This is the moment a photo load can
    // actually fail, and the moment the fallback is certain to exist: the old
    // inline onerror fired during parsing, before this script had run at all,
    // which is why a missing student photo threw a ReferenceError instead of
    // quietly showing the initials it was written to show.
    img.onerror = function () {
        img.onerror = null;
        img.removeAttribute('src');
        showIdInitialsFallback();
    };
    if (src) { img.style.display = 'block'; img.src = src; }
    else { img.style.display = 'none'; ini.textContent = idInitialsOf(d.name); ini.style.display = 'flex'; }
    document.getElementById('cardName').textContent = d.name || '—';
    document.getElementById('cardCourse').textContent = d.course || '—';
    document.getElementById('cardNumber').textContent = d.idnumber || d.studentNumber || '—';
    document.getElementById('cardIssued').textContent = d.issued ? new Date(d.issued).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    document.getElementById('cardExpiry').textContent = d.expiry ? new Date(d.expiry).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'No expiry';
    const qrImg = document.getElementById('cardQr');
    const cap = document.getElementById('cardQrCap');
    cap.textContent = 'Scan to verify'; cap.classList.remove('ok');
    qrImg.style.display = 'block';
    idCardData.qrData = generateQrDataUrl(d.studentId);
    if (d.qr) {
        qrImg.onerror = function () { this.onerror = null; if (idCardData.qrData) { this.src = idCardData.qrData; cap.classList.add('ok'); } };
        qrImg.onload = function () { cap.classList.add('ok'); };
        qrImg.src = normalizePhotoPath(d.qr);
    } else {
        qrImg.onerror = null;
        if (idCardData.qrData) { qrImg.src = idCardData.qrData; cap.classList.add('ok'); }
        else { qrImg.style.display = 'none'; cap.textContent = ''; }
    }
    fillIdCardBack(d.studentId);
    applyIdDesign(currentIdDesign);
    document.getElementById('cardFlip').classList.remove('flipped');
    document.getElementById('idCardModal').classList.add('active');
    openIdStudioDetails(btn);
    fitIdCardPreview();
    refreshIdPhotoFromStorage(d.id, d.studentId);
    document.body.style.overflow = 'hidden';
}

// Re-read Digital File Storage on every preview, including uploads made since page load.
async function refreshIdPhotoFromStorage(cardId, studentId) {
    const request = ++idPhotoRequest;
    if (!cardId) return;
    try {
        const response = await fetch('../api/rfid.php?id=' + encodeURIComponent(cardId), {cache:'no-store', credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.success || !result.data || request !== idPhotoRequest) return;
        if (String(result.data.student_id) !== String(studentId)) return;
        const src = result.data.photo_url || '';
        const img = document.getElementById('cardPhoto');
        if (!src) { img.removeAttribute('src'); showIdInitialsFallback(); return; }
        img.onerror = function () { this.onerror = null; this.removeAttribute('src'); showIdInitialsFallback(); };
        img.onload = function () {
            if (request !== idPhotoRequest) return;
            document.getElementById('cardInitials').style.display = 'none';
            this.style.display = 'block';
        };
        img.src = src;
        idCardData.photo = src;
    } catch (_) { /* Keep the last resolved photo if the network is unavailable. */ }
}

function fillIdCardBack(studentId) {
    const emgBox = document.getElementById('cardBackEmg');
    const addrBox = document.getElementById('cardBackAddr');
    const extras = (typeof CARD_EXTRAS !== 'undefined' ? CARD_EXTRAS[studentId] : null) || {};
    const list = extras.emergency || [];
    if (!list.length) {
        emgBox.innerHTML = '<div class="idcard-addr"><em>No emergency contact on file</em></div>';
    } else {
        emgBox.innerHTML = list.map(c =>
            '<div class="idcard-emg-row">'
            + '<span class="idcard-emg-ico"><i class="fa-solid fa-phone-volume"></i></span>'
            + '<div><div class="idcard-emg-name">' + (c.name||'&mdash;').replace(/</g,'&lt;') + '</div>'
            + '<div class="idcard-emg-sub">' + (c.rel||'Emergency').replace(/</g,'&lt;') + (c.phone ? ' &middot; '+c.phone.replace(/</g,'&lt;') : '') + '</div></div>'
            + '</div>'
        ).join('');
    }
    addrBox.innerHTML = extras.address ? extras.address.replace(/</g,'&lt;') : '<em>Not on file</em>';
}

function fitIdCardPreview() {
    const flip = document.getElementById('cardFlip');
    if (!flip) return;
    const canvas = flip.closest('.idstudio-canvas');
    const width = canvas ? Math.max(1, Math.min(340, canvas.clientWidth - 40, (canvas.clientHeight - 24) * 340 / 539)) : Math.min(340, flip.parentElement.clientWidth);
    flip.style.width = width + 'px';
    flip.style.height = (width * 539 / 340) + 'px';
    flip.style.setProperty('--idcard-scale', width / 340);
}
window.addEventListener('resize', fitIdCardPreview);

async function printIdCard() {
    stopIdConstruction();
    const area = document.getElementById('printArea');
    const flip = document.getElementById('cardFlip');
    if (!flip || !area) return;
    const faces = [flip.querySelector('.idcard'), flip.querySelector('.idcard-back')];
    if (faces.some(face => !face)) return;
    area.replaceChildren();
    // Portrait CR80: 53.98 × 85.60 mm; retain the same designed aspect ratio.
    for (const face of faces) {
        const sheet = document.createElement('div');
        sheet.className = 'idcard-print-face';
        const clone = face.cloneNode(true);
        clone.removeAttribute('id');
        clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
        sheet.appendChild(clone);
        area.appendChild(sheet);
    }
    try {
        await document.fonts.ready;
        await Promise.all(Array.from(area.querySelectorAll('img')).map(img => img.decode().catch(() => {})));
        window.print();
    } finally { area.replaceChildren(); }
}

// Wire up ID card modal close-on-overlay + Esc
document.getElementById('idCardModal')?.addEventListener('click', function(e) {
    if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }
});

// ── QR Modal ──────────────────────────────────────────────────────
function showQrModal(img) {
    const overlay = document.getElementById('qrModalOverlay');
    overlay.querySelector('img').src = img.src;
    overlay.querySelector('.qr-modal-name').textContent = img.dataset.name || '';
    overlay.querySelector('.qr-modal-sub').textContent = img.dataset.id ? 'Student #: ' + img.dataset.id : '';
    overlay.classList.add('active');
}
function closeQrModal() {
    document.getElementById('qrModalOverlay').classList.remove('active');
}
document.getElementById('qrModalOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeQrModal();
});

// Bulk registration is handled by js/register-cards.js.

</script>

<script type="application/json" id="assignStudentsData"><?= json_encode($students,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="../js/assign-card.js?v=20261111-1"></script>
<script src="../js/register-cards.js?v=20261111-2"></script>
<?php include '../includes/footer.php'; ?>
