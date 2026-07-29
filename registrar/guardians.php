<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Guardian / Parent management — full CRUD, profile, bulk
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Handle POST actions
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
        $data = [
            'full_name' => $_POST['full_name'],
            'relationship' => $_POST['relationship'],
            'contact_number' => $_POST['contact_number'] ?? '',
            'email' => $_POST['email'] ?? null,
            'address' => $_POST['address'] ?? null,
            'is_primary' => isset($_POST['is_primary']) ? 1 : 0,
            'is_emergency' => isset($_POST['is_emergency']) ? 1 : 0
        ];
        $db->update('guardians', $data, 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'delete') {
        $db->delete('guardians', 'id = ?', [intval($_POST['id'])]);
    } elseif ($action === 'bulk-delete') {
        $ids = explode(',', $_POST['ids'] ?? '');
        foreach ($ids as $iid) { $db->delete('guardians', 'id = ?', [intval($iid)]); }
    }
    header('Location: guardians.php');
    exit;
}

// Filters
$relFilter = $_GET['rel'] ?? '';
$searchQ = trim($_GET['q'] ?? '');

$where = "1=1";
$params = [];
if ($relFilter) { $where .= " AND g.relationship = ?"; $params[] = $relFilter; }
if ($searchQ) { $where .= " AND (g.full_name LIKE ? OR g.contact_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)"; $q = "%$searchQ%"; $params = array_merge($params, [$q, $q, $q, $q, $q]); }

$guardians = $db->fetchAll("SELECT g.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number, s.course, s.year_level FROM guardians g LEFT JOIN students s ON g.student_id = s.id WHERE $where ORDER BY g.full_name", $params);
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course FROM students WHERE status = 'active' ORDER BY name");

$totalGuardians = $db->fetchColumn("SELECT COUNT(*) FROM guardians");
$emergencyCount = $db->fetchColumn("SELECT COUNT(*) FROM guardians WHERE is_emergency = 1");
$fatherCount = $db->fetchColumn("SELECT COUNT(*) FROM guardians WHERE relationship = 'father'");

