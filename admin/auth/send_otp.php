<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: forgot_password.php');
    exit;
}

$email = sanitize($_POST['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Please enter a valid email address.');
    header('Location: forgot_password.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$admin = $stmt->fetch();

// ---- Check condition: email exists or not ----
if (!$admin) {
    setFlash('error', 'Email not found. Please check the email address and try again.');
    header('Location: forgot_password.php');
    exit;
}

$otp = generateOtp();
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

$update = $pdo->prepare('UPDATE admin SET otp = ?, otp_expiry = ? WHERE id = ?');
$update->execute([$otp, $expiry, $admin['id']]);

$sent = sendOtpEmail($admin['email'], $admin['name'], $otp);

if (!$sent) {
    // If on localhost and SMTP fails (e.g. firewall or offline), allow seamless local testing
    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');
    if ($isLocalhost) {
        $_SESSION['reset_email'] = $admin['email'];
        $_SESSION['otp_sent_time'] = time();
        setFlash('warning', 'Notice: Email delivery failed (check mail_config.php). For local testing, your OTP is: ' . $otp);
        header('Location: verify_otp.php');
        exit;
    }

    setFlash('error', 'Failed to send OTP email. Please check SMTP settings in config/mail_config.php and try again.');
    header('Location: forgot_password.php');
    exit;
}

$_SESSION['reset_email'] = $admin['email'];
$_SESSION['otp_sent_time'] = time();

setFlash('success', 'An OTP has been sent to your email address.');
header('Location: verify_otp.php');
exit;
