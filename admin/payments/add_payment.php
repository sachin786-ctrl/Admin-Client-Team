<?php
/**
 * Admin Panel - Add Milestone / Payment Invoice
 * c:\xampp\htdocs\client-flow\admin\payments\add_payment.php
 */

require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Add Milestone / Payment Invoice';

/* ------------------------------------------------------------------
 * Helpers for this page
 * ------------------------------------------------------------------ */
function apLoadProject(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare("
        SELECT p.id, p.project_code, p.title, p.client_id, p.total_amount, p.status, p.deadline,
               p.created_at, p.agreed_at, c.name AS client_name, c.email AS client_email
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ? AND p.deleted_at IS NULL" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function apBilling(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS billed, COALESCE(SUM(paid_amount), 0) AS paid,
               COALESCE(SUM(is_advance), 0) AS adv
        FROM payments WHERE project_id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $r = $st->fetch();
    return [
        'count'       => (int) $r['cnt'],
        'billed'      => (float) $r['billed'],
        'paid'        => (float) $r['paid'],
        'has_advance' => (int) $r['adv'] > 0,
    ];
}

function apValidDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

/**
 * Single transactional route to create milestone invoices.
 */
function apCreateInvoices(PDO $pdo, int $projectId, array $rows, bool $extra): array
{
    $sum = 0.0;
    foreach ($rows as $r) $sum += $r['amount'];

    for ($try = 0; $try < 3; $try++) {
        $pdo->beginTransaction();
        try {
            $prj = apLoadProject($pdo, $projectId, true);
            if (!$prj || $prj['status'] === 'cancelled') {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'This project is unavailable (deleted or cancelled).'];
            }

            $b        = apBilling($pdo, $projectId);
            $unbilled = max(0.0, (float) $prj['total_amount'] - $b['billed']);

            if ($extra) {
                if ($unbilled > 0.01) {
                    $pdo->rollBack();
                    return ['ok' => false, 'msg' => 'Contract budget still has ₹' . number_format($unbilled, 2) . ' unallocated. Please create standard milestones first.'];
                }
            } elseif ($sum > $unbilled + 0.01) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Total amount ₹' . number_format($sum, 2) . ' exceeds remaining unallocated budget ₹' . number_format($unbilled, 2) . '.'];
            }

            $created = date('Y-m-d', strtotime($prj['created_at']));
            foreach ($rows as $r) {
                if ($r['due_date'] < $created) {
                    $pdo->rollBack();
                    return ['ok' => false, 'msg' => 'Due date cannot be earlier than the project creation date (' . date('d M Y', strtotime($created)) . ').'];
                }
            }

            $n      = (int) substr(nextInvoiceNo($pdo), 4);
            $hasAdv = $b['has_advance'];
            $ins    = $pdo->prepare("
                INSERT INTO payments (invoice_no, project_id, client_id, title, amount, paid_amount, due_date, status, payment_method, is_advance)
                VALUES (?, ?, ?, ?, ?, 0.00, ?, 'pending', NULL, ?)");

            $nums = [];
            foreach ($rows as $r) {
                $no  = 'INV-' . $n++;
                $adv = (!$hasAdv && !empty($r['advance'])) ? 1 : 0;
                if ($adv) $hasAdv = true;
                $ins->execute([$no, $projectId, $prj['client_id'], $r['title'], $r['amount'], $r['due_date'], $adv]);
                $nums[] = $no;
            }

            $pdo->commit();
            return ['ok' => true, 'prj' => $prj, 'numbers' => $nums, 'sum' => $sum];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000' && $try < 2) continue; // retry on invoice clash
            return ['ok' => false, 'msg' => 'Database Error: ' . $e->getMessage()];
        }
    }
    return ['ok' => false, 'msg' => 'Could not assign invoice number. Please try again.'];
}

