<?php
// ============================================================
//  SCRIPTS/ADOPT_LEGACY_ATTACHMENTS.PHP
//  Adopt pre-existing requirement files into the wizard's
//  attachment table.
//
//  Run once after migrations/document_request_wizard.sql:
//
//      php scripts/adopt_legacy_attachments.php
//
//  WHY THIS IS PHP AND NOT SQL
//  --------------------------
//  document_requests.requirement_file_path predates the wizard and
//  points at a real uploaded file on every request that has one.
//  Left alone, the wizard's upload step would report "nothing attached"
//  for a request the office demonstrably already holds a file for - and
//  the student's reaction would be to upload it again.
//
//  Building the attachment row needs two things SQL cannot portably do:
//
//  1. The file's SHA-256 and byte size. The hash is the whole reason
//     the column exists: it is what answers, months later, "is the file
//     the registrar is looking at the file that was uploaded?". A row
//     written without it proves nothing.
//
//  2. A real check that the file still EXISTS. A row pointing at
//     nothing is worse than no row at all - it makes a missing file
//     look present, which is the one thing the checklist exists to
//     prevent.
//
//  Either of those done badly produces a silent data-quality problem,
//  so it is done where the filesystem can be asked.
//
//  IDEMPOTENT: rows are only added where the request has no
//  attachment yet, so re-running adds nothing.
// ============================================================

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/schema.php';

$db = Database::getInstance();

if (!db_table_exists('document_request_attachments')) {
    fwrite(STDERR, "document_request_attachments does not exist."
        . " Run migrations/document_request_wizard.sql first.\n");
    exit(1);
}

$root = dirname(__DIR__);

$candidates = $db->fetchAll(
    "SELECT id, request_id, requirement_file_path, catalog_id
       FROM document_requests
      WHERE requirement_file_path IS NOT NULL
        AND requirement_file_path <> ''"
);

if (!$candidates) {
    echo "No legacy requirement files to adopt.\n";
    exit(0);
}

// Which checklist items exist per catalog, so a file can be filed
// against the right one where that is unambiguous.
$reqByCatalog = [];
foreach ($db->fetchAll(
    "SELECT catalog_id, code, label FROM document_type_requirements
      WHERE is_active = 1 ORDER BY catalog_id, sort_order, id"
) as $r) {
    $reqByCatalog[(int) $r['catalog_id']][] = $r;
}

$adopted = $skipped = $missing = 0;

foreach ($candidates as $row) {
    $requestId = (int) $row['id'];
    $rel       = (string) $row['requirement_file_path'];

    // Already adopted by a previous run.
    $has = (int) $db->fetchColumn(
        'SELECT COUNT(*) FROM document_request_attachments WHERE request_id = ?', [$requestId]
    );
    if ($has > 0) { $skipped++; continue; }

    // The file has to be there. A path that no longer resolves is
    // recorded as skipped rather than adopted, because a row pointing
    // at a missing file would make the wizard claim a requirement is met
    // when it is not.
    $abs = $root . '/' . ltrim($rel, '/');
    if (!is_file($abs) || !is_readable($abs)) {
        echo "  skip  #{$requestId}: file not on disk ($rel)\n";
        $missing++;
        continue;
    }

    // File the attachment against a checklist item ONLY where this SKU
    // names exactly one. With two or more there is no honest way to know
    // which one this file is, and guessing would let the wizard tick a
    // box on the strength of a guess - which is exactly what the
    // checklist is supposed to prevent.
    $code = null;
    $items = $reqByCatalog[(int) ($row['catalog_id'] ?? 0)] ?? [];
    if (count($items) === 1) {
        $code = (string) $items[0]['code'];
    }

    $db->insert('document_request_attachments', [
        'request_id'       => $requestId,
        'requirement_code' => $code,
        'original_name'    => mb_substr(basename($rel), 0, 180),
        'file_path'        => $rel,
        'sha256'           => hash_file('sha256', $abs),
        'mime_type'        => function_exists('mime_content_type')
            ? (string) (@mime_content_type($abs) ?: null)
            : null,
        'size_bytes'       => (int) filesize($abs),
        'uploaded_at'      => date('Y-m-d H:i:s'),
    ]);

    $adopted++;
    echo "  adopted #{$requestId}" . ($code !== null ? " -> $code" : ' (unkeyed)') . "\n";
}

echo "\nadopted: $adopted   already had one: $skipped   file missing: $missing\n";
echo "The legacy requirement_file_path column is left in place; the walk-in\n";
echo "counter form still writes it.\n";