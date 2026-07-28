<?php
// ============================================================
//  API/CHECK-AUTHORIZED-CARD.PHP
//  Verify if a card UID is authorized for station changes
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$cardUid = trim($input['card_uid'] ?? '');

if (empty($cardUid) || strlen($cardUid) !== 10) {
    echo json_encode(['success' => false, 'message' => 'Invalid UID.']);
    exit;
}

try {
    $db = Database::getInstance();
    $card = $db->fetchOne("SELECT * FROM authorized_cards WHERE card_uid = ? AND can_change_station = 1", [$cardUid]);
    if ($card) {
        echo json_encode(['success' => true, 'authorized' => true, 'name' => $card['name'], 'role' => $card['role']]);
    } else {
        echo json_encode(['success' => true, 'authorized' => false]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error.']);
}
?>