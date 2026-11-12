<?php
require_once __DIR__.'/../shared/registrar_access.php';
require_once __DIR__.'/../shared/csrf_guard.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(!isLoggedIn()||!in_array(getCurrentUserRole(),['super_admin','registrar_admin'],true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Administrator access required.']);exit;}
$db=Database::getInstance();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $users=$db->fetchAll("SELECT id,username,full_name,role FROM users WHERE role IN ('staff','acad_officer','department_head')".(getCurrentUserRole()==='registrar_admin'?" AND role='staff'":"")." ORDER BY full_name");
 foreach($users as &$u)$u['programs']=registrarAssignedPrograms((int)$u['id']);unset($u);
 echo json_encode(['success'=>true,'users'=>$users,'programs'=>registrarProgramCatalog()]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$input=json_decode(file_get_contents('php://input'),true)?:[];$id=(int)($input['user_id']??0);
$target=$db->fetchOne('SELECT id,role FROM users WHERE id=?',[$id]);
if(!$target||!in_array($target['role'],['staff','acad_officer','department_head'],true)||(getCurrentUserRole()==='registrar_admin'&&$target['role']!=='staff')){http_response_code(403);echo json_encode(['success'=>false,'message'=>'This account cannot be assigned by the current administrator.']);exit;}
$programs=array_values(array_unique(array_map('strtoupper',(array)($input['programs']??[]))));$catalog=registrarProgramCatalog();
foreach($programs as $code)if(!isset($catalog[$code])){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Unknown program.']);exit;}
$pdo=$db->getConnection();$pdo->beginTransaction();
try{$before=registrarAssignedPrograms($id);$db->query('DELETE FROM user_program_assignments WHERE user_id=?',[$id]);foreach($programs as $code)$db->insert('user_program_assignments',['user_id'=>$id,'program_code'=>$code,'assigned_by'=>getCurrentUserId(),'assigned_at'=>date('Y-m-d H:i:s')]);logActivity(getCurrentUserId(),'program_assignments_updated',null,'users',$id,['programs'=>$before],['programs'=>$programs]);$pdo->commit();echo json_encode(['success'=>true]);}catch(Throwable $e){$pdo->rollBack();http_response_code(500);echo json_encode(['success'=>false,'message'=>'Assignments could not be saved.']);}
