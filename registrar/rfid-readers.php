<?php
// ============================================================
//  REGISTRAR/RFID-READERS.PHP
//  Card reader management — add, edit, deactivate (gen-2)
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$readers = $db->fetchAll("SELECT * FROM card_readers ORDER BY name");

$page_title = 'Card Readers';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">
<header class="header">
<div class="title"><h1>Card Readers</h1><p>Manage RFID reader stations</p></div>
<div class="header-actions">
<a href="rfid-cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
<button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Reader</button>
</div>
</header>

<div class="panel">
<div class="table-responsive" style="overflow-x:auto;">
<table class="table"><thead><tr><th>Name</th><th>Location</th><th>Code</th><th>Type</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if(empty($readers)): ?>
<tr><td colspan="6" class="empty-state"><i class="fas fa-tower-broadcast"></i><p>No readers registered</p><span>Add a reader station to start scanning</span></td></tr>
<?php else: foreach($readers as $r): ?>
<tr data-id="<?=(int)$r['id']?>">
<td style="font-weight:600;"><?=htmlspecialchars($r['name'])?></td>
<td><?=htmlspecialchars($r['location'])?></td>
<td><code style="background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px;"><?=htmlspecialchars($r['reader_code'])?></code></td>
<td><span class="chip blue"><?=htmlspecialchars(ucfirst($r['reader_type']))?></span></td>
<td><span class="pill <?=$r['status']?>"><?=ucfirst(htmlspecialchars($r['status']))?></span></td>
<td style="text-align:center;"><div class="action-group">
<button class="action-btn edit" onclick="openEditModal(<?=(int)$r['id']?>,'<?=htmlspecialchars($r['name'],ENT_QUOTES)?>','<?=htmlspecialchars($r['location'],ENT_QUOTES)?>','<?=htmlspecialchars($r['reader_code'],ENT_QUOTES)?>','<?=htmlspecialchars($r['reader_type'])?>')" title="Edit"><i class="fas fa-pen"></i></button>
<button class="action-btn delete" onclick="confirmDelete(<?=(int)$r['id']?>,'<?=htmlspecialchars($r['name'],ENT_QUOTES)?>')" title="Deactivate"><i class="fas fa-trash-alt"></i></button>
</div></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div>
<div class="table-footer"><div class="info-text">Showing <strong><?= count($readers) ?></strong> reader<?= count($readers) === 1 ? '' : 's' ?></div></div>
</div>
</div>
</main>

<!-- Add/Edit Reader Modal -->
<div class="modal-overlay" id="readerModal">
<div class="modal-content"><div class="modal-header"><h2><i class="fas fa-tower-broadcast"></i> <span id="readerModalTitle">Add Reader</span></h2><button class="modal-close" onclick="closeModal('readerModal')"><i class="fas fa-times"></i></button></div>
<form id="readerForm">
<input type="hidden" id="readerId" value="">
<div class="modal-body">
<p style="font-size:13px;color:#64748b;margin:0 0 14px;" id="readerModalSub">Register a new RFID reader station.</p>
<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
<div class="form-group"><label>Reader Name</label><input type="text" id="readerName" class="form-control" placeholder="e.g. Main Gate Reader" required /></div>
<div class="form-group"><label>Reader Code</label><input type="text" id="readerCode" class="form-control" placeholder="e.g. GATE-001" required /></div>
</div>
<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
<div class="form-group"><label>Location</label><input type="text" id="readerLocation" class="form-control" placeholder="e.g. Main Entrance" required /></div>
<div class="form-group"><label>Type</label><select id="readerType" class="form-control"><option value="entrance">Entrance</option><option value="exit">Exit</option><option value="both">Both</option></select></div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-light" onclick="closeModal('readerModal')">Cancel</button>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
</div>
</form>
</div>
</div>

<!-- Deactivate Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
<div class="modal-content" style="max-width:420px;"><div class="modal-header"><h2><i class="fas fa-trash-alt"></i> Deactivate Reader</h2><button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button></div>
<div class="modal-body"><p style="font-size:14px;color:#64748b;margin:0;" id="deleteMessage">Are you sure?</p></div>
<div class="modal-footer">
<button class="btn btn-light" onclick="closeModal('deleteModal')">Cancel</button>
<button class="btn btn-danger" id="deleteConfirm"><i class="fas fa-trash-alt"></i> Deactivate</button>
</div>
</div>
</div>

<script>
let deleteTarget=null;
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openAddModal(){
    document.getElementById('readerId').value='';
    document.getElementById('readerForm').reset();
    document.getElementById('readerModalTitle').textContent='Add Reader';
    document.getElementById('readerModalSub').textContent='Register a new RFID reader station.';
    openModal('readerModal');
}
function openEditModal(id,name,location,code,type){
    document.getElementById('readerId').value=id;
    document.getElementById('readerName').value=name;
    document.getElementById('readerLocation').value=location;
    document.getElementById('readerCode').value=code;
    document.getElementById('readerType').value=type;
    document.getElementById('readerModalTitle').textContent='Edit Reader';
    document.getElementById('readerModalSub').textContent='Update reader details.';
    openModal('readerModal');
}
function confirmDelete(id,name){
    deleteTarget=id;
    document.getElementById('deleteMessage').textContent='Deactivate reader "'+name+'"? It will no longer accept scans.';
    openModal('deleteModal');
}
['readerModal','deleteModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click',function(e){ if(e.target===this){ closeModal(id); if(id==='deleteModal') deleteTarget=null; } });
});
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeModal('readerModal'); closeModal('deleteModal'); deleteTarget=null; } });
document.getElementById('deleteConfirm').addEventListener('click',function(){
    if(!deleteTarget)return;
    fetch('../api/card-readers.php?id='+deleteTarget,{method:'DELETE'}).then(r=>r.json()).then(d=>{ if(d.success){ showToast('Reader deactivated.', 'success'); window.location.reload(); } else showToast(d.message||'Error.', 'error'); });
});
document.getElementById('readerForm').addEventListener('submit',async function(e){
e.preventDefault();const id=document.getElementById('readerId').value;
const body={name:document.getElementById('readerName').value,location:document.getElementById('readerLocation').value,reader_code:document.getElementById('readerCode').value,reader_type:document.getElementById('readerType').value};
const url=id?'../api/card-readers.php?id='+id:'../api/card-readers.php';const method=id?'PUT':'POST';
try{const res=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const d=await res.json();if(d.success){ showToast('Reader saved.', 'success'); window.location.reload(); } else showToast(d.message||'Error.', 'error');}catch(e){showToast('Network error.', 'error');}});
</script>
<?php include '../includes/footer.php'; ?>
