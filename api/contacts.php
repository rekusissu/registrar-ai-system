<?php
// ============================================================
//  API/CONTACTS.PHP
//  Emergency & Contacts module — registrar-authoritative.
//
//  Session + CSRF protected (csrf_guard enforces on POST).
//  The Registrar is the source of truth for a student's contacts:
//  guardians + emergency contacts (legacy tables) AND the email
//  recipients (contact_recipients). The student portal is READ-ONLY —
//  a student may only submit a change request that the Registrar
//  approves or rejects here.
//
//  Actions:
//    list             → {contacts:[...]}   (student: own; registrar: ?student_id)
//    my_contacts      → student: {guardians, emergency, email_recipients, requests}
//    request_change   → student: submit add|update|remove for a contact
//    approve_change   → staff: apply a pending request to the real table
//    reject_change    → staff: mark a request rejected (with note)
//    pull_enrollment  → staff: create guardians from students.father/mother_name
//
//  Staff-only (a student may NOT mutate or trigger emails directly —
//  they request changes instead):
//    save, delete, test_email, email_contact, blast, resend_invoice
// ============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php'; // rejects POSTs without a valid token
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/mail_client.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($input['action'] ?? ($_GET['action'] ?? ''));
$role   = (string) getCurrentUserRole();
$db     = Database::getInstance();
$now    = date('Y-m-d H:i:s');

/** Resolve the student a caller may act on; student role is pinned to self. */
function contactsTargetStudentId(string $role, array $input): int {
    if ($role === 'student') {
        return (int) getCurrentStudentId();
    }
    if (in_array($role, ['admin', 'registrar'], true)) {
        return (int) ($input['student_id'] ?? 0);
    }
    return 0;
}

/** A registrar/admin-only action: reject everyone else. */
function contactsRequireStaff(string $role): void {
    if (!in_array($role, ['admin', 'registrar'], true)) {
        echo json_encode(['success' => false, 'message' => 'Forbidden.']);
        exit;
    }
}

/** Fetch one contact by id, enforcing that the caller owns it (students). */
function contactsFetchOwned(string $role, int $contactId, ?int $studentId): ?array {
    $db = Database::getInstance();
    if ($role === 'student') {
        $row = $db->fetchOne(
            'SELECT * FROM contact_recipients WHERE id = ? AND student_id = ?',
            [$contactId, $studentId]
        );
    } else {
        $row = $db->fetchOne('SELECT * FROM contact_recipients WHERE id = ?', [$contactId]);
    }
    return $row ?: null;
}

