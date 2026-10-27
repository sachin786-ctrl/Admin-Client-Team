<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('team_manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = $_GET['token'] ?? '';

if ($id <= 0 || !verifyActionSecurity($token)) {
    setFlash('error', 'Invalid security token or request parameter.');
    header('Location: list_team.php');
    exit;
}

if ($id === (int)$admin['id']) {
    setFlash('error', 'You cannot deactivate your own account.');
    header('Location: list_team.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, role, status FROM admin WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'Team member not found.');
    header('Location: list_team.php');
    exit;
}

if ($member['role'] === 'super_admin') {
    setFlash('error', 'Super Admin accounts cannot be deactivated.');
    header('Location: list_team.php');
    exit;
}

$newStatus = ((int)$member['status'] === 1) ? 0 : 1;
$upStmt = $pdo->prepare("UPDATE admin SET status = ? WHERE id = ?");
$upStmt->execute([$newStatus, $id]);

// Dispatch email notification to team member AND admin audit copy
$mailRes = sendAccountStatusEmail('team', $member, $newStatus, $admin);

$statusLabel = ($newStatus === 1) ? 'activated' : 'deactivated';
$adminAuditEmail = htmlspecialchars($mailRes['admin_email'] ?? $admin['email'] ?? 'Admin');
$emailNotice = ($mailRes['success'] ?? false) 
    ? ' Notification emails sent to member (' . htmlspecialchars($member['email']) . ') and Admin (' . $adminAuditEmail . ').' 
    : '';
setFlash('success', 'Team member ' . $member['name'] . ' has been ' . $statusLabel . '.' . $emailNotice);
header('Location: list_team.php');
exit;
