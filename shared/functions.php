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
 * Generate a strong human-readable password (letters + digits).
 */
function generateStrongPassword($length = 10) {
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $lower . $upper . $digits;
    $chars = [];
    // Ensure at least one of each class, then fill randomly.
    $chars[] = $lower[random_int(0, strlen($lower) - 1)];
    $chars[] = $upper[random_int(0, strlen($upper) - 1)];
    $chars[] = $digits[random_int(0, strlen($digits) - 1)];
    for ($i = 3; $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
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

/**
 * Semester digit for a semester label ('' → 1, '1st' → 1, '2nd' → 2, 'summer' → 3).
 */
function semesterDigit(?string $semester): int {
    $semester = strtolower(trim((string) $semester));
    if ($semester === '2nd') return 2;
    if ($semester === 'summer') return 3;
    return 1; // '' or '1st'
}

/**
 * Section code for a year/semester/section-number combo, e.g. 11001 =
 * year 1, semester 1, section 1. Format: [year][sem][###] (5 digits).
 */
function sectionCodeFromParts(int $year, ?string $semester, int $sectionNumber): string {
    $yearDigit = max(1, min(9, $year));
    $semDigit = semesterDigit($semester);
    $num = max(1, min(999, $sectionNumber));
    return $yearDigit . $semDigit . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
}

/**
 * Next section number for a course+year+semester, mirroring autoAssignStudentSections.
 * Scoped by course + year + semester only (the code has no SY digit), consistent with
 * auto-assign's bucket key. Manual codes that aren't 5-digit numeric are ignored.
 */
function nextSectionNumber(string $course, int $year, ?string $semester): int {
    $db = Database::getInstance();
    $prefix = substr(sectionCodeFromParts($year, $semester, 1), 0, 2); // e.g. "11"
    $rows = $db->fetchAll(
        "SELECT DISTINCT section FROM students
         WHERE TRIM(course) = ? AND year_level = ?
           AND TRIM(IFNULL(semester, '')) = ?
           AND section LIKE ? AND section REGEXP '^[0-9]{5}$'",
        [$course, $year, (string) $semester, $prefix . '%']
    );
    $max = 0;
    foreach ($rows as $r) {
        $num = (int) substr(trim((string) $r['section']), 2);
        if ($num > $max) $max = $num;
    }
    return $max + 1;
}

/**
 * True if a student already exists in this course+year+semester+section.
 * Same code across different courses is allowed (matches auto-assign).
 */
function sectionExists(string $course, int $year, ?string $semester, string $section): bool {
    $db = Database::getInstance();
    $found = $db->fetchOne(
        "SELECT id FROM students
         WHERE TRIM(course) = ? AND year_level = ?
           AND TRIM(IFNULL(semester, '')) = ? AND TRIM(section) = ?
         LIMIT 1",
        [$course, $year, (string) $semester, $section]
    );
    return (bool) $found;
}

/**
 * Offered BCP courses with their majors. Single source of truth shared by
 * the Students page and the Masterlist section-creation flow.
 */
function getOfferedCourses(): array {
    return [
        'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)' => [],
        'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)' => [],
        'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)' => [],
        'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)' => [],
        'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)' => [],
        'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP (BSENTREP)' => [],
        'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)' => [
            'Human Resource Management',
            'Marketing Management',
        ],
        'BACHELOR OF LIBRARY INFORMATION SCIENCE (BLIS)' => [],
        'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)' => [],
        'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)' => [],
        'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)' => [],
        'BACHELOR OF SCIENCE IN PHYSICAL EDUCATION (BPED)' => [],
        'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)' => [],
        'BACHELOR OF SCIENCE IN ELEMENTARY EDUCATION (BEED)' => [],
        'BACHELOR OF SCIENCE IN SECONDARY EDUCATION (BSED)' => [
            'English',
            'Social Studies',
            'Filipino',
            'Values Education',
            'Mathematics',
            'Science',
        ],
    ];
}

/**
 * Assign sections automatically: group by course + year + semester,
 * max N students per section. Section codes follow [year][sem][###],
 * e.g. 11001 (yr 1, sem 1, section 1), 12001 (yr 1, sem 2), 21001 (yr 2, sem 1).
 *
 * @return array{updated: int, sections: list<array{course: ?string, year_level: ?int, semester: ?string, section: string, count: int, max: int}>}
 */
function autoAssignStudentSections(?int $maxPerSection = null): array {
    $maxPerSection = $maxPerSection ?? (defined('MAX_STUDENTS_PER_SECTION') ? (int) MAX_STUDENTS_PER_SECTION : 50);
    if ($maxPerSection < 1) {
        $maxPerSection = 50;
    }

    $db = Database::getInstance();
    $students = $db->fetchAll(
        "SELECT id, course, year_level, semester, section, last_name, first_name
         FROM students
         WHERE course IS NOT NULL AND TRIM(course) != ''
         ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, last_name ASC, first_name ASC, id ASC"
    );

    $buckets = [];
    foreach ($students as $row) {
        $course = trim((string) $row['course']);
        $year = $row['year_level'] !== null && $row['year_level'] !== '' ? (int) $row['year_level'] : 0;
        $semester = ($row['semester'] ?? '') !== '' ? (string) $row['semester'] : null;
        $key = $course . "\0" . $year . "\0" . ($semester ?? '');
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'course' => $course,
                'year_level' => $year,
                'semester' => $semester,
                'ids' => [],
            ];
        }
        $buckets[$key]['ids'][] = [
            'id' => (int) $row['id'],
            'section' => trim((string) ($row['section'] ?? '')),
        ];
    }

    $updated = 0;
    $sections = [];
    $conn = $db->getConnection();
    $conn->beginTransaction();

    try {
        foreach ($buckets as $bucket) {
            $course = $bucket['course'];
            $year = $bucket['year_level'];
            $semester = $bucket['semester'];

            // Split this bucket's students into those already in a section vs unassigned
            $unassigned = [];
            foreach ($bucket['ids'] as $s) {
                if ($s['section'] === '') $unassigned[] = $s['id'];
            }

            // Existing section codes for this course+year+semester (5-digit only)
            $existingRows = $db->fetchAll(
                "SELECT TRIM(section) AS section, COUNT(*) AS cnt
                 FROM students
                 WHERE TRIM(course) = ? AND year_level = ?
                   AND TRIM(IFNULL(semester, '')) = ?
                   AND section REGEXP '^[0-9]{5}$'
                 GROUP BY TRIM(section)
                 ORDER BY section",
                [$course, $year, (string) $semester]
            );

            $existingCodes = [];
            foreach ($existingRows as $r) {
                $existingCodes[trim($r['section'])] = (int) $r['cnt'];
            }

            // 1) Fill existing sections first (only unassigned students)
            foreach ($existingCodes as $code => $cnt) {
                if (empty($unassigned)) break;
                $slots = $maxPerSection - $cnt;
                if ($slots <= 0) continue;
                $toPlace = array_splice($unassigned, 0, $slots);
                foreach ($toPlace as $sid) {
                    $db->update('students', ['section' => $code], 'id = ?', [$sid]);
                    $updated++;
                }
                $existingCodes[$code] += count($toPlace);
            }

            // 2) Create new sections only for students still unassigned
            $nextNumber = 1;
            while (!empty($unassigned)) {
                // Pick the next free code (skip codes already used)
                while (isset($existingCodes[sectionCodeFromParts($year, $semester, $nextNumber)])) {
                    $nextNumber++;
                }
                $code = sectionCodeFromParts($year, $semester, $nextNumber);
                $toPlace = array_splice($unassigned, 0, $maxPerSection);
                foreach ($toPlace as $sid) {
                    $db->update('students', ['section' => $code], 'id = ?', [$sid]);
                    $updated++;
                }
                $existingCodes[$code] = count($toPlace);
                $sections[] = [
                    'course' => $course,
                    'year_level' => $year > 0 ? $year : null,
                    'semester' => $semester,
                    'section' => $code,
                    'count' => count($toPlace),
                    'max' => $maxPerSection,
                ];
            }

            // Record the filled existing sections in the result
            foreach ($existingCodes as $code => $cnt) {
                // Skip codes we already added as newly-created (avoid duplicates in the report)
                $isNew = false;
                foreach ($sections as $s) {
                    if ($s['section'] === $code) { $isNew = true; break; }
                }
                if (!$isNew) {
                    $sections[] = [
                        'course' => $course,
                        'year_level' => $year > 0 ? $year : null,
                        'semester' => $semester,
                        'section' => $code,
                        'count' => $cnt,
                        'max' => $maxPerSection,
                    ];
                }
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    return ['updated' => $updated, 'sections' => $sections];
}

/**
 * Group student rows for masterlist display (course → year → section).
 *
 * @param array<int, array<string, mixed>> $students
 * @return list<array{course: string, year_level: string, section: string, students: array<int, array<string, mixed>>}>
 */
function groupStudentsForMasterlist(array $students): array {
    $groups = [];

    foreach ($students as $student) {
        $courseRaw = trim((string) ($student['course'] ?? ''));
        $course = $courseRaw !== '' ? $courseRaw : 'No Course';
        $year = $student['year_level'];
        $yearLabel = ($year !== null && $year !== '') ? (string) (int) $year : 'N/A';
        $sectionRaw = trim((string) ($student['section'] ?? ''));
        $section = $sectionRaw !== '' ? $sectionRaw : '—';
        $key = $course . "\0" . $yearLabel . "\0" . $section;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'course' => $course,
                'year_level' => $yearLabel,
                'section' => $section,
                'students' => [],
            ];
        }
        $groups[$key]['students'][] = $student;
    }

    $list = array_values($groups);
    usort($list, static function (array $a, array $b): int {
        return [$a['course'], $a['year_level'], $a['section']]
            <=> [$b['course'], $b['year_level'], $b['section']];
    });

    return $list;
}

/**
 * Whether a masterlist group has a real assigned section (not placeholder).
 */
function masterlistGroupHasSection(array $group): bool {
    $section = trim((string) ($group['section'] ?? ''));
    return $section !== '' && $section !== '—';
}

/**
 * Keep only groups that belong to an assigned section.
 *
 * @param list<array<string, mixed>> $groups
 * @return list<array<string, mixed>>
 */
function filterAssignedMasterlistGroups(array $groups): array {
    return array_values(array_filter($groups, 'masterlistGroupHasSection'));
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

/**
 * Record a student status change into the status_tracker table.
 * Used whenever a student's status changes (quick dropdown, bulk update,
 * restore). Keeps the dashboard "Recent Activity" and AI insights fed.
 */
function trackStatusChange($studentId, $newStatus, $reason = null) {
    try {
        $db = Database::getInstance();
        $old = $db->fetchOne("SELECT status FROM students WHERE id = ?", [intval($studentId)]);
        $oldStatus = $old['status'] ?? null;
        if ($oldStatus === $newStatus) {
            return false; // nothing changed
        }
        $db->insert('status_tracker', [
            'student_id'      => intval($studentId),
            'previous_status' => $oldStatus,
            'current_status'  => $newStatus,
            'reason'          => $reason,
            'changed_by'      => $_SESSION['user_id'] ?? null,
            'created_at'      => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        // Silent fail — never block the status update because logging broke.
        error_log('trackStatusChange failed: ' . $e->getMessage());
        return false;
    }
}
?>