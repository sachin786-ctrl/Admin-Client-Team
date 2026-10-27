<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('projects_view');
$pageTitle = 'View Project';
$flash = getFlash();

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlash('error', 'Invalid project ID.');
    header('Location: list_projects.php');
    exit;
}

// -------------------------------------------------------------
// POST HANDLERS: Unified Milestone & Payment Operations on Same Page
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('projects_manage');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Security token missing or expired. Please try again.');
        header("Location: view_project.php?id={$id}");
        exit;
    }
    $action = trim($_POST['action'] ?? '');

    // 1. ADD MILESTONE
    if ($action === 'add_milestone') {
        $title          = trim($_POST['title'] ?? '');
        $amount         = (float) ($_POST['amount'] ?? 0);
        $dueDate        = trim($_POST['due_date'] ?? '');
        $isAdvance      = isset($_POST['is_advance']) && $_POST['is_advance'] === '1' ? 1 : 0;
        $workStatus     = trim($_POST['work_status'] ?? 'not_started');
        $deliverableUrl = trim($_POST['deliverable_url'] ?? '');
        $notes          = trim($_POST['deliverable_notes'] ?? '');

        if ($title === '' || $amount <= 0 || empty($dueDate)) {
            setFlash('error', 'Please enter a valid milestone title, positive amount, and due date.');
            header("Location: view_project.php?id={$id}");
            exit;
        }

        try {
            // Sequential Invoice Generator
            $stmtLast = $pdo->query("SELECT invoice_no FROM payments ORDER BY id DESC LIMIT 1");
            $last = $stmtLast->fetch();
            $newNum = 1001;
            if ($last && preg_match('/INV-(\d+)/', $last['invoice_no'], $matches)) {
                $newNum = ((int) $matches[1]) + 1;
            }
            $invoiceNo = 'INV-' . str_pad($newNum, 4, '0', STR_PAD_LEFT);

            // Fetch Client ID & Project Creation Date
            $pStmt = $pdo->prepare("SELECT client_id, created_at FROM projects WHERE id = ?");
            $pStmt->execute([$id]);
            $prjInfo = $pStmt->fetch();
            $clientId = (int) ($prjInfo['client_id'] ?? 0);
            $prjCreatedDate = date('Y-m-d', strtotime($prjInfo['created_at'] ?? 'now'));

            if ($dueDate < $prjCreatedDate) {
                setFlash('error', "Milestone due date cannot be earlier than project creation date (" . date('d M Y', strtotime($prjCreatedDate)) . ").");
                header("Location: view_project.php?id={$id}");
                exit;
            }

            $ins = $pdo->prepare("
                INSERT INTO payments 
                (invoice_no, project_id, client_id, title, amount, paid_amount, due_date, status, work_status, is_advance, deliverable_url, deliverable_notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0.00, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([$invoiceNo, $id, $clientId, $title, $amount, $dueDate, $workStatus, $isAdvance, $deliverableUrl ?: null, $notes ?: null]);

            // Recalculate Project Total Budget
            $updBudget = $pdo->prepare("
                UPDATE projects 
                SET total_amount = (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE project_id = ?)
                WHERE id = ?
            ");
            $updBudget->execute([$id, $id]);

            addProjectLog($pdo, $id, 'milestone_added', "Milestone Added ({$invoiceNo})", "Added milestone '{$title}' for ₹" . number_format($amount, 2) . " due on " . date('d M Y', strtotime($dueDate)) . ".", 'admin', $admin['name'] ?? 'Admin');
            syncProjectStatus($pdo, $id);

            setFlash('success', "Milestone '{$title}' ({$invoiceNo}) added successfully!");
            header("Location: view_project.php?id={$id}");
            exit;
        } catch (PDOException $e) {
            setFlash('error', 'Database Error: ' . $e->getMessage());
            header("Location: view_project.php?id={$id}");
            exit;
        }
    }

    // 2. EDIT MILESTONE
    if ($action === 'edit_milestone') {
        $milestoneId    = (int) ($_POST['milestone_id'] ?? 0);
        $title          = trim($_POST['title'] ?? '');
        $amount         = (float) ($_POST['amount'] ?? 0);
        $dueDate        = trim($_POST['due_date'] ?? '');
        $isAdvance      = isset($_POST['is_advance']) && $_POST['is_advance'] === '1' ? 1 : 0;
        $workStatus     = trim($_POST['work_status'] ?? 'not_started');
        $deliverableUrl = trim($_POST['deliverable_url'] ?? '');
        $notes          = trim($_POST['deliverable_notes'] ?? '');

        if ($milestoneId <= 0 || $title === '' || $amount <= 0 || empty($dueDate)) {
            setFlash('error', 'Please provide valid milestone details.');
            header("Location: view_project.php?id={$id}");
            exit;
        }

        try {
            // Verify Milestone exists for this project
            $stmtCheck = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND project_id = ?");
            $stmtCheck->execute([$milestoneId, $id]);
            $existing = $stmtCheck->fetch();

            if (!$existing) {
                setFlash('error', 'Milestone not found.');
                header("Location: view_project.php?id={$id}");
                exit;
            }

            // Project Date Guard: Due date cannot be earlier than project creation date
            $pStmt = $pdo->prepare("SELECT created_at FROM projects WHERE id = ?");
            $pStmt->execute([$id]);
            $prjCreatedDate = date('Y-m-d', strtotime($pStmt->fetchColumn() ?: 'now'));

            if ($dueDate < $prjCreatedDate) {
                setFlash('error', "Milestone due date cannot be earlier than project creation date (" . date('d M Y', strtotime($prjCreatedDate)) . ").");
                header("Location: view_project.php?id={$id}");
                exit;
            }

            // Guard: Cannot set amount below already received payment!
            $paidAmt = (float) $existing['paid_amount'];
            if ($paidAmt > 0 && $amount < ($paidAmt - 0.001)) {
                setFlash('error', "Milestone amount ₹" . number_format($amount, 2) . " cannot be less than already collected payment ₹" . number_format($paidAmt, 2) . ".");
                header("Location: view_project.php?id={$id}");
                exit;
            }

            // Keep status aligned with amount
            $newStatus = $existing['status'];
            if ($paidAmt >= $amount && $amount > 0) {
                $newStatus = 'paid';
            } elseif ($paidAmt > 0 && $paidAmt < $amount) {
                $newStatus = 'partial';
            } elseif ($paidAmt == 0) {
                $newStatus = (strtotime($dueDate) < strtotime(date('Y-m-d'))) ? 'overdue' : 'pending';
            }

            $upd = $pdo->prepare("
                UPDATE payments 
                SET title = ?, amount = ?, due_date = ?, is_advance = ?, work_status = ?, deliverable_url = ?, deliverable_notes = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND project_id = ?
            ");
            $upd->execute([$title, $amount, $dueDate, $isAdvance, $workStatus, $deliverableUrl ?: null, $notes ?: null, $newStatus, $milestoneId, $id]);

            // Recalculate Project Total Budget
            $updBudget = $pdo->prepare("
                UPDATE projects 
                SET total_amount = (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE project_id = ?)
                WHERE id = ?
            ");
            $updBudget->execute([$id, $id]);

            addProjectLog($pdo, $id, 'milestone_updated', "Milestone Updated ({$existing['invoice_no']})", "Updated milestone '{$title}' (Amount: ₹" . number_format($amount, 2) . ", Work Status: {$workStatus}).", 'admin', $admin['name'] ?? 'Admin');
            syncProjectStatus($pdo, $id);

            setFlash('success', "Milestone {$existing['invoice_no']} updated successfully!");
            header("Location: view_project.php?id={$id}");
            exit;
        } catch (PDOException $e) {
            setFlash('error', 'Database Error: ' . $e->getMessage());
            header("Location: view_project.php?id={$id}");
            exit;
        }
    }

    // 3. DELETE MILESTONE
    if ($action === 'delete_milestone') {
        $milestoneId = (int) ($_POST['milestone_id'] ?? 0);

        try {
            $stmtCheck = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND project_id = ?");
            $stmtCheck->execute([$milestoneId, $id]);
            $existing = $stmtCheck->fetch();

            if (!$existing) {
                setFlash('error', 'Milestone not found.');
                header("Location: view_project.php?id={$id}");
                exit;
            }

            if ((float)$existing['paid_amount'] > 0 || $existing['status'] === 'paid') {
                setFlash('error', 'Cannot delete milestone with recorded payments. Please refund or edit instead.');
                header("Location: view_project.php?id={$id}");
                exit;
            }

            $del = $pdo->prepare("DELETE FROM payments WHERE id = ? AND project_id = ?");
            $del->execute([$milestoneId, $id]);

            // Recalculate Project Total Budget
            $updBudget = $pdo->prepare("
                UPDATE projects 
                SET total_amount = (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE project_id = ?)
                WHERE id = ?
            ");
            $updBudget->execute([$id, $id]);

            addProjectLog($pdo, $id, 'milestone_deleted', "Milestone Removed ({$existing['invoice_no']})", "Deleted milestone '{$existing['title']}' totaling ₹" . number_format($existing['amount'], 2) . ".", 'admin', $admin['name'] ?? 'Admin');
            syncProjectStatus($pdo, $id);

            setFlash('success', "Milestone {$existing['invoice_no']} deleted successfully.");
            header("Location: view_project.php?id={$id}");
            exit;
        } catch (PDOException $e) {
            setFlash('error', 'Database Error: ' . $e->getMessage());
            header("Location: view_project.php?id={$id}");
            exit;
        }
    }

    // 4. UPDATE PROJECT STATUS (Directly from view_project.php)
    if ($action === 'update_project_status') {
        $newStatus = trim($_POST['status'] ?? '');
        $allowed = ['draft', 'waiting_confirmation', 'advance_payment_pending', 'in_progress', 'review', 'on_hold', 'completed', 'cancelled'];
        
        if ($id > 0 && in_array($newStatus, $allowed, true)) {
            try {
                $stP = $pdo->prepare("SELECT project_code, title, client_id, status FROM projects WHERE id = ?");
                $stP->execute([$id]);
                $pRow = $stP->fetch();

                if ($pRow) {
                    $oldStatus = $pRow['status'];
                    $upd = $pdo->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$newStatus, $id]);

                    $statusLabels = [
                        'draft'                  => 'Draft',
                        'waiting_confirmation'   => 'Waiting Confirmation',
                        'advance_payment_pending'=> 'Advance Payment Pending',
                        'in_progress'            => 'In Progress',
                        'review'                 => 'Review / Approval',
                        'on_hold'                => 'On Hold',
                        'completed'              => 'Completed',
                        'cancelled'              => 'Cancelled',
                    ];
                    $lbl = $statusLabels[$newStatus] ?? ucfirst(str_replace('_', ' ', $newStatus));
                    
                    addProjectLog($pdo, $id, 'status_changed', "Status: {$lbl}", "Project status changed from '" . ucfirst(str_replace('_', ' ', $oldStatus)) . "' to '{$lbl}'.", 'admin', $admin['name'] ?? 'Admin');
                    addNotification($pdo, 'client', $pRow['client_id'], 'Project Status Updated', "Project '{$pRow['title']}' ({$pRow['project_code']}) status is now {$lbl}.", '/client-flow/client/projects.php', 'info', 'bi-flag-fill');

                    // Dispatch completed email if status changed to completed
                    if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                        if (function_exists('sendProjectCompletedEmail')) {
                            try {
                                sendProjectCompletedEmail($pdo, $id);
                            } catch (Throwable $e) {
                                // Suppress error
                            }
                        }
                    }

                    syncProjectStatus($pdo, $id);
                    setFlash('success', "Project {$pRow['project_code']} status updated to '{$lbl}' successfully!");
                }
            } catch (PDOException $e) {
                setFlash('error', 'Database Error: ' . $e->getMessage());
            }
        }
        header("Location: view_project.php?id={$id}");
        exit;
    }
}

// -------------------------------------------------------------
// READ PROJECT & MILESTONES DATA
// -------------------------------------------------------------
syncProjectStatus($pdo, $id);

$stmt = $pdo->prepare("
    SELECT p.*, 
           c.name AS client_name, c.company_name, c.client_code, c.email AS client_email, c.phone AS client_phone, c.address AS client_address, c.profile_pic AS client_profile_pic,
           a.name AS admin_name, a.email AS admin_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN admin a ON a.id = p.created_by
    WHERE p.id = ?
");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    setFlash('error', 'Project not found.');
    header('Location: list_projects.php');
    exit;
}

$stmtPay = $pdo->prepare("SELECT * FROM payments WHERE project_id = ? ORDER BY due_date ASC, id ASC");
$stmtPay->execute([$id]);
$payments = $stmtPay->fetchAll();

$logs = getProjectLogs($pdo, $id);

$totalBudget = (float) $project['total_amount'];
$totalPaid = 0.00;
$allDeliveredOrApproved = !empty($payments);
foreach ($payments as $m) {
    $totalPaid += (float) ($m['paid_amount'] ?? ($m['status'] === 'paid' ? $m['amount'] : 0));
    $wSt = $m['work_status'] ?? 'not_started';
    if (!in_array($wSt, ['delivered', 'approved'], true)) {
        $allDeliveredOrApproved = false;
    }
}
$totalDue = max(0, $totalBudget - $totalPaid);
$isProposalPending = ($project['status'] === 'proposal_sent' || ($project['status'] === 'waiting_confirmation' && empty($project['agreed_at'])));
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-permanent mb-4">
                <i class="bi <?php echo $flash['type'] === 'error' ? 'bi-exclamation-triangle-fill text-danger' : 'bi-check-circle-fill text-success'; ?> fs-5"></i>
                <div><?php echo htmlspecialchars($flash['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Celebration Alert when all milestones are settled & delivered -->
        <?php if ($project['status'] !== 'completed' && $totalDue <= 0 && !empty($payments) && $allDeliveredOrApproved): ?>
            <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-3 p-3 mb-4 shadow-sm border-2 border-success bg-success-subtle">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle p-2 bg-success text-white">
                        <i class="bi bi-patch-check-fill fs-3"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-success-emphasis">All Deliverables Delivered & 100% Payments Settled!</h6>
                        <div class="text-secondary small">All <?php echo count($payments); ?> milestones are settled and deliverables marked. Click to complete project.</div>
                    </div>
                </div>
                <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>" class="m-0"
                      data-confirm-text="Are you sure you want to mark this project as COMPLETED?"
                      data-confirm-title="Complete Project"
                      data-confirm-btn="Complete Project"
                      data-confirm-color="success"
                      data-confirm-icon="<i class='bi bi-check-circle-fill text-success'></i>">
                    <input type="hidden" name="action" value="update_project_status">
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow">
                        <i class="bi bi-check-circle-fill me-1"></i> Complete Project Now
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Action Bar Top -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($project['title']); ?></h4>
                    <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($project['project_code']); ?></span>
                    <?php if (!empty($project['token_expires_at'])): ?>
                        <span class="badge bg-secondary-subtle text-muted border font-monospace small" title="Proposal Token Expiry">
                            <i class="bi bi-clock me-1"></i>Expires: <?php echo date('d M Y', strtotime($project['token_expires_at'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-muted mb-0">Client: <strong><?php echo htmlspecialchars($project['client_name']); ?></strong> · <code><?php echo htmlspecialchars($project['client_code']); ?></code></p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($project['status'] !== 'completed'): ?>
                    <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>" class="d-inline m-0"
                          data-confirm-text="Are you sure you want to mark this project as COMPLETED?"
                          data-confirm-title="Complete Project"
                          data-confirm-btn="Complete Project"
                          data-confirm-color="success"
                          data-confirm-icon="<i class='bi bi-check-circle-fill text-success'></i>">
                        <input type="hidden" name="action" value="update_project_status">
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-success fw-semibold shadow-sm" title="Mark Project Completed">
                            <i class="bi bi-check-circle-fill me-1"></i> Complete Project
                        </button>
                    </form>
                <?php endif; ?>
                <a href="send_proposal.php?id=<?php echo $project['id']; ?>&redirect=view&token=<?php echo generateCsrfToken(); ?>" class="btn btn-outline-info" title="<?php echo !empty($project['proposal_sent_at']) ? 'Resend Proposal to Client' : 'Send Proposal to Client'; ?>">
                    <i class="bi bi-send-fill me-1"></i> <?php echo !empty($project['proposal_sent_at']) ? 'Resend Proposal' : 'Send Proposal'; ?>
                </a>
                <a href="edit_project.php?id=<?php echo $project['id']; ?>" class="btn btn-outline-primary" title="Edit Project Details">
                    <i class="bi bi-pencil-square me-1"></i> Edit Project
                </a>
                <a href="view_proposal.php?id=<?php echo $project['id']; ?>" class="btn btn-primary" title="View Full Proposal">
                    <i class="bi bi-file-earmark-text me-1"></i> View Proposal
                </a>
                <a href="list_projects.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Projects
                </a>
            </div>
        </div>

        <?php if ($isProposalPending): ?>
            <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-3 p-3 mb-4 shadow-sm border-0">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle p-2 bg-warning-subtle text-warning">
                        <i class="bi bi-hourglass-split fs-4"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">Proposal Awaiting Client Digital Confirmation</h6>
                        <small class="text-muted">The proposal and payment schedule are ready for client agreement. Client email: <strong><?php echo htmlspecialchars($project['client_email']); ?></strong></small>
                    </div>
                </div>
                <div>
                    <a href="send_proposal.php?id=<?php echo $project['id']; ?>&redirect=view&token=<?php echo generateCsrfToken(); ?>" class="btn btn-sm btn-warning fw-semibold shadow-sm text-dark">
                        <i class="bi bi-send-fill me-1"></i> Dispatch Proposal Now
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- 3-Metric Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-sm-4">
                <div class="stat-card stat-card-primary">
                    <i class="bi bi-cash-stack"></i>
                    <h3>₹<?php echo number_format($totalBudget, 2); ?></h3>
                    <div class="small opacity-75">Total Project Budget</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card stat-card-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <h3>₹<?php echo number_format($totalPaid, 2); ?></h3>
                    <div class="small opacity-75">Paid & Settled</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card <?php echo $totalDue > 0 ? 'stat-card-danger' : 'stat-card-secondary'; ?>">
                    <i class="bi bi-hourglass-bottom"></i>
                    <h3>₹<?php echo number_format($totalDue, 2); ?></h3>
                    <div class="small opacity-75">Remaining Balance Due</div>
                </div>
            </div>
        </div>

        <!-- Project Overview & Client Details Card -->
        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3 border-bottom pb-2">
                <i class="bi bi-info-circle text-primary me-2"></i>Project Scope & Details
            </h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="text-muted small">Client Name & Company</label> 
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars(!empty($project['client_profile_pic']) ? $project['client_profile_pic'] : 'default-avatar.png'); ?>" 
                             class="rounded-circle object-fit-cover border shadow-xs" 
                             style="width:36px;height:36px;min-width:36px;min-height:36px;" 
                             alt="<?php echo htmlspecialchars($project['client_name']); ?>"
                             onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                        <div>
                            <div class="fw-semibold text-dark">
                                <?php echo htmlspecialchars($project['client_name']); ?>
                            </div>
                            <?php if (!empty($project['company_name'])): ?>
                                <div class="text-muted small"><?php echo htmlspecialchars($project['company_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="text-muted small">Client Email & Phone</label>
                    <div class="fw-semibold">
                        <div><i class="bi bi-envelope text-primary me-1"></i><?php echo htmlspecialchars($project['client_email']); ?></div>
                        <?php if (!empty($project['client_phone'])): ?>
                            <div class="small text-muted"><i class="bi bi-telephone text-primary me-1"></i><?php echo htmlspecialchars($project['client_phone']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="text-muted small">Project Status & Deadline</label>
                    <div>
                        <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                            <?php if ($project['status'] === 'waiting_confirmation' || $project['status'] === 'proposal_sent'): ?>
                                <span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-hourglass-split me-1"></i> Proposal Sent</span>
                            <?php elseif ($project['status'] === 'advance_pending' || $project['status'] === 'advance_payment_pending'): ?>
                                <span class="badge bg-info-subtle text-info border px-2 py-1"><i class="bi bi-cash-coin me-1"></i> Advance Pending</span>
                            <?php elseif ($project['status'] === 'completed'): ?>
                                <span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Completed</span>
                            <?php elseif ($project['status'] === 'in_progress'): ?>
                                <span class="badge bg-primary-subtle text-primary border px-2 py-1"><i class="bi bi-gear-fill me-1"></i> In Progress</span>
                            <?php elseif ($project['status'] === 'review'): ?>
                                <span class="badge border px-2 py-1" style="background: #f3e8ff; color: #7e22ce;"><i class="bi bi-award-fill me-1"></i> Review / Approval</span>
                            <?php elseif ($project['status'] === 'on_hold'): ?>
                                <span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i> On Hold</span>
                            <?php elseif ($project['status'] === 'revision_requested'): ?>
                                <span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-arrow-repeat me-1"></i> Revision Requested</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $project['status']))); ?></span>
                            <?php endif; ?>

                            <!-- Quick Status Change Dropdown -->
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-xs btn-outline-secondary dropdown-toggle py-0 px-2" style="font-size: 11px;" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Quick Change Status">
                                    Change
                                </button>
                                <ul class="dropdown-menu shadow border-0" style="font-size: 13px;">
                                    <li><h6 class="dropdown-header">Update Status</h6></li>
                                    <li>
                                        <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>">
                                            <input type="hidden" name="action" value="update_project_status">
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="dropdown-item py-1"><i class="bi bi-gear-fill text-primary me-2"></i>In Progress</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>">
                                            <input type="hidden" name="action" value="update_project_status">
                                            <input type="hidden" name="status" value="review">
                                            <button type="submit" class="dropdown-item py-1" style="color: #7e22ce;"><i class="bi bi-award-fill me-2" style="color: #7e22ce;"></i>Review / Approval</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>"
                                              data-confirm-text="Mark project as Completed?"
                                              data-confirm-title="Complete Project"
                                              data-confirm-btn="Complete Project"
                                              data-confirm-color="success"
                                              data-confirm-icon="<i class='bi bi-check-circle-fill text-success'></i>">
                                            <input type="hidden" name="action" value="update_project_status">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="dropdown-item py-1 text-success fw-bold"><i class="bi bi-check-circle-fill text-success me-2"></i>Completed</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>">
                                            <input type="hidden" name="action" value="update_project_status">
                                            <input type="hidden" name="status" value="on_hold">
                                            <button type="submit" class="dropdown-item py-1 text-danger"><i class="bi bi-pause-circle-fill text-danger me-2"></i>On Hold</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="small text-muted mt-1"><i class="bi bi-calendar-event me-1"></i>Deadline: <?php echo date('d M Y', strtotime($project['deadline'])); ?></div>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <label class="text-muted small">Description / Deliverables Scope</label>
                    <div class="p-3 bg-light rounded border text-secondary" style="font-size: 13px; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($project['description'] ?: 'No scope details entered.')); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- UNIFIED MILESTONES & PAYMENT SCHEDULE SECTION                 -->
        <!-- ============================================================= -->
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-diagram-3-fill text-primary me-2"></i>Milestones, Delivery & Payments
                    </h5>
                    <small class="text-muted">Manage all milestones, deliverables, and payment settlements in one unified table.</small>
                </div>
                <button type="button" class="btn btn-sm btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#addMilestoneModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Milestone
                </button>
            </div>

            <?php if (empty($payments)): ?>
                <div class="text-center py-5 text-muted bg-light rounded border">
                    <i class="bi bi-receipt fs-1 d-block mb-2 text-muted opacity-50"></i>
                    <h6 class="fw-bold mb-1">No payment milestones added yet.</h6>
                    <small class="text-muted d-block mb-3">Add milestones to track project deliverables, paid amounts, and due balances.</small>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMilestoneModal">
                        <i class="bi bi-plus-circle me-1"></i> Add First Milestone
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Milestone Details</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Due Balance</th>
                                <th>Due Date</th>
                                <th>Payment</th>
                                <th>Work Delivery</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pay): 
                                $tot = (float) $pay['amount'];
                                $pd  = (float) ($pay['paid_amount'] ?? ($pay['status'] === 'paid' ? $tot : 0));
                                $bal = max(0, $tot - $pd);
                                $isOverdue = (!$isProposalPending && $bal > 0 && strtotime($pay['due_date']) < strtotime(date('Y-m-d')));
                                $workStatus = $pay['work_status'] ?? 'not_started';
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pay['invoice_no']); ?></span>
                                        <?php if (!empty($pay['is_advance'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border d-block mt-1 font-monospace" style="font-size: 9.5px;">Advance</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($pay['title']); ?></div>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <?php if ($pay['payment_method']): ?>
                                                <span class="small text-muted"><i class="bi bi-wallet2 me-1"></i>Via <?php echo htmlspecialchars($pay['payment_method']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($pay['deliverable_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($pay['deliverable_url']); ?>" target="_blank" class="small text-decoration-none text-primary fw-semibold" title="View Deliverables Link">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i>Preview Link
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><strong>₹<?php echo number_format($tot, 2); ?></strong></td>
                                    <td><span class="text-success fw-semibold">₹<?php echo number_format($pd, 2); ?></span></td>
                                    <td>
                                        <?php if ($bal > 0): ?>
                                            <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : 'text-dark fw-bold'; ?>">₹<?php echo number_format($bal, 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">₹0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isProposalPending): ?>
                                            <span class="text-muted small">Target: <?php echo date('d M Y', strtotime($pay['due_date'])); ?></span>
                                        <?php else: ?>
                                            <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>"><?php echo date('d M Y', strtotime($pay['due_date'])); ?></span>
                                            <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Payment Status Badge -->
                                    <td>
                                        <?php if ($isProposalPending): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-hourglass-split me-1"></i> Awaiting Client</span>
                                        <?php elseif ($bal <= 0 || $pay['status'] === 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle-fill me-1"></i> Paid</span>
                                        <?php elseif ($pd > 0): ?>
                                            <span class="badge bg-info-subtle text-info border">Partial</span>
                                        <?php elseif ($isOverdue): ?>
                                            <span class="badge bg-danger-subtle text-danger border">Overdue</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Work Delivery Status Badge -->
                                    <td>
                                        <?php if ($workStatus === 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border"><i class="bi bi-patch-check-fill me-1"></i> Approved</span>
                                        <?php elseif ($workStatus === 'delivered'): ?>
                                            <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-send-check me-1"></i> Delivered</span>
                                        <?php elseif ($workStatus === 'in_progress'): ?>
                                            <span class="badge bg-info-subtle text-info border"><i class="bi bi-gear me-1"></i> In Progress</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border"><i class="bi bi-circle me-1"></i> Not Started</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Unified Actions (Paid, Edit, Delete) -->
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <!-- 1. Mark / Record Payment -->
                                            <?php if ($bal > 0): ?>
                                                <a href="../payments/mark_paid.php?id=<?php echo $pay['id']; ?>&redirect=project" 
                                                   class="btn btn-sm btn-success text-white btn-record-payment" 
                                                   title="Record Payment / Mark as Paid"
                                                   data-pay-id="<?php echo $pay['id']; ?>"
                                                   data-invoice="<?php echo htmlspecialchars($pay['invoice_no']); ?>"
                                                   data-title="<?php echo htmlspecialchars($pay['title']); ?>"
                                                   data-client="<?php echo htmlspecialchars($project['client_name']); ?>"
                                                   data-project-created="<?php echo date('Y-m-d', strtotime($project['created_at'])); ?>"
                                                   data-total="<?php echo number_format($tot, 2, '.', ''); ?>"
                                                   data-paid="<?php echo number_format($pd, 2, '.', ''); ?>"
                                                   data-due="<?php echo number_format($bal, 2, '.', ''); ?>"
                                                   data-redirect="project">
                                                    <i class="bi bi-check2-circle me-1"></i> Paid
                                                </a>
                                            <?php endif; ?>

                                            <!-- 2. Edit Milestone (Opens Modal on this page) -->
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary btn-edit-milestone" 
                                                    title="Edit Milestone"
                                                    data-id="<?php echo $pay['id']; ?>"
                                                    data-invoice="<?php echo htmlspecialchars($pay['invoice_no']); ?>"
                                                    data-title="<?php echo htmlspecialchars($pay['title']); ?>"
                                                    data-amount="<?php echo htmlspecialchars($pay['amount']); ?>"
                                                    data-paid="<?php echo number_format($pd, 2, '.', ''); ?>"
                                                    data-due-date="<?php echo htmlspecialchars($pay['due_date']); ?>"
                                                    data-is-advance="<?php echo !empty($pay['is_advance']) ? '1' : '0'; ?>"
                                                    data-work-status="<?php echo htmlspecialchars($workStatus); ?>"
                                                    data-deliverable-url="<?php echo htmlspecialchars($pay['deliverable_url'] ?? ''); ?>"
                                                    data-deliverable-notes="<?php echo htmlspecialchars($pay['deliverable_notes'] ?? ''); ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- 3. Delete Milestone (If unpaid) -->
                                            <?php if ($pd == 0 && $pay['status'] !== 'paid'): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger btn-delete-milestone" 
                                                        title="Delete Milestone"
                                                        data-id="<?php echo $pay['id']; ?>"
                                                        data-invoice="<?php echo htmlspecialchars($pay['invoice_no']); ?>"
                                                        data-title="<?php echo htmlspecialchars($pay['title']); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Project Audit History Timeline -->
        <div class="card p-4">
            <h5 class="fw-bold mb-3 border-bottom pb-2">
                <i class="bi bi-clock-history text-primary me-2"></i>Audit History & Timeline
            </h5>
            <?php if (empty($logs)): ?>
                <div class="text-center py-4 text-muted small">No audit logs recorded yet.</div>
            <?php else: ?>
                <div class="timeline-wrapper">
                    <?php foreach ($logs as $log): ?>
                        <div class="d-flex align-items-start gap-3 py-2 border-bottom">
                            <div class="avatar-circle rounded-circle bg-light text-primary border" style="width: 32px; height: 32px; font-size: 13px;">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark small"><?php echo htmlspecialchars($log['title']); ?></strong>
                                    <span class="text-muted small" style="font-size: 11px;"><?php echo timeAgo($log['created_at']); ?></span>
                                </div>
                                <div class="text-muted small"><?php echo htmlspecialchars($log['description'] ?? ''); ?></div>
                                <div class="text-muted small mt-1" style="font-size: 10.5px;">By: <span class="fw-semibold"><?php echo htmlspecialchars($log['actor_name']); ?></span> (<?php echo htmlspecialchars($log['actor_type']); ?>)</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 1: ADD MILESTONE                                        -->
<!-- ============================================================= -->
<div class="modal fade" id="addMilestoneModal" tabindex="-1" aria-labelledby="addMilestoneLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addMilestoneLabel">
                    <i class="bi bi-plus-circle-fill text-success me-2"></i>Add Project Milestone
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>" id="addMilestoneForm" novalidate>
                <input type="hidden" name="action" value="add_milestone">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Milestone Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="addMilestoneTitle" class="form-control" placeholder="e.g. Milestone 2: UI Wireframes & Layout" required>
                        <p class="text-danger small mt-1 mb-0" id="addMilestoneTitleError"></p>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Installment Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="1" name="amount" id="addMilestoneAmount" class="form-control fw-bold" placeholder="10000.00" required>
                            </div>
                            <p class="text-danger small mt-1 mb-0" id="addMilestoneAmountError"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" id="addMilestoneDueDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" min="<?php echo date('Y-m-d', strtotime($project['created_at'])); ?>" required>
                            <p class="text-danger small mt-1 mb-0" id="addMilestoneDueDateError"></p>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Work Delivery Status</label>
                            <select name="work_status" class="form-select">
                                <option value="not_started" selected>Not Started</option>
                                <option value="in_progress">In Progress</option>
                                <option value="delivered">Delivered</option>
                                <option value="approved">Approved by Client</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="is_advance" value="1" id="addIsAdvance">
                                <label class="form-check-label fw-semibold" for="addIsAdvance">
                                    Is Advance / Kickoff
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deliverable Preview Link</label>
                        <input type="url" name="deliverable_url" class="form-control" placeholder="https://figma.com/file/... or Drive link">
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Deliverable Scope / Notes</label>
                        <textarea name="deliverable_notes" class="form-control" rows="2" placeholder="Brief scope of work covered in this milestone"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <i class="bi bi-check2-circle me-1"></i> Add Milestone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 2: EDIT MILESTONE                                       -->
<!-- ============================================================= -->
<div class="modal fade" id="editMilestoneModal" tabindex="-1" aria-labelledby="editMilestoneLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="editMilestoneLabel">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit Milestone Details <span id="editMilestoneInvoiceBadge" class="badge bg-light text-dark border font-monospace ms-1 small"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>" id="editMilestoneForm" novalidate>
                <input type="hidden" name="action" value="edit_milestone">
                <input type="hidden" name="milestone_id" id="editMilestoneId" value="">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Milestone Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editTitle" class="form-control" required>
                        <p class="text-danger small mt-1 mb-0" id="editMilestoneTitleError"></p>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Total Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="1" name="amount" id="editAmount" class="form-control fw-bold" required>
                            </div>
                            <p class="text-danger small mt-1 mb-0" id="editMilestoneAmountError"></p>
                            <div id="editAmountHint" class="small mt-1"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" id="editDueDate" class="form-control" min="<?php echo date('Y-m-d', strtotime($project['created_at'])); ?>" required>
                            <p class="text-danger small mt-1 mb-0" id="editMilestoneDueDateError"></p>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Work Delivery Status</label>
                            <select name="work_status" id="editWorkStatus" class="form-select">
                                <option value="not_started">Not Started</option>
                                <option value="in_progress">In Progress</option>
                                <option value="delivered">Delivered</option>
                                <option value="approved">Approved by Client</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="is_advance" value="1" id="editIsAdvance">
                                <label class="form-check-label fw-semibold" for="editIsAdvance">
                                    Is Advance Payment
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deliverable Preview Link</label>
                        <input type="url" name="deliverable_url" id="editDeliverableUrl" class="form-control" placeholder="https://figma.com/file/... or Drive link">
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Deliverable Notes</label>
                        <textarea name="deliverable_notes" id="editDeliverableNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 3: DELETE MILESTONE CONFIRMATION                        -->
<!-- ============================================================= -->
<div class="modal fade" id="deleteMilestoneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow text-center p-3">
            <div class="text-danger fs-1 mb-2"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <h6 class="fw-bold mb-1">Delete Milestone?</h6>
            <p class="text-muted small mb-3" id="deleteMilestoneText">Are you sure you want to remove this milestone invoice?</p>
            <form method="POST" action="view_project.php?id=<?php echo $project['id']; ?>">
                <input type="hidden" name="action" value="delete_milestone">
                <input type="hidden" name="milestone_id" id="deleteMilestoneId" value="">
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit Milestone Modal Population
    document.querySelectorAll('.btn-edit-milestone').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const invoice = this.dataset.invoice || '';
            const title = this.dataset.title;
            const amount = parseFloat(this.dataset.amount || 0);
            const paid = parseFloat(this.dataset.paid || 0);
            const dueDate = this.dataset.dueDate;
            const isAdvance = (this.dataset.isAdvance === '1');
            const workStatus = this.dataset.workStatus || 'not_started';
            const deliverableUrl = this.dataset.deliverableUrl || '';
            const deliverableNotes = this.dataset.deliverableNotes || '';

            document.getElementById('editMilestoneId').value = id;
            const invBadge = document.getElementById('editMilestoneInvoiceBadge');
            if (invBadge) invBadge.textContent = invoice;
            document.getElementById('editTitle').value = title;
            document.getElementById('editDueDate').value = dueDate;
            document.getElementById('editIsAdvance').checked = isAdvance;
            document.getElementById('editWorkStatus').value = workStatus;
            document.getElementById('editDeliverableUrl').value = deliverableUrl;
            document.getElementById('editDeliverableNotes').value = deliverableNotes;

            const amountInput = document.getElementById('editAmount');
            const amountHint = document.getElementById('editAmountHint');
            amountInput.value = amount.toFixed(2);

            if (paid > 0) {
                amountInput.min = paid.toFixed(2);
                if (paid >= amount) {
                    amountHint.innerHTML = '<span class="text-success"><i class="bi bi-shield-check me-1"></i>Settled / Paid: ₹' + paid.toFixed(2) + ' · Amount </span>';
                } else {
                    amountHint.innerHTML = '<span class="text-info"><i class="bi bi-info-circle me-1"></i>Partially Paid: ₹' + paid.toFixed(2) + ' · Min allowed: ₹' + paid.toFixed(2) + '</span>';
                }
            } else {
                amountInput.min = '1';
                amountHint.innerHTML = '<span class="text-muted">Unpaid milestone. You can modify amount freely.</span>';
            }

            const editModal = new bootstrap.Modal(document.getElementById('editMilestoneModal'));
            editModal.show();
        });
    });

    // Delete Milestone Modal Population
    document.querySelectorAll('.btn-delete-milestone').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('deleteMilestoneId').value = this.dataset.id;
            document.getElementById('deleteMilestoneText').textContent = 'Remove ' + (this.dataset.invoice || 'invoice') + ' - ' + (this.dataset.title || 'milestone') + '?';
            
            const delModal = new bootstrap.Modal(document.getElementById('deleteMilestoneModal'));
            delModal.show();
        });
    });

    // Validation for Add Milestone Form
    const addMsForm = document.getElementById('addMilestoneForm');
    const addTitle = document.getElementById('addMilestoneTitle');
    const addAmount = document.getElementById('addMilestoneAmount');
    const addDueDate = document.getElementById('addMilestoneDueDate');
    const addTitleErr = document.getElementById('addMilestoneTitleError');
    const addAmountErr = document.getElementById('addMilestoneAmountError');
    const addDateErr = document.getElementById('addMilestoneDueDateError');

    function validateAddTitle() {
        if (!addTitle) return true;
        const v = addTitle.value.trim();
        if (!v) {
            addTitleErr.textContent = 'Milestone title is required.';
            addTitle.classList.add('is-invalid');
            return false;
        }
        addTitleErr.textContent = '';
        addTitle.classList.remove('is-invalid');
        return true;
    }

    function validateAddAmount() {
        if (!addAmount) return true;
        const v = parseFloat(addAmount.value);
        if (isNaN(v) || v <= 0) {
            addAmountErr.textContent = 'Please enter a valid installment amount greater than 0.';
            addAmount.classList.add('is-invalid');
            return false;
        }
        addAmountErr.textContent = '';
        addAmount.classList.remove('is-invalid');
        return true;
    }

    function validateAddDueDate() {
        if (!addDueDate) return true;
        if (!addDueDate.value) {
            addDateErr.textContent = 'Due date is required.';
            addDueDate.classList.add('is-invalid');
            return false;
        }
        addDateErr.textContent = '';
        addDueDate.classList.remove('is-invalid');
        return true;
    }

    if (addTitle) { addTitle.addEventListener('input', validateAddTitle); addTitle.addEventListener('blur', validateAddTitle); }
    if (addAmount) { addAmount.addEventListener('input', validateAddAmount); addAmount.addEventListener('blur', validateAddAmount); }
    if (addDueDate) { addDueDate.addEventListener('change', validateAddDueDate); addDueDate.addEventListener('blur', validateAddDueDate); }

    if (addMsForm) {
        addMsForm.addEventListener('submit', function(e) {
            const v1 = validateAddTitle();
            const v2 = validateAddAmount();
            const v3 = validateAddDueDate();
            if (!v1 || !v2 || !v3) {
                e.preventDefault();
                if (!v1) addTitle.focus();
                else if (!v2) addAmount.focus();
                else if (!v3) addDueDate.focus();
            }
        });
    }

    // Validation for Edit Milestone Form
    const editMsForm = document.getElementById('editMilestoneForm');
    const editTitleInput = document.getElementById('editTitle');
    const editAmountInput = document.getElementById('editAmount');
    const editDueDateInput = document.getElementById('editDueDate');
    const editTitleErr = document.getElementById('editMilestoneTitleError');
    const editAmountErr = document.getElementById('editMilestoneAmountError');
    const editDateErr = document.getElementById('editMilestoneDueDateError');

    function validateEditTitle() {
        if (!editTitleInput) return true;
        const v = editTitleInput.value.trim();
        if (!v) {
            editTitleErr.textContent = 'Milestone title is required.';
            editTitleInput.classList.add('is-invalid');
            return false;
        }
        editTitleErr.textContent = '';
        editTitleInput.classList.remove('is-invalid');
        return true;
    }

    function validateEditAmount() {
        if (!editAmountInput) return true;
        const v = parseFloat(editAmountInput.value);
        const minAllowed = parseFloat(editAmountInput.min || 1);
        if (isNaN(v) || v <= 0) {
            editAmountErr.textContent = 'Please enter a valid milestone amount.';
            editAmountInput.classList.add('is-invalid');
            return false;
        } else if (v < minAllowed) {
            editAmountErr.textContent = 'Amount cannot be less than collected amount (₹' + minAllowed.toFixed(2) + ').';
            editAmountInput.classList.add('is-invalid');
            return false;
        }
        editAmountErr.textContent = '';
        editAmountInput.classList.remove('is-invalid');
        return true;
    }

    function validateEditDueDate() {
        if (!editDueDateInput) return true;
        if (!editDueDateInput.value) {
            editDateErr.textContent = 'Due date is required.';
            editDueDateInput.classList.add('is-invalid');
            return false;
        }
        editDateErr.textContent = '';
        editDueDateInput.classList.remove('is-invalid');
        return true;
    }

    if (editTitleInput) { editTitleInput.addEventListener('input', validateEditTitle); editTitleInput.addEventListener('blur', validateEditTitle); }
    if (editAmountInput) { editAmountInput.addEventListener('input', validateEditAmount); editAmountInput.addEventListener('blur', validateEditAmount); }
    if (editDueDateInput) { editDueDateInput.addEventListener('change', validateEditDueDate); editDueDateInput.addEventListener('blur', validateEditDueDate); }

    if (editMsForm) {
        editMsForm.addEventListener('submit', function(e) {
            const v1 = validateEditTitle();
            const v2 = validateEditAmount();
            const v3 = validateEditDueDate();
            if (!v1 || !v2 || !v3) {
                e.preventDefault();
                if (!v1) editTitleInput.focus();
                else if (!v2) editAmountInput.focus();
                else if (!v3) editDueDateInput.focus();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
