<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
if (function_exists('requirePermission')) requirePermission('projects_delete');

$redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? 'list');
$goto = function () use ($redirect) {
    header('Location: ' . paymentRedirectUrl($redirect === 'dashboard' ? 'dashboard' : 'projects'));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    setFlash('warning', 'Please use the Delete button from the project list.');
    $goto();
}
if (!sameOriginPost()) {
    setFlash('error', 'Invalid request origin. Please try again.');
    $goto();
}

$ids = !empty($_POST['project_ids'])
    ? array_values(array_filter(array_map('intval', (array) $_POST['project_ids'])))
    : array_filter([(int) ($_POST['id'] ?? 0)]);

if (!$ids) {
    setFlash('error', 'Invalid project request.');
    $goto();
}

$exists  = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL");

$deleted = 0;
try {
    foreach ($ids as $pid) {
        $exists->execute([$pid]);
        if (!$exists->fetchColumn()) continue;

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE project_id = ? AND deleted_at IS NULL")->execute([$pid]);
        $pdo->prepare("UPDATE projects SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$pid]);
        $pdo->commit();
        $deleted++;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setFlash('error', 'Delete error: ' . $e->getMessage());
    $goto();
}

if ($deleted > 0) {
    setFlash('success', $deleted > 1 ? "{$deleted} projects and associated milestones deleted successfully." : 'Project and associated milestones deleted successfully.');
} else {
    setFlash('error', 'No valid projects found to delete.');
}
$goto();