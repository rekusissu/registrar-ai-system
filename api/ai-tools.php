<?php
// ============================================================
//  API/AI-TOOLS.PHP
//  Batch AI tools for the student list page.
//  Actions:
//    action=quality           → deterministic student-record quality queue
//    action=quality_summary   → AI explanation of one student's detected issues
//    action=apply_safe_repairs → apply registrar-confirmed safe corrections
//    action=report            → AI population report
//  LLM responses are cached in ai_cache. Deterministic checks create findings;
//  writes are limited to explicitly confirmed safe repairs.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/student_quality.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$qualityActions = ['quality', 'quality_summary', 'apply_safe_repairs'];
if (in_array($action, $qualityActions, true) && !in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$db = Database::getInstance();

switch ($action) {

    // ─── STUDENT PAGE: DATA QUALITY SCAN ────────────────────────
    case 'quality':
        $students = $db->fetchAll(
            "SELECT id, student_number, first_name, middle_name, last_name, gender,
                    birth_date, nationality, address, contact_number, email, course,
                    major, year_level, school_year, semester, section, status
             FROM students ORDER BY last_name, first_name, id"
        );
        $reports = [];
        $issueCounts = ['identity' => 0, 'contact' => 0, 'academic' => 0, 'duplicate' => 0];
        foreach ($students as $student) {
            $report = buildStudentQualityReport($student);
            $report['duplicates'] = studentQualityDuplicateCandidates($student, $students);
            if (!empty($report['duplicates'])) $report['score'] = max(0, $report['score'] - 20);
            if (empty($report['issues']) && empty($report['duplicates'])) continue;
            $report['student'] = [
                'id' => (int)$student['id'],
                'student_number' => (string)($student['student_number'] ?? ''),
                'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                'course' => (string)($student['course'] ?? ''),
                'year_level' => $student['year_level'],
                'status' => (string)($student['status'] ?? ''),
            ];
            $categories = array_unique(array_column($report['issues'], 'category'));
            if (!empty($report['duplicates'])) $categories[] = 'duplicate';
            foreach ($categories as $category) $issueCounts[$category] = ($issueCounts[$category] ?? 0) + 1;
            $reports[] = $report;
        }
        usort($reports, static function (array $a, array $b): int {
            return count($b['issues']) <=> count($a['issues']) ?: $a['score'] <=> $b['score'];
        });
        echo json_encode(['success' => true, 'data' => [
            'total_students' => count($students),
            'needs_review' => count($reports),
            'issue_counts' => $issueCounts,
            'students' => $reports,
            'generated_at' => date('c'),
        ]]);
        exit;

    // ─── AI EXPLANATION OF ONE STUDENT'S DETECTED ISSUES ─────────
    case 'quality_summary':
        $studentId = (int)($input['student_id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student is required.']);
            exit;
        }
        $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }
        $report = buildStudentQualityReport($student);
        $facts = [
            'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
            'score' => $report['score'],
            'issues' => array_map(static fn(array $issue): array => [
                'field' => $issue['label'], 'severity' => $issue['severity'],
                'message' => $issue['message'],
            ], $report['issues']),
        ];
        $summary = aiGenerate(
            'You explain deterministic student data-quality findings to a registrar. Write one concise paragraph. Use only supplied facts, do not invent values, and do not recommend automatic identity changes.',
            json_encode($facts),
            ['max_tokens' => 220, 'temperature' => 0.1]
        );
        echo json_encode(['success' => true, 'data' => [
            'summary' => $summary !== '' ? $summary : $report['summary'],
            'source' => $summary !== '' ? 'ai' : 'rules',
            'report' => $report,
        ]]);
        exit;

    // ─── APPLY EXPLICITLY CONFIRMED SAFE REPAIRS ────────────────
    case 'apply_safe_repairs':
        $studentId = (int)($input['student_id'] ?? 0);
        $requested = $input['repairs'] ?? [];
        if (!$studentId || !is_array($requested) || empty($requested)) {
            echo json_encode(['success' => false, 'message' => 'Student and safe repairs are required.']);
            exit;
        }
        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $student = $db->fetchOne("SELECT * FROM students WHERE id = ? FOR UPDATE", [$studentId]);
            if (!$student) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
            $allowed = ['contact_number', 'email', 'course'];
            $report = buildStudentQualityReport($student);
            $safeByField = [];
            foreach ($report['safe_repairs'] as $repair) $safeByField[$repair['field']] = $repair;
            $updates = [];
            $oldValues = [];
            foreach ($requested as $request) {
                $field = (string)($request['field'] ?? '');
                $expected = (string)($request['expected_value'] ?? '');
                $suggested = (string)($request['suggested_value'] ?? '');
                if (!in_array($field, $allowed, true) || !isset($safeByField[$field])) continue;
                if ($safeByField[$field]['current_value'] !== $expected) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'message' => 'The record changed. Refresh the quality check before applying.']);
                    exit;
                }
                if ($safeByField[$field]['suggested_value'] !== $suggested) continue;
                $updates[$field] = $suggested;
                $oldValues[$field] = $expected;
            }
            if (empty($updates)) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'No verified safe repairs were selected.']);
                exit;
            }
            $db->update('students', $updates, 'id = ?', [$studentId]);
            logActivity(
                (int)($_SESSION['user_id'] ?? 0), 'student_quality_safe_repair', null,
                'students', $studentId, $oldValues, $updates
            );
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('[ai-tools] safe repair failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Corrections could not be applied.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Safe corrections applied.', 'data' => ['updated_fields' => array_keys($updates)]]);
        exit;



    // ─── BATCH AI REPORT (whole list summary) ─────────────────
    case 'report':

        $students = $db->fetchAll("SELECT * FROM students");
        $total = count($students);
        $active = 0; $atRisk = 0; $noGender = 0; $noCourse = 0;
        $byCourse = []; $byStatus = [];
        foreach ($students as $s) {
            if (($s['status'] ?? '') === 'active') $active++;
            if (($s['status'] ?? '') === 'at-risk') $atRisk++;
            if (empty(trim((string)($s['gender'] ?? '')))) $noGender++;
            if (empty(trim((string)($s['course'] ?? '')))) $noCourse++;
            $c = trim((string)($s['course'] ?? 'N/A'));
            $byCourse[$c] = ($byCourse[$c] ?? 0) + 1;
            $st = $s['status'] ?? 'N/A';
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        arsort($byCourse); arsort($byStatus);

        $system = "You are a registrar's reporting assistant. Write a concise 3-4 sentence summary of the student population, highlighting notable trends or concerns a registrar should know. Do not invent data.";
        $facts = "Total students: {$total}\n"
            . "Active: {$active}, At-risk: {$atRisk}, Missing gender: {$noGender}, Missing course: {$noCourse}\n"
            . "By course: " . json_encode($byCourse) . "\n"
            . "By status: " . json_encode($byStatus);

        $report = aiGenerate($system, $facts, ['max_tokens' => 300]);
        if ($report === '') {
            $report = "Total students: {$total}. Active: {$active}. No data issues found to report.";
        }

        echo json_encode(['success' => true, 'data' => ['report' => $report]]);
        exit;

    // ─── STATUS RECOMMENDATIONS (AI) ────────────────────────
    case 'status_recommendations':
        $allS = $db->fetchAll(
            "SELECT s.id,s.student_number,s.first_name,s.last_name,s.course,
                    s.year_level,s.status,MAX(st.created_at) AS last_change
             FROM students s LEFT JOIN status_tracker st ON st.student_id=s.id
             GROUP BY s.id ORDER BY s.id"
        );
        $recs = [];
        foreach ($allS as $s) {
            $sid = (int) $s['id'];
            $st  = strtolower(trim((string) ($s['status'] ?? '')));
            $nm  = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            $h   = $db->fetchAll("SELECT current_status,created_at FROM status_tracker WHERE student_id=? ORDER BY created_at DESC LIMIT 10", [$sid]);
            $ds  = $s['last_change'] ? (int) floor((time() - strtotime($s['last_change'])) / 86400) : 999;
            $cP  = 0;
            foreach ($h as $x) { if (strtolower((string)($x['current_status'] ?? '')) === 'probation') $cP++; else break; }
            $ad = (int) ($db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE student_id=? AND status NOT IN ('completed','claimed')", [$sid]) ?? 0);
            $sc = (int) ($db->fetchColumn("SELECT COUNT(*) FROM rfid_scan_logs l JOIN rfid_cards c ON l.card_uid=c.card_uid WHERE c.student_id=? AND l.scan_time>=DATE_SUB(NOW(),INTERVAL 30 DAY)", [$sid]) ?? 0);
            $r = null;
            if ($st === 'probation' && $cP >= 2)           $r = ['recommended_status'=>'at-risk','severity'=>'high',"reason"=>"$nm probation $cP consecutive periods."];
            elseif ($st === 'at-risk' && $ds > 180)        $r = ['recommended_status'=>'inactive','severity'=>'high',"reason"=>"$nm at-risk over 6 months."];
            elseif ($st === 'graduated' && $ad === 0 && (int)($s['year_level'] ?? 0) >= 4)
                                                           $r = ['recommended_status'=>'alumni','severity'=>'medium',"reason"=>"$nm graduated, no pending docs."];
            elseif (in_array($st, ['enrolled','active']) && $ds > 90 && $sc === 0)
                                                           $r = ['recommended_status'=>'inactive','severity'=>'medium',"reason"=>"$nm no activity for {$ds} days."];
            if ($r) { $r['student_id']=$sid; $r['student_name']=$nm; $r['student_number']=(string)($s['student_number']??''); $r['current_status']=$st; $r['action_type']=$r['recommended_status']?'change_status':'review'; $recs[]=$r; }
        }
        $src = 'rule';
        if (!empty($recs) && function_exists('aiGenerateJson')) {
            $ai = aiGenerateJson("Refine recommendations. JSON: {\"recommendations\":[{\"student_id\":int,\"student_name\":str,\"student_number\":str,\"current_status\":str,\"recommended_status\":str|null,\"severity\":\"low\"|\"medium\"|\"high\",\"reason\":str,\"action_type\":\"change_status\"|\"review\"}]}", json_encode(array_slice($recs, 0, 20)), [], ['max_tokens' => 1200]);
            if (is_array($ai) && !empty($ai['recommendations'])) { $recs = $ai['recommendations']; $src = 'ai'; }
        }
        usort($recs, fn($a,$b) => (['high'=>0,'medium'=>1,'low'=>2][$a['severity']??'low']??2) <=> (['high'=>0,'medium'=>1,'low'=>2][$b['severity']??'low']??2));
        echo json_encode(['success' => true, 'data' => ['recommendations' => array_slice($recs, 0, 15), 'source' => $src]]);
        exit;

    // ─── STUDENT PROFILE (registrar-safe read-only) ─────
    case 'profile':
        $studentId = (int)($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success'=>false,'message'=>'Student is required.']); exit; }
        $student = $db->fetchOne("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course, year_level, status FROM students WHERE id = ?", [$studentId]);
        if (!$student) { echo json_encode(['success'=>false,'message'=>'Student not found.']); exit; }
        $history = $db->fetchAll("SELECT previous_status, current_status, reason, created_at FROM status_tracker WHERE student_id=? ORDER BY created_at DESC LIMIT 12", [$studentId]);
        $current = strtolower((string)($student['status'] ?? 'inactive'));
        $attention = in_array($current, ['at-risk','probation'], true) ? 'Review recommended' : ($current === 'inactive' ? 'Inactive record' : 'Routine review');
        $summary = sprintf('%s is currently listed as %s with %d recorded status change(s).', $student['name'], $current, count($history));
        $recommendation = $attention === 'Routine review' ? 'No immediate status action is indicated by the available tracker records.' : 'Review the student’s status history and supporting registrar records before making any status change.';
        echo json_encode(['success'=>true,'data'=>['summary'=>$summary,'recommendation'=>$recommendation,'attention'=>$attention,'source'=>'rules','student'=>$student,'history_count'=>count($history)]]);
        exit;

    // ─── STUDENT RISKS ──────────────────────────────────────
    case 'student_risks':
        $ids = $input['student_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) { echo json_encode(['success'=>false,'message'=>'student_ids required.']); exit; }
        $ids = array_map('intval', $ids);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->fetchAll("SELECT s.id,s.first_name,s.last_name,s.status FROM students s WHERE s.id IN ($ph)", $ids);
        $rr = [];
        foreach ($rows as $s) {
            $sid = (int) $s['id'];
            $st  = strtolower(trim((string) ($s['status'] ?? '')));
            $h   = $db->fetchAll("SELECT current_status,created_at FROM status_tracker WHERE student_id=? ORDER BY created_at DESC LIMIT 10", [$sid]);
            $cc  = 0;
            foreach ($h as $x) { $cs = strtolower((string)($x['current_status'] ?? '')); if (in_array($cs, ['at-risk','probation'])) $cc++; else break; }
            $last = $h[0]['created_at'] ?? null;
            $days = $last ? (int) floor((time() - strtotime($last)) / 86400) : 999;
            $gwa  = $db->fetchColumn("SELECT gwa FROM academic_history WHERE student_id=? ORDER BY created_at DESC LIMIT 1", [$sid]);
            $gwa  = $gwa ? (float) $gwa : null;
            $r = 'low'; $reason = 'Stable, no red flags.';
            if (in_array($st, ['at-risk','probation'])) { $r='high'; $reason="Currently $st"; if ($cc >= 2) $reason .= " for $cc periods"; $reason .= "."; }
            elseif ($st === 'dropped')     { $r='medium'; $reason='Dropped.'; }
            elseif ($gwa !== null && $gwa > 3.0) { $r='medium'; $reason="GWA $gwa above 3.0."; }
            elseif ($days > 180)           { $r='medium'; $reason="No change for $days days."; }
            $rr[$sid] = ['risk' => $r, 'reason' => $reason];
        }
        $src = 'rule';
        if (function_exists('aiGenerateJson') && count($ids) <= 20) {
            $ai = aiGenerateJson("Assess risk per student. JSON: {\"risks\":{\"id\":{\"risk\":\"low\"|\"medium\"|\"high\",\"reason\":str}}}", json_encode($rr), [], ['max_tokens' => 1500]);
            if (is_array($ai) && !empty($ai['risks'])) { $rr = $ai['risks']; $src = 'ai'; }
        }
        echo json_encode(['success' => true, 'data' => ['risks' => $rr, 'source' => $src]]);
        exit;

    // ─── STATUS ANOMALIES ──────────────────────────────────
    case 'status_anomalies':
        $anom = [];
        $freq = $db->fetchAll("SELECT st.student_id,s.first_name,s.last_name,s.student_number,COUNT(*) AS cnt FROM status_tracker st JOIN students s ON s.id=st.student_id WHERE st.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY st.student_id HAVING cnt>=3");
        if ($freq) $anom[] = ['type'=>'frequent_changes','label'=>count($freq).' student(s) changed 3+ times in 30 days','icon'=>'fas fa-sync-alt','color'=>'#f59e0b','students'=>array_map(fn($r)=>['id'=>(int)$r['student_id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??''),'count'=>(int)$r['cnt']],$freq)];
        $gp = $db->fetchAll("SELECT s.id,s.first_name,s.last_name,s.student_number FROM students s JOIN document_requests dr ON dr.student_id=s.id AND dr.status NOT IN ('completed','claimed') WHERE s.status='graduated' GROUP BY s.id");
        if ($gp) $anom[] = ['type'=>'grad_pending_docs','label'=>count($gp).' graduated with pending docs','icon'=>'fas fa-file-circle-exclamation','color'=>'#8b5cf6','students'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??'')],$gp)];
        $inact = $db->fetchAll("SELECT s.id,s.first_name,s.last_name,s.student_number FROM students s LEFT JOIN status_tracker st ON st.student_id=s.id LEFT JOIN rfid_cards rc ON rc.student_id=s.id LEFT JOIN rfid_scan_logs rl ON rl.card_uid=rc.card_uid WHERE s.status IN ('enrolled','active') AND (st.created_at IS NULL OR st.created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)) GROUP BY s.id HAVING COUNT(DISTINCT rl.id)=0");
        if ($inact) $anom[] = ['type'=>'inactive_students','label'=>count($inact).' inactive 90+ days no activity','icon'=>'fas fa-ghost','color'=>'#6366f1','students'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??'')],$inact)];
        echo json_encode(['success' => true, 'data' => ['anomalies' => $anom]]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
