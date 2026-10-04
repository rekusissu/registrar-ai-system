<?php
// ============================================================
//  API/GRADE-CHASER-AI.PHP
//  Turn a section's blocking findings into a chaser note.
//
//  POST action=explain -> why a section is stuck, and who to chase
//
//  ASSIST ONLY, and assist in a specific sense: the model is given the
//  findings termAudit() already computed and asked to explain them, NOT
//  asked to decide whether the term can be accepted. The gate is
//  deterministic (api/grade-acceptance.php) and this endpoint cannot move
//  it. A model's opinion is not a registrar's signature, so nothing here
//  is ever written to the database.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';
require_once __DIR__ . '/../shared/grade_acceptance.php';
require_once __DIR__ . '/../shared/ai_client.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in first.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['registrar', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only a registrar can use this.']);
    exit;
}

if (($_GET['action'] ?? '') !== 'explain' || strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Use POST action=explain.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sy  = trim((string) ($input['sy'] ?? ''));
$sem = trim((string) ($input['sem'] ?? ''));

// NOT trimmed. PHP's trim() strips NUL by default, and the NUL separates the
// program from the code in the board's key - trimming first made every lookup
// miss and the panel reported "this section has no students".
$sectionRaw = (string) ($input['section'] ?? '');
$program    = trim((string) ($input['program'] ?? ''));

if ($sy === '' || $sem === '' || $sectionRaw === '') {
    echo json_encode(['success' => false, 'message' => 'Pick a term and a section first.']);
    exit;
}

// The key arrives as "program\0code" from the board. Split it back apart here
// rather than trusting a separate program field, so the two cannot disagree.
if (strpos($sectionRaw, "\0") !== false) {
    [$program, $sectionRaw] = explode("\0", $sectionRaw, 2);
}
$section = trim($sectionRaw);

// Recomputed here rather than accepted from the request. A client could send
// any findings list it liked, and the whole value of the note is that it
// describes the section's REAL gaps.
$db     = Database::getInstance();
$roster = grade_acceptance_roster($db, $sy, $sem, $section, $program);
$gate   = sectionAcceptance($sy, $sem, $roster);

if ($gate['state'] === 'empty') {
    echo json_encode([
        'success'    => true,
        'note'       => 'No students are filed under ' . ah_section_label($program, $section)
                    . ' for this term, so there is nobody to chase. Assign the section from the Masterlist first.',
        'can_accept' => false,
        'blocking'   => [],
    ]);
    exit;
}

if (!$gate['blocking']) {
    // Saying "nothing to fix" through a model wastes a call and adds a way
    // for the answer to be wrong. The deterministic answer is the better one.
    echo json_encode([
        'success'    => true,
        'note'       => 'Nothing is blocking ' . ah_section_label($program, $section) . ' this term. '
                    . $gate['summary'] . ' It is ready to accept.',
        'can_accept' => true,
        'blocking'   => [],
    ]);
    exit;
}

$lines = [];
foreach ($gate['blocking'] as $b) {
    $lines[] = '- [' . ($b['code'] ?? 'issue') . '] ' . ($b['title'] ?? '')
             . ' — ' . ($b['detail'] ?? '');
}

$advisory = '';
if ($gate['advisory']) {
    $adv = [];
    foreach ($gate['advisory'] as $a) {
        $adv[] = '- ' . ($a['title'] ?? '') . ' — ' . ($a['detail'] ?? '');
    }
    $advisory = "\n\nWorth a look, but not blocking acceptance:\n" . implode("\n", $adv);
}

$system = "You help a Philippine college registrar clear a blocked term. "
        . "You are given a list of findings that a deterministic checker produced, "
        . "and you explain them in plain language and draft a short note to the "
        . "College of Computer Studies faculty who can fix them.\n\n"
        . "Rules:\n"
        . "- Every finding you were given is real. Do not invent findings that are not in the list.\n"
        . "- Do not soften a missing grade into a formality. A term that cannot be accepted is a real problem.\n"
        . "- Group students who are missing the same thing, so faculty are told once rather than per student.\n"
        . "- No emoji. No markdown headings. Plain sentences.\n"
        . "- Reply with a JSON object only, with keys \"summary\" and \"message\".\n"
        . "- \"summary\" is one or two sentences for the registrar reading the screen.\n"
        . "- \"message\" is the note to faculty, ready to paste. Start it \"College of Computer Studies faculty,\".\n"
        . "- Never state or imply that the term is accepted. The registrar decides that.";

$user = "Section: " . ah_section_label($program, $section) . "\n"
      . "Term: " . termLabel($sy, $sem) . "\n"
      . "Students on this section: " . (int) ($gate['stats']['students'] ?? 0) . "\n"
      . "Students with at least one grade: " . (int) ($gate['stats']['with_grades'] ?? 0) . "\n\n"
      . "Findings blocking acceptance:\n" . implode("\n", $lines)
      . $advisory;

$result = aiGenerateJson($system, $user, [], ['max_tokens' => 900]);

$note = trim((string) ($result['summary'] ?? ''));
$msg  = trim((string) ($result['message'] ?? ''));

if ($note === '' && $msg === '') {
    // aiGenerateJson hands back its fallback on failure. Say so plainly and
    // return the deterministic findings, so the registrar is never left
    // staring at an empty panel unable to tell whether the section is fine.
    $err = aiLastError();
    echo json_encode([
        'success'  => false,
        'message'  => $err !== ''
            ? 'The assistant could not be reached: ' . $err
            : 'The assistant returned nothing usable. The findings below are the real check.',
        'blocking' => $gate['blocking'],
        'advisory' => $gate['advisory'],
        'summary'  => $gate['summary'],
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'note'       => $note,
    'message'    => $msg,
    // Echoed back so the client can never present the note as permission to
    // accept. can_accept is recomputed server-side on every accept call.
    'can_accept' => $gate['can_accept'],
    'blocking'   => $gate['blocking'],
    'advisory'   => $gate['advisory'],
]);