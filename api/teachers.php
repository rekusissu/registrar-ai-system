<?php
// ============================================================
//  API/TEACHERS.PHP
//  Teacher profile + master-subject assignment endpoints.
//  Actions (GET):
//    action=subjects            → master subject catalog
//    action=profile&id=         → teacher profile + subjects + advisees
//    action=advisees&id=        → advised student list for a teacher
//    action=loadsum             → teaching + advising load for every teacher
//  Actions (POST, JSON body):
//    action=save_profile        → upsert teacher_profiles row
//    action=assign_subjects     → replace a teacher's subject assignments
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

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

try {

// ─── GET MASTER SUBJECT CATALOG ────────────────────────────
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'subjects') {
    $rows = $db->fetchAll("SELECT id, code, title, units, department, year_level, semester, is_active FROM subjects WHERE is_active = 1 ORDER BY code");
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ─── GET TEACHER PROFILE + SUBJECTS + ADVISEES ─────────────
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'profile') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $teacher = $db->fetchOne("SELECT id, email, full_name, role, rfid_uid, is_active FROM users WHERE id = ?", [$id]);
    if (!$teacher) {
        echo json_encode(['success' => false, 'message' => 'Teacher not found.']);
        exit;
    }
    $profile = $db->fetchOne("SELECT * FROM teacher_profiles WHERE user_id = ?", [$id]) ?: [];
    $subjects = teacherSubjects($id);
    $advisees = $db->fetchAll(
        "SELECT id, student_number, first_name, middle_name, last_name, course, year_level, section, status
         FROM students WHERE adviser_id = ? ORDER BY last_name, first_name", [$id]);
    $load = teacherTeachingLoad($id);
    $adviseeCount = count($advisees);
    $allUsers = $db->fetchAll("SELECT id, email, rfid_uid FROM users");
    $flags = teacherProfileDataQualityFlags($teacher, $allUsers, $adviseeCount, $profile, $load['assignments']);

    echo json_encode(['success' => true, 'data' => [
        'teacher'   => $teacher,
        'profile'   => $profile,
        'subjects'  => $subjects,
        'advisees'  => $advisees,
        'teaching'  => $load,
        'flags'     => $flags,
    ]]);
    exit;
}

// ─── GET ADVISEES FOR A TEACHER ────────────────────────────
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'advisees') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $advisees = $db->fetchAll(
        "SELECT id, student_number, first_name, middle_name, last_name, course, year_level, section, status
         FROM students WHERE adviser_id = ? ORDER BY last_name, first_name", [$id]);
    echo json_encode(['success' => true, 'data' => $advisees]);
    exit;
}

// ─── GET LOAD SUMMARY FOR ALL TEACHERS ─────────────────────
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'loadsum') {
    $teachers = $db->fetchAll("SELECT id, full_name, email, role, is_active FROM users WHERE role IN ('teacher','staff') ORDER BY full_name");
    $advRows = $db->fetchAll("SELECT adviser_id, COUNT(*) AS cnt FROM students WHERE adviser_id IS NOT NULL GROUP BY adviser_id");
    $advByTeacher = [];
    foreach ($advRows as $r) $advByTeacher[(int)$r['adviser_id']] = (int)$r['cnt'];

    $out = [];
    foreach ($teachers as $t) {
        $teaching = teacherTeachingLoad((int)$t['id']);
        $out[] = [
            'id'        => (int)$t['id'],
            'full_name' => $t['full_name'],
            'email'     => $t['email'],
            'role'      => $t['role'],
            'is_active' => (int)$t['is_active'],
            'advisees'  => $advByTeacher[(int)$t['id']] ?? 0,
            'teaching'  => $teaching,
            'load_total'=> $teaching['assignments'] + ($advByTeacher[(int)$t['id']] ?? 0),
        ];
    }
    echo json_encode(['success' => true, 'data' => $out]);
    exit;
}

// ─── SAVE TEACHER PROFILE (upsert) ─────────────────────────
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_profile') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $uid = isset($input['user_id']) ? intval($input['user_id']) : 0;
    if (!$uid) {
        echo json_encode(['success' => false, 'message' => 'User ID required.']);
        exit;
    }
    $exists = $db->fetchOne("SELECT user_id FROM teacher_profiles WHERE user_id = ?", [$uid]);

    $fields = ['employee_number','designation','department','highest_degree','specialization','years_teaching','date_hired','contact_number','emergency_contact','birthdate','address'];
    $data = [];
    foreach ($fields as $f) {
        $v = $input[$f] ?? null;
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') $v = null;
        }
        $data[$f] = $v;
    }
    $data['user_id'] = $uid;

    if ($exists) {
        $db->update('teacher_profiles', $data, 'user_id = ?', [$uid]);
    } else {
        $db->insert('teacher_profiles', $data);
    }
    echo json_encode(['success' => true, 'message' => 'Profile saved.', 'data' => ['profile' => $data]]);
    exit;
}

// ─── ASSIGN SUBJECTS (replace a teacher's assignments) ─────
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'assign_subjects') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $uid = isset($input['teacher_id']) ? intval($input['teacher_id']) : 0;
    if (!$uid) {
        echo json_encode(['success' => false, 'message' => 'teacher_id required.']);
        exit;
    }

    // Validate the teacher is a real user.
    $teacher = $db->fetchOne("SELECT id FROM users WHERE id = ? AND role IN ('teacher','staff')", [$uid]);
    if (!$teacher) {
        echo json_encode(['success' => false, 'message' => 'Teacher not found.']);
        exit;
    }

    // Optional per-assignment context applied to all rows submitted now.
    $section    = trim((string) ($input['section']    ?? ''));
    $schoolYear = trim((string) ($input['school_year'] ?? ''));
    $semester   = trim((string) ($input['semester']   ?? ''));
    $schedule   = trim((string) ($input['schedule']   ?? ''));

    $subjectIds = $input['subject_ids'] ?? [];
    if (!is_array($subjectIds)) $subjectIds = [];
    $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
    $subjectIds = array_filter($subjectIds, fn($s) => $s > 0);

    // Begin: replace the teacher's current assignments.
    $db->delete('teacher_subjects', 'teacher_id = ?', [$uid]);

    $count = 0;
    foreach ($subjectIds as $sid) {
        $db->insert('teacher_subjects', [
            'teacher_id' => $uid,
            'subject_id' => $sid,
            'section'    => $section !== '' ? $section : null,
            'school_year'=> $schoolYear !== '' ? $schoolYear : null,
            'semester'   => $semester !== '' ? $semester : null,
            'schedule'   => $schedule !== '' ? $schedule : null,
        ]);
        $count++;
    }

    echo json_encode(['success' => true, 'message' => "Assigned {$count} subject(s).", 'data' => ['count' => $count]]);
    exit;
}

// ─── DEACTIVATE TEACHER (soft delete) ──────────────────────
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'deactivate') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $id = isset($input['id']) ? intval($input['id']) : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Teacher ID required.']);
        exit;
    }
    $db->update('users', ['is_active' => 0], 'id = ?', [$id]);
    echo json_encode(['success' => true, 'message' => 'Teacher deactivated.']);
    exit;
}

// ─── UNKNOWN ───────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}