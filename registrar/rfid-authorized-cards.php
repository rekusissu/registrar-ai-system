<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $cardUid = trim($_POST['card_uid']);
        $existing = $db->fetchOne("SELECT id FROM authorized_cards WHERE card_uid = ?", [$cardUid]);
        if (!$existing) {
            $db->insert('authorized_cards', [
                'card_uid' => $cardUid,
                'name' => $_POST['name'],
                'role' => $_POST['role'],
                'can_change_station' => isset($_POST['can_change_station']) ? 1 : 0
            ]);
        }
    } elseif ($action === 'edit') {
        $db->update('authorized_cards', [
            'name' => $_POST['name'],
            'role' => $_POST['role'],
            'can_change_station' => isset($_POST['can_change_station']) ? 1 : 0
        ], 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->delete('authorized_cards', 'id = ?', [intval($_POST['id'])]);
    }
    header('Location: rfid-authorized-cards.php');
    exit;
}

$cards = $db->fetchAll("SELECT * FROM authorized_cards ORDER BY name");
$page_title = 'Authorized Cards';
$APP_ROOT = '../';
$ACTIVE_NAV = 'authorized';
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
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600}
.badge.superadmin{background:#fee2e2;color:#dc2626}
.badge.admin{background:#eef4ff;color:#2563eb}
.badge.registrar{background:#fef3c7;color:#b45309}
code{background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:480px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.15)}
.modal-content h3{font-size:17px;font-weight:700;color:#0f172a;margin-bottom:14px}
.form-group{margin-bottom:12px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb}
.form-check{display:flex;align-items:center;gap:8px;margin-top:8px}
.form-check input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}
.form-check label{font-size:13px;color:#1e293b;cursor:pointer}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #f1f5f9}
@media(max-width:768px){.dashboard-main{margin-left:0;padding:16px}}
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Authorized Cards</h1><p>RFID cards authorized for kiosk station changes</p></div>
<div class="header-actions"><button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Card</button></div></header>
<table>
<thead><tr><th>Card UID</th><th>Name</th><th>Role</th><th>Can Change Station</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($cards)): ?>
<tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No authorized cards.</td></tr>
<?php else: foreach ($cards as $c): ?>
<tr>
<td><code><?= htmlspecialchars($c['card_uid']) ?></code></td>
<td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
<td><span class="badge <?= htmlspecialchars($c['role']) ?>"><?= htmlspecialchars(ucfirst($c['role'])) ?></span></td>
<td><?= $c['can_change_station'] ? '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Yes</span>' : '<span style="color:#94a3b8;">No</span>' ?></td>
<td style="text-align:center;">
<button class="btn btn-secondary" style="padding:5px 10px;font-size:12px;" onclick="openEdit(<?= (int)$c['id'] ?>,'<?= htmlspecialchars($c['card_uid'],ENT_QUOTES) ?>','<?= htmlspecialchars($c['name'],ENT_QUOTES) ?>','<?= htmlspecialchars($c['role']) ?>',<?= $c['can_change_station']?'true':'false' ?>)"><i class="fas fa-pen"></i></button>
<form method="POST" style="display:inline;" onsubmit="return confirm('Delete authorized card for <?= htmlspecialchars($c['name']) ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-light" style="padding:5px 10px;font-size:12px;color:#dc2626;"><i class="fas fa-trash-alt"></i></button></form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content"><h3>Add Authorized Card</h3><form method="POST"><input type="hidden" name="action" value="add">
<div class="form-group"><label>Card UID (10 digits)</label><input type="text" name="card_uid" class="form-control" maxlength="10" inputmode="numeric" required></div>
<div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" required></div>
<div class="form-group"><label>Role</label><select name="role" class="form-control"><option value="registrar">Registrar</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
<div class="form-group"><div class="form-check"><input type="checkbox" name="can_change_station" id="addChange" checked><label for="addChange">Can change kiosk station</label></div></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('addModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><h3>Edit Authorized Card</h3><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
<div class="form-group"><label>Card UID</label><code id="editUid" style="font-size:16px;"></code></div>
<div class="form-group"><label>Name</label><input type="text" name="name" id="editName" class="form-control" required></div>
<div class="form-group"><label>Role</label><select name="role" id="editRole" class="form-control"><option value="registrar">Registrar</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
<div class="form-group"><div class="form-check"><input type="checkbox" name="can_change_station" id="editChange"><label for="editChange">Can change kiosk station</label></div></div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="document.getElementById('editModal').classList.remove('active');document.body.style.overflow='';">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<script>
function openAdd() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});
function openEdit(id, uid, name, role, canChange) {
    document.getElementById('editId').value = id;
    document.getElementById('editUid').textContent = uid;
    document.getElementById('editName').value = name;
    document.getElementById('editRole').value = role;
    document.getElementById('editChange').checked = canChange;
    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; }});
</script>
<?php include '../includes/footer.php'; ?>