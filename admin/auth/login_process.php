<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request. Please try again.');
    header('Location: ../login.php');
    exit;
}

$email    = sanitize($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

if (empty($email) || empty($password)) {
    setFlash('error', 'Email and password are required.');
    header('Location: ../login.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Please enter a valid email address.');
    header('Location: ../login.php');
    exit;
}

// Basic brute-force throttling
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempt_time'] = time();
}
if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['login_attempt_time']) < 300) {
    setFlash('error', 'Too many failed attempts. Please try again after 5 minutes.');
    header('Location: ../login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$admin = $stmt->fetch();

if (!$admin) {
    setFlash('error', 'Email not found. Please check and try again.');
    header('Location: ../login.php');
    exit;
}

if (!password_verify($password, $admin['password'])) {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempt_time'] = time();
    setFlash('error', 'Incorrect password. Please try again.');
    header('Location: ../login.php');
    exit;
}

if (isset($admin['status']) && (int)$admin['status'] === 0) {
    setFlash('error', 'Your staff account has been deactivated. Please contact your Super Admin.');
    header('Location: ../login.php');
    exit;
}

// Success - reset attempts, regenerate session id (session fixation protection)
$_SESSION['login_attempts'] = 0;
session_regenerate_id(true);

$_SESSION['admin_id']    = $admin['id'];
$_SESSION['admin_name']  = $admin['name'];
$_SESSION['admin_email'] = $admin['email'];
$_SESSION['admin_role']  = $admin['role'] ?? 'staff';

if ($remember) {
    $token = bin2hex(random_bytes(32));
    setcookie('remember_admin', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
    // Optional: persist token in DB linked to admin->id for validation on future visits
}

setFlash('success', 'Welcome back, ' . $admin['name'] . '!');
header('Location: ../dashboard.php');
exit;
