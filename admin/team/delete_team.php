<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('team_manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = $_GET['token'] ?? '';

if ($id <= 0 || $token === '' || !verifyActionSecurity($token)) {
    setFlash('error', 'Invalid security token or request parameter.');
    header('Location: list_team.php');
    exit;
}

if ($id === (int)$admin['id']) {
    setFlash('error', 'You cannot delete your own account.');
    header('Location: list_team.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, role FROM admin WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'Team member not found.');
    header('Location: list_team.php');
    exit;
}

if ($member['role'] === 'super_admin') {
    setFlash('error', 'Super Admin accounts cannot be deleted.');
    header('Location: list_team.php');
    exit;
}

// Is member ne jo records banaye hain unko bacha ke rakho: delete ki jagah deactivate
$owned = 0;
foreach ([['clients', 'created_by'], ['projects', 'created_by'], ['reminder_logs', 'sent_by']] as [$tbl, $col]) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE `$col` = ?");
    $c->execute([$id]);
    $owned += (int) $c->fetchColumn();
}
if ($owned > 0) {
    setFlash('error', "{$member['name']} ne {$owned} client/project/reminder records banaye hain, isliye delete nahi ho sakta. Account ko Deactivate karo.");
    header('Location: list_team.php');
    exit;
}

try {
    $mailRes = sendAccountDeletedEmail('team', $member, $admin);
    $pdo->prepare("DELETE FROM admin WHERE id = ?")->execute([$id]);
} catch (PDOException $e) {
    setFlash('error', 'Delete blocked by linked records. Please deactivate instead.');
    header('Location: list_team.php');
    exit;
}

$adminAuditEmail = htmlspecialchars($mailRes['admin_email'] ?? $admin['email'] ?? 'Admin');
$emailNotice = ($mailRes['success'] ?? false)
    ? ' Notice emails dispatched to member (' . htmlspecialchars($member['email']) . ') and Admin (' . $adminAuditEmail . ').'
    : '';
setFlash('success', 'Team member ' . $member['name'] . ' was permanently removed.' . $emailNotice);
header('Location: list_team.php');
exit;