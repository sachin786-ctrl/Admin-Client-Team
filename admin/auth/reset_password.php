<?php
require_once __DIR__ . '/../includes/functions.php';
redirectIfLoggedIn();

if (empty($_SESSION['reset_email']) || empty($_SESSION['otp_verified'])) {
    setFlash('error', 'Please verify your OTP first.');
    header('Location: forgot_password.php');
    exit;
}

$pageTitle = 'Reset Password';
$flash = getFlash();
$csrf = generateCsrfToken();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-card-header">
            <i class="bi bi-key-fill"></i>
            <h4 class="mb-0 fw-bold">Reset Password</h4>
            <p class="mb-0 small opacity-75">Create a new password for your account</p>
        </div>
        <div class="auth-card-body">

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> py-2">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <form action="update_password.php" method="POST" id="resetPasswordForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Min 8 chars" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary toggle-password bg-white" data-target="new_password" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('new_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                        </div>
                        <p class="text-danger small mt-1 mb-0" id="new_passwordError"></p>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary toggle-password bg-white" data-target="confirm_password" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('confirm_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                        </div>
                        <p class="text-danger small mt-1 mb-0" id="confirm_passwordError"></p>
                    </div>
                    <div class="col-12">
                        <div id="passwordMatchMsg" class="form-text"></div>
                    </div>
                </div>

                <button type="submit" id="submitBtn" class="btn btn-primary w-100 text-white mt-3">
                    <i class="bi bi-check2-circle me-1"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resetPasswordForm');
    const newPwd = document.getElementById('new_password');
    const confPwd = document.getElementById('confirm_password');
    const newPwdErr = document.getElementById('new_passwordError');
    const confPwdErr = document.getElementById('confirm_passwordError');
    const matchMsg = document.getElementById('passwordMatchMsg');
    const submitBtn = document.getElementById('submitBtn');

    function checkMatch() {
        if (!newPwd || !confPwd) return true;
        const val1 = newPwd.value;
        const val2 = confPwd.value;

        let valid = true;
        if (!val1) {
            newPwdErr.textContent = 'New Password is required.';
            newPwd.classList.add('is-invalid');
            valid = false;
        } else if (val1.length < 8) {
            newPwdErr.textContent = 'Password must be at least 8 characters long.';
            newPwd.classList.add('is-invalid');
            valid = false;
        } else {
            newPwdErr.textContent = '';
            newPwd.classList.remove('is-invalid');
        }

        if (!val2) {
            if (confPwdErr) confPwdErr.textContent = 'Confirm Password is required.';
            confPwd.classList.add('is-invalid');
            valid = false;
        } else if (val1 !== val2) {
            if (confPwdErr) confPwdErr.textContent = 'Passwords do not match.';
            confPwd.classList.add('is-invalid');
            valid = false;
        } else {
            if (confPwdErr) confPwdErr.textContent = '';
            confPwd.classList.remove('is-invalid');
        }

        if (matchMsg) {
            if (val1 && val2 && val1 === val2 && val1.length >= 8) {
                matchMsg.textContent = '✓ Passwords match and meet strength requirements';
                matchMsg.className = 'form-text text-success fw-semibold';
            } else {
                matchMsg.textContent = '';
            }
        }

        return valid;
    }

    if (newPwd) {
        newPwd.addEventListener('input', function() {
            if (newPwd.value.length >= 8) {
                newPwdErr.textContent = '';
                newPwd.classList.remove('is-invalid');
            }
            if (confPwd.value) checkMatch();
        });
    }
    if (confPwd) {
        confPwd.addEventListener('input', function() {
            if (confPwd.value === newPwd.value) {
                confPwdErr.textContent = '';
                confPwd.classList.remove('is-invalid');
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!checkMatch()) {
                e.preventDefault();
                if (!newPwd.value || newPwd.value.length < 8) {
                    newPwd.focus();
                } else {
                    confPwd.focus();
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
