<?php
// ============================================================
//  STUDENT/CONTACTS.PHP
//  Emergency & Contacts — READ-ONLY student view.
//
//  The Office of the Registrar is the source of truth for a
//  student's contacts. This page shows exactly what is on file:
//    Panel 1  Guardians & Emergency Contacts   → guardians +
//              (on file)                         emergency_contacts
//    Panel 2  Email Recipients                 → contact_recipients
//              (verified badge + permissions)
//    Panel 3  My Change Requests               → contact_change_requests
//
//  A student may NOT add/edit/remove contacts or trigger emails
//  directly (api/contacts.php rejects those for the student role).
//  The only student write path is "Request a Change" — the proposed
//  change lands in contact_change_requests for the Registrar to
//  approve or reject from registrar/guardians.php.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Emergency &amp; Contacts';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_contacts';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';   // requireStudent() + renders header/sidebar, sets $student

$db = Database::getInstance();
$sid = (int) $student['id'];

$studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
if ($studentName === '') { $studentName = $student['full_name'] ?? 'Student'; }
$studentNum = $student['student_number'] ?? '';

$guardians = $db->fetchAll(
    'SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, is_emergency DESC, id ASC',
    [$sid]
);
$emergency = $db->fetchAll(
    'SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC',
    [$sid]
);
$contacts = [];
$requests = [];
try {
    $contacts = $db->fetchAll(
        'SELECT * FROM contact_recipients WHERE student_id = ? ORDER BY verified DESC, id DESC',
        [$sid]
    );
} catch (Throwable $e) {
    error_log('[student/contacts] contact_recipients query failed: ' . $e->getMessage());
}
try {
    $requests = $db->fetchAll(
        'SELECT * FROM contact_change_requests WHERE student_id = ? ORDER BY id DESC LIMIT 100',
        [$sid]
    );
} catch (Throwable $e) {
    error_log('[student/contacts] contact_change_requests query failed: ' . $e->getMessage());
}

// ── Overview counters ─────────────────────────────────────────
$statGuardians = count($guardians);
$statEmergency = count($emergency);
$statVerified  = 0;
$statPending   = 0;
foreach ($contacts as $c) {
    if ((int) ($c['verified'] ?? 0) === 1) { $statVerified++; }
}
foreach ($requests as $r) {
    if (($r['status'] ?? '') === 'pending') { $statPending++; }
}

$relLabel = [
    'parent'    => 'Parent',
    'guardian'  => 'Guardian',
    'sponsor'   => 'Sponsor',
    'emergency' => 'Emergency',
    'other'     => 'Other',
    'father'    => 'Father',
    'mother'    => 'Mother',
    'spouse'    => 'Spouse',
    'sibling'   => 'Sibling',
];

