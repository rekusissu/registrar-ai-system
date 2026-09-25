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
        if (empty($visit['action_taken'])) $issues[] = 'One visit does not include a recorded action.';
        if (empty($visit['assessment'])) $issues[] = 'One visit does not include a recorded assessment.';
        if (empty($visit['immunization_records'])) $issues[] = 'Immunization information is not documented in the available record.';
        if (!empty($visit['allergies'])) $issues[] = 'Allergy information is documented and may need routine clinic confirmation.';
    }
    arsort($reasonCounts);
    $repeated = array_filter($reasonCounts, static fn(int $count): bool => $count >= 2);
    foreach ($repeated as $reason => $count) $issues[] = "{$reason} appears as the visit reason in {$count} records.";
    $issues = array_values(array_unique($issues));
    $latest = $visits[0] ?? null;
    $facts = ['student' => $student, 'visit_count' => count($visits), 'latest_visit' => $latest, 'repeated_reasons' => $repeated, 'information_flags' => $issues];

    // Keep this administrative and deterministic. The registrar view is a
    // record summary, not a diagnostic assistant, and must not expose model
    // reasoning or issue instructions to clinic staff.
    $studentName = trim((string)($student['name'] ?? 'The student'));
    $program = trim((string)($student['course'] ?? ''));
    $year = trim((string)($student['year_level'] ?? ''));
    $studentLabel = $studentName . ($program !== '' ? ', ' . $program . ($year !== '' ? ' year ' . $year : '') : '');
    $latestDate = $latest ? (string)($latest['date_time'] ?: $latest['visit_date'] ?: 'date not recorded') : 'no visit date recorded';
    $summary = sprintf('%s has %d clinic record%s on file; the latest record is dated %s.', $studentLabel, count($visits), count($visits) === 1 ? '' : 's', $latestDate);
    if ($latest) {
        $reason = trim((string)($latest['reason_for_visit'] ?? ''));
        $assessment = trim((string)($latest['assessment'] ?? ''));
        $action = trim((string)($latest['action_taken'] ?? ''));
        if ($reason !== '') $summary .= ' The latest visit lists “' . $reason . '” as the reason.';
        if ($assessment !== '') $summary .= ' Assessment: ' . $assessment . '.';
        if ($action !== '') $summary .= ' Action recorded: ' . $action . '.';
    }
    if ($issues) $summary .= ' The record set also contains documentation notes for routine clinic confirmation.';
    echo json_encode(['success' => true, 'data' => ['summary' => $summary, 'source' => 'rules', 'visit_count' => count($visits), 'latest_visit' => $latest, 'information_flags' => $issues, 'disclaimer' => 'Administrative summary only. Confirm details with the Clinic Portal or nurse when needed.']]);
} catch (Exception $e) {
    error_log('[clinic-ai-review] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Health review unavailable.']);
}
