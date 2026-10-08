<?php
// tools/_newest_request_id.php -- print the id of the most recent
// document_request row, for tools/_shots.js to photograph.
//
// Prints nothing rather than a wrong answer when there is no row, so the
// caller can skip the tracking page instead of screenshotting an error.
ob_start();
require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$id = $db->fetchColumn("SELECT id FROM document_requests WHERE document_status <> 'Draft' ORDER BY id DESC LIMIT 1");
echo ($id === null || $id === false) ? '' : (int) $id;