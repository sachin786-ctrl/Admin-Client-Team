<?php
$unreadClientNotifsCount = isset($pdo, $client['id']) ? getUnreadNotificationsCount($pdo, 'client', $client['id']) : 0;
$recentClientNotifs = isset($pdo, $client['id']) ? getRecentNotifications($pdo, 'client', $client['id'], 5) : [];
?>
<header class="topbar" style="position: -webkit-sticky !important; position: sticky !important; top: 0 !important; z-index: 1020 !important;">
    <button class="btn btn-sm btn-light d-lg-none" id="sidebarToggle" aria-label="Toggle Navigation">
        <i class="bi bi-list fs-4"></i>
    </button>
    <div class="d-none d-md-flex align-items-center gap-2">
        <h5 class="mb-0 fw-semibold"><?php echo $pageTitle ?? 'Client Portal'; ?></h5>
        <?php if (!empty($client['client_code'])): ?>
            <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($client['client_code']); ?></span>
        <?php endif; ?>
    </div>
    
    <div class="topbar-right">
        <!-- Client Notifications Dropdown -->
        <div class="dropdown">
            <a href="#" class="notification-bell-btn" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($unreadClientNotifsCount > 0): ?>
                    <span class="notification-badge-dot"><?php echo $unreadClientNotifsCount > 9 ? '9+' : $unreadClientNotifsCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown-menu shadow">
                <div class="notification-header">
                    <span class="fw-bold small text-dark">Notifications</span>
                    <?php if ($unreadClientNotifsCount > 0): ?>
                        <a href="/client-flow/client/notifications_action.php" class="small text-decoration-none" style="font-size: 11px;">Mark all read</a>
                    <?php endif; ?>
                </div>
                <div class="notification-list" style="max-height: 320px; overflow-y: auto;">
                    <?php if (empty($recentClientNotifs)): ?>
                        <div class="text-center py-4 text-muted small">
                            <i class="bi bi-bell-slash fs-4 d-block mb-1 text-muted opacity-50"></i>
                            No notifications yet
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentClientNotifs as $notif): 
                            $iconColor = ($notif['type'] === 'success') ? 'text-success bg-success-subtle' : (($notif['type'] === 'warning') ? 'text-warning bg-warning-subtle' : (($notif['type'] === 'danger') ? 'text-danger bg-danger-subtle' : 'text-primary bg-primary-subtle'));
                        ?>
                            <a href="/client-flow/client/notifications_action.php?id=<?php echo $notif['id']; ?>" 
                               class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> js-notif-trigger"
                               data-notif-id="<?php echo $notif['id']; ?>"
                               data-notif-title="<?php echo htmlspecialchars($notif['title']); ?>"
                               data-notif-msg="<?php echo htmlspecialchars($notif['message']); ?>"
                               data-notif-time="<?php echo timeAgo($notif['created_at']); ?> &bull; <?php echo date('d M, Y h:i A', strtotime($notif['created_at'])); ?>"
                               data-notif-icon="bi <?php echo htmlspecialchars($notif['icon'] ?: 'bi-bell-fill'); ?>"
                               data-notif-color="<?php echo $iconColor; ?>"
                               data-notif-link="<?php echo htmlspecialchars($notif['link'] ?: ''); ?>"
                               data-action-url="/client-flow/client/notifications_action.php">
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
                    <a href="/client-flow/client/notifications.php">View All Notifications <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>

        <!-- Client Profile Dropdown -->
        <div class="dropdown topbar-profile-dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle topbar-profile-pill" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars(!empty($client['profile_pic']) ? $client['profile_pic'] : 'default-avatar.png'); ?>" 
                     class="topbar-avatar" alt="<?php echo htmlspecialchars($client['name'] ?? 'Client'); ?>"
                     onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                <span class="fw-semibold d-none d-sm-inline ms-1"><?php echo htmlspecialchars($client['name'] ?? 'Client'); ?></span>
                <i class="bi bi-chevron-down ms-1 text-muted topbar-dropdown-arrow"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="/client-flow/client/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="/client-flow/client/auth/logout.php" id="logoutBtn"><i class="bi bi-box-arrow-right me-2"></i>Log Out</a></li>
            </ul>
        </div>
    </div>
</header>
