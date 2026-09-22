<?php
// ============================================================
//  API/DATA-QUALITY.PHP
//  Digital File Storage - data quality checks.
//  Rule-based duplicate detection + missing-document check,
//  with a notify action for the student portal bell.
//  Never deletes or merges records - it only reports.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$action = $_GET['action'] ?? '';

$requiredTypes = ['enrollment', 'transcript', 'health', 'photo', 'clearance'];
$typeLabels = [
    'enrollment' => 'Enrollment Form',
    'transcript' => 'Transcript / Grades',
    'health'     => 'Health Record',
    'photo'      => 'ID Photo',
    'clearance'  => 'Clearance',
    'other'      => 'Other',
];

// Normalize a filename for duplicate comparison: lowercase, alphanumeric only.
$normFile = function (string $name): string {
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', trim($name));
    return strtolower(preg_replace('/[^a-z0-9]+/', '', $base ?? ''));
};

// ---- SUMMARY / CHECKS ----
if ($method === 'GET' && $action === 'summary') {
    $students = $db->fetchAll(
        "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS student_name
         FROM students WHERE status != 'archived' ORDER BY last_name, first_name"
    );
    $docs = $db->fetchAll("SELECT id, student_id, doc_type, filename FROM documents ORDER BY student_id, doc_type");

    // Group docs per student / type.
    $docsByStudentType = [];
    foreach ($docs as $d) {
        $docsByStudentType[(int) $d['student_id']][$d['doc_type']][] = $d;
    }

    // Missing documents per student (rule-based against required types).
    $missingStudents = [];
    $completeStudents = 0;
    foreach ($students as $s) {
        $sid = (int) $s['id'];
        $uploadedTypes = array_keys($docsByStudentType[$sid] ?? []);
        $missing = array_values(array_diff($requiredTypes, $uploadedTypes));
        if (empty($missing)) { $completeStudents++; continue; }
        $missingStudents[] = [
            'student_id'     => $sid,
            'student_number' => (string) $s['student_number'],
            'student_name'   => (string) $s['student_name'],
            'missing'        => array_map(fn($t) => ['type' => $t, 'label' => $typeLabels[$t] ?? $t], $missing),
            'missing_count'  => count($missing),
        ];
    }

    // Duplicate detection: same student + same type + identical normalized filename.
    $duplicates = [];
    $dupBuckets = [];
    foreach ($docs as $d) {
        $key = (int) $d['student_id'] . '|' . $d['doc_type'] . '|' . $normFile($d['filename']);
        $dupBuckets[$key][] = $d;
    }
    foreach ($dupBuckets as $key => $bucket) {
        if (count($bucket) < 2) continue;
        list($sid, $dtype) = explode('|', $key, 3);
        $student = null;
        foreach ($students as $s) { if ((int) $s['id'] === (int) $sid) { $student = $s; break; } }
        $duplicates[] = [
            'student_id'     => (int) $sid,
            'student_number' => $student ? (string) $student['student_number'] : '',
            'student_name'   => $student ? (string) $student['student_name'] : '?',
            'doc_type'       => $dtype,
            'doc_label'      => $typeLabels[$dtype] ?? $dtype,
            'filename'       => (string) $bucket[0]['filename'],
            'count'          => count($bucket),
            'file_ids'       => array_map(fn($f) => (int) $f['id'], $bucket),
        ];
    }

    // Documents staged under enrollment numbers (student not yet enrolled).
    $db->update('documents', [
        'enroll_status' => 'abandoned',
    ], "student_id IS NULL AND enroll_status = 'pending' AND created_at < (NOW() - INTERVAL 30 DAY)");

    $staged = $db->fetchAll(
        "SELECT id, enroll_no, filename, doc_type, enroll_status, created_at
         FROM documents WHERE student_id IS NULL ORDER BY created_at DESC"
    );
    $stagedPending = 0;
    $stagedAbandoned = 0;
    foreach ($staged as $sd) {
        if ($sd['enroll_status'] === 'abandoned') $stagedAbandoned++;
        else $stagedPending++;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total_students'    => count($students),
            'complete'          => $completeStudents,
            'missing'           => count($missingStudents),
            'duplicate_groups'  => count($duplicates),
            'missing_students'  => $missingStudents,
            'duplicates'        => $duplicates,
            'staged'            => $staged,
            'staged_pending'    => $stagedPending,
            'staged_abandoned'  => $stagedAbandoned,
        ],
    ]);
    exit;
}

// ---- NOTIFY STUDENT (bell) ----
if ($method === 'POST' && $action === 'notify') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $studentId = intval($input['student_id'] ?? 0);
    if (!$studentId) {
        echo json_encode(['success' => false, 'message' => 'Student is required.']);
        exit;
    }
    $student = $db->fetchOne("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE id = ?", [$studentId]);
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }
    $message = trim((string) ($input['message'] ?? ''));
    if ($message === '') {
        $message = 'You have missing required documents in the Digital File Storage. Please upload them as soon as possible.';
    }
    logActivity(
        $_SESSION['user_id'],
        'documents_missing_reminder',
        json_encode(['student_id' => $studentId, 'student' => $student['name'], 'message' => $message]),
        'documents',
        $studentId
    );
    echo json_encode(['success' => true, 'message' => 'Reminder sent to the student portal.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);