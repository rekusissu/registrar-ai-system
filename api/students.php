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
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
// Admin + registrar only
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
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

    // ─── GET ALL GUARDIANS (Subsystem 2 — multi) ────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardians' && isset($_GET['student_id'])) {
        $guardians = $db->fetchAll(
            "SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, is_emergency DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardians]);
        exit;
    }

    // ─── SAVE GUARDIAN (add or update) ──────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $gId = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        if (!in_array($input['relationship'] ?? '', ['father','mother','guardian','spouse','sibling'], true)) {
            $input['relationship'] = 'guardian';
        }
        $gData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => $input['relationship'],
            'contact_number' => trim($input['contact_number'] ?? ''),
            'email'          => ($input['email'] ?? '') !== '' ? trim($input['email']) : null,
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
            'is_emergency'   => !empty($input['is_emergency']) ? 1 : 0,
        ];
        if ($gData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Guardian name required.']); exit; }
        if ($gId > 0) {
            $db->update('guardians', $gData, 'id = ? AND student_id = ?', [$gId, $studentId]);
        } else {
            $gData['student_id'] = $studentId;
            $gId = $db->insert('guardians', $gData);
        }
        echo json_encode(['success' => true, 'message' => 'Guardian saved.', 'data' => ['id' => $gId]]);
        exit;
    }

    // ─── DELETE GUARDIAN ────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Guardian ID required.']); exit; }
        $db->delete('guardians', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Guardian deleted.']);
        exit;
    }

    // ─── EMERGENCY CONTACTS (Subsystem 2) ───────────────────────
    // GET ?action=emergency&student_id=N   → list
    // POST ?action=save-emergency         → add/update
    // POST ?action=delete-emergency       → remove
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'emergency' && isset($_GET['student_id'])) {
        $rows = $db->fetchAll(
            "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $id = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        $eData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => trim($input['relationship'] ?? ''),
            'contact_number' => trim($input['contact_number'] ?? ''),
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
        ];
        if ($eData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Contact name required.']); exit; }
        if ($id > 0) {
            $db->update('emergency_contacts', $eData, 'id = ? AND student_id = ?', [$id, $studentId]);
        } else {
            $eData['student_id'] = $studentId;
            $id = $db->insert('emergency_contacts', $eData);
        }
        echo json_encode(['success' => true, 'message' => 'Emergency contact saved.', 'data' => ['id' => $id]]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Contact ID required.']); exit; }
        $db->delete('emergency_contacts', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Emergency contact deleted.']);
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

    // ─── SAVE ACADEMIC HISTORY ─────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $schoolName = trim($input['school_name'] ?? '');
        if (!$studentId || $schoolName === '') {
            echo json_encode(['success' => false, 'message' => 'Student and school name are required.']);
            exit;
        }
        $data = [
            'student_id'        => $studentId,
            'school_name'       => $schoolName,
            'school_year'       => $input['school_year'] ?? null,
            'grade_level'       => $input['grade_level'] ?? null,
            'gwa'               => ($input['gwa'] ?? '') !== '' ? (float) $input['gwa'] : null,
            'subjects_completed'=> ($input['subjects_completed'] ?? '') !== '' ? (int) $input['subjects_completed'] : null,
            'semester'          => $input['semester'] ?? null,
            'remarks'           => $input['remarks'] ?? null
        ];
        // Guard optional columns (pre-migration safety)
        $cols = $db->fetchAll("SHOW COLUMNS FROM academic_history");
        $colNames = array_column($cols, 'Field');
        if (!in_array('semester', $colNames, true)) unset($data['semester']);

        $recordId = intval($input['id'] ?? 0);
        if ($recordId) {
            $db->update('academic_history', $data, 'id = ?', [$recordId]);
        } else {
            $recordId = $db->insert('academic_history', $data);
        }

        // Store per-subject grades (Subsystem 3) if provided
        if (isset($input['grades']) && is_array($input['grades'])) {
            $tblExists = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades'");
            if ($tblExists) {
                // Remove existing grades for this record, then re-insert
                $db->delete('academic_grades', 'academic_history_id = ?', [$recordId]);
                foreach ($input['grades'] as $g) {
                    $subject = trim($g['subject'] ?? '');
                    if ($subject === '') continue;
                    $db->insert('academic_grades', [
                        'academic_history_id' => $recordId,
                        'subject'  => $subject,
                        'units'    => ($g['units'] ?? '') !== '' ? (float) $g['units'] : null,
                        'grade'    => ($g['grade'] ?? '') !== '' ? (float) $g['grade'] : null,
                        'remarks'  => ($g['remarks'] ?? '') !== '' ? trim($g['remarks']) : null,
                    ]);
                }
            }
            // Also recompute subjects_completed from actual grade count
            if (!empty($input['grades'])) {
                $db->update('academic_history', ['subjects_completed' => count($input['grades'])], 'id = ?', [$recordId]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Academic record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── GET ACADEMIC GRADES (Subsystem 3) ─────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'grades' && isset($_GET['record_id'])) {
        $grades = $db->fetchAll(
            "SELECT * FROM academic_grades WHERE academic_history_id = ? ORDER BY id ASC",
            [intval($_GET['record_id'])]);
        echo json_encode(['success' => true, 'data' => $grades]);
        exit;
    }

    // ─── DELETE ACADEMIC HISTORY ───────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $recordId = intval($input['id'] ?? 0);
        if (!$recordId) {
            echo json_encode(['success' => false, 'message' => 'Record ID required.']);
            exit;
        }
        $db->delete('academic_history', 'id = ?', [$recordId]);
        echo json_encode(['success' => true, 'message' => 'Academic record deleted.']);
        exit;
    }

    // ─── SAVE HEALTH RECORD ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH RECORD ── include blood_pressure / dietary_restrictions
    //     (columns added by registrar_upgrade.sql — guarded with branch)
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health-full') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'blood_pressure'          => $input['blood_pressure'] ?? null,
            'dietary_restrictions'    => $input['dietary_restrictions'] ?? null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH VISITS (Subsystem 4 — timeline) ────────────────
    // GET ?action=visits&student_id=N   → list visits
    // POST ?action=add-visit            → create a visit
    // POST ?action=delete-visit         → remove a visit
    $hasVisits = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits'") === 1;
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'visits' && isset($_GET['student_id'])) {
        if (!$hasVisits) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $visits = $db->fetchAll(
            "SELECT * FROM health_visits WHERE student_id = ? ORDER BY visit_date DESC, id DESC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $visits]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        if (!$hasVisits) { echo json_encode(['success' => false, 'message' => 'Run registrar_upgrade.sql first (health_visits table missing).']); exit; }
        $id = $db->insert('health_visits', [
            'student_id'     => $studentId,
            'visit_date'     => ($input['visit_date'] ?? '') !== '' ? $input['visit_date'] : date('Y-m-d'),
            'complaint'      => $input['complaint'] ?? null,
            'diagnosis'      => $input['diagnosis'] ?? null,
            'temperature'    => ($input['temperature'] ?? '') !== '' ? (float) $input['temperature'] : null,
            'blood_pressure' => $input['blood_pressure'] ?? null,
            'treatment'      => $input['treatment'] ?? null,
            'medication'     => $input['medication'] ?? null,
            'physician'      => $input['physician'] ?? null,
            'notes'          => $input['notes'] ?? null,
            'created_at'     => date('Y-m-d H:i:s')
        ]);
        // bump clinic_visits counter
        $db->getConnection()->exec("UPDATE health_records hr SET hr.clinic_visits = COALESCE(hr.clinic_visits,0) + 1 WHERE hr.student_id = " . intval($studentId));
        echo json_encode(['success' => true, 'message' => 'Visit logged.', 'data' => ['id' => $id]]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Visit ID required.']); exit; }
        $db->delete('health_visits', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Visit deleted.']);
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
            trackStatusChange(intval($sid), $status, 'Bulk status update');
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
            'birth_country' => isset($input['birth_country']) && trim($input['birth_country']) !== '' ? trim($input['birth_country']) : null,
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

        // Subsystem 1 additions — LRN, suffix, parents (guarded against pre-migration schema)
        $studentCols = $db->fetchAll("SHOW COLUMNS FROM students");
        $studentColNames = array_column($studentCols, 'Field');
        if (isset($input['lrn']) && trim($input['lrn']) !== '' && in_array('lrn', $studentColNames, true)) {
            $data['lrn'] = strtoupper(preg_replace('/[^0-9]/', '', trim($input['lrn'])));
        }
        if (isset($input['name_suffix']) && trim($input['name_suffix']) !== '' && in_array('name_suffix', $studentColNames, true)) {
            $data['name_suffix'] = trim($input['name_suffix']);
        }
        if (isset($input['mother_name']) && trim($input['mother_name']) !== '' && in_array('mother_name', $studentColNames, true)) {
            $data['mother_name'] = normalizeNameCase(trim($input['mother_name']));
        }
        if (isset($input['father_name']) && trim($input['father_name']) !== '' && in_array('father_name', $studentColNames, true)) {
            $data['father_name'] = normalizeNameCase(trim($input['father_name']));
        }

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
                          'birth_country', 'lrn', 'name_suffix', 'mother_name', 'father_name',
                          'nationality', 'religion', 'address', 'contact_number', 'email',
                          'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id', 'status'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if ($value === '' && in_array($field, ['birth_date', 'middle_name', 'place_of_birth', 'birth_country', 'nationality', 'religion', 'contact_number', 'email', 'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id', 'lrn', 'name_suffix', 'mother_name', 'father_name'], true)) {
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
                if ($field === 'lrn' && $value !== null && $value !== '') {
                    $value = strtoupper(preg_replace('/[^0-9]/', '', (string)$value));
                }
                // B3: normalize text fields before saving.
                if ($value !== null && $value !== '') {
                    if (in_array($field, ['first_name', 'middle_name', 'last_name', 'place_of_birth', 'religion', 'mother_name', 'father_name'], true)) {
                        $value = normalizeNameCase((string) $value);
                    } elseif ($field === 'contact_number') {
                        $value = normalizePhone((string) $value);
                    } elseif ($field === 'email') {
                        $value = strtolower(trim((string) $value));
                    } elseif ($field === 'course') {
                        $value = courseStandardize((string) $value);
                    } elseif ($field === 'address' || $field === 'nationality' || $field === 'major' || $field === 'school_year' || $field === 'birth_country' || $field === 'name_suffix') {
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
        // Track status change in status_tracker
        if (array_key_exists('status', $data)) {
            trackStatusChange($id, $data['status'], $input['status_reason'] ?? null);
        }
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
        trackStatusChange($id, 'archived', 'Student deactivated');
        echo json_encode(['success' => true, 'message' => 'Student deactivated.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    json_error($e);
}
?>
