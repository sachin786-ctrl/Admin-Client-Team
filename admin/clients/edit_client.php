<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('clients_manage');
$pageTitle = 'Edit Client';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    setFlash('error', 'Client not found.');
    header('Location: list_clients.php');
    exit;
}

$errors = [];
$fieldErrors = [
    'name'         => '',
    'company_name' => '',
    'email'        => '',
    'phone'        => '',
    'password'     => '',
    'address'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token expired or invalid. Please reload the page.';
    }

    $client_code  = $client['client_code'];
    $name         = trim($_POST['name'] ?? '');
    $email        = trim(strtolower($_POST['email'] ?? ''));
    $company_name = trim($_POST['company_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $new_pwd      = trim($_POST['password'] ?? '');
    $status       = isset($_POST['status']) ? 1 : 0;

    // Validate Name
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

    // Validate Company Name
    if ($company_name !== '' && mb_strlen($company_name) > 150) {
        $msg = 'Company Name cannot exceed 150 characters.';
        $errors[] = $msg;
        $fieldErrors['company_name'] = $msg;
    }

    // Validate Email
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
            $checkEmail = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND id != ?");
            $checkEmail->execute([$email, $id]);
            if ($checkEmail->fetch()) {
                $msg = 'Email address "' . htmlspecialchars($email) . '" is already registered to another client.';
                $errors[] = $msg;
                $fieldErrors['email'] = $msg;
            }
        }
    }

    // Validate Phone
    if ($phone !== '') {
        $digitsOnly = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digitsOnly) < 7 || strlen($digitsOnly) > 15 || !preg_match('/^[+0-9\s\-()]{7,20}$/', $phone)) {
            $msg = 'Please enter a valid phone number (between 7 to 15 digits).';
            $errors[] = $msg;
            $fieldErrors['phone'] = $msg;
        }
    }

    // Validate Password (if changing)
    if ($new_pwd !== '') {
        if (strlen($new_pwd) < 6) {
            $msg = 'Client password must be at least 6 characters long.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        } elseif (strlen($new_pwd) > 64) {
            $msg = 'Client password cannot exceed 64 characters.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        }
    }

    // Validate Address
    if ($address !== '' && mb_strlen($address) > 500) {
        $msg = 'Address cannot exceed 500 characters.';
        $errors[] = $msg;
        $fieldErrors['address'] = $msg;
    }

    if (empty($errors)) {
        try {
            $clientData = [
                'id'           => $id,
                'client_code'  => $client_code,
                'name'         => $name,
                'company_name' => $company_name,
                'email'        => $email,
            ];

            if ($new_pwd !== '') {
                $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
                $update = $pdo->prepare("
                    UPDATE clients
                    SET name = ?, email = ?, company_name = ?, phone = ?, address = ?, password = ?, status = ?
                    WHERE id = ?
                ");
                $update->execute([$name, $email, $company_name, $phone, $address, $hashed, $status, $id]);

                // Dispatch password update notification email to client
                sendClientPasswordResetEmail($clientData, $new_pwd, $admin);
            } else {
                $update = $pdo->prepare("
                    UPDATE clients
                    SET name = ?, email = ?, company_name = ?, phone = ?, address = ?, status = ?
                    WHERE id = ?
                ");
                $update->execute([$name, $email, $company_name, $phone, $address, $status, $id]);
            }

            // 1. Dispatch status change email if status was toggled (Block / Unblock)
            $statusChanged = ((int)$client['status'] !== $status);
            if ($statusChanged) {
                sendAccountStatusEmail('client', $clientData, $status, $admin);
            }

            // 2. Dispatch profile modified email if details were updated (and password wasn't changed, nor status changed)
            if ($new_pwd === '' && !$statusChanged) {
                $changes = [];
                if ($client['name'] !== $name) {
                    $changes['Client Name'] = $name;
                }
                if ($client['email'] !== $email) {
                    $changes['Email Address'] = $email;
                }
                if (($client['company_name'] ?? '') !== $company_name && !empty($company_name)) {
                    $changes['Company'] = $company_name;
                }
                if (($client['phone'] ?? '') !== $phone && !empty($phone)) {
                    $changes['Phone Number'] = $phone;
                }
                if (!empty($changes)) {
                    sendAccountModifiedEmail('client', $clientData, $changes, $admin);
                }
            }

            // Notify Admin
            addNotification($pdo, 'admin', $admin['id'], 'Client Updated', "Client {$name} ({$client_code}) details were updated.", "/client-flow/admin/clients/view_client.php?id={$id}", 'info', 'bi-person-gear');

            // Notify Client
            addNotification($pdo, 'client', $id, 'Profile Updated by Admin', "Your account details were updated by your administrator.", '/client-flow/client/profile.php', 'info', 'bi-person-check');

            setFlash('success', "Client {$client_code} ({$name}) updated successfully.");
            header('Location: list_clients.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database Error: ' . $e->getMessage();
        }
    }
}

$csrf = generateCsrfToken();
$flash = getFlash();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Edit Client</h4>
                <p class="text-muted mb-0">Update client details and credentials.</p>
            </div>
            <a href="list_clients.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Clients
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex align-items-center align-content-center justify-content-center">
            <div class="card p-4 shadow-sm" style="max-width: 800px; width: 100%;">
                <!-- Client Avatar & Profile Preview for Admin/Team -->
                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 border mb-4">
                    <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars(!empty($client['profile_pic']) ? $client['profile_pic'] : 'default-avatar.png'); ?>" 
                         class="rounded-circle object-fit-cover border shadow-xs" 
                         style="width: 58px; height: 58px; min-width: 58px; min-height: 58px;"
                         alt="<?php echo htmlspecialchars($client['name']); ?>"
                         onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($client['name']); ?></h5>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                                <?php echo htmlspecialchars($client['client_code']); ?>
                            </span>
                            <?php if ((int)$client['status'] === 1): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($client['email']); ?>
                            <?php if (!empty($client['company_name'])): ?>
                                &bull; <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($client['company_name']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" novalidate id="editClientForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

                    <div class="row g-3">
                        <!-- Client Name -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="client_name" 
                                   class="form-control <?php echo !empty($fieldErrors['name']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? $client['name']); ?>" 
                                   placeholder="e.g. Rahul Sharma" required minlength="2" maxlength="100">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['name']) ? 'd-none' : ''; ?>" id="nameError">
                                <?php echo htmlspecialchars($fieldErrors['name'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Company Name -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Company Name</label>
                            <input type="text" name="company_name" id="client_company" 
                                   class="form-control <?php echo !empty($fieldErrors['company_name']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($_POST['company_name'] ?? ($client['company_name'] ?? '')); ?>" 
                                   placeholder="e.g. Apex Tech Solutions" maxlength="150">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['company_name']) ? 'd-none' : ''; ?>" id="companyError">
                                <?php echo htmlspecialchars($fieldErrors['company_name'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Email Address -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="client_email" 
                                   class="form-control <?php echo !empty($fieldErrors['email']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? $client['email']); ?>" 
                                   placeholder="client@example.com" required maxlength="150">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['email']) ? 'd-none' : ''; ?>" id="emailError">
                                <?php echo htmlspecialchars($fieldErrors['email'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Phone Number -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="text" name="phone" id="client_phone" 
                                   class="form-control <?php echo !empty($fieldErrors['phone']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ($client['phone'] ?? '')); ?>" 
                                   placeholder="+91 9876543210" maxlength="10">
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['phone']) ? 'd-none' : ''; ?>" id="phoneError">
                                <?php echo htmlspecialchars($fieldErrors['phone'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Client Code (Permanent) -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Client Code <span class="badge bg-light text-muted border ms-1" style="font-size: 10px;">Permanent</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="text" class="form-control font-monospace text-uppercase bg-light" value="<?php echo htmlspecialchars($client['client_code']); ?>" readonly disabled style="cursor: not-allowed; font-weight: 600;">
                            </div>
                            <input type="hidden" name="client_code" value="<?php echo htmlspecialchars($client['client_code']); ?>">
                        </div>

                        <!-- Reset Password -->
                        <div class="col-6">
                            <label class="form-label fw-semibold">Reset Password</label> 
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" id="client_password" 
                                       class="form-control <?php echo !empty($fieldErrors['password']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="Leave blank to keep current" minlength="6" maxlength="64">
                                <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('client_password', this)" title="Show Password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['password']) ? 'd-none' : ''; ?>" id="passwordError">
                                <?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Address -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address / Location</label>
                            <textarea name="address" id="client_address" 
                                      class="form-control <?php echo !empty($fieldErrors['address']) ? 'is-invalid' : ''; ?>" 
                                      rows="2" placeholder="Office / Business address..." maxlength="500"><?php echo htmlspecialchars($_POST['address'] ?? ($client['address'] ?? '')); ?></textarea>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['address']) ? 'd-none' : ''; ?>" id="addressError">
                                <?php echo htmlspecialchars($fieldErrors['address'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Active Account Switch -->
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="status" id="statusSwitch" <?php echo (isset($_POST['status']) ? !empty($_POST['status']) : $client['status'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="statusSwitch">Active Account</label>
                            </div>
                        </div>

                        <div class="col-12 mt-4 pt-2 border-top">
                            <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnUpdateClient">
                                <i class="bi bi-check-circle-fill me-1"></i> Update Client
                            </button>
                            <a href="list_clients.php" class="btn btn-outline-secondary ms-2">Cancel</a>
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

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('editClientForm');
    const nameInput = document.getElementById('client_name');
    const emailInput = document.getElementById('client_email');
    const phoneInput = document.getElementById('client_phone');
    const pwdInput = document.getElementById('client_password');

    const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}$/;

    function validateName() {
        const v = nameInput.value.trim();
        if (!v) {
            setFieldError('nameError', nameInput, 'Client Name is required.');
            return false;
        }
        if (v.length < 2) {
            setFieldError('nameError', nameInput, 'Client Name must be at least 2 characters.');
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

    function validateEmail() {
        const v = emailInput.value.trim().toLowerCase();
        if (!v) {
            setFieldError('emailError', emailInput, 'Email Address is required.');
            return false;
        }
        if (!emailRegex.test(v)) {
            setFieldError('emailError', emailInput, 'Please enter a valid email address (e.g. name@domain.com).');
            return false;
        }
        clearFieldValidation('emailError', emailInput, true);
        return true;
    }

    function validatePhone() {
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

    function validatePassword() {
        const v = pwdInput.value;
        if (!v) {
            clearFieldValidation('passwordError', pwdInput, false);
            return true;
        }
        if (v.length < 6) {
            setFieldError('passwordError', pwdInput, 'Client password must be at least 6 characters long.');
            return false;
        }
        clearFieldValidation('passwordError', pwdInput, true);
        return true;
    }

    if (nameInput) {
        nameInput.addEventListener('input', validateName);
        nameInput.addEventListener('blur', validateName);
    }
    if (emailInput) {
        emailInput.addEventListener('input', validateEmail);
        emailInput.addEventListener('blur', validateEmail);
    }
    if (phoneInput) {
        phoneInput.addEventListener('input', validatePhone);
        phoneInput.addEventListener('blur', validatePhone);
    }
    if (pwdInput) {
        pwdInput.addEventListener('input', validatePassword);
        pwdInput.addEventListener('blur', validatePassword);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            let valid = true;
            if (!validateName()) valid = false;
            if (!validateEmail()) valid = false;
            if (!validatePhone()) valid = false;
            if (!validatePassword()) valid = false;

            if (!valid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
