<?php
// ============================================================
//  API/RFID-AI-CHAT.PHP
//  Conversational RFID card operations AI assistant
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/ai_client.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? []; // [{role, content}] max 20 turns

if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'Empty message.']);
    exit;
}

try {
    $db = Database::getInstance();

    // Gather current inventory context
    $stats = $db->fetchOne("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'available' AND student_id IS NULL) AS available,
            SUM(status = 'expired') AS expired,
            SUM(status = 'lost') AS lost,
            SUM(status = 'archived') AS archived,
            SUM(status = 'inactive') AS inactive
        FROM rfid_cards
    ");

    $expiringSoon = $db->fetchAll("
        SELECT rf.card_uid, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
               s.student_number, rf.expiry_date
        FROM rfid_cards rf
        JOIN students s ON rf.student_id = s.id
        WHERE rf.status = 'active' AND rf.expiry_date IS NOT NULL
          AND rf.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY rf.expiry_date ASC LIMIT 5
    ");

    $recentAssignments = $db->fetchAll("
        SELECT rf.card_uid, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
               rf.assigned_at
        FROM rfid_cards rf
        JOIN students s ON rf.student_id = s.id
        WHERE rf.assigned_at IS NOT NULL
        ORDER BY rf.assigned_at DESC LIMIT 5
    ");

    $systemPrompt = "You are the RFID Card Inventory AI assistant for a school registrar system.
Your role is to help with RFID card operations: registration, assignment, archiving, and inventory management.

Current inventory snapshot:
- Total cards: {$stats['total']}
- Active (assigned): {$stats['active']}
- Available (unassigned pool): {$stats['available']}
- Expired: {$stats['expired']}
- Lost: {$stats['lost']}
- Archived: {$stats['archived']}

Cards expiring within 30 days: " . (empty($expiringSoon) ? 'None' : implode('; ', array_map(function($e) { return $e['card_uid'] . ' (' . $e['student_name'] . ', expires ' . $e['expiry_date'] . ')'; }, $expiringSoon))) . "

Recent assignments: " . (empty($recentAssignments) ? 'None' : implode('; ', array_map(function($a) { return $a['card_uid'] . ' → ' . $a['student_name'] . ' (' . $a['assigned_at'] . ')'; }, $recentAssignments))) . "

Capabilities:
- Answer questions about inventory status and trends
- Advise on best practices for card lifecycle management
- Help troubleshoot card issues

Rules:
- Only advise on operations; don't generate card UIDs yourself.
- Be concise. Use bullet points when listing multiple items.
- When users ask about specific students, suggest they use the search/filter in the UI.
- Never execute operations directly — the user uses the UI buttons for that.
- Do NOT use markdown formatting. No asterisks, no bold, no headers. Reply in plain text only.";

    // Build conversation history into a single user prompt for context
    $fullUserPrompt = '';
    $trimmedHistory = array_slice($history, -20);
    if (!empty($trimmedHistory)) {
        $fullUserPrompt .= "Previous conversation:\n";
        foreach ($trimmedHistory as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
            $fullUserPrompt .= $role . ': ' . ($turn['content'] ?? '') . "\n";
        }
        $fullUserPrompt .= "\n---\n\n";
    }
    $fullUserPrompt .= $message;

    $reply = aiGenerate($systemPrompt, $fullUserPrompt, [
        'max_tokens' => 1024,
        'temperature' => 0.4,
        'forceRefresh' => true,
    ]);

    if (empty($reply)) {
        echo json_encode(['success' => false, 'message' => 'AI service unavailable. Check AI_API_KEY configuration.']);
        exit;
    }

    echo json_encode(['success' => true, 'reply' => $reply]);
    exit;

} catch (Exception $e) {
    json_error($e);
}
?>
