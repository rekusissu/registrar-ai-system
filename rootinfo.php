<?php
// ============================================================
//  ROOTINFO.PHP
//  phpinfo() configuration viewer - ADMIN ONLY.
//  Exposes detailed PHP/server configuration, so only
//  authenticated admin accounts may open it.
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
requireRole('admin');

phpinfo();
