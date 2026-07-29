<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Guardian management — premium UI
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $db->insert('guardians', [
            'student_id' => intval($_POST['student_id']),
            'full_name' => $_POST['full_name'],
            'relationship' => $_POST['relationship'],
            'contact_number' => $_POST['contact_number'] ?? '',
            'email' => $_POST['email'] ?? null,
            'address' => $_POST['address'] ?? null,
            'is_primary' => isset($_POST['is_primary']) ? 1 : 0,
            'is_emergency' => isset($_POST['is_emergency']) ? 1 : 0
        ]);
    } elseif ($action === 'edit') {
        $db->update('guardians', [
            'full_name' => $_POST['full_name'],
            'relationship' => $_POST['relationship'],
            'contact_number' => $_POST['contact_number'] ?? '',
            'email' => $_POST['email'] ?? null,
            'address' => $_POST['address'] ?? null,
            'is_primary' => isset($_POST['is_primary']) ? 1 : 0,
            'is_emergency' => isset($_POST['is_emergency']) ? 1 : 0
        ], 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->delete('guardians', 'id = ?', [intval($_POST['id'])]);
    }
    header('Location: guardians.php');
    exit;
}

$relFilter = $_GET['rel'] ?? '';
$searchQ = trim($_GET['q'] ?? '');
$where = "1=1"; $params = [];
if ($relFilter) { $where .= " AND g.relationship = ?"; $params[] = $relFilter; }
if ($searchQ) { $q = "%$searchQ%"; $where .= " AND (g.full_name LIKE ? OR g.contact_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)"; $params = array_merge($params, [$q, $q, $q, $q, $q]); }

$guardians = $db->fetchAll("SELECT g.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number, s.course FROM guardians g LEFT JOIN students s ON g.student_id = s.id WHERE $where ORDER BY g.full_name", $params);
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course FROM students WHERE status='active' ORDER BY name");

$totalGuardians = $db->fetchColumn("SELECT COUNT(*) FROM guardians");
$emergencyCount = $db->fetchColumn("SELECT COUNT(*) FROM guardians WHERE is_emergency=1");
$fatherCount = $db->fetchColumn("SELECT COUNT(*) FROM guardians WHERE relationship='father'");

