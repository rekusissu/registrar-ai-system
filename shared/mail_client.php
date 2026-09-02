<?php
// ============================================================
//  SHARED/MAIL_CLIENT.PHP
//  Emergency & Contacts module — email transport + senders.
//
//  Depends on PHPMailer (composer) and the SMTP_* config block in
//  shared/config.php (Gmail App Password via shared/email_secret.local).
//
//  Every sender writes a communication_log row (the Registrar audit
//  trail) and touches contact_recipients.last_emailed. When SMTP is
//  not configured (EMAIL_CONFIGURED === false) every sender no-ops and
//  returns sent=false — the app keeps working, exactly like the
//  PayMongo mock fallback. A mail failure never throws: senders
//  return the outcome and log it.
// ============================================================

if (defined('MAIL_CLIENT_LOADED')) {
    return;
}
define('MAIL_CLIENT_LOADED', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/document_pdf.php'; // buildInvoicePdf / buildTranscriptPdf
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/** True when SMTP creds are present — flips the module on. */
function emailConfigured(): bool {
    return defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED;
}

/**
 * Low-level SMTP send via PHPMailer. HTML body, UTF-8. One optional
 * attachment: ['data' => string, 'name' => string, 'mime' => string|null].
 * Returns true on successful send; failure is error_logged.
 */
function sendEmail(array $to, string $subject, string $htmlBody, ?array $attachment = null): bool {
    if (!emailConfigured()) {
        error_log('mail: EMAIL_CONFIGURED is false — refusing to send to ' . ($to['email'] ?? ''));
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 30;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM !== '' ? MAIL_FROM : SMTP_USER, MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'BCP Registrar System');
        $mail->addAddress($to['email'], $to['name'] ?? '');
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        if (is_array($attachment) && isset($attachment['data'])) {
            $mail->addStringAttachment(
                $attachment['data'],
                $attachment['name'] ?? 'attachment.pdf',
                'base64',
                $attachment['mime'] ?? 'application/pdf'
            );
        }

        return $mail->send();
    } catch (PHPMailerException $e) {
        error_log('mail: PHPMailer send failed → ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('mail: unexpected send failure → ' . $e->getMessage());
        return false;
    }
}

/**
 * Write a communication_log row. $fields: student_id, contact_id,
 * recipient_email, recipient_name, message_type, subject, status, ref,
 * detail, sent_by. created_at is always PHP wall-clock (Asia/Manila).
 */
function contactLog(array $fields): int {
    $data = [
        'student_id'      => (int) ($fields['student_id'] ?? 0),
        'contact_id'      => isset($fields['contact_id']) && $fields['contact_id'] !== null ? (int) $fields['contact_id'] : null,
        'recipient_email' => (string) ($fields['recipient_email'] ?? ''),
        'recipient_name'  => ($fields['recipient_name'] ?? null),
        'message_type'    => (string) ($fields['message_type'] ?? 'test'),
        'subject'         => ($fields['subject'] ?? null),
        'status'          => (string) ($fields['status'] ?? 'sent'),
        'ref'             => ($fields['ref'] ?? null),
        'detail'          => ($fields['detail'] ?? null),
        'sent_by'         => ($fields['sent_by'] ?? null),
        'created_at'      => date('Y-m-d H:i:s'),
    ];
    return (int) Database::getInstance()->insert('communication_log', $data);
}

/** Stamp last_emailed on a contact. */
function contactTouch(int $contactId, ?string $at = null): void {
    if ($contactId <= 0) return;
    Database::getInstance()->update(
        'contact_recipients',
        ['last_emailed' => $at !== null ? $at : date('Y-m-d H:i:s')],
        'id = ?',
        [$contactId]
    );
}

/** Absolute app URL for email links (scheme + host + app_url). */
function emailAppUrl(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_url($path);
}

/**
 * Student portal account welcome email (auto-created on enrollment).
 * Emails the student their portal credentials. No-ops gracefully when
 * SMTP is not configured (EMAIL_CONFIGURED=false) — the registrar still
 * sees the credentials in the UI, just no email goes out.
 */
function sendStudentWelcomeEmail(array $student, array $account, ?int $sentBy = null): array {
    $email = (string) ($account['email'] ?? '');
    $now   = date('Y-m-d H:i:s');
    if ($email === '') {
        return ['sent' => false, 'message' => 'No recipient email.', 'log_id' => null];
    }
    if (!emailConfigured()) {
        contactLog([
            'student_id'      => (int) ($student['id'] ?? 0),
            'recipient_email' => $email,
            'recipient_name'  => ($account['full_name'] ?? null),
            'message_type'    => 'portal_welcome',
            'subject'         => 'Your Student Portal account — BCP Registrar',
            'status'          => 'failed',
            'detail'          => 'Email is not configured (EMAIL_CONFIGURED=false).',
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => 'Email sending is not configured yet.', 'log_id' => null];
    }

    $studentName = getStudentFullName($student);
    $portalUrl   = emailAppUrl('login.php');
    $studentNo   = ($student['student_number'] ?? '—');
    $username    = (string) ($account['username'] ?? '');
    $html = '<p>Hello <strong>' . e_($studentName) . '</strong>,</p>'
        . '<p>Your student portal account has been created for the '
        . '<strong>Bestlink College of the Philippines — Office of the Registrar</strong>.</p>'
        . '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;margin:20px 0;width:100%;max-width:440px;border:1px solid #e2e8f0;border-radius:8px;">'
        . '<tr><td style="background:#f8fafc;font-weight:600;width:40%;">Student No.</td><td>' . e_($studentNo) . '</td></tr>'
        . '<tr><td style="background:#f8fafc;font-weight:600;">Username</td><td>' . e_($username) . '</td></tr>'
        . '<tr><td style="background:#f8fafc;font-weight:600;">Temporary password</td><td>' . e_($account['password'] ?? '') . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0;">Sign in at the Student Portal using the <strong>username</strong> above and the '
        . 'temporary password. Please <strong>change your password after your first login</strong>.</p>'
        . '<p style="margin:24px 0;"><a href="' . e_($portalUrl) . '" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Open the Student Portal</a></p>'
        . '<p style="color:#64748b;font-size:13px;">If you did not expect this message, you can safely ignore it.</p>';

    $sent = sendEmail(['email' => $email, 'name' => ($account['full_name'] ?? $studentName)], 'Your Student Portal account — BCP Registrar', $html);
    $logId = contactLog([
        'student_id'      => (int) ($student['id'] ?? 0),
        'recipient_email' => $email,
        'recipient_name'  => ($account['full_name'] ?? null),
        'message_type'    => 'portal_welcome',
        'subject'         => 'Your Student Portal account — BCP Registrar',
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Portal welcome email sent.' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);

    return [
        'sent'    => $sent,
        'message' => $sent ? 'Welcome email sent to the student.' : 'Account created, but the welcome email could not be sent.',
        'log_id'  => $logId,
    ];
}

// ── Academic data helpers (shared by snapshot + transcript) ─────

/** Terms (academic_history) with their subjects (academic_grades). */
function contactBuildTerms(int $studentId): array {
    $db = Database::getInstance();
    $terms = $db->fetchAll(
        "SELECT * FROM academic_history WHERE student_id = ? ORDER BY IFNULL(school_year,''), IFNULL(semester,''), id",
        [$studentId]
    );
    foreach ($terms as &$t) {
        $t['subjects'] = $db->fetchAll(
            "SELECT subject, subject_code, units, final_rating, grade, remarks
               FROM academic_grades WHERE academic_history_id = ?
              ORDER BY subject_code, subject",
            [(int) $t['id']]
        );
    }
    return $terms;
}

/** Simple GWA from numeric final ratings (falls back to grade). */
function contactComputeGwa(array $terms): ?float {
    $vals = [];
    foreach ($terms as $t) {
        foreach ($t['subjects'] as $s) {
            $g = trim((string) ($s['final_rating'] !== '' && $s['final_rating'] !== null ? $s['final_rating'] : ($s['grade'] ?? '')));
            if ($g !== '' && is_numeric($g) && (float) $g > 0 && (float) $g <= 5) {
                $vals[] = (float) $g;
            }
        }
    }
    return count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : null;
}

// ── Feature senders ────────────────────────────────────────────

/**
 * Test-email verification. Requires an auth_token already set on the
 * contact (the API generates it). Returns ['sent'=>bool,'message'=>…].
 */
function sendContactTestEmail(array $contact, array $student, ?int $sentBy = null): array {
    $now   = date('Y-m-d H:i:s');
    $token = trim((string) ($contact['auth_token'] ?? ''));
    if ($token === '' || !emailConfigured()) {
        contactLog([
            'student_id'      => (int) $contact['student_id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'test',
            'subject'         => 'Confirm your email address — BCP Registrar',
            'status'          => 'failed',
            'detail'          => emailConfigured() ? 'Missing verification token.' : 'Email is not configured (EMAIL_CONFIGURED=false).',
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => emailConfigured() ? 'Could not prepare verification email.' : 'Email sending is not configured yet.'];
    }

    $confirmUrl = emailAppUrl('api/contacts-confirm.php?token=' . urlencode($token));
    $studentName = getStudentFullName($student);
    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p><strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . ') '
        . 'has listed this email address as a contact for receiving communications from '
        . '<strong>Bestlink College of the Philippines — Office of the Registrar</strong>.</p>'
        . '<p>Please confirm that this is the right address by clicking the button below:</p>'
        . '<p style="margin:24px 0;"><a href="' . e_($confirmUrl) . '" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Confirm Receipt</a></p>'
        . '<p style="color:#64748b;font-size:13px;">If you were not expecting this message, you can safely ignore it — '
        . 'no student information will be sent to this address unless you confirm.</p>';
    $subject = 'Confirm your email address — BCP Registrar';

    $sent = sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html);
    $logId = contactLog([
        'student_id'      => (int) $contact['student_id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'test',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'ref'             => $token,
        'detail'          => $sent ? 'Verification email sent.' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id'], $now);

    return [
        'sent'    => $sent,
        'message' => $sent ? 'Verification email sent. Ask the contact to click “Confirm Receipt”.' : 'Email could not be sent.',
        'log_id'  => $logId,
    ];
}

/**
 * Invoice forwarding — feature A. Builds the encrypted invoice PDF
 * (password = student number) and emails one contact. Re-fetches the
 * request/catalog rows so callers can pass light data.
 */
function sendContactInvoice(array $contact, int $requestId, ?int $sentBy = null): array {
    $db = Database::getInstance();
    $request = $db->fetchOne(
        "SELECT dr.*, c.name AS catalog_name, c.fee_type, c.base_fee, c.sku
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.id = ?",
        [$requestId]
    );
    if (!$request) {
        return ['sent' => false, 'message' => 'Request not found.', 'log_id' => null];
    }
    $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [(int) $request['student_id']]);
    if (!$student) {
        return ['sent' => false, 'message' => 'Student record not found.', 'log_id' => null];
    }
    if (!emailConfigured()) {
        contactLog([
            'student_id'      => (int) $student['id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'invoice',
            'subject'         => 'Invoice ' . ($request['request_id'] ?? '') . ' for ' . getStudentFullName($student),
            'status'          => 'failed',
            'detail'          => 'Email is not configured (EMAIL_CONFIGURED=false).',
            'ref'             => $request['request_id'] ?? null,
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => 'Email sending is not configured yet.', 'log_id' => null];
    }

    $catalog = $request['catalog_name'] ?? null;
    $deliveryFee = (float) ($request['delivery_fee'] ?? 0);
    $fee = (float) ($request['fee_amount'] ?? 0);
    if ($fee <= 0) {
        $fee = round((float) ($request['base_fee'] ?? 0) * max(1, (int) ($request['quantity'] ?? 1)), 2);
    }
    $pdf = buildInvoicePdf(
        $request,
        $student,
        $catalog !== null
            ? ['name' => $catalog, 'sku' => $request['sku'] ?? '', 'base_fee' => (float) ($request['base_fee'] ?? 0)]
            : null,
        $deliveryFee
    );

    $studentName = getStudentFullName($student);
    $total = $fee + $deliveryFee;
    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>A new invoice has been generated for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . '):</p>'
        . '<ul>'
        . '<li>Document: ' . e_($catalog ?: 'Document request') . '</li>'
        . '<li>Request No.: ' . e_($request['request_id'] ?? '—') . '</li>'
        . '<li>Document fee: PHP ' . number_format($fee, 2) . '</li>'
        . ($deliveryFee > 0 ? '<li>Delivery fee: PHP ' . number_format($deliveryFee, 2) . '</li>' : '')
        . '<li><strong>Total: PHP ' . number_format($total, 2) . '</strong></li>'
        . '</ul>'
        . '<p>The invoice is attached as a secure PDF. It opens with the student’s <strong>ID number</strong> as the password.</p>';
    $subject = 'Invoice ' . ($request['request_id'] ?? '') . ' for ' . $studentName;

    $sent = sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html, [
        'data' => $pdf['bytes'],
        'name' => $pdf['filename'],
        'mime' => 'application/pdf',
    ]);
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'invoice',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'ref'             => $request['request_id'] ?? null,
        'detail'          => $sent ? 'Invoice forwarded (fee ' . number_format($total, 2) . ').' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Invoice emailed to ' . $contact['email'] . '.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Grade snapshot — feature B. Clean HTML email: GWA + subject list.
 * No attachment (the spec keeps the snapshot lightweight).
 */
function sendContactGradeSnapshot(array $contact, array $student, array $terms, ?int $sentBy = null): array {
    $studentName = getStudentFullName($student);
    $gwa = contactComputeGwa($terms);

    $rows = '';
    foreach ($terms as $t) {
        $subjects = $t['subjects'] ?? [];
        if (count($subjects) === 0) continue;
        $termLabel = trim(($t['school_name'] ?? '') . ' ' . ($t['school_year'] ?? '') . ' ' . ($t['semester'] ?? ''));
        $rows .= '<tr><td colspan="3" style="background:#f1f5f9;font-weight:600;color:#334155;">' . e_($termLabel) . '</td></tr>';
        foreach ($subjects as $s) {
            $g = trim((string) ($s['final_rating'] !== '' && $s['final_rating'] !== null ? $s['final_rating'] : ($s['grade'] ?? '')));
            $rows .= '<tr><td>' . e_($s['subject'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . e_($s['units'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . e_($g) . '</td></tr>';
        }
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="3" style="color:#64748b;text-align:center;">No graded subjects on file.</td></tr>';
    }

    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>Here is a current grade snapshot for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . '), as of ' . date('F d, Y h:i A') . ':</p>'
        . '<p><strong>Computed GWA:</strong> ' . ($gwa !== null ? number_format($gwa, 2) : '—') . '</p>'
        . '<table style="border-collapse:collapse;width:100%;font-size:14px;">'
        . '<thead><tr style="background:#eef4ff;"><th style="text-align:left;padding:6px 8px;">Subject</th><th style="padding:6px 8px;">Units</th><th style="padding:6px 8px;">Final</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<p style="color:#64748b;font-size:12px;">This is an unofficial snapshot for your reference. Official records are available from the Office of the Registrar.</p>';
    $subject = 'Grade snapshot for ' . $studentName;

    $sent = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html) : false;
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'snapshot',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Grade snapshot sent (GWA ' . ($gwa !== null ? number_format($gwa, 2) : 'n/a') . ').' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Grade snapshot emailed.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Transcript delivery — encrypted PDF attachment (password = student
 * number), per the Secure PDF feature (§D).
 */
function sendContactTranscript(array $contact, array $student, array $terms, ?int $sentBy = null): array {
    $studentName = getStudentFullName($student);
    $gwa = contactComputeGwa($terms);
    $pdf = buildTranscriptPdf($student, $terms);

    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>As requested, here is the current transcript for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . ').</p>'
        . '<p>Computed GWA: <strong>' . ($gwa !== null ? number_format($gwa, 2) : '—') . '</strong></p>'
        . '<p>The transcript is attached as a secure PDF. It opens with the student’s <strong>ID number</strong> as the password.</p>';
    $subject = 'Transcript for ' . $studentName;

    $sent = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html, [
        'data' => $pdf['bytes'],
        'name' => $pdf['filename'],
        'mime' => 'application/pdf',
    ]) : false;
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'transcript',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Transcript PDF emailed.' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Transcript emailed.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Emergency blast — feature C. One email to every verified contact with
 * send_emergency on. Returns ['sent'=>int,'failed'=>int].
 */
function sendEmergencyBlast(string $subject, string $message, ?int $sentBy = null, ?int $studentId = null): array {
    $db = Database::getInstance();
    $sql = "SELECT * FROM contact_recipients WHERE verified = 1 AND send_emergency = 1";
    $params = [];
    if ($studentId) {
        $sql .= " AND student_id = ?";
        $params[] = (int) $studentId;
    }
    $contacts = $db->fetchAll($sql, $params);

    $sent = 0;
    $failed = 0;
    foreach ($contacts as $contact) {
        $html = '<p><strong>' . e_($subject) . '</strong></p>'
            . '<p>' . nl2br(e_(trim($message))) . '</p>'
            . '<p style="color:#64748b;font-size:12px;">Bestlink College of the Philippines — Office of the Registrar</p>';
        $ok = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html) : false;
        contactLog([
            'student_id'      => (int) $contact['student_id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'emergency',
            'subject'         => $subject,
            'status'          => $ok ? 'sent' : 'failed',
            'detail'          => $ok ? 'Emergency blast delivered.' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
            'sent_by'         => $sentBy,
        ]);
        if ($ok) { $sent++; contactTouch((int) $contact['id']); }
        else { $failed++; }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Automatic invoice forwarding — hooks into api/student-documents.php
 * when a request enters Awaiting_Payment. Emails every VERIFIED contact
 * with send_billing on for that student. Never throws (the caller runs
 * this inside try/catch, but be safe anyway).
 *
 * @return array{sent:int, billing_contacts:int, message:string}
 */
function contactAutoForwardInvoice(int $requestId, int $studentId): array {
    $db = Database::getInstance();
    $contacts = $db->fetchAll(
        "SELECT * FROM contact_recipients
          WHERE student_id = ? AND verified = 1 AND send_billing = 1",
        [$studentId]
    );
    $sent = 0;
    foreach ($contacts as $contact) {
        $res = sendContactInvoice($contact, $requestId, null);
        if ($res['sent']) $sent++;
    }
    return [
        'sent'             => $sent,
        'billing_contacts' => count($contacts),
        'message'          => count($contacts) > 0
            ? 'Invoice forwarded to ' . $sent . '/' . count($contacts) . ' billing contact(s).'
            : 'No verified billing contacts for this student.',
    ];
}

/** HTML-escape helper (short alias used in the senders above). */
if (!function_exists('e_')) {
    function e_(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
