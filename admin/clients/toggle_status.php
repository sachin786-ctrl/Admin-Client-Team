<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('clients_manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = $_GET['token'] ?? '';

if ($id <= 0 || !verifyActionSecurity($token)) {
    setFlash('error', 'Invalid security token or request parameter.');
    header('Location: list_clients.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, client_code, company_name, status FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    setFlash('error', 'Client not found.');
    header('Location: list_clients.php');
    exit;
}

$newStatus = ((int)$client['status'] === 1) ? 0 : 1;
$upStmt = $pdo->prepare("UPDATE clients SET status = ? WHERE id = ?");
$upStmt->execute([$newStatus, $id]);

// Dispatch email notification to client AND admin audit copy
$mailRes = sendAccountStatusEmail('client', $client, $newStatus, $admin);

// In-app notification
$statusLabel = ($newStatus === 1) ? 'activated' : 'deactivated';
addNotification(
    $pdo,
    'client',
    $id,
    'Account ' . ucfirst($statusLabel),
    "Your client portal account was {$statusLabel} by administrator {$admin['name']}.",
    '/client-flow/client/profile.php',
    ($newStatus === 1 ? 'success' : 'warning'),
    ($newStatus === 1 ? 'bi-person-check' : 'bi-person-slash')
);

$adminAuditEmail = htmlspecialchars($mailRes['admin_email'] ?? $admin['email'] ?? 'Admin');
$emailNotice = ($mailRes['success'] ?? false) 
    ? ' Notification emails sent to client (' . htmlspecialchars($client['email']) . ') and Admin (' . $adminAuditEmail . ').' 
    : '';
setFlash('success', 'Client ' . $client['name'] . ' (' . $client['client_code'] . ') has been ' . $statusLabel . '.' . $emailNotice);
header('Location: list_clients.php');
exit;
