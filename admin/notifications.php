<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Notifications';

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$highlightId = (int)($_GET['highlight'] ?? 0);

$allNotifications = getAllNotifications($pdo, 'admin', $admin['id'], $filter, 1000, $search);
$unreadCount = getUnreadNotificationsCount($pdo, 'admin', $admin['id']);
$totalCount = count(getAllNotifications($pdo, 'admin', $admin['id'], 'all', 1000));

// Pagination (Max 5 per page)
$totalMatching = count($allNotifications);
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$notifications = array_slice($allNotifications, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);

function getNotifPaginationUrl($p, $filter, $search) {
    $params = ['page' => $p];
    if ($filter !== 'all') $params['filter'] = $filter;
    if ($search !== '') $params['search'] = $search;
    return 'notifications.php?' . http_build_query($params);
}

$flash = getFlash();
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-bell-fill text-primary me-2"></i>Notifications</h4>
                <p class="text-muted mb-0">Track all client updates, project creations, invoices, payments, and system alerts.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($unreadCount > 0): ?>
                    <a href="/client-flow/admin/notifications_action.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-check2-all me-1"></i> Mark All as Read
                    </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="nav nav-pills gap-1">
                    <a href="notifications.php?filter=all<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="nav-link btn-sm py-1 px-3 <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        All <span class="badge bg-secondary ms-1"><?php echo $totalCount; ?></span>
                    </a>
                    <a href="notifications.php?filter=unread<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="nav-link btn-sm py-1 px-3 <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                        Unread <span class="badge bg-danger ms-1"><?php echo $unreadCount; ?></span>
                    </a>
                    <a href="notifications.php?filter=read<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="nav-link btn-sm py-1 px-3 <?php echo $filter === 'read' ? 'active' : ''; ?>">
                        Read
                    </a>
                </div>

                <!-- Search Form -->
                <form method="GET" action="notifications.php" class="d-flex align-items-center gap-2">
                    <?php if ($filter !== 'all'): ?>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <input type="text" name="search" class="form-control" placeholder="Search notifications..." value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    </div>
                    <?php if ($search !== ''): ?>
                        <a href="notifications.php?filter=<?php echo urlencode($filter); ?>" class="btn btn-sm btn-outline-secondary" title="Clear Search">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Notifications List -->
        <?php if (empty($notifications)): ?>
            <div class="card border-0 shadow-sm text-center py-5">
                <div class="py-4">
                    <i class="bi bi-bell-slash text-muted opacity-50" style="font-size: 3.5rem;"></i>
                    <h5 class="fw-bold mt-3 mb-1">No Notifications Found</h5>
                    <p class="text-muted mb-0">
                        <?php if ($search !== ''): ?>
                            No notifications match your search query "<?php echo htmlspecialchars($search); ?>".
                        <?php else: ?>
                            <?php echo $filter === 'unread' ? 'You have read all administrator notifications.' : 'You have no system notifications yet.'; ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <form method="POST" action="/client-flow/admin/notifications_action.php" id="bulkNotifForm">
                <input type="hidden" name="action" value="bulk_delete">
                
                <!-- Bulk Delete Controls -->
                <div class="card p-2 px-3 mb-3 border-0 shadow-sm d-flex flex-row justify-content-between align-items-center bg-light">
                    <div class="d-flex align-items-center gap-2">
                        <input type="checkbox" id="selectAllNotifs" class="form-check-input cursor-pointer">
                        <label for="selectAllNotifs" class="form-check-label small fw-semibold cursor-pointer mb-0">Select All</label>
                    </div>
                    <button type="submit" id="btnDeleteSelectedNotifs" class="btn btn-sm btn-outline-danger" disabled 
                            data-confirm-text="Are you sure you want to delete all selected notifications?"
                            data-confirm-title="Delete Notifications"
                            data-confirm-btn="Delete Selected"
                            data-confirm-color="danger">
                        <i class="bi bi-trash me-1"></i> Delete Selected (<span id="countSelectedNotifs">0</span>)
                    </button>
                </div>

                <div class="notification-container">
                    <?php foreach ($notifications as $n): 
                        $isHighlighted = ($highlightId === (int)$n['id']);
                        $iconColor = ($n['type'] === 'success') ? 'text-success bg-success-subtle' : (($n['type'] === 'warning') ? 'text-warning bg-warning-subtle' : (($n['type'] === 'danger') ? 'text-danger bg-danger-subtle' : 'text-primary bg-primary-subtle'));
                    ?>
                        <div class="notification-card <?php echo $n['is_read'] ? '' : 'unread'; ?> <?php echo $isHighlighted ? 'highlighted' : ''; ?> js-notif-trigger"
                             id="notif-card-<?php echo $n['id']; ?>"
                             data-notif-id="<?php echo $n['id']; ?>"
                             data-notif-title="<?php echo htmlspecialchars($n['title']); ?>"
                             data-notif-msg="<?php echo htmlspecialchars($n['message']); ?>"
                             data-notif-time="<?php echo timeAgo($n['created_at']); ?> &bull; <?php echo date('d M, Y h:i A', strtotime($n['created_at'])); ?>"
                             data-notif-icon="bi <?php echo htmlspecialchars($n['icon'] ?: 'bi-bell-fill'); ?>"
                             data-notif-color="<?php echo $iconColor; ?>"
                             data-notif-link="<?php echo htmlspecialchars($n['link'] ?: ''); ?>"
                             data-action-url="/client-flow/admin/notifications_action.php">
                            <div class="d-flex align-items-start gap-3">
                                <!-- Individual Checkbox -->
                                <div class="pt-1">
                                    <input type="checkbox" name="notification_ids[]" value="<?php echo $n['id']; ?>" class="form-check-input notif-checkbox cursor-pointer" onclick="event.stopPropagation();">
                                </div>
                                <div class="notification-item-icon <?php echo $iconColor; ?>" style="width: 36px; height: 36px; min-width: 36px; font-size: 16px;">
                                    <i class="bi <?php echo htmlspecialchars($n['icon'] ?: 'bi-bell-fill'); ?>"></i>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <h6 class="fw-bold mb-0 text-dark" style="font-size: 14px;"><?php echo htmlspecialchars($n['title']); ?></h6>
                                            <?php if (!$n['is_read']): ?>
                                                <span class="badge bg-primary" style="font-size: 10px;">New</span>
                                            <?php endif; ?>
                                            <?php if ($isHighlighted): ?>
                                                <span class="badge bg-warning text-dark" style="font-size: 10px;">Selected</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-muted small" style="font-size: 11px;">
                                            <i class="bi bi-clock me-1"></i><?php echo timeAgo($n['created_at']); ?> (<?php echo date('M d, Y h:i A', strtotime($n['created_at'])); ?>)
                                        </span>
                                    </div>
                                    <p class="text-secondary mb-2" style="font-size: 13px; line-height: 1.45;">
                                        <?php echo htmlspecialchars($n['message']); ?>
                                    </p>
                                    <div class="d-flex align-items-center gap-2 pt-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 11px;">
                                            <i class="bi bi-eye me-1"></i> View Details
                                        </button>
                                        <?php if (!empty($n['link']) && $n['link'] !== '#'): ?>
                                            <a href="<?php echo htmlspecialchars($n['link']); ?>" class="btn btn-sm btn-light border py-0 px-2 text-decoration-none" style="font-size: 11px;" onclick="event.stopPropagation();">
                                                <span>Open Page</span> <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="/client-flow/admin/notifications_action.php?id=<?php echo $n['id']; ?>&action=delete" 
                                           class="btn btn-sm btn-outline-danger py-0 px-2 ms-auto btn-delete" 
                                           style="font-size: 11px;"
                                           title="Delete this notification"
                                           data-confirm-text="Are you sure you want to delete this notification?"
                                           data-confirm-title="Delete Notification"
                                           data-confirm-btn="Delete"
                                           data-confirm-color="danger">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>

            <!-- Pagination (Max 5 per page) -->
            <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">
                    <div class="small text-muted">
                        Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> notifications
                    </div>
                    <nav aria-label="Notifications pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getNotifPaginationUrl($page - 1, $filter, $search); ?>">
                                    <i class="bi bi-chevron-left me-1"></i> Previous
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo getNotifPaginationUrl($i, $filter, $search); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getNotifPaginationUrl($page + 1, $filter, $search); ?>">
                                    Next <i class="bi bi-chevron-right ms-1"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Select All and Bulk Delete handlers
    const selectAll = document.getElementById('selectAllNotifs');
    const checkboxes = document.querySelectorAll('.notif-checkbox');
    const btnDelete = document.getElementById('btnDeleteSelectedNotifs');
    const countDisplay = document.getElementById('countSelectedNotifs');

    function updateDeleteState() {
        const checked = document.querySelectorAll('.notif-checkbox:checked');
        const count = checked.length;
        if (countDisplay) countDisplay.textContent = count;
        if (btnDelete) btnDelete.disabled = (count === 0);
        if (selectAll && checkboxes.length > 0) {
            selectAll.checked = (count === checkboxes.length);
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateDeleteState();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateDeleteState);
    });

    <?php if ($highlightId > 0): ?>
    const el = document.getElementById('notif-card-<?php echo $highlightId; ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
