<?php
/**
 * accept_agreement.php
 * Transparent forwarder / handler — delegates to view_proposal.php
 * Ensures single source of truth for proposal viewing and digital acceptance.
 */
require_once __DIR__ . '/includes/functions.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
    header('Location: view_proposal.php');
    exit;
}

$sel = $pdo->prepare("
    SELECT p.*,
           c.name AS client_name, c.company_name, c.client_code, c.email AS client_email,
           c.phone AS client_phone, c.address AS client_address
    FROM projects p
    JOIN clients c ON c.id = p.client_id AND c.deleted_at IS NULL
    WHERE p.agreement_token = ? AND p.deleted_at IS NULL
    LIMIT 1
");
$sel->execute([$token]);
$project = $sel->fetch();

if (!$project) {
    header('Location: view_proposal.php?token=' . urlencode($token));
    exit;
}

$projectId  = (int) $project['id'];
$ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$signerName = $project['client_name'];

$isAgreed  = !empty($project['agreed_at']);
$isExpired = !empty($project['token_expires_at']) && strtotime($project['token_expires_at']) < time();
$canAccept = !$isAgreed && !$isExpired && !in_array($project['status'], ['cancelled', 'completed'], true);

/* ---------------- POST: record acceptance ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canAccept) {
    $first = $pdo->prepare("SELECT status, paid_amount, amount FROM payments
                            WHERE project_id = ? AND deleted_at IS NULL
                            ORDER BY is_advance DESC, due_date ASC, id ASC LIMIT 1");
    $first->execute([$projectId]);
    $f = $first->fetch();
    $nextStatus = ($f && ($f['status'] === 'paid' || (float) $f['paid_amount'] >= (float) $f['amount']))
        ? 'in_progress' : 'advance_payment_pending';

    $upd = $pdo->prepare("
        UPDATE projects
        SET status = ?, agreed_at = NOW(), agreed_by_name = ?, agreed_ip = ?
        WHERE id = ? AND agreed_at IS NULL AND deleted_at IS NULL
    ");
    $upd->execute([$nextStatus, $signerName, $ip, $projectId]);

    if ($upd->rowCount() === 1) {
        addProjectLog($pdo, $projectId, 'agreed', 'Proposal & Agreement Digitally Accepted',
            "Client {$signerName} digitally accepted the agreement (IP: {$ip}).", 'client', $signerName);

        addNotification($pdo, 'admin', (int) ($project['created_by'] ?? 1), 'Agreement Confirmed via Email',
            "Client {$signerName} accepted the project proposal for '{$project['title']}' ({$project['project_code']}).",
            "/client-flow/admin/projects/view_project.php?id={$projectId}", 'success', 'bi-file-earmark-check-fill');

        addNotification($pdo, 'client', (int) $project['client_id'], 'Agreement Confirmed',
            "You accepted the agreement for '{$project['title']}'.", '/client-flow/client/projects.php', 'success', 'bi-check2-all');

        syncProjectStatus($pdo, $projectId);

        if (function_exists('sendAgreementConfirmedEmail')) {
            try { sendAgreementConfirmedEmail($pdo, $projectId); } catch (Throwable $e) { /* ignore */ }
        }
    }

    header('Location: view_proposal.php?token=' . urlencode($token) . '&accepted=1');
    exit;
}

// Any GET request seamlessly forwards to view_proposal.php
header('Location: view_proposal.php?token=' . urlencode($token));
exit;