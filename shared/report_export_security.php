<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session_config.php';

function reportExportRole(): bool {
    return in_array(getCurrentUserRole(), ['super_admin','registrar_admin','staff','acad_officer','department_head'], true);
}
function reportExportResource(string $path, array $query): string {
    unset($query['export_grant']);
    ksort($query);
    return basename($path) . ($query ? '?' . http_build_query($query) : '');
}
/** A grant is session-bound, resource-bound, short-lived, and consumed exactly once. */
function consumeReportExport(string $token, string $resource): bool {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $db = Database::getInstance();
    return $db->query("UPDATE report_exports SET status='initiated', initiated_at=NOW()
       WHERE token_hash=? AND session_hash=? AND user_id=? AND resource=?
         AND status='authorized' AND expires_at>NOW()",
       [hash('sha256',$token),hash('sha256',session_id()),getCurrentUserId(),$resource])->rowCount() === 1;
}
function requireReportExport(): void {
    if (!reportExportRole()) return; // Existing endpoint authorization still applies.
    $resource=reportExportResource($_SERVER['SCRIPT_NAME'] ?? '', $_GET);
    $token=(string)($_SERVER['HTTP_X_EXPORT_GRANT'] ?? $_GET['export_grant'] ?? '');
    if (!consumeReportExport($token,$resource)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success'=>false,'code'=>'export_password_required','message'=>'Confirm your account password before downloading this file.']);
        exit;
    }
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}
