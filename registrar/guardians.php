<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Subsystem 2 — Guardian & Emergency Contact management.
//  Multiple guardians + separate emergency contacts per student,
//  built on the shared registrar.css design system.
//  Requires registrar_upgrade.sql (emergency_contacts table).
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

$emgCounts = [];
foreach ($db->fetchAll("SELECT student_id, COUNT(*) AS c FROM emergency_contacts GROUP BY student_id") as $e) {
    $emgCounts[$e['student_id']] = (int)$e['c'];
}

$page_title = 'Guardians & Contacts';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
include '../includes/header.php';
include '../includes/sidebar.php';

// Build a JSON list of guardian rows per student so JS can populate the modal
$guardRowsByStudent = [];
foreach ($byStudent as $sid => $s) {
    $guardRowsByStudent[$sid] = $s['guardians'];
}
?>
<style>
.contact-card { background:#fafcfd; border:1px solid #f1f5f9; border-radius:8px; padding:8px 10px; margin-bottom:6px; }
.section-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#94a3b8; }
.g-row, .e-row { border:1px solid #f1f5f9; border-radius:8px; padding:10px 12px; margin-bottom:8px; background:#fafcfd; }
</style>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Guardians &amp; Emergency Contacts</h1>
                <p>Manage guardian and emergency contact information for every student</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openManage()"><i class="fas fa-users"></i> Manage Contacts</button>
            </div>
        </header>

        <div class="panel">
            <div class="search-toolbar" style="padding:12px 20px;background:#fafcfe;border-bottom:1px solid #e8edf4;">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="guardSearch" placeholder="Search by student name or number...">
                </div>
            </div>

            <div class="table-responsive" style="overflow-x:auto;">
            <table class="table">
                <thead><tr><th>Student</th><th>Guardians</th><th>Emergency Contacts</th><th style="text-align:center;">Actions</th></tr></thead>
                <tbody id="guardBody">
                <?php if (empty($byStudent)): ?>
                    <tr><td colspan="4" class="empty-state"><i class="fas fa-users"></i><p>No students found</p><span>Add students first to manage their contacts</span></td></tr>
                <?php else: foreach ($byStudent as $s): ?>
                    <tr data-id="<?= (int)$s['student_id'] ?>"
                        data-search="<?= htmlspecialchars(strtolower($s['student_name'].' '.$s['student_number']), ENT_QUOTES) ?>">
                        <td>
                            <div><div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($s['student_name']) ?></div>
                            <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($s['student_number']) ?></div></div>
                        </td>
                        <td style="min-width:230px;">
                            <?php if (empty($s['guardians'])): ?>
                                <span style="color:#94a3b8;font-size:12px;">No guardian recorded</span>
                            <?php else: foreach ($s['guardians'] as $g): ?>
                                <div style="margin-bottom:4px;">
                                    <span style="font-weight:600;font-size:12px;color:#0f172a;"><?= htmlspecialchars($g['full_name']) ?></span>
                                    <span class="chip blue"><?= htmlspecialchars(ucfirst($g['relationship'])) ?></span>
                                    <?php if ($g['is_primary']): ?><span class="chip green">Primary</span><?php endif; ?>
                                    <?php if ($g['is_emergency']): ?><span class="chip purple">Emergency</span><?php endif; ?>
                                    <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($g['contact_number'] ?? '') ?><?= $g['email'] ? ' · '.htmlspecialchars($g['email']) : '' ?></div>
                                </div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td>
                            <?php if (($emgCounts[$s['student_id']] ?? 0) > 0): ?>
                                <span class="chip purple"><?= $emgCounts[$s['student_id']] ?> contact(s)</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">—</span>
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
    <div class="modal-header">
        <h2><i class="fas fa-users" style="color:#2563eb;"></i> <span id="mgTitle">Manage Contacts</span></h2>
        <button class="modal-close" onclick="closeModal('manageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <!-- Student picker shown when opened from header button -->
        <div id="mgPickerWrap" style="display:none;" class="form-group">
            <label>Student</label>
            <select id="mgPicker" class="form-control">
                <option value="">Select a student...</option>
                <?php foreach ($students as $st): ?>
                    <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'].' — '.$st['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" id="mgStudentId">

        <div class="section-label" style="margin-bottom:8px;">Guardians</div>
        <div id="mgGuardians"></div>
        <div style="display:flex;justify-content:flex-end;margin:8px 0;">
            <button class="btn btn-light btn-sm" onclick="addGuardianRow()"><i class="fas fa-plus"></i> Add Guardian</button>
        </div>

        <hr style="border:none;border-top:1px solid #e8edf4;margin:14px 0;">

        <div class="section-label" style="margin-bottom:8px;">Emergency Contacts</div>
        <div id="mgEmergency"></div>
        <div style="display:flex;justify-content:flex-end;margin:8px 0;">
            <button class="btn btn-light btn-sm" onclick="addEmergencyRow()"><i class="fas fa-plus"></i> Add Emergency Contact</button>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" onclick="closeModal('manageModal')">Cancel</button>
        <button class="btn btn-primary" onclick="saveAll()"><i class="fas fa-save"></i> Save Contacts</button>
    </div>
</div></div>

<script>
// ─── DATA INJECTED FROM PHP ─────────────────────────────────
const ALL_STUDENTS = <?= json_encode(array_values(array_map(fn($s) => ['id'=>$s['id'],'label'=>$s['student_number'].' — '.$s['name']], $students))) ?>;
const GUARD_DATA = <?= json_encode($guardRowsByStudent) ?>;

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

// ─── HELPERS ────────────────────────────────────────────────
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function relOptions(sel){
    const rels=['father','mother','guardian','spouse','sibling'];
    return '<option value="">Relationship</option>'+rels.map(r=>'<option value="'+r+'"'+(r===sel?' selected':'')+'>'+r.charAt(0).toUpperCase()+r.slice(1)+'</option>').join('');
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
    }
});

