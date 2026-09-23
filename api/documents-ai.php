<?php
// API/DOCUMENTS-AI.PHP - AI-powered Digital File Storage endpoints
header('Content-Type: application/json');
require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/document_reader.php';

if (!isLoggedIn()) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) { echo json_encode(['success' => false, 'message' => 'Forbidden.']); exit; }

$db = Database::getInstance();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$docTypeLabels = [
    'enrollment' => 'Enrollment Form', 'transcript' => 'Transcript',
    'health' => 'Health Record', 'photo' => '1x1 ID Photo', 'clearance' => 'Clearance',
    'form_137' => 'Form 137', 'psa' => 'PSA Birth Certificate', 'other' => 'Other',
];

// --- 1. CLASSIFY ---
if ($method === 'POST' && $action === 'classify') {
    $docId = intval($_POST['document_id'] ?? 0);
    if (!$docId) { echo json_encode(['success' => false, 'message' => 'document_id required.']); exit; }
    $doc = $db->fetchOne("SELECT * FROM documents WHERE id = ?", [$docId]);
    if (!$doc) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    $text = $doc['content_text'] ?? '';
    if ($text === '' && $doc['file_path'] && is_file($doc['file_path'])) {
        try {
            $text = extractDocumentText($doc['file_path'], $doc['filename']);
            if ($text !== '') $db->update('documents', ['content_text' => substr($text, 0, 65000)], 'id = ?', [$docId]);
        } catch (Exception $e) {}
    }
    $context = $text !== '' ? substr($text, 0, 3000) : 'No text extracted.';
    $sys = 'Classify into: ' . implode(', ', array_keys($docTypeLabels)) . "\nJSON: " . '{"type":"<type>","confidence":0-1,"reasoning":"..."}';
    $usr = "Current: {$doc['doc_type']}, File: {$doc['filename']}\n{$context}";
    $resp = aiGenerate($sys, $usr, ['max_tokens' => 256, 'temperature' => 0.1]);
    $p = json_decode(trim($resp), true);
    if ($p && isset($p['type']) && isset($docTypeLabels[$p['type']])) {
        $conf = round((float)($p['confidence'] ?? 0.5), 2);
        if ($conf >= 0.7) $db->update('documents', ['doc_type' => $p['type'], 'ai_classified' => 1, 'ai_confidence' => $conf], 'id = ?', [$docId]);
        $db->insert('document_ai_audit', [
            'document_id' => $docId, 'student_id' => $doc['student_id'], 'action' => 'classify',
            'input_summary' => substr($context, 0, 500), 'result' => json_encode($p),
            'confidence' => $conf, 'created_by' => $userId,
        ]);
        echo json_encode(['success' => true, 'type' => $p['type'], 'label' => $docTypeLabels[$p['type']], 'confidence' => $conf, 'updated' => $conf >= 0.7]);
    } else {
        echo json_encode(['success' => false, 'message' => 'AI could not classify.']);
    }
    exit;
}

