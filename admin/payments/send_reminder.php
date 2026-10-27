<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);
requirePermission('payments_remind');
$pageTitle = 'Send Payment Reminder';

$id       = (int) ($_GET['id'] ?? 0);
$type     = trim($_GET['type'] ?? ''); // 'email', 'whatsapp', or ''
$redirect = $_GET['redirect'] ?? '';
$isForce  = (isset($_GET['force']) && $_GET['force'] === '1');

$stmt = $pdo->prepare("
    SELECT pay.*, p.title AS project_title, p.project_code,
           c.name AS client_name, c.company_name, c.email AS client_email, c.phone AS client_phone
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    JOIN clients c ON c.id = pay.client_id
    WHERE pay.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('error', 'Payment not found.');
    header('Location: list_payments.php');
    exit;
}

$client = [
    'id'    => $payment['client_id'],
    'name'  => $payment['client_name'],
    'email' => $payment['client_email'],
    'phone' => $payment['client_phone']
];
$project = [
    'id'    => $payment['project_id'],
    'title' => $payment['project_title'],
    'code'  => $payment['project_code']
];

$totalAmount = (float) $payment['amount'];
$paidAmount  = (float) ($payment['paid_amount'] ?? ($payment['status'] === 'paid' ? $totalAmount : 0.00));
$dueAmount   = max(0, $totalAmount - $paidAmount);

// Cooldown Guard: 24-Hour Protection to prevent spamming clients
$lastSentTime = !empty($payment['last_reminder_sent_at']) ? strtotime($payment['last_reminder_sent_at']) : 0;
$isWithinCooldown = ($lastSentTime > 0 && (time() - $lastSentTime) < 86400);

