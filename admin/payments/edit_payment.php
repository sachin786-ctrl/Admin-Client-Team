<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Edit Payment';

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$stmt = $pdo->prepare("
    SELECT pay.*, p.title AS project_title, p.project_code, p.total_amount AS project_budget, 
           p.created_at AS project_created_at, p.deadline AS project_deadline, c.name AS client_name 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    JOIN clients c ON c.id = pay.client_id
    WHERE pay.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('error', 'Payment record not found.');
    header('Location: list_payments.php');
    exit;
}

// Calculate financial context from other project milestones
$otherStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) AS other_billed, 
           COALESCE(SUM(paid_amount), 0) AS other_paid 
    FROM payments 
    WHERE project_id = ? AND id != ?
");
$otherStmt->execute([$payment['project_id'], $id]);
$otherFin = $otherStmt->fetch();

$projectBudget      = (float) $payment['project_budget'];
$otherBilled        = (float) ($otherFin['other_billed'] ?? 0.00);
$otherPaid          = (float) ($otherFin['other_paid'] ?? 0.00);
$maxAllowedBudget   = max(0.00, $projectBudget - $otherBilled);
$projectCreatedDate = date('Y-m-d', strtotime($payment['project_created_at']));
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title          = trim($_POST['title'] ?? '');
    $amount         = (float) ($_POST['amount'] ?? 0.00);
    $paid_amount    = (float) ($_POST['paid_amount'] ?? 0.00);
    $due_date       = $_POST['due_date'] ?? '';
    $status         = $_POST['status'] ?? 'pending';
    $payment_method = trim($_POST['payment_method'] ?? '');
    $paid_date      = !empty($_POST['paid_date']) ? $_POST['paid_date'] : null;
    $isOutOfScope   = isset($_POST['is_out_of_scope']) && $_POST['is_out_of_scope'] === '1';

    // 1. Paid amount clamping: cannot exceed milestone total
    if ($paid_amount < 0) $paid_amount = 0.00;
    if ($paid_amount > $amount) $paid_amount = $amount;

    if ($title === '') {
        $fieldErrors['title'] = 'Milestone Title is required.';
    }

    if ($amount <= 0) {
        $fieldErrors['amount'] = 'Total Amount must be greater than 0.';
    } elseif (!$isOutOfScope && $maxAllowedBudget > 0 && $amount > ($maxAllowedBudget + 0.01)) {
        $fieldErrors['amount'] = "Milestone amount ₹" . number_format($amount, 2) . " exceeds available project budget limit of ₹" . number_format($maxAllowedBudget, 2) . ".";
    } elseif (!$isOutOfScope && $projectBudget > 0 && $amount > ($projectBudget + 0.01)) {
        $fieldErrors['amount'] = "Milestone amount ₹" . number_format($amount, 2) . " cannot exceed total project budget of ₹" . number_format($projectBudget, 2) . ".";
    }

    if (empty($due_date)) {
        $fieldErrors['due_date'] = 'Due Date is required.';
    } elseif ($due_date < $projectCreatedDate) {
        $fieldErrors['due_date'] = "Due date cannot be earlier than project start date (" . date('d M Y', strtotime($projectCreatedDate)) . ").";
    }

    if (!empty($paid_date) && $paid_date < $projectCreatedDate) {
        $fieldErrors['paid_date'] = "Paid date cannot be earlier than project start date (" . date('d M Y', strtotime($projectCreatedDate)) . ").";
    }

    if (!empty($fieldErrors)) {
        setFlash('error', 'Please resolve the highlighted field errors below.');
    } else {
        // Auto-determine status if user didn't intentionally override
        if ($paid_amount >= $amount && $amount > 0) {
            $status = 'paid';
            if (empty($paid_date)) {
                $paid_date = date('Y-m-d');
            }
        } elseif ($paid_amount > 0 && $paid_amount < $amount) {
            if ($status === 'paid' || $status === 'pending') {
                $status = 'partial';
            }
            if (empty($paid_date)) {
                $paid_date = date('Y-m-d');
            }
        } elseif ($paid_amount == 0 && ($status === 'paid' || $status === 'partial')) {
            $status = (!empty($due_date) && $due_date < date('Y-m-d')) ? 'overdue' : 'pending';
            $paid_date = null;
        }

        try {
            $upd = $pdo->prepare("
                UPDATE payments 
                SET title = ?, amount = ?, paid_amount = ?, due_date = ?, status = ?, payment_method = ?, paid_date = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$title, $amount, $paid_amount, $due_date, $status, $payment_method ?: null, $paid_date, $id]);

            // Sync project status & audit log
            addProjectLog($pdo, $payment['project_id'], 'payment_updated', "Invoice Updated ({$payment['invoice_no']})", "Payment updated: Paid ₹" . number_format($paid_amount, 2) . " / Total ₹" . number_format($amount, 2) . " (Status: {$status}).", 'admin', $admin['name'] ?? 'Admin');
            syncProjectStatus($pdo, $payment['project_id']);

            // Notify Admin
            addNotification($pdo, 'admin', $admin['id'], 'Payment Updated', "Invoice {$payment['invoice_no']} details updated.", '/client-flow/admin/payments/list_payments.php', 'info', 'bi-credit-card');

            // Notify Client
            addNotification($pdo, 'client', $payment['client_id'], 'Invoice Updated', "Invoice {$payment['invoice_no']} status or amount updated.", '/client-flow/client/payments.php', 'info', 'bi-receipt');

            setFlash('success', "Payment {$payment['invoice_no']} updated successfully! (Total: ₹" . number_format($amount, 2) . ", Paid: ₹" . number_format($paid_amount, 2) . ", Due: ₹" . number_format(max(0, $amount - $paid_amount), 2) . ")");
            $redirect = trim($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));
            if ($redirect === 'project') {
                header('Location: ../projects/view_project.php?id=' . $payment['project_id']);
            } else {
                header('Location: list_payments.php');
            }
            exit;
        } catch (PDOException $e) {
            setFlash('error', 'Database Error: ' . $e->getMessage());
        }
    }
}

