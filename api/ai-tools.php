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
