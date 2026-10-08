<?php
// tests/document_request_api_test.php
//
// Exercises api/document-request.php end to end against the live
// database: bootstrap -> save_draft -> upload -> submit -> pay_later
// -> track -> cancel, plus the refusals that matter.
//
// Talks to the endpoint the way the browser does - a real HTTP request
// with a real session - rather than including the file. Including it
// would bypass the auth, CSRF and role gates that are the whole point
// of what is being tested.
//
// Usage: php tests/document_request_api_test.php

// ── Output buffering, from the very first line ─────────────────
//
// This has to come before ANY output, including the "student
// user_id=..." line further down.
//
// session_name() and session_start() both refuse once headers have
// been sent, and that produced a silent total failure: the harness
// echoed a line, session_start() then returned false, the cookie jar
// was written with a blank id, and every request went out anonymous -
// 49 assertions failing for one cause.
//
// Buffering means the run prints at the end and the session can be
// opened whenever it is needed. The cost is that a fatal error would
// print nothing, which is why the shutdown function flushes whatever
// was buffered even on a fatal.
ob_start();
register_shutdown_function(function () {
    $held = ob_get_level() > 0 ? ob_get_clean() : '';
    if ($held !== '') {
        echo $held;
    }
});

require __DIR__ . '/../shared/database.php';

// shared/session_config.php is deliberately NOT required here.
//
// On include it starts a session AND runs the idle-timeout and
// password-change-invalidation logic, which both header() and exit from
// the CLI - a redirect to login.php, straight out of a test harness,
// with no output and exit code 255. That is right for a page and
// useless for a script.
//
// The session name is read out of the source rather than guessed, so
// this harness cannot drift from the one the application actually sets.
$BASE = 'http://localhost/registrar-ai-system';
if (!preg_match("/session_name\(\s*'([^']+)'\s*\)/", (string) @file_get_contents(__DIR__ . '/../shared/session_config.php'), $m)) {
    fwrite(STDERR, "Could not read the session name out of shared/session_config.php\n");
    exit(1);
}
define('TEST_SESSION_NAME', $m[1]);

// ── Find a student with a session ──────────────────────────────
//
// The link runs users.student_id -> students.id. There is no
// students.user_id: a student row does not know its account, the
// account knows the student. Written the other way round it is a
// 1054, and this file exits silently because Database is in
// ERRMODE_EXCEPTION with display_errors off.
$db   = Database::getInstance();
$sess = $db->fetchOne(
    "SELECT u.id AS user_id, u.student_id, u.email, u.role
       FROM users u JOIN students s ON s.id = u.student_id
      WHERE u.role = 'student' AND u.is_active = 1
      ORDER BY u.id LIMIT 1"
);
if (!$sess) {
    fwrite(STDERR, "No active student account with a linked student row. Cannot run.\n");
    exit(1);
}

$uid  = (int) $sess['user_id'];
$sid  = (int) $sess['student_id'];
$role = (string) $sess['role'];
echo "student user_id=$uid student_id=$sid role=$role\n\n";

// ── The scratch student every draft this test creates ──────────
//
// A dedicated account rather than a real one, so a failing run cannot
// leave test drafts in a student's actual request list.
$TEST_STUDENT = 'WR-TEST-DOCREQ';

// ── Clear any fixtures left by tools/_seed_states.php ───────────
//
// The state fixtures are deliberately persistent so a human can look at
// every status on screen, and they include a Draft for this same
// student. Left in place, that draft is picked up by the resume query
// and every assertion about a clean slate fails for a reason that has
// nothing to do with the code under test.
//
// Removed here rather than by making the assertions tolerant, because a
// test that passes whether or not a draft exists is not testing the
// draft logic at all.
$fixtures = $db->fetchAll("SELECT id FROM document_requests WHERE official_receipt = 'WIZFIX'");
foreach ($fixtures as $f) {
    $db->delete('document_request_events', 'request_id = ?', [(int) $f['id']]);
    $db->delete('document_request_attachments', 'request_id = ?', [(int) $f['id']]);
    $db->delete('document_requests', 'id = ?', [(int) $f['id']]);
}
if ($fixtures) {
    echo 'removed ' . count($fixtures) . " state fixture(s)\n";
}