$flash = getFlash();

$curAmount = (float) $payment['amount'];
$curPaid   = (float) ($payment['paid_amount'] ?? ($payment['status'] === 'paid' ? $curAmount : 0.00));
$curBal    = max(0, $curAmount - $curPaid);
$today     = date('Y-m-d');
$isPastDue = (!empty($payment['due_date']) && $payment['due_date'] < $today);
$curMethod = trim($payment['payment_method'] ?? '');

$totalBilledWithCurrent = $otherBilled + $curAmount;
$unbilledAvailable      = max(0.00, $projectBudget - $totalBilledWithCurrent);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Breadcrumbs & Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="../dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="list_payments.php" class="text-decoration-none">Payments</a></li>
                        <li class="breadcrumb-item"><a href="../projects/view_project.php?id=<?php echo $payment['project_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($payment['project_code']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit <?php echo htmlspecialchars($payment['invoice_no']); ?></li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit Payment — <?php echo htmlspecialchars($payment['invoice_no']); ?>
                </h4>
            </div>
            <div class="d-flex gap-2">
                <a href="../projects/view_project.php?id=<?php echo $payment['project_id']; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-eye me-1"></i> View Project
                </a>
                <a href="list_payments.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Payments
                </a>
            </div>
        </div>

        <div class="d-flex justify-content-center">
            <div class="card p-4 shadow-sm border-0" style="max-width: 900px; width: 100%;">
                
                <!-- Project Financial Context Box (Matching add_payment.php) -->
                <div class="p-3 mb-4 bg-light rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <div>
                            <span class="fs-6 fw-bold text-primary">
                                <i class="bi bi-folder-check me-1"></i> <?php echo htmlspecialchars($payment['project_code']); ?> — <?php echo htmlspecialchars($payment['project_title']); ?>
                            </span>
                            <div class="small text-muted mt-1">
                                Client: <strong class="text-dark"><?php echo htmlspecialchars($payment['client_name']); ?></strong> | 
                                Project Start Date: <strong class="text-dark"><?php echo date('d M Y', strtotime($projectCreatedDate)); ?></strong>
                            </div>
                        </div>
                        <span class="badge font-monospace bg-light text-dark border fs-6"><?php echo htmlspecialchars($payment['invoice_no']); ?></span>
                    </div>

                    <div class="row g-2 text-center bg-white p-2 rounded border mt-2">
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block fw-semibold text-uppercase" style="font-size: 10.5px;">PROJECT TOTAL BUDGET</small>
                            <span class="fw-bold fs-6 text-dark">₹<?php echo number_format($projectBudget, 2); ?></span>
                        </div>
                        <div class="col-6 col-md-3 border-start">
                            <small class="text-primary d-block fw-semibold text-uppercase" style="font-size: 10.5px;">OTHER MILESTONES BILLED</small>
                            <span class="fw-bold fs-6 text-primary">₹<?php echo number_format($otherBilled, 2); ?></span>
                        </div>
                        <div class="col-6 col-md-3 border-start">
                            <small class="text-success d-block fw-semibold text-uppercase" style="font-size: 10.5px;">CLIENT PAID (OTHER)</small>
                            <span class="fw-bold fs-6 text-success">₹<?php echo number_format($otherPaid, 2); ?></span>
                        </div>
                        <div class="col-6 col-md-3 border-start">
                            <small class="text-danger d-block fw-semibold text-uppercase" style="font-size: 10.5px;">MAX BUDGET FOR THIS INVOICE</small>
                            <span class="fw-bold fs-6 text-danger" id="boxMaxAllowed">₹<?php echo number_format($maxAllowedBudget, 2); ?></span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="editPaymentForm" novalidate>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect'] ?? ''); ?>">

                    <div class="row g-3">
                        <!-- Invoice No & Title -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Invoice No</label>
                            <input type="text" class="form-control bg-light font-monospace fw-bold" value="<?php echo htmlspecialchars($payment['invoice_no']); ?>" readonly>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Milestone Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="editTitle" class="form-control <?php echo !empty($fieldErrors['title']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['title'] ?? $payment['title']); ?>" placeholder="e.g. Milestone 2: Backend Integration" required minlength="2" maxlength="150">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['title']) ? 'd-none' : ''; ?>" id="titleError">
                                <?php echo htmlspecialchars($fieldErrors['title'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Total Amount -->
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold mb-0">Total Amount <span class="text-danger">*</span></label>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 11px;">
                                    Budget Limit: ₹<?php echo number_format($maxAllowedBudget > 0 ? $maxAllowedBudget : $projectBudget, 2); ?>
                                </span>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">₹</span>
                                <input type="number" step="0.01" min="1" max="<?php echo $maxAllowedBudget > 0 ? $maxAllowedBudget : $projectBudget; ?>" name="amount" id="editTotalAmount" class="form-control fw-bold <?php echo !empty($fieldErrors['amount']) ? 'is-invalid' : ''; ?>" placeholder="0.00" value="<?php echo htmlspecialchars($_POST['amount'] ?? $curAmount); ?>" required>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['amount']) ? 'd-none' : ''; ?>" id="amountError">
                                <?php echo htmlspecialchars($fieldErrors['amount'] ?? ''); ?>
                            </p>
                            <small class="text-muted d-block mt-1" id="editAmountFeedback" style="font-size: 11px;">
                                Cannot exceed project budget: ₹<?php echo number_format($projectBudget, 2); ?>.
                            </small>
                        </div>

                        <!-- Paid Amount -->
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold mb-0">Paid Amount</label>
                                <div>
                                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-success fw-semibold" id="btnEditMarkFullPaid" style="font-size: 0.75rem;">Full</button>
                                    <span class="text-muted mx-1">|</span>
                                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" id="btnEditMarkZeroPaid" style="font-size: 0.75rem;">0</button>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-success-subtle text-success fw-bold">₹</span>
                                <input type="number" step="0.01" min="0" max="<?php echo $curAmount; ?>" name="paid_amount" id="editPaidAmount" class="form-control text-success fw-bold <?php echo !empty($fieldErrors['paid_amount']) ? 'is-invalid' : ''; ?>" placeholder="0.00" value="<?php echo htmlspecialchars($_POST['paid_amount'] ?? $curPaid); ?>">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['paid_amount']) ? 'd-none' : ''; ?>" id="paid_amountError">
                                <?php echo htmlspecialchars($fieldErrors['paid_amount'] ?? ''); ?>
                            </p>
                            <small class="text-muted d-block mt-1" style="font-size: 11px;">
                                Cannot exceed milestone total amount.
                            </small>
                        </div>

                        <!-- Balance Due -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-danger">Balance Due</label>
                            <div class="input-group">
                                <span class="input-group-text bg-danger-subtle text-danger fw-bold">₹</span>
                                <input type="text" id="editRemainingBalance" class="form-control bg-light text-danger fw-bold" placeholder="0.00" value="<?php echo number_format($curBal, 2, '.', ''); ?>" readonly>
                            </div>
                        </div>

                        <!-- Status Dropdown -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="editStatus" class="form-select">
                                <option value="pending" <?php echo $payment['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo $payment['status'] === 'partial' ? 'selected' : ''; ?>>Partial Payment</option>
                                <option value="paid" <?php echo $payment['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo $payment['status'] === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>

                        <!-- Due Date (Min = Project Creation Date) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" id="editDueDate" class="form-control <?php echo !empty($fieldErrors['due_date']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['due_date'] ?? $payment['due_date']); ?>" min="<?php echo $projectCreatedDate; ?>" required>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['due_date']) ? 'd-none' : ''; ?>" id="due_dateError">
                                <?php echo htmlspecialchars($fieldErrors['due_date'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Paid Date (Min = Project Creation Date) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date Paid / Received</label>
                            <input type="date" name="paid_date" id="editPaidDate" class="form-control <?php echo !empty($fieldErrors['paid_date']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['paid_date'] ?? ($payment['paid_date'] ?? '')); ?>" min="<?php echo $projectCreatedDate; ?>">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['paid_date']) ? 'd-none' : ''; ?>" id="paid_dateError">
                                <?php echo htmlspecialchars($fieldErrors['paid_date'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Payment Method SELECT Dropdown -->
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" id="editPaymentMethod" class="form-select">
                                <option value="">-- Choose Payment Method --</option>
                                <option value="UPI / QR Code" <?php echo ($curMethod === 'UPI / QR Code') ? 'selected' : ''; ?>>UPI / QR Code</option>
                                <option value="Bank Transfer (NEFT/IMPS)" <?php echo ($curMethod === 'Bank Transfer (NEFT/IMPS)' || $curMethod === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="Net Banking" <?php echo ($curMethod === 'Net Banking') ? 'selected' : ''; ?>>Net Banking</option>
                                <option value="Credit / Debit Card" <?php echo ($curMethod === 'Credit / Debit Card') ? 'selected' : ''; ?>>Credit / Debit Card</option>
                                <option value="Cash" <?php echo ($curMethod === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="Cheque" <?php echo ($curMethod === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                <option value="Direct Payment" <?php echo ($curMethod === 'Direct Payment') ? 'selected' : ''; ?>>Direct Payment</option>
                                <?php if (!empty($curMethod) && !in_array($curMethod, ['UPI / QR Code', 'Bank Transfer (NEFT/IMPS)', 'Bank Transfer', 'Net Banking', 'Credit / Debit Card', 'Cash', 'Cheque', 'Direct Payment'], true)): ?>
                                    <option value="<?php echo htmlspecialchars($curMethod); ?>" selected><?php echo htmlspecialchars($curMethod); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-12 pt-3 border-top d-flex gap-2">
                            <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSubmitEditPayment">
                                <i class="bi bi-check-circle-fill me-1"></i> Update Payment
                            </button>
                            <a href="<?php echo (!empty($_GET['redirect']) && $_GET['redirect'] === 'project') ? '../projects/view_project.php?id=' . $payment['project_id'] : 'list_payments.php'; ?>" class="btn btn-outline-secondary px-3">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const totalEl     = document.getElementById('editTotalAmount');
    const paidEl      = document.getElementById('editPaidAmount');
    const remEl       = document.getElementById('editRemainingBalance');
    const dueDateEl   = document.getElementById('editDueDate');
    const statusEl    = document.getElementById('editStatus');
    const paidDateEl  = document.getElementById('editPaidDate');
    const fullBtn     = document.getElementById('btnEditMarkFullPaid');
    const zeroBtn     = document.getElementById('btnEditMarkZeroPaid');
    const feedbackEl  = document.getElementById('editAmountFeedback');

    const maxAllowedBudget = <?php echo ($maxAllowedBudget > 0 ? $maxAllowedBudget : $projectBudget); ?>;
    const projectBudget    = <?php echo $projectBudget; ?>;
    const projectCreated   = '<?php echo $projectCreatedDate; ?>';

    function recalc() {
        let total = parseFloat(totalEl.value) || 0;
        
        // 1. Clamp Total Amount: cannot exceed allowed budget
        if (maxAllowedBudget > 0 && total > maxAllowedBudget) {
            total = maxAllowedBudget;
            totalEl.value = maxAllowedBudget.toFixed(2);
            if (feedbackEl) {
                feedbackEl.className = 'text-danger fw-semibold d-block mt-1';
                feedbackEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Clamped to maximum allowed budget of ₹${maxAllowedBudget.toLocaleString('en-IN', {minimumFractionDigits: 2})}.`;
            }
        } else if (feedbackEl) {
            feedbackEl.className = 'text-success fw-semibold d-block mt-1';
            feedbackEl.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> Valid amount within project budget.`;
        }

        // 2. Clamp Paid Amount: cannot exceed total
        let paid = parseFloat(paidEl.value) || 0;
        if (paid < 0) paid = 0;
        if (paid > total && total > 0) {
            paid = total;
            paidEl.value = total.toFixed(2);
        }
        paidEl.max = total > 0 ? total.toFixed(2) : '';

        const bal = Math.max(0, total - paid);
        remEl.value = bal.toFixed(2);

        const dueDateVal = dueDateEl ? dueDateEl.value : '';
        const todayStr   = new Date().toISOString().split('T')[0];
        const isOverdue  = (dueDateVal && dueDateVal < todayStr);

        // Smart status suggestion
        if (total > 0 && paid >= total) {
            statusEl.value = 'paid';
            if (!paidDateEl.value) {
                paidDateEl.value = todayStr;
            }
        } else if (paid > 0 && paid < total) {
            statusEl.value = 'partial';
            if (!paidDateEl.value) {
                paidDateEl.value = todayStr;
            }
        } else if (paid === 0) {
            if (isOverdue && total > 0) {
                statusEl.value = 'overdue';
            } else {
                statusEl.value = 'pending';
            }
        }
    }

    if (totalEl) {
        totalEl.addEventListener('input', recalc);
        totalEl.addEventListener('change', recalc);
    }
    if (paidEl) {
        paidEl.addEventListener('input', recalc);
        paidEl.addEventListener('change', recalc);
    }
    if (dueDateEl) {
        dueDateEl.addEventListener('change', function() {
            if (this.value && this.value < projectCreated) {
                if (typeof window.showAppToast === 'function') {
                    window.showAppToast(`Due date cannot be before project creation date (${projectCreated}).`, 'warning', 'Invalid Date');
                }
                this.value = projectCreated;
            }
            recalc();
        });
    }
    if (paidDateEl) {
        paidDateEl.addEventListener('change', function() {
            if (this.value && this.value < projectCreated) {
                if (typeof window.showAppToast === 'function') {
                    window.showAppToast(`Paid date cannot be before project creation date (${projectCreated}).`, 'warning', 'Invalid Date');
                }
                this.value = projectCreated;
            }
        });
    }

    function setFieldError(pId, inputElem, msg) {
        const p = document.getElementById(pId);
        if (p) {
            p.textContent = msg;
            p.classList.remove('d-none');
        }
        if (inputElem) {
            inputElem.classList.add('is-invalid');
            inputElem.classList.remove('is-valid');
        }
    }

    function clearFieldValidation(pId, inputElem) {
        const p = document.getElementById(pId);
        if (p) {
            p.textContent = '';
            p.classList.add('d-none');
        }
        if (inputElem) {
            inputElem.classList.remove('is-invalid');
        }
    }

    const titleEl = document.getElementById('editTitle');
    const form = document.getElementById('editPaymentForm');
    const submitBtn = document.getElementById('btnSubmitEditPayment');

    function validateTitle() {
        if (!titleEl) return true;
        const val = titleEl.value.trim();
        if (!val) {
            setFieldError('titleError', titleEl, 'Milestone Title is required.');
            return false;
        }
        clearFieldValidation('titleError', titleEl);
        return true;
    }

    function validateAmount() {
        if (!totalEl) return true;
        const val = parseFloat(totalEl.value) || 0;
        if (val <= 0) {
            setFieldError('amountError', totalEl, 'Total Amount must be greater than 0.');
            return false;
        }
        clearFieldValidation('amountError', totalEl);
        return true;
    }

    function validateDueDate() {
        if (!dueDateEl) return true;
        const val = dueDateEl.value;
        if (!val) {
            setFieldError('due_dateError', dueDateEl, 'Due Date is required.');
            return false;
        }
        if (val < projectCreated) {
            setFieldError('due_dateError', dueDateEl, `Due date cannot be earlier than project start date (${projectCreated}).`);
            return false;
        }
        clearFieldValidation('due_dateError', dueDateEl);
        return true;
    }

    if (titleEl) {
        titleEl.addEventListener('input', validateTitle);
        titleEl.addEventListener('blur', validateTitle);
    }
    if (totalEl) {
        totalEl.addEventListener('input', function() { recalc(); validateAmount(); });
        totalEl.addEventListener('blur', validateAmount);
    }
    if (dueDateEl) {
        dueDateEl.addEventListener('change', function() { validateDueDate(); recalc(); });
        dueDateEl.addEventListener('blur', validateDueDate);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            let isValid = true;
            if (!validateTitle()) isValid = false;
            if (!validateAmount()) isValid = false;
            if (!validateDueDate()) isValid = false;

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Updating Payment...';
                }
            }
        });
    }

    recalc();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
