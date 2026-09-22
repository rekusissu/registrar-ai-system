<?php
// ============================================================
//  SHARED/QR_GENERATOR.PHP
//  Shared QR code generation for student IDs.
//  Used by api/rfid.php and api/student-ids.php.
//  QR payload = verification URL (not JSON) for phone scanning.
// ============================================================

/**
 * Generate a QR code SVG pointing to the student verification page.
 *
 * @param int $studentId The student's DB id (primary key)
 * @return string|null   Web-relative path (e.g. '../uploads/ids/xyz.svg') or null on failure
 */
function generateStudentQrFile(int $studentId): ?string {
    $dir = __DIR__ . '/../uploads/ids/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $filename = 'id_' . $studentId . '_' . time() . '.svg';
    $path = $dir . $filename;

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('QR generation skipped: vendor/autoload.php missing (run composer install).');
        return null;
    }

    try {
        require_once $autoload;
        $opts = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'outputBase64'    => false,
            'eccLevel'        => 0, // ECC_M
            'scale'           => 6,
        ]);
        $qrcode = new \chillerlan\QRCode\QRCode($opts);
        $verifyUrl = 'https://registrar.bcpsms2.com/verify-student.php?student_id=' . intval($studentId);
        $svg = $qrcode->render($verifyUrl);
        if (file_put_contents($path, $svg) === false) {
            error_log('QR generation failed: could not write ' . $path);
            return null;
        }
        return '../uploads/ids/' . $filename;
    } catch (\Throwable $e) {
        error_log('QR generation failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Auto-generate next ID number: YYYY-XXXX format.
 *
 * @param Database $db
 * @return string  e.g. "2026-0001"
 */
function generateNextIdNumber(Database $db): string {
    $year = date('Y');
    $last = $db->fetchColumn(
        "SELECT id_number FROM student_ids WHERE id_number LIKE ? ORDER BY id DESC LIMIT 1",
        [$year . '%']
    );
    $num = $last ? intval(substr($last, -4)) + 1 : 1;
    return $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}
