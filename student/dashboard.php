<?php
// ============================================================
//  STUDENT/DASHBOARD.PHP
//  Student portal home — welcome hero, quick status,
//  announcements, and quick actions.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Student Dashboard';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_dashboard';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// Latest published announcements
$announcements = $db->fetchAll(
    "SELECT a.*, u.full_name AS author_name
     FROM announcements a
     LEFT JOIN users u ON u.id = a.author_id
     WHERE a.is_published = 1
     ORDER BY a.created_at DESC
     LIMIT 4"
);

// Document request count + pending count
$docTotal = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM document_requests WHERE student_id = ?",
    [$student['id']]
);
$docPending = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM document_requests WHERE student_id = ? AND status = 'pending'",
    [$student['id']]
);

// Today's queue ticket (own)
$today = date('Y-m-d');
// Active queue only — a ticket that was completed, marked no-show,
// or removed no longer counts as the student's active queue.
$queue = $db->fetchOne(
    "SELECT * FROM queue_tickets
     WHERE queue_date = ? AND student_id = ?
       AND status IN ('waiting', 'serving')
     ORDER BY joined_at DESC LIMIT 1",
    [$today, $student['id']]
);
$queuePosition = 0;
if ($queue && $queue['status'] === 'waiting') {
    $queuePosition = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM queue_tickets
         WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
        [$today, (int) $queue['ticket_number']]
    );
}

// Canonical status label (Enrolled / Active / Graduated / Transferred / Dropped)
$statusLabel = getStudentStatusLabel($student['status'] ?? 'enrolled');
$enrollmentLabel = ($student['status'] ?? '') === 'enrolled'
    ? 'Currently Enrolled'
    : ucfirst(str_replace('-', ' ', strtolower((string) $student['status'])));
