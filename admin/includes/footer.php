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

    <!-- ========================================== -->
    <!-- Custom Universal Record Payment Modal -->
    <!-- ========================================== -->
    <div id="recordPaymentModal" class="logout-modal-overlay" style="display: none;">
        <div class="logout-modal-box" style="max-width: 480px; text-align: left; padding: 24px;">
            <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <div class="notification-item-icon text-success bg-success-subtle" style="width:36px;height:36px;min-width:36px;font-size:18px;">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size: 15px;">Record Payment Received</h6>
                        <span class="text-muted small" id="rpmInvoiceSubtitle">Invoice Payment Entry</span>
                    </div>
                </div>
                <button type="button" class="btn-close" id="closeRecordPaymentModalBtn" aria-label="Close"></button>
            </div>

            <form id="recordPaymentForm" method="POST" action="/client-flow/admin/payments/mark_paid.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="id" id="rpmPaymentId" value="">
                <input type="hidden" name="redirect" id="rpmRedirect" value="">

                <div class="p-3 bg-light rounded border mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Total Milestone Due:</span>
                        <strong class="text-dark fs-6" id="rpmTotalDue">₹0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Already Paid:</span>
                        <span class="text-success small fw-semibold" id="rpmAlreadyPaid">₹0.00</span>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-semibold mb-0">Amount Received <span class="text-danger">*</span></label>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="rpmMaxDueBadge" style="font-size: 11px;">Max: ₹0.00</span>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" step="0.01" min="0.01" name="paid_amount" id="rpmPaidAmount" class="form-control fw-bold fs-6" placeholder="0.00" required>
                        <button type="button" class="btn btn-outline-secondary" id="rpmFillFullBtn" title="Set exact due amount">Full Due</button>
                    </div>
                    <p class="text-danger small mt-1 mb-0" id="rpmAmountError"></p>
                    <div class="form-text" id="rpmAmountHint">Type exact amount received. Cannot exceed remaining due.</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Payment Mode</label>

                        <select name="payment_method" id="rpmPaymentMethod" class="form-select" required>
                            <option value="" selected disabled>-- Choose Payment Method --</option>
                            <option value="upi">UPI / QR Code</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="net_banking">Net Banking</option>
                            <option value="card">Credit / Debit Card</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="direct_payment">Direct Payment</option>
                        </select>

                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Date Received <span class="text-danger">*</span></label>
                        <input type="date" name="paid_date" id="rpmPaidDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        <p class="text-danger small mt-1 mb-0" id="rpmDateError"></p>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <button type="button" class="btn btn-secondary px-3" id="cancelRecordPaymentBtn">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <i class="bi bi-check2-circle me-1"></i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $toastFlash = $flash ?? (function_exists('getFlash') ? getFlash() : null);
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
    <script src="<?php echo $assetBase ?? getBasePath(); ?>assets/js/script.js?v=8.4"></script>
    </body>

    </html>