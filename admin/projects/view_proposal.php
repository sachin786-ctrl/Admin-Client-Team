<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Project Proposal & Agreement';
$flash = getFlash();

$id = (int) ($_GET['id'] ?? 0);

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
$milestones = $stmtPay->fetchAll();

$logs = getProjectLogs($pdo, $id);

$totalBudget = (float) $project['total_amount'];
$totalPaid = 0;
foreach ($milestones as $m) {
    $totalPaid += (float) ($m['paid_amount'] ?? ($m['status'] === 'paid' ? $m['amount'] : 0));
}
$totalDue = max(0, $totalBudget - $totalPaid);
$isAgreed = !empty($project['agreed_at']);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

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
    border-radius: 12px;
    box-shadow: var(--card-shadow);
}
.proposal-header {
    border-bottom: 2px solid var(--primary);
    padding-bottom: 18px;
}
.seal-badge {
    border: 2px dashed #10b981;
    background: #ecfdf5;
    color: #065f46;
    border-radius: 8px;
    padding: 14px 20px;
}
.timeline-item {
    position: relative;
    padding-left: 28px;
    padding-bottom: 16px;
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
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-permanent mb-4 btn-no-print">
                <i class="bi <?php echo $flash['type'] === 'error' ? 'bi-exclamation-triangle-fill text-danger' : 'bi-check-circle-fill text-success'; ?> fs-5"></i>
                <div><?php echo htmlspecialchars($flash['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Top Action Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 action-bar-top">
            <div>
                <h4 class="fw-bold mb-0">Project Proposal & Agreement Document</h4>
                <p class="text-muted mb-0">Code: <span class="font-monospace fw-semibold text-dark"><?php echo htmlspecialchars($project['project_code']); ?></span> · Client: <?php echo htmlspecialchars($project['client_name']); ?></p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a href="send_proposal.php?id=<?php echo $project['id']; ?>&redirect=proposal&token=<?php echo generateCsrfToken(); ?>" class="btn btn-primary btn-no-print shadow-sm">
                    <i class="bi bi-send-fill me-1"></i> <?php echo !empty($project['proposal_sent_at']) ? 'Resend Proposal via Email' : 'Send Proposal via Email'; ?>
                </a>
                <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-no-print">
                    <i class="bi bi-printer me-1"></i> Print / Save PDF
                </button>
                <a href="view_project.php?id=<?php echo $project['id']; ?>" class="btn btn-outline-secondary btn-no-print">
                    <i class="bi bi-arrow-left me-1"></i> Back to Project
                </a>
            </div>
        </div>

        <!-- Official Agreement Document Card -->
        <div class="card p-4 p-md-5 proposal-card mb-4 mx-auto" style="max-width: 900px;">
            
            <!-- Document Header -->
            <div class="proposal-header mb-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-2 font-monospace">
                            OFFICIAL PROPOSAL & CONTRACT
                        </span>
                        <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($project['title']); ?></h3>
                        <div class="text-muted small">
                            Project Code: <strong class="text-dark font-monospace"><?php echo htmlspecialchars($project['project_code']); ?></strong>
                        </div>
                    </div>
                    <div class="text-md-end">
                        <div class="small text-muted mb-1">Target Completion Deadline:</div>
                        <div class="fw-bold text-dark fs-6">
                            <i class="bi bi-calendar-check text-primary me-1"></i>
                            <?php echo $project['deadline'] ? date('d M Y', strtotime($project['deadline'])) : 'Milestone Based'; ?>
                        </div>
                        <div class="mt-2">
                            <?php
                            $badgeClass = [
                                'waiting_confirmation'   => 'warning text-dark',
                                'advance_payment_pending' => 'info text-dark',
                                'in_progress'            => 'primary',
                                'completed'              => 'success',
                                'on_hold'                => 'danger',
                                'cancelled'              => 'secondary'
                            ][$project['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $badgeClass; ?> px-3 py-2 text-capitalize">
                                Status: <?php echo str_replace('_', ' ', $project['status']); ?>
                            </span>
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

            <!-- Scope of Work & Deliverables -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="fw-bold text-dark mb-2">
                    <i class="bi bi-card-text text-primary me-2"></i>1. Scope of Work & Deliverables
                </h5>
                <div class="p-3 bg-light-subtle border rounded text-secondary" style="line-height: 1.6;">
                    <?php if (!empty($project['description'])): ?>
                        <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                    <?php else: ?>
                        <span class="text-muted">Standard software development and implementation scope as discussed and agreed between parties.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Milestone Payment Installment Schedule -->
            <div class="mb-4 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-cash-stack text-primary me-2"></i>2. Payment Milestone Schedule
                    </h5>
                    <span class="badge bg-light text-dark border">
                        Total Budget: <strong class="text-success">₹<?php echo number_format($totalBudget, 2); ?></strong>
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Project budget is partitioned into milestones. Work proceeds phase-by-phase upon timely payment settlement.
                </p>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 40%;">Milestone Deliverable</th>
                                <th style="width: 15%;">Split</th>
                                <th style="width: 20%;">Amount</th>
                                <th style="width: 20%;">Scheduled Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($milestones)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No individual milestone invoices recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($milestones as $idx => $m): 
                                    $pct = $totalBudget > 0 ? round(((float)$m['amount'] / $totalBudget) * 100) : 0;
                                    $mStatusBadge = ($m['status'] === 'paid') ? 'success' : (($m['due_date'] < date('Y-m-d')) ? 'danger' : 'warning text-dark');
                                ?>
                                    <tr>
                                        <td class="text-center fw-semibold text-muted"><?php echo $idx + 1; ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($m['title']); ?></div>
                                            <div class="font-monospace text-muted small" style="font-size: 11px;">Invoice: <?php echo htmlspecialchars($m['invoice_no']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-primary border font-monospace"><?php echo $pct; ?>%</span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            ₹<?php echo number_format($m['amount'], 2); ?>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold"><?php echo date('d M Y', strtotime($m['due_date'])); ?></div>
                                            <span class="badge bg-<?php echo $mStatusBadge; ?>" style="font-size: 10px;">
                                                <?php echo ucfirst($m['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Total Contract Value:</th>
                                <th class="text-success fs-6">₹<?php echo number_format($totalBudget, 2); ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Terms & Conditions -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="fw-bold text-dark mb-2">
                    <i class="bi bi-file-earmark-lock text-primary me-2"></i>3. Project Terms & Operating Policies
                </h5>
                <ol class="text-secondary small ps-3 mb-0" style="line-height: 1.8;">
                    <li><strong>Project Commencement:</strong> Work commences immediately upon clearance of Milestone 1 (Advance Kickoff Payment).</li>
                    <li><strong>Milestone Deadlines & Overdue Pause Clause:</strong> All milestone dates are strictly aligned before the final project delivery date (<strong><?php echo $project['deadline'] ? date('d M Y', strtotime($project['deadline'])) : 'Scheduled Deadline'; ?></strong>). If any scheduled installment is not cleared on or before its due date, <strong>project work is automatically placed on hold</strong> until payment is verified.</li>
                    <li><strong>Resumption of Work:</strong> Settling overdue milestone invoices instantly restores project status to <strong>In Progress</strong> and resumes active development.</li>
                    <li><strong>Final Delivery & Ownership:</strong> Final source code, domain deployment, and administrator credentials will be handed over following 100% full settlement of all milestones.</li>
                </ol>
            </div>

            <!-- Section 4: Digital Signature Status -->
            <div class="mb-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="bi bi-pen text-primary me-2"></i>4. Agreement & Digital Confirmation Status
                </h5>

                <?php if ($project['status'] === 'waiting_confirmation'): ?>
                    <div class="p-3 bg-light border rounded d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-warning fs-3"></i>
                            <div>
                                <div class="fw-semibold text-dark">Awaiting Client Confirmation & Digital Signature</div>
                                <div class="small text-muted">Proposal has been dispatched to client. Once client reviews and signs in portal, project will activate.</div>
                            </div>
                        </div>
                        <span class="badge bg-warning text-dark px-3 py-2">
                            Pending Client Action
                        </span>
                    </div>
                <?php else: ?>
                    <div class="seal-badge d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <i class="bi bi-patch-check-fill fs-1 text-success"></i>
                            <div>
                                <h6 class="fw-bold mb-0 text-success">Digitally Signed & Accepted by Client</h6>
                                <div class="small text-secondary">
                                    Signatory: <strong><?php echo htmlspecialchars($project['agreed_by_name'] ?: $project['client_name']); ?></strong>
                                </div>
                                <div class="small text-muted">
                                    Timestamp: <?php echo $project['agreed_at'] ? date('d M Y, h:i A', strtotime($project['agreed_at'])) : date('d M Y'); ?> · IP: <?php echo htmlspecialchars($project['agreed_ip'] ?: '127.0.0.1'); ?>
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-success px-3 py-2 fs-6">
                            <i class="bi bi-check-circle-fill me-1"></i> Legally Binding
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
                                <div class="text-muted small"><?php echo htmlspecialchars($log['description']); ?></div>
                                <div class="text-secondary" style="font-size: 11px;">
                                    By: <strong><?php echo htmlspecialchars($log['actor_name']); ?></strong> (<?php echo ucfirst($log['actor_type']); ?>)
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
