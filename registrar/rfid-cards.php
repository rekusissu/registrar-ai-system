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
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';

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

$totalCards   = count($cards);
$activeCards  = count(array_filter($cards, fn($c) => $c['status'] === 'active'));
$expiredCards = count(array_filter($cards, fn($c) => $c['status'] === 'expired'));
$lostCards    = count(array_filter($cards, fn($c) => $c['status'] === 'lost'));
$inactiveCards = $totalCards - $activeCards - $expiredCards - $lostCards;

// Percentages
$activePct  = $totalCards ? round($activeCards / $totalCards * 100) : 0;
$expiredPct = $totalCards ? round($expiredCards / $totalCards * 100) : 0;
$lostPct    = $totalCards ? round($lostCards / $totalCards * 100) : 0;

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

$page_title = 'RFID Cards';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';

include '../includes/header.php';
include '../includes/sidebar.php';

// Deterministic avatar color per student_id
$avatarPalette = ['blue', 'green', 'purple', 'orange', 'pink'];
$avatarClasses = [];
foreach ($cards as $i => $c) {
    $studentKey = (string)($c['student_id'] ?? $c['id'] ?? $i);
    $avatarClasses[$i] = $avatarPalette[abs(crc32($studentKey)) % count($avatarPalette)];
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
</style>

<!-- ID Card Styles (from student-ids.php) -->
<style>
.idtype-chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#eef4ff; color:#2563eb; }
.qr-thumb { width:36px; height:36px; border:1px solid #e2e8f0; border-radius:8px; object-fit:contain; background:white; }
/* â”€â”€ ID card (view modal) &mdash; 3D flip card â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.idcard-flip { width:340px; height:430px; margin:0 auto; perspective:1400px; }
.idcard-inner { position:relative; width:100%; height:100%; transform-style:preserve-3d; transition:transform .8s cubic-bezier(.4,.15,.2,1); }
.idcard-flip.flipped .idcard-inner { transform:rotateY(180deg); }
.idcard, .idcard-back {
    position:absolute; inset:0; width:100%; height:100%; margin:0;
    border-radius:18px; overflow:hidden; background:#fff;
    border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.12);
    display:flex; flex-direction:column;
    backface-visibility:hidden; -webkit-backface-visibility:hidden;
}
.idcard-head { background:linear-gradient(135deg,#1a3a8c 0%,#2563eb 100%); color:#fff; padding:14px 18px; display:flex; align-items:center; gap:11px; position:relative; overflow:hidden; }
.idcard-head::after { content:''; position:absolute; right:-30px; top:-30px; width:110px; height:110px; border-radius:50%; background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%); }
.idcard-head img { width:34px; height:34px; border-radius:8px; background:#fff; object-fit:contain; position:relative; z-index:1; }
.idcard-head .school { font-size:12.5px; font-weight:800; letter-spacing:.3px; position:relative; z-index:1; }
.idcard-head .school small { display:block; font-size:9.5px; font-weight:500; opacity:.85; margin-top:1px; }
.idcard-photo-wrap { display:flex; justify-content:center; padding-top:16px; position:relative; z-index:2; }
.idcard-photo { width:86px; height:86px; border-radius:50%; object-fit:cover; background:#e2e8f0; border:4px solid #fff; box-shadow:0 4px 12px rgba(15,23,42,.18); display:block; }
.idcard-initials { width:86px; height:86px; border-radius:50%; background:linear-gradient(135deg,#e2e8f0,#cbd5e1); border:4px solid #fff; box-shadow:0 4px 12px rgba(15,23,42,.18); display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800; color:#475569; }
.idcard-center { text-align:center; padding:10px 20px 0; }
.idcard-name { font-size:16px; font-weight:800; color:#0f172a; margin-top:4px; line-height:1.3; }
.idcard-meta { font-size:11.5px; color:#64748b; margin-top:3px; }
.idcard-sec { display:flex; align-items:flex-start; gap:14px; margin:14px 18px 0; padding:12px 14px; background:#f8fafc; border:1px solid #eef2f7; border-radius:14px; }
.idcard-fields { flex:1; min-width:0; }
.idcard-field { margin-bottom:7px; }
.idcard-field:last-child { margin-bottom:0; }
.idcard-field .k { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:#94a3b8; }
.idcard-field .v { font-size:12.5px; font-weight:700; color:#0f172a; }
.idcard-field .v.mono { font-family:'JetBrains Mono',monospace; letter-spacing:.5px; }
.idcard-qrbox { text-align:center; flex-shrink:0; }
.idcard-qrbox img { width:78px; height:78px; background:#fff; padding:4px; border-radius:10px; border:1px solid #e2e8f0; display:block; }
.idcard-qrbox .cap { font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; margin-top:3px; }
.idcard-qrbox .cap.ok { color:#16a34a; }
.idcard-foot { margin-top:auto; background:#f8fafc; border-top:1px solid #eef2f7; padding:12px 20px; text-align:center; font-size:9px; color:#94a3b8; line-height:1.5; }
/* â”€â”€ Card back â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.idcard-back {
    position:absolute; inset:0; width:100%; height:100%; margin:0;
    border-radius:18px; overflow:hidden; background:#fff;
    border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.12);
    display:flex; flex-direction:column;
    backface-visibility:hidden; -webkit-backface-visibility:hidden;
    transform:rotateY(180deg);
}
.idcard-back-head { background:#0f172a; color:#fff; padding:10px 18px; display:flex; align-items:center; justify-content:space-between; }
.idcard-back-head .b-school { font-size:10.5px; font-weight:800; letter-spacing:.4px; }
.idcard-back-head .b-school small { display:block; font-size:8.5px; font-weight:500; color:#94a3b8; margin-top:1px; }
.idcard-back-sec { padding:12px 18px 4px; }
.idcard-back-sec + .idcard-back-sec { padding-top:0; }
.idcard-sec-title { display:flex; align-items:center; gap:6px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.7px; color:#94a3b8; margin-bottom:6px; }
.idcard-sec-title i { color:#2563eb; font-size:10px; }
.idcard-emg-row { display:flex; align-items:flex-start; gap:9px; padding:6px 0; }
.idcard-emg-row + .idcard-emg-row { border-top:1px dashed #eef2f7; }
.idcard-emg-ico { width:26px; height:26px; border-radius:8px; background:#fef2f2; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:11px; flex-shrink:0; }
.idcard-emg-name { font-size:12px; font-weight:700; color:#0f172a; }
.idcard-emg-sub { font-size:10.5px; color:#64748b; margin-top:1px; }
.idcard-addr { font-size:11.5px; color:#334155; line-height:1.55; background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:8px 12px; }
.idcard-addr em { color:#94a3b8; }
.idcard-sig { text-align:center; padding:4px 24px 14px; }
.idcard-sig .line { border-top:1px solid #334155; margin:0 24px 4px; }
.idcard-sig .who { font-size:8.5px; color:#475569; text-transform:uppercase; letter-spacing:.5px; }
.idcard-back-foot { margin-top:auto; background:#f8fafc; border-top:1px solid #eef2f7; padding:8px 20px; text-align:center; font-size:9px; color:#94a3b8; line-height:1.5; }
/* ── Print-only ID card styles ──────────────────────── */
#printArea{display:none;}
@media print{
    @page{size:portrait;margin:10mm;}
    body>*{display:none !important;}
    #printArea{display:block !important;position:fixed;left:0;top:0;width:100%;}
    #printArea *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;color-adjust:exact !important;}
}
</style>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>RFID Cards</h1>
            <p>Manage student RFID cards</p>
        </div>
        <div class="header-actions">
            <a href="rfid-test.php" class="btn btn-secondary">
                <i class="fas fa-credit-card"></i> Test Scanner
            </a>
            <button class="btn btn-primary" id="openAssignModal" onclick="openAssignModal()">
                <i class="fas fa-plus"></i> Assign Card
            </button>
        </div>
    </header>

    <!-- Stats -->
    <div class="rfid-stats">
        <div class="rfid-stat-card">
            <div class="stat-top">
                <div class="stat-icon blue"><i class="fas fa-credit-card"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $trendActive ?>%</span>
            </div>
            <div class="stat-number"><?= $totalCards ?></div>
            <div class="stat-label">Total Cards</div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-top">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $activePct ?>%</span>
            </div>
            <div class="stat-number"><?= $activeCards ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-top">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <span class="stat-trend down"><i class="fas fa-arrow-down"></i> <?= $expiredPct ?>%</span>
            </div>
            <div class="stat-number"><?= $expiredCards ?></div>
            <div class="stat-label">Expired</div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-top">
                <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                <span class="stat-trend down"><i class="fas fa-arrow-down"></i> <?= $lostPct ?>%</span>
            </div>
            <div class="stat-number"><?= $lostCards ?></div>
            <div class="stat-label">Lost</div>
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
                        <option value="inactive">Inactive</option>
                        <option value="unassigned">Unassigned</option>
                    </select>
                    <i class="fas fa-chevron-down filter-select-arrow"></i>
                </div>
            </div>
        </div>

    <!-- Table -->
    <div class="rfid-table-wrapper">
        <table>
            <thead>
                <tr>
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
                <?php if (empty($cards)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:48px 12px; color:#94a3b8;">
                            <i class="fas fa-credit-card" style="font-size:42px; color:#cbd5e1; display:block; margin-bottom:10px;"></i>
                            <p style="font-size:15px; font-weight:600; color:#64748b; margin:0;">No RFID cards found</p>
                            <p style="font-size:13px; margin:4px 0 0;">Assign cards to students to get started</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cards as $cardIndex => $card):
                        $initials = '';
                        if (!empty($card['student_name'])) {
                            $names = explode(' ', $card['student_name']);
                            $initials = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                        }
                        $avatarClass = $avatarClasses[$cardIndex] ?? 'blue';
                    ?>
                        <tr data-card='<?= htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8') ?>'
                            data-id="<?= (int)$card['id'] ?>"
                            data-student-id="<?= (int)($card['student_id'] ?? 0) ?>"
                            data-name="<?= htmlspecialchars($card['student_name'] ?? '', ENT_QUOTES) ?>"
                            data-photo="<?= htmlspecialchars($card['student_photo'] ?? '', ENT_QUOTES) ?>"
                            data-course="<?= htmlspecialchars($card['course'] ?? '', ENT_QUOTES) ?>"
                            data-year="<?= htmlspecialchars($card['year_level'] ?? '', ENT_QUOTES) ?>"
                            data-idnumber="<?= htmlspecialchars($card['student_id_number'] ?? '', ENT_QUOTES) ?>"
                            data-idtype="<?= htmlspecialchars($card['id_type'] ?? 'school_id', ENT_QUOTES) ?>"
                            data-issued="<?= htmlspecialchars($card['id_issue_date'] ?? $card['issued_date'] ?? '', ENT_QUOTES) ?>"
                            data-expiry="<?= htmlspecialchars($card['id_expiry_date'] ?? $card['expiry_date'] ?? '', ENT_QUOTES) ?>"
                            data-qr="<?= htmlspecialchars($card['qr_code_path'] ?? '', ENT_QUOTES) ?>"
                            data-student-number="<?= htmlspecialchars($card['student_number'] ?? '', ENT_QUOTES) ?>">
                            <td>
                                <div class="card-uid-display">
                                    <span class="chip"><i class="fas fa-microchip"></i></span>
                                    <?= htmlspecialchars($card['card_uid']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($card['student_id'])): ?>
                                    <div class="student-info">
                                        <div class="student-avatar <?= $avatarClass ?>"><?= $initials ?: '?' ?></div>
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
                                <?php if (!empty($card['qr_code_path'])): ?>
                                    <img class="qr-thumb" src="<?= htmlspecialchars($card['qr_code_path']) ?>" alt="QR">
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
                                    <button class="action-btn edit" onclick="openEditModal(<?= (int)$card['id'] ?>)" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="action-btn delete" onclick="confirmDelete(<?= (int)$card['id'] ?>, '<?= htmlspecialchars($card['card_uid'], ENT_QUOTES) ?>')" title="Delete">
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

    <div class="table-footer">
            <div class="info-text">Showing <strong id="showingCount"><?= count($cards) ?></strong> of <strong id="totalCount"><?= count($cards) ?></strong> cards</div>
        </div>
</div><!-- /search-table-container -->
    </main>

<!-- â”€â”€ Assign Card Modal â”€â”€ -->
<div class="logout-modal-overlay" id="assignModal">
    <div class="logout-modal rfid-modal">
        <div class="rfid-modal-header">
            <div class="header-icon assign"><i class="fas fa-credit-card"></i></div>
            <div>
                <h3>Assign RFID Card</h3>
                <p>Enter UID, assign to student, set validity.</p>
            </div>
        </div>

        <form id="assignCardForm">
            <input type="hidden" id="selectedStudentId" name="student_id" value="">
            <div class="rfid-modal-body-wrapper">

                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fas fa-user-graduate"></i></div>
                        <div>
                            <div class="form-section-title">Student</div>
                            <div class="form-section-subtitle">Search and select</div>
                        </div>
                    </div>

                    <div class="student-search-wrapper" style="position:relative;">
                        <input type="text" id="studentSearchInput" class="form-control" placeholder="Type student name..." autocomplete="off" />
                        <div class="student-search-results" id="studentSearchResults"></div>
                    </div>

                    <select id="studentSelect" style="display:none;">
                        <option value="">Select a student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int)$student['id'] ?>">
                                <?= htmlspecialchars($student['name']) ?> &middot; <?= htmlspecialchars($student['student_number']) ?> &middot; <?= htmlspecialchars($student['course']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="selected-student-chip" id="selectedStudentDisplay">
                        <i class="fas fa-check-circle"></i>
                        <div style="flex:1;">
                            <span class="chip-name" id="selectedName"></span>
                            <span class="chip-id" id="selectedId" style="display:block;font-size:11px;color:#16a34a;margin-top:1px;">Selected</span>
                        </div>
                        <button type="button" class="chip-clear" onclick="clearSelectedStudent()" title="Clear">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fas fa-microchip"></i></div>
                        <div>
                            <div class="form-section-title">Card UID</div>
                            <div class="form-section-subtitle">10-digit identifier</div>
                        </div>
                    </div>

                    <div class="uid-row">
                        <div style="flex:1;position:relative;">
                            <input type="text" id="cardUid" class="form-control" maxlength="10" inputmode="numeric" placeholder="e.g. 1234567890" style="padding-right:50px;" />
                            <span id="uidLengthBadge" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:600;color:#94a3b8;background:#f1f5f9;padding:2px 8px;border-radius:6px;">0/10</span>
                        </div>
                        <div style="width:42px;height:42px;border:1.5px solid #e2e8f0;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#94a3b8;background:#f8fafc;flex-shrink:0;">
                            <i class="fas fa-wave-square" style="font-size:16px;"></i>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fas fa-calendar"></i></div>
                        <div>
                            <div class="form-section-title">Validity Period</div>
                            <div class="form-section-subtitle">Issued and expiry dates</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Issued</label>
                            <input type="date" id="issuedDate" name="issued_date" class="form-control" value="<?= date('Y-m-d') ?>" />
                        </div>
                        <div class="form-group">
                            <label>Expiry</label>
                            <input type="date" id="expiryDate" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" />
                        </div>
                    </div>
                </div>

                <div class="form-section" style="border-bottom:none;">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fas fa-sticky-note"></i></div>
                        <div>
                            <div class="form-section-title">Notes</div>
                            <div class="form-section-subtitle">Optional remarks</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <textarea id="cardNotes" name="notes" class="form-control" rows="2" placeholder="e.g. Replacement card, lost card reported..."></textarea>
                    </div>
                </div>

            </div><!-- /rfid-modal-body-wrapper -->

            <div style="padding:10px 20px;font-size:12px;color:#64748b;background:#f8fafc;border-top:1px solid #e2e8f0;">
                <i class="fas fa-info-circle" style="color:#2563eb;"></i> A Student ID (school_id) with QR code will also be issued automatically.
            </div>

            <div class="rfid-modal-actions">
                <button type="button" class="btn btn-light" data-close-modal="assign">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn" disabled>
                    <i class="fas fa-save"></i> Assign Card
                </button>
            </div>
        </form>
    </div>
</div>

<!-- â”€â”€ Edit Card Modal â”€â”€ -->
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

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-microchip"></i></div>
                    <div>
                        <div class="form-section-title">Card Identity</div>
                        <div class="form-section-subtitle">Read-only card and student details</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Card UID</label>
                        <div class="uid-row" style="margin-top:5px;">
                            <span class="chip" style="display:inline-flex;width:32px;height:32px;align-items:center;justify-content:center;background:linear-gradient(135deg,#e2e8f0,#cbd5e1);border-radius:6px;font-size:12px;color:#64748b;flex-shrink:0;"><i class="fas fa-microchip"></i></span>
                            <span id="editUid" style="font-family:'Courier New',monospace;font-size:16px;font-weight:700;color:#1e40af;letter-spacing:1px;line-height:32px;"></span>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Assigned Student</label>
                        <div style="padding:9px 12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;font-weight:600;font-size:14px;color:#0f172a;">
                            <span id="editStudentName">&mdash;</span>
                            <span id="editStudentNumber" style="font-weight:400;color:#64748b;margin-left:8px;"></span>
                        </div>
                    </div>
                </div>
                <div style="margin-top:8px;padding:8px 12px;background:#fef9c3;border:1px solid #facc15;border-radius:8px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Last updated: <span id="editUpdatedAt">&mdash;</span></span>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-sliders"></i></div>
                    <div>
                        <div class="form-section-title">Status & Validity</div>
                        <div class="form-section-subtitle">Change status and expiration</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-flag" style="margin-right:4px;color:#64748b;"></i>Status</label>
                        <div style="position:relative;">
                            <select id="editStatus" class="form-control">
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="lost">Lost</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <i class="fas fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:10px;pointer-events:none;"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar" style="margin-right:4px;color:#64748b;"></i>Expiry Date</label>
                        <input type="date" id="editExpiryDate" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-question-circle" style="margin-right:4px;color:#64748b;"></i>Status Reason</label>
                    <div style="position:relative;">
                        <select id="editStatusReason" class="form-control">
                            <option value="">&mdash; Select reason &mdash;</option>
                            <option value="active">Active</option>
                            <option value="graduated">Graduated</option>
                            <option value="lost_card">Lost Card</option>
                            <option value="dropped_out">Dropped Out</option>
                            <option value="transferred">Transferred</option>
                            <option value="inactive">Inactive</option>
                            <option value="other">Other</option>
                        </select>
                        <i class="fas fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:10px;pointer-events:none;"></i>
                    </div>
                </div>
            </div>

            <div class="form-section" style="border-bottom: none;">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-sticky-note"></i></div>
                    <div>
                        <div class="form-section-title">Notes</div>
                        <div class="form-section-subtitle">Additional remarks</div>
                    </div>
                </div>
                <div class="form-group">
                    <textarea id="editNotes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                </div>
            </div>

            </div><!-- /rfid-modal-body-wrapper -->

            <div class="rfid-modal-actions">
                <button type="button" class="btn btn-light" data-close-modal="edit">Cancel</button>
                <button type="submit" class="btn btn-primary" id="editSubmitBtn">
                    <i class="fas fa-save"></i> Save Changes
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
                    <li><span class="kv-label">ID Number</span><span class="kv-value" id="viewIdNumber">&mdash;</span></li>
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
<div class="modal-overlay" id="idCardModal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3><i class="fas fa-id-card" style="color:#2563eb;"></i> Student ID Card</h3><button class="modal-close" onclick="document.getElementById('idCardModal').classList.remove('active'); document.body.style.overflow='';"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="idcard-flip" id="cardFlip">
              <div class="idcard-inner">
                <div class="idcard">
                    <div class="idcard-head">
                        <img src="../assets/images/BCP_LOGO.png" alt="BCP" onerror="this.style.display='none'">
                        <div class="school">BESTLINK COLLEGE OF THE PHILIPPINES<small>Official Student Identification</small></div>
                    </div>
                    <div class="idcard-photo-wrap">
                        <img class="idcard-photo" id="cardPhoto" src="" alt="photo" style="display:none;" onerror="showIdInitialsFallback()">
                        <div class="idcard-initials" id="cardInitials" style="display:none;">&mdash;</div>
                    </div>
                    <div class="idcard-center">
                        <div class="idcard-name" id="cardName">&mdash;</div>
                    </div>
                    <div class="idcard-sec">
                        <div class="idcard-fields">
                            <div class="idcard-field"><div class="k">Course</div><div class="v" id="cardCourse">&mdash;</div></div>
                            <div class="idcard-field"><div class="k">ID Number</div><div class="v mono" id="cardNumber">&mdash;</div></div>
                            <div style="display:flex;gap:16px;">
                                <div class="idcard-field" style="flex:1;"><div class="k">Issued</div><div class="v" id="cardIssued">&mdash;</div></div>
                                <div class="idcard-field" style="flex:1;"><div class="k">Valid Until</div><div class="v" id="cardExpiry">&mdash;</div></div>
                            </div>
                        </div>
                        <div class="idcard-qrbox">
                            <img id="cardQr" src="" alt="QR">
                            <div class="cap" id="cardQrCap">Scan to verify</div>
                        </div>
                    </div>
                    <div class="idcard-foot">This ID is property of Bestlink College of the Philippines.<br>If found, please return to the Registrar's Office.</div>
                </div>

                <div class="idcard-back">
                    <div class="idcard-back-head">
                        <div class="b-school">BESTLINK COLLEGE OF THE PHILIPPINES<small>Registrar's Office</small></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-triangle-exclamation"></i> In case of emergency, please contact</div>
                        <div id="cardBackEmg"></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-house"></i> Address</div>
                        <div class="idcard-addr" id="cardBackAddr"><em>Not on file</em></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-file-shield"></i> Reminders</div>
                        <div class="idcard-addr" style="font-size:10.5px;">
                            &bull; This card is non-transferable and must be worn at all times inside the campus.<br>
                            &bull; Not valid without the signature of the Registrar.<br>
                            &bull; Report lost cards immediately to the Registrar's Office.
                        </div>
                    </div>
                    <div class="idcard-sig">
                        <div class="line"></div>
                        <div class="who">Signature of Student &mdash; Not valid without signature</div>
                    </div>
                    <div class="idcard-back-foot">If this card is found, please return to the Registrar's Office or call the school hotline.</div>
                </div>
              </div>
            </div>
            <div style="text-align:center;margin-top:14px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('cardFlip').classList.toggle('flipped')" style="min-width:150px;"><i class="fas fa-rotate"></i> Flip Card</button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="document.getElementById('idCardModal').classList.remove('active'); document.body.style.overflow='';">Close</button>
            <button class="btn btn-primary" onclick="printIdCard()"><i class="fas fa-print"></i> Print ID</button>
        </div>
    </div>
</div>

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

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
<script>
// =================================================================
// RFID CARDS &mdash; INLINE JS
// =================================================================
let deleteTarget = null;

// â”€â”€ Toast helper (colored, from components.css) â”€â”€
function showToast(title, message, type) {
    const container = document.getElementById('toastContainer') || (() => {
        const c = document.createElement('div');
        c.className = 'toast-container';
        document.body.appendChild(c);
        return c;
    })();
    type = type || 'info';
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' };
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML =
        '<i class="fas ' + (icons[type] || icons.info) + ' toast-icon"></i>' +
        '<div class="toast-content">' +
            '<div class="toast-title"></div>' +
            '<div class="toast-message"></div>' +
        '</div>' +
        '<button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>';
    toast.querySelector('.toast-title').textContent = title;
    toast.querySelector('.toast-message').textContent = message;
    toast.querySelector('.toast-close').addEventListener('click', () => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    });
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// â”€â”€ Delete modal â”€â”€
function confirmDelete(id, uid) {
    deleteTarget = id;
    document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete RFID card ' + uid + '? This action cannot be undone.';
    document.getElementById('deleteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('deleteCancel').addEventListener('click', () => {
    document.getElementById('deleteModal').classList.remove('active');
    document.body.style.overflow = '';
    deleteTarget = null;
});
document.getElementById('deleteConfirm').addEventListener('click', () => {
    if (!deleteTarget) return;
    fetch('../api/rfid.php?id=' + deleteTarget, { method: 'DELETE' }).then(() => window.location.reload());
});
document.getElementById('deleteModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        e.currentTarget.classList.remove('active');
        document.body.style.overflow = '';
        deleteTarget = null;
    }
});
['assignModal','editModal','viewModal'].forEach(id => {
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
const statusFilter = document.getElementById('statusFilter');

function applyRfidSearch() {
    if (!rfidTableBody) return;
    const query = (rfidSearchInput?.value || '').trim().toLowerCase();
    const filterStatus = statusFilter?.value || '';
    const rows = rfidTableBody.querySelectorAll('tr[data-card]');
    let visible = 0;
    rows.forEach(row => {
        const data = (row.getAttribute('data-card') || '').toLowerCase();
        let match = true;
        if (query) match = data.indexOf(query) !== -1;
        if (match && filterStatus === 'unassigned') match = data.indexOf('"student_id":null') !== -1 || data.indexOf('"student_id":""') !== -1;
        else if (match && filterStatus) match = data.indexOf('"status":"' + filterStatus + '"') !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    if (showingCount) showingCount.textContent = visible;
}
if (rfidSearchInput) rfidSearchInput.addEventListener('input', applyRfidSearch);
if (statusFilter) statusFilter.addEventListener('change', applyRfidSearch);

// â”€â”€ Assign modal: students from <select> â”€â”€

const allStudents = [];
const studentSelectEl = document.getElementById('studentSelect');
if (studentSelectEl) {
    Array.from(studentSelectEl.options).forEach(opt => {
        if (opt.value) allStudents.push({ id: opt.value, name: opt.textContent.trim() });
    });
}
const studentSearchInput = document.getElementById('studentSearchInput');
const studentSearchResults = document.getElementById('studentSearchResults');

function selectStudent(id, name) {
    document.getElementById('studentSelect').value = id;
    document.getElementById('selectedStudentId').value = id;
    document.getElementById('selectedName').textContent = name;
    document.getElementById('selectedId').textContent = 'Selected';
    document.getElementById('selectedStudentDisplay').classList.add('show');
    if (studentSearchInput) {
        studentSearchInput.value = name;
        studentSearchInput.style.borderColor = '#22c55e';
        studentSearchInput.style.background = '#f0fdf4';
    }
    if (studentSearchResults) studentSearchResults.classList.remove('show');
    showToast('Student Selected', name, 'success');
    validateAssignForm();
}

function clearSelectedStudent() {
    document.getElementById('selectedStudentDisplay').classList.remove('show');
    document.getElementById('selectedStudentId').value = '';
    document.getElementById('studentSelect').value = '';
    if (studentSearchInput) {
        studentSearchInput.value = '';
        studentSearchInput.style.borderColor = '';
        studentSearchInput.style.background = '';
    }
    validateAssignForm();
}

if (studentSearchInput) {
    studentSearchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        if (!query) { studentSearchResults.classList.remove('show'); return; }
        const results = allStudents.filter(s => s.name.toLowerCase().includes(query));
        if (!results.length) {
            studentSearchResults.innerHTML = '<div class="no-results">No students found</div>';
        } else {
            studentSearchResults.innerHTML = results.slice(0, 10).map(s =>
                `<div class="result-item" onclick="selectStudent('${s.id}', '${s.name.replace(/'/g, "\\'")}')">
                    <span class="student-name">${s.name}</span>
                </div>`
            ).join('');
        }
        studentSearchResults.classList.add('show');
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.student-search-wrapper')) studentSearchResults.classList.remove('show');
    });
}

// â”€â”€ UID input + duplicate check â”€â”€
const cardUidInput = document.getElementById('cardUid');
let uidCheckTimeout;

if (cardUidInput) {
    cardUidInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
        const len = this.value.length;
        this.style.borderColor = len === 10 ? '#22c55e' : '';
        this.style.background = len === 10 ? '#f0fdf4' : '';
        const badge = document.getElementById('uidLengthBadge');
        if (badge) {
            badge.textContent = len + '/10';
            badge.style.color = len === 10 ? '#16a34a' : '#94a3b8';
            badge.style.background = len === 10 ? '#dcfce7' : '#f1f5f9';
        }
        // Duplicate check
        clearTimeout(uidCheckTimeout);
        const uid = this.value;
        if (uid.length === 10) {
            uidCheckTimeout = setTimeout(() => {
                fetch('../api/rfid.php?check_uid=' + encodeURIComponent(uid))
                    .then(r => r.json())
                    .then(d => {
                        if (d.exists) {
                            this.style.borderColor = '#dc2626';
                            this.style.background = '#fee2e2';
                            const badge2 = document.getElementById('uidLengthBadge');
                            if (badge2) { badge2.style.color = '#dc2626'; badge2.style.background = '#fee2e2'; }
                            showToast('Duplicate UID', 'Card ' + uid + ' already assigned to ' + d.student, 'warning');
                        }
                    })
                    .catch(() => {});
            }, 500);
        }
        validateAssignForm();
    });
}