try {
    switch ($action) {

        case 'list': {
            $studentId = contactsTargetStudentId($role, $input);
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'No linked student record.']);
                exit;
            }
            $contacts = $db->fetchAll(
                'SELECT * FROM contact_recipients WHERE student_id = ? ORDER BY verified DESC, id DESC',
                [$studentId]
            );
            echo json_encode(['success' => true, 'data' => ['contacts' => $contacts]]);
            exit;
        }

        // ─── STUDENT: full read-only snapshot (guardians + emergency + email + requests) ───
        case 'my_contacts': {
            if ($role !== 'student') {
                contactsRequireStaff($role);
            }
            $studentId = (int) getCurrentStudentId();
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'No linked student record.']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => [
                'guardians'        => $db->fetchAll('SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC', [$studentId]),
                'emergency'        => $db->fetchAll('SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC', [$studentId]),
                'email_recipients' => $db->fetchAll('SELECT * FROM contact_recipients WHERE student_id = ? ORDER BY verified DESC, id DESC', [$studentId]),
                'requests'         => $db->fetchAll('SELECT * FROM contact_change_requests WHERE student_id = ? ORDER BY id DESC', [$studentId]),
            ]]);
            exit;
        }

        // ─── STUDENT: submit a change request (the ONLY student write path) ───
        case 'request_change': {
            if ($role !== 'student') {
                echo json_encode(['success' => false, 'message' => 'Forbidden.']);
                exit;
            }
            $studentId = (int) getCurrentStudentId();
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'No linked student record.']);
                exit;
            }
            $contactType = trim((string) ($input['contact_type'] ?? ''));
            $requestType = trim((string) ($input['request_type'] ?? ''));
            if (!in_array($contactType, ['guardian', 'emergency', 'email'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid contact type.']);
                exit;
            }
            if (!in_array($requestType, ['add', 'update', 'remove'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid request type.']);
                exit;
            }
            $targetId = (int) ($input['target_id'] ?? 0);
            $payload  = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            $reason   = trim((string) ($input['reason'] ?? ''));

            // For update/remove the target must belong to this student.
            if ($requestType !== 'add') {
                if ($targetId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Select the contact you want to change.']);
                    exit;
                }
                $ownerTable = $contactType === 'email' ? 'contact_recipients'
                    : ($contactType === 'guardian' ? 'guardians' : 'emergency_contacts');
                $owned = $db->fetchOne("SELECT id FROM {$ownerTable} WHERE id = ? AND student_id = ?", [$targetId, $studentId]);
                if (!$owned) {
                    echo json_encode(['success' => false, 'message' => 'Selected contact was not found on your record.']);
                    exit;
                }
            }

            // Minimal field sanity on the proposed values.
            if ($requestType !== 'remove') {
                if ($contactType === 'email') {
                    $email = strtolower(trim((string) ($payload['email'] ?? '')));
                    if (!isValidEmail($email)) {
                        echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
                        exit;
                    }
                } elseif (trim((string) ($payload['full_name'] ?? '')) === '') {
                    echo json_encode(['success' => false, 'message' => 'A full name is required.']);
                    exit;
                }
            }

            $id = $db->insert('contact_change_requests', [
                'student_id'   => $studentId,
                'contact_type' => $contactType,
                'request_type' => $requestType,
                'target_id'    => $targetId > 0 ? $targetId : null,
                'payload'      => json_encode($payload),
                'reason'       => $reason !== '' ? $reason : null,
                'status'       => 'pending',
                'created_at'   => $now,
            ]);
            logActivity((int) getCurrentUserId(), 'submitted contact change request', null, 'contact_change_requests', $id,
                null, ['contact_type' => $contactType, 'request_type' => $requestType]);
            echo json_encode(['success' => true, 'message' => 'Change request submitted for review by the Office of the Registrar.', 'data' => ['id' => $id]]);
            exit;
        }

        // ─── STAFF: apply a pending change request to the real tables ───
        case 'approve_change': {
            contactsRequireStaff($role);
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                exit;
            }
            $req = $db->fetchOne('SELECT * FROM contact_change_requests WHERE id = ?', [$id]);
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Request not found.']);
                exit;
            }
            if (($req['status'] ?? '') !== 'pending') {
                echo json_encode(['success' => false, 'message' => 'This request has already been reviewed.']);
                exit;
            }
            $studentId   = (int) $req['student_id'];
            $payload     = json_decode((string) $req['payload'], true);
            $payload     = is_array($payload) ? $payload : [];
            $targetId    = (int) ($req['target_id'] ?? 0);
            $requestType = (string) $req['request_type'];
            $note        = trim((string) ($input['note'] ?? ''));

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                switch ((string) $req['contact_type']) {
                    case 'guardian': {
                        if ($requestType !== 'remove') {
                            $rel = (string) ($payload['relationship'] ?? 'guardian');
                            if (!in_array($rel, ['father', 'mother', 'guardian', 'spouse', 'sibling'], true)) {
                                $rel = 'guardian';
                            }
                            $data = [
                                'full_name'      => trim((string) ($payload['full_name'] ?? '')),
                                'relationship'   => $rel,
                                'contact_number' => trim((string) ($payload['contact_number'] ?? '')),
                                'email'          => trim((string) ($payload['email'] ?? '')) !== '' ? strtolower(trim((string) $payload['email'])) : null,
                                'address'        => trim((string) ($payload['address'] ?? '')) !== '' ? trim((string) $payload['address']) : null,
                                'is_primary'     => !empty($payload['is_primary']) ? 1 : 0,
                                'is_emergency'   => !empty($payload['is_emergency']) ? 1 : 0,
                            ];
                            if ($data['full_name'] === '') {
                                throw new RuntimeException('Guardian name is required.');
                            }
                            if ($requestType === 'update' && $targetId > 0) {
                                $db->update('guardians', $data, 'id = ? AND student_id = ?', [$targetId, $studentId]);
                            } else {
                                $data['student_id'] = $studentId;
                                $db->insert('guardians', $data);
                            }
                        } else {
                            if ($targetId <= 0) { throw new RuntimeException('Missing target guardian.'); }
                            $db->delete('guardians', 'id = ? AND student_id = ?', [$targetId, $studentId]);
                        }
                        break;
                    }

                    case 'emergency': {
                        if ($requestType !== 'remove') {
                            $data = [
                                'full_name'      => trim((string) ($payload['full_name'] ?? '')),
                                'relationship'   => trim((string) ($payload['relationship'] ?? '')),
                                'contact_number' => trim((string) ($payload['contact_number'] ?? '')),
                                'address'        => trim((string) ($payload['address'] ?? '')) !== '' ? trim((string) $payload['address']) : null,
                                'is_primary'     => !empty($payload['is_primary']) ? 1 : 0,
                            ];
                            if ($data['full_name'] === '') {
                                throw new RuntimeException('Contact name is required.');
                            }
                            if ($requestType === 'update' && $targetId > 0) {
                                $db->update('emergency_contacts', $data, 'id = ? AND student_id = ?', [$targetId, $studentId]);
                            } else {
                                $data['student_id'] = $studentId;
                                $db->insert('emergency_contacts', $data);
                            }
                        } else {
                            if ($targetId <= 0) { throw new RuntimeException('Missing target contact.'); }
                            $db->delete('emergency_contacts', 'id = ? AND student_id = ?', [$targetId, $studentId]);
                        }
                        break;
                    }

                    case 'email': {
                        if ($requestType !== 'remove') {
                            $email = strtolower(trim((string) ($payload['email'] ?? '')));
                            $data = [
                                'full_name'      => trim((string) ($payload['full_name'] ?? '')),
                                'relationship'   => trim((string) ($payload['relationship'] ?? '')) !== '' ? trim((string) $payload['relationship']) : 'parent',
                                'email'          => $email,
                                'phone'          => trim((string) ($payload['phone'] ?? '')) !== '' ? trim((string) $payload['phone']) : null,
                                'send_billing'   => !empty($payload['send_billing']) ? 1 : 0,
                                'send_grades'    => !empty($payload['send_grades']) ? 1 : 0,
                                'send_emergency' => !empty($payload['send_emergency']) ? 1 : 0,
                            ];
                            if ($data['full_name'] === '' || !isValidEmail($email)) {
                                throw new RuntimeException('A valid name and email address are required.');
                            }
                            if ($requestType === 'update' && $targetId > 0) {
                                $dup = $db->fetchOne('SELECT id FROM contact_recipients WHERE student_id = ? AND email = ? AND id <> ?', [$studentId, $email, $targetId]);
                                if ($dup) { throw new RuntimeException('Another recipient already uses that email for this student.'); }
                                $db->update('contact_recipients', $data, 'id = ? AND student_id = ?', [$targetId, $studentId]);
                            } else {
                                $dup = $db->fetchOne('SELECT id FROM contact_recipients WHERE student_id = ? AND email = ?', [$studentId, $email]);
                                if ($dup) { throw new RuntimeException('That email is already listed for this student.'); }
                                $data['student_id'] = $studentId;
                                $db->insert('contact_recipients', $data);
                            }
                        } else {
                            if ($targetId <= 0) { throw new RuntimeException('Missing target recipient.'); }
                            $db->delete('contact_recipients', 'id = ? AND student_id = ?', [$targetId, $studentId]);
                        }
                        break;
                    }

                    default:
                        throw new RuntimeException('Unknown contact type.');
                }

                $db->update('contact_change_requests', [
                    'status'      => 'approved',
                    'review_note' => $note !== '' ? $note : null,
                    'reviewed_at' => $now,
                    'reviewed_by' => (int) getCurrentUserId(),
                ], 'id = ?', [$id]);

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }

            logActivity((int) getCurrentUserId(), 'approved contact change request', null, 'contact_change_requests', $id,
                null, ['contact_type' => $req['contact_type'], 'request_type' => $requestType, 'student_id' => $studentId]);
            echo json_encode(['success' => true, 'message' => 'Change request approved and applied.']);
            exit;
        }

        // ─── STAFF: reject a pending change request ───
        case 'reject_change': {
            contactsRequireStaff($role);
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                exit;
            }
            $req = $db->fetchOne('SELECT * FROM contact_change_requests WHERE id = ?', [$id]);
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Request not found.']);
                exit;
            }
            if (($req['status'] ?? '') !== 'pending') {
                echo json_encode(['success' => false, 'message' => 'This request has already been reviewed.']);
                exit;
            }
            $note = trim((string) ($input['note'] ?? ''));
            $db->update('contact_change_requests', [
                'status'      => 'rejected',
                'review_note' => $note !== '' ? $note : null,
                'reviewed_at' => $now,
                'reviewed_by' => (int) getCurrentUserId(),
            ], 'id = ?', [$id]);
            logActivity((int) getCurrentUserId(), 'rejected contact change request', null, 'contact_change_requests', $id,
                null, ['contact_type' => $req['contact_type'], 'request_type' => $req['request_type'], 'student_id' => (int) $req['student_id']]);
            echo json_encode(['success' => true, 'message' => 'Change request rejected.']);
            exit;
        }

        // ─── STAFF: auto-create guardians from the enrollment record ───
        case 'pull_enrollment': {
            contactsRequireStaff($role);
            $studentId = (int) ($input['student_id'] ?? 0);
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Student required.']);
                exit;
            }
            $student = $db->fetchOne('SELECT id, father_name, mother_name FROM students WHERE id = ?', [$studentId]);
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
            $created = 0;
            foreach (['father' => 'father_name', 'mother' => 'mother_name'] as $rel => $col) {
                $name = trim((string) ($student[$col] ?? ''));
                if ($name === '') { continue; }
                $exists = $db->fetchOne(
                    'SELECT id FROM guardians WHERE student_id = ? AND (relationship = ? OR full_name = ?)',
                    [$studentId, $rel, $name]
                );
                if ($exists) { continue; }
                $db->insert('guardians', [
                    'student_id'     => $studentId,
                    'full_name'      => $name,
                    'relationship'   => $rel,
                    'contact_number' => '',
                    'email'          => null,
                    'is_primary'     => 0,
                    'is_emergency'   => 0,
                ]);
                $created++;
            }
            logActivity((int) getCurrentUserId(), 'pulled guardians from enrollment', null, 'guardians', $studentId,
                null, ['created' => $created]);
            echo json_encode(['success' => true,
                'message' => $created > 0
                    ? $created . ' guardian' . ($created === 1 ? '' : 's') . ' added from enrollment.'
                    : 'No new guardians added — enrollment names are already on file.',
                'data' => ['created' => $created]]);
            exit;
        }

        // ─── STAFF-ONLY below: students may NOT mutate or send. ───
        case 'save': {
            contactsRequireStaff($role);
            $studentId = contactsTargetStudentId($role, $input);
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'No linked student record.']);
                exit;
            }
            $id        = (int) ($input['id'] ?? 0);
            $fullName  = trim((string) ($input['full_name'] ?? ''));
            $email     = strtolower(trim((string) ($input['email'] ?? '')));
            $relationship = trim((string) ($input['relationship'] ?? 'parent'));
            $phone     = trim((string) ($input['phone'] ?? ''));
            $billing   = (int) (bool) ($input['send_billing'] ?? 0);
            $grades    = (int) (bool) ($input['send_grades'] ?? 0);
            $emergency = (int) (bool) ($input['send_emergency'] ?? 0);

            if ($fullName === '' || !isValidEmail($email)) {
                echo json_encode(['success' => false, 'message' => 'A valid name and email are required.']);
                exit;
            }
            if ($relationship === '' || strlen($relationship) > 40) {
                $relationship = 'parent';
            }

            $fields = [
                'student_id'     => $studentId,
                'full_name'      => $fullName,
                'relationship'   => $relationship,
                'email'          => $email,
                'phone'          => $phone !== '' ? $phone : null,
                'send_billing'   => $billing,
                'send_grades'    => $grades,
                'send_emergency' => $emergency,
            ];

            if ($id > 0) {
                $existing = contactsFetchOwned($role, $id, $studentId);
                if (!$existing) {
                    echo json_encode(['success' => false, 'message' => 'Contact not found.']);
                    exit;
                }
                $dup = $db->fetchOne(
                    'SELECT id FROM contact_recipients WHERE student_id = ? AND email = ? AND id <> ?',
                    [$studentId, $email, $id]
                );
                if ($dup) {
                    echo json_encode(['success' => false, 'message' => 'Another contact already uses that email for this student.']);
                    exit;
                }
                $fields['updated_at'] = $now;
                // Changing the address invalidates verification.
                if (strtolower(trim((string) $existing['email'])) !== $email) {
                    $fields['verified'] = 0;
                    $fields['auth_token'] = null;
                    $fields['token_expires_at'] = null;
                }
                $db->update('contact_recipients', $fields, 'id = ?', [$id]);
                echo json_encode(['success' => true, 'message' => 'Contact updated.', 'data' => ['id' => $id]]);
                exit;
            }

            $dup = $db->fetchOne(
                'SELECT id FROM contact_recipients WHERE student_id = ? AND email = ?',
                [$studentId, $email]
            );
            if ($dup) {
                echo json_encode(['success' => false, 'message' => 'That email is already listed for this student.']);
                exit;
            }
            $fields['created_at'] = $now;
            $newId = (int) $db->insert('contact_recipients', $fields);
            echo json_encode(['success' => true, 'message' => 'Contact added.', 'data' => ['id' => $newId]]);
            exit;
        }

        case 'delete': {
            contactsRequireStaff($role);
            $studentId = contactsTargetStudentId($role, $input);
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Missing contact id.']);
                exit;
            }
            $existing = contactsFetchOwned($role, $id, $studentId);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Contact not found.']);
                exit;
            }
            $db->delete('contact_recipients', 'id = ?', [$id]);
            echo json_encode(['success' => true, 'message' => 'Contact removed.']);
            exit;
        }

        case 'test_email': {
            contactsRequireStaff($role);
            $studentId = contactsTargetStudentId($role, $input);
            $id = (int) ($input['id'] ?? 0);
            $contact = contactsFetchOwned($role, $id, $studentId);
            if (!$contact) {
                echo json_encode(['success' => false, 'message' => 'Contact not found.']);
                exit;
            }
            // (Re)issue the one-time token when missing or expired.
            $token = (string) ($contact['auth_token'] ?? '');
            $expires = (string) ($contact['token_expires_at'] ?? '');
            if ($token === '' || ($expires !== '' && strtotime($expires) < time())) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 86400);
                $db->update(
                    'contact_recipients',
                    ['auth_token' => $token, 'token_expires_at' => $expires, 'updated_at' => $now],
                    'id = ?',
                    [$id]
                );
                $contact['auth_token'] = $token;
                $contact['token_expires_at'] = $expires;
            }
            $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [(int) $contact['student_id']]);
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student record not found.']);
                exit;
            }
            $res = sendContactTestEmail($contact, $student, (int) getCurrentUserId());
            echo json_encode(['success' => $res['sent'], 'message' => $res['message'], 'data' => ['log_id' => $res['log_id'] ?? null]]);
            exit;
        }

        case 'email_contact': {
            contactsRequireStaff($role);
            $studentId = contactsTargetStudentId($role, $input);
            $id = (int) ($input['id'] ?? 0);
            $kind = trim((string) ($input['kind'] ?? 'snapshot'));
            if (!in_array($kind, ['snapshot', 'transcript'], true)) {
                echo json_encode(['success' => false, 'message' => 'Unknown email kind.']);
                exit;
            }
            $contact = contactsFetchOwned($role, $id, $studentId);
            if (!$contact) {
                echo json_encode(['success' => false, 'message' => 'Contact not found.']);
                exit;
            }
            if ((int) ($contact['verified'] ?? 0) !== 1) {
                echo json_encode(['success' => false, 'message' => 'This contact has not verified their email address yet. Send a Test Email first.']);
                exit;
            }
            $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [(int) $contact['student_id']]);
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student record not found.']);
                exit;
            }
            $terms = contactBuildTerms((int) $contact['student_id']);
            $res = $kind === 'transcript'
                ? sendContactTranscript($contact, $student, $terms, (int) getCurrentUserId())
                : sendContactGradeSnapshot($contact, $student, $terms, (int) getCurrentUserId());
            echo json_encode(['success' => $res['sent'], 'message' => $res['message'], 'data' => ['log_id' => $res['log_id'] ?? null]]);
            exit;
        }

        case 'blast': {
            contactsRequireStaff($role);
            $subject = trim((string) ($input['subject'] ?? ''));
            $message = trim((string) ($input['message'] ?? ''));
            if ($subject === '' || $message === '') {
                echo json_encode(['success' => false, 'message' => 'Subject and message are required.']);
                exit;
            }
            $studentId = (int) ($input['student_id'] ?? 0); // 0 = all students
            $res = sendEmergencyBlast($subject, $message, (int) getCurrentUserId(), $studentId > 0 ? $studentId : null);
            echo json_encode([
                'success' => true,
                'message' => 'Blast complete: ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed.',
                'data'    => $res,
            ]);
            exit;
        }

        case 'resend_invoice': {
            contactsRequireStaff($role);
            $ref = trim((string) ($input['ref'] ?? ''));
            $contactId = (int) ($input['contact_id'] ?? 0);
            if ($ref === '' || $contactId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Request reference and contact are required.']);
                exit;
            }
            $contact = $db->fetchOne('SELECT * FROM contact_recipients WHERE id = ?', [$contactId]);
            if (!$contact) {
                echo json_encode(['success' => false, 'message' => 'Contact not found.']);
                exit;
            }
            $request = $db->fetchOne('SELECT id FROM document_requests WHERE request_id = ?', [$ref]);
            if (!$request) {
                echo json_encode(['success' => false, 'message' => 'No document request with that reference.']);
                exit;
            }
            $res = sendContactInvoice($contact, (int) $request['id'], (int) getCurrentUserId());
            echo json_encode(['success' => $res['sent'], 'message' => $res['message'], 'data' => ['log_id' => $res['log_id'] ?? null]]);
            exit;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
    }
} catch (Throwable $e) {
    json_error($e, 'Unable to process the request.');
}
