<?php
/**
 * Shared Payment & Billing Helpers
 * Location: admin/includes/payment_helpers.php
 */

if (!defined('PAYMENT_METHODS')) {
    define('PAYMENT_METHODS', [
        'UPI / QR Code',
        'Bank Transfer (NEFT/IMPS/RTGS)',
        'Cash',
        'Cheque',
        'Credit / Debit Card',
        'PayPal / International',
        'Other'
    ]);
}

if (!defined('PRJ_BLOCK_PAYMENT')) {
    define('PRJ_BLOCK_PAYMENT', ['draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled']);
}

if (!defined('PAYABLE_WHERE')) {
    define('PAYABLE_WHERE', "pay.deleted_at IS NULL AND p.deleted_at IS NULL
        AND p.status NOT IN ('draft','proposal_sent','waiting_confirmation','revision_requested','cancelled')
        AND pay.paid_amount < pay.amount");
}

if (!function_exists('sameOriginPost')) {
    /** POST request origin check */
    function sameOriginPost(): bool
    {
        $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if ($src === '') return true;
        $a = parse_url($src, PHP_URL_HOST);
        $b = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        return $a !== null && $a === $b;
    }
}

if (!function_exists('paymentRedirectUrl')) {
    function paymentRedirectUrl(string $key, int $projectId = 0): string
    {
        $base = '/client-flow/admin/';
        switch ($key) {
            case 'project':     return $base . 'projects/view_project.php?id=' . $projectId;
            case 'projects':    return $base . 'projects/list_projects.php';
            case 'add_payment': return $base . 'payments/add_payment.php?project_id=' . $projectId;
            case 'dashboard':   return $base . 'dashboard.php';
            default:            return $base . 'payments/list_payments.php';
        }
    }
}

if (!function_exists('nextInvoiceNo')) {
    /** Next invoice number (invoice_no UNIQUE) */
    function nextInvoiceNo(PDO $pdo): string
    {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no, 5) AS UNSIGNED)), 1000) FROM payments"
        )->fetchColumn();
        return 'INV-' . ($max + 1);
    }
}

if (!function_exists('projectBilling')) {
    /** Project billed / paid */
    function projectBilling(PDO $pdo, int $projectId, int $excludePaymentId = 0): array
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS billed, COALESCE(SUM(paid_amount),0) AS paid
                             FROM payments WHERE project_id = ? AND deleted_at IS NULL AND id != ?");
        $st->execute([$projectId, $excludePaymentId]);
        $r = $st->fetch();
        return ['billed' => (float) $r['billed'], 'paid' => (float) $r['paid']];
    }
}

if (!function_exists('canRecordPayment')) {
    /** Invoice payment eligibility check */
    function canRecordPayment(array $pay): array
    {
        if (!empty($pay['deleted_at']))
            return [false, 'This invoice has been deleted.'];
        if (in_array($pay['project_status'], PRJ_BLOCK_PAYMENT, true))
            return [false, "Client confirmation pending or project is cancelled. Payment cannot be recorded."];
        if ($pay['status'] === 'paid' || (float) $pay['paid_amount'] >= (float) $pay['amount'] - 0.005)
            return [false, "Invoice {$pay['invoice_no']} is already fully settled."];
        return [true, ''];
    }
}