/* ------------------------------------------------------------------
 * POST: Create invoices
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pid  = (int) ($_POST['project_id'] ?? 0);
    $mode = trim($_POST['form_mode'] ?? '');
    $back = 'add_payment.php?project_id=' . $pid . (!empty($_POST['extra']) ? '&extra=1' : '');

    if (function_exists('sameOriginPost') && !sameOriginPost()) {
        setFlash('error', 'Invalid request origin. Please try again.');
        header('Location: ' . $back);
        exit;
    }

    $rows = [];
    $extra = false;
    $notify = false;

    if ($mode === 'split') {
        $titles = (array) ($_POST['milestone_title'] ?? []);
        $amts   = (array) ($_POST['milestone_amount'] ?? []);
        $dates  = (array) ($_POST['milestone_due_date'] ?? []);
        $bad    = false;
        foreach ($titles as $i => $t) {
            $t = mb_substr(trim((string) $t), 0, 150);
            $a = round((float) ($amts[$i] ?? 0), 2);
            $d = trim((string) ($dates[$i] ?? ''));
            if ($t === '' || $a <= 0 || !apValidDate($d)) { $bad = true; continue; }
            $rows[] = ['title' => $t, 'amount' => $a, 'due_date' => $d, 'advance' => count($rows) === 0];
        }
        if ($bad || !$rows) {
            setFlash('error', 'Milestone title, positive amount, and valid due date are required for each row.');
            header('Location: ' . $back);
            exit;
        }
        $notify = !empty($_POST['notify_client']);
    } elseif ($mode === 'single') {
        $t = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
        $a = round((float) ($_POST['amount'] ?? 0), 2);
        $d = trim($_POST['due_date'] ?? '');
        if ($t === '' || $a <= 0 || !apValidDate($d)) {
            setFlash('error', 'Invoice title, positive amount, and valid due date are required.');
            header('Location: ' . $back);
            exit;
        }
        $rows[] = ['title' => $t, 'amount' => $a, 'due_date' => $d, 'advance' => !empty($_POST['is_advance'])];
        $extra  = !empty($_POST['extra']);
    } else {
        setFlash('error', 'Invalid request form submission.');
        header('Location: ' . $back);
        exit;
    }

    $res = apCreateInvoices($pdo, $pid, $rows, $extra);
    if (!$res['ok']) {
        setFlash('error', $res['msg']);
        header('Location: ' . $back);
        exit;
    }

    // --- Post-commit actions: logs, notifications, proposal email ---
    $prj   = $res['prj'];
    $count = count($rows);
    $who   = $admin['name'] ?? 'Admin';
    $warn  = '';
    try {
        if ($mode === 'split') {
            addProjectLog($pdo, $pid, 'milestones_configured', 'Payment Milestones Configured',
                "Configured {$count} milestone installments totaling ₹" . number_format($res['sum'], 2) . '.', 'admin', $who);
            addNotification($pdo, 'client', (int) $prj['client_id'], 'Milestone Schedule Configured',
                "Payment milestone schedule for '{$prj['title']}' ({$prj['project_code']}) has been configured.",
                '/client-flow/client/projects.php', 'primary', 'bi-calendar-check');
            addNotification($pdo, 'admin', (int) $admin['id'], 'Milestones Created',
                "{$count} milestone invoice(s) created for '{$prj['title']}'.",
                "/client-flow/admin/projects/view_project.php?id={$pid}", 'success', 'bi-calendar-check');

            if ($notify && in_array($prj['status'], ['waiting_confirmation', 'proposal_sent'], true)) {
                sendProjectProposalEmail($pdo, $pid);
                $pdo->prepare("UPDATE projects SET proposal_sent_at = NOW(), status = 'proposal_sent' WHERE id = ?")->execute([$pid]);
                addProjectLog($pdo, $pid, 'proposal_sent', 'Proposal Dispatched to Client',
                    "Milestone agreement and formal proposal dispatched to {$prj['client_email']}.", 'admin', $who);
            }
        } else {
            $no = $res['numbers'][0];
            $r0 = $rows[0];
            addProjectLog($pdo, $pid, 'invoice_created', "Invoice Created · {$no}",
                "'{$r0['title']}' · ₹" . number_format($r0['amount'], 2) . ' due ' . date('d M Y', strtotime($r0['due_date'])) . ($extra ? ' · Extra / Change-Order' : '') . '.', 'admin', $who);
            addNotification($pdo, 'client', (int) $prj['client_id'], 'New Invoice Issued',
                "Invoice {$no} · {$r0['title']} for ₹" . number_format($r0['amount'], 2) . ' has been issued.',
                '/client-flow/client/payments.php', 'warning', 'bi-receipt');
            addNotification($pdo, 'admin', (int) $admin['id'], 'Invoice Created',
                "Invoice {$no} · {$r0['title']} for ₹" . number_format($r0['amount'], 2) . ' was created.',
                '/client-flow/admin/payments/list_payments.php', 'success', 'bi-credit-card-2-front');
        }
        syncProjectStatus($pdo, $pid);
    } catch (Throwable $e) {
        $warn = ' (Invoice created, but notification notice encountered an error: ' . $e->getMessage() . ')';
    }

    if ($mode === 'split') {
        setFlash($warn ? 'warning' : 'success',
            "{$count} milestone invoice(s) created successfully — Total: ₹" . number_format($res['sum'], 2) . " ({$prj['project_code']})." . $warn);
    } else {
        setFlash($warn ? 'warning' : 'success',
            "Invoice {$res['numbers'][0]} created successfully — ₹" . number_format($rows[0]['amount'], 2) . '.' . $warn);
    }
    header('Location: ../projects/view_project.php?id=' . $pid);
    exit;
}

/* ------------------------------------------------------------------
 * GET: Decide page state
 * ------------------------------------------------------------------ */
$flash     = getFlash();
$pid       = (int) ($_GET['project_id'] ?? 0);
$extraMode = ($_GET['extra'] ?? '') === '1';
$type      = trim($_GET['type'] ?? '');

$prj = null; $bill = null; $existing = [];
$budget = 0.0; $unbilled = 0.0;
$state = 'select';

