<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('team_manage');

$pageTitle = 'Add Team Member';
$availablePermissions = getAvailablePermissions();
$errors = [];
$fieldErrors = [
    'name'        => '',
    'email'       => '',
    'password'    => '',
    'permissions' => '',
];

$name = '';
$email = '';
$status = 1;
$selectedPermissions = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token or session expired. Please reload and try again.';
    } else {
        $name                = trim($_POST['name'] ?? '');
        $email               = trim(strtolower($_POST['email'] ?? ''));
        $password            = $_POST['password'] ?? '';
        $status              = isset($_POST['status']) ? (int)$_POST['status'] : 1;
        $selectedPermissions = $_POST['permissions'] ?? [];
        if (!is_array($selectedPermissions)) {
            $selectedPermissions = [];
        }

        // 1. Validate Full Name
        if ($name === '') {
            $msg = 'Full name is required.';
            $errors[] = $msg;
            $fieldErrors['name'] = $msg;
        } elseif (mb_strlen($name) < 2) {
            $msg = 'Full name must be at least 2 characters long.';
            $errors[] = $msg;
            $fieldErrors['name'] = $msg;
        } elseif (mb_strlen($name) > 100) {
            $msg = 'Full name cannot exceed 100 characters.';
            $errors[] = $msg;
            $fieldErrors['name'] = $msg;
        } elseif (!preg_match("/^[a-zA-Z\s.'-]+$/", $name)) {
            $msg = 'Full name contains invalid characters. Only letters, spaces, dots, and hyphens are allowed.';
            $errors[] = $msg;
            $fieldErrors['name'] = $msg;
        }

        // 2. Validate & Verify Email Address
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
                // Check duplicate in admin table
                $stmt = $pdo->prepare("SELECT id FROM admin WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $msg = 'An account with this email address already exists in team staff or administrators.';
                    $errors[] = $msg;
                    $fieldErrors['email'] = $msg;
                }

                // Check duplicate in clients table
                $stmtCl = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1");
                $stmtCl->execute([$email]);
                if ($stmtCl->fetch()) {
                    $msg = 'This email is already registered as a client account. Please use a different staff email.';
                    $errors[] = $msg;
                    $fieldErrors['email'] = $msg;
                }
            }
        }

        // 3. REQUIRE EMAIL OTP VERIFICATION
        $sessOtp = $_SESSION['email_verification']['team_create'] ?? null;
        if (empty($fieldErrors['email'])) {
            if (empty($sessOtp) || empty($sessOtp['verified']) || strcasecmp($sessOtp['email'] ?? '', $email) !== 0) {
                $msg = "Email verification required! Please verify '{$email}' using the 6-digit OTP before adding the team member.";
                $errors[] = $msg;
                $fieldErrors['email'] = $msg;
            }
        }

        // 4. Validate Password
        if (strlen($password) < 6) {
            $msg = 'Password must be at least 6 characters long.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        } elseif (strlen($password) > 64) {
            $msg = 'Password cannot exceed 64 characters.';
            $errors[] = $msg;
            $fieldErrors['password'] = $msg;
        }

        // 5. Validate Permissions
        if (empty($selectedPermissions)) {
            $msg = 'Please select at least one module permission for this team member.';
            $errors[] = $msg;
            $fieldErrors['permissions'] = $msg;
        }

        // If no errors, insert into admin table
        if (empty($errors)) {
            try {
                $profilePicName = 'default-avatar.png';
                if (!empty($_FILES['profile_pic']['name'])) {
                    $uploadRes = uploadImage('profile_pic', 'profile');
                    if ($uploadRes['success']) {
                        $profilePicName = $uploadRes['fileName'];
                    } else {
                        $errors[] = $uploadRes['message'];
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Profile picture upload failed: ' . $e->getMessage();
            }
        }

        if (empty($errors)) {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $permsJson = json_encode(array_values($selectedPermissions));

                $insertStmt = $pdo->prepare("
                    INSERT INTO admin (name, email, password, role, permissions, status, profile_pic, cover_pic, created_at)
                    VALUES (?, ?, ?, 'staff', ?, ?, ?, 'default-cover.jpg', NOW())
                ");
                $insertStmt->execute([$name, $email, $hashedPassword, $permsJson, $status, $profilePicName]);
                $newTeamId = (int)$pdo->lastInsertId();

                // Clear verification session on success
                unset($_SESSION['email_verification']['team_create']);

                // Send Onboarding Email with Credentials & Permissions to Staff Member & Admin audit copy
                $teamMemberData = [
                    'id'    => $newTeamId,
                    'name'  => $name,
                    'email' => $email,
                    'role'  => 'staff',
                ];
                $emailResult = sendTeamWelcomeEmail($teamMemberData, $password, $admin, $selectedPermissions);

                $flashMsg = 'Team member ' . $name . ' added successfully (Email Verified with OTP).';
                if (!empty($emailResult['member_sent'])) {
                    $flashMsg .= ' Welcome credentials sent to ' . htmlspecialchars($email) . '.';
                }

                setFlash('success', $flashMsg);
                header('Location: list_team.php');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Database Error: ' . $e->getMessage();
            }
        }
    }
}
$csrf = generateCsrfToken();

