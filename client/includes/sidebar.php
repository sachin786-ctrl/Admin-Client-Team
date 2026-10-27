<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 
$curClient = $client ?? (isset($pdo) && function_exists('getAuthenticatedClient') ? getAuthenticatedClient($pdo) : null);
$unreadClientCountSidebar = isset($pdo, $curClient['id']) ? getUnreadNotificationsCount($pdo, 'client', $curClient['id']) : 0;

$clientAvatar = '/client-flow/assets/uploads/profile/default-avatar.png';
if (!empty($curClient['profile_pic']) && file_exists(__DIR__ . '/../../assets/uploads/profile/' . $curClient['profile_pic'])) {
    $clientAvatar = '/client-flow/assets/uploads/profile/' . htmlspecialchars($curClient['profile_pic']);
}
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between">
        <a href="/client-flow/client/profile.php" class="d-flex align-items-center gap-2 text-decoration-none text-white overflow-hidden" title="My Profile">
            <img src="<?php echo $clientAvatar; ?>" 
                 alt="<?php echo htmlspecialchars($curClient['name'] ?? 'Client'); ?>" 
                 style="width:40px;height:40px;min-width:40px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.25);"
                 onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
            <div class="d-flex flex-column text-truncate" style="line-height:1.2;">
                <span class="fw-bold text-white text-truncate" style="font-size:14px;"><?php echo htmlspecialchars($curClient['name'] ?? 'Client'); ?></span>
                <span class="text-white-50 text-truncate" style="font-size:11px;"><?php echo htmlspecialchars(!empty($curClient['company_name']) ? $curClient['company_name'] : 'Client Portal'); ?></span>
            </div>
        </a>
        <i class="bi bi-x-lg sidebar-close-icon d-lg-none" id="clientSidebarCloseBtn" aria-label="Close Sidebar" onclick="document.getElementById('sidebar')?.classList.remove('show', 'sidebar-show');" title="Close"></i>
    </div>
    <ul class="sidebar-nav">
        <li>
            <a href="/client-flow/client/dashboard.php" class="<?php echo ($currentPage === 'dashboard.php' || $currentPage === 'index.php') ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="/client-flow/client/projects.php" class="<?php echo $currentPage === 'projects.php' ? 'active' : ''; ?>">
                <i class="bi bi-folder-fill"></i> <span>My Projects</span>
            </a>
        </li>
        <li>
            <a href="/client-flow/client/payments.php" class="<?php echo $currentPage === 'payments.php' ? 'active' : ''; ?>">
                <i class="bi bi-credit-card-fill"></i> <span>My Payments</span>
            </a>
        </li>
        <li>
            <a href="/client-flow/client/notifications.php" class="<?php echo $currentPage === 'notifications.php' ? 'active' : ''; ?>">
                <i class="bi bi-bell-fill"></i> <span>Notifications</span>
                <?php if ($unreadClientCountSidebar > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 10px;"><?php echo $unreadClientCountSidebar; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="/client-flow/client/profile.php" class="<?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
                <i class="bi bi-person-circle"></i> <span>My Profile</span>
            </a>
        </li>
        <li>
            <a href="/client-flow/client/auth/logout.php" id="logoutBtn">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</nav>
