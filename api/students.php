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
require_once __DIR__ . '/../shared/normalize.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // ─── GET GUARDIAN ──────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardian' && isset($_GET['student_id'])) {
        $guardian = $db->fetchOne("SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardian]);
        exit;
    }

    // ─── GET ACADEMIC HISTORY ──────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'academic' && isset($_GET['student_id'])) {
        $data = $db->fetchAll("SELECT * FROM academic_history WHERE student_id = ? ORDER BY created_at DESC", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET HEALTH ────────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'health' && isset($_GET['student_id'])) {
        $data = $db->fetchOne("SELECT * FROM health_records WHERE student_id = ?", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET DOCUMENT REQUESTS ─────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'documents' && isset($_GET['student_id'])) {
        $data = $db->fetchAll("SELECT * FROM document_requests WHERE student_id = ? ORDER BY request_date DESC", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET LAST SCAN ────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'lastscan' && isset($_GET['student_id'])) {
        $student = $db->fetchOne("SELECT card_uid FROM rfid_cards WHERE student_id = ?", [intval($_GET['student_id'])]);
        if ($student) {
            $scan = $db->fetchOne("SELECT scanned_at, location, event_type, status FROM rfid_scan_logs WHERE card_uid = ? ORDER BY scanned_at DESC LIMIT 1", [$student['card_uid']]);
            echo json_encode(['success' => true, 'data' => $scan]);
        } else {
            echo json_encode(['success' => true, 'data' => null]);
        }
        exit;
    }

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

    // ─── PHOTO UPLOAD ─────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload-photo') {
        $studentId = intval($_POST['student_id'] ?? 0);
        if (!$studentId || !isset($_FILES['photo'])) {
            echo json_encode(['success' => false, 'message' => 'No file or student ID.']);
            exit;
        }
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
            exit;
        }
        $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
        $dest = __DIR__ . '/../uploads/students/' . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            $photoUrl = '../uploads/students/' . $filename;
            $db->update('students', ['photo' => $photoUrl], 'id = ?', [$studentId]);
            echo json_encode(['success' => true, 'photo_url' => $photoUrl]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed.']);
        }
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

        // B3: normalize before save so bad data never lands.
        $firstName = normalizeNameCase($firstName);
        $lastName  = normalizeNameCase($lastName);
        $address   = trim($address);

        $data = [
            'student_number' => $studentNumber,
            'first_name' => $firstName,
            'middle_name' => isset($input['middle_name']) && $input['middle_name'] !== '' ? normalizeNameCase(trim($input['middle_name'])) : null,
            'last_name' => $lastName,
            'gender' => $input['gender'] ?? null,
            'civil_status' => $input['civil_status'] ?? null,
            'birth_date' => $birthDate,
            'place_of_birth' => isset($input['place_of_birth']) && $input['place_of_birth'] !== '' ? normalizeNameCase(trim($input['place_of_birth'])) : null,
            'nationality' => isset($input['nationality']) && trim($input['nationality']) !== '' ? trim($input['nationality']) : null,
            'religion' => isset($input['religion']) && trim($input['religion']) !== '' ? normalizeNameCase(trim($input['religion'])) : null,
            'address' => $address,
            'contact_number' => isset($input['contact_number']) && trim($input['contact_number']) !== '' ? normalizePhone(trim($input['contact_number'])) : null,
            'email' => isset($input['email']) && trim($input['email']) !== '' ? strtolower(trim($input['email'])) : null,
            'course' => isset($input['course']) && trim($input['course']) !== '' ? courseStandardize(trim($input['course'])) : null,
            'major' => isset($input['major']) && trim($input['major']) !== '' ? trim($input['major']) : null,
            'year_level' => isset($input['year_level']) && $input['year_level'] !== '' ? (int)$input['year_level'] : null,
            'school_year' => isset($input['school_year']) && trim($input['school_year']) !== '' ? trim($input['school_year']) : null,
            'semester' => $input['semester'] ?? null,
            'section' => $input['section'] ?? null,
            'adviser_id' => isset($input['adviser_id']) && $input['adviser_id'] !== '' ? (int)$input['adviser_id'] : null,
            'status' => $input['status'] ?? 'active'
        ];

        $newId = $db->insert('students', $data);

        // Insert guardian
        $guardianName = trim($input['guardian_name'] ?? '');
        if ($guardianName !== '') {
            try {
                $db->insert('guardians', [
                    'student_id' => $newId,
                    'full_name' => $guardianName,
                    'relationship' => $input['guardian_relationship'] ?? 'guardian',
                    'contact_number' => $input['guardian_contact'] ?? '',
                    'email' => $input['guardian_email'] ?? null
                ]);
            } catch (Exception $e) {}
        }

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
        $allowedFields = ['first_name', 'middle_name', 'last_name', 'gender', 'civil_status', 'birth_date', 'place_of_birth',
                          'nationality', 'religion', 'address', 'contact_number', 'email',
                          'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id', 'status'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if ($value === '' && in_array($field, ['birth_date', 'middle_name', 'place_of_birth', 'nationality', 'religion', 'contact_number', 'email', 'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id'], true)) {
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
                if ($field === 'adviser_id' && $value !== null) {
                    $value = (int)$value;
                }
                // B3: normalize text fields before saving.
                if ($value !== null && $value !== '') {
                    if (in_array($field, ['first_name', 'middle_name', 'last_name', 'place_of_birth', 'religion'], true)) {
                        $value = normalizeNameCase((string) $value);
                    } elseif ($field === 'contact_number') {
                        $value = normalizePhone((string) $value);
                    } elseif ($field === 'email') {
                        $value = strtolower(trim((string) $value));
                    } elseif ($field === 'course') {
                        $value = courseStandardize((string) $value);
                    } elseif ($field === 'address' || $field === 'nationality' || $field === 'major' || $field === 'school_year') {
                        $value = trim((string) $value);
                    }
                }
                $data[$field] = $value;
            }
        }

        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => 'No data to update.']);
            exit;
        }

        $db->update('students', $data, 'id = ?', [$id]);
        // Update guardian if provided
        $guardianName = trim($input['guardian_name'] ?? '');
        if ($guardianName !== '') {
            $existingGuardian = $db->fetchOne("SELECT id FROM guardians WHERE student_id = ?", [$id]);
            $gData = [
                'full_name' => $guardianName,
                'relationship' => $input['guardian_relationship'] ?? 'guardian',
                'contact_number' => $input['guardian_contact'] ?? '',
                'email' => $input['guardian_email'] ?? null
            ];
            if ($existingGuardian) {
                $db->update('guardians', $gData, 'student_id = ?', [$id]);
            } else {
                $gData['student_id'] = $id;
                $db->insert('guardians', $gData);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
        exit;
    }

    // ─── SOFT DELETE STUDENT ────────────────────────────────────
    if ($method === 'DELETE' && $id) {
        $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $db->update('students', ['status' => 'archived'], 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Student deactivated.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>