<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
if (function_exists('requirePermission')) requirePermission('payments_delete');

$redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? 'list');
$goto = function (int $projectId = 0) use ($redirect) {
    header('Location: ' . paymentRedirectUrl($redirect === 'list' ? 'payments' : $redirect, $projectId));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    setFlash('warning', 'Please use the Delete button from the invoice list.');
    $goto();
}
if (!sameOriginPost()) {
    setFlash('error', 'Invalid request origin. Please try again.');
    $goto();
}

$who = $admin['name'] ?? 'Admin';

/** Ek invoice soft-delete. Return: [bool ok, string msg, int projectId] */
$deleteOne = function (int $id) use ($pdo, $who): array {
    $st = $pdo->prepare("SELECT id, project_id, invoice_no, title, amount, paid_amount, status
                         FROM payments WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $pay = $st->fetch();
    if (!$pay) return [false, 'Invoice not found.', 0];

    $pid = (int) $pay['project_id'];
    if ((float) $pay['paid_amount'] > 0 || $pay['status'] === 'paid') {
        return [false, "Invoice {$pay['invoice_no']} has recorded payments and cannot be deleted.", $pid];
    }

    $up = $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE id = ? AND paid_amount = 0 AND deleted_at IS NULL");
    $up->execute([$id]);
    if ($up->rowCount() !== 1) return [false, 'Invoice could not be deleted.', $pid];

    addProjectLog($pdo, $pid, 'invoice_deleted', "Invoice Deleted · {$pay['invoice_no']}",
        "'{$pay['title']}' · ₹" . number_format((float) $pay['amount'], 2) . " deleted.", 'admin', $who);
    syncProjectStatus($pdo, $pid);
    return [true, "Invoice {$pay['invoice_no']} deleted successfully.", $pid];
};

// Bulk delete
if (!empty($_POST['payment_ids'])) {
    $ids = array_values(array_filter(array_map('intval', (array) $_POST['payment_ids'])));
    if (!$ids) {
        setFlash('warning', 'No valid invoice selected.');
        $goto();
    }
    $done = 0; $skip = 0;
    foreach ($ids as $pid) {
        try { [$ok] = $deleteOne($pid); } catch (Throwable $e) { $ok = false; }
        $ok ? $done++ : $skip++;
    }
    setFlash($skip ? 'warning' : 'success', "{$done} invoice(s) deleted." . ($skip ? " {$skip} skipped (payments recorded / not found)." : ''));
    $goto();
}

// Single delete
$id = (int) ($_POST['id'] ?? 0);
try {
    [$ok, $msg, $projectId] = $deleteOne($id);
    setFlash($ok ? 'success' : 'error', $msg);
} catch (Throwable $e) {
    $projectId = 0;
    setFlash('error', 'Delete error: ' . $e->getMessage());
}
$goto($projectId);