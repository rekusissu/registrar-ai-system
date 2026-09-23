<?php
// ============================================================
//  fix_bad_email_domains.php
//  One-time migration: replaces @bestlink.edu.ph emails with
//  @gmail.com (derived from MAIL_FROM) in students + users.
//
//  Usage:
//    php fix_bad_email_domains.php            # dry-run (preview)
//    php fix_bad_email_domains.php --run       # apply changes
//    php fix_bad_email_domains.php --domain=X  # target domain to replace
// ============================================================

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

$targetDomain = 'bestlink.edu.ph';

// Parse --domain=X override
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--domain=')) {
        $targetDomain = trim(substr($arg, strlen('--domain=')));
    }
}

$dryRun = !in_array('--run', $argv, true);
$mode   = $dryRun ? 'DRY RUN' : 'LIVE';

// Derive replacement domain from MAIL_FROM
$replacementDomain = (defined('MAIL_FROM') && MAIL_FROM !== '')
    ? substr(MAIL_FROM, strrpos(MAIL_FROM, '@') + 1)
    : '';

if ($replacementDomain === '' || $replacementDomain === $targetDomain) {
    echo "Cannot fix: replacement domain '$replacementDomain' is empty or same as target.\n";
    echo "Set MAIL_FROM in email_secret.local or as an env var.\n";
    exit(1);
}

$db = Database::getInstance();
$affected = 0;

echo "$mode — Replacing @$targetDomain → @$replacementDomain\n";
echo str_repeat('-', 60) . "\n";

// 1. Students table
$students = $db->fetchAll(
    "SELECT id, student_number, email FROM students WHERE email LIKE ?",
    ['%@' . $targetDomain]
);
echo "students table: " . count($students) . " record(s) with @$targetDomain\n";

foreach ($students as $s) {
    $oldEmail = $s['email'];
    $newEmail = str_replace('@' . $targetDomain, '@' . $replacementDomain, $oldEmail);
    printf("  #%d %-12s  %s → %s\n", $s['id'], $s['student_number'] ?? '', $oldEmail, $newEmail);
    if (!$dryRun) {
        $db->update('students', ['email' => $newEmail], 'id = ?', [(int) $s['id']]);
    }
    $affected++;
}

// 2. Users table (portal accounts)
$users = $db->fetchAll(
    "SELECT id, username, email FROM users WHERE email LIKE ?",
    ['%@' . $targetDomain]
);
echo "users table: " . count($users) . " record(s) with @$targetDomain\n";

foreach ($users as $u) {
    $oldEmail = $u['email'];
    $newEmail = str_replace('@' . $targetDomain, '@' . $replacementDomain, $oldEmail);
    printf("  #%d user=%-16s  %s → %s\n", $u['id'], $u['username'], $oldEmail, $newEmail);
    if (!$dryRun) {
        $db->update('users', ['email' => $newEmail], 'id = ?', [(int) $u['id']]);
    }
    $affected++;
}

echo str_repeat('-', 60) . "\n";
echo "Total affected: $affected record(s)\n";

if ($dryRun) {
    echo "\nRe-run with --run to apply.\n";
} else {
    echo "\nDone. All @$targetDomain emails replaced with @$replacementDomain.\n";
}
?>