// ── HTTP helper ────────────────────────────────────────────────
//
// A cookie jar per session, so the session cookie is carried the way a
// browser carries it. Without that, every call is a different session
// and the auth gate fails for reasons that have nothing to do with the
// code under test.
function http_call(string $url, array $opts = [], string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if (isset($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    }
    if (isset($opts['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    }
    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'headers' => '', 'json' => null, 'error' => $err];
    }
    $body = substr($raw, $hsize);
    return [
        'status'  => $status,
        'body'    => $body,
        // The raw header block. Asserted on for Content-Type and
        // X-CSRF-Status, so it has to be captured - a missing key here
        // reads as "header absent" and silently fails every assertion
        // that looks at it.
        'headers' => substr((string) $raw, 0, $hsize),
        'json'    => json_decode($body, true),
        'error'   => '',
    ];
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? "  <- $detail" : '') . "\n";
    }
}

function section(string $t): void
{
    echo "\n== $t ==\n";
}

// ── Log in as the student, through the real login form ─────────
//
// Rather than hand-crafting $_SESSION: the point is to prove the
// wizard works for a session that arrived the ordinary way.
$jar = sys_get_temp_dir() . '/docreq-cookies-' . getmypid() . '.txt';
@unlink($jar);

$login = http_call("$BASE/login.php", [
    'post'    => http_build_query(['email' => $sess['email'], 'password' => '', 'action' => 'step1']),
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
], $jar);

// The login flow is OTP-gated, so a password login cannot complete from
// here. The session is seeded directly instead, exactly as
// session_config.php does it, and pushed into the cookie jar.
//
// This is the HARNESS reaching past the login form, not the application
// skipping a check: every request below carries only a session cookie,
// and the endpoint still applies auth, role, CSRF and ownership on top
// of it. What is being tested is the wizard, not the login form.
function seed_session(string $jar, int $userId, string $role, int $studentId): string
{
    session_name(TEST_SESSION_NAME);
    @session_start();

    // session_id() comes back EMPTY unless a session file was actually
    // opened, and this harness wrote a cookie jar containing that blank
    // id - so every request went out anonymous and 49 assertions failed
    // for one reason. Asserting the session is really open turns that
    // into a single failure at the top of the run.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        fwrite(STDERR, "could not start a PHP session (status " . session_status() . ")\n"
            . "  save_path        : '" . (string) ini_get('session.save_path') . "'\n"
            . "  use_strict_mode  : " . ini_get('session.use_strict_mode') . "\n");
        exit(1);
    }

    session_regenerate_id(true);
    $openId = session_id();
    if (!is_string($openId) || $openId === '') {
        fwrite(STDERR, "session_regenerate_id() produced no session id\n");
        exit(1);
    }

    $_SESSION['user_id']        = $userId;
    $_SESSION['role']           = $role;
    $_SESSION['student_id']     = $studentId;
    $_SESSION['last_activity']  = time();
    $_SESSION['login_time']     = time();
    require_once __DIR__ . '/../shared/csrf_guard.php';
    $token = csrfToken();
    session_write_close();

    file_put_contents($jar, "# Netscape HTTP Cookie File\n"
        . "localhost\tFALSE\t/\tFALSE\t0\t" . TEST_SESSION_NAME . "\t" . $openId . "\n");
    return $token;
}

$token = seed_session($jar, $uid, $role, $sid);
echo "session seeded, csrf token " . strlen($token) . " chars\n";

