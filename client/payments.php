<?php
/**
 * Client Portal - My Payments & Invoices
 * c:\xampp\htdocs\client-flow\client\payments.php
 */

require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);
$pageTitle = 'My Payments & Invoices';
$flash = getClientFlash();

$filterStatus = trim($_GET['status'] ?? '');
$search       = trim($_GET['search'] ?? '');

$sql = "
    SELECT pay.*, 
           p.title AS project_title, p.project_code, p.status AS project_status, p.agreed_at AS project_agreed_at
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    WHERE pay.client_id = ? 
      AND pay.deleted_at IS NULL 
      AND p.deleted_at IS NULL 
      AND p.status != 'draft'
";
$params = [$client['id']];

if ($search !== '') {
    $sql .= " AND (pay.invoice_no LIKE ? OR pay.title LIKE ? OR p.title LIKE ? OR p.project_code LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$today = date('Y-m-d');
if ($filterStatus !== '') {
    if ($filterStatus === 'pending') {
        $sql .= " AND pay.status != 'paid' AND pay.paid_amount < pay.amount 
                  AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')";
    } elseif ($filterStatus === 'overdue') {
        $sql .= " AND pay.status != 'paid' AND pay.paid_amount < pay.amount AND pay.due_date < '{$today}'
                  AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')";
    } elseif ($filterStatus === 'paid') {
        $sql .= " AND (pay.status = 'paid' OR pay.paid_amount >= pay.amount)";
    }
}

$sql .= " ORDER BY (pay.status = 'pending') DESC, pay.due_date ASC, pay.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allPayments = $stmt->fetchAll();

$cntPayStmt = $pdo->prepare("SELECT COUNT(*) FROM payments pay JOIN projects p ON p.id = pay.project_id WHERE pay.client_id = ? AND pay.deleted_at IS NULL AND p.deleted_at IS NULL");
$cntPayStmt->execute([$client['id']]);
$totalClientPayments = (int) $cntPayStmt->fetchColumn();

// Pagination
$totalMatching = count($allPayments);
$perPage = 6;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalMatching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$payments = array_slice($allPayments, $offset, $perPage);
$startEntry = $totalMatching > 0 ? $offset + 1 : 0;
$endEntry = min($offset + $perPage, $totalMatching);

// Metrics for this client (Excludes unconfirmed proposals & drafts)
$stmtMetrics = $pdo->prepare("
    SELECT 
        COALESCE(SUM(pay.paid_amount), 0) AS total_paid,
        COALESCE(SUM(CASE 
            WHEN pay.paid_amount < pay.amount 
                 AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')
            THEN (pay.amount - pay.paid_amount) 
            ELSE 0 
        END), 0) AS total_pending,
        COUNT(CASE 
            WHEN pay.paid_amount < pay.amount 
                 AND pay.due_date < '{$today}'
                 AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled')
            THEN 1 
        END) AS overdue_count
    FROM payments pay
    JOIN projects p ON p.id = pay.project_id
    WHERE pay.client_id = ? AND pay.deleted_at IS NULL AND p.deleted_at IS NULL
");
$stmtMetrics->execute([$client['id']]);
$metrics = $stmtMetrics->fetch();
$totalPaid    = (float) $metrics['total_paid'];
$totalPending = (float) $metrics['total_pending'];
$overdueCount = (int) $metrics['overdue_count'];

// Fetch recent payment transactions for this client
$stmtTxns = $pdo->prepare("
    SELECT pt.*, pay.invoice_no, pay.title AS milestone_title, p.title AS project_title
    FROM payment_transactions pt
    JOIN payments pay ON pay.id = pt.payment_id
    JOIN projects p ON p.id = pay.project_id
    WHERE pay.client_id = ? AND pt.voided_at IS NULL AND pay.deleted_at IS NULL
    ORDER BY pt.paid_date DESC, pt.id DESC
    LIMIT 5
");
$stmtTxns->execute([$client['id']]);
$recentTxns = $stmtTxns->fetchAll();

function getPaymentsFilterUrl($p, $sFilter, $q) {
    $params = ['page' => $p];
    if ($sFilter !== '') $params['status'] = $sFilter;
    if ($q !== '') $params['search'] = $q;
    return 'payments.php?' . http_build_query($params);
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h4 class="fw-bold mb-0">My Payments & Invoices</h4>
                <p class="text-muted small mb-0">Track milestone installments, clearance history, and transfer instructions.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentInfoModal">
                    <i class="bi bi-bank me-1"></i> Payment Instructions
                </button>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($totalClientPayments > 0 || ($search !== '' || $filterStatus !== '')): ?>
        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <a href="payments.php?status=paid" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-success" title="Filter Settled Invoices">
                        <i class="bi bi-check-circle-fill"></i>
                        <h3>₹<?php echo number_format($totalPaid, 2); ?></h3>
                        <div class="small opacity-75">Total Settled Payments</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4">
                <a href="payments.php?status=pending" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-warning" title="Filter Pending Dues">
                        <i class="bi bi-clock-history"></i>
                        <h3>₹<?php echo number_format($totalPending, 2); ?></h3>
                        <div class="small opacity-75">Scheduled Balance Dues</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4">
                <a href="payments.php?status=overdue" class="text-decoration-none">
                    <div class="stat-card clickable stat-card-danger" title="Filter Overdue Invoices">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <h3><?php echo $overdueCount; ?></h3>
                        <div class="small opacity-75">Overdue Milestone Invoices</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="card p-3 mb-4 shadow-sm" style="border-radius: 12px;">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-auto d-flex flex-wrap gap-1">
                    <a href="payments.php" class="btn btn-sm <?php echo empty($filterStatus) ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                        All Invoices
                    </a>
                    <a href="payments.php?status=pending" class="btn btn-sm <?php echo $filterStatus === 'pending' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?>">
                        Pending Dues
                    </a>
                    <a href="payments.php?status=overdue" class="btn btn-sm <?php echo $filterStatus === 'overdue' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                        Overdue Only
                    </a>
                    <a href="payments.php?status=paid" class="btn btn-sm <?php echo $filterStatus === 'paid' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Settled History
                    </a>
                </div>

                <div class="col-12 col-md-auto ms-md-auto">
                    <form method="GET" action="payments.php" class="d-flex gap-2">
                        <?php if ($filterStatus): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                        <?php endif; ?>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" class="form-control" placeholder="Search invoice or title..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
                            <?php if ($search !== '' || $filterStatus !== ''): ?>
                                <a href="payments.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($payments)): ?>
            <div class="card p-5 text-center shadow-sm border-0 my-3" style="border-radius: 14px;">
                <div class="mb-3">
                    <i class="bi bi-receipt text-muted opacity-50" style="font-size: 3.5rem;"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No Invoices Found</h5>
                <p class="text-muted small mb-3">No payment records or milestone invoices match your current filter.</p>
                <?php if ($search !== '' || $filterStatus !== ''): ?>
                    <div>
                        <a href="payments.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card p-3 shadow-sm mb-4" style="border-radius: 14px;">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice No</th>
                                <th>Milestone & Project</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance Due</th>
                                <th>Scheduled Due</th>
                                <th>Status</th>
                                <th>Settled Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pay): 
                                $tot = (float) $pay['amount'];
                                $pd  = (float) ($pay['paid_amount'] ?? ($pay['status'] === 'paid' ? $tot : 0));
                                $bal = max(0, $tot - $pd);
                                $isProposalPending = in_array($pay['project_status'], ['draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested'], true);
                                $isOverdue = (!$isProposalPending && $bal > 0 && !empty($pay['due_date']) && $pay['due_date'] < $today);
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pay['invoice_no']); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($pay['title']); ?></div>
                                        <div class="small text-primary fw-medium"><?php echo htmlspecialchars($pay['project_title']); ?> <span class="text-muted font-monospace">(<?php echo htmlspecialchars($pay['project_code']); ?>)</span></div>
                                        <?php if (!empty($pay['transaction_id'])): ?>
                                            <div class="font-monospace text-muted small" style="font-size: 11px;">Ref: <?php echo htmlspecialchars($pay['transaction_id']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>₹<?php echo number_format($tot, 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-success fw-semibold">₹<?php echo number_format($pd, 2); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($bal > 0): ?>
                                            <span class="fw-bold <?php echo $isOverdue ? 'text-danger' : 'text-dark'; ?>">
                                                ₹<?php echo number_format($bal, 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">₹0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isProposalPending): ?>
                                            <span class="text-muted small">Scheduled: <?php echo date('d M Y', strtotime($pay['due_date'])); ?></span>
                                        <?php else: ?>
                                            <div class="<?php echo $isOverdue ? 'text-danger fw-bold' : 'text-dark'; ?>">
                                                <?php echo date('d M Y', strtotime($pay['due_date'])); ?>
                                                <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo getClientMilestoneStatusBadge($pay, $pay['project_status']); ?>
                                    </td>
                                    <td>
                                        <?php echo $pay['paid_date'] ? date('d M Y', strtotime($pay['paid_date'])) : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($isProposalPending): ?>
                                            <a href="view_proposal.php?id=<?php echo $pay['project_id']; ?>" class="btn btn-sm btn-outline-warning text-dark py-1 px-2" style="font-size: 0.78rem;">
                                                <i class="bi bi-file-earmark-check me-1"></i> Review Proposal
                                            </a>
                                        <?php elseif ($bal > 0): ?>
                                            <button type="button" class="btn btn-sm btn-danger py-1 px-2 fw-semibold" style="font-size: 0.78rem;" 
                                                    data-bs-toggle="modal" data-bs-target="#paymentInfoModal"
                                                    data-inv="<?php echo htmlspecialchars($pay['invoice_no']); ?>"
                                                    data-title="<?php echo htmlspecialchars($pay['title']); ?>"
                                                    data-amount="₹<?php echo number_format($bal, 2); ?>">
                                                <i class="bi bi-credit-card me-1"></i> Pay Now
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2" style="font-size: 0.75rem;">
                                                <i class="bi bi-check2-all me-1"></i> Settled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">
                        <div class="small text-muted">
                            Showing <strong><?php echo $startEntry; ?></strong> to <strong><?php echo $endEntry; ?></strong> of <strong><?php echo $totalMatching; ?></strong> invoices
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getPaymentsFilterUrl($page - 1, $filterStatus, $search); ?>">
                                        <i class="bi bi-chevron-left me-1"></i> Previous
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo getPaymentsFilterUrl($i, $filterStatus, $search); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getPaymentsFilterUrl($page + 1, $filterStatus, $search); ?>">
                                        Next <i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Recent Payment Transactions History -->
        <?php if (!empty($recentTxns)): ?>
            <div class="card p-4 shadow-sm" style="border-radius: 14px;">
                <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-receipt-cutoff text-success"></i> Recent Payment Receipts
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Milestone Deliverable</th>
                                <th>Project</th>
                                <th>Amount Paid</th>
                                <th>Payment Method</th>
                                <th>Payment Date</th>
                                <th>Transaction / UTR Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTxns as $txn): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($txn['invoice_no']); ?></span></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($txn['milestone_title']); ?></td>
                                    <td><span class="text-secondary small"><?php echo htmlspecialchars($txn['project_title']); ?></span></td>
                                    <td class="text-success fw-bold">₹<?php echo number_format($txn['amount'], 2); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($txn['method']); ?></span></td>
                                    <td><i class="bi bi-calendar-check me-1 text-muted"></i><?php echo date('d M Y', strtotime($txn['paid_date'])); ?></td>
                                    <td><span class="font-monospace text-muted small"><?php echo htmlspecialchars($txn['transaction_id'] ?: 'Verified Entry'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- ========================================== -->
<!-- Payment Instructions & Transfer Details Modal -->
<!-- ========================================== -->
<div class="modal fade" id="paymentInfoModal" tabindex="-1" aria-labelledby="paymentInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);">
                <h5 class="modal-title fw-bold" id="paymentInfoModalLabel">
                    <i class="bi bi-qr-code-scan me-2"></i> Payment Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <!-- Dynamic Invoice Due Banner -->
                <div id="modalInvBanner" class="alert alert-primary py-2 px-3 mb-3 text-start d-none" style="border-radius: 10px;">
                    <div class="small fw-semibold text-muted" id="modalInvText">Invoice: INV-1001</div>
                    <div class="fs-5 fw-bold text-primary" id="modalInvAmount">₹0.00 Due</div>
                </div>

                <!-- UPI QR Code Card -->
                <div class="card p-3 mb-3 border shadow-sm mx-auto d-inline-block bg-white" style="border-radius: 14px;">
                    <img id="upiQrCodeImg" 
                         src="https://api.qrserver.com/v1/create-qr-code/?size=170x170&margin=4&data=upi%3A%2F%2Fpay%3Fpa%3D8787228805%40okhdfcbank%26pn%3DSachin%2BKumar%26cu%3DINR" 
                         alt="UPI QR Code" 
                         class="img-fluid rounded" 
                         style="width: 170px; height: 170px;">
                    <div class="small text-muted mt-2 fw-semibold">
                        <i class="bi bi-camera me-1"></i> Scan with any UPI App
                    </div>
                </div>

                <!-- UPI ID Copy Box -->
                <div class="p-3 bg-light rounded-3 border mb-3 text-start">
                    <label class="form-label small text-muted fw-semibold mb-1">Direct UPI ID</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace fw-bold bg-white text-dark" id="upiIdInput" value="8787228805@okhdfcbank" readonly>
                        <button type="button" class="btn btn-primary px-3" id="copyUpiBtn" title="Copy UPI ID">
                            <i class="bi bi-clipboard me-1" id="copyUpiIcon"></i> <span id="copyUpiText">Copy</span>
                        </button>
                    </div>
                    <div class="small text-muted mt-1">Google Pay · PhonePe · Paytm · BHIM · Cred</div>
                </div>

                <!-- Direct Mobile Pay Button -->
                <div class="mb-3 d-grid">
                    <a href="upi://pay?pa=8787228805@okhdfcbank&pn=Sachin%20Kumar&cu=INR" 
                       id="upiPayDeepLink" 
                       class="btn btn-success fw-bold py-2 shadow-sm">
                        <i class="bi bi-phone me-1"></i> Pay via UPI App
                    </a>
                </div>

                <!-- Bank Transfer Details Section -->
                <div class="text-start mb-3">
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="fw-semibold text-dark mb-1">
                            <i class="bi bi-bank text-primary me-1"></i> Bank Transfer (NEFT / IMPS)
                        </div>
                        <div class="text-muted small">
                            For bank wire transfers or company billing, contact your project administrator for current account details.
                        </div>
                    </div>
                </div>

                <div class="alert alert-secondary small mb-0 py-2 text-start">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>After Payment:</strong> Share the transaction UTR number or screenshot with admin to verify and mark your invoice settled.
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentModalEl = document.getElementById('paymentInfoModal');
    if (paymentModalEl) {
        const baseUpi = '8787228805@okhdfcbank';
        const payeeName = 'Sachin Kumar';

        paymentModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const banner = document.getElementById('modalInvBanner');
            const invText = document.getElementById('modalInvText');
            const invAmount = document.getElementById('modalInvAmount');
            const qrImg = document.getElementById('upiQrCodeImg');
            const deepLink = document.getElementById('upiPayDeepLink');

            if (button && button.hasAttribute('data-inv')) {
                const inv = button.getAttribute('data-inv');
                const title = button.getAttribute('data-title');
                const amtFormatted = button.getAttribute('data-amount');
                const cleanAmt = parseFloat(amtFormatted.replace(/[^0-9.]/g, '') || 0).toFixed(2);

                invText.textContent = `${inv} · ${title}`;
                invAmount.textContent = `${amtFormatted} Due`;
                banner.classList.remove('d-none');

                const upiUrl = `upi://pay?pa=${encodeURIComponent(baseUpi)}&pn=${encodeURIComponent(payeeName)}&am=${cleanAmt}&tr=${encodeURIComponent(inv)}&tn=${encodeURIComponent('Invoice ' + inv)}&cu=INR`;
                if (deepLink) deepLink.setAttribute('href', upiUrl);
                if (qrImg) {
                    qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=170x170&margin=4&data=${encodeURIComponent(upiUrl)}`;
                }
            } else {
                banner.classList.add('d-none');
                const defaultUpiUrl = `upi://pay?pa=${encodeURIComponent(baseUpi)}&pn=${encodeURIComponent(payeeName)}&cu=INR`;
                if (deepLink) deepLink.setAttribute('href', defaultUpiUrl);
                if (qrImg) {
                    qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=170x170&margin=4&data=${encodeURIComponent(defaultUpiUrl)}`;
                }
            }
        });
    }

    const copyBtn = document.getElementById('copyUpiBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            const input = document.getElementById('upiIdInput');
            if (!input) return;
            navigator.clipboard.writeText(input.value).then(function() {
                const icon = document.getElementById('copyUpiIcon');
                const text = document.getElementById('copyUpiText');
                if (icon) icon.className = 'bi bi-check-lg me-1';
                if (text) text.textContent = 'Copied!';
                copyBtn.classList.replace('btn-primary', 'btn-success');
                setTimeout(function() {
                    if (icon) icon.className = 'bi bi-clipboard me-1';
                    if (text) text.textContent = 'Copy';
                    copyBtn.classList.replace('btn-success', 'btn-primary');
                }, 2500);
            }).catch(function() {
                input.select();
                document.execCommand('copy');
            });
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
