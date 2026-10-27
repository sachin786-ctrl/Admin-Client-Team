/**
 * Universal Password Visibility Toggle (Global Function & Aliases)
 * Handles input IDs, input names, input elements, data-target attributes, and auto-detection.
 */
window.togglePasswordVisibility = function (target, btn) {
    let input = null;

    if (typeof target === 'string') {
        input = document.getElementById(target) || document.querySelector(`input[name="${target}"]`);
    } else if (target && target.tagName === 'INPUT') {
        input = target;
    }

    // Resolve trigger button/span/icon element
    let trigger = btn;
    if (!trigger && typeof event !== 'undefined' && event && event.currentTarget) {
        trigger = event.currentTarget;
    }

    // Fallback: If input not resolved yet, inspect container of trigger
    if (!input && trigger && trigger.closest) {
        const group = trigger.closest('.input-group, .form-group, .mb-3, .mb-2, .col-6, div');
        if (group) {
            input = group.querySelector('input[type="password"], input[data-toggle-active="true"]');
        }
    }

    if (!input) return;

    // Resolve icon element
    let icon = null;
    if (trigger) {
        icon = (trigger.tagName === 'I' || trigger.classList.contains('bi')) 
            ? trigger 
            : trigger.querySelector('i, .bi');
    }
    if (!icon && input.parentElement) {
        icon = input.parentElement.querySelector('.toggle-password i, button i, span i');
    }

    const isCurrentlyPassword = (input.type === 'password');
    input.type = isCurrentlyPassword ? 'text' : 'password';
    input.setAttribute('data-toggle-active', isCurrentlyPassword ? 'true' : 'false');

    if (icon) {
        if (isCurrentlyPassword) {
            // Password was dots, NOW REVEALED as text -> show OPEN eye (bi-eye)
            icon.classList.remove('bi-eye-slash', 'bi-eye-slash-fill');
            icon.classList.add('bi-eye');
        } else {
            // Password was text, NOW CONCEALED as dots -> show SLASHED eye (bi-eye-slash)
            icon.classList.remove('bi-eye', 'bi-eye-fill');
            icon.classList.add('bi-eye-slash');
        }
    }

    if (trigger && typeof trigger.setAttribute === 'function') {
        const newTitle = isCurrentlyPassword ? 'Hide password' : 'Show password';
        trigger.setAttribute('title', newTitle);
        trigger.setAttribute('aria-label', newTitle);
    }

    // Preserve focus & cursor position on the input field
    if (document.activeElement === trigger && typeof input.focus === 'function') {
        const len = input.value.length;
        input.focus();
        try {
            input.setSelectionRange(len, len);
        } catch (e) {}
    }
};

window.togglePass = window.togglePasswordVisibility;