// Check if email was already verified in this session
$alreadyVerifiedTeam = false;
$verifiedTeamEmail = '';
if (!empty($_SESSION['email_verification']['team_create']['verified'])) {
    $alreadyVerifiedTeam = true;
    $verifiedTeamEmail = $_SESSION['email_verification']['team_create']['email'] ?? '';
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Breadcrumb / Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="list_team.php" class="text-decoration-none">Team</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add Member</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0"><i class="bi bi-person-plus-fill text-primary me-2"></i>Add New Team Member</h4>
                <p class="text-muted mb-0">Create staff accounts with granular permissions.</p>
            </div>
            <div>
                <a href="list_team.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Team
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="add_team.php" novalidate id="teamForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" id="csrfTokenField" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="email_otp_verified" id="email_otp_verified" value="<?php echo $alreadyVerifiedTeam ? '1' : '0'; ?>">

            <div class="row g-4">
                <!-- Left Column: Member Details -->
                <div class="col-12 col-lg-4">
                    <div class="card shadow-sm p-4 h-100 border">
                        <h5 class="fw-bold mb-3 pb-2 border-bottom text-dark">
                            <i class="bi bi-person-badge text-primary me-2"></i>Account Details
                        </h5>

                        <!-- Full Name -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                                <input type="text" name="name" id="team_name" 
                                       class="form-control <?php echo !empty($fieldErrors['name']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="e.g. Rahul Sharma" 
                                       value="<?php echo htmlspecialchars($name); ?>" 
                                       required minlength="2" maxlength="100">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['name']) ? 'd-none' : ''; ?>" id="nameError">
                                <?php echo htmlspecialchars($fieldErrors['name'] ?? ''); ?>
                            </p>
                        </div>

                        <!-- Email with OTP Verification -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                                <span>
                                    Email Address <span class="text-danger">*</span>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1" id="teamVerificationStatusBadge">
                                        <i class="bi bi-shield-exclamation me-1"></i>OTP Required
                                    </span>
                                </span>
                                <span id="teamVerifiedSuccessBadge" class="badge bg-success-subtle text-success border border-success-subtle d-none">
                                    <i class="bi bi-check-circle-fill me-1"></i>Verified with OTP
                                </span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="team_email" 
                                       class="form-control <?php echo !empty($fieldErrors['email']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="staff@company.com" 
                                       value="<?php echo htmlspecialchars($email); ?>" 
                                       required maxlength="150">
                                <button type="button" class="btn btn-outline-primary fw-semibold" id="btnSendTeamOtp">
                                    <i class="bi bi-send me-1"></i>Send OTP
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['email']) ? 'd-none' : ''; ?>" id="emailError">
                                <?php echo htmlspecialchars($fieldErrors['email'] ?? ''); ?>
                            </p>

                            <!-- Inline OTP Verification Box -->
                            <div id="teamOtpBox" class="p-3 mt-3 rounded-3 border bg-light d-none shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-dark small">
                                        <i class="bi bi-shield-lock-fill text-primary me-1"></i> Enter Verification OTP
                                    </strong>
                                    <span class="text-muted small" id="teamOtpTimerText">
                                        Resend in <strong id="teamOtpSeconds" class="text-primary">60</strong>s
                                    </span>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none" id="btnResendTeamOtp">
                                        Resend OTP
                                    </button>
                                </div>
                                <p class="small text-muted mb-2">
                                   <strong id="teamOtpTargetDisplay" class="text-dark"></strong> 
                                </p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-6">
                                        <input type="text" id="teamEmailOtpInput" class="form-control font-monospace text-center fs-5 fw-bold" 
                                               maxlength="6" placeholder="• • • • • •" 
                                               style="letter-spacing: 6px;">
                                    </div>
                                    <div class="col-sm-6">
                                        <button type="button" class="btn btn-success fw-bold w-100" id="btnVerifyTeamOtp">
                                            <i class="bi bi-check2-circle me-1"></i> Verify OTP
                                        </button>
                                    </div>
                                </div>
                                <p class="text-danger small mt-2 mb-0 d-none" id="teamOtpBoxError"></p>
                                <div id="teamOtpStatusMsg" class="mt-2 small fw-semibold"></div>
                            </div>
                        </div>

                        <!-- Login Password -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Login Password <span class="text-danger">*</span></label>
                            <div class="input-group mb-1">
                                <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" id="staffPassword" 
                                       class="form-control <?php echo !empty($fieldErrors['password']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="Minimum 6 characters" 
                                       required minlength="6" maxlength="64">
                                <button type="button" class="btn btn-outline-secondary toggle-password bg-white" 
                                        style="border-color:#dee2e6; cursor:pointer;" 
                                        onclick="togglePass('staffPassword', this)" 
                                        title="Show Password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['password']) ? 'd-none' : ''; ?>" id="passwordError">
                                <?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?>
                            </p>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="generateRandomPass()">
                                    <i class="bi bi-magic me-1"></i> Generate Password
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Account Status</label>
                            <select name="status" class="form-select">
                                <option value="1" <?php echo (int)$status === 1 ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo (int)$status === 0 ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Profile Picture (Optional)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-image"></i></span>
                                <input type="file" name="profile_pic" class="form-control" accept="image/jpeg,image/png,image/webp">
                            </div>
                            <div class="form-text">JPG, PNG or WEBP (Max 5MB).</div>
                        </div>

                        <div class="p-3 bg-light rounded-3 mt-auto border">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">Role: Staff</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Permissions -->
                <div class="col-12 col-lg-8">
                    <div class="card shadow-sm p-4 border">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">
                                    <i class="bi bi-shield-check text-primary me-2"></i>Module Permissions<span class="text-danger">*</span>
                                </h5>
                                <p class="text-muted small mb-0">Select granular access or apply a role preset below.</p>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="applyPreset('all')">
                                    <i class="bi bi-check-all me-1"></i> Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="applyPreset('pm')">
                                    <i class="bi bi-briefcase-fill me-1"></i> Project Manager
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="applyPreset('finance')">
                                    <i class="bi bi-cash-stack me-1"></i> Accountant
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" onclick="applyPreset('viewer')">
                                    <i class="bi bi-eye-fill me-1"></i> Read-Only
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="applyPreset('clear')">
                                    <i class="bi bi-x-circle me-1"></i> Clear
                                </button>
                            </div>
                        </div>

                        <!-- Permissions Error Notice -->
                        <p class="text-danger small mt-1 mb-3 <?php echo empty($fieldErrors['permissions']) ? 'd-none' : ''; ?>" id="permTopNotice">
                            <i class="bi bi-exclamation-circle-fill me-1"></i> <?php echo htmlspecialchars($fieldErrors['permissions'] ?: 'Please select at least one permission module before creating account.'); ?>
                        </p>

                        <!-- Categorized Permissions Grid -->
                        <div class="row g-3">
                            <?php foreach ($availablePermissions as $category => $perms): ?>
                                <div class="col-12 col-md-6 category-group" data-cat="<?php echo htmlspecialchars($category); ?>">
                                    <div class="border rounded-3 p-3 bg-light h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                                            <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.5px;">
                                                <?php if ($category === 'Clients'): ?>
                                                    <i class="bi bi-people-fill text-primary me-1"></i>
                                                <?php elseif ($category === 'Projects'): ?>
                                                    <i class="bi bi-folder-fill text-warning me-1"></i>
                                                <?php elseif ($category === 'Payments'): ?>
                                                    <i class="bi bi-credit-card-fill text-success me-1"></i>
                                                <?php elseif ($category === 'Notifications'): ?>
                                                    <i class="bi bi-bell-fill text-info me-1"></i>
                                                <?php elseif ($category === 'Team' || $category === 'Team & Staff'): ?>
                                                    <i class="bi bi-shield-lock-fill text-purple me-1"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-gear-fill text-secondary me-1"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($category); ?>
                                            </span>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-muted" onclick="toggleCategory('<?php echo htmlspecialchars($category); ?>')">
                                                Toggle
                                            </button>
                                        </div>
                                        <div class="d-flex flex-column gap-2 mt-2">
                                            <?php foreach ($perms as $permKey => $permLabel): ?>
                                                <?php $isChecked = in_array($permKey, $selectedPermissions, true); ?>
                                                <div class="form-check">
                                                    <input class="form-check-input perm-checkbox" 
                                                           type="checkbox" 
                                                           name="permissions[]" 
                                                           value="<?php echo htmlspecialchars($permKey); ?>" 
                                                           id="perm_<?php echo htmlspecialchars($permKey); ?>"
                                                           <?php echo $isChecked ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="perm_<?php echo htmlspecialchars($permKey); ?>" style="cursor: pointer;">
                                                        <span class="fw-semibold text-dark d-block"><?php echo htmlspecialchars($permLabel); ?></span>
                                                        <span class="text-muted" style="font-size: 11px;">
                                                            Code: <code><?php echo htmlspecialchars($permKey); ?></code>
                                                        </span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="text-danger small mt-2 mb-0 <?php echo empty($fieldErrors['permissions']) ? 'd-none' : ''; ?>" id="permErrorAlert">
                            <?php echo htmlspecialchars($fieldErrors['permissions'] ?? ''); ?>
                        </p>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-end align-items-center gap-2 mt-4 pt-3 border-top">
                            <a href="list_team.php" class="btn btn-outline-secondary px-3">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnSubmitTeam">
                                <i class="bi bi-check-circle-fill me-1"></i> Create Member
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

    </div>
