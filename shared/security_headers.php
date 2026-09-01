<?php
// ============================================================
//  SECURITY_HEADERS.PHP  (shared/)
//  Sets security headers to protect against XSS, clickjacking,
//  MIME-sniffing, and other common web vulnerabilities.
// ============================================================

// ── Prevent direct access ──
if (!defined('SECURITY_HEADERS_LOADED')) {
    define('SECURITY_HEADERS_LOADED', true);
}

// ── Set X-Content-Type-Options ──
// Prevents MIME-sniffing (forces browser to respect declared MIME type)
header('X-Content-Type-Options: nosniff');

// ── Set X-Frame-Options ──
// Prevents clickjacking (denies embedding in frames)
header('X-Frame-Options: DENY');

// ── Set X-XSS-Protection ──
// Enables browser's built-in XSS protection
header('X-XSS-Protection: 1; mode=block');

// ── Set Referrer-Policy ──
// Controls how much referrer information is sent
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Set Content-Security-Policy ──
// Controls what resources can be loaded
// Allow: self, fonts, CDN, and inline styles/scripts
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none';"
);

// ── Set Permissions-Policy ──
// Controls which browser features can be used
header('Permissions-Policy: ' .
    'geolocation=(), ' .
    'microphone=(), ' .
    'camera=(), ' .
    'fullscreen=(self)'
);

// ── Set Strict-Transport-Security (HSTS) ──
// Enforces HTTPS (only in production)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// ── Prevent caching of sensitive pages ──
// Disable caching for pages that contain sensitive data
if (strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false ||
    strpos($_SERVER['REQUEST_URI'], 'registrar') !== false) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

// ── Set Content-Type ──
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// ── Session cookie security ──
// Prevent JavaScript access to session cookie
ini_set('session.cookie_httponly', 1);

// Only send cookie over HTTPS in production
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

// Use strict SameSite policy
ini_set('session.cookie_samesite', 'Strict');
?>