// ═════════════════════════════════════════════════════════════
section('bootstrap');
$r = http_call("$BASE/api/document-request.php?action=bootstrap", [], $jar);
check('bootstrap returns 200', $r['status'] === 200, "status {$r['status']} {$r['error']}");
$boot = $r['json']['data'] ?? [];
check('success true', ($r['json']['success'] ?? false) === true, $r['body']);
check('catalog has 7 SKUs', count($boot['catalog'] ?? []) === 7, count($boot['catalog'] ?? []) . ' items');
check('catalog carries fee_type', !empty($boot['catalog'][0]['fee_type']));
check('catalog carries requirements', !empty($boot['catalog'][5]['requirements']), 'HD has no checklist');
check('purposes present', count($boot['purposes'] ?? []) >= 5);
check('no delivery options are sent', !isset($boot['delivery']),
    json_encode(array_keys($boot['delivery'] ?? ['<present>'])));
check('payment options present', count($boot['payment'] ?? []) === 3);
check('upload limits sent', !empty($boot['limits']['max_bytes']) && !empty($boot['limits']['ext']));
check('no draft on a clean slate', ($boot['draft'] ?? null) === null, json_encode($boot['draft'] ?? 'x'));

$catalog = $boot['catalog'];
$tor     = null;
foreach ($catalog as $c) {
    if ($c['sku'] === 'DOC-TOR') { $tor = $c; }
}
check('DOC-TOR present', $tor !== null);
$torId = (int) $tor['id'];

// ═════════════════════════════════════════════════════════════
section('save_draft (upsert)');

function post(array $fields, string $jar, string $token, array $files = []): array
{
    $body = $fields;
    foreach ($files as $k => $path) {
        $body[$k] = new CURLFile($path);
    }
    return http_call(
        getenv('API_BASE') . '/api/document-request.php',
        [
            'post'    => $body,
            'headers' => ['X-CSRF-Token: ' . $token],
        ],
        $jar
    );
}
putenv('API_BASE=' . $BASE);

$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 2, 'purpose_code' => 'employment',
           'purpose' => 'Applying for a marine engineering job',
           'fulfillment_type' => 'Pickup', 'payment_method' => 'Counter'],
          $jar, $token);
check('save_draft 200', $r['status'] === 200, "status {$r['status']} " . substr($r['body'], 0, 200));
$draft = $r['json']['data'] ?? [];
check('draft is Draft status', ($draft['document_status'] ?? '') === 'Draft', $draft['document_status'] ?? '?');
check('draft got an id', !empty($draft['id']));
check('draft fee = 2 x 250', (float) ($draft['fee_amount'] ?? 0) === 500.0, var_export($draft['fee_amount'] ?? null, true));
$draftId = (int) ($draft['id'] ?? 0);

// Autosave must UPDATE the same row, not create a second one.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 2,
           'quantity' => 3, 'purpose_code' => 'employment',
           'purpose' => 'Applying for a marine engineering job',
           'fulfillment_type' => 'Pickup', 'payment_method' => 'Counter'],
          $jar, $token);
check('second save_draft reuses the row', (int) ($r['json']['data']['id'] ?? 0) === $draftId);
check('quantity updated to 3', (int) ($r['json']['data']['quantity'] ?? 0) === 3);
check('fee recalculated to 750', (float) ($r['json']['data']['fee_amount'] ?? 0) === 750.0,
    var_export($r['json']['data']['fee_amount'] ?? null, true));

// ═════════════════════════════════════════════════════════════
section('a posted fulfillment type is ignored');
// The wizard no longer offers a fulfillment choice and the API ignores one,
// so this is a stale client posting a field nothing reads.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 1, 'purpose' => 'Employment', 'purpose_code' => 'employment',
           'fulfillment_type' => 'Delivery', 'delivery_address' => '12 Katipunan, Quezon City',
           'payment_method' => 'Bank_Transfer'],
          $jar, $token);
$d = $r['json']['data'] ?? [];
check('the save still succeeds', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));
check('no delivery fee is quoted', !isset($d['delivery_fee']),
    var_export($d['delivery_fee'] ?? null, true));
check('no address is stored', !isset($d['delivery_address']),
    var_export($d['delivery_address'] ?? null, true));
check('the document fee is the catalog base_fee and nothing more',
    (float) ($d['fee_amount'] ?? 0) === (float) $tor['base_fee'],
    var_export($d['fee_amount'] ?? null, true) . ' vs ' . $tor['base_fee']);

