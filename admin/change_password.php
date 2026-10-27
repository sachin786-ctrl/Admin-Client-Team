<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'Change Password';
$flash = getFlash();
$csrf = generateCsrfToken();
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Change Password</h4>
                <p class="text-muted mb-0">Update your account credentials to keep your administrator portal secure.</p>
            </div>
            <a href="profile.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Profile
            </a>
        </div>



        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card p-4">
                    <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: var(--primary-light); color: var(--primary);">
                            <i class="bi bi-key-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Update Administrator Password</h5>
                            <small class="text-muted">Ensure your new password contains at least 8 characters</small>
                        </div>
                    </div>

                    <form action="change_password_process.php" method="POST" id="changePasswordForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="current_password" id="current_password" class="form-control" required placeholder="Enter current password">
                                <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('current_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 d-none" id="current_passwordError"></p>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="new_password" id="new_password" class="form-control" minlength="8" placeholder="Min 8 chars" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('new_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                        <i class="bi bi-eye-slash"></i>
                                    </button>
                                </div>
                                <p class="text-danger small mt-1 mb-0 d-none" id="new_passwordError"></p>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" minlength="8" placeholder="Re-enter" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('confirm_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                        <i class="bi bi-eye-slash"></i>
                                    </button>
                                </div>
                                <p class="text-danger small mt-1 mb-0 d-none" id="confirm_passwordError"></p>
                            </div>
                            <div class="col-12">
                                <div id="passwordMatchMsg" class="form-text"></div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="btn btn-primary text-white mt-3">
                            <i class="bi bi-check2-circle me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('changePasswordForm');
    const curPwd = document.getElementById('current_password');
    const newPwd = document.getElementById('new_password');
    const confPwd = document.getElementById('confirm_password');
    const matchMsg = document.getElementById('passwordMatchMsg');
    const submitBtn = document.getElementById('submitBtn');

    function setFieldError(pId, inputElem, msg) {
        const p = document.getElementById(pId);
        if (p) {
            p.textContent = msg;
            p.classList.remove('d-none');
        }
        if (inputElem) {
            inputElem.classList.add('is-invalid');
            inputElem.classList.remove('is-valid');
        }
    }

    function clearFieldValidation(pId, inputElem) {
        const p = document.getElementById(pId);
        if (p) {
            p.textContent = '';
            p.classList.add('d-none');
        }
        if (inputElem) {
            inputElem.classList.remove('is-invalid');
        }
    }

    function validateCurrent() {
        if (!curPwd) return true;
        if (!curPwd.value) {
            setFieldError('current_passwordError', curPwd, 'Current password is required.');
            return false;
        }
        clearFieldValidation('current_passwordError', curPwd);
        return true;
    }

    function validateNew() {
        if (!newPwd) return true;
        if (!newPwd.value) {
            setFieldError('new_passwordError', newPwd, 'New password is required.');
            return false;
        }
        if (newPwd.value.length < 8) {
            setFieldError('new_passwordError', newPwd, 'New password must be at least 8 characters long.');
            return false;
        }
        clearFieldValidation('new_passwordError', newPwd);
        return true;
    }

    function validateConfirm() {
        if (!confPwd) return true;
        if (!confPwd.value) {
            setFieldError('confirm_passwordError', confPwd, 'Please confirm your new password.');
            return false;
        }
        if (confPwd.value !== newPwd.value) {
            setFieldError('confirm_passwordError', confPwd, 'Passwords do not match.');
            return false;
        }
        clearFieldValidation('confirm_passwordError', confPwd);
        return true;
    }

    function checkMatch() {
        if (!newPwd || !confPwd || !matchMsg) return;
        const val1 = newPwd.value;
        const val2 = confPwd.value;
        if (!val2) {
            matchMsg.textContent = '';
            matchMsg.className = 'form-text';
            return;
        }
        if (val1 === val2) {
            matchMsg.textContent = '✓ Passwords match';
            matchMsg.className = 'form-text text-success fw-semibold';
            clearFieldValidation('confirm_passwordError', confPwd);
        } else {
            matchMsg.textContent = '✗ Passwords do not match';
            matchMsg.className = 'form-text text-danger fw-semibold';
            setFieldError('confirm_passwordError', confPwd, 'Passwords do not match.');
        }
    }

    if (curPwd) {
        curPwd.addEventListener('input', validateCurrent);
        curPwd.addEventListener('blur', validateCurrent);
    }
    if (newPwd) {
        newPwd.addEventListener('input', function() { validateNew(); checkMatch(); });
        newPwd.addEventListener('blur', validateNew);
    }
    if (confPwd) {
        confPwd.addEventListener('input', function() { validateConfirm(); checkMatch(); });
        confPwd.addEventListener('blur', validateConfirm);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            let isValid = true;
            if (!validateCurrent()) isValid = false;
            if (!validateNew()) isValid = false;
            if (!validateConfirm()) isValid = false;

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Updating...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>