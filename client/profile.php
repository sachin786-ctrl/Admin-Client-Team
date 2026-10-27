<?php
require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);
$pageTitle = 'My Profile';
$csrf = generateClientCsrfToken();

// 1. Handle Cover Picture Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cover'])) {
    if (!verifyClientCsrfToken($_POST['csrf_token'] ?? '')) {
        setClientFlash('error', 'Security session expired. Please refresh and try again.');
        header('Location: profile.php');
        exit;
    }

    $res = uploadClientImage('cover_pic', 'cover', $client['cover_pic'] ?? null);
    if (!$res['success']) {
        setClientFlash('error', $res['message']);
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET cover_pic = ? WHERE id = ?");
        $stmt->execute([$res['fileName'], $client['id']]);

        $adminId = $client['created_by'] ?? 1;
        addNotification($pdo, 'client', $client['id'], 'Cover Photo Updated', 'Your profile cover banner has been updated.', '/client-flow/client/profile.php', 'success', 'bi-image');

        setClientFlash('success', 'Cover picture updated successfully.');
    }
    header('Location: profile.php');
    exit;
}

// 2. Handle Profile Avatar Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    if (!verifyClientCsrfToken($_POST['csrf_token'] ?? '')) {
        setClientFlash('error', 'Security session expired. Please refresh and try again.');
        header('Location: profile.php');
        exit;
    }

    $res = uploadClientImage('profile_pic', 'profile', $client['profile_pic'] ?? null);
    if (!$res['success']) {
        setClientFlash('error', $res['message']);
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET profile_pic = ? WHERE id = ?");
        $stmt->execute([$res['fileName'], $client['id']]);

        $adminId = $client['created_by'] ?? 1;
        // Notify Client
        addNotification($pdo, 'client', $client['id'], 'Profile Photo Updated', 'You updated your profile picture.', '/client-flow/client/profile.php', 'success', 'bi-image');
        // Notify Admin
        addNotification($pdo, 'admin', $adminId, 'Client Photo Updated', "{$client['name']} ({$client['client_code']}) updated their profile photo.", "/client-flow/admin/clients/edit_client.php?id={$client['id']}", 'info', 'bi-person-badge');

        setClientFlash('success', 'Profile picture updated successfully.');
    }
    header('Location: profile.php');
    exit;
}

// 3. Handle Contact & Account Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyClientCsrfToken($_POST['csrf_token'] ?? '')) {
        setClientFlash('error', 'Security session expired. Please refresh and try again.');
        header('Location: profile.php');
        exit;
    }

    $name         = trim($_POST['name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    if ($name === '') {
        setClientFlash('error', 'Full Name is required.');
        header('Location: profile.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE clients SET name = ?, company_name = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([$name, $company_name, $phone, $address, $client['id']]);
        $_SESSION['client_name'] = $name;

        $adminId = $client['created_by'] ?? 1;
        // Notify Client
        addNotification($pdo, 'client', $client['id'], 'Profile Details Updated', 'You updated your contact and account information.', '/client-flow/client/profile.php', 'success', 'bi-person-check');
        // Notify Admin
        addNotification($pdo, 'admin', $adminId, 'Client Profile Updated', "{$name} ({$client['client_code']}) updated their account details.", "/client-flow/admin/clients/edit_client.php?id={$client['id']}", 'info', 'bi-person-check');

        setClientFlash('success', 'Profile details updated successfully.');
    } catch (PDOException $e) {
        setClientFlash('error', 'Update Error: ' . $e->getMessage());
    }

    header('Location: profile.php');
    exit;
}

// 4. Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyClientCsrfToken($_POST['csrf_token'] ?? '')) {
        setClientFlash('error', 'Security session expired. Please refresh and try again.');
        header('Location: profile.php');
        exit;
    }

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        setClientFlash('error', 'All password fields are required.');
    } elseif (!password_verify($current, $client['password'])) {
        setClientFlash('error', 'Current password is incorrect.');
    } elseif ($new !== $confirm) {
        setClientFlash('error', 'New password and confirm password do not match.');
    } elseif (strlen($new) < 6) {
        setClientFlash('error', 'New password must be at least 6 characters long.');
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE clients SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $client['id']]);

            $adminId = $client['created_by'] ?? 1;
            // Notify Client
            addNotification($pdo, 'client', $client['id'], 'Password Changed', 'Your account security password was updated successfully.', '/client-flow/client/profile.php', 'success', 'bi-shield-lock-fill');
            // Notify Admin
            addNotification($pdo, 'admin', $adminId, 'Client Password Changed', "{$client['name']} ({$client['client_code']}) changed account password.", "/client-flow/admin/clients/edit_client.php?id={$client['id']}", 'warning', 'bi-shield-check');

            setClientFlash('success', 'Password updated successfully.');
        } catch (PDOException $e) {
            setClientFlash('error', 'Password Error: ' . $e->getMessage());
        }
    }

    header('Location: profile.php');
    exit;
}

