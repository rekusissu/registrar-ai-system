<?php
// tools/_vocab_check.php -- print the stamp and the waiting-on sentence for
// every status, and for a real row per status.
//
// A vocabulary table is only correct if it was checked against real rows,
// not against the map that defines it. tools/_seed_states.php writes one
// row per status first.
ob_start();
require __DIR__ . '/../shared/database.php';
require __DIR__ . '/../shared/doc_wizard.php';
$db = Database::getInstance();

echo "--- doc_status_meta() ---\n";
foreach (doc_status_meta() as $k => $v) {
    printf("  %-20s stamp=%-18s ink=%-9s terminal=%s%s",
        $k, $v['stamp'], $v['ink'], $v['terminal'] ? 'y' : 'n', PHP_EOL);
}

echo "--- doc_waiting_on() against a real row per status ---\n";
$rows = $db->fetchAll(
    "SELECT * FROM document_requests WHERE document_status <> 'Draft' ORDER BY id"
);
$seen = [];
foreach ($rows as $r) {
    $st = (string) $r['document_status'];
    if (isset($seen[$st])) continue;
    $seen[$st] = true;
    $w = doc_waiting_on($r);
    printf("  %-20s who=%-7s urgent=%-5s %s%s",
        $st, $w['who'], $w['urgent'] ? 'yes' : 'no', $w['what'], PHP_EOL);
}

echo "--- doc_waiting_on() on a synthetic draft ---\n";
$w = doc_waiting_on(['document_status' => 'Draft', 'fee_amount' => 0]);
printf("  %-20s who=%-7s urgent=%-5s %s%s", 'Draft', $w['who'], $w['urgent'] ? 'yes' : 'no', $w['what'], PHP_EOL);

echo "--- an unknown status must not fatal ---\n";
$s = doc_status('Nonsense');
printf("  label=%s stamp=%s ink=%s%s", $s['label'], $s['stamp'], $s['ink'], PHP_EOL);