<?php
require_once __DIR__.'/../shared/security_headers.php';
require_once __DIR__.'/../shared/academic_dashboard_data.php';
require_once __DIR__.'/../shared/academic_analytics.php';
require_once __DIR__.'/../shared/csrf_guard.php';
header('Content-Type: application/json');header('Cache-Control: no-store, private');
if(!isLoggedIn()){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Sign in again.']);exit;}
if(!in_array(getCurrentUserRole(),['super_admin','registrar_admin','staff','acad_officer','department_head'],true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Academic workspace access required.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$input=json_decode(file_get_contents('php://input'),true);
if(!is_array($input)||!is_string($input['school_year']??'')||!is_string($input['semester']??'')||!in_array($input['mode']??'local',['local','ai'],true)||strlen((string)($input['school_year']??''))>20||!in_array($input['semester']??'', ['','1st','2nd','summer','T1','T2','T3'],true)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Select a valid academic period.']);exit;}
try {
    $snapshot=academicDashboardData($input);$report=academicAnalytics($snapshot);$report['source']='local_analysis';
    if(($input['mode']??'')==='ai'&&$report['counts']['students']>0){
        require_once __DIR__.'/../shared/ai_client.php';
        $text=aiGenerate('Write a concise registrar academic record summary from aggregate counts only. State observations then practical recommendations. Period counts reflect the currently authorized roster, not historical enrollment totals. Do not invent trends, causes, grading rules, identities or submissions. Received records do not prove curriculum completeness. External integrations are disabled. Plain text only, maximum 250 words.',json_encode(['counts'=>$report['counts'],'periods'=>$report['periods']]),['max_tokens'=>500,'temperature'=>0.1]);
        if(trim($text)!==''){$report['summary']=mb_substr(trim($text),0,6000);$report['source']='configured_ai';}
        else $report['notice']='AI is unavailable. Showing the verified local analysis.';
    }
    $report['school_year']=$snapshot['school_year'];$report['semester']=$snapshot['semester'];$report['generated_at']=date(DATE_ATOM);
    echo json_encode(['success'=>true,'data'=>$report],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log('[academic-summary] '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Academic summary is temporarily unavailable.']);}
