<?php
/**
 * Run the queue logging migration
 * This can be accessed via browser to apply the database migration
 */

header('Content-Type: text/plain');

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

$db = Database::getInstance();

try {
    echo "Starting migration...\n";

    // Extend event_type ENUM
    $sql = "ALTER TABLE `rfid_scan_logs`
MODIFY `event_type` enum('entry','exit','library','cafeteria','other','queue_join','queue_call','queue_serving','queue_completed','queue_no_show','queue_cancelled') DEFAULT 'entry'";

    $db->query($sql);
    echo "Migration applied successfully!\n";

    // Verify
    $result = $db->fetchOne("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'rfid_scan_logs' AND COLUMN_NAME = 'event_type'");
    echo "Current event_type: " . $result['COLUMN_TYPE'] . "\n";

    echo "\nDone!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}