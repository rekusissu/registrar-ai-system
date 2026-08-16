<?php
// ============================================================
//  REGISTRAR/ANNOUNCEMENTS.PHP
//  Announcements management (admin/registrar) — compose, edit,
//  publish/unpublish, delete. Matches the users.php gen-2 design.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$announcements = $db->fetchAll(
    "SELECT a.*, u.full_name AS author_name
     FROM announcements a
     LEFT JOIN users u ON u.id = a.author_id
     ORDER BY a.created_at DESC"
);

$page_title = 'Announcements';
$APP_ROOT = '../';
$ACTIVE_NAV = 'announcements';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title"><h1>Announcements</h1><p>Publish updates for students</p></div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> New Announcement</button>
    </div>
</header>

<div class="panel">
    <div class="search-toolbar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="announcementSearch" placeholder="Search announcements...">
        </div>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr><th>Title</th><th>Author</th><th>Status</th><th>Published</th><th style="text-align:center;">Actions</th></tr>
        </thead>
        <tbody id="announcementBody">
        <?php if (empty($announcements)): ?>
        <tr><td colspan="5" class="empty-state"><i class="fas fa-bullhorn"></i><p>No announcements yet</p><span>Publish your first announcement to get started</span></td></tr>
        <?php else:
        foreach ($announcements as $i => $a): ?>
        <tr data-announcement='<?= htmlspecialchars(json_encode($a),ENT_QUOTES,'UTF-8') ?>' class="<?= (int)$a['is_published']===0?'archived':'' ?>">
            <td><div class="student-info"><div><div class="student-name"><?= htmlspecialchars($a['title']) ?></div><div class="student-sub"><?= nl2br(htmlspecialchars(mb_strimwidth($a['body'] ?? '', 0, 90, '…'))) ?></div></div></div></td>
            <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($a['author_name'] ?? '—') ?></td>
            <td><span class="pill <?= (int)$a['is_published']===1?'active':'inactive' ?>"><span class="status-dot <?= (int)$a['is_published']===1?'active':'inactive' ?>"></span><?= (int)$a['is_published']===1?'Published':'Draft' ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y h:i A', strtotime($a['created_at'])) ?></td>
            <td><div class="action-group">
                <button class="action-btn edit" onclick="editAnnouncement(<?= (int)$a['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                <button class="action-btn" style="color:#7c3aed;" onclick="togglePublish(<?= (int)$a['id'] ?>,<?= (int)$a['is_published'] ?>)" title="<?= (int)$a['is_published']===1?'Unpublish':'Publish' ?>"><i class="fas fa-<?= (int)$a['is_published']===1?'eye-slash':'eye' ?>"></i></button>
                <button class="action-btn delete" onclick="deleteAnnouncement(<?= (int)$a['id'] ?>,'<?= htmlspecialchars($a['title'],ENT_QUOTES) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong><?= count($announcements) ?></strong> of <strong><?= count($announcements) ?></strong> announcements</div>
    </div>
</div>

</div>
</main>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-bullhorn"></i> New Announcement</h2><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form id="addForm"><div class="modal-body">
    <div class="form-group"><label>Title <span style="color:#dc2626;">*</span></label><input type="text" id="addTitle" class="form-control" required></div>
    <div class="form-group"><label>Message</label><textarea id="addBody" class="form-control" rows="5" placeholder="What should students know?"></textarea></div>
    <div class="form-group"><label><input type="checkbox" id="addPublished" checked> Publish immediately</label></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publish</button></div></form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-bullhorn"></i> Edit Announcement</h2><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
<form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
    <div class="form-group"><label>Title <span style="color:#dc2626;">*</span></label><input type="text" id="editTitle" class="form-control" required></div>
    <div class="form-group"><label>Message</label><textarea id="editBody" class="form-control" rows="5"></textarea></div>
    <div class="form-group"><label><input type="checkbox" id="editPublished"> Publish immediately</label></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<script>
const allAnnouncements = [];
document.querySelectorAll('#announcementBody tr[data-announcement]').forEach(row => {
    try { const d = JSON.parse(row.dataset.announcement); if (d) allAnnouncements.push({ ...d, element: row }); } catch(e) {}
});

document.getElementById('announcementSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    allAnnouncements.forEach(a => {
        const match = !q || (a.title || '').toLowerCase().includes(q) || (a.body || '').toLowerCase().includes(q);
        a.element.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.querySelector('.info-text').textContent = 'Showing ' + visible + ' of ' + allAnnouncements.length + ' announcements';
});

function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
['addModal','editModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { ['addModal','editModal'].forEach(closeModal); } });

function openAddModal() {
    document.getElementById('addForm').reset();
    document.getElementById('addPublished').checked = true;
    openModal('addModal');
}

function editAnnouncement(id) {
    const a = allAnnouncements.find(x => String(x.id) === String(id));
    if (!a) return;
    document.getElementById('editId').value = a.id;
    document.getElementById('editTitle').value = a.title;
    document.getElementById('editBody').value = a.body || '';
    document.getElementById('editPublished').checked = a.is_published == 1;
    openModal('editModal');
}

async function submitJson(url, method, body) {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await res.json();
}

document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing...';
    try {
        const d = await submitJson('../api/announcements.php', 'POST', {
            title: document.getElementById('addTitle').value,
            body: document.getElementById('addBody').value,
            is_published: document.getElementById('addPublished').checked ? 1 : 0
        });
        if (d.success) { alert('Announcement published.'); window.location.reload(); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Publish'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Publish'; }
});

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('editId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('../api/announcements.php?id=' + id, 'PUT', {
            title: document.getElementById('editTitle').value,
            body: document.getElementById('editBody').value,
            is_published: document.getElementById('editPublished').checked ? 1 : 0
        });
        if (d.success) { alert('Announcement updated.'); window.location.reload(); }
        else { alert(d.message); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
    } catch(e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
});

function togglePublish(id, current) {
    const target = current ? 0 : 1;
    fetch('../api/announcements.php?id=' + id, {
        method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_published: target })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); }).catch(() => alert('Error.'));
}

function deleteAnnouncement(id, title) {
    if (!confirm('Delete announcement "' + title + '"?')) return;
    fetch('../api/announcements.php?id=' + id, { method: 'DELETE' })
        .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else alert(d.message); })
        .catch(() => alert('Error.'));
}
</script>

<?php include '../includes/footer.php'; ?>
