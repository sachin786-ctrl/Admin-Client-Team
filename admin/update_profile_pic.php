<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token expired or invalid request. Please try again.');
    header('Location: profile.php');
    exit;
}

$result = uploadImage('profile_pic', 'profile', $admin['profile_pic']);

if (!$result['success']) {
    setFlash('error', $result['message']);
    header('Location: profile.php');
    exit;
}

$update = $pdo->prepare('UPDATE admin SET profile_pic = ? WHERE id = ?');
$update->execute([$result['fileName'], $admin['id']]);

// Notify Admin
addNotification($pdo, 'admin', $admin['id'], 'Profile Photo Updated', 'Your profile picture has been updated.', '/client-flow/admin/profile.php', 'success', 'bi-image');

setFlash('success', 'Profile picture updated successfully.');
header('Location: profile.php');
exit;
