<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('projects_manage');
$pageTitle = 'Edit Project';

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));

if ($id <= 0) {
    setFlash('error', 'Invalid project ID.');
    header('Location: list_projects.php');
    exit;
}

// Fetch project with client info
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS client_name, c.client_code, c.company_name, c.email AS client_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    setFlash('error', 'Project not found or has been deleted.');
    header('Location: list_projects.php');
    exit;
}

// Fetch financial summary of milestones (excluding deleted)
$finStmt = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_milestones,
        COALESCE(SUM(amount), 0) AS billed_amount,
        COALESCE(SUM(paid_amount), 0) AS paid_amount
    FROM payments 
    WHERE project_id = ? AND deleted_at IS NULL
");
$finStmt->execute([$id]);
$fin = $finStmt->fetch();

$totalMilestones = (int) ($fin['total_milestones'] ?? 0);
$billedAmount    = (float) ($fin['billed_amount'] ?? 0.00);
$paidAmount      = (float) ($fin['paid_amount'] ?? 0.00);
$currentBudget   = (float) $project['total_amount'];
$remainingBudget = max(0.00, $currentBudget - $billedAmount);
$balanceDue      = max(0.00, $billedAmount - $paidAmount);

// Fetch all existing project milestones
$mStmt = $pdo->prepare("
    SELECT * 
    FROM payments 
    WHERE project_id = ? AND deleted_at IS NULL 
    ORDER BY due_date ASC, id ASC
");
$mStmt->execute([$id]);
$existingMilestones = $mStmt->fetchAll();
$existingMilestonesMap = [];
$existingMilestonesData = [];
foreach ($existingMilestones as $em) {
    $existingMilestonesMap[(int)$em['id']] = $em;
    $existingMilestonesData[] = [
        'id'          => (int) $em['id'],
        'invoice_no'  => $em['invoice_no'],
        'title'       => $em['title'],
        'amount'      => (float) $em['amount'],
        'paid_amount' => (float) ($em['paid_amount'] ?? 0),
        'due_date'    => $em['due_date'] ?? '',
        'status'      => $em['status'],
        'is_advance'  => (int) ($em['is_advance'] ?? 0)
    ];
}

// Check if client is locked (if milestones/invoices exist or client agreement is signed or payment received)
$isClientLocked  = ($totalMilestones > 0 || !empty($project['agreed_at']) || $paidAmount > 0);

// Fetch all active clients for dropdown
$clients = $pdo->query("SELECT id, client_code, name, company_name, email FROM clients WHERE status = 1 ORDER BY name ASC")->fetchAll();
$fieldErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // If client is locked, preserve original client_id
    if ($isClientLocked) {
        $client_id = (int) $project['client_id'];
    } else {
        $client_id = (int) ($_POST['client_id'] ?? 0);
    }

    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $total_amount = (float) ($_POST['total_amount'] ?? 0.00);
    $deadline     = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $status       = trim($_POST['status'] ?? 'waiting_confirmation');

    $allowedStatuses = ['draft', 'waiting_confirmation', 'advance_payment_pending', 'in_progress', 'review', 'on_hold', 'completed', 'cancelled'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = $project['status'];
    }

    $prjCreatedDate = date('Y-m-d', strtotime($project['created_at']));
    if ($client_id <= 0) {
        $fieldErrors['client_id'] = 'Please select a valid client.';
    }
    if ($title === '') {
        $fieldErrors['title'] = 'Project Title is required.';
    } elseif (strlen($title) < 3) {
        $fieldErrors['title'] = 'Project Title must be at least 3 characters.';
    }
    // Parse milestones if enabled
    $enableMilestones = !empty($_POST['enable_milestones']);
    if (!$enableMilestones) {
        $fieldErrors['milestones'] = 'Please enable Payment Milestones.';
    }
    $milestoneIds      = $_POST['milestone_id'] ?? [];
    $milestoneTitles   = $_POST['milestone_title'] ?? [];
    $milestoneAmounts  = $_POST['milestone_amount'] ?? [];
    $milestoneDueDates = $_POST['milestone_due_date'] ?? [];

    $parsedMilestones = [];
    $totalAllocatedSubmitted = 0.00;
    if ($enableMilestones && is_array($milestoneTitles)) {
        foreach ($milestoneTitles as $idx => $rawT) {
            $t   = trim($rawT);
            $mid = (int) ($milestoneIds[$idx] ?? 0);
            $amt = round((float) ($milestoneAmounts[$idx] ?? 0), 2);
            $due = trim($milestoneDueDates[$idx] ?? '');

            if ($t === '') {
                $fieldErrors['milestones'] = 'All milestone titles are required.';
                break;
            }
            if ($amt <= 0) {
                $fieldErrors['milestones'] = 'Milestone amounts must be greater than 0.';
                break;
            }
            if (empty($due)) {
                $fieldErrors['milestones'] = 'All milestone scheduled due dates are required.';
                break;
            }

            if ($mid > 0 && isset($existingMilestonesMap[$mid])) {
                $mPaid = (float) ($existingMilestonesMap[$mid]['paid_amount'] ?? 0);
                if ($amt < $mPaid - 0.005) {
                    $fieldErrors['milestones'] = "Milestone '{$t}' cannot be reduced below collected amount: ₹" . number_format($mPaid, 2) . ".";
                    break;
                }
            }

            $parsedMilestones[] = [
                'id'       => $mid,
                'title'    => $t,
                'amount'   => $amt,
                'due_date' => $due
            ];
            $totalAllocatedSubmitted += $amt;
        }

        if (empty($fieldErrors['milestones'])) {
            if ($totalAllocatedSubmitted > $total_amount + 0.01) {
                $fieldErrors['milestones'] = "Total allocated milestones (₹" . number_format($totalAllocatedSubmitted, 2) . ") cannot exceed the total project budget (₹" . number_format($total_amount, 2) . ").";
            }
        }
    }

    if ($total_amount <= 0) {
        $fieldErrors['total_amount'] = 'Total Budget is required and must be greater than 0.';
    } elseif ($total_amount < $paidAmount) {
        $fieldErrors['total_amount'] = "Project budget cannot be reduced below the amount already collected: ₹" . number_format($paidAmount, 2) . ".";
    } elseif (!$enableMilestones && $total_amount < $billedAmount) {
        $fieldErrors['total_amount'] = "Project budget cannot be reduced below total allocated milestones: ₹" . number_format($billedAmount, 2) . ".";
    }
    if (empty($deadline)) {
        $fieldErrors['deadline'] = 'Project Deadline is required.';
    } elseif ($deadline < $prjCreatedDate) {
        $fieldErrors['deadline'] = "Project deadline cannot be earlier than project creation date: " . date('d M Y', strtotime($prjCreatedDate)) . ".";
    }

    if (!empty($fieldErrors)) {
        setFlash('error', 'Please resolve the highlighted field errors below.');
    } else {
        try {
            $oldStatus = $project['status'];
            $oldBudget = (float) $project['total_amount'];

            $upd = $pdo->prepare("
                UPDATE projects 
                SET client_id = ?, title = ?, description = ?, total_amount = ?, deadline = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$client_id, $title, $description, $total_amount, $deadline, $status, $id]);

            // Keep payments synced to new client if client was changed
            if ($client_id !== (int) $project['client_id']) {
                $pdo->prepare("UPDATE payments SET client_id = ? WHERE project_id = ?")->execute([$client_id, $id]);
            }

            // Audit Log
            $changes = [];
            if ($oldBudget !== $total_amount) {
                $changes[] = "Budget: ₹" . number_format($oldBudget, 2) . " → ₹" . number_format($total_amount, 2);
            }
            if ($oldStatus !== $status) {
                $changes[] = "Status: " . ucfirst(str_replace('_', ' ', $oldStatus)) . " → " . ucfirst(str_replace('_', ' ', $status));
            }
            if ($project['title'] !== $title) {
                $changes[] = "Title updated";
            }

            // Save/Update/Insert Milestones if enabled
            if ($enableMilestones && !empty($parsedMilestones)) {
                $submittedIds = [];
                $newCreatedCount = 0;
                $updatedCount = 0;

                $hasAdvance = false;
                foreach ($existingMilestones as $em) {
                    if (!empty($em['is_advance'])) {
                        $hasAdvance = true;
                        break;
                    }
                }

                $invNum = 1001;
                $stPayMax = $pdo->query("SELECT invoice_no FROM payments ORDER BY id DESC LIMIT 1");
                $lastPay = $stPayMax->fetch();
                if ($lastPay && preg_match('/INV-(\d+)/', $lastPay['invoice_no'], $mMatches)) {
                    $invNum = ((int) $mMatches[1]) + 1;
                }

                $insStmt = $pdo->prepare("
                    INSERT INTO payments (invoice_no, project_id, client_id, title, amount, paid_amount, due_date, status, payment_method, is_advance)
                    VALUES (?, ?, ?, ?, ?, 0.00, ?, 'pending', NULL, ?)
                ");
                $updStmt = $pdo->prepare("
                    UPDATE payments 
                    SET title = ?, amount = ?, due_date = ?, updated_at = NOW()
                    WHERE id = ? AND project_id = ? AND deleted_at IS NULL
                ");

                foreach ($parsedMilestones as $pm) {
                    if ($pm['id'] > 0 && isset($existingMilestonesMap[$pm['id']])) {
                        $updStmt->execute([$pm['title'], $pm['amount'], $pm['due_date'], $pm['id'], $id]);
                        $submittedIds[] = $pm['id'];
                        $updatedCount++;
                    } else {
                        $invNo = 'INV-' . $invNum;
                        $invNum++;
                        $isAdv = (!$hasAdvance && $newCreatedCount === 0 && empty($submittedIds)) ? 1 : 0;
                        $insStmt->execute([$invNo, $id, $client_id, $pm['title'], $pm['amount'], $pm['due_date'], $isAdv]);
                        $newCreatedCount++;
                    }
                }

                // Soft-delete unpaid milestones that were deleted in the UI
                foreach ($existingMilestones as $em) {
                    if (!in_array((int)$em['id'], $submittedIds, true)) {
                        $emPaid = (float)($em['paid_amount'] ?? 0);
                        if ($emPaid <= 0.005) {
                            $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE id = ? AND project_id = ?")->execute([$em['id'], $id]);
                        }
                    }
                }

                if ($newCreatedCount > 0) {
                    $changes[] = "Added {$newCreatedCount} new milestone(s)";
                }
                if ($updatedCount > 0) {
                    $changes[] = "Milestones updated";
                }
            }

            $changeSummary = !empty($changes) ? implode(', ', $changes) : "Details updated";

            addProjectLog($pdo, $id, 'updated', 'Project Details Updated', "Project {$project['project_code']} updated by {$admin['name']}. ({$changeSummary})", 'admin', $admin['name'] ?? 'Admin');

            // Recalculate status consistency
            syncProjectStatus($pdo, $id);

            setFlash('success', "Project {$project['project_code']} ({$title}) updated successfully!");
            header("Location: view_project.php?id={$id}");
            exit;
        } catch (PDOException $e) {
            setFlash('error', 'Database Error: ' . $e->getMessage());
        }
    }
}

$flash = getFlash();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">

        <!-- Header & Breadcrumb -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="../dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="list_projects.php" class="text-decoration-none">Projects</a></li>
                        <li class="breadcrumb-item"><a href="view_project.php?id=<?php echo $id; ?>" class="text-decoration-none"><?php echo htmlspecialchars($project['project_code']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit Project</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit Project: <?php echo htmlspecialchars($project['project_code']); ?>
                </h4>
            </div>
            <div class="d-flex gap-2">
                <a href="view_project.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-eye me-1"></i> View Project
                </a>
                <a href="list_projects.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Projects
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Edit Form (Matches add_project.php structure and labels) -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-sliders me-1 text-primary"></i> Project Information
                        </h6>
                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($project['project_code']); ?></span>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="" id="editProjectForm" novalidate>
                            <input type="hidden" name="id" value="<?php echo $id; ?>">

                            <div class="row g-3">
                                <!-- Project Code (Permanent / Disabled) -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Project Code <span class="badge bg-light text-muted border ms-1" style="font-size: 10px;">Permanent</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                        <input type="text" class="form-control font-monospace text-uppercase bg-light fw-bold" value="<?php echo htmlspecialchars($project['project_code']); ?>" readonly disabled style="cursor: not-allowed;">
                                    </div>
                                </div>

                                <!-- Select Client -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Select Client <span class="text-danger">*</span>
                                        <?php if ($isClientLocked): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1" style="font-size: 10px;"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                                        <?php endif; ?>
                                    </label>
                                    <?php if ($isClientLocked): ?>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-person-lock"></i></span>
                                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($project['client_name']); ?> · <?php echo htmlspecialchars($project['client_code']); ?><?php echo !empty($project['company_name']) ? ' · ' . htmlspecialchars($project['company_name']) : ''; ?>" readonly disabled style="cursor: not-allowed; font-weight: 500;">
                                        </div>
                                        <input type="hidden" name="client_id" value="<?php echo (int) $project['client_id']; ?>">
                                    <?php else: ?>
                                        <select name="client_id" id="prjClientId" class="form-select <?php echo !empty($fieldErrors['client_id']) ? 'is-invalid' : ''; ?>" required>
                                            <option value="">-- Choose Client --</option>
                                            <?php foreach ($clients as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo ((int)$project['client_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['name']); ?> · <?php echo htmlspecialchars($c['client_code']); ?><?php echo !empty($c['company_name']) ? ' · ' . htmlspecialchars($c['company_name']) : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['client_id']) ? 'd-none' : ''; ?>" id="client_idError">
                                            <?php echo htmlspecialchars($fieldErrors['client_id'] ?? ''); ?>
                                        </p>
                                        <div class="form-text small text-muted">Selected client will receive project notifications & invoices.</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Project Title -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Project Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" id="prjTitle" class="form-control <?php echo !empty($fieldErrors['title']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['title'] ?? $project['title']); ?>" placeholder="e.g. E-Commerce Website & Mobile App" required minlength="3" maxlength="200">
                                    <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['title']) ? 'd-none' : ''; ?>" id="titleError">
                                        <?php echo htmlspecialchars($fieldErrors['title'] ?? ''); ?>
                                    </p>
                                </div>

                                <!-- Total Budget -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Total Budget <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" step="0.01" min="<?php echo max(1, $paidAmount, $billedAmount); ?>" name="total_amount" id="prjTotalBudget" class="form-control fw-bold <?php echo !empty($fieldErrors['total_amount']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['total_amount'] ?? $project['total_amount']); ?>" placeholder="e.g. 4000.00" required>
                                    </div>
                                    <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['total_amount']) ? 'd-none' : ''; ?>" id="total_amountError">
                                        <?php echo htmlspecialchars($fieldErrors['total_amount'] ?? ''); ?>
                                    </p>
                                    <div class="form-text small text-muted">
                                        Already paid: <strong class="text-success">₹<?php echo number_format($paidAmount, 2); ?></strong> · Milestones allocated: <strong class="text-primary">₹<?php echo number_format($billedAmount, 2); ?></strong>
                                    </div>
                                </div>

                                <!-- Project Deadline -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Project Deadline <span class="text-danger">*</span></label>
                                    <input type="date" name="deadline" id="prjDeadline" class="form-control <?php echo !empty($fieldErrors['deadline']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['deadline'] ?? ($project['deadline'] ?? '')); ?>" min="<?php echo date('Y-m-d', strtotime($project['created_at'])); ?>" required>
                                    <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['deadline']) ? 'd-none' : ''; ?>" id="deadlineError">
                                        <?php echo htmlspecialchars($fieldErrors['deadline'] ?? ''); ?>
                                    </p>
                                </div>

                                <!-- Project Status -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Project Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select" required>
                                        <?php $curStatus = $_POST['status'] ?? $project['status']; ?>
                                        <option value="waiting_confirmation" <?php echo $curStatus === 'waiting_confirmation' ? 'selected' : ''; ?>>Waiting Confirmation</option>
                                        <option value="draft" <?php echo $curStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="advance_payment_pending" <?php echo $curStatus === 'advance_payment_pending' ? 'selected' : ''; ?>>Advance Payment Pending</option>
                                        <option value="in_progress" <?php echo $curStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="review" <?php echo $curStatus === 'review' ? 'selected' : ''; ?>>Review / Approval</option>
                                        <option value="on_hold" <?php echo $curStatus === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                        <option value="completed" <?php echo $curStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $curStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <!-- Description / Scope of Work -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Description / Scope of Work</label>
                                    <textarea name="description" class="form-control" rows="3" placeholder="Detail deliverables, client responsibilities..."><?php echo htmlspecialchars($_POST['description'] ?? ($project['description'] ?? '')); ?></textarea>
                                </div>

                                <!-- TOGGLE SWITCH FOR PAYMENT MILESTONES -->
                                <div class="col-12 mt-3">
                                    <div class="form-check form-switch p-3 bg-light rounded border">
                                        <input
                                            class="form-check-input ms-0 me-2"
                                            type="checkbox"
                                            id="toggleMilestones"
                                            name="enable_milestones"
                                            value="1"
                                            <?php echo !empty($_POST['enable_milestones']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold text-dark fs-6" for="toggleMilestones" style="cursor: pointer;">
                                            <i class="bi bi-diagram-3-fill text-primary me-1"></i>Payment Milestones
                                            <?php if ($totalMilestones > 0): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2 font-monospace" style="font-size: 11px;"><?php echo $totalMilestones; ?> Configured</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>

                                <!-- COLLAPSIBLE MILESTONES SECTION -->
                                <div id="milestonesSection" class="col-12" style="<?php echo !empty($_POST['enable_milestones']) ? 'display:block;' : 'display:none;'; ?>">
                                    <div class="card p-3 border border-primary-subtle bg-light">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                            <div>
                                                <h6 class="fw-bold mb-0 text-dark">
                                                    <i class="bi bi-magic me-1 text-primary"></i>Milestone Schedule Generator
                                                </h6>
                                                <small class="text-muted">Manage existing milestones or allocate additional budget.</small>
                                            </div>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary" id="btnPresetAuto" title="Allocate remaining unallocated budget into a new milestone">
                                                    <i class="bi bi-magic me-1"></i>Allocate Remaining
                                                </button>
                                                <button type="button" class="btn btn-outline-primary" id="btnPresetSplit2" title="Split remaining budget into 2 milestones">
                                                    Split in 2
                                                </button>
                                                <button type="button" class="btn btn-outline-primary" id="btnPresetSplit3" title="Split remaining budget into 3 milestones">
                                                    Split in 3
                                                </button>
                                            </div>
                                        </div>

                                        <?php if (!empty($fieldErrors['milestones'])): ?>
                                            <div class="alert alert-danger py-2 px-3 small mb-2">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($fieldErrors['milestones']); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Milestones Table -->
                                        <div class="table-responsive my-2">
                                            <table class="table table-bordered align-middle mb-0 bg-white" id="milestonesTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 40px;" class="text-center">#</th>
                                                        <th>Milestone Title <span class="text-danger">*</span></th>
                                                        <th style="width: 180px;">Amount ₹ <span class="text-danger">*</span></th>
                                                        <th style="width: 170px;">Scheduled Due Date <span class="text-danger">*</span></th>
                                                        <th style="width: 110px;" class="text-center">Status</th>
                                                        <th style="width: 50px;" class="text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="milestoneRowsContainer">
                                                    <!-- Dynamically generated rows -->
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddMilestoneRow">
                                                <i class="bi bi-plus-circle me-1"></i> Add Milestone Row
                                            </button>
                                            <span class="text-muted small">
                                                <i class="bi bi-shield-lock me-1"></i> Settled / paid milestones cannot be removed or reduced below paid amount.
                                            </span>
                                        </div>

                                        <!-- Real-time Live Allocation Tracker -->
                                        <div class="d-flex justify-content-between align-items-center bg-white p-2 px-3 rounded border flex-wrap gap-2">
                                            <div>
                                                <span class="text-muted small fw-semibold">Total Budget:</span>
                                                <strong class="text-dark ms-1" id="trackBudget">₹0.00</strong>
                                                <span class="text-muted mx-2">|</span>
                                                <span class="text-muted small fw-semibold">Allocated:</span>
                                                <strong class="text-primary ms-1" id="trackAllocated">₹0.00</strong>
                                                <span class="text-muted mx-2">|</span>
                                                <span class="text-muted small fw-semibold">Unallocated:</span>
                                                <strong class="ms-1" id="trackUnallocated">₹0.00</strong>
                                            </div>
                                            <span class="badge py-2 px-3 fs-6 bg-secondary" id="trackBadge">
                                                <i class="bi bi-info-circle me-1"></i> Check Allocation
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-4 pt-2 border-top d-flex gap-2">
                                    <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnSubmitEditProject">
                                        <i class="bi bi-check-circle-fill me-1"></i> Save Changes
                                    </button>
                                    <a href="view_project.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary px-3">Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right: Financial & Lifecycle Overview Card -->
            <div class="col-lg-4">
                <!-- Financial Overview -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-wallet2 text-success me-1"></i> Financial Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small">Total Project Budget:</span>
                                <strong class="fs-6 text-dark">₹<?php echo number_format($currentBudget, 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small">Milestones Allocated:</span>
                                <span class="fw-semibold text-primary">₹<?php echo number_format($billedAmount, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small">Client Paid So Far:</span>
                                <span class="fw-bold text-success">₹<?php echo number_format($paidAmount, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <span class="text-muted small">Remaining Balance Due:</span>
                                <strong class="text-danger fs-6">₹<?php echo number_format($balanceDue, 2); ?></strong>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <a href="view_project.php?id=<?php echo $id; ?>#milestones" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-diagram-3-fill me-1"></i> Manage Milestones (<?php echo $totalMilestones; ?>)
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Client & Contract Snapshot -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-person-check text-primary me-1"></i> Client & Contract
                        </h6>
                    </div>
                    <div class="card-body small">
                        <div class="mb-2">
                            <span class="text-muted d-block">Client Name:</span>
                            <strong class="text-dark"><?php echo htmlspecialchars($project['client_name']); ?></strong>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted d-block">Email Address:</span>
                            <span><?php echo htmlspecialchars($project['client_email']); ?></span>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted d-block">Proposal Dispatched:</span>
                            <span><?php echo !empty($project['proposal_sent_at']) ? date('d M Y, h:i A', strtotime($project['proposal_sent_at'])) : '<span class="text-muted">Not yet sent</span>'; ?></span>
                        </div>
                        <div class="mb-0">
                            <span class="text-muted d-block">Client Agreement:</span>
                            <?php if (!empty($project['agreed_at'])): ?>
                                <span class="text-success fw-semibold">
                                    <i class="bi bi-check-circle-fill me-1"></i> Accepted on <?php echo date('d M Y', strtotime($project['agreed_at'])); ?>
                                </span>
                                <?php if (!empty($project['agreed_ip'])): ?>
                                    <div class="text-muted font-monospace" style="font-size: 11px;">IP: <?php echo htmlspecialchars($project['agreed_ip']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-warning">
                                    <i class="bi bi-clock-history me-1"></i> Pending acceptance
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('editProjectForm');
        const clientSelect = document.getElementById('prjClientId');
        const titleInput = document.getElementById('prjTitle');
        const budgetInput = document.getElementById('prjTotalBudget');
        const deadlineInput = document.getElementById('prjDeadline');
        const btnSubmit = document.getElementById('btnSubmitEditProject');

        const paidAmount = <?php echo (float)$paidAmount; ?>;
        const billedAmount = <?php echo (float)$billedAmount; ?>;
        const projectCreated = '<?php echo date("Y-m-d", strtotime($project["created_at"])); ?>';

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

        function validateClient() {
            if (!clientSelect) return true;
            if (!clientSelect.value || parseInt(clientSelect.value) <= 0) {
                setFieldError('client_idError', clientSelect, 'Please select a client from the dropdown.');
                return false;
            }
            clearFieldValidation('client_idError', clientSelect);
            return true;
        }

        function validateTitle() {
            if (!titleInput) return true;
            const val = titleInput.value.trim();
            if (!val) {
                setFieldError('titleError', titleInput, 'Project Title is required.');
                return false;
            }
            if (val.length < 3) {
                setFieldError('titleError', titleInput, 'Project Title must be at least 3 characters.');
                return false;
            }
            clearFieldValidation('titleError', titleInput);
            return true;
        }

        const toggleSwitch = document.getElementById('toggleMilestones');
        const milestoneSec = document.getElementById('milestonesSection');
        const rowsContainer = document.getElementById('milestoneRowsContainer');
        const trackBudget = document.getElementById('trackBudget');
        const trackAllocated = document.getElementById('trackAllocated');
        const trackUnallocated = document.getElementById('trackUnallocated');
        const trackBadge = document.getElementById('trackBadge');

        const btnPresetAuto = document.getElementById('btnPresetAuto');
        const btnPresetSplit2 = document.getElementById('btnPresetSplit2');
        const btnPresetSplit3 = document.getElementById('btnPresetSplit3');
        const btnAddRow = document.getElementById('btnAddMilestoneRow');

        const initialMilestones = <?php echo json_encode($existingMilestonesData); ?>;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function createMilestoneRow(index, m) {
            const tr = document.createElement('tr');
            tr.className = 'milestone-row';

            const mid = m.id || 0;
            const title = m.title || `Milestone ${index}: Phase Deliverable`;
            const amount = m.amount ? parseFloat(m.amount).toFixed(2) : '';
            const paid = parseFloat(m.paid_amount) || 0;
            const dueDate = m.due_date || (deadlineInput ? deadlineInput.value : '');
            const isPaid = (paid > 0 && paid >= parseFloat(amount || 0) - 0.005);
            const isPartial = (paid > 0 && !isPaid);

            let statusBadge = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">New</span>';
            if (mid > 0) {
                if (isPaid) {
                    statusBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="₹${paid.toFixed(2)} Paid"><i class="bi bi-check-circle me-1"></i>Paid</span>`;
                } else if (isPartial) {
                    statusBadge = `<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1" title="₹${paid.toFixed(2)} Paid">Partial (₹${paid.toFixed(0)})</span>`;
                } else {
                    statusBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Pending</span>';
                }
            }

            const minAmount = paid > 0 ? paid.toFixed(2) : '0.01';
            const actionHtml = paid > 0 ?
                '<span class="text-muted small" title="Cannot remove milestone with recorded payments"><i class="bi bi-lock-fill"></i></span>' :
                `<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 btn-remove-row" title="Remove Milestone"><i class="bi bi-x-lg"></i></button>`;

            tr.innerHTML = `
            <td class="text-center fw-bold text-muted row-index">${index}</td>
            <td>
                <input type="hidden" name="milestone_id[]" value="${mid}">
                <input type="text" name="milestone_title[]" class="form-control form-control-sm row-title" value="${escapeHtml(title)}" placeholder="Milestone Phase Title" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">₹</span>
                    <input type="number" step="0.01" min="${minAmount}" name="milestone_amount[]" class="form-control form-control-sm fw-bold row-amount" value="${amount}" placeholder="0.00" required>
                </div>
                ${paid > 0 ? `<small class="text-muted d-block mt-1" style="font-size:10px;">Min allowed: ₹${paid.toFixed(2)}</small>` : ''}
            </td>
            <td>
                <input type="date" name="milestone_due_date[]" class="form-control form-control-sm row-date" value="${dueDate}" min="${projectCreated}" required>
            </td>
            <td class="text-center">
                ${statusBadge}
            </td>
            <td class="text-center">
                ${actionHtml}
            </td>
        `;

            tr.querySelector('.row-amount').addEventListener('input', recalculateMilestoneAllocations);
            const removeBtn = tr.querySelector('.btn-remove-row');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    tr.remove();
                    reindexRows();
                    recalculateMilestoneAllocations();
                });
            }

            return tr;
        }

        function reindexRows() {
            if (!rowsContainer) return;
            const rows = rowsContainer.querySelectorAll('.milestone-row');
            rows.forEach((row, idx) => {
                const idxEl = row.querySelector('.row-index');
                if (idxEl) idxEl.textContent = idx + 1;
            });
        }

        function recalculateMilestoneAllocations() {
            if (!toggleSwitch || !toggleSwitch.checked || !rowsContainer) {
                if (btnSubmit) btnSubmit.disabled = false;
                return;
            }

            const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
            const amountInputs = rowsContainer.querySelectorAll('.row-amount');
            let totalAllocated = 0;

            amountInputs.forEach(input => {
                const val = parseFloat(input.value) || 0;
                totalAllocated += val;
            });

            const diff = totalBudget - totalAllocated;

            if (trackBudget) trackBudget.textContent = '₹' + totalBudget.toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            if (trackAllocated) trackAllocated.textContent = '₹' + totalAllocated.toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            if (trackUnallocated) trackUnallocated.textContent = '₹' + Math.max(0, diff).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            if (!trackBadge) return;

            if (totalBudget <= 0) {
                trackBadge.className = 'badge py-2 px-3 fs-6 bg-secondary';
                trackBadge.innerHTML = '<i class="bi bi-info-circle me-1"></i> Enter Total Budget Above';
                if (btnSubmit) btnSubmit.disabled = false;
            } else if (amountInputs.length === 0) {
                trackBadge.className = 'badge py-2 px-3 fs-6 bg-secondary';
                trackBadge.innerHTML = '<i class="bi bi-info-circle me-1"></i> No Milestones Defined';
                if (btnSubmit) btnSubmit.disabled = false;
            } else if (Math.abs(diff) < 0.01) {
                trackBadge.className = 'badge py-2 px-3 fs-6 bg-success';
                trackBadge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> 100% Budget Allocated';
                if (btnSubmit) btnSubmit.disabled = false;
            } else if (diff > 0) {
                trackBadge.className = 'badge py-2 px-3 fs-6 bg-warning text-dark';
                trackBadge.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ₹${diff.toLocaleString('en-IN', {minimumFractionDigits: 2})} Remaining`;
                if (btnSubmit) btnSubmit.disabled = false;
            } else {
                trackBadge.className = 'badge py-2 px-3 fs-6 bg-danger';
                trackBadge.innerHTML = `<i class="bi bi-x-circle-fill me-1"></i> Over Budget by ₹${Math.abs(diff).toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
                if (btnSubmit) btnSubmit.disabled = true;
            }
        }

        // Populate initial rows from existing milestones
        if (rowsContainer && initialMilestones && initialMilestones.length > 0) {
            initialMilestones.forEach((m, idx) => {
                rowsContainer.appendChild(createMilestoneRow(idx + 1, m));
            });
            recalculateMilestoneAllocations();
        }

        if (toggleSwitch) {
            toggleSwitch.addEventListener('change', function() {
                if (toggleSwitch.checked) {
                    if (milestoneSec) milestoneSec.style.display = 'block';
                    if (rowsContainer && rowsContainer.children.length === 0) {
                        const bVal = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                        if (bVal > 0) {
                            rowsContainer.appendChild(createMilestoneRow(1, {
                                title: 'Milestone 1: Deliverable Phase',
                                amount: bVal,
                                due_date: deadlineInput ? deadlineInput.value : ''
                            }));
                        }
                    }
                    recalculateMilestoneAllocations();
                } else {
                    if (milestoneSec) milestoneSec.style.display = 'none';
                    if (btnSubmit) btnSubmit.disabled = false;
                }
            });
        }

        // Preset: Allocate remaining full
        if (btnPresetAuto) {
            btnPresetAuto.addEventListener('click', function() {
                const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                let currentAlloc = 0;
                if (rowsContainer) {
                    rowsContainer.querySelectorAll('.row-amount').forEach(i => currentAlloc += (parseFloat(i.value) || 0));
                }
                const rem = Math.max(0, totalBudget - currentAlloc);
                if (rem > 0.005) {
                    const nextIdx = rowsContainer ? rowsContainer.children.length + 1 : 1;
                    rowsContainer.appendChild(createMilestoneRow(nextIdx, {
                        title: `Milestone ${nextIdx}: Deliverable Phase`,
                        amount: rem,
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    reindexRows();
                    recalculateMilestoneAllocations();
                } else {
                    alert('All budget is already allocated! Increase Total Budget above to add more milestones.');
                }
            });
        }

        // Preset: Split remaining in 2
        if (btnPresetSplit2) {
            btnPresetSplit2.addEventListener('click', function() {
                const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                let currentAlloc = 0;
                if (rowsContainer) {
                    rowsContainer.querySelectorAll('.row-amount').forEach(i => currentAlloc += (parseFloat(i.value) || 0));
                }
                const rem = Math.max(0, totalBudget - currentAlloc);
                if (rem > 0.005) {
                    const part1 = (rem / 2).toFixed(2);
                    const part2 = (rem - parseFloat(part1)).toFixed(2);
                    const nextIdx = rowsContainer ? rowsContainer.children.length + 1 : 1;
                    rowsContainer.appendChild(createMilestoneRow(nextIdx, {
                        title: `Milestone ${nextIdx}: Development Phase`,
                        amount: parseFloat(part1),
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    rowsContainer.appendChild(createMilestoneRow(nextIdx + 1, {
                        title: `Milestone ${nextIdx + 1}: Final Handover`,
                        amount: parseFloat(part2),
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    reindexRows();
                    recalculateMilestoneAllocations();
                } else {
                    alert('All budget is already allocated! Increase Total Budget above to add more milestones.');
                }
            });
        }

        // Preset: Split remaining in 3
        if (btnPresetSplit3) {
            btnPresetSplit3.addEventListener('click', function() {
                const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                let currentAlloc = 0;
                if (rowsContainer) {
                    rowsContainer.querySelectorAll('.row-amount').forEach(i => currentAlloc += (parseFloat(i.value) || 0));
                }
                const rem = Math.max(0, totalBudget - currentAlloc);
                if (rem > 0.005) {
                    const part1 = (rem * 0.33).toFixed(2);
                    const part2 = (rem * 0.33).toFixed(2);
                    const part3 = (rem - (parseFloat(part1) + parseFloat(part2))).toFixed(2);
                    const nextIdx = rowsContainer ? rowsContainer.children.length + 1 : 1;
                    rowsContainer.appendChild(createMilestoneRow(nextIdx, {
                        title: `Milestone ${nextIdx}: Phase 1 Deliverables`,
                        amount: parseFloat(part1),
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    rowsContainer.appendChild(createMilestoneRow(nextIdx + 1, {
                        title: `Milestone ${nextIdx + 1}: Phase 2 Deliverables`,
                        amount: parseFloat(part2),
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    rowsContainer.appendChild(createMilestoneRow(nextIdx + 2, {
                        title: `Milestone ${nextIdx + 2}: Final Handover`,
                        amount: parseFloat(part3),
                        due_date: deadlineInput ? deadlineInput.value : ''
                    }));
                    reindexRows();
                    recalculateMilestoneAllocations();
                } else {
                    alert('All budget is already allocated! Increase Total Budget above to add more milestones.');
                }
            });
        }

        // Add Milestone Row
        if (btnAddRow) {
            btnAddRow.addEventListener('click', function() {
                const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                let currentAlloc = 0;
                if (rowsContainer) {
                    rowsContainer.querySelectorAll('.row-amount').forEach(i => currentAlloc += (parseFloat(i.value) || 0));
                }
                const rem = Math.max(0, totalBudget - currentAlloc);
                const nextIdx = rowsContainer ? rowsContainer.children.length + 1 : 1;
                rowsContainer.appendChild(createMilestoneRow(nextIdx, {
                    title: `Milestone ${nextIdx}: Deliverable Phase`,
                    amount: rem > 0 ? rem : '',
                    due_date: deadlineInput ? deadlineInput.value : ''
                }));
                reindexRows();
                recalculateMilestoneAllocations();
            });
        }

        function validateBudget() {
            if (!budgetInput) return true;
            const val = parseFloat(budgetInput.value) || 0;
            if (val <= 0) {
                setFieldError('total_amountError', budgetInput, 'Total Budget is required and must be greater than 0.');
                return false;
            }
            if (val < paidAmount) {
                setFieldError('total_amountError', budgetInput, `Project budget cannot be reduced below collected amount: ₹${paidAmount.toFixed(2)}.`);
                return false;
            }
            if (!toggleSwitch || !toggleSwitch.checked) {
                if (val < billedAmount) {
                    setFieldError('total_amountError', budgetInput, `Project budget cannot be reduced below allocated milestones: ₹${billedAmount.toFixed(2)}.`);
                    return false;
                }
            }
            clearFieldValidation('total_amountError', budgetInput);
            return true;
        }

        function validateDeadline() {
            if (!deadlineInput) return true;
            const val = deadlineInput.value;
            if (!val) {
                setFieldError('deadlineError', deadlineInput, 'Project Deadline is required.');
                return false;
            }
            if (val < projectCreated) {
                setFieldError('deadlineError', deadlineInput, `Project deadline cannot be earlier than project start date (${projectCreated}).`);
                return false;
            }
            clearFieldValidation('deadlineError', deadlineInput);
            return true;
        }

        if (clientSelect) {
            clientSelect.addEventListener('change', validateClient);
        }
        if (titleInput) {
            titleInput.addEventListener('input', validateTitle);
            titleInput.addEventListener('blur', validateTitle);
        }
        if (budgetInput) {
            budgetInput.addEventListener('input', function() {
                validateBudget();
                recalculateMilestoneAllocations();
            });
            budgetInput.addEventListener('blur', validateBudget);
        }
        if (deadlineInput) {
            deadlineInput.addEventListener('change', validateDeadline);
            deadlineInput.addEventListener('blur', validateDeadline);
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                let isValid = true;
                // Payment Milestones checkbox validation
                if (toggleSwitch && !toggleSwitch.checked) {
                    alert('Please enable Payment Milestones before saving changes.');
                    isValid = false;
                }
                if (!validateClient()) isValid = false;
                if (!validateTitle()) isValid = false;
                if (!validateBudget()) isValid = false;
                if (!validateDeadline()) isValid = false;

                if (toggleSwitch && toggleSwitch.checked && rowsContainer) {
                    const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                    let totalAllocated = 0;
                    let hasEmptyTitle = false;
                    let hasZeroAmount = false;
                    let hasEmptyDate = false;

                    const rows = rowsContainer.querySelectorAll('.milestone-row');
                    rows.forEach(r => {
                        const t = r.querySelector('.row-title');
                        const a = r.querySelector('.row-amount');
                        const d = r.querySelector('.row-date');

                        if (!t || !t.value.trim()) hasEmptyTitle = true;
                        const aVal = parseFloat(a ? a.value : 0) || 0;
                        if (aVal <= 0) hasZeroAmount = true;
                        totalAllocated += aVal;
                        if (!d || !d.value) hasEmptyDate = true;
                    });

                    if (hasEmptyTitle || hasZeroAmount || hasEmptyDate) {
                        alert('Please complete all milestone titles, positive amounts, and scheduled due dates.');
                        isValid = false;
                    } else if (totalAllocated > totalBudget + 0.01) {
                        alert(`Total allocated milestones (₹${totalAllocated.toFixed(2)}) cannot exceed the total project budget (₹${totalBudget.toFixed(2)}).`);
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    const firstInvalid = form.querySelector('.is-invalid');
                    if (firstInvalid) firstInvalid.focus();
                } else {
                    if (btnSubmit) {
                        btnSubmit.disabled = true;
                        btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving Changes...';
                    }
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>