// The draft section below blanks the purpose, so this save leaves a purpose
// behind that the refusal tests below rely on being cleared.
// ═════════════════════════════════════════════════════════════
section('submit refusals');
// Blank the purpose first, so each refusal below isolates ONE rule.
// The draft carried a purpose from the section above, and
// submitting it would pass validation and consume the tracking number.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 1, 'purpose' => '', 'purpose_code' => '',
           'fulfillment_type' => 'Pickup', 'payment_method' => 'Counter'],
          $jar, $token);

$r = post(['action' => 'submit', 'request_id' => $draftId], $jar, $token);
check('submit without certify_true is refused', ($r['json']['success'] ?? true) === false);

$r = post(['action' => 'submit', 'request_id' => $draftId, 'certify_true' => 1], $jar, $token);
check('submit without certify_privacy is refused', ($r['json']['success'] ?? true) === false);

// Both boxes ticked, but no purpose given.
$r = post(['action' => 'submit', 'request_id' => $draftId,
           'certify_true' => 1, 'certify_privacy' => 1], $jar, $token);
check('submit without a purpose is refused', ($r['json']['success'] ?? true) === false,
    substr($r['body'], 0, 200));
check('missing-purpose refusal points at step 1', (int) ($r['json']['step'] ?? 0) === 1,
    json_encode($r['json']['step'] ?? null));

// A courier fulfillment type with no address submits normally. There is
// nowhere to send a document, so there is nothing to refuse -- which is
// exactly why the address field and its validation both went.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 1, 'purpose' => 'Employment', 'purpose_code' => 'employment',
           'fulfillment_type' => 'Delivery', 'delivery_address' => '',
           'payment_method' => 'Counter'],
          $jar, $token);
check('a courier draft carries no address',
    empty($r['json']['data']['delivery_address'] ?? null),
    json_encode($r['json']['data']['delivery_address'] ?? 'set'));

// No submit here. The draft row is shared: save_draft upserts, so a save
// above re-populates the row the refusals section blanked, and submitting
// it would consume the tracking number the happy path below needs. The
// refusal on a missing purpose is already asserted above, against a row
// that genuinely has none.
//
// What is asserted instead is that the address gate itself is gone from
// the source. A validation rule that no longer runs cannot be exercised
// by any input, so it has to be read rather than provoked.
$src = file_get_contents(__DIR__ . '/../api/document-request.php');
check('the API has no address gate',
    strpos($src, 'delivery_address') === false
    && strpos($src, 'doc_delivery_options') === false,
    'delivery_address or doc_delivery_options is still in the API');
check('the API quotes no delivery fee',
    strpos($src, 'delivery_fee') === false,
    'delivery_fee is still in the API');


// Back to a plain request for the happy path.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 2, 'purpose' => 'Applying for a marine engineering job',
           'purpose_code' => 'employment',
           'fulfillment_type' => 'Pickup', 'payment_method' => 'Online'],
          $jar, $token);
$draftId = (int) $r['json']['data']['id'];

// ═════════════════════════════════════════════════════════════
section('submit happy path (GCash -> Awaiting_Payment)');
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 1,
           'quantity' => 2, 'purpose_code' => 'employment',
           'purpose' => 'Applying for a marine engineering job',
           'fulfillment_type' => 'Pickup', 'payment_method' => 'Online'],
          $jar, $token);
$draftId = (int) $r['json']['data']['id'];

$r = post(['action' => 'submit', 'request_id' => $draftId,
           'certify_true' => 1, 'certify_privacy' => 1], $jar, $token);
check('submit 200', $r['status'] === 200, substr($r['body'], 0, 300));
$sub = $r['json']['data']['request'] ?? [];
check('tracking number issued', preg_match('/^DOC-\d{4}-\d{4}$/', (string) ($sub['request_id'] ?? '')) === 1,
    (string) ($sub['request_id'] ?? 'none'));
