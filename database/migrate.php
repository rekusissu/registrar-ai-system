<?php
// ============================================================
//  database/migrate.php
//  Idempotent migration runner for the Registrar AI System.
//
//  Applies any migration files inside database/migrations/ that have not yet
//  been recorded in the `migrations` table. Runs on every container boot via
//  the entrypoint (after seed.php), and is safe to run manually for
//  non-Docker deploys:
//
//      php database/migrate.php
//
//  Migration files are executed in alphabetical order. Each file MUST be
//  idempotent (safe to re-run) — but unlike the generic seed.php runner,
//  this one executes the FULL SQL, including row-level changes (UPDATE /
//  DELETE), because these are controlled migrations rather than arbitrary
//  schema patches.
//
//  A migration file that fails is NOT recorded as applied, so it retries on
//  the next run. DDL statements auto-commit in MariaDB, so a partially
//  applied file may leave some changes in place; the included migrations are
//  written to be safe under that failure mode.
// ============================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only.' . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$db   = Database::getInstance();
$pdo  = $db->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Migration tracking table (created once).
$pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$appliedRows = $pdo->fetchAll("SELECT name FROM migrations ORDER BY id");
$appliedNames = array_column($appliedRows, 'name');

$migrationDir = __DIR__ . '/migrations';
if (!is_dir($migrationDir)) {
    echo "No migrations directory found at $migrationDir." . PHP_EOL;
    exit(0);
}

$files = glob($migrationDir . '/*.sql');
if (!$files) {
    echo "No migration files found in $migrationDir." . PHP_EOL;
    exit(0);
}
sort($files);

$appliedCount = 0;
$total        = count($files);
$exitCode     = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $appliedNames, true)) {
        echo "[skipped] $name (already applied)" . PHP_EOL;
        continue;
    }

    echo "[applying] $name ..." . PHP_EOL;

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "  ERROR: could not read $name." . PHP_EOL;
        $exitCode = 1;
        continue;
    }

    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== ''
    );

    $ok = true;
    foreach ($statements as $stmt) {
        $body = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
        if ($body === '') continue;
        if (preg_match('/^USE\s/i', $body)) continue;

        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            echo "  ERROR in $name: " . $e->getMessage() . PHP_EOL;
            echo "    Statement: " . substr($stmt, 0, 240) . PHP_EOL;
            $ok = false;
            break;
        }
    }

    if ($ok) {
        $ins = $pdo->prepare("INSERT INTO migrations (name) VALUES (:name)");
        $ins->execute([':name' => $name]);
        echo "  applied." . PHP_EOL;
        $appliedCount++;
    } else {
        echo "  FAILED — not recorded as applied (will retry next run)." . PHP_EOL;
        $exitCode = 1;
    }
}

echo PHP_EOL . "Migrations: $appliedCount applied, " . ($total - $appliedCount) . " up to date." . PHP_EOL;
exit($exitCode);
