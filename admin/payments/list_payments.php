<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('payments_view');
$pageTitle = 'Payments';
$flash = getFlash();

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = "
    SELECT pay.*, p.title AS project_title, p.project_code, p.status AS project_status, p.agreed_at AS project_agreed_at, p.created_at AS project_created_at,
           c.name AS client_name, c.company_name, c.phone AS client_phone, c.email AS client_email
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL
";
$params = [];

if ($search !== '') {
    $sql .= " AND (pay.invoice_no LIKE ? OR pay.title LIKE ? OR p.title LIKE ? OR c.name LIKE ? OR c.company_name LIKE ? OR c.client_code LIKE ? OR p.project_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'paid') {
    $sql .= " AND (pay.status = 'paid' OR pay.paid_amount >= pay.amount)";
} elseif ($status === 'partial') {
    $sql .= " AND (pay.status = 'partial' OR (pay.paid_amount > 0 AND pay.paid_amount < pay.amount))";
} elseif ($status === 'pending') {
    $sql .= " AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date >= CURRENT_DATE() AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)";
} elseif ($status === 'overdue' || $status === 'due') {
    $sql .= " AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date < CURRENT_DATE() AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)";
}

$sql .= " ORDER BY (pay.status != 'paid' AND pay.paid_amount < pay.amount) DESC, pay.due_date ASC, pay.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allPayments = $stmt->fetchAll();

$totalMatching = count($allPayments);
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$payments = array_slice($allPayments, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);

