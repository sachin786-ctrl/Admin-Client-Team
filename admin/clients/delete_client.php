<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('clients_delete');

$id    = (int) ($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';

// Token ab compulsory hai (pehle empty token bhi chal jata tha)
if ($id <= 0 || $token === '' || !verifyActionSecurity($token)) {
    setFlash('error', 'Invalid or expired security token. Please try again from the client list.');
    header('Location: list_clients.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, client_code, company_name FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $client = $stmt->fetch();

    if (!$client) {
        setFlash('error', 'Client not found.');
        header('Location: list_clients.php');
        exit;
    }

    $pdo->beginTransaction();
    // Cascade delete all payments belonging to this client or their projects
    $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE (client_id = ? OR project_id IN (SELECT id FROM projects WHERE client_id = ?)) AND deleted_at IS NULL")->execute([$id, $id]);
    // Cascade delete all projects belonging to this client
    $pdo->prepare("UPDATE projects SET deleted_at = NOW() WHERE client_id = ? AND deleted_at IS NULL")->execute([$id]);
    // Delete notifications for this client
    $pdo->prepare("DELETE FROM notifications WHERE user_type = 'client' AND user_id = ?")->execute([$id]);
    // email unique hai, isliye rename taaki wahi email dobara use ho sake. client_code kabhi reuse nahi hota.
    $pdo->prepare("UPDATE clients SET deleted_at = NOW(), status = 0,
                       email = LEFT(CONCAT('deleted_', id, '_', email), 150)
                   WHERE id = ?")->execute([$id]);
    $pdo->commit();

    // Commit ke baad email (original email par)
    $mailRes = sendAccountDeletedEmail('client', $client, $admin);

    $adminAuditEmail = htmlspecialchars($mailRes['admin_email'] ?? $admin['email'] ?? 'Admin');
    $emailNotice = ($mailRes['success'] ?? false)
        ? ' Notification emails sent to client (' . htmlspecialchars($client['email']) . ') and Admin (' . $adminAuditEmail . ').'
        : '';
    setFlash('success', 'Client ' . $client['name'] . ' (' . $client['client_code'] . ') deleted successfully.' . $emailNotice);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setFlash('error', 'Delete error: ' . $e->getMessage());
}

header('Location: list_clients.php');
exit;