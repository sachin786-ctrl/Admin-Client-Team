<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('projects_manage');
$pageTitle = 'Add Project';

// Fetch all active clients for dropdown
$clients = $pdo->query("SELECT id, client_code, name, company_name, email FROM clients WHERE status = 1 ORDER BY name ASC")->fetchAll();
$preClientId = (int) ($_GET['client_id'] ?? 0);
$fieldErrors = [];
$formData = [
    'client_id'    => $preClientId,
    'title'        => '',
    'description'  => '',
    'total_amount' => '',
    'deadline'     => date('Y-m-d'),
    'status'       => 'waiting_confirmation'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id        = (int) ($_POST['client_id'] ?? 0);
    $title            = trim($_POST['title'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $total_amount     = (float) ($_POST['total_amount'] ?? 0.00);
    $deadline         = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $status           = $_POST['status'] ?? 'waiting_confirmation';
    $enableMilestones = isset($_POST['enable_milestones']) && $_POST['enable_milestones'] === '1';

    $formData['client_id']    = $client_id;
    $formData['title']        = $title;
    $formData['description']  = $description;
    $formData['total_amount'] = $_POST['total_amount'] ?? '';
    $formData['deadline']     = $deadline;
    $formData['status']       = $status;

    $allowedStatuses = ['draft', 'waiting_confirmation'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'waiting_confirmation';
    }

    if ($client_id <= 0) {
        $fieldErrors['client_id'] = 'Please select a client.';
    }
    if ($title === '') {
        $fieldErrors['title'] = 'Project Title is required.';
    } elseif (strlen($title) < 3) {
        $fieldErrors['title'] = 'Project Title must be at least 3 characters.';
    }
    if ($total_amount <= 0) {
        $fieldErrors['total_amount'] = 'Total Budget is required and must be greater than 0.';
    }
    if (empty($deadline)) {
        $fieldErrors['deadline'] = 'Project Deadline is required.';
    }

    if (!empty($fieldErrors)) {
        // Halt processing and let form render with field-specific errors
    } else {

    // Process milestones if toggle was active
    $validMilestones = [];
    $totalMilestoneAllocated = 0.00;
    if ($enableMilestones) {
        $mTitles   = $_POST['milestone_title'] ?? [];
        $mAmounts  = $_POST['milestone_amount'] ?? [];
        $mDueDates = $_POST['milestone_due_date'] ?? [];

        if (is_array($mTitles)) {
            foreach ($mTitles as $idx => $rawT) {
                $t = trim($rawT);
                $a = (float)($mAmounts[$idx] ?? 0);
                $d = trim($mDueDates[$idx] ?? '');
                if ($t !== '' && $a > 0 && !empty($d)) {
                    $validMilestones[] = [
                        'title'    => $t,
                        'amount'   => $a,
                        'due_date' => $d
                    ];
                    $totalMilestoneAllocated += $a;
                }
            }
        }
    }

    try {
        // Auto-generate project_code: PRJ001, PRJ002...
        $stmt = $pdo->query("SELECT project_code FROM projects ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetch();
        $newNum = $last ? ((int) substr($last['project_code'], 3)) + 1 : 1;
        $project_code = 'PRJ' . str_pad($newNum, 3, '0', STR_PAD_LEFT);
        $agreement_token = bin2hex(random_bytes(16));

        $insert = $pdo->prepare("
            INSERT INTO projects (project_code, client_id, title, description, total_amount, deadline, status, proposal_sent_at, agreement_token, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        $insert->execute([$project_code, $client_id, $title, $description, $total_amount, $deadline, $status, $agreement_token, $admin['id']]);
        $newProjectId = (int) $pdo->lastInsertId();

        // Project Creation Audit Log
        addProjectLog($pdo, $newProjectId, 'created', 'Project Created', "Project {$project_code} created with total budget ₹" . number_format($total_amount, 2) . ".", 'admin', $admin['name'] ?? 'Admin');

        // Insert milestones if provided
        $createdMilestonesCount = 0;
        if ($enableMilestones && !empty($validMilestones)) {
            // Find starting invoice number
            $stPay = $pdo->query("SELECT invoice_no FROM payments ORDER BY id DESC LIMIT 1");
            $lastPay = $stPay->fetch();
            $invNum = 1001;
            if ($lastPay && preg_match('/INV-(\d+)/', $lastPay['invoice_no'], $mMatches)) {
                $invNum = ((int) $mMatches[1]) + 1;
            }

            $insPay = $pdo->prepare("
                INSERT INTO payments (invoice_no, project_id, client_id, title, amount, paid_amount, due_date, status, payment_method, is_advance)
                VALUES (?, ?, ?, ?, ?, 0.00, ?, 'pending', NULL, ?)
            ");

            foreach ($validMilestones as $idx => $vm) {
                $invNo = 'INV-' . $invNum;
                $invNum++;
                $insPay->execute([$invNo, $newProjectId, $client_id, $vm['title'], $vm['amount'], $vm['due_date'], $idx === 0 ? 1 : 0]);
                $createdMilestonesCount++;
            }

            // Project Audit Log for milestones
            addProjectLog($pdo, $newProjectId, 'milestones_configured', 'Payment Milestones Configured', "Configured {$createdMilestonesCount} milestone installments totaling ₹" . number_format($totalMilestoneAllocated, 2) . ".", 'admin', $admin['name'] ?? 'Admin');
        }

        // Notify Admin
        addNotification($pdo, 'admin', $admin['id'], 'New Project Created', "Project '{$title}' ({$project_code}) was created.", "/client-flow/admin/projects/view_project.php?id={$newProjectId}", 'success', 'bi-folder-plus');

        // Automatically dispatch proposal confirmation email to client if requested or status waiting_confirmation
        $dispatchProposal = (!empty($_POST['send_proposal']) || in_array($status, ['waiting_confirmation', 'proposal_sent'], true));
        $emailNotice = '';
        if ($dispatchProposal) {
            $mailRes = sendProjectProposalEmail($pdo, $newProjectId);
            if (!empty($mailRes['success'])) {
                $emailNotice = " Proposal and agreement email sent successfully via SMTP.";
            } else {
                $emailNotice = " (Proposal email notice: " . ($mailRes['message'] ?? 'Could not dispatch') . ")";
            }
            addNotification($pdo, 'client', $client_id, 'New Project Proposal', "New project proposal '{$title}' ({$project_code}) has been scheduled for your agreement.", "/client-flow/client/dashboard.php", 'primary', 'bi-folder-plus');
        } else {
            addNotification($pdo, 'client', $client_id, 'New Project Assigned', "Project '{$title}' ({$project_code}) has been created for your account.", '/client-flow/client/projects.php', 'primary', 'bi-folder-plus');
        }

        // Sync status
        syncProjectStatus($pdo, $newProjectId);

        if ($createdMilestonesCount > 0) {
            setFlash('success', "Project {$project_code} created successfully with {$createdMilestonesCount} payment milestones!{$emailNotice}");
            header('Location: view_project.php?id=' . $newProjectId);
        } else {
            setFlash('success', "Project {$project_code} created successfully! Please configure the payment milestones.{$emailNotice}");
            header('Location: ../payments/add_payment.php?project_id=' . $newProjectId);
        }
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Database Error: ' . $e->getMessage());
        header('Location: add_project.php');
        exit;
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

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Create New Project</h4>
                <p class="text-muted mb-0">Assign project scope, budget, and milestone payments to clients.</p>
            </div>
            <a href="list_projects.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Projects
            </a>
        </div>

        <?php if (empty($clients)): ?>
            <div class="card p-5 text-center my-4 bg-light border-0 shadow-sm mx-auto" style="max-width: 860px;">
                <div class="mb-3">
                    <i class="bi bi-people text-muted" style="font-size: 3.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark mb-2">No Clients Available</h4>
                <p class="text-muted mb-4">You cannot create a project without a client. Please add a client first.</p>
                <div>
                    <a href="../clients/add_client.php" class="btn btn-primary px-4">
                        <i class="bi bi-person-plus me-1"></i> Add First Client
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-center align-items-center">
                <div class="card p-4 shadow-sm" style="max-width: 900px; width: 100%;">
                    <form method="POST" action="" id="createProjectForm" novalidate>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Select Client <span class="text-danger">*</span></label>
                                <select name="client_id" id="prjClientId" class="form-select <?php echo !empty($fieldErrors['client_id']) ? 'is-invalid' : ''; ?>" required>
                                    <option value="">-- Choose Client --</option>
                                    <?php foreach ($clients as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo (int)$formData['client_id'] === (int) $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']); ?> · <?php echo htmlspecialchars($c['client_code']); ?><?php echo !empty($c['company_name']) ? ' · ' . htmlspecialchars($c['company_name']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['client_id']) ? 'd-none' : ''; ?>" id="client_idError">
                                    <?php echo htmlspecialchars($fieldErrors['client_id'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Project Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="prjTitle" class="form-control <?php echo !empty($fieldErrors['title']) ? 'is-invalid' : ''; ?>" 
                                       placeholder="e.g. E-Commerce Website & Mobile App" 
                                       value="<?php echo htmlspecialchars($formData['title']); ?>" 
                                       required minlength="3" maxlength="200">
                                <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['title']) ? 'd-none' : ''; ?>" id="titleError">
                                    <?php echo htmlspecialchars($fieldErrors['title'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Total Budget <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" min="1" name="total_amount" id="prjTotalBudget" 
                                           class="form-control fw-bold <?php echo !empty($fieldErrors['total_amount']) ? 'is-invalid' : ''; ?>" 
                                           placeholder="e.g. 4000.00" 
                                           value="<?php echo htmlspecialchars($formData['total_amount']); ?>" required>
                                </div>
                                <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['total_amount']) ? 'd-none' : ''; ?>" id="total_amountError">
                                    <?php echo htmlspecialchars($fieldErrors['total_amount'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Project Deadline <span class="text-danger">*</span></label>
                                <input type="date" name="deadline" id="prjDeadline" 
                                       class="form-control <?php echo !empty($fieldErrors['deadline']) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($formData['deadline']); ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                                <p class="text-danger small mt-1 mb-0 <?php echo empty($fieldErrors['deadline']) ? 'd-none' : ''; ?>" id="deadlineError">
                                    <?php echo htmlspecialchars($fieldErrors['deadline'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Project Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="waiting_confirmation" <?php echo $formData['status'] === 'waiting_confirmation' ? 'selected' : ''; ?>>Waiting Confirmation</option>
                                    <option value="draft" <?php echo $formData['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="advance_payment_pending" <?php echo $formData['status'] === 'advance_payment_pending' ? 'selected' : ''; ?>>Advance Payment Pending</option>
                                    <option value="in_progress" <?php echo $formData['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="review" <?php echo $formData['status'] === 'review' ? 'selected' : ''; ?>>Review / Approval</option>
                                    <option value="on_hold" <?php echo $formData['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                    <option value="completed" <?php echo $formData['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $formData['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Description / Work Scope</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Detail deliverables, client responsibilities..."><?php echo htmlspecialchars($formData['description']); ?></textarea>
                            </div>

                            <!-- TOGGLE SWITCH FOR 1-CLICK MILESTONE GENERATOR -->
                            <div class="col-12 mt-3">
                                <div class="form-check form-switch p-3 bg-light rounded border">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" id="toggleMilestones" name="enable_milestones" value="1">
                                    <label class="form-check-label fw-bold text-dark fs-6" for="toggleMilestones" style="cursor: pointer;">
                                        <i class="bi bi-diagram-3-fill text-primary me-1"></i>Payment Milestones
                                    </label>
                                </div>
                            </div>

                            <!-- COLLAPSIBLE 1-CLICK MILESTONES SECTION -->
                            <div id="milestonesSection" class="col-12">
                                <div class="card p-3 border border-primary-subtle bg-light">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">
                                                <i class="bi bi-magic me-1 text-primary"></i>Milestone Schedule Generator
                                            </h6>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" id="btnPreset4">4 Milestones</button>
                                            <button type="button" class="btn btn-outline-primary" id="btnPreset3">3 Milestones</button>
                                            <button type="button" class="btn btn-outline-primary" id="btnPreset2">2 Milestones</button>
                                            <button type="button" class="btn btn-outline-secondary" id="btnPreset1">Full Payment</button>
                                        </div>
                                    </div>

                                    <!-- Milestones Table -->
                                    <div class="table-responsive my-2">
                                        <table class="table table-bordered align-middle mb-0 bg-white" id="milestonesTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 40px;" class="text-center">#</th>
                                                    <th>Milestone Title <span class="text-danger">*</span></th>
                                                    <th style="width: 180px;">Amount ₹ <span class="text-danger">*</span></th>
                                                    <th style="width: 170px;">Scheduled Due Date <span class="text-danger">*</span></th>
                                                    <th style="width: 50px;" class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="milestoneRowsContainer">
                                                <!-- Dynamically generated rows -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddMilestoneRow">
                                            <i class="bi bi-plus-circle me-1"></i> Add Milestone Row
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnClearMilestones">
                                            <i class="bi bi-trash me-1"></i> Reset Milestones
                                        </button>
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
                                            <i class="bi bi-info-circle me-1"></i> Enter Total Budget Above
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="form-check p-2 bg-light border rounded">
                                    <input class="form-check-input ms-1 me-2" type="checkbox" name="send_proposal" value="1" id="sendProposalCheck" checked>
                                    <label class="form-check-label fw-semibold text-dark" for="sendProposalCheck">
                                        <i class="bi bi-envelope-paper-fill text-primary me-1"></i> Send formal proposal & milestone agreement email to client immediately
                                    </label>
                                </div>
                            </div>

                            <div class="col-12 mt-3 pt-2 border-top">
                                <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnSubmitProject">
                                    <i class="bi bi-check-circle-fill me-1"></i> Create Project & Dispatch Proposal
                                </button>
                                <a href="list_projects.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleSwitch = document.getElementById('toggleMilestones');
        const milestoneSec = document.getElementById('milestonesSection');
        const budgetInput = document.getElementById('prjTotalBudget');
        const deadlineInput = document.getElementById('prjDeadline');
        const rowsContainer = document.getElementById('milestoneRowsContainer');
        const btnSubmit = document.getElementById('btnSubmitProject');
        const projectForm = document.getElementById('createProjectForm');

        const trackBudget = document.getElementById('trackBudget');
        const trackAllocated = document.getElementById('trackAllocated');
        const trackUnallocated = document.getElementById('trackUnallocated');
        const trackBadge = document.getElementById('trackBadge');

        const btnPreset4 = document.getElementById('btnPreset4');
        const btnPreset3 = document.getElementById('btnPreset3');
        const btnPreset2 = document.getElementById('btnPreset2');
        const btnPreset1 = document.getElementById('btnPreset1');
        const btnAddRow = document.getElementById('btnAddMilestoneRow');
        const btnClear = document.getElementById('btnClearMilestones');

        if (!toggleSwitch || !rowsContainer) {
            return;
        }

        function parseDate(str) {
            if (!str) return addDays(new Date(), 30);
            const parts = str.split('-');
            if (parts.length === 3) {
                return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            }
            return new Date(str);
        }

        function formatDate(dateObj) {
            const year = dateObj.getFullYear();
            const month = String(dateObj.getMonth() + 1).padStart(2, '0');
            const day = String(dateObj.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function addDays(dateObj, days) {
            const res = new Date(dateObj.getTime());
            res.setDate(res.getDate() + days);
            return res;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function calculateDistributedDates(count, targetDeadline) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const deadline = parseDate(targetDeadline);
            deadline.setHours(0, 0, 0, 0);
            const dates = [];

            if (count <= 1) {
                dates.push(formatDate(deadline));
                return dates;
            }

            const totalDays = Math.max(1, Math.round((deadline.getTime() - today.getTime()) / (1000 * 60 * 60 * 24)));
            const dayStep = Math.max(1, Math.floor(totalDays / count));

            for (let i = 1; i <= count; i++) {
                if (i === count) {
                    dates.push(formatDate(deadline));
                } else {
                    const stepDate = addDays(today, dayStep * i);
                    if (stepDate >= deadline) {
                        dates.push(formatDate(deadline));
                    } else {
                        dates.push(formatDate(stepDate));
                    }
                }
            }
            return dates;
        }

        function createMilestoneRow(index, title, amount, dueDate) {
            const tr = document.createElement('tr');
            tr.className = 'milestone-row';
            tr.innerHTML = `
            <td class="text-center fw-bold text-muted row-index">${index}</td>
            <td>
                <input type="text" name="milestone_title[]" class="form-control form-control-sm row-title" value="${escapeHtml(title)}" placeholder="Milestone Deliverable / Phase Title" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">₹</span>
                    <input type="number" step="0.01" min="0.01" name="milestone_amount[]" class="form-control form-control-sm fw-bold row-amount" value="${amount}" placeholder="0.00" required>
                </div>
            </td>
            <td>
                <input type="date" name="milestone_due_date[]" class="form-control form-control-sm row-date" value="${dueDate}" min="${formatDate(new Date())}" max="${deadlineInput ? deadlineInput.value : ''}" required>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 btn-remove-row" title="Remove Milestone">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;

            tr.querySelector('.row-amount').addEventListener('input', recalculateMilestoneAllocations);
            tr.querySelector('.btn-remove-row').addEventListener('click', function() {
                tr.remove();
                reindexRows();
                recalculateMilestoneAllocations();
            });

            return tr;
        }

        function reindexRows() {
            const rows = rowsContainer.querySelectorAll('.milestone-row');
            rows.forEach((row, idx) => {
                const idxEl = row.querySelector('.row-index');
                if (idxEl) idxEl.textContent = idx + 1;
            });
        }

        function recalculateMilestoneAllocations() {
            if (!toggleSwitch || !toggleSwitch.checked) {
                if (btnSubmit) btnSubmit.disabled = false;
                return;
            }

            const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
            const amountInputs = rowsContainer.querySelectorAll('.row-amount');
            let totalAllocated = 0;
            let filledCount = 0;

            amountInputs.forEach(input => {
                const val = parseFloat(input.value) || 0;
                if (val > 0) filledCount++;
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
                if (btnSubmit) btnSubmit.disabled = true;
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

        function generateMilestones(count) {
            rowsContainer.innerHTML = '';
            const targetAmount = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
            const targetDeadline = deadlineInput ? deadlineInput.value : '';
            const dates = calculateDistributedDates(count, targetDeadline);

            const titles4 = [
                'Milestone 1: Advance',
                'Milestone 2: Frontend',
                'Milestone 3: Backend',
                'Milestone 4: Handover'
            ];

            const titles3 = [
                'Milestone 1: Advance',
                'Milestone 2: Development',
                'Milestone 3: Handover'
            ];

            const titles2 = [
                'Milestone 1: Advance',
                'Milestone 2: Handover'
            ];

            if (count === 4) {
                const part = targetAmount > 0 ? (targetAmount / 4).toFixed(2) : '';
                for (let i = 0; i < 4; i++) {
                    let amt = part;
                    if (targetAmount > 0 && i === 3) {
                        amt = (targetAmount - (parseFloat(part) * 3)).toFixed(2);
                    }
                    rowsContainer.appendChild(createMilestoneRow(i + 1, titles4[i], amt, dates[i]));
                }
            } else if (count === 3) {
                let amt1 = '',
                    amt2 = '',
                    amt3 = '';
                if (targetAmount > 0) {
                    amt1 = (targetAmount * 0.30).toFixed(2);
                    amt2 = (targetAmount * 0.30).toFixed(2);
                    amt3 = (targetAmount - (parseFloat(amt1) + parseFloat(amt2))).toFixed(2);
                }
                const amounts = [amt1, amt2, amt3];
                for (let i = 0; i < 3; i++) {
                    rowsContainer.appendChild(createMilestoneRow(i + 1, titles3[i], amounts[i], dates[i]));
                }
            } else if (count === 2) {
                let amt1 = '',
                    amt2 = '';
                if (targetAmount > 0) {
                    amt1 = (targetAmount * 0.50).toFixed(2);
                    amt2 = (targetAmount - parseFloat(amt1)).toFixed(2);
                }
                rowsContainer.appendChild(createMilestoneRow(1, titles2[0], amt1, dates[0]));
                rowsContainer.appendChild(createMilestoneRow(2, titles2[1], amt2, dates[1]));
            } else if (count === 1) {
                const amt1 = targetAmount > 0 ? targetAmount.toFixed(2) : '';
                rowsContainer.appendChild(createMilestoneRow(1, 'Milestone 1: Full Payment', amt1, dates[0]));
            }

            recalculateMilestoneAllocations();
        }

        // Toggle switch handler
        toggleSwitch.addEventListener('change', function() {
            if (toggleSwitch.checked) {
                if (milestoneSec) milestoneSec.style.display = 'block';
                if (rowsContainer.children.length === 0) {
                    generateMilestones(4);
                } else {
                    recalculateMilestoneAllocations();
                }
            } else {
                if (milestoneSec) milestoneSec.style.display = 'none';
                if (btnSubmit) btnSubmit.disabled = false;
            }
        });

        // Preset buttons with smart guidance
        if (btnPreset4) {
            btnPreset4.addEventListener('click', function() {
                generateMilestones(4);
                if (!budgetInput || !budgetInput.value || parseFloat(budgetInput.value) <= 0) {
                    budgetInput?.focus();
                }
            });
        }

        if (btnPreset3) {
            btnPreset3.addEventListener('click', function() {
                generateMilestones(3);
                if (!budgetInput || !budgetInput.value || parseFloat(budgetInput.value) <= 0) {
                    budgetInput?.focus();
                }
            });
        }

        if (btnPreset2) {
            btnPreset2.addEventListener('click', function() {
                generateMilestones(2);
                if (!budgetInput || !budgetInput.value || parseFloat(budgetInput.value) <= 0) {
                    budgetInput?.focus();
                }
            });
        }

        if (btnPreset1) {
            btnPreset1.addEventListener('click', function() {
                generateMilestones(1);
                if (!budgetInput || !budgetInput.value || parseFloat(budgetInput.value) <= 0) {
                    budgetInput?.focus();
                }
            });
        }

        if (btnAddRow) {
            btnAddRow.addEventListener('click', function() {
                const newIndex = rowsContainer.children.length + 1;
                const targetTotal = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                let currentAllocated = 0;
                rowsContainer.querySelectorAll('.row-amount').forEach(i => currentAllocated += (parseFloat(i.value) || 0));
                const rem = Math.max(0, targetTotal - currentAllocated);
                const ddl = deadlineInput && deadlineInput.value ? deadlineInput.value : formatDate(addDays(new Date(), 30));
                rowsContainer.appendChild(createMilestoneRow(newIndex, `Milestone ${newIndex}: Phase Deliverable`, rem > 0 ? rem.toFixed(2) : '', ddl));
                recalculateMilestoneAllocations();
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', function() {
                rowsContainer.innerHTML = '';
                recalculateMilestoneAllocations();
            });
        }

        // Automatically recalculate and distribute amounts when user types in Total Budget
        if (budgetInput) {
            budgetInput.addEventListener('input', function() {
                const rows = rowsContainer.querySelectorAll('.milestone-row');
                const numRows = rows.length;
                if (numRows === 4) {
                    generateMilestones(4);
                } else if (numRows === 3) {
                    generateMilestones(3);
                } else if (numRows === 2) {
                    generateMilestones(2);
                } else if (numRows === 1) {
                    generateMilestones(1);
                } else if (numRows > 0) {
                    const targetAmount = parseFloat(budgetInput.value) || 0;
                    if (targetAmount > 0) {
                        const perRow = (targetAmount / numRows).toFixed(2);
                        let acc = 0;
                        rows.forEach((r, idx) => {
                            const amtIn = r.querySelector('.row-amount');
                            if (amtIn) {
                                if (idx === numRows - 1) {
                                    amtIn.value = (targetAmount - acc).toFixed(2);
                                } else {
                                    amtIn.value = perRow;
                                    acc += parseFloat(perRow);
                                }
                            }
                        });
                    }
                    recalculateMilestoneAllocations();
                } else {
                    recalculateMilestoneAllocations();
                }
            });
        }

        // Update due dates when deadline changes
        if (deadlineInput) {
            deadlineInput.addEventListener('change', function() {
                const rows = rowsContainer.querySelectorAll('.milestone-row');
                if (rows.length > 0) {
                    const dates = calculateDistributedDates(rows.length, deadlineInput.value);
                    rows.forEach((r, idx) => {
                        const dateIn = r.querySelector('.row-date');
                        if (dateIn && dates[idx]) {
                            dateIn.value = dates[idx];
                            dateIn.max = deadlineInput.value;
                        }
                    });
                }
            });
        }

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

        const clientSelect = document.getElementById('prjClientId');
        const titleInput = document.getElementById('prjTitle');

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

        function validateBudget() {
            if (!budgetInput) return true;
            const val = parseFloat(budgetInput.value) || 0;
            if (val <= 0) {
                setFieldError('total_amountError', budgetInput, 'Total Budget is required and must be greater than 0.');
                return false;
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
            budgetInput.addEventListener('input', validateBudget);
            budgetInput.addEventListener('blur', validateBudget);
        }
        if (deadlineInput) {
            deadlineInput.addEventListener('change', validateDeadline);
            deadlineInput.addEventListener('blur', validateDeadline);
        }

        // Client-side form submission safety check
        if (projectForm) {
            projectForm.addEventListener('submit', function(e) {
                let isValid = true;
                if (!validateClient()) isValid = false;
                if (!validateTitle()) isValid = false;
                if (!validateBudget()) isValid = false;
                if (!validateDeadline()) isValid = false;

                if (toggleSwitch && toggleSwitch.checked) {
                    const totalBudget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
                    const rows = rowsContainer.querySelectorAll('.milestone-row');
                    if (rows.length === 0) {
                        e.preventDefault();
                        if (typeof window.showAppToast === 'function') {
                            window.showAppToast('Please configure at least one milestone or uncheck the milestone switch.', 'warning', 'Milestone Error');
                        }
                        return false;
                    }
                    let totalAllocated = 0;
                    let hasEmptyAmount = false;
                    rows.forEach(r => {
                        const val = parseFloat(r.querySelector('.row-amount')?.value) || 0;
                        if (val <= 0) hasEmptyAmount = true;
                        totalAllocated += val;
                    });
                    if (hasEmptyAmount) {
                        e.preventDefault();
                        if (typeof window.showAppToast === 'function') {
                            window.showAppToast('Please specify a valid amount greater than 0 for each milestone deliverable.', 'warning', 'Milestone Amount');
                        }
                        return false;
                    }
                    const diff = totalBudget - totalAllocated;
                    if (Math.abs(diff) >= 0.01) {
                        e.preventDefault();
                        if (typeof window.showAppToast === 'function') {
                            window.showAppToast('Milestone allocations: ₹' + totalAllocated.toFixed(2) + ' must match Total Budget: ₹' + totalBudget.toFixed(2) + '.', 'danger', 'Budget Mismatch');
                        }
                        return false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    const firstInvalid = projectForm.querySelector('.is-invalid');
                    if (firstInvalid) firstInvalid.focus();
                } else {
                    if (btnSubmit) {
                        btnSubmit.disabled = true;
                        btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Creating Project...';
                    }
                }
            });
        }

        // Initialize Payment Milestones
        if (toggleSwitch) {
            toggleSwitch.checked = false;

            if (milestoneSec) {
                milestoneSec.style.display = 'none';
            }

            if (btnSubmit) {
                btnSubmit.disabled = false;
            }

            toggleSwitch.addEventListener('change', function() {
                if (toggleSwitch.checked) {
                    if (milestoneSec) {
                        milestoneSec.style.display = 'block';
                    }

                    if (rowsContainer.children.length === 0) {
                        generateMilestones(4);
                    }

                    recalculateMilestoneAllocations();
                } else {
                    if (milestoneSec) {
                        milestoneSec.style.display = 'none';
                    }

                    if (btnSubmit) {
                        btnSubmit.disabled = false;
                    }
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>