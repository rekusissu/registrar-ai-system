<?php
// tests/clear_login_probe.php - drop the throttle rows a login probe created.
//
//   php tests/clear_login_probe.php <identifier>
//
// shared/login_throttle.php writes a row per attempt to login_attempts and
// blocks the identifier at five failures in fifteen minutes. A tool that
// signs in unsuccessfully therefore has to clean up after itself, or its
// own testing locks a real account out of the system.
//
// Only the named identifier is removed, and only on rows that are failures.
// Nothing else in the table is touched — the throttle history for real
// accounts is the thing that is protecting them.
ob_start();
require __DIR__ . '/../shared/database.php';
ob_end_clean();

$identifier = trim((string) ($argv[1] ?? ''));
if ($identifier === '') {
    fwrite(STDERR, "usage: php tests/clear_login_probe.php <identifier>\n");
    exit(1);
}

$db = Database::getInstance();
try {
    $db->query(
        'DELETE FROM login_attempts WHERE email = ? AND success = 0',
        [$identifier]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'could not clear: ' . $e->getMessage() . "\n");
    exit(1);
}

$left = (int) $db->fetchColumn(
    'SELECT COUNT(*) FROM login_attempts WHERE email = ?',
    [$identifier]
);

echo $left === 0
    ? 'cleared the login throttle rows for ' . $identifier
    : 'WARNING: ' . $left . ' row(s) remain for ' . $identifier;