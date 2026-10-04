<?php
// ============================================================
//  SHARED/PAYMENT_QR.PHP
//  Where the GCash QR code lives, and whether it is there.
//
//  The office collects document fees against one static GCash QR
//  rather than through a payment gateway. A QR code IS the payment
//  address: there is nothing to negotiate per request, no provider
//  account, and no callback URL to keep alive — the student scans it,
//  pays, and sends the screenshot back for a registrar to check.
//
//  So the image is deliberately a file on disk and not a setting row.
//  A setting would let it be changed at runtime, and this one thing
//  must not be: paying the wrong account is not recoverable by an
//  admin edit afterwards. It changes when someone replaces the file.
//
//  Why the existence check matters: the image is supplied out of
//  band, and a host that has not received it yet would otherwise
//  render a broken-image icon inside a payment screen. A student
//  looking at that has no idea whether they can pay, so the page
//  says plainly that it is not set up and to pay at the counter —
//  which is true, and is the fallback the office actually has.
//
//  Overridable with the GCASH_QR_PATH environment variable for a host
//  that serves assets from somewhere other than the project root.
require_once __DIR__ . '/app_path.php';

// The project root, from this file's own location rather than from the
// APP_ROOT constant. APP_ROOT is defined in config.php, which opens a
// database connection — and this helper is asked whether an image exists,
// which is a question worth answering on a page that never needs the DB.
define('PAYMENT_QR_ROOT', dirname(__DIR__) . '/');

/** Configured path, relative to the project root. No leading slash. */
function gcashQrRelativePath(): string
{
    $p = trim((string) (getenv('GCASH_QR_PATH') ?: ''));
    if ($p !== '') return ltrim($p, '/');
    return 'assets/images/gcash-qr.png';
}

/**
 * The QR image as {url, exists}.
 *
 * `exists` is a real filesystem check rather than a flag, so dropping
 * the file in is all that is needed — there is no second place to
 * remember to update, and no way for the two to disagree.
 *
 * @return array{url:string, exists:bool}
 */
function gcashQrImage(): array
{
    $rel = gcashQrRelativePath();
    return [
        // Root-relative through app_url(), so the student portal's nested
        // path resolves the same as the registrar's.
        'url'     => app_url('/' . $rel),
        'exists'  => is_file(PAYMENT_QR_ROOT . $rel),
    ];
}