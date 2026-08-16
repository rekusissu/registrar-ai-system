<?php
// ============================================================
//  REGISTRAR/USERS.PHP
//  User Management (admin-only) — create, edit, enable/disable,
//  reset passwords. Matches the students.php gen-2 design.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$users = $db->fetchAll("
    SELECT u.id, u.email, u.full_name, u.role, u.is_active, u.created_at, u.updated_at,
           u.student_id,
           CONCAT(s.first_name, ' ', s.last_name) AS student_name,
           s.student_number
    FROM users u
    LEFT JOIN students s ON s.id = u.student_id
    ORDER BY u.id ASC
");

$totalUsers    = count($users);
$activeUsers   = count(array_filter($users, fn($u) => (int)$u['is_active'] === 1));
$disabledUsers = $totalUsers - $activeUsers;
$adminUsers    = count(array_filter($users, fn($u) => $u['role'] === 'admin'));

$page_title = 'Users';
$APP_ROOT = '../';
$ACTIVE_NAV = 'users';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title"><h1>Users</h1><p>Manage system accounts and roles</p></div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add User</button>
    </div>
</header>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-users"></i></div></div><div class="stat-number"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-check"></i></div></div><div class="stat-number"><?= $activeUsers ?></div><div class="stat-label">Active</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-user-slash"></i></div></div><div class="stat-number"><?= $disabledUsers ?></div><div class="stat-label">Disabled</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-user-shield"></i></div></div><div class="stat-number"><?= $adminUsers ?></div><div class="stat-label">Admins</div></div>
</div>

<!-- Search + Table -->
<div class="panel">
    <div class="search-toolbar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="userSearch" placeholder="Search by name or email...">
        </div>
        <select id="roleFilter" class="form-control" style="width:auto;height:40px;" onchange="performSearch()">
            <option value="">All roles</option>
            <option value="admin">Admin</option>
            <option value="registrar">Registrar</option>
            <option value="staff">Staff</option>
            <option value="teacher">Teacher</option>
            <option value="student">Student</option>
        </select>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr><th>User</th><th>Role</th><th>Linked Student</th><th>Status</th><th>Created</th><th style="text-align:center;">Actions</th></tr>
        </thead>
        <tbody id="userBody">
        <?php if (empty($users)): ?>
        <tr><td colspan="6" class="empty-state"><i class="fas fa-user-slash"></i><p>No users found</p><span>Add your first user to get started</span></td></tr>
        <?php else:
        $avatarColors = ['blue','green','purple','orange','pink'];
        foreach ($users as $i => $u):
            $initials = strtoupper(substr($u['full_name'],0,1));
            if (strpos($u['full_name'],' ') !== false) {
                $parts = explode(' ', trim($u['full_name']));
                $initials = strtoupper(substr($parts[0],0,1).substr(end($parts),0,1));
            }
            $ac = $avatarColors[$i % count($avatarColors)];
        ?>
        <tr data-user='<?= htmlspecialchars(json_encode($u),ENT_QUOTES,'UTF-8') ?>' class="<?= (int)$u['is_active']===0?'archived':'' ?>">
            <td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($u['full_name']) ?></div><div class="student-sub"><?= htmlspecialchars($u['email']) ?></div></div></div></td>
            <td><span class="pill <?= htmlspecialchars($u['role']) ?>"><i class="fas fa-<?= $u['role']==='admin'?'user-shield':($u['role']==='student'?'user-graduate':($u['role']==='registrar'?'user-tie':'user')) ?>"></i> <?= ucfirst($u['role']) ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= !empty($u['student_name']) ? htmlspecialchars($u['student_name']) . ' <span style="color:#94a3b8;">(' . htmlspecialchars($u['student_number']) . ')</span>' : '<span style="color:#94a3b8;">—</span>' ?></td>
            <td><span class="pill <?= (int)$u['is_active']===1?'active':'inactive' ?>"><span class="status-dot <?= (int)$u['is_active']===1?'active':'inactive' ?>"></span><?= (int)$u['is_active']===1?'Active':'Disabled' ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td><div class="action-group">
                <button class="action-btn edit" onclick="editUser(<?= (int)$u['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                <button class="action-btn" style="color:#7c3aed;" onclick="resetPassword(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                <?php if ((int)$u['is_active']===1): ?>
                <button class="action-btn delete" onclick="toggleUser(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','disable')" title="Disable"><i class="fas fa-user-slash"></i></button>
                <?php else: ?>
                <button class="action-btn restore" onclick="toggleUser(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','enable')" title="Enable"><i class="fas fa-user-check"></i></button>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="showingCount"><?= count($users) ?></strong> of <strong id="totalCount"><?= count($users) ?></strong> users</div>
    </div>
</div>

</div>
</main>

<!-- Add User Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-user-plus"></i> Add User</h2><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form id="addForm"><div class="modal-body">
    <div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFullName" class="form-control" required></div>
    <div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" id="addEmail" class="form-control" required></div>
    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Role</label><select id="addRole" class="form-control" onchange="onRoleChange()"><option value="staff">Staff</option><option value="registrar">Registrar</option><option value="teacher">Teacher</option><option value="admin">Admin</option><option value="student">Student</option></select></div>
        <div class="form-group"><label>Password <span style="color:#dc2626;">*</span></label><input type="password" id="addPassword" class="form-control" required minlength="6" placeholder="Min 6 chars"></div>
    </div>
    <div class="form-group" id="addStudentLink" style="display:none;"><label>Link Student Record</label><select id="addStudentId" class="form-control"><option value="">— Select a student —</option></select></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create</button></div></form></div></div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-user-pen"></i> Edit User</h2><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
