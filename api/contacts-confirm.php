<?php
// ============================================================
//  API/CONTACTS-CONFIRM.PHP
//  Email-link confirmation for the Emergency & Contacts module.
//
//  The recipient clicks "Confirm Receipt" in the Test Email and lands
//  here with ?token=… . This endpoint deliberately has NO session and
//  NO CSRF — the auth_token is the only credential, so the page works
//  in any browser (including the contact's own, who is not a user of
//  this system). Modeled on api/paymongo-webhook.php's minimal no-auth
//  bootstrap — do NOT include session_config.php / csrf_guard.php here.
//
//  On success:
//    * contact_recipients.verified  → 1, auth_token + expiry cleared
//    * the matching communication_log 'test' row flips to 'verified'
//  On a bad/expired/missing token: a friendly "link invalid" page.
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex');

/** Minimal local escape (mail_client.php's e_() isn't needed here). */
function confirm_e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Modern status badge: animated SVG shield-check for success, amber warning
 * glyph for errors, plain emoji fallback for anything else. confirm_page()
 * stays emoji-agnostic so all six call sites keep working without loading
 * Font Awesome on this standalone (session-free) page.
 */
function confirm_icon(string $icon): string {
    if ($icon === '✅') {
        return '<div class="icon success">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path class="ic-shield" d="M12 3l7 2.8v5.4c0 4.3-2.9 7.6-7 9.3-4.1-1.7-7-5-7-9.3V5.8z"/>'
            . '<path class="ic-check" d="M8.8 12l2.3 2.3 4.1-4.3"/>'
            . '</svg></div>';
    }
    if ($icon === '⚠️') {
        return '<div class="icon warning">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round">'
            . '<path d="M12 7.5v6"/>'
            . '<circle cx="12" cy="17" r="1" fill="#fff" stroke="none"/>'
            . '</svg></div>';
    }
    return '<div class="icon">' . $icon . '</div>';
}

