<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Completely clear all session data
$_SESSION = [];

// 2. Kill the PHP session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

// 3. Clear remember-me cookies on all possible paths
setcookie('remember_admin', '', time() - 3600, '/');
setcookie('remember_admin', '', time() - 3600, '/client-flow/');
setcookie('remember_admin', '', time() - 3600, '/client-flow/admin/');
if (isset($_COOKIE['remember_admin'])) {
    unset($_COOKIE['remember_admin']);
}

// 4. Destroy the server session
session_destroy();

// 5. Start a clean session to pass the success flash
session_start();
$_SESSION['flash'] = [
    'type' => 'success',
    'message' => 'You have been logged out successfully.'
];

// 6. Send anti-cache headers and redirect to login page
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: /client-flow/admin/login.php');
exit;
