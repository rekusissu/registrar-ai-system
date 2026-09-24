<?php
// ============================================================
//  API/CLINIC-AI-RECOMMEND.PHP
//  AI Aid Recommendation for the Clinic Portal.
//  POST { reason_for_visit, assessment, temperature,
//         blood_pressure, allergies, pre_existing_conditions,
//         immunization_records, height, weight, nurse_notes }
//  Returns: { recommendation, urgency, key_warnings[],
//             referral_needed, confidence, summary }
//  Roles: nurse / admin
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (!isLoggedIn()) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$role = getCurrentUserRole();
if ($role !== 'admin' && $role !== 'nurse') { echo json_encode(['success' => false, 'message' => 'Forbidden.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$reason        = trim((string) ($input['reason_for_visit'] ?? ''));
$assessment    = trim((string) ($input['assessment'] ?? ''));
$temperature   = trim((string) ($input['temperature'] ?? ''));
$bloodPressure = trim((string) ($input['blood_pressure'] ?? ''));
$allergies     = trim((string) ($input['allergies'] ?? ''));
$conditions    = trim((string) ($input['pre_existing_conditions'] ?? ''));
$immunizations = trim((string) ($input['immunization_records'] ?? ''));
$height        = trim((string) ($input['height'] ?? ''));
$weight        = trim((string) ($input['weight'] ?? ''));
$nurseNotes    = trim((string) ($input['nurse_notes'] ?? ''));
$pulse         = trim((string) ($input['pulse'] ?? ''));
$respiratory   = trim((string) ($input['respiratory_rate'] ?? ''));
$oxygen        = trim((string) ($input['oxygen_saturation'] ?? ''));
$symptoms      = trim((string) ($input['symptoms'] ?? ''));
$disposition   = trim((string) ($input['disposition'] ?? ''));
$precautions   = trim((string) ($input['return_precautions'] ?? ''));
$followUp      = trim((string) ($input['follow_up_plan'] ?? ''));
$redFlags      = is_array($input['red_flags'] ?? null) ? implode(', ', $input['red_flags']) : trim((string) ($input['red_flags'] ?? ''));

if ($reason === '' && $assessment === '' && $temperature === '' && $bloodPressure === '') {
    echo json_encode(['success' => false, 'message' => 'At least one visit field is required for a recommendation.']);
    exit;
}

$parts = [];
if ($reason !== '')          $parts[] = "Reason for visit: {$reason}";
if ($assessment !== '')      $parts[] = "Nurse assessment: {$assessment}";
if ($temperature !== '')     $parts[] = "Temperature: {$temperature}°C";
if ($bloodPressure !== '')   $parts[] = "Blood pressure: {$bloodPressure}";
if ($allergies !== '')       $parts[] = "Known allergies: {$allergies}";
if ($conditions !== '')      $parts[] = "Pre-existing conditions: {$conditions}";
if ($immunizations !== '')   $parts[] = "Immunization records: {$immunizations}";
if ($height !== '')          $parts[] = "Height: {$height} cm";
if ($weight !== '')          $parts[] = "Weight: {$weight} kg";
if ($nurseNotes !== '')      $parts[] = "Nurse notes: {$nurseNotes}";
if ($symptoms !== '')        $parts[] = "Symptoms and observations: {$symptoms}";
if ($pulse !== '')           $parts[] = "Pulse: {$pulse} bpm";
if ($respiratory !== '')     $parts[] = "Respiratory rate: {$respiratory}/min";
if ($oxygen !== '')          $parts[] = "Oxygen saturation: {$oxygen}%";
if ($redFlags !== '')        $parts[] = "Safety flags recorded: {$redFlags}";
if ($disposition !== '')     $parts[] = "Disposition: {$disposition}";
if ($precautions !== '')     $parts[] = "Return precautions: {$precautions}";
if ($followUp !== '')        $parts[] = "Follow-up plan: {$followUp}";

if ($height !== '' && $weight !== '') {
    $h = (float) $height / 100;
    $w = (float) $weight;
    if ($h > 0) { $parts[] = "BMI: " . round($w / ($h * $h), 1); }
}

$userPrompt = "Student visit data:\n" . implode("\n", $parts);

$systemPrompt = <<<'EOT'
You are a non-diagnostic clinical aid in a school clinic portal. Summarize entered facts, identify missing documentation, and suggest escalation prompts. Do not diagnose, prescribe, clear, or restrict students.

Rules:
- Base every statement only on supplied visit data.
- Identify missing pulse, respiratory rate, disposition, precautions, guardian contact, or follow-up fields.
- Flag entered symptoms such as chest pain, difficulty breathing, severe allergic reaction, suspected fracture, loss of consciousness, uncontrolled bleeding, confusion, or concerning vitals for nurse review and clinic protocol.
- Do not recommend a specific medication or treatment.
- Keep the output concise and appropriate for a school clinic.

Respond with ONLY a single valid JSON object:
{"recommendation":"concise first-aid action (1-3 sentences)","urgency":"low|medium|high","key_warnings":["warning1"],"referral_needed":true|false,"confidence":"high|medium|low","summary":"one-line clinical summary"}

No markdown, no code fences, no commentary.
EOT;

$fallback = [
    'recommendation'  => 'AI unavailable. Proceed with standard first-aid protocol per clinical judgment.',
    'urgency'         => 'medium',
    'key_warnings'    => ['AI recommendation unavailable.'],
    'referral_needed' => false,
    'confidence'      => 'low',
    'summary'         => 'AI analysis unavailable.',
];

$result = aiGenerateJson($systemPrompt, $userPrompt, $fallback, [
    'max_tokens'  => 512,
    'temperature' => 0.15,
    'ttl'         => 0,
]);

if (!isset($result['recommendation']))  $result['recommendation'] = $fallback['recommendation'];
if (!isset($result['urgency']) || !in_array($result['urgency'], ['low','medium','high'], true)) $result['urgency'] = 'medium';
if (!isset($result['key_warnings']) || !is_array($result['key_warnings'])) $result['key_warnings'] = $fallback['key_warnings'];
if (!isset($result['referral_needed']) || !is_bool($result['referral_needed'])) $result['referral_needed'] = !empty($result['referral_needed']);
if (!isset($result['confidence']) || !in_array($result['confidence'], ['high','medium','low'], true)) $result['confidence'] = 'low';
if (!isset($result['summary'])) $result['summary'] = '';

try {
    logActivity(getCurrentUserId(), 'clinic_ai_recommendation', json_encode([
        'reason' => $reason, 'urgency' => $result['urgency'], 'referral' => $result['referral_needed'],
    ]));
} catch (Exception $e) { /* audit log failure must not break response */ }

echo json_encode(['success' => true, 'data' => $result]);

