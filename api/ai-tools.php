<?php
// ============================================================
//  API/AI-TOOLS.PHP
//  Batch AI tools for the student list page.
//  Actions:
//    action=quality      → data-quality summary + flags for all students
//    action=standardize  → draft standardization changes (AI-assisted)
//    action=apply_std    → apply a specific standardization change
//    action=scan_dupes   → fuzzy duplicate scan across the table
//    action=profile      → AI digest of one student (academic+scans+docs)
//  LLM calls are cached in ai_cache. Writes only happen via
//  apply_std (a registrar confirms each change).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/normalize.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$db = Database::getInstance();

switch ($action) {

    // ─── DATA-QUALITY SUMMARY ────────────────────────────────
    case 'quality':
        $students = $db->fetchAll("SELECT * FROM students");
        $issues = [];
        $issueCounts = [];
        $courseVariants = [];

        foreach ($students as $s) {
            $flags = studentDataQualityFlags($s);
            foreach ($flags as $f) {
                $issueCounts[$f] = ($issueCounts[$f] ?? 0) + 1;
            }
            if (!empty($s['course'])) {
                $c = trim((string) $s['course']);
                $courseVariants[$c] = ($courseVariants[$c] ?? 0) + 1;
            }
        }

        // Find course names that differ from the official list.
        $official = getOfferedCourses();
        $officialSet = [];
        foreach (array_keys($official) as $n) $officialSet[$n] = true;
        $nonStandard = [];
        foreach ($courseVariants as $name => $count) {
            if (!isset($officialSet[$name])) {
                $std = courseStandardize($name);
                $nonStandard[] = [
                    'raw' => $name,
                    'count' => $count,
                    'standardized' => ($std !== $name) ? $std : null,
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'total_students' => count($students),
                'issue_counts' => $issueCounts,
                'non_standard_courses' => $nonStandard,
            ],
        ]);
        exit;

    // ─── DRAFT STANDARDIZATION ───────────────────────────────
    case 'standardize':
        // Deterministic: apply courseStandardize to every non-standard course.
        $students = $db->fetchAll("SELECT id, course FROM students WHERE course IS NOT NULL AND TRIM(course) != ''");
        $changes = [];
        foreach ($students as $s) {
            $raw = trim((string) $s['course']);
            $std = courseStandardize($raw);
            if ($std !== $raw) {
                $changes[] = [
                    'id' => (int) $s['id'],
                    'from' => $raw,
                    'to' => $std,
                ];
            }
        }
        echo json_encode(['success' => true, 'data' => ['changes' => $changes]]);
        exit;

    // ─── APPLY A STANDARDIZATION CHANGE ──────────────────────
    case 'apply_std':
        $id = (int) ($input['id'] ?? 0);
        $to = trim((string) ($input['to'] ?? ''));
        if (!$id || $to === '') {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        $db->update('students', ['course' => $to], 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Course updated.']);
        exit;

    // ─── DUPLICATE SCAN ──────────────────────────────────────
    case 'scan_dupes':
        $students = $db->fetchAll("SELECT id, first_name, middle_name, last_name, student_number, birth_date FROM students");
        $pairs = [];
        $n = count($students);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $students[$i];
                $b = $students[$j];
                $lnA = strtolower(trim((string) ($a['last_name'] ?? '')));
                $lnB = strtolower(trim((string) ($b['last_name'] ?? '')));
                $fnA = strtolower(trim((string) ($a['first_name'] ?? '')));
                $fnB = strtolower(trim((string) ($b['first_name'] ?? '')));
                if ($lnA === '' || $lnB === '' || $fnA === '' || $fnB === '') continue;

                $score = 0.0;
                $lnSim = similar_text($lnA, $lnB) / max(strlen($lnA), strlen($lnB), 1);
                if ($lnSim >= 0.85) {
                    $fnSim = similar_text($fnA, $fnB) / max(strlen($fnA), strlen($fnB), 1);
                    $score = ($lnSim * 0.6) + ($fnSim * 0.4);
                }
                if (!empty($a['birth_date']) && !empty($b['birth_date']) && $a['birth_date'] === $b['birth_date']) {
                    $score = min(1.0, $score + 0.2);
                } elseif (!empty($a['birth_date']) && !empty($b['birth_date'])) {
                    $score -= 0.25;
                }
                if ($score >= 0.78) {
                    $pairs[] = [
                        'a' => ['id' => (int)$a['id'], 'name' => trim(($a['first_name']??'') . ' ' . ($a['last_name']??'')), 'sn' => (string)($a['student_number']??''), 'bd' => (string)($a['birth_date']??'')],
                        'b' => ['id' => (int)$b['id'], 'name' => trim(($b['first_name']??'') . ' ' . ($b['last_name']??'')), 'sn' => (string)($b['student_number']??''), 'bd' => (string)($b['birth_date']??'')],
                        'score' => round($score, 2),
                    ];
                }
            }
        }
        // Sort by score desc, cap output.
        usort($pairs, fn($x, $y) => $y['score'] <=> $x['score']);
        echo json_encode(['success' => true, 'data' => ['pairs' => array_slice($pairs, 0, 30)]]);
        exit;

    // ─── MERGE DUPLICATE RECORDS ─────────────────────────────
    case 'merge':
        $keeperId  = (int) ($input['keeper_id'] ?? 0);
        $removeId  = (int) ($input['remove_id'] ?? 0);
        if (!$keeperId || !$removeId || $keeperId === $removeId) {
            echo json_encode(['success' => false, 'message' => 'Invalid merge request.']);
            exit;
        }

        $keeper = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$keeperId]);
        $remove = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$removeId]);
        if (!$keeper || !$remove) {
            echo json_encode(['success' => false, 'message' => 'One or both records not found.']);
            exit;
        }

        // Reassign related records to the keeper.
        $tables = ['academic_history', 'guardians', 'health_records', 'status_tracker', 'student_ids', 'rfid_cards', 'documents'];
        foreach ($tables as $t) {
            $db->update($t, ['student_id' => $keeperId], 'student_id = ?', [$removeId]);
        }
        $db->update('document_requests', ['student_id' => $keeperId], 'student_id = ?', [$removeId]);

        // Keep non-empty fields from the keeper, falling back to the removed record.
        $mergeFields = ['first_name','middle_name','last_name','gender','civil_status','birth_date','place_of_birth','nationality','religion','address','contact_number','email','course','major','year_level','school_year','semester','section','status'];
        $updates = [];
        foreach ($mergeFields as $f) {
            $k = trim((string) ($keeper[$f] ?? ''));
            $r = trim((string) ($remove[$f] ?? ''));
            if ($k === '' && $r !== '') {
                $updates[$f] = $remove[$f];
            }
        }
        if ($updates) {
            $db->update('students', $updates, 'id = ?', [$keeperId]);
        }

        // Delete the removed record.
        $db->delete('students', 'id = ?', [$removeId]);

        echo json_encode(['success' => true, 'message' => 'Records merged.']);
        exit;

    // ─── AI PROFILE SUMMARY ──────────────────────────────────
    case 'profile':
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Student ID required.']);
            exit;
        }
        $s = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$id]);
        if (!$s) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $acad = $db->fetchAll("SELECT school_name, school_year, grade_level, gwa, remarks FROM academic_history WHERE student_id = ? ORDER BY created_at DESC", [$id]);
        $scans = $db->fetchColumn("SELECT COUNT(*) FROM rfid_scan_logs l JOIN rfid_cards c ON l.card_uid = c.card_uid WHERE c.student_id = ?", [$id]);
        $scans = $scans ? (int) $scans : 0;
        $docs = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE student_id = ?", [$id]);
        $docs = $docs ? (int) $docs : 0;
        $status = $s['status'] ?? '';
        $course = trim((string) ($s['course'] ?? ''));
        $year = $s['year_level'] ?? '';

        $system = "You are a registrar's assistant. Write a concise 2-3 sentence profile summary for a student, highlighting anything noteworthy for a registrar (status flags, academic history, documents pending, card scans). Do not invent data.";
        $facts = "Name: {$s['first_name']} {$s['last_name']}\n"
            . "Student No: {$s['student_number']}\n"
            . "Course: {$course} Year: {$year}\n"
            . "Status: {$status}\n"
            . "Academic history entries: " . count($acad) . "\n"
            . "RFID scans: {$scans}\n"
            . "Document requests: {$docs}";

        $summary = aiGenerate($system, $facts, ['max_tokens' => 220]);
        if ($summary === '') {
            $summary = "Active student. Course: " . ($course ?: 'N/A') . ", Year: " . ($year ?: 'N/A') . ". Status: " . ($status ?: 'N/A') . ".";
        }

        echo json_encode(['success' => true, 'data' => ['summary' => $summary]]);
        exit;

    // ─── TEACHER PROFILE SUMMARY ─────────────────────────────
    case 'teacher_profile':
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Teacher ID required.']);
            exit;
        }
        $t = $db->fetchOne("SELECT id, full_name, email, role, rfid_uid, is_active FROM users WHERE id = ?", [$id]);
        if (!$t) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found.']);
            exit;
        }

        $load = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE adviser_id = ?", [$id]);
        $sections = $db->fetchColumn("SELECT COUNT(DISTINCT CONCAT(COALESCE(course,''),'|',COALESCE(year_level,''),'|',COALESCE(section,''))) FROM students WHERE adviser_id = ?", [$id]);
        $hasRfid = !empty($t['rfid_uid']);
        $status = $t['is_active'] ? 'active' : 'inactive';

        $system = "You are a registrar's assistant. Write a concise 2-3 sentence profile summary of a teacher, highlighting anything noteworthy (advising load, RFID status, account status). Do not invent data.";
        $facts = "Teacher: {$t['full_name']}\n"
            . "Email: {$t['email']}\n"
            . "Role: {$t['role']}\n"
            . "Status: {$status}\n"
            . "Advises {$load} student(s) across {$sections} section(s)\n"
            . "RFID assigned: " . ($hasRfid ? 'yes' : 'no');

        $summary = aiGenerate($system, $facts, ['max_tokens' => 220]);
        if ($summary === '') {
            $summary = ucfirst($t['full_name']) . " advises {$load} student(s) across {$sections} section(s). Account status: {$status}. RFID " . ($hasRfid ? 'assigned' : 'not assigned') . ".";
        }

        echo json_encode(['success' => true, 'data' => ['summary' => $summary]]);
        exit;

    // ─── NATURAL-LANGUAGE SEARCH ─────────────────────────────
    case 'nl_search':
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            echo json_encode(['success' => false, 'message' => 'Query required.']);
            exit;
        }

        $system = "You are a registrar's search assistant. Translate a natural-language query into a JSON filter object for a student database. "
                . "Return ONLY a JSON object with any of these keys: "
                . "status (one of active/probation/at-risk/loa/graduated/transferred/dropped/archived), "
                . "year_level (integer), course (a keyword substring), section, semester (1st/2nd/summer), "
                . "gender (Male/Female), has_rfid (bool), or keywords (array of free-text search terms to match against name/student_number/course). "
                . "If the query implies no filter for a key, omit it. Be literal and conservative.";

        $filter = aiGenerateJson($system, "Query: \"" . mb_substr($query, 0, 300) . "\"", [], ['max_tokens' => 200]);
        if (!is_array($filter) || empty($filter)) {
            // Fall back to a free-text keyword search.
            $filter = ['keywords' => [$query]];
        }

        // Build the SQL from the structured filter.
        $sql = "SELECT * FROM students WHERE 1=1";
        $params = [];
        if (!empty($filter['status'])) {
            $sql .= " AND status = ?"; $params[] = (string) $filter['status'];
        }
        if (isset($filter['year_level']) && $filter['year_level'] !== '' && $filter['year_level'] !== null) {
            $sql .= " AND year_level = ?"; $params[] = (int) $filter['year_level'];
        }
        if (!empty($filter['course'])) {
            $sql .= " AND course LIKE ?"; $params[] = '%' . (string) $filter['course'] . '%';
        }
        if (!empty($filter['section'])) {
            $sql .= " AND section = ?"; $params[] = (string) $filter['section'];
        }
        if (!empty($filter['semester'])) {
            $sql .= " AND semester = ?"; $params[] = (string) $filter['semester'];
        }
        if (!empty($filter['gender'])) {
            $sql .= " AND gender = ?"; $params[] = (string) $filter['gender'];
        }
        if (isset($filter['has_rfid']) && $filter['has_rfid']) {
            $sql .= " AND id IN (SELECT DISTINCT student_id FROM rfid_cards)";
        }
        if (!empty($filter['keywords']) && is_array($filter['keywords'])) {
            $sql .= " AND (";
            $kwParts = [];
            foreach ($filter['keywords'] as $kw) {
                if ($kw === '' || $kw === null) continue;
                $kwParts[] = "(first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ? OR course LIKE ?)";
                $like = '%' . (string) $kw . '%';
                $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
            }
            if ($kwParts) {
                $sql .= implode(' OR ', $kwParts) . ")";
            }
        }
        $sql .= " ORDER BY id DESC LIMIT 50";

        $rows = $db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'student_number' => (string) ($r['student_number'] ?? ''),
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'course' => (string) ($r['course'] ?? ''),
                'year_level' => $r['year_level'] ?? null,
                'section' => (string) ($r['section'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => ['filter' => $filter, 'students' => $out],
        ]);
        exit;

    // ─── BATCH AI REPORT (whole list summary) ─────────────────
    case 'report':
        $students = $db->fetchAll("SELECT * FROM students");
        $total = count($students);
        $active = 0; $atRisk = 0; $noGender = 0; $noCourse = 0;
        $byCourse = []; $byStatus = [];
        foreach ($students as $s) {
            if (($s['status'] ?? '') === 'active') $active++;
            if (($s['status'] ?? '') === 'at-risk') $atRisk++;
            if (empty(trim((string)($s['gender'] ?? '')))) $noGender++;
            if (empty(trim((string)($s['course'] ?? '')))) $noCourse++;
            $c = trim((string)($s['course'] ?? 'N/A'));
            $byCourse[$c] = ($byCourse[$c] ?? 0) + 1;
            $st = $s['status'] ?? 'N/A';
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        arsort($byCourse); arsort($byStatus);

        $system = "You are a registrar's reporting assistant. Write a concise 3-4 sentence summary of the student population, highlighting notable trends or concerns a registrar should know. Do not invent data.";
        $facts = "Total students: {$total}\n"
            . "Active: {$active}, At-risk: {$atRisk}, Missing gender: {$noGender}, Missing course: {$noCourse}\n"
            . "By course: " . json_encode($byCourse) . "\n"
            . "By status: " . json_encode($byStatus);

        $report = aiGenerate($system, $facts, ['max_tokens' => 300]);
        if ($report === '') {
            $report = "Total students: {$total}. Active: {$active}. No data issues found to report.";
        }

        echo json_encode(['success' => true, 'data' => ['report' => $report]]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
