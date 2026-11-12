<?php
// Authenticated photo display; upload directories remain private.
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (!isLoggedIn()) { http_response_code(401); exit; }
if (!in_array($_SESSION['role'] ?? '', ['super_admin','registrar_admin','staff'], true)) { http_response_code(403); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit; }
$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) { http_response_code(400); exit; }
session_write_close();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/stored_file.php';
try {
    $documentId=(int)($_GET['document_id']??0);
    if($documentId>0){
        // Image previews retain the student ownership and program-scope checks.
        $row=Database::getInstance()->fetchOne('SELECT d.file_path AS photo_path FROM documents d JOIN students s ON s.id=d.student_id WHERE d.id=? AND s.id=?',[$documentId,$studentId]);
        $path=$row ? storedFileDiskPath($row['photo_path']) : null;
    }else{
    $row = Database::getInstance()->fetchOne('SELECT s.photo,'.studentPhotoSelectSql().' AS photo_path FROM students s WHERE s.id = ?', [$studentId]);
    $path = $row ? (storedFileDiskPath($row['photo_path']) ?: storedFileDiskPath($row['photo'])) : null;
    }
    $real = $path ? realpath($path) : false;
    $root = realpath(dirname(__DIR__));
    if (!$real || !$root || strncmp(strtolower(str_replace('\\','/',$real)), strtolower(str_replace('\\','/',$root)).'/', strlen($root)+1) !== 0) { http_response_code(404); exit; }
    $image = @getimagesize($real);
    $mime = $image['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) { http_response_code(415); exit; }
    header('Content-Type: '.$mime);
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: '.filesize($real));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($real);
} catch (Throwable $e) { error_log('[student-photo] '.$e->getMessage()); http_response_code(500); }
