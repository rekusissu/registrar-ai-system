<?php
// ============================================================
//  API/AI-INSIGHTS-DATA.PHP
//  Period-aware dashboard data for Intelligent Analytics.
//
//    GET ?month=8&year=2026
//    → { success, data: { period, cards, charts } }
//
//  Read-only, aggregates only. The page uses
//  shared/analytics.php directly for the first paint and calls this
//  endpoint when the Reporting Period changes.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/analytics.php';

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

try {
    $period = aiInsightPeriodFromRequest();
    $build  = aiInsightBuild($period);

    echo json_encode([
        'success' => true,
        'data'    => [
            'period' => [
                'month'      => $period['month'],
                'year'       => $period['year'],
                'label'      => $period['label'],
                'month_name' => $period['month_name'],
                'start'      => substr($period['start'], 0, 10),
                'end'        => date('Y-m-d', strtotime($period['end'] . ' -1 day')),
                'prev_label' => $period['prev_label'],
            ],
            'cards'  => $build['cards'],
            'charts' => $build['charts'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[ai-insights-data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load analytics for that period.']);
}
