<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Your session expired. Sign in again.']); exit; }
if (!in_array($_SESSION['role'] ?? '', ['super_admin','registrar_admin','staff'], true)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
session_write_close();
require_once __DIR__ . '/../shared/dashboard_data.php';
try { echo json_encode(['success'=>true,'data'=>registrarDashboardData()], JSON_THROW_ON_ERROR); }
catch (Throwable $e) { error_log('[dashboard] '.$e->getMessage()); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Dashboard data is temporarily unavailable.']); }