if (!function_exists('recordPayment')) {
    /**
     * Primary transaction-safe route to record payments.
     */
    function recordPayment(PDO $pdo, int $paymentId, float $amount, string $method, string $paidDate, ?string $txnId, array $admin): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0)
            return ['ok' => false, 'msg' => 'Amount must be greater than zero.'];
        $method = mb_substr(trim($method), 0, 50);
        if ($method === '')
            return ['ok' => false, 'msg' => 'Please select a payment method.'];

        $d = DateTime::createFromFormat('Y-m-d', $paidDate);
        if (!$d || $d->format('Y-m-d') !== $paidDate)
            return ['ok' => false, 'msg' => 'Invalid payment date.'];
        if ($paidDate > date('Y-m-d'))
            return ['ok' => false, 'msg' => 'Payment date cannot be in the future.'];

        $txnId = ($txnId !== null && trim($txnId) !== '') ? mb_substr(trim($txnId), 0, 100) : null;

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("
                SELECT pay.*, prj.status AS project_status, prj.agreed_at, prj.project_code,
                       prj.created_at AS project_created_at
                FROM payments pay
                JOIN projects prj ON prj.id = pay.project_id
                WHERE pay.id = ? AND pay.deleted_at IS NULL
                FOR UPDATE");
            $st->execute([$paymentId]);
            $pay = $st->fetch();

            if (!$pay) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Invoice not found.']; }

            [$ok, $why] = canRecordPayment($pay);
            if (!$ok) { $pdo->rollBack(); return ['ok' => false, 'msg' => $why]; }

            $created = date('Y-m-d', strtotime($pay['project_created_at']));
            if ($paidDate < $created) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Payment date cannot be earlier than project creation date: ' . date('d M Y', strtotime($created)) . '.'];
            }

            $total     = (float) $pay['amount'];
            $already   = (float) $pay['paid_amount'];
            $remaining = round($total - $already, 2);

            if ($amount > $remaining + 0.005) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Payment amount exceeds remaining balance due: ₹' . number_format($remaining, 2) . '.'];
            }

            $newPaid   = round($already + $amount, 2);
            $newStatus = ($newPaid >= $total - 0.005) ? 'paid' : 'partial';

            try {
                $pdo->prepare("INSERT INTO payment_transactions (payment_id, amount, method, paid_date, transaction_id, recorded_by)
                               VALUES (?,?,?,?,?,?)")
                    ->execute([$paymentId, $amount, $method, $paidDate, $txnId, $admin['id'] ?? null]);
            } catch (Throwable $e) {
                // Table might not exist or optional in some setups
            }

            $pdo->prepare("UPDATE payments
                           SET paid_amount = ?, status = ?, paid_date = ?, payment_method = ?,
                               transaction_id = COALESCE(?, transaction_id)
                           WHERE id = ?")
                ->execute([$newPaid, $newStatus, $paidDate, $method, $txnId, $paymentId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Database error: ' . $e->getMessage()];
        }

        // --- commit ke baad: log, project status, notifications ---
        $who   = $admin['name'] ?? 'Admin';
        $after = max(0, round($total - $newPaid, 2));
        $log   = 'Payment ₹' . number_format($amount, 2) . " received via {$method} on " . date('d M Y', strtotime($paidDate))
               . ($txnId ? " (Txn: {$txnId})" : '') . '. '
               . ($newStatus === 'paid' ? 'Invoice fully settled.' : 'Remaining: ₹' . number_format($after, 2) . '.');

        if (function_exists('addProjectLog')) {
            addProjectLog($pdo, (int) $pay['project_id'], 'payment_received', "Payment Received ({$pay['invoice_no']})", $log, 'admin', $who);
        }
        if (function_exists('syncProjectStatus')) {
            syncProjectStatus($pdo, (int) $pay['project_id']);
        }

        if (function_exists('addNotification')) {
            addNotification($pdo, 'client', (int) $pay['client_id'], 'Payment Received',
                "₹" . number_format($amount, 2) . " received for invoice {$pay['invoice_no']}.", '/client-flow/client/payments.php', 'success', 'bi-check2-circle');
            if (!empty($admin['id'])) {
                addNotification($pdo, 'admin', (int) $admin['id'], 'Payment Recorded',
                    "{$pay['invoice_no']}: ₹" . number_format($amount, 2) . " recorded ({$newStatus}).", '/client-flow/admin/payments/list_payments.php', 'success', 'bi-cash-coin');
            }
        }

        // Automated Payment Receipt Email Dispatch
        if (function_exists('sendPaymentReceiptEmail')) {
            try {
                sendPaymentReceiptEmail($pdo, $paymentId, $amount, $method, $admin);
            } catch (Throwable $e) {
                // Silently prevent payment record failure if mail encounters network error
            }
        }

        return [
            'ok'        => true,
            'status'    => $newStatus,
            'invoice'   => $pay['invoice_no'],
            'received'  => $amount,
            'remaining' => $after,
        ];
    }
}
