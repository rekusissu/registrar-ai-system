<?php
// ============================================================
//  REGISTRAR/RFID-CARDS.PHP
//  RFID cards management
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$cards = $db->fetchAll("
    SELECT 
        rf.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number,
        s.course,
        s.year_level
    FROM rfid_cards rf
    LEFT JOIN students s ON rf.student_id = s.id
    ORDER BY rf.id DESC
");

$totalCards = count($cards);
$activeCards = count(array_filter($cards, fn($c) => $c['status'] === 'active'));
$expiredCards = count(array_filter($cards, fn($c) => $c['status'] === 'expired'));
$lostCards = count(array_filter($cards, fn($c) => $c['status'] === 'lost'));

$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name, ' ', last_name) AS name, course FROM students WHERE status = 'active' ORDER BY name");

$page_title = 'RFID Cards';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';
$extra_css = ['rfid-cards.css'];
$page_scripts = ['rfid.js'];

include '../includes/header.php';
include '../includes/sidebar.php';

$avatarPalette = ['blue', 'green', 'purple', 'orange', 'pink'];
$avatarClasses = [];
foreach ($cards as $i => $c) {
    $studentKey = (string)($c['student_id'] ?? $c['id'] ?? $i);
    $avatarClasses[$i] = $avatarPalette[abs(crc32($studentKey)) % count($avatarPalette)];
}
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>RFID Cards</h1>
            <p>Manage student RFID cards</p>
        </div>
        <div class="header-actions">
            <a href="rfid-test.php" class="btn btn-secondary">
                <i class="fas fa-credit-card"></i> Test Scanner
            </a>
            <a href="rfid-scan-logs.php" class="btn btn-secondary">
                <i class="fas fa-clock-rotate-left"></i> Scan Logs
            </a>
            <button class="btn btn-primary" id="openAssignModal" onclick="openAssignModal()">
                <i class="fas fa-plus"></i> Assign Card
            </button>
        </div>
    </header>

    <!-- Stats -->
    <div class="rfid-stats">
        <div class="rfid-stat-card">
            <div class="stat-icon blue"><i class="fas fa-credit-card"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $totalCards ?></div>
                <div class="stat-label">Total Cards</div>
            </div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $activeCards ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $expiredCards ?></div>
                <div class="stat-label">Expired</div>
            </div>
        </div>
        <div class="rfid-stat-card">
            <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $lostCards ?></div>
                <div class="stat-label">Lost</div>
            </div>
        </div>
    </div>

    <!-- Search -->
    <div class="search-filter-bar" style="margin-bottom: 16px;">
        <div class="search-wrapper">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="rfidSearch" placeholder="Search by UID, student, or status..." />
            <button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <!-- Table -->
    <div class="rfid-table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Card UID</th>
                    <th>Student</th>
                    <th>Issued</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="rfidTableBody">
                <?php if (empty($cards)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-12 text-gray-500">
                            <i class="fas fa-credit-card text-5xl block mb-3 text-gray-300"></i>
                            <p class="text-lg font-medium text-gray-400">No RFID cards found</p>
                            <p class="text-sm text-gray-400">Assign cards to students to get started</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cards as $cardIndex => $card):
                        $initials = '';
                        if (!empty($card['student_name'])) {
                            $names = explode(' ', $card['student_name']);
                            $initials = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                        }
                        $avatarClass = $avatarClasses[$cardIndex] ?? 'blue';
                    ?>
                        <tr data-card='<?= json_encode($card) ?>'>
                            <td>
                                <div class="card-uid-display">
                                    <span class="chip"><i class="fas fa-microchip"></i></span>
                                    <?= htmlspecialchars($card['card_uid']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($card['student_id']): ?>
                                    <div class="student-info">
                                        <div class="student-avatar <?= $avatarClass ?>"><?= $initials ?: '?' ?></div>
                                        <div>
                                            <div class="student-name"><?= htmlspecialchars($card['student_name']) ?></div>
                                            <div class="student-detail"><?= htmlspecialchars($card['student_number']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="unassigned-text">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $card['issued_date'] ? date('M d, Y', strtotime($card['issued_date'])) : '—' ?></td>
                            <td>
                                <?= $card['expiry_date'] ? date('M d, Y', strtotime($card['expiry_date'])) : '—' ?>
                                <?php
                                    $daysLeft = null;
                                    if ($card['expiry_date']) {
                                        $daysLeft = (strtotime($card['expiry_date']) - time()) / (60 * 60 * 24);
                                    }
                                    if ($card['status'] === 'active' && $daysLeft !== null && $daysLeft <= 30 && $daysLeft > 0):
                                ?>
                                    <span class="expiry-warning"><i class="fas fa-clock"></i> <?= round($daysLeft) ?> days</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $card['status'] ?>">
                                    <span class="status-dot <?= $card['status'] ?>"></span>
                                    <?= ucfirst($card['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <button class="action-btn view" onclick="openViewDrawer(<?= $card['id'] ?>)" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn edit" onclick="openEditModal(<?= $card['id'] ?>)" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="action-btn delete" onclick="confirmDelete(<?= $card['id'] ?>, '<?= htmlspecialchars($card['card_uid']) ?>')" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="showingCount"><?= count($cards) ?></strong> of <strong id="totalCount"><?= count($cards) ?></strong> cards</div>
    </div>
</main>

<!-- ── Assign Card Modal ── -->
<div class="logout-modal-overlay" id="assignModal">
    <div class="logout-modal rfid-modal">
        <div class="rfid-modal-header">
            <div class="header-icon assign"><i class="fas fa-credit-card"></i></div>
            <div>
                <h3>Assign RFID Card</h3>
                <p>Tap a card or enter the 10-digit UID, then choose a student.</p>
            </div>
        </div>

        <form id="assignCardForm">
            <input type="hidden" id="selectedStudentId" name="student_id" value="">

            <!-- Student picker -->
            <div class="form-section">
                <div class="form-section-header" style="margin-bottom:10px;">
                    <div class="form-section-icon"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <div class="form-section-title">Student</div>
                        <div class="form-section-subtitle">Search by name</div>
                    </div>
                </div>

                <div class="student-search-wrapper">
                    <input type="text" id="studentSearchInput" class="form-control" placeholder="Type a student name..." autocomplete="off" />
                    <div class="student-search-results" id="studentSearchResults"></div>
                </div>

                <select id="studentSelect" class="form-control" style="display:none;">
                    <option value="">Select a student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int)$student['id'] ?>">
                            <?= htmlspecialchars($student['name']) ?> · <?= htmlspecialchars($student['student_number']) ?> · <?= htmlspecialchars($student['course']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="selected-student-chip" id="selectedStudentDisplay">
                    <i class="fas fa-check-circle"></i>
                    <span class="chip-name" id="selectedName"></span>
                    <span class="chip-id" id="selectedId"></span>
                    <button type="button" class="chip-clear" onclick="clearSelectedStudent()" title="Clear">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- UID + Tap -->
            <div class="form-section">
                <div class="form-section-header" style="margin-bottom:10px;">
                    <div class="form-section-icon"><i class="fas fa-microchip"></i></div>
                    <div>
                        <div class="form-section-title">Card UID</div>
                        <div class="form-section-subtitle">10-digit RFID identifier</div>
                    </div>
                </div>

                <div class="uid-row">
                    <input type="text" id="cardUid" class="form-control" maxlength="10" inputmode="numeric" placeholder="e.g. 1234567890" />
                    <button type="button" class="btn-tap" id="tapBtn">
                        <i class="fas fa-wave-square"></i>
                        <span id="tapBtnText">Tap</span>
                    </button>
                </div>
            </div>

            <!-- Dates + Notes -->
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Issued Date</label>
                        <input type="date" id="issuedDate" name="issued_date" class="form-control" value="<?= date('Y-m-d') ?>" />
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Expiry Date</label>
                        <input type="date" id="expiryDate" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea id="cardNotes" name="notes" class="form-control" rows="2" placeholder="e.g. Re-issue, lost card reported..."></textarea>
                </div>
            </div>

            <div class="rfid-modal-actions">
                <button type="button" class="btn btn-light" data-close-modal="assign">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn">
                    <i class="fas fa-save"></i> Assign Card
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Card Modal ── -->
<div class="logout-modal-overlay" id="editModal">
    <div class="logout-modal rfid-modal">
        <div class="rfid-modal-header">
            <div class="header-icon edit"><i class="fas fa-pen"></i></div>
            <div>
                <h3>Edit RFID Card</h3>
                <p>Update status, expiry date, and notes.</p>
            </div>
        </div>

        <form id="editCardForm">
            <input type="hidden" id="editCardId" value="">

            <div class="form-section">
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Card UID</label>
                        <div class="form-control" id="editUid" style="font-family:'Courier New',monospace;letter-spacing:1px;font-weight:600;"></div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Student</label>
                        <div class="form-control" id="editStudentName" style="font-weight:600;"></div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;" id="editStudentNumber"></div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Status</label>
                        <select id="editStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="lost">Lost</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Expiry Date</label>
                        <input type="date" id="editExpiryDate" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="editNotes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                </div>
            </div>

            <div class="rfid-modal-actions">
                <button type="button" class="btn btn-light" data-close-modal="edit">Cancel</button>
                <button type="submit" class="btn btn-primary" id="editSubmitBtn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── View Card Drawer ── -->
<div class="rfid-drawer-overlay" id="rfidDrawerOverlay" data-close-modal="drawer"></div>
<aside class="rfid-drawer" id="rfidDrawer" aria-hidden="true">
    <div class="drawer-header">
        <h2><i class="fas fa-id-card"></i> Card Details</h2>
        <button class="drawer-close" data-close-modal="drawer" title="Close">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="drawer-body">
        <div class="uid-display">
            <div class="uid-icon"><i class="fas fa-microchip"></i></div>
            <div>
                <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Card UID</div>
                <div class="uid-text" data-bind="uid">—</div>
            </div>
        </div>

        <div class="drawer-section" style="margin-top:18px;">
            <div class="drawer-section-title">Student</div>
            <ul class="kv-list">
                <li><span class="kv-label">Name</span><span class="kv-value" data-bind="student">—</span></li>
                <li><span class="kv-label">Student #</span><span class="kv-value" data-bind="student_number">—</span></li>
                <li><span class="kv-label">Course</span><span class="kv-value" data-bind="course">—</span></li>
                <li><span class="kv-label">Year</span><span class="kv-value" data-bind="year_level">—</span></li>
            </ul>
        </div>

        <div class="drawer-section">
            <div class="drawer-section-title">Card Info</div>
            <ul class="kv-list">
                <li><span class="kv-label">Status</span><span class="kv-value"><span class="status-badge" data-bind="status-badge"><span class="status-dot"></span><span data-bind="status">—</span></span></span></li>
                <li><span class="kv-label">Issued</span><span class="kv-value" data-bind="issued">—</span></li>
                <li><span class="kv-label">Expiry</span><span class="kv-value" data-bind="expiry">—</span></li>
            </ul>
        </div>

        <div class="drawer-section">
            <div class="drawer-section-title">Notes</div>
            <div class="notes-box" data-bind="notes">—</div>
        </div>

        <div class="drawer-section">
            <div class="drawer-section-title">Recent Scans</div>
            <div data-bind="scans">
                <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">Open to load scans.</p>
            </div>
        </div>
    </div>
</aside>

<!-- Delete Confirmation Modal -->
<div class="logout-modal-overlay" id="deleteModal">
    <div class="logout-modal">
        <div class="logout-modal-icon" style="background: #fee2e2;">
            <i class="fas fa-trash-alt" style="color: #dc2626;"></i>
        </div>
        <h3 class="logout-modal-title">Delete RFID Card</h3>
        <p class="logout-modal-message" id="deleteMessage">Are you sure you want to delete this card? This action cannot be undone.</p>
        <div class="logout-modal-actions">
            <button class="logout-btn-cancel" id="deleteCancel">Cancel</button>
            <button class="logout-btn-confirm" id="deleteConfirm" style="background: #dc2626;">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
    </div>
</div>

<script>
let deleteTarget = null;

function confirmDelete(id, uid) {
    deleteTarget = id;
    document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete RFID card ' + uid + '? This action cannot be undone.';
    document.getElementById('deleteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

document.getElementById('deleteCancel').addEventListener('click', function() {
    document.getElementById('deleteModal').classList.remove('active');
    document.body.style.overflow = '';
    deleteTarget = null;
});

document.getElementById('deleteConfirm').addEventListener('click', function() {
    if (!deleteTarget) return;
    fetch('../api/rfid.php?id=' + deleteTarget, { method: 'DELETE' })
        .then(() => window.location.reload());
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('active');
        document.body.style.overflow = '';
        deleteTarget = null;
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('deleteModal');
        if (modal.classList.contains('active')) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
            deleteTarget = null;
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>