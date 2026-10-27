<?php
require_once __DIR__ . '/../includes/functions.php';
redirectIfLoggedIn();

if (empty($_SESSION['reset_email'])) {
    setFlash('error', 'Please request an OTP first.');
    header('Location: forgot_password.php');
    exit;
}

$pageTitle = 'Verify OTP';
$flash = getFlash();
$csrf = generateCsrfToken();
$maskedEmail = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $_SESSION['reset_email']);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-card-header">
            <i class="bi bi-shield-check"></i>
            <h4 class="mb-0 fw-bold">Verify OTP</h4>
            <p class="mb-0 small opacity-75">Enter the 6-digit code sent to <?php echo htmlspecialchars($maskedEmail); ?></p>
        </div>
        <div class="auth-card-body">

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> py-2">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <form action="check_otp.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="otp" id="otp_combined">

                <div class="d-flex justify-content-between otp-inputs mb-2 gap-4">
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric" autofocus>
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric">
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric">
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric">
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric">
                    <input type="text" maxlength="1" class="form-control" inputmode="numeric">
                </div>
                <p class="text-danger small mb-3 text-center" id="otpError"></p>

                <button type="submit" class="btn btn-primary w-100 text-white">
                    <i class="bi bi-check2-circle me-1"></i> Verify OTP
                </button>

                <div class="text-center mt-3">
                    <span class="small text-muted">Didn't receive the code?</span>
                    <a href="resend_otp.php" class="small text-decoration-none">Resend OTP</a>
                </div>
                <div class="text-center mt-1">
                    <a href="forgot_password.php" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Change Email</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = document.querySelectorAll('.otp-inputs input');
    const hidden = document.getElementById('otp_combined');
    const form = inputs[0].closest('form');

    function updateHidden() {
        hidden.value = Array.from(inputs).map(i => i.value).join('');
    }

    inputs.forEach((input, index) => {
        // Sirf digits allow karo, aur next box pe jao
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
            updateHidden();
        });

        // Backspace pe pichle box pe jao
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && index > 0) {
                inputs[index - 1].focus();
            }
            if (e.key === 'ArrowLeft' && index > 0) inputs[index - 1].focus();
            if (e.key === 'ArrowRight' && index < inputs.length - 1) inputs[index + 1].focus();
        });

        // Paste: poora OTP ek saath fill karo
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData)
                .getData('text').replace(/\D/g, '').slice(0, inputs.length);

            if (!pasted) return;

            pasted.split('').forEach((digit, i) => {
                inputs[i].value = digit;
            });

            // Last filled box pe focus
            inputs[Math.min(pasted.length, inputs.length) - 1].focus();
            updateHidden();
        });
    });

    // Submit se pehle final check
    form.addEventListener('submit', function (e) {
        updateHidden();
        const otpErr = document.getElementById('otpError');
        if (hidden.value.length !== inputs.length) {
            e.preventDefault();
            if (otpErr) {
                otpErr.textContent = 'Please enter the complete 6-digit OTP code.';
            }
            if (typeof window.showAppToast === 'function') {
                window.showAppToast('Please enter the complete 6-digit OTP.', 'warning', 'OTP Required');
            }
            const firstEmpty = Array.from(inputs).find(i => !i.value);
            if (firstEmpty) firstEmpty.focus();
        } else {
            if (otpErr) otpErr.textContent = '';
        }
    });

    inputs.forEach(input => {
        input.addEventListener('input', function() {
            const otpErr = document.getElementById('otpError');
            if (otpErr) otpErr.textContent = '';
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
