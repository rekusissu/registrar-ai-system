<?php
// ============================================================
//  API/STUDENT-UPLOAD-PHOTO.PHP
//  Handle student profile photo uploads
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

try {
    // Only POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Guard: student role only
    if (($_SESSION['role'] ?? '') !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized - not a student']);
        exit;
    }

    // Check if file was uploaded
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $err = $_FILES['photo']['error'] ?? 'unknown';
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error: ' . $err]);
        exit;
    }

    $file = $_FILES['photo'];

    // Initialize database first
    $db = Database::getInstance();
    $studentId = getCurrentStudentId();

    if (!$studentId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Student ID not found']);
        exit;
    }

    // Validate file type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mimeType])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type: ' . $mimeType . '. Only JPEG, PNG, GIF, and WebP are allowed']);
        exit;
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
        exit;
    }

    // Create uploads directory if it doesn't exist
    $uploadsDir = __DIR__ . '/../assets/uploads/students';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create uploads directory']);
            exit;
        }
    }

    // Generate unique filename.
    // The extension comes from the MIME type we just validated, never from
    // $file['name'] — a caller-supplied ".php" on a polyglot image would
    // otherwise land an executable file under the web root.
    $ext = $allowedMimes[$mimeType];
    $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    $relativePath = './assets/uploads/students/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    // Update database
    $db->update(
        'students',
        ['photo' => $relativePath],
        'id = ?',
        [$studentId]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Photo updated successfully',
        'data' => ['photoUrl' => $relativePath]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
