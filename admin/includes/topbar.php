<?php
$unreadNotifsCount = isset($pdo, $admin['id']) ? getUnreadNotificationsCount($pdo, 'admin', $admin['id']) : 0;
$recentAdminNotifs = isset($pdo, $admin['id']) ? getRecentNotifications($pdo, 'admin', $admin['id'], 5) : [];
?>
<header class="topbar" style="position: -webkit-sticky !important; position: sticky !important; top: 0 !important; z-index: 1020 !important;">
    <button class="btn btn-sm btn-light d-lg-none" id="sidebarToggle" aria-label="Toggle Navigation">
        <i class="bi bi-list fs-4"></i>
    </button>
    <h5 class="mb-0 d-none d-md-block"><?php echo $pageTitle ?? 'Dashboard'; ?></h5>
    
    <div class="topbar-right">
        <!-- Admin Notifications Dropdown -->
        <div class="dropdown">
            <a href="#" class="notification-bell-btn" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($unreadNotifsCount > 0): ?>
                    <span class="notification-badge-dot"><?php echo $unreadNotifsCount > 9 ? '9+' : $unreadNotifsCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown-menu shadow">
                <div class="notification-header">
                    <span class="fw-bold small text-dark">Notifications</span>
                    <?php if ($unreadNotifsCount > 0): ?>
                        <a href="/client-flow/admin/notifications_action.php" class="small text-decoration-none" style="font-size: 11px;">Mark all read</a>
                    <?php endif; ?>
                </div>
                <div class="notification-list" style="max-height: 320px; overflow-y: auto;">
                    <?php if (empty($recentAdminNotifs)): ?>
                        <div class="text-center py-4 text-muted small">
                            <i class="bi bi-bell-slash fs-4 d-block mb-1 text-muted opacity-50"></i>
                            No notifications yet
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentAdminNotifs as $notif): 
                            $iconColor = ($notif['type'] === 'success') ? 'text-success bg-success-subtle' : (($notif['type'] === 'warning') ? 'text-warning bg-warning-subtle' : (($notif['type'] === 'danger') ? 'text-danger bg-danger-subtle' : 'text-primary bg-primary-subtle'));
                        ?>
                            <a href="/client-flow/admin/notifications_action.php?id=<?php echo $notif['id']; ?>" 
                               class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> js-notif-trigger"
                               data-notif-id="<?php echo $notif['id']; ?>"
                               data-notif-title="<?php echo htmlspecialchars($notif['title']); ?>"
                               data-notif-msg="<?php echo htmlspecialchars($notif['message']); ?>"
                               data-notif-time="<?php echo timeAgo($notif['created_at']); ?> &bull; <?php echo date('d M, Y h:i A', strtotime($notif['created_at'])); ?>"
                               data-notif-icon="bi <?php echo htmlspecialchars($notif['icon'] ?: 'bi-bell-fill'); ?>"
                               data-notif-color="<?php echo $iconColor; ?>"
                               data-notif-link="<?php echo htmlspecialchars($notif['link'] ?: ''); ?>"
                               data-action-url="/client-flow/admin/notifications_action.php">
                                <div class="notification-item-icon <?php echo $iconColor; ?>">
                                    <i class="bi <?php echo htmlspecialchars($notif['icon'] ?: 'bi-bell-fill'); ?>"></i>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="notification-item-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-item-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notification-item-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="notification-footer">
                    <a href="/client-flow/admin/notifications.php">View All Notifications <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>

        <!-- Admin Profile Dropdown -->
        <div class="dropdown topbar-profile-dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle topbar-profile-pill" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <img src="<?php echo $assetBase ?? getBasePath(); ?>assets/uploads/profile/<?php echo htmlspecialchars($admin['profile_pic'] ?? 'default-avatar.png'); ?>"
                     class="topbar-avatar" alt="Profile">
                <span class="fw-semibold d-none d-sm-inline ms-1"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></span>
                <i class="bi bi-chevron-down ms-1 text-muted topbar-dropdown-arrow"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="/client-flow/admin/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="/client-flow/admin/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="/client-flow/admin/logout.php" id="logoutBtn"><i class="bi bi-box-arrow-right me-2"></i>Log Out</a></li>
            </ul>
        </div>
    </div>
</header>
