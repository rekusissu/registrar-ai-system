<?php
// ============================================================
//  API/AI-ASSIST.PHP
//  AI-assisted data entry for the student form.
//  Actions:
//    POST action=paste_fill    → LLM extracts student fields from pasted text
//    POST action=suggest_field → LLM corrects/suggests a single field value
//    POST action=check_duplicate → deterministic fuzzy duplicate scan
//  All LLM responses are cached in ai_cache. Nothing writes to
//  the DB here — this is assist-only.
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
require_once __DIR__ . '/../shared/document_reader.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

switch ($action) {

    // ─── EXTRACT FROM DOCUMENT (PDF/DOCX/TXT) ────────────────
    case 'extract_doc':
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload failed.']);
            exit;
        }

        $tmpName = $_FILES['file']['tmp_name'];
        $origName = (string) ($_FILES['file']['name'] ?? 'document');
        $maxBytes = 15 * 1024 * 1024; // 15 MB
        if ((int) $_FILES['file']['size'] > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'File too large (max 15 MB).']);
            exit;
        }

        try {
            $text = extractDocumentText($tmpName, $origName);
        } catch (RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        if (trim($text) === '') {
            echo json_encode(['success' => false, 'message' => 'No readable text found in the document.']);
            exit;
        }

        // Reuse the same extraction prompt as paste_fill.
        $system = "You are a data-entry assistant for a Philippine university registrar. " .
                  "Extract student enrollment details from the document text into a JSON object. " .
                  "Use ONLY these keys (omit keys you cannot find): " .
                  "first_name, middle_name, last_name, gender, civil_status, birth_date (YYYY-MM-DD), " .
                  "place_of_birth, nationality, religion, email, contact_number (PH mobile), " .
                  "address, course, year_level (integer), guardian_name, guardian_relationship. " .
                  "Be conservative: do not invent data. If uncertain, leave the key out.";

        $result = aiGenerateJson($system, "Document text to extract from:\n\n" . mb_substr($text, 0, 6000), [], [
            'max_tokens' => 800,
        ]);

        if (is_array($result)) {
            if (!empty($result['first_name']))  $result['first_name']  = normalizeNameCase((string) $result['first_name']);
            if (!empty($result['last_name']))   $result['last_name']   = normalizeNameCase((string) $result['last_name']);
            if (!empty($result['middle_name'])) $result['middle_name'] = normalizeNameCase((string) $result['middle_name']);
            if (!empty($result['contact_number'])) $result['contact_number'] = normalizePhone((string) $result['contact_number']);
            if (!empty($result['course']))      $result['course']      = courseStandardize((string) $result['course']);
            if (!empty($result['nationality']) && strtolower(trim((string)$result['nationality'])) === 'filipino') {
                $result['nationality'] = 'Filipino';
            }
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;

    // ─── PASTE-TO-FILL ──────────────────────────────────────
    case 'paste_fill':
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '') {
            echo json_encode(['success' => false, 'message' => 'No text provided.']);
            exit;
        }

        $system = "You are a data-entry assistant for a Philippine university registrar. " .
                  "Extract student enrollment details from the pasted text into a JSON object. " .
                  "Use ONLY these keys (omit keys you cannot find): " .
                  "first_name, middle_name, last_name, gender, civil_status, birth_date (YYYY-MM-DD), " .
                  "place_of_birth, nationality, religion, email, contact_number (PH mobile), " .
                  "address, course, year_level (integer), guardian_name, guardian_relationship. " .
                  "Be conservative: do not invent data. If uncertain, leave the key out.";

        $result = aiGenerateJson($system, "Text to extract from:\n\n" . mb_substr($text, 0, 6000), [], [
            'max_tokens' => 800,
        ]);

        // Normalize extracted values through the deterministic layer.
        if (is_array($result)) {
            if (!empty($result['first_name']))  $result['first_name']  = normalizeNameCase((string) $result['first_name']);
            if (!empty($result['last_name']))   $result['last_name']   = normalizeNameCase((string) $result['last_name']);
            if (!empty($result['middle_name'])) $result['middle_name'] = normalizeNameCase((string) $result['middle_name']);
            if (!empty($result['contact_number'])) $result['contact_number'] = normalizePhone((string) $result['contact_number']);
            if (!empty($result['course']))      $result['course']      = courseStandardize((string) $result['course']);
            if (!empty($result['nationality']) && strtolower(trim((string)$result['nationality'])) === 'filipino') {
                $result['nationality'] = 'Filipino';
            }
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;

    // ─── SINGLE-FIELD SUGGESTION ────────────────────────────
    case 'suggest_field':
        $field = (string) ($input['field'] ?? '');
        $value = trim((string) ($input['value'] ?? ''));
        $context = (string) ($input['context'] ?? '');
        if ($field === '' || $value === '') {
            echo json_encode(['success' => false, 'message' => 'Field and value required.']);
            exit;
        }

        $system = "You are a data-quality assistant for a Philippine university. " .
                  "Correct/standardize a single field value. Respond with a JSON object: " .
                  "{\"suggested\": \"...\", \"reason\": \"short note\"}. If the value is already fine, " .
                  "return the same value with reason \"ok\". Context: " . mb_substr($context, 0, 400);

        $result = aiGenerateJson($system, "Field: $field\nValue: $value", ['suggested' => $value, 'reason' => 'ok'], [
            'max_tokens' => 200,
        ]);

        $suggested = (string) ($result['suggested'] ?? $value);
        // Deterministic normalization for known fields.
        if ($field === 'contact_number') $suggested = normalizePhone($suggested);
        if ($field === 'course')         $suggested = courseStandardize($suggested);
        if (in_array($field, ['first_name','middle_name','last_name','place_of_birth','religion'], true)) {
            $suggested = normalizeNameCase($suggested);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'suggested' => $suggested,
                'reason'    => (string) ($result['reason'] ?? ''),
            ],
        ]);
        exit;

    // ─── DUPLICATE CHECK (deterministic) ────────────────────
    case 'check_duplicate':
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName  = trim((string) ($input['last_name'] ?? ''));
        $birthDate = trim((string) ($input['birth_date'] ?? ''));
        $candidates = findDuplicateStudents($firstName, $lastName, $birthDate !== '' ? $birthDate : null);
        echo json_encode(['success' => true, 'data' => $candidates]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
