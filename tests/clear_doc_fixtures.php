<?php
// tests/clear_doc_fixtures.php - remove test rows from document_requests.
//
// With no argument: removes the state fixtures written by
// tools/_seed_states.php (official_receipt = 'WIZFIX').
//
// With a tracking number: removes that ONE request and everything hanging
// off it. This is the path tests/doc_wizard_probe.js uses, because it
// files a genuine request through the real wizard and otherwise leaks one
// row per run into the developer's database - where it then shows up on
// the registrar desk as a real student request.
//
//   php tests/clear_doc_fixtures.php DOC-2026-0005
ob_start();
require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$tracking = trim((string) ($argv[1] ?? ''));

if ($tracking !== '') {
    $rows = $db->fetchAll(
        'SELECT id FROM document_requests WHERE request_id = ?',
        [$tracking]
    );
    $what = 'request ' . $tracking;
} else {
    $rows = $db->fetchAll("SELECT id FROM document_requests WHERE official_receipt = 'WIZFIX'");
    $what = 'state fixture(s)';
}

// Collected before the deletes: once the parent row is gone the
// attachment rows are unreachable, and their files would be orphaned on
// disk with nothing left pointing at them.
$paths = [];
foreach ($rows as $r) {
    foreach ($db->fetchAll(
        'SELECT file_path FROM document_request_attachments WHERE request_id = ?',
        [(int) $r['id']]
    ) as $a) {
        $paths[] = (string) $a['file_path'];
    }
    $db->delete('document_request_events', 'request_id = ?', [(int) $r['id']]);
    $db->delete('document_request_attachments', 'request_id = ?', [(int) $r['id']]);
    $db->delete('document_requests', 'id = ?', [(int) $r['id']]);
}

$removedFiles = 0;
foreach ($paths as $p) {
    if ($p !== '' && @unlink(dirname(__DIR__) . '/' . $p)) {
        $removedFiles++;
    }
}

echo 'cleared ' . count($rows) . ' ' . $what
    . ($removedFiles ? ' (+' . $removedFiles . ' uploaded file(s))' : '');