// Calculate total metrics: Paid, Pending, and Due (Overdue) - excluding projects pending client proposal confirmation
$systemTotalClients  = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
$systemTotalProjects = (int) $pdo->query("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$systemTotalPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments pay JOIN projects p ON p.id = pay.project_id JOIN clients c ON c.id = pay.client_id WHERE pay.deleted_at IS NULL AND p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();

$totalPaid = (float) $pdo->query("SELECT COALESCE(SUM(pay.paid_amount), 0) FROM payments pay JOIN projects p ON p.id = pay.project_id JOIN clients c ON c.id = pay.client_id WHERE pay.deleted_at IS NULL AND p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$countPaid = (int) $pdo->query("SELECT COUNT(*) FROM payments pay JOIN projects p ON p.id = pay.project_id JOIN clients c ON c.id = pay.client_id WHERE pay.deleted_at IS NULL AND p.deleted_at IS NULL AND c.deleted_at IS NULL AND (pay.status = 'paid' OR pay.paid_amount >= pay.amount)")->fetchColumn();

$totalPending = (float) $pdo->query("
    SELECT COALESCE(SUM(pay.amount - pay.paid_amount), 0) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date >= CURRENT_DATE()
    AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)
")->fetchColumn();

$countPending = (int) $pdo->query("
    SELECT COUNT(*) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date >= CURRENT_DATE()
    AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)
")->fetchColumn();

$totalDue = (float) $pdo->query("
    SELECT COALESCE(SUM(pay.amount - pay.paid_amount), 0) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date < CURRENT_DATE()
    AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)
")->fetchColumn();

$countDue = (int) $pdo->query("
    SELECT COUNT(*) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date < CURRENT_DATE()
    AND (p.status != 'waiting_confirmation' OR p.agreed_at IS NOT NULL)
")->fetchColumn();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">


        <?php if ($systemTotalClients === 0): ?>
            <!-- Onboarding State: No clients created yet -->
            <div class="card p-5 text-center my-4 bg-light border-0 shadow-sm">
                <div class="mb-3">
                    <i class="bi bi-people text-muted" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark">No Clients Added Yet</h4>
                <p class="text-muted mx-auto mb-3" style="max-width: 500px;">
                    Payments and invoices belong to clients and projects. Please create your first client before adding payments and reminders.
                </p>
                <div>
                    <a href="../clients/add_client.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                        <i class="bi bi-person-plus me-1"></i> Add First Client
                    </a>
                </div>
            </div>
        <?php elseif ($systemTotalProjects === 0): ?>
            <!-- Onboarding State: No projects created yet -->
            <div class="card p-5 text-center my-4 bg-light border-0 shadow-sm">
                <div class="mb-3">
                    <i class="bi bi-folder2-open text-muted" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark">No Projects Added Yet</h4>
                <p class="text-muted mx-auto mb-3" style="max-width: 500px;">
                    Payments and milestones must belong to a project. Please create your first project before adding payments.
                </p>
                <div>
                    <a href="../projects/add_project.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                        <i class="bi bi-folder-plus me-1"></i> Add First Project
                    </a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($systemTotalPayments > 0): ?>
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold mb-0">Payments & Invoices</h4>
                        <p class="text-muted mb-0">Track Paid, Pending, and Overdue balances with Email & WhatsApp reminders.</p>
                    </div>
                    <div>
                        <a href="add_payment.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle-fill me-1"></i> Add Milestone / Payment
                        </a>
                    </div>
                </div>

                <!-- Metric Cards: Paid, Pending, Due (Clickable filters) -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <a href="list_payments.php?status=paid" class="text-decoration-none">
                            <div class="stat-card clickable stat-card-success" title="Filter Paid Invoices">
                                <i class="bi bi-check-circle-fill"></i>
                                <h3>₹<?php echo number_format($totalPaid, 2); ?></h3>
                                <div class="small opacity-75">Paid : Collected (<?php echo $countPaid; ?> settled)</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4">
                        <a href="list_payments.php?status=pending" class="text-decoration-none">
                            <div class="stat-card clickable stat-card-warning" title="Filter Pending Upcoming Invoices">
                                <i class="bi bi-clock-history"></i>
                                <h3>₹<?php echo number_format($totalPending, 2); ?></h3>
                                <div class="small opacity-75">Pending : Upcoming (<?php echo $countPending; ?> invoices)</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4">
                        <a href="list_payments.php?status=overdue" class="text-decoration-none">
                            <div class="stat-card clickable stat-card-danger" title="Filter Overdue Invoices">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <h3>₹<?php echo number_format($totalDue, 2); ?></h3>
                                <div class="small opacity-75">Due : Overdue (<?php echo $countDue; ?> invoices)</div>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card p-3 mb-4">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label small text-muted mb-1">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Search by invoice no, milestone, project, client..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label small text-muted mb-1">Status Filter</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="overdue" <?php echo ($status === 'overdue' || $status === 'due') ? 'selected' : ''; ?>>Due / Overdue</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-search me-1"></i> Filter
                            </button>
                            <a href="list_payments.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Payments Table or Clean Empty Card -->
            <?php if ($systemTotalPayments === 0): ?>
                <div class="card p-5 text-center my-3 shadow-sm border-0">
                    <div class="mb-3">
                        <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">No Payments Added Yet</h5>
                    <p class="text-muted mb-3">You have not created any payment milestones yet. Click below to add your first payment.</p>
                    <div>
                        <a href="add_payment.php" class="btn btn-success">
                            <i class="bi bi-plus-circle me-1"></i> Add First Payment
                        </a>
                    </div>
                </div>
            <?php elseif (empty($payments)): ?>
                <div class="card p-5 text-center my-3 shadow-sm border-0">
                    <div class="mb-3">
                        <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">No Matching Payments Found</h5>
                    <p class="text-muted mb-3">No payment records match your search or filter.</p>
                    <div>
                        <a href="list_payments.php" class="btn btn-outline-secondary">Clear Filter</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card p-3 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Milestone</th>
                                    <th>Project & Client</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Pending</th>
                                    <th>Due</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Last Reminder</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $pay): 
                                    $tot = (float) $pay['amount'];
                                    $pd  = (float) ($pay['paid_amount'] ?? ($pay['status'] === 'paid' ? $tot : 0));
                                    $bal = max(0, $tot - $pd);
                                    $isProposalPending = ($pay['project_status'] === 'waiting_confirmation' && empty($pay['project_agreed_at']));
                                    $isOverdue = (!$isProposalPending && $bal > 0 && strtotime($pay['due_date']) < strtotime(date('Y-m-d')));
                                    $pendingVal = (!$isProposalPending && $bal > 0 && !$isOverdue) ? $bal : 0.00;
                                    $dueVal     = (!$isProposalPending && $bal > 0 && $isOverdue) ? $bal : 0.00;
                                ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pay['invoice_no']); ?></span>
                                        </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($pay['title']); ?></div>
                                                <?php if ($pay['payment_method']): ?>
                                                    <div class="small text-muted"><i class="bi bi-wallet2 me-1"></i><?php echo htmlspecialchars($pay['payment_method']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-primary"><?php echo htmlspecialchars($pay['project_title']); ?></div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($pay['client_name']); ?>
                                                    <?php if (!empty($pay['company_name'])): ?>
                                                        · <?php echo htmlspecialchars($pay['company_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <strong>₹<?php echo number_format($tot, 2); ?></strong>
                                            </td>
                                            <td>
                                                <span class="text-success fw-semibold">₹<?php echo number_format($pd, 2); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($pendingVal > 0): ?>
                                                    <span class="fw-bold text-warning text-dark">
                                                        ₹<?php echo number_format($pendingVal, 2); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">₹0.00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($dueVal > 0): ?>
                                                    <span class="fw-bold text-danger">
                                                        ₹<?php echo number_format($dueVal, 2); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">₹0.00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isProposalPending): ?>
                                                    <span class="text-muted small" title="Milestone schedule becomes active after client digital agreement">
                                                        Target: <?php echo date('d M Y', strtotime($pay['due_date'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <div class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                                                        <?php echo date('d M Y', strtotime($pay['due_date'])); ?>
                                                        <?php if ($isOverdue): ?>
                                                            <span class="badge bg-danger ms-1">Overdue</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isProposalPending): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" title="Proposal dispatched; awaiting client digital signature">
                                                        <i class="bi bi-hourglass-split me-1"></i> Awaiting Client
                                                    </span>
                                                <?php elseif ($bal <= 0 || $pay['status'] === 'paid'): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Paid</span>
                                                    <?php if ($pay['paid_date']): ?>
                                                        <div class="small text-muted mt-1"><?php echo date('d M Y', strtotime($pay['paid_date'])); ?></div>
                                                    <?php endif; ?>
                                                <?php elseif ($pd > 0): ?>
                                                    <span class="badge bg-info text-dark"><i class="bi bi-pie-chart me-1"></i> Partial</span>
                                                <?php elseif ($isOverdue): ?>
                                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i> Due</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($pay['last_reminder_sent_at']): ?>
                                                    <div class="small text-muted"><i class="bi bi-send-check text-success me-1"></i><?php echo date('d M, h:i A', strtotime($pay['last_reminder_sent_at'])); ?></div>
                                                <?php else: ?>
                                                    <span class="small text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                                    <?php if ($isProposalPending): ?>
                                                        <span class="badge bg-light text-muted border py-1 px-2" title="Client must accept proposal before payment can be recorded">
                                                            <i class="bi bi-lock me-1"></i> Proposal Awaiting Client
                                                        </span>
                                                        <a href="../projects/view_project.php?id=<?php echo $pay['project_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Proposal Status">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php if ($bal > 0): ?>
                                                            <?php if (hasPermission($admin, 'payments_remind')): ?>
                                                                <a href="send_reminder.php?id=<?php echo $pay['id']; ?>&type=whatsapp" target="_blank" class="btn btn-sm btn-outline-success" title="WhatsApp Reminder">
                                                                    <i class="bi bi-whatsapp"></i>
                                                                </a>
                                                                <a href="send_reminder.php?id=<?php echo $pay['id']; ?>&type=email" class="btn btn-sm btn-outline-primary" title="Email Reminder">
                                                                    <i class="bi bi-envelope-fill"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if (hasPermission($admin, 'payments_record')): ?>
                                                                <a href="mark_paid.php?id=<?php echo $pay['id']; ?>" 
                                                                   class="btn btn-sm btn-success text-white btn-record-payment" 
                                                                   title="Record Payment / Mark as Paid"
                                                                   data-pay-id="<?php echo $pay['id']; ?>"
                                                                   data-invoice="<?php echo htmlspecialchars($pay['invoice_no']); ?>"
                                                                   data-title="<?php echo htmlspecialchars($pay['title']); ?>"
                                                                   data-client="<?php echo htmlspecialchars($pay['client_name']); ?>"
                                                                   data-project-created="<?php echo date('Y-m-d', strtotime($pay['project_created_at'] ?? 'now')); ?>"
                                                                   data-total="<?php echo number_format($tot, 2, '.', ''); ?>"
                                                                   data-paid="<?php echo number_format($pd, 2, '.', ''); ?>"
                                                                   data-due="<?php echo number_format($bal, 2, '.', ''); ?>"
                                                                   data-redirect="payments">
                                                                    <i class="bi bi-check2-circle"></i> Paid
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <a href="../projects/view_project.php?id=<?php echo $pay['project_id']; ?>" class="btn btn-sm btn-outline-info" title="Manage Milestone in Project">
                                                        <i class="bi bi-folder2-open"></i>
                                                    </a>
                                                    <?php if (hasPermission($admin, 'payments_record')): ?>
                                                        <a href="edit_payment.php?id=<?php echo $pay['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (hasPermission($admin, 'payments_delete')): ?>
                                                        <?php if ($pd <= 0 && $pay['status'] !== 'paid'): ?>
                                                        <form method="POST" action="delete_payment.php" class="d-inline m-0"
                                                              data-confirm-text="Are you sure you want to delete this invoice?"
                                                              data-confirm-title="Delete Invoice"
                                                              data-confirm-btn="Delete"
                                                              data-confirm-color="danger">
                                                            <input type="hidden" name="id" value="<?php echo (int) $pay['id']; ?>">
                                                            <input type="hidden" name="redirect" value="list">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                        <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete invoice with recorded payments"><i class="bi bi-trash"></i></button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- Pagination (5 items per page) -->
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">
                            <div class="small text-muted">
                                Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> payments
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo getPaginationQuery($page - 1); ?>">
                                            <i class="bi bi-chevron-left me-1"></i> Previous
                                        </a>
                                    </li>
                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo getPaginationQuery($i); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo getPaginationQuery($page + 1); ?>">
                                            Next <i class="bi bi-chevron-right ms-1"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>