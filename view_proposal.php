<?php
require_once __DIR__ . '/includes/functions.php';

$token   = trim($_GET['token'] ?? '');
$id      = (int)($_GET['id'] ?? 0);
$isAdmin = isAdminLoggedIn();

$baseSelect = "
    SELECT p.*,
           c.name AS client_name, c.company_name, c.client_code, c.email AS client_email,
           c.phone AS client_phone, c.address AS client_address,
           a.name AS admin_name, a.email AS admin_email
    FROM projects p
    JOIN clients c ON c.id = p.client_id AND c.deleted_at IS NULL
    JOIN admin a ON a.id = p.created_by
";

$project = null;
if ($token !== '') {
    $stmt = $pdo->prepare($baseSelect . " WHERE p.agreement_token = ? AND p.deleted_at IS NULL");
    $stmt->execute([$token]);
    $project = $stmt->fetch();
} elseif ($id > 0 && $isAdmin) {
    // Admin direct preview by project id
    $stmt = $pdo->prepare($baseSelect . " WHERE p.id = ? AND p.deleted_at IS NULL");
    $stmt->execute([$id]);
    $project = $stmt->fetch();
}

// Expired / replaced token or invalid request UI
if (!$project) {
    http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Proposal Link Expired - ClientFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #eef1f6; color: #2c3345; }
        .error-card { max-width: 600px; margin: 60px auto; background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(120, 72, 255, 0.08); padding: 48px 30px; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <div class="error-card">
        <div class="mb-3 text-warning">
            <i class="bi bi-clock-history" style="font-size: 3.8rem; color: #7848ff;"></i>
        </div>
        <h3 class="fw-bold mb-2 text-dark">Proposal Link Expired or Replaced</h3>
        <p class="text-muted mx-auto mb-4" style="max-width: 460px; font-size: 14px; line-height: 1.6;">
            This proposal link is no longer active. If a new or updated proposal was resent, please check your inbox and open the <strong>latest email link</strong>.
        </p>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="/client-flow/client/dashboard.php" class="btn btn-primary fw-semibold px-4 py-2" style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); border: none; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Client Portal
            </a>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit;
}

$projectId = (int)$project['id'];

// Sync status, then re-read fresh database state
syncProjectStatus($pdo, $projectId);
$chk = $pdo->prepare("SELECT status, agreed_at, agreed_by_name, agreed_ip, token_expires_at FROM projects WHERE id = ?");
$chk->execute([$projectId]);
$fresh = $chk->fetch();
if ($fresh) {
    $project = array_merge($project, $fresh);
}

// Agreement status evaluation
$isAgreed  = !empty($project['agreed_at']);
$isExpired = !empty($project['token_expires_at']) && strtotime($project['token_expires_at']) < time();
$canAccept = !$isAgreed && !$isExpired && !$isAdmin && !in_array($project['status'], ['cancelled', 'completed'], true);

// -------------------------------------------------------------
// POST: Client Accepts Agreement Directly on this Page
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_agreement') {
    if ($canAccept) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $signerName = $project['client_name'];

        // Determine next status: advance_payment_pending if advance installment pending, else in_progress
        $first = $pdo->prepare("SELECT status, paid_amount, amount FROM payments
                                WHERE project_id = ? AND deleted_at IS NULL
                                ORDER BY is_advance DESC, due_date ASC, id ASC LIMIT 1");
        $first->execute([$projectId]);
        $f = $first->fetch();
        $nextStatus = ($f && ($f['status'] === 'paid' || (float) $f['paid_amount'] >= (float) $f['amount']))
            ? 'in_progress' : 'advance_payment_pending';

        $upd = $pdo->prepare("
            UPDATE projects
            SET status = ?, agreed_at = NOW(), agreed_by_name = ?, agreed_ip = ?
            WHERE id = ? AND agreed_at IS NULL AND deleted_at IS NULL
        ");
        $upd->execute([$nextStatus, $signerName, $ip, $projectId]);

        if ($upd->rowCount() === 1) {
            addProjectLog($pdo, $projectId, 'agreed', 'Proposal & Agreement Digitally Accepted',
                "Client {$signerName} digitally accepted the agreement (IP: {$ip}).", 'client', $signerName);

            addNotification($pdo, 'admin', (int) ($project['created_by'] ?? 1), 'Agreement Confirmed via Email',
                "Client {$signerName} accepted the project proposal for '{$project['title']}' ({$project['project_code']}).",
                "/client-flow/admin/projects/view_project.php?id={$projectId}", 'success', 'bi-file-earmark-check-fill');

            addNotification($pdo, 'client', (int) $project['client_id'], 'Agreement Confirmed',
                "You accepted the agreement for '{$project['title']}'.", '/client-flow/client/projects.php', 'success', 'bi-check2-all');

            syncProjectStatus($pdo, $projectId);

            if (function_exists('sendAgreementConfirmedEmail')) {
                try { sendAgreementConfirmedEmail($pdo, $projectId); } catch (Throwable $e) { /* ignore */ }
            }
        }
    }
    // Post/Redirect/Get back to view_proposal.php?token=... with success banner
    header('Location: view_proposal.php?token=' . urlencode($token) . '&accepted=1');
    exit;
}

