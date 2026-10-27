<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
if (function_exists('requirePermission')) requirePermission('payments_record');

$id       = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');

$st = $pdo->prepare("SELECT project_id FROM payments WHERE id = ? AND deleted_at IS NULL");
$st->execute([$id]);
$projectId = (int) $st->fetchColumn();

if (!$projectId) {
    setFlash('error', 'Invoice not found.');
    header('Location: ' . paymentRedirectUrl('payments'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    setFlash('warning', 'Please use the Record Payment button to record a payment.');
    header('Location: ' . paymentRedirectUrl($redirect, $projectId));
    exit;
}

if (!sameOriginPost()) {
    setFlash('error', 'Invalid request origin. Please try again.');
    header('Location: ' . paymentRedirectUrl($redirect, $projectId));
    exit;
}
$token = $_POST['csrf_token'] ?? $_POST['token'] ?? $_GET['token'] ?? '';
if (!verifyActionSecurity($token)) {
    setFlash('error', 'Security token expired. Please reload the page and try again.');
    header('Location: ' . paymentRedirectUrl($redirect, $projectId));
    exit;
}

$r = recordPayment(
    $pdo,
    $id,
    (float) ($_POST['paid_amount'] ?? 0),
    trim($_POST['payment_method'] ?? ''),
    trim($_POST['paid_date'] ?? date('Y-m-d')),
    $_POST['transaction_id'] ?? null,
    $admin
);

if ($r['ok']) {
    setFlash('success', $r['status'] === 'paid'
        ? "{$r['invoice']}: Payment of ₹" . number_format($r['received'], 2) . ' recorded. Invoice fully settled.'
        : "{$r['invoice']}: Payment of ₹" . number_format($r['received'], 2) . ' recorded. Balance due: ₹' . number_format($r['remaining'], 2));
} else {
    setFlash('error', $r['msg']);
}

header('Location: ' . paymentRedirectUrl($redirect, $projectId));
exit;