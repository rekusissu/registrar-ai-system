<?php
// Quick migration runner
require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Read and execute the migration
    $sql = file_get_contents(__DIR__ . '/database/registrar_upgrade.sql');

    // Split by semicolon and execute each statement
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

    echo "✓ Migration complete! Executed $count statements.\n";

    // Verify status_tracker table exists
    $result = $conn->query("SHOW TABLES LIKE 'status_tracker'");
    if ($result->rowCount() > 0) {
        echo "✓ status_tracker table created successfully.\n";
    } else {
        echo "✗ status_tracker table not found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
