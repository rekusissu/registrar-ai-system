<?php
// tests/desk_mark_paid.php - toggle paid_at on a fixture request.
//
//   php tests/desk_mark_paid.php <request_id> [on|off]
//
// Used by tests/desk_probe.js to check the refund warning a PAID but
// cancelled request must carry - the case where money has gone and the
// document has been withdrawn, which is the one a clerk cannot afford
// to miss.
ob_start();
require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$id  = (int) ($argv[1] ?? 0);
$on  = ($argv[2] ?? 'on') !== 'off';
if ($id <= 0) {
    fwrite(STDERR, "usage: php tests/desk_mark_paid.php <request_id> [on|off]\n");
    exit(1);
}

$db->update('document_requests', [
    'paid_at' => $on ? date('Y-m-d H:i:s') : null,
], 'id = ?', [$id]);

echo ($on ? 'marked paid' : 'marked unpaid') . ": request {$id}\n";