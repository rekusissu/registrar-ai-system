<?php
// ============================================================
//  API/RFID-AI-SEARCH.PHP
//  AI-powered RFID card search
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Search query is required.']);
    exit;
}

try {
    $db = Database::getInstance();
    $queryLower = strtolower($query);

    // Fetch all cards with student info
    $cards = $db->fetchAll("
        SELECT 
            rf.*,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            s.student_number,
            s.course,
            s.year_level
        FROM rfid_cards rf
        LEFT JOIN students s ON rf.student_id = s.id
        ORDER BY rf.id DESC
    ");

    $filters = [];
    $searchTerm = '';
    $interpretation = 'Showing all cards';

    // ─── STATUS DETECTION ──────────────────────────────────────
    $statusKeywords = [
        'expired' => ['expired', 'expire', 'outdated', 'old', 'past due'],
        'active' => ['active', 'activated', 'working', 'valid'],
        'lost' => ['lost', 'missing', 'stolen', 'gone'],
        'inactive' => ['inactive', 'disabled', 'deactivated']
    ];

    foreach ($statusKeywords as $status => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($queryLower, $keyword) !== false) {
                $filters['status'] = $status;
                $interpretation = "Showing $status cards";
                break 2;
            }
        }
    }

    // ─── EXPIRING SOON DETECTION ────────────────────────────────
    $expiringSoon = false;
    $expiringKeywords = ['soon', 'expiring', 'about to expire', 'near expiry', 'nearly'];
    foreach ($expiringKeywords as $keyword) {
        if (strpos($queryLower, $keyword) !== false) {
            $expiringSoon = true;
            $interpretation = 'Showing cards expiring within 30 days';
            break;
        }
    }

    // ─── COURSE DETECTION ──────────────────────────────────────
    $courses = ['nursing', 'computer', 'education', 'accountancy', 'psychology', 'engineering', 'business', 'arts'];
    foreach ($courses as $course) {
        if (strpos($queryLower, $course) !== false) {
            $filters['course'] = $course;
            $interpretation = "Showing $course students";
            break;
        }
    }

    // ─── STUDENT NAME DETECTION ────────────────────────────────
    if (preg_match('/(?:student|name|find|search|show|for)\s+([a-z\s]+)/i', $query, $matches) && isset($matches[1])) {
        $possibleName = trim($matches[1]);
        $commandWords = ['show', 'me', 'find', 'search', 'list', 'all', 'cards', 'card'];
        if (!in_array(strtolower($possibleName), $commandWords) && strlen($possibleName) > 2) {
            $searchTerm = $possibleName;
            $interpretation = "Searching for student: $possibleName";
        }
    }

    // ─── STUDENT NUMBER DETECTION ──────────────────────────────
    if (preg_match('/\b\d{4}-\d{4}\b/', $query, $matches)) {
        $searchTerm = $matches[0];
        $interpretation = "Searching for student ID: " . $matches[0];
    }

    // ─── CARD UID DETECTION ────────────────────────────────────
    if (preg_match('/\b\d{10}\b/', $query, $matches)) {
        $filters['card_uid'] = $matches[0];
        $interpretation = "Searching for card: " . $matches[0];
    }

    // ─── APPLY FILTERS ──────────────────────────────────────────
    $results = $cards;
    $today = time();

    if (isset($filters['status'])) {
        $results = array_filter($results, function($card) use ($filters) {
            return $card['status'] === $filters['status'];
        });
    }

    if ($expiringSoon) {
        $results = array_filter($results, function($card) use ($today) {
            if ($card['status'] !== 'active') return false;
            if (empty($card['expiry_date'])) return false;
            $daysLeft = (strtotime($card['expiry_date']) - $today) / (60 * 60 * 24);
            return $daysLeft <= 30 && $daysLeft > 0;
        });
        $interpretation = 'Showing ' . count($results) . ' cards expiring within 30 days';
    }

    if (isset($filters['course'])) {
        $results = array_filter($results, function($card) use ($filters) {
            return stripos($card['course'] ?? '', $filters['course']) !== false;
        });
    }

    if (isset($filters['card_uid'])) {
        $results = array_filter($results, function($card) use ($filters) {
            return $card['card_uid'] === $filters['card_uid'];
        });
    }

    if (!empty($searchTerm) && empty($filters['status']) && empty($filters['card_uid']) && !$expiringSoon) {
        $term = strtolower($searchTerm);
        $results = array_filter($results, function($card) use ($term) {
            return (
                stripos($card['student_name'] ?? '', $term) !== false ||
                stripos($card['student_number'] ?? '', $term) !== false ||
                stripos($card['course'] ?? '', $term) !== false ||
                stripos($card['card_uid'] ?? '', $term) !== false
            );
        });
    }

    // ─── GENERATE INSIGHTS ──────────────────────────────────────
    $insights = [];
    $expiredCount = count(array_filter($results, fn($c) => $c['status'] === 'expired'));
    $lostCount = count(array_filter($results, fn($c) => $c['status'] === 'lost'));
    $activeCount = count(array_filter($results, fn($c) => $c['status'] === 'active'));

    if ($expiredCount > 0) $insights[] = "$expiredCount expired cards";
    if ($lostCount > 0) $insights[] = "$lostCount lost cards";
    if ($activeCount > 0 && count($results) > 0) $insights[] = "$activeCount active cards";

    echo json_encode([
        'success' => true,
        'query' => $query,
        'ai_interpretation' => $interpretation,
        'insights' => $insights,
        'results' => array_values($results),
        'count' => count($results)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Search failed: ' . $e->getMessage()]);
}
?>