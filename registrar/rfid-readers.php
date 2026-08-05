<?php
// ============================================================
//  REGISTRAR/RFID-READERS.PHP
//  Card reader management — add, edit, deactivate
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
<style>
:root { --sidebar-width:260px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; box-sizing:border-box; }
.header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid #e8eaef; gap:16px; flex-wrap:wrap; }
.header .title h1 { font-size:22px; font-weight:700; color:#1e293b; margin:0 0 2px; }
.header .title p { font-size:13px; color:#64748b; margin:0; }
.header-actions { display:flex; align-items:center; gap:8px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; border:1.5px solid transparent; text-decoration:none; font-family:inherit; }
.btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; }
.btn-primary:hover { transform:translateY(-1px); }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; border-color:#cbd5e1; color:#0f172a; }
.btn-light { background:#f1f5f9; color:#475569; }
.btn-light:hover { background:#e2e8f0; color:#0f172a; }
.table-wrapper { background:white; border-radius:14px; border:1px solid #e2e8f0; overflow:hidden; }
table { width:100%; border-collapse:collapse; }
th { text-align:left; padding:11px 16px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; }
td { padding:11px 16px; font-size:14px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }
.badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; }
.badge.active { background:#dcfce7; color:#16a34a; }
.badge.inactive { background:#fee2e2; color:#dc2626; }
.badge-type { background:#eef4ff; color:#2563eb; }
.action-btn { width:32px; height:32px; border:none; background:transparent; border-radius:8px; cursor:pointer; font-size:13px; }
.action-btn:hover { background:#f1f5f9; }
.action-btn.edit { color:#b45309; } .action-btn.edit:hover { background:#fef3c7; }
.action-btn.delete { color:#dc2626; } .action-btn.delete:hover { background:#fee2e2; }
code { background:#f1f5f9; padding:3px 8px; border-radius:5px; font-size:12px; }
.form-row { display:flex; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
.form-group { flex:1; min-width:160px; }
.form-group label { display:block; font-size:12px; color:#475569; margin-bottom:4px; font-weight:600; }
.form-control { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; font-family:inherit; outline:none; background:white; color:#1e293b; box-sizing:border-box; }
.logout-modal select.form-control,
.logout-modal select { appearance:auto !important; -webkit-appearance:auto !important; cursor:pointer !important; }
.form-control:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
@media(max-width:768px) { .dashboard-main { margin-left:0; width:100%; max-width:100%; padding:18px 14px; } }
</style>
<main class="dashboard-main">
<header class="header">
<div class="title"><h1>Card Readers</h1><p>Manage RFID reader stations</p></div>
<div class="header-actions">
<a href="rfid-cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
<button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Reader</button>
</div>
</header>
<div class="table-wrapper">
<table><thead><tr><th>Name</th><th>Location</th><th>Code</th><th>Type</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody>
<?php if(empty($readers)): ?>
<tr><td colspan="6" style="text-align:center;padding:40px 12px;color:#94a3b8;">No readers registered.</td></tr>
<?php else: foreach($readers as $r): ?>
<tr data-id="<?=(int)$r['id']?>">
<td><strong><?=htmlspecialchars($r['name'])?></strong></td>
<td><?=htmlspecialchars($r['location'])?></td>
<td><code><?=htmlspecialchars($r['reader_code'])?></code></td>
<td><span class="badge badge-type"><?=htmlspecialchars($r['reader_type'])?></span></td>
<td><span class="badge <?=$r['status']?>"><?=ucfirst(htmlspecialchars($r['status']))?></span></td>
<td style="text-align:center;">
<button class="action-btn edit" onclick="openEditModal(<?=(int)$r['id']?>,'<?=htmlspecialchars($r['name'],ENT_QUOTES)?>','<?=htmlspecialchars($r['location'],ENT_QUOTES)?>','<?=htmlspecialchars($r['reader_code'],ENT_QUOTES)?>','<?=htmlspecialchars($r['reader_type'])?>')" title="Edit"><i class="fas fa-pen"></i></button>
<button class="action-btn delete" onclick="confirmDelete(<?=(int)$r['id']?>,'<?=htmlspecialchars($r['name'],ENT_QUOTES)?>')" title="Delete"><i class="fas fa-trash-alt"></i></button>
</td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></main>

<div class="logout-modal-overlay" id="readerModal">
<div class="logout-modal" style="max-width:500px;text-align:left;">
<div class="rfid-modal-header" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
<div class="header-icon" style="width:44px;height:44px;border-radius:12px;background:#eef4ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-hard-hat"></i></div>
<div><h3 id="readerModalTitle" style="font-size:18px;font-weight:700;color:#0f172a;margin:0;">Add Reader</h3><p style="font-size:13px;color:#64748b;margin:2px 0 0;" id="readerModalSub">Register a new RFID reader station.</p></div>
</div>
<form id="readerForm">
<input type="hidden" id="readerId" value="">
<div class="form-row">
<div class="form-group"><label>Reader Name</label><input type="text" id="readerName" class="form-control" placeholder="e.g. Main Gate Reader" required /></div>
<div class="form-group"><label>Reader Code</label><input type="text" id="readerCode" class="form-control" placeholder="e.g. GATE-001" required /></div>
</div>
<div class="form-row">
<div class="form-group"><label>Location</label><input type="text" id="readerLocation" class="form-control" placeholder="e.g. Main Entrance" required /></div>
<div class="form-group"><label>Type</label><select id="readerType" class="form-control"><option value="entrance">Entrance</option><option value="exit">Exit</option><option value="both">Both</option></select></div>
</div>
<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid #f1f5f9;">
<button type="button" class="btn btn-light" onclick="document.getElementById('readerModal').classList.remove('active');document.body.style.overflow='';">Cancel</button>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
</div>
</form>
</div>
</div>

<div class="logout-modal-overlay" id="deleteModal">
<div class="logout-modal">
<div class="logout-modal-icon" style="background:#fee2e2;"><i class="fas fa-trash-alt" style="color:#dc2626;"></i></div>
<h3 class="logout-modal-title">Deactivate Reader</h3>
<p class="logout-modal-message" id="deleteMessage">Are you sure?</p>
<div class="logout-modal-actions">
<button class="logout-btn-cancel" id="deleteCancel">Cancel</button>
<button class="logout-btn-confirm" id="deleteConfirm" style="background:#dc2626;"><i class="fas fa-trash-alt"></i> Deactivate</button>
</div></div></div>

<script>
let deleteTarget=null;
function openAddModal(){document.getElementById('readerId').value='';document.getElementById('readerForm').reset();document.getElementById('readerModalTitle').textContent='Add Reader';document.getElementById('readerModalSub').textContent='Register a new RFID reader station.';document.getElementById('readerModal').classList.add('active');document.body.style.overflow='hidden';}
function openEditModal(id,name,location,code,type){document.getElementById('readerId').value=id;document.getElementById('readerName').value=name;document.getElementById('readerLocation').value=location;document.getElementById('readerCode').value=code;document.getElementById('readerType').value=type;document.getElementById('readerModalTitle').textContent='Edit Reader';document.getElementById('readerModalSub').textContent='Update reader details.';document.getElementById('readerModal').classList.add('active');document.body.style.overflow='hidden';}
function confirmDelete(id,name){deleteTarget=id;document.getElementById('deleteMessage').textContent='Deactivate reader "'+name+'"? It will no longer accept scans.';document.getElementById('deleteModal').classList.add('active');document.body.style.overflow='hidden';}
document.getElementById('deleteCancel').addEventListener('click',function(){document.getElementById('deleteModal').classList.remove('active');document.body.style.overflow='';deleteTarget=null;});
document.getElementById('deleteConfirm').addEventListener('click',function(){if(!deleteTarget)return;fetch('../api/card-readers.php?id='+deleteTarget,{method:'DELETE'}).then(r=>r.json()).then(d=>{if(d.success)window.location.reload();else alert(d.message);});});
document.getElementById('readerModal').addEventListener('click',function(e){if(e.target===e.currentTarget){this.classList.remove('active');document.body.style.overflow='';}});
document.getElementById('deleteModal').addEventListener('click',function(e){if(e.target===e.currentTarget){this.classList.remove('active');document.body.style.overflow='';deleteTarget=null;}});
document.getElementById('readerForm').addEventListener('submit',async function(e){
e.preventDefault();const id=document.getElementById('readerId').value;
const body={name:document.getElementById('readerName').value,location:document.getElementById('readerLocation').value,reader_code:document.getElementById('readerCode').value,reader_type:document.getElementById('readerType').value};
const url=id?'../api/card-readers.php?id='+id:'../api/card-readers.php';const method=id?'PUT':'POST';
try{const res=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const d=await res.json();if(d.success)window.location.reload();else alert(d.message);}catch(e){alert('Network error.');}});
</script>
<?php include '../includes/footer.php'; ?>