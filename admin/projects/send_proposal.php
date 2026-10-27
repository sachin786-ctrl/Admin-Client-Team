<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
if (function_exists('requirePermission')) {
    requirePermission('projects_manage');
}

$id       = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? 'view');
$token    = $_POST['csrf_token'] ?? $_GET['token'] ?? '';

$goto = function () use ($id, $redirect) {
    if ($redirect === 'proposal')      header("Location: view_proposal.php?id={$id}");
    elseif ($redirect === 'list')      header("Location: list_projects.php");
    else                               header("Location: view_project.php?id={$id}");
    exit;
};

if ($id <= 0) {
    setFlash('error', 'Invalid project ID.');
    header('Location: list_projects.php');
    exit;
}

// CSRF: If a token is supplied, verify it; otherwise rely on authenticated admin session
if (!empty($token) && !verifyActionSecurity($token)) {
    if (empty($admin['id'])) {
        setFlash('error', 'Security token missing or expired. Please try again from the project page.');
        $goto();
    }
}

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS client_name, c.email AS client_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id AND c.deleted_at IS NULL
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    setFlash('error', 'Project not found or has been deleted.');
    header('Location: list_projects.php');
    exit;
}

// Agreed / cancelled / completed project par proposal dobara nahi bhejna
if (!empty($project['agreed_at'])) {
    setFlash('warning', 'Client has already accepted this proposal. It cannot be sent again.');
    $goto();
}
if (in_array($project['status'], ['cancelled', 'completed'], true)) {
    setFlash('warning', 'Proposal cannot be sent for a ' . $project['status'] . ' project.');
    $goto();
}

if (empty($project['client_email']) || !filter_var($project['client_email'], FILTER_VALIDATE_EMAIL)) {
    setFlash('error', "Client '{$project['client_name']}' does not have a valid email address configured.");
    $goto();
}

$res = sendProjectProposalEmail($pdo, $id);

if (!empty($res['success'])) {
    setFlash('success', "Proposal and digital agreement link successfully dispatched to {$project['client_name']} ({$project['client_email']}).");
} else {
    setFlash('error', "Failed to send proposal email: " . ($res['message'] ?? 'Unknown mail error'));
}

$goto();