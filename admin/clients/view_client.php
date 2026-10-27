<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
$pageTitle = 'View Client';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    setFlash('error', 'Client not found.');
    header('Location: list_clients.php');
    exit;
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Client Overview</h4>
                <p class="text-muted mb-0">Detailed view of <?php echo htmlspecialchars($client['name']); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="edit_client.php?id=<?php echo $client['id']; ?>" class="btn btn-primary">
                    <i class="bi bi-pencil me-1"></i> Edit Client
                </a>
                <a href="list_clients.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="row g-4" style="max-width: 900px;">
            <div class="col-md-4">
                <div class="card p-4 text-center shadow-sm">
                    <div class="mx-auto mb-3">
                        <?php if (!empty($client['profile_pic']) && file_exists(__DIR__ . '/../../assets/uploads/profile/' . $client['profile_pic'])): ?>
                            <img src="/client-flow/assets/uploads/profile/<?php echo htmlspecialchars($client['profile_pic']); ?>" 
                                 class="rounded-circle object-fit-cover shadow-sm" 
                                 style="width:84px;height:84px;border:3px solid #ffffff;box-shadow: 0 4px 12px rgba(0,0,0,0.12);" 
                                 alt="<?php echo htmlspecialchars($client['name']); ?>">
                        <?php else: ?>
                            <div class="mx-auto avatar-circle bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width:84px;height:84px;font-size:32px;font-weight:600;">
                                <?php echo strtoupper(substr($client['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($client['name']); ?></h5>
                    <p class="text-muted mb-2"><?php echo htmlspecialchars($client['company_name'] ?: 'Individual Client'); ?></p>

                    <div class="mb-3">
                        <span class="badge bg-light text-dark border font-monospace px-3 py-2 fs-6">
                            <?php echo htmlspecialchars($client['client_code']); ?>
                        </span>
                    </div>

                    <div class="mb-2">
                        <?php if ($client['status'] == 1): ?>
                            <span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle me-1"></i> Active</span>
                        <?php else: ?>
                            <span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle me-1"></i> Inactive</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($client['updated_at'])): ?>
                        <div class="text-muted small mt-2" style="font-size: 11px;">
                            <i class="bi bi-clock-history me-1"></i> Updated: <?php echo date('d M Y, h:i A', strtotime($client['updated_at'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card p-4">
                    <h6 class="fw-bold mb-3 border-bottom pb-2">Client Details</h6>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="text-muted small">Email Address</label>
                            <div class="fw-semibold"><i class="bi bi-envelope text-primary me-1"></i> <?php echo htmlspecialchars($client['email']); ?></div>
                        </div>

                        <div class="col-sm-6">
                            <label class="text-muted small">Phone Number</label>
                            <div class="fw-semibold"><i class="bi bi-telephone text-primary me-1"></i> <?php echo htmlspecialchars($client['phone'] ?: 'N/A'); ?></div>
                        </div>

                        <div class="col-sm-6">
                            <label class="text-muted small">Assigned Projects</label>
                            <div class="fw-semibold">
                                <a href="../projects/list_projects.php?search=<?php echo urlencode($client['client_code']); ?>" class="text-decoration-none">
                                    <i class="bi bi-folder-check text-primary me-1"></i> View Client Projects &rarr;
                                </a>
                            </div>
                        </div>

                        <div class="col-sm-6">
                            <label class="text-muted small">Member Since</label>
                            <div class="fw-semibold"><i class="bi bi-calendar3 text-primary me-1"></i> <?php echo date('d M Y, h:i A', strtotime($client['created_at'])); ?></div>
                        </div>

                        <div class="col-12">
                            <label class="text-muted small">Address / Location</label>
                            <div class="fw-semibold"><i class="bi bi-geo-alt text-primary me-1"></i> <?php echo nl2br(htmlspecialchars($client['address'] ?: 'Not Provided')); ?></div>
                        </div>

                        <div class="col-12 mt-4 pt-3 border-top d-flex gap-2">
                            <a href="edit_client.php?id=<?php echo $client['id']; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-pencil me-1"></i> Edit Details
                            </a>
                            <a href="delete_client.php?id=<?php echo $client['id']; ?>&token=<?php echo generateCsrfToken(); ?>" 
                               class="btn btn-outline-danger btn-sm btn-delete"
                               data-confirm-text="Are you sure you want to delete client &quot;<?php echo htmlspecialchars($client['name'], ENT_QUOTES); ?>&quot; and all associated records?"
                               data-confirm-title="Delete Client"
                               data-confirm-btn="Delete"
                               data-confirm-color="danger">
                                <i class="bi bi-trash me-1"></i> Delete Client
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
