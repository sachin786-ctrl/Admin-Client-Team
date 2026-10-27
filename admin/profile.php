<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'My Profile';
$flash = getFlash();
$csrf = generateCsrfToken();

// Workspace Overview Stats for the Admin
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE created_by = ?");
$stmt->execute([$admin['id']]);
$totalClients = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p JOIN clients c ON p.client_id = c.id WHERE c.created_by = ?");
$stmt->execute([$admin['id']]);
$totalProjects = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments pay JOIN projects p ON pay.project_id = p.id JOIN clients c ON p.client_id = c.id WHERE c.created_by = ?");
$stmt->execute([$admin['id']]);
$totalPayments = (int)$stmt->fetchColumn();
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
                <p class="text-muted mb-0">Manage your administrator profile, security settings, and account details.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>



        <!-- 1. Hero Profile Banner Card -->
        <div class="card profile-hero-card mb-4">
            <!-- Cover Photo Area -->
            <div class="profile-cover" id="coverPicPreview"
                style="background-image:url('<?php echo $assetBase; ?>assets/uploads/cover/<?php echo htmlspecialchars($admin['cover_pic'] ?? 'default-cover.jpg'); ?>'); background-size: cover; background-position: center center; background-repeat: no-repeat; height: 160px; overflow: hidden;">
                <form action="update_cover.php" method="POST" enctype="multipart/form-data">
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
                            <img src="<?php echo $assetBase; ?>assets/uploads/profile/<?php echo htmlspecialchars($admin['profile_pic'] ?? 'default-avatar.png'); ?>" 
                                 id="profilePicPreview" 
                                 class="profile-avatar-img" 
                                 style="width: 88px; height: 88px; min-width: 88px; min-height: 88px; object-fit: cover; border-radius: 50%;"
                                 alt="<?php echo htmlspecialchars($admin['name']); ?>">
                            <form action="update_profile_pic.php" method="POST" enctype="multipart/form-data">
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
                                <h3 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($admin['name']); ?></h3>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                    <i class="bi bi-shield-check me-1"></i> Super Admin
                                </span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="bi bi-check-circle-fill me-1"></i> Active
                                </span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-start gap-3 text-muted small">
                                <span><i class="bi bi-envelope text-primary me-1"></i> <?php echo htmlspecialchars($admin['email']); ?></span>
                                <span><i class="bi bi-calendar3 text-primary me-1"></i> Joined: <?php echo date('M d, Y', strtotime($admin['created_at'])); ?></span>
                                <span><i class="bi bi-person-badge text-primary me-1"></i> Admin ID: #<?php echo str_pad($admin['id'], 3, '0', STR_PAD_LEFT); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Two-Column Grid: Form & Info Cards -->
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
                                <h5 class="fw-bold mb-0">Personal & Account Information</h5>
                                <small class="text-muted">Update your display name and view account identifiers</small>
                            </div>
                        </div>
                        <span class="badge bg-light text-muted border">Profile Form</span>
                    </div>

                    <form action="update_profile.php" method="POST" id="adminProfileForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person text-primary"></i></span>
                                    <input type="text" name="name" id="adminProfileName" class="form-control" value="<?php echo htmlspecialchars($admin['name']); ?>" placeholder="Enter full name" required minlength="2" maxlength="100">
                                </div>
                                <p class="text-danger small mt-1 mb-0" id="nameError"></p>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-envelope text-primary"></i></span>
                                    <input type="email" name="email" id="adminProfileEmail" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" placeholder="admin@example.com" required maxlength="150">
                                </div>
                                <p class="text-danger small mt-1 mb-0" id="emailError"></p>
                                <div class="form-text text-muted small"><i class="bi bi-shield-check text-success me-1"></i> Admin login & alert email.</div>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Role & Access Level</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-shield-check text-success"></i></span>
                                    <input type="text" class="form-control bg-light" value="Super Administrator" disabled>
                                </div>
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Member Since</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-clock-history text-muted"></i></span>
                                    <input type="text" class="form-control bg-light" value="<?php echo date('M d, Y', strtotime($admin['created_at'])); ?>" disabled>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center justify-content-between pt-4 mt-3 border-top gap-2">
                            <span class="text-muted small"><i class="bi bi-info-circle me-1"></i> Changes will immediately take effect across all dashboard views.</span>
                            <button type="submit" class="btn btn-primary text-white" id="btnSaveProfile">
                                <i class="bi bi-check2-circle me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Quick Card -->
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
                        <a href="change_password.php" class="btn btn-outline-primary btn-sm text-nowrap">
                            <i class="bi bi-arrow-repeat me-1"></i> Change Password
                        </a>
                    </div>
                </div>

            </div>

            <!-- Right Column: Stats & System Overview (col-lg-4) -->
            <div class="col-lg-4">
                
                <!-- System Stats Card -->
                <div class="card p-4 mb-4">
                    <h6 class="fw-bold mb-3 pb-2 border-bottom">
                        <i class="bi bi-speedometer2 text-primary me-2"></i>Workspace Overview
                    </h6>

                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-people text-primary me-2"></i>Total Clients</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold fs-6"><?php echo $totalClients; ?></span>
                        </div>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-folder-check text-primary me-2"></i>Total Projects</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold fs-6"><?php echo $totalProjects; ?></span>
                        </div>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                            <span class="text-muted"><i class="bi bi-receipt text-primary me-2"></i>Payment Invoices</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold fs-6"><?php echo $totalPayments; ?></span>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-top">
                        <a href="dashboard.php" class="btn btn-light w-100 btn-sm text-primary fw-semibold">
                            <i class="bi bi-grid-fill me-1"></i> Open Control Dashboard
                        </a>
                    </div>
                </div>

                <!-- Media Upload Guidelines Card -->
                <div class="card p-4">
                    <h6 class="fw-bold mb-3 pb-2 border-bottom">
                        <i class="bi bi-image text-primary me-2"></i>Photo Guidelines
                    </h6>
                    <ul class="text-muted small ps-3 mb-0" style="line-height: 1.8;">
                        <li><strong>Avatar:</strong> 1:1 square ratio (e.g. 400×400px), JPG, PNG or WEBP format.</li>
                        <li><strong>Cover Banner:</strong> Wide panoramic ratio (e.g. 1200×300px) works best.</li>
                        <li><strong>File Size:</strong> Maximum file size allowed is 2 MB.</li>
                        <li><strong>Auto-Save:</strong> Selecting an image instantly updates your profile banner.</li>
                    </ul>
                </div>

            </div>

        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('adminProfileForm');
    const nameInput = document.getElementById('adminProfileName');
    const emailInput = document.getElementById('adminProfileEmail');
    const nameErr = document.getElementById('nameError');
    const emailErr = document.getElementById('emailError');

    function validateName() {
        if (!nameInput) return true;
        const val = nameInput.value.trim();
        if (!val) {
            nameErr.textContent = 'Full Name is required.';
            nameInput.classList.add('is-invalid');
            return false;
        } else if (val.length < 2) {
            nameErr.textContent = 'Full Name must be at least 2 characters.';
            nameInput.classList.add('is-invalid');
            return false;
        }
        nameErr.textContent = '';
        nameInput.classList.remove('is-invalid');
        return true;
    }

    function validateEmail() {
        if (!emailInput) return true;
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

    if (nameInput) {
        nameInput.addEventListener('input', validateName);
        nameInput.addEventListener('blur', validateName);
    }
    if (emailInput) {
        emailInput.addEventListener('input', validateEmail);
        emailInput.addEventListener('blur', validateEmail);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            const v1 = validateName();
            const v2 = validateEmail();
            if (!v1 || !v2) {
                e.preventDefault();
                if (!v1 && nameInput) nameInput.focus();
                else if (!v2 && emailInput) emailInput.focus();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>