// Refresh client data in case it was updated
$client = getAuthenticatedClient($pdo);

// Workspace Overview Stats for this Client
$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND deleted_at IS NULL AND status != 'draft'");
$stmt->execute([$client['id']]);
$totalProjects = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE client_id = ? AND deleted_at IS NULL");
$stmt->execute([$client['id']]);
$totalPayments = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM payments WHERE client_id = ? AND deleted_at IS NULL");
$stmt->execute([$client['id']]);
$totalPaid = (float)$stmt->fetchColumn();

$flash = getClientFlash();
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-person-circle text-primary me-2"></i>My Profile</h4>
                <p class="text-muted mb-0">Manage your client profile, security credentials, and account details.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- 1. Hero Profile Banner Card (Same as Admin) -->
        <div class="card profile-hero-card mb-4">
            <!-- Cover Photo Area -->
            <div class="profile-cover" id="coverPicPreview"
                style="background-image:url('/client-flow/assets/uploads/cover/<?php echo htmlspecialchars(!empty($client['cover_pic']) ? $client['cover_pic'] : 'default-cover.jpg'); ?>'); background-size: cover; background-position: center center; background-repeat: no-repeat; height: 160px; overflow: hidden;">
                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_cover" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <label for="cover_pic_input" class="cover-edit-btn" title="Click to upload a new cover banner">
                        <i class="bi bi-camera-fill"></i>
                        <span>Change Cover</span>
                    </label>
                    <input type="file" id="cover_pic_input" name="cover_pic" accept="image/png,image/jpeg,image/webp" class="d-none" onchange="this.form.submit()">
                </form>
            </div>

            <!-- Profile Info Row -->
            <div class="profile-banner-body">
                <div class="d-flex flex-column flex-md-row align-items-center align-items-md-end justify-content-between gap-3">
                    
                    <div class="d-flex flex-column flex-md-row align-items-center align-items-md-end gap-3 text-center text-md-start">
                        <!-- Profile Avatar with Camera Button -->
                        <div class="profile-pic-wrapper">
                            <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars(!empty($client['profile_pic']) ? $client['profile_pic'] : 'default-avatar.png'); ?>" 
                                 id="profilePicPreview" 
                                 class="profile-avatar-img" 
                                 style="width: 88px; height: 88px; min-width: 88px; min-height: 88px; object-fit: cover; border-radius: 50%;"
                                 alt="<?php echo htmlspecialchars($client['name']); ?>"
                                 onerror="this.src='/client-flow/assets/uploads/profile/default-avatar.png';">
                            <form action="profile.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="update_avatar" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <label for="profile_pic_input" class="pic-edit-btn" title="Click to upload a new profile picture">
                                    <i class="bi bi-camera-fill"></i>
                                </label>
                                <input type="file" id="profile_pic_input" name="profile_pic" accept="image/png,image/jpeg,image/webp" class="d-none" onchange="this.form.submit()">
                            </form>
                        </div>

                        <!-- Name & Badges -->
                        <div class="profile-header-meta pb-1">
                            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-start gap-2 mb-1">
                                <h3 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($client['name']); ?></h3>
                                <?php if (!empty($client['client_code'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 font-monospace">
                                        <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($client['client_code']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="bi bi-check-circle-fill me-1"></i> Active Client
                                </span>
                                <?php if (!empty($client['company_name'])): ?>
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($client['company_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-start gap-3 text-muted small">
                                <span><i class="bi bi-envelope text-primary me-1"></i> <?php echo htmlspecialchars($client['email']); ?></span>
                                <?php if (!empty($client['phone'])): ?>
                                    <span><i class="bi bi-telephone text-primary me-1"></i> <?php echo htmlspecialchars($client['phone']); ?></span>
                                <?php endif; ?>
                                <span><i class="bi bi-calendar3 text-primary me-1"></i> Joined: <?php echo date('M d, Y', strtotime($client['created_at'])); ?></span>
                                <span><i class="bi bi-person-badge text-primary me-1"></i> Client ID: #<?php echo str_pad($client['id'], 3, '0', STR_PAD_LEFT); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Two-Column Grid: Form & Info Cards (Same as Admin) -->
        <div class="row g-4">
            
            <!-- Left Column: Edit Profile & Security Details (col-lg-8) -->
            <div class="col-lg-8">
                
                <!-- Edit Profile Details Card -->
                <div class="card p-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; background: var(--primary-light); color: var(--primary);">
                                <i class="bi bi-person-lines-fill fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0">Personal & Business Information</h5>
                                <small class="text-muted">Update your contact details for invoices and project communications</small>
                            </div>
                        </div>
                        <span class="badge bg-light text-muted border">Profile Form</span>
                    </div>

                    <form action="profile.php" method="POST" id="clientProfileForm" novalidate>
                        <input type="hidden" name="update_profile" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person text-primary"></i></span>
                                    <input type="text" name="name" id="clientProfileName" class="form-control" value="<?php echo htmlspecialchars($client['name']); ?>" placeholder="Enter full name" required minlength="2" maxlength="100">
                                </div>
                                <p class="text-danger small mt-1 mb-0 d-none" id="nameError"></p>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-envelope text-primary"></i></span>
                                    <input type="email" class="form-control bg-light" value="<?php echo htmlspecialchars($client['email']); ?>" readonly disabled>
                                </div>
                                <div class="form-text text-muted small"><i class="bi bi-shield-check text-success me-1"></i> Primary login & billing email.</div>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-telephone text-primary"></i></span>
                                    <input type="text" name="phone" id="clientProfilePhone" class="form-control" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" placeholder="+91 9876543210">
                                </div>
                                <p class="text-danger small mt-1 mb-0 d-none" id="phoneError"></p>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Company / Business Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-building text-primary"></i></span>
                                    <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($client['company_name'] ?? ''); ?>" placeholder="Company or Organization">
                                </div>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Client Code</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-upc-scan text-muted"></i></span>
                                    <input type="text" class="form-control bg-light font-monospace" value="<?php echo htmlspecialchars($client['client_code']); ?>" disabled>
                                </div>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Member Since</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-clock-history text-muted"></i></span>
                                    <input type="text" class="form-control bg-light" value="<?php echo date('M d, Y', strtotime($client['created_at'])); ?>" disabled>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Billing Address / Location</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-geo-alt text-primary"></i></span>
                                    <textarea name="address" class="form-control" rows="2" placeholder="Street, City, State, Postal Code"><?php echo htmlspecialchars($client['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center justify-content-between pt-4 mt-3 border-top gap-2">
                            <span class="text-muted small"><i class="bi bi-info-circle me-1"></i> Changes will immediately take effect across all dashboard views and invoices.</span>
                            <button type="submit" class="btn btn-primary text-white" id="btnSaveClientProfile">
                                <i class="bi bi-check2-circle me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Quick Card (Same as Admin) -->
                <div class="card p-4">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between p-3 rounded-3" style="background: var(--bg-light); border: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center gap-3 mb-2 mb-sm-0">
                            <div class="p-2 rounded-3 bg-white shadow-sm border text-primary">
                                <i class="bi bi-key-fill fs-4"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0">Password Protection</h6>
                                <p class="text-muted small mb-0">Password is securely hashed with BCrypt. Update it only if needed.</p>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" data-bs-toggle="collapse" data-bs-target="#changePasswordCollapse" aria-expanded="false" aria-controls="changePasswordCollapse">
                            <i class="bi bi-arrow-repeat me-1"></i> Change Password
                        </button>
                    </div>

                    <!-- Collapsible Password Form -->
                    <div class="collapse mt-3" id="changePasswordCollapse">
                        <form method="POST" action="profile.php" id="clientPasswordForm" class="p-3 bg-light rounded-3 border" novalidate>
                            <input type="hidden" name="change_password" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Current Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-lock text-muted"></i></span>
                                    <input type="password" name="current_password" id="client_current_pwd" class="form-control" required placeholder="Enter current password">
                                    <button type="button" class="btn btn-outline-secondary toggle-password bg-white" onclick="togglePassVisibility('client_current_pwd', this)" title="Show/Hide">
                                        <i class="bi bi-eye-slash"></i>
                                    </button>
                                </div>
                                <p class="text-danger small mt-1 mb-0 d-none" id="current_passwordError"></p>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-key text-primary"></i></span>
                                        <input type="password" name="new_password" id="client_new_pwd" class="form-control" minlength="6" required placeholder="Min 6 characters">
                                        <button type="button" class="btn btn-outline-secondary toggle-password bg-white" onclick="togglePassVisibility('client_new_pwd', this)" title="Show/Hide">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                    </div>
                                    <p class="text-danger small mt-1 mb-0 d-none" id="new_passwordError"></p>
                                </div>

                                <div class="col-6">
                                    <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-key-fill text-primary"></i></span>
                                        <input type="password" name="confirm_password" id="client_confirm_pwd" class="form-control" minlength="6" required placeholder="Re-enter password">
                                        <button type="button" class="btn btn-outline-secondary toggle-password bg-white" onclick="togglePassVisibility('client_confirm_pwd', this)" title="Show/Hide">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                    </div>
                                    <p class="text-danger small mt-1 mb-0 d-none" id="confirm_passwordError"></p>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#changePasswordCollapse">Cancel</button>
                                <button type="submit" class="btn btn-sm btn-primary text-white">
                                    <i class="bi bi-shield-check me-1"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Right Column: Stats & System Overview (col-lg-4) -->
            <div class="col-lg-4">
                
                <!-- System Stats Card (Same as Admin) -->
                <div class="card p-4 mb-4">
                    <h6 class="fw-bold mb-3 pb-2 border-bottom">
                        <i class="bi bi-speedometer2 text-primary me-2"></i>Account Overview
                    </h6>

                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-folder-check text-primary me-2"></i>My Projects</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold fs-6"><?php echo $totalProjects; ?></span>
                        </div>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-receipt text-primary me-2"></i>Payment Invoices</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold fs-6"><?php echo $totalPayments; ?></span>
                        </div>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-cash-stack text-success me-2"></i>Total Settled</span>
                            <span class="badge bg-success-subtle text-success fw-bold fs-6">₹<?php echo number_format($totalPaid, 2); ?></span>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-top">
                        <a href="projects.php" class="btn btn-light w-100 btn-sm text-primary fw-semibold">
                            <i class="bi bi-folder-check me-1"></i> View My Projects
                        </a>
                    </div>
                </div>

                <!-- Media Upload Guidelines Card (Same as Admin) -->
                <div class="card p-4">
                    <h6 class="fw-bold mb-3 pb-2 border-bottom">
                        <i class="bi bi-image text-primary me-2"></i>Photo Guidelines
                    </h6>
                    <ul class="text-muted small ps-3 mb-0" style="line-height: 1.8;">
                        <li><strong>Avatar:</strong> 1:1 square ratio (e.g. 400×400px), JPG, PNG or WEBP format.</li>
                        <li><strong>Cover Banner:</strong> Wide panoramic ratio (e.g. 1200×300px) works best.</li>
                        <li><strong>File Size:</strong> Maximum file size allowed is 2 MB.</li>
                        <li><strong>Auto-Save:</strong> Selecting an image instantly updates your profile banner or avatar.</li>
                    </ul>
                </div>

            </div>

        </div>

    </div>
</div>

<script>
function togglePassVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn ? btn.querySelector('i') : null;
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
    } else {
        input.type = 'password';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('clientProfileForm');
    const nameInput = document.getElementById('clientProfileName');
    const nameErr = document.getElementById('nameError');

    function validateName() {
        if (!nameInput) return true;
        const val = nameInput.value.trim();
        if (!val) {
            nameErr.textContent = 'Full Name is required.';
            nameErr.classList.remove('d-none');
            nameInput.classList.add('is-invalid');
            return false;
        } else if (val.length < 2) {
            nameErr.textContent = 'Full Name must be at least 2 characters.';
            nameErr.classList.remove('d-none');
            nameInput.classList.add('is-invalid');
            return false;
        }
        nameErr.textContent = '';
        nameErr.classList.add('d-none');
        nameInput.classList.remove('is-invalid');
        return true;
    }

    if (nameInput) {
        nameInput.addEventListener('input', validateName);
        nameInput.addEventListener('blur', validateName);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateName()) {
                e.preventDefault();
                nameInput.focus();
            }
        });
    }

    // Password Form Validation
    const pwdForm = document.getElementById('clientPasswordForm');
    if (pwdForm) {
        pwdForm.addEventListener('submit', function(e) {
            const cur = document.getElementById('client_current_pwd');
            const nw  = document.getElementById('client_new_pwd');
            const cnf = document.getElementById('client_confirm_pwd');
            let valid = true;

            const curErr = document.getElementById('current_passwordError');
            const nwErr  = document.getElementById('new_passwordError');
            const cnfErr = document.getElementById('confirm_passwordError');

            if (!cur || !cur.value.trim()) {
                if (curErr) { curErr.textContent = 'Current password is required.'; curErr.classList.remove('d-none'); }
                if (cur) cur.classList.add('is-invalid');
                valid = false;
            } else {
                if (curErr) curErr.classList.add('d-none');
                if (cur) cur.classList.remove('is-invalid');
            }

            if (!nw || nw.value.length < 6) {
                if (nwErr) { nwErr.textContent = 'New password must be at least 6 characters.'; nwErr.classList.remove('d-none'); }
                if (nw) nw.classList.add('is-invalid');
                valid = false;
            } else {
                if (nwErr) nwErr.classList.add('d-none');
                if (nw) nw.classList.remove('is-invalid');
            }

            if (!cnf || cnf.value !== (nw ? nw.value : '')) {
                if (cnfErr) { cnfErr.textContent = 'Passwords do not match.'; cnfErr.classList.remove('d-none'); }
                if (cnf) cnf.classList.add('is-invalid');
                valid = false;
            } else {
                if (cnfErr) cnfErr.classList.add('d-none');
                if (cnf) cnf.classList.remove('is-invalid');
            }

            if (!valid) {
                e.preventDefault();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