$fullName = trim(trim($student['first_name'] ?? '') . ' ' . trim($student['last_name'] ?? ''));
if (empty($fullName)) $fullName = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$initial  = strtoupper(substr(trim($fullName), 0, 1));
$photo    = $student['photo'] ?? '';
$photoUrl = $photo ? $APP_ROOT . ltrim($photo, './') : '';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- Welcome hero -->
        <div class="student-intro">
            <div class="intro-avatar">
                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo">
                <?php else: ?>
                    <?= htmlspecialchars($initial) ?>
                <?php endif; ?>
            </div>
            <div class="intro-body">
                <div class="intro-greet">Welcome back</div>
                <div class="intro-title"><?= htmlspecialchars($fullName) ?> 👋</div>
                <div class="intro-sub">Your BCP student portal — records, documents, queue, and updates in one place.</div>
                <div class="intro-meta">
                    <span class="intro-chip"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($student['student_number'] ?? '—') ?></span>
                    <span class="intro-chip"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($student['course'] ?? '—') ?></span>
                    <span class="intro-chip"><i class="fa-solid fa-calendar-days"></i> Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></span>
                </div>
            </div>
        </div>

        <!-- Status strip -->
        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-user-graduate"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars($statusLabel) ?></div>
                    <div class="s-label">Student Status · <?= htmlspecialchars($enrollmentLabel) ?></div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-file-lines"></i></div>
                <div>
                    <div class="s-value"><?= $docPending ?> <span style="font-size:14px;font-weight:600;color:#94a3b8;">/ <?= $docTotal ?></span></div>
                    <div class="s-label">Pending Documents</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon purple"><i class="fa-solid fa-display"></i></div>
                <div>
                    <div class="s-value"><?= $queue ? str_pad((string)$queue['ticket_number'], 3, '0', STR_PAD_LEFT) : '—' ?></div>
                    <div class="s-label"><?= $queue ? ('Queue · ' . ucfirst($queue['status'])) : 'No queue today' ?></div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-id-badge"></i></div>
                <div>
                    <div class="s-value"><?= $docTotal ?></div>
                    <div class="s-label">Document Requests</div>
                </div>
            </div>
        </div>

        <!-- Current Status block -->
        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-user-graduate" style="color:#2563eb;"></i> Current Status</div>
                <?php if (($student['status'] ?? '') === 'enrolled'): ?>
                    <div class="panel-actions"><span class="pill active"><i class="fa-solid fa-circle-check"></i> Currently Enrolled</span></div>
                <?php endif; ?>
            </div>
            <div style="padding:16px 20px;">
                <div class="status-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Student ID</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($student['student_number'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Student Name</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($fullName) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Current Student Status</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($statusLabel) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Program / Course</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($student['course'] ?? '—') ?><?= !empty($student['major']) ? ' · ' . htmlspecialchars($student['major']) : '' ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Year Level</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;">Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Section</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($student['section'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Academic Year</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($student['school_year'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Semester</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($student['semester'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:600;margin-bottom:4px;">Enrollment Status</div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($enrollmentLabel) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="student-grid">

            <!-- Announcements -->
            <div class="panel">
                <div class="panel-toolbar">
                    <div class="panel-title"><i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Announcements</div>
                    <div class="panel-actions">
                        <a href="<?= $APP_ROOT ?>student/announcements.php" class="btn btn-sm btn-light"><i class="fa-solid fa-arrow-right"></i> View all</a>
                    </div>
                </div>
                <?php if (empty($announcements)): ?>
                    <div class="empty-state"><i class="fa-solid fa-bullhorn"></i><p>No announcements yet</p><span>Check back soon for updates from the Registrar.</span></div>
                <?php else: foreach ($announcements as $a): ?>
                    <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                            <div style="font-weight:600;color:#0f172a;font-size:14px;"><?= htmlspecialchars($a['title']) ?></div>
                            <span class="chip blue" style="flex-shrink:0;"><i class="fa-solid fa-clock"></i> <?= date('M d', strtotime($a['created_at'])) ?></span>
                        </div>
                        <div style="color:#64748b;font-size:13px;margin-top:5px;line-height:1.5;"><?= htmlspecialchars(mb_strimwidth((string)($a['body'] ?? ''), 0, 140, '…')) ?></div>
                        <?php if (!empty($a['author_name'])): ?>
                        <div style="color:#94a3b8;font-size:11px;margin-top:7px;">
                            <i class="fa-solid fa-user-pen"></i> <?= htmlspecialchars($a['author_name']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Today's queue -->
            <div class="panel">
                <div class="panel-toolbar">
                    <div class="panel-title"><i class="fa-solid fa-display" style="color:#7c3aed;"></i> My Queue</div>
                    <div class="panel-actions">
                        <a href="<?= $APP_ROOT ?>student/queue.php" class="btn btn-sm btn-light"><i class="fa-solid fa-arrow-right"></i> Details</a>
                    </div>
                </div>
                <?php if (!$queue): ?>
                    <div style="padding:26px 20px;text-align:center;">
                        <i class="fa-solid fa-ticket" style="font-size:38px;color:#e2e8f0;"></i>
                        <p style="margin:12px 0 2px;font-size:14px;font-weight:600;color:#64748b;">No queue ticket today</p>
                        <p style="margin:0;color:#94a3b8;font-size:13px;">Tap your RFID card at the kiosk to join the line.</p>
                        <a href="<?= $APP_ROOT ?>student/queue.php" class="btn btn-sm btn-primary" style="margin-top:16px;"><i class="fa-solid fa-display"></i> Go to My Queue</a>
                    </div>
                <?php else: ?>
                    <div style="padding:20px;">
                        <div class="queue-quick">
                            <div class="qq-number">#<?= str_pad((string)$queue['ticket_number'], 3, '0', STR_PAD_LEFT) ?></div>
                            <div class="qq-info">
                                <div class="qq-status">
                                    <span class="pill <?= $queue['status'] === 'waiting' ? 'pending' : ($queue['status'] === 'serving' ? 'active' : 'inactive') ?>">
                                        <i class="fa-solid fa-<?= $queue['status'] === 'waiting' ? 'clock' : ($queue['status'] === 'serving' ? 'bell-concierge' : 'circle-check') ?>"></i>
                                        <?= ucfirst($queue['status']) ?>
                                    </span>
                                </div>
                                <?php if ($queue['status'] === 'waiting'): ?>
                                    <div class="qq-detail"><?= max(0, $queuePosition - 1) ?> ahead you · <?= $queuePosition === 1 ? 'You are next!' : 'Position ' . $queuePosition . ' in line' ?></div>
                                <?php elseif ($queue['status'] === 'serving'): ?>
                                    <div class="qq-detail" style="color:#16a34a;font-weight:600;">It's your turn — proceed to Counter <?= htmlspecialchars((string)$queue['counter']) ?>.</div>
                                <?php else: ?>
                                    <div class="qq-detail">Ticket <?= ucfirst($queue['status']) ?> today.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</main>

<?php include '../includes/footer.php'; ?>