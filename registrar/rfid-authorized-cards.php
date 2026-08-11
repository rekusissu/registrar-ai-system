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
<main class="dashboard-main">
<div class="dashboard-container">
<header class="header"><div class="title"><h1>Authorized Cards</h1><p>RFID cards authorized for kiosk station changes</p></div>
<div class="header-actions"><button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Add Card</button></div></header>

<div class="panel">
<div class="table-responsive" style="overflow-x:auto;">
<table class="table">
<thead><tr><th>Card UID</th><th>Name</th><th>Role</th><th>Can Change Station</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if (empty($cards)): ?>
<tr><td colspan="5" class="empty-state"><i class="fas fa-id-card"></i><p>No authorized cards</p><span>Add a card to allow kiosk station changes</span></td></tr>
<?php else: foreach ($cards as $c): ?>
<tr>
<td><code style="background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px;"><?= htmlspecialchars($c['card_uid']) ?></code></td>
<td style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></td>
<td><span class="pill <?= htmlspecialchars($c['role']) ?>"><?= htmlspecialchars(ucfirst($c['role'])) ?></span></td>
<td><?= $c['can_change_station'] ? '<span class="pill active"><i class="fas fa-check-circle"></i> Yes</span>' : '<span class="pill inactive">No</span>' ?></td>
<td style="text-align:center;"><div class="action-group">
<button class="action-btn edit" onclick="openEdit(<?= (int)$c['id'] ?>,'<?= htmlspecialchars($c['card_uid'],ENT_QUOTES) ?>','<?= htmlspecialchars($c['name'],ENT_QUOTES) ?>','<?= htmlspecialchars($c['role']) ?>',<?= $c['can_change_station']?'true':'false' ?>)"><i class="fas fa-pen"></i></button>
<form method="POST" style="display:inline;" onsubmit="return confirm('Delete authorized card for <?= htmlspecialchars($c['name']) ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="action-btn delete" type="submit"><i class="fas fa-trash-alt"></i></button></form>
</div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<div class="table-footer"><div class="info-text">Showing <strong><?= count($cards) ?></strong> authorized card<?= count($cards) === 1 ? '' : 's' ?></div></div>
</div>
</div>
</main>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-id-card"></i> Add Authorized Card</h2><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div><form method="POST"><input type="hidden" name="action" value="add">
<div class="modal-body">
<div class="form-group"><label>Card UID (10 digits)</label><input type="text" name="card_uid" class="form-control" maxlength="10" inputmode="numeric" required></div>
<div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" required></div>
<div class="form-group"><label>Role</label><select name="role" class="form-control"><option value="registrar">Registrar</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
<div class="form-group"><div class="form-check"><input type="checkbox" name="can_change_station" id="addChange" checked><label for="addChange">Can change kiosk station</label></div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-pen"></i> Edit Authorized Card</h2><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div><form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
<div class="modal-body">
<div class="form-group"><label>Card UID</label><code id="editUid" style="font-size:16px;"></code></div>
<div class="form-group"><label>Name</label><input type="text" name="name" id="editName" class="form-control" required></div>
<div class="form-group"><label>Role</label><select name="role" id="editRole" class="form-control"><option value="registrar">Registrar</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
<div class="form-group"><div class="form-check"><input type="checkbox" name="can_change_station" id="editChange"><label for="editChange">Can change kiosk station</label></div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openAdd() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
['addModal','editModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeModal('addModal'); closeModal('editModal'); } });
function openEdit(id, uid, name, role, canChange) {
    document.getElementById('editId').value = id;
    document.getElementById('editUid').textContent = uid;
    document.getElementById('editName').value = name;
    document.getElementById('editRole').value = role;
    document.getElementById('editChange').checked = canChange;
    openModal('editModal');
}
</script>
<?php include '../includes/footer.php'; ?>