// Action 1: Dedicated Send Email Trigger
if ($type === 'email') {
    if ($isWithinCooldown && !$isForce) {
        setFlash('warning', 'A reminder was sent ' . timeAgo($payment['last_reminder_sent_at']) . '. To avoid client spam, use "Send Anyway (Force)" below if urgent.');
        $backUrl = "send_reminder.php?id={$payment['id']}" . ($redirect ? "&redirect={$redirect}" : "") . "&warn_cooldown=1";
        header("Location: $backUrl");
        exit;
    }

    $emailResult = sendPaymentReminderEmail($client, $payment, $project, $admin, 'standard');

    try {
        $logStmt = $pdo->prepare("
            INSERT INTO reminder_logs (payment_id, client_id, type, sent_by, notes, sent_at)
            VALUES (?, ?, 'email', ?, ?, NOW())
        ");
        $notes = $emailResult['success'] 
            ? ($isForce ? '[Admin Manual - Force Send] ' : '[Admin Manual] ') . 'Reminder email sent to ' . $client['email'] 
            : 'Email dispatch failed: ' . $emailResult['message'];
        $logStmt->execute([$payment['id'], $client['id'], $admin['id'], $notes]);

        $updPay = $pdo->prepare("UPDATE payments SET last_reminder_sent_at = NOW() WHERE id = ?");
        $updPay->execute([$payment['id']]);

        addNotification($pdo, 'client', $client['id'], 'Payment Reminder', "Reminder sent for pending invoice {$payment['invoice_no']} ({$payment['title']}).", '/client-flow/client/payments.php', 'warning', 'bi-bell-fill');
    } catch (PDOException $e) {}

    if ($emailResult['success']) {
        setFlash('success', "Reminder email sent successfully to {$client['name']} ({$client['email']})!");
    } else {
        setFlash('warning', "Email dispatch attempted: " . $emailResult['message']);
    }

    $dest = ($redirect === 'project') ? "../projects/view_project.php?id={$project['id']}" : "list_payments.php";
    header("Location: $dest");
    exit;
}

// Action 2: Dedicated WhatsApp Direct Launch Trigger
$whatsAppUrl = '';
if (!empty($client['phone'])) {
    $whatsAppUrl = generateWhatsAppReminderUrl(
        $client['phone'],
        $client['name'],
        $project['title'],
        $dueAmount,
        $payment['due_date'],
        $payment['invoice_no'],
        $paidAmount,
        $totalAmount
    );
}

if ($type === 'whatsapp') {
    if ($isWithinCooldown && !$isForce) {
        setFlash('warning', 'A reminder was sent ' . timeAgo($payment['last_reminder_sent_at']) . '. Click "WhatsApp Anyway" below if needed.');
        $backUrl = "send_reminder.php?id={$payment['id']}" . ($redirect ? "&redirect={$redirect}" : "") . "&warn_cooldown=1";
        header("Location: $backUrl");
        exit;
    }

    try {
        $logStmt = $pdo->prepare("
            INSERT INTO reminder_logs (payment_id, client_id, type, sent_by, notes, sent_at)
            VALUES (?, ?, 'whatsapp', ?, ?, NOW())
        ");
        $notes = ($isForce ? '[Admin Manual - Force WhatsApp] ' : '[Admin Manual] ') . 'WhatsApp reminder link generated for ' . $client['phone'];
        $logStmt->execute([$payment['id'], $client['id'], $admin['id'], $notes]);

        $updPay = $pdo->prepare("UPDATE payments SET last_reminder_sent_at = NOW() WHERE id = ?");
        $updPay->execute([$payment['id']]);
    } catch (PDOException $e) {}

    if ($whatsAppUrl) {
        header("Location: $whatsAppUrl");
        exit;
    } else {
        setFlash('error', "Client phone number is missing. Cannot send WhatsApp reminder.");
        header("Location: list_payments.php");
        exit;
    }
}

// Fetch Reminder Logs for this payment
$stmtLogs = $pdo->prepare("
    SELECT rl.*, a.name AS admin_name, a.email AS admin_email
    FROM reminder_logs rl
    LEFT JOIN admin a ON a.id = rl.sent_by
    WHERE rl.payment_id = ?
    ORDER BY rl.sent_at DESC
");
$stmtLogs->execute([$payment['id']]);
$reminderLogs = $stmtLogs->fetchAll();

// Default Hub: Show both options clearly
$isOverdue = strtotime($payment['due_date']) < strtotime(date('Y-m-d'));
$balanceIcon = $isOverdue ? '*Due Amount (Overdue):*' : '*Pending Amount:*';
$statusLabel = $isOverdue ? 'Due (Overdue)' : 'Pending (Upcoming)';

$portalUrl = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/client-flow/client/payments.php";
$prefilledText = "Hello " . htmlspecialchars($client['name']) . ",\n\n"
               . "This is a friendly payment reminder for project *" . htmlspecialchars($project['title']) . "*.\n\n"
               . "*Invoice No:* " . htmlspecialchars($payment['invoice_no']) . "\n"
               . "*Total Amount:* ₹" . number_format($totalAmount, 2) . "\n"
               . "*Paid Amount:* ₹" . number_format($paidAmount, 2) . "\n"
               . "{$balanceIcon} ₹" . number_format($dueAmount, 2) . "\n"
               . "*Due Date:* " . date('d M Y', strtotime($payment['due_date'])) . "\n\n"
               . "Click here to view invoice and make payment:\n"
               . "{$portalUrl}\n\n"
               . "Kindly process the payment at your earliest convenience. Thank you!";
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="page-body">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold mb-0">Send Payment Reminder</h4>
                <p class="text-muted mb-0">Dispatches official email or WhatsApp reminder to client with invoice details.</p>
            </div>
            <a href="<?php echo $redirect === 'project' ? '../projects/view_project.php?id=' . $project['id'] : 'list_payments.php'; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to <?php echo $redirect === 'project' ? 'Project' : 'Payments'; ?>
            </a>
        </div>

        <!-- 24-Hour Cooldown Protection Warning Banner -->
        <?php if ($isWithinCooldown): ?>
            <div class="alert alert-warning border-start border-4 border-warning shadow-sm mb-4" style="max-width: 900px; border-radius: 12px;">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-warning text-dark rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; min-width: 44px;">
                            <i class="bi bi-shield-exclamation fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1 text-dark">24-Hour Reminder Cooldown Active</h6>
                            <p class="mb-0 text-muted small">
                                A reminder was sent on <strong><?php echo date('d M Y, h:i A', $lastSentTime); ?></strong> (<?php echo timeAgo($payment['last_reminder_sent_at']); ?>).
                                To prevent spamming the client, spacing reminders by 24 hours is recommended.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=email&force=1<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" class="btn btn-warning btn-sm fw-semibold shadow-sm">
                            <i class="bi bi-send-exclamation-fill me-1"></i> Force Send Email
                        </a>
                        <?php if (!empty($client['phone'])): ?>
                            <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=whatsapp&force=1<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" target="_blank" class="btn btn-outline-success btn-sm fw-semibold bg-white">
                                <i class="bi bi-whatsapp me-1"></i> Open WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Invoice Snapshot Card -->
        <div class="card p-3 mb-4 shadow-sm" style="max-width: 900px; border-radius: 12px;">
            <div class="row align-items-center g-3">
                <div class="col-md-3">
                    <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($payment['invoice_no']); ?></span>
                    <h6 class="fw-bold mt-1 mb-0"><?php echo htmlspecialchars($payment['title']); ?></h6>
                    <small class="text-muted"><?php echo htmlspecialchars($project['title']); ?></small>
                </div>
                <div class="col-md-3 text-center border-start">
                    <small class="text-muted d-block">Total Milestone</small>
                    <strong class="text-dark fs-6">₹<?php echo number_format($totalAmount, 2); ?></strong>
                </div>
                <div class="col-md-3 text-center border-start">
                    <small class="text-success d-block">Paid Amount</small>
                    <strong class="text-success fs-6">₹<?php echo number_format($paidAmount, 2); ?></strong>
                </div>
                <div class="col-md-3 text-center border-start">
                    <small class="<?php echo $isOverdue ? 'text-danger' : 'text-warning text-dark'; ?> d-block fw-semibold"><?php echo $statusLabel; ?></small>
                    <strong class="<?php echo $isOverdue ? 'text-danger' : 'text-warning text-dark'; ?> fs-5">₹<?php echo number_format($dueAmount, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="row g-4" style="max-width: 900px;">
            <!-- Option 1: Send Email -->
            <div class="col-md-6">
                <div class="card p-4 h-100 border-start border-4 border-primary shadow-sm" style="border-radius: 12px;">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="p-2 bg-primary bg-opacity-10 text-primary rounded">
                                <i class="bi bi-envelope-fill fs-4"></i>
                            </div>
                            <h5 class="fw-bold mb-0">1. Send Email</h5>
                        </div>
                        <span class="badge bg-primary">Option 1</span>
                    </div>

                    <p class="text-muted small">
                        Sends a formal HTML invoice reminder email directly to the client's inbox via PHPMailer with amount breakdown, due date, and payment portal link.
                    </p>

                    <div class="bg-light p-2 rounded mb-3 small">
                        <strong>Recipient:</strong> <?php echo htmlspecialchars($client['name']); ?><br>
                        <strong>Email:</strong> <span class="text-primary"><?php echo htmlspecialchars($client['email'] ?: 'No email available'); ?></span>
                    </div>

                    <div class="mt-auto">
                        <?php if (!empty($client['email'])): ?>
                            <?php if ($isWithinCooldown): ?>
                                <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=email&force=1<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" class="btn btn-warning w-100 py-2 fw-semibold">
                                    <i class="bi bi-send-exclamation-fill me-2"></i> Send Reminder Email (Force)
                                </a>
                                <small class="text-muted d-block text-center mt-2" style="font-size: 11px;">
                                    <i class="bi bi-clock-history me-1"></i>Last sent <?php echo timeAgo($payment['last_reminder_sent_at']); ?>
                                </small>
                            <?php else: ?>
                                <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=email<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" class="btn btn-primary w-100 py-2 fw-semibold">
                                    <i class="bi bi-send-fill me-2"></i> Send Reminder Email
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 py-2" disabled>
                                Client Email Missing
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Option 2: Send WhatsApp -->
            <div class="col-md-6">
                <div class="card p-4 h-100 border-start border-4 border-success shadow-sm" style="border-radius: 12px;">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="p-2 bg-success bg-opacity-10 text-success rounded">
                                <i class="bi bi-whatsapp fs-4"></i>
                            </div>
                            <h5 class="fw-bold mb-0">2. Send WhatsApp</h5>
                        </div>
                        <span class="badge bg-success">Option 2</span>
                    </div>

                    <p class="text-muted small">
                        Opens WhatsApp Web or App with a pre-written message containing the project name, invoice number, and pending balance.
                    </p>

                    <div class="bg-light p-2 rounded mb-3 small">
                        <strong>Client:</strong> <?php echo htmlspecialchars($client['name']); ?><br>
                        <strong>Phone:</strong> <span class="text-success"><?php echo htmlspecialchars($client['phone'] ?: 'No phone available'); ?></span>
                    </div>

                    <div class="mt-auto">
                        <?php if (!empty($client['phone'])): ?>
                            <?php if ($isWithinCooldown): ?>
                                <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=whatsapp&force=1<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" target="_blank" class="btn btn-outline-success w-100 py-2 fw-semibold">
                                    <i class="bi bi-whatsapp me-2"></i> Open WhatsApp (Force)
                                </a>
                                <small class="text-muted d-block text-center mt-2" style="font-size: 11px;">
                                    <i class="bi bi-clock-history me-1"></i>Last recorded <?php echo timeAgo($payment['last_reminder_sent_at']); ?>
                                </small>
                            <?php else: ?>
                                <a href="send_reminder.php?id=<?php echo $payment['id']; ?>&type=whatsapp<?php echo $redirect ? '&redirect=' . $redirect : ''; ?>" target="_blank" class="btn btn-success w-100 py-2 fw-semibold">
                                    <i class="bi bi-whatsapp me-2"></i> Open WhatsApp Message
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 py-2" disabled>
                                Client Phone Missing
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Message Preview -->
            <div class="col-12">
                <div class="card p-4 shadow-sm" style="border-radius: 12px;">
                    <h6 class="fw-bold mb-2"><i class="bi bi-chat-left-text me-2"></i>Pre-formatted Reminder Message Preview</h6>
                    <pre class="bg-light p-3 rounded border text-dark font-monospace mb-0" style="white-space: pre-wrap; font-size: 13px;"><?php echo $prefilledText; ?></pre>
                </div>
            </div>

            <!-- Reminder Dispatch History Section -->
            <div class="col-12">
                <div class="card p-4 shadow-sm" style="border-radius: 12px;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold mb-0">
                                <i class="bi bi-clock-history me-2 text-primary"></i>Reminder Dispatch History (This Invoice)
                            </h6>
                            <small class="text-muted">Complete log of automated and manual reminders sent for <?php echo htmlspecialchars($payment['invoice_no']); ?></small>
                        </div>
                        <span class="badge bg-secondary"><?php echo count($reminderLogs); ?> Reminders Sent</span>
                    </div>

                    <?php if (empty($reminderLogs)): ?>
                        <div class="text-center py-4 bg-light rounded-3 text-muted">
                            <i class="bi bi-bell-slash fs-2 d-block mb-1 text-muted opacity-50"></i>
                            <span class="small">No reminders have been dispatched yet for this milestone invoice.</span>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Channel</th>
                                        <th>Sender</th>
                                        <th>Notes / Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reminderLogs as $rl): 
                                        $isAutomated = str_starts_with($rl['notes'] ?? '', '[Automated');
                                    ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <div class="fw-semibold text-dark"><?php echo date('d M Y, h:i A', strtotime($rl['sent_at'])); ?></div>
                                                <small class="text-muted"><?php echo timeAgo($rl['sent_at']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($rl['type'] === 'email'): ?>
                                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1">
                                                        <i class="bi bi-envelope-fill me-1"></i>Email
                                                    </span>
                                                <?php elseif ($rl['type'] === 'whatsapp'): ?>
                                                    <span class="badge bg-success-subtle text-success border px-2 py-1">
                                                        <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                                                        <?php echo ucfirst($rl['type']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isAutomated): ?>
                                                    <span class="badge bg-info-subtle text-info border">
                                                        <i class="bi bi-robot me-1"></i>System Automation
                                                    </span>
                                                <?php else: ?>
                                                    <span class="fw-semibold text-dark"><?php echo htmlspecialchars($rl['admin_name'] ?: 'Admin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?php echo htmlspecialchars($rl['notes'] ?: 'No notes'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
