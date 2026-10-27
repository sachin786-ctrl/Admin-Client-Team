<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('team_manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setFlash('error', 'Invalid team member ID.');
    header('Location: list_team.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM admin WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'Team member not found.');
    header('Location: list_team.php');
    exit;
}

$isSelf = ((int)$member['id'] === (int)$admin['id']);
$isSuper = ($member['role'] === 'super_admin');
$currentUserIsSuper = (($admin['role'] ?? '') === 'super_admin');

// Only a Super Admin can edit another Super Admin
if ($isSuper && !$currentUserIsSuper) {
    setFlash('error', 'Only a Super Admin can edit another Super Admin account.');
    header('Location: list_team.php');
    exit;
}

$pageTitle = 'Edit Team Member';
$availablePermissions = getAvailablePermissions();
$errors = [];
$fieldErrors = [];

$name = $member['name'];
$email = $member['email'];
$status = (int)$member['status'];
$currentPerms = !empty($member['permissions']) ? (is_array($member['permissions']) ? $member['permissions'] : json_decode($member['permissions'], true)) : [];
if (!is_array($currentPerms)) {
    $currentPerms = [];
}
$selectedPermissions = $currentPerms;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $newStatus = isset($_POST['status']) ? (int)$_POST['status'] : $status;
        
        if (!$isSuper) {
            $selectedPermissions = $_POST['permissions'] ?? [];
            if (!is_array($selectedPermissions)) {
                $selectedPermissions = [];
            }
        }

        if ($name === '') {
            $fieldErrors['name'] = 'Full Name is required.';
            $errors[] = 'Full name is required.';
        } elseif (strlen($name) < 2) {
            $fieldErrors['name'] = 'Full Name must be at least 2 characters.';
            $errors[] = 'Full Name must be at least 2 characters.';
        }

        if ($email === '') {
            $fieldErrors['email'] = 'Email Address is required.';
            $errors[] = 'Email Address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'A valid email address is required (e.g. user@domain.com).';
            $errors[] = 'A valid email address is required.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM admin WHERE email = ? AND id != ? LIMIT 1");
            $chk->execute([$email, $id]);
            if ($chk->fetch()) {
                $fieldErrors['email'] = 'An account with this email address already exists.';
                $errors[] = 'An account with this email address already exists.';
            }
        }

        if (!empty($password) && strlen($password) < 6) {
            $fieldErrors['password'] = 'Password must be at least 6 characters long.';
            $errors[] = 'Password must be at least 6 characters long.';
        }

        if (!$isSuper && empty($selectedPermissions)) {
            $fieldErrors['permissions'] = 'Please assign at least one permission to this team member.';
            $errors[] = 'Please assign at least one permission to this team member.';
        }

        // Prevent self-deactivation
        if ($isSelf && $newStatus === 0) {
            $errors[] = 'You cannot deactivate your own account.';
        }

        // Prevent deactivating Super Admin
        if ($isSuper && $newStatus === 0) {
            $errors[] = 'Super Admin account cannot be deactivated.';
        }

        if (empty($errors)) {
            $updateFields = [
                'name = ?',
                'email = ?',
                'status = ?'
            ];
            $params = [$name, $email, $newStatus];

            if (!empty($password)) {
                $updateFields[] = 'password = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);

                // Dispatch password update notification email to team member
                $teamMemberData = [
                    'id'    => $id,
                    'name'  => $name,
                    'email' => $email,
                    'role'  => $member['role'] ?? 'staff',
                ];
                sendTeamPasswordResetEmail($teamMemberData, $password, $admin);
            }

            if (!$isSuper) {
                $updateFields[] = 'permissions = ?';
                $params[] = json_encode(array_values($selectedPermissions));
            }

            if (!empty($_FILES['profile_pic']['name'])) {
                $uploadRes = uploadImage('profile_pic', 'profile', $member['profile_pic']);
                if ($uploadRes['success']) {
                    $updateFields[] = 'profile_pic = ?';
                    $params[] = $uploadRes['fileName'];
                    $member['profile_pic'] = $uploadRes['fileName'];
                } else {
                    $errors[] = $uploadRes['message'];
                }
            }

            $params[] = $id;
            $sql = "UPDATE admin SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $upStmt = $pdo->prepare($sql);
            $upStmt->execute($params);

            $teamMemberData = [
                'id'    => $id,
                'name'  => $name,
                'email' => $email,
                'role'  => $member['role'] ?? 'staff',
            ];

            // 1. Check if status was changed (Block / Unblock)
            $statusChanged = ((int)$member['status'] !== $newStatus);
            if (!$isSelf && $statusChanged) {
                sendAccountStatusEmail('team', $teamMemberData, $newStatus, $admin);
            }

            // 2. Check if details or permissions were modified (when password was not updated)
            if (!$isSelf && empty($password) && !$statusChanged) {
                $oldPerms = !empty($member['permissions']) ? json_decode($member['permissions'], true) : [];
                if (!is_array($oldPerms)) { $oldPerms = []; }
                $newPerms = array_values($selectedPermissions);
                sort($oldPerms);
                sort($newPerms);
                $permsChanged = (!$isSuper && $oldPerms !== $newPerms);
                $detailsChanged = ($member['name'] !== $name || $member['email'] !== $email);

                if ($detailsChanged || $permsChanged) {
                    $changes = [];
                    if ($member['name'] !== $name) {
                        $changes['Full Name'] = $name;
                    }
                    if ($member['email'] !== $email) {
                        $changes['Email Address'] = $email;
                    }
                    if ($permsChanged) {
                        $changes['Permissions'] = count($newPerms) . ' Active (' . (!empty($newPerms) ? implode(', ', array_map(function($p) {
                            return ucwords(str_replace('_', ' ', $p));
                        }, $newPerms)) : 'None') . ')';
                    }
                    sendAccountModifiedEmail('team', $teamMemberData, $changes, $admin);
                }
            }

            // Update session if editing self
            if ($isSelf) {
                $_SESSION['admin_name'] = $name;
                $_SESSION['admin_email'] = $email;
            }

            setFlash('success', 'Team member ' . $name . ' updated successfully.');
            header('Location: list_team.php');
            exit;
        }
    }
}
$csrf = generateCsrfToken();
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
                        <li class="breadcrumb-item active" aria-current="page">Edit Member</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit Member: <?php echo htmlspecialchars($member['name']); ?>
                </h4>
                <p class="text-muted mb-0">Modify credentials, account status, and role access permissions.</p>
            </div>
            <div>
                <a href="list_team.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Team
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit_team.php?id=<?php echo $id; ?>" novalidate id="editTeamForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="row g-4">
                <!-- Left Column: Member Details -->
                <div class="col-12 col-lg-4">
                    <div class="card shadow-sm p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                            <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars($member['profile_pic'] ?: 'default-avatar.png'); ?>"
                                 class="rounded-circle object-fit-cover border shadow-xs" style="width: 52px; height: 52px;" alt="Avatar"
                                 onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($member['name']); ?></h6>
                                <span class="badge <?php echo $isSuper ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-info-subtle text-info border border-info-subtle'; ?> px-2 py-1 mt-1">
                                    <?php echo $isSuper ? 'Role: Super Admin' : 'Role: Staff Member'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Update Profile Picture</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-image"></i></span>
                                <input type="file" name="profile_pic" class="form-control" accept="image/jpeg,image/png,image/webp">
                            </div>
                            <div class="form-text">JPG, PNG or WEBP (Max 5MB). Leave empty to keep existing.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                                <input type="text" name="name" id="member_name" class="form-control <?php echo !empty($fieldErrors['name']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>" required minlength="2" maxlength="100">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['name']) ? 'd-none' : ''; ?>" id="nameError">
                                <?php echo htmlspecialchars($fieldErrors['name'] ?? ''); ?>
                            </p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="member_email" class="form-control <?php echo !empty($fieldErrors['email']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required maxlength="150">
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['email']) ? 'd-none' : ''; ?>" id="emailError">
                                <?php echo htmlspecialchars($fieldErrors['email'] ?? ''); ?>
                            </p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group mb-1">
                                <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" id="staffPassword" class="form-control <?php echo !empty($fieldErrors['password']) ? 'is-invalid' : ''; ?>" placeholder="Leave blank to keep unchanged" minlength="6" maxlength="64">
                                <button type="button" class="btn btn-outline-secondary toggle-password bg-white" style="border-color:#dee2e6; cursor:pointer;" onclick="togglePasswordVisibility('staffPassword', this)" title="Show Password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                            <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['password']) ? 'd-none' : ''; ?>" id="passwordError">
                                <?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?>
                            </p>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="generateRandomPass()">
                                <i class="bi bi-magic me-1"></i> Generate Password
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Account Status</label>
                            <select name="status" class="form-select" <?php echo ($isSelf || $isSuper) ? 'disabled' : ''; ?>>
                                <option value="1" <?php echo (int)$status === 1 ? 'selected' : ''; ?>>Active - Can log in immediately</option>
                                <option value="0" <?php echo (int)$status === 0 ? 'selected' : ''; ?>>Inactive - Login blocked</option>
                            </select>
                            <?php if ($isSelf || $isSuper): ?>
                                <input type="hidden" name="status" value="<?php echo $status; ?>">
                            <?php endif; ?>
                        </div>

                        <div class="p-3 bg-light rounded-3 mt-auto border">
                            <small class="text-muted d-block">
                                Created on <?php echo date('d M, Y', strtotime($member['created_at'])); ?>
                            </small>
                            <small class="text-muted d-block">
                                Last updated on <?php echo date('d M, Y', strtotime($member['updated_at'])); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Permissions Matrix -->
                <div class="col-12 col-lg-8">
                    <div class="card shadow-sm p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">
                                    <i class="bi bi-shield-check text-primary me-2"></i>Module Permissions
                                </h5>
                                <p class="text-muted small mb-0">Control member access to pages and actions.</p>
                            </div>
                            <?php if (!$isSuper): ?>
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
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" onclick="applyPreset('viewer')">
                                        <i class="bi bi-eye-fill me-1"></i> Read Only
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="applyPreset('clear')">
                                        <i class="bi bi-x-circle me-1"></i> Clear
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isSuper): ?>
                            <div class="alert alert-info py-3 mb-0 rounded-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-shield-fill-check fs-4 text-primary"></i>
                                    <div>
                                        <span class="fw-bold d-block">Super Admin Access</span>
                                        <span>Super Admin Access: Full access to all current and future modules. Permissions cannot be changed.</span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Permission Categories -->
                            <div class="row g-3">
                                <?php foreach ($availablePermissions as $category => $perms): ?>
                                    <div class="col-12 col-md-6">
                                        <div class="perm-box p-3 h-100">
                                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                                <span class="fw-bold text-dark d-flex align-items-center">
                                                    <?php if ($category === 'Clients'): ?>
                                                        <i class="bi bi-people-fill text-primary me-2"></i>
                                                    <?php elseif ($category === 'Projects'): ?>
                                                        <i class="bi bi-folder-fill text-warning me-2"></i>
                                                    <?php elseif ($category === 'Payments'): ?>
                                                        <i class="bi bi-credit-card-fill text-success me-2"></i>
                                                    <?php elseif ($category === 'Notifications'): ?>
                                                        <i class="bi bi-bell-fill text-info me-2"></i>
                                                    <?php elseif ($category === 'Team' || $category === 'Team & Staff'): ?>
                                                        <i class="bi bi-shield-lock-fill text-purple me-2"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-gear-fill text-secondary me-2"></i>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($category); ?>
                                                </span>
                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-muted" onclick="toggleCategory('<?php echo strtolower($category); ?>')">
                                                    Toggle All
                                                </button>
                                            </div>

                                            <div class="d-flex flex-column gap-1 category-group" data-cat="<?php echo strtolower($category); ?>">
                                                <?php foreach ($perms as $permKey => $permLabel): 
                                                    $isChecked = in_array($permKey, $selectedPermissions, true);
                                                ?>
                                                    <div class="form-check perm-item mb-0">
                                                        <input class="form-check-input perm-checkbox" 
                                                               type="checkbox" 
                                                               name="permissions[]" 
                                                               value="<?php echo htmlspecialchars($permKey); ?>" 
                                                               id="perm_<?php echo htmlspecialchars($permKey); ?>"
                                                               <?php echo $isChecked ? 'checked' : ''; ?>>
                                                        <label class="form-check-label w-100 ps-1" for="perm_<?php echo htmlspecialchars($permKey); ?>" style="cursor: pointer;">
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
                        <?php endif; ?>

                        <p class="text-danger small mt-2 mb-0 <?php echo empty($fieldErrors['permissions']) ? 'd-none' : ''; ?>" id="permissionsError">
                            <?php echo htmlspecialchars($fieldErrors['permissions'] ?? ''); ?>
                        </p>

                        <!-- Submit Buttons -->
                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <a href="list_team.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4" id="btnSubmitEditTeam">
                                <i class="bi bi-check-lg me-1"></i> Save Changes
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
    if (typeof window.togglePasswordVisibility === 'function') {
        window.togglePasswordVisibility(inputId, btn);
    } else {
        const input = document.getElementById(inputId);
        const icon = btn ? (btn.querySelector('i') || btn) : null;
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
            if (btn) btn.setAttribute('title', 'Hide password');
        } else {
            input.type = 'password';
            if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
            if (btn) btn.setAttribute('title', 'Show password');
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
    input.type = 'text';
    input.value = result;
    const btn = input.nextElementSibling;
    if (btn) {
        const icon = btn.querySelector('i') || btn;
        if (icon) {
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
        btn.setAttribute('title', 'Hide password');
    }
}

function toggleCategory(cat) {
    const group = document.querySelector(`.category-group[data-cat="${cat}"]`);
    if (!group) return;
    const checkboxes = group.querySelectorAll('.perm-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });
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
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('editTeamForm');
    const nameInput = document.getElementById('member_name');
    const emailInput = document.getElementById('member_email');
    const passInput = document.getElementById('staffPassword');
    const isSuperAdmin = <?php echo $isSuper ? 'true' : 'false'; ?>;

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

    function validateName() {
        if (!nameInput) return true;
        const val = nameInput.value.trim();
        if (!val) {
            setFieldError('nameError', nameInput, 'Full Name is required.');
            return false;
        }
        if (val.length < 2) {
            setFieldError('nameError', nameInput, 'Full Name must be at least 2 characters.');
            return false;
        }
        clearFieldValidation('nameError', nameInput);
        return true;
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
            setFieldError('emailError', emailInput, 'A valid email address is required (e.g. user@domain.com).');
            return false;
        }
        clearFieldValidation('emailError', emailInput);
        return true;
    }

    function validatePassword() {
        if (!passInput) return true;
        const val = passInput.value;
        if (val && val.length < 6) {
            setFieldError('passwordError', passInput, 'Password must be at least 6 characters long.');
            return false;
        }
        clearFieldValidation('passwordError', passInput);
        return true;
    }

    function validatePermissions() {
        if (isSuperAdmin) return true;
        const checked = document.querySelectorAll('.perm-checkbox:checked');
        if (checked.length === 0) {
            const p = document.getElementById('permissionsError');
            if (p) {
                p.textContent = 'Please assign at least one permission to this team member.';
                p.classList.remove('d-none');
            }
            return false;
        }
        const p = document.getElementById('permissionsError');
        if (p) {
            p.textContent = '';
            p.classList.add('d-none');
        }
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
    if (passInput) {
        passInput.addEventListener('input', validatePassword);
        passInput.addEventListener('blur', validatePassword);
    }

    const permBoxes = document.querySelectorAll('.perm-checkbox');
    permBoxes.forEach(cb => {
        cb.addEventListener('change', validatePermissions);
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            let isValid = true;
            if (!validateName()) isValid = false;
            if (!validateEmail()) isValid = false;
            if (!validatePassword()) isValid = false;
            if (!validatePermissions()) isValid = false;

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                } else {
                    const permError = document.getElementById('permissionsError');
                    if (permError && !permError.classList.contains('d-none')) {
                        permError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            } else {
                const btn = document.getElementById('btnSubmitEditTeam');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
