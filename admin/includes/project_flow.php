<?php
/**
 * Canonical project-status helpers (single source of truth).
 */

if (!function_exists('isInvoiceSettled')) {
    function isInvoiceSettled(?array $pay): bool
    {
        if (!$pay) {
            return true;
        }
        return ($pay['status'] ?? '') === 'paid'
            || (float) ($pay['paid_amount'] ?? 0) >= ((float) ($pay['amount'] ?? 0) - 0.005);
    }
}

if (!function_exists('isPreAgreementStatus')) {
    function isPreAgreementStatus(string $status): bool
    {
        return in_array($status, ['draft', 'waiting_confirmation', 'proposal_sent', 'revision_requested'], true);
    }
}

if (!function_exists('isAdvanceDueStatus')) {
    function isAdvanceDueStatus(string $status): bool
    {
        return in_array($status, ['advance_pending', 'advance_payment_pending'], true);
    }
}

if (!function_exists('canonicalProjectStatus')) {
    function canonicalProjectStatus(string $status): string
    {
        return $status === 'advance_pending' ? 'advance_payment_pending' : $status;
    }
}

if (!function_exists('getProjectLeadInvoice')) {
    /** First / advance invoice for a project. */
    function getProjectLeadInvoice(PDO $pdo, int $projectId): ?array
    {
        $st = $pdo->prepare("
            SELECT * FROM payments
            WHERE project_id = ? AND deleted_at IS NULL
            ORDER BY is_advance DESC, due_date ASC, id ASC
            LIMIT 1
        ");
        $st->execute([$projectId]);
        $row = $st->fetch();
        return $row ?: null;
    }
}

if (!function_exists('nextStatusAfterAgreement')) {
    function nextStatusAfterAgreement(PDO $pdo, int $projectId): string
    {
        $first = getProjectLeadInvoice($pdo, $projectId);
        return isInvoiceSettled($first) ? 'in_progress' : 'advance_payment_pending';
    }
}

if (!function_exists('projectHasUnpaidInvoices')) {
    function projectHasUnpaidInvoices(PDO $pdo, int $projectId): bool
    {
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM payments
            WHERE project_id = ? AND deleted_at IS NULL AND paid_amount < amount
        ");
        $q->execute([$projectId]);
        return (int) $q->fetchColumn() > 0;
    }
}

if (!function_exists('projectStatusLabel')) {
    function projectStatusLabel(string $status): string
    {
        $labels = [
            'draft'                   => 'Draft',
            'proposal_sent'           => 'Proposal Sent',
            'waiting_confirmation'    => 'Waiting Confirmation',
            'revision_requested'      => 'Revision Requested',
            'advance_pending'         => 'Advance Payment Pending',
            'advance_payment_pending' => 'Advance Payment Pending',
            'in_progress'             => 'In Progress',
            'review'                  => 'Review / Approval',
            'on_hold'                 => 'On Hold',
            'completed'               => 'Completed',
            'cancelled'               => 'Cancelled',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('validateProjectStatusChange')) {
    /**
     * @return string|null Error message, or null if allowed.
     */
    function validateProjectStatusChange(PDO $pdo, array $prj, string $newStatus): ?string
    {
        $newStatus = canonicalProjectStatus($newStatus);
        $allowed = [
            'draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested',
            'advance_payment_pending', 'in_progress', 'review', 'on_hold', 'completed', 'cancelled',
        ];
        if (!in_array($newStatus, $allowed, true)) {
            return 'Invalid status value.';
        }

        $label = projectStatusLabel($newStatus);
        $needAgreement = ['advance_payment_pending', 'in_progress', 'review', 'completed'];
        $needAdvance   = ['in_progress', 'review', 'completed'];

        if (in_array($newStatus, $needAgreement, true) && empty($prj['agreed_at'])) {
            return "Cannot move to '{$label}': client has not accepted the proposal yet.";
        }

        if (in_array($newStatus, $needAdvance, true)) {
            $adv = getProjectLeadInvoice($pdo, (int) $prj['id']);
            if ($adv && !isInvoiceSettled($adv)) {
                $inv = $adv['invoice_no'] ?? 'advance';
                return "Cannot move to '{$label}'! Advance payment ({$inv}) must be received first.";
            }
        }

        if ($newStatus === 'completed' && projectHasUnpaidInvoices($pdo, (int) $prj['id'])) {
            return 'Cannot mark Completed: some invoices are still unpaid.';
        }

        return null;
    }
}

if (!function_exists('csrfUrlToken')) {
    function csrfUrlToken(): string
    {
        return urlencode(generateCsrfToken());
    }
}

if (!function_exists('sendProposalHref')) {
    function sendProposalHref(int $id, string $redirect = 'view'): string
    {
        return 'send_proposal.php?id=' . $id . '&redirect=' . urlencode($redirect) . '&token=' . csrfUrlToken();
    }
}

if (!function_exists('safeInternalRedirect')) {
    function safeInternalRedirect(?string $url, string $fallback): void
    {
        if ($url === null || $url === '') {
            header('Location: ' . $fallback);
            exit;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            header('Location: ' . $url);
            exit;
        }
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        $parts = parse_url($url);
        $refHost = strtolower($parts['host'] ?? '');
        if ($refHost !== '' && $host !== '' && hash_equals($host, $refHost)) {
            $path = $parts['path'] ?? '/';
            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            header('Location: ' . $path . $query);
            exit;
        }
        header('Location: ' . $fallback);
        exit;
    }
}
