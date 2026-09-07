<?php
// ============================================================
//  RUN_CLINIC_MIGRATION.PHP
//  Applies database/clinic_portal.sql (nurse role + clinic
//  portal health_visits columns). Idempotent — safe to re-run.
//  Usage:  php run_clinic_migration.php
// ============================================================
require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/database/clinic_portal.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $count = 0;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $conn->exec($stmt);
            $count++;
        } catch (Exception $e) {
            echo "Statement error: " . $e->getMessage() . "\n";
            echo "Statement: " . substr($stmt, 0, 100) . "...\n\n";
        }
    }

    echo "✓ Clinic portal migration complete! Executed $count statements.\n";

    // Verify the new columns exist
    $cols = $conn->query("SHOW COLUMNS FROM health_visits")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'Field');
    $needed = ['date_time', 'reason_for_visit', 'assessment', 'action_taken', 'nurse_notes', 'record_status', 'recorded_by', 'blood_type', 'allergies', 'height', 'weight', 'pre_existing_conditions', 'immunization_records'];
    foreach ($needed as $c) {
        echo in_array($c, $names, true) ? "✓ health_visits.$c OK\n" : "✗ health_visits.$c MISSING\n";
    }

    // Verify the nurse role is supported
    $roleCols = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    $roleType = $roleCols['Type'] ?? '';
    echo strpos($roleType, 'nurse') !== false
        ? "✓ users.role supports 'nurse'\n"
        : "✗ users.role does NOT support 'nurse'\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}