<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('projects_view');
$pageTitle = 'Projects';
$flash = getFlash();

$search   = trim($_GET['search'] ?? '');
$status   = trim($_GET['status'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);

$sql = "
    SELECT p.*, c.name AS client_name, c.company_name, c.client_code, c.profile_pic AS client_profile_pic,
           COALESCE(SUM(pay.paid_amount), 0) AS total_paid,
           (SELECT id FROM payments WHERE project_id = p.id AND deleted_at IS NULL AND (status != 'paid' OR status IS NULL) ORDER BY id ASC LIMIT 1) AS next_pay_id,
           (SELECT invoice_no FROM payments WHERE project_id = p.id AND deleted_at IS NULL AND (status != 'paid' OR status IS NULL) ORDER BY id ASC LIMIT 1) AS next_pay_invoice,
           (SELECT title FROM payments WHERE project_id = p.id AND deleted_at IS NULL AND (status != 'paid' OR status IS NULL) ORDER BY id ASC LIMIT 1) AS next_pay_title,
           (SELECT amount FROM payments WHERE project_id = p.id AND deleted_at IS NULL AND (status != 'paid' OR status IS NULL) ORDER BY id ASC LIMIT 1) AS next_pay_amount,
           (SELECT paid_amount FROM payments WHERE project_id = p.id AND deleted_at IS NULL AND (status != 'paid' OR status IS NULL) ORDER BY id ASC LIMIT 1) AS next_pay_paid
    FROM projects p
    JOIN clients c ON c.id = p.client_id AND c.deleted_at IS NULL
    LEFT JOIN payments pay ON pay.project_id = p.id AND pay.deleted_at IS NULL
    WHERE p.deleted_at IS NULL
";
$params = [];

if ($clientId > 0) {
    $sql .= " AND p.client_id = ?";
    $params[] = $clientId;
}

if ($search !== '') {
    $sql .= " AND (p.title LIKE ? OR p.project_code LIKE ? OR c.name LIKE ? OR c.company_name LIKE ? OR c.client_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $sql .= " AND p.status = ?";
    $params[] = $status;
}

$sql .= " GROUP BY p.id ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allProjects = $stmt->fetchAll();

$totalClientsCount  = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
$totalProjectsCount = (int) $pdo->query("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.deleted_at IS NULL AND c.deleted_at IS NULL")->fetchColumn();
$totalMatching = count($allProjects);
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$projects = array_slice($allProjects, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);

$filterClient = null;
if ($clientId > 0) {
    $cStmt = $pdo->prepare("SELECT name, client_code, company_name FROM clients WHERE id = ?");
    $cStmt->execute([$clientId]);
    $filterClient = $cStmt->fetch();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">


        <?php if ($totalClientsCount === 0): ?>
            <div class="card p-5 text-center my-4 bg-light border-0 shadow-sm">
                <div class="mb-3">
                    <i class="bi bi-people text-muted" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark">No Clients Added Yet</h4>
                <p class="text-muted mx-auto mb-3" style="max-width: 500px;">
                    Projects must be assigned to an existing client. Please create your first client before adding projects.
                </p>
                <div>
                    <a href="../clients/add_client.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                        <i class="bi bi-person-plus me-1"></i> Add First Client
                    </a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($totalProjectsCount > 0): ?>
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold mb-0">Project Management</h4>
                        <p class="text-muted mb-0">Track all client projects, deadlines, and financial milestones.</p>
                    </div>
                    <div>
                        <a href="add_project.php" class="btn btn-primary">
                            <i class="bi bi-folder-plus me-1"></i> Add New Project
                        </a>
                    </div>
                </div>
                <?php if ($filterClient): ?>
                    <div class="alert alert-info py-2 px-3 d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <i class="bi bi-funnel-fill me-1 text-primary"></i> Filtered by Client:
                            <strong><?php echo htmlspecialchars($filterClient['name']); ?></strong> (<?php echo htmlspecialchars($filterClient['client_code']); ?>)
                        </div>
                        <a href="list_projects.php" class="btn btn-sm btn-outline-dark py-0 px-2" style="font-size: 0.8rem;">Clear Filter</a>
                    </div>
                <?php endif; ?>

                <div class="card p-3 mb-4">
                    <form method="GET" class="row g-2 align-items-end">
                        <?php if ($clientId > 0): ?>
                            <input type="hidden" name="client_id" value="<?php echo $clientId; ?>">
                        <?php endif; ?>
                        <div class="col-12 col-md-6">
                            <label class="form-label small text-muted mb-1">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Search by title, code, client name, client code..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Status</label>

                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>

                                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>
                                    Draft
                                </option>

                                <option value="proposal_sent" <?php echo $status === 'proposal_sent' ? 'selected' : ''; ?>>
                                    Waiting Confirmation
                                </option>

                                <option value="advance_pending" <?php echo $status === 'advance_pending' ? 'selected' : ''; ?>>
                                    Advance Payment Pending
                                </option>

                                <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>
                                    In Progress
                                </option>

                                <option value="review_approval" <?php echo $status === 'review_approval' ? 'selected' : ''; ?>>
                                    Review / Approval
                                </option>

                                <option value="on_hold" <?php echo $status === 'on_hold' ? 'selected' : ''; ?>>
                                    On Hold
                                </option>

                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>
                                    Completed
                                </option>

                                <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>
                                    Cancelled
                                </option>
                            </select>

                        </div>
                        <div class="col-6 col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-search me-1"></i> Search
                            </button>
                            <a href="list_projects.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($totalProjectsCount === 0): ?>
                <div class="card p-5 text-center my-3 shadow-sm border-0">
                    <div class="mb-3">
                        <i class="bi bi-folder2-open text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">No Projects Added Yet</h5>
                    <p class="text-muted mb-3">You have not created any projects yet. Click below to add your first project.</p>
                    <div>
                        <a href="add_project.php" class="btn btn-primary">
                            <i class="bi bi-folder-plus me-1"></i> Add First Project
                        </a>
                    </div>
                </div>
            <?php elseif (empty($projects)): ?>
                <div class="card p-5 text-center my-3 shadow-sm border-0">
                    <div class="mb-3">
                        <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">No Matching Projects Found</h5>
                    <p class="text-muted mb-3">No projects match your filter criteria.</p>
                    <div>
                        <a href="list_projects.php" class="btn btn-outline-secondary">Clear Filter</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card p-3 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Project Code</th>
                                    <th>Title & Client</th>
                                    <th>Total Budget</th>
                                    <th>Paid</th>
                                    <th>Due</th>
                                    <th>Deadline</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $p):
                                    $balance = max(0, $p['total_amount'] - $p['total_paid']);

                                    $statusBadge = [
                                        'draft'           => 'light text-dark border',
                                        'proposal_sent'   => 'warning text-dark',
                                        'advance_pending' => 'info text-dark',
                                        'in_progress'     => 'primary',
                                        'review_approval' => 'info text-dark',
                                        'on_hold'         => 'secondary',
                                        'completed'       => 'success',
                                        'cancelled'       => 'danger'
                                    ][$p['status']] ?? 'secondary';

                                ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($p['project_code']); ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars(!empty($p['client_profile_pic']) ? $p['client_profile_pic'] : 'default-avatar.png'); ?>" 
                                                     class="rounded-circle object-fit-cover shadow-xs border" 
                                                     style="width:34px;height:34px;min-width:34px;min-height:34px;" 
                                                     alt="<?php echo htmlspecialchars($p['client_name']); ?>"
                                                     onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                                                <div>
                                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($p['title']); ?></div>
                                                    <div class="text-muted small">
                                                        <?php echo htmlspecialchars($p['client_name']); ?>
                                                        <?php if (!empty($p['company_name'])): ?>
                                                            · <?php echo htmlspecialchars($p['company_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold">₹<?php echo number_format($p['total_amount'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="text-success fw-semibold">₹<?php echo number_format($p['total_paid'], 2); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($balance > 0): ?>
                                                <span class="text-danger fw-bold">₹<?php echo number_format($balance, 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">₹0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($p['deadline']): ?>
                                                <div class="small <?php echo (strtotime($p['deadline']) < time() && $p['status'] !== 'completed') ? 'text-danger fw-bold' : ''; ?>">
                                                    <i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($p['deadline'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">No deadline</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $statusBadge; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $p['status'])); ?>
                                            </span>

                                            <?php if ($p['status'] === 'proposal_sent' || $p['status'] === 'waiting_confirmation' || $p['status'] === 'draft'): ?>
                                                <a href="send_proposal.php?id=<?php echo $p['id']; ?>&redirect=list&token=<?php echo generateCsrfToken(); ?>" class="btn btn-xs btn-outline-warning mt-1 d-block" style="font-size: 0.70rem; padding: 2px 4px;" title="Send Proposal Email to Client">
                                                    <i class="bi bi-send me-1"></i><?php echo $p['status'] === 'proposal_sent' ? 'Resend Proposal' : 'Send Proposal'; ?>
                                                </a>
                                            <?php elseif ($p['status'] === 'advance_pending'): ?>
                                                <?php if (!empty($p['next_pay_id'])):
                                                    $nTot = (float) $p['next_pay_amount'];
                                                    $nPd  = (float) ($p['next_pay_paid'] ?? 0);
                                                    $nDue = max(0, $nTot - $nPd);
                                                ?>
                                                    <a href="../payments/mark_paid.php?id=<?php echo $p['next_pay_id']; ?>&redirect=projects"
                                                        class="btn btn-xs btn-success text-white mt-1 d-block fw-semibold btn-record-payment"
                                                        style="font-size: 0.72rem; padding: 2px 6px;"
                                                        title="Collect Advance Payment · <?php echo htmlspecialchars($p['next_pay_invoice']); ?>"
                                                        data-pay-id="<?php echo $p['next_pay_id']; ?>"
                                                        data-invoice="<?php echo htmlspecialchars($p['next_pay_invoice']); ?>"
                                                        data-title="<?php echo htmlspecialchars($p['next_pay_title']); ?>"
                                                        data-client="<?php echo htmlspecialchars($p['client_name']); ?>"
                                                        data-total="<?php echo number_format($nTot, 2, '.', ''); ?>"
                                                        data-paid="<?php echo number_format($nPd, 2, '.', ''); ?>"
                                                        data-due="<?php echo number_format($nDue, 2, '.', ''); ?>"
                                                        data-redirect="projects">
                                                        <i class="bi bi-cash me-1"></i>Collect Advance
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../payments/add_payment.php?project_id=<?php echo $p['id']; ?>&type=advance" class="btn btn-xs btn-success text-white mt-1 d-block fw-semibold" style="font-size: 0.72rem; padding: 2px 6px;" title="Set Milestones & Advance">
                                                        <i class="bi bi-plus-circle me-1"></i>Set Milestones
                                                    </a>
                                                <?php endif; ?>
                                                <a href="update_status.php?id=<?php echo $p['id']; ?>&status=in_progress" class="btn btn-xs btn-outline-primary mt-1 d-block" style="font-size: 0.70rem; padding: 1px 4px;" title="Advance Received? Start Project">
                                                    <i class="bi bi-play-fill me-1"></i>Start Project
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="send_proposal.php?id=<?php echo $p['id']; ?>&redirect=list&token=<?php echo generateCsrfToken(); ?>" class="btn btn-sm btn-outline-warning" title="Send / Resend Proposal Email">
                                                    <i class="bi bi-send"></i>
                                                </a>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View Proposal Document">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                                <a href="view_project.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-info" title="View Project & Payments">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (hasPermission($admin, 'projects_manage')): ?>
                                                    <a href="edit_project.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Project">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (hasPermission($admin, 'projects_delete')): ?>
                                                    <a href="delete_project.php?id=<?php echo $p['id']; ?>"
                                                        class="btn btn-sm btn-outline-danger btn-delete"
                                                        title="Delete"
                                                        data-confirm-text="Are you sure you want to delete this project and all its records?"
                                                        data-confirm-title="Delete Project"
                                                        data-confirm-btn="Delete Project"
                                                        data-confirm-color="danger">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
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
                                Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> projects
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