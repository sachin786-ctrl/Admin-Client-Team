<?php
/**
 * AJAX Email OTP Verification Endpoint for Client & Team Creation
 * Supports action=send and action=verify
 */
require_once __DIR__ . '/../includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Reload page.']);
    exit;
}

$action  = trim($_POST['action'] ?? '');
$purpose = trim($_POST['purpose'] ?? 'client_create'); // 'client_create' or 'team_create'
$email   = trim(strtolower($_POST['email'] ?? ''));
$name    = trim($_POST['name'] ?? ($purpose === 'client_create' ? 'Client' : 'Team Member'));

if (!in_array($purpose, ['client_create', 'team_create'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid purpose.']);
    exit;
}

if ($action === 'send') {
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required.']);
        exit;
    }

    // Verify syntax and domain deliverability
    $check = verifyEmailAddress($email, true);
    if (!$check['valid']) {
        echo json_encode(['success' => false, 'message' => $check['message']]);
        exit;
    }

    if ($purpose === 'client_create') {
        // Disallow admin email
        if (strcasecmp($email, $admin['email'] ?? '') === 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot use admin email.']);
            exit;
        }
        // Check duplicate client
        $cStmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1");
        $cStmt->execute([$email]);
        if ($cStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
    } else {
        // purpose === 'team_create'
        $aStmt = $pdo->prepare("SELECT id FROM admin WHERE email = ? LIMIT 1");
        $aStmt->execute([$email]);
        if ($aStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $clStmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1");
        $clStmt->execute([$email]);
        if ($clStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already used by a client.']);
            exit;
        }
    }

    // Rate-limiting: max 1 OTP request per 15 seconds
    if (!empty($_SESSION['email_verification'][$purpose]['sent_at'])) {
        $lastSent = $_SESSION['email_verification'][$purpose]['sent_at'];
        if (time() - $lastSent < 15) {
            $wait = 15 - (time() - $lastSent);
            echo json_encode(['success' => false, 'message' => "Wait {$wait}s and retry."]);
            exit;
        }
    }

    $otp = generateOtp(6);
    $_SESSION['email_verification'][$purpose] = [
        'email'      => $email,
        'otp'        => $otp,
        'expires_at' => time() + 600, // 10 minutes
        'verified'   => false,
        'token'      => '',
        'sent_at'    => time(),
        'attempts'   => 0
    ];

    $mailRes = sendVerificationOtpEmail($email, $name, $otp, $purpose);
    if (!empty($mailRes['success'])) {
        echo json_encode([
            'success' => true,
            'message' => "OTP sent to {$email}.",
            'email'   => $email,
            'timeout' => 60
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "OTP send failed: " . ($mailRes['message'] ?? 'SMTP error.')
        ]);
    }
    exit;
}

if ($action === 'verify') {
    $enteredOtp = trim($_POST['otp'] ?? '');
    if (empty($enteredOtp)) {
        echo json_encode(['success' => false, 'message' => 'Enter OTP.']);
        exit;
    }

    $sess = $_SESSION['email_verification'][$purpose] ?? null;
    if (empty($sess) || empty($sess['email'])) {
        echo json_encode(['success' => false, 'message' => 'Send OTP first.']);
        exit;
    }

    if (strcasecmp($sess['email'], $email) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Email changed. Resend OTP.']);
        exit;
    }

    if (time() > $sess['expires_at']) {
        echo json_encode(['success' => false, 'message' => 'OTP expired.']);
        exit;
    }

    if (($sess['attempts'] ?? 0) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many attempts. Resend OTP.']);
        exit;
    }

    if ($sess['otp'] === $enteredOtp) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['email_verification'][$purpose]['verified']    = true;
        $_SESSION['email_verification'][$purpose]['token']       = $token;
        $_SESSION['email_verification'][$purpose]['verified_at'] = time();

        echo json_encode([
            'success' => true,
            'message' => 'Email verified.',
            'email'   => $email,
            'token'   => $token
        ]);
        exit;
    } else {
        $_SESSION['email_verification'][$purpose]['attempts'] = ($sess['attempts'] ?? 0) + 1;
        $rem = 5 - $_SESSION['email_verification'][$purpose]['attempts'];
        echo json_encode([
            'success' => false,
            'message' => "Wrong OTP. {$rem} attempts left."
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;