<?php
// ============================================================
//  STUDENT/PROFILE.PHP
//  Student's own record: personal info, parents/guardians,
//  emergency contacts, and status timeline.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Profile';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_profile';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$guardians = $db->fetchAll(
    "SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
    [$student['id']]
);
$emergency = $db->fetchAll(
    "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
    [$student['id']]
);
$statusLog = $db->fetchAll(
    "SELECT st.*, u.full_name AS changed_by_name
     FROM status_tracker st
     LEFT JOIN users u ON u.id = st.changed_by
     WHERE st.student_id = ?
     ORDER BY st.created_at DESC LIMIT 6",
    [$student['id']]
);

$fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . ($student['name_suffix'] ?? ''));
$firstLast = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
if (empty($firstLast)) $firstLast = $fullName;
$initial  = strtoupper(substr(trim($firstLast), 0, 1));
$photo    = $student['photo'] ?? '';
$photoUrl = $photo ? $APP_ROOT . ltrim($photo, './') : '';
$statusPillCls = $statusPillDot = 'active';
$statusNow = strtolower($student['status'] ?? 'active');
foreach (['enrolled','probation','at-risk','graduated','loa','transferred','dropped','inactive'] as $st) {
    if ($statusNow === $st) { $statusPillCls = $statusPillDot = $st; break; }
}
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Profile</h1><p>Your personal record on file with the Registrar.</p></div>
        </header>

        <!-- Identity card -->
        <div class="profile-card" style="margin:0 0 24px;">
            <div class="profile-header">
                <div class="student-avatar profile-avatar-lg blue">
                    <?php if ($photoUrl): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <?= htmlspecialchars($initial) ?>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h2><?= htmlspecialchars($fullName) ?></h2>
                    <div class="student-no"><?= htmlspecialchars($student['student_number'] ?? '—') ?></div>
                    <div class="profile-meta">
                        <span class="profile-meta-item"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($student['course'] ?? '—') ?><?= $student['major'] ? ' · ' . htmlspecialchars($student['major']) : '' ?></span>
                        <span class="profile-meta-item"><i class="fa-solid fa-calendar-days"></i> Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?><?= $student['section'] ? ' · Section ' . htmlspecialchars($student['section']) : '' ?></span>
                        <span class="profile-meta-item"><i class="fa-solid fa-flag"></i> <?= htmlspecialchars($student['nationality'] ?? '—') ?></span>
                        <span class="profile-meta-item"><i class="fa-solid fa-van-shuttle"></i> <?= !empty($student['is_transferee']) ? 'Transferee' : 'Regular' ?></span>
                    </div>
                </div>
                <div style="flex-shrink:0;">
                    <span class="pill <?= $statusPillCls ?>"><span class="status-dot <?= $statusPillDot ?>"></span> <?= ucfirst(htmlspecialchars($student['status'] ?? 'Active')) ?></span>
                </div>
            </div>
            <div class="profile-body">
                <div class="profile-grid">

                    <div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-fingerprint"></i></div>
                            <div class="profile-field-content"><span class="label">LRN</span><span class="value"><?= htmlspecialchars($student['lrn'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-genderless"></i></div>
                            <div class="profile-field-content"><span class="label">Gender</span><span class="value"><?= htmlspecialchars($student['gender'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-ring"></i></div>
                            <div class="profile-field-content"><span class="label">Civil Status</span><span class="value"><?= htmlspecialchars($student['civil_status'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-cake-candles"></i></div>
                            <div class="profile-field-content"><span class="label">Birth Date</span><span class="value"><?= htmlspecialchars($student['birth_date'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-map-pin"></i></div>
                            <div class="profile-field-content"><span class="label">Place of Birth</span><span class="value"><?= htmlspecialchars($student['place_of_birth'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-earth-asia"></i></div>
                            <div class="profile-field-content"><span class="label">Nationality</span><span class="value"><?= htmlspecialchars($student['nationality'] ?? '—') ?></span></div>
                        </div>
                    </div>

                    <div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-church"></i></div>
                            <div class="profile-field-content"><span class="label">Religion</span><span class="value"><?= htmlspecialchars($student['religion'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-house"></i></div>
                            <div class="profile-field-content"><span class="label">Address</span><span class="value"><?= htmlspecialchars($student['address'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-phone"></i></div>
                            <div class="profile-field-content"><span class="label">Contact Number</span><span class="value"><?= htmlspecialchars($student['contact_number'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-envelope"></i></div>
                            <div class="profile-field-content"><span class="label">Email</span><span class="value"><?= htmlspecialchars($student['email'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field">
                            <div class="profile-field-icon"><i class="fa-solid fa-person-breastfeeding"></i></div>
                            <div class="profile-field-content"><span class="label">Mother</span><span class="value"><?= htmlspecialchars($student['mother_name'] ?? '—') ?></span></div>
                        </div>
                        <div class="profile-field" style="border-bottom:none;">
                            <div class="profile-field-icon"><i class="fa-solid fa-person"></i></div>
                            <div class="profile-field-content"><span class="label">Father</span><span class="value"><?= htmlspecialchars($student['father_name'] ?? '—') ?></span></div>
                        </div>
                    </div>

                </div>

                <?php if ($guardians || $emergency): ?>
                <div class="profile-section">
                    <div class="profile-section-header">
                        <div class="profile-section-icon"><i class="fa-solid fa-users"></i></div>
                        <span class="profile-section-title">Guardians &amp; Emergency Contacts</span>
                        <span class="profile-section-count"><?= count($guardians) + count($emergency) ?></span>
                    </div>
                    <?php foreach ($guardians as $g): ?>
                    <div class="guardian-row">
                        <div class="guardian-avatar"><?= strtoupper(substr(htmlspecialchars(trim($g['full_name'])), 0, 1)) ?></div>
                        <div class="guardian-info">
                            <div class="guardian-name"><?= htmlspecialchars($g['full_name']) ?></div>
                            <div class="guardian-contact"><?= htmlspecialchars(ucfirst($g['relationship'] ?? '')) ?><?= $g['contact_number'] ? ' · ' . htmlspecialchars($g['contact_number']) : '' ?><?= $g['email'] ? ' · ' . htmlspecialchars($g['email']) : '' ?></div>
                        </div>
                        <div class="guardian-tags"><?= $g['is_primary'] ? '<span class="chip blue"><i class="fa-solid fa-star"></i> Primary</span>' : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($emergency as $e): ?>
                    <div class="guardian-row">
                        <div class="guardian-avatar" style="background:linear-gradient(135deg,#b45309,#92400e);"><?= strtoupper(substr(htmlspecialchars(trim($e['full_name'])), 0, 1)) ?></div>
                        <div class="guardian-info">
                            <div class="guardian-name"><?= htmlspecialchars($e['full_name']) ?></div>
                            <div class="guardian-contact"><?= htmlspecialchars($e['relationship'] ?? '') ?><?= $e['contact_number'] ? ' · ' . htmlspecialchars($e['contact_number']) : '' ?></div>
                        </div>
                        <div class="guardian-tags"><span class="chip gray"><i class="fa-solid fa-truck-medical"></i> Emergency</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Status history -->
        <div class="panel">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-arrows-rotate" style="color:#b45309;"></i> Status History</div>
                <div class="panel-actions"><span class="chip gray">Last <?= count($statusLog) ?></span></div>
            </div>
            <?php if (empty($statusLog)): ?>
                <div class="empty-state"><i class="fa-solid fa-arrows-rotate"></i><p>No status changes recorded</p><span>Changes to your student status will appear here.</span></div>
            <?php else: ?>
                <div style="padding:16px 20px;">
                    <div class="activity-list">
                        <?php foreach ($statusLog as $s): ?>
                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon <?= in_array(strtolower($s['current_status'] ?? ''), ['probation','at-risk']) ? 'risk' : ($s['previous_status'] ? 'active' : 'warning') ?>">
                                    <i class="fa-solid fa-right-left"></i>
                                </div>
                                <div class="activity-info">
                                    <div class="activity-name">
                                        <span class="pill <?= in_array(strtolower($s['previous_status'] ?? ''), ['probation','at-risk','dropped']) ? 'inactive' : 'inactive' ?>"><?= htmlspecialchars(ucfirst($s['previous_status'] ?? '—')) ?></span>
                                        <i class="fa-solid fa-arrow-right" style="color:#94a3b8;margin:0 6px;font-size:12px;"></i>
                                        <span class="pill active"><?= htmlspecialchars(ucfirst($s['current_status'])) ?></span>
                                    </div>
                                    <div class="activity-detail"><?= !empty($s['remarks']) ? htmlspecialchars($s['remarks']) : 'Status updated' ?></div>
                                </div>
                            </div>
                            <div class="activity-time">
                                <?= date('M d, Y', strtotime($s['created_at'])) ?>
                                <?= !empty($s['changed_by_name']) ? '<br>· ' . htmlspecialchars($s['changed_by_name']) : '' ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<?php include '../includes/footer.php'; ?>