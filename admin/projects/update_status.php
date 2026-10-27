<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
if (function_exists('requirePermission')) requirePermission('projects_manage');
$pageTitle = 'Update Project Status';

$id     = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$status = trim($_POST['status'] ?? ($_GET['status'] ?? ''));
$notes  = trim($_POST['status_notes'] ?? '');
$token  = $_POST['csrf_token'] ?? ($_GET['token'] ?? '');

$allowed = ['draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'advance_payment_pending',
            'in_progress', 'review', 'on_hold', 'completed', 'cancelled'];

$statusLabels = [
    'draft'                   => 'Draft',
    'proposal_sent'           => 'Proposal Sent',
    'waiting_confirmation'    => 'Waiting Confirmation',
    'revision_requested'      => 'Revision Requested',
    'advance_payment_pending' => 'Advance Payment Pending',
    'in_progress'             => 'In Progress',
    'review'                  => 'Review / Approval',
    'on_hold'                 => 'On Hold',
    'completed'               => 'Completed',
    'cancelled'               => 'Cancelled',
];

// Ye statuses client agreement ke bina nahi ho sakte
$needAgreement = ['advance_payment_pending', 'in_progress', 'review', 'completed'];
// Ye statuses advance payment aane ke baad hi
$needAdvance   = ['in_progress', 'review', 'completed'];

$backTo = function (int $pid, string $redirect) {
    if ($redirect === 'list') header('Location: list_projects.php');
    else header('Location: view_project.php?id=' . $pid);
    exit;
};

/* =============== ACTION =============== */
if ($id > 0 && $status !== '') {
    $redirect = trim($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));

    if ($token === '' || !verifyActionSecurity($token)) {
        setFlash('error', 'Security token missing or expired. Please try again.');
        $backTo($id, $redirect);
    }
    if (!in_array($status, $allowed, true)) {
        setFlash('error', 'Invalid status value.');
        $backTo($id, $redirect);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, project_code, title, client_id, status, agreed_at
                               FROM projects WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $prj = $stmt->fetch();

        if (!$prj) {
            setFlash('error', 'Project not found.');
            header('Location: list_projects.php');
            exit;
        }

        $oldStatus = $prj['status'];
        if ($oldStatus === $status) {
            setFlash('info', 'Project is already in this status.');
            $backTo($id, $redirect);
        }

        // Guard 1: agreement
        if (in_array($status, $needAgreement, true) && empty($prj['agreed_at'])) {
            setFlash('error', "Cannot move to '{$statusLabels[$status]}': client has not accepted the proposal yet.");
            $backTo($id, $redirect);
        }

        // Guard 2: advance payment (pehla / advance milestone)
        if (in_array($status, $needAdvance, true)) {
            $q = $pdo->prepare("SELECT invoice_no, status, paid_amount, amount FROM payments
                                WHERE project_id = ? AND deleted_at IS NULL
                                ORDER BY is_advance DESC, due_date ASC, id ASC LIMIT 1");
            $q->execute([$id]);
            $adv = $q->fetch();
            if ($adv && $adv['status'] !== 'paid' && (float) $adv['paid_amount'] < (float) $adv['amount']) {
                setFlash('error', "Cannot move to '{$statusLabels[$status]}'! Advance payment ({$adv['invoice_no']}) must be received first.");
                $backTo($id, $redirect);
            }
        }

        // Guard 3: completed = saare invoices settle
        if ($status === 'completed') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE project_id = ? AND deleted_at IS NULL AND paid_amount < amount");
            $q->execute([$id]);
            if ((int) $q->fetchColumn() > 0) {
                setFlash('error', 'Cannot mark Completed: some invoices are still unpaid.');
                $backTo($id, $redirect);
            }
        }

        $pdo->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $id]);

        $label    = $statusLabels[$status];
        $oldLabel = $statusLabels[$oldStatus] ?? ucfirst(str_replace('_', ' ', $oldStatus));

        $logDesc = "Status changed from '{$oldLabel}' to '{$label}'." . ($notes ? " Note: {$notes}" : "");
        addProjectLog($pdo, $id, 'status_changed', "Status: {$label}", $logDesc, 'admin', $admin['name'] ?? 'Admin');

        addNotification($pdo, 'client', (int) $prj['client_id'], 'Project Status Updated',
            "Status for '{$prj['title']}' ({$prj['project_code']}) is now {$label}.", '/client-flow/client/projects.php', 'info', 'bi-flag-fill');
        addNotification($pdo, 'admin', (int) $admin['id'], 'Status Changed',
            "{$prj['project_code']} status updated to {$label}.", "/client-flow/admin/projects/view_project.php?id={$id}", 'success', 'bi-flag-fill');

        if ($status === 'completed' && function_exists('sendProjectCompletedEmail')) {
            try { sendProjectCompletedEmail($pdo, $id); } catch (Throwable $e) { /* ignore */ }
        }

        syncProjectStatus($pdo, $id);
        setFlash('success', "Project {$prj['project_code']} status successfully updated to '{$label}'.");
    } catch (PDOException $e) {
        setFlash('error', 'Status update error: ' . $e->getMessage());
    }
    $backTo($id, $redirect);
}

