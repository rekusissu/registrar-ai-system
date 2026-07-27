<?php
// ============================================================
//  REGISTRAR/STUDENTS-VIEW.PHP
//  View student profile - polished UI
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: students.php');
    exit;
}

$db = Database::getInstance();
$student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$id]);

if (!$student) {
    header('Location: students.php');
    exit;
}

// Get guardian info
$guardians = $db->fetchAll("SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, is_emergency DESC, id ASC", [$id]);

// Get document requests
$documents = $db->fetchAll("SELECT * FROM document_requests WHERE student_id = ? ORDER BY id DESC LIMIT 5", [$id]);

function safeHtml($value, $fallback = '') {
    return htmlspecialchars($value ?? $fallback);
}

function displayValue($value, $fallback = '—') {
    $v = trim((string)$value);
    return $v === '' ? $fallback : htmlspecialchars($v);
}

function displayDate($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date($format, strtotime($date));
}

$page_title = 'Student Profile';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';

include '../includes/header.php';
include '../includes/sidebar.php';

$firstName = $student['first_name'] ?? '';
$lastName  = $student['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . ($student['middle_name'] ?? '') . ' ' . $lastName);

$initials = strtoupper(
    substr($firstName, 0, 1) .
    substr($student['middle_name'] ?? '', 0, 1) .
    substr($lastName, 0, 1)
);
$initials = substr($initials, 0, 2) ?: '?';

$avatarClasses = ['blue', 'green', 'purple', 'orange', 'pink'];
// Use a stable color from student id so it doesn't change on every render
$avatarClass = $avatarClasses[$student['id'] % count($avatarClasses)];

$status = $student['status'] ?? 'active';
?>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Student Profile</h1>
            <p>View complete information for <?= safeHtml($firstName . ' ' . $lastName) ?></p>
        </div>
        <div class="header-actions">
            <a href="students-edit.php?id=<?= (int)$student['id'] ?>" class="btn btn-primary">
                <i class="fas fa-pen"></i> Edit
            </a>
            <a href="students.php" class="btn btn-light">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </header>

    <!-- Profile Card -->
    <div class="profile-card">
        <div class="profile-header">
            <div class="student-avatar <?= safeHtml($avatarClass) ?> profile-avatar-lg">
                <?= safeHtml($initials) ?>
            </div>
            <div class="profile-info">
                <h2><?= safeHtml($fullName) ?></h2>
                <p class="student-no">Student No. <?= safeHtml($student['student_number']) ?></p>
                <span class="status-badge <?= safeHtml($status) ?>">
                    <span class="status-dot <?= safeHtml($status) ?>"></span>
                    <?= safeHtml(ucfirst(str_replace('-', ' ', $status))) ?>
                </span>

                <div class="profile-meta">
                    <?php if (!empty($student['course'])): ?>
                        <div class="profile-meta-item">
                            <i class="fas fa-graduation-cap"></i>
                            <?= displayValue($student['course']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student['year_level'])): ?>
                        <div class="profile-meta-item">
                            <i class="fas fa-layer-group"></i>
                            <?= displayValue($student['year_level']) ?><?= ($student['year_level'] == 1) ? 'st' : (($student['year_level'] == 2) ? 'nd' : (($student['year_level'] == 3) ? 'rd' : 'th')) ?> Year
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student['section'])): ?>
                        <div class="profile-meta-item">
                            <i class="fas fa-users-rectangle"></i>
                            Section <?= displayValue($student['section']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-body">
            <!-- ── Personal ── -->
            <div class="profile-grid">
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-user"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Full Name</span>
                        <span class="value"><?= displayValue($fullName) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-cake-candles"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Birth Date</span>
                        <span class="value"><?= displayDate($student['birth_date']) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Place of Birth</span>
                        <span class="value"><?= displayValue($student['place_of_birth']) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-flag"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Nationality</span>
                        <span class="value"><?= displayValue($student['nationality']) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-hands-praying"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Religion</span>
                        <span class="value"><?= displayValue($student['religion']) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-phone"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Contact Number</span>
                        <span class="value"><?= displayValue($student['contact_number']) ?></span>
                    </div>
                </div>
                <div class="profile-field">
                    <div class="profile-field-icon"><i class="fas fa-envelope"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Email Address</span>
                        <span class="value"><?= displayValue($student['email']) ?></span>
                    </div>
                </div>
                <div class="profile-field" style="grid-column: 1 / -1;">
                    <div class="profile-field-icon"><i class="fas fa-map-location-dot"></i></div>
                    <div class="profile-field-content">
                        <span class="label">Home Address</span>
                        <span class="value"><?= displayValue($student['address']) ?></span>
                    </div>
                </div>
            </div>

            <!-- ── Academic ── -->
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div class="profile-section-title">Academic Details</div>
                </div>
                <div class="profile-grid">
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fas fa-book"></i></div>
                        <div class="profile-field-content">
                            <span class="label">Course / Program</span>
                            <span class="value"><?= displayValue($student['course']) ?></span>
                        </div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="profile-field-content">
                            <span class="label">Year Level</span>
                            <span class="value"><?= !empty($student['year_level']) ? $student['year_level'] . ((($student['year_level'] == 1) ? 'st' : (($student['year_level'] == 2) ? 'nd' : (($student['year_level'] == 3) ? 'rd' : 'th')))) . ' Year' : '—' ?></span>
                        </div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fas fa-users-rectangle"></i></div>
                        <div class="profile-field-content">
                            <span class="label">Section</span>
                            <span class="value"><?= displayValue($student['section']) ?></span>
                        </div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fas fa-circle-info"></i></div>
                        <div class="profile-field-content">
                            <span class="label">Status</span>
                            <span class="value">
                                <span class="status-badge <?= safeHtml($status) ?>" style="display: inline-flex;">
                                    <span class="status-dot <?= safeHtml($status) ?>"></span>
                                    <?= safeHtml(ucfirst(str_replace('-', ' ', $status))) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Guardians ── -->
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="profile-section-title">Guardians</div>
                    <span class="profile-section-count"><?= count($guardians) ?></span>
                </div>
                <?php if (empty($guardians)): ?>
                    <div class="empty-mini">No guardians on record.</div>
                <?php else: ?>
                    <?php foreach ($guardians as $guardian):
                        $gName = $guardian['full_name'] ?? '';
                        $gInitials = strtoupper(substr($gName, 0, 1) . substr($gName, strpos($gName, ' ') !== false ? strpos($gName, ' ') + 1 : 1, 1));
                    ?>
                        <div class="guardian-row">
                            <div class="guardian-avatar"><?= safeHtml(substr($gInitials, 0, 2) ?: '?') ?></div>
                            <div class="guardian-info">
                                <div class="guardian-name"><?= safeHtml($gName) ?></div>
                                <div class="guardian-contact">
                                    <?= safeHtml($guardian['relationship'] ?? '') ?> · <?= safeHtml($guardian['contact_number'] ?? 'No contact') ?>
                                </div>
                            </div>
                            <div class="guardian-tags">
                                <?php if (!empty($guardian['is_primary'])): ?><span class="badge badge-primary">Primary</span><?php endif; ?>
                                <?php if (!empty($guardian['is_emergency'])): ?><span class="badge badge-danger">Emergency</span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ── Documents ── -->
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon"><i class="fas fa-file-lines"></i></div>
                    <div class="profile-section-title">Recent Document Requests</div>
                    <span class="profile-section-count"><?= count($documents) ?></span>
                </div>
                <?php if (empty($documents)): ?>
                    <div class="empty-mini">No document requests yet.</div>
                <?php else: ?>
                    <?php foreach ($documents as $doc):
                        $docType = $doc['document_type'] ?? '';
                        $docInitials = strtoupper(substr($docType, 0, 1));
                    ?>
                        <div class="document-row">
                            <div class="document-icon"><i class="fas fa-file"></i></div>
                            <div class="document-info">
                                <div class="document-type"><?= safeHtml(ucfirst(str_replace('_', ' ', $docType))) ?></div>
                                <div class="document-date">
                                    <?= !empty($doc['created_at']) ? date('M d, Y', strtotime($doc['created_at'])) : '—' ?>
                                </div>
                            </div>
                            <div class="document-tags">
                                <?php
                                    $docStatus = $doc['status'] ?? 'pending';
                                    $badgeClass = $docStatus === 'approved' ? 'badge-success' : ($docStatus === 'denied' ? 'badge-danger' : 'badge-warning');
                                ?>
                                <span class="badge <?= safeHtml($badgeClass) ?>"><?= safeHtml(ucfirst($docStatus)) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>