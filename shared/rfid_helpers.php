<?php
// ============================================================
//  SHARED/RFID_HELPERS.PHP
//  Reusable RFID card-resolution + reader-resolution helpers.
//  Extracted from api/rfid-scan.php so the public queue kiosk
//  endpoint validates cards exactly the same way as the
//  staff-side scanner.
//
//  Requires: Database already loaded (shared/database.php).
// ============================================================

/**
 * Auto-expire cards whose expiry_date has passed. Mirrors the
 * maintenance pass done at the top of api/rfid-scan.php POST.
 */
function autoExpireCards(Database $db): void {
    $db->query("UPDATE rfid_cards SET status = 'expired'
                WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()
                  AND status = 'active'");
}

/**
 * Resolve a card UID to its rfid_cards row joined with the student,
 * exactly the shape api/rfid-scan.php uses.
 *
 * @return array|null  Card row with student_id/first_name/last_name/
 *                     student_number/course, or null if unrecognized.
 */
function lookupCardByUid(Database $db, string $cardUid): ?array {
    if ($cardUid === '') return null;
    autoExpireCards($db);
    $card = $db->fetchOne("
        SELECT
            rf.*,
            s.id AS student_id,
            s.first_name,
            s.last_name,
            s.student_number,
            s.course
        FROM rfid_cards rf
        LEFT JOIN students s ON rf.student_id = s.id
        WHERE rf.card_uid = ?
    ", [$cardUid]);
    return $card ?: null;
}

/**
 * Resolve an optional reader id to [reader, location] like the
 * staff scanner: explicit id first, then SCANNER_READER_ID from
 * shared/config.local.php, else null location.
 *
 * @return array [ ?array $reader, string $location ]
 */
function resolveReaderLocation(Database $db, ?int $readerId): array {
    if ($readerId === null || $readerId <= 0) {
        $localConfig = __DIR__ . '/config.local.php';
        if (file_exists($localConfig)) {
            include_once $localConfig;
            if (defined('SCANNER_READER_ID') && SCANNER_READER_ID > 0) {
                $readerId = intval(SCANNER_READER_ID);
            }
        }
    }

    $reader = null;
    $location = 'Registrar Kiosk';
    if ($readerId && $readerId > 0) {
        $reader = $db->fetchOne("SELECT * FROM card_readers WHERE id = ? AND status = 'active'", [$readerId]);
        if ($reader) {
            $location = $reader['location'];
        }
    }
    return [$reader, $location];
}