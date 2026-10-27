<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: reset_password.php');
    exit;
}

if (empty($_SESSION['reset_email']) || empty($_SESSION['otp_verified'])) {
    setFlash('error', 'Session expired. Please start over.');
    header('Location: forgot_password.php');
    exit;
}

$newPassword     = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// ---- Check condition: fields not empty ----
if (empty($newPassword) || empty($confirmPassword)) {
    setFlash('error', 'Please fill in both password fields.');
    header('Location: reset_password.php');
    exit;
}

// ---- Check condition: minimum length ----
if (strlen($newPassword) < 8) {
    setFlash('error', 'Password must be at least 8 characters long.');
    header('Location: reset_password.php');
    exit;
}

// ---- Check condition: passwords match ----
if ($newPassword !== $confirmPassword) {
    setFlash('error', 'New password and confirm password do not match.');
    header('Location: reset_password.php');
    exit;
}

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$update = $pdo->prepare('UPDATE admin SET password = ?, otp = NULL, otp_expiry = NULL WHERE email = ?');
$update->execute([$hashedPassword, $_SESSION['reset_email']]);

// Clean up reset session data
unset($_SESSION['reset_email'], $_SESSION['otp_verified'], $_SESSION['otp_sent_time']);

setFlash('success', 'Password updated successfully. Please login with your new password.');
header('Location: ../login.php');
exit;