function loadStudentContacts(id) {
    loadGuardians(id);
    loadEmergency(id);
}
function loadGuardians(id) {
    const list = (GUARD_DATA[id] || []);
    document.getElementById('mgGuardians').innerHTML = list.length
        ? list.map(g => guardianRow(g)).join('')
        : '<div id="mgG-empty" style="color:#94a3b8;font-size:12px;padding:6px 0;">No guardians recorded.</div>';
}
function loadEmergency(id) {
    fetch('../api/students.php?action=emergency&student_id=' + id)
    .then(r => r.json()).then(d => {
        const list = (d.success && d.data) ? d.data : [];
        document.getElementById('mgEmergency').innerHTML = list.length
            ? list.map(e => emergencyRow(e)).join('')
            : '<div id="mgE-empty" style="color:#94a3b8;font-size:12px;padding:6px 0;">No emergency contacts.</div>';
    }).catch(() => document.getElementById('mgEmergency').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:6px 0;">Error loading contacts.</div>');
}

// ─── ROW BUILDERS ───────────────────────────────────────────
let gSeq=1000, eSeq=1000;
function guardianRow(g) {
    gSeq++;
    return '<div class="g-row" data-gid="' + (g.id||'') + '" data-key="' + gSeq + '" style="border:1px solid #f1f5f9;border-radius:8px;padding:10px 12px;margin-bottom:8px;background:#fafcfd;">'
        + '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;margin-bottom:6px;">'
        + '<input class="form-control gi-name" placeholder="Full name *" value="' + esc(g.full_name) + '">'
        + '<select class="form-control gi-rel">' + relOptions(g.relationship) + '</select>'
        + '<input class="form-control gi-contact" placeholder="Contact no." value="' + esc(g.contact_number) + '"></div>'
        + '<div style="display:grid;grid-template-columns:2fr auto;gap:8px;align-items:center;">'
        + '<input class="form-control gi-email" placeholder="Email" value="' + esc(g.email) + '">'
        + '<div style="display:flex;gap:10px;align-items:center;">'
        + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;color:#475569;cursor:pointer;"><input type="checkbox" class="gi-primary" ' + (g.is_primary?'checked':'') + '> Primary</label>'
        + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;color:#475569;cursor:pointer;"><input type="checkbox" class="gi-emergency" ' + (g.is_emergency?'checked':'') + '> Emergency</label>'
        + '<button type="button" class="action-btn delete" onclick="this.closest(\'.g-row\').remove()" title="Remove"><i class="fas fa-trash-alt"></i></button>'
        + '</div></div></div>';
}
function emergencyRow(e) {
    eSeq++;
    return '<div class="e-row" data-eid="' + (e.id||'0') + '" data-ekey="' + eSeq + '" style="border:1px solid #f1f5f9;border-radius:8px;padding:8px 10px;margin-bottom:8px;background:#fafcfd;">'
        + '<div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:center;">'
        + '<input class="form-control ei-name" placeholder="Full name *" value="' + esc(e.full_name) + '">'
        + '<input class="form-control ei-rel" placeholder="Relationship" value="' + esc(e.relationship) + '">'
        + '<input class="form-control ei-contact" placeholder="Contact no." value="' + esc(e.contact_number) + '">'
        + '<button type="button" class="action-btn delete" onclick="this.closest(\'.e-row\').remove()" title="Remove"><i class="fas fa-trash-alt"></i></button>'
        + '</div></div>';
}

// ─── ADD ROW ────────────────────────────────────────────────
function addGuardianRow() {
    const empty = document.getElementById('mgG-empty'); if (empty) empty.remove();
    document.getElementById('mgGuardians').insertAdjacentHTML('beforeend', guardianRow({}));
}
function addEmergencyRow() {
    const empty = document.getElementById('mgE-empty'); if (empty) empty.remove();
    document.getElementById('mgEmergency').insertAdjacentHTML('beforeend', emergencyRow({}));
}

// ─── SAVE ALL ───────────────────────────────────────────────
async function saveAll() {
    if (!currentStudentId) { alert('Select a student first.'); return; }
    const btn = document.querySelector('#manageModal .modal-footer .btn-primary');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const url = '../api/students.php?action=';
    const seq = async (action, payload) => {
        const r = await fetch(url + action, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        return await r.json();
    };

    try {
        // Guardians
        const gRows = document.querySelectorAll('.g-row');
        for (const row of gRows) {
            await seq('save-guardian', {
                id: parseInt(row.dataset.gid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: row.querySelector('.gi-name').value,
                relationship: row.querySelector('.gi-rel').value,
                contact_number: row.querySelector('.gi-contact').value,
                email: row.querySelector('.gi-email').value,
                is_primary: row.querySelector('.gi-primary').checked ? 1 : 0,
                is_emergency: row.querySelector('.gi-emergency').checked ? 1 : 0
            });
        }
        // Emergency contacts
        const eRows = document.querySelectorAll('.e-row');
        for (const row of eRows) {
            await seq('save-emergency', {
                id: parseInt(row.dataset.eid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: row.querySelector('.ei-name').value,
                relationship: row.querySelector('.ei-rel').value,
                contact_number: row.querySelector('.ei-contact').value
            });
        }
        showToast('Saved', 'Contacts updated.', 'success');
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        alert('Error saving contacts.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Contacts';
    }
}

// ── TOAST ──────────────────────────────────────────────────
function showToast(title, message, type) {
    let c = document.querySelector('.toast-container');
    if (!c) { c = document.createElement('div'); c.className='toast-container'; document.body.appendChild(c); }
    const t = document.createElement('div'); t.className='toast '+(type||'info');
    t.innerHTML = '<i class="fas '+(type==='success'?'fa-circle-check':'fa-circle-info')+' toast-icon"></i><div class="toast-content"><div class="toast-title"></div><div class="toast-message"></div></div><button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>';
    t.querySelector('.toast-title').textContent = title;
    t.querySelector('.toast-message').textContent = message;
    t.querySelector('.toast-close').addEventListener('click', () => { t.classList.add('hiding'); setTimeout(()=>t.remove(),300); });
    c.appendChild(t); setTimeout(() => { t.classList.add('hiding'); setTimeout(()=>t.remove(),300); }, 4000);
}
</script>

<?php include '../includes/footer.php'; ?>