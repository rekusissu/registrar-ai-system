<?php
// ============================================================
//  API/MASTERLIST.PHP
//  Auto-assign sections & masterlist helpers
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance();
    $maxPerSection = defined('MAX_STUDENTS_PER_SECTION') ? (int) MAX_STUDENTS_PER_SECTION : 50;

    if ($method === 'GET') {
        $courseFilter = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
        $yearFilter = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';

        $sql = "SELECT * FROM students WHERE 1=1";
        $params = [];

        if ($courseFilter !== '') {
            $sql .= " AND TRIM(course) = ?";
            $params[] = $courseFilter;
        }
        if ($yearFilter !== '' && is_numeric($yearFilter)) {
            $sql .= " AND year_level = ?";
            $params[] = (int) $yearFilter;
        }

        $sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC";

        $students = $db->fetchAll($sql, $params);
        $groups = groupStudentsForMasterlist($students);

        echo json_encode([
            'success' => true,
            'max_per_section' => $maxPerSection,
            'total' => count($students),
            'groups' => $groups,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $action = $input['action'] ?? 'assign_sections';
        if ($action !== 'assign_sections') {
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
        }

        $requestedMax = isset($input['max_per_section']) ? (int) $input['max_per_section'] : $maxPerSection;
        if ($requestedMax < 1 || $requestedMax > 500) {
            $requestedMax = $maxPerSection;
        }

        $result = autoAssignStudentSections($requestedMax);

        echo json_encode([
            'success' => true,
            'message' => 'Sections assigned successfully.',
            'max_per_section' => $requestedMax,
            'updated' => $result['updated'],
            'sections' => $result['sections'],
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
