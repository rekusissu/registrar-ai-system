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

$page_title = 'Academic History';
$APP_ROOT = '../';
$ACTIVE_NAV = 'academic';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.acad-searchbar { display:flex; align-items:center; gap:10px; padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e8edf4; flex-wrap:wrap; }
.acad-search { position:relative; flex:1; min-width:220px; max-width:380px; }
.acad-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; pointer-events:none; }
.acad-search input { width:100%; height:38px; padding:0 12px 0 34px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; outline:none; background:#fff; box-sizing:border-box; }
.acad-search input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.acad-count { font-size:12px; color:#94a3b8; }
.rec-pill { display:inline-flex; align-items:center; gap:5px; padding:2px 10px; border-radius:999px; font-size:11.5px; font-weight:700; background:#eef4ff; color:#2563eb; }
.rec-pill.zero { background:#f1f5f9; color:#64748b; }
.ah-record { border:1px solid #eef2f7; border-radius:12px; padding:12px 14px; margin-bottom:10px; background:#fbfcfe; }
.ah-record:last-child { margin-bottom:0; }
.ah-row { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; padding:4px 0; }
.ah-row span:first-child { color:#64748b; }
.ah-row span:last-child { font-weight:600; color:#0f172a; text-align:right; }
.ah-grades { margin-top:8px; border-top:1px dashed #e2e8f0; padding-top:8px; }
.ah-grades table { width:100%; border-collapse:collapse; font-size:12px; }
.ah-grades th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.4px; color:#94a3b8; padding:3px 6px; }
.ah-grades td { padding:3px 6px; border-top:1px solid #f1f5f9; }
.receive-row { display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid #f1f5f9; }
.receive-row:last-child { border-bottom:none; }
.rv-who { flex:1; min-width:0; }
.rv-name { font-size:13px; font-weight:700; color:#0f172a; }
.rv-sub { font-size:11.5px; color:#94a3b8; margin-top:1px; }
</style>

<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header">
        <div class="title">
            <h1>Academic History</h1>
            <p>Previous schools and academic records, received from the enrollment intake</p>
        </div>
        <div class="header-actions">
            <span class="acad-count" style="font-size:13px;color:#64748b;"><?= count($studentRows) ?> students with records</span>
            <button class="btn btn-primary" onclick="openReceive()"><i class="fas fa-inbox"></i> Receive Record</button>
        </div>
    </header>

    <div class="panel" style="padding:0;overflow:hidden;">
        <div class="acad-searchbar">
            <div class="acad-search">
                <i class="fas fa-search"></i>
                <input type="text" id="acadSearch" placeholder="Search by student ID or name...">
            </div>
            <span class="acad-count" id="acadCount"></span>
        </div>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Student ID</th><th>Student Name</th><th>Records</th><th>Latest School</th><th style="text-align:center;">Actions</th></tr></thead>
            <tbody id="acadBody">
            <?php if (empty($studentRows)): ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No academic history records yet. Use <strong>Receive Record</strong> to import them.</td></tr>
            <?php else: foreach ($studentRows as $s): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($s['student_number'] . ' ' . $s['student_name']), ENT_QUOTES) ?>"
                    data-sid="<?= (int) $s['student_id'] ?>">
                    <td style="font-family:'JetBrains Mono',monospace;font-size:12.5px;"><?= htmlspecialchars($s['student_number']) ?></td>
                    <td><strong><?= htmlspecialchars($s['student_name']) ?></strong><?= $s['course'] ? '<br><span style="font-size:11px;color:#94a3b8;">' . htmlspecialchars($s['course']) . '</span>' : '' ?></td>
                    <td><span class="rec-pill"><i class="fas fa-file-lines"></i> <?= count($s['records']) ?></span></td>
                    <td style="color:#475569;"><?= htmlspecialchars($s['records'][0]['school_name']) ?></td>
                    <td style="text-align:center;">
                        <button class="action-btn view" title="View academic history" onclick="openView(<?= (int) $s['student_id'] ?>)"><i class="fas fa-eye"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</main>

<!-- Receive Record Modal -->
<div class="modal-overlay" id="receiveModal"><div class="modal-content" style="max-width:640px;">
    <div class="modal-header">
        <h3><i class="fas fa-inbox" style="color:#2563eb;"></i> Receive Academic Records</h3>
        <button class="modal-close" onclick="closeModal('receiveModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <p style="font-size:12.5px;color:#64748b;margin:0 0 12px;"><i class="fas fa-circle-info"></i> Pick a student, review their records, then click <strong>Import</strong> to pull their previous-school data from the enrollment dashboard.</p>
        <div class="acad-search" style="max-width:100%;margin-bottom:10px;">
            <i class="fas fa-search"></i>
            <input type="text" id="receiveSearch" placeholder="Search student ID or name...">
        </div>
        <div id="receiveList" style="max-height:52vh;overflow-y:auto;border:1px solid #f1f5f9;border-radius:10px;"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('receiveModal')">Close</button>
    </div>
</div></div>

<!-- View (read-only) Modal -->
<div class="modal-overlay" id="viewModal"><div class="modal-content" style="max-width:640px;">
    <div class="modal-header">
        <h3><i class="fas fa-school" style="color:#2563eb;"></i> Academic History</h3>
        <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="viewHead" style="margin-bottom:14px;"></div>
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
function renderReceiveList(q) {
    q = (q || '').trim().toLowerCase();
    const wrap = document.getElementById('receiveList');
    const filtered = RECEIVE_LIST.filter(s => !q || (s.number + ' ' + s.name).toLowerCase().indexOf(q) !== -1);
    if (!filtered.length) {
        wrap.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:24px;">No students found.</p>';
        return;
    }
    wrap.innerHTML = filtered.map(s =>
        '<div class="receive-row">'
        + '<div class="rv-who"><div class="rv-name">' + esc(s.name) + '</div>'
        + '<div class="rv-sub">' + esc(s.number) + ' - ' + s.count + ' record(s) on file</div></div>'
        + '<span class="rec-pill' + (s.count ? '' : ' zero') + '">' + s.count + '</span>'
        + '<button class="btn btn-primary btn-sm" style="height:30px;padding:0 12px;font-size:11px;" onclick="importRecords(' + s.id + ', this)"><i class="fas fa-file-import"></i> Import</button>'
        + '</div>'
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
    if (recs.length) {
        head.innerHTML = '<div style="font-size:15px;font-weight:800;color:#0f172a;">' + esc(RECEIVE_LIST.find(s => s.id === studentId)?.name || 'Student') + '</div>'
            + '<div style="font-size:12px;color:#94a3b8;margin-top:2px;">' + esc(RECEIVE_LIST.find(s => s.id === studentId)?.number || '') + ' <span style="color:#cbd5e1;">-</span> ' + recs.length + ' record(s)</div>';
    } else {
        head.innerHTML = '<div style="font-size:15px;font-weight:800;color:#0f172a;">' + esc(RECEIVE_LIST.find(s => s.id === studentId)?.name || 'Student') + '</div>'
            + '<div style="font-size:12px;color:#94a3b8;margin-top:2px;">No academic records on file</div>';
    }
    if (!recs.length) {
        body.innerHTML = '<div style="padding:24px;text-align:center;color:#94a3b8;background:#f8fafc;border-radius:12px;">No academic history yet. Use <strong>Receive Record</strong> to import from the enrollment dashboard.</div>';
    } else {
        body.innerHTML = recs.map(r =>
            '<div class="ah-record">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
            + '<strong style="font-size:13.5px;color:#0f172a;">' + esc(r.school) + '</strong>'
            + (r.gwa ? '<span class="gwa-badge" style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#eef4ff;color:#2563eb;">GWA ' + esc(r.gwa) + '</span>' : '')
            + '</div>'
            + (r.year || r.grade ? '<div class="ah-row"><span>' + [r.year, r.grade, r.semester].filter(Boolean).join(' - ') + '</span></div>' : '')
            + (r.remarks ? '<div class="ah-row"><span style="color:#475569;">' + esc(r.remarks) + '</span></div>' : '')
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