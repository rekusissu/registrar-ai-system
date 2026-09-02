<?php
// ============================================================
//  REGISTRAR/FILE-STORAGE.PHP
//  Subsystem 9 — Digital File Storage
//  Upload / preview / download / delete student documents.
//  Built on the shared registrar.css design system.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Student dropdown options (for upload target)
$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name
     FROM students WHERE status != 'archived'
     ORDER BY last_name, first_name"
);

// Files, optionally pre-filtered by ?student_id=
$fileStudentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if ($fileStudentId) {
    $files = $db->fetchAll(
        "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
         FROM documents d LEFT JOIN students s ON d.student_id = s.id
         WHERE d.student_id = ? ORDER BY d.created_at DESC", [$fileStudentId]);
} else {
    $files = $db->fetchAll(
        "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
         FROM documents d LEFT JOIN students s ON d.student_id = s.id
         ORDER BY d.created_at DESC");
}

// Stats
$totalFiles = count($files);
$byType = $db->fetchAll("SELECT doc_type, COUNT(*) AS c FROM documents GROUP BY doc_type");
$typeMap = ['enrollment','transcript','health','photo','clearance','other'];
$typeCounts = array_fill_keys($typeMap, 0);
foreach ($byType as $t) $typeCounts[$t['doc_type']] = (int)$t['c'];
$sumBytes = (int) $db->fetchColumn("SELECT COALESCE(SUM(file_size),0) FROM documents");
function fmtBytes($b) {
    if ($b >= 1073741824) return round($b/1073741824, 1).' GB';
    if ($b >= 1048576) return round($b/1048576, 1).' MB';
    if ($b >= 1024) return round($b/1024, 1).' KB';
    return $b.' B';
}
$isImage = function($t) { return in_array(strtolower($t), ['jpg','jpeg','png','webp','gif']); };

$page_title = 'File Storage';
$APP_ROOT = '../';
$ACTIVE_NAV = 'filestorage';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* Module-specific tweaks only — layout comes from registrar.css */
.file-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.file-state { display:flex; align-items:center; gap:10px; }
.fname { font-weight:600; color:#0f172a; font-size:13px; word-break:break-all; }
.fmeta { font-size:11px; color:#94a3b8; }
.thumb { width:44px; height:44px; object-fit:cover; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
.dropzone { border:2px dashed #cbd5e1; border-radius:12px; padding:22px 16px; text-align:center;
            color:#64748b; background:#f8fafc; cursor:pointer; transition:all .15s; }
.dropzone:hover, .dropzone.over { border-color:#2563eb; background:#eef4ff; }
.category-tags { display:flex; gap:6px; flex-wrap:wrap; }
</style>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Digital File Storage</h1>
                <p>Store, preview and release student documents (Form 137, transcripts, clearances)</p>
            </div>
            <div class="header-actions">
                <?php if ($fileStudentId): ?>
                    <a href="file-storage.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> All Files</a>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="openUpload()"><i class="fas fa-upload"></i> Upload File</button>
            </div>
        </header>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-folder-open"></i></div></div><div class="stat-number"><?= $totalFiles ?></div><div class="stat-label">Total Files</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-file-pdf"></i></div></div><div class="stat-number"><?= $typeCounts['transcript'] + $typeCounts['enrollment'] ?></div><div class="stat-label">Records &amp; Transcripts</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-heartbeat"></i></div></div><div class="stat-number"><?= $typeCounts['health'] ?></div><div class="stat-label">Health Documents</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-database"></i></div></div><div class="stat-number"><?= fmtBytes($sumBytes) ?></div><div class="stat-label">Total Storage</div></div>
        </div>

        <!-- Table panel -->
        <div class="panel">
            <div class="panel-toolbar">
                <div class="search-toolbar" style="flex:1;padding:0;background:transparent;border:none;">
                    <div class="search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="fileSearch" placeholder="Search by student, filename or type...">
                    </div>
                </div>
                <div class="panel-actions">
                    <select id="typeFilter" class="form-control" style="width:auto;height:40px;" onchange="applyFilter()">
                        <option value="">All types</option>
                        <option value="enrollment">Enrollment</option>
                        <option value="transcript">Transcript</option>
                        <option value="health">Health</option>
                        <option value="photo">Photo</option>
                        <option value="clearance">Clearance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive" style="overflow-x:auto;">
            <table class="table">
                <thead><tr><th>File</th><th>Student</th><th>Type</th><th>Size</th><th>Uploaded</th><th style="text-align:center;">Actions</th></tr></thead>
                <tbody id="fileBody">
                <?php if (empty($files)): ?>
                    <tr><td colspan="6" style="height:60vh;text-align:center;"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;"><i class="fas fa-folder-open" style="font-size:40px;color:#cbd5e1;margin-bottom:12px;"></i><p style="font-size:15px;font-weight:600;color:#64748b;margin:0 0 4px;">No files stored</p><span style="font-size:13px;color:#94a3b8;">Upload a document to get started</span></div></td></tr>
                <?php else: foreach ($files as $f):
                    $ext = strtolower($f['file_type'] ?? '');
                    $icons = ['pdf'=>'fa-file-pdf red','doc'=>'fa-file-word blue','docx'=>'fa-file-word blue','xls'=>'fa-file-excel green','xlsx'=>'fa-file-excel green','jpg'=>'fa-file-image purple','jpeg'=>'fa-file-image purple','png'=>'fa-file-image purple','webp'=>'fa-file-image purple','txt'=>'fa-file-lines gray','zip'=>'fa-file-zipper gray'];
                    $ic = $icons[$ext] ?? 'fa-file gray';
                    $iCol = str_contains($ic, 'red') ? 'background:#fee2e2;color:#dc2626;' : (str_contains($ic, 'blue') ? 'background:#eef4ff;color:#2563eb;' : (str_contains($ic, 'green') ? 'background:#dcfce7;color:#16a34a;' : (str_contains($ic, 'purple') ? 'background:#f3e8ff;color:#7c3aed;' : 'background:#f1f5f9;color:#64748b;')));
                ?>
                    <tr data-id="<?= (int)$f['id'] ?>"
                        data-name="<?= htmlspecialchars($f['filename'], ENT_QUOTES) ?>"
                        data-path="<?= htmlspecialchars($f['file_path'], ENT_QUOTES) ?>"
                        data-type="<?= htmlspecialchars($f['file_type'], ENT_QUOTES) ?>"
                        data-student="<?= htmlspecialchars($f['student_name'] ?? '', ENT_QUOTES) ?>"
                        data-doctype="<?= htmlspecialchars($f['doc_type'], ENT_QUOTES) ?>"
                        data-desc="<?= htmlspecialchars($f['description'] ?? '', ENT_QUOTES) ?>">
                        <td>
                            <div class="file-state">
                                <?php if ($isImage($ext)): ?>
                                    <img class="thumb" src="<?= htmlspecialchars($f['file_path']) ?>" alt="preview" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="file-icon" style="<?= $iCol ?>"><i class="fas <?= explode(' ', $ic)[0] ?>"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fname"><?= htmlspecialchars($f['filename']) ?></div>
                                    <?php if ($f['description']): ?><div class="fmeta"><?= htmlspecialchars($f['description']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div><div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($f['student_name'] ?? '—') ?></div>
                            <div class="fmeta"><?= htmlspecialchars($f['student_number'] ?? '') ?></div></div>
                        </td>
                        <td><span class="chip blue"><?= htmlspecialchars(ucfirst($f['doc_type'])) ?></span></td>
                        <td style="color:#64748b;"><?= fmtBytes((int)$f['file_size']) ?></td>
                        <td class="fmeta"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                        <td><div class="action-group">
                            <button class="action-btn view" onclick="previewFile(this)" title="Preview"><i class="fas fa-eye"></i></button>
                            <a class="action-btn download" href="<?= htmlspecialchars($f['file_path']) ?>" title="Download" download><i class="fas fa-download"></i></a>
                            <button class="action-btn delete" onclick="deleteFile(this)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <div class="table-footer">
                <div class="info-text">Showing <strong id="shownCount"><?= count($files) ?></strong> of <strong><?= count($files) ?></strong> files</div>
            </div>
        </div>
    </div>
</main>

<!-- Upload Modal -->
<div class="modal-overlay" id="uploadModal"><div class="modal-content wide">
    <div class="modal-header"><h2><i class="fas fa-upload" style="color:#2563eb;"></i> Upload Document</h2><button class="modal-close" onclick="closeModal('uploadModal')"><i class="fas fa-times"></i></button></div>
    <form id="uploadForm">
    <div class="modal-body">
        <div class="form-group"><label>Student <span style="color:#dc2626;">*</span></label>
            <select id="upStudent" class="form-control" data-searchable required>
                <option value="">Select a student</option>
                <?php foreach ($students as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= $fileStudentId && $st['id'] === $fileStudentId ? 'selected' : '' ?>><?= htmlspecialchars($st['student_number'].' — '.$st['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Document Type</label>
                <select id="upType" class="form-control">
                    <option value="enrollment">Enrollment</option>
                    <option value="transcript">Transcript / Records</option>
                    <option value="health">Health</option>
                    <option value="photo">Photo</option>
                    <option value="clearance">Clearance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group"><label>Category (optional)</label><input type="text" id="upCategory" class="form-control" placeholder="e.g. Form 137, NCAE..."></div>
        </div>
        <div id="upDropzone" class="dropzone">
            <i class="fas fa-cloud-arrow-up" style="font-size:26px;display:block;margin-bottom:8px;color:#94a3b8;"></i>
            <div style="font-size:13px;"><strong>Drag &amp; drop a file</strong> or <span style="color:#2563eb;text-decoration:underline;">click to browse</span></div>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">PDF, Word, Excel, images, text · up to 25 MB</div>
            <input type="file" id="upFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.txt,.zip" style="display:none;">
        </div>
        <div id="upFileName" style="font-size:12px;color:#16a34a;margin-top:8px;"></div>
        <div class="form-group" style="margin-top:12px;"><label>Description</label><textarea id="upDesc" class="form-control" rows="2" placeholder="What this document is..."></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" onclick="closeModal('uploadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="upSubmit"><i class="fas fa-upload"></i> Upload</button>
    </div>
    </form>
</div></div>

<!-- Preview Modal -->
<div class="modal-overlay" id="previewModal"><div class="modal-content wide">
    <div class="modal-header"><h2><i class="fas fa-file-lines" style="color:#2563eb;"></i> <span id="pvFileName">File</span></h2><button class="modal-close" onclick="closeModal('previewModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
        <div id="pvMeta" style="font-size:12px;color:#64748b;margin-bottom:12px;"></div>
        <div id="pvContent" style="text-align:center;padding:10px;background:#f8fafc;border-radius:12px;min-height:120px;"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" onclick="closeModal('previewModal')">Close</button>
        <a id="pvDownload" class="btn btn-primary" download><i class="fas fa-download"></i> Download</a>
    </div>
</div></div>

<script>
// ─── MODAL HELPERS ─────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
['uploadModal','previewModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => { if (e.target === document.getElementById(id)) closeModal(id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal('uploadModal'); closeModal('previewModal'); } });

// ─── UPLOAD ──────────────────────────────────────────────────
function openUpload() {
    const fi = document.getElementById('upFile');
    fi.value = '';
    document.getElementById('upFileName').textContent = '';
    document.getElementById('upDesc').value = '';
    document.getElementById('upCategory').value = '';
    openModal('uploadModal');
}
// dropzone wiring
(function() {
    const dz = document.getElementById('upDropzone');
    const fi = document.getElementById('upFile');
    const nameEl = document.getElementById('upFileName');
    function showName() {
        nameEl.textContent = fi.files.length ? 'Selected: ' + fi.files[0].name + ' (' + (fi.files[0].size/1024).toFixed(1) + ' KB)' : '';
    }
    dz.addEventListener('click', e => { if (e.target !== fi) fi.click(); });
    fi.addEventListener('change', showName);
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dz.classList.add('over'); }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dz.classList.remove('over'); }));
    dz.addEventListener('drop', e => { const f = e.dataTransfer.files; if (f.length) { fi.files = f; showName(); } });
})();

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const studentId = document.getElementById('upStudent').value;
    const file = document.getElementById('upFile').files[0];
    if (!studentId) { alert('Select a student.'); return; }
    if (!file) { alert('Choose a file.'); return; }
    const btn = document.getElementById('upSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    const fd = new FormData();
    fd.append('student_id', studentId);
    fd.append('doc_type', document.getElementById('upType').value);
    fd.append('category', document.getElementById('upCategory').value);
    fd.append('description', document.getElementById('upDesc').value);
    fd.append('file', file);
    fetch('../api/documents.php?section=files', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.success) { showToast('Uploaded', 'Document stored.', 'success'); setTimeout(() => window.location.reload(), 700); } else alert(d.message || 'Upload failed.'); })
    .catch(() => alert('Network error.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Upload'; });
});

// ─── SEARCH + FILTER ─────────────────────────────────────────
const allRows = Array.from(document.querySelectorAll('#fileBody tr[data-id]'));
const searchInput = document.getElementById('fileSearch');
const shownCount = document.getElementById('shownCount');
function applyFilter() {
    const q = searchInput.value.trim().toLowerCase();
    const t = document.getElementById('typeFilter').value;
    let visible = 0;
    allRows.forEach(r => {
        const s = r.dataset.name.toLowerCase() + ' ' + r.dataset.student.toLowerCase() + ' ' + r.dataset.doctype.toLowerCase();
        const okQ = !q || s.includes(q);
        const okT = !t || r.dataset.doctype === t;
        r.style.display = okQ && okT ? '' : 'none';
        if (okQ && okT) visible++;
    });
    shownCount.textContent = visible;
}
searchInput.addEventListener('input', applyFilter);

// ─── PREVIEW ─────────────────────────────────────────────────
function previewFile(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('pvFileName').textContent = d.name;
    document.getElementById('pvMeta').innerHTML = '<b>' + (d.student || '') + '</b> · ' + d.type.toUpperCase() + ' · ' + (d.desc || 'No description');
    document.getElementById('pvDownload').href = d.path;
    const ext = d.type.toLowerCase();
    const c = document.getElementById('pvContent');
    if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
        c.innerHTML = '<img src="' + d.path + '" alt="preview" style="max-width:100%;max-height:420px;border-radius:8px;">';
    } else if (ext === 'pdf') {
        c.innerHTML = '<iframe src="' + d.path + '" style="width:100%;height:480px;border:none;border-radius:8px;background:#fff;"></iframe>';
    } else if (ext === 'txt') {
        fetch(d.path).then(r => r.text()).then(t => { c.innerHTML = '<pre style="text-align:left;font-size:12px;white-space:pre-wrap;max-height:420px;overflow:auto;">' + t.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>'; }).catch(() => c.innerHTML = '<p style="color:#64748b;">Preview not available.</p>');
    } else {
        c.innerHTML = '<span style="font-size:32px;color:#94a3b8;"><i class="fas fa-file"></i></span><p style="color:#64748b;margin-top:8px;">No inline preview for .' + ext + ' — download to view.</p>';
    }
    openModal('previewModal');
}

// ─── DELETE ──────────────────────────────────────────────────
let deleteTarget = null;
function deleteFile(btn) {
    const d = btn.closest('tr').dataset;
    if (!confirm('Delete "' + d.name + '" for ' + d.student + '? This cannot be undone.')) return;
    fetch('../api/documents.php?section=files&action=delete', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: d.id })
    }).then(r => r.json()).then(res => {
        if (res.success) { showToast('Deleted', 'File removed.', 'success'); setTimeout(() => window.location.reload(), 500); }
        else alert(res.message || 'Delete failed.');
    }).catch(() => alert('Network error.'));
}

// ─── TOAST (shared helper if not already present) ────────────
function showToast(title, message, type) {
    let c = document.querySelector('.toast-container');
    if (!c) { c = document.createElement('div'); c.className = 'toast-container'; document.body.appendChild(c); }
    const t = document.createElement('div'); t.className = 'toast ' + (type || 'info');
    t.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info') + ' toast-icon"></i><div class="toast-content"><div class="toast-title"></div><div class="toast-message"></div></div><button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>';
    t.querySelector('.toast-title').textContent = title;
    t.querySelector('.toast-message').textContent = message;
    t.querySelector('.toast-close').addEventListener('click', () => { t.classList.add('hiding'); setTimeout(() => t.remove(), 300); });
    c.appendChild(t); setTimeout(() => { t.classList.add('hiding'); setTimeout(() => t.remove(), 300); }, 4000);
}
</script>

<?php include '../includes/footer.php'; ?>