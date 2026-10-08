<?php
// tests/desk_attach_fixture.php - attach a real image to one request, so
// the desk's paperwork panel is exercised with a file actually on disk.
//
// The desk endpoint refuses to attach (ownership is enforced against the
// session's student), which is correct - so a registrar cannot fabricate
// a student's paperwork. This is a TEST fixture writing the same row the
// upload endpoint would, bypassing the API on purpose.
//
//   php tests/desk_attach_fixture.php <request_id>
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    $h = ob_get_level() > 0 ? ob_get_clean() : '';
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE], true)) {
        $h .= "\nFATAL: " . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n";
    }
    if ($h !== '') echo $h;
});

require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$requestId = (int) ($argv[1] ?? 0);
if ($requestId <= 0) {
    fwrite(STDERR, "usage: php tests/desk_attach_fixture.php <request_id>\n");
    exit(1);
}

$row = $db->fetchOne('SELECT catalog_id, student_id FROM document_requests WHERE id = ?', [$requestId]);
if (!$row) {
    fwrite(STDERR, "no such request: {$requestId}\n");
    exit(1);
}

// Clear anything from a previous run so re-running is idempotent.
foreach ($db->fetchAll('SELECT file_path FROM document_request_attachments WHERE request_id = ?', [$requestId]) as $old) {
    @unlink(dirname(__DIR__) . '/' . $old['file_path']);
}
$db->delete('document_request_attachments', 'request_id = ?', [$requestId]);

// A real photograph of a real ID, copied from tests/fixtures/.
//
// Not generated here: this box has no GD extension, and the fallback was
// a 1x1 pixel. A 1x1 scaled into the panel's thumbnail is a flat red
// square - it proves the bytes arrive, but it tells nobody whether an
// actual photographed ID is recognisable at a glance, which is the whole
// reason the thumbnail is inline rather than a filename link.
//
// The asset is committed as a file for that reason. A base64 literal in
// this script would be unreadable and would make the image impossible to
// regenerate by hand.
$dir = dirname(__DIR__) . '/uploads/document_requirements/';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$name = 'desk-fixture-' . $requestId . '.png';
$abs  = $dir . $name;
copy(__DIR__ . '/fixtures/desk-school-id.png', $abs);

// Keyed against the real checklist where one exists, so the panel shows
// it as SATISFIED rather than as a loose file.
$code = null;
$items = $db->fetchAll(
    'SELECT code FROM document_type_requirements WHERE catalog_id = ? AND is_active = 1 ORDER BY sort_order, id',
    [(int) $row['catalog_id']]
);
if (!empty($items)) {
    $code = (string) $items[0]['code'];
}

$db->insert('document_request_attachments', [
    'request_id'       => $requestId,
    'requirement_code' => $code,
    'original_name'    => 'desk-fixture-id.png',
    'file_path'        => 'uploads/document_requirements/' . $name,
    'sha256'           => hash_file('sha256', $abs),
    'mime_type'        => 'image/png',
    'size_bytes'       => (int) filesize($abs),
    'uploaded_at'      => date('Y-m-d H:i:s'),
]);

// A note, so the "their note" line has something in it.
$db->update('document_requests', [
    'notes' => 'Please text me when it is ready — I finish at 6pm.',
], 'id = ?', [$requestId]);

echo 'attached to request ' . $requestId . ' as ' . ($code ?? 'loose') . "\n";