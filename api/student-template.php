<?php
// ============================================================
//  API/STUDENT-TEMPLATE.PHP
//  Download the fillable Word (.docx) enrolment template that
//  students fill out and registrars upload via the AI Document
//  Reader.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized.');
}

require_once __DIR__ . '/../shared/student_template.php';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="Student_Enrolment_Form.docx"');
header('Content-Length: ' . strlen(buildStudentTemplateDocx()));
header('Cache-Control: no-store');

echo buildStudentTemplateDocx();
exit;
