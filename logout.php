<?php
/**
 * OFFGRID - Safe Session Termination (Logout)
 * 
 * Securely invalidates user session by:
 * - Unsetting all $_SESSION keys
 * - Clearing the session cookie from the client's browser
 * - Calling session_destroy()
 * - Starting a fresh flash context and redirecting to the login screen
 */

require_once __DIR__ . '/includes/functions.php';

init_session();

// 1. Unset all session variables
$_SESSION = [];

// 2. Invalidate the session cookie if present
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

// 3. Destroy session on server
session_destroy();

// 4. Start a fresh session to pass a confirmation flash notice
init_session();
set_flash('info', 'You have been successfully logged out. See you on the trail!');
redirect('login.php');
