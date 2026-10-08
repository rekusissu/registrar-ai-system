<?php
// tools/_count_desk_rows.php -- how many requests should the desk be showing?
//
// tests/desk_probe.js compares the number of <tr data-doc> the browser
// rendered against the number of rows the database holds, so a desk that
// silently drops or invents a request fails the probe.
//
// "Non-draft" is the whole point of the count: a Draft is the student's own
// unfinished form and must never appear on the desk, so counting drafts here
// would make the probe demand the one thing it is meant to catch.
//
// Prints the bare count and nothing else. The caller does parseInt on the
// last line, so a stray notice would be read as a count.
//
// This file is NOT scratch despite the underscore: it is a load-bearing
// dependency of tests/desk_probe.js. An earlier cleanup deleted it on the
// assumption that nothing referenced it, and the probe failed with
// "Command failed" rather than anything informative -- which is the worst
// possible failure mode for a missing test dependency, because it points at
// the probe instead of at the thing that is actually missing.
ob_start();
require __DIR__ . '/../shared/database.php';
ob_end_clean();

$db   = Database::getInstance();
$rows = $db->fetchColumn(
    "SELECT COUNT(*) FROM document_requests WHERE document_status <> 'Draft'"
);

echo ($rows === null || $rows === false) ? 0 : (int) $rows;