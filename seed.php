<?php
// seed.php — auto-import registrar_ai.sql on fresh databases
// Called by docker entrypoint; can also be run manually: php seed.php
//
// After the initial import, runs every *.sql in database/ that uses
// CREATE TABLE IF NOT EXISTS — these are idempotent migrations for
// features added after the base schema (contacts, security, student
// portal, etc.).

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
    $pdo = $db->getConnection();

    // ── Step 1: Import base schema if users table is missing ────────────
    $r = $db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'", [DB_NAME]);
    if ($r && (int)$r > 0) {
        echo "Database already seeded (users table exists).\n";
    } else {
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
    }

    // ── Step 2: Run idempotent migration SQL files ──────────────────────
    // These use CREATE TABLE IF NOT EXISTS / ALTER TABLE guards so they
    // are safe to re-run on every container start.
    $migrationDir = __DIR__ . '/database';
    if (is_dir($migrationDir)) {
        $files = glob($migrationDir . '/*.sql');
        sort($files); // alphabetical — ensures consistent order
        foreach ($files as $file) {
            // Skip files that contain destructive DML (DELETE, DROP without IF)
            $contents = file_get_contents($file);
            if ($contents === false) continue;

            $statements = array_filter(
                array_map('trim', explode(';', $contents)),
                fn($s) => $s !== '' && !preg_match('/^(DELETE|DROP|UPDATE)\s/i', $s)
            );

            $applied = 0;
            $warns   = 0;
            foreach ($statements as $stmt) {
                // Skip non-DDL statements (SET, USE, etc.)
                if (!preg_match('/^(CREATE|ALTER|INSERT|PREPARE|EXECUTE|DEALLOCATE)\s/i', $stmt)) continue;
                try {
                    $pdo->exec($stmt);
                    $applied++;
                } catch (PDOException $e) {
                    $warns++;
                    // Table/column already exists is expected
                    if (!str_contains($e->getMessage(), 'already exists')
                        && $e->getCode() !== '42S01'
                        && !str_contains($e->getMessage(), 'Duplicate')) {
                        echo "  MIGRATION WARNING (" . basename($file) . "): " . $e->getMessage() . "\n";
                    }
                }
            }
            if ($applied > 0) {
                echo "  Applied " . basename($file) . " ($applied statement(s), $warns warning(s))\n";
            }
        }
    }

    // ── Verify ──────────────────────────────────────────────────────────
    $r = $db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'", [DB_NAME]);
    if ($r && (int)$r > 0) {
        echo "SUCCESS: Database ready. Users table exists.\n";
        exit(0);
    } else {
        echo "ERROR: Import completed but users table still missing.\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
