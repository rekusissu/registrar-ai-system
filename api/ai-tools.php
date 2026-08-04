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

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
