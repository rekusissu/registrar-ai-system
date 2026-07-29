<?php
// ============================================================
//  API/STUDENTS.PHP
//  Student CRUD operations
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // ─── GET ALL STUDENTS ──────────────────────────────────────
    if ($method === 'GET' && !$id) {
        $students = $db->fetchAll(
            "SELECT * FROM students ORDER BY created_at DESC"
        );
        echo json_encode(['success' => true, 'data' => $students]);
        exit;
    }

    // ─── GET SINGLE STUDENT ────────────────────────────────────
    if ($method === 'GET' && $id) {
        $student = $db->fetchOne(
            "SELECT * FROM students WHERE id = ?",
            [$id]
        );
        if ($student) {
            echo json_encode(['success' => true, 'data' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
        }
        exit;
    }

    // ─── CREATE STUDENT ────────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
            exit;
        }

        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $address   = trim($input['address'] ?? '');

        if ($firstName === '' || $lastName === '' || $address === '') {
            echo json_encode(['success' => false, 'message' => 'First name, last name, and address are required.']);
            exit;
        }

        // Generate student number if not provided
        $studentNumber = isset($input['student_number']) && trim($input['student_number']) !== ''
            ? trim($input['student_number'])
            : generateStudentNumber();

        // Check uniqueness — bail out if student_number already exists
        $existing = $db->fetchOne("SELECT id FROM students WHERE student_number = ?", [$studentNumber]);
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'Student number already exists.']);
            exit;
        }

        $birthDateRaw = trim((string)($input['birth_date'] ?? ''));
        $birthDate = ($birthDateRaw !== '' && $birthDateRaw !== '0000-00-00') ? $birthDateRaw : null;

        $data = [
            'student_number' => $studentNumber,
            'first_name' => $firstName,
            'middle_name' => isset($input['middle_name']) && $input['middle_name'] !== '' ? trim($input['middle_name']) : null,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'place_of_birth' => $input['place_of_birth'] ?? null,
            'nationality' => $input['nationality'] ?? null,
            'religion' => $input['religion'] ?? null,
            'address' => $address,
            'contact_number' => $input['contact_number'] ?? null,
            'email' => $input['email'] ?? null,
            'course' => $input['course'] ?? null,
            'year_level' => isset($input['year_level']) && $input['year_level'] !== '' ? (int)$input['year_level'] : null,
            'section' => $input['section'] ?? null,
            'status' => $input['status'] ?? 'active'
        ];

        $newId = $db->insert('students', $data);
        echo json_encode(['success' => true, 'message' => 'Student added successfully.', 'data' => ['id' => $newId, 'student_number' => $studentNumber]]);
        exit;
    }

    // ─── UPDATE STUDENT ────────────────────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
            exit;
        }

        $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $data = [];
        $allowedFields = ['first_name', 'middle_name', 'last_name', 'birth_date', 'place_of_birth',
                          'nationality', 'religion', 'address', 'contact_number', 'email',
                          'course', 'year_level', 'section', 'status'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if ($value === '' && in_array($field, ['birth_date', 'middle_name', 'place_of_birth', 'nationality', 'religion', 'contact_number', 'email', 'course', 'year_level', 'section'], true)) {
                    $value = null;
                }
                if ($field === 'birth_date' && $value === '0000-00-00') {
                    $value = null;
                }
                if ($field === 'year_level' && $value !== null && $value !== '') {
                    $value = (int)$value;
                } elseif ($field === 'year_level' && $value === '') {
                    $value = null;
                }
                $data[$field] = $value;
            }
        }

        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => 'No data to update.']);
            exit;
        }

        $db->update('students', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
        exit;
    }

    // ─── BULK STATUS UPDATE ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'bulk-status') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        $status = $input['status'] ?? '';
        if (empty($ids) || !$status) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        foreach ($ids as $sid) {
            $db->update('students', ['status' => $status], 'id = ?', [intval($sid)]);
        }
        echo json_encode(['success' => true, 'message' => count($ids) . ' student(s) updated.']);
        exit;
    }

    // ─── DELETE STUDENT ────────────────────────────────────────
    if ($method === 'DELETE' && $id) {
        $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        // Clean up related rows where the schema links to students
        try { $db->delete('guardians', 'student_id = ?', [$id]); } catch (Exception $e) {}
        try { $db->delete('document_requests', 'student_id = ?', [$id]); } catch (Exception $e) {}

        $deleted = $db->delete('students', 'id = ?', [$id]);
        if ($deleted) {
            echo json_encode(['success' => true, 'message' => 'Student deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete student.']);
        }
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>