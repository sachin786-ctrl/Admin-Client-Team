<?php
/**
 * Client Portal - Project Actions (Accept Proposal, Request Revisions, Approve Delivery)
 * c:\xampp\htdocs\client-flow\client\confirm_project.php
 */

require_once __DIR__ . '/includes/functions.php';
$client = getAuthenticatedClient($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: projects.php');
    exit;
}

$projectId  = (int) ($_POST['project_id'] ?? 0);
$action     = trim($_POST['action'] ?? 'accept');
$signerName = trim($_POST['signer_name'] ?? '');
if ($signerName === '') {
    $signerName = $client['name'];
}
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$redirectTo = $_POST['redirect_to'] ?? '';

if ($projectId <= 0) {
    setClientFlash('error', 'Invalid project selection.');
    header('Location: projects.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, title, project_code, status, created_by, total_amount, agreed_at
        FROM projects 
        WHERE id = ? AND client_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$projectId, $client['id']]);
    $prj = $stmt->fetch();

    if (!$prj) {
        setClientFlash('error', 'Project not found or unauthorized access.');
        header('Location: projects.php');
        exit;
    }

    $adminId = (int) ($prj['created_by'] ?? 1);

    // ==========================================
    // ACTION 1: Accept Proposal & Digital Sign
    // ==========================================
    if ($action === 'accept') {
        if (!in_array($prj['status'], ['proposal_sent', 'waiting_confirmation', 'revision_requested'], true)) {
            setClientFlash('info', 'This project proposal is already confirmed or active.');
        } else {
            // Check if first milestone is already paid
            $payFirst = $pdo->prepare("
                SELECT status, paid_amount, amount 
                FROM payments 
                WHERE project_id = ? AND deleted_at IS NULL 
                ORDER BY due_date ASC, id ASC 
                LIMIT 1
            ");
            $payFirst->execute([$projectId]);
            $first = $payFirst->fetch();

            $isFirstPaid = ($first && ($first['status'] === 'paid' || (float)$first['paid_amount'] >= (float)$first['amount'] - 0.005));
            $nextStatus = $isFirstPaid ? 'in_progress' : 'advance_pending';

            $upd = $pdo->prepare("
                UPDATE projects 
                SET status = ?,
                    agreed_at = NOW(),
                    agreed_by_name = ?,
                    agreed_ip = ?
                WHERE id = ?
            ");
            $upd->execute([$nextStatus, $signerName, $ip, $projectId]);

            // Audit Log
            addProjectLog($pdo, $projectId, 'agreed', 'Proposal & Agreement Accepted', 
                "Client {$signerName} digitally signed and accepted the project scope and installment schedule from IP {$ip}. Status transitioned to " . ($isFirstPaid ? 'In Progress' : 'Advance Due') . ".", 
                'client', $signerName
            );

            // Sync dynamic project status
            syncProjectStatus($pdo, $projectId);

            // Notify Admin
            addNotification($pdo, 'admin', $adminId, 'Agreement Accepted', 
                "Client {$signerName} has digitally signed the agreement for '{$prj['title']}' ({$prj['project_code']}).", 
                "/client-flow/admin/projects/view_project.php?id={$prj['id']}", 'success', 'bi-file-earmark-check-fill'
            );

            // Notify Client
            addNotification($pdo, 'client', $client['id'], 'Agreement Confirmed', 
                "You have accepted the agreement for '{$prj['title']}'. " . ($isFirstPaid ? 'Active development is underway!' : 'Work commences upon advance milestone clearance.'), 
                "/client-flow/client/projects.php", 'success', 'bi-check2-all'
            );

            // Dispatch agreement signed confirmation email to client & admin
            if (function_exists('sendAgreementConfirmedEmail')) {
                try {
                    sendAgreementConfirmedEmail($pdo, $projectId);
                } catch (Throwable $e) {
                    // Suppress error so client flow continues smoothly
                }
            }

            setClientFlash('success', "Project agreement for '{$prj['title']}' successfully accepted and digitally confirmed!");
        }
    }
    // ==========================================
    // ACTION 2: Request Scope / Milestone Changes
    // ==========================================
    elseif ($action === 'request_revision') {
        $notes = trim($_POST['revision_notes'] ?? '');
        if ($notes === '') {
            setClientFlash('error', 'Please provide revision notes or explain what changes you would like in the project.');
            header('Location: view_proposal.php?id=' . $projectId);
            exit;
        }

        $upd = $pdo->prepare("
            UPDATE projects 
            SET status = 'revision_requested',
                client_feedback = ?
            WHERE id = ?
        ");
        $upd->execute([$notes, $projectId]);

        // Audit Log
        addProjectLog($pdo, $projectId, 'revision_requested', 'Changes Requested by Client', 
            "Client requested proposal modifications: \"{$notes}\"", 
            'client', $signerName
        );

        // Notify Admin
        addNotification($pdo, 'admin', $adminId, 'Proposal Revision Requested', 
            "Client {$client['name']} requested changes for '{$prj['title']}' ({$prj['project_code']}): \"{$notes}\"", 
            "/client-flow/admin/projects/view_project.php?id={$prj['id']}", 'warning', 'bi-pencil-square'
        );

        // Notify Client
        addNotification($pdo, 'client', $client['id'], 'Changes Submitted', 
            "Your revision request for '{$prj['title']}' has been forwarded to the project manager.", 
            "/client-flow/client/view_proposal.php?id={$projectId}", 'info', 'bi-send-check'
        );

        setClientFlash('success', 'Your requested modifications have been forwarded to the administrator for review.');
    }
    // ==========================================
    // ACTION 3: Approve Final Deliverables (Review -> Completed)
    // ==========================================
    elseif ($action === 'approve_delivery') {
        if ($prj['status'] === 'review') {
            $upd = $pdo->prepare("UPDATE projects SET status = 'completed' WHERE id = ?");
            $upd->execute([$projectId]);

            // Audit Log
            addProjectLog($pdo, $projectId, 'completed', 'Deliverables Approved & Completed', 
                "Client {$signerName} reviewed and approved the delivered project work. Project marked Completed.", 
                'client', $signerName
            );

            // Notify Admin
            addNotification($pdo, 'admin', $adminId, 'Deliverables Approved', 
                "Client {$client['name']} approved all deliverables for '{$prj['title']}' ({$prj['project_code']}). Project is now Completed!", 
                "/client-flow/admin/projects/view_project.php?id={$prj['id']}", 'success', 'bi-award-fill'
            );

            // Notify Client
            addNotification($pdo, 'client', $client['id'], 'Project Completed', 
                "Congratulations! You approved the deliverables for '{$prj['title']}'. The project is now completed.", 
                "/client-flow/client/projects.php", 'success', 'bi-award-fill'
            );

            // Dispatch project completed congratulations email
            if (function_exists('sendProjectCompletedEmail')) {
                try {
                    sendProjectCompletedEmail($pdo, $projectId);
                } catch (Throwable $e) {
                    // Suppress error so client flow continues smoothly
                }
            }

            setClientFlash('success', "Awesome! You have approved all deliverables and project '{$prj['title']}' is now marked Completed!");
        } else {
            setClientFlash('info', 'Project is not in review status.');
        }
    }

} catch (PDOException $e) {
    setClientFlash('error', 'Operation error: ' . $e->getMessage());
}

if ($redirectTo === 'dashboard') {
    header('Location: dashboard.php');
} elseif ($redirectTo === 'projects') {
    header('Location: projects.php');
} else {
    header('Location: view_proposal.php?id=' . $projectId);
}
exit;
