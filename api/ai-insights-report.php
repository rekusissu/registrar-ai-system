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
//  When the gateway is unreachable, or returns something unusable, `report`
//  is EMPTY and source=fallback. It is never filled with a template.
//
//  The deterministic figures are returned SEPARATELY as `figures`, on every
//  response whatever happened, because they are always true. They are shown in
//  their own block, styled as counts, and never under a heading that says AI.
//  See aiInsightFiguresMarkdown() for why that separation exists.
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
require_once __DIR__ . '/../shared/ai_report_cache.php';

header('Content-Type: application/json');

// ── This request must NOT take a minute ───────────────────────────────────
//
// Generating the report is a synchronous upstream call measured at 40-65
// seconds against the current primary model (the reasoning pass is most of
// the wall clock). A stock shared host runs PHP with max_execution_time at
// 30s and sits behind a proxy that gives up at 60s, so the long version of
// this endpoint ended in a 503 "Gateway timeout" HTML page from the HOST -
// not from this application, which only ever emits 401/403/405/500.
//
// set_time_limit() cannot fix that. It raises PHP's own limit, but the proxy
// in front of PHP is a different limit, on a different clock, and outside
// this file's control. Raising a number the host ignores is not a fix.
//
// So the long work is moved OFF the request. The POST returns in well under
// a second with status 'pending', the generation continues after the
// response has been flushed, and the browser polls a cheap GET until the
// finished report is in the cache. Every individual request is now short,
// so no proxy in the path can time one out - which is the only thing that
// actually survives a shared host.
//
// fastcgi_finish_request() is what makes "after the response" possible: it
// hands the response to the web server and lets the script keep running. It
// exists on PHP-FPM and CGI, which is what shared hosting runs. Where it is
// missing (mod_php) we fall back to generating synchronously, because a
// correct-but-slow answer beats a fast one that never arrives.
$canDetach = function_exists('fastcgi_finish_request');
@set_time_limit($canDetach ? 300 : 180);

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
// GET polls for a finished report (handled below); POST generates one.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET or POST required.']);
    exit;
}

$isPoll = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

if ($isPoll) {
    $filters = [
        'month' => isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n'),
        'year'  => isset($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y'),
    ];
    $force   = false;
} else {
    $input   = json_decode(file_get_contents('php://input'), true) ?: [];
    $filters = $input['filters'] ?? [];
    $force   = !empty($input['force']);
}

$period = aiInsightPeriod(
    isset($filters['month']) ? (int) $filters['month'] : (int) date('n'),
    isset($filters['year'])  ? (int) $filters['year']  : (int) date('Y')
);


// ── POLL: has the background job finished? ──────────────────────
//
// Every call here is a cache read - no upstream call, no re-aggregation of
// the whole registrar dataset - so it costs about what serving a static file
// costs. That is what makes polling safe against the very proxy that was
// timing the long request out: the thing we retry is cheap.
//
// A miss answers 'pending', never an error. The browser cannot tell the
// difference between "still working" and "failed", and guessing wrong the
// other way would show an error for a report that is thirty seconds from
// being ready.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $pollBuild = aiInsightBuild($period);
        $stored    = aiReportCacheGet(aiReportCacheKey($period, $pollBuild['facts']));

        echo json_encode(['success' => true, 'data' => $stored === null
            ? ['status' => 'pending']
            : [
                'status'       => 'ready',
                'report'       => (string) $stored['report'],
                'model'        => (string) ($stored['model'] ?? ''),
                'cached'       => true,
                'generated_at' => (string) ($stored['generated_at'] ?? ''),
            ]]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Unable to check the report.']);
    }
    exit;
}

