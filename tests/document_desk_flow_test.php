<?php
// tools/_desk_flow.php - drive a request all the way through the desk's
// own transitions, as a registrar, over HTTP.
//
// The wizard tests prove a student can file. This proves the OTHER
// half: that the office can pick the request up, check the receipt and
// release the document, and that the timeline a student sees afterwards
// agrees with what actually happened.
//
// A wizard that files beautifully and that the desk cannot advance is
// half a feature.
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    $h = ob_get_level() > 0 ? ob_get_clean() : '';
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE], true)) {
        $h .= "\nFATAL: " . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n";
    }
    if ($h !== '') echo $h;
});

require __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

// A student session AND a registrar session, because the flow needs
// both: the student files and attaches the receipt, the registrar
// verifies it and moves the request along.
$src = file_get_contents(__DIR__ . '/../shared/session_config.php');
preg_match("/session_name\(\s*'([^']+)'\s*\)/", $src, $m);
$NAME = $m[1];

/**
 * Mint one session, in a CHILD process.
 *
 * A child, because two session_start() calls on the same session name
 * within one process do not produce two independent sessions - the
 * second call finds the first still open and reuses it. Both jars then
 * hold the same id, the registrar's request carries the student's CSRF
 * token, and every call is refused. Spawning is the smallest change
 * that makes the two genuinely independent.
 *
 * Prints "session_id<TAB>token", and writes the jar itself.
 */
function seedViaChild(string $jar, string $name, int $userId, string $role, ?int $studentId): string
{
    $php = PHP_BINARY;
    $script = <<<'PHP'
<?php
// Child: create one session and report it. Output is buffered so the
// cookie file notice cannot precede the token on stdout.
ob_start();
[$jar, $name, $uid, $role, $sid] = [$argv[1], $argv[2], (int) $argv[3], $argv[4], $argv[5] === '' ? null : (int) $argv[5]];
session_name($name);
session_start();
session_regenerate_id(true);
$_SESSION['user_id'] = $uid;
$_SESSION['role'] = $role;
$_SESSION['student_id'] = $sid;
$_SESSION['last_activity'] = time();
$_SESSION['login_time'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$id = session_id();
$token = $_SESSION['csrf_token'];
session_write_close();
file_put_contents($jar, "# Netscape HTTP Cookie File\nlocalhost\tFALSE\t/\tFALSE\t0\t{$name}\t{$id}\n");
ob_end_clean();
echo $id . "\t" . $token;
PHP;
    $tmp = sys_get_temp_dir() . '/seed-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, $script);

    // escapeshellarg() is POSIX quoting; on Windows it wraps a path in
    // single quotes, which cmd.exe does NOT strip - so the interpreter
    // was handed a filename beginning with a quote and reported "cannot
    // find the path". escapeshellcmd() is the right primitive here, and
    // none of these values is attacker-controlled.
    $cmd = escapeshellcmd($php) . ' ' . escapeshellcmd($tmp)
         . ' ' . escapeshellcmd($jar)
         . ' ' . escapeshellcmd($name)
         . ' ' . escapeshellcmd((string) $userId)
         . ' ' . escapeshellcmd((string) $role)
         . ' ' . escapeshellcmd($studentId === null ? '' : (string) $studentId);
    $out = shell_exec($cmd . ' 2>NUL');
    @unlink($tmp);

    $out = trim((string) $out);
    $parts = explode("\t", $out);
    return count($parts) === 2 ? $parts[1] : '';
}

$jarS = sys_get_temp_dir() . '/desk-s-' . getmypid() . '.txt';
$jarR = sys_get_temp_dir() . '/desk-r-' . getmypid() . '.txt';
@unlink($jarS); @unlink($jarR);

$student = $db->fetchOne("SELECT u.id, u.student_id FROM users u WHERE u.role='student' AND u.is_active=1 AND u.student_id IS NOT NULL ORDER BY u.id LIMIT 1");
$registrar = $db->fetchOne("SELECT id FROM users WHERE role='registrar' AND is_active=1 ORDER BY id LIMIT 1");
if (!$student || !$registrar) { fwrite(STDERR, "need a student and a registrar account\n"); exit(1); }

// One token per session: the student's signs the wizard and the receipt
// upload, the registrar's signs the desk's transitions.
//
// Seeded in SEPARATE child processes. Two session_start() calls on the
// same session name in one process do not reliably produce two
// independent sessions: the second reuses the first's already-open
// session, so the two jars end up holding the SAME id, the registrar's
// request carries the student's token, and every call fails the CSRF
// check for a reason that has nothing to do with the code under test.
$token    = seedViaChild($jarS, $NAME, (int) $student['id'], 'student', (int) $student['student_id']);
$regToken = seedViaChild($jarR, $NAME, (int) $registrar['id'], 'registrar', null);

if ($token === '' || $regToken === '') {
    fwrite(STDERR, "could not mint a CSRF token for both sessions\n");
    exit(1);
}

function post(string $url, $fields, string $jar, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 40, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $token],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = substr($raw, $hs);
    curl_close($ch);
    return ['status' => $st, 'body' => $body, 'json' => json_decode($body, true)];
}

