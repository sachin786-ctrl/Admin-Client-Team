<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('clients_manage');
$pageTitle = 'Add Client';

// Calculate suggested BNCL client code (e.g. BNCL001)
$stmtBncl = $pdo->query("SELECT client_code FROM clients WHERE client_code LIKE 'BNCL%' ORDER BY id DESC LIMIT 1");
$lastBncl = $stmtBncl->fetch();
if ($lastBncl && preg_match('/BNCL(\d+)/', $lastBncl['client_code'], $m)) {
    $nextNum = ((int) $m[1]) + 1;
} else {
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $nextNum = $cnt + 1;
}
$suggestedCode = 'BNCL' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

$errors = [];
$fieldErrors = [
    'name'         => '',
    'company_name' => '',
    'email'        => '',
    'phone'        => '',
    'password'     => '',
    'address'      => '',
];

$formData = [
    'name'         => '',
    'company_name' => '',
    'email'        => '',
    'phone'        => '',
    'address'      => '',
    'status'       => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token expired or invalid. Please reload the page and try again.';
    }

    $name         = trim($_POST['name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $email        = trim(strtolower($_POST['email'] ?? ''));
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $custom_pwd   = trim($_POST['password'] ?? '');
    $status       = isset($_POST['status']) ? 1 : 0;
    $client_code  = $suggestedCode;

    // Retain input for repopulation
    $formData = [
        'name'         => $name,
        'company_name' => $company_name,
        'email'        => $email,
        'phone'        => $phone,
        'address'      => $address,
        'status'       => $status,
    ];

    // 2. Validate Client Name
    if ($name === '') {
        $msg = 'Client Name is required.';
        $errors[] = $msg;
        $fieldErrors['name'] = $msg;
    } elseif (mb_strlen($name) < 2) {
        $msg = 'Client Name must be at least 2 characters long.';
        $errors[] = $msg;
        $fieldErrors['name'] = $msg;
    } elseif (mb_strlen($name) > 100) {
        $msg = 'Client Name cannot exceed 100 characters.';
        $errors[] = $msg;
        $fieldErrors['name'] = $msg;
    } elseif (!preg_match("/^[a-zA-Z\s.'-]+$/", $name)) {
        $msg = 'Client Name contains invalid characters. Only letters, spaces, dots, and hyphens are allowed.';
        $errors[] = $msg;
        $fieldErrors['name'] = $msg;
    }

    // 3. Validate Company Name (Optional)
    if ($company_name !== '' && mb_strlen($company_name) > 150) {
        $msg = 'Company Name cannot exceed 150 characters.';
        $errors[] = $msg;
        $fieldErrors['company_name'] = $msg;
    }

    // 4. Validate & Verify Email Address
    if ($email === '') {
        $msg = 'Email Address is required.';
        $errors[] = $msg;
        $fieldErrors['email'] = $msg;
    } else {
        $emailVerify = verifyEmailAddress($email, true);
        if (!$emailVerify['valid']) {
            $msg = $emailVerify['message'];
            $errors[] = $msg;
            $fieldErrors['email'] = $msg;
        } else {
            // Cannot be same as admin's email
            if (strcasecmp($email, $admin['email'] ?? '') === 0) {
                $msg = 'Client email cannot be the same as your administrator email.';
                $errors[] = $msg;
                $fieldErrors['email'] = $msg;
            } else {
                // Check duplicate email in clients
                $check = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $msg = 'A client with this email address already exists. Please use a unique email.';
                    $errors[] = $msg;
                    $fieldErrors['email'] = $msg;
                }
            }
        }
    }

    // 5. REQUIRE EMAIL OTP VERIFICATION
    $sessOtp = $_SESSION['email_verification']['client_create'] ?? null;
    if (empty($fieldErrors['email'])) {
        if (empty($sessOtp) || empty($sessOtp['verified']) || strcasecmp($sessOtp['email'] ?? '', $email) !== 0) {
            $msg = "Email verification required! Please verify '{$email}' using the 6-digit OTP before creating client.";
            $errors[] = $msg;
            $fieldErrors['email'] = $msg;
        }
    }

    // 6. Validate Phone Number (Optional)
    if ($phone !== '') {
        $digitsOnly = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digitsOnly) < 7 || strlen($digitsOnly) > 15 || !preg_match('/^[+0-9\s\-()]{7,20}$/', $phone)) {
            $msg = 'Please enter a valid phone number (between 7 to 15 digits).';
            $errors[] = $msg;
            $fieldErrors['phone'] = $msg;
        }
    }

    // 7. Validate Custom Password (Optional)
    if ($custom_pwd !== '') {
        if (strlen($custom_pwd) < 6) {
            $msg = 'Client password must be at least 6 characters long.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        } elseif (strlen($custom_pwd) > 64) {
            $msg = 'Client password cannot exceed 64 characters.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        }
    }

    // 8. Validate Address (Optional)
    if ($address !== '' && mb_strlen($address) > 500) {
        $msg = 'Address cannot exceed 500 characters.';
        $errors[] = $msg;
        $fieldErrors['address'] = $msg;
    }

    // If all validations pass, proceed to insert
    if (empty($errors)) {
        try {
            // Check duplicate client_code
            $checkCode = $pdo->prepare("SELECT id FROM clients WHERE client_code = ? LIMIT 1");
            $checkCode->execute([$client_code]);
            if ($checkCode->fetch()) {
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
                $client_code = 'BNCL' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
            }

            // Password handling
            $plain_password = $custom_pwd !== '' ? $custom_pwd : substr(bin2hex(random_bytes(4)), 0, 8);
            $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare("
                INSERT INTO clients 
                    (client_code, name, company_name, email, phone, address, service_type, password, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
            ");
            $insert->execute([
                $client_code,
                $name,
                $company_name,
                $email,
                $phone,
                $address,
                $hashed_password,
                $status,
                $admin['id']
            ]);
            $newClientId = (int) $pdo->lastInsertId();

            // Clear verification session on success
            unset($_SESSION['email_verification']['client_create']);

            // Dispatch Welcome Onboarding Email with credentials to Client & Admin audit copy
            $clientData = [
                'id'           => $newClientId,
                'client_code'  => $client_code,
                'name'         => $name,
                'company_name' => $company_name,
                'email'        => $email,
                'phone'        => $phone,
            ];
            $emailResult = sendClientWelcomeEmail($clientData, $plain_password, $admin);

            // Notify Admin
            addNotification($pdo, 'admin', $admin['id'], 'New Client Added', "Client {$name} ({$client_code}) was successfully created.", "/client-flow/admin/clients/view_client.php?id={$newClientId}", 'success', 'bi-person-plus-fill');

            // Notify Client
            addNotification($pdo, 'client', $newClientId, 'Welcome to Your Client Portal', "Your portal account has been activated. Welcome aboard!", '/client-flow/client/dashboard.php', 'success', 'bi-stars');

            $flashMsg = "Client created successfully! Client Code: {$client_code} (Email Verified with OTP)";
            if (!empty($emailResult['client_sent'])) {
                $flashMsg .= " | Welcome email dispatched to {$email}.";
            } else {
                $flashMsg .= " | Password: {$plain_password}";
            }

            setFlash('success', $flashMsg);
            header('Location: list_clients.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database Error: ' . $e->getMessage();
        }
    }
}

$csrf = generateCsrfToken();
$flash = getFlash();

// Check if email was already verified in this session
$alreadyVerified = false;
$verifiedEmail = '';
if (!empty($_SESSION['email_verification']['client_create']['verified'])) {
    $alreadyVerified = true;
    $verifiedEmail = $_SESSION['email_verification']['client_create']['email'] ?? '';
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="list_clients.php" class="text-decoration-none">Clients</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add Client</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0"><i class="bi bi-person-plus-fill text-primary me-2"></i>Add New Client</h4>
                <p class="text-muted mb-0">Create client profile with mandatory Email OTP verification.</p>
            </div>
            <a href="list_clients.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Clients
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following errors:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-center">
            <div class="card p-4 shadow-sm border" style="max-width: 820px; width: 100%;">
                <form method="POST" action="add_client.php" novalidate id="clientCreateForm">
                    <input type="hidden" name="csrf_token" id="csrfTokenField" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="email_otp_verified" id="email_otp_verified" value="<?php echo $alreadyVerified ? '1' : '0'; ?>">

                    <div class="row g-3">
                        <!-- Client Name -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                                <input type="text" name="name" id="client_name" 
                                       class="form-control <?php echo !empty($fieldErrors['name']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="e.g. Rahul Sharma" 
                                       value="<?php echo htmlspecialchars($formData['name']); ?>" 
                                       required minlength="2" maxlength="100">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['name']) ? 'd-none' : ''; ?>" id="nameError">
                                <?php echo htmlspecialchars($fieldErrors['name'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Company Name -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Company Name</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-building"></i></span>
                                <input type="text" name="company_name" id="client_company" 
                                       class="form-control <?php echo !empty($fieldErrors['company_name']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="e.g. Apex Tech Solutions" 
                                       value="<?php echo htmlspecialchars($formData['company_name']); ?>" 
                                       maxlength="150">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['company_name']) ? 'd-none' : ''; ?>" id="companyError">
                                <?php echo htmlspecialchars($fieldErrors['company_name'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Email Address with OTP Verification -->
                        <div class="col-12">
                            <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                                <span>
                                    Email Address <span class="text-danger">*</span>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1" id="verificationStatusBadge">
                                        <i class="bi bi-shield-exclamation me-1"></i>OTP Verification Required
                                    </span>
                                </span>
                                <span id="verifiedSuccessBadge" class="badge bg-success-subtle text-success border border-success-subtle d-none">
                                    <i class="bi bi-check-circle-fill me-1"></i>Email Verified with OTP
                                </span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="client_email" 
                                       class="form-control <?php echo !empty($fieldErrors['email']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="client@example.com" 
                                       value="<?php echo htmlspecialchars($formData['email']); ?>" 
                                       required maxlength="150">
                                <button type="button" class="btn btn-outline-primary fw-semibold" id="btnSendOtp">
                                    <i class="bi bi-send me-1"></i>Send OTP
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['email']) ? 'd-none' : ''; ?>" id="emailError">
                                <?php echo htmlspecialchars($fieldErrors['email'] ?? ''); ?>
                            </p>
                         

                            <!-- Inline OTP Verification Box -->
                            <div id="otpBox" class="p-3 mt-3 rounded-3 border bg-light d-none shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-dark small">
                                        <i class="bi bi-shield-lock-fill text-primary me-1"></i> Enter Verification OTP
                                    </strong>
                                    <span class="text-muted small" id="otpTimerText">
                                        Resend in <strong id="otpSeconds" class="text-primary">60</strong>s
                                    </span>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none" id="btnResendOtp">
                                        Resend OTP
                                    </button>
                                </div>
                                <p class="small text-muted mb-2">
                                    <strong id="otpTargetDisplay" class="text-dark"></strong>
                                </p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-6">
                                        <input type="text" id="emailOtpInput" class="form-control font-monospace text-center fs-5 fw-bold" 
                                               maxlength="6" placeholder="• • • • • •" 
                                               style="letter-spacing: 6px;">
                                    </div>
                                    <div class="col-sm-6">
                                        <button type="button" class="btn btn-success fw-bold w-100" id="btnVerifyOtp">
                                            <i class="bi bi-check2-circle me-1"></i> Verify OTP
                                        </button>
                                    </div>
                                </div>
                                <p class="text-danger small mt-2 mb-0 d-none" id="otpBoxError"></p>
                                <div id="otpStatusMsg" class="mt-2 small fw-semibold"></div>
                            </div>
                        </div>

                        <!-- Phone Number -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="phone" id="client_phone" 
                                       class="form-control <?php echo !empty($fieldErrors['phone']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="+91 98765 43210" 
                                       value="<?php echo htmlspecialchars($formData['phone']); ?>" 
                                       maxlength="10">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['phone']) ? 'd-none' : ''; ?>" id="phoneError">
                                <?php echo htmlspecialchars($fieldErrors['phone'] ?? ''); ?>
                            </p>
                            <small class="text-muted d-block mt-1" style="font-size: 12px;">Enter the WhatsApp Number</small>
                        </div>

                        <!-- Client Code (Auto-assigned) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Client Code <span class="badge bg-light text-muted border ms-1" style="font-size: 10px;">Auto-Assigned</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="text" class="form-control font-monospace text-uppercase bg-light" 
                                       value="<?php echo htmlspecialchars($suggestedCode); ?>" 
                                       readonly disabled style="cursor: not-allowed; font-weight: 600;">
                            </div>
                            <input type="hidden" name="client_code" value="<?php echo htmlspecialchars($suggestedCode); ?>">
                        </div>

                        <!-- Client Password -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Client Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" id="client_password" 
                                       class="form-control <?php echo !empty($fieldErrors['password']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="Auto-generate if blank (Min 6 chars)" 
                                       minlength="6" maxlength="64">
                                <button type="button" class="btn btn-outline-secondary toggle-password bg-white" 
                                        style="border-color:#dee2e6; cursor:pointer;" 
                                        onclick="togglePasswordVisibility('client_password', this)" 
                                        title="Show Password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['password']) ? 'd-none' : ''; ?>" id="passwordError">
                                <?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 12px;" onclick="generateClientPassword()">
                                    <i class="bi bi-magic me-1"></i>Generate
                                </button>
                            </div>
                        </div>

                        <!-- Address / Location -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"> Address / Location</label>
                            <textarea name="address" id="client_address" 
                                      class="form-control <?php echo !empty($fieldErrors['address']) ? 'is-invalid' : ''; ?>" 
                                      rows="1" maxlength="500" 
                                      placeholder="Office / Business address..."><?php echo htmlspecialchars($formData['address']); ?></textarea>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['address']) ? 'd-none' : ''; ?>" id="addressError">
                                <?php echo htmlspecialchars($fieldErrors['address'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Status Switch -->
                        <div class="col-12">
                            <div class="form-check form-switch p-3 bg-light rounded border">
                                <input class="form-check-input ms-0 me-3" type="checkbox" name="status" id="statusSwitch" <?php echo !empty($formData['status']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-dark" for="statusSwitch">
                                    Active Account
                                </label>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="col-12 mt-4 pt-3 border-top d-flex align-items-center gap-2">
                            <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnSubmitClient">
                                <i class="bi bi-check-circle-fill me-1"></i>Create Client
                            </button>
                            <a href="list_clients.php" class="btn btn-outline-secondary px-3">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPassword ? 'bi bi-eye' : 'bi bi-eye-slash';
    }
}

function generateClientPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    let pwd = '';
    for (let i = 0; i < 10; i++) {
        pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('client_password');
    if (input) {
        input.type = 'text';
        input.value = pwd;
        const toggleBtn = input.parentElement.querySelector('.toggle-password i');
        if (toggleBtn) toggleBtn.className = 'bi bi-eye';
        clearFieldValidation('passwordError', input);
    }
}

function setFieldError(errorId, inputEl, message) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }
    if (inputEl) {
        inputEl.classList.remove('is-valid');
        inputEl.classList.add('is-invalid');
    }
}

function clearFieldValidation(errorId, inputEl, markValid = true) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.classList.add('d-none');
    }
    if (inputEl) {
        inputEl.classList.remove('is-invalid');
        if (markValid) {
            inputEl.classList.add('is-valid');
        } else {
            inputEl.classList.remove('is-valid');
        }
    }
}

