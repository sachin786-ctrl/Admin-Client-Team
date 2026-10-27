<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('team_manage');

$pageTitle = 'Team Management';
$flash = getFlash();

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$role   = $_GET['role'] ?? '';

$sql = "SELECT * FROM admin WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $sql .= " AND status = ?";
    $params[] = (int) $status;
}

if ($role !== '') {
    $sql .= " AND role = ?";
    $params[] = $role;
}

$sql .= " ORDER BY CASE WHEN role = 'super_admin' THEN 0 ELSE 1 END, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teamMembers = $stmt->fetchAll();

$totalCount = (int) $pdo->query("SELECT COUNT(*) FROM admin")->fetchColumn();
$staffCount = (int) $pdo->query("SELECT COUNT(*) FROM admin WHERE role = 'staff'")->fetchColumn();
$activeCount = (int) $pdo->query("SELECT COUNT(*) FROM admin WHERE status = 1")->fetchColumn();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-people-fill text-primary"></i> Team Management
                </h4>
                <p class="text-muted mb-0 small">Create staff accounts, assign granular role permissions, and manage access.</p>
            </div>
            <div>
                <a href="add_team.php" class="btn btn-primary px-3 py-2 shadow-sm rounded-3">
                    <i class="bi bi-person-plus-fill me-1"></i> Add New Member
                </a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i><?php echo htmlspecialchars($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Vibrant Soft Gradient Stat Cards (Clickable) -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <a href="list_team.php" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-primary" title="View all registered accounts">
                        <i class="bi bi-people-fill"></i>
                        <h3><?php echo $totalCount; ?></h3>
                        <div class="small opacity-75">Total Accounts</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4">
                <a href="list_team.php?role=staff" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-info" title="Filter delegated staff members">
                        <i class="bi bi-person-gear"></i>
                        <h3><?php echo $staffCount; ?></h3>
                        <div class="small opacity-75">Staff Members</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4">
                <a href="list_team.php?status=1" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-success" title="Filter active authorized logins">
                        <i class="bi bi-shield-check"></i>
                        <h3><?php echo $activeCount; ?></h3>
                        <div class="small opacity-75">Active Users</div>
                    </div>
                </a>
            </div>
        </div>

        <?php if ($staffCount > 0 || ($search !== '' || $status !== '' || $role !== '')): ?>
        <!-- Filter Card -->
        <div class="card p-3 mb-4 border-0 shadow-sm rounded-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label small text-muted mb-1 fw-semibold">Search Member</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1 fw-semibold">Role</label>
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <option value="super_admin" <?php echo $role === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="staff" <?php echo $role === 'staff' ? 'selected' : ''; ?>>Staff Member</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1 fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="bi bi-funnel-fill me-1"></i>Filter
                    </button>
                    <?php if ($search !== '' || $status !== '' || $role !== ''): ?>
                        <a href="list_team.php" class="btn btn-outline-secondary" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Team List Table -->
        <div class="card p-0 shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 py-3 text-muted small text-uppercase" style="width: 50px;">#</th>
                            <th class="py-3 text-muted small text-uppercase">Team Member</th>
                            <th class="py-3 text-muted small text-uppercase">Role</th>
                            <th class="py-3 text-muted small text-uppercase">Permissions Granted</th>
                            <th class="py-3 text-muted small text-uppercase">Status</th>
                            <th class="py-3 text-muted small text-uppercase">Created</th>
                            <th class="py-3 text-muted small text-uppercase text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teamMembers)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-people text-muted opacity-50 d-block mb-2" style="font-size: 2.5rem;"></i>
                                    No team members found matching your search or filter.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teamMembers as $idx => $member): 
                                $isSelf = ((int)$member['id'] === (int)$admin['id']);
                                $isSuper = ($member['role'] === 'super_admin');
                                $perms = !empty($member['permissions']) ? (is_array($member['permissions']) ? $member['permissions'] : json_decode($member['permissions'], true)) : [];
                                if (!is_array($perms)) $perms = [];

                                // Group permissions for visual pills
                                $hasClients  = !empty(array_filter($perms, fn($p) => strpos($p, 'clients_') === 0));
                                $hasProjects = !empty(array_filter($perms, fn($p) => strpos($p, 'projects_') === 0));
                                $hasPayments = !empty(array_filter($perms, fn($p) => strpos($p, 'payments_') === 0));
                                $hasNotifs   = !empty(array_filter($perms, fn($p) => strpos($p, 'notifications_') === 0));
                                $hasTeam     = !empty(array_filter($perms, fn($p) => strpos($p, 'team_') === 0));
                            ?>
                                <tr>
                                    <td class="ps-3 text-muted small fw-semibold"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3 py-1">
                                            <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars($member['profile_pic'] ?: 'default-avatar.png'); ?>"
                                                 class="rounded-circle object-fit-cover border shadow-xs" 
                                                 style="width: 42px; height: 42px;" 
                                                 alt="Avatar"
                                                 onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                                            <div>
                                                <div class="fw-bold text-dark d-flex align-items-center gap-1">
                                                    <?php echo htmlspecialchars($member['name']); ?>
                                                    <?php if ($isSelf): ?>
                                                        <span class="badge bg-primary text-white rounded-pill px-2 py-1 ms-1" style="font-size: 10px;">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted d-flex align-items-center gap-1">
                                                    <i class="bi bi-envelope text-muted"></i>
                                                    <?php echo htmlspecialchars($member['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($isSuper): ?>
                                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-semibold">
                                                <i class="bi bi-shield-fill-check me-1"></i>Super Admin
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-3 py-2 fw-semibold">
                                                <i class="bi bi-person-badge me-1"></i>Staff
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isSuper): ?>
                                            <span class="badge rounded-pill bg-light text-dark border px-3 py-2 fw-semibold">
                                                <i class="bi bi-infinity text-primary me-1"></i>Full Access
                                            </span>
                                        <?php elseif (empty($perms)): ?>
                                            <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                <i class="bi bi-slash-circle me-1"></i>No Permissions
                                            </span>
                                        <?php else: ?>
                                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-semibold me-1">
                                                    <i class="bi bi-check2-circle me-1"></i><?php echo count($perms); ?> Items
                                                </span>
                                                <?php if ($hasClients): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 10.5px;" title="Clients Access">
                                                        <i class="bi bi-people-fill me-1"></i>Clients
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasProjects): ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size: 10.5px;" title="Projects Access">
                                                        <i class="bi bi-folder-fill me-1"></i>Projects
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasPayments): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 10.5px;" title="Payments Access">
                                                        <i class="bi bi-credit-card-fill me-1"></i>Payments
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasNotifs): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 10.5px;" title="Notifications Access">
                                                        <i class="bi bi-bell-fill me-1"></i>Notifications
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasTeam): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 10.5px;" title="Team Access">
                                                        <i class="bi bi-person-badge-fill me-1"></i>Team
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$member['status'] === 1): ?>
                                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2 fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fw-semibold">
                                                <i class="bi bi-dash-circle-fill me-1"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted">
                                            <i class="bi bi-calendar3 me-1"></i><?php echo date('d M, Y', strtotime($member['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_team.php?id=<?php echo $member['id']; ?>" class="btn btn-outline-primary" title="Edit permissions & details">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <?php if (!$isSelf && !$isSuper): ?>
                                                <a href="toggle_status.php?id=<?php echo $member['id']; ?>&token=<?php echo generateCsrfToken(); ?>" 
                                                   class="btn <?php echo (int)$member['status'] === 1 ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                                   title="<?php echo (int)$member['status'] === 1 ? 'Deactivate member login' : 'Activate member login'; ?>">
                                                    <i class="bi <?php echo (int)$member['status'] === 1 ? 'bi-lock-fill' : 'bi-unlock-fill'; ?>"></i>
                                                </a>
                                                <a href="delete_team.php?id=<?php echo $member['id']; ?>&token=<?php echo generateCsrfToken(); ?>" 
                                                   class="btn btn-outline-danger btn-delete" 
                                                   title="Delete member"
                                                   data-confirm-text="Are you sure you want to permanently remove <?php echo htmlspecialchars($member['name'], ENT_QUOTES); ?>?"
                                                   data-confirm-title="Remove Team Member"
                                                   data-confirm-btn="Delete"
                                                   data-confirm-color="danger">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
