<?php
// tools/_seed_states.php - leave one row in each wizard status, so the
// student list and the registrar desk can be rendered against real data
// for every state rather than only the ones that happen to exist.
//
// This is a THROWAWAY fixture: it writes rows into the live table and
// prints their ids. It does not clean up after itself - the point is
// that they stay put while a human looks at the pages.
//
//   php tools/_seed_states.php
require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$studentId = 1;
$tor = $db->fetchOne("SELECT id, sku FROM document_catalog WHERE sku='DOC-TOR'");
if (!$tor) { fwrite(STDERR, "no DOC-TOR in the catalog\n"); exit(1); }

// Remove any previous fixture rows so re-running is idempotent.
foreach ($db->fetchAll(
    "SELECT id FROM document_requests WHERE official_receipt = 'WIZFIX'"
) as $old) {
    $db->delete('document_request_events', 'request_id = ?', [(int) $old['id']]);
    $db->delete('document_request_attachments', 'request_id = ?', [(int) $old['id']]);
    $db->delete('document_requests', 'id = ?', [(int) $old['id']]);
}

$states = [
    'Draft'            => ['step' => 2, 'paid' => false, 'purpose' => 'Employment - not submitted yet'],
    'Filed'            => ['step' => 4, 'paid' => false, 'purpose' => 'Employment'],
    'Awaiting_Payment' => ['step' => 4, 'paid' => false, 'purpose' => 'Board exam'],
    'Pending_Clearance' => ['step' => 4, 'paid' => true, 'purpose' => 'Transfer to another school'],
    'Processing'       => ['step' => 4, 'paid' => true, 'purpose' => 'Employment'],
    'Ready'            => ['step' => 4, 'paid' => true, 'purpose' => 'Job application'],
    'Shipped'          => ['step' => 4, 'paid' => true, 'purpose' => 'Scholarship application', 'shipped' => true],
    'Claimed'          => ['step' => 4, 'paid' => true, 'purpose' => 'Personal records'],
    'Rejected'         => ['step' => 4, 'paid' => true, 'purpose' => 'Employment'],
    'Cancelled'        => ['step' => 4, 'paid' => true, 'purpose' => 'Cancelled by the student'],
    // A signed COURIER request. The only row that should carry the
    // "Mark dispatched" button, so it is the one that proves the courier
    // leg exists - without it the button is never rendered and a gap in
    // doc_next_step() is invisible.
    'Ready (courier)'  => ['step' => 4, 'paid' => true, 'purpose' => 'Employment - offshore', 'shipped' => true],
];

$made = [];
foreach ($states as $status => $cfg) {
    // "Ready (courier)" is a courier request that has been signed but not
    // dispatched; the bare status stored is still Ready.
    $storedStatus = $status === 'Ready (courier)' ? 'Ready' : $status;
    $now = date('Y-m-d H:i:s', time() - (count($made) + 1) * 3600);
    $id = (int) $db->insert('document_requests', [
        'request_id'       => null,
        'student_id'       => $studentId,
        'document_type'    => 'transcript',
        'catalog_id'       => (int) $tor['id'],
        'quantity'         => 1,
        'request_type'     => 'Regular',
        'fulfillment_type' => !empty($cfg['shipped']) ? 'Delivery' : 'Pickup',
        'delivery_address' => !empty($cfg['shipped']) ? '12 Katipunan, Quezon City' : null,
        'payment_method'   => !empty($cfg['shipped']) ? 'Bank_Transfer' : 'Online',
        'purpose'          => $cfg['purpose'],
        'purpose_code'     => 'employment',
        'notes'            => 'Seeded fixture row for rendering checks.',
        'status'           => $storedStatus === 'Claimed' ? 'released' : (in_array($storedStatus, ['Rejected', 'Cancelled'], true) ? 'denied' : 'pending'),
        'document_status'  => $storedStatus,
        'fee_amount'       => 250.00,
        'source'           => 'online',
        'wizard_step'      => $cfg['step'],
        'submitted_at'     => $status === 'Draft' ? null : $now,
        'request_date'     => $now,
        'paid_at'          => !empty($cfg['paid']) ? $now : null,
        // A signed courier request is ready_at but NOT shipped_at - that is
        // precisely the state the dispatch button exists for.
        'ready_at'         => in_array($storedStatus, ['Ready', 'Shipped', 'Claimed'], true) ? $now : null,
        'shipped_at'       => $storedStatus === 'Shipped' ? $now : null,
        'claimed_at'       => $status === 'Claimed' ? $now : null,
        'cancelled_at'     => $status === 'Cancelled' ? $now : null,
        'cancellation_reason' => $status === 'Cancelled' ? 'Found a certified copy elsewhere.' : null,
        'rejection_reason' => $status === 'Rejected' ? 'Account balance was not settled at filing.' : null,
        'estimated_release_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
        'payment_reference' => 'D26-' . str_pad((string) 0, 6, '0', STR_PAD_LEFT),
        // Marks the row as a fixture, so it can be found and removed
        // again. Reusing official_receipt rather than adding a column
        // keeps the migration surface unchanged.
        'official_receipt' => 'WIZFIX',
        'qr_hash'          => hash('sha256', $status . '|' . random_bytes(8)),
    ]);

    // The tracking number is assigned after insert, exactly as the API
    // does it, so the seed exercises the same uniqueness constraint.
    $db->update('document_requests', [
        'request_id'       => 'DOC-' . date('Y') . '-F' . substr(str_pad((string) $id, 4, '0', STR_PAD_LEFT), 0, 4),
        'payment_reference' => 'D26-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
    ], 'id = ?', [$id]);

    if ($storedStatus !== 'Draft') {
        $db->insert('document_request_events', [
            'request_id' => $id,
            'status'     => $storedStatus,
            'note'       => 'Seeded fixture: ' . $cfg['purpose'],
            'created_by' => null,
            'created_at' => $now,
        ]);
    }

    $made[$status] = $id;
}

echo "seeded " . count($made) . " fixture rows for student $studentId\n";
foreach ($made as $st => $id) {
    printf("  %-18s id=%d\n", $st, $id);
}
echo "\nfixture id: " . reset($made) . " (Draft)\n";