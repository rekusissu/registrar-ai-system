<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Registrar-authoritative contact management — the single place
//  the Registrar manages a student's guardians, emergency contacts,
//  AND email recipients, plus the queue of student change requests.
//
//  * Guardians + emergency contacts   → guardians / emergency_contacts
//  * Email recipients (verified + perms, Test Email, snapshot,
//    transcript)                      → contact_recipients
//  * Student "Request a Change" queue → contact_change_requests
//    (approve APPLIES the change to the real table)
//  * Auto-fill from enrollment        → pulls father/mother names
//    off the students record (api/contacts.php pull_enrollment)
//  Built on the shared registrar.css + registrar-premium.css system.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name
     FROM students WHERE status != 'archived'
     ORDER BY last_name, first_name"
);

$rows = $db->fetchAll("
    SELECT s.id AS student_id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name,
           g.id AS guardian_id, g.full_name, g.relationship, g.contact_number, g.email, g.is_primary, g.is_emergency
    FROM students s
    LEFT JOIN guardians g ON g.student_id = s.id
    WHERE s.status != 'archived'
    ORDER BY s.last_name, s.first_name, g.is_primary DESC, g.id
");
$byStudent = [];
foreach ($rows as $r) {
    if (!isset($byStudent[$r['student_id']])) {
        $byStudent[$r['student_id']] = [
            'student_id' => $r['student_id'],
            'student_number' => $r['student_number'],
            'student_name' => $r['student_name'],
            'guardians' => [],
        ];
    }
    if ($r['guardian_id']) $byStudent[$r['student_id']]['guardians'][] = $r;
}

// Emergency contacts, grouped by student (names shown inline in the table).
$emgByStudent = [];
foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM emergency_contacts ORDER BY full_name") as $e) {
    $emgByStudent[$e['student_id']][] = $e;
}

// Email recipients (contact_recipients), grouped by student.
$contactByStudent = [];
try {
    foreach ($db->fetchAll("SELECT * FROM contact_recipients ORDER BY verified DESC, id DESC") as $c) {
        $contactByStudent[(int) $c['student_id']][] = $c;
    }
} catch (Throwable $e) {
    error_log('[guardians] contact_recipients query failed: ' . $e->getMessage());
    $contactByStudent = [];
}

// Pending student change requests (contact_change_requests).
$pendingRequests = [];
try {
    $pendingRequests = $db->fetchAll("
        SELECT ccr.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
        FROM contact_change_requests ccr
        JOIN students s ON s.id = ccr.student_id
        WHERE ccr.status = 'pending'
        ORDER BY ccr.id DESC
    ");
} catch (Throwable $e) {
    error_log('[guardians] contact_change_requests query failed: ' . $e->getMessage());
    $pendingRequests = [];
}

// ── Overview counters ─────────────────────────────────────────
$statStudents = count($byStudent);
$statGuardians = 0;
foreach ($byStudent as $s) $statGuardians += count($s['guardians']);
$statEmergency = 0;
foreach ($emgByStudent as $list) $statEmergency += count($list);
$statMissing = count(array_filter($byStudent, fn($s) => empty($s['guardians'])));
$statPending = count($pendingRequests);

/** Two-letter initials from a name, for the student avatar tile. */
function gdn_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    $sub = fn($s) => function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    $up  = fn($s) => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $init = $up($sub($parts[0]));
    if (count($parts) > 1) $init .= $up($sub(end($parts)));
    return $init;
}

/** HTML-escape, tolerant of null. */
function gdn_esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Human-readable summary of a student's proposed change request. */
function gdn_ap_summary(array $req): string {
    $payload = json_decode((string) ($req['payload'] ?? '{}'), true);
    $payload = is_array($payload) ? $payload : [];
    $t    = (string) $req['request_type'];
    $type = (string) $req['contact_type'];
    $name = trim((string) ($payload['full_name'] ?? ''));

    $line = '<strong>' . ucfirst($t) . ' ' . $type . '</strong>'
        . ($name !== '' ? ' — ' . gdn_esc($name) : '');

    $bits = [];
    if (!empty($payload['relationship'])) $bits[] = gdn_esc(ucfirst((string) $payload['relationship']));
    if (!empty($payload['contact_number'])) $bits[] = gdn_esc((string) $payload['contact_number']);
    if (!empty($payload['phone'])) $bits[] = gdn_esc((string) $payload['phone']);
    if (!empty($payload['email'])) $bits[] = gdn_esc((string) $payload['email']);
    if ($type === 'email') {
        $perms = [];
        if (!empty($payload['send_billing'])) $perms[] = 'Invoices';
        if (!empty($payload['send_grades'])) $perms[] = 'Grades';
        if (!empty($payload['send_emergency'])) $perms[] = 'Alerts';
        if ($perms) $bits[] = implode(', ', $perms);
    }
    return $line . ($bits ? ' · <span class="ap-detail">' . implode(' · ', $bits) . '</span>' : '');
}

$page_title = 'Guardians & Contacts';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
include '../includes/header.php';
include '../includes/sidebar.php';