document.addEventListener('DOMContentLoaded', function () {
    // =========================================================================
    // 1. SIDEBAR TOGGLE (Desktop & Mobile)
    // =========================================================================
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('show');
            sidebar.classList.toggle('sidebar-show');
        });

        // Close sidebar on click outside on mobile
        document.addEventListener('click', function (e) {
            if (window.innerWidth < 992 && sidebar.classList.contains('sidebar-show')) {
                if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
                    sidebar.classList.remove('sidebar-show', 'show');
                }
            }
        });
    }

    // =========================================================================
    // =========================================================================
    // 2. UNIVERSAL CONFIRMATION & LOGOUT MODAL
    // =========================================================================
    function getOrCreateConfirmModal() {
        let modal = document.getElementById('customLogoutModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'customLogoutModal';
            modal.className = 'logout-modal-overlay';
            modal.style.display = 'none';
            modal.innerHTML = `
                <div class="logout-modal-box">
                    <div class="logout-modal-icon" id="confirmModalIcon"><i class="bi bi-exclamation-triangle-fill text-warning"></i></div>
                    <h3 id="confirmModalTitle">Confirm Action</h3>
                    <p id="confirmModalText">Are you sure you want to proceed?</p>
                    <div class="logout-modal-actions">
                        <button type="button" id="cancelLogoutBtn" class="btn-cancel">Cancel</button>
                        <a href="#" id="confirmLogoutBtn" class="btn-confirm btn-confirm-primary">Confirm</a>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) window.closeConfirmModal();
            });
            modal.querySelector('#cancelLogoutBtn')?.addEventListener('click', function () {
                window.closeConfirmModal();
            });
        }
        return modal;
    }

    const cancelConfirmBtn = document.getElementById('cancelLogoutBtn');
    if (cancelConfirmBtn) {
        cancelConfirmBtn.addEventListener('click', function () {
            window.closeConfirmModal();
        });
    }

    const existingConfirmModal = document.getElementById('customLogoutModal');
    if (existingConfirmModal) {
        existingConfirmModal.addEventListener('click', function (e) {
            if (e.target === existingConfirmModal) window.closeConfirmModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('customLogoutModal');
            if (modal && modal.style.display === 'flex') {
                window.closeConfirmModal();
            }
        }
    });

    window.openConfirmModal = function (options) {
        options = options || {};
        const modal = getOrCreateConfirmModal();
        if (!modal) return;

        const titleEl = modal.querySelector('#confirmModalTitle');
        const textEl = modal.querySelector('#confirmModalText');
        const iconEl = modal.querySelector('#confirmModalIcon');
        const confirmBtn = modal.querySelector('#confirmLogoutBtn');

        if (titleEl) titleEl.textContent = options.title || 'Confirm Action';
        if (textEl) textEl.textContent = options.text || 'Are you sure you want to proceed?';
        if (iconEl && options.icon) iconEl.innerHTML = options.icon;

        if (confirmBtn) {
            confirmBtn.textContent = options.confirmText || 'Confirm';
            const color = options.confirmColor || 'primary';
            confirmBtn.className = 'btn-confirm btn-confirm-' + color;

            confirmBtn.onclick = function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.closeConfirmModal();
                if (options.onConfirm && typeof options.onConfirm === 'function') {
                    options.onConfirm();
                } else if (options.href && options.href !== '#' && options.href.trim() !== '') {
                    window.location.href = options.href;
                }
            };
        }
        modal.style.display = 'flex';
    };

    window.closeConfirmModal = function () {
        const modal = document.getElementById('customLogoutModal');
        if (modal) modal.style.display = 'none';
    };

    // =========================================================================
    // 3. UNIVERSAL NOTIFICATION DETAILS MODAL
    // =========================================================================
    const notifModal = document.getElementById('customNotificationModal');
    const closeNotifBtn = document.getElementById('closeNotifModalBtn');
    if (closeNotifBtn) {
        closeNotifBtn.addEventListener('click', function () {
            window.closeNotifModal();
        });
    }
    if (notifModal) {
        notifModal.addEventListener('click', function (e) {
            if (e.target === notifModal) window.closeNotifModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && notifModal.style.display === 'flex') {
                window.closeNotifModal();
            }
        });
    }

    window.openNotifModal = function (data) {
        const modal = document.getElementById('customNotificationModal');
        if (!modal) return;
        const titleEl = document.getElementById('notifModalTitle');
        const timeEl = document.getElementById('notifModalTime');
        const msgEl = document.getElementById('notifModalMsg');
        const iconEl = document.getElementById('notifModalIcon');
        const actionArea = document.getElementById('notifModalActionArea');
        const actionBtn = document.getElementById('notifModalActionBtn');

        if (titleEl) titleEl.textContent = data.title || 'Notification Details';
        if (timeEl) timeEl.textContent = data.time || 'Just now';
        if (msgEl) msgEl.textContent = data.message || '';
        if (iconEl && data.icon) {
            iconEl.innerHTML = `<i class="${data.icon}"></i>`;
            if (data.color) iconEl.className = 'notification-item-icon ' + data.color;
        }
        if (actionArea && actionBtn) {
            if (data.link && data.link !== '#' && data.link.trim() !== '') {
                actionBtn.href = data.link;
                actionArea.style.display = 'flex';
            } else {
                actionArea.style.display = 'none';
            }
        }
        modal.style.display = 'flex';
    };

    window.closeNotifModal = function () {
        const modal = document.getElementById('customNotificationModal');
        if (modal) modal.style.display = 'none';
    };

    // =========================================================================
    // 4. NOTIFICATION CLICK INTERCEPTOR
    // =========================================================================
    document.addEventListener('click', function (e) {
        const item = e.target.closest('.js-notif-trigger');
        if (!item) return;
        if (e.target.closest('input, button, label') || (e.target.closest('a') && e.target.closest('a') !== item)) return;

        const notifId = item.dataset.notifId;
        const title = item.dataset.notifTitle;
        const msg = item.dataset.notifMsg;
        const time = item.dataset.notifTime;
        const icon = item.dataset.notifIcon;
        const color = item.dataset.notifColor;
        const link = item.dataset.notifLink;
        const actionUrl = item.dataset.actionUrl;

        // If in topbar dropdown with a link, go to destination
        if (item.closest('.notification-dropdown-menu') && link && link !== '#' && link.trim() !== '') {
            if (item.classList.contains('unread') && actionUrl && notifId) {
                navigator.sendBeacon ? navigator.sendBeacon(actionUrl + '?id=' + notifId + '&ajax=1') : fetch(actionUrl + '?id=' + notifId + '&ajax=1');
            }
            window.location.href = link;
            return;
        }

        // Open details modal
        e.preventDefault();
        if (item.classList.contains('unread') && actionUrl && notifId) {
            fetch(actionUrl + '?id=' + notifId + '&ajax=1')
                .then(r => r.json())
                .then(data => {
                    item.classList.remove('unread');
                    const dot = document.querySelector('.notification-badge-dot');
                    if (dot && data.unread_count !== undefined) {
                        if (data.unread_count <= 0) dot.remove();
                        else dot.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    }
                })
                .catch(() => {});
        }

        if (window.openNotifModal) {
            window.openNotifModal({
                title: title,
                message: msg,
                time: time,
                icon: icon,
                color: color,
                link: link
            });
        }
    });

    // =========================================================================
    // 5. UNIVERSAL RECORD PAYMENT MODAL (Mark as Paid Popup)
    // =========================================================================
    setupRecordPaymentModal();

    function setupRecordPaymentModal() {
        const rpm = document.getElementById('recordPaymentModal');
        if (!rpm) return;

        window.closeRecordPaymentModal = function () {
            rpm.style.display = 'none';
        };

        const closeBtn = document.getElementById('closeRecordPaymentModalBtn');
        if (closeBtn) closeBtn.addEventListener('click', window.closeRecordPaymentModal);
        const cancelBtn = document.getElementById('cancelRecordPaymentBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', window.closeRecordPaymentModal);

        rpm.addEventListener('click', function (e) {
            if (e.target === rpm) window.closeRecordPaymentModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && rpm.style.display === 'flex') {
                window.closeRecordPaymentModal();
            }
        });

        const form = document.getElementById('recordPaymentForm');
        const idInput = document.getElementById('rpmPaymentId');
        const redInput = document.getElementById('rpmRedirect');
        const subtitle = document.getElementById('rpmInvoiceSubtitle');
        const totalDueEl = document.getElementById('rpmTotalDue');
        const alreadyPaidEl = document.getElementById('rpmAlreadyPaid');
        const paidAmountInput = document.getElementById('rpmPaidAmount');
        const maxBadge = document.getElementById('rpmMaxDueBadge');
        const hintEl = document.getElementById('rpmAmountHint');
        const paidDateInput = document.getElementById('rpmPaidDate');
        const fullBtn = document.getElementById('rpmFillFullBtn');

        let currentDue = 0;

        function refreshRpmValidation() {
            if (!paidAmountInput) return;
            const v = parseFloat(paidAmountInput.value) || 0;
            const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            const amtErr = document.getElementById('rpmAmountError');
            if (v <= 0) {
                if (amtErr) amtErr.textContent = 'Please enter a valid payment amount greater than 0.';
                if (paidAmountInput) paidAmountInput.classList.add('is-invalid');
                if (submitBtn) submitBtn.disabled = true;
            } else if (v > currentDue + 0.005) {
                if (amtErr) amtErr.textContent = 'Amount exceeds balance due: ₹' + currentDue.toFixed(2);
                if (paidAmountInput) paidAmountInput.classList.add('is-invalid');
                if (submitBtn) submitBtn.disabled = true;
            } else {
                if (amtErr) amtErr.textContent = '';
                if (paidAmountInput) paidAmountInput.classList.remove('is-invalid');
                const left = Math.max(0, currentDue - v);
                if (hintEl) {
                    hintEl.className = left === 0 ? 'form-text text-success fw-semibold' : 'form-text text-muted';
                    hintEl.textContent = left === 0 ? 'Invoice fully settled.' : 'Remaining due: ₹' + left.toFixed(2);
                }
                if (submitBtn) submitBtn.disabled = false;
            }
        }

        if (paidAmountInput) {
            paidAmountInput.addEventListener('input', refreshRpmValidation);
            paidAmountInput.addEventListener('blur', refreshRpmValidation);
        }

        if (paidDateInput) {
            paidDateInput.addEventListener('change', function() {
                const dateErr = document.getElementById('rpmDateError');
                if (!paidDateInput.value) {
                    if (dateErr) dateErr.textContent = 'Payment date is required.';
                    paidDateInput.classList.add('is-invalid');
                } else {
                    if (dateErr) dateErr.textContent = '';
                    paidDateInput.classList.remove('is-invalid');
                }
            });
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                const v = parseFloat(paidAmountInput.value) || 0;
                const amtErr = document.getElementById('rpmAmountError');
                const dateErr = document.getElementById('rpmDateError');
                let valid = true;

                if (v <= 0) {
                    if (amtErr) amtErr.textContent = 'Payment amount is required and must be greater than 0.';
                    paidAmountInput.classList.add('is-invalid');
                    valid = false;
                } else if (v > currentDue + 0.005) {
                    if (amtErr) amtErr.textContent = 'Payment amount cannot exceed remaining due (₹' + currentDue.toFixed(2) + ').';
                    paidAmountInput.classList.add('is-invalid');
                    valid = false;
                } else {
                    if (amtErr) amtErr.textContent = '';
                    paidAmountInput.classList.remove('is-invalid');
                }

                if (!paidDateInput || !paidDateInput.value) {
                    if (dateErr) dateErr.textContent = 'Payment date is required.';
                    if (paidDateInput) paidDateInput.classList.add('is-invalid');
                    valid = false;
                } else {
                    if (dateErr) dateErr.textContent = '';
                    if (paidDateInput) paidDateInput.classList.remove('is-invalid');
                }

                if (!valid) {
                    e.preventDefault();
                    if (v <= 0 || v > currentDue + 0.005) {
                        paidAmountInput.focus();
                    } else if (paidDateInput) {
                        paidDateInput.focus();
                    }
                }
            });
        }

        if (fullBtn) {
            fullBtn.addEventListener('click', function () {
                if (paidAmountInput) {
                    paidAmountInput.value = currentDue > 0 ? currentDue.toFixed(2) : '';
                    refreshRpmValidation();
                }
            });
        }

        document.addEventListener('click', function (e) {
            const trigger = e.target.closest('.btn-record-payment') || 
                            (e.target.closest('a') && e.target.closest('a').href && e.target.closest('a').href.includes('mark_paid.php') ? e.target.closest('a') : null);
            if (!trigger) return;

            // If another modal (like Bootstrap payment_modal.php with #rpId) is active on this page, let it handle
            if (document.getElementById('rpId')) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            const href = trigger.getAttribute('href') || '';
            const payId = trigger.dataset.payId || (href.match(/[?&]id=(\d+)/) ? href.match(/[?&]id=(\d+)/)[1] : '');
            const invoice = trigger.dataset.invoice || '';
            const title = trigger.dataset.title || 'Milestone';
            const client = trigger.dataset.client || '';
            const total = parseFloat(trigger.dataset.total) || 0;
            const paid = parseFloat(trigger.dataset.paid) || 0;
            const due = parseFloat(trigger.dataset.due) || (total - paid);
            const redirect = trigger.dataset.redirect || '';

            currentDue = Math.max(0, due > 0 ? due : (total - paid));

            if (form) {
                form.action = href && href.includes('mark_paid.php') ? href : '/client-flow/admin/payments/mark_paid.php';
            }
            if (idInput) idInput.value = payId;
            if (redInput) redInput.value = redirect;
            if (subtitle) {
                subtitle.textContent = (invoice ? invoice + ' · ' : '') + title + (client ? ' (' + client + ')' : '');
            }
            if (totalDueEl) {
                totalDueEl.textContent = '₹' + currentDue.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            if (alreadyPaidEl) {
                alreadyPaidEl.textContent = '₹' + paid.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            if (maxBadge) {
                maxBadge.textContent = 'Max: ₹' + currentDue.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            if (paidAmountInput) {
                paidAmountInput.max = currentDue.toFixed(2);
                paidAmountInput.value = currentDue > 0 ? currentDue.toFixed(2) : '';
            }

            const projectCreated = trigger.dataset.projectCreated || '';
            if (paidDateInput) {
                paidDateInput.value = new Date().toISOString().slice(0, 10);
                paidDateInput.max = new Date().toISOString().slice(0, 10);
                if (projectCreated) {
                    paidDateInput.min = projectCreated;
                } else {
                    paidDateInput.removeAttribute('min');
                }
            }

            refreshRpmValidation();

            rpm.style.display = 'flex';
            setTimeout(() => {
                if (paidAmountInput) {
                    paidAmountInput.focus();
                    paidAmountInput.select();
                }
            }, 100);
        });
    }

    // =========================================================================
    // 6. SANITIZE LEGACY INLINE CONFIRMS & GLOBAL INTERCEPTORS
    // =========================================================================
    // Auto-convert any lingering inline onclick/onsubmit="return confirm('...')" to avoid browser's "localhost says"
    document.querySelectorAll('[onclick*="confirm("], [onsubmit*="confirm("]').forEach(function (el) {
        const inline = el.getAttribute('onclick') || el.getAttribute('onsubmit') || '';
        const match = inline.match(/confirm\s*\(\s*(['"`])(.*?)\1\s*\)/);
        if (match && match[2] && !el.getAttribute('data-confirm-text')) {
            el.setAttribute('data-confirm-text', match[2]);
        }
        if (el.hasAttribute('onclick') && inline.includes('confirm(')) {
            el.removeAttribute('onclick');
        }
        if (el.hasAttribute('onsubmit') && inline.includes('confirm(')) {
            el.removeAttribute('onsubmit');
        }
    });

    // Global Click Handler for Links, Buttons, and Deletes
    document.addEventListener('click', function (e) {
        // Skip inside confirmation modal itself
        if (e.target.closest('#customLogoutModal')) return;

        const trigger = e.target.closest('a, button');
        if (!trigger) return;

        // Skip bootstrap dropdown toggles or dismiss controls
        if (trigger.hasAttribute('data-bs-toggle') || trigger.hasAttribute('data-bs-dismiss')) return;

        const href = trigger.getAttribute('href') || '';
        const customPrompt = trigger.getAttribute('data-confirm-text') || trigger.getAttribute('data-confirm');
        const isDelete = href.includes('delete_') || trigger.classList.contains('btn-delete') || (customPrompt && customPrompt.toLowerCase().includes('delete'));
        const formParent = trigger.closest('form');

        // Only intercept if element has data-confirm or is a delete action
        if (customPrompt || isDelete) {
            e.preventDefault();
            e.stopPropagation();

            const title = trigger.getAttribute('data-confirm-title') || (isDelete ? 'Delete Confirmation' : 'Confirm Action');
            const text = customPrompt || (isDelete ? 'Are you sure you want to delete this item? This action cannot be undone.' : 'Are you sure you want to proceed?');
            const icon = trigger.getAttribute('data-confirm-icon') || (isDelete ? '<i class="bi bi-trash3-fill text-danger"></i>' : '<i class="bi bi-exclamation-triangle-fill text-warning"></i>');
            const confirmBtnText = trigger.getAttribute('data-confirm-btn') || (isDelete ? 'Delete' : 'Confirm');
            const confirmBtnColor = trigger.getAttribute('data-confirm-color') || (isDelete ? 'danger' : 'primary');

            window.openConfirmModal({
                title: title,
                text: text,
                icon: icon,
                confirmText: confirmBtnText,
                confirmColor: confirmBtnColor,
                href: href && href !== '#' ? href : null,
                onConfirm: function () {
                    if (href && href !== '#' && href.trim() !== '') {
                        window.location.href = href;
                    } else if (formParent) {
                        formParent.dataset.confirmed = 'true';
                        if (trigger.type === 'submit' && trigger.name) {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = trigger.name;
                            hidden.value = trigger.value;
                            formParent.appendChild(hidden);
                        }
                        formParent.submit();
                    }
                }
            });
            return;
        }
    }, true);

    // Form submit listener for forms with data-confirm-text
    document.addEventListener('submit', function (e) {
        const form = e.target;
        const confirmText = form.getAttribute('data-confirm-text') || form.getAttribute('data-confirm');
        if (confirmText && !form.dataset.confirmed) {
            e.preventDefault();
            e.stopPropagation();
            const isDelete = confirmText.toLowerCase().includes('delete') || (form.action && form.action.includes('delete_'));
            window.openConfirmModal({
                title: form.getAttribute('data-confirm-title') || (isDelete ? 'Delete Confirmation' : 'Confirm Action'),
                text: confirmText,
                icon: form.getAttribute('data-confirm-icon') || (isDelete ? '<i class="bi bi-trash3-fill text-danger"></i>' : '<i class="bi bi-question-circle-fill text-primary"></i>'),
                confirmText: form.getAttribute('data-confirm-btn') || (isDelete ? 'Delete' : 'Confirm'),
                confirmColor: form.getAttribute('data-confirm-color') || (isDelete ? 'danger' : 'success'),
                onConfirm: function () {
                    form.dataset.confirmed = 'true';
                    form.submit();
                }
            });
        }
    });

    // =========================================================================
    // 7. SESSION FLASH TOAST POPUP (On Page Load)
    // =========================================================================
    const flashEl = document.getElementById('sessionFlashData');
    if (flashEl) {
        const msg = flashEl.dataset.message;
        const typ = flashEl.dataset.type || 'success';
        const tit = flashEl.dataset.title || '';
        if (msg) {
            window.showAppToast(msg, typ, tit);
        }
    }

    // =========================================================================
    // 8. UNIVERSAL PASSWORD EYE TOGGLE EVENT DELEGATION
    // =========================================================================
    document.addEventListener('click', function (e) {
        const eyeIcon = e.target.closest('.bi-eye, .bi-eye-slash, .bi-eye-fill, .bi-eye-slash-fill');
        const trigger = e.target.closest('.toggle-password, [data-toggle="password"]') || 
                        (eyeIcon ? eyeIcon.closest('.input-group-text, button, .btn') : null);

        if (!trigger) return;

        // Skip if inline onclick already handled it
        const inlineOnclick = trigger.getAttribute('onclick') || '';
        if (inlineOnclick && (inlineOnclick.includes('togglePasswordVisibility') || inlineOnclick.includes('togglePass'))) {
            return;
        }

        const targetId = trigger.getAttribute('data-target') || trigger.getAttribute('data-input');
        if (targetId) {
            e.preventDefault();
            window.togglePasswordVisibility(targetId, trigger);
        } else {
            const group = trigger.closest('.input-group') || trigger.parentElement;
            if (group) {
                const input = group.querySelector('input[type="password"], input[data-toggle-active="true"]');
                if (input) {
                    e.preventDefault();
                    window.togglePasswordVisibility(input, trigger);
                }
            }
        }
    });
});

