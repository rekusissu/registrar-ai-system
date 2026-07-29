<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Guardian / Parent management
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Handle edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'edit') {
        $db->update('guardians', [
            'full_name' => $_POST['full_name'],
            'relationship' => $_POST['relationship'],
            'contact_number' => $_POST['contact_number'],
            'email' => $_POST['email']
        ], 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->delete('guardians', 'id = ?', [intval($_POST['id'])]);
    }
    header('Location: guardians.php');
    exit;
}

$guardians = $db->fetchAll("SELECT g.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number FROM guardians g LEFT JOIN students s ON g.student_id = s.id ORDER BY g.full_name");

$page_title = 'Guardians';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root { --sidebar-width:260px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; box-sizing:border-box; }
.header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid #e8eaef; gap:16px; flex-wrap:wrap; }
.header .title h1 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 2px; }
.header .title p { font-size:13px; color:#64748b; margin:0; }
.header-actions { display:flex; align-items:center; gap:8px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; border:1.5px solid transparent; text-decoration:none; font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; }
.btn-sm { padding:5px 12px; font-size:12px; }
.btn-danger { background:#dc2626; color:white; border:none; padding:5px 12px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; }

table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
td { padding:10px 14px; font-size:13px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }

.status-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:600; }
.status-badge.father, .status-badge.mother, .status-badge.guardian { background:#eef4ff; color:#2563eb; }
.status-badge.sibling, .status-badge.spouse { background:#f3e8ff; color:#7c3aed; }

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.active { display:flex; }
.modal-content { background:white; border-radius:20px; padding:28px 32px; max-width:480px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,0.15); }
.modal-content h3 { font-size:17px; font-weight:700; color:#0f172a; margin-bottom:14px; }
.form-group { margin-bottom:12px; }
.form-group label { display:block; font-size:12px; color:#475569; margin-bottom:4px; font-weight:600; }
.form-control { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; font-family:inherit; outline:none; background:white; color:#1e293b; box-sizing:border-box; }
.form-control:focus { border-color:#2563eb; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; padding-top:14px; border-top:1px solid #f1f5f9; }

@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
</style>
<main class="dashboard-main">
<header class="header">
<div class="title"><h1>Guardians</h1><p>Manage parent and guardian records</p></div>
<div class="header-actions"><?php if ($guardians): ?><span style="font-size:13px;color:#64748b;"><?= count($guardians) ?> records</span><?php endif; ?></div>
</header>

<table>
<thead><tr><th>Name</th><th>Relationship</th><th>Contact</th><th>Email</th><th>Student</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($guardians)): ?>
<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No guardian records found. Add one from the student profile.</td></tr>
<?php else: foreach ($guardians as $g): ?>
<tr>
<td><strong><?= htmlspecialchars($g['full_name']) ?></strong></td>
<td><span class="status-badge <?= htmlspecialchars($g['relationship']) ?>"><?= htmlspecialchars(ucfirst($g['relationship'])) ?></span></td>
<td><?= htmlspecialchars($g['contact_number'] ?? '—') ?></td>
<td><?= htmlspecialchars($g['email'] ?? '—') ?></td>
<td><a href="students.php?search=<?= urlencode($g['student_name'] ?? '') ?>" style="color:#2563eb;text-decoration:none;font-weight:500;"><?= htmlspecialchars($g['student_name'] ?? 'Unknown') ?></a> <span style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars($g['student_number'] ?? '') ?></span></td>
<td style="text-align:center;">
<button class="btn btn-secondary btn-sm" onclick="openEdit(<?= (int)$g['id'] ?>,'<?= htmlspecialchars($g['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($g['relationship']) ?>','<?= htmlspecialchars($g['contact_number'] ?? '') ?>','<?= htmlspecialchars($g['email'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
<form method="POST" style="display:inline;" onsubmit="return confirm('Delete guardian <?= htmlspecialchars($g['full_name']) ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button type="submit" class="btn-danger"><i class="fas fa-trash-alt"></i></button></form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><h3>Edit Guardian</h3><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId" value="">
<div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="editName" class="form-control" required></div>
<div class="form-group"><label>Relationship</label><select name="relationship" id="editRel" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="sibling">Sibling</option><option value="spouse">Spouse</option></select></div>
<div class="form-group"><label>Contact No.</label><input type="text" name="contact_number" id="editContact" class="form-control"></div>
<div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control"></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('editModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<script>
function openEdit(id, name, rel, contact, email) {
    document.getElementById('editId').value = id;
    document.getElementById('editName').value = name;
    document.getElementById('editRel').value = rel;
    document.getElementById('editContact').value = contact;
    document.getElementById('editEmail').value = email;
    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});
</script>
<?php include '../includes/footer.php'; ?>