// Interactive Real-Time Validation & Email OTP Verification
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('clientCreateForm');
    const nameInput = document.getElementById('client_name');
    const emailInput = document.getElementById('client_email');
    const phoneInput = document.getElementById('client_phone');
    const pwdInput = document.getElementById('client_password');
    const companyInput = document.getElementById('client_company');
    const addressInput = document.getElementById('client_address');
    const emailHint = document.getElementById('emailHint');
    const csrfToken = document.getElementById('csrfTokenField').value;

    const btnSendOtp = document.getElementById('btnSendOtp');
    const btnResendOtp = document.getElementById('btnResendOtp');
    const btnVerifyOtp = document.getElementById('btnVerifyOtp');
    const otpBox = document.getElementById('otpBox');
    const emailOtpInput = document.getElementById('emailOtpInput');
    const otpTargetDisplay = document.getElementById('otpTargetDisplay');
    const otpStatusMsg = document.getElementById('otpStatusMsg');
    const otpBoxError = document.getElementById('otpBoxError');
    const otpTimerText = document.getElementById('otpTimerText');
    const otpSeconds = document.getElementById('otpSeconds');
    const verificationStatusBadge = document.getElementById('verificationStatusBadge');
    const verifiedSuccessBadge = document.getElementById('verifiedSuccessBadge');
    const emailOtpVerifiedHidden = document.getElementById('email_otp_verified');

    let countdownTimer = null;
    let verifiedEmailAddress = '<?php echo $alreadyVerified ? addslashes($verifiedEmail) : ""; ?>';

    if (emailOtpVerifiedHidden.value === '1' && verifiedEmailAddress) {
        setVerifiedState(verifiedEmailAddress);
    }

    const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}$/;
    const commonTypos = {
        'gmai.com': 'gmail.com',
        'gamil.com': 'gmail.com',
        'gmaill.com': 'gmail.com',
        'gmail.co': 'gmail.com',
        'gmail.con': 'gmail.com',
        'yaho.com': 'yahoo.com',
        'hotmial.com': 'hotmail.com',
        'outlok.com': 'outlook.com'
    };

    function validateNameField() {
        const v = nameInput.value.trim();
        if (!v) {
            setFieldError('nameError', nameInput, 'Client Name is required.');
            return false;
        }
        if (v.length < 2) {
            setFieldError('nameError', nameInput, 'Client Name must be at least 2 characters long.');
            return false;
        }
        if (v.length > 100) {
            setFieldError('nameError', nameInput, 'Client Name cannot exceed 100 characters.');
            return false;
        }
        if (!/^[a-zA-Z\s.'-]+$/.test(v)) {
            setFieldError('nameError', nameInput, 'Client Name can only contain letters, spaces, dots, and hyphens.');
            return false;
        }
        clearFieldValidation('nameError', nameInput, true);
        return true;
    }

    function validateEmailSyntax() {
        const val = emailInput.value.trim().toLowerCase();
        if (!val) {
            setFieldError('emailError', emailInput, 'Email Address is required.');
            return false;
        }
        if (!emailRegex.test(val)) {
            setFieldError('emailError', emailInput, 'Please enter a valid email format (e.g. name@domain.com).');
            return false;
        }
        const domain = val.split('@')[1];
        if (commonTypos[domain]) {
            setFieldError('emailError', emailInput, `Typo detected in domain? Did you mean @${commonTypos[domain]}?`);
            return false;
        }

        clearFieldValidation('emailError', emailInput, false);
        return true;
    }

    function validatePhoneField() {
        const v = phoneInput.value.trim();
        if (!v) {
            clearFieldValidation('phoneError', phoneInput, false);
            return true;
        }
        const digits = v.replace(/[^0-9]/g, '');
        if (digits.length < 7 || digits.length > 15 || !/^[+0-9\s\-()]{7,20}$/.test(v)) {
            setFieldError('phoneError', phoneInput, 'Please enter a valid phone number (between 7 to 15 digits).');
            return false;
        }
        clearFieldValidation('phoneError', phoneInput, true);
        return true;
    }

    function validatePasswordField() {
        const v = pwdInput.value;
        if (!v) {
            clearFieldValidation('passwordError', pwdInput, false);
            return true;
        }
        if (v.length < 6) {
            setFieldError('passwordError', pwdInput, 'Client password must be at least 6 characters long.');
            return false;
        }
        if (v.length > 64) {
            setFieldError('passwordError', pwdInput, 'Client password cannot exceed 64 characters.');
            return false;
        }
        clearFieldValidation('passwordError', pwdInput, true);
        return true;
    }

    // Reset verification state if email changes
    emailInput.addEventListener('input', function () {
        validateEmailSyntax();
        const currentEmail = emailInput.value.trim().toLowerCase();
        if (currentEmail !== verifiedEmailAddress) {
            emailOtpVerifiedHidden.value = '0';
            verificationStatusBadge.classList.remove('d-none');
            verifiedSuccessBadge.classList.add('d-none');
            btnSendOtp.classList.remove('d-none');
        }
    });

    if (nameInput) {
        nameInput.addEventListener('input', validateNameField);
        nameInput.addEventListener('blur', validateNameField);
    }
    if (emailInput) {
        emailInput.addEventListener('blur', validateEmailSyntax);
    }
    if (phoneInput) {
        phoneInput.addEventListener('input', validatePhoneField);
        phoneInput.addEventListener('blur', validatePhoneField);
    }
    if (pwdInput) {
        pwdInput.addEventListener('input', validatePasswordField);
        pwdInput.addEventListener('blur', validatePasswordField);
    }

    function setVerifiedState(email) {
        emailOtpVerifiedHidden.value = '1';
        verifiedEmailAddress = email;
        verificationStatusBadge.classList.add('d-none');
        verifiedSuccessBadge.classList.remove('d-none');
        otpBox.classList.add('d-none');
        btnSendOtp.classList.add('d-none');
        clearFieldValidation('emailError', emailInput, true);
        emailHint.innerHTML = `<span class="text-success fw-bold"><i class="bi bi-shield-check me-1"></i>Verified: ${email} confirmed with OTP.</span>`;
    }

    function startTimer(duration) {
        let sec = duration;
        clearInterval(countdownTimer);
        otpTimerText.classList.remove('d-none');
        btnResendOtp.classList.add('d-none');
        otpSeconds.textContent = sec;

        countdownTimer = setInterval(() => {
            sec--;
            otpSeconds.textContent = sec;
            if (sec <= 0) {
                clearInterval(countdownTimer);
                otpTimerText.classList.add('d-none');
                btnResendOtp.classList.remove('d-none');
            }
        }, 1000);
    }

    async function sendOtpAction() {
        if (!validateEmailSyntax()) {
            emailInput.focus();
            return;
        }

        const email = emailInput.value.trim().toLowerCase();
        const name = nameInput.value.trim() || 'Client';

        btnSendOtp.disabled = true;
        btnSendOtp.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
        otpStatusMsg.innerHTML = '<span class="text-primary"><i class="bi bi-hourglass-split me-1"></i>Dispatching verification code...</span>';
        if (otpBoxError) otpBoxError.classList.add('d-none');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'send');
            formData.append('purpose', 'client_create');
            formData.append('email', email);
            formData.append('name', name);

            const res = await fetch('/client-flow/admin/ajax/email_otp.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            btnSendOtp.disabled = false;
            btnSendOtp.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Resend OTP';

            if (data.success) {
                otpBox.classList.remove('d-none');
                otpTargetDisplay.textContent = email;
                otpStatusMsg.innerHTML = `<span class="text-success"><i class="bi bi-check2-circle me-1"></i>${data.message}</span>`;
                emailOtpInput.value = '';
                emailOtpInput.focus();
                startTimer(data.timeout || 60);
                clearFieldValidation('emailError', emailInput, false);
            } else {
                otpBox.classList.remove('d-none');
                otpStatusMsg.innerHTML = '';
                if (otpBoxError) {
                    otpBoxError.textContent = data.message;
                    otpBoxError.classList.remove('d-none');
                }
                setFieldError('emailError', emailInput, data.message);
            }
        } catch (err) {
            btnSendOtp.disabled = false;
            btnSendOtp.innerHTML = '<i class="bi bi-send me-1"></i>Send OTP';
            otpStatusMsg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Network error while requesting OTP.</span>';
        }
    }

    btnSendOtp.addEventListener('click', sendOtpAction);
    btnResendOtp.addEventListener('click', sendOtpAction);

    btnVerifyOtp.addEventListener('click', async function () {
        const otp = emailOtpInput.value.trim();
        const email = emailInput.value.trim().toLowerCase();

        if (otp.length !== 6 || !/^\d{6}$/.test(otp)) {
            if (otpBoxError) {
                otpBoxError.textContent = 'Please enter a complete 6-digit numerical OTP.';
                otpBoxError.classList.remove('d-none');
            }
            emailOtpInput.focus();
            return;
        }

        btnVerifyOtp.disabled = true;
        btnVerifyOtp.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verifying...';
        if (otpBoxError) otpBoxError.classList.add('d-none');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'verify');
            formData.append('purpose', 'client_create');
            formData.append('email', email);
            formData.append('otp', otp);

            const res = await fetch('/client-flow/admin/ajax/email_otp.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            btnVerifyOtp.disabled = false;
            btnVerifyOtp.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Verify OTP';

            if (data.success) {
                setVerifiedState(email);
            } else {
                if (otpBoxError) {
                    otpBoxError.textContent = data.message;
                    otpBoxError.classList.remove('d-none');
                }
                emailOtpInput.focus();
            }
        } catch (err) {
            btnVerifyOtp.disabled = false;
            btnVerifyOtp.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Verify OTP';
            if (otpBoxError) {
                otpBoxError.textContent = 'Network error during verification.';
                otpBoxError.classList.remove('d-none');
            }
        }
    });

    // Form submit guard
    if (form) {
        form.addEventListener('submit', function (e) {
            let isValid = true;

            if (!validateNameField()) {
                isValid = false;
            }
            if (!validateEmailSyntax()) {
                isValid = false;
            }

            // Must be OTP verified
            if (emailOtpVerifiedHidden.value !== '1') {
                e.preventDefault();
                e.stopPropagation();
                otpBox.classList.remove('d-none');
                setFieldError('emailError', emailInput, 'Email verification required! Please click Send OTP and verify code before creating client.');
                otpBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (!emailOtpInput.value) {
                    sendOtpAction();
                }
                return;
            }

            if (!validatePhoneField()) {
                isValid = false;
            }
            if (!validatePasswordField()) {
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else {
                const btn = document.getElementById('btnSubmitClient');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Creating Client...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>