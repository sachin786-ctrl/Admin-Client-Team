<?php
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin WHERE email = ? LIMIT 1');
$stmt->execute([$_SESSION['reset_email']]);
$admin = $stmt->fetch();

if (!$admin) {
    setFlash('error', 'Something went wrong. Please try again.');
    header('Location: forgot_password.php');
    exit;
}

$otp = generateOtp();
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

$update = $pdo->prepare('UPDATE admin SET otp = ?, otp_expiry = ? WHERE id = ?');
$update->execute([$otp, $expiry, $admin['id']]);

$sent = sendOtpEmail($admin['email'], $admin['name'], $otp);

if ($sent) {
    setFlash('success', 'A new OTP has been sent to your email.');
} else {
    setFlash('error', 'Failed to resend OTP. Please try again.');
}

header('Location: verify_otp.php');
exit;
