<?php
require_once __DIR__.'/../shared/registrar_access.php';
require_once __DIR__.'/../shared/csrf_guard.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(!isLoggedIn()||!in_array(getCurrentUserRole(),['super_admin','registrar_admin','staff'],true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Registrar access required.']);exit;}
$db=Database::getInstance();$input=json_decode(file_get_contents('php://input'),true)?:[];
$sid=(int)($_GET['student_id']??$input['student_id']??0);registrarRequireStudent($sid);
if($_SERVER['REQUEST_METHOD']==='GET'){
 $contacts=$db->fetchAll("SELECT id,full_name,relationship,contact_number,email,'guardian' AS type FROM guardians WHERE student_id=? UNION ALL SELECT id,full_name,relationship,contact_number,NULL AS email,'emergency' AS type FROM emergency_contacts WHERE student_id=?",[$sid,$sid]);
 $history=$db->fetchAll('SELECT a.*,u.full_name AS author FROM guardian_contact_attempts a LEFT JOIN users u ON u.id=a.recorded_by WHERE a.student_id=? ORDER BY a.id DESC LIMIT 100',[$sid]);
 echo json_encode(['success'=>true,'contacts'=>$contacts,'history'=>$history]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$type=$input['contact_type']??'';$channel=$input['channel']??'';$outcome=$input['outcome']??'';$occasion=trim((string)($input['occasion']??''));$notes=trim((string)($input['notes']??''));
$valid=in_array($type,['guardian','emergency'],true)&&in_array($channel,['call','email'],true)&&in_array($outcome,$channel==='email'?['email_sent','failed']:['reached','no_answer','left_message','failed'],true)&&$occasion!==''&&strlen($occasion)<=100&&strlen($notes)<=4000;
if(!$valid){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Complete the contact, occasion, channel, and matching outcome.']);exit;}
$table=$type==='guardian'?'guardians':'emergency_contacts';
$contact=$db->fetchOne("SELECT * FROM $table WHERE id=? AND student_id=?",[(int)($input['contact_id']??0),$sid]);
if(!$contact||($channel==='email'&&empty($contact['email']))){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Contact or email address unavailable.']);exit;}
$db->insert('guardian_contact_attempts',['student_id'=>$sid,'contact_type'=>$type,'contact_id'=>$contact['id'],'contact_name'=>$contact['full_name'],'channel'=>$channel,'occasion'=>$occasion,'outcome'=>$outcome,'notes'=>$notes,'recorded_by'=>getCurrentUserId(),'created_at'=>date('Y-m-d H:i:s')]);
echo json_encode(['success'=>true,'message'=>'Contact attempt recorded.']);
