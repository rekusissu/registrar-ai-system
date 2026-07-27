<?php
// logout.php - CLEAN VERSION

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('BCP_REGISTRAR_SESSION');
    session_start();
}

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Redirect to login
header('Location: login.php');
exit;
?>