function confirm_page(string $title, string $body, string $icon = '✅'): never {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . confirm_e($title) . '</title>'
        . '<style>
            *{box-sizing:border-box}
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
                 padding:24px;background:linear-gradient(160deg,#f0f4f9 0%,#e7ecf3 100%);
                 font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;color:#0d1b2e}
            .card{background:#fff;border-radius:24px;box-shadow:0 24px 60px rgba(26,58,140,.16);
                  max-width:460px;width:100%;overflow:hidden;position:relative;animation:rise .45s cubic-bezier(.16,1,.3,1)}
            @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
            .card::before{content:"";position:absolute;top:-70px;right:-70px;width:220px;height:220px;border-radius:50%;
                          background:radial-gradient(circle,rgba(37,99,235,.10) 0%,transparent 70%)}
            .head{background:linear-gradient(135deg,#1a3a8c 0%,#2563eb 100%);padding:30px 24px 26px;text-align:center;position:relative;overflow:hidden}
            .head::after{content:"";position:absolute;left:-50px;bottom:-90px;width:200px;height:200px;border-radius:50%;
                         background:radial-gradient(circle,rgba(255,255,255,.14) 0%,transparent 70%)}
            .logo{width:64px;height:64px;margin:0 auto;padding:7px;background:#fff;border-radius:16px;
                  box-shadow:0 8px 24px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
            .logo img{width:100%;height:100%;object-fit:contain}
            .org{color:rgba(255,255,255,.9);font-size:10.5px;letter-spacing:1.6px;font-weight:700;text-transform:uppercase;margin-top:16px;position:relative;z-index:1}
            .org small{display:block;color:rgba(255,255,255,.6);font-size:10px;letter-spacing:.8px;margin-top:4px;font-weight:600}
            .body{padding:30px 34px 26px;text-align:center;position:relative;z-index:1}
            .icon{width:76px;height:76px;border-radius:50%;margin:0 auto 20px;font-size:34px;line-height:1;
                  display:flex;align-items:center;justify-content:center;
                  background:#eef4ff;box-shadow:0 8px 20px rgba(37,99,235,.18);
                  animation:pop .55s cubic-bezier(.34,1.56,.64,1) .05s backwards}
            @keyframes pop{from{transform:scale(.55);opacity:0}to{transform:scale(1);opacity:1}}
            .icon.success{background:linear-gradient(135deg,#34d399 0%,#059669 100%);box-shadow:0 12px 28px rgba(5,150,105,.35)}
            .icon.warning{background:linear-gradient(135deg,#fbbf24 0%,#d97706 100%);box-shadow:0 12px 28px rgba(217,119,6,.35)}
            .icon svg{width:38px;height:38px}
            .ic-shield,.ic-check{stroke-dasharray:80;stroke-dashoffset:80;animation:draw .6s cubic-bezier(.65,0,.45,1) forwards}
            .ic-check{animation-delay:.22s}
            @keyframes draw{to{stroke-dashoffset:0}}
            h1{font-size:22px;font-weight:800;margin:0 0 12px;letter-spacing:-.5px}
            p{color:#475569;font-size:14.5px;line-height:1.65;margin:0;font-weight:500}
            p strong{color:#0f172a}
            .foot{margin-top:26px;padding-top:18px;border-top:1px dashed #dce2ea;
                  font-size:11px;color:#94a3b8;letter-spacing:.4px;font-weight:600;line-height:1.6}
            .foot b{color:#64748b}
          </style></head><body>'
        . '<div class="card">'
        . '<div class="head"><div class="logo"><img src="../assets/images/BCP_LOGO.png" alt="BCP"></div>'
        . '<div class="org">Bestlink College of the Philippines<small>Office of the Registrar</small></div></div>'
        . '<div class="body">'
        . confirm_icon($icon)
        . '<h1>' . confirm_e($title) . '</h1>'
        . '<p>' . $body . '</p>'
        . '<div class="foot">Verified by the <b>Registrar Communication System</b><br>This link can be used only once and expires after 24 hours.</div>'
        . '</div></div></body></html>';
    exit;
}

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        confirm_page('Link not recognized', 'This confirmation link is missing its token. Please request a new Test Email from the student portal.');
    }

    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s'); // PHP wall clock — never MySQL NOW() (timezone skew)

    // The token is one-time: find it, then immediately burn it.
    $contact = $db->fetchOne(
        'SELECT * FROM contact_recipients WHERE auth_token = ? LIMIT 1',
        [$token]
    );

    if (!$contact) {
        confirm_page('Link already used or invalid', 'This confirmation link is no longer valid. It may have already been confirmed, or the token has been replaced.');
    }

    $expires = (string) ($contact['token_expires_at'] ?? '');
    if ($expires !== '' && strtotime($expires) < time()) {
        confirm_page('Link expired', 'This confirmation link expired. Please ask the student to send a new Test Email.');
    }

    // Valid → mark verified and clear the token (one-time use).
    $db->update(
        'contact_recipients',
        ['verified' => 1, 'auth_token' => null, 'token_expires_at' => null, 'updated_at' => $now],
        'id = ?',
        [(int) $contact['id']]
    );

    // Flip the audit trail: the most recent 'sent' test email for this
    // contact becomes 'verified'.
    $db->query(
        "UPDATE communication_log
            SET status = 'verified', detail = CONCAT(COALESCE(detail, ''), ' Confirmed via email link on " . $now . ".')
          WHERE contact_id = ? AND message_type = 'test' AND status = 'sent'
          ORDER BY id DESC LIMIT 1",
        [(int) $contact['id']]
    );

    confirm_page(
        'Email confirmed',
        'Thank you, <strong>' . confirm_e($contact['full_name'] ?? '') . '</strong>. This address is now a verified contact for '
        . 'communications from the Office of the Registrar. You may close this page.',
        '✅'
    );
} catch (Throwable $e) {
    error_log('[contacts-confirm] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    confirm_page('Something went wrong', 'We could not complete the confirmation right now. Please try again later.', '⚠️');
}
