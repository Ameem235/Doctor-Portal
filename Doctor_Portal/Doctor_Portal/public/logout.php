<?php
/**
 * Doctor Logout Handler
 * 
 * Clears current session and redirects to login portal.
 */

// Start session
session_start();

// Unset all session variables
$_SESSION = [];

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect back to login page
header("Location: login.php");
exit();
?>
