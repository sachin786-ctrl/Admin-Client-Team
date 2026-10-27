<?php
/**
 * Client Portal Helper Functions
 * c:\xampp\htdocs\client-flow\client\includes\functions.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Universal Core Logic Include (Loads database, payment helpers & core engine)
require_once __DIR__ . '/../../admin/includes/functions.php';

/* ----------------------------------------------------------
 |  Client Authentication & Guards
 * ---------------------------------------------------------- */
if (!function_exists('isClientLoggedIn')) {
    function isClientLoggedIn(): bool
    {
        return !empty($_SESSION['client_id']);
    }
}

if (!function_exists('requireClientLogin')) {
    function requireClientLogin(): void
    {
        if (empty($_SESSION['client_id'])) {
            header('Location: /client-flow/client/auth/login.php');
            exit;
        }
    }
}

if (!function_exists('getClientById')) {
    function getClientById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('getAuthenticatedClient')) {
    function getAuthenticatedClient(PDO $pdo): array
    {
        requireClientLogin();

        $clientId = (int) $_SESSION['client_id'];
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();

        if (!$client || (int) ($client['status'] ?? 0) !== 1) {
            unset($_SESSION['client_id'], $_SESSION['client_name'], $_SESSION['client_email']);
            $_SESSION['client_login_error'] = 'Session expired or your account is deactivated. Please log in again.';
            header('Location: /client-flow/client/auth/login.php');
            exit;
        }

        // Cache name in session for display
        $_SESSION['client_name'] = $client['name'];

        // Dynamic sync for overdue check if helper exists
             // Max 1 baar per ghanta per session (poori table par scan har click par nahi)
        if (function_exists('checkAllOverdueMilestones')
            && (empty($_SESSION['overdue_checked_at']) || time() - $_SESSION['overdue_checked_at'] > 3600)) {
            checkAllOverdueMilestones($pdo);
            $_SESSION['overdue_checked_at'] = time();
        }

        return $client;
    }
}

/* ----------------------------------------------------------
 |  Client Flash & Security Helpers
 * ---------------------------------------------------------- */
if (!function_exists('setClientFlash')) {
    function setClientFlash(string $type, string $message): void
    {
        $_SESSION['client_flash'] = [
            'type'    => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('getClientFlash')) {
    function getClientFlash(): ?array
    {
        if (!empty($_SESSION['client_flash'])) {
            $flash = $_SESSION['client_flash'];
            unset($_SESSION['client_flash']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('generateClientCsrfToken')) {
    function generateClientCsrfToken(): string
    {
        if (empty($_SESSION['client_csrf_token'])) {
            $_SESSION['client_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['client_csrf_token'];
    }
}

if (!function_exists('verifyClientCsrfToken')) {
    function verifyClientCsrfToken(?string $token): bool
    {
        return !empty($token) && !empty($_SESSION['client_csrf_token']) && hash_equals($_SESSION['client_csrf_token'], $token);
    }
}

/* ----------------------------------------------------------
 |  Client Image Upload Helper
 * ---------------------------------------------------------- */
if (!function_exists('uploadClientImage')) {
    function uploadClientImage(string $fileInputName, string $targetDir = 'profile', ?string $oldFile = null): array
    {
        // Re-use system uploadImage function
        if (function_exists('uploadImage')) {
            return uploadImage($fileInputName, $targetDir, $oldFile);
        }

        // Fallback uploader
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'message' => 'No file uploaded.'];
        }

        $file = $_FILES[$fileInputName];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error code: ' . $file['error']];
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedTypes, true)) {
            return ['success' => false, 'message' => 'Only JPG, PNG and WEBP images are allowed.'];
        }

        if ($file['size'] > 4 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Image size must be under 4MB.'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $newName = uniqid('client_', true) . '.' . $ext;
        $destDir = __DIR__ . '/../../assets/uploads/' . trim($targetDir, '/') . '/';

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $destDir . $newName)) {
            if ($oldFile && !str_contains($oldFile, 'default') && file_exists($destDir . $oldFile)) {
                @unlink($destDir . $oldFile);
            }
            return ['success' => true, 'fileName' => $newName];
        }

        return ['success' => false, 'message' => 'Failed to move uploaded file.'];
    }
}

/* ----------------------------------------------------------
 |  Client Status Badges & Presenters
 * ---------------------------------------------------------- */
if (!function_exists('getClientProjectStatusBadge')) {
    function getClientProjectStatusBadge(string $status): string
    {
        switch ($status) {
            case 'draft':
                return '<span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-pencil me-1"></i>Draft</span>';
            case 'proposal_sent':
            case 'waiting_confirmation':
                return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="bi bi-clock-history me-1"></i>Proposal Received</span>';
            case 'revision_requested':
                return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1"><i class="bi bi-pencil-square me-1"></i>Revision Requested</span>';
            case 'advance_pending':
            case 'advance_payment_pending':
                return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-credit-card me-1"></i>Advance Due</span>';
            case 'in_progress':
                return '<span class="badge bg-primary text-white px-2 py-1"><i class="bi bi-gear-fill me-1"></i>In Progress</span>';
            case 'review':
                return '<span class="badge px-2 py-1" style="background:#f3e8ff;color:#7e22ce;border:1px solid #d8b4fe;"><i class="bi bi-eye-fill me-1"></i>Under Review</span>';
            case 'on_hold':
                return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i>On Hold</span>';
            case 'completed':
                return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Completed</span>';
            case 'cancelled':
                return '<span class="badge bg-dark-subtle text-dark border px-2 py-1"><i class="bi bi-x-circle me-1"></i>Cancelled</span>';
            default:
                return '<span class="badge bg-secondary px-2 py-1">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $status))) . '</span>';
        }
    }
}

if (!function_exists('getClientMilestoneStatusBadge')) {
    function getClientMilestoneStatusBadge(array $m, string $projectStatus = ''): string
    {
        $total   = (float) ($m['amount'] ?? 0);
        $paid    = (float) ($m['paid_amount'] ?? 0);
        $due     = max(0, $total - $paid);
        $dueDate = $m['due_date'] ?? date('Y-m-d');
        $today   = date('Y-m-d');

        if (in_array($projectStatus, ['draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested'], true)) {
            return '<span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>Awaiting Agreement</span>';
        }

        if ($m['status'] === 'paid' || $paid >= $total - 0.005) {
            return '<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>';
        }

        if ($paid > 0) {
            return '<span class="badge bg-info-subtle text-dark border border-info-subtle px-2 py-1"><i class="bi bi-pie-chart-fill me-1"></i>Partial · ₹' . number_format($paid, 2) . '</span>';
        }

        if ($dueDate < $today) {
            return '<span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue</span>';
        }

        return '<span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-clock me-1"></i>Pending</span>';
    }
}