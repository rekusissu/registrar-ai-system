<?php
// seed.php — auto-import registrar_ai.sql on fresh databases
// Called by docker entrypoint; can also be run manually: php seed.php

// Only run from CLI or entrypoint (not from web)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit(1);
}

require_once __DIR__ . '/shared/config.php';
// database.php provides the PDO Database helper class.
require_once __DIR__ . '/shared/database.php';

try {
    $db = Database::getInstance();

    // Check if users table already exists
    $r = $db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'", [DB_NAME]);
    if ($r && (int)$r > 0) {
        echo "Database already seeded (users table exists). Skipping.\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/registrar_ai.sql';
    if (!is_file($sqlFile)) {
        echo "ERROR: registrar_ai.sql not found at $sqlFile\n";
        exit(1);
    }

    echo "Database is empty. Importing registrar_ai.sql …\n";

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        echo "ERROR: Could not read registrar_ai.sql\n";
        exit(1);
    }

    // Split on statement separator, filter empty, execute one-by-one
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== ''
    );

    $pdo = $db->getConnection();
    $imported = 0;
    $errors = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $imported++;
        } catch (PDOException $e) {
            $errors++;
            // Duplicate-table or already-exists is fine on re-runs
            if ($e->getCode() !== '42S01' && !str_contains($e->getMessage(), 'already exists')) {
                echo "WARNING: " . $e->getMessage() . "\n  Statement: " . substr($stmt, 0, 120) . "…\n";
            }
        }
    }
    echo "Imported $imported statement(s), $errors warning(s).\n";

    // Verify
    $r = $db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'", [DB_NAME]);
    if ($r && (int)$r > 0) {
        echo "SUCCESS: Database seeded. Users table exists.\n";
        exit(0);
    } else {
        echo "ERROR: Import completed but users table still missing.\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
