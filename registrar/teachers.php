<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $db->insert('users', [
            'email' => $_POST['email'],
            'password_hash' => $hash,
            'full_name' => $_POST['full_name'],
            'role' => 'teacher',
            'rfid_uid' => trim($_POST['rfid_uid'] ?: '') ?: null,
            'is_active' => 1
        ]);
    } elseif ($action === 'edit') {
        $data = [
            'email' => $_POST['email'],
            'full_name' => $_POST['full_name'],
            'rfid_uid' => trim($_POST['rfid_uid'] ?: '') ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        if (!empty(trim($_POST['password'] ?? ''))) {
            $data['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        $db->update('users', $data, 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->update('users', ['is_active' => 0], 'id = ?', [intval($_POST['id'])]);
    }
    header('Location: teachers.php');
    exit;
}

$teachers = $db->fetchAll("SELECT * FROM users WHERE role = 'teacher' OR role = 'staff' ORDER BY full_name");
$page_title = 'Teachers';
$APP_ROOT = '../';
$ACTIVE_NAV = 'teachers';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root{--sidebar-width:260px}
.dashboard-main{margin-left:var(--sidebar-width);padding:24px 32px;min-height:100vh;width:calc(100% - var(--sidebar-width));max-width:calc(100% - var(--sidebar-width));overflow-x:hidden;box-sizing:border-box}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid #e8eaef;gap:16px;flex-wrap:wrap}
.header .title h1{font-size:22px;font-weight:700;color:#0f172a;margin:0 0 2px}
.header .title p{font-size:13px;color:#64748b;margin:0}
.header-actions{display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:white}
.btn-primary:hover{transform:translateY(-1px)}
.btn-secondary{background:white;color:#475569;border-color:#e2e8f0}
.btn-secondary:hover{background:#f8fafc}
.btn-light{background:#f1f5f9;color:#475569}
.btn-light:hover{background:#e2e8f0}
table{width:100%;border-collapse:collapse;background:white;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0}
th{text-align:left;padding:10px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap}
td{padding:10px 14px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover{background:#f8fafc}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600}
.badge.active{background:#dcfce7;color:#16a34a}
.badge.inactive{background:#f1f5f9;color:#94a3b8}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:500px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.15)}
.modal-content h3{font-size:17px;font-weight:700;color:#0f172a;margin-bottom:14px}
.form-row{display:flex;gap:12px;flex-wrap:wrap}
.form-group{flex:1;min-width:160px;margin-bottom:12px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb}
.form-check{display:flex;align-items:center;gap:8px;margin-top:8px}
.form-check input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #f1f5f9}
code{background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px}
@media(max-width:768px){.dashboard-main{margin-left:0;padding:16px}}
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Teachers</h1><p>Manage teacher accounts</p></div>
<div class="header-actions"><button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Teacher</button></div></header>
<table>
<thead><tr><th>Name</th><th>Email</th><th>Role</th><th>RFID UID</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($teachers)): ?>
<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No teachers found.</td></tr>
<?php else: foreach ($teachers as $t): ?>
<tr>
<td><strong><?= htmlspecialchars($t['full_name']) ?></strong></td>
<td><?= htmlspecialchars($t['email']) ?></td>
<td><span class="badge" style="background:#f3e8ff;color:#7c3aed;"><?= htmlspecialchars(ucfirst($t['role'])) ?></span></td>
<td><?= $t['rfid_uid'] ? '<code>'.$t['rfid_uid'].'</code>' : '<span style="color:#94a3b8;">—</span>' ?></td>
<td><span class="badge <?= $t['is_active'] ? 'active' : 'inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
<td style="text-align:center;">
<button class="btn btn-secondary" style="padding:5px 10px;font-size:12px;" onclick='openEdit(<?= json_encode($t) ?>)'><i class="fas fa-pen"></i></button>
<form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate <?= htmlspecialchars($t['full_name']) ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-light" style="padding:5px 10px;font-size:12px;color:#dc2626;"><i class="fas fa-trash-alt"></i></button></form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content"><h3>Add Teacher</h3><form method="POST"><input type="hidden" name="action" value="add">
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" name="full_name" class="form-control" required></div><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" name="email" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Password <span style="color:#dc2626;">*</span></label><input type="password" name="password" class="form-control" required></div><div class="form-group"><label>RFID UID (optional)</label><input type="text" name="rfid_uid" class="form-control" maxlength="10" placeholder="10-digit UID"></div></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('addModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><h3>Edit Teacher</h3><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
<div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="editName" class="form-control" required></div><div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>New Password (leave blank to keep)</label><input type="password" name="password" class="form-control"></div><div class="form-group"><label>RFID UID</label><input type="text" name="rfid_uid" id="editRfid" class="form-control" maxlength="10"></div></div>
<div class="form-group"><div style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_active" id="editActive" checked style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb;"><label for="editActive" style="cursor:pointer;font-size:13px;">Active</label></div></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('editModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<script>
function openAdd() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});
function openEdit(t) {
    document.getElementById('editId').value = t.id;
    document.getElementById('editName').value = t.full_name;
    document.getElementById('editEmail').value = t.email;
    document.getElementById('editRfid').value = t.rfid_uid || '';
    document.getElementById('editActive').checked = t.is_active == 1;
    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});
</script>
<?php include '../includes/footer.php'; ?>