// Build JSON lists per student so JS can populate the modal.
$guardRowsByStudent = [];
foreach ($byStudent as $sid => $s) {
    $guardRowsByStudent[$sid] = $s['guardians'];
}
$contactRowsByStudent = [];
foreach ($contactByStudent as $sid => $list) {
    $contactRowsByStudent[$sid] = $list;
}
?>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Guardians &amp; Contacts</h1>
                <p>Registrar-managed guardians, emergency contacts, and email recipients for every student</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openManage()"><i class="fas fa-users"></i> Manage Contacts</button>
            </div>
        </header>

        <!-- ── Overview stats ─────────────────────────────────── -->
        <div class="gdn-stats">
            <div class="gdn-card">
                <div class="gdn-ico gdn-blue"><i class="fa-solid fa-user-graduate"></i></div>
                <div>
                    <div class="gdn-val"><?= $statStudents ?></div>
                    <div class="gdn-lbl">Students</div>
                </div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-teal"><i class="fa-solid fa-people-roof"></i></div>
                <div>
                    <div class="gdn-val"><?= $statGuardians ?></div>
                    <div class="gdn-lbl">Guardians on file</div>
                </div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-purple"><i class="fa-solid fa-truck-medical"></i></div>
                <div>
                    <div class="gdn-val"><?= $statEmergency ?></div>
                    <div class="gdn-lbl">Emergency contacts</div>
                </div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-amber"><i class="fa-solid fa-circle-exclamation"></i></div>
                <div>
                    <div class="gdn-val"><?= $statMissing ?></div>
                    <div class="gdn-lbl">No guardian recorded</div>
                </div>
            </div>
            <div class="gdn-card<?= $statPending > 0 ? ' clickable' : '' ?>"<?= $statPending > 0 ? ' onclick="document.getElementById(\'approvalsPanel\')?.scrollIntoView({behavior:\'smooth\'})"' : '' ?>>
                <div class="gdn-ico gdn-red"><i class="fa-solid fa-file-circle-check"></i></div>
                <div>
                    <div class="gdn-val"><?= $statPending ?></div>
                    <div class="gdn-lbl">Pending approvals</div>
                </div>
            </div>
        </div>

        <?php if ($pendingRequests): ?>
        <!-- ── Approvals queue ────────────────────────────────── -->
        <div class="panel gdn-panel" id="approvalsPanel" style="margin-bottom:24px;border-left:4px solid #ea580c;">
            <div class="gdn-toolbar">
                <div class="gdn-toolbar-title">
                    <i class="fa-solid fa-file-circle-check"></i> Student change requests
                    <span class="gdn-pill amber"><?= count($pendingRequests) ?></span>
                </div>
                <span class="mg-hint" style="color:#94a3b8;font-size:12px;">Approve to apply the change; Reject to send it back with a note.</span>
            </div>
            <?php foreach ($pendingRequests as $req): ?>
            <div class="ap-row">
                <div class="ap-main">
                    <div class="ap-head">
                        <span class="ap-student"><?= gdn_esc($req['student_name']) ?><small><?= gdn_esc($req['student_number']) ?></small></span>
                        <span class="chip blue"><?= gdn_esc(ucfirst((string) $req['contact_type'])) ?></span>
                        <span class="chip green"><?= gdn_esc(ucfirst((string) $req['request_type'])) ?></span>
                        <span class="ap-time"><?= date('M j, g:i A', strtotime((string) $req['created_at'])) ?></span>
                    </div>
                    <div class="ap-body"><?= gdn_ap_summary($req) ?></div>
                    <?php if (!empty($req['reason'])): ?>
                        <div class="ap-reason"><i class="fa-solid fa-quote-left"></i><span><?= gdn_esc($req['reason']) ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="ap-actions">
                    <button class="btn btn-primary btn-sm" onclick="approveRequest(<?= (int) $req['id'] ?>)"><i class="fa-solid fa-check"></i> Approve</button>
                    <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?= (int) $req['id'] ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="panel gdn-panel">
            <div class="gdn-toolbar">
                <div class="gdn-toolbar-title">
                    <i class="fa-solid fa-address-book"></i> All students
                    <span class="gdn-pill"><?= count($byStudent) ?></span>
                </div>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="guardSearch" placeholder="Search by student name or number...">
                </div>
            </div>

            <div class="table-responsive" style="overflow-x:auto;">
            <table class="ct-table">
                <thead><tr><th>Student</th><th>Guardians</th><th>Emergency Contacts</th><th>Email Recipients</th><th style="text-align:center;">Actions</th></tr></thead>
                <tbody id="guardBody">
                <?php if (empty($byStudent)): ?>
                    <tr class="empty-state-row"><td colspan="5"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:300px;gap:8px;"><p style="font-size:16px;font-weight:600;color:#334155;margin:0;">No students found yet</p><p style="margin:0;color:#94a3b8;">Add students first to manage their contacts</p></div></td></tr>
                <?php else: foreach ($byStudent as $s): ?>
                    <tr data-id="<?= (int)$s['student_id'] ?>"
                        data-search="<?= htmlspecialchars(strtolower($s['student_name'].' '.$s['student_number']), ENT_QUOTES) ?>">
                        <td>
                            <div class="ct-who">
                                <span class="ct-avatar<?= empty($s['guardians']) ? ' none' : '' ?>"><?= gdn_initials($s['student_name']) ?></span>
                                <div>
                                    <div class="ct-name"><?= htmlspecialchars($s['student_name']) ?></div>
                                    <div class="ct-rel"><?= htmlspecialchars($s['student_number']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if (empty($s['guardians'])): ?>
                                <span class="ct-none">No guardian recorded</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($s['guardians'] as $g): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-people-roof"></i>
                                        <div>
                                            <div class="ct-contact-name">
                                                <?= htmlspecialchars($g['full_name']) ?>
                                                <span class="ct-tag t-blue"><?= htmlspecialchars(ucfirst((string) $g['relationship'])) ?></span>
                                                <?php if ($g['is_primary']): ?><span class="ct-tag t-green">Primary</span><?php endif; ?>
                                                <?php if ($g['is_emergency']): ?><span class="ct-tag t-red">Emergency</span><?php endif; ?>
                                            </div>
                                            <div class="ct-contact-sub">
                                                <?php if (trim((string) ($g['contact_number'] ?? '')) !== ''): ?>
                                                    <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars((string) $g['contact_number']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($g['email'])): ?>
                                                    <span><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars((string) $g['email']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $emgList = $emgByStudent[$s['student_id']] ?? []; ?>
                            <?php if (!$emgList): ?>
                                <span class="ct-none">—</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($emgList as $e): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-truck-medical"></i>
                                        <div>
                                            <div class="ct-contact-name"><?= htmlspecialchars((string) $e['full_name']) ?></div>
                                            <div class="ct-contact-sub">
                                                <?php if (trim((string) ($e['relationship'] ?? '')) !== ''): ?>
                                                    <span><?= htmlspecialchars((string) $e['relationship']) ?></span>
                                                <?php endif; ?>
                                                <?php if (trim((string) ($e['contact_number'] ?? '')) !== ''): ?>
                                                    <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars((string) $e['contact_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $cList = $contactByStudent[$s['student_id']] ?? []; ?>
                            <?php if (!$cList): ?>
                                <span class="ct-none">—</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($cList as $c): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-envelope-open-text"></i>
                                        <div>
                                            <div class="ct-contact-name">
                                                <?= htmlspecialchars((string) $c['full_name']) ?>
                                                <?php if ((int) ($c['verified'] ?? 0) === 1): ?><span class="ct-vdot" title="Verified"></span><?php endif; ?>
                                            </div>
                                            <div class="ct-contact-sub">
                                                <span><i class="fa-solid fa-at"></i> <?= htmlspecialchars((string) $c['email']) ?></span>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td><div class="action-group">
                            <button class="action-btn edit" onclick="openManage(<?= (int)$s['student_id'] ?>,'<?= htmlspecialchars($s['student_name'], ENT_QUOTES) ?>')" title="Manage"><i class="fas fa-user-pen"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <div class="table-footer">
                <div class="info-text">Showing <strong id="shownCount"><?= count($byStudent) ?></strong> students</div>
            </div>
        </div>
    </div>
</main>

<!-- Manage Contacts Modal -->
<div class="modal-overlay" id="manageModal"><div class="modal-content wide">
    <div class="modal-header mg-header">
        <h2><i class="fa-solid fa-address-book"></i> <span id="mgTitle">Manage Contacts</span></h2>
        <button class="modal-close" onclick="closeModal('manageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <!-- Student picker shown when opened from header button -->
        <div id="mgPickerWrap" style="display:none;" class="form-group">
            <label>Student</label>
            <select id="mgPicker" class="form-control" data-searchable>
                <option value="">Select a student...</option>
                <?php foreach ($students as $st): ?>
                    <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'].' — '.$st['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" id="mgStudentId">

        <div class="mg-tabs">
            <button type="button" class="mg-tab active" data-tab="tab-guardians" onclick="switchTab('tab-guardians', this)"><i class="fa-solid fa-people-roof"></i> Guardians <span class="mg-count" id="mgGCount">0</span></button>
            <button type="button" class="mg-tab" data-tab="tab-emergency" onclick="switchTab('tab-emergency', this)"><i class="fa-solid fa-truck-medical"></i> Emergency <span class="mg-count" id="mgECount">0</span></button>
            <button type="button" class="mg-tab" data-tab="tab-email" onclick="switchTab('tab-email', this)"><i class="fa-solid fa-envelope-circle-check"></i> Email Recipients <span class="mg-count" id="mgCCount">0</span></button>
        </div>

        <div class="mg-tabpane active" id="tab-guardians">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Guardians / parents on file for this student.</span>
                <button class="btn btn-light btn-sm" onclick="pullEnrollment()" title="Create guardian rows from the student's enrollment record (father / mother name)."><i class="fa-solid fa-cloud-arrow-down"></i> Auto-fill from Enrollment</button>
            </div>
            <div id="mgGuardians"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addGuardianRow()"><i class="fas fa-plus"></i> Add Guardian</button>
        </div>

        <div class="mg-tabpane" id="tab-emergency">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Point-of-contact for health &amp; safety emergencies.</span>
            </div>
            <div id="mgEmergency"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addEmergencyRow()"><i class="fas fa-plus"></i> Add Emergency Contact</button>
        </div>

        <div class="mg-tabpane" id="tab-email">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Verified email recipients — invoices, grade snapshots, transcripts, alerts.</span>
            </div>
            <div id="mgEmail"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addEmailRow()"><i class="fas fa-plus"></i> Add Email Recipient</button>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" onclick="closeModal('manageModal')">Cancel</button>
        <button class="btn btn-primary" onclick="saveAll()"><i class="fas fa-save"></i> Save Contacts</button>
    </div>
</div></div>

<style>
/* ── Overview stats ─────────────────────────────── */
.gdn-stats {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:16px;
    margin-bottom:24px;
}
.gdn-card {
    background:#fff; border:1px solid #e8ecf3; border-radius:16px; padding:18px 20px;
    display:flex; align-items:center; gap:14px;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
    position:relative; overflow:hidden;
    transition:all .25s cubic-bezier(.16,1,.3,1);
}
.gdn-card::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1a3a8c,#2563eb); opacity:0; transition:opacity .2s ease;
}
.gdn-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(26,58,140,.12); border-color:#d4dce6; }
.gdn-card:hover::before { opacity:1; }
.gdn-card.clickable { cursor:pointer; }
.gdn-ico {
    width:46px; height:46px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:18px;
    box-shadow:0 4px 12px rgba(15,23,42,.08);
}
.gdn-blue   { background:#eff6ff; color:#2563eb; }
.gdn-teal   { background:#ccfbf1; color:#0d9488; }
.gdn-purple { background:#f3e8ff; color:#7c3aed; }
.gdn-amber  { background:#ffedd5; color:#ea580c; }
.gdn-red    { background:#fee2e2; color:#dc2626; }
.gdn-val { font-size:24px; font-weight:800; color:#0d1b2e; letter-spacing:-.6px; line-height:1.1; }
.gdn-lbl { font-size:11px; color:#64748b; margin-top:4px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; }

/* ── List panel ─────────────────────────────────── */
.gdn-panel { padding:0; overflow:hidden; }
.gdn-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    gap:16px; flex-wrap:wrap; padding:18px 24px;
    border-bottom:1px solid #e8ecf3;
}
.gdn-toolbar-title { display:flex; align-items:center; gap:10px; font-size:15px; font-weight:800; color:#0d1b2e; letter-spacing:-.3px; }
.gdn-toolbar-title i { color:#2563eb; }
.gdn-pill {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:26px; height:22px; padding:0 8px; border-radius:999px;
    background:#eef4ff; color:#2563eb; font-size:12px; font-weight:800;
}
.gdn-pill.amber { background:#ffedd5; color:#ea580c; }
.gdn-toolbar .search-wrap { position:relative; flex:1 1 320px; min-width:220px; max-width:420px; margin-left:auto; }
.gdn-toolbar .search-wrap i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px; pointer-events:none; }
.gdn-toolbar .search-wrap input {
    width:100%; height:42px; padding:0 14px 0 38px;
    border:1.5px solid #e2e8f0; border-radius:10px;
    font-size:14px; font-family:inherit; outline:none; background:#fff; color:#1e293b;
    box-sizing:border-box; transition:all .2s cubic-bezier(.16,1,.3,1);
}
.gdn-toolbar .search-wrap input:focus { border-color:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.1); background:#fff; }

/* ── Student avatar tile ────────────────────────── */
.gdn-avatar {
    width:38px; height:38px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:13px;
    background:linear-gradient(135deg,#1a3a8c,#2563eb);
    box-shadow:0 4px 10px rgba(26,58,140,.25);
}
.gdn-avatar.none { background:#e2e8f0; color:#94a3b8; box-shadow:none; }

/* ── Organized contacts table (ct-* — mirrors student portal) ── */
.ct-table{width:100%;border-collapse:collapse;background:#fff;margin:0;table-layout:fixed;min-width:820px;}
.ct-table th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#fbfcfe;padding:13px 18px;text-align:left;border-bottom:1px solid #eef2f7;font-weight:800;}
.ct-table td{padding:14px 18px;border-top:1px solid #eef2f7;color:#334155;font-size:13px;vertical-align:top;}
.ct-table td:last-child{vertical-align:middle;text-align:center;}
.ct-table tbody tr{transition:background .15s ease;}
.ct-table tbody tr:hover{background:#f8fafc;}
.ct-table thead th:nth-child(1){width:23%}
.ct-table thead th:nth-child(2){width:28%}
.ct-table thead th:nth-child(3){width:16%}
.ct-table thead th:nth-child(4){width:21%}
.ct-table thead th:nth-child(5){width:12%;text-align:center;}
.ct-who{display:flex;align-items:center;gap:12px;}
.ct-avatar{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;background:linear-gradient(135deg,#1a3a8c,#2563eb);color:#fff;}
.ct-avatar.none{background:#e2e8f0;color:#94a3b8;}
.ct-name{font-weight:800;color:#0f172a;font-size:14px;}
.ct-rel{font-size:12px;color:#64748b;margin-top:2px;}
.ct-details{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;}
.ct-contact{display:flex;align-items:flex-start;gap:9px;padding:7px 0;}
.ct-contact + .ct-contact{border-top:1px solid #f1f5f9;}
.ct-contact > i{width:16px;color:#94a3b8;font-size:12px;flex-shrink:0;margin-top:2px;}
.ct-contact-name{font-weight:700;color:#0f172a;font-size:12.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;line-height:1.35;}
.ct-contact-sub{font-size:11px;color:#64748b;margin-top:2px;display:flex;gap:10px;flex-wrap:wrap;align-items:baseline;line-height:1.5;}
.ct-contact-sub i{color:#94a3b8;font-size:10px;}
.ct-vdot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);display:inline-block;flex-shrink:0;}
.ct-none{font-size:12px;color:#94a3b8;font-style:italic;}
.ct-tag{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.2px;white-space:nowrap;}
.ct-tag.t-green{background:#dcfce7;color:#15803d;}
.ct-tag.t-red{background:#fee2e2;color:#dc2626;}
.ct-tag.t-purple{background:#f3e8ff;color:#7c3aed;}
.ct-tag.t-blue{background:#eef4ff;color:#2563eb;}

/* ── Approvals queue ─────────────────────────────── */
.ap-row { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:16px 24px; border-bottom:1px solid #f1f5f9; }
.ap-row:last-child { border-bottom:none; }
.ap-main { flex:1; min-width:0; }
.ap-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ap-student { font-weight:800; color:#0d1b2e; font-size:14px; }
.ap-student small { font-weight:600; color:#94a3b8; margin-left:4px; font-size:11.5px; }
.ap-time { margin-left:auto; font-size:11.5px; color:#94a3b8; font-weight:600; }
.ap-body { margin-top:6px; font-size:13px; color:#334155; }
.ap-detail { color:#64748b; }
.ap-reason {
    margin-top:8px; font-size:12.5px; color:#92400e; background:#fffbeb;
    border:1px solid #fde68a; border-radius:8px; padding:6px 10px;
    display:inline-flex; gap:8px; align-items:flex-start;
}
.ap-reason i { color:#d97706; margin-top:2px; }
.ap-actions { display:flex; gap:8px; flex-shrink:0; }

/* ── "+N more" collapse chip ─────────────────────── */
.gdn-more {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 6px; padding: 4px 10px;
    border: 1px dashed #cbd5e1; border-radius: 999px;
    background: #fff; color: #2563eb;
    font-size: 11.5px; font-weight: 700; font-family: inherit;
    cursor: pointer; transition: all .15s ease;
}
.gdn-more:hover { background: #eff6ff; border-color: #93c5fd; }

/* ── Modal header (gradient, scoped to #manageModal) ── */
#manageModal .modal-header.mg-header {
    background:linear-gradient(135deg,#1a3a8c 0%, #2563eb 100%);
    margin:-28px -32px 0; padding:22px 28px;
    border-radius:20px 20px 0 0; color:#fff;
}
#manageModal .modal-header.mg-header h2 { color:#fff; font-size:18px; }
#manageModal .modal-header.mg-header h2 i { color:#93c5fd; }
#manageModal .modal-close { background:rgba(255,255,255,.16); color:#fff; }
#manageModal .modal-close:hover { background:rgba(255,255,255,.3); color:#fff; }
#manageModal .modal-body { padding:22px 0 4px; }

/* ── Tabs ────────────────────────────────────────── */
.mg-tabs {
    display:flex; gap:4px; flex-wrap:wrap;
    border-bottom:2px solid #f1f5f9; margin-bottom:16px; padding:0 2px;
}
.mg-tab {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 14px; border:none; background:transparent; cursor:pointer;
    border-bottom:2px solid transparent; margin-bottom:-2px;
    font-size:13px; font-weight:700; color:#64748b; font-family:inherit;
    border-radius:10px 10px 0 0; transition:all .18s ease;
}
.mg-tab:hover { color:#1d4ed8; background:#f8fafc; }
.mg-tab.active { color:#1d4ed8; border-bottom-color:#2563eb; background:#eff6ff; }
.mg-tabpane { display:none; }
.mg-tabpane.active { display:block; animation:fadein .2s ease; }
@keyframes fadein { from{opacity:0; transform:translateY(4px)} to{opacity:1; transform:none} }
.mg-pane-toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.mg-hint { font-size:12px; color:#94a3b8; }

/* ── Modal sections ──────────────────────────────── */
.mg-section { margin-bottom:20px; }
.mg-section-hd {
    display:flex; align-items:center; gap:8px;
    font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.6px;
    color:#64748b; margin-bottom:10px;
}
.mg-section-hd i { color:#2563eb; font-size:13px; }
.mg-count {
    min-width:22px; height:18px; padding:0 6px; border-radius:999px;
    background:#eef4ff; color:#2563eb; font-size:11px; font-weight:800;
    display:inline-flex; align-items:center; justify-content:center;
}
.mg-empty { color:#94a3b8; font-size:12.5px; padding:8px 2px 2px; }

/* ── Modal contact rows ──────────────────────────── */
.mg-row {
    border:1.5px solid #e8ecf3; border-radius:12px;
    padding:12px 14px; margin-bottom:10px; background:#f8fafc;
    transition:border-color .2s ease;
}
.mg-row:hover { border-color:#d4dce6; }
.mg-row-grid { display:grid; grid-template-columns:2fr 1fr 1fr; gap:10px; margin-bottom:10px; }
.mg-row-grid-e { grid-template-columns:2fr 1fr 1fr auto; align-items:center; margin-bottom:0; }
.mg-row-sub { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center; }
.mg-toggles { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Email recipient row — flex so the badges/actions wrap on narrow modals */
.c-row .mg-row-sub { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.c-actions { display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.c-actions .btn-sm { padding:6px 9px; font-size:11.5px; }
.mg-verified, .mg-unverified {
    display:inline-flex; align-items:center; gap:5px;
    font-size:10.5px; font-weight:800; padding:4px 8px; border-radius:999px; letter-spacing:.3px;
}
.mg-verified   { background:#dcfce7; color:#15803d; }
.mg-unverified { background:#fef3c7; color:#b45309; }

/* Pill toggles (Primary / Emergency / permissions) */
.mg-toggle { display:inline-flex; align-items:center; cursor:pointer; position:relative; }
.mg-toggle input { position:absolute; opacity:0; pointer-events:none; }
.mg-toggle span {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 12px; border-radius:999px;
    background:#eef2f7; border:1.5px solid #e2e8f0; color:#64748b;
    font-size:11.5px; font-weight:700; user-select:none;
    transition:all .2s cubic-bezier(.16,1,.3,1);
}
.mg-toggle span i { font-size:11px; }
.mg-toggle input:checked + span {
    background:linear-gradient(135deg,#1a3a8c 0%, #2563eb 100%);
    border-color:transparent; color:#fff;
    box-shadow:0 4px 10px rgba(26,58,140,.28);
}
.mg-add { margin-top:2px; }

@media (max-width:1200px) { .gdn-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width:640px)  {
    .gdn-stats { grid-template-columns:1fr; }
    .gdn-toolbar { flex-direction:column; align-items:stretch; }
    .gdn-toolbar .search-wrap { max-width:none; margin-left:0; }
    .mg-row-grid, .mg-row-sub, .mg-row-grid-e { grid-template-columns:1fr; }
    .ap-row { flex-direction:column; }
    .ap-actions { width:100%; justify-content:flex-end; }
}
</style>

<script>
// ─── DATA INJECTED FROM PHP ─────────────────────────────────
const ALL_STUDENTS = <?= json_encode(array_values(array_map(fn($s) => ['id'=>$s['id'],'label'=>$s['student_number'].' — '.$s['name']], $students))) ?>;
const GUARD_DATA = <?= json_encode($guardRowsByStudent) ?>;
const CONTACT_DATA = <?= json_encode($contactRowsByStudent) ?>;

// ─── MODAL + SEARCH ─────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow=''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow='hidden'; }
document.getElementById('manageModal').addEventListener('click', e => { if (e.target === document.getElementById('manageModal')) closeModal('manageModal'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal('manageModal'); });

const guardRows = Array.from(document.querySelectorAll('#guardBody tr[data-search]'));
document.getElementById('guardSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    let shown = 0;
    guardRows.forEach(r => {
        const ok = !q || r.dataset.search.includes(q);
        r.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    document.getElementById('shownCount').textContent = shown;
});
// ─── TABLE: collapse long contact lists ("+N more") ─────────
const GDN_CARD_SEL = '.ct-contact';
const GDN_SHOWN = 2;   // cards visible before collapsing

function gdnCollapseCell(cell) {
    // Reset any previous collapse first (idempotent re-runs)
    cell.querySelectorAll('.gdn-more').forEach(b => b.remove());
    cell.querySelectorAll(GDN_CARD_SEL).forEach(c => c.style.display = '');

    const cards = Array.from(cell.querySelectorAll(GDN_CARD_SEL));
    if (cards.length <= GDN_SHOWN) return;

    cards.slice(GDN_SHOWN).forEach(c => c.style.display = 'none');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gdn-more';
    btn.textContent = '+' + (cards.length - GDN_SHOWN) + ' more';
    btn.addEventListener('click', () => {
        const hidden = cards.slice(GDN_SHOWN).every(c => c.style.display === 'none');
        cards.slice(GDN_SHOWN).forEach(c => c.style.display = hidden ? '' : 'none');
        btn.textContent = hidden ? 'Show less' : '+' + (cards.length - GDN_SHOWN) + ' more';
    });
    cell.appendChild(btn);
}

function gdnCollapseAll() {
    document.querySelectorAll('#guardBody tr').forEach(tr => {
        // Columns 2–4: Guardians, Emergency, Email (0-based index 1..3)
        [1, 2, 3].forEach(i => {
            const cell = tr.cells[i];
            if (cell) gdnCollapseCell(cell);
        });
    });
}
gdnCollapseAll();


// ─── HELPERS ────────────────────────────────────────────────
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function relOptions(sel){
    const rels=['father','mother','guardian','spouse','sibling'];
    return '<option value="">Relationship</option>'+rels.map(r=>'<option value="'+r+'"'+(r===sel?' selected':'')+'>'+r.charAt(0).toUpperCase()+r.slice(1)+'</option>').join('');
}
function refreshCounts() {
    const set = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n; };
    set('mgGCount', document.querySelectorAll('#mgGuardians .g-row').length);
    set('mgECount', document.querySelectorAll('#mgEmergency .e-row').length);
    set('mgCCount', document.querySelectorAll('#mgEmail .c-row').length);
}
function switchTab(tabId, btn) {
    document.querySelectorAll('.mg-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.mg-tabpane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

// ─── OPEN MANAGE ────────────────────────────────────────────
let currentStudentId = null;
function openManage(id, name) {
    if (id) {
        currentStudentId = id;
        document.getElementById('mgTitle').textContent = (name || 'Student') + ' — Manage Contacts';
        document.getElementById('mgPickerWrap').style.display = 'none';
        document.getElementById('mgStudentId').value = id;
        loadStudentContacts(id);
        openModal('manageModal');
    } else {
        currentStudentId = null;
        document.getElementById('mgTitle').textContent = 'Manage Contacts';
        document.getElementById('mgPickerWrap').style.display = '';
        document.getElementById('mgPicker').value = '';
        document.getElementById('mgGuardians').innerHTML = '';
        document.getElementById('mgEmergency').innerHTML = '';
        document.getElementById('mgEmail').innerHTML = '';
        refreshCounts();
        openModal('manageModal');
    }
}
document.getElementById('mgPicker') && document.getElementById('mgPicker').addEventListener('change', function(e) {
    if (e.target.value) {
        currentStudentId = e.target.value;
        loadStudentContacts(e.target.value);
    } else {
        currentStudentId = null;
        document.getElementById('mgGuardians').innerHTML = '';
        document.getElementById('mgEmergency').innerHTML = '';
        document.getElementById('mgEmail').innerHTML = '';
        refreshCounts();
    }
});

function loadStudentContacts(id) {
    loadGuardians(id);
    loadEmergency(id);
    loadEmail(id);
}
function loadGuardians(id) {
    const list = (GUARD_DATA[id] || []);
    document.getElementById('mgGuardians').innerHTML = list.length
        ? list.map(g => guardianRow(g)).join('')
        : '<div id="mgG-empty" class="mg-empty">No guardians recorded.</div>';
    refreshCounts();
}
function loadEmergency(id) {
    fetch('../api/students.php?action=emergency&student_id=' + id)
    .then(r => r.json()).then(d => {
        const list = (d.success && d.data) ? d.data : [];
        document.getElementById('mgEmergency').innerHTML = list.length
            ? list.map(e => emergencyRow(e)).join('')
            : '<div id="mgE-empty" class="mg-empty">No emergency contacts.</div>';
        refreshCounts();
    }).catch(() => {
        document.getElementById('mgEmergency').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:6px 0;">Error loading contacts.</div>';
        refreshCounts();
    });
}
function loadEmail(id) {
    const list = (CONTACT_DATA[id] || []);
    document.getElementById('mgEmail').innerHTML = list.length
        ? list.map(c => emailRow(c)).join('')
        : '<div id="mgC-empty" class="mg-empty">No email recipients on file.</div>';
    refreshCounts();
}

// ─── ROW BUILDERS ───────────────────────────────────────────
let gSeq=1000, eSeq=1000, cSeq=2000;
function guardianRow(g) {
    gSeq++;
    const gid = (g.id || 0);
    return '<div class="g-row mg-row" data-gid="' + gid + '" data-key="' + gSeq + '">'
        + '<div class="mg-row-grid">'
        + '<input class="form-control gi-name" placeholder="Full name *" value="' + esc(g.full_name) + '">'
        + '<select class="form-control gi-rel">' + relOptions(g.relationship) + '</select>'
        + '<input class="form-control gi-contact" placeholder="Contact no." value="' + esc(g.contact_number) + '"></div>'
        + '<div class="mg-row-sub">'
        + '<input class="form-control gi-email" placeholder="Email" value="' + esc(g.email) + '">'
        + '<div class="mg-toggles">'
        + '<label class="mg-toggle"><input type="checkbox" class="gi-primary" ' + (g.is_primary?'checked':'') + '><span><i class="fa-solid fa-star"></i> Primary</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="gi-emergency" ' + (g.is_emergency?'checked':'') + '><span><i class="fa-solid fa-bell"></i> Emergency</span></label>'
        + '</div></div></div>';
}
function emergencyRow(e) {
    eSeq++;
    const eid = (e.id || 0);
    return '<div class="e-row mg-row" data-eid="' + eid + '" data-ekey="' + eSeq + '">'
        + '<div class="mg-row-grid mg-row-grid-e">'
        + '<input class="form-control ei-name" placeholder="Full name *" value="' + esc(e.full_name) + '">'
        + '<input class="form-control ei-rel" placeholder="Relationship" value="' + esc(e.relationship) + '">'
        + '<input class="form-control ei-contact" placeholder="Contact no." value="' + esc(e.contact_number) + '">'
        + '</div></div>';
}
function emailRow(c) {
    cSeq++;
    const cid = (c.id || 0);
    const verified = Number(c.verified) === 1;
    const badge = verified
        ? '<span class="mg-verified"><i class="fa-solid fa-check-circle"></i> Verified</span>'
        : '<span class="mg-unverified"><i class="fa-solid fa-clock"></i> Awaiting</span>';
    return '<div class="c-row mg-row" data-cid="' + cid + '" data-ckey="' + cSeq + '">'
        + '<div class="mg-row-grid">'
        + '<input class="form-control ci-name" placeholder="Full name *" value="' + esc(c.full_name) + '">'
        + '<input class="form-control ci-email" placeholder="Email *" value="' + esc(c.email) + '">'
        + '<input class="form-control ci-phone" placeholder="Phone" value="' + esc(c.phone) + '"></div>'
        + '<div class="mg-row-sub">'
        + '<div class="mg-toggles">'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-billing" ' + (Number(c.send_billing)===1?'checked':'') + '><span><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-grades" ' + (Number(c.send_grades)===1?'checked':'') + '><span><i class="fa-solid fa-chart-simple"></i> Grades</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-emg" ' + (Number(c.send_emergency)===1?'checked':'') + '><span><i class="fa-solid fa-triangle-exclamation"></i> Alerts</span></label>'
        + '</div>'
        + '<div class="c-actions">'
        + badge
        + (verified
            ? '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'snapshot\')" title="Email grade snapshot"><i class="fa-solid fa-chart-simple"></i></button>'
              + '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'transcript\')" title="Email transcript"><i class="fa-solid fa-file-lines"></i></button>'
            : '')
        + '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'test\')" title="Send Test Email"><i class="fa-solid fa-envelope-circle-check"></i></button>'
        + '</div></div></div>';
}

// ─── ADD / REMOVE ROWS ──────────────────────────────────────
// NOTE: Delete/remove of guardians, emergency contacts, and email
// recipients is intentionally NOT available in this UI — it is reserved
// for the college system. Staff can add and edit, but cannot remove a
// contact record. (Backend delete endpoints remain for admin use.)

function addGuardianRow() {
    const empty = document.getElementById('mgG-empty'); if (empty) empty.remove();
    document.getElementById('mgGuardians').insertAdjacentHTML('beforeend', guardianRow({}));
    refreshCounts();
}
function addEmergencyRow() {
    const empty = document.getElementById('mgE-empty'); if (empty) empty.remove();
    document.getElementById('mgEmergency').insertAdjacentHTML('beforeend', emergencyRow({}));
    refreshCounts();
}
function addEmailRow() {
    const empty = document.getElementById('mgC-empty'); if (empty) empty.remove();
    document.getElementById('mgEmail').insertAdjacentHTML('beforeend', emailRow({}));
    refreshCounts();
}

// ─── AUTO-FILL FROM ENROLLMENT ──────────────────────────────
async function pullEnrollment() {
    if (!currentStudentId) { alert('Select a student first.'); return; }
    const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'pull_enrollment', student_id: currentStudentId }) }).then(r => r.json());
    showToast(d.message, d.success ? 'success' : 'error');
    if (!d.success) return;
    // Refetch the authoritative list — GUARD_DATA is a page-load snapshot and
    // does not include the rows just created server-side.
    const g = await fetch('../api/students.php?action=guardians&student_id=' + currentStudentId).then(r => r.json());
    document.getElementById('mgGuardians').innerHTML = (g.success && g.data && g.data.length)
        ? g.data.map(x => guardianRow(x)).join('')
        : '<div id="mgG-empty" class="mg-empty">No guardians recorded.</div>';
    refreshCounts();
}

// ─── PER-ROW EMAIL ACTIONS (Test / Snapshot / Transcript) ───
async function sendContactAction(btn, kind) {
    const row = btn.closest('.c-row');
    const id = parseInt(row ? row.dataset.cid : '0', 10) || 0;
    if (!id) { showToast('Save the contact before sending email.', 'error'); return; }
    const orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const payload = { action: kind === 'test' ? 'test_email' : 'email_contact', id: id, student_id: currentStudentId };
    if (kind !== 'test') payload.kind = kind;
    try {
        const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
        showToast(d.message, d.success ? 'success' : 'error');
    } catch (err) { showToast('Network error. Please try again.', 'error'); }
    btn.disabled = false; btn.innerHTML = orig;
}

// ─── APPROVAL QUEUE ─────────────────────────────────────────
async function approveRequest(id) {
    if (!confirm('Approve and apply this change request?')) return;
    const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'approve_change', id: id }) }).then(r => r.json());
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) setTimeout(() => window.location.reload(), 600);
}
async function rejectRequest(id) {
    const note = prompt('Reason for rejection (shown to the student):', '');
    if (note === null) return;
    const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'reject_change', id: id, note: note }) }).then(r => r.json());
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) setTimeout(() => window.location.reload(), 600);
}

// ─── SAVE ALL ───────────────────────────────────────────────
async function saveAll() {
    if (!currentStudentId) { alert('Select a student first.'); return; }
    const btn = document.querySelector('#manageModal .modal-footer .btn-primary');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const api = (url, payload) => fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
    const seqStudents = async (action, payload) => { const d = await api('../api/students.php?action=' + action, payload); if (!d.success) throw new Error(d.message || 'Save failed'); return d; };
    const seqContacts = async (payload) => { const d = await api('../api/contacts.php', payload); if (!d.success) throw new Error(d.message || 'Save failed'); return d; };

    try {
        // Deletion is intentionally not available in this UI (reserved for
        // the college system) — save new/updated rows only.

        // NOW save guardians (only process rows that have at least a name)
        const gRows = document.querySelectorAll('.g-row');
        for (const row of gRows) {
            const fullName = row.querySelector('.gi-name').value.trim();
            if (!fullName) continue; // Skip empty rows

            const res = await seqStudents('save-guardian', {
                id: parseInt(row.dataset.gid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                relationship: row.querySelector('.gi-rel').value,
                contact_number: row.querySelector('.gi-contact').value,
                email: row.querySelector('.gi-email').value,
                is_primary: row.querySelector('.gi-primary').checked ? 1 : 0,
                is_emergency: row.querySelector('.gi-emergency').checked ? 1 : 0
            });
            // Remember the real DB id so a re-save updates instead of cloning.
            if (res && res.data && res.data.id) row.dataset.gid = res.data.id;
        }

        // NOW save emergency contacts (only process rows that have at least a name)
        const eRows = document.querySelectorAll('.e-row');
        for (const row of eRows) {
            const fullName = row.querySelector('.ei-name').value.trim();
            if (!fullName) continue; // Skip empty rows

            await seqStudents('save-emergency', {
                id: parseInt(row.dataset.eid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                relationship: row.querySelector('.ei-rel').value,
                contact_number: row.querySelector('.ei-contact').value
            });
        }

        // NOW save email recipients (only process rows that have at least name and email)
        const cRows = document.querySelectorAll('.c-row');
        for (const row of cRows) {
            const fullName = row.querySelector('.ci-name').value.trim();
            const email = row.querySelector('.ci-email').value.trim();
            if (!fullName || !email) continue; // Skip incomplete rows

            await seqContacts({
                action: 'save',
                id: parseInt(row.dataset.cid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                email: email,
                phone: row.querySelector('.ci-phone').value,
                send_billing: row.querySelector('.ci-billing').checked ? 1 : 0,
                send_grades: row.querySelector('.ci-grades').checked ? 1 : 0,
                send_emergency: row.querySelector('.ci-emg').checked ? 1 : 0
            });
        }

        showToast('Contacts updated.', 'success');
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        alert(err.message || 'Error saving contacts.');
        // Reconcile to server truth: any earlier saves in this run may
        // have partially applied. A reload rebuilds the lists from the
        // authoritative data, so a removed row that wasn't deleted won't
        // silently vanish, and a saved row gets its real id (no clones
        // on the next save).
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Contacts';
        setTimeout(() => window.location.reload(), 1200);
    }
}

</script>

<?php include '../includes/footer.php'; ?>
