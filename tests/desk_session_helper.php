<?php
// tests/desk_session_helper.php - mint a REGISTRAR session and print it
// as JSON, for tests/desk_probe.js.
//
// The browser is launched with no cookies, so without this it lands on
// the login page and every desk assertion fails for the wrong reason.
//
// The last line is the JSON; the buffering keeps anything the included
// files print off stdout, so the caller can just take the last line.
ob_start();
require __DIR__ . '/../shared/database.php';
ob_end_clean();

$db = Database::getInstance();
$u  = $db->fetchOne(
    "SELECT id FROM users WHERE role IN ('registrar','admin') AND is_active = 1 ORDER BY role = 'registrar' DESC, id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "no active registrar or admin account\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../shared/session_config.php');
preg_match("/session_name\(\s*'([^']+)'\s*\)/", $src, $m);
$name = $m[1];

session_name($name);
session_start();
session_regenerate_id(true);
$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $db->fetchColumn('SELECT role FROM users WHERE id = ?', [(int) $u['id']]);
$_SESSION['last_activity'] = time();
$_SESSION['login_time']    = time();
session_write_close();

echo json_encode(['name' => $name, 'id' => session_id()]) . "\n";