// ── Organized contact rows (single, unified table) ─────────────
$rows = [];
foreach ($guardians as $g) {
    $tags = [];
    if ((int) ($g['is_primary'] ?? 0) === 1)   { $tags[] = '<span class="ct-tag t-green"><i class="fa-solid fa-star"></i> Primary</span>'; }
    if ((int) ($g['is_emergency'] ?? 0) === 1) { $tags[] = '<span class="ct-tag t-red"><i class="fa-solid fa-bell"></i> Emergency</span>'; }
    $details = [];
    if (trim((string) ($g['contact_number'] ?? '')) !== '') { $details[] = '<li><i class="fa-solid fa-phone"></i> ' . esc($g['contact_number']) . '</li>'; }
    if (trim((string) ($g['email'] ?? '')) !== '')          { $details[] = '<li><i class="fa-solid fa-envelope"></i> ' . esc($g['email']) . '</li>'; }
    if (trim((string) ($g['address'] ?? '')) !== '')        { $details[] = '<li><i class="fa-solid fa-location-dot"></i> ' . esc($g['address']) . '</li>'; }
    $rows[] = [
        'type'    => 'guardian',
        'name'    => $g['full_name'] ?? '',
        'initial' => contactInitials($g['full_name'] ?? ''),
        'rel'     => $relLabel[$g['relationship'] ?? ''] ?? ucfirst((string) ($g['relationship'] ?? '')),
        'details' => $details ? implode('', $details) : '<li class="ct-empty">No phone / email recorded</li>',
        'tags'    => $tags ? implode('', $tags) : '<span class="ct-tag t-gray"><i class="fa-solid fa-check"></i> On file</span>',
        'ktag'    => 'Guardian',
    ];
}
foreach ($emergency as $e) {
    $tags = [];
    if ((int) ($e['is_primary'] ?? 0) === 1) { $tags[] = '<span class="ct-tag t-amber"><i class="fa-solid fa-star"></i> Primary</span>'; }
    $details = [];
    if (trim((string) ($e['relationship'] ?? '')) !== '')   $relE = $relLabel[$e['relationship']] ?? ucfirst((string) $e['relationship']);
    else $relE = 'Emergency';
    if (trim((string) ($e['contact_number'] ?? '')) !== '') { $details[] = '<li><i class="fa-solid fa-phone"></i> ' . esc($e['contact_number']) . '</li>'; }
    if (trim((string) ($e['address'] ?? '')) !== '')        { $details[] = '<li><i class="fa-solid fa-location-dot"></i> ' . esc($e['address']) . '</li>'; }
    $rows[] = [
        'type'    => 'emergency',
        'name'    => $e['full_name'] ?? '',
        'initial' => contactInitials($e['full_name'] ?? ''),
        'rel'     => $relE,
        'details' => $details ? implode('', $details) : '<li class="ct-empty">No phone recorded</li>',
        'tags'    => $tags ? implode('', $tags) : '<span class="ct-tag t-red"><i class="fa-solid fa-shield-halved"></i> Health &amp; safety</span>',
        'ktag'    => 'Emergency',
    ];
}
foreach ($contacts as $c) {
    $tags = [];
    if ((int) ($c['verified'] ?? 0) === 1) { $tags[] = '<span class="ct-tag t-green"><i class="fa-solid fa-circle-check"></i> Verified</span>'; }
    else { $tags[] = '<span class="ct-tag t-amber"><i class="fa-solid fa-clock"></i> Awaiting verification</span>'; }
    if ((int) ($c['send_billing'] ?? 0) === 1)   { $tags[] = '<span class="ct-tag t-blue"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</span>'; }
    if ((int) ($c['send_grades'] ?? 0) === 1)    { $tags[] = '<span class="ct-tag t-blue"><i class="fa-solid fa-chart-simple"></i> Grades</span>'; }
    if ((int) ($c['send_emergency'] ?? 0) === 1) { $tags[] = '<span class="ct-tag t-red"><i class="fa-solid fa-triangle-exclamation"></i> Alerts</span>'; }
    $details = ['<li><i class="fa-solid fa-envelope"></i> ' . esc($c['email'] ?? '') . '</li>'];
    if (trim((string) ($c['phone'] ?? '')) !== '') { $details[] = '<li><i class="fa-solid fa-phone"></i> ' . esc($c['phone']) . '</li>'; }
    $rows[] = [
        'type'    => 'email',
        'name'    => $c['full_name'] ?? '',
        'initial' => contactInitials($c['full_name'] ?? ''),
        'rel'     => $relLabel[$c['relationship'] ?? ''] ?? ucfirst((string) ($c['relationship'] ?? '')),
        'details' => implode('', $details),
        'tags'    => $tags ? implode('', $tags) : '<span class="ct-tag t-gray">None</span>',
        'ktag'    => 'Email',
    ];
}

function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function contactInitials(?string $name): string {
    $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) { return '?'; }
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (isset($parts[1]) && $parts[1] !== '') { $ini .= mb_strtoupper(mb_substr($parts[1], 0, 1)); }
    return $ini;
}

// ── Panel 3 rows (my change requests) ──────────────────────────
function reqSummary(array $r): string {
    $payload = json_decode((string) ($r['payload'] ?? '{}'), true);
    $payload = is_array($payload) ? $payload : [];
    $typeLabel = ['guardian' => 'Guardian', 'emergency' => 'Emergency contact', 'email' => 'Email recipient'][(string) ($r['contact_type'] ?? '')] ?? ucfirst((string) ($r['contact_type'] ?? ''));
    $actionLabel = ['add' => 'Add', 'update' => 'Update', 'remove' => 'Remove'][(string) ($r['request_type'] ?? '')] ?? (string) ($r['request_type'] ?? '');
    $name = trim((string) ($payload['full_name'] ?? ''));
    return $actionLabel . ' ' . $typeLabel . ($name !== '' ? ' — ' . $name : '');
}

