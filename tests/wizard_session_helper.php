<?php
// tests/wizard_session_helper.php - mint a student session, print it as
// JSON. Invoked by tests/doc_wizard_probe.js.
//
// Used by tests/doc_wizard_probe.js, which launches a browser with no
// cookies of its own and would otherwise land on the login page - so
// every wizard step would fail for the wrong reason.
//
// Prints ONLY the last line as JSON, so the caller does not have to
// parse around the connection chatter this codebase's includes emit.
ob_start();
require __DIR__ . '/../shared/database.php';
ob_end_clean();

$db = Database::getInstance();
$u  = $db->fetchOne(
    "SELECT u.id, u.role, u.student_id
       FROM users u
      WHERE u.role = 'student' AND u.is_active = 1 AND u.student_id IS NOT NULL
      ORDER BY u.id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "no active student account with a linked student row\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../shared/session_config.php');
preg_match("/session_name\(\s*'([^']+)'\s*\)/", $src, $m);
$name = $m[1];

session_name($name);
session_start();
session_regenerate_id(true);
$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $u['role'];
$_SESSION['student_id']    = (int) $u['student_id'];
$_SESSION['last_activity'] = time();
$_SESSION['login_time']    = time();
session_write_close();

echo json_encode(['name' => $name, 'id' => session_id()]) . "\n";