if ($pid > 0) {
    $prj = apLoadProject($pdo, $pid);
    if (!$prj) {
        $state = 'missing';
    } elseif ($prj['status'] === 'cancelled') {
        $state = 'cancelled';
    } else {
        $bill     = apBilling($pdo, $pid);
        $budget   = (float) $prj['total_amount'];
        $unbilled = max(0.0, $budget - $bill['billed']);
        $balanceDue = max(0.0, $bill['billed'] - $bill['paid']);
        $isFullyPaid = ($bill['count'] > 0 && $unbilled <= 0.01 && $balanceDue <= 0.005);

        // Load existing invoices for this project
        $st = $pdo->prepare("SELECT id, invoice_no, title, amount, paid_amount, due_date, status, payment_method
                             FROM payments WHERE project_id = ? AND deleted_at IS NULL ORDER BY due_date ASC, id ASC");
        $st->execute([$pid]);
        $existing = $st->fetchAll();

        if ($budget <= 0)                $state = 'nobudget';
        elseif ($bill['count'] === 0)    $state = 'fresh';      // No invoices yet: Auto-split or Single
        elseif ($extraMode)              $state = 'extra';      // Extra / out-of-scope invoice requested
        elseif ($unbilled > 0.01)        $state = 'partial';    // Unallocated budget remains
        else                             $state = 'billed';     // 100% contract budget is already scheduled
    }
}

// Fetch ALL active projects for the dropdown (Do NOT block projects whose budget is scheduled!)
$projects = [];
if ($state === 'select' || in_array($state, ['missing', 'cancelled'], true)) {
    $projects = $pdo->query("
        SELECT p.id, p.project_code, p.title, p.total_amount, p.status, c.name AS client_name,
               COALESCE(SUM(pay.amount), 0) AS billed,
               COALESCE(SUM(pay.paid_amount), 0) AS paid
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        LEFT JOIN payments pay ON pay.project_id = p.id AND pay.deleted_at IS NULL
        WHERE p.deleted_at IS NULL AND p.status <> 'cancelled' AND p.total_amount > 0
        GROUP BY p.id, p.project_code, p.title, p.total_amount, p.status, c.name
        ORDER BY (p.total_amount - COALESCE(SUM(pay.amount), 0) > 0.01) DESC, p.id DESC
    ")->fetchAll();
}

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$m = fn($n) => '₹' . number_format((float) $n, 2);

$createdDate  = $prj ? date('Y-m-d', strtotime($prj['created_at'])) : date('Y-m-d');
$statusLabel  = $prj ? ucwords(str_replace('_', ' ', $prj['status'])) : '';
$statusBadge  = [
    'in_progress'             => 'bg-primary',
    'waiting_confirmation'    => 'bg-warning text-dark',
    'proposal_sent'           => 'bg-warning text-dark',
    'advance_pending'         => 'bg-info text-dark',
    'advance_payment_pending' => 'bg-info text-dark',
    'review'                  => 'text-white" style="background:#9333ea;"',
    'on_hold'                 => 'bg-dark',
    'completed'               => 'bg-success',
    'cancelled'               => 'bg-danger'
][$prj['status'] ?? ''] ?? 'bg-secondary';

$showSingle   = in_array($state, ['fresh', 'partial', 'extra'], true);
$singleMax    = $state === 'extra' ? 0 : $unbilled;
$defaultTitle = $type === 'advance' ? 'Milestone 1: Advance' : ($state === 'extra' ? 'Additional Scope' : '');
$defaultDue   = date('Y-m-d', strtotime($type === 'advance' ? '+3 days' : '+7 days'));
if ($defaultDue < $createdDate) $defaultDue = $createdDate;

$jsCfg = [
    'unbilled' => round($unbilled, 2),
    'created'  => $createdDate,
    'deadline' => !empty($prj['deadline']) ? date('Y-m-d', strtotime($prj['deadline'])) : '',
    'extra'    => $state === 'extra',
];

$singleFormHtml = '';
if ($showSingle) {
    ob_start(); ?>
<form method="POST" action="" id="singleForm" novalidate>
    <input type="hidden" name="form_mode" value="single">
    <input type="hidden" name="project_id" value="<?= (int) $prj['id'] ?>">
    <?php if ($state === 'extra'): ?><input type="hidden" name="extra" value="1"><?php endif; ?>

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label fw-semibold">Milestone Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="spTitle" maxlength="150" class="form-control" value="<?= $e($defaultTitle) ?>"
                   placeholder="e.g. Milestone 1: Advance" required minlength="2">
            <p class="text-danger small mt-1 mb-0 d-none" id="spTitleError"></p>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
            <input type="date" name="due_date" id="spDueDate" class="form-control" value="<?= $e($defaultDue) ?>" min="<?= $e($createdDate) ?>" required>
            <p class="text-danger small mt-1 mb-0 d-none" id="spDueDateError"></p>
        </div>

        <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-semibold mb-0">Amount <span class="text-danger">*</span></label>
                <?php if ($state !== 'extra' && $unbilled > 0): ?>
                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 fw-semibold" id="spFull" style="font-size: 0.8rem;">
                        Allocate remaining: <?= $m($unbilled) ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="input-group">
                <span class="input-group-text fw-bold">₹</span>
                <input type="number" step="0.01" min="0.01" <?= $singleMax > 0 ? 'max="' . number_format($singleMax, 2, '.', '') . '"' : '' ?>
                       name="amount" id="spAmount" class="form-control fw-bold" placeholder="0.00" required>
            </div>
            <p class="text-danger small mt-1 mb-0 d-none" id="spAmountError"></p>
            <div class="form-text" id="spFeedback"></div>
        </div>

        <?php if ($state !== 'extra' && !$bill['has_advance']): ?>
        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="is_advance" id="spAdvance" value="1" <?= $type === 'advance' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="spAdvance">Mark as Advance Milestone</label>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12 pt-3 border-top d-flex gap-2">
            <button type="submit" class="btn btn-primary fw-bold px-4" id="spSubmit" disabled>
                <i class="bi bi-check-circle-fill me-1"></i> <?= $state === 'extra' ? 'Create Extra Invoice' : 'Create Milestone Invoice' ?>
            </button>
            <?php if ($state === 'extra'): ?>
                <a href="add_payment.php?project_id=<?= (int) $prj['id'] ?>" class="btn btn-outline-secondary px-3">Back</a>
            <?php else: ?>
                <a href="list_payments.php" class="btn btn-outline-secondary px-3">Cancel</a>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php
    $singleFormHtml = ob_get_clean();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="../dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="list_payments.php" class="text-decoration-none">Payments</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add Milestone / Payment</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add Milestone / Invoice</h4>
            </div>
            <?php if ($prj && !in_array($state, ['missing'], true)): ?>
                <a href="../projects/view_project.php?id=<?= (int) $prj['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Project
                </a>
            <?php else: ?>
                <a href="list_payments.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Payments
                </a>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-center">
            <div class="card p-4 shadow-sm border-0" style="max-width: 920px; width: 100%; border-radius: 14px;">

<?php /* ================= STEP 1: SELECT A PROJECT ================= */ ?>
<?php if ($state === 'select' || $state === 'missing' || $state === 'cancelled'): ?>

    <?php if ($state === 'missing'): ?>
        <div class="alert alert-danger mb-3">Project not found or has been deleted. Please select another project from the list below.</div>
    <?php elseif ($state === 'cancelled'): ?>
        <div class="alert alert-warning mb-3">This project is cancelled. Milestone invoices cannot be created for cancelled projects.</div>
    <?php endif; ?>

    <?php if (empty($projects)): ?>
        <div class="text-center py-5">
            <i class="bi bi-folder-x text-muted opacity-50" style="font-size: 3rem;"></i>
            <h5 class="fw-bold mt-3 mb-1">No Active Projects Available</h5>
            <p class="text-muted mb-3">There are currently no active projects. Create a new project first to set up its milestone schedule.</p>
            <a href="../projects/add_project.php" class="btn btn-primary"><i class="bi bi-folder-plus me-1"></i> Create New Project</a>
        </div>
    <?php else: ?>
        <form method="GET" action="add_payment.php">
            <div class="mb-3">
                <label class="form-label fw-bold">Select Project <span class="text-danger">*</span></label>
                <select name="project_id" class="form-select form-select-lg" required onchange="if(this.value) this.form.submit();">
                    <option value="">-- Choose a Project --</option>
                    <?php foreach ($projects as $p): 
                        $pTotal = (float) $p['total_amount'];
                        $pBilled = (float) $p['billed'];
                        $pPaid = (float) $p['paid'];
                        $left = max(0, $pTotal - $pBilled); 
                        $due = max(0, $pBilled - $pPaid);
                        $isComplete = ($pTotal > 0 && $left <= 0.01 && $pPaid >= $pTotal - 0.01);
                        $isFullyScheduled = ($left <= 0.01);
                    ?>
                        <option value="<?= (int) $p['id'] ?>">
                            <?= $e($p['project_code']) ?> — <?= $e($p['title']) ?> · <?= $e($p['client_name']) ?> · 
                            <?php if ($isComplete): ?>
                                ✓ Paid: <?= $m($pTotal) ?>
                            <?php elseif ($isFullyScheduled): ?>
                                Scheduled · Due: <?= $m($due) ?>
                            <?php else: ?>
                                Unscheduled: <?= $m($left) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary px-4">
                Continue to Project <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </form>
    <?php endif; ?>

<?php else: /* ================= PROJECT SELECTED SUMMARY ================= */ ?>

    <!-- Project Header Summary Card -->
    <div class="p-3 mb-4 bg-light rounded border">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
                <span class="fs-6 fw-bold text-primary">
                    <i class="bi bi-folder-check me-1"></i> <?= $e($prj['project_code']) ?> — <?= $e($prj['title']) ?>
                </span>
                <div class="small text-muted mt-1">
                    Client: <strong class="text-dark"><?= $e($prj['client_name']) ?></strong>
                    <?php if (!empty($prj['deadline'])): ?> | Deadline: <strong class="text-dark"><?= date('d M Y', strtotime($prj['deadline'])) ?></strong><?php endif; ?>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($isFullyPaid): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fw-semibold">
                        <i class="bi bi-patch-check-fill me-1"></i> Payment Fully Completed
                    </span>
                <?php endif; ?>
                <span class="badge <?= $statusBadge ?> px-3 py-2"><?= $e($statusLabel) ?></span>
                <a href="add_payment.php" class="btn btn-sm btn-outline-secondary">Change Project</a>
            </div>
        </div>

        <?php if ($bill): ?>
        <div class="row g-2 text-center bg-white p-2 rounded border mt-2">
            <div class="col-6 col-md-3">
                <small class="text-muted d-block fw-semibold" style="font-size: 10.5px;">PROJECT BUDGET</small>
                <span class="fw-bold text-dark"><?= $m($budget) ?></span>
            </div>
            <div class="col-6 col-md-3 border-start">
                <small class="text-primary d-block fw-semibold" style="font-size: 10.5px;">SCHEDULED / BILLED</small>
                <span class="fw-bold text-primary"><?= $m($bill['billed']) ?></span>
            </div>
            <div class="col-6 col-md-3 border-start">
                <small class="text-success d-block fw-semibold" style="font-size: 10.5px;">CLIENT PAID</small>
                <span class="fw-bold text-success"><?= $m($bill['paid']) ?></span>
            </div>
            <div class="col-6 col-md-3 border-start">
                <small class="<?= $balanceDue > 0.005 ? 'text-danger' : 'text-success' ?> d-block fw-semibold" style="font-size: 10.5px;">
                    <?= $balanceDue > 0.005 ? 'BALANCE DUE' : 'PAYMENT STATUS' ?>
                </small>
                <span class="fw-bold <?= $balanceDue > 0.005 ? 'text-danger' : 'text-success' ?>">
                    <?= $balanceDue > 0.005 ? $m($balanceDue) : '✓ 100% Fully Paid' ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($state === 'nobudget'): ?>
        <div class="alert alert-warning mb-0">
            This project does not have a contract budget defined.
            <a href="../projects/edit_project.php?id=<?= (int) $prj['id'] ?>" class="alert-link">Edit project to set total budget</a> before generating milestone invoices.
        </div>

    <?php elseif ($isFullyPaid && !$extraMode): ?>
        <!-- Payment Already Fully Completed Banner -->
        <div class="card p-4 text-center border-0 rounded-4 mb-4 shadow-sm" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border: 1.5px solid #86efac !important;">
            <div class="mb-2">
                <i class="bi bi-patch-check-fill text-success" style="font-size: 3.2rem;"></i>
            </div>
            <h4 class="fw-bold text-success mb-1">Payment Already Fully Completed</h4>
            <p class="text-secondary small mb-3">
                All milestone invoices for this project are <strong>100% paid and fully settled</strong>.
                Total Paid: <strong class="text-success"><?= $m($bill['paid']) ?></strong> of <?= $m($budget) ?> · Outstanding Due: <strong class="text-success">₹0.00</strong>.
            </p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="add_payment.php?project_id=<?= (int) $prj['id'] ?>&extra=1" class="btn btn-outline-primary btn-sm fw-semibold shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> Add Extra / Change-Order Invoice
                </a>
                <a href="../projects/view_project.php?id=<?= (int) $prj['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-folder-check me-1"></i> View Project Scope
                </a>
                <a href="list_payments.php?search=<?= urlencode($prj['project_code']) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-receipt me-1"></i> View Invoices in Ledger
                </a>
            </div>
        </div>

    <?php elseif ($state === 'billed' && !$extraMode): ?>
        <!-- 100% Contract Budget Scheduled but dues pending -->
        <div class="card p-4 text-center border-0 bg-light rounded-3 mb-4">
            <div class="mb-2">
                <i class="bi bi-calendar-check text-primary" style="font-size: 2.8rem;"></i>
            </div>
            <h5 class="fw-bold text-dark mb-1">Contract Budget is Fully Scheduled</h5>
            <p class="text-muted small mb-3">
                The full budget of <strong><?= $m($budget) ?></strong> is already allocated across <strong><?= (int) $bill['count'] ?></strong> milestone invoice(s).
                Outstanding client balance due is <strong class="text-danger"><?= $m($balanceDue) ?></strong>.
                You can record client payments below or add extra change-order invoices.
            </p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="add_payment.php?project_id=<?= (int) $prj['id'] ?>&extra=1" class="btn btn-outline-primary fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i> Add Extra / Change-Order Invoice
                </a>
                <a href="../projects/view_project.php?id=<?= (int) $prj['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-folder-check me-1"></i> View Project Scope
                </a>
                <a href="list_payments.php?search=<?= urlencode($prj['project_code']) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-receipt me-1"></i> View Invoices in Ledger
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Display Existing Invoices Table if project has invoices -->
    <?php if (!empty($existing)): ?>
        <div class="d-flex justify-content-between align-items-center mb-2 mt-2">
            <h6 class="fw-bold text-dark mb-0">
                <i class="bi bi-receipt text-primary me-1"></i> Existing Invoices
            </h6>
            <?php if ($state !== 'extra' && $unbilled <= 0.01): ?>
                <a href="add_payment.php?project_id=<?= (int) $prj['id'] ?>&extra=1" class="btn btn-xs btn-outline-primary text-decoration-none" style="font-size: 11.5px;">
                    <i class="bi bi-plus-circle me-1"></i> Add Extra Invoice
                </a>
            <?php endif; ?>
        </div>
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th>Invoice No</th>
                        <th>Milestone Title</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($existing as $x):
                    $totX = (float) $x['amount'];
                    $pdX  = (float) $x['paid_amount'];
                    $balX = max(0, $totX - $pdX);
                    $settled = $x['status'] === 'paid' || $pdX >= $totX - 0.005;
                    $partialPaid = !$settled && $pdX > 0; 
                ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border font-monospace"><?= $e($x['invoice_no']) ?></span></td>
                        <td><div class="fw-semibold text-dark"><?= $e($x['title']) ?></div></td>
                        <td class="text-end fw-bold"><?= $m($totX) ?></td>
                        <td class="text-end text-success fw-semibold"><?= $m($pdX) ?></td>
                        <td class="text-end <?= $balX > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= $m($balX) ?></td>
                        <td class="text-muted"><?= date('d M Y', strtotime($x['due_date'])) ?></td>
                        <td>
                            <?php if ($settled): ?><span class="badge bg-success">Paid</span>
                            <?php elseif ($partialPaid): ?><span class="badge bg-info text-dark">Partial</span>
                            <?php elseif ($x['due_date'] < date('Y-m-d')): ?><span class="badge bg-danger">Overdue</span>
                            <?php else: ?><span class="badge bg-warning text-dark">Pending</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (!$settled): ?>
                                <button type="button" class="btn btn-sm btn-success py-0 px-2 fw-semibold btn-record-payment" 
                                        style="font-size: 11px;"
                                        data-pay-id="<?= (int) $x['id'] ?>"
                                        data-invoice="<?= $e($x['invoice_no']) ?>"
                                        data-title="<?= $e($x['title']) ?>"
                                        data-client="<?= $e($prj['client_name']) ?>"
                                        data-project-created="<?= date('Y-m-d', strtotime($prj['created_at'])) ?>"
                                        data-total="<?= number_format($totX, 2, '.', '') ?>"
                                        data-paid="<?= number_format($pdX, 2, '.', '') ?>"
                                        data-due="<?= number_format($balX, 2, '.', '') ?>"
                                        data-redirect="add_payment">
                                    <i class="bi bi-cash-coin me-1"></i> Record Payment
                                </button>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2" style="font-size: 10.5px;">
                                    <i class="bi bi-check2-all me-1"></i> Settled
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($state === 'partial'): ?>
        <div class="alert alert-info py-2 small mb-4">
            <i class="bi bi-info-circle me-1"></i> Unscheduled contract balance is <strong><?= $m($unbilled) ?></strong>. Enter details below to create an invoice.
        </div>
    <?php endif; ?>

    <?php if ($state === 'extra'): ?>
        <div class="alert alert-warning py-2 small mb-4">
            <i class="bi bi-plus-circle-fill me-1"></i> <strong>Additional Scope Invoice.</strong> This invoice will be billed as additional work beyond the original scope.
        </div>
    <?php endif; ?>

    <?php /* ============ FRESH: AUTO-SPLIT OR SINGLE TABS ============ */ ?>
    <?php if ($state === 'fresh'): ?>
        <h6 class="fw-bold text-dark mb-3">Choose Invoice Creation Method</h6>
        <ul class="nav nav-pills mb-4 gap-2" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#tabSplit" type="button" role="tab">
                    <i class="bi bi-diagram-3-fill me-1"></i> Auto-Split Milestone Schedule
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#tabSingle" type="button" role="tab">
                    <i class="bi bi-receipt me-1"></i> Single Custom Invoice
                </button>
            </li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabSplit" role="tabpanel">
                <form method="POST" action="" id="splitForm">
                    <input type="hidden" name="form_mode" value="split">
                    <input type="hidden" name="project_id" value="<?= (int) $prj['id'] ?>">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div class="fw-semibold">Split remaining budget of <?= $m($unbilled) ?> into installments:</div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary ms-preset" data-n="4">4 Milestones</button>
                            <button type="button" class="btn btn-outline-primary ms-preset" data-n="3">3 Milestones</button>
                            <button type="button" class="btn btn-outline-primary ms-preset" data-n="2">2 Milestones</button>
                            <button type="button" class="btn btn-outline-primary ms-preset" data-n="1">Full Payment</button>
                        </div>
                    </div>

                    <div class="table-responsive mb-2">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 44px;" class="text-center">#</th>
                                    <th>Milestone Title</th>
                                    <th style="width: 170px;">Amount</th>
                                    <th style="width: 160px;">Due Date</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="msRows"></tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="msAdd">
                        <i class="bi bi-plus-circle me-1"></i> Add Milestone Row
                    </button>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 bg-light rounded border mb-3">
                        <div class="small">
                            Allocated: <strong class="text-primary" id="msAllocated">₹0.00</strong>
                            <span class="text-muted mx-2">|</span>
                            Remaining Unallocated: <strong id="msLeft">₹0.00</strong>
                        </div>
                        <span class="badge py-2 px-3 bg-secondary" id="msBadge">Add milestones</span>
                    </div>

                    <?php if (in_array($prj['status'], ['waiting_confirmation', 'proposal_sent'], true)): ?>
                        <div class="form-check form-switch p-3 bg-light rounded border mb-3">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="notify_client" id="notifyClient" value="1" checked>
                            <label class="form-check-label fw-semibold" for="notifyClient">
                                Send formal proposal and digital agreement link to client via email
                            </label>
                        </div>
                    <?php endif; ?>

                    <p class="text-danger small mt-2 mb-0" id="splitFormError"></p>

                    <div class="pt-3 border-top d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-bold px-4" id="msSubmit" disabled>
                            <i class="bi bi-check-circle-fill me-1"></i> Save Milestone Schedule
                        </button>
                        <a href="list_payments.php" class="btn btn-outline-secondary px-3">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="tab-pane fade" id="tabSingle" role="tabpanel">
                <?= $singleFormHtml ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($state === 'partial' || $state === 'extra'): ?>
        <h6 class="fw-bold text-dark mb-3">
            <?= $state === 'extra' ? 'Create Extra Scope / Out-of-Scope Invoice' : 'Create Invoice for Remaining Balance' ?>
        </h6>
        <?= $singleFormHtml ?>
    <?php endif; ?>

<?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php if ($showSingle): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const CFG = <?= json_encode($jsCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const inr = n => '₹' + Number(n).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    /* ---------- Single invoice amount validator ---------- */
    const sAmt = document.getElementById('spAmount');
    if (sAmt) {
        const fb  = document.getElementById('spFeedback');
        const btn = document.getElementById('spSubmit');
        const upd = function () {
            const v = parseFloat(sAmt.value) || 0;
            let ok = v > 0;
            if (v <= 0) {
                fb.className = 'form-text';
                fb.textContent = CFG.extra ? 'Enter extra invoice amount.' : 'Maximum allowed: ' + inr(CFG.unbilled);
            } else if (CFG.extra) {
                fb.className = 'form-text text-warning-emphasis fw-semibold';
                fb.textContent = inr(v) + ' will be billed beyond contract budget.';
            } else if (v > CFG.unbilled + 0.005) {
                ok = false;
                fb.className = 'form-text text-danger fw-semibold';
                fb.textContent = 'Amount cannot exceed remaining unscheduled budget (' + inr(CFG.unbilled) + ').';
            } else {
                fb.className = 'form-text text-success';
                fb.textContent = 'Remaining unscheduled after this invoice: ' + inr(Math.max(0, CFG.unbilled - v));
            }
            btn.disabled = !ok;
        };

        function setSingleFieldError(pId, inputElem, msg) {
            const p = document.getElementById(pId);
            if (p) {
                p.textContent = msg;
                p.classList.remove('d-none');
            }
            if (inputElem) {
                inputElem.classList.add('is-invalid');
            }
        }

        function clearSingleFieldValidation(pId, inputElem) {
            const p = document.getElementById(pId);
            if (p) {
                p.textContent = '';
                p.classList.add('d-none');
            }
            if (inputElem) {
                inputElem.classList.remove('is-invalid');
            }
        }

        const spTitleInput = document.getElementById('spTitle');
        const spDueDateInput = document.getElementById('spDueDate');

        if (spTitleInput) {
            spTitleInput.addEventListener('input', function() { clearSingleFieldValidation('spTitleError', spTitleInput); });
            spTitleInput.addEventListener('blur', function() {
                if (!spTitleInput.value.trim()) {
                    setSingleFieldError('spTitleError', spTitleInput, 'Milestone Title is required.');
                } else {
                    clearSingleFieldValidation('spTitleError', spTitleInput);
                }
            });
        }

        if (spDueDateInput) {
            spDueDateInput.addEventListener('change', function() { clearSingleFieldValidation('spDueDateError', spDueDateInput); });
        }

        sAmt.addEventListener('input', function() {
            clearSingleFieldValidation('spAmountError', sAmt);
            upd();
        });

        const full = document.getElementById('spFull');
        if (full) full.addEventListener('click', function () { sAmt.value = CFG.unbilled.toFixed(2); clearSingleFieldValidation('spAmountError', sAmt); upd(); });

        const sForm = document.getElementById('singleForm');
        if (sForm) {
            sForm.addEventListener('submit', function (e) {
                let isValid = true;
                if (!spTitleInput || !spTitleInput.value.trim()) {
                    setSingleFieldError('spTitleError', spTitleInput, 'Milestone Title is required.');
                    isValid = false;
                } else {
                    clearSingleFieldValidation('spTitleError', spTitleInput);
                }

                if (!spDueDateInput || !spDueDateInput.value) {
                    setSingleFieldError('spDueDateError', spDueDateInput, 'Due Date is required.');
                    isValid = false;
                } else {
                    clearSingleFieldValidation('spDueDateError', spDueDateInput);
                }

                const v = parseFloat(sAmt.value) || 0;
                if (v <= 0) {
                    setSingleFieldError('spAmountError', sAmt, 'Amount is required and must be greater than 0.');
                    isValid = false;
                } else if (!CFG.extra && v > CFG.unbilled + 0.005) {
                    setSingleFieldError('spAmountError', sAmt, 'Amount cannot exceed remaining unscheduled budget (' + inr(CFG.unbilled) + ').');
                    isValid = false;
                } else {
                    clearSingleFieldValidation('spAmountError', sAmt);
                }

                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    const firstInvalid = sForm.querySelector('.is-invalid');
                    if (firstInvalid) firstInvalid.focus();
                } else {
                    btn.disabled = true;
                }
            });
        }
        upd();
    }

    /* ---------- Auto-split generator ---------- */
    const body = document.getElementById('msRows');
    if (!body) return;
    const badge = document.getElementById('msBadge'), allocEl = document.getElementById('msAllocated'),
          leftEl = document.getElementById('msLeft'), submit = document.getElementById('msSubmit');

    const PRESETS = {
        4: [['Milestone 1: Advance', 25], ['Milestone 2: Frontend', 25], ['Milestone 3: Backend', 25], ['Milestone 4: Handover', 25]],
        3: [['Milestone 1: Advance', 30], ['Milestone 2: Development', 30], ['Milestone 3: Handover', 40]],
        2: [['Milestone 1: Advance', 50], ['Milestone 2: Handover', 50]],
        1: [['Milestone 1: Full Payment', 100]]
    };

    function dueDates(n) {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        let end = CFG.deadline ? new Date(CFG.deadline + 'T00:00:00') : null;
        if (!end || end <= today) { end = new Date(today); end.setDate(end.getDate() + 30); }
        const span = end - today, out = [];
        for (let i = 1; i <= n; i++) out.push(ymd(new Date(today.getTime() + Math.round(span * i / n))));
        return out;
    }

    function splitAmounts(total, pcts) {
        const cents = Math.round(total * 100); let used = 0;
        return pcts.map(function (p, i) {
            const c = i === pcts.length - 1 ? cents - used : Math.round(cents * p / 100);
            used += c;
            return (c / 100).toFixed(2);
        });
    }

    function addRow(title, amount, date) {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="text-center fw-bold text-muted ms-idx"></td>' +
            '<td><input type="text" name="milestone_title[]" maxlength="150" class="form-control form-control-sm" required></td>' +
            '<td><div class="input-group input-group-sm"><span class="input-group-text">₹</span>' +
            '<input type="number" step="0.01" min="0.01" name="milestone_amount[]" class="form-control fw-bold ms-amt" required></div></td>' +
            '<td><input type="date" name="milestone_due_date[]" class="form-control form-control-sm" required></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 ms-del" title="Remove"><i class="bi bi-x-lg"></i></button></td>';
        const f = tr.querySelectorAll('input');
        f[0].value = title; f[1].value = amount; f[2].value = date; f[2].min = CFG.created;
        f[1].addEventListener('input', recalc);
        tr.querySelector('.ms-del').addEventListener('click', function () { tr.remove(); recalc(); });
        body.appendChild(tr);
    }

    function recalc() {
        let sum = 0, bad = false;
        body.querySelectorAll('tr').forEach(function (tr, i) {
            tr.querySelector('.ms-idx').textContent = i + 1;
            const v = parseFloat(tr.querySelector('.ms-amt').value) || 0;
            if (v <= 0) bad = true;
            sum += v;
        });
        const diff = Math.round((CFG.unbilled - sum) * 100) / 100;
        allocEl.textContent = inr(sum);
        leftEl.textContent = inr(Math.max(0, diff));
        leftEl.className = diff > 0.01 ? 'text-warning-emphasis' : 'text-success';
        if (!body.children.length) {
            badge.className = 'badge py-2 px-3 bg-secondary'; badge.textContent = 'Add milestones'; submit.disabled = true;
        } else if (diff < -0.01) {
            badge.className = 'badge py-2 px-3 bg-danger'; badge.textContent = 'Exceeds budget by ' + inr(-diff); submit.disabled = true;
        } else if (bad) {
            badge.className = 'badge py-2 px-3 bg-danger'; badge.textContent = 'Please enter valid amounts in all rows'; submit.disabled = true;
        } else if (Math.abs(diff) <= 0.01) {
            badge.className = 'badge py-2 px-3 bg-success'; badge.textContent = '100% Allocated'; submit.disabled = false;
        } else {
            badge.className = 'badge py-2 px-3 bg-warning text-dark'; badge.textContent = inr(diff) + ' unallocated'; submit.disabled = false;
        }
    }

    function preset(n) {
        body.innerHTML = '';
        const p = PRESETS[n], dates = dueDates(n), amts = splitAmounts(CFG.unbilled, p.map(x => x[1]));
        p.forEach(function (x, i) { addRow(x[0], amts[i], dates[i]); });
        recalc();
    }

    document.querySelectorAll('.ms-preset').forEach(b => b.addEventListener('click', () => preset(parseInt(b.dataset.n, 10))));
    document.getElementById('msAdd').addEventListener('click', function () {
        let sum = 0;
        body.querySelectorAll('.ms-amt').forEach(i => sum += parseFloat(i.value) || 0);
        const left = Math.max(0, CFG.unbilled - sum);
        const last = body.querySelector('tr:last-child input[type=date]');
        addRow('Milestone ' + (body.children.length + 1), left > 0 ? left.toFixed(2) : '', last ? last.value : ymd(new Date()));
        recalc();
    });
    const splitFormEl = document.getElementById('splitForm');
    const splitErrEl = document.getElementById('splitFormError');
    if (splitFormEl) {
        splitFormEl.addEventListener('submit', function (e) {
            let rowErr = false;
            body.querySelectorAll('tr').forEach(function(tr) {
                const titleInput = tr.querySelector('input[name="milestone_title[]"]');
                const amtInput = tr.querySelector('input[name="milestone_amount[]"]');
                const dateInput = tr.querySelector('input[name="milestone_due_date[]"]');
                if (!titleInput || !titleInput.value.trim() || !amtInput || parseFloat(amtInput.value) <= 0 || !dateInput || !dateInput.value) {
                    rowErr = true;
                }
            });
            if (rowErr) {
                e.preventDefault();
                if (splitErrEl) splitErrEl.textContent = 'Please fill out all milestone titles, valid amounts, and due dates before saving.';
                return;
            }
            if (splitErrEl) splitErrEl.textContent = '';
            submit.disabled = true;
        });
    }
    preset(4);
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>