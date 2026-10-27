<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: change_password.php');
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword      = $_POST['new_password'] ?? '';
$confirmPassword  = $_POST['confirm_password'] ?? '';

// ---- Check condition: all fields filled ----
if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    setFlash('error', 'All fields are required.');
    header('Location: change_password.php');
    exit;
}

// ---- Check condition: current password correct ----
if (!password_verify($currentPassword, $admin['password'])) {
    setFlash('error', 'Current password is incorrect.');
    header('Location: change_password.php');
    exit;
}

// ---- Check condition: minimum length ----
if (strlen($newPassword) < 8) {
    setFlash('error', 'New password must be at least 8 characters long.');
    header('Location: change_password.php');
    exit;
}

// ---- Check condition: new password matches confirm ----
if ($newPassword !== $confirmPassword) {
    setFlash('error', 'New password and confirm password do not match.');
    header('Location: change_password.php');
    exit;
}

// ---- Check condition: new password must differ from current ----
if (password_verify($newPassword, $admin['password'])) {
    setFlash('error', 'New password must be different from the current password.');
    header('Location: change_password.php');
    exit;
}

$hashed = password_hash($newPassword, PASSWORD_DEFAULT);
$update = $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?');
$update->execute([$hashed, $_SESSION['admin_id']]);

// Notify Admin
addNotification($pdo, 'admin', $admin['id'], 'Password Changed', 'Your administrator security password was updated successfully.', '/client-flow/admin/change_password.php', 'warning', 'bi-shield-lock-fill');

setFlash('success', 'Password changed successfully.');
header('Location: change_password.php');
exit;
