<?php
require_once __DIR__.'/../shared/security_headers.php';
require_once __DIR__.'/../shared/academic_dashboard_data.php';
header('Content-Type: application/json');header('Cache-Control: no-store, private');
if(!isLoggedIn()){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Sign in again.']);exit;}
if(!in_array(getCurrentUserRole(),['super_admin','registrar_admin','staff','acad_officer','department_head'],true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Academic workspace access required.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
try{echo json_encode(['success'=>true,'data'=>academicDashboardData($_GET)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
catch(Throwable $e){error_log('[academic-dashboard] '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Academic workspace is temporarily unavailable.']);}