// --- 2. VALIDATE (auto-review on upload) ---
if ($method === 'POST' && $action === 'validate') {
    $docId = intval($_POST['document_id'] ?? 0);
    if (!$docId) { echo json_encode(['success' => false, 'message' => 'document_id required.']); exit; }
    $doc = $db->fetchOne("SELECT * FROM documents WHERE id = ?", [$docId]);
    if (!$doc) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    // Extract text
    $text = $doc['content_text'] ?? '';
    if ($text === '' && $doc['file_path'] && is_file($doc['file_path'])) {
        try {
            $text = extractDocumentText($doc['file_path'], $doc['filename']);
            if ($text !== '') $db->update('documents', ['content_text' => substr($text, 0, 65000)], 'id = ?', [$docId]);
        } catch (Exception $e) { /* text extraction failed — continue with empty */ }
    }

    // Images: can't extract text but could still be a valid photo doc
    $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif'], true);

    $declared = $docTypeLabels[$doc['doc_type']] ?? $doc['doc_type'];
    $context = $text !== '' ? substr($text, 0, 3000) : ($isImage ? '[Image file — no extractable text]' : '[No text content extracted]');

    $sys = "You are a document validator for a school registrar system. Analyze the document content and determine if it is a legitimate student record document matching the declared type.\n"
         . "Declared type: {$declared}\n"
         . "Respond with JSON only: {\"valid\": true/false, \"confidence\": 0.0-1.0, \"reasoning\": \"brief explanation\"}\n"
         . "Mark invalid if:\n"
         . "- Content clearly does not match the declared document type\n"
         . "- Document appears to be fake, gibberish, or a test file\n"
         . "- File is empty or contains no meaningful content (unless it's an image/photo)\n"
         . "- Content appears unrelated to student records\n"
         . "Mark valid if:\n"
         . "- Content plausibly matches the declared type (even if partial)\n"
         . "- It's an image/photo file and type is 'photo'";

    $usr = "Filename: {$doc['filename']}\nDeclared type: {$declared}\n\nDocument content:\n{$context}";
    $resp = aiGenerate($sys, $usr, ['max_tokens' => 256, 'temperature' => 0.1]);
    $p = json_decode(trim($resp), true);

    if ($p && isset($p['valid']) && isset($p['confidence'])) {
        $valid = (bool) $p['valid'];
        $conf = round((float)($p['confidence'] ?? 0.5), 2);
        $note = $p['reasoning'] ?? '';

        $db->update('documents', [
            'ai_valid' => $valid ? 1 : 0,
            'ai_validation_note' => $note,
        ], 'id = ?', [$docId]);

        $db->insert('document_ai_audit', [
            'document_id' => $docId,
            'student_id' => $doc['student_id'],
            'action' => 'validate',
            'input_summary' => substr($context, 0, 500),
            'result' => json_encode($p),
            'confidence' => $conf,
            'created_by' => $userId,
        ]);

        echo json_encode([
            'success' => true,
            'valid' => $valid,
            'confidence' => $conf,
            'reasoning' => $note,
        ]);
    } else {
        // AI failed to return structured response — mark as inconclusive
        $db->update('documents', [
            'ai_valid' => -1,
            'ai_validation_note' => 'AI validation inconclusive',
        ], 'id = ?', [$docId]);
        echo json_encode(['success' => true, 'valid' => null, 'confidence' => 0, 'reasoning' => 'AI validation inconclusive.']);
    }
    exit;
}

// --- 3. CHECK DUPLICATE ---
if ($method === 'POST' && $action === 'check_duplicate') {
    $docId = intval($_POST['document_id'] ?? 0);
    if (!$docId) { echo json_encode(['success' => false, 'message' => 'document_id required.']); exit; }
    $doc = $db->fetchOne("SELECT * FROM documents WHERE id = ?", [$docId]);
    if (!$doc) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    $hash = $doc['file_hash'] ?? '';
    $hashDups = $hash ? $db->fetchAll(
        "SELECT d.id, d.filename, d.doc_type, d.created_at, CONCAT(s.first_name,' ',s.last_name) AS student_name
         FROM documents d LEFT JOIN students s ON d.student_id = s.id
         WHERE d.file_hash = ? AND d.id != ?", [$hash, $docId]
    ) : [];

    $sameType = $doc['student_id'] ? $db->fetchAll(
        "SELECT d.id, d.filename, d.file_hash, d.created_at FROM documents d
         WHERE d.student_id = ? AND d.doc_type = ? AND d.id != ?",
        [$doc['student_id'], $doc['doc_type'], $docId]
    ) : [];

    $db->insert('document_ai_audit', [
        'document_id' => $docId, 'student_id' => $doc['student_id'], 'action' => 'check_duplicate',
        'input_summary' => "hash={$hash}, " . count($hashDups) . " hash dupes",
        'result' => json_encode(['hash_matches' => count($hashDups), 'same_type' => count($sameType)]), 'created_by' => $userId,
    ]);
    echo json_encode(['success' => true, 'has_duplicates' => count($hashDups) > 0, 'hash_duplicates' => $hashDups, 'same_type_docs' => $sameType]);
    exit;
}

