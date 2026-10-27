<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';

$email    = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    $_SESSION['client_login_error'] = 'Both email and password are required.';
    header('Location: login.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $client = $stmt->fetch();

    if (!$client || !password_verify($password, $client['password'])) {
        $_SESSION['client_login_error'] = 'Invalid email or password.';
        header('Location: login.php');
        exit;
    }

    if ((int) $client['status'] !== 1) {
        $_SESSION['client_login_error'] = 'Your account is inactive. Please contact the administrator.';
        header('Location: login.php');
        exit;
    }

    // Success: store client session
    $_SESSION['client_id'] = $client['id'];
    header('Location: ../dashboard.php');
    exit;

} catch (PDOException $e) {
    $_SESSION['client_login_error'] = 'Database Error: ' . $e->getMessage();
    header('Location: login.php');
    exit;
}