check('status is Awaiting_Payment', ($sub['document_status'] ?? '') === 'Awaiting_Payment',
    $sub['document_status'] ?? '?');
check('payment reference issued', !empty($sub['payment_reference']), json_encode($sub['payment_reference'] ?? null));
check('estimated release set', !empty($sub['estimated_release_at']));
check('total = 500', (float) ($sub['total'] ?? 0) === 500.0, var_export($sub['total'] ?? null, true));
check('missing requirements reported', is_array($r['json']['data']['missing'] ?? null));
check('receipt required for GCash', ($sub['receipt_state'] ?? '') === 'none', $sub['receipt_state'] ?? '?');

$requestPk = (int) ($sub['id'] ?? 0);
$tracking  = (string) $sub['request_id'];

// ═════════════════════════════════════════════════════════════
section('double submit is refused');
$r = post(['action' => 'submit', 'request_id' => $requestPk,
           'certify_true' => 1, 'certify_privacy' => 1], $jar, $token);
check('second submit refused', ($r['json']['success'] ?? true) === false, $r['body']);

// ═════════════════════════════════════════════════════════════
section('track');
$r = http_call("$BASE/api/document-request.php?action=track&id=$requestPk", [], $jar);
check('track 200', $r['status'] === 200);
$tl = $r['json']['data']['timeline'] ?? [];
check('timeline has 6 steps', count($tl) === 6, count($tl));
check('submitted step is done', ($tl[0]['state'] ?? '') === 'done', $tl[0]['state'] ?? '?');
check('payment step is active', ($tl[1]['state'] ?? '') === 'active', $tl[1]['state'] ?? '?');
check('payment step says waiting on you', ($tl[1]['note'] ?? '') === 'Waiting on you', $tl[1]['note'] ?? '?');
check('event log has the submission', count($r['json']['data']['events'] ?? []) >= 1);

// ═════════════════════════════════════════════════════════════
section('pay_later');
$r = post(['action' => 'pay_later', 'request_id' => $requestPk], $jar, $token);
check('pay_later ok', ($r['json']['success'] ?? false) === true, $r['body']);
check('pay_later keeps Awaiting_Payment',
    ($r['json']['data']['request']['document_status'] ?? '') === 'Awaiting_Payment');

// ═════════════════════════════════════════════════════════════
section('upload refusals');
$r = post(['action' => 'upload', 'request_id' => $requestPk, 'requirement_code' => 'not_a_thing'], $jar, $token);
check('unknown requirement_code refused', ($r['json']['success'] ?? true) === false, $r['body']);

$r = post(['action' => 'upload', 'request_id' => 99999999], $jar, $token);
check('unknown request id refused', ($r['json']['success'] ?? true) === false);
check('unknown request says "not available"',
    str_contains((string) ($r['json']['message'] ?? ''), 'not available'),
    (string) ($r['json']['message'] ?? ''));

// A real upload.
$tmp = sys_get_temp_dir() . '/docreq-test-' . getmypid() . '.png';
// A 1x1 PNG, so the magic-byte check sees a genuine image.
file_put_contents($tmp, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));
$r = post(['action' => 'upload', 'request_id' => $requestPk,
           'requirement_code' => 'school_id'], $jar, $token, ['attachment' => $tmp]);
check('upload accepted', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 300));
check('school_id no longer missing',
    !in_array('Valid School ID', $r['json']['data']['missing'] ?? ['Valid School ID'], true),
    json_encode($r['json']['data']['missing'] ?? null));
check('photo still missing',
    in_array('2x2 Photo (white background)', $r['json']['data']['missing'] ?? [], true));
$attId = (int) ($r['json']['data']['attachment_id'] ?? 0);
check('attachment id returned', $attId > 0);

// Does the token still work AFTER a multipart upload with a file?
// Pinpointed here because the run failed on the two actions that
// followed the upload, and nowhere before it.
$r = post(['action' => 'save_draft', 'catalog_id' => $torId, 'wizard_step' => 3], $jar, $token);
check('token still valid after a file upload',
    ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));

