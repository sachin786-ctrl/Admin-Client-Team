<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Dashboard';
$flash = getFlash();

// Automated Payment Reminders Background Runner (runs at most once every 6h)
autoRunRemindersDaily($pdo);

// ---- Metric Counts ----
$totalClients     = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE status = 1 AND deleted_at IS NULL")->fetchColumn();
$activeProjects   = (int) $pdo->query("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.status IN ('in_progress', 'proposal_sent', 'advance_pending') AND p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$totalCollected   = (float) $pdo->query("SELECT COALESCE(SUM(pay.paid_amount), 0) FROM payments pay JOIN projects p ON p.id = pay.project_id JOIN clients c ON c.id = pay.client_id WHERE pay.deleted_at IS NULL AND p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$totalPendingDues = (float) $pdo->query("
    SELECT COALESCE(SUM(pay.amount - pay.paid_amount), 0) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount
      AND p.status NOT IN ('draft','proposal_sent','revision_requested','cancelled')
")->fetchColumn();
$pendingCount     = (int) $pdo->query("
    SELECT COUNT(*) 
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount
      AND p.status NOT IN ('draft','proposal_sent','revision_requested','cancelled')
")->fetchColumn();
$totalProjectsCount = (int) $pdo->query("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$totalPaymentsCount = (int) $pdo->query("SELECT COUNT(*) FROM payments pay JOIN projects p ON p.id = pay.project_id JOIN clients c ON c.id = pay.client_id WHERE pay.deleted_at IS NULL AND p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();

// ---- Pending Payments List with Search & Pagination (Max 5 per page) ----
$searchPay = trim($_GET['search_pay'] ?? '');
$pagePay   = max(1, (int)($_GET['page_pay'] ?? 1));
$perPagePay = 5;

$paySql = "
    SELECT pay.*, p.title AS project_title, p.project_code, p.status AS project_status, p.agreed_at AS project_agreed_at, p.created_at AS project_created_at,
           c.name AS client_name, c.company_name
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id AND p.deleted_at IS NULL
    JOIN clients c ON c.id = pay.client_id AND c.deleted_at IS NULL
    WHERE pay.deleted_at IS NULL AND pay.status != 'paid' AND pay.paid_amount < pay.amount
      AND p.status NOT IN ('draft','proposal_sent','revision_requested','cancelled')
";
$payParams = [];
if ($searchPay !== '') {
    $paySql .= " AND (pay.invoice_no LIKE ? OR pay.title LIKE ? OR p.title LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)";
    $like = "%$searchPay%";
    $payParams = array_fill(0, 5, $like);
}
$paySql .= " ORDER BY pay.due_date ASC";
$stmtPayAll = $pdo->prepare($paySql);
$stmtPayAll->execute($payParams);
$allPendingPayments = $stmtPayAll->fetchAll();

$totalPendingMatching = count($allPendingPayments);
$totalPagesPay = max(1, (int)ceil($totalPendingMatching / $perPagePay));
$pagePay = min($pagePay, $totalPagesPay);
$offsetPay = ($pagePay - 1) * $perPagePay;
$pendingPayments = array_slice($allPendingPayments, $offsetPay, $perPagePay);

// ---- Recent Projects with Search & Pagination (Max 5 per page) ----
$searchPrj = trim($_GET['search_prj'] ?? '');
$pagePrj   = max(1, (int)($_GET['page_prj'] ?? 1));
$perPagePrj = 5;

$prjSql = "
    SELECT p.*, c.name AS client_name, c.company_name, c.client_code,
           COALESCE(SUM(pay.paid_amount), 0) AS total_paid
    FROM projects p
    JOIN clients c ON c.id = p.client_id AND c.deleted_at IS NULL
    LEFT JOIN payments pay ON pay.project_id = p.id AND pay.deleted_at IS NULL
    WHERE p.deleted_at IS NULL
";
$prjParams = [];
if ($searchPrj !== '') {
    $prjSql .= " AND (p.title LIKE ? OR p.project_code LIKE ? OR c.name LIKE ? OR c.company_name LIKE ? OR p.status LIKE ?)";
    $like = "%$searchPrj%";
    $prjParams = array_fill(0, 5, $like);
}
$prjSql .= " GROUP BY p.id ORDER BY p.id DESC";
$stmtPrjAll = $pdo->prepare($prjSql);
$stmtPrjAll->execute($prjParams);
$allRecentProjects = $stmtPrjAll->fetchAll();

$totalProjectsMatching = count($allRecentProjects);
$totalPagesPrj = max(1, (int)ceil($totalProjectsMatching / $perPagePrj));
$pagePrj = min($pagePrj, $totalPagesPrj);
$offsetPrj = ($pagePrj - 1) * $perPagePrj;
$recentProjects = array_slice($allRecentProjects, $offsetPrj, $perPagePrj);
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">



        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold mb-0"><i class="bi bi-person-fill text-primary me-2"></i>Welcome back, <?php echo htmlspecialchars($admin['name']); ?></h4>
                <p class="text-muted mb-0">Overview of client projects, cash flow, and pending collections.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/client-flow/admin/clients/add_client.php" class="btn btn-outline-primary">
                    <i class="bi bi-person-plus me-1"></i> Add Client
                </a>
                <?php if ($totalClients > 0): ?>
                    <a href="/client-flow/admin/projects/add_project.php" class="btn btn-outline-info">
                        <i class="bi bi-folder-plus me-1"></i> Add Project
                    </a>
                <?php endif; ?>
                <?php if ($totalProjectsCount > 0): ?>
                    <a href="/client-flow/admin/payments/add_payment.php" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i> Add Payment
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards (Clickable) -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <a href="/client-flow/admin/clients/list_clients.php" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-primary" title="View Clients List">
                        <i class="bi bi-people-fill"></i>
                        <h3><?php echo $totalClients; ?></h3>
                        <div class="small opacity-75">Active Clients</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="/client-flow/admin/projects/list_projects.php" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-info" title="View Projects List">
                        <i class="bi bi-folder-check"></i>
                        <h3><?php echo $activeProjects; ?></h3>
                        <div class="small opacity-75">Active Projects</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="/client-flow/admin/payments/list_payments.php?status=paid" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-success" title="View Settled Payments">
                        <i class="bi bi-check-circle-fill"></i>
                        <h3>₹<?php echo number_format($totalCollected, 2); ?></h3>
                        <div class="small opacity-75">Total Collected</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="/client-flow/admin/payments/list_payments.php?status=pending" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-danger" title="View Pending Dues">
                        <i class="bi bi-bell-fill"></i>
                        <h3>₹<?php echo number_format($totalPendingDues, 2); ?></h3>
                        <div class="small opacity-75"><?php echo $pendingCount; ?> Pending Invoices</div>
                    </div>
                </a>
            </div>
        </div>

        <?php if ($totalClients === 0): ?>
            <!-- Onboarding Card when no clients exist yet -->
            <div class="card p-5 text-center shadow-sm border-0 my-4 bg-light">
                <div class="mb-3">
                    <i class="bi bi-person-plus-fill text-primary" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark">Welcome to ClientFlow!</h4>
                <p class="text-muted mx-auto mb-4" style="max-width: 520px;">
                    Start by creating your first client. Once a client is created, you can assign projects, generate invoices, track paid & due balances, and send automated WhatsApp/Email reminders right from this dashboard.
                </p>
                <div>
                    <a href="/client-flow/admin/clients/add_client.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                        <i class="bi bi-person-plus-fill me-2"></i> Add Your First Client
                    </a>
                </div>
            </div>
        <?php elseif ($totalProjectsCount === 0): ?>
            <!-- Onboarding Card when clients exist, but no projects created yet -->
            <div class="card p-5 text-center shadow-sm border-0 my-4 bg-light">
                <div class="mb-3">
                    <i class="bi bi-folder-plus text-primary" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark">Create Your First Project</h4>
                <p class="text-muted mx-auto mb-4" style="max-width: 520px;">
                    You have active clients. Now create a project to assign deliverables, set deadlines, and track milestone payments.
                </p>
                <div>
                    <a href="/client-flow/admin/projects/add_project.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                        <i class="bi bi-folder-plus me-2"></i> Add First Project
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- 1. PENDING PAYMENTS & REMINDERS SECTION (Visible only if pending dues exist or search is active) -->
            <?php if ($pendingCount > 0 || $searchPay !== ''): ?>
            <div class="card p-3 mb-4 border-start border-2 border-danger shadow-sm"> 
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Pending Payments & Reminders</h6>
                        <span class="text-muted small">Search, filter, remind, or delete pending milestone invoices (Showing max 5 per page)</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Search Box for Payments -->
                        <form method="GET" action="" class="d-flex gap-1 align-items-center">
                            <?php if ($searchPrj !== ''): ?>
                                <input type="hidden" name="search_prj" value="<?php echo htmlspecialchars($searchPrj); ?>">
                            <?php endif; ?>
                            <?php if ($pagePrj > 1): ?>
                                <input type="hidden" name="page_prj" value="<?php echo $pagePrj; ?>">
                            <?php endif; ?>
                            <div class="input-group input-group-sm" style="width: 220px;">
                                <input type="text" name="search_pay" class="form-control" placeholder="Search dues / invoice..." value="<?php echo htmlspecialchars($searchPay); ?>">
                                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                            </div>
                            <?php if ($searchPay !== ''): ?>
                                <a href="dashboard.php<?php echo $searchPrj !== '' ? '?search_prj=' . urlencode($searchPrj) : ''; ?>" class="btn btn-sm btn-outline-secondary" title="Clear Search">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                        <a href="/client-flow/admin/payments/list_payments.php?status=pending" class="small text-decoration-none">View All Dues &rarr;</a>
                    </div>
                </div>

                <?php if (empty($pendingPayments)): ?>
                    <div class="text-center py-4 text-muted bg-light rounded">
                        <i class="bi bi-check2-circle text-success fs-2 d-block mb-1"></i>
                        <h6 class="fw-bold mb-1"><?php echo $searchPay !== '' ? 'No matching pending payments found' : 'No pending payments requiring action'; ?></h6>
                        <small class="text-muted"><?php echo $searchPay !== '' ? 'Try a different search term or clear the filter.' : 'All client milestone invoices are settled up to date!'; ?></small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Milestone</th>
                                    <th>Client & Project</th>
                                    <th>Amount Due</th>
                                    <th>Due Date</th>
                                    <th>Last Reminder</th>
                                    <th class="text-end">Quick Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingPayments as $pay): 
                                    $tot = (float) $pay['amount'];
                                    $pd  = (float) ($pay['paid_amount'] ?? 0);
                                    $bal = max(0, $tot - $pd);
                                    $isOverdue = ($bal > 0 && strtotime($pay['due_date']) < strtotime(date('Y-m-d')));
                                ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pay['invoice_no']); ?></span></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($pay['title']); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($pay['client_name']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($pay['project_title']); ?></div>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-danger">₹<?php echo number_format($bal, 2); ?></span>
                                                <?php if ($pd > 0): ?>
                                                    <div class="small text-muted">Paid: ₹<?php echo number_format($pd, 2); ?> / Total: ₹<?php echo number_format($tot, 2); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo date('d M Y', strtotime($pay['due_date'])); ?>
                                                    <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($pay['last_reminder_sent_at']): ?>
                                                    <small class="text-muted"><i class="bi bi-check2 text-success me-1"></i><?php echo date('d M, h:i A', strtotime($pay['last_reminder_sent_at'])); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">Not yet</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                                    <a href="/client-flow/admin/payments/send_reminder.php?id=<?php echo $pay['id']; ?>&type=whatsapp" target="_blank" class="btn btn-sm btn-outline-success" title="Send WhatsApp Reminder">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </a>
                                                    <a href="/client-flow/admin/payments/send_reminder.php?id=<?php echo $pay['id']; ?>&type=email" class="btn btn-sm btn-outline-primary" title="Send Email Reminder">
                                                        <i class="bi bi-envelope-fill"></i>
                                                    </a>
                                                    <a href="/client-flow/admin/payments/mark_paid.php?id=<?php echo $pay['id']; ?>&redirect=dashboard" 
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
                                                       data-redirect="dashboard">
                                                        <i class="bi bi-check2-circle"></i>
                                                    </a>
                                                    <a href="/client-flow/admin/payments/delete_payment.php?id=<?php echo $pay['id']; ?>&redirect=dashboard" 
                                                       class="btn btn-sm btn-outline-danger btn-delete" 
                                                       title="Delete Payment" 
                                                       data-confirm-text="Are you sure you want to delete this payment milestone?"
                                                       data-confirm-title="Delete Payment"
                                                       data-confirm-btn="Delete"
                                                       data-confirm-color="danger">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- Pagination for Pending Payments (Max 5 per page) -->
                    <?php if ($totalPagesPay > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top flex-wrap gap-2">
                            <span class="small text-muted">Showing <?php echo $offsetPay + 1; ?> to <?php echo min($offsetPay + $perPagePay, $totalPendingMatching); ?> of <?php echo $totalPendingMatching; ?> entries</span>
                            <nav aria-label="Payments pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $pagePay <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page_pay=<?php echo $pagePay - 1; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?><?php echo $pagePrj > 1 ? '&page_prj=' . $pagePrj : ''; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?>">&laquo; Prev</a>
                                    </li>
                                    <?php for ($p = 1; $p <= $totalPagesPay; $p++): ?>
                                        <li class="page-item <?php echo $p === $pagePay ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page_pay=<?php echo $p; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?><?php echo $pagePrj > 1 ? '&page_prj=' . $pagePrj : ''; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $pagePay >= $totalPagesPay ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page_pay=<?php echo $pagePay + 1; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?><?php echo $pagePrj > 1 ? '&page_prj=' . $pagePrj : ''; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?>">Next &raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 2. ACTIVE & RECENT PROJECTS SECTION -->
            <div class="card p-3 shadow-sm mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold mb-0">Active & Recent Projects</h6>
                        <span class="text-muted small">Manage client projects, view scopes, or delete records (Showing max 5 per page)</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Search Box for Projects -->
                        <form method="GET" action="" class="d-flex gap-1 align-items-center">
                            <?php if ($searchPay !== ''): ?>
                                <input type="hidden" name="search_pay" value="<?php echo htmlspecialchars($searchPay); ?>">
                            <?php endif; ?>
                            <?php if ($pagePay > 1): ?>
                                <input type="hidden" name="page_pay" value="<?php echo $pagePay; ?>">
                            <?php endif; ?>
                            <div class="input-group input-group-sm" style="width: 220px;">
                                <input type="text" name="search_prj" class="form-control" placeholder="Search projects / client..." value="<?php echo htmlspecialchars($searchPrj); ?>">
                                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                            </div>
                            <?php if ($searchPrj !== ''): ?>
                                <a href="dashboard.php<?php echo $searchPay !== '' ? '?search_pay=' . urlencode($searchPay) : ''; ?>" class="btn btn-sm btn-outline-secondary" title="Clear Search">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                        <a href="/client-flow/admin/projects/list_projects.php" class="small text-decoration-none">View All Projects &rarr;</a>
                    </div>
                </div>

                <?php if (empty($recentProjects)): ?>
                    <div class="text-center py-4 text-muted bg-light rounded">
                        <i class="bi bi-folder2-open fs-2 d-block mb-1 text-muted"></i>
                        <h6 class="fw-bold mb-1"><?php echo $searchPrj !== '' ? 'No matching projects found' : 'No projects found.'; ?></h6>
                        <small class="text-muted d-block mb-2"><?php echo $searchPrj !== '' ? 'Try another search keyword or clear filter.' : 'Create a project for your active clients to begin tracking.'; ?></small>
                        <a href="/client-flow/admin/projects/add_project.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-folder-plus me-1"></i> Add Project
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Project Title</th>
                                    <th>Client</th>
                                    <th>Budget</th>
                                    <th>Paid</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentProjects as $rp): 
                                    $bal = max(0, $rp['total_amount'] - $rp['total_paid']);
                                ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($rp['project_code']); ?></span></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($rp['title']); ?></td>
                                        <td><?php echo htmlspecialchars($rp['client_name']); ?></td>
                                        <td><strong>₹<?php echo number_format($rp['total_amount'], 2); ?></strong></td>
                                        <td>
                                            <span class="text-success fw-semibold">₹<?php echo number_format($rp['total_paid'], 2); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($bal > 0): ?>
                                                <span class="text-danger fw-bold">₹<?php echo number_format($bal, 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">₹0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $sBadge = [
                                                'proposal_sent'   => 'warning text-dark',
                                                'advance_pending' => 'info text-dark',
                                                'in_progress'            => 'primary',
                                                'completed'              => 'success',
                                                'on_hold'                => 'secondary',
                                                'cancelled'              => 'danger'
                                            ][$rp['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $sBadge; ?>"><?php echo ucfirst(str_replace('_', ' ', $rp['status'])); ?></span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="/client-flow/admin/projects/view_project.php?id=<?php echo $rp['id']; ?>" class="btn btn-outline-info" title="View Project">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="/client-flow/admin/projects/edit_project.php?id=<?php echo $rp['id']; ?>" class="btn btn-outline-primary" title="Edit Project">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="/client-flow/admin/projects/delete_project.php?id=<?php echo $rp['id']; ?>&redirect=dashboard" 
                                                   class="btn btn-outline-danger btn-delete" 
                                                   title="Delete Project" 
                                                   data-confirm-text="Are you sure you want to delete this project and all its records?"
                                                   data-confirm-title="Delete Project"
                                                   data-confirm-btn="Delete Project"
                                                   data-confirm-color="danger">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination for Recent Projects (Max 5 per page) -->
                    <?php if ($totalPagesPrj > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top flex-wrap gap-2">
                            <span class="small text-muted">Showing <?php echo $offsetPrj + 1; ?> to <?php echo min($offsetPrj + $perPagePrj, $totalProjectsMatching); ?> of <?php echo $totalProjectsMatching; ?> entries</span>
                            <nav aria-label="Projects pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $pagePrj <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page_prj=<?php echo $pagePrj - 1; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?><?php echo $pagePay > 1 ? '&page_pay=' . $pagePay : ''; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?>">&laquo; Prev</a>
                                    </li>
                                    <?php for ($p = 1; $p <= $totalPagesPrj; $p++): ?>
                                        <li class="page-item <?php echo $p === $pagePrj ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page_prj=<?php echo $p; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?><?php echo $pagePay > 1 ? '&page_pay=' . $pagePay : ''; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $pagePrj >= $totalPagesPrj ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page_prj=<?php echo $pagePrj + 1; ?><?php echo $searchPrj !== '' ? '&search_prj=' . urlencode($searchPrj) : ''; ?><?php echo $pagePay > 1 ? '&page_pay=' . $pagePay : ''; ?><?php echo $searchPay !== '' ? '&search_pay=' . urlencode($searchPay) : ''; ?>">Next &raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</div>
