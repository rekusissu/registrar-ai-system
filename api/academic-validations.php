<?php
require_once __DIR__.'/../shared/security_headers.php';
require_once __DIR__.'/../shared/registrar_access.php';
require_once __DIR__.'/../shared/academic_diagnostics.php';
require_once __DIR__.'/../shared/csrf_guard.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['super_admin','registrar_admin','staff','acad_officer','department_head'], true)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Academic records access required.']); exit;
}
$db=Database::getInstance();
if ($_SERVER['REQUEST_METHOD']==='GET') {
    if (isset($_GET['record_id'])) {
        $record=$db->fetchOne('SELECT student_id FROM academic_history WHERE id=?',[(int)$_GET['record_id']]);
        if (!$record) {http_response_code(404);echo json_encode(['success'=>false,'message'=>'Record not found.']);exit;}
        registrarRequireStudent((int)$record['student_id']);
        $history=$db->fetchAll('SELECT v.review_status,v.remarks,v.created_at,u.full_name AS reviewer FROM academic_validations v LEFT JOIN users u ON u.id=v.reviewer_id WHERE v.academic_history_id=? ORDER BY v.id DESC LIMIT 100',[(int)$_GET['record_id']]);
        echo json_encode(['success'=>true,'history'=>$history,'diagnostics'=>academicRecordDiagnostics((int)$_GET['record_id']),'correction_route'=>['mode'=>'local','office'=>'Curriculum','external_forwarding'=>false]]);exit;
    }
    $scope=registrarStudentScope();
    $records=$db->fetchAll("SELECT a.id,a.student_id,a.school_year,a.semester,a.accepted_at,
        s.student_number,s.first_name,s.last_name,s.course,s.year_level,
        v.review_status,v.remarks,v.created_at AS reviewed_at,u.full_name AS reviewer
        FROM academic_history a JOIN students s ON s.id=a.student_id
        LEFT JOIN academic_validations v ON v.id=(SELECT MAX(v2.id) FROM academic_validations v2 WHERE v2.academic_history_id=a.id)
        LEFT JOIN users u ON u.id=v.reviewer_id
        WHERE {$scope['sql']} ORDER BY a.school_year DESC,s.last_name,s.first_name,a.id DESC", $scope['params']);
    echo json_encode(['success'=>true,'records'=>$records]); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
if (getCurrentUserRole()==='department_head') {http_response_code(403);echo json_encode(['success'=>false,'message'=>'Department Head access is read-only.']);exit;}
$input=json_decode(file_get_contents('php://input'),true)?:[];
$record=$db->fetchOne('SELECT id,student_id FROM academic_history WHERE id=?',[(int)($input['record_id']??0)]);
if (!$record) {http_response_code(404);echo json_encode(['success'=>false,'message'=>'Academic record not found.']);exit;}
registrarRequireStudent((int)$record['student_id']);
$status=(string)($input['status']??'');$remarks=trim((string)($input['remarks']??''));
if (!in_array($status,['reviewed','needs_correction','incomplete'],true) || strlen($remarks)>4000 || ($status!=='reviewed'&&$remarks==='')) {
    http_response_code(422);echo json_encode(['success'=>false,'message'=>'Choose a review result and include remarks for corrections or incomplete records (maximum 4,000 bytes).']);exit;
}
$pdo=$db->getConnection();$pdo->beginTransaction();
try {
    $id=$db->insert('academic_validations',['academic_history_id'=>$record['id'],'reviewer_id'=>getCurrentUserId(),'review_status'=>$status,'remarks'=>$remarks,'created_at'=>date('Y-m-d H:i:s')]);
    logActivity(getCurrentUserId(),'academic_validation',null,'academic_validations',$id,null,['record_id'=>$record['id'],'review_status'=>$status,'remarks'=>$remarks]);
    $pdo->commit();echo json_encode(['success'=>true,'message'=>'Validation recorded. Grades and registrar acceptance remain unchanged.']);
} catch (Throwable $e) {$pdo->rollBack();http_response_code(500);echo json_encode(['success'=>false,'message'=>'Validation could not be saved.']);}