// --- 4. QUALITY CHECK (per-student) ---
if ($method === 'POST' && $action === 'quality_check') {
    $studentId = intval($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
    if (!$studentId) { echo json_encode(['success' => false, 'message' => 'student_id required.']); exit; }
    $student = $db->fetchOne("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE id = ?", [$studentId]);
    if (!$student) { echo json_encode(['success' => false, 'message' => 'Student not found.']); exit; }

    $required = ['form_137', 'psa', 'photo', 'enrollment', 'transcript'];
    $docs = $db->fetchAll("SELECT * FROM documents WHERE student_id = ? ORDER BY created_at DESC", [$studentId]);
    $present = [];
    $issues = [];
    foreach ($docs as $d) {
        $t = $d['doc_type'];
        if (!isset($present[$t])) $present[$t] = [];
        $present[$t][] = $d;
        if ($d['file_hash']) {
            $cnt = (int)$db->fetchColumn("SELECT COUNT(*) FROM documents WHERE file_hash = ?", [$d['file_hash']]);
            if ($cnt > 1) $issues[] = ['doc_id' => $d['id'], 'type' => $t, 'issue' => 'duplicate_hash', 'detail' => "Hash in {$cnt} records"];
        }
        if ($d['file_path'] && !is_file($d['file_path'])) {
            $issues[] = ['doc_id' => $d['id'], 'type' => $t, 'issue' => 'missing_file', 'detail' => 'File not on disk'];
        }
    }
    $missing = [];
    foreach ($required as $rt) { if (empty($present[$rt])) $missing[] = ['type' => $rt, 'label' => $docTypeLabels[$rt] ?? $rt]; }
    $score = count($required) > 0 ? round((count($required) - count($missing)) / count($required) * 100) : 100;

    echo json_encode(['success' => true, 'student' => $student, 'score' => $score, 'required' => $required, 'present' => array_keys($present), 'missing' => $missing, 'issues' => $issues, 'total_docs' => count($docs)]);
    exit;
}

// --- 5. DATA QUALITY REPORT ---
if ($method === 'GET' && $action === 'data_quality_report') {
    $students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS sn FROM students WHERE status != 'archived' ORDER BY last_name");
    $allDocs = $db->fetchAll("SELECT * FROM documents ORDER BY student_id, doc_type");
    $required = ['form_137', 'psa', 'photo', 'enrollment', 'transcript'];

    $byStudent = [];
    foreach ($allDocs as $d) $byStudent[(int)$d['student_id']][$d['doc_type']][] = $d;

    $missingStudents = [];
    $completeCount = 0;
    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $present = array_keys($byStudent[$sid] ?? []);
        $missing = array_values(array_diff($required, $present));
        if (empty($missing)) { $completeCount++; }
        else { $missingStudents[] = ['student_id' => $sid, 'student_number' => $s['student_number'], 'student_name' => $s['sn'], 'missing' => array_map(fn($t) => ['type' => $t, 'label' => $docTypeLabels[$t] ?? $t], $missing), 'missing_count' => count($missing)]; }
    }

    $hashGroups = [];
    foreach ($allDocs as $d) { if ($d['file_hash']) $hashGroups[$d['file_hash']][] = $d; }
    $dupGroups = 0; $dupFiles = 0;
    foreach ($hashGroups as $g) { if (count($g) > 1) { $dupGroups++; $dupFiles += count($g); } }

    $stale = (int)$db->fetchColumn("SELECT COUNT(*) FROM documents WHERE student_id IS NULL AND enroll_status = 'pending'");
    $auditCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM document_ai_audit");
    $lastAudit = $db->fetchOne("SELECT created_at FROM document_ai_audit ORDER BY id DESC LIMIT 1");

    echo json_encode([
        'success' => true, 'total_students' => count($students), 'complete' => $completeCount,
        'incomplete' => count($missingStudents),
        'completion_rate' => count($students) > 0 ? round($completeCount / count($students) * 100) : 0,
        'missing_students' => $missingStudents, 'duplicate_groups' => $dupGroups, 'total_duplicates' => $dupFiles,
        'staged_pending' => $stale, 'total_docs' => count($allDocs),
        'ai_actions_run' => $auditCount, 'last_ai_action' => $lastAudit['created_at'] ?? null,
    ]);
    exit;
}

// --- 6. SMART NOTIFY ---
if ($method === 'POST' && $action === 'smart_notify') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetSid = intval($input['student_id'] ?? 0);
    $required = ['form_137', 'psa', 'photo', 'enrollment', 'transcript'];

    $q = "SELECT s.id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS name FROM students s WHERE s.status != 'archived'";
    $p = [];
    if ($targetSid) { $q .= " AND s.id = ?"; $p[] = $targetSid; }
    $students = $db->fetchAll($q, $p);

    $notified = 0; $skipped = 0;
    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $present = array_column($db->fetchAll("SELECT DISTINCT doc_type FROM documents WHERE student_id = ?", [$sid]), 'doc_type');
        $missing = array_values(array_diff($required, $present));
        if (empty($missing)) { $skipped++; continue; }
        $labels = array_map(fn($t) => $docTypeLabels[$t] ?? $t, $missing);
        $msg = "Missing required documents:\n- " . implode("\n- ", $labels) . "\n\nPlease upload them ASAP.";
        $nid = notifyStudent($sid, 'Missing Documents', $msg, 'warning', null, $userId);
        if ($nid) { $notified++; logActivity($userId, 'smart_notify_missing', json_encode(['student_id' => $sid, 'missing' => $missing]), 'student_notifications', $nid); }
    }
    echo json_encode(['success' => true, 'notified' => $notified, 'skipped' => $skipped, 'message' => "{$notified} student(s) notified."]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);