function put(string $url, array $fields, string $jar, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_TIMEOUT => 40,
        CURLOPT_POSTFIELDS => json_encode($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $token],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = substr($raw, $hs);
    curl_close($ch);
    return ['status' => $st, 'body' => $body, 'json' => json_decode($body, true)];
}

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($d !== '' ? "  <- $d" : '') . "\n"; }
}

$API = 'http://localhost/registrar-ai-system/api';
$base = 'http://localhost/registrar-ai-system';

// ── 1. File online, awaiting payment ──────────────────────
echo "== student files an online request ==\n";
$tor = $db->fetchOne("SELECT id FROM document_catalog WHERE sku='DOC-TOR'");
$r = post("$API/document-request.php?action=save_draft", [
    'catalog_id' => (int) $tor['id'], 'wizard_step' => 1, 'quantity' => 1,
    'purpose_code' => 'employment', 'purpose' => 'Desk flow test',
    'fulfillment_type' => 'Pickup', 'payment_method' => 'Online',
], $jarS, $token);
$draftId = (int) ($r['json']['data']['id'] ?? 0);
check('draft saved', $draftId > 0, substr($r['body'], 0, 120));

$r = post("$API/document-request.php?action=submit", [
    'request_id' => $draftId, 'certify_true' => 1, 'certify_privacy' => 1,
], $jarS, $token);
check('submitted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
check('it is waiting on payment',
    ($r['json']['data']['request']['document_status'] ?? '') === 'Awaiting_Payment',
    $r['json']['data']['request']['document_status'] ?? '?');
$reqId = (int) $r['json']['data']['request']['id'];
check('it owes money', (float) ($r['json']['data']['request']['total'] ?? 0) > 0);

// ── 2. The desk may NOT start work on an unpaid request ─────
echo "\n== the desk cannot start an unpaid request ==\n";
$r = put("$API/documents.php?id=$reqId", ['action' => 'process'], $jarR, $regToken);
check('process is refused', ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 160));
// The STATUS guard fires before the receipt gate, and that ordering is
// right: "this request is waiting on payment" tells a clerk what to do
// next, where the gate's wording assumes they already know it is
// theirs to start. So the refusal must name the status.
check('the refusal names the status it is in',
    stripos((string) ($r['json']['message'] ?? ''), 'can be started') !== false,
    (string) ($r['json']['message'] ?? ''));

// The gate itself is what stops an unpaid request once it has been
// released to Filed - so exercise that directly by flipping the status.
$db->update('document_requests', ['document_status' => 'Filed'], 'id = ?', [$reqId]);
$r = put("$API/documents.php?id=$reqId", ['action' => 'process'], $jarR, $regToken);
check('the receipt gate refuses a Filed request with no receipt',
    ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 160));
check('the gate names the receipt',
    stripos((string) ($r['json']['message'] ?? ''), 'receipt') !== false,
    (string) ($r['json']['message'] ?? ''));
// Put it back for the rest of the flow.
$db->update('document_requests', ['document_status' => 'Awaiting_Payment'], 'id = ?', [$reqId]);