$page_title = 'Guardians';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
include '../includes/header.php';
include '../includes/sidebar.php';
?><style>
:root{--sidebar-width:260px}
.dashboard-main{margin-left:var(--sidebar-width);padding:24px 32px;min-height:100vh;width:calc(100% - var(--sidebar-width));max-width:calc(100% - var(--sidebar-width));overflow-x:hidden;box-sizing:border-box}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid #e8eaef;gap:16px;flex-wrap:wrap}
.header .title h1{font-size:22px;font-weight:700;color:#0f172a;margin:0}
.header .title p{font-size:13px;color:#64748b;margin:2px 0 0}
.header-actions{display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;font-family:inherit;transition:all .2s}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.35)}
.btn-secondary{background:#f1f5f9;color:#475569}
.btn-secondary:hover{background:#e2e8f0;color:#0f172a}
.btn-sm{padding:6px 14px;font-size:12px}

/* ── Stats ── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat{background:#fff;border-radius:12px;padding:16px 18px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;transition:box-shadow .2s}
.stat:hover{box-shadow:0 4px 12px rgba(0,0,0,0.04)}
.stat-i{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.stat-i.b{background:#eef4ff;color:#2563eb}
.stat-i.g{background:#dcfce7;color:#16a34a}
.stat-i.y{background:#fef3c7;color:#b45309}
.stat-i.p{background:#f3e8ff;color:#7c3aed}
.stat-v{font-size:22px;font-weight:700;color:#0f172a;line-height:1}
.stat-l{font-size:12px;color:#64748b;margin-top:2px}

/* ── Search ── */
.search{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
.s-wrap{position:relative;flex:1;min-width:220px}
.s-wrap input{width:100%;height:40px;padding:0 14px 0 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:#fff;color:#1e293b;box-sizing:border-box}
.s-wrap input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.s-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}
.s-filters{display:flex;gap:8px;align-items:center}
.s-filters select{height:40px;padding:0 30px 0 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;color:#1e293b;background:#fff;outline:none;cursor:pointer;min-width:125px}
.s-filters select:focus{border-color:#2563eb}

/* ── Table ── */
.tw{border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;background:#fff}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:#64748b;background:#f8fafc;border-bottom:2px solid #eaeef5;white-space:nowrap}
td{padding:11px 14px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover{background:#fafbfc}

/* Avatar mini */
.am{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:11px;flex-shrink:0}
.am.b{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.am.g{background:linear-gradient(135deg,#16a34a,#15803d)}
.am.p{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.am.o{background:linear-gradient(135deg,#b45309,#92400e)}
.am.pk{background:linear-gradient(135deg,#db2777,#be185d)}

/* Relationship badges */
.rb{display:inline-flex;align-items:center;gap:4px;padding:3px 12px;border-radius:999px;font-size:11px;font-weight:600}
.rb-f{background:#eef4ff;color:#2563eb}
.rb-m{background:#fce7f3;color:#db2777}
.rb-g{background:#f3e8ff;color:#7c3aed}
.rb-s{background:#fef3c7;color:#b45309}
.rb-sp{background:#dcfce7;color:#16a34a}

/* Flags */
.fl{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600}
.fl-p{background:#dcfce7;color:#16a34a}
.fl-e{background:#fee2e2;color:#dc2626}

/* Empty */
.emp{text-align:center;padding:48px 16px;color:#94a3b8}
.emp i{font-size:44px;color:#cbd5e1;display:block;margin-bottom:8px}
.emp p{font-size:15px;font-weight:600;color:#64748b;margin:0}
.emp span{font-size:13px;display:block;margin-top:4px}

/* ── Modal ── */
.modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:99999;align-items:center;justify-content:center;padding:20px}
.modal-ov.active{display:flex}
.modal-xl{background:#fff;border-radius:20px;padding:26px 30px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.14)}
.modal-xl h3{font-size:17px;font-weight:700;color:#0f172a;margin:0 0 3px;display:flex;align-items:center;gap:10px}
.modal-xl h3 i{color:#2563eb}
.modal-xl .sub{font-size:13px;color:#64748b;margin-bottom:14px}
.fr{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.fg{flex:1;min-width:150px}
.fg label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.fc{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:#fff;color:#1e293b;box-sizing:border-box}
.fc:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.cr{display:flex;gap:14px;flex-wrap:wrap;margin-top:6px}
.cr label{display:flex;align-items:center;gap:6px;font-size:13px;color:#1e293b;cursor:pointer}
.cr input[type=checkbox]{width:16px;height:16px;accent-color:#2563eb;cursor:pointer}
.ma{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;margin-top:14px;border-top:1px solid #f1f5f9}

/* View modal */
.vw{display:flex;flex-direction:column;align-items:center;padding:6px 0}
.va{width:70px;height:70px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:26px;margin-bottom:10px}
.va.b{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.va.pk{background:linear-gradient(135deg,#db2777,#be185d)}
.va.p{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.va.g{background:linear-gradient(135deg,#16a34a,#15803d)}
.vn{font-size:20px;font-weight:700;color:#0f172a}
.vr{font-size:13px;color:#64748b;margin-top:2px}
.vg{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;width:100%;text-align:left}
.vi .l{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.2px}
.vi .v{font-size:14px;font-weight:600;color:#1e293b;margin-top:1px}

/* Footer */
.tf{padding:10px 18px;background:#fafcfd;border-top:1px solid #e8edf4;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;font-size:13px;color:#64748b}
.tf strong{color:#0f172a}

@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.dashboard-main{margin-left:0;padding:16px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>

<main class="dashboard-main">
<header class="header">
<div><h1>Guardians</h1><p>Manage parent and guardian records</p></div>
<div class="header-actions"><button class="btn btn-primary" onclick="om('addModal')"><i class="fas fa-plus"></i> Add Guardian</button></div>
</header>

<div class="stats">
<div class="stat"><div class="stat-i b"><i class="fas fa-users"></i></div><div><div class="stat-v"><?= $totalGuardians ?></div><div class="stat-l">Total Guardians</div></div></div>
<div class="stat"><div class="stat-i g"><i class="fas fa-user-tie"></i></div><div><div class="stat-v"><?= $fatherCount ?></div><div class="stat-l">Fathers</div></div></div>
<div class="stat"><div class="stat-i y"><i class="fas fa-phone-alt"></i></div><div><div class="stat-v"><?= $emergencyCount ?></div><div class="stat-l">Emergency Contacts</div></div></div>
<div class="stat"><div class="stat-i p"><i class="fas fa-user-graduate"></i></div><div><div class="stat-v"><?= count($students) ?></div><div class="stat-l">Active Students</div></div></div>
</div>

<div class="search">
<div class="s-wrap"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search by name, contact, or student..." value="<?= htmlspecialchars($searchQ) ?>" onkeyup="ft()"></div>
<div class="s-filters">
<select id="relFilter" onchange="ft()"><option value="">All Relationships</option><option value="father"<?= $relFilter==='father'?' selected':''?>>Father</option><option value="mother"<?= $relFilter==='mother'?' selected':''?>>Mother</option><option value="guardian"<?= $relFilter==='guardian'?' selected':''?>>Guardian</option><option value="sibling"<?= $relFilter==='sibling'?' selected':''?>>Sibling</option><option value="spouse"<?= $relFilter==='spouse'?' selected':''?>>Spouse</option></select>
<button class="btn btn-secondary btn-sm" onclick="window.location='guardians.php'"><i class="fas fa-undo"></i> Reset</button>
</div>
</div>

<div class="tw">
<table>
<thead><tr><th style="width:28px;"><input type="checkbox" id="selAll" onchange="ta()" style="accent-color:#2563eb;cursor:pointer"></th><th>Name</th><th>Relationship</th><th>Contact</th><th>Email</th><th>Student</th><th>Flags</th><th style="width:105px;">Actions</th></tr></thead>
<tbody>
<?php if (empty($guardians)): ?>
<tr><td colspan="8" class="emp"><i class="fas fa-users"></i><p>No guardians found</p><span>Add a guardian from the button above</span></td></tr>
<?php else:
$ac = ['b','g','p','o','pk'];
foreach ($guardians as $i=>$g):
$inits = strtoupper(substr($g['full_name'],0,1).(($p=strpos($g['full_name'],' '))!==false?substr($g['full_name'],$p+1,1):substr($g['full_name'],1,1)));
$c = $ac[$i%count($ac)];
?>
<tr class="gr" data-n="<?=strtolower(htmlspecialchars($g['full_name']))?>" data-c="<?=strtolower(htmlspecialchars($g['contact_number']??''))?>" data-s="<?=strtolower(htmlspecialchars($g['student_name']??''))?>">
<td><input type="checkbox" class="cb" value="<?=(int)$g['id']?>" style="accent-color:#2563eb;cursor:pointer"></td>
<td><div style="display:flex;align-items:center;gap:10px"><div class="am <?=$c?>"><?=$inits?:'?'?></div><strong><?=htmlspecialchars($g['full_name'])?></strong></div></td>
<td><span class="rb rb-<?=$g['relationship']?>"><?=ucfirst(htmlspecialchars($g['relationship']))?></span></td>
<td><?=htmlspecialchars($g['contact_number']??'—')?></td>
<td><?=htmlspecialchars($g['email']??'—')?></td>
<td><a href="students.php?search=<?=urlencode($g['student_name']??'')?>" style="color:#2563eb;text-decoration:none;font-weight:500"><?=htmlspecialchars($g['student_name']??'Unknown')?></a><br><span style="font-size:11px;color:#94a3b8"><?=htmlspecialchars($g['student_number']??'')?></span></td>
<td><?php if($g['is_primary']):?><span class="fl fl-p"><i class="fas fa-star"></i> Primary</span><?php endif; ?><?php if($g['is_emergency']):?><span class="fl fl-e"><i class="fas fa-exclamation-circle"></i> Emergency</span><?php endif; ?><?php if(!$g['is_primary']&&!$g['is_emergency']):?><span style="color:#cbd5e1">—</span><?php endif; ?></td>
<td style="white-space:nowrap">
<button class="btn btn-secondary btn-sm" onclick="vw(<?=(int)$g['id']?>,'<?=htmlspecialchars($g['full_name'],ENT_QUOTES)?>','<?=htmlspecialchars($g['relationship'])?>','<?=htmlspecialchars($g['contact_number']??'')?>','<?=htmlspecialchars($g['email']??'')?>','<?=htmlspecialchars($g['student_name']??'')?>','<?=htmlspecialchars($g['student_number']??'')?>','<?=htmlspecialchars($g['address']??'')?>',<?=$g['is_primary']?1:0?>,<?=$g['is_emergency']?1:0?>)" title="View"><i class="fas fa-eye"></i></button>
<button class="btn btn-secondary btn-sm" onclick="em(<?=(int)$g['id']?>,'<?=htmlspecialchars($g['full_name'],ENT_QUOTES)?>','<?=htmlspecialchars($g['relationship'])?>','<?=htmlspecialchars($g['contact_number']??'')?>','<?=htmlspecialchars($g['email']??'')?>','<?=htmlspecialchars($g['address']??'')?>',<?=$g['is_primary']?1:0?>,<?=$g['is_emergency']?1:0?>)" title="Edit"><i class="fas fa-pen"></i></button>
<button class="btn btn-secondary btn-sm" style="color:#dc2626" onclick="cd(<?=(int)$g['id']?>,'<?=htmlspecialchars($g['full_name'],ENT_QUOTES)?>')" title="Delete"><i class="fas fa-trash-alt"></i></button>
</td></tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<div class="tf"><span>Showing <strong id="sc"><?=count($guardians)?></strong> of <?=count($guardians)?> guardians</span><button class="btn btn-secondary btn-sm" onclick="ex()"><i class="fas fa-download"></i> Export CSV</button></div>
</main>

<!-- View Modal -->
<div class="modal-ov" id="viewModal"><div class="modal-xl" style="max-width:440px"><h3><i class="fas fa-id-card"></i> Guardian Profile</h3><p class="sub">Parent / guardian details</p>
<div class="vw"><div class="va b" id="vA">JD</div><div class="vn" id="vN">—</div><div class="vr" id="vR">—</div></div>
<div class="vg"><div class="vi"><div class="l">Contact</div><div class="v" id="vC">—</div></div><div class="vi"><div class="l">Email</div><div class="v" id="vE">—</div></div><div class="vi"><div class="l">Address</div><div class="v" id="vAd">—</div></div><div class="vi"><div class="l">Linked Student</div><div class="v" id="vSt">—</div></div><div class="vi"><div class="l">Primary</div><div class="v" id="vP">—</div></div><div class="vi"><div class="l">Emergency Contact</div><div class="v" id="vEm">—</div></div></div>
<div class="ma"><button class="btn btn-primary" onclick="cm('viewModal')"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Add Modal -->
<div class="modal-ov" id="addModal"><div class="modal-xl"><h3><i class="fas fa-user-plus"></i> Add Guardian</h3><p class="sub">Link a parent or guardian to a student</p>
<form method="POST"><input type="hidden" name="action" value="add">
<div class="fg" style="margin-bottom:10px"><label>Linked Student <span style="color:#dc2626">*</span></label><select name="student_id" class="fc" required><option value="">Select student...</option><?php foreach($students as $s):?><option value="<?=(int)$s['id']?>"><?=htmlspecialchars($s['student_number'].' — '.$s['name'].' ('.$s['course'].')')?></option><?php endforeach;?></select></div>
<div class="fr"><div class="fg"><label>Full Name <span style="color:#dc2626">*</span></label><input type="text" name="full_name" class="fc" required></div><div class="fg"><label>Relationship</label><select name="relationship" class="fc"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="sibling">Sibling</option><option value="spouse">Spouse</option></select></div></div>
<div class="fr"><div class="fg"><label>Contact No.</label><input type="text" name="contact_number" class="fc" placeholder="0917xxxxxxx"></div><div class="fg"><label>Email</label><input type="email" name="email" class="fc" placeholder="parent@email.com"></div></div>
<div class="fg" style="margin-bottom:6px"><label>Address</label><textarea name="address" class="fc" rows="2" placeholder="Optional"></textarea></div>
<div class="cr"><label><input type="checkbox" name="is_primary" value="1"> <i class="fas fa-star" style="color:#b45309"></i> Primary Guardian</label><label><input type="checkbox" name="is_emergency" value="1"> <i class="fas fa-exclamation-circle" style="color:#dc2626"></i> Emergency Contact</label></div>
<div class="ma"><button type="button" class="btn btn-secondary" onclick="cm('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Guardian</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-ov" id="editModal"><div class="modal-xl"><h3><i class="fas fa-pen"></i> Edit Guardian</h3><p class="sub">Update guardian information</p>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
<div class="fr"><div class="fg"><label>Full Name <span style="color:#dc2626">*</span></label><input type="text" name="full_name" id="eN" class="fc" required></div><div class="fg"><label>Relationship</label><select name="relationship" id="eR" class="fc"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="sibling">Sibling</option><option value="spouse">Spouse</option></select></div></div>
<div class="fr"><div class="fg"><label>Contact No.</label><input type="text" name="contact_number" id="eC" class="fc"></div><div class="fg"><label>Email</label><input type="email" name="email" id="eE" class="fc"></div></div>
<div class="fg" style="margin-bottom:6px"><label>Address</label><textarea name="address" id="eAd" class="fc" rows="2"></textarea></div>
<div class="cr"><label><input type="checkbox" name="is_primary" value="1" id="eP"> <i class="fas fa-star" style="color:#b45309"></i> Primary</label><label><input type="checkbox" name="is_emergency" value="1" id="eEm"> <i class="fas fa-exclamation-circle" style="color:#dc2626"></i> Emergency</label></div>
<div class="ma"><button type="button" class="btn btn-secondary" onclick="cm('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Delete Modal -->
<div class="modal-ov" id="deleteModal"><div class="modal-xl" style="max-width:380px;text-align:center"><div style="width:54px;height:54px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px;color:#dc2626"><i class="fas fa-trash-alt"></i></div>
<h3 style="justify-content:center">Delete Guardian</h3><p class="sub" id="dMsg">Are you sure?</p>
<div class="ma" style="justify-content:center"><button class="btn btn-secondary" onclick="cm('deleteModal')">Cancel</button>
<form method="POST" style="display:inline" id="dForm"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="dId"><button class="btn btn-primary" style="background:#dc2626" onclick="return confirm('Delete this guardian?')"><i class="fas fa-trash-alt"></i> Delete</button></form></div></div></div>

<script>
function ft(){const q=document.getElementById('searchInput').value.toLowerCase(),r=document.getElementById('relFilter').value;let v=0;document.querySelectorAll('.gr').forEach(el=>{let s=true;if(q&&!el.dataset.n.includes(q)&&!el.dataset.c.includes(q)&&!el.dataset.s.includes(q))s=false;if(r&&!el.querySelector('.rb').textContent.toLowerCase().includes(r))s=false;el.style.display=s?'':'none';if(s)v++;});document.getElementById('sc').textContent=v;}
function ta(){document.querySelectorAll('.cb').forEach(c=>c.checked=document.getElementById('selAll').checked);}
function om(i){document.getElementById(i).classList.add('active');document.body.style.overflow='hidden';}
function cm(i){document.getElementById(i).classList.remove('active');document.body.style.overflow='';}
['addModal','editModal','viewModal','deleteModal'].forEach(i=>{const e=document.getElementById(i);if(e)e.addEventListener('click',function(ev){if(ev.target===this)cm(i);});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')['addModal','editModal','viewModal','deleteModal'].forEach(i=>cm(i));});

function vw(id,name,rel,contact,email,student,snum,addr,prim,emerg){
const cols=['b','pk','p','o','g'];const c=cols[id%cols.length];
document.getElementById('vA').className='va '+c;
document.getElementById('vA').textContent=(name.charAt(0)+(name.includes(' ')?name.split(' ')[1].charAt(0):name.charAt(1))).toUpperCase();
document.getElementById('vN').textContent=name;
const l={father:'Father',mother:'Mother',guardian:'Guardian',sibling:'Sibling',spouse:'Spouse'};
document.getElementById('vR').innerHTML='<span class="rb rb-'+rel+'">'+(l[rel]||rel)+'</span>';
document.getElementById('vC').textContent=contact||'—';
document.getElementById('vE').textContent=email||'—';
document.getElementById('vAd').textContent=addr||'—';
document.getElementById('vSt').textContent=student?student+' ('+snum+')':'—';
document.getElementById('vP').innerHTML=prim?'<span style="color:#16a34a"><i class="fas fa-check-circle"></i> Yes</span>':'<span style="color:#94a3b8">No</span>';
document.getElementById('vEm').innerHTML=emerg?'<span style="color:#dc2626"><i class="fas fa-exclamation-circle"></i> Yes</span>':'<span style="color:#94a3b8">No</span>';
om('viewModal');
}
function em(id,name,rel,contact,email,addr,prim,emerg){
document.getElementById('eId').value=id;document.getElementById('eN').value=name;document.getElementById('eR').value=rel;
document.getElementById('eC').value=contact||'';document.getElementById('eE').value=email||'';document.getElementById('eAd').value=addr||'';
document.getElementById('eP').checked=prim==1;document.getElementById('eEm').checked=emerg==1;om('editModal');
}
function cd(id,name){document.getElementById('dId').value=id;document.getElementById('dMsg').textContent='Remove guardian "'+name+'"? This cannot be undone.';om('deleteModal');}

function ex(){let c="Name,Relationship,Contact,Email,Student\n";document.querySelectorAll('.gr').forEach(r=>{const t=r.querySelectorAll('td');if(t.length>=6){c+=t[1].textContent.trim()+','+t[2].textContent.trim()+','+t[3].textContent.trim()+','+t[4].textContent.trim()+','+t[5].textContent.trim()+'\n';}});const b=new Blob([c],{type:'text/csv'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='guardians_export.csv';a.click();URL.revokeObjectURL(a.href);}
</script>
<?php include '../includes/footer.php'; ?>