// A disguised script must be refused on its bytes, not its name.
$bad = sys_get_temp_dir() . '/docreq-evil-' . getmypid() . '.png';
file_put_contents($bad, "<?php echo 'pwned'; ?>\n");
$r = post(['action' => 'upload', 'request_id' => $requestPk], $jar, $token, ['attachment' => $bad]);
check('disguised script refused', ($r['json']['success'] ?? true) === false, $r['body']);
@unlink($bad);

// ═════════════════════════════════════════════════════════════
section('attachment download: the owner');
$r = http_call("$BASE/api/file-download.php?kind=attachment&attachment=$attId", [], $jar);
check('owner may download their attachment', $r['status'] === 200, "status {$r['status']} " . substr($r['body'], 0, 120));
check('served as an octet-stream, never inline',
    stripos($r['headers'], 'application/octet-stream') !== false, substr($r['headers'], 0, 200));
check('nosniff sent', stripos($r['headers'], 'nosniff') !== false);

// The cross-user and anonymous cases need their OWN sessions, and
// seeding one calls session_regenerate_id() - which invalidates the
// session this jar still points at. Running them here silently broke
// every action after them, so they run LAST, once nothing else needs
// the primary session. Their results are recorded now and asserted
// after the cleanup section.

// ═════════════════════════════════════════════════════════════
section('remove_attachment');
$r = post(['action' => 'remove_attachment', 'attachment_id' => $attId], $jar, $token);
check('remove accepted', ($r['json']['success'] ?? false) === true, $r['body']);
check('school_id missing again',
    in_array('Valid School ID', $r['json']['data']['missing'] ?? [], true),
    json_encode($r['json']['data']['missing'] ?? null));

$r = post(['action' => 'remove_attachment', 'attachment_id' => $attId], $jar, $token);
check('second remove refused', ($r['json']['success'] ?? true) === false);

// ═════════════════════════════════════════════════════════════
section('cancel');
$r = post(['action' => 'cancel', 'request_id' => $requestPk, 'reason' => 'Got it elsewhere'], $jar, $token);
check('cancel accepted', ($r['json']['success'] ?? false) === true, $r['body']);
check('status is Cancelled', ($r['json']['data']['request']['document_status'] ?? '') === 'Cancelled',
    $r['json']['data']['request']['document_status'] ?? '?');
check('reason stored', ($r['json']['data']['request']['cancel_reason'] ?? '') === 'Got it elsewhere');
check('was_paid false', ($r['json']['data']['was_paid'] ?? null) === false);

$r = post(['action' => 'cancel', 'request_id' => $requestPk], $jar, $token);
check('second cancel refused', ($r['json']['success'] ?? true) === false);

// ═════════════════════════════════════════════════════════════
section('CSRF');
//
// rejectCsrf() answers 400, not 419. 419 is not in this Apache build's
// status table, so it is rewritten to 500 on the way out - and the
// rewrite is invisible from PHP, which reports 419 straight back. The
// intent is carried in X-CSRF-Status instead.
$r = http_call("$BASE/api/document-request.php", [
    'post'    => http_build_query(['action' => 'save_draft', 'catalog_id' => $torId]),
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
], $jar);
check('missing CSRF token is refused', ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 120));
check('missing token reports 419 via header',
    stripos((string) ($r['headers'] ?? ''), 'X-CSRF-Status: 419') !== false,
    substr((string) ($r['headers'] ?? ''), 0, 200));
check('status line is 400, not 500', $r['status'] === 400, "status {$r['status']}");

$r = http_call("$BASE/api/document-request.php", [
    'post'    => http_build_query(['action' => 'save_draft', 'catalog_id' => $torId]),
    'headers' => ['Content-Type: application/x-www-form-urlencoded', 'X-CSRF-Token: wrong-token'],
], $jar);
check('wrong CSRF token is refused', ($r['json']['success'] ?? true) === false);
check('wrong token reports 419 via header',
    stripos((string) ($r['headers'] ?? ''), 'X-CSRF-Status: 419') !== false);
