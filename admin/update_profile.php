<?php
require_once __DIR__ . '/includes/functions.php';

$admin = getAuthenticatedAdmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token expired or invalid request. Please try again.');
    header('Location: profile.php');
    exit;
}

$name  = sanitize($_POST['name'] ?? '');
$email = sanitize($_POST['email'] ?? '');

// -------------------------
// Validate Name
// -------------------------
if (empty($name)) {
    setFlash('error', 'Full Name is required.');
    header('Location: profile.php');
    exit;
}

// -------------------------
// Validate Email
// -------------------------
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Please enter a valid email address.');
    header('Location: profile.php');
    exit;
}

// Check for duplicate email in admin table
$check = $pdo->prepare('SELECT id FROM admin WHERE email = ? AND id != ?');
$check->execute([$email, $admin['id']]);
if ($check->fetch()) {
    setFlash('error', 'This email address is already in use by another administrator.');
    header('Location: profile.php');
    exit;
}

// -------------------------
// Update Profile
// -------------------------
$update = $pdo->prepare('UPDATE admin SET name = ?, email = ? WHERE id = ?');
$update->execute([$name, $email, $admin['id']]);

// -------------------------
// Update Session Variables
// -------------------------
$_SESSION['admin_name']  = $name;
$_SESSION['admin_email'] = $email;

// Notify Admin
addNotification($pdo, 'admin', $admin['id'], 'Profile Updated', 'Your administrator profile details were updated.', '/client-flow/admin/profile.php', 'success', 'bi-person-check');

setFlash('success', 'Profile information updated successfully.');
header('Location: profile.php');
exit;