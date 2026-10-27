<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: verify_otp.php');
    exit;
}

if (empty($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit;
}

$otp = sanitize($_POST['otp'] ?? '');

if (empty($otp) || strlen($otp) !== 6) {
    setFlash('error', 'Please enter the complete 6-digit OTP.');
    header('Location: verify_otp.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin WHERE email = ? LIMIT 1');
$stmt->execute([$_SESSION['reset_email']]);
$admin = $stmt->fetch();

if (!$admin) {
    setFlash('error', 'Something went wrong. Please start over.');
    header('Location: forgot_password.php');
    exit;
}

// ---- Check condition: OTP correct? ----
if ($admin['otp'] === null || $admin['otp'] !== $otp) {
    setFlash('error', 'Invalid OTP. Please try again.');
    header('Location: verify_otp.php');
    exit;
}

// ---- Check condition: OTP expired? ----
if ($admin['otp_expiry'] === null || strtotime($admin['otp_expiry']) < time()) {
    setFlash('error', 'OTP has expired. Please request a new one.');
    header('Location: verify_otp.php');
    exit;
}

// OTP verified - allow password reset
$_SESSION['otp_verified'] = true;

setFlash('success', 'OTP verified successfully. Please set your new password.');
header('Location: reset_password.php');
exit;
