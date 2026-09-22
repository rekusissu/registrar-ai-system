<?php
// ============================================================
//  BACKFILL: Create student_ids for RFID cards missing one
//  Run once: C:\xampp\php\php.exe database\migrate_rfid_to_student_ids.php
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/qr_generator.php';

$db = Database::getInstance();

// RFID cards that don't have a linked school_id in student_ids
$cards = $db->fetchAll("
    SELECT rf.id AS rfid_id, rf.student_id, rf.card_uid, rf.issued_date, rf.expiry_date
    FROM rfid_cards rf
    WHERE rf.student_id IS NOT NULL
      AND rf.id NOT IN (
          SELECT rfid_card_id FROM student_ids WHERE rfid_card_id IS NOT NULL
      )
      AND rf.status != 'archived'
");

echo "Found " . count($cards) . " RFID card(s) without student_ids.\n";

$created = 0;
foreach ($cards as $card) {
    $studentId = $card['student_id'];
    // Skip if student already has an active school_id via another path
    $existing = $db->fetchOne(
        "SELECT id FROM student_ids WHERE student_id = ? AND id_type = 'school_id' AND status = 'active'",
        [$studentId]
    );
    if ($existing) {
        echo "  Skip student #{$studentId} — already has active school_id.\n";
        continue;
    }

    $idNumber = generateNextIdNumber($db);
    $qrPath = generateStudentQrFile($idNumber, intval($studentId));

    $data = [
        'student_id'   => $studentId,
        'id_number'    => $idNumber,
        'id_type'      => 'school_id',
        'issue_date'   => $card['issued_date'] ?: date('Y-m-d'),
        'expiry_date'  => $card['expiry_date'],
        'status'       => 'active',
        'rfid_card_id' => $card['rfid_id'],
    ];
    if ($qrPath) $data['qr_code_path'] = $qrPath;

    $db->insert('student_ids', $data);
    $created++;
    echo "  Created: student #{$studentId} → ID {$idNumber}" . ($qrPath ? " (QR)" : " (no QR)") . "\n";
}

echo "\nDone. Created {$created} student ID record(s).\n";