$page_title = 'Guardians';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
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

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:white;box-shadow:0 1px 3px rgba(37,99,235,0.25)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(37,99,235,0.3)}
.btn-secondary{background:white;color:#475569;border-color:#e2e8f0}
.btn-secondary:hover{background:#f8fafc;border-color:#cbd5e1;color:#0f172a}
.btn-light{background:#f1f5f9;color:#475569}
.btn-light:hover{background:#e2e8f0;color:#0f172a}
.btn-sm{padding:6px 12px;font-size:12px}

/* Stats cards */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.stat-card{background:white;border-radius:14px;padding:16px 18px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;transition:box-shadow .2s}
.stat-card:hover{box-shadow:0 4px 14px rgba(0,0,0,0.04)}
.stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.stat-icon.blue{background:#eef4ff;color:#2563eb}
.stat-icon.green{background:#dcfce7;color:#16a34a}
.stat-icon.yellow{background:#fef3c7;color:#b45309}
.stat-icon.purple{background:#f3e8ff;color:#7c3aed}
.stat-num{font-size:22px;font-weight:700;color:#0f172a;line-height:1}
.stat-lbl{font-size:12px;color:#64748b;margin-top:2px}

/* Search & filter bar */
.search-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
.search-wrap{position:relative;flex:1;min-width:240px}
.search-wrap input{width:100%;height:40px;padding:0 14px 0 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box;transition:all .2s}
.search-wrap input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
.search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none}
.filter-group{display:flex;gap:8px;align-items:center}
.filter-group select{height:40px;padding:0 32px 0 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;color:#1e293b;background:white;outline:none;cursor:pointer;appearance:auto !important;-webkit-appearance:auto !important;min-width:130px}
.filter-group select:focus{border-color:#2563eb}

/* Table */
.table-wrap{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.04)}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:11px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border-bottom:2px solid #e8edf4;white-space:nowrap}
td{padding:12px 14px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover{background:#f8fafc}
tr:last-child td{border-bottom:none}

/* Avatar */
.g-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:12px;flex-shrink:0}
.g-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.g-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.g-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.g-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.g-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}

/* Relation badges */
.rel-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.rel-father{background:#eef4ff;color:#2563eb}
.rel-mother{background:#fce7f3;color:#db2777}
.rel-guardian{background:#f3e8ff;color:#7c3aed}
.rel-sibling{background:#fef3c7;color:#b45309}
.rel-spouse{background:#dcfce7;color:#16a34a}

/* Flags */
.flag{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600}
.flag-primary{background:#dcfce7;color:#16a34a}
.flag-emergency{background:#fee2e2;color:#dc2626}

/* Empty state */
.empty-state{text-align:center;padding:48px 16px;color:#94a3b8}
.empty-state i{font-size:48px;color:#cbd5e1;display:block;margin-bottom:10px}
.empty-state p{font-size:15px;font-weight:600;color:#64748b;margin:0}
.empty-state span{font-size:13px;display:block;margin-top:4px}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-box{background:white;border-radius:20px;padding:28px 32px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.15);scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}
.modal-box h3{font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;display:flex;align-items:center;gap:10px}
.modal-box h3 i{color:#2563eb}
.modal-box .sub{font-size:13px;color:#64748b;margin-bottom:16px}
.form-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.form-group{flex:1;min-width:160px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
select.form-control{cursor:pointer;appearance:auto !important;-webkit-appearance:auto !important}
.check-row{display:flex;gap:16px;margin-top:8px;flex-wrap:wrap}
.check-row label{display:flex;align-items:center;gap:6px;font-size:13px;color:#1e293b;cursor:pointer}
.check-row input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;margin-top:14px;border-top:1px solid #f1f5f9}

/* View modal specific */
.vwrap{display:flex;flex-direction:column;align-items:center;padding:8px 0}
.vavatar{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:28px;margin-bottom:10px}
.vavatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.vavatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.vavatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.vavatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.vname{font-size:20px;font-weight:700;color:#0f172a}
.vrel{font-size:13px;color:#64748b;margin-top:2px}
.vgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;width:100%;text-align:left}
.vitem .lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px}
.vitem .val{font-size:14px;font-weight:600;color:#1e293b;margin-top:2px}

/* Footer */
.table-footer{padding:10px 18px;background:#fafcfd;border-top:1px solid #e8edf4;font-size:13px;color:#64748b;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.table-footer strong{color:#0f172a}

@media(max-width:900px){.stat-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.dashboard-main{margin-left:0;padding:16px}}
@media(max-width:480px){.stat-row{grid-template-columns:1fr}.filter-group{width:100%}.filter-group select{flex:1}}
</style>

<main class="dashboard-main">
<header class="header">
<div class="title"><h1>Guardians</h1><p>Manage parent and guardian records</p></div>
<div class="header-actions">
<button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Add Guardian</button>
</div>
</header>

<!-- Stats -->
<div class="stat-row">
<div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div><div class="stat-num"><?= $totalGuardians ?></div><div class="stat-lbl">Total Guardians</div></div></div>
<div class="stat-card"><div class="stat-icon green"><i class="fas fa-female"></i></div><div><div class="stat-num"><?= $fatherCount ?></div><div class="stat-lbl">Fathers</div></div></div>
<div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-phone-alt"></i></div><div><div class="stat-num"><?= $emergencyCount ?></div><div class="stat-lbl">Emergency Contacts</div></div></div>
<div class="stat-card"><div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div><div><div class="stat-num"><?= count($students) ?></div><div class="stat-lbl">Active Students</div></div></div>
</div>

<!-- Search + Filter -->
<div class="search-bar">
<div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search guardian name, contact, student..." value="<?= htmlspecialchars($searchQ) ?>" onkeyup="filterTable()"></div>
<div class="filter-group">
<select id="relFilter" onchange="filterTable()">
<option value="">All Relationships</option>
<option value="father" <?= $relFilter==='father'?'selected':'' ?>>Father</option>
<option value="mother" <?= $relFilter==='mother'?'selected':'' ?>>Mother</option>
<option value="guardian" <?= $relFilter==='guardian'?'selected':'' ?>>Guardian</option>
<option value="sibling" <?= $relFilter==='sibling'?'selected':'' ?>>Sibling</option>
<option value="spouse" <?= $relFilter==='spouse'?'selected':'' ?>>Spouse</option>
</select>
<button class="btn btn-light btn-sm" onclick="window.location='guardians.php'"><i class="fas fa-undo"></i> Reset</button>
</div>
</div>

<!-- Table -->
<div class="table-wrap">
<table>
<thead><tr><th style="width:30px;"><input type="checkbox" id="selectAll" onchange="toggleAll()" style="accent-color:#2563eb;cursor:pointer;"></th><th>Name</th><th>Relationship</th><th>Contact</th><th>Email</th><th>Linked Student</th><th>Flags</th><th style="text-align:center;">Actions</th></tr></thead>
<tbody id="tableBody">
<?php if (empty($guardians)): ?>
<tr><td colspan="8" class="empty-state"><i class="fas fa-users"></i><p>No guardians found</p><span><?= $searchQ || $relFilter ? 'Try adjusting filters' : 'Add guardians from the student profile or use Add Guardian button' ?></span></td></tr>
<?php else:
$avatarColors = ['blue','green','purple','orange','pink'];
foreach ($guardians as $i => $g):
$ac = $avatarColors[$i % count($avatarColors)];
$initials = strtoupper(substr($g['full_name'],0,1).(strpos($g['full_name'],' ') !== false ? substr($g['full_name'],strpos($g['full_name'],' ')+1,1) : substr($g['full_name'],1,1)));
?>
<tr class="g-row" data-name="<?= strtolower(htmlspecialchars($g['full_name'])) ?>" data-contact="<?= strtolower(htmlspecialchars($g['contact_number'] ?? '')) ?>" data-student="<?= strtolower(htmlspecialchars($g['student_name'] ?? '')) ?>">
<td><input type="checkbox" class="cb" value="<?= (int)$g['id'] ?>" style="accent-color:#2563eb;cursor:pointer;"></td>
<td><div style="display:flex;align-items:center;gap:10px;"><div class="g-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><strong><?= htmlspecialchars($g['full_name']) ?></strong></div></div></td>
<td><span class="rel-badge rel-<?= htmlspecialchars($g['relationship']) ?>"><?= htmlspecialchars(ucfirst($g['relationship'])) ?></span></td>
<td><?= htmlspecialchars($g['contact_number'] ?? '—') ?></td>
<td><?= htmlspecialchars($g['email'] ?? '—') ?></td>
<td><a href="students.php?search=<?= urlencode($g['student_name'] ?? '') ?>" style="color:#2563eb;text-decoration:none;font-weight:500;"><?= htmlspecialchars($g['student_name'] ?? 'Unknown') ?></a><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($g['student_number'] ?? '') ?></span></td>
<td>
<?php if ($g['is_primary']): ?><span class="flag flag-primary"><i class="fas fa-star"></i> Primary</span><?php endif; ?>
<?php if ($g['is_emergency']): ?><span class="flag flag-emergency"><i class="fas fa-exclamation-circle"></i> Emergency</span><?php endif; ?>
<?php if (!$g['is_primary'] && !$g['is_emergency']): ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
</td>
<td style="text-align:center;white-space:nowrap;">
<button class="btn btn-light btn-sm" onclick="viewGuardian(<?= (int)$g['id'] ?>,'<?= htmlspecialchars($g['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($g['relationship']) ?>','<?= htmlspecialchars($g['contact_number'] ?? '') ?>','<?= htmlspecialchars($g['email'] ?? '') ?>','<?= htmlspecialchars($g['student_name'] ?? '') ?>','<?= htmlspecialchars($g['student_number'] ?? '') ?>','<?= htmlspecialchars($g['address'] ?? '') ?>',<?= $g['is_primary'] ? 1 : 0 ?>,<?= $g['is_emergency'] ? 1 : 0 ?>)" title="View"><i class="fas fa-eye"></i></button>
<button class="btn btn-light btn-sm" onclick="openEditModal(<?= (int)$g['id'] ?>,'<?= htmlspecialchars($g['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($g['relationship']) ?>','<?= htmlspecialchars($g['contact_number'] ?? '') ?>','<?= htmlspecialchars($g['email'] ?? '') ?>','<?= htmlspecialchars($g['address'] ?? '') ?>',<?= $g['is_primary'] ? 1 : 0 ?>,<?= $g['is_emergency'] ? 1 : 0 ?>)" title="Edit"><i class="fas fa-pen"></i></button>
<button class="btn btn-light btn-sm" style="color:#dc2626;" onclick="confirmDelete(<?= (int)$g['id'] ?>,'<?= htmlspecialchars($g['full_name'],ENT_QUOTES) ?>')" title="Delete"><i class="fas fa-trash-alt"></i></button>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="table-footer">
<div class="info-text">Showing <strong id="showingCount"><?= count($guardians) ?></strong> of <strong><?= count($guardians) ?></strong> guardians</div>
<?php if (count($guardians) > 0): ?>
<div><button class="btn btn-light btn-sm" onclick="exportCSV()"><i class="fas fa-download"></i> Export</button></div>
<?php endif; ?>
</div>
</main>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal"><div class="modal-box" style="max-width:460px;"><h3><i class="fas fa-id-card"></i> Guardian Profile</h3><p class="sub">Parent / guardian details</p>
<div class="vwrap"><div class="vavatar blue" id="vAvatar">JD</div><div class="vname" id="vName">—</div><div class="vrel" id="vRel">—</div></div>
<div class="vgrid">
<div class="vitem"><div class="lbl">Contact</div><div class="val" id="vContact">—</div></div>
<div class="vitem"><div class="lbl">Email</div><div class="val" id="vEmail">—</div></div>
<div class="vitem"><div class="lbl">Address</div><div class="val" id="vAddress">—</div></div>
<div class="vitem"><div class="lbl">Linked Student</div><div class="val" id="vStudent">—</div></div>
<div class="vitem"><div class="lbl">Primary</div><div class="val" id="vPrimary">—</div></div>
<div class="vitem"><div class="lbl">Emergency Contact</div><div class="val" id="vEmergency">—</div></div>
</div>
<div class="modal-actions"><button class="btn btn-primary" onclick="document.getElementById('viewModal').classList.remove('active');document.body.style.overflow='';"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal-box"><h3><i class="fas fa-user-plus"></i> Add Guardian</h3><p class="sub">Link a parent or guardian to a student</p>
<form method="POST"><input type="hidden" name="action" value="add">
<div class="form-group"><label>Linked Student <span style="color:#dc2626;">*</span></label>
<select name="student_id" class="form-control" required>
<option value="">Select student...</option>
<?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['student_number'].' — '.$s['name'].' ('.$s['course'].')') ?></option><?php endforeach; ?>
</select></div>
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" name="full_name" class="form-control" required></div>
<div class="form-group"><label>Relationship</label><select name="relationship" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="sibling">Sibling</option><option value="spouse">Spouse</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No.</label><input type="text" name="contact_number" class="form-control" placeholder="0917xxxxxxx"></div>
<div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="parent@email.com"></div></div>
<div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2" placeholder="Optional"></textarea></div>
<div class="check-row">
<label><input type="checkbox" name="is_primary" value="1"> <i class="fas fa-star" style="color:#b45309;"></i> Primary Guardian</label>
<label><input type="checkbox" name="is_emergency" value="1"> <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i> Emergency Contact</label>
</div>
<div class="modal-actions"><button type="button" class="btn btn-light" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Guardian</button></div>
</form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal-box"><h3><i class="fas fa-pen"></i> Edit Guardian</h3><p class="sub">Update guardian information</p>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" name="full_name" id="editName" class="form-control" required></div>
<div class="form-group"><label>Relationship</label><select name="relationship" id="editRel" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="sibling">Sibling</option><option value="spouse">Spouse</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No.</label><input type="text" name="contact_number" id="editContact" class="form-control"></div>
<div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control"></div></div>
<div class="form-group"><label>Address</label><textarea name="address" id="editAddress" class="form-control" rows="2"></textarea></div>
<div class="check-row">
<label><input type="checkbox" name="is_primary" value="1" id="editPrimary"> <i class="fas fa-star" style="color:#b45309;"></i> Primary Guardian</label>
<label><input type="checkbox" name="is_emergency" value="1" id="editEmergency"> <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i> Emergency Contact</label>
</div>
<div class="modal-actions"><button type="button" class="btn btn-light" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal"><div class="modal-box" style="max-width:400px;text-align:center;"><div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;color:#dc2626;"><i class="fas fa-trash-alt"></i></div>
<h3 style="justify-content:center;">Delete Guardian</h3><p class="sub" id="deleteMsg">Are you sure?</p>
<div class="modal-actions" style="justify-content:center;"><button class="btn btn-light" onclick="closeModal('deleteModal')">Cancel</button>
<form method="POST" style="display:inline;" id="deleteForm"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"><button class="btn btn-primary" style="background:#dc2626;" onclick="return confirm('Delete this guardian?')"><i class="fas fa-trash-alt"></i> Delete</button></form></div></div></div>

<script>
// ─── FILTER ─────────────────────────────────────────────────
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rel = document.getElementById('relFilter').value;
    document.querySelectorAll('.g-row').forEach(r => {
        let show = true;
        if (q && !r.dataset.name.includes(q) && !r.dataset.contact.includes(q) && !r.dataset.student.includes(q)) show = false;
        if (rel && !r.querySelector('.rel-badge').textContent.toLowerCase().includes(rel)) show = false;
        r.style.display = show ? '' : 'none';
    });
    document.getElementById('showingCount').textContent = document.querySelectorAll('.g-row[style*=""]:not([style*="display: none"])').length;
}

// ─── SELECT ALL ─────────────────────────────────────────────
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.cb').forEach(c => c.checked = this.checked);
});

// ─── VIEW ────────────────────────────────────────────────────
function viewGuardian(id, name, rel, contact, email, student, snum, address, primary, emergency) {
    const cols = ['blue','pink','purple','green','orange']; const c = cols[id % cols.length];
    document.getElementById('vAvatar').className = 'vavatar ' + c;
    document.getElementById('vAvatar').textContent = (name.charAt(0) + (name.includes(' ')?name.split(' ')[1].charAt(0):name.charAt(1))).toUpperCase();
    document.getElementById('vName').textContent = name;
    const relLabels = {father:'Father',mother:'Mother',guardian:'Guardian',sibling:'Sibling',spouse:'Spouse'};
    document.getElementById('vRel').innerHTML = '<span class="rel-badge rel-'+rel+'">'+ (relLabels[rel]||rel) +'</span>';
    document.getElementById('vContact').textContent = contact || '—';
    document.getElementById('vEmail').textContent = email || '—';
    document.getElementById('vAddress').textContent = address || '—';
    document.getElementById('vStudent').textContent = student ? student + ' ('+snum+')' : '—';
    document.getElementById('vPrimary').innerHTML = primary ? '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Yes</span>' : '<span style="color:#94a3b8;">No</span>';
    document.getElementById('vEmergency').innerHTML = emergency ? '<span style="color:#dc2626;"><i class="fas fa-exclamation-circle"></i> Yes</span>' : '<span style="color:#94a3b8;">No</span>';
    document.getElementById('viewModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// ─── EDIT ────────────────────────────────────────────────────
function openEditModal(id, name, rel, contact, email, address, primary, emergency) {
    document.getElementById('editId').value = id;
    document.getElementById('editName').value = name;
    document.getElementById('editRel').value = rel;
    document.getElementById('editContact').value = contact;
    document.getElementById('editEmail').value = email;
    document.getElementById('editAddress').value = address;
    document.getElementById('editPrimary').checked = primary == 1;
    document.getElementById('editEmergency').checked = emergency == 1;
    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// ─── ADD ─────────────────────────────────────────────────────
function openAddModal() { document.getElementById('addModal').classList.add('active'); document.body.style.overflow = 'hidden'; }

// ─── DELETE ─────────────────────────────────────────────────
function confirmDelete(id, name) { document.getElementById('deleteId').value = id; document.getElementById('deleteMsg').textContent = 'Remove guardian "'+name+'"? This cannot be undone.'; document.getElementById('deleteModal').classList.add('active'); document.body.style.overflow = 'hidden'; }

// ─── CLOSE ──────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
['addModal','editModal','viewModal','deleteModal'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('click', function(e) { if (e.target === this) closeModal(id); }); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { ['addModal','editModal','viewModal','deleteModal'].forEach(id => closeModal(id)); }});

// ─── EXPORT ─────────────────────────────────────────────────
function exportCSV() {
    let csv = "Name,Relationship,Contact,Email,Student\n";
    document.querySelectorAll('.g-row').forEach(r => {
        const cells = r.querySelectorAll('td');
        if (cells.length >= 6) {
            const name = cells[1].textContent.trim();
            const rel = cells[2].textContent.trim();
            const contact = cells[3].textContent.trim();
            const email = cells[4].textContent.trim();
            const student = cells[5].textContent.trim();
            csv += name + ',' + rel + ',' + contact + ',' + email + ',' + student + '\n';
        }
    });
    const blob = new Blob([csv], {type:'text/csv'}); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'guardians_export.csv'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
<?php include '../includes/footer.php'; ?>