// =============================================================================
// 8. FLOATING TOAST SYSTEM (Global Function)
// =============================================================================
window.showAppToast = function (message, type = 'success', title = '') {
    if (!message) return;
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-popup-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const borderClass = (type === 'danger' || type === 'error') 
        ? 'toast-danger' 
        : (type === 'warning' ? 'toast-warning' : (type === 'info' ? 'toast-info' : 'toast-success'));

    const iconClass = (type === 'danger' || type === 'error') 
        ? 'bi-exclamation-octagon-fill' 
        : (type === 'warning' ? 'bi-exclamation-triangle-fill' : (type === 'info' ? 'bi-info-circle-fill' : 'bi-check2-circle'));

    const headerTitle = title || ((type === 'danger' || type === 'error') 
        ? 'Notice' 
        : (type === 'warning' ? 'Warning' : (type === 'info' ? 'Information' : 'Success')));

    toast.className = `toast-popup ${borderClass}`;
    toast.innerHTML = `
        <div class="toast-popup-icon">
            <i class="bi ${iconClass}"></i>
        </div>
        <div class="toast-popup-content">
            <div class="toast-popup-title">
                <span>${headerTitle}</span>
            </div>
            <div class="toast-popup-msg">${message}</div>
        </div>
        <button type="button" class="toast-popup-close" aria-label="Close" title="Dismiss">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="toast-popup-progress">
            <div class="toast-popup-progress-bar"></div>
        </div>
    `;

    let dismissTimer = null;
    let remainingTime = 4500;
    let startTime = Date.now();

    function dismissToast() {
        if (toast.classList.contains('hide')) return;
        toast.classList.add('hide');
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 360);
    }

    function startTimer(duration) {
        startTime = Date.now();
        dismissTimer = setTimeout(dismissToast, duration);
    }

    // Close button dismiss
    const closeBtn = toast.querySelector('.toast-popup-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            clearTimeout(dismissTimer);
            dismissToast();
        });
    }

    // Pause countdown & dismiss on hover
    toast.addEventListener('mouseenter', function () {
        clearTimeout(dismissTimer);
        remainingTime -= (Date.now() - startTime);
        const progressBar = toast.querySelector('.toast-popup-progress-bar');
        if (progressBar) {
            progressBar.style.animationPlayState = 'paused';
        }
    });

    toast.addEventListener('mouseleave', function () {
        const progressBar = toast.querySelector('.toast-popup-progress-bar');
        if (progressBar) {
            progressBar.style.animationPlayState = 'running';
        }
        if (remainingTime > 0) {
            startTimer(remainingTime);
        } else {
            dismissToast();
        }
    });

    container.appendChild(toast);
    startTimer(remainingTime);
};

// =============================================================================
// GLOBAL ALERT OVERRIDE (Replaces browser "localhost says" alert with UI toast)
// =============================================================================
window.alert = function (message) {
    if (typeof window.showAppToast === 'function') {
        window.showAppToast(String(message), 'warning', 'Notice');
    } else {
        console.warn('System Alert:', message);
    }
};

// =============================================================================
// 9. PASSWORD VISIBILITY TOGGLE EXPORT CONFIRMATION
// =============================================================================
// Already defined at top of script to ensure early availability.
if (!window.togglePasswordVisibility) {
    window.togglePasswordVisibility = function (inputId, btn) {
        window.togglePass(inputId, btn);
    };
}