/* =============== PAGE (GET) =============== */
if ($id <= 0) {
    $firstPrj = $pdo->query("SELECT id FROM projects WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetch();
    if ($firstPrj) {
        $id = (int) $firstPrj['id'];
    } else {
        setFlash('error', 'No projects found.');
        header('Location: list_projects.php');
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS client_name, c.client_code, c.email AS client_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    setFlash('error', 'Project not found.');
    header('Location: list_projects.php');
    exit;
}

$statusDefinitions = [
    'draft' => ['title' => 'Draft', 'badge' => 'bg-secondary',
        'desc' => 'Project is being planned internally and has not yet been dispatched to client.'],
    'proposal_sent' => ['title' => 'Proposal Sent', 'badge' => 'bg-warning text-dark',
        'desc' => 'Proposal & agreement link emailed to client; awaiting acceptance.'],
    'waiting_confirmation' => ['title' => 'Waiting Confirmation', 'badge' => 'bg-warning text-dark',
        'desc' => 'Formal proposal & milestone agreement dispatched; awaiting client digital signature.'],
    'revision_requested' => ['title' => 'Revision Requested', 'badge' => 'bg-info text-dark',
        'desc' => 'Client asked for changes to the proposal before accepting.'],
    'advance_payment_pending' => ['title' => 'Advance Payment Pending', 'badge' => 'bg-info text-dark',
        'desc' => 'Client accepted proposal agreement; awaiting initial advance deposit before kick-off.'],
    'in_progress' => ['title' => 'In Progress', 'badge' => 'bg-primary',
        'desc' => 'Advance secured; project development and execution are actively underway.'],
    'review' => ['title' => 'Review / Approval', 'badge' => 'bg-primary-subtle text-primary-emphasis',
        'desc' => 'Deliverables submitted to client for sign-off, feedback, or testing verification.'],
    'on_hold' => ['title' => 'On Hold', 'badge' => 'bg-dark',
        'desc' => 'Project temporarily paused due to client request, dependencies, or scope review.'],
    'completed' => ['title' => 'Completed', 'badge' => 'bg-success',
        'desc' => 'All deliverables approved, invoices fully settled, and source handover complete.'],
    'cancelled' => ['title' => 'Cancelled', 'badge' => 'bg-danger',
        'desc' => 'Project contract cancelled or terminated.'],
];

$flash = getFlash();
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
                        <li class="breadcrumb-item"><a href="list_projects.php" class="text-decoration-none">Projects</a></li>
                        <li class="breadcrumb-item"><a href="view_project.php?id=<?php echo $id; ?>" class="text-decoration-none"><?php echo htmlspecialchars($project['project_code']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Update Status</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0"><i class="bi bi-flag-fill text-primary me-2"></i>Update Project Lifecycle Status</h4>
            </div>
            <a href="view_project.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to Project</a>
        </div>

        <div class="d-flex justify-content-center">
            <div class="card shadow-sm border-0" style="max-width: 820px; width: 100%;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fs-6 fw-bold text-dark"><?php echo htmlspecialchars($project['title']); ?></span>
                        <div class="small text-muted">
                            Client: <strong><?php echo htmlspecialchars($project['client_name']); ?></strong> (<?php echo htmlspecialchars($project['client_code']); ?>) |
                            Budget: <strong class="text-dark">₹<?php echo number_format($project['total_amount'], 2); ?></strong>
                        </div>
                    </div>
                    <span class="badge font-monospace bg-light text-dark border fs-6"><?php echo htmlspecialchars($project['project_code']); ?></span>
                </div>

                <div class="card-body p-4">
                    <form method="POST" action="update_status.php">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">

                        <div class="mb-4 p-3 bg-light rounded border d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <small class="text-muted d-block fw-semibold text-uppercase" style="font-size: 11px;">Current Lifecycle Stage</small>
                                <span class="fs-5 fw-bold text-dark">
                                    <?php echo htmlspecialchars($statusDefinitions[$project['status']]['title'] ?? ucfirst(str_replace('_', ' ', $project['status']))); ?>
                                </span>
                            </div>
                            <span class="badge py-2 px-3 fs-6 <?php echo $statusDefinitions[$project['status']]['badge'] ?? 'bg-secondary'; ?>">
                                CURRENT: <?php echo strtoupper(str_replace('_', ' ', $project['status'])); ?>
                            </span>
                        </div>

                        <label class="form-label fw-bold mb-2">Select New Status <span class="text-danger">*</span></label>
                        <div class="row g-2 mb-4">
                            <?php foreach ($statusDefinitions as $key => $info):
                                $isChecked = ($project['status'] === $key); ?>
                                <div class="col-md-6">
                                    <label class="d-block p-3 rounded border h-100 <?php echo $isChecked ? 'border-primary bg-primary-subtle' : 'bg-white'; ?> position-relative" style="cursor: pointer;" for="st_<?php echo $key; ?>">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="radio" name="status" value="<?php echo $key; ?>" id="st_<?php echo $key; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                                <span class="form-check-label fw-bold text-dark ms-1"><?php echo $info['title']; ?></span>
                                            </div>
                                            <span class="badge <?php echo $info['badge']; ?> py-1 px-2" style="font-size: 11px;"><?php echo strtoupper(str_replace('_', ' ', $key)); ?></span>
                                        </div>
                                        <p class="small text-muted mb-0 ps-4" style="font-size: 12px; line-height: 1.4;"><?php echo $info['desc']; ?></p>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Status Update Note</label>
                            <input type="text" name="status_notes" class="form-control" maxlength="255" placeholder="e.g. Advance payment confirmed by finance; starting sprint 1 development">
                            <div class="form-text small">This note will be permanently logged in the project audit history.</div>
                        </div>

                        <div class="pt-3 border-top d-flex gap-2">
                            <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-check-circle-fill me-1"></i> Update Status</button>
                            <a href="view_project.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary px-3">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>