function renderRequestRow(array $r): string {
    $s = (string) ($r['status'] ?? 'pending');
    if ($s === 'approved')   { $ico = 'fa-circle-check'; $color = 'green';  $label = 'Approved'; }
    elseif ($s === 'rejected') { $ico = 'fa-circle-xmark'; $color = 'red'; $label = 'Rejected'; }
    else { $ico = 'fa-clock'; $color = 'amber'; $label = 'Pending'; }

    $rk = (string) ($r['request_type'] ?? '');
    $actColor = ['add' => 'green', 'update' => 'blue', 'remove' => 'purple'][$rk] ?? 'gray';
    $actLabel = ['add' => 'Add', 'update' => 'Update', 'remove' => 'Remove'][$rk] ?? $rk;

    $html = '<div class="ap-row">'
        . '<div class="ap-top">'
        . '<span class="chip blue">' . esc(ucfirst((string) ($r['contact_type'] ?? ''))) . '</span>'
        . '<span class="chip ' . $actColor . '">' . esc($actLabel) . '</span>'
        . '<span class="ap-status ' . $color . '"><i class="fa-solid ' . $ico . '"></i> ' . $label . '</span>'
        . '<span class="ap-time">' . esc(date('M j, Y g:i A', strtotime((string) $r['created_at']))) . '</span>'
        . '</div>'
        . '<div class="ap-summary">' . esc(reqSummary($r)) . '</div>';

    $reason = trim((string) ($r['reason'] ?? ''));
    if ($reason !== '') {
        $html .= '<div class="ap-why"><i class="fa-solid fa-quote-left"></i><span>Reason: ' . esc($reason) . '</span></div>';
    }
    $note = trim((string) ($r['review_note'] ?? ''));
    if ($note !== '') {
        $html .= '<div class="ap-why ap-office"><i class="fa-solid fa-file-pen"></i><span><b>Office of the Registrar:</b> ' . esc($note) . '</span></div>';
    }
    $html .= '</div>';
    return $html;
}
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title">
                <h1>Emergency &amp; Contacts</h1>
                <p>Your guardians, emergency contacts, and email recipients as recorded by the Office of the Registrar.</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openReqModal()"><i class="fa-solid fa-file-pen"></i> Request a Change</button>
            </div>
        </header>

        <!-- Overview stat cards styling lives in css/student.css (gdn-*) -->

        <!-- ── Overview stats ─────────────────────────────────── -->
        <div class="gdn-stats">
            <div class="gdn-card">
                <div class="gdn-ico gdn-teal"><i class="fa-solid fa-people-roof"></i></div>
                <div><div class="gdn-val"><?= $statGuardians ?></div><div class="gdn-lbl">Guardians on file</div></div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-purple"><i class="fa-solid fa-truck-medical"></i></div>
                <div><div class="gdn-val"><?= $statEmergency ?></div><div class="gdn-lbl">Emergency contacts</div></div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-blue"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <div><div class="gdn-val"><?= $statVerified ?></div><div class="gdn-lbl">Verified emails</div></div>
            </div>
            <div class="gdn-card">
                <div class="gdn-ico gdn-amber"><i class="fa-solid fa-file-circle-check"></i></div>
                <div><div class="gdn-val"><?= $statPending ?></div><div class="gdn-lbl">Pending requests</div></div>
            </div>
        </div>

        <!-- ════ My Contacts (organized record) ════ -->
        <!-- Contacts table styling lives in css/student.css (ct-*) -->

        <div class="panel gdn-panel">
            <div class="gdn-toolbar">
                <div class="gdn-toolbar-title">
                    <i class="fa-solid fa-address-book"></i> My contacts
                    <span class="gdn-pill"><?= count($rows) ?></span>
                </div>
                <span class="gdn-toolbar-sub">Read-only record maintained by the Office of the Registrar.</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="ct-table">
                    <thead>
                        <tr>
                            <th style="width:150px;">Type</th>
                            <th>Name &amp; Relationship</th>
                            <th>Contact Details</th>
                            <th>Notations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="4" class="ct-empty" style="text-align:center;padding:36px 20px;font-style:normal;">
                                    <i class="fa-solid fa-user-slash" style="font-size:26px;color:#94a3b8;display:block;margin-bottom:8px;"></i>
                                    No contacts on file yet. Submit a <b>Request a Change</b> above to add your guardians or emergency contacts.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span class="ct-type <?= $row['type'] ?>"><i class="fa-solid <?= $row['type'] === 'guardian' ? 'fa-user-shield' : ($row['type'] === 'emergency' ? 'fa-truck-medical' : 'fa-envelope-open-text') ?>"></i><span><?= $row['ktag'] ?></span></span></td>
                                <td>
                                    <div class="ct-who">
                                        <span class="ct-avatar <?= $row['type'] ?>"><?= esc($row['initial']) ?></span>
                                        <div>
                                            <div class="ct-name"><?= esc($row['name']) ?></div>
                                            <div class="ct-rel"><?= esc($row['rel']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><ul class="ct-details"><?= $row['details'] ?></ul></td>
                                <td><div class="ct-tags"><?= $row['tags'] ?></div></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="std-foot"><i class="fa-solid fa-shield-halved"></i> Your emergency contact can be reached for health &amp; safety. Email recipients may receive invoices, grades, or alerts — permissions are set by the Office of the Registrar.</div>
        </div>

        <!-- ════ My Change Requests ════ -->
        <!-- Change-request feed styling lives in css/student.css (ap-*) -->

        <div class="panel gdn-panel" style="margin-top:24px;">
            <div class="gdn-toolbar">
                <div class="gdn-toolbar-title">
                    <i class="fa-solid fa-file-circle-check"></i> My change requests
                    <span class="gdn-pill<?= $statPending > 0 ? ' amber' : '' ?>"><?= count($requests) ?></span>
                </div>
                <span class="gdn-toolbar-sub">Nothing is applied until the Office of the Registrar approves it.</span>
            </div>
            <?php if (!$requests): ?>
                <div class="gdn-empty" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:34px 24px;text-align:center;border-radius:12px;font-style:normal;">
                    <i class="fa-solid fa-file-pen" style="font-size:26px;"></i>
                    <span>No change requests yet. Use <b>Request a Change</b> above to propose updates to your contacts.</span>
                </div>
            <?php else: ?>
                <?php foreach ($requests as $r): ?><?= renderRequestRow($r) ?><?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="contacts-footnote">
            <i class="fa-solid fa-lock"></i>
            <span>Contacts are maintained by the Office of the Registrar. To change anything, submit a request above and wait for approval. Every approved change is recorded in the Registrar's Communication Log.</span>
        </div>
    </div>
</main>

<!-- ════ Request a Change modal (styling lives in css/student.css) ════ -->
<div class="modal-overlay" id="reqModal">
    <div class="modal-content rq-modal">
        <div class="modal-header rq-modal-header">
            <div class="rq-head-left">
                <div class="rq-head-icon"><i class="fa-solid fa-file-pen"></i></div>
                <div>
                    <span class="rq-eyebrow"><i class="fa-solid fa-circle-info"></i> Emergency &amp; Contacts</span>
                    <h2>Request a Change</h2>
                    <p class="rq-head-sub">Propose an update — the Office of the Registrar reviews each request.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeReqModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="reqForm">
            <div class="modal-body rq-body">
                <div class="rq-intro">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Tell us what to update and why. The <b>Office of the Registrar</b> reviews each request before anything changes on your record.</span>
                </div>

                <div class="rq-card">
                    <div class="rq-card-hd"><i class="fa-solid fa-sliders"></i> What &amp; how</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>What to change</label>
                            <div class="field-wrap"><i class="fa-solid fa-tag"></i>
                                <select id="reqType" name="contact_type" onchange="syncReqForm()">
                                    <option value="guardian">Guardian / Parent</option>
                                    <option value="emergency">Emergency Contact</option>
                                    <option value="email">Email Recipient</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Change type</label>
                            <div class="field-wrap"><i class="fa-solid fa-arrows-rotate"></i>
                                <select id="reqAction" name="request_type" onchange="syncReqForm()">
                                    <option value="add">Add new</option>
                                    <option value="update">Update existing</option>
                                    <option value="remove">Remove</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rq-card" id="reqTargetGroup" style="display:none;">
                    <div class="rq-card-hd"><i class="fa-solid fa-user-pen"></i> Target contact</div>
                    <label>Which one</label>
                    <div class="field-wrap"><i class="fa-solid fa-list-ul"></i>
                        <select id="reqTarget" name="target_id"><option value="">Loading...</option></select>
                    </div>
                </div>

                <div id="reqFields"><!-- populated by JS --></div>

                <div class="rq-card">
                    <div class="rq-card-hd"><i class="fa-solid fa-comment"></i> Reason <span style="font-weight:600;text-transform:none;color:#94a3b8;letter-spacing:0;">(optional)</span></div>
                    <div class="field-wrap"><i class="fa-solid fa-message"></i>
                        <textarea id="reqReason" name="reason" maxlength="500" rows="2" placeholder="e.g. My mother's number changed to 0917 123 4567."></textarea>
                    </div>
                </div>

                <p class="rq-note"><i class="fa-solid fa-circle-info"></i> Your request goes to the Office of the Registrar for approval. Nothing on your record changes until they approve it.</p>
            </div>
            <div class="modal-footer">
                <p class="rq-footer-hint"><i class="fa-solid fa-lock"></i> Submitted securely · reviewed by the Office of the Registrar</p>
                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px;">
                    <button type="button" class="btn rq-cancel" onclick="closeReqModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    <button type="submit" class="btn btn-primary rq-save" id="reqSubmit"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
const MY_DATA = <?= json_encode([
    'guardians'        => $guardians,
    'emergency'        => $emergency,
    'email_recipients' => $contacts,
], JSON_UNESCAPED_UNICODE) ?>;
const API_URL = '../api/contacts.php';

function csrfFetch(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());
}

