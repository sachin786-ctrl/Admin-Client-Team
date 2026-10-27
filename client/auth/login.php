<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['client_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

$error = $_SESSION['client_login_error'] ?? '';
unset($_SESSION['client_login_error']);

$success = $_SESSION['client_login_success'] ?? '';
unset($_SESSION['client_login_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/client-flow/assets/css/style.css?v=6.3" rel="stylesheet">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-card-header">
            <i class="bi bi-briefcase-fill"></i>
            <h4 class="mb-0 fw-bold">Client Login</h4>
            <p class="mb-0 small opacity-75">Sign in to your client account</p>
        </div>
        <div class="auth-card-body">

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 alert-dismissible fade show">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success py-2 alert-dismissible fade show">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" id="clientLoginForm" novalidate>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" id="clientEmail" class="form-control" placeholder="client@example.com" required autofocus>
                    </div>
                    <p class="text-danger small mt-1 mb-0 d-none" id="emailError"></p>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="clientPassword" class="form-control" placeholder="Enter password" required>
                        <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('clientPassword', this)" title="Show Password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <p class="text-danger small mt-1 mb-0 d-none" id="passwordError"></p>
                </div>

                <button type="submit" class="btn btn-primary w-100 text-white mt-2" id="btnClientLoginSubmit">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In 
                </button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/client-flow/assets/js/script.js?v=8.4"></script>
<script>
// Standalone instant fallback
if (typeof window.togglePasswordVisibility !== 'function') {
    window.togglePasswordVisibility = function(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn ? (btn.querySelector('i') || btn) : null;
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
            if (btn) btn.setAttribute('title', 'Hide password');
        } else {
            input.type = 'password';
            if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
            if (btn) btn.setAttribute('title', 'Show password');
        }
    };
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('clientLoginForm');
    const emailInput = document.getElementById('clientEmail');
    const pwdInput = document.getElementById('clientPassword');
    const submitBtn = document.getElementById('btnClientLoginSubmit');

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
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Signing In...';
                }
            }
        });
    }
});
</script>
</body>
</html>
