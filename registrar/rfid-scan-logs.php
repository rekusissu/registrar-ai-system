<?php
// ============================================================
//  REGISTRAR/RFID-SCAN-LOGS.PHP
//  RFID scan logs history
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$logs = $db->fetchAll("
    SELECT 
        l.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number
    FROM rfid_scan_logs l
    LEFT JOIN students s ON l.student_id = s.id
    ORDER BY l.scanned_at DESC
    LIMIT 100
");

$page_title = 'Scan Logs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>Scan Logs</h1>
            <p>Record of all RFID card taps</p>
        </div>
        <div class="header-actions">
            <a href="rfid-cards.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Cards
            </a>
        </div>
    </header>

    <div class="table-container">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Card UID</th>
                        <th>Student</th>
                        <th>Location</th>
                        <th>Event</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <i class="fas fa-credit-card text-5xl block mb-3 text-gray-300"></i>
                                <p class="text-lg font-medium text-gray-400">No scan logs yet</p>
                                <p class="text-sm text-gray-400">Tap a card to see logs appear here</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-gray-600 text-sm"><?= date('M d, Y h:i:s A', strtotime($log['scanned_at'])) ?></td>
                                <td><span class="font-mono text-sm font-medium"><?= htmlspecialchars($log['card_uid']) ?></span></td>
                                <td><?= $log['student_name'] ? htmlspecialchars($log['student_name']) : '<span class="text-gray-400">Unknown</span>' ?></td>
                                <td><?= htmlspecialchars($log['location'] ?? 'Main Gate') ?></td>
                                <td><span class="capitalize"><?= htmlspecialchars($log['event_type'] ?? 'entry') ?></span></td>
                                <td>
                                    <span class="status-badge <?= $log['status'] ?>">
                                        <span class="status-dot"></span> <?= ucfirst($log['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>