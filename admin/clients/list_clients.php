<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('clients_view');
$pageTitle = 'Clients';
$flash = getFlash();

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$sql = "
    SELECT c.*, COUNT(p.id) AS project_count
    FROM clients c
    LEFT JOIN projects p ON p.client_id = c.id AND p.deleted_at IS NULL
    WHERE c.deleted_at IS NULL
";
$params = [];

if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.client_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $sql .= " AND c.status = ?";
    $params[] = (int) $status;
}

$sql .= " GROUP BY c.id ORDER BY c.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allClients = $stmt->fetchAll();

$totalClientsCount = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
$totalMatching = count($allClients);
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$clients = array_slice($allClients, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">


        <?php if ($totalClientsCount > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-0">Client Management</h4>
                    <p class="text-muted mb-0">Manage all your clients and their portal accounts.</p>
                </div>
                <div>
                    <a href="add_client.php" class="btn btn-primary">
                        <i class="bi bi-person-plus-fill me-1"></i> Add New Client
                    </a>
                </div>
            </div>
            <div class="card p-3 mb-4">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-6">
                        <label class="form-label small text-muted mb-1">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, company, email, phone, code..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-search me-1"></i> Search
                        </button>
                        <a href="list_clients.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($totalClientsCount === 0): ?>
            <div class="card p-5 text-center my-3 shadow-sm border-0">
                <div class="mb-3">
                    <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No Clients Added Yet</h5>
                <p class="text-muted mb-3">You have not added any clients yet. Click below to add your first client.</p>
                <div>
                    <a href="add_client.php" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i> Add First Client
                    </a>
                </div>
            </div>
        <?php elseif (empty($clients)): ?>
            <div class="card p-5 text-center my-3 shadow-sm border-0">
                <div class="mb-3">
                    <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No Matching Clients Found</h5>
                <p class="text-muted mb-3">No clients match your filter criteria.</p>
                <div>
                    <a href="list_clients.php" class="btn btn-outline-secondary">Clear Filter</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card p-3 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client Code</th>
                                <th>Client Name & Company</th>
                                <th>Contact Info</th>
                                <th>Projects</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $c): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($c['client_code']); ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($c['profile_pic']) && file_exists(__DIR__ . '/../../assets/uploads/profile/' . $c['profile_pic'])): ?>
                                                <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars($c['profile_pic']); ?>" 
                                                     class="rounded-circle object-fit-cover" 
                                                     style="width:36px;height:36px;min-width:36px;min-height:36px;border:1.5px solid var(--border-color);" 
                                                     alt="<?php echo htmlspecialchars($c['name']); ?>">
                                            <?php else: ?>
                                                <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width:36px;height:36px;min-width:36px;min-height:36px;font-weight:600;font-size:14px;">
                                                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($c['name']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($c['company_name'] ?: 'Individual'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div><i class="bi bi-envelope text-muted me-1"></i> <?php echo htmlspecialchars($c['email']); ?></div>
                                        <?php if (!empty($c['phone'])): ?>
                                            <div class="small text-muted"><i class="bi bi-telephone text-muted me-1"></i> <?php echo htmlspecialchars($c['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="../projects/list_projects.php?client_id=<?php echo $c['id']; ?>&search=<?php echo urlencode($c['client_code']); ?>" class="badge bg-primary-subtle text-primary border text-decoration-none" title="View Client Projects">
                                            <i class="bi bi-folder-check me-1"></i><?php echo (int)$c['project_count']; ?> Projects
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] == 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo date('d M Y', strtotime($c['created_at'])); ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="view_client.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (hasPermission($admin, 'clients_manage')): ?>
                                                <a href="edit_client.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="toggle_status.php?id=<?php echo $c['id']; ?>&token=<?php echo generateCsrfToken(); ?>" 
                                                   class="btn btn-sm <?php echo (int)$c['status'] === 1 ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                                   title="<?php echo (int)$c['status'] === 1 ? 'Deactivate client portal' : 'Activate client portal'; ?>">
                                                    <i class="bi <?php echo (int)$c['status'] === 1 ? 'bi-lock-fill' : 'bi-unlock-fill'; ?>"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (hasPermission($admin, 'clients_delete')): ?>
                                                <a href="delete_client.php?id=<?php echo $c['id']; ?>&token=<?php echo generateCsrfToken(); ?>" 
                                                   class="btn btn-sm btn-outline-danger btn-delete" 
                                                   title="Delete"
                                                   data-confirm-text="Are you sure you want to delete client &quot;<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>&quot; and all associated records?"
                                                   data-confirm-title="Delete Client"
                                                   data-confirm-btn="Delete"
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
                            Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> clients
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
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
