<?php
/**
 * Client Portal - My Projects
 * c:\xampp\htdocs\client-flow\client\projects.php
 */

require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);
$pageTitle = 'My Projects';
$flash = getClientFlash();

// Automatically sync status for all client projects
$clientPrjIds = $pdo->prepare("SELECT id FROM projects WHERE client_id = ? AND deleted_at IS NULL");
$clientPrjIds->execute([$client['id']]);
foreach ($clientPrjIds->fetchAll(PDO::FETCH_COLUMN) as $pId) {
    syncProjectStatus($pdo, (int) $pId);
}

// Filters & Search
$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['search'] ?? '');

$sql = "
    SELECT p.*,
           COALESCE(SUM(pay.paid_amount), 0) AS total_paid
    FROM projects p
    LEFT JOIN payments pay ON pay.project_id = p.id AND pay.deleted_at IS NULL
    WHERE p.client_id = ? AND p.deleted_at IS NULL AND p.status != 'draft'
";
$params = [$client['id']];

if ($search !== '') {
    $sql .= " AND (p.title LIKE ? OR p.project_code LIKE ? OR p.description LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($statusFilter !== '') {
    if ($statusFilter === 'proposals') {
        $sql .= " AND p.status IN ('proposal_sent', 'waiting_confirmation')";
    } elseif ($statusFilter === 'active') {
        $sql .= " AND p.status IN ('in_progress', 'advance_pending', 'advance_payment_pending', 'review')";
    } elseif ($statusFilter === 'review') {
        $sql .= " AND p.status = 'review'";
    } elseif ($statusFilter === 'completed') {
        $sql .= " AND p.status = 'completed'";
    } elseif ($statusFilter === 'on_hold') {
        $sql .= " AND p.status = 'on_hold'";
    } else {
        $sql .= " AND p.status = ?";
        $params[] = $statusFilter;
    }
}

$sql .= " GROUP BY p.id ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allProjects = $stmt->fetchAll();

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND deleted_at IS NULL AND status != 'draft'");
$cntStmt->execute([$client['id']]);
$totalClientProjects = (int) $cntStmt->fetchColumn();

// Pagination
$totalMatching = count($allProjects);
$perPage = 6;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$projects = array_slice($allProjects, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);

function getProjectsFilterUrl($p, $sFilter, $q) {
    $params = ['page' => $p];
    if ($sFilter !== '') $params['status'] = $sFilter;
    if ($q !== '') $params['search'] = $q;
    return 'projects.php?' . http_build_query($params);
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h4 class="fw-bold mb-0">My Projects</h4>
                <p class="text-muted small mb-0">Track all project stages, installment budgets, and agreement documents.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Dashboard
            </a>
        </div>

        <?php if ($totalClientProjects > 0 || ($search !== '' || $statusFilter !== '')): ?>
        <!-- Filter & Search Bar -->
        <div class="card p-3 mb-4 shadow-sm" style="border-radius: 12px;">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-auto d-flex flex-wrap gap-1">
                    <a href="projects.php" class="btn btn-sm <?php echo empty($statusFilter) ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                        All Projects
                    </a>
                    <a href="projects.php?status=proposals" class="btn btn-sm <?php echo $statusFilter === 'proposals' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?>">
                        Proposals Awaiting Action
                    </a>
                    <a href="projects.php?status=active" class="btn btn-sm <?php echo $statusFilter === 'active' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        Active Development
                    </a>
                    <a href="projects.php?status=review" class="btn btn-sm <?php echo $statusFilter === 'review' ? 'btn-dark' : 'btn-outline-dark'; ?>" style="<?php echo $statusFilter === 'review' ? 'background:#7e22ce;border-color:#7e22ce;color:#fff;' : 'color:#7e22ce;border-color:#d8b4fe;'; ?>">
                        Review Stage
                    </a>
                    <a href="projects.php?status=completed" class="btn btn-sm <?php echo $statusFilter === 'completed' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Completed
                    </a>
                    <a href="projects.php?status=on_hold" class="btn btn-sm <?php echo $statusFilter === 'on_hold' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                        On Hold
                    </a>
                </div>

                <div class="col-12 col-md-auto ms-md-auto">
                    <form method="GET" action="projects.php" class="d-flex gap-2">
                        <?php if ($statusFilter): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" class="form-control" placeholder="Search project or code..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                <a href="projects.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($projects)): ?>
            <div class="card p-5 text-center shadow-sm border-0 my-3" style="border-radius: 14px;">
                <div class="mb-3">
                    <i class="bi bi-folder2-open text-muted opacity-50" style="font-size: 3.5rem;"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No Projects Found</h5>
                <p class="text-muted small mb-3">No project matching your selected filter or search criteria was found.</p>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <div>
                        <a href="projects.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset All Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card p-3 shadow-sm" style="border-radius: 14px;">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Project Code</th>
                                <th>Project Scope & Title</th>
                                <th>Total Budget</th>
                                <th>Paid</th>
                                <th>Balance Due</th>
                                <th>Target Deadline</th>
                                <th>Current Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $p): 
                                $bal = max(0, (float)$p['total_amount'] - (float)$p['total_paid']);
                                $isProposal = in_array($p['status'], ['proposal_sent', 'waiting_confirmation'], true);
                                $isAdvanceDue = in_array($p['status'], ['advance_pending', 'advance_payment_pending'], true);
                                $isReview = ($p['status'] === 'review');
                                $isOnHold = ($p['status'] === 'on_hold');
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($p['project_code']); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark fs-6"><?php echo htmlspecialchars($p['title']); ?></div>
                                        <div class="text-muted small mt-1 text-truncate" style="max-width: 320px;">
                                            <?php echo htmlspecialchars($p['description'] ?: 'No additional scope notes.'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-bold">₹<?php echo number_format($p['total_amount'], 2); ?></span>
                                    </td>
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
                                        <?php if ($p['deadline']): ?>
                                            <div class="small text-muted"><i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($p['deadline'])); ?></div>
                                        <?php else: ?>
                                            <span class="small text-muted">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo getClientProjectStatusBadge($p['status']); ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="d-inline-flex flex-column gap-1 align-items-end">
                                            <?php if ($isProposal): ?>
                                                <form method="POST" action="confirm_project.php" class="d-inline"
                                                      data-confirm-text="Do you agree and accept the project deliverables, total budget of ₹<?php echo number_format($p['total_amount'], 2); ?>, and the milestone payment schedule?"
                                                      data-confirm-title="Accept Project Proposal"
                                                      data-confirm-btn="Accept Proposal"
                                                      data-confirm-color="success"
                                                      data-confirm-icon="<i class='bi bi-check-circle-fill text-success'></i>">
                                                    <input type="hidden" name="project_id" value="<?php echo $p['id']; ?>">
                                                    <input type="hidden" name="signer_name" value="<?php echo htmlspecialchars($client['name']); ?>">
                                                    <input type="hidden" name="action" value="accept">
                                                    <input type="hidden" name="redirect_to" value="projects">
                                                    <button type="submit" class="btn btn-sm btn-success fw-semibold py-1 px-2" style="font-size: 0.78rem;">
                                                        <i class="bi bi-check-circle-fill me-1"></i> Accept Proposal
                                                    </button>
                                                </form>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-eye me-1"></i> Review Proposal
                                                </a>
                                            <?php elseif ($isReview): ?>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>#agreementSection" class="btn btn-sm text-white fw-semibold py-1 px-2 shadow-sm" style="background: #9333ea; font-size: 0.78rem;">
                                                    <i class="bi bi-award-fill me-1"></i> Review & Approve
                                                </a>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-folder-check me-1"></i> View Scope
                                                </a>
                                            <?php elseif ($isOnHold): ?>
                                                <a href="payments.php" class="btn btn-sm btn-danger fw-semibold py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Settle Dues
                                                </a>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-file-text me-1"></i> View Details
                                                </a>
                                            <?php elseif ($isAdvanceDue): ?>
                                                <a href="payments.php" class="btn btn-sm btn-primary fw-semibold py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-credit-card me-1"></i> Pay Advance
                                                </a>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-file-text me-1"></i> View Details
                                                </a>
                                            <?php else: ?>
                                                <a href="view_proposal.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    <i class="bi bi-folder-check me-1"></i> View Scope
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">
                        <div class="small text-muted">
                            Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> projects
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getProjectsFilterUrl($page - 1, $statusFilter, $search); ?>">
                                        <i class="bi bi-chevron-left me-1"></i> Previous
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo getProjectsFilterUrl($i, $statusFilter, $search); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getProjectsFilterUrl($page + 1, $statusFilter, $search); ?>">
                                        Next <i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