// â”€â”€ Form validation for Assign modal â”€â”€
function validateAssignForm() {
    const studentId = document.getElementById('selectedStudentId').value;
    const cardUid = document.getElementById('cardUid').value.trim();
    const submitBtn = document.getElementById('assignSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = !(studentId && cardUid && cardUid.length === 10);
    }
}

// â”€â”€ Open / close modals â”€â”€
function openAssignModal() {
    document.getElementById('assignModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    if (cardUidInput) setTimeout(() => cardUidInput.focus(), 50);
}
function closeAssignModal() {
    document.getElementById('assignModal').classList.remove('active');
    document.body.style.overflow = '';
    const f = document.getElementById('assignCardForm');
    if (f) f.reset();
    clearSelectedStudent();
    document.getElementById('issuedDate').value = new Date().toISOString().slice(0, 10);
    const d = new Date(); d.setFullYear(d.getFullYear() + 1);
    document.getElementById('expiryDate').value = d.toISOString().slice(0, 10);
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    document.body.style.overflow = '';
}
function closeViewDrawer() {
    closeViewModal();
}

// â”€â”€ Assign form submit â”€â”€
document.getElementById('assignCardForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const studentId = document.getElementById('selectedStudentId').value;
    const cardUid = document.getElementById('cardUid').value.trim();
    if (!studentId) { showToast('Error', 'Please select a student.', 'error'); return; }
    if (!cardUid || cardUid.length !== 10) { showToast('Error', 'Card UID must be exactly 10 digits.', 'error'); return; }

    const submitBtn = document.getElementById('assignSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';

    try {
        const res = await fetch('../api/rfid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                student_id: studentId,
                card_uid: cardUid,
                issued_date: document.getElementById('issuedDate').value,
                expiry_date: document.getElementById('expiryDate').value,
                notes: document.getElementById('cardNotes').value
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Success', data.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('Error', data.message, 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Assign Card';
        }
    } catch (err) {
        showToast('Error', 'Network error. Please try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Assign Card';
    }
});

// â”€â”€ Edit modal â”€â”€
async function openEditModal(id) {
    const m = document.getElementById('editModal');
    if (!m) return;
    try {
        const res = await fetch('../api/rfid.php?id=' + id);
        const json = await res.json();
        if (!json.success || !json.data) {
            showToast('Error', json.message || 'Card not found.', 'error');
            return;
        }
        const c = json.data;
        document.getElementById('editCardId').value = c.id;
        document.getElementById('editUid').textContent = c.card_uid;
        document.getElementById('editStudentName').textContent = c.student_name || 'Unassigned';
        document.getElementById('editStudentNumber').textContent = c.student_number || '';
        document.getElementById('editStatus').value = c.status;
        document.getElementById('editStatusReason').value = c.status_reason || '';
        document.getElementById('editExpiryDate').value = c.expiry_date || '';
        document.getElementById('editNotes').value = c.notes || '';
        const updated = c.updated_at || c.updated_date;
        document.getElementById('editUpdatedAt').textContent = updated ? new Date(updated).toLocaleString(undefined, { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' }) : 'Never';
        m.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        showToast('Error', 'Failed to load card details.', 'error');
    }
}

document.getElementById('editCardForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const id = document.getElementById('editCardId').value;
    if (!id) return;
    const submitBtn = document.getElementById('editSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const res = await fetch('../api/rfid.php?id=' + id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                status: document.getElementById('editStatus').value,
                status_reason: document.getElementById('editStatusReason').value,
                expiry_date: document.getElementById('editExpiryDate').value,
                notes: document.getElementById('editNotes').value
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Card updated', data.message || 'Saved.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast('Error', data.message || 'Update failed.', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        }
    } catch (err) {
        showToast('Error', 'Network error. Please try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
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
        if (el) el.textContent = (v === null || v === undefined || v === '') ? '&mdash;' : v;
    };

    setText('#viewUid', 'â€¦');
    setText('#viewStudent', 'Loadingâ€¦');

    try {
        const res = await fetch('../api/rfid.php?id=' + id);
        const json = await res.json();
        if (!json.success || !json.data) { showToast('Error', json.message || 'Card not found.', 'error'); closeViewModal(); return; }
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
        showToast('Error', 'Failed to load card details.', 'error');
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
let idCardData = null;

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

function generateQrDataUrl(idNumber) {
    if (typeof qrcode === 'undefined') return '';
    try {
        const qr = qrcode(0, 'M');
        qr.addData('https://registrar.bcpsms2.com/verify-student.php?id_number=' + encodeURIComponent(idNumber || ''));
        qr.make();
        return qr.createDataURL(8, 8);
    } catch (e) { return ''; }
}

function viewIdCard(btn) {
    const tr = btn.closest('tr');
    const d = tr.dataset;
    if (!d.studentId || d.studentId === '0') { showToast('No Student', 'This card has no student assigned.', 'warning'); return; }
    idCardData = {
        name: d.name, studentId: d.studentId, photo: d.photo, course: d.course,
        year: d.year, idnumber: d.idnumber, qr: d.qr, idtype: d.idtype,
        issued: d.issued, expiry: d.expiry
    };
    const img = document.getElementById('cardPhoto');
    const ini = document.getElementById('cardInitials');
    const src = normalizePhotoPath(d.photo);
    ini.style.display = 'none';
    if (src) { img.style.display = 'block'; img.src = src; }
    else { img.style.display = 'none'; ini.textContent = idInitialsOf(d.name); ini.style.display = 'flex'; }
    document.getElementById('cardName').textContent = d.name || '&mdash;';
    document.getElementById('cardCourse').textContent = d.course || '&mdash;';
    document.getElementById('cardNumber').textContent = d.idnumber || '&mdash;';
    document.getElementById('cardIssued').textContent = d.issued ? new Date(d.issued).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '&mdash;';
    document.getElementById('cardExpiry').textContent = d.expiry ? new Date(d.expiry).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'No expiry';
    const qrImg = document.getElementById('cardQr');
    const cap = document.getElementById('cardQrCap');
    cap.textContent = 'Scan to verify'; cap.classList.remove('ok');
    qrImg.style.display = 'block';
    idCardData.qrData = generateQrDataUrl(d.idnumber);
    if (d.qr) {
        qrImg.onerror = function () { this.onerror = null; if (idCardData.qrData) this.src = idCardData.qrData; };
        qrImg.src = normalizePhotoPath(d.qr);
    } else {
        qrImg.onerror = null;
        if (idCardData.qrData) { qrImg.src = idCardData.qrData; cap.classList.add('ok'); }
        else { qrImg.style.display = 'none'; cap.textContent = ''; }
    }
    fillIdCardBack(d.studentId);
    document.getElementById('cardFlip').classList.remove('flipped');
    document.getElementById('idCardModal').classList.add('active');
    document.body.style.overflow = 'hidden';
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

function printIdCard() {
    var area = document.getElementById('printArea');
    var flip = document.getElementById('cardFlip');
    if (!flip || !area) { showToast('Error','Card elements not found.','error'); return; }
    var frontEl = flip.querySelector('.idcard');
    var backEl = flip.querySelector('.idcard-back');
    if (!frontEl || !backEl) { showToast('Error','Card faces not found.','error'); return; }

    /*
     * Standard RFID/credit card (CR80): 85mm wide × 55mm tall
     * Screen card: 340px wide × 430px tall
     * Portrait orientation on card: 55mm wide × 85mm tall
     * At 96 DPI: 340px = 89.42mm, 430px = 113.90mm
     * Scale to fit width: 55 / 89.42 = 0.615
     * Resulting height: 113.90 × 0.615 = 70.05mm (fits within 85mm)
     */
    var targetWmm = 55;  // mm
    var pxPerMm = 96 / 25.4;
    var scale = (targetWmm * pxPerMm) / 340;

    var fClone = frontEl.cloneNode(true);
    var bClone = backEl.cloneNode(true);
    [fClone, bClone].forEach(function(el) {
        el.style.position = 'relative';
        el.style.inset = 'auto';
        el.style.width = '340px';
        el.style.height = '430px';
        el.style.transform = 'none';
        el.style.backfaceVisibility = 'visible';
        el.style.webkitBackfaceVisibility = 'visible';
        el.style.margin = '0 auto 4mm';
        el.style.pageBreakInside = 'avoid';
        el.style.zoom = scale;
    });
    bClone.style.transform = 'none';

    area.innerHTML = '';
    area.appendChild(fClone);
    area.appendChild(bClone);

    window.print();
    area.innerHTML = '';
}

// Wire up ID card modal close-on-overlay + Esc
document.getElementById('idCardModal').addEventListener('click', function(e) {
    if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }
});
</script>

<?php include '../includes/footer.php'; ?>