// ── 3. The student attaches the receipt ────────────────────
echo "\n== student attaches the receipt ==\n";
$png = sys_get_temp_dir() . '/desk-receipt-' . getmypid() . '.png';
file_put_contents($png, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));
$r = post("$API/student-documents.php", [
    'action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => 'ABC123456',
    'payment_receipt' => new CURLFile($png),
], $jarS, $token);
check('receipt uploaded', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
@unlink($png);

// ── 4. The registrar verifies it ───────────────────────────
echo "\n== registrar verifies the receipt ==\n";
$r = put("$API/documents.php?id=$reqId", ['action' => 'verify_receipt'], $jarR, $regToken);
check('verify accepted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
$after = $db->fetchOne('SELECT document_status, paid_at FROM document_requests WHERE id = ?', [$reqId]);
check('verification released it from Awaiting_Payment',
    (string) $after['document_status'] === 'Filed', (string) $after['document_status']);
check('verification stamped paid_at', !empty($after['paid_at']));

// ── 5. Process → ready → claim ────────────────────────────
echo "\n== desk moves it along the track ==\n";
$r = put("$API/documents.php?id=$reqId", ['action' => 'process'], $jarR, $regToken);
check('process accepted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 160));
check('now Processing',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id=?', [$reqId]) === 'Processing');

$r = put("$API/documents.php?id=$reqId", [
    'action' => 'ready', 'approval_reason' => 'Desk flow test',
    'release_date' => date('Y-m-d'),
], $jarR, $regToken);
check('ready accepted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
check('now Ready',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id=?', [$reqId]) === 'Ready');

$r = put("$API/documents.php?id=$reqId", ['action' => 'claim', 'official_receipt' => 'OR-1'], $jarR, $regToken);
check('claim accepted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
check('now Claimed',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id=?', [$reqId]) === 'Claimed');

// ── 6. The student's timeline agrees ────────────────────────
echo "\n== the student's tracking page agrees with what happened ==\n";
$r = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$reqId]);
require_once __DIR__ . '/../shared/doc_wizard.php';
$events = $db->fetchAll('SELECT * FROM document_request_events WHERE request_id=? ORDER BY id', [$reqId]);
$tl = doc_track_timeline($r, $events);
$states = array_column($tl, 'state');
check('every step is done', array_unique($states) === ['done'], implode(',', $states));
check('no step is live', !in_array('active', $states, true));
check('the last step says it is complete',
    stripos((string) ($tl[count($tl) - 1]['note'] ?? ''), 'complete') !== false,
    (string) ($tl[count($tl) - 1]['note'] ?? ''));

// And the page renders it.
$ch = curl_init("$base/student/document-track.php?id=$reqId");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
    CURLOPT_COOKIEJAR => $jarS, CURLOPT_COOKIEFILE => $jarS,
]);
$html = (string) curl_exec($ch);
$st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('tracking page renders', $st === 200, "status $st");
check('page shows no live step', substr_count($html, 'wr-tl-item is-active') === 0,
    substr_count($html, 'wr-tl-item is-active') . ' active');
check('page shows six completed steps', substr_count($html, 'wr-tl-item is-done') === 6,
    substr_count($html, 'wr-tl-item is-done') . ' done');

// ── 7. A Claimed request can no longer be cancelled ────────
echo "\n== a collected request is closed to the student ==\n";
$r = post("$API/document-request.php?action=cancel", ['request_id' => $reqId], $jarS, $token);
check('cancel refused once collected', ($r['json']['success'] ?? true) === false);
check('the refusal says why',
    stripos((string) ($r['json']['message'] ?? ''), 'collected') !== false,
    (string) ($r['json']['message'] ?? ''));

// ── 8. There is no courier leg ────────────────────────────────────────
//
// A courier request used to have no route past "signed": Shipped had no
// button and no API transition, so the only way to finish one was to
// claim it in person - which is not what the student paid for. The
// service is withdrawn, so what follows asserts the absence rather than
// the flow: an API that offers a `ship` action nobody can complete is
// worse than one that refuses it.
echo "\n== there is no courier leg to walk ==\n";

// A request is filed the ordinary way, with no fulfillment field sent at
// all. The wizard no longer offers one and the API ignores one, so this
// is exactly what the student's browser sends.
$r = post("$API/document-request.php?action=save_draft", [
    'catalog_id' => (int) $tor['id'], 'wizard_step' => 1, 'quantity' => 1,
    'purpose_code' => 'employment', 'purpose' => 'Counter flow test',
    'payment_method' => 'Counter',
], $jarS, $token);
$pickDraft = (int) ($r['json']['data']['id'] ?? 0);
check('draft saved with no fulfillment field', $pickDraft > 0, substr($r['body'], 0, 160));
check('no delivery fee is quoted',
    !isset($r['json']['data']['delivery_fee']),
    json_encode($r['json']['data']['delivery_fee'] ?? 'absent'));

// A fulfillment type sent anyway is not honoured. The API does not read the
// field, so the column is written from doc_fulfillment() and the request is
// filed as counter collection regardless of what was posted.
$r = post("$API/document-request.php?action=save_draft", [
    'catalog_id' => (int) $tor['id'], 'wizard_step' => 1, 'quantity' => 1,
    'purpose' => 'Posted a fulfillment type', 'fulfillment_type' => 'Delivery',
    'delivery_address' => '12 Katipunan Ave, Quezon City',
    'payment_method' => 'Counter',
], $jarS, $token);
$ignoredDraft = (int) ($r['json']['data']['id'] ?? 0);
check('a posted fulfillment type does not fail the save', $ignoredDraft > 0, substr($r['body'], 0, 160));
check('and it is not written to the row',
    (string) $db->fetchColumn('SELECT fulfillment_type FROM document_requests WHERE id = ?', [$ignoredDraft]) !== 'Delivery',
    (string) $db->fetchColumn('SELECT fulfillment_type FROM document_requests WHERE id = ?', [$ignoredDraft]));

$r = post("$API/document-request.php?action=submit", [
    'request_id' => $pickDraft, 'certify_true' => 1, 'certify_privacy' => 1,
], $jarS, $token);
check('request submitted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
$pickId = (int) ($r['json']['data']['request']['id'] ?? 0);
check('paying at the counter files it as workable',
    ($r['json']['data']['request']['document_status'] ?? '') === 'Filed',
    $r['json']['data']['request']['document_status'] ?? '?');

// Drive it to Ready, then assert the dispatch action is gone.
$r = put("$API/documents.php?id=$pickId", ['action' => 'process'], $jarR, $regToken);
check('request can be processed', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 160));

$r = put("$API/documents.php?id=$pickId", [
    'action' => 'ready', 'approval_reason' => 'Counter flow test',
    'release_date' => date('Y-m-d'),
], $jarR, $regToken);
check('request can be signed', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));

$r = put("$API/documents.php?id=$pickId", ['action' => 'ship'], $jarR, $regToken);
check('dispatch is refused', ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 200));
check('and the row did not move to Shipped',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id = ?', [$pickId]) === 'Ready',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id = ?', [$pickId]));

// Ready is the last thing before the student collects. Claim is the only
// way past it, which is the whole lifecycle now.
$r = put("$API/documents.php?id=$pickId", ['action' => 'claim'], $jarR, $regToken);
check('a signed request can be claimed', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
check('it is now Claimed',
    (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id=?', [$pickId]) === 'Claimed');

// The track is four stations and takes no argument: the parameter that
// spliced in the Shipped station is gone, so a call passing one would be a
// fatal rather than a silent five-station rail.
require_once __DIR__ . '/../shared/document_process.php';
$track = doc_stage_track();
check('the track has 4 stations', count($track) === 4, count($track) . ' stations');
check('and they are Filed, Processing, Ready, Claimed',
    array_column($track, 'key') === ['Filed', 'Processing', 'Ready', 'Claimed'],
    implode(',', array_column($track, 'key')));
check('Ready is two stations along', doc_stage_position('Ready')['index'] === 2,
    json_encode(doc_stage_position('Ready')));
check('a cancelled request left the track', doc_stage_position('Cancelled')['stopped'] === true);

// A legacy Shipped row must still render. It reports at the Ready station
// rather than index 0, so a discontinued courier request does not draw as
// though it had never started, and it offers no next step.
check('a legacy Shipped row reports at the Ready station',
    doc_stage_position('Shipped')['index'] === 2,
    json_encode(doc_stage_position('Shipped')));
check('and offers no next step', doc_next_step(['document_status' => 'Shipped']) === null);
check('while Ready does',
    (doc_next_step(['document_status' => 'Ready'])['action'] ?? '') === 'claim',
    json_encode(doc_next_step(['document_status' => 'Ready'])));

foreach ([$pickId, $ignoredDraft] as $cleanup) {
    if ($cleanup > 0) {
        $db->delete('document_request_events', 'request_id = ?', [$cleanup]);
        $db->delete('document_request_attachments', 'request_id = ?', [$cleanup]);
        $db->delete('document_requests', 'id = ?', [$cleanup]);
    }
}

// ── cleanup ────────────────────────────────────────────────
foreach ($db->fetchAll('SELECT file_path FROM document_request_attachments WHERE request_id=?', [$reqId]) as $a) {
    @unlink(dirname(__DIR__) . '/' . $a['file_path']);
}
$receipt = $db->fetchColumn('SELECT payment_receipt_path FROM document_requests WHERE id=?', [$reqId]);
if ($receipt) { @unlink(dirname(__DIR__) . '/' . $receipt); }
$db->delete('document_request_events', 'request_id = ?', [$reqId]);
$db->delete('document_request_attachments', 'request_id = ?', [$reqId]);
$db->delete('document_requests', 'id = ?', [$reqId]);
@unlink($jarS); @unlink($jarR);

echo "\n----------------------------------------\n";
echo "passed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);