try {
    $build = aiInsightBuild($period);
    $facts = $build['facts'];

    // ── Reuse a report already written from these exact figures ──────
    //
    // The call below costs 40-65 seconds, and the office was paying it
    // every time even when nothing had changed - reopening a report
    // already read, showing the same month to a colleague, clicking
    // again because the first one felt slow.
    //
    // The key fingerprints $facts, not the period. A period-keyed cache
    // could serve last month's reading of this month's data, and a
    // registrar acting on a stale analysis is worse off than one with no
    // analysis. Because the key is derived from the figures themselves,
    // any change to any of them produces a different key - so a hit can
    // only be a report built from exactly the numbers on screen now.
    //
    // $force is the deliberate "regenerate anyway", for when the wording
    // needs re-rolling.
    $cacheKey = $force ? '' : aiReportCacheKey($period, $facts);
    $cached   = $cacheKey === '' ? null : aiReportCacheGet($cacheKey);
    $modelUsed   = AI_MODEL;
    $generatedAt = '';

    // ── Prompt contract: one section per module, supplied facts only ──
    //
    // The previous contract asked for three sections and capped the reply at 320
    // words, which produced a paragraph per module. A registrar opening
    // Insights wants the whole picture: what each module did, how it moved, and
    // what it means for the office. So the model now writes a section per
    // module, in full, and is allowed the room to say what it sees.
    //
    // The headings are FIXED and named, because the client checks for them and
    // because a reader who knows where to look is a reader who reads.
    $systemPrompt = "You are the data analyst for the Office of the Registrar at Bestlink College "
        . "of the Philippines. Write a full operational analysis of the registrar data you are given.\n\n"
        . "Your reply MUST be plain Markdown with EXACTLY these seven numbered headings, in this order, "
        . "and nothing before the first heading:\n\n"
        . "## 1. Executive Summary\n"
        . "## 2. Student Population and Registration\n"
        . "## 3. Programme Mix\n"
        . "## 4. Document Services\n"
        . "## 5. RFID and Campus Cards\n"
        . "## 6. Queue Operations\n"
        . "## 7. Cross-Module Findings and Recommended Actions\n\n"
        . "SECTION 1 - Executive Summary\n"
        . "Four to six sentences. State the period, the scale of activity across all modules, the single "
        . "most important thing a reader should know, and what it implies. This is the only section that "
        . "may be read alone, so it must stand on its own without the later sections.\n\n"
        . "SECTIONS 2 TO 6 - one per module, in full\n"
        . "For each module write two to four short paragraphs or bullet groups covering: what the "
        . "figures are; how they compare with the previous period; the internal distribution (statuses, "
        . "programmes, document types, workflow stages, card states) and what the biggest slice is; and "
        . "what this means operationally for the registrar's office. Cite a figure for every claim. "
        . "Where a figure is zero, say so plainly and say what it implies - do not skip the module.\n\n"
        . "SECTION 7 - Cross-module findings\n"
        . "Three to six bullets, each connecting at least TWO modules (for example document backlog "
        . "against queue peaks, or card coverage against enrolment), followed by two to four concrete "
        . "recommended actions. Each action must name the module it belongs to and say what it would "
        . "change. End with one bullet naming the single largest uncertainty in this data and what "
        . "would resolve it.\n\n"
        . "RULES\n"
        . "- Use ONLY the figures supplied below. Never estimate, extrapolate or invent numbers.\n"
        . "- Never name or identify an individual student; aggregates only.\n"
        . "- Keep every bullet to one or two sentences. No paragraphs inside a bullet.\n"
        . "- Professional, neutral, administrative tone. No preamble, no closing pleasantries.\n"
        . "- Do not use emoji. Do not use tables.\n"
        . "- Aim for 700 to 1000 words. Be specific; do not pad to reach the length.";

    $userPrompt = aiInsightFactSheetText($facts)
        . "\nWrite the full seven-section analysis for this reporting period.";

    if ($cached !== null) {
        // Already paid for this exact analysis. The shape check below still
        // runs on it, so a stored report that somehow lost its headings is
        // rejected exactly like a fresh one.
        $aiText      = (string) $cached['report'];
        $modelUsed   = (string) ($cached['model'] ?? AI_MODEL);
        // Keep the time the analysis was WRITTEN, not the time it was
        // served. On screen this sits under the source badge, and a
        // timestamp that jumps forward every time someone reopens the page
        // would read as "this is up to date with now".
        $generatedAt = (string) ($cached['generated_at'] ?? '');
    } elseif ($canDetach && !$force) {
        // ANSWER NOW, WRITE AFTERWARDS.
        //
        // This is the whole 503 fix. Instead of holding a 40-65 second
        // request open - which the proxy in front of PHP refuses to wait for -
        // the browser is told "pending" in under a second and the generation
        // carries on after the response has been handed to the web server.
        // The browser then polls the GET above, which is a cache read, until
        // this finishes and writes the report.
        //
        // $force is excluded deliberately. It means "re-roll the wording",
        // and it bypasses the cache on BOTH sides - so a forced generation
        // would poll against a key nothing will ever be written to, and spin
        // until it timed out. Forcing stays synchronous; it is a rare,
        // deliberate act and the user is already braced for a wait.
        echo json_encode([
            'success' => true,
            'data'    => [
                'status'  => 'pending',
                'period'  => [
                    'month'      => $period['month'],
                    'year'       => $period['year'],
                    'label'      => $period['label'],
                    'prev_label' => $period['prev_label'],
                ],
            ],
        ]);

        // Hand the response over now; everything below runs on a timer the
        // browser is no longer waiting on.
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        @fastcgi_finish_request();
        @ob_end_flush();
        @flush();
    } else {
        $aiText = aiGenerate($systemPrompt, $userPrompt, [
            // Sized for the ANSWER, not the budget. The primary model
            // (stealth/space-bunny-alpha) reasons MANDATORILY and reasoning tokens
            // come out of the same max_tokens allowance, so this is set well above
            // the 700-1000 words the prompt asks for: enough room for the reasoning
            // pass to run first and still leave the whole report written. The model
            // allows 524288, so there is no reason to be stingy here.
            'max_tokens'   => 8000,
            'temperature'  => 0.3,
            'forceRefresh' => $force,
        ]);
        $modelUsed = AI_MODEL;
        // Stamped AFTER the call returns, not before it starts. This one
        // takes 40-65 seconds, so a timestamp taken up front would tell the
        // registrar their analysis was written a minute before it existed.
        $generatedAt = date('Y-m-d H:i:s');
    }
    if ($generatedAt === '') {
        $generatedAt = date('Y-m-d H:i:s');
    }

    $source  = 'ai';
    $aiError = '';

    // Guard the promised shape. A model that returns prose without the seven
    // headings would render as an undifferentiated wall, so the shape is
    // checked rather than trusted - and a shape failure is treated exactly
    // like an unreachable gateway: no report, error shown, figures still
    // served.
    $required = [
        '## 1.', '## 2.', '## 3.', '## 4.', '## 5.', '## 6.', '## 7.',
    ];
    $hasShape = $aiText !== '';
    foreach ($required as $heading) {
        if (strpos($aiText, $heading) === false) {
            $hasShape = false;
            break;
        }
    }

    if (!$hasShape) {
        $aiError = aiLastError() !== ''
            ? aiLastError()
            : 'The AI model did not return the required seven-section report.';
        // The report slot is left EMPTY. Not filled with a template, not filled
        // with a trimmed partial. A half-report from a model that ignored its
        // format is worse than none, because it looks like the whole thing.
        $aiText  = '';
        $source  = 'fallback';
    }

    // ── Store the completed report ──────────────────────────────────
    //
    // Only after the shape check passed, so a truncated or malformed
    // reply is never kept. Storing a failure would be the worst possible
    // behaviour here: the user has already waited a minute, and the one
    // thing they most want is to be able to try again.
    if ($source === 'ai' && $cached === null) {
        aiReportCacheSet($cacheKey, [
            'report'       => $aiText,
            'source'       => $source,
            'model'        => $modelUsed,
            'generated_at' => $generatedAt,
            'period'       => $period['label'],
        ]);
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
            'figures'     => aiInsightFiguresMarkdown($facts),
            'source'      => $source,          // 'ai' | 'fallback'
            'ai_error'    => $aiError,         // shown as a banner when fallback
            'model'        => $source === 'ai' ? $modelUsed : null,
            'generated_at' => $generatedAt,
            // true when this analysis was reused rather than newly written.
            // The UI says so out loud instead of quietly pretending the
            // minute-long call happened again: a report that appears
            // instantly is worth explaining, or the user reads it as a bug.
            'cached'       => $cached !== null,
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
