<?php
// ============================================================
//  SHARED/APP_PATH.PHP
//  Where is this application, as far as a browser is concerned?
//
//  Split out of config.php because it is the one piece of configuration a
//  PUBLIC page needs and config.php is not free: including config.php opens a
//  mysqli connection, which the queue kiosk and the queue monitor — two pages
//  that are loaded on a wall display all day and must render even when the
//  database is unreachable — have no reason to do.
//
//  Everything else stays in config.php. This file defines no database
//  constants, reads no secrets and touches no connection.
//
//  Why it matters: the depth of the app in the URL is a property of the
//  DEPLOYMENT, not of the code, and it is different in every environment:
//
//      localhost/registrar-ai-system/queue/monitor.php   -> /registrar-ai-system
//      registrar.bcpsms2.com/queue/monitor.php          -> (root)
//
//  Any client that works it out by counting slashes in window.location works
//  in one and 404s in the other. That is not a hypothetical: js/queue.js did
//  exactly that, so on the live site every call from the monitor and the
//  serving console went to /queue/api/queue.php and 404'd. The server knows
//  the answer; the client was guessing.
// ============================================================

if (defined('APP_PATH_LOADED')) {
    return;
}
define('APP_PATH_LOADED', true);

// APP_ROOT is the filesystem path to the app root, with a trailing slash.
// config.php defines the same constant; whichever is included first wins and
// both agree, so a page may include this file alone or config.php with it.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/');
}

/**
 * The app's own directories, relative to the app root.
 *
 * Used ONLY to locate the app inside SCRIPT_NAME when nothing better is
 * available (see strategy 4 below). A page one level down has a first URL
 * segment that must be one of these, which is what tells us how deep the app
 * is mounted. "app" itself is excluded on purpose: a mount point named "app"
 * would then be mistaken for the app root.
 */
const APP_DIRS = [
    'ai', 'api', 'assets', 'includes', 'js', 'css', 'queue',
    'registrar', 'shared', 'student', 'uploads', 'vendor',
];

/**
 * Root-relative URL path to the application base, e.g. "/registrar-ai-system".
 * Used for Header('Location: ...') redirects so they resolve from any depth
 * (root pages, registrar/, student/, api/, ai/). Falls back to "/" when the
 * doc root mapping can't be inferred.
 */
if (!function_exists('app_base_path')) {
    function app_base_path(): string {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $script   = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $docRoot  = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $fsApp    = rtrim(str_replace('\\', '/', APP_ROOT), '/');
        $fsPage   = rtrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? '')), '/');

        // ── 1. DOCUMENT_ROOT usable ────────────────────────────────────────
        // The app sits under the doc root, so the base is whatever remains
        // after stripping the doc root off. This is the only strategy that is
        // correct by construction, and it is what XAMPP and most Apache hosts
        // give us.
        //
        // strncmp, not str_starts_with: this is the one place the app needs a
        // path test on every request that touches app_url(), and str_starts_with
        // is PHP 8.0+ - a fatal on 7.x, not a fallback. registrar/documents.php
        // calls app_url() at the top level of the page, so it was the only
        // registrar page that died on a PHP 7 host while the rest rendered fine.
        if ($docRoot !== '' && strncmp($fsApp, $docRoot, strlen($docRoot)) === 0) {
            $base = rtrim(substr($fsApp, strlen($docRoot)), '/'); // /registrar-ai-system
            return $base;
        }

        // ── 2. The page's own URL tells us the depth ────────────────────────
        // DOCUMENT_ROOT was unusable, so fall back to SCRIPT_NAME and work
        // out how far below the app root this page sits.
        //
        // This used to strip only the FILENAME off SCRIPT_NAME:
        //
        //     /registrar-ai-system/queue/monitor.php  ->  /registrar-ai-system/queue
        //
        // which is the directory of the page, not the base of the app. Every
        // caller that asked for app_url('/api') from a page one level down
        // then got /registrar-ai-system/queue/api - a 404 for a path that does
        // not exist. Root pages were the only ones that worked, so the bug was
        // invisible on login.php and fatal on every other page.
        //
        // And the correction then depended on SCRIPT_FILENAME, which a
        // FastCGI box or nginx does not reliably provide. With it missing, the
        // depth counted as 0 and the page's own directory came back as the
        // base: app_url('/api') on the student portal became /student/api, and
        // the GCash QR became /student/assets/images/gcash-qr.png. Both 404,
        // which is what stopped the New Document Request screen working on
        // hosting while it was fine under XAMPP.
        if ($script !== '' && $script[0] === '/') {
            $parts = explode('/', trim($script, '/'));

            // How many directories this page sits BELOW the app root, derived
            // from where the page's file is on disk relative to APP_ROOT. This
            // is the accurate measure and is preferred when available.
            $below = 0;
            if ($fsPage !== '' && strncmp($fsPage, $fsApp . '/', strlen($fsApp) + 1) === 0) {
                $rel    = trim(substr($fsPage, strlen($fsApp) + 1), '/');
                $relDir = trim(str_replace('\\', '/', dirname($rel)), './');
                $below  = $relDir === '' || $relDir === '.' ? 0 : substr_count($relDir, '/') + 1;
            } else {
                // SCRIPT_FILENAME is missing or points outside APP_ROOT, so the
                // depth is read off the URL itself: the first segment of a
                // nested page is one of the app's own directories. That is a
                // reliable marker on every host - these names are fixed by the
                // repository, not by the deployment - and it needs nothing from
                // the server but SCRIPT_NAME.
                $below = 0;
                foreach ($parts as $i => $seg) {
                    if (in_array($seg, APP_DIRS, true)) { $below = count($parts) - 1 - $i; break; }
                }
            }

            // 1 for the filename itself, plus one per directory below the root.
            $parts = array_slice($parts, 0, max(0, count($parts) - 1 - $below));
            $base  = $parts ? '/' . implode('/', $parts) : '';
            return $base;
        }
        return $base = '';
    }
}

/**
 * Root-relative URL to a resource, e.g. app_url('/login.php') → "/registrar-ai-system/login.php".
 */
if (!function_exists('app_url')) {
    function app_url(?string $path = null): string {
        $base = rtrim(app_base_path(), '/');
        if ($path === null || $path === '' || $path === '/') {
            return ($base === '' ? '/' : $base . '/');
        }
        $path = '/' . ltrim($path, '/');
        return $base . $path;
    }
}
