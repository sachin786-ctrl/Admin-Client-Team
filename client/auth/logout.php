<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];

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

setcookie('remember_client', '', time() - 3600, '/');
setcookie('remember_client', '', time() - 3600, '/client-flow/');
setcookie('remember_client', '', time() - 3600, '/client-flow/client/');
if (isset($_COOKIE['remember_client'])) {
    unset($_COOKIE['remember_client']);
}

session_destroy();

session_start();
$_SESSION['client_login_success'] = 'You have been successfully logged out.';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: /client-flow/client/auth/login.php');
exit;