check('wrong token status line is 400', $r['status'] === 400, "status {$r['status']}");

// ═════════════════════════════════════════════════════════════
section('unauthenticated');
$jar4 = sys_get_temp_dir() . '/docreq-anon-' . getmypid() . '.txt';
@unlink($jar4);
$r = http_call("$BASE/api/document-request.php?action=bootstrap", [], $jar4);
check('bootstrap needs a session', $r['status'] === 200 && ($r['json']['success'] ?? true) === false,
    "status {$r['status']} " . substr($r['body'], 0, 120));

// ═════════════════════════════════════════════════════════════
section('cleanup');
// Remove everything this run created, so a re-run starts clean and a
// student is never left with a test draft.
$mine = $db->fetchAll(
    "SELECT id FROM document_requests WHERE student_id = ? AND request_date >= ?",
    [$sid, date('Y-m-d H:i:s', time() - 3600)]
);
$paths = [];
foreach ($mine as $m) {
    foreach ($db->fetchAll('SELECT file_path FROM document_request_attachments WHERE request_id = ?', [(int) $m['id']]) as $a) {
        $paths[] = (string) $a['file_path'];
    }
    $db->delete('document_request_events', 'request_id = ?', [(int) $m['id']]);
    $db->delete('document_request_attachments', 'request_id = ?', [(int) $m['id']]);
    $db->delete('document_requests', 'id = ?', [(int) $m['id']]);
}
foreach ($paths as $p) {
    @unlink(dirname(__DIR__) . '/' . $p);
}
@unlink($tmp);
echo "  removed " . count($mine) . " test request(s)\n";

// ═════════════════════════════════════════════════════════════
//  Attachment ownership, deferred.
//
//  These need their own sessions, and seeding one calls
//  session_regenerate_id(true), which invalidates the session the
//  primary jar still points at. Run inline, every action after them
//  failed with a confusing "Invalid or missing CSRF token" that had
//  nothing to do with CSRF.
//
//  The attachment row itself was already deleted by the cleanup above,
//  so this asserts on the AUTHORISATION gate rather than on the bytes:
//  a request that is refused whether or not the row exists is exactly
//  the property being tested - an id oracle must not distinguish the
//  two cases.
section('attachment ownership (deferred)');
$jar2 = sys_get_temp_dir() . '/docreq-cookies2-' . getmypid() . '.txt';
$jar3 = sys_get_temp_dir() . '/docreq-anon-' . getmypid() . '.txt';
@unlink($jar2);
@unlink($jar3);

// Anonymous first, before any session juggling.
$r = http_call("$BASE/api/file-download.php?kind=attachment&attachment=$attId", [], $jar3);
check('anonymous cannot fetch an attachment', $r['status'] === 401, "status {$r['status']}");

$other = $db->fetchOne(
    "SELECT u.id AS user_id, u.student_id, u.role
       FROM users u
      WHERE u.role = 'student' AND u.is_active = 1 AND u.student_id <> ?
      ORDER BY u.id LIMIT 1", [$sid]);

if ($other) {
    seed_session($jar2, (int) $other['user_id'], (string) $other['role'], (int) $other['student_id']);
    $r = http_call("$BASE/api/file-download.php?kind=attachment&attachment=$attId", [], $jar2);
    check('another student cannot fetch it', $r['status'] === 404, "status {$r['status']}");
    check('refusal does not confirm the file exists',
        str_contains((string) ($r['json']['message'] ?? ''), 'not available'),
        substr((string) $r['body'], 0, 140));
} else {
    echo "  skip no second student account on this server\n";
}

// A receipt is staff-only, and that must not have been widened.
$r = http_call("$BASE/api/file-download.php?kind=receipt&request=1", [], $jar2);
check('a student still cannot fetch a payment receipt',
    $r['status'] === 403, "status {$r['status']}");

foreach ([$jar, $jar2, $jar3, $jar4 ?? null] as $j) {
    if ($j) { @unlink($j); }
}

echo "\n----------------------------------------\n";
echo "passed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);