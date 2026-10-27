<?php
require_once __DIR__ . '/includes/functions.php';
redirectIfLoggedIn();
$pageTitle = 'Login';
$flash = getFlash();
$csrf = generateCsrfToken();
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-card-header">
            <i class="bi bi-shield-lock-fill"></i>
            <h4 class="mb-0 fw-bold">Admin Login</h4>
            <p class="mb-0 white opacity-75">Sign in to access your dashboard</p>
        </div>
        <div class="auth-card-body">

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> py-2">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <form action="auth/login_process.php" method="POST" id="adminLoginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" id="loginEmail" class="form-control" placeholder="info@example.com" required autofocus>
                    </div>
                    <p class="text-danger small mt-1 mb-0 d-none" id="emailError"></p>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter your password" required>
                        <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('loginPassword', this)" title="Show Password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <p class="text-danger small mt-1 mb-0 d-none" id="passwordError"></p>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label small" for="remember">Remember me</label>
                    </div>
                    <a href="auth/forgot_password.php" class="small text-decoration-none">Forgot Password?</a>
                </div>

                <button type="submit" class="btn btn-primary w-100 text-white" id="btnLoginSubmit">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Login
                </button>
                 <div class="text-center mt-4 pt-3 border-top">
                <a href="/client-flow/client/auth/login.php" class="small text-muted text-decoration-none">
                    <i class="bi bi-shield-lock me-1"></i> Login as Client
                </a>
            </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('adminLoginForm');
    const emailInput = document.getElementById('loginEmail');
    const pwdInput = document.getElementById('loginPassword');
    const submitBtn = document.getElementById('btnLoginSubmit');

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

    function validateEmail() {
        if (!emailInput) return true;
        const val = emailInput.value.trim();
        if (!val) {
            setFieldError('emailError', emailInput, 'Email Address is required.');
            return false;
        }
        const regex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!regex.test(val)) {
            setFieldError('emailError', emailInput, 'Please enter a valid email address.');
            return false;
        }
        clearFieldValidation('emailError', emailInput);
        return true;
    }

    function validatePassword() {
        if (!pwdInput) return true;
        const val = pwdInput.value;
        if (!val) {
            setFieldError('passwordError', pwdInput, 'Password is required.');
            return false;
        }
        clearFieldValidation('passwordError', pwdInput);
        return true;
    }

    if (emailInput) {
        emailInput.addEventListener('input', validateEmail);
        emailInput.addEventListener('blur', validateEmail);
    }
    if (pwdInput) {
        pwdInput.addEventListener('input', validatePassword);
        pwdInput.addEventListener('blur', validatePassword);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            let isValid = true;
            if (!validateEmail()) isValid = false;
            if (!validatePassword()) isValid = false;

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Logging in...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
