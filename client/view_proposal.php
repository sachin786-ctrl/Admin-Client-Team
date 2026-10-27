<?php
/**
 * Client Portal - Project Proposal & Agreement View
 * c:\xampp\htdocs\client-flow\client\view_proposal.php
 */

require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);
$pageTitle = 'Project Proposal & Agreement';
$flash = getClientFlash();

$id = (int) ($_GET['id'] ?? 0);

// Sync project status dynamically
if ($id > 0) {
    syncProjectStatus($pdo, $id);
}

$stmt = $pdo->prepare("
    SELECT p.*, 
           c.name AS client_name, c.company_name, c.client_code, c.email AS client_email, c.phone AS client_phone, c.address AS client_address,
           a.name AS admin_name, a.email AS admin_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN admin a ON a.id = p.created_by
    WHERE p.id = ? AND p.client_id = ? AND p.deleted_at IS NULL AND p.status != 'draft'
");
$stmt->execute([$id, $client['id']]);
$project = $stmt->fetch();

if (!$project) {
    setClientFlash('error', 'Project proposal not found or unauthorized.');
    header('Location: projects.php');
    exit;
}

// Fetch all payment milestones (excluding deleted)
$stmtPay = $pdo->prepare("
    SELECT * FROM payments 
    WHERE project_id = ? AND deleted_at IS NULL
    ORDER BY due_date ASC, id ASC
");
$stmtPay->execute([$id]);
$milestones = $stmtPay->fetchAll();

// Fetch audit logs ("kab kaise kya hua tha")
$logs = function_exists('getProjectLogs') ? getProjectLogs($pdo, $id) : [];

$totalBudget = (float) $project['total_amount'];
$totalPaid = 0;
foreach ($milestones as $m) {
    $totalPaid += (float) ($m['paid_amount'] ?? ($m['status'] === 'paid' ? $m['amount'] : 0));
}
$totalDue = max(0, $totalBudget - $totalPaid);
$isAgreed = !empty($project['agreed_at']);
$isProposal = in_array($project['status'], ['proposal_sent', 'waiting_confirmation'], true);
$isRevisionRequested = ($project['status'] === 'revision_requested');
$isReview = ($project['status'] === 'review');
$isOnHold = ($project['status'] === 'on_hold');
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<style>
@media print {
    .sidebar, .topbar, .btn-no-print, .action-bar-top {
        display: none !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .page-body {
        padding: 0 !important;
    }
    .proposal-card {
        border: none !important;
        box-shadow: none !important;
    }
}
.proposal-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: var(--card-shadow);
}
.proposal-header {
    border-bottom: 2px solid var(--primary);
    padding-bottom: 20px;
}
.seal-badge {
    border: 2px dashed #10b981;
    background: #ecfdf5;
    color: #065f46;
    border-radius: 10px;
    padding: 16px 22px;
}
.timeline-item {
    position: relative;
    padding-left: 28px;
    padding-bottom: 18px;
    border-left: 2px solid var(--border-color);
}
.timeline-item:last-child {
    border-left: 2px solid transparent;
    padding-bottom: 0;
}
.timeline-dot {
    position: absolute;
    left: -7px;
    top: 2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary);
}
</style>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Top Action Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 action-bar-top">
            <div>
                <h4 class="fw-bold mb-0">Project Proposal & Agreement</h4>
                <p class="text-muted mb-0">Code: <span class="font-monospace fw-semibold text-dark"><?php echo htmlspecialchars($project['project_code']); ?></span> · Official project scope, milestone installments, and legal terms.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-no-print">
                    <i class="bi bi-printer me-1"></i> Print / PDF
                </button>
                <a href="projects.php" class="btn btn-outline-secondary btn-no-print">
                    <i class="bi bi-arrow-left me-1"></i> Back to Projects
                </a>
            </div>
        </div>

        <!-- Notification Banner depending on project state -->
        <?php if ($isProposal): ?>
            <div class="alert alert-warning border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 btn-no-print" style="border-radius: 12px;">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-file-earmark-check-fill fs-3 text-warning"></i>
                    <div>
                        <strong class="text-dark">Action Required: Proposal Approval Pending</strong>
                        <div class="small text-muted">Please read the project scope, installment dates, and terms below, then digitally accept or request changes.</div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="#agreementSection" class="btn btn-warning text-dark btn-sm fw-bold shadow-sm">
                        <i class="bi bi-pen-fill me-1"></i> Review & Accept
                    </a>
                </div>
            </div>
        <?php elseif ($isRevisionRequested): ?>
            <div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center gap-3 btn-no-print" style="border-radius: 12px;">
                <i class="bi bi-pencil-square fs-3 text-info"></i>
                <div>
                    <strong class="text-dark">Revisions Requested: Proposal Under Modification</strong>
                    <div class="small text-muted">You submitted requested modifications on this proposal. The project manager has been notified and is updating the scope or milestones accordingly.</div>
                    <?php if (!empty($project['client_feedback'])): ?>
                        <div class="small mt-1 p-2 bg-white rounded border">
                            <strong>Your Feedback:</strong> <?php echo htmlspecialchars($project['client_feedback']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($isReview): ?>
            <div class="alert text-white border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 btn-no-print" style="background: #9333ea; border-radius: 12px;">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-award-fill fs-3"></i>
                    <div>
                        <strong>Deliverables Ready for Approval</strong>
                        <div class="small opacity-90">Project work and milestone deliverables have been completed. Please review the deliverable outputs and approve to complete the project!</div>
                    </div>
                </div>
                <a href="#agreementSection" class="btn btn-light text-purple fw-bold btn-sm shadow-sm" style="color: #7e22ce;">
                    <i class="bi bi-check2-circle me-1"></i> Approve Deliverables
                </a>
            </div>
        <?php elseif ($isOnHold): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 btn-no-print" style="border-radius: 12px;">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-pause-circle-fill fs-3 text-danger"></i>
                    <div>
                        <strong>Notice: Project Work Paused</strong>
                        <div class="small text-muted">Development is currently on hold due to an overdue milestone payment. Work will resume automatically once pending invoices are cleared.</div>
                    </div>
                </div>
                <a href="payments.php" class="btn btn-danger btn-sm fw-bold shadow-sm">
                    <i class="bi bi-credit-card-2-front me-1"></i> Settle Due Payments
                </a>
            </div>
        <?php endif; ?>

        <!-- Official Agreement Document Card -->
        <div class="card p-4 p-md-5 proposal-card mb-4 mx-auto" style="max-width: 920px;">
            
            <!-- Document Header -->
            <div class="proposal-header mb-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-2 font-monospace">
                            OFFICIAL SCOPE & CONTRACT AGREEMENT
                        </span>
                        <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($project['title']); ?></h3>
                        <div class="text-muted small">
                            Project Reference: <strong class="text-dark font-monospace"><?php echo htmlspecialchars($project['project_code']); ?></strong>
                        </div>
                    </div>
                    <div class="text-md-end">
                        <div class="small text-muted mb-1">Target Completion Deadline:</div>
                        <div class="fw-bold text-dark fs-6">
                            <i class="bi bi-calendar-check text-primary me-1"></i>
                            <?php echo $project['deadline'] ? date('d M Y', strtotime($project['deadline'])) : 'Milestone Based'; ?>
                        </div>
                        <div class="mt-2">
                            <?php echo getClientProjectStatusBadge($project['status']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contracting Parties -->
            <div class="row g-3 mb-4 pb-3 border-bottom">
                <div class="col-sm-6">
                    <div class="p-3 bg-light rounded border h-100">
                        <div class="text-muted small fw-semibold text-uppercase mb-1">Service Provider (Agency)</div>
                        <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($project['admin_name'] ?: 'Lead Agency Administrator'); ?></div>
                        <div class="small text-muted"><?php echo htmlspecialchars($project['admin_email']); ?></div>
                        <div class="small text-muted mt-1"><i class="bi bi-shield-check text-success me-1"></i>Verified Administrator</div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 bg-light rounded border h-100">
                        <div class="text-muted small fw-semibold text-uppercase mb-1">Client (Recipient)</div>
                        <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($project['client_name']); ?></div>
                        <?php if (!empty($project['company_name'])): ?>
                            <div class="small text-dark fw-semibold"><?php echo htmlspecialchars($project['company_name']); ?></div>
                        <?php endif; ?>
                        <div class="small text-muted"><?php echo htmlspecialchars($project['client_email']); ?> · <?php echo htmlspecialchars($project['client_phone'] ?: 'No Phone'); ?></div>
                        <?php if (!empty($project['client_address'])): ?>
                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($project['client_address']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section 1: Scope of Work & Deliverables -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="fw-bold text-dark mb-2">
                    <i class="bi bi-card-text text-primary me-2"></i>1. Scope of Work & Deliverables
                </h5>
                <div class="p-3 bg-light border rounded text-secondary" style="line-height: 1.7; font-size: 14px;">
                    <?php if (!empty($project['description'])): ?>
                        <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                    <?php else: ?>
                        <span class="text-muted">Software engineering, design, and implementation scope as agreed with the project manager.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 2: Milestone Payment Schedule -->
            <div class="mb-4 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-cash-stack text-primary me-2"></i>2. Payment Milestone Schedule
                    </h5>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        Total Project Budget: <strong class="text-success fs-6">₹<?php echo number_format($totalBudget, 2); ?></strong>
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Milestones partition the project budget into phased payments. Work commences upon clearance of the advance milestone.
                </p>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 32%;">Milestone Deliverable</th>
                                <th style="width: 15%;">Work Status</th>
                                <th style="width: 12%;">Split</th>
                                <th style="width: 18%;">Amount</th>
                                <th style="width: 18%;">Due Date & Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($milestones)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No individual milestone invoices recorded yet. Full payment due upon completion.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($milestones as $idx => $m): 
                                    $pct = $totalBudget > 0 ? round(((float)$m['amount'] / $totalBudget) * 100) : 0;
                                    $wStatus = $m['work_status'] ?? 'not_started';
                                ?>
                                    <tr>
                                        <td class="text-center fw-semibold text-muted"><?php echo $idx + 1; ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($m['title']); ?></div>
                                            <div class="font-monospace text-muted small" style="font-size: 11px;">Invoice: <?php echo htmlspecialchars($m['invoice_no']); ?></div>
                                            <?php if (!empty($m['deliverable_url'])): ?>
                                                <div class="mt-1">
                                                    <a href="<?php echo htmlspecialchars($m['deliverable_url']); ?>" target="_blank" class="small text-primary text-decoration-none">
                                                        <i class="bi bi-box-arrow-up-right me-1"></i> View Deliverable Asset
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($m['deliverable_notes'])): ?>
                                                <div class="small text-muted mt-1 fst-italic">Note: <?php echo htmlspecialchars($m['deliverable_notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace" style="font-size: 11px;">
                                                <?php echo ucfirst(str_replace('_', ' ', $wStatus)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-primary border font-monospace"><?php echo $pct; ?>%</span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            ₹<?php echo number_format($m['amount'], 2); ?>
                                            <?php if (!empty($m['paid_amount']) && (float)$m['paid_amount'] > 0 && (float)$m['paid_amount'] < (float)$m['amount']): ?>
                                                <div class="small text-success fw-normal">Paid: ₹<?php echo number_format($m['paid_amount'], 2); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold"><?php echo date('d M Y', strtotime($m['due_date'])); ?></div>
                                            <div class="mt-1">
                                                <?php echo getClientMilestoneStatusBadge($m, $project['status']); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Total Contract Value:</th>
                                <th class="text-success fs-6">₹<?php echo number_format($totalBudget, 2); ?></th>
                                <th>
                                    <span class="small text-muted">Paid: ₹<?php echo number_format($totalPaid, 2); ?></span>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Section 3: Terms & Operating Policies -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="fw-bold text-dark mb-2">
                    <i class="bi bi-file-earmark-lock text-primary me-2"></i>3. Project Terms & Operating Policies
                </h5>
                <ol class="text-secondary small ps-3 mb-0" style="line-height: 1.85;">
                    <li><strong>Project Commencement:</strong> Active development commences immediately following the confirmation of this agreement and settlement of the advance kickoff milestone.</li>
                    <li><strong>Milestone Deadlines & Overdue Pause Clause:</strong> All milestone dates are aligned prior to the scheduled delivery date (<strong><?php echo $project['deadline'] ? date('d M Y', strtotime($project['deadline'])) : 'Scheduled Deadline'; ?></strong>). If any milestone payment remains unpaid past its scheduled due date, <strong>project work is automatically placed on hold</strong> until payment is verified.</li>
                    <li><strong>Resumption of Work:</strong> Clearing overdue milestone invoices immediately restores project status to <strong>In Progress</strong> and active engineering continues without delay.</li>
                    <li><strong>Deliverable Approvals:</strong> Upon completing work, deliverables are placed in <strong>Review</strong> stage. The client reviews the work and clicks <strong>Approve Deliverables</strong> to complete the project.</li>
                    <li><strong>Final Ownership:</strong> Final code, cloud deployment, and all administrative credentials will be transferred upon 100% full settlement of all milestones.</li>
                </ol>
            </div>

            <!-- Section 4: Digital Signature / Acceptance / Review -->
            <div id="agreementSection" class="mb-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="bi bi-pen text-primary me-2"></i>4. Agreement & Digital Confirmation
                </h5>

                <?php if ($isProposal): ?>
                    <!-- Client Proposal Acceptance Form -->
                    <div class="p-4 border rounded" style="background: #fffdf5; border-color: #fde68a !important;">
                        <h6 class="fw-bold text-dark mb-1">Awaiting Your Digital Acceptance</h6>
                        <p class="text-muted small mb-3">
                            By typing your name and clicking Accept below, you digitally approve the project deliverables, milestone amounts, and payment due dates.
                        </p>

                        <form method="POST" action="confirm_project.php">
                            <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                            <input type="hidden" name="action" value="accept">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Signatory Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="signer_name" class="form-control" value="<?php echo htmlspecialchars($client['name']); ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Designation / Representing</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($client['company_name'] ?: 'Authorized Client Representative'); ?>" readonly>
                                </div>

                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="agreeTermsCheck" required>
                                        <label class="form-check-label text-dark small fw-semibold" for="agreeTermsCheck">
                                            I, <u><?php echo htmlspecialchars($client['name']); ?></u>, have read and agreed to the project scope, installment breakdown of ₹<?php echo number_format($totalBudget, 2); ?>, and acknowledge that work pauses automatically if milestone dues are overdue.
                                        </label>
                                    </div>
                                </div>

                                <div class="col-12 mt-3 d-flex flex-wrap gap-2 align-items-center">
                                    <button type="submit" class="btn btn-success px-4 py-2 fw-semibold shadow-sm">
                                        <i class="bi bi-check-circle-fill me-1"></i> Accept & Digitally Sign Agreement
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary px-3 py-2 fw-semibold" data-bs-toggle="collapse" data-bs-target="#revisionBox">
                                        <i class="bi bi-pencil-square me-1"></i> Request Changes / Revisions
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- Revision Request Collapse Box -->
                        <div class="collapse mt-3" id="revisionBox">
                            <div class="card card-body bg-white border">
                                <h6 class="fw-bold text-dark mb-2">Request Changes to Proposal or Milestones</h6>
                                <p class="text-muted small mb-3">Explain what changes, additions, or milestone date adjustments you would like the administrator to make.</p>
                                <form method="POST" action="confirm_project.php">
                                    <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                    <input type="hidden" name="action" value="request_revision">
                                    <div class="mb-3">
                                        <textarea name="revision_notes" class="form-control" rows="3" placeholder="Explain your requested changes in detail..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-warning text-dark fw-semibold btn-sm">
                                        <i class="bi bi-send me-1"></i> Submit Revision Request
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($isReview): ?>
                    <!-- Review Deliverables & Final Approval Box -->
                    <div class="p-4 border rounded" style="background: #faf5ff; border-color: #d8b4fe !important;">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-award-fill fs-2" style="color: #9333ea;"></i>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold text-dark mb-1">Final Deliverables Submitted for Approval</h6>
                                <p class="text-secondary small mb-3">
                                    The administrator has completed all milestone work and submitted deliverables for project <strong><?php echo htmlspecialchars($project['title']); ?></strong>. Please review the deliverables above. If everything is complete, approve the delivery below to mark the project Completed.
                                </p>
                                <form method="POST" action="confirm_project.php"
                                      data-confirm-text="Confirm that you have reviewed and approved all deliverables for this project?"
                                      data-confirm-title="Approve Deliverables"
                                      data-confirm-btn="Approve & Complete"
                                      data-confirm-color="success"
                                      data-confirm-icon="<i class='bi bi-award-fill' style='color:#9333ea;'></i>">
                                    <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                    <input type="hidden" name="action" value="approve_delivery">
                                    <input type="hidden" name="signer_name" value="<?php echo htmlspecialchars($client['name']); ?>">
                                    <button type="submit" class="btn text-white px-4 py-2 fw-semibold shadow-sm" style="background: #9333ea;">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approve Deliverables & Complete Project
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif (!empty($project['agreed_at'])): ?>
                    <!-- Verified Digital Contract Seal -->
                    <div class="seal-badge d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <i class="bi bi-patch-check-fill fs-1 text-success"></i>
                            <div>
                                <h6 class="fw-bold mb-0 text-success">Digitally Signed & Confirmed Agreement</h6>
                                <div class="small text-secondary">
                                    Accepted by: <strong><?php echo htmlspecialchars($project['agreed_by_name'] ?: $client['name']); ?></strong>
                                </div>
                                <div class="small text-muted">
                                    Timestamp: <?php echo date('d M Y, h:i A', strtotime($project['agreed_at'])); ?> · IP: <?php echo htmlspecialchars($project['agreed_ip'] ?: '127.0.0.1'); ?>
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-success px-3 py-2 fs-6">
                            <i class="bi bi-lock-fill me-1"></i> Active Contract
                        </span>
                    </div>

                <?php endif; ?>
            </div>

            <!-- Section 5: Project Audit Trail ("kab kaise kya hua tha") -->
            <div class="mt-4 pt-3 border-top btn-no-print">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi bi-clock-history text-primary me-2"></i>Project Activity & Audit Trail
                </h6>

                <?php if (empty($logs)): ?>
                    <div class="text-muted small">No audit activity recorded yet.</div>
                <?php else: ?>
                    <div class="ps-2">
                        <?php foreach ($logs as $log): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div class="fw-semibold text-dark small"><?php echo htmlspecialchars($log['title']); ?></div>
                                    <span class="text-muted" style="font-size: 11px;"><?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?></span>
                                </div>
                                <div class="text-muted small"><?php echo htmlspecialchars($log['description'] ?? ''); ?></div>
                                <div class="text-secondary" style="font-size: 11px;">
                                    By: <strong><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></strong> (<?php echo ucfirst($log['actor_type'] ?? 'system'); ?>)
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
