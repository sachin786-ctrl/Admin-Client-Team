    <!-- ========================================== -->
    <!-- Custom Universal Confirmation & Logout Modal -->
    <!-- ========================================== -->
    <div id="customLogoutModal" class="logout-modal-overlay" style="display: none;">
        <div class="logout-modal-box">
            <div class="logout-modal-icon" id="confirmModalIcon"><i class="bi bi-exclamation-triangle-fill text-warning"></i></div>
            <h3 id="confirmModalTitle">Confirm Action</h3>
            <p id="confirmModalText">Are you sure you want to proceed?</p>
            <div class="logout-modal-actions">
                <button type="button" id="cancelLogoutBtn" class="btn-cancel">Cancel</button>
                <a href="#" id="confirmLogoutBtn" class="btn-confirm">Confirm</a>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- Custom Universal Notification Details Modal -->
    <!-- ========================================== -->
    <div id="customNotificationModal" class="logout-modal-overlay" style="display: none;">
        <div class="logout-modal-box" style="max-width: 460px; text-align: left; padding: 22px;">
            <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <div id="notifModalIcon" class="notification-item-icon" style="width:34px;height:34px;min-width:34px;font-size:16px;">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark" id="notifModalTitle" style="font-size: 14.5px;">Notification Details</h6>
                        <span class="text-muted" id="notifModalTime" style="font-size: 11px;">Just now</span>
                    </div>
                </div>
                <button type="button" class="btn-close" id="closeNotifModalBtn" aria-label="Close"></button>
            </div>
            <div>
                <p class="text-secondary mb-3" id="notifModalMsg" style="font-size: 13.5px; line-height: 1.55; word-break: break-word;"></p>
                <div id="notifModalActionArea" style="display: none;" class="mt-3 pt-3 border-top d-flex justify-content-end gap-2">
                    <a href="#" id="notifModalActionBtn" class="btn btn-sm btn-primary">
                        <span>Open Related Page</span> <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php
    $toastFlash = $flash ?? (function_exists('getClientFlash') ? getClientFlash() : null);
    if (!empty($toastFlash) && !empty($toastFlash['message'])):
        $toastType = ($toastFlash['type'] === 'error' || $toastFlash['type'] === 'danger') ? 'danger' : ($toastFlash['type'] === 'warning' ? 'warning' : 'success');
        $toastTitle = $toastType === 'success' ? 'Success' : ($toastType === 'danger' ? 'Notice' : 'Notification');
    ?>
        <div id="sessionFlashData" style="display: none;"
             data-message="<?php echo htmlspecialchars($toastFlash['message']); ?>"
             data-type="<?php echo htmlspecialchars($toastType); ?>"
             data-title="<?php echo htmlspecialchars($toastTitle); ?>"></div>
    <?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/client-flow/assets/js/script.js?v=8.4"></script>
</body>
</html>
