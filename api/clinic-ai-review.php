<?php
// ============================================================
//  API/CLINIC-AI-REVIEW.PHP
//  Registrar-safe, read-only health record review.
//  Summarizes existing clinic records; it does not diagnose,
//  prescribe, edit, or create health data.
// ============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';

if (!isLoggedIn()) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
if (getCurrentUserRole() !== 'admin' && getCurrentUserRole() !== 'registrar') { echo json_encode(['success' => false, 'message' => 'Forbidden.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required.']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$studentId = (int)($input['student_id'] ?? 0);
if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student is required.']); exit; }

try {
    $db = Database::getInstance();
    $student = $db->fetchOne("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course, year_level FROM students WHERE id = ?", [$studentId]);
    if (!$student) { echo json_encode(['success' => false, 'message' => 'Student not found.']); exit; }
    $visits = $db->fetchAll("SELECT id, date_time, visit_date, reason_for_visit, assessment, action_taken, nurse_notes, record_status, temperature, blood_pressure, allergies, pre_existing_conditions, immunization_records FROM health_visits WHERE student_id = ? ORDER BY COALESCE(date_time, visit_date) DESC, id DESC LIMIT 50", [$studentId]);

    $issues = [];
    $reasonCounts = [];
    foreach ($visits as $visit) {
        $reason = trim((string)($visit['reason_for_visit'] ?? ''));
        if ($reason !== '') $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        if (empty($visit['action_taken'])) $issues[] = 'One visit has no recorded action.';
        if (empty($visit['assessment'])) $issues[] = 'One visit has no recorded assessment.';
        if (empty($visit['immunization_records'])) $issues[] = 'Immunization information is not recorded in this visit.';
        if (!empty($visit['allergies'])) $issues[] = 'Allergy information is present; confirm it with the Clinic Portal before acting.';
    }
    arsort($reasonCounts);
    $repeated = array_filter($reasonCounts, static fn(int $count): bool => $count >= 2);
    foreach ($repeated as $reason => $count) $issues[] = "Repeated visit reason: {$reason} ({$count} visits).";
    $issues = array_values(array_unique($issues));
    $latest = $visits[0] ?? null;
    $fallback = sprintf('%d clinic record(s) found. Latest visit: %s. %d information item(s) may need clinic confirmation.', count($visits), $latest ? (string)($latest['date_time'] ?: $latest['visit_date'] ?: 'not recorded') : 'none', count($issues));

    $facts = ['student' => $student, 'visit_count' => count($visits), 'latest_visit' => $latest, 'repeated_reasons' => $repeated, 'information_flags' => $issues];
    $review = aiGenerate('You provide a read-only administrative review of existing school clinic records for a registrar. Summarize facts and missing information in two concise sentences. Do not diagnose, prescribe, clear, restrict, or invent medical facts. Recommend confirming unclear information with the Clinic Portal or nurse.', json_encode($facts), ['max_tokens' => 220, 'temperature' => 0.1, 'ttl' => 300]);
    echo json_encode(['success' => true, 'data' => ['summary' => $review !== '' ? $review : $fallback, 'source' => $review !== '' ? 'ai' : 'rules', 'visit_count' => count($visits), 'latest_visit' => $latest, 'information_flags' => $issues, 'disclaimer' => 'Informational review only. Confirm with the Clinic Portal or nurse before taking action.']]);
} catch (Exception $e) {
    error_log('[clinic-ai-review] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Health review unavailable.']);
}
