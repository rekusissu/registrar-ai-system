<?php
// ============================================================
//  API/STUDENT-GRADES-AI.PHP
//  AI summary of the student's own academic history.
//
//  POST  { action: "summary" }
//
//  Flow:
//    1. requireStudent() — only a logged-in student (or admin).
//    2. Deterministic stats from academic_history + academic_grades
//       (computed GWA, trend, strong/weak subjects) — always shown.
//    3. LLM via shared/ai_client.php (aiGenerateJson) for the
//       plain-language summary. Cached in ai_cache.
//  Read-only: never writes to student records.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';

requireStudent();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$studentId = getCurrentStudentId();
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'No linked student record.']);
    exit;
}

$db = Database::getInstance();

// ── Load the student's academic history ─────────────────────
$terms = $db->fetchAll(
    'SELECT * FROM academic_history
     WHERE student_id = ?
     ORDER BY school_year IS NULL, school_year, semester IS NULL, semester, id ASC',
    [$studentId]
);

if (empty($terms)) {
    echo json_encode(['success' => true, 'data' => [
        'summary'       => 'No academic history on file yet. Records will appear here once the Registrar publishes your grades.',
        'advice'        => '',
        'computed_gwa'  => null,
        'terms'         => 0,
        'subjects'      => 0,
        'trend'         => null,
        'strengths'     => [],
        'attention'     => [],
        'honors_hint'   => null,
    ]]);
    exit;
}

$termIds = array_column($terms, 'id');
$placeholders = implode(',', array_fill(0, count($termIds), '?'));
$gradeRows = $db->fetchAll(
    'SELECT * FROM academic_grades
     WHERE academic_history_id IN (' . $placeholders . ')
     ORDER BY id ASC',
    $termIds
);

$gradesByTerm = [];
foreach ($gradeRows as $g) {
    $gradesByTerm[$g['academic_history_id']][] = $g;
}

// ── Deterministic stats ─────────────────────────────────────
$numeric = [];
foreach ($gradeRows as $g) {
    $v = is_numeric($g['grade'] ?? null) ? (float) $g['grade'] : null;
    if ($v !== null && $v >= 1.0 && $v <= 5.0) {
        $numeric[] = $v;
    }
}
$gwa = count($numeric) ? round(array_sum($numeric) / count($numeric), 2) : null;

// Trend: compare the average of the first half of terms vs the last half.
$termAverages = [];
foreach ($terms as $t) {
    $vals = [];
    foreach ($gradesByTerm[$t['id']] ?? [] as $g) {
        $v = is_numeric($g['grade'] ?? null) ? (float) $g['grade'] : null;
        if ($v !== null && $v >= 1.0 && $v <= 5.0) {
            $vals[] = $v;
        }
    }
    if (count($vals)) {
        $termAverages[] = array_sum($vals) / count($vals);
    }
}
$trend = null;
if (count($termAverages) >= 2) {
    $half     = intdiv(count($termAverages), 2);
    $firstAvg = array_sum(array_slice($termAverages, 0, $half)) / $half;
    $lastAvg  = array_sum(array_slice($termAverages, $half)) / (count($termAverages) - $half);
    $delta    = $lastAvg - $firstAvg; // lower is better on the PH 1–5 scale
    $trend    = $delta <= -0.15 ? 'improving' : ($delta >= 0.15 ? 'declining' : 'steady');
}

$strengths = [];
$attention = [];
foreach ($gradeRows as $g) {
    $subject = trim((string) ($g['subject'] ?? ''));
    $grade   = trim((string) ($g['grade'] ?? ''));
    if ($subject === '') continue;

    if (is_numeric($grade)) {
        $gv = (float) $grade;
        if ($gv >= 1.0 && $gv <= 2.0) {
            $strengths[] = $subject . ' (' . $grade . ')';
        } elseif ($gv > 3.0) {
            $attention[] = $subject . ' (' . $grade . ')';
        }
    } else {
        $gl = strtolower($grade);
        if (in_array($gl, ['f', 'inc', 'ng', 'drp', 'failed', 'incomplete'], true)) {
            $attention[] = $subject . ' (' . $grade . ')';
        }
    }
}

$honorsHint = null;
if ($gwa !== null && $gwa <= 1.75) {
    $honorsHint = 'With Honors range';
} elseif ($gwa !== null && $gwa <= 2.0) {
    $honorsHint = 'Strong academic standing';
}

// ── LLM narrative (cached in ai_cache) ──────────────────────
$ctx = 'Academic history:' . PHP_EOL;
$termIndex = 1;
foreach ($terms as $t) {
    $ctx .= $termIndex++ . '. ' . ($t['school_name'] ?? '?')
         . ' — ' . ($t['school_year'] ?? '?') . ' ' . ($t['semester'] ?? '')
         . ', grade level ' . ($t['grade_level'] ?? '?')
         . ($t['gwa'] !== null ? ', GWA ' . $t['gwa'] : '') . PHP_EOL;
    foreach ($gradesByTerm[$t['id']] ?? [] as $g) {
        $ctx .= '   - ' . $g['subject'] . ': ' . ($g['grade'] ?? '—')
             . ($g['units'] !== null && $g['units'] !== '' ? ' (' . $g['units'] . ' units)' : '')
             . (!empty($g['remarks']) ? ' [' . $g['remarks'] . ']' : '') . PHP_EOL;
    }
}
$ctx = mb_substr($ctx, 0, 6000);

$system = 'You are an academic advisor assistant inside a Philippine college registrar portal. ' .
    'Summarize the student\'s own academic history for the student. Be encouraging, honest, and concise. ' .
    'Respond with ONLY a JSON object: {"summary": <2-3 sentence plain-language summary>, "advice": <one short actionable suggestion>}. ' .
    'Use the provided computed GWA and trend when present. Do not invent subjects, grades, or honors claims beyond what is listed.';

$prompt = 'Computed GWA: ' . ($gwa !== null ? number_format($gwa, 2) : 'n/a')
        . '. Trend: ' . ($trend ?? 'n/a') . '.' . PHP_EOL . PHP_EOL . $ctx;

$result  = aiGenerateJson($system, $prompt, [], ['max_tokens' => 400, 'temperature' => 0.3]);
$summary = trim((string) ($result['summary'] ?? ''));
$advice  = trim((string) ($result['advice'] ?? ''));

if ($summary === '') {
    $summary = $gwa !== null
        ? 'Your computed GWA is ' . number_format($gwa, 2) . ' across ' . count($terms) . ' term(s) on file.'
        : 'Your academic history has ' . count($terms) . ' term(s) on file. Add numeric grades for a computed GWA.';
}

echo json_encode(['success' => true, 'data' => [
    'summary'       => $summary,
    'advice'        => $advice,
    'computed_gwa'  => $gwa,
    'terms'         => count($terms),
    'subjects'      => count($gradeRows),
    'trend'         => $trend,
    'strengths'     => array_slice($strengths, 0, 6),
    'attention'     => array_slice($attention, 0, 6),
    'honors_hint'   => $honorsHint,
]]);
exit;