// Fetch active milestones (excluding soft-deleted)
$stmtPay = $pdo->prepare("SELECT * FROM payments WHERE project_id = ? AND deleted_at IS NULL ORDER BY due_date ASC, id ASC");
$stmtPay->execute([$projectId]);
$milestones = $stmtPay->fetchAll();

$totalBudget = (float) $project['total_amount'];
$totalPaid = 0;
foreach ($milestones as $m) {
    $totalPaid += (float) ($m['paid_amount'] ?? ($m['status'] === 'paid' ? $m['amount'] : 0));
}
$totalDue = max(0, $totalBudget - $totalPaid);

$justAccepted = isset($_GET['accepted']) && $_GET['accepted'] == '1';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Proposal & Agreement - <?php echo htmlspecialchars($project['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #eef1f6;
            color: #2c3345;
        }

        .document-wrapper {
            max-width: 860px;
            margin: 30px auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(120, 72, 255, 0.07);
            padding: 40px;
        }

        .doc-header-border {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .brand-gradient {
            background: linear-gradient(135deg, #7848ff 0%, #693be7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-brand {
            background: linear-gradient(135deg, #7848ff 0%, #693be7 100%);
            color: #ffffff;
            border: none;
            box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);
            transition: all 0.2s ease;
        }

        .btn-brand:hover {
            background: linear-gradient(135deg, #693be7 0%, #582ac9 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(120, 72, 255, 0.45);
        }

        .section-header {
            background-color: #f8fafc;
            border-left: 4px solid #7848ff;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #2c3345;
            border-radius: 4px;
            margin: 25px 0 15px;
        }

        .badge-brand {
            background: rgba(120, 72, 255, 0.12);
            color: #7848ff;
            font-weight: 600;
        }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .document-wrapper { border: none; box-shadow: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>

<?php if ($isAdmin): ?>
    <div class="bg-dark text-white py-2 px-3 sticky-top shadow-sm border-bottom border-secondary d-flex align-items-center justify-content-between flex-wrap gap-2 no-print">
        <div class="d-flex align-items-center gap-2">
            <span class="badge px-2 py-1" style="background: #7848ff;"><i class="bi bi-shield-lock-fill me-1"></i> Admin Preview</span>
            <span class="small text-light">Previewing client proposal. Awaiting client: <strong><?php echo htmlspecialchars($project['client_name']); ?></strong> (via email link).</span>
        </div>
        <div>
            <a href="/client-flow/admin/projects/view_project.php?id=<?php echo $projectId; ?>" class="btn btn-sm btn-light">
                <i class="bi bi-arrow-left me-1"></i> Return to Admin Panel
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="container py-3">
    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between align-items-center max-w-860 mx-auto mb-3 no-print" style="max-width: 860px;">
        <span class="badge badge-brand px-3 py-2 text-uppercase" style="border-radius: 20px;">
            <i class="bi bi-file-earmark-text me-1"></i> Formal Service Agreement
        </span>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer me-1"></i> Print / Save PDF
            </button>
            <button onclick="window.close()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> Close
            </button>
        </div>
    </div>

    <div class="document-wrapper">
        <!-- Header -->
        <div class="doc-header-border d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge mb-2 text-uppercase" style="background: #7848ff; color: #fff; border-radius: 6px; font-size: 11px;">Formal Service Agreement</span>
                <h3 class="fw-bold mb-1 text-dark">PROJECT PROPOSAL &amp; AGREEMENT</h3>
                <div class="text-muted small">
                    Document Ref: <strong><?php echo htmlspecialchars($project['project_code']); ?></strong> |
                    Issued: <strong><?php echo date('d M Y', strtotime($project['created_at'])); ?></strong>
                </div>
            </div>
            <div class="text-end">
                <span class="fs-4 fw-bold brand-gradient">ClientFlow</span>
                <div class="small text-muted">Operations &amp; Client Engagements</div>
            </div>
        </div>

        <!-- Agreement Status Banner -->
        <?php if ($justAccepted): ?>
            <div class="alert alert-success border-0 d-flex align-items-center gap-3 p-3 mb-4 shadow-sm" style="background: #ecfdf5; border-left: 5px solid #10b981 !important;">
                <i class="bi bi-patch-check-fill fs-2 text-success"></i>
                <div>
                    <h6 class="fw-bold mb-0 text-success">Agreement Successfully Accepted &amp; Confirmed!</h6>
                    <div class="small text-muted">Your digital signature has been securely recorded. An official audit copy has been dispatched to your email.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isAgreed): ?>
            <div class="alert alert-success border-0 d-flex align-items-center gap-3 p-3 mb-4" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                <i class="bi bi-shield-fill-check fs-2 text-success"></i>
                <div>
                    <h6 class="fw-bold mb-0 text-success">Agreement Digitally Signed &amp; Active</h6>
                    <div class="small text-muted">
                        Accepted by <strong><?php echo htmlspecialchars($project['agreed_by_name'] ?: $project['client_name']); ?></strong> 
                        on <?php echo date('d M Y, h:i A', strtotime($project['agreed_at'])); ?> 
                        (Recorded IP: <?php echo htmlspecialchars($project['agreed_ip'] ?: '127.0.0.1'); ?>)
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert border-0 d-flex align-items-center gap-3 p-3 mb-4" style="background: #f5f3ff; border: 1px solid #ddd6fe;">
                <i class="bi bi-hourglass-split fs-2" style="color: #7848ff;"></i>
                <div>
                    <h6 class="fw-bold mb-0" style="color: #7848ff;">Awaiting Client Digital Acceptance</h6>
                    <div class="small text-muted">
                        Please review the project deliverables and milestone schedule below, then check the agreement box and click Accept to proceed.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Parties Section -->
        <div class="section-header">1. Engaging Parties</div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="p-3 border rounded bg-light">
                    <small class="text-muted fw-bold text-uppercase d-block" style="font-size: 11px;">Service Provider (Developer / Agency)</small>
                    <strong class="fs-6 text-dark"><?php echo htmlspecialchars($project['admin_name']); ?></strong>
                    <div class="small text-muted mt-1">Project Operations &amp; Management</div>
                    <div class="small text-muted"><?php echo htmlspecialchars($project['admin_email']); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border rounded bg-light">
                    <small class="text-muted fw-bold text-uppercase d-block" style="font-size: 11px;">Client / Organization</small>
                    <strong class="fs-6 text-dark"><?php echo htmlspecialchars($project['client_name']); ?></strong>
                    <?php if (!empty($project['company_name'])): ?>
                        <div class="small text-dark fw-semibold"><?php echo htmlspecialchars($project['company_name']); ?></div>
                    <?php endif; ?>
                    <div class="small text-muted"><?php echo htmlspecialchars($project['client_email']); ?> | Code: <?php echo htmlspecialchars($project['client_code']); ?></div>
                </div>
            </div>
        </div>

        <!-- Project Scope -->
        <div class="section-header">2. Project Scope &amp; Budget</div>
        <div class="p-3 border rounded bg-light mb-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 11px;">Project Title</small>
                    <strong class="fs-6 text-dark"><?php echo htmlspecialchars($project['title']); ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 11px;">Total Contract Budget</small>
                    <strong class="fs-6" style="color: #7848ff;">₹<?php echo number_format($totalBudget, 2); ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 11px;">Target Final Delivery</small>
                    <strong class="fs-6 text-dark"><?php echo !empty($project['deadline']) ? date('d M Y', strtotime($project['deadline'])) : 'Open Schedule'; ?></strong>
                </div>
                <div class="col-12 mt-2 pt-2 border-top">
                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 11px;">Scope Description &amp; Deliverables</small>
                    <p class="mb-0 text-secondary" style="line-height: 1.6;">
                        <?php echo !empty($project['description']) ? nl2br(htmlspecialchars($project['description'])) : 'Scope includes complete architectural design, engineering development, milestone phase demonstrations, and final handover according to agreed requirements.'; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Milestones Schedule -->
        <div class="section-header">3. Payment Milestone Schedule</div>
        <div class="table-responsive mb-3">
            <table class="table table-bordered align-middle mb-0" style="font-size: 13px;">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40px;" class="text-center">#</th>
                        <th>Milestone Deliverable / Phase</th>
                        <th style="width: 100px;" class="text-center">Allocation</th>
                        <th style="width: 130px;">Amount</th>
                        <th style="width: 130px;">Scheduled Due</th>
                        <th style="width: 110px;" class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($milestones)): ?>
                        <?php foreach ($milestones as $idx => $m): 
                            $pct = $totalBudget > 0 ? round(((float)$m['amount'] / $totalBudget) * 100) : 0;
                            $isPaid = ($m['status'] === 'paid' || (float)$m['paid_amount'] >= (float)$m['amount']);
                        ?>
                            <tr>
                                <td class="text-center fw-bold text-muted"><?php echo $idx + 1; ?></td>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($m['title']); ?></td>
                                <td class="text-center fw-bold" style="color: #7848ff;"><?php echo $pct; ?>%</td>
                                <td class="fw-bold">₹<?php echo number_format($m['amount'], 2); ?></td>
                                <td class="text-muted"><?php echo !empty($m['due_date']) ? date('d M Y', strtotime($m['due_date'])) : 'On Delivery'; ?></td>
                                <td class="text-center">
                                    <?php if ($isPaid): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">Full payment scheduled upon project handover.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Terms of Service -->
        <div class="section-header">4. Operational Terms &amp; Milestone Pause Rule</div>
        <div class="small text-secondary mb-4" style="line-height: 1.6;">
            <p class="mb-1"><strong>1. Commencement of Work:</strong> Active development commences immediately upon receipt and confirmation of Milestone 1 (Advance Payment).</p>
            <p class="mb-1"><strong>2. Milestone Auto-Pause Clause:</strong> In accordance with this agreed schedule, each milestone installment must be settled on or before its due date. If any installment remains unpaid past its scheduled due date, active project development is automatically placed on hold until payment is received, at which point work immediately resumes to In Progress.</p>
            <p class="mb-0"><strong>3. Final Handover &amp; IP:</strong> All code, intellectual property, credentials, and deployment deliverables are transferred upon full contract settlement.</p>
        </div>

        <!-- Agreement Action / Certificate -->
        <div class="p-4 rounded border bg-light mt-4">
            <?php if ($isAgreed): ?>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <span class="badge bg-success px-3 py-2 fs-6 mb-2" style="border-radius: 8px;"><i class="bi bi-check2-all me-1"></i> Formally Accepted &amp; Executed</span>
                        <div class="small text-dark fw-semibold">Digitally accepted by: <?php echo htmlspecialchars($project['agreed_by_name'] ?: $project['client_name']); ?></div>
                        <div class="small text-muted">Timestamp: <?php echo date('d M Y, h:i A', strtotime($project['agreed_at'])); ?> | Recorded IP: <?php echo htmlspecialchars($project['agreed_ip'] ?: '127.0.0.1'); ?></div>
                    </div>
                    <div class="d-flex gap-2 no-print">
                        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm px-3">
                            <i class="bi bi-printer me-1"></i> Print Receipt
                        </button>
                        <a href="/client-flow/client/projects.php" class="btn btn-brand btn-sm px-3">
                            <i class="bi bi-speedometer2 me-1"></i> Client Portal
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php if ($canAccept): ?>
                    <!-- Client 1-Click Digital Acceptance Form (Direct on this page) -->
                    <form method="POST" action="view_proposal.php?token=<?php echo urlencode($token); ?>" id="proposalAcceptForm" class="text-center py-2">
                        <input type="hidden" name="action" value="accept_agreement">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <h5 class="fw-bold text-dark mb-2">Accept &amp; Confirm Agreement</h5>
                        <p class="text-muted small mb-3">
                            Total Contract Budget: <strong style="color: #7848ff;">₹<?php echo number_format($totalBudget, 2); ?></strong>
                        </p>

                        <div class="form-check d-inline-block text-start mb-3 p-3 bg-white border rounded shadow-sm" style="max-width: 620px;">
                            <input class="form-check-input ms-0 me-2" type="checkbox" id="agreeCheckbox" required style="cursor: pointer; width: 1.25rem; height: 1.25rem;">
                            <label class="form-check-label small text-dark" for="agreeCheckbox" style="cursor: pointer; line-height: 1.5;">
                                I, <strong><?php echo htmlspecialchars($project['client_name']); ?></strong>, have reviewed the project deliverables, total budget, and milestone payment schedule, and I hereby agree to digitally execute this agreement.
                            </label>
                        </div>

                        <div>
                            <button type="submit" id="agreeSubmitBtn" class="btn btn-brand btn-lg fw-bold px-5 py-3" disabled style="border-radius: 10px;">
                                <i class="bi bi-check-circle-fill me-2"></i> I Agree &amp; Digitally Accept Agreement
                            </button>
                        </div>
                    </form>

                    <script>
                        (function() {
                            var chk = document.getElementById('agreeCheckbox');
                            var btn = document.getElementById('agreeSubmitBtn');
                            var frm = document.getElementById('proposalAcceptForm');

                            if (chk && btn && frm) {
                                chk.addEventListener('change', function() {
                                    btn.disabled = !chk.checked;
                                });

                                frm.addEventListener('submit', function() {
                                    btn.disabled = true;
                                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Recording Acceptance...';
                                });
                            }
                        })();
                    </script>

                <?php elseif ($isAdmin): ?>
                    <div class="text-center text-muted small py-2">
                        <i class="bi bi-info-circle me-1"></i> Admin preview mode: The digital acceptance checkbox and button are active for the client when accessed via their email token link.
                    </div>
                <?php elseif ($isExpired): ?>
                    <div class="text-center text-warning fw-semibold py-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> This agreement link has expired. Please contact us to receive a fresh proposal link.
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-2">
                        This proposal is not currently open for acceptance.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>