</div>

<script>
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPassword ? 'bi bi-eye' : 'bi bi-eye-slash';
    }
}

function setTeamFieldError(errorId, inputEl, message) {
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

function clearTeamFieldValidation(errorId, inputEl, markValid = true) {
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

function generateRandomPass() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    let result = '';
    for (let i = 0; i < 10; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('staffPassword');
    if (!input) return;
    input.type = 'text';
    input.value = result;
    const btn = input.parentElement.querySelector('.toggle-password i');
    if (btn) btn.className = 'bi bi-eye';
    clearTeamFieldValidation('passwordError', input, true);
}

function toggleCategory(cat) {
    const group = document.querySelector(`.category-group[data-cat="${cat}"]`);
    if (!group) return;
    const checkboxes = group.querySelectorAll('.perm-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });
    checkPermissionsSelected();
}

function applyPreset(preset) {
    const checkboxes = document.querySelectorAll('.perm-checkbox');
    checkboxes.forEach(cb => cb.checked = false);

    const presetMap = {
        'all': [
            'clients_view', 'clients_manage', 'clients_delete',
            'projects_view', 'projects_manage', 'projects_delete',
            'payments_view', 'payments_record', 'payments_remind', 'payments_delete',
            'notifications_view', 'notifications_manage', 'notifications_delete',
            'team_view', 'team_manage', 'team_delete'
        ],
        'pm': [
            'clients_view', 'clients_manage',
            'projects_view', 'projects_manage',
            'notifications_view', 'notifications_manage'
        ],
        'finance': [
            'clients_view',
            'payments_view', 'payments_record', 'payments_remind',
            'notifications_view'
        ],
        'viewer': [
            'clients_view',
            'projects_view',
            'payments_view',
            'notifications_view',
            'team_view'
        ]
    };

    if (presetMap[preset]) {
        presetMap[preset].forEach(perm => {
            const cb = document.getElementById(`perm_${perm}`);
            if (cb) cb.checked = true;
        });
    }
    checkPermissionsSelected();
}

function checkPermissionsSelected() {
    const checked = document.querySelectorAll('.perm-checkbox:checked').length;
    const alertBox = document.getElementById('permErrorAlert');
    if (alertBox) {
        if (checked === 0) {
            alertBox.textContent = 'Please select at least one permission module before creating the account.';
            alertBox.classList.remove('d-none');
            return false;
        } else {
            alertBox.textContent = '';
            alertBox.classList.add('d-none');
            return true;
        }
    }
    return checked > 0;
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('teamForm');
    const nameInput = document.getElementById('team_name');
    const emailInput = document.getElementById('team_email');
    const pwdInput = document.getElementById('staffPassword');
    const emailHint = document.getElementById('teamEmailHint');
    const csrfToken = document.getElementById('csrfTokenField').value;

    const btnSendTeamOtp = document.getElementById('btnSendTeamOtp');
    const btnResendTeamOtp = document.getElementById('btnResendTeamOtp');
    const btnVerifyTeamOtp = document.getElementById('btnVerifyTeamOtp');
    const teamOtpBox = document.getElementById('teamOtpBox');
    const teamEmailOtpInput = document.getElementById('teamEmailOtpInput');
    const teamOtpTargetDisplay = document.getElementById('teamOtpTargetDisplay');
    const teamOtpStatusMsg = document.getElementById('teamOtpStatusMsg');
    const teamOtpBoxError = document.getElementById('teamOtpBoxError');
    const teamOtpTimerText = document.getElementById('teamOtpTimerText');
    const teamOtpSeconds = document.getElementById('teamOtpSeconds');
    const teamVerificationStatusBadge = document.getElementById('teamVerificationStatusBadge');
    const teamVerifiedSuccessBadge = document.getElementById('teamVerifiedSuccessBadge');
    const emailOtpVerifiedHidden = document.getElementById('email_otp_verified');

    let countdownTimer = null;
    let verifiedEmailAddress = '<?php echo $alreadyVerifiedTeam ? addslashes($verifiedTeamEmail) : ""; ?>';

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

    function validateTeamName() {
        const v = nameInput.value.trim();
        if (!v) {
            setTeamFieldError('nameError', nameInput, 'Full name is required.');
            return false;
        }
        if (v.length < 2) {
            setTeamFieldError('nameError', nameInput, 'Full name must be at least 2 characters long.');
            return false;
        }
        if (v.length > 100) {
            setTeamFieldError('nameError', nameInput, 'Full name cannot exceed 100 characters.');
            return false;
        }
        if (!/^[a-zA-Z\s.'-]+$/.test(v)) {
            setTeamFieldError('nameError', nameInput, 'Only letters, spaces, dots, and hyphens are allowed.');
            return false;
        }
        clearTeamFieldValidation('nameError', nameInput, true);
        return true;
    }

    function validateTeamEmailSyntax() {
        const val = emailInput.value.trim().toLowerCase();
        if (!val) {
            setTeamFieldError('emailError', emailInput, 'Email Address is required.');
            return false;
        }
        if (!emailRegex.test(val)) {
            setTeamFieldError('emailError', emailInput, 'Please enter a valid email format (e.g. staff@domain.com).');
            return false;
        }
        const domain = val.split('@')[1];
        if (commonTypos[domain]) {
            setTeamFieldError('emailError', emailInput, `Typo detected in domain? Did you mean @${commonTypos[domain]}?`);
            return false;
        }
        clearTeamFieldValidation('emailError', emailInput, false);
        return true;
    }

    function validateTeamPassword() {
        const v = pwdInput.value;
        if (!v) {
            setTeamFieldError('passwordError', pwdInput, 'Login password is required.');
            return false;
        }
        if (v.length < 6) {
            setTeamFieldError('passwordError', pwdInput, 'Password must be at least 6 characters long.');
            return false;
        }
        if (v.length > 64) {
            setTeamFieldError('passwordError', pwdInput, 'Password cannot exceed 64 characters.');
            return false;
        }
        clearTeamFieldValidation('passwordError', pwdInput, true);
        return true;
    }

    // Reset verification state if email changes
    emailInput.addEventListener('input', function () {
        validateTeamEmailSyntax();
        const currentEmail = emailInput.value.trim().toLowerCase();
        if (currentEmail !== verifiedEmailAddress) {
            emailOtpVerifiedHidden.value = '0';
            teamVerificationStatusBadge.classList.remove('d-none');
            teamVerifiedSuccessBadge.classList.add('d-none');
            btnSendTeamOtp.classList.remove('d-none');
        }
    });

    if (nameInput) {
        nameInput.addEventListener('input', validateTeamName);
        nameInput.addEventListener('blur', validateTeamName);
    }
    if (emailInput) {
        emailInput.addEventListener('blur', validateTeamEmailSyntax);
    }
    if (pwdInput) {
        pwdInput.addEventListener('input', validateTeamPassword);
        pwdInput.addEventListener('blur', validateTeamPassword);
    }

    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.addEventListener('change', checkPermissionsSelected);
    });

    function setVerifiedState(email) {
        emailOtpVerifiedHidden.value = '1';
        verifiedEmailAddress = email;
        teamVerificationStatusBadge.classList.add('d-none');
        teamVerifiedSuccessBadge.classList.remove('d-none');
        teamOtpBox.classList.add('d-none');
        btnSendTeamOtp.classList.add('d-none');
        clearTeamFieldValidation('emailError', emailInput, true);
        teamEmailHint.innerHTML = `<span class="text-success fw-bold"><i class="bi bi-shield-check me-1"></i>Verified: ${email} confirmed with OTP.</span>`;
    }

    function startTimer(duration) {
        let sec = duration;
        clearInterval(countdownTimer);
        teamOtpTimerText.classList.remove('d-none');
        btnResendTeamOtp.classList.add('d-none');
        teamOtpSeconds.textContent = sec;

        countdownTimer = setInterval(() => {
            sec--;
            teamOtpSeconds.textContent = sec;
            if (sec <= 0) {
                clearInterval(countdownTimer);
                teamOtpTimerText.classList.add('d-none');
                btnResendTeamOtp.classList.remove('d-none');
            }
        }, 1000);
    }

    async function sendTeamOtpAction() {
        if (!validateTeamEmailSyntax()) {
            emailInput.focus();
            return;
        }

        const email = emailInput.value.trim().toLowerCase();
        const name = nameInput.value.trim() || 'Team Member';

        btnSendTeamOtp.disabled = true;
        btnSendTeamOtp.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
        teamOtpStatusMsg.innerHTML = '<span class="text-primary"><i class="bi bi-hourglass-split me-1"></i>Dispatching verification code...</span>';
        if (teamOtpBoxError) teamOtpBoxError.classList.add('d-none');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'send');
            formData.append('purpose', 'team_create');
            formData.append('email', email);
            formData.append('name', name);

            const res = await fetch('/client-flow/admin/ajax/email_otp.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            btnSendTeamOtp.disabled = false;
            btnSendTeamOtp.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Resend OTP';

            if (data.success) {
                teamOtpBox.classList.remove('d-none');
                teamOtpTargetDisplay.textContent = email;
                teamOtpStatusMsg.innerHTML = `<span class="text-success"><i class="bi bi-check2-circle me-1"></i>${data.message}</span>`;
                teamEmailOtpInput.value = '';
                teamEmailOtpInput.focus();
                startTimer(data.timeout || 60);
                clearTeamFieldValidation('emailError', emailInput, false);
            } else {
                teamOtpBox.classList.remove('d-none');
                teamOtpStatusMsg.innerHTML = '';
                if (teamOtpBoxError) {
                    teamOtpBoxError.textContent = data.message;
                    teamOtpBoxError.classList.remove('d-none');
                }
                setTeamFieldError('emailError', emailInput, data.message);
            }
        } catch (err) {
            btnSendTeamOtp.disabled = false;
            btnSendTeamOtp.innerHTML = '<i class="bi bi-send me-1"></i>Send OTP';
            teamOtpStatusMsg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Network error while requesting OTP.</span>';
        }
    }

    btnSendTeamOtp.addEventListener('click', sendTeamOtpAction);
    btnResendTeamOtp.addEventListener('click', sendTeamOtpAction);

    btnVerifyTeamOtp.addEventListener('click', async function () {
        const otp = teamEmailOtpInput.value.trim();
        const email = emailInput.value.trim().toLowerCase();

        if (otp.length !== 6 || !/^\d{6}$/.test(otp)) {
            if (teamOtpBoxError) {
                teamOtpBoxError.textContent = 'Please enter a complete 6-digit numerical OTP.';
                teamOtpBoxError.classList.remove('d-none');
            }
            teamEmailOtpInput.focus();
            return;
        }

        btnVerifyTeamOtp.disabled = true;
        btnVerifyTeamOtp.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verifying...';
        if (teamOtpBoxError) teamOtpBoxError.classList.add('d-none');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'verify');
            formData.append('purpose', 'team_create');
            formData.append('email', email);
            formData.append('otp', otp);

            const res = await fetch('/client-flow/admin/ajax/email_otp.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            btnVerifyTeamOtp.disabled = false;
            btnVerifyTeamOtp.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Verify OTP';

            if (data.success) {
                setVerifiedState(email);
            } else {
                if (teamOtpBoxError) {
                    teamOtpBoxError.textContent = data.message;
                    teamOtpBoxError.classList.remove('d-none');
                }
                teamEmailOtpInput.focus();
            }
        } catch (err) {
            btnVerifyTeamOtp.disabled = false;
            btnVerifyTeamOtp.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Verify OTP';
            if (teamOtpBoxError) {
                teamOtpBoxError.textContent = 'Network error during verification.';
                teamOtpBoxError.classList.remove('d-none');
            }
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            let valid = true;

            if (!validateTeamName()) {
                valid = false;
            }
            if (!validateTeamEmailSyntax()) {
                valid = false;
            }

            // Must be OTP verified
            if (emailOtpVerifiedHidden.value !== '1') {
                e.preventDefault();
                e.stopPropagation();
                teamOtpBox.classList.remove('d-none');
                setTeamFieldError('emailError', emailInput, 'Email verification required! Please click Send OTP and verify code before creating member.');
                teamOtpBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (!teamEmailOtpInput.value) {
                    sendTeamOtpAction();
                }
                return;
            }

            if (!validateTeamPassword()) {
                valid = false;
            }
            if (!checkPermissionsSelected()) {
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid') || document.getElementById('permErrorAlert');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                const btn = document.getElementById('btnSubmitTeam');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Creating Member...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
