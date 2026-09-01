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

// Check if users table already exists
$r = $conn->query("SHOW TABLES LIKE 'users'");
if ($r && $r->num_rows > 0) {
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

// Execute multi_query — handles multiple statements separated by ;
$conn->multi_query($sql);
while ($conn->next_result()) {
    $conn->store_result();
}

if ($conn->errno) {
    echo "ERROR during import: " . $conn->error . "\n";
    exit(1);
}

// Verify
$r = $conn->query("SHOW TABLES LIKE 'users'");
if ($r && $r->num_rows > 0) {
    echo "SUCCESS: Database seeded. Users table exists.\n";
    exit(0);
} else {
    echo "ERROR: Import completed but users table still missing.\n";
    exit(1);
}