// ── Modal open / close ─────────────────────────────────────────
function openReqModal() {
    lastReqType = null;
    document.getElementById('reqType').value = 'guardian';
    document.getElementById('reqAction').value = 'add';
    document.getElementById('reqReason').value = '';
    document.getElementById('reqForm').reset();
    syncReqForm();
    document.getElementById('reqModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeReqModal() {
    document.getElementById('reqModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('reqModal').addEventListener('click', function (e) { if (e.target === this) closeReqModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeReqModal(); });

// ── Field building ─────────────────────────────────────────────
const RELS_GUARDIAN = ['father', 'mother', 'guardian', 'spouse', 'sibling'];
const RELS_EMAIL = ['parent', 'guardian', 'sponsor', 'emergency', 'other'];

function relSelectOptions(rels, sel) {
    return '<option value="">Relationship</option>'
        + rels.map(r => '<option value="' + r + '"' + (r === sel ? ' selected' : '') + '>' + r.charAt(0).toUpperCase() + r.slice(1) + '</option>').join('');
}

function fieldInput(id, placeholder, icon, type) {
    return '<div class="field-wrap"><i class="fa-solid ' + icon + '"></i>'
        + '<input type="' + (type || 'text') + '" id="' + id + '" placeholder="' + placeholder + '"></div>';
}
function fieldSelect(id, optionsHtml) {
    return '<div class="field-wrap"><i class="fa-solid fa-people-roof"></i>'
        + '<select id="' + id + '">' + optionsHtml + '</select></div>';
}
function reqToggle(id, icon, iconBg, iconColor, title, sub, checked) {
    return '<label class="rq-toggle' + (checked ? ' active' : '') + '">'
        + '<span class="rq-toggle-ico" style="background:' + iconBg + ';color:' + iconColor + ';"><i class="fa-solid ' + icon + '"></i></span>'
        + '<span class="rq-toggle-txt"><b>' + title + '</b><span>' + sub + '</span></span>'
        + '<input type="checkbox" id="' + id + '"' + (checked ? ' checked' : '') + ' onchange="this.closest(\'.rq-toggle\').classList.toggle(\'active\', this.checked)">'
        + '<span class="switch"></span></label>';
}

let lastReqType = null;
function renderReqFields(type) {
    let h = '';
    if (type === 'guardian') {
        h += '<div class="rq-field-hd"><i class="fa-solid fa-person"></i> Guardian details</div>';
        h += '<div class="form-group">' + fieldInput('f_name', 'Full name *', 'fa-user') + '</div>';
        h += '<div class="form-row">'
            + '<div class="form-group"><label>Relationship</label>' + fieldSelect('f_rel', relSelectOptions(RELS_GUARDIAN, '')) + '</div>'
            + '<div class="form-group"><label>Contact number</label>' + fieldInput('f_phone', '0917 123 4567', 'fa-phone') + '</div>'
            + '</div>';
        h += '<div class="form-group"><label>Email (optional)</label>' + fieldInput('f_email', 'name@example.com', 'fa-envelope') + '</div>';
        h += '<div class="rq-field-hd"><i class="fa-solid fa-star"></i> Flags</div>'
            + reqToggle('f_primary', 'fa-star', '#eff6ff', '#2563eb', 'Primary guardian', 'Mark as the main contact', false)
            + reqToggle('f_emergency', 'fa-bell', '#fef2f2', '#dc2626', 'Emergency contact', 'Mark as a point of contact for health and safety', false);
    } else if (type === 'emergency') {
        h += '<div class="rq-field-hd"><i class="fa-solid fa-truck-medical"></i> Emergency contact details</div>';
        h += '<div class="form-group">' + fieldInput('f_name', 'Full name *', 'fa-user') + '</div>';
        h += '<div class="form-row">'
            + '<div class="form-group"><label>Relationship</label>' + fieldInput('f_rel', 'e.g. Aunt, Mother', 'fa-people-roof') + '</div>'
            + '<div class="form-group"><label>Contact number</label>' + fieldInput('f_phone', '0917 123 4567', 'fa-phone') + '</div>'
            + '</div>';
        h += '<div class="rq-field-hd"><i class="fa-solid fa-star"></i> Flags</div>'
            + reqToggle('f_primary', 'fa-star', '#eff6ff', '#2563eb', 'Primary contact', 'Mark as the main emergency contact', false);
    } else { // email
        h += '<div class="rq-field-hd"><i class="fa-solid fa-envelope-open-text"></i> Recipient details</div>';
        h += '<div class="form-group">' + fieldInput('f_name', 'Full name *', 'fa-user') + '</div>';
        h += '<div class="form-row">'
            + '<div class="form-group"><label>Relationship</label>' + fieldSelect('f_rel', relSelectOptions(RELS_EMAIL, 'parent')) + '</div>'
            + '<div class="form-group"><label>Email *</label>' + fieldInput('f_email', 'name@example.com', 'fa-envelope') + '</div>'
            + '</div>';
        h += '<div class="form-group"><label>Phone (optional)</label>' + fieldInput('f_phone', '0917 123 4567', 'fa-phone') + '</div>';
        h += '<div class="rq-field-hd"><i class="fa-solid fa-bolt"></i> What can they receive?</div>'
            + reqToggle('f_billing', 'fa-file-invoice-dollar', '#eff6ff', '#2563eb', 'Tuition Invoices', 'Copies of your document-fee invoices', false)
            + reqToggle('f_grades', 'fa-chart-simple', '#ecfdf5', '#059669', 'Semester Grades', 'Grade snapshots and transcripts', false)
            + reqToggle('f_emergency', 'fa-triangle-exclamation', '#fef2f2', '#dc2626', 'Emergency Alerts', 'Urgent notices and alerts', false);
    }
    document.getElementById('reqFields').innerHTML = h;
}

const DATA_KEY = { guardian: 'guardians', emergency: 'emergency', email: 'email_recipients' };

function populateTargets(type, action) {
    const list = MY_DATA[DATA_KEY[type]] || [];
    const sel = document.getElementById('reqTarget');
    if (action === 'add') return;
    if (!list.length) {
        sel.innerHTML = '<option value="">Nothing on file for ' + type + ' — choose \'Add new\'</option>';
        return;
    }
    sel.innerHTML = '<option value="">Select one...</option>'
        + list.map(x => '<option value="' + x.id + '">' + (x.full_name || x.email || ('#' + x.id)) + '</option>').join('');
}

function syncReqForm() {
    const type = document.getElementById('reqType').value;
    const action = document.getElementById('reqAction').value;
    if (type !== lastReqType) { renderReqFields(type); lastReqType = type; }
    document.getElementById('reqTargetGroup').style.display = action === 'add' ? 'none' : '';
    document.getElementById('reqFields').style.display = action === 'remove' ? 'none' : '';
    populateTargets(type, action);
    prefillReqFields();
}
document.getElementById('reqTarget').addEventListener('change', prefillReqFields);

// Prefill the editable fields with the selected target's current values
// (update mode) so the student can propose changes on top of them.
function prefillReqFields() {
    const type = document.getElementById('reqType').value;
    const action = document.getElementById('reqAction').value;
    if (action !== 'update') return;
    const targetId = Number(document.getElementById('reqTarget').value) || 0;
    if (!targetId) return;
    const t = (MY_DATA[DATA_KEY[type]] || []).find(x => Number(x.id) === targetId);
    if (!t) return;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v == null ? '' : v); };
    const setChk = (id, v) => { const el = document.getElementById(id); if (el) el.checked = Number(v) === 1; };
    if (type === 'guardian') {
        set('f_name', t.full_name); set('f_rel', t.relationship); set('f_phone', t.contact_number); set('f_email', t.email);
        setChk('f_primary', t.is_primary); setChk('f_emergency', t.is_emergency);
    } else if (type === 'emergency') {
        set('f_name', t.full_name); set('f_rel', t.relationship); set('f_phone', t.contact_number);
        setChk('f_primary', t.is_primary);
    } else {
        set('f_name', t.full_name); set('f_rel', t.relationship); set('f_email', t.email); set('f_phone', t.phone);
        setChk('f_billing', t.send_billing); setChk('f_grades', t.send_grades); setChk('f_emergency', t.send_emergency);
    }
    // Keep the pill/switch visuals in sync with the checkbox state.
    document.querySelectorAll('#reqFields .rq-toggle').forEach(lbl => {
        const cb = lbl.querySelector('input[type=checkbox]');
        if (cb) lbl.classList.toggle('active', cb.checked);
    });
}

function collectFields(type) {
    const f = {};
    const val = id => (document.getElementById(id) ? document.getElementById(id).value.trim() : '');
    const chk = id => (document.getElementById(id) && document.getElementById(id).checked ? 1 : 0);
    if (type === 'guardian') {
        f.full_name = val('f_name'); f.relationship = val('f_rel'); f.contact_number = val('f_phone'); f.email = val('f_email');
        f.is_primary = chk('f_primary'); f.is_emergency = chk('f_emergency');
    } else if (type === 'emergency') {
        f.full_name = val('f_name'); f.relationship = val('f_rel'); f.contact_number = val('f_phone');
        f.is_primary = chk('f_primary');
    } else {
        f.full_name = val('f_name'); f.relationship = val('f_rel'); f.email = val('f_email'); f.phone = val('f_phone');
        f.send_billing = chk('f_billing'); f.send_grades = chk('f_grades'); f.send_emergency = chk('f_emergency');
    }
    return f;
}

// ── Submit ─────────────────────────────────────────────────────
document.getElementById('reqForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('reqSubmit');
    const type = document.getElementById('reqType').value;
    const action = document.getElementById('reqAction').value;

    const payload = {
        action: 'request_change',
        contact_type: type,
        request_type: action,
        reason: document.getElementById('reqReason').value.trim()
    };

    if (action !== 'add') {
        payload.target_id = Number(document.getElementById('reqTarget').value) || 0;
        if (!payload.target_id) { showToast('Select the contact you want to change.', 'error'); return; }
        if (action === 'update') {
            // Proposed new values — the fields are prefilled from the current record.
            payload.payload = collectFields(type);
        } else {
            // Carry the current name so the request reads clearly to the Registrar.
            const target = (MY_DATA[DATA_KEY[type]] || []).find(x => Number(x.id) === payload.target_id);
            if (target) payload.payload = { full_name: target.full_name || target.email || '' };
        }
    } else {
        payload.payload = collectFields(type);
    }

    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    try {
        const d = await csrfFetch(API_URL, payload);
        if (d.success) {
            showToast(d.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(d.message || 'Could not submit your request.', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
