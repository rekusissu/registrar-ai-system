<?php
require_once __DIR__.'/../shared/security_headers.php';
require_once __DIR__.'/../shared/session_config.php';
require_once __DIR__.'/../shared/csrf_guard.php';
require_once __DIR__.'/../shared/report_export_security.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function exportReply(int $status,array $data): void { http_response_code($status); echo json_encode($data); exit; }
if (!isLoggedIn()) exportReply(401,['success'=>false,'message'=>'Sign in again.']);
if (!reportExportRole()) exportReply(403,['success'=>false,'message'=>'Report export is not allowed for this account.']);
$db=Database::getInstance();
$action=(string)($_GET['action'] ?? 'authorize');
if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='history') {
    $admin=in_array(getCurrentUserRole(),['super_admin','registrar_admin'],true);
    $rows=$db->fetchAll('SELECT id,user_id,actor_name,actor_role,report_name,format,resource,status,ip_address,created_at,initiated_at FROM report_exports '.($admin?'':'WHERE user_id=? ').'ORDER BY id DESC LIMIT 200',$admin?[]:[getCurrentUserId()]);
    exportReply(200,['success'=>true,'data'=>$rows]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') exportReply(405,['success'=>false,'message'=>'Use POST.']);
$input=json_decode(file_get_contents('php://input'),true) ?: [];
if ($action==='consume') {
    $ok=consumeReportExport((string)($input['token']??''),(string)($input['resource']??''));
    exportReply($ok?200:403,['success'=>$ok,'message'=>$ok?'Export initiated.':'Confirmation expired or already used.']);
}
if ($action!=='authorize') exportReply(400,['success'=>false,'message'=>'Unknown export action.']);
$user=$db->fetchOne('SELECT full_name,role,password_hash,is_active FROM users WHERE id=?',[getCurrentUserId()]);
if (!$user || !$user['is_active'] || !in_array($user['role'],['super_admin','registrar_admin','staff','acad_officer','department_head'],true)) exportReply(403,['success'=>false,'message'=>'Account is not permitted to export.']);
$since=date('Y-m-d H:i:s',time()-300);
if ((int)$db->fetchColumn("SELECT COUNT(*) FROM report_exports WHERE user_id=? AND status='denied' AND created_at>?",[getCurrentUserId(),$since])>=5) exportReply(429,['success'=>false,'message'=>'Too many incorrect passwords. Try again in five minutes.']);
$report=mb_substr(trim((string)($input['report']??'Report')),0,160);
$format=preg_replace('/[^a-z0-9_-]/i','',substr((string)($input['format']??'file'),0,20));
$resource=substr((string)($input['resource']??'client-report'),0,2000);
$server=isset($input['server_url']) && $input['server_url']!=='';
if ($server) {
    $parts=parse_url((string)$input['server_url']);
    if (isset($parts['scheme']) || isset($parts['host']) || !str_ends_with((string)($parts['path']??''),'.php')) exportReply(400,['success'=>false,'message'=>'Invalid download target.']);
    $allowed=['masterlist-folders.php','file-download.php','document-preview.php','student-template.php','generate-document-pdf.php'];
    if (!in_array(basename($parts['path']),$allowed,true)) exportReply(400,['success'=>false,'message'=>'Unsupported report target.']);
    parse_str($parts['query']??'',$query);$resource=reportExportResource($parts['path'],$query);
}
$verified=password_verify((string)($input['password']??''),$user['password_hash']);
$token=$verified?bin2hex(random_bytes(32)):null;
$id=$db->insert('report_exports',[
 'user_id'=>getCurrentUserId(),'actor_name'=>$user['full_name'],'actor_role'=>$user['role'],
 'report_name'=>$report?:'Report','format'=>$format?:'file','resource'=>$resource,
 'status'=>$verified?'authorized':'denied','token_hash'=>$token?hash('sha256',$token):null,
 'session_hash'=>$token?hash('sha256',session_id()):null,'ip_address'=>$_SERVER['REMOTE_ADDR']??'',
 'created_at'=>date('Y-m-d H:i:s'),'expires_at'=>$token?date('Y-m-d H:i:s',time()+120):null
]);
if (!$verified) exportReply(403,['success'=>false,'message'=>'Your account password is incorrect.']);
exportReply(200,['success'=>true,'token'=>$token,'resource'=>$resource,'audit_id'=>$id]);
