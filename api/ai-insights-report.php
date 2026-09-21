<?php
// ============================================================
//  API/AI-INSIGHTS-REPORT.PHP
//  AI-assisted analysis of the registrar data (OpenRouter models).
//
//    POST { filters: { month, year }, force?: bool }
//    → { success, data: { report, source, model, ai_error,
//                         generated_at, period, facts, cards } }
//
//  The narrative is written from aiInsightFactSheetText(), which is
//  built from the very same numbers the charts are drawn from — the
//  model interprets, it never calculates.
//
//  When the gateway is unreachable (or returns text that does not match
//  the required three-section shape) the deterministic
//  aiInsightFallbackReport() is returned with source=fallback, so the
//  report card is never empty.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/analytics.php';
require_once __DIR__ . '/../shared/ai_client.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), aiInsightRoles(), true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$filters = $input['filters'] ?? [];
$force   = !empty($input['force']);

$period = aiInsightPeriod(
    isset($filters['month']) ? (int) $filters['month'] : (int) date('n'),
    isset($filters['year'])  ? (int) $filters['year']  : (int) date('Y')
);


try {
    $build = aiInsightBuild($period);
    $facts = $build['facts'];

    // ── Prompt contract: exactly three sections, supplied facts only ──
    $systemPrompt = "You are the data analyst for the Office of the Registrar at Bestlink College "
        . "of the Philippines. Write a short, factual analysis of the registrar data you are given.\n\n"
        . "Your reply MUST be plain Markdown with EXACTLY these three numbered headings, in this order, "
        . "and nothing before the first heading:\n\n"
        . "## 1. AI Registrar Summary\n"
        . "## 2. Detected Trends\n"
        . "## 3. Patterns Observed\n\n"
        . "Section 1: two to three sentences describing the reporting period as a whole.\n"
        . "Section 2: a bullet list with one bullet per category, each starting with the category name in bold "
        . "exactly as \"- **Students:**\", \"- **Document Transactions:**\", \"- **RFID:**\", \"- **Queue:**\". "
        . "Every bullet must cite at least one figure from the data.\n"
        . "Section 3: two to four bullets on notable strengths, risks or bottlenecks, such as document turnaround, "
        . "pending backlog, card coverage, or queue peaks.\n\n"
        . "RULES:\n"
        . "- Use ONLY the figures supplied below. Never estimate, extrapolate or invent numbers.\n"
        . "- Never name or identify an individual student; aggregates only.\n"
        . "- One sentence per bullet, no paragraphs, no preamble, no closing remarks.\n"
        . "- Professional, neutral, administrative tone. Under 320 words.";

    $userPrompt = aiInsightFactSheetText($facts)
        . "\nWrite the three-section analysis for this reporting period.";

    $aiText = aiGenerate($systemPrompt, $userPrompt, [
        'max_tokens'   => 1200,
        'temperature'  => 0.3,
        'forceRefresh' => $force,
    ]);

    $source  = 'ai';
    $aiError = '';

    // Guard the promised format: if the model ignored the headings, ship the
    // deterministic report instead of an unformatted wall of text.
    $hasShape = $aiText !== ''
        && strpos($aiText, '## 1.') !== false
        && strpos($aiText, '## 2.') !== false
        && strpos($aiText, '## 3.') !== false;

    if (!$hasShape) {
        $aiError = aiLastError() !== ''
            ? aiLastError()
            : 'The AI model did not return the required three-section format.';
        $aiText  = aiInsightFallbackReport($facts);
        $source  = 'fallback';
    }


    // ── Audit trail (same convention as api/generate-document-pdf.php) ──
    try {
        logActivity(
            (int) $_SESSION['user_id'],
            'ai_insights_generate',
            'AI Insight report for ' . $period['label'],
            null,
            null,
            null,
            ['period' => $period['label'], 'source' => $source]
        );
    } catch (Throwable $e) {
        // Auditing must never break the report.
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'report'       => $aiText,
            'source'       => $source,          // 'ai' | 'fallback'
            'ai_error'     => $aiError,         // shown as a hint when fallback
            'model'        => $source === 'ai' ? AI_MODEL : null,
            'generated_at' => date('Y-m-d H:i:s'),
            'period'       => [
                'month'      => $period['month'],
                'year'       => $period['year'],
                'label'      => $period['label'],
                'start'      => substr($period['start'], 0, 10),
                'end'        => date('Y-m-d', strtotime($period['end'] . ' -1 day')),
                'prev_label' => $period['prev_label'],
            ],
            // Handed back so Print/Export can label the report without a
            // second round-trip or a re-run of the aggregates.
            'facts'        => $facts,
            'cards'        => $build['cards'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[ai-insights-report] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to generate the analysis right now.']);
}
