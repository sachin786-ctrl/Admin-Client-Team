<?php 
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']); 
$curAdmin = $admin ?? (isset($pdo) && function_exists('getAuthenticatedAdmin') ? getAuthenticatedAdmin($pdo) : null);
$unreadAdminCountSidebar = isset($pdo, $curAdmin['id']) ? getUnreadNotificationsCount($pdo, 'admin', $curAdmin['id']) : 0;
$canViewClients  = empty($curAdmin) || hasPermission($curAdmin, 'clients_view');
$canViewProjects = empty($curAdmin) || hasPermission($curAdmin, 'projects_view');
$canViewPayments = empty($curAdmin) || hasPermission($curAdmin, 'payments_view');
$canManageTeam   = !empty($curAdmin) && (hasPermission($curAdmin, 'team_manage') || hasPermission($curAdmin, 'team_view'));
$canViewNotifs   = empty($curAdmin) || hasPermission($curAdmin, 'notifications_view');

// Profile picture determination (works for super_admin & team staff)
$adminAvatar = '/client-flow/assets/uploads/profile/default-avatar.png';
if (!empty($curAdmin['profile_pic']) && file_exists(__DIR__ . '/../../assets/uploads/profile/' . $curAdmin['profile_pic'])) {
    $adminAvatar = '/client-flow/assets/uploads/profile/' . htmlspecialchars($curAdmin['profile_pic']);
}
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between">
        <a href="/client-flow/admin/profile.php" class="d-flex align-items-center gap-2 text-decoration-none text-white overflow-hidden" title="My Profile">
            <img src="<?php echo $adminAvatar; ?>" 
                 alt="<?php echo htmlspecialchars($curAdmin['name'] ?? 'Admin'); ?>" 
                 style="width:40px;height:40px;min-width:40px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.25);"
                 onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
            <div class="d-flex flex-column text-truncate" style="line-height:1.2;">
                <span class="fw-bold text-white text-truncate" style="font-size:14px;"><?php echo htmlspecialchars($curAdmin['name'] ?? 'Admin'); ?></span>
                <span class="text-white-50 text-truncate" style="font-size:11px;"><?php echo (($curAdmin['role'] ?? '') === 'super_admin') ? 'Super Admin' : 'Team Member'; ?></span>
            </div>
        </a>
        <i class="bi bi-x-lg sidebar-close-icon d-lg-none" id="sidebarCloseBtn" aria-label="Close Sidebar" onclick="document.getElementById('sidebar')?.classList.remove('show', 'sidebar-show');" title="Close"></i>
    </div>
    <ul class="sidebar-nav">
        <li>
            <a href="/client-flow/admin/dashboard.php" class="<?php echo ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        </li>
        <?php if ($canViewClients): ?>
        <li>
            <a href="/client-flow/admin/clients/list_clients.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/clients/') !== false ? 'active' : ''; ?>">
                <i class="bi bi-people-fill"></i> <span>Clients</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($canViewProjects): ?>
        <li>
            <a href="/client-flow/admin/projects/list_projects.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/projects/') !== false ? 'active' : ''; ?>">
                <i class="bi bi-folder-fill"></i> <span>Projects</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($canViewPayments): ?>
        <li>
            <a href="/client-flow/admin/payments/list_payments.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/payments/') !== false ? 'active' : ''; ?>">
                <i class="bi bi-credit-card-fill"></i> <span>Payments</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($canManageTeam): ?>
        <li>
            <a href="/client-flow/admin/team/list_team.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/team/') !== false ? 'active' : ''; ?>">
                <i class="bi bi-people-fill"></i> <span>Team</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($canViewNotifs): ?>
        <li>
            <a href="/client-flow/admin/notifications.php" class="<?php echo $currentPage === 'notifications.php' ? 'active' : ''; ?>">
                <i class="bi bi-bell-fill"></i> <span>Notifications</span>
                <?php if ($unreadAdminCountSidebar > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 10px;"><?php echo $unreadAdminCountSidebar; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endif; ?>
        <li>
            <a href="/client-flow/admin/profile.php" class="<?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
                <i class="bi bi-person-circle"></i> <span>Profile</span>
            </a>
        </li>
        <li>
            <a href="/client-flow/admin/logout.php" id="logoutBtn">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</nav>
