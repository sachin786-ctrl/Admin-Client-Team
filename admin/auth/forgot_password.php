<?php
require_once __DIR__ . '/../includes/functions.php';

redirectIfLoggedIn();

$pageTitle = 'Forgot Password';
$flash = getFlash();
$csrf = generateCsrfToken();

$defaultEmail = '';
$emailHint    = '';

$stmt = $pdo->prepare("SELECT email FROM admin ORDER BY id ASC LIMIT 1");
$stmt->execute();

$row = $stmt->fetch();
if ($row) {
    $defaultEmail = $row['email'];
    $emailHint    = maskEmail($row['email']);
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="auth-wrapper">
    <div class="auth-card">

        <div class="auth-card-header">
            <i class="bi bi-envelope-paper-fill"></i>
            <h4 class="mb-0 fw-bold">Forgot Password</h4>
            <p class="mb-0 small opacity-75">
                Enter your registered administrator email to receive a reset OTP
            </p>
        </div>

        <div class="auth-card-body">

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : ($flash['type'] === 'warning' ? 'warning' : 'success'); ?> py-2">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

                <form action="send_otp.php" method="POST" id="forgotPasswordForm" novalidate>

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo $csrf; ?>"
                >

                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Registered Email Address <span class="text-danger">*</span>
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>

                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control"
                            placeholder="e.g. admin@agency.com"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? $defaultEmail); ?>"
                            required
                            autofocus
                        >

                    </div>
                    <p class="text-danger small mt-1 mb-0" id="emailError"></p>

                    <?php if (!empty($emailHint)): ?>
                        <div class="form-text small text-muted mt-2">
                            <i class="bi bi-shield-check text-success me-1"></i>Registered Admin Account: <code><?php echo htmlspecialchars($emailHint); ?></code>
                        </div>
                    <?php endif; ?>

                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100 text-white"
                >
                    <i class="bi bi-send me-1"></i>
                    Send OTP
                </button>

                <div class="text-center mt-3">
                    <a href="../login.php" class="small text-decoration-none">
                        <i class="bi bi-arrow-left"></i>
                        Back to Login
                    </a>
                </div>

            </form>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('forgotPasswordForm');
    const emailInput = document.getElementById('email');
    const emailErr = document.getElementById('emailError');

    function validateEmail() {
        const val = emailInput.value.trim();
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!val) {
            emailErr.textContent = 'Email Address is required.';
            emailInput.classList.add('is-invalid');
            return false;
        } else if (!re.test(val)) {
            emailErr.textContent = 'Please enter a valid email address.';
            emailInput.classList.add('is-invalid');
            return false;
        }
        emailErr.textContent = '';
        emailInput.classList.remove('is-invalid');
        return true;
    }

    if (emailInput) {
        emailInput.addEventListener('input', validateEmail);
        emailInput.addEventListener('blur', validateEmail);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateEmail()) {
                e.preventDefault();
                emailInput.focus();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>