<form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
    <div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFullName" class="form-control" required></div>
    <div class="form-group"><label>Email</label><input type="email" id="editEmail" class="form-control" disabled style="background:#f8fafc;font-size:12px;"></div>
    <div class="form-group"><label>Role</label><select id="editRole" class="form-control" onchange="onRoleChange()"><option value="staff">Staff</option><option value="registrar">Registrar</option><option value="teacher">Teacher</option><option value="admin">Admin</option><option value="student">Student</option></select></div>
    <div class="form-group" id="editStudentLink" style="display:none;"><label>Link Student Record</label><select id="editStudentId" class="form-control"><option value="">— Not linked —</option></select></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="passwordModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-key"></i> Reset Password</h2><button class="modal-close" onclick="closeModal('passwordModal')"><i class="fas fa-times"></i></button></div>
<form id="passwordForm"><input type="hidden" id="passwordUserId" value=""><div class="modal-body">
    <p id="passwordTarget" style="font-size:13px;color:#64748b;margin-bottom:12px;"></p>
    <div class="form-group"><label>New Password <span style="color:#dc2626;">*</span></label><input type="password" id="passwordValue" class="form-control" required minlength="6" placeholder="Min 6 chars"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('passwordModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Set Password</button></div></form></div></div>

<script>
const allUsers = [];
document.querySelectorAll('#userBody tr[data-user]').forEach(row => {
    try { const d = JSON.parse(row.dataset.user); if (d) allUsers.push({ ...d, element: row }); } catch(e) {}
});
const showingCount = document.getElementById('showingCount');

// ── Student link dropdown population ─────────────────────────
let studentOptions = '<option value="">— Select a student —</option>';
fetch('../api/students.php')
    .then(r => r.json())
    .then(d => {
        if (d.success && Array.isArray(d.data)) {
            studentOptions = '<option value="">— Select a student —</option>';
            d.data.forEach(s => {
                const label = (s.first_name || '') + ' ' + (s.last_name || '') + ' — ' + (s.student_number || '');
                studentOptions += '<option value="' + s.id + '">' + label.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '</option>';
            });
            ['addStudentId', 'editStudentId'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = studentOptions;
            });
        }
    })
    .catch(() => {});

function onRoleChange() {
    const role = document.getElementById('addRole')?.value || document.getElementById('editRole')?.value || '';
    document.getElementById('addStudentLink').style.display = role === 'student' ? '' : 'none';
    document.getElementById('editStudentLink').style.display = role === 'student' ? '' : 'none';
}

function performSearch() {
    const q = document.getElementById('userSearch').value.trim().toLowerCase();
    const role = document.getElementById('roleFilter').value;
    let visible = 0;
    allUsers.forEach(u => {
        const matchQ = !q || (u.full_name||'').toLowerCase().includes(q) || (u.email||'').toLowerCase().includes(q);
        const matchRole = !role || u.role === role;
        const show = matchQ && matchRole;
        u.element.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    showingCount.textContent = visible;
}
document.getElementById('userSearch').addEventListener('input', performSearch);

function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
['addModal','editModal','passwordModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { ['addModal','editModal','passwordModal'].forEach(closeModal); } });

function openAddModal() {
    document.getElementById('addForm').reset();
    document.getElementById('addStudentLink').style.display = 'none';
    openModal('addModal');
}

function editUser(id) {
    const u = allUsers.find(x => String(x.id) === String(id));
    if (!u) return;
    document.getElementById('editId').value = u.id;
    document.getElementById('editFullName').value = u.full_name;
    document.getElementById('editEmail').value = u.email;
    document.getElementById('editRole').value = u.role;
    // Preselect the linked student in the dropdown
    const editStudentId = document.getElementById('editStudentId');
    if (editStudentId) {
        editStudentId.value = (u.student_id != null && u.student_id !== '') ? String(u.student_id) : '';
        document.getElementById('editStudentLink').style.display = u.role === 'student' ? '' : 'none';
    }
    openModal('editModal');
}

function resetPassword(id, name) {
    document.getElementById('passwordUserId').value = id;
    document.getElementById('passwordValue').value = '';
    document.getElementById('passwordTarget').textContent = 'Set a new password for ' + name + '.';
    openModal('passwordModal');
}

function toggleUser(id, name, action) {
    if (!confirm((action === 'disable' ? 'Disable' : 'Enable') + ' user ' + name + '?')) return;
    fetch('../api/users.php?id=' + id + (action === 'enable' ? '&action=enable' : ''), {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); }).catch(() => alert('Error.'));
}

async function submitJson(url, method, body) {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await res.json();
}

document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    try {
        const d = await submitJson('../api/users.php', 'POST', {
            full_name: document.getElementById('addFullName').value,
            email: document.getElementById('addEmail').value,
            role: document.getElementById('addRole').value,
            password: document.getElementById('addPassword').value,
            student_id: document.getElementById('addStudentId').value || null
        });
        if (d.success) { alert('User created.'); window.location.reload(); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Create'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Create'; }
});

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('editId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('../api/users.php?id=' + id, 'PUT', {
            full_name: document.getElementById('editFullName').value,
            role: document.getElementById('editRole').value,
            student_id: document.getElementById('editStudentId').value || null
        });
        if (d.success) { alert('User updated.'); window.location.reload(); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
});

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('passwordUserId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('../api/users.php?id=' + id, 'PATCH', {
            action: 'password',
            password: document.getElementById('passwordValue').value
        });
        if (d.success) { alert('Password updated.'); window.location.reload(); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Set Password'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Set Password'; }
});
</script>

<?php include '../includes/footer.php'; ?>
