<?php

/**
 * Client Portal Dashboard
 * c:\xampp\htdocs\client-flow\client\dashboard.php
 */

require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);
$pageTitle = 'Dashboard';
$flash = getClientFlash();

// Automated Payment Reminders Background Runner
autoRunRemindersDaily($pdo);

// Automatically sync all client project statuses (checks milestone dues and overdue pauses)
$clientPrjIds = $pdo->prepare("SELECT id FROM projects WHERE client_id = ? AND deleted_at IS NULL");
$clientPrjIds->execute([$client['id']]);
foreach ($clientPrjIds->fetchAll(PDO::FETCH_COLUMN) as $pId) {
    syncProjectStatus($pdo, (int) $pId);
}

// 1. Fetch pending proposals waiting for client agreement
$stmtPendingProposals = $pdo->prepare("
    SELECT * FROM projects 
    WHERE client_id = ? AND status IN ('proposal_sent', 'waiting_confirmation') AND deleted_at IS NULL
    ORDER BY id DESC
");
$stmtPendingProposals->execute([$client['id']]);
$pendingProposals = $stmtPendingProposals->fetchAll();

// 2. Fetch projects under client review / approval
$stmtReviewProjects = $pdo->prepare("
    SELECT * FROM projects 
    WHERE client_id = ? AND status = 'review' AND deleted_at IS NULL
    ORDER BY id DESC
");
$stmtReviewProjects->execute([$client['id']]);
$reviewProjects = $stmtReviewProjects->fetchAll();

// 3. Fetch paused projects due to overdue payments
$stmtPausedProjects = $pdo->prepare("
    SELECT * FROM projects 
    WHERE client_id = ? AND status = 'on_hold' AND deleted_at IS NULL
    ORDER BY id DESC
");
$stmtPausedProjects->execute([$client['id']]);
$pausedProjects = $stmtPausedProjects->fetchAll();

// 4. Client Metrics (Excluding soft-deleted rows and drafts)
$stmtPrjCount = $pdo->prepare("
    SELECT COUNT(*) FROM projects 
    WHERE client_id = ? 
      AND status IN ('in_progress', 'proposal_sent', 'waiting_confirmation', 'advance_pending', 'advance_payment_pending', 'review')
      AND deleted_at IS NULL
");
$stmtPrjCount->execute([$client['id']]);
$activeProjectsCount = (int) $stmtPrjCount->fetchColumn();

$stmtPayMetrics = $pdo->prepare("
    SELECT 
        COALESCE(SUM(pay.paid_amount), 0) AS total_paid,
        COALESCE(SUM(CASE 
            WHEN pay.paid_amount < pay.amount 
                 AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')
            THEN (pay.amount - pay.paid_amount) 
            ELSE 0 
        END), 0) AS total_pending,
        COUNT(CASE 
            WHEN (pay.status != 'paid' AND pay.paid_amount < pay.amount 
                  AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')) 
            THEN 1 
        END) AS pending_invoices_count
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    WHERE pay.client_id = ? AND pay.deleted_at IS NULL AND p.deleted_at IS NULL
");
$stmtPayMetrics->execute([$client['id']]);
$payMetrics = $stmtPayMetrics->fetch();
$totalPaid = (float) $payMetrics['total_paid'];
$totalPending = (float) $payMetrics['total_pending'];
$pendingInvoicesCount = (int) $payMetrics['pending_invoices_count'];

// 5. Client's Recent Projects (Excluding drafts & deleted)
$stmtProjects = $pdo->prepare("
    SELECT p.*,
           COALESCE(SUM(pay.paid_amount), 0) AS total_paid
    FROM projects p
    LEFT JOIN payments pay ON pay.project_id = p.id AND pay.deleted_at IS NULL
    WHERE p.client_id = ? AND p.deleted_at IS NULL AND p.status != 'draft'
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT 5
");
$stmtProjects->execute([$client['id']]);
$recentProjects = $stmtProjects->fetchAll();

// 6. Client's Payable Invoices (Excluding drafts & unconfirmed proposals)
$stmtPending = $pdo->prepare("
    SELECT pay.*, p.title AS project_title, p.project_code, p.status AS project_status, p.agreed_at AS project_agreed_at
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    WHERE pay.client_id = ? 
      AND pay.deleted_at IS NULL 
      AND p.deleted_at IS NULL
      AND pay.status != 'paid' 
      AND pay.paid_amount < pay.amount
      AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')
    ORDER BY pay.due_date ASC
");
$stmtPending->execute([$client['id']]);
$pendingList = $stmtPending->fetchAll();
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Proposals Awaiting Agreement Banner -->
        <?php if (!empty($pendingProposals)): ?>
            <?php foreach ($pendingProposals as $pp): ?>
                <div class="card p-3 mb-3 border-0 shadow-sm" style="background: #fffdf5; border-left: 4px solid #f59e0b !important; border-radius: 12px;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-warning text-dark rounded-circle p-2 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; min-width: 44px;">
                                <i class="bi bi-file-earmark-check-fill fs-5"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge bg-warning text-dark font-monospace" style="font-size: 11px;">Action Required</span>
                                    <h6 class="fw-bold mb-0 text-dark">Project Proposal Awaiting Your Confirmation</h6>
                                </div>
                                <p class="mb-0 text-muted small">
                                    Project <strong><?php echo htmlspecialchars($pp['title']); ?></strong> · <code><?php echo htmlspecialchars($pp['project_code']); ?></code> with scheduled budget of <strong>₹<?php echo number_format($pp['total_amount'], 2); ?></strong> is ready for your review and digital signature.
                                </p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <form method="POST" action="confirm_project.php" class="d-inline"
                                data-confirm-text="Do you agree and accept the project scope, deliverables, and scheduled milestone payments totaling ₹<?php echo number_format($pp['total_amount'], 2); ?>?"
                                data-confirm-title="Accept Project Proposal"
                                data-confirm-btn="Accept Proposal"
                                data-confirm-color="success"
                                data-confirm-icon="<i class='bi bi-check-circle-fill text-success'></i>">
                                <input type="hidden" name="project_id" value="<?php echo $pp['id']; ?>">
                                <input type="hidden" name="signer_name" value="<?php echo htmlspecialchars($client['name']); ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="redirect_to" value="dashboard">
                                <button type="submit" class="btn btn-success btn-sm fw-semibold px-3 shadow-sm">
                                    <i class="bi bi-check-circle-fill me-1"></i> Accept Proposal
                                </button>
                            </form>
                            <a href="view_proposal.php?id=<?php echo $pp['id']; ?>" class="btn btn-outline-secondary btn-sm px-3">
                                <i class="bi bi-eye me-1"></i> Review Proposal
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Review / Approval Stage Banner -->
        <?php if (!empty($reviewProjects)): ?>
            <?php foreach ($reviewProjects as $rp): ?>
                <div class="card p-3 mb-3 border-0 shadow-sm" style="background: #faf5ff; border-left: 4px solid #a855f7 !important; border-radius: 12px;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-white rounded-circle p-2 d-flex align-items-center justify-content-center shadow-sm" style="background: #9333ea; width: 44px; height: 44px; min-width: 44px;">
                                <i class="bi bi-award-fill fs-5"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge text-white font-monospace" style="background: #9333ea; font-size: 11px;">Final Review</span>
                                    <h6 class="fw-bold mb-0 text-dark">Deliverables Ready for Approval</h6>
                                </div>
                                <p class="mb-0 text-muted small">
                                    Development deliverables for project <strong><?php echo htmlspecialchars($rp['title']); ?></strong> have been submitted. Please review the output and approve completion!
                                </p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="view_proposal.php?id=<?php echo $rp['id']; ?>#agreementSection" class="btn btn-sm text-white fw-semibold px-3 shadow-sm" style="background: #9333ea;">
                                <i class="bi bi-check2-circle me-1"></i> Review & Approve
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Overdue Paused Projects Banner -->
        <?php if (!empty($pausedProjects)): ?>
            <?php foreach ($pausedProjects as $pz): ?>
                <div class="card p-3 mb-3 border-0 shadow-sm" style="background: #fef2f2; border-left: 4px solid #ef4444 !important; border-radius: 12px;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-danger text-white rounded-circle p-2 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; min-width: 44px;">
                                <i class="bi bi-pause-circle-fill fs-5"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1 text-danger">Project Work Paused: Milestone Overdue</h6>
                                <p class="mb-0 text-muted small">
                                    Project <strong><?php echo htmlspecialchars($pz['title']); ?></strong> is currently on hold because an installment invoice is past its due date. Work resumes immediately upon settlement.
                                </p>
                            </div>
                        </div>
                        <div>
                            <a href="payments.php" class="btn btn-danger btn-sm fw-semibold px-3 shadow-sm">
                                <i class="bi bi-credit-card-2-front me-1"></i> Settle Overdue Invoice
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Welcome Banner -->

        <!-- Welcome Banner -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">

            <!-- Client Information -->
            <div class="d-flex align-items-center gap-3">

                <?php if (!empty($client['profile_pic']) && file_exists(__DIR__ . '/../../assets/uploads/profile/' . $client['profile_pic'])): ?>

                    <img
                        src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars($client['profile_pic']); ?>"
                        class="rounded-circle object-fit-cover shadow-sm border border-2 border-light"
                        style="width: 58px; height: 58px; min-width: 58px;"
                        alt="<?php echo htmlspecialchars($client['name']); ?>">

                <?php else: ?>


                <?php endif; ?>
                <div>
                    <div class="badge bg-primary-subtle text-primary d-inline-block mb-2 font-monospace">
                        Client Code: <?php echo htmlspecialchars($client['client_code']); ?>
                    </div>

                    <h4 class="fw-bold mb-1">
                        <i class="bi bi-person-fill text-primary me-2"></i>Welcome back, <?php echo htmlspecialchars($client['name']); ?>
                    </h4>

                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($client['company_name'] ?: 'Personal Account'); ?>
                        <span class="ms-1">·</span>
                        Service: <?php echo htmlspecialchars($client['service_type'] ?: 'Standard Service'); ?>
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex gap-2 flex-wrap">

                <a href="payments.php" class="btn btn-outline-primary">
                    <i class="bi bi-credit-card me-1"></i> Invoices
                </a>

                <a href="projects.php" class="btn btn-outline-info">
                    <i class="bi bi-folder me-1"></i> My Projects
                </a>

            </div>

        </div>


        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-4">
                <a href="projects.php" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-primary" title="View Active Projects">
                        <i class="bi bi-folder-fill"></i>
                        <h3><?php echo $activeProjectsCount; ?></h3>
                        <div class="small opacity-75">Active Projects Ongoing</div>
                    </div>
                </a>
            </div>

            <div class="col-6 col-lg-4">
                <a href="payments.php?status=paid" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-success" title="View Settled Payments">
                        <i class="bi bi-check-circle-fill"></i>
                        <h3>₹<?php echo number_format($totalPaid, 2); ?></h3>
                        <div class="small opacity-75">Total Settled</div>
                    </div>
                </a>
            </div>

            <div class="col-6 col-lg-4">
                <a href="payments.php?status=pending" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-danger" title="View Pending Dues">
                        <i class="bi bi-bell-fill"></i>
                        <h3>₹<?php echo number_format($totalPending, 2); ?></h3>
                        <div class="small opacity-75"><?php echo $pendingInvoicesCount; ?> Pending Invoices Due</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Pending Invoices Due Section -->
        <?php if (!empty($pendingList)): ?>
            <div class="card p-3 mb-4 border-start border-4 border-danger shadow-sm" style="border-radius: 12px;">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-danger mb-0 d-inline-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-octagon-fill"></i> Attention: Scheduled Invoices Due
                            <span class="badge bg-danger text-white ms-1"><?php echo count($pendingList); ?> Pending</span>
                        </h6>
                        <div class="text-secondary small mt-1">Settle your milestone installments on time to ensure continuous project progression.</div>
                    </div>
                    <a href="payments.php?status=pending" class="btn btn-sm btn-outline-danger fw-semibold">
                        View All Invoices <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Milestone</th>
                                <th>Project</th>
                                <th>Balance Due</th>
                                <th>Scheduled Due Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingList as $pl):
                                $bal = max(0, (float)$pl['amount'] - (float)($pl['paid_amount'] ?? 0));
                                $isOverdue = (!empty($pl['due_date']) && $pl['due_date'] < date('Y-m-d'));
                            ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pl['invoice_no']); ?></span></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($pl['title']); ?></div>
                                        <?php if (!empty($pl['work_status'])): ?>
                                            <span class="badge bg-light text-muted border font-monospace" style="font-size: 10px;">
                                                Work: <?php echo ucfirst(str_replace('_', ' ', $pl['work_status'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-primary"><?php echo htmlspecialchars($pl['project_title']); ?></div>
                                        <span class="text-muted small font-monospace"><?php echo htmlspecialchars($pl['project_code']); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-danger">₹<?php echo number_format($bal, 2); ?></div>
                                        <?php if (!empty($pl['paid_amount']) && (float)$pl['paid_amount'] > 0): ?>
                                            <div class="small text-muted">Paid: ₹<?php echo number_format($pl['paid_amount'], 2); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : 'text-dark'; ?>">
                                            <i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($pl['due_date'])); ?>
                                            <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="payments.php" class="btn btn-sm btn-danger text-white fw-semibold">
                                            <i class="bi bi-credit-card me-1"></i> Settle Now
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Projects Section -->
        <div class="card p-4 shadow-sm" style="border-radius: 14px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0">My Projects</h5>
                    <p class="text-muted small mb-0">Recent projects assigned to your account</p>
                </div>
                <a href="projects.php" class="btn btn-sm btn-outline-primary fw-semibold">
                    View All Projects <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <?php if (empty($recentProjects)): ?>
                <div class="text-center py-5 text-muted bg-light rounded-3">
                    <i class="bi bi-folder2-open fs-1 d-block mb-2 text-muted opacity-50"></i>
                    <h6 class="fw-bold mb-1">No Projects Assigned Yet</h6>
                    <small class="text-muted">Your administrator will assign your projects and scope here.</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Project Code</th>
                                <th>Project Title</th>
                                <th>Budget</th>
                                <th>Paid</th>
                                <th>Balance Due</th>
                                <th>Deadline</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentProjects as $p):
                                $bal = max(0, (float)$p['total_amount'] - (float)$p['total_paid']);
                            ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($p['project_code']); ?></span></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($p['title']); ?></div>
                                        <div class="text-muted small text-truncate" style="max-width: 260px;"><?php echo htmlspecialchars($p['description'] ?: 'No scope notes.'); ?></div>
                                    </td>
                                    <td><strong>₹<?php echo number_format($p['total_amount'], 2); ?></strong></td>
                                    <td>
                                        <span class="text-success fw-semibold">₹<?php echo number_format($p['total_paid'], 2); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($bal > 0): ?>
                                            <span class="text-danger fw-bold">₹<?php echo number_format($bal, 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">₹0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $p['deadline'] ? date('d M Y', strtotime($p['deadline'])) : '<span class="text-muted">Open</span>'; ?>
                                    </td>
                                    <td>
                                        <?php echo getClientProjectStatusBadge($p['status']); ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-eye me-1"></i> View Scope
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>