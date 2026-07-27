<?php
// ============================================================
//  FUNCTIONS.PHP  (shared/)
//  Global helper functions used across the application.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// ── Prevent direct access ──
if (defined('FUNCTIONS_LOADED')) {
    return;
}
define('FUNCTIONS_LOADED', true);

// ─── STRING HELPERS ────────────────────────────────────────────

/**
 * Truncate a string to a specified length
 */
function truncate($string, $length = 50, $suffix = '...') {
    if (strlen($string) <= $length) {
        return $string;
    }
    return substr($string, 0, $length) . $suffix;
}

/**
 * Generate a random string
 */
function randomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a slug from a string
 */
function slugify($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Format a phone number
 */
function formatPhoneNumber($number) {
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) === 11) {
        return substr($number, 0, 4) . '-' . substr($number, 4, 3) . '-' . substr($number, 7, 4);
    }
    return $number;
}

/**
 * Format a date
 */
function formatDate($date, $format = 'M d, Y') {
    if (!$date || $date === '0000-00-00') {
        return '—';
    }
    return date($format, strtotime($date));
}

/**
 * Format a time
 */
function formatTime($time, $format = 'h:i A') {
    if (!$time) {
        return '—';
    }
    return date($format, strtotime($time));
}

/**
 * Format a datetime
 */
function formatDateTime($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime) {
        return '—';
    }
    return date($format, strtotime($datetime));
}

/**
 * Calculate days between two dates
 */
function daysBetween($date1, $date2 = null) {
    $date2 = $date2 ?? date('Y-m-d');
    $diff = strtotime($date2) - strtotime($date1);
    return floor($diff / (60 * 60 * 24));
}

// ─── VALIDATION HELPERS ────────────────────────────────────────

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Philippines format)
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^(09|\\+63)[0-9]{9,10}$/', $phone);
}

/**
 * Validate password strength
 */
function isValidPassword($password) {
    return strlen($password) >= PASSWORD_MIN_LENGTH;
}

// ─── FILE HELPERS ──────────────────────────────────────────────

/**
 * Get file extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Get file size formatted
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Generate a unique filename
 */
function generateFilename($originalName) {
    $ext = getFileExtension($originalName);
    return date('Ymd_His') . '_' . randomString(8) . '.' . $ext;
}

/**
 * Check if file is allowed
 */
function isAllowedFile($filename) {
    $ext = getFileExtension($filename);
    return in_array($ext, ALLOWED_FILE_EXTENSIONS);
}

/**
 * Get file mime type
 */
function getFileMime($path) {
    return mime_content_type($path);
}

// ─── LOGGING HELPERS ───────────────────────────────────────────

/**
 * Log user activity
 */
function logActivity($userId, $action, $details = null) {
    try {
        $db = Database::getInstance();
        $db->insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // Silent fail - log to error log
        error_log('Failed to log activity: ' . $e->getMessage());
    }
}

/**
 * Log an error
 */
function logError($message) {
    $logFile = LOGS_PATH . 'error.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Log API request
 */
function logApiRequest($endpoint, $request, $response) {
    $logFile = LOGS_PATH . 'api.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' .
             'Endpoint: ' . $endpoint . PHP_EOL .
             'Request: ' . json_encode($request) . PHP_EOL .
             'Response: ' . json_encode($response) . PHP_EOL .
             '---' . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// ─── RESPONSE HELPERS ──────────────────────────────────────────

/**
 * Send JSON response
 */
function jsonResponse($success, $message = '', $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Send success response
 */
function successResponse($data = null, $message = 'Success') {
    jsonResponse(true, $message, $data);
}

/**
 * Send error response
 */
function errorResponse($message = 'Error', $code = 400) {
    jsonResponse(false, $message, null, $code);
}

// ─── SECURITY HELPERS ──────────────────────────────────────────

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = randomString(CSRF_TOKEN_LENGTH);
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Encrypt data
 */
function encryptData($data, $key = null) {
    $key = $key ?? JWT_SECRET;
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data
 */
function decryptData($encrypted, $key = null) {
    $key = $key ?? JWT_SECRET;
    $data = base64_decode($encrypted);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}

// ─── STUDENT HELPERS ────────────────────────────────────────────

/**
 * Generate student number
 */
function generateStudentNumber() {
    $year = date('Y');
    $db = Database::getInstance();
    $last = $db->fetchColumn(
        "SELECT student_number FROM students WHERE student_number LIKE ? ORDER BY id DESC LIMIT 1",
        [$year . '%']
    );
    
    if ($last) {
        $num = intval(substr($last, -4)) + 1;
        return $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
    return $year . '-0001';
}

/**
 * Get student full name
 */
function getStudentFullName($student) {
    $parts = [];
    if (!empty($student['first_name'])) {
        $parts[] = $student['first_name'];
    }
    if (!empty($student['middle_name'])) {
        $parts[] = $student['middle_name'];
    }
    if (!empty($student['last_name'])) {
        $parts[] = $student['last_name'];
    }
    return implode(' ', $parts);
}

/**
 * Get student initials
 */
function getStudentInitials($student) {
    $name = getStudentFullName($student);
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

// ─── STATUS HELPERS ────────────────────────────────────────────

/**
 * Get status badge class
 */
function getStatusBadgeClass($status) {
    $classes = [
        'active' => 'active',
        'inactive' => 'inactive',
        'pending' => 'pending',
        'approved' => 'approved',
        'denied' => 'denied',
        'completed' => 'completed',
        'at-risk' => 'at-risk',
        'probation' => 'probation',
        'graduated' => 'graduated',
        'dropped' => 'dropped',
        'transferred' => 'transferred',
        'loa' => 'loa'
    ];
    return $classes[$status] ?? 'default';
}

/**
 * Get status label
 */
function getStatusLabel($status) {
    $labels = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'completed' => 'Completed',
        'at-risk' => 'At Risk',
        'probation' => 'Probation',
        'graduated' => 'Graduated',
        'dropped' => 'Dropped',
        'transferred' => 'Transferred',
        'loa' => 'LOA'
    ];
    return $labels[$status] ?? ucfirst($status);
}
?>