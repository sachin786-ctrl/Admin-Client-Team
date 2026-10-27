<?php

/**
 * Universal Core Helper Functions & System Bootstrap
 * c:\xampp\htdocs\client-flow\includes\functions.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

if (!defined('CF_ROOT')) {
    define('CF_ROOT', dirname(__DIR__, 2));
}

// Universal Database & Payment Helpers
require_once CF_ROOT . '/config/database.php';
require_once __DIR__ . '/payment_helpers.php';

/* ----------------------------------------------------------
 |  Base URL / Path Helper
 * ---------------------------------------------------------- */
if (!function_exists('getBasePath')) {
    function getBasePath(): string
    {
        return '/client-flow/';
    }
}

/* ----------------------------------------------------------
 |  Admin Authentication & Access Guards
 * ---------------------------------------------------------- */
if (!function_exists('isAdminLoggedIn')) {
    function isAdminLoggedIn(): bool
    {
        return !empty($_SESSION['admin_id']);
    }
}

if (!function_exists('requireAdminLogin')) {
    function requireAdminLogin(): void
    {
        if (!isAdminLoggedIn()) {
            header('Location: /client-flow/admin/login.php');
            exit;
        }
    }
}

if (!function_exists('redirectIfLoggedIn')) {
    function redirectIfLoggedIn(): void
    {
        if (isAdminLoggedIn()) {
            header('Location: /client-flow/admin/dashboard.php');
            exit;
        }
    }
}

if (!function_exists('getAuthenticatedAdmin')) {
    function getAuthenticatedAdmin(PDO $pdo): array
    {
        requireAdminLogin();

        $adminId = (int) $_SESSION['admin_id'];
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = ? LIMIT 1");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();

        if (!$admin || (isset($admin['status']) && (int)$admin['status'] === 0)) {
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_email']);
            setFlash('error', 'Session expired or your account is deactivated.');
            header('Location: /client-flow/admin/login.php');
            exit;
        }

        // Cache session attributes
        $_SESSION['admin_name']  = $admin['name'];
        $_SESSION['admin_email'] = $admin['email'];

        return $admin;
    }
}

/* ----------------------------------------------------------
 |  Staff Role & Permissions System (RBAC)
 * ---------------------------------------------------------- */
if (!function_exists('getAvailablePermissions')) {
    function getAvailablePermissions(): array
    {
        return [
            'Clients' => [
                'clients_view'   => 'View Clients',
                'clients_manage' => 'Add & Edit Clients',
                'clients_delete' => 'Delete Clients',
            ],
            'Projects' => [
                'projects_view'   => 'View Projects',
                'projects_manage' => 'Create & Edit Projects',
                'projects_delete' => 'Delete Projects',
            ],
            'Payments' => [
                'payments_view'   => 'View Payments & Invoices',
                'payments_record' => 'Record Payment Received',
                'payments_remind' => 'Send Payment Reminders',
                'payments_delete' => 'Delete Payment Invoices',
            ],
            'Notifications' => [
                'notifications_view'   => 'View Notifications',
                'notifications_manage' => 'Mark Read & Manage Notifications',
                'notifications_delete' => 'Delete Notifications',
            ],
            'Team' => [
                'team_view'   => 'View Team Members',
                'team_manage' => 'Add & Edit Team Members',
                'team_delete' => 'Delete Team Members',
            ]
        ];
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission(?array $adminUser, string $permission): bool
    {
        if (empty($adminUser)) {
            return false;
        }

        // Super Admin always has full unrestricted access
        if (($adminUser['role'] ?? '') === 'super_admin') {
            return true;
        }

        $rawPerms = $adminUser['permissions'] ?? '';
        if (empty($rawPerms)) {
            return false;
        }

        $perms = is_array($rawPerms) ? $rawPerms : json_decode($rawPerms, true);
        if (!is_array($perms)) {
            return false;
        }

        // Backward compatibility aliases
        if ($permission === 'team_view' && in_array('team_manage', $perms, true)) {
            return true;
        }
        if ($permission === 'team_delete' && in_array('team_manage', $perms, true)) {
            return true;
        }
        if ($permission === 'notifications_view' && (in_array('notifications_manage', $perms, true) || in_array('notifications_delete', $perms, true))) {
            return true;
        }

        return in_array($permission, $perms, true);
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(string $permission, ?array $adminUser = null): void
    {
        global $pdo;

        if ($adminUser === null) {
            if (isset($pdo)) {
                $adminUser = getAuthenticatedAdmin($pdo);
            } elseif (!empty($_SESSION['admin_id'])) {
                $adminUser = $_SESSION;
            } else {
                header('Location: /client-flow/admin/login.php');
                exit;
            }
        }

        if (!hasPermission($adminUser, $permission)) {
            setFlash('error', 'Access Denied: You do not have permission to perform that action.');
            header('Location: /client-flow/admin/dashboard.php');
            exit;
        }
    }
}

/* ----------------------------------------------------------
 |  Flash Notifications
 * ---------------------------------------------------------- */
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type'    => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('getFlash')) {
    function getFlash(): ?array
    {
        if (!empty($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

/* ----------------------------------------------------------
 |  CSRF Security Helpers
 * ---------------------------------------------------------- */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        $token = generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

if (!function_exists('verifyActionSecurity')) {
    function verifyActionSecurity(?string $token): bool
    {
        return verifyCsrfToken($token);
    }
}

require_once __DIR__ . '/project_flow.php';

if (!function_exists('resolveAdminEmail')) {
    /**
     * Resolve valid administrative name and email for notifications and audit logging
     */
    function resolveAdminEmail(array $admin = [], ?PDO $pdo = null): array
    {
        $name = trim($admin['name'] ?? '');
        $email = trim($admin['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!empty($_SESSION['admin_email']) && filter_var($_SESSION['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $email = trim($_SESSION['admin_email']);
            }
        }
        if (empty($name) && !empty($_SESSION['admin_name'])) {
            $name = trim($_SESSION['admin_name']);
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            global $pdo;
            $db = $pdo ?? null;
            if ($db instanceof PDO) {
                try {
                    $sa = $db->query("SELECT name, email FROM admin WHERE role = 'super_admin' AND status = 1 ORDER BY id ASC LIMIT 1")->fetch();
                    if ($sa && !empty($sa['email']) && filter_var($sa['email'], FILTER_VALIDATE_EMAIL)) {
                        $email = trim($sa['email']);
                        if (empty($name)) {
                            $name = trim($sa['name'] ?? 'Super Admin');
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (defined('SMTP_FROM_EMAIL') && filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL)) {
                $email = SMTP_FROM_EMAIL;
            } else {
                $email = 'sachink27263@gmail.com';
            }
        }
        if (empty($name)) {
            $name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Super Admin';
        }

        return ['name' => $name, 'email' => $email];
    }
}

/* ----------------------------------------------------------
 |  Input Sanitization
 * ---------------------------------------------------------- */
if (!function_exists('sanitize')) {
    function sanitize($data)
    {
        if (is_array($data)) {
            return array_map('sanitize', $data);
        }
        return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
    }
}

/* ----------------------------------------------------------
 |  Email Verification & Deliverability Validator
 * ---------------------------------------------------------- */
if (!function_exists('verifyEmailAddress')) {
    /**
     * Strict Email Syntax, Length, Typo & DNS MX/A Verification
     *
     * @param string $email
     * @param bool   $checkDns Verify domain has active MX/A mail exchanger records
     * @return array ['valid' => bool, 'message' => string, 'email' => string, 'domain' => string]
     */
    function verifyEmailAddress(string $email, bool $checkDns = true): array
    {
        $cleanEmail = trim(strtolower($email));
        if ($cleanEmail === '') {
            return ['valid' => false, 'message' => 'Email address is required.', 'email' => '', 'domain' => ''];
        }

        if (strlen($cleanEmail) > 150) {
            return ['valid' => false, 'message' => 'Email address cannot exceed 150 characters.', 'email' => $cleanEmail, 'domain' => ''];
        }

        // Standard RFC & strict regex check
        $regex = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}$/';
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL) || !preg_match($regex, $cleanEmail)) {
            return ['valid' => false, 'message' => 'Please enter a valid email address (e.g. name@domain.com).', 'email' => $cleanEmail, 'domain' => ''];
        }

        $parts = explode('@', $cleanEmail);
        if (count($parts) !== 2) {
            return ['valid' => false, 'message' => 'Invalid email address structure.', 'email' => $cleanEmail, 'domain' => ''];
        }
        $user = $parts[0];
        $domain = $parts[1];

        // Disallow consecutive dots or leading/trailing dots in username
        if (str_contains($user, '..') || str_starts_with($user, '.') || str_ends_with($user, '.')) {
            return ['valid' => false, 'message' => 'Invalid email: username cannot have consecutive or leading/trailing dots.', 'email' => $cleanEmail, 'domain' => $domain];
        }

        // Domain must have at least one dot and a valid TLD
        if (!str_contains($domain, '.')) {
            return ['valid' => false, 'message' => 'Email domain is missing a valid extension (e.g. .com, .in).', 'email' => $cleanEmail, 'domain' => $domain];
        }

        $tld = substr(strrchr($domain, '.'), 1);
        if (strlen($tld) < 2 || !ctype_alpha($tld)) {
            return ['valid' => false, 'message' => 'Email domain contains an invalid top-level extension.', 'email' => $cleanEmail, 'domain' => $domain];
        }

        // Common domain typo suggestions
        $typos = [
            'gmai.com'   => 'gmail.com',
            'gamil.com'  => 'gmail.com',
            'gmaill.com' => 'gmail.com',
            'gmail.co'   => 'gmail.com',
            'gmail.con'  => 'gmail.com',
            'hotmial.com'=> 'hotmail.com',
            'hotmaill.com'=> 'hotmail.com',
            'yaho.com'   => 'yahoo.com',
            'yaho.co.in' => 'yahoo.co.in',
            'outlok.com' => 'outlook.com',
        ];
        if (isset($typos[$domain])) {
            return ['valid' => false, 'message' => "Did you mean '{$user}@{$typos[$domain]}'? Please verify spelling.", 'email' => $cleanEmail, 'domain' => $domain];
        }

        // DNS MX and A record verification
        if ($checkDns && function_exists('checkdnsrr')) {
            $hasMx = @checkdnsrr($domain, 'MX');
            $hasA  = @checkdnsrr($domain, 'A');
            if (!$hasMx && !$hasA) {
                return ['valid' => false, 'message' => "Domain '{$domain}' does not exist or has no active mail server to receive emails.", 'email' => $cleanEmail, 'domain' => $domain];
            }
        }

        return ['valid' => true, 'message' => 'Email address is valid and deliverable.', 'email' => $cleanEmail, 'domain' => $domain];
    }
}


/* ----------------------------------------------------------
 |  Time Formatting Helper
 * ---------------------------------------------------------- */
if (!function_exists('timeAgo')) {
    function timeAgo($datetime): string
    {
        if (empty($datetime)) return 'Never';
        $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 5) return 'Just now';
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M d, Y', $time);
    }
}

/* ----------------------------------------------------------
 |  Pagination & Query String Helpers
 * ---------------------------------------------------------- */
if (!function_exists('getPaginationQuery')) {
    function getPaginationQuery(int $targetPage): string
    {
        $params = $_GET;
        $params['page'] = max(1, $targetPage);
        return '?' . http_build_query($params);
    }
}

/* ----------------------------------------------------------
 |  OTP & Email Masking Helpers
 * ---------------------------------------------------------- */
if (!function_exists('generateOtp')) {
    function generateOtp(int $length = 6): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('maskEmail')) {
    function maskEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        $parts = explode('@', $email, 2);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $len = strlen($name);
        if ($len <= 2) {
            $maskedName = substr($name, 0, 1) . '*';
        } else {
            $maskedName = substr($name, 0, 2) . str_repeat('*', max(1, $len - 3)) . substr($name, -1);
        }
        return $maskedName . '@' . $domain;
    }
}

if (!function_exists('sendSystemEmail')) {
    /**
     * Unified PHPMailer / SMTP Mail Dispatcher with SMTPOptions & Native Fallback
     */
    function sendSystemEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $plainBody = '',
        string $replyToEmail = '',
        string $replyToName = ''
    ): array {
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid or missing recipient email address.'];
        }

        if (empty($plainBody)) {
            $plainBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\r\n", $htmlBody));
        }

        $rootPath = defined('CF_ROOT') ? CF_ROOT : dirname(__DIR__, 2);
        if (!file_exists($rootPath . '/config/mail_config.php')) {
            $rootPath = dirname(__DIR__);
        }

        $mailConfigFile = $rootPath . '/config/mail_config.php';
        $phpMailerFile  = $rootPath . '/PHPMailer/src/PHPMailer.php';

        if (file_exists($mailConfigFile) && file_exists($phpMailerFile)) {
            require_once $mailConfigFile;
            require_once $rootPath . '/PHPMailer/src/Exception.php';
            require_once $rootPath . '/PHPMailer/src/PHPMailer.php';
            require_once $rootPath . '/PHPMailer/src/SMTP.php';

            try {
                $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

                if (defined('SMTP_HOST') && !empty(SMTP_HOST)) {
                    $mailer->isSMTP();
                    $mailer->Host       = SMTP_HOST;
                    $mailer->SMTPAuth   = true;
                    $mailer->Username   = SMTP_USERNAME;
                    $mailer->Password   = SMTP_PASSWORD;
                    $mailer->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mailer->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
                    $mailer->Timeout    = 10;
                    $mailer->SMTPOptions = [
                        'ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                }

                $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@clientflow.local';
                $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'ClientFlow';

                $mailer->setFrom($fromEmail, $fromName);
                $mailer->addAddress($toEmail, $toName);

                if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                    $mailer->addReplyTo($replyToEmail, $replyToName ?: $fromName);
                }

                $mailer->isHTML(true);
                $mailer->CharSet = 'UTF-8';
                $mailer->Subject = $subject;
                $mailer->Body    = $htmlBody;
                $mailer->AltBody = $plainBody;

                $mailer->send();
                return ['success' => true, 'message' => 'Email sent successfully via SMTP.'];
            } catch (\Throwable $pmEx) {
                // If SMTP fails, fallback to PHP native mail
                $headers = "MIME-Version: 1.0\r\n" .
                    "Content-type: text/html; charset=UTF-8\r\n" .
                    "From: " . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@clientflow.local') . "\r\n" .
                    (!empty($replyToEmail) ? "Reply-To: {$replyToEmail}\r\n" : "") .
                    "X-Mailer: PHP/" . phpversion();
                $nativeSent = @mail($toEmail, $subject, $htmlBody, $headers);
                return [
                    'success' => $nativeSent,
                    'message' => $nativeSent ? 'Dispatched via fallback mail server.' : ('SMTP error: ' . $pmEx->getMessage())
                ];
            }
        }

        // Native PHP mail fallback
        $headers = "MIME-Version: 1.0\r\n" .
            "Content-type: text/html; charset=UTF-8\r\n" .
            "From: noreply@clientflow.local\r\n" .
            "X-Mailer: PHP/" . phpversion();
        $sent = @mail($toEmail, $subject, $htmlBody, $headers);
        return [
            'success' => $sent,
            'message' => $sent ? 'Email dispatched via mail server.' : 'No mail transport available.'
        ];
    }
}

if (!function_exists('sendOtpEmail')) {
    function sendOtpEmail(string $toEmail, string $name, string $otp): bool
    {
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $subject = "Your Security Verification Code: {$otp}";
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verification Code</title>
</head>
<body style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;">
    <div style="max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
        <div style="background: #1e293b; color: #ffffff; padding: 20px 24px; text-align: center;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600;">ClientFlow Security</h3>
        </div>
        <div style="padding: 24px; color: #334155;">
            <p style="margin-top: 0;">Hello <strong>{$name}</strong>,</p>
            <p>We received a password reset request for your administrator account. Use the following One-Time Password (OTP) to continue:</p>
            <div style="text-align: center; margin: 24px 0;">
                <span style="display: inline-block; font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #2563eb; background: #eff6ff; padding: 12px 28px; border-radius: 8px; border: 1px dashed #bfdbfe;">
                    {$otp}
                </span>
            </div>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 0;">
                This code is valid for <strong>10 minutes</strong>. If you did not request this code, you can safely ignore this email.
            </p>
        </div>
        <div style="background: #f1f5f9; padding: 12px 24px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;">
            ClientFlow Agency Management System
        </div>
    </div>
</body>
</html>
HTML;

        $plainBody = "Hello {$name},\r\n\r\nYour One-Time Password (OTP) for password reset is: {$otp}\r\nThis code is valid for 10 minutes.\r\n\r\nRegards,\r\nClientFlow Team";

        $result = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody);
        return $result['success'];
    }
}

if (!function_exists('sendVerificationOtpEmail')) {
    /**
     * Dispatch an official email address verification OTP to a client or team member
     */
    function sendVerificationOtpEmail(string $toEmail, string $name, string $otp, string $purpose = 'client_create'): array
    {
        $toEmail = trim($toEmail);
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient email address.'];
        }

        $isClient = ($purpose === 'client_create');
        $subject = "Your Verification Code: {$otp} - ClientFlow";
        $badgeText = $isClient ? 'CLIENT EMAIL VERIFICATION' : 'STAFF EMAIL VERIFICATION';
        $headline = 'Verify Your Email Address';
        $desc = $isClient
            ? 'You are being registered for a new Client Portal account on ClientFlow. Please use the verification code below to verify your email address.'
            : 'You are being added as a new team staff member on ClientFlow. Please use the verification code below to verify your email address.';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: #3b82f6; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 10px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">{$headline}</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 13px;">ClientFlow Agency Management System</p>
        </div>
        <div style="padding: 28px 24px;">
            <p style="margin-top: 0; font-size: 15px;">Hello <strong>{$name}</strong>,</p>
            <p style="font-size: 14px; color: #475569;">{$desc}</p>
            <div style="text-align: center; margin: 26px 0; background: #f8fafc; padding: 20px; border-radius: 10px; border: 1px dashed #cbd5e1;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; margin-bottom: 8px;">
                    One-Time Verification Code (OTP)
                </div>
                <span style="display: inline-block; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #2563eb; font-family: Consolas, monospace;">
                    {$otp}
                </span>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px 14px; margin-bottom: 15px;">
                <p style="margin: 0; font-size: 12.5px; color: #92400e;">
                    <strong>Notice:</strong> This verification code will expire in <strong>10 minutes</strong>. Do not share this code with anyone.
                </p>
            </div>
            <p style="font-size: 13px; color: #64748b; margin: 0;">
                If you did not request this account or verification, please disregard this email.
            </p>
        </div>
        <div style="background: #f8fafc; padding: 16px 24px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;">
            Sent by ClientFlow Security &bull; Automated System Dispatch
        </div>
    </div>
</body>
</html>
HTML;

        $plainBody = "Hello {$name},\r\n\r\n"
            . "{$subject}\r\n\r\n"
            . "Your One-Time Password (OTP) verification code is: {$otp}\r\n\r\n"
            . "This code will expire in 10 minutes.\r\n\r\n"
            . "Regards,\r\nClientFlow Team";

        return sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody);
    }
}

if (!function_exists('sendClientWelcomeEmail')) {
    /**
     * Send professional welcome onboarding email to client with credentials,
     * and dispatch an official audit confirmation copy to the Admin.
     */
    function sendClientWelcomeEmail(array $client, string $plainPassword, array $admin = []): array
    {
        $toEmail     = trim($client['email'] ?? '');
        $clientName  = trim($client['name'] ?? 'Valued Client');
        $clientCode  = trim($client['client_code'] ?? 'N/A');
        $companyName = trim($client['company_name'] ?? '');
        $adminInfo   = resolveAdminEmail($admin);
        $adminName   = $adminInfo['name'];
        $adminEmail  = $adminInfo['email'];

        $hasClientEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasClientEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing client and admin email address.'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . '/client-flow/client/auth/login.php';
        $nowFormatted = date('d M Y, h:i A');

        $clientSent = false;
        $clientError = '';

        if ($hasClientEmail) {
            $clientSubject = "Welcome to ClientFlow - Your Client Portal Account Details";
            $companyLine = $companyName ? "<p style='margin: 0 0 16px; font-size: 14px; color: #64748b;'>Organization: <strong>" . htmlspecialchars($companyName) . "</strong></p>" : "";

            $clientHtmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$clientSubject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345; line-height: 1.5;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: rgba(255, 255, 255, 0.2); color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                CLIENT PORTAL ACTIVATED
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">Welcome to Your Portal</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">ClientFlow Agency Management System</p>
        </div>
        <div style="padding: 24px;">
            <p style="font-size: 14px; margin-top: 0; color: #334155;">
                Hello <strong>{$clientName}</strong>,
            </p>
            {$companyLine}
            <p style="font-size: 14px; color: #475569; margin-bottom: 16px;">
                Your client portal account is active. Use the credentials below to log in:
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b; width: 120px;">Client Code:</td>
                        <td style="padding: 4px 0; font-weight: 700; color: #2c3345;">{$clientCode}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Login Email:</td>
                        <td style="padding: 4px 0; font-weight: 600; color: #2c3345;">{$toEmail}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Password:</td>
                        <td style="padding: 4px 0;">
                            <span style="display: inline-block; background: #f3f0ff; color: #7848ff; font-weight: 700; font-family: monospace; padding: 2px 8px; border-radius: 4px; border: 1px dashed #d8b4fe;">
                                {$plainPassword}
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 20px 0;">
                <a href="{$loginUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    Log In to Client Portal &rarr;
                </a>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Authorized by {$adminName} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;
            $clientPlainBody = "Hello {$clientName},\r\n\r\n"
                . "Your ClientFlow client portal account has been created!\r\n\r\n"
                . "Account Credentials:\r\n"
                . "----------------------------------------\r\n"
                . "Client Code: {$clientCode}\r\n"
                . "Login Email: {$toEmail}\r\n"
                . "Password: {$plainPassword}\r\n"
                . "Login URL: {$loginUrl}\r\n"
                . "----------------------------------------\r\n\r\n"
                . "Please log in and update your password under Profile Settings.\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $cRes = sendSystemEmail($toEmail, $clientName, $clientSubject, $clientHtmlBody, $clientPlainBody, $adminEmail, $adminName);
            $clientSent = ($cRes['success'] ?? false);
            if (!$clientSent) {
                $clientError = $cRes['message'] ?? 'Failed sending client welcome email';
            }
        }

        return [
            'success'      => $clientSent,
            'client_sent'  => $clientSent,
            'admin_sent'   => false,
            'admin_email'  => '',
            'client_error' => $clientError,
            'message'      => $clientSent ? 'Client welcome email dispatched to client successfully.' : ($clientError ?: 'Failed sending client welcome email.')
        ];
    }
}

if (!function_exists('sendClientPasswordResetEmail')) {
    /**
     * Send password change notice to client, and dispatch an official audit copy to Admin.
     */
    function sendClientPasswordResetEmail(array $client, string $newPassword, array $admin = []): array
    {
        $toEmail     = trim($client['email'] ?? '');
        $clientName  = trim($client['name'] ?? 'Valued Client');
        $adminInfo   = resolveAdminEmail($admin);
        $adminName   = $adminInfo['name'];
        $adminEmail  = $adminInfo['email'];

        $hasClientEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasClientEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing client and admin email address.'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . '/client-flow/client/auth/login.php';
        $nowFormatted = date('d M Y, h:i A');

        $clientSent = false;
        $clientError = '';

        if ($hasClientEmail) {
            $clientSubject = "Security Notice: Your ClientFlow Password Has Been Updated";

            $clientHtmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$clientSubject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: #f59e0b; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                SECURITY NOTIFICATION
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Password Updated</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">ClientFlow Portal Account</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$clientName}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                Your client portal account password was updated by your agency administrator. You can now use your updated password below to sign in:
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 26px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Registered Email:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$toEmail}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">New Password:</td>
                        <td style="padding: 8px 0;">
                            <span style="display: inline-block; background: #eff6ff; color: #1d4ed8; font-weight: 700; font-family: Consolas, monospace; padding: 4px 10px; border-radius: 6px; border: 1px dashed #bfdbfe;">
                                {$newPassword}
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 30px 0 25px;">
                <a href="{$loginUrl}" style="display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
                    Log In to Portal &rarr;
                </a>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                <p style="margin: 0; font-size: 13px; color: #991b1b;">
                    <strong>Notice:</strong> If you did not request or expect this change, please contact your account manager immediately.
                </p>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Agency Portal</p>
            <p style="margin: 0;">Updated by {$adminName}</p>
        </div>
    </div>
</body>
</html>
HTML;
            $clientPlainBody = "Hello {$clientName},\r\n\r\n"
                . "Your ClientFlow account password has been updated by your administrator.\r\n\r\n"
                . "New Login Details:\r\n"
                . "Email: {$toEmail}\r\n"
                . "New Password: {$newPassword}\r\n"
                . "Portal URL: {$loginUrl}\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $cRes = sendSystemEmail($toEmail, $clientName, $clientSubject, $clientHtmlBody, $clientPlainBody, $adminEmail, $adminName);
            $clientSent = ($cRes['success'] ?? false);
            if (!$clientSent) {
                $clientError = $cRes['message'] ?? 'Failed sending client password notice';
            }
        }

        return [
            'success'      => $clientSent,
            'client_sent'  => $clientSent,
            'admin_sent'   => false,
            'admin_email'  => '',
            'message'      => $clientSent ? 'Password notification email dispatched to client.' : ($clientError ?: 'Failed sending client password notice.')
        ];
    }
}

if (!function_exists('sendTeamWelcomeEmail')) {
    /**
     * Send onboarding email with credentials and permissions to newly created team member,
     * and dispatch an official audit confirmation copy to the Admin.
     */
    function sendTeamWelcomeEmail(array $teamMember, string $plainPassword, array $admin = [], array $permissions = []): array
    {
        $toEmail    = trim($teamMember['email'] ?? '');
        $name       = trim($teamMember['name'] ?? 'Team Member');
        $adminInfo  = resolveAdminEmail($admin);
        $adminName  = $adminInfo['name'];
        $adminEmail = $adminInfo['email'];

        $hasMemberEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasMemberEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing team member and admin email address.'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . '/client-flow/admin/login.php';
        $nowFormatted = date('d M Y, h:i A');

        $allPermMap = [
            'clients_view'         => 'View Clients',
            'clients_manage'       => 'Add & Edit Clients',
            'clients_delete'       => 'Delete Clients',
            'projects_view'        => 'View Projects',
            'projects_manage'      => 'Create & Edit Projects',
            'projects_delete'      => 'Delete Projects',
            'payments_view'        => 'View Invoices & Payments',
            'payments_record'      => 'Record Payments',
            'payments_remind'      => 'Dispatch Payment Reminders',
            'payments_delete'      => 'Delete Invoices',
            'notifications_view'   => 'View Notifications',
            'notifications_manage' => 'Manage Notifications',
            'notifications_delete' => 'Delete Notifications',
            'team_view'            => 'View Team Members',
            'team_manage'          => 'Manage Team & Permissions',
            'team_delete'          => 'Delete Team Members',
        ];

        $permBadgesHtml = '';
        if (!empty($permissions)) {
            foreach ($permissions as $pKey) {
                $pLabel = $allPermMap[$pKey] ?? ucfirst(str_replace('_', ' ', $pKey));
                $permBadgesHtml .= "<span style='display: inline-block; background: #e0e7ff; color: #4338ca; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 12px; margin: 3px 4px 3px 0;'>✓ {$pLabel}</span>";
            }
        } else {
            $permBadgesHtml = "<span style='color: #64748b; font-size: 13px;'>Standard staff access (no special module permissions assigned).</span>";
        }

        $memberSent = false;
        $memberError = '';

        if ($hasMemberEmail) {
            $subject = "Welcome to the Team - Your ClientFlow Staff Account";

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: #4f46e5; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                STAFF ONBOARDING
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Welcome to the Team!</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">ClientFlow Agency Management System</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$name}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                You have been registered as a staff team member on ClientFlow by <strong>{$adminName}</strong>. You can now access the administrative panel with your assigned permissions.
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 16px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700;">
                    Your Staff Login Credentials
                </h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Role:</td>
                        <td style="padding: 8px 0; font-weight: 700; color: #4f46e5;">Staff Member</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Login Email:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$toEmail}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Initial Password:</td>
                        <td style="padding: 8px 0;">
                            <span style="display: inline-block; background: #eff6ff; color: #1d4ed8; font-weight: 700; font-family: Consolas, monospace; padding: 4px 10px; border-radius: 6px; border: 1px dashed #bfdbfe;">
                                {$plainPassword}
                            </span>
                        </td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Admin Panel URL:</td>
                        <td style="padding: 8px 0; color: #4f46e5; word-break: break-all; font-size: 13px;">
                            <a href="{$loginUrl}" style="color: #4f46e5; text-decoration: underline;">{$loginUrl}</a>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="background: #faf5ff; border: 1px solid #f3e8ff; border-radius: 10px; padding: 18px 22px; margin-bottom: 26px;">
                <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: #6b21a8; margin-bottom: 10px; letter-spacing: 0.5px;">
                    Assigned Module Permissions
                </div>
                <div>
                    {$permBadgesHtml}
                </div>
            </div>
            <div style="text-align: center; margin: 28px 0 25px;">
                <a href="{$loginUrl}" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(79,70,229,0.25);">
                    Access Staff Portal &rarr;
                </a>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                <p style="margin: 0; font-size: 13px; color: #92400e;">
                    <strong>Security Notice:</strong> Please sign in and update your temporary password immediately in your <strong>Profile Settings</strong>.
                </p>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Agency Management System</p>
            <p style="margin: 0;">Authorized by Super Admin {$adminName}</p>
        </div>
    </div>
</body>
</html>
HTML;
            $plainBody = "Hello {$name},\r\n\r\n"
                . "Welcome to the team! You have been registered as a staff member on ClientFlow by {$adminName}.\r\n\r\n"
                . "Login Credentials:\r\n"
                . "Email: {$toEmail}\r\n"
                . "Password: {$plainPassword}\r\n"
                . "Admin URL: {$loginUrl}\r\n\r\n"
                . "Please log in and update your password under Profile Settings.\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $mRes = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            $memberSent = ($mRes['success'] ?? false);
            if (!$memberSent) {
                $memberError = $mRes['message'] ?? 'Failed sending staff welcome email';
            }
        }

        return [
            'success'     => $memberSent,
            'member_sent' => $memberSent,
            'admin_sent'  => false,
            'admin_email' => '',
            'message'     => $memberSent ? 'Staff welcome credentials sent successfully.' : ($memberError ?: 'Failed sending staff welcome email.')
        ];
    }
}

if (!function_exists('sendTeamPasswordResetEmail')) {
    /**
     * Send password change notice to staff member, and dispatch an official audit copy to Admin.
     */
    function sendTeamPasswordResetEmail(array $teamMember, string $newPassword, array $admin = []): array
    {
        $toEmail    = trim($teamMember['email'] ?? '');
        $name       = trim($teamMember['name'] ?? 'Team Member');
        $adminInfo  = resolveAdminEmail($admin);
        $adminName  = $adminInfo['name'];
        $adminEmail = $adminInfo['email'];

        $hasMemberEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasMemberEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing team member and admin email address.'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . '/client-flow/admin/login.php';
        $nowFormatted = date('d M Y, h:i A');

        $memberSent = false;
        $memberError = '';

        if ($hasMemberEmail) {
            $subject = "Security Notice: Your ClientFlow Staff Password Has Been Updated";

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: #f59e0b; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                SECURITY NOTIFICATION
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Staff Password Updated</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">ClientFlow Admin Account</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$name}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                Your ClientFlow staff login password was updated by Super Admin <strong>{$adminName}</strong>. You can now use your updated password below to access the administrative dashboard:
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 26px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Login Email:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$toEmail}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">New Password:</td>
                        <td style="padding: 8px 0;">
                            <span style="display: inline-block; background: #eff6ff; color: #1d4ed8; font-weight: 700; font-family: Consolas, monospace; padding: 4px 10px; border-radius: 6px; border: 1px dashed #bfdbfe;">
                                {$newPassword}
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 30px 0 25px;">
                <a href="{$loginUrl}" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(79,70,229,0.25);">
                    Log In to Admin Panel &rarr;
                </a>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                <p style="margin: 0; font-size: 13px; color: #991b1b;">
                    <strong>Important:</strong> If you did not expect this password update, please contact Super Admin immediately.
                </p>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Agency Management System</p>
            <p style="margin: 0;">Authorized by Super Admin {$adminName}</p>
        </div>
    </div>
</body>
</html>
HTML;
            $plainBody = "Hello {$name},\r\n\r\n"
                . "Your ClientFlow staff password has been updated by Super Admin {$adminName}.\r\n\r\n"
                . "Login Details:\r\n"
                . "Email: {$toEmail}\r\n"
                . "New Password: {$newPassword}\r\n"
                . "Admin URL: {$loginUrl}\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $mRes = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            $memberSent = ($mRes['success'] ?? false);
            if (!$memberSent) {
                $memberError = $mRes['message'] ?? 'Failed sending staff password notice';
            }
        }

        return [
            'success'     => $memberSent,
            'member_sent' => $memberSent,
            'admin_sent'  => false,
            'admin_email' => '',
            'message'     => $memberSent ? 'Staff password updated and notified.' : ($memberError ?: 'Failed sending staff password notice.')
        ];
    }
}

if (!function_exists('sendAccountStatusEmail')) {
    /**
     * Send account status change notification (Activated / Deactivated) to client or team member,
     * and dispatch an official audit copy to Admin.
     */
    function sendAccountStatusEmail(string $userType, array $user, int $newStatus, array $admin = []): array
    {
        $toEmail     = trim($user['email'] ?? '');
        $name        = trim($user['name'] ?? ($userType === 'client' ? 'Valued Client' : 'Team Member'));
        $adminInfo   = resolveAdminEmail($admin);
        $adminName   = $adminInfo['name'];
        $adminEmail  = $adminInfo['email'];
        $isClient    = ($userType === 'client');
        $userTypeTitle = $isClient ? 'Client' : 'Staff Member';

        $hasTargetEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasTargetEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing recipient and admin email address.'];
        }

        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . ($isClient ? '/client-flow/client/auth/login.php' : '/client-flow/admin/login.php');
        $nowFormatted = date('d M Y, h:i A');

        $isActivated = ($newStatus === 1);
        $statusAction = $isActivated ? 'Activated' : 'Deactivated';

        $targetSent = false;
        $targetError = '';

        if ($hasTargetEmail) {
            if ($isActivated) {
                $badgeText  = 'ACCOUNT ACTIVATED';
                $badgeBg    = '#10b981';
                $headline   = $isClient ? 'Client Account Reactivated' : 'Staff Access Reactivated';
                $subject    = $isClient
                    ? 'Account Restored: Your ClientFlow Account Has Been Reactivated'
                    : 'Access Restored: Your ClientFlow Staff Account Has Been Reactivated';
                $bannerDesc = 'Your login access and system privileges have been successfully restored.';
                $message    = $isClient
                    ? "We are pleased to inform you that your ClientFlow client portal account has been reactivated by <strong>{$adminName}</strong>. You now have full access to view your projects, invoices, proposals, and collaborate with our team."
                    : "Your ClientFlow administrative staff access has been reactivated by Super Admin <strong>{$adminName}</strong>. You may now log in to the administrative panel using your regular credentials.";
            } else {
                $badgeText  = 'ACCOUNT DEACTIVATED';
                $badgeBg    = '#ef4444';
                $headline   = $isClient ? 'Client Account Deactivated' : 'Staff Access Deactivated';
                $subject    = $isClient
                    ? 'Security Notice: Your ClientFlow Account Has Been Suspended'
                    : 'Security Notice: Your ClientFlow Staff Access Has Been Deactivated';
                $bannerDesc = 'Account login and portal access have been temporarily disabled.';
                $message    = $isClient
                    ? "This is an official notice that your ClientFlow client portal account has been deactivated by administrator <strong>{$adminName}</strong>. Active portal login, project access, and dashboard services have been paused."
                    : "Your ClientFlow administrative staff access has been temporarily deactivated by Super Admin <strong>{$adminName}</strong>. You will be unable to log in to the admin panel while the account is suspended.";
            }

            $extraRowHtml = '';
            if ($isClient && !empty($user['client_code'])) {
                $extraRowHtml = '
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Client Code:</td>
                        <td style="padding: 8px 0; font-weight: 700; color: #0f172a; font-family: Consolas, monospace;">' . htmlspecialchars($user['client_code']) . '</td>
                    </tr>';
            } elseif (!$isClient && !empty($user['role'])) {
                $extraRowHtml = '
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Assigned Role:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">' . htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) . '</td>
                    </tr>';
            }

            $actionBtnHtml = '';
            if ($isActivated) {
                $actionBtnHtml = '
                <div style="text-align: center; margin: 30px 0 25px;">
                    <a href="' . $loginUrl . '" style="display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
                        Log In to Portal &rarr;
                    </a>
                </div>';
            }

            $noticeBox = $isActivated
                ? '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                        <p style="margin: 0; font-size: 13px; color: #166534;">
                            <strong>Status Note:</strong> All services are active. If you ever have difficulty logging in, please use the password reset feature or reach out to your administrator.
                        </p>
                   </div>'
                : '<div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                        <p style="margin: 0; font-size: 13px; color: #991b1b;">
                            <strong>Need Assistance?</strong> If you believe this deactivation was done in error or require urgent reactivation, please reply directly to this email or contact <strong>' . htmlspecialchars($adminName) . '</strong>.
                        </p>
                   </div>';

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: {$badgeBg}; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">{$headline}</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">{$bannerDesc}</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$name}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                {$message}
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 26px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Account Holder:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$name}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Account Email:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$toEmail}</td>
                    </tr>
                    {$extraRowHtml}
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Current Status:</td>
                        <td style="padding: 8px 0;">
                            <span style="display: inline-block; background: {$badgeBg}20; color: {$badgeBg}; font-weight: 700; font-size: 12px; padding: 3px 10px; border-radius: 6px; border: 1px solid {$badgeBg}40;">
                                {$badgeText}
                            </span>
                        </td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Action By:</td>
                        <td style="padding: 8px 0; color: #475569;">{$adminName}</td>
                    </tr>
                </table>
            </div>
            {$actionBtnHtml}
            {$noticeBox}
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Management System</p>
            <p style="margin: 0;">Authorized by {$adminName} &bull; Need help? Reply directly to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$name},\r\n\r\n"
                . ($isActivated ? "Your account has been reactivated.\r\n" : "Your account has been deactivated.\r\n")
                . "Action by: {$adminName}\r\n"
                . "Status: {$badgeText}\r\n"
                . "Email: {$toEmail}\r\n\r\n"
                . ($isActivated ? "You may log in at: {$loginUrl}\r\n\r\n" : "Please contact your administrator if you have questions.\r\n\r\n")
                . "Regards,\r\n{$adminName}";

            $tRes = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            $targetSent = ($tRes['success'] ?? false);
            if (!$targetSent) {
                $targetError = $tRes['message'] ?? 'Failed sending user status email';
            }
        }

        return [
            'success'     => $targetSent,
            'target_sent' => $targetSent,
            'admin_sent'  => false,
            'admin_email' => '',
            'message'     => $targetSent ? 'Account status email dispatched to user.' : ($targetError ?: 'Failed sending status update.')
        ];
    }
}

if (!function_exists('sendAccountDeletedEmail')) {
    /**
     * Send account deletion notice to client or team member,
     * and dispatch an official audit copy to Admin.
     */
    function sendAccountDeletedEmail(string $userType, array $user, array $admin = []): array
    {
        $toEmail     = trim($user['email'] ?? '');
        $name        = trim($user['name'] ?? ($userType === 'client' ? 'Valued Client' : 'Team Member'));
        $adminInfo   = resolveAdminEmail($admin);
        $adminName   = $adminInfo['name'];
        $adminEmail  = $adminInfo['email'];
        $isClient    = ($userType === 'client');
        $userTypeTitle = $isClient ? 'Client' : 'Staff Member';

        $hasTargetEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasTargetEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing recipient and admin email address.'];
        }

        $nowFormatted = date('d M Y, h:i A');
        $targetSent = false;
        $targetError = '';

        if ($hasTargetEmail) {
            $badgeText  = 'ACCOUNT REMOVED';
            $badgeBg    = '#dc2626';
            $headline   = $isClient ? 'Client Account Closed' : 'Staff Account Removed';
            $subject    = $isClient
                ? 'Notice: Your ClientFlow Account Has Been Closed'
                : 'Notice: Your ClientFlow Staff Account Has Been Removed';
            $bannerDesc = 'Official notice regarding the closure and removal of your account.';

            $message = $isClient
                ? "This email confirms that your ClientFlow client account has been closed and removed by administrator <strong>{$adminName}</strong>. All active client portal login credentials and sessions have been permanently terminated."
                : "This email confirms that your ClientFlow administrative staff account has been removed by Super Admin <strong>{$adminName}</strong>. All associated administrative privileges and login credentials have been revoked.";

            $extraRowHtml = '';
            if ($isClient && !empty($user['client_code'])) {
                $extraRowHtml = '
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Client Code:</td>
                        <td style="padding: 8px 0; font-weight: 700; color: #0f172a; font-family: Consolas, monospace;">' . htmlspecialchars($user['client_code']) . '</td>
                    </tr>';
            } elseif (!$isClient && !empty($user['role'])) {
                $extraRowHtml = '
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Assigned Role:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">' . htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) . '</td>
                    </tr>';
            }

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: {$badgeBg}; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">{$headline}</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">{$bannerDesc}</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$name}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                {$message}
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 26px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #64748b; width: 140px;">Account Holder:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$name}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Registered Email:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">{$toEmail}</td>
                    </tr>
                    {$extraRowHtml}
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Action Performed By:</td>
                        <td style="padding: 8px 0; color: #475569;">{$adminName}</td>
                    </tr>
                </table>
            </div>
            <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                <p style="margin: 0; font-size: 13px; color: #9f1239;">
                    <strong>Inquiries:</strong> If you have questions regarding past projects, historical records, or invoicing, please reply directly to this email to get in touch with our administration.
                </p>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Agency Portal</p>
            <p style="margin: 0;">Sent by {$adminName} &bull; ClientFlow Team</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$name},\r\n\r\n"
                . "This notice confirms that your ClientFlow account has been closed and removed by {$adminName}.\r\n"
                . "Account: {$name} ({$toEmail})\r\n\r\n"
                . "If you have any questions or require copies of past records, please reply to this email.\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $tRes = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            $targetSent = ($tRes['success'] ?? false);
            if (!$targetSent) {
                $targetError = $tRes['message'] ?? 'Failed sending account removal notice';
            }
        }

        return [
            'success'     => $targetSent,
            'target_sent' => $targetSent,
            'admin_sent'  => false,
            'admin_email' => '',
            'message'     => $targetSent ? 'Account deletion notice dispatched to user.' : ($targetError ?: 'Failed sending account removal notice.')
        ];
    }
}

if (!function_exists('sendAccountModifiedEmail')) {
    /**
     * Send notification to user when their profile details or permissions are modified by admin,
     * and dispatch an official audit copy to Admin.
     */
    function sendAccountModifiedEmail(string $userType, array $user, array $changesSummary, array $admin = []): array
    {
        $toEmail     = trim($user['email'] ?? '');
        $name        = trim($user['name'] ?? ($userType === 'client' ? 'Valued Client' : 'Team Member'));
        $adminInfo   = resolveAdminEmail($admin);
        $adminName   = $adminInfo['name'];
        $adminEmail  = $adminInfo['email'];
        $isClient    = ($userType === 'client');
        $userTypeTitle = $isClient ? 'Client' : 'Staff Member';

        $hasTargetEmail = (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL));
        $hasAdminEmail  = (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL));

        if (!$hasTargetEmail && !$hasAdminEmail) {
            return ['success' => false, 'message' => 'Invalid or missing recipient and admin email address.'];
        }

        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $loginUrl = $protocol . $host . ($isClient ? '/client-flow/client/auth/login.php' : '/client-flow/admin/login.php');
        $nowFormatted = date('d M Y, h:i A');

        $changesRows = '';
        $changesPlain = '';
        foreach ($changesSummary as $label => $val) {
            $changesRows .= '
                <tr style="border-top: 1px solid #f1f5f9;">
                    <td style="padding: 8px 0; color: #64748b; width: 140px;">' . htmlspecialchars($label) . ':</td>
                    <td style="padding: 8px 0; font-weight: 600; color: #0f172a;">' . htmlspecialchars($val) . '</td>
                </tr>';
            $changesPlain .= "{$label}: {$val}\r\n";
        }

        $targetSent = false;
        $targetError = '';

        if ($hasTargetEmail) {
            $badgeText  = 'ACCOUNT UPDATED';
            $badgeBg    = '#3b82f6';
            $headline   = $isClient ? 'Client Profile Updated' : 'Staff Profile & Permissions Updated';
            $subject    = $isClient
                ? 'Notice: Your ClientFlow Account Details Have Been Updated'
                : 'Notice: Your ClientFlow Staff Profile & Permissions Have Been Updated';
            $bannerDesc = 'Your account configuration has been updated by administration.';

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 32px 30px; text-align: center;">
            <div style="display: inline-block; background: {$badgeBg}; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 5px 14px; border-radius: 20px; margin-bottom: 12px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">{$headline}</h2>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 14px;">{$bannerDesc}</p>
        </div>
        <div style="padding: 32px 30px;">
            <p style="font-size: 16px; margin-top: 0; color: #1e293b;">
                Hello <strong>{$name}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px;">
                Your account details or permissions on ClientFlow were recently updated by administrator <strong>{$adminName}</strong>. Summary of current settings:
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 26px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    {$changesRows}
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 8px 0; color: #64748b;">Updated By:</td>
                        <td style="padding: 8px 0; color: #475569;">{$adminName}</td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 30px 0 25px;">
                <a href="{$loginUrl}" style="display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
                    Review In Dashboard &rarr;
                </a>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-top: 20px;">
                <p style="margin: 0; font-size: 13px; color: #64748b;">
                    If you did not authorize or expect these modifications, please contact administrator <strong>{$adminName}</strong> immediately.
                </p>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 0 0 4px; font-weight: 600; color: #334155;">ClientFlow Agency Portal</p>
            <p style="margin: 0;">Sent by {$adminName} &bull; Need help? Reply directly to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$name},\r\n\r\n"
                . "Your account settings have been updated by {$adminName}.\r\n\r\n"
                . "Summary of Details:\r\n"
                . "----------------------------------------\r\n"
                . $changesPlain
                . "----------------------------------------\r\n\r\n"
                . "Log in to check: {$loginUrl}\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $tRes = sendSystemEmail($toEmail, $name, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            $targetSent = ($tRes['success'] ?? false);
            if (!$targetSent) {
                $targetError = $tRes['message'] ?? 'Failed sending account modified notice';
            }
        }

        return [
            'success'     => $targetSent,
            'target_sent' => $targetSent,
            'admin_sent'  => false,
            'admin_email' => '',
            'message'     => $targetSent ? 'Account modification notice dispatched to user.' : ($targetError ?: 'Failed sending account modified notice.')
        ];
    }
}

/* ----------------------------------------------------------
 |  File / Image Upload Helper
 * ---------------------------------------------------------- */
if (!function_exists('uploadImage')) {
    function uploadImage(string $fileInputName, string $targetDir = 'profile', ?string $oldFile = null): array
    {
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

        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Image size must be under 5MB.'];
        }

        // Extension user ke filename se NAHI, verified MIME se
        $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $ext = $extMap[$mime];
        if (@getimagesize($file['tmp_name']) === false) {
            return ['success' => false, 'message' => 'Invalid image file.'];
        }
        $newName = 'client_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $destDir = __DIR__ . '/../assets/uploads/' . trim($targetDir, '/') . '/';

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
 |  Project Audit & Activity Logging
 * ---------------------------------------------------------- */
if (!function_exists('addProjectLog')) {
    function addProjectLog(PDO $pdo, int $projectId, string $action, string $title, ?string $description = null, string $actorType = 'admin', ?string $actorName = 'Admin'): bool
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO project_logs (project_id, action, title, description, actor_type, actor_name, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([$projectId, $action, $title, $description, $actorType, $actorName]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getProjectLogs')) {
    function getProjectLogs(PDO $pdo, int $projectId, int $limit = 50): array
    {
        try {
            $stmt = $pdo->prepare(
                "
                SELECT * FROM project_logs 
                WHERE project_id = ? 
                ORDER BY created_at DESC, id DESC 
                LIMIT " . (int)$limit
            );
            $stmt->execute([$projectId]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

/* ----------------------------------------------------------
 |  Notification System Helpers
 * ---------------------------------------------------------- */
if (!function_exists('addNotification')) {
    function addNotification(PDO $pdo, string $userType, int $userId, string $title, string $message, ?string $link = null, string $type = 'info', string $icon = 'bi-bell-fill'): bool
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_type, user_id, title, message, link, type, icon, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            return $stmt->execute([$userType, $userId, $title, $message, $link, $type, $icon]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getUnreadNotificationsCount')) {
    function getUnreadNotificationsCount(PDO $pdo, string $userType, int $userId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_type = ? AND user_id = ? AND is_read = 0");
            $stmt->execute([$userType, $userId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('getRecentNotifications')) {
    function getRecentNotifications(PDO $pdo, string $userType, int $userId, int $limit = 5): array
    {
        try {
            $stmt = $pdo->prepare(
                "
                SELECT * FROM notifications 
                WHERE user_type = ? AND user_id = ? 
                ORDER BY created_at DESC, id DESC 
                LIMIT " . (int)$limit
            );
            $stmt->execute([$userType, $userId]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('getAllNotifications')) {
    function getAllNotifications(PDO $pdo, string $userType, int $userId, string $filter = 'all', int $limit = 1000, string $search = ''): array
    {
        try {
            $sql = "SELECT * FROM notifications WHERE user_type = ? AND user_id = ?";
            $params = [$userType, $userId];

            if ($filter === 'unread') {
                $sql .= " AND is_read = 0";
            } elseif ($filter === 'read') {
                $sql .= " AND is_read = 1";
            }

            if ($search !== '') {
                $sql .= " AND (title LIKE ? OR message LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('getNotificationById')) {
    function getNotificationById(PDO $pdo, int $id, string $userType, int $userId): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND user_type = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$id, $userType, $userId]);
            $res = $stmt->fetch();
            return $res ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead(PDO $pdo, int $id, string $userType, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_type = ? AND user_id = ?");
            return $stmt->execute([$id, $userType, $userId]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('markNotificationsAsRead')) {
    function markNotificationsAsRead(PDO $pdo, string $userType, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_type = ? AND user_id = ?");
            return $stmt->execute([$userType, $userId]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('deleteNotification')) {
    function deleteNotification(PDO $pdo, int $id, string $userType, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_type = ? AND user_id = ?");
            return $stmt->execute([$id, $userType, $userId]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('bulkDeleteNotifications')) {
    function bulkDeleteNotifications(PDO $pdo, array $ids, string $userType, int $userId): int
    {
        $cleanIds = array_values(array_filter(array_map('intval', $ids)));
        if (empty($cleanIds)) return 0;
        try {
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $params = array_merge([$userType, $userId], $cleanIds);
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_type = ? AND user_id = ? AND id IN ($placeholders)");
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

/* ----------------------------------------------------------
 |  Status Badges
 * ---------------------------------------------------------- */
if (!function_exists('getProjectStatusBadge')) {
    function getProjectStatusBadge(string $status): string
    {
        switch ($status) {
            case 'draft':
                return '<span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="bi bi-pencil me-1"></i>Draft</span>';
            case 'proposal_sent':
            case 'waiting_confirmation':
                return '<span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>Proposal Sent</span>';
            case 'revision_requested':
                return '<span class="badge bg-info-subtle text-info border px-2 py-1"><i class="bi bi-pencil-square me-1"></i>Revision Requested</span>';
            case 'advance_pending':
            case 'advance_payment_pending':
                return '<span class="badge bg-info-subtle text-info border px-2 py-1"><i class="bi bi-cash-coin me-1"></i>Advance Pending</span>';
            case 'in_progress':
                return '<span class="badge bg-primary-subtle text-primary border px-2 py-1"><i class="bi bi-gear-fill me-1"></i>In Progress</span>';
            case 'review':
                return '<span class="badge border px-2 py-1" style="background: #f3e8ff; color: #7e22ce;"><i class="bi bi-award-fill me-1"></i>Review / Approval</span>';
            case 'on_hold':
                return '<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i>On Hold</span>';
            case 'completed':
                return '<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Completed</span>';
            case 'cancelled':
                return '<span class="badge bg-dark-subtle text-dark border px-2 py-1"><i class="bi bi-x-circle me-1"></i>Cancelled</span>';
            default:
                return '<span class="badge bg-light text-dark border px-2 py-1">' . htmlspecialchars(ucwords(str_replace('_', ' ', $status))) . '</span>';
        }
    }
}

if (!function_exists('getPaymentStatusBadge')) {
    function getPaymentStatusBadge(string $status): string
    {
        switch ($status) {
            case 'paid':
                return '<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>';
            case 'partial':
                return '<span class="badge bg-info-subtle text-info border px-2 py-1"><i class="bi bi-pie-chart-fill me-1"></i>Partial</span>';
            case 'overdue':
                return '<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue</span>';
            default:
                return '<span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-clock me-1"></i>Pending</span>';
        }
    }
}

/* ----------------------------------------------------------
 |  Project Status Automation & Milestone Overdue Sync
 * ---------------------------------------------------------- */
if (!function_exists('syncProjectStatus')) {
    function syncProjectStatus(PDO $pdo, int $projectId): void
    {
        try {
            $stPrj = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $stPrj->execute([$projectId]);
            $prj = $stPrj->fetch();
            if (!$prj) return;

            $currentStatus = $prj['status'];

            // Do not auto-alter unconfirmed, unagreed, or cancelled projects
            if (isPreAgreementStatus($currentStatus) || $currentStatus === 'cancelled' || empty($prj['agreed_at'])) {
                return;
            }

            // Sync payment milestone statuses (overdue check)
            $stPays = $pdo->prepare("SELECT * FROM payments WHERE project_id = ? AND deleted_at IS NULL ORDER BY due_date ASC, id ASC");
            $stPays->execute([$projectId]);
            $payments = $stPays->fetchAll();

            $today = date('Y-m-d');
            $hasOverdueMilestone = false;

            foreach ($payments as $pay) {
                if (!isInvoiceSettled($pay) && ($pay['due_date'] ?? '') < $today) {
                    $hasOverdueMilestone = true;
                    if ($pay['status'] !== 'overdue') {
                        $pdo->prepare("UPDATE payments SET status = 'overdue' WHERE id = ?")->execute([$pay['id']]);
                    }
                }
            }

            $lead = getProjectLeadInvoice($pdo, $projectId);
            $hasAdvancePending = $lead && !isInvoiceSettled($lead);

            // Completed stays completed unless changed manually
            if ($currentStatus === 'completed') {
                return;
            }

            // Auto-resume from on_hold if overdue milestone was paid
            if ($currentStatus === 'on_hold') {
                if (!$hasOverdueMilestone) {
                    $newStatus = $hasAdvancePending ? 'advance_payment_pending' : 'in_progress';
                    $pdo->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $projectId]);
                    addProjectLog($pdo, $projectId, 'status_change', 'Project Resumed', 'All overdue milestone installments have been settled. Project work resumed automatically.', 'system', 'System Automation');
                    addNotification($pdo, 'client', (int)$prj['client_id'], 'Project Work Resumed', "Payment received for overdue invoice. Work on project '{$prj['title']}' has resumed!", '/client-flow/client/projects.php', 'success', 'bi-play-circle-fill');
                }
                return;
            }

            // Do not pull Review backward
            if ($currentStatus === 'review') {
                return;
            }

            if ($hasAdvancePending) {
                if (!isAdvanceDueStatus($currentStatus)) {
                    $pdo->prepare("UPDATE projects SET status = 'advance_payment_pending', updated_at = NOW() WHERE id = ?")->execute([$projectId]);
                } elseif ($currentStatus === 'advance_pending') {
                    $pdo->prepare("UPDATE projects SET status = 'advance_payment_pending', updated_at = NOW() WHERE id = ?")->execute([$projectId]);
                }
            } elseif (isAdvanceDueStatus($currentStatus)) {
                $pdo->prepare("UPDATE projects SET status = 'in_progress', updated_at = NOW() WHERE id = ?")->execute([$projectId]);
            }
        } catch (Throwable $e) {
            // Silently ignore sync errors
        }
    }
}

if (!function_exists('checkAllOverdueMilestones')) {
    function checkAllOverdueMilestones(PDO $pdo): void
    {
        try {
            $today = date('Y-m-d');
            $pdo->prepare("
                UPDATE payments pay
                JOIN projects p ON p.id = pay.project_id
                SET pay.status = 'overdue'
                WHERE pay.status != 'paid' 
                  AND pay.paid_amount < pay.amount 
                  AND pay.due_date < ? 
                  AND pay.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                  AND p.status NOT IN ('draft','proposal_sent','waiting_confirmation','revision_requested','cancelled')
            ")->execute([$today]);
        } catch (Throwable $e) {
            // Silently ignore
        }
    }
}

/* ----------------------------------------------------------
 |  Payment Reminder Communication Helpers (HTML + PHPMailer)
 * ---------------------------------------------------------- */
if (!function_exists('sendPaymentReminderEmail')) {
    function sendPaymentReminderEmail(array $client, array $payment, array $project, array $admin, string $touchType = 'standard'): array
    {
        $to = $client['email'] ?? '';
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid or missing client email address.'];
        }

        $tot = (float)$payment['amount'];
        $pd  = (float)($payment['paid_amount'] ?? 0);
        $due = max(0, $tot - $pd);
        $dueDateFmt = !empty($payment['due_date']) ? date('d M Y', strtotime($payment['due_date'])) : 'On Delivery';
        $clientPortalUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/client-flow/client/payments.php';

        // Subject & Tone configuration based on 3-Touch Framework
        switch ($touchType) {
            case 'upcoming': // Touch 1: Advance reminder (3 days before due date)
                $subject = "Upcoming Payment Reminder: {$payment['title']} ({$payment['invoice_no']}) - Due in 3 Days";
                $badgeText = "UPCOMING PAYMENT";
                $badgeColor = "#3b82f6";
                $headerTitle = "Friendly Advance Payment Notice";
                $introText = "This is a friendly reminder that the following milestone payment for project <strong>" . htmlspecialchars($project['title']) . "</strong> will be due in 3 days on <strong>{$dueDateFmt}</strong>.";
                break;

            case 'due': // Touch 2: Due date / 1 day past due settlement alert
                $subject = "Payment Due Alert: Invoice {$payment['invoice_no']} - {$payment['title']}";
                $badgeText = "PAYMENT DUE";
                $badgeColor = "#f59e0b";
                $headerTitle = "Milestone Invoice Settlement Due";
                $introText = "This is a notification that invoice <strong>" . htmlspecialchars($payment['invoice_no']) . "</strong> for project <strong>" . htmlspecialchars($project['title']) . "</strong> is due for settlement.";
                break;

            case 'urgent': // Touch 3: 7+ days overdue & project paused
                $subject = "URGENT: Project Work Paused - Invoice {$payment['invoice_no']} Overdue";
                $badgeText = "PROJECT ON HOLD - OVERDUE";
                $badgeColor = "#ef4444";
                $headerTitle = "Urgent Notice: Work Temporarily Paused";
                $introText = "Invoice <strong>" . htmlspecialchars($payment['invoice_no']) . "</strong> is now overdue by over 7 days. In accordance with policy, development on <strong>" . htmlspecialchars($project['title']) . "</strong> has been placed on hold until this payment is settled.";
                break;

            default: // Manual Reminder from Admin
                $subject = "Payment Reminder: " . ($payment['title'] ?? 'Milestone Invoice') . " (" . ($payment['invoice_no'] ?? '') . ")";
                $badgeText = "PAYMENT REMINDER";
                $badgeColor = "#4f46e5";
                $headerTitle = "Payment Reminder for " . htmlspecialchars($project['title']);
                $introText = "This is a payment reminder regarding the milestone invoice scheduled for project <strong>" . htmlspecialchars($project['title']) . "</strong>.";
                break;
        }

        // Professional Responsive HTML Email Template
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: rgba(255, 255, 255, 0.2); color: #ffffff; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">{$headerTitle}</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">{$payment['invoice_no']} &bull; {$project['title']}</p>
        </div>

        <div style="padding: 24px;">
            <p style="font-size: 14px; line-height: 1.5; margin-top: 0; color: #334155;">
                Hello <strong>{$client['name']}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569; margin-bottom: 16px;">
                {$introText}
            </p>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Invoice No:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 700; color: #2c3345;">{$payment['invoice_no']}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Milestone:</td>
                        <td style="padding: 4px 0; text-align: right; color: #475569;">{$payment['title']}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Due Date:</td>
                        <td style="padding: 4px 0; text-align: right; color: #475569;">{$dueDateFmt}</td>
                    </tr>
                    <tr style="border-top: 1px dashed #cbd5e1;">
                        <td style="padding: 8px 0 4px; color: #64748b;">Total Amount:</td>
                        <td style="padding: 8px 0 4px; text-align: right; font-weight: 600; color: #2c3345;">₹{$tot}</td>
                    </tr>
                    <tr style="border-top: 2px solid #e2e8f0;">
                        <td style="padding: 8px 0 4px; font-weight: 700; color: #2c3345; font-size: 14px;">Balance Due:</td>
                        <td style="padding: 8px 0 4px; text-align: right; font-size: 16px; font-weight: 800; color: #7848ff;">₹{$due}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align: center; margin: 24px 0 16px;">
                <a href="{$clientPortalUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    View Invoice & Pay in Portal &rarr;
                </a>
            </div>

            <p style="font-size: 12px; color: #8e99ac; text-align: center; margin: 12px 0 0;">
                All receipts and deliverables can be accessed directly from your client portal.
            </p>
        </div>

        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Sent by {$admin['name']} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;

        // Plain Text Fallback Body
        $plainBody = "Hello {$client['name']},\r\n\r\n"
            . "{$subject}\r\n\r\n"
            . "Invoice No: {$payment['invoice_no']}\r\n"
            . "Project: {$project['title']}\r\n"
            . "Milestone: {$payment['title']}\r\n"
            . "Total Amount: ₹" . number_format($tot, 2) . "\r\n"
            . "Paid Amount: ₹" . number_format($pd, 2) . "\r\n"
            . "Balance Due: ₹" . number_format($due, 2) . "\r\n"
            . "Due Date: {$dueDateFmt}\r\n\r\n"
            . "Log in to your client portal to complete payment:\r\n{$clientPortalUrl}\r\n\r\n"
            . "Regards,\r\n" . ($admin['name'] ?? 'Admin Team');

        // Send via unified system mailer
        return sendSystemEmail($to, $client['name'], $subject, $htmlBody, $plainBody, $admin['email'] ?? '', $admin['name'] ?? 'Admin Team');
    }
}

/* ----------------------------------------------------------
 |  Project Proposal & Milestone Agreement Email Dispatcher
 * ---------------------------------------------------------- */
if (!function_exists('sendProjectProposalEmail')) {
    function sendProjectProposalEmail(PDO $pdo, int $projectId): array
    {
        try {
            $st = $pdo->prepare("
                SELECT p.*, 
                       c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.company_name,
                       a.name AS admin_name, a.email AS admin_email
                FROM projects p
                JOIN clients c ON c.id = p.client_id
                LEFT JOIN admin a ON a.id = p.created_by
                WHERE p.id = ? AND p.deleted_at IS NULL
            ");
            $st->execute([$projectId]);
            $prj = $st->fetch();

            if (!$prj) {
                return ['success' => false, 'message' => 'Project not found.'];
            }

            $to = trim($prj['client_email'] ?? '');
            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid or missing client email address.'];
            }

            // Always generate a fresh cryptographic token on dispatch/resend so older email links expire immediately (3 Days Validity)
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE projects SET agreement_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY), status = 'proposal_sent' WHERE id = ?")->execute([$token, $projectId]);

            // Fetch scheduled payment milestones
            $stPay = $pdo->prepare("SELECT * FROM payments WHERE project_id = ? AND deleted_at IS NULL ORDER BY due_date ASC, id ASC");
            $stPay->execute([$projectId]);
            $milestones = $stPay->fetchAll();

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $proposalUrl = 'http://' . $host . '/client-flow/view_proposal.php?token=' . urlencode($token);
            $clientPortalUrl = 'http://' . $host . '/client-flow/client/dashboard.php';

            $totalBudgetFmt = number_format((float) $prj['total_amount'], 2);
            $deadlineFmt = !empty($prj['deadline']) ? date('d M Y', strtotime($prj['deadline'])) : 'Open';
            $adminName = $prj['admin_name'] ?: 'Admin Team';
            $adminEmail = $prj['admin_email'] ?: '';

            $subject = "Project Proposal & Milestone Agreement: {$prj['title']} ({$prj['project_code']})";

            // Milestone Rows HTML (Compact)
            $milestoneRowsHtml = '';
            if (!empty($milestones)) {
                foreach ($milestones as $idx => $m) {
                    $mNum = $idx + 1;
                    $mTitle = htmlspecialchars($m['title']);
                    $mAmt = number_format((float)$m['amount'], 2);
                    $mDue = !empty($m['due_date']) ? date('d M Y', strtotime($m['due_date'])) : 'On Delivery';
                    $milestoneRowsHtml .= "
                    <tr>
                        <td style='padding: 7px 10px; border-bottom: 1px solid #eef1f6; font-size: 12px; color: #8e99ac;'>#{$mNum}</td>
                        <td style='padding: 7px 10px; border-bottom: 1px solid #eef1f6; font-size: 13px; font-weight: 500; color: #2c3345;'>{$mTitle}</td>
                        <td style='padding: 7px 10px; border-bottom: 1px solid #eef1f6; font-size: 13px; text-align: right; font-weight: 700; color: #7848ff;'>₹{$mAmt}</td>
                        <td style='padding: 7px 10px; border-bottom: 1px solid #eef1f6; font-size: 12px; text-align: right; color: #64748b;'>{$mDue}</td>
                    </tr>";
                }
            } else {
                $milestoneRowsHtml = "<tr><td colspan='4' style='padding: 10px; text-align: center; color: #8e99ac; font-size: 12px;'>Full project payment scheduled upon delivery.</td></tr>";
            }

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: rgba(255, 255, 255, 0.2); color: #ffffff; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                PROJECT PROPOSAL & AGREEMENT
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">{$prj['title']}</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">Ref: {$prj['project_code']}</p>
        </div>

        <!-- Body -->
        <div style="padding: 24px;">
            <p style="font-size: 14px; line-height: 1.5; margin-top: 0; color: #334155;">
                Hello <strong>{$prj['client_name']}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569; margin-bottom: 16px;">
                A formal project proposal and milestone schedule is ready for your review and digital acceptance.
            </p>

            <!-- Project Details Card (Minimized) -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin: 16px 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Total Budget:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 700; font-size: 15px; color: #7848ff;">₹{$totalBudgetFmt}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Target Delivery:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #2c3345;">{$deadlineFmt}</td>
                    </tr>
                </table>
            </div>

            <!-- Milestone Schedule Table -->
            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin: 16px 0 8px;">Payment Milestone Schedule</div>
            <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="padding: 7px 10px; text-align: left; font-size: 11px; color: #475569;">#</th>
                            <th style="padding: 7px 10px; text-align: left; font-size: 11px; color: #475569;">Milestone</th>
                            <th style="padding: 7px 10px; text-align: right; font-size: 11px; color: #475569;">Amount</th>
                            <th style="padding: 7px 10px; text-align: right; font-size: 11px; color: #475569;">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$milestoneRowsHtml}
                    </tbody>
                </table>
            </div>

            <!-- Action Button -->
            <div style="text-align: center; margin: 24px 0 16px;">
                <a href="{$proposalUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    Review & Accept Proposal &rarr;
                </a>
                <p style="font-size: 11.5px; color: #8e99ac; margin: 8px 0 0;">⚡ This agreement link is valid for 3 days.</p>
            </div>

            <p style="font-size: 12px; color: #8e99ac; text-align: center; margin: 12px 0 0;">
                You can also review deliverables anytime in your <a href="{$clientPortalUrl}" style="color: #7848ff; text-decoration: underline;">Client Portal</a>.
            </p>
        </div>

        <!-- Footer -->
        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Sent by {$adminName} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$prj['client_name']},\r\n\r\n"
                . "{$subject}\r\n\r\n"
                . "Project: {$prj['title']}\r\n"
                . "Project Code: {$prj['project_code']}\r\n"
                . "Total Budget: ₹{$totalBudgetFmt}\r\n"
                . "Target Delivery: {$deadlineFmt}\r\n\r\n"
                . "Please review and digitally sign your project proposal using the link below (Valid for 3 days):\r\n"
                . "{$proposalUrl}\r\n\r\n"
                . "Or access via your client portal:\r\n"
                . "{$clientPortalUrl}\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            $mailRes = sendSystemEmail($to, $prj['client_name'], $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
            if ($mailRes['success']) {
                $pdo->prepare("UPDATE projects SET proposal_sent_at = NOW(), token_expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY), status = 'proposal_sent' WHERE id = ?")->execute([$projectId]);
                if (function_exists('addProjectLog')) {
                    addProjectLog($pdo, $projectId, 'proposal_sent', 'Proposal Dispatched to Client', "Milestone proposal and digital agreement link dispatched via email to {$to}.", 'system', 'System Automation');
                }
            }
            return $mailRes;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Proposal email error: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('sendPaymentReceiptEmail')) {
    /**
     * Send official payment receipt / settlement email to client
     */
    function sendPaymentReceiptEmail(PDO $pdo, int $paymentId, float $amountReceived, string $method, array $admin = []): array
    {
        try {
            $st = $pdo->prepare("
                SELECT pay.*, 
                       prj.title AS project_title, prj.project_code, prj.status AS project_status,
                       c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.company_name
                FROM payments pay
                JOIN projects prj ON prj.id = pay.project_id
                JOIN clients c ON c.id = pay.client_id
                WHERE pay.id = ? AND pay.deleted_at IS NULL
            ");
            $st->execute([$paymentId]);
            $payment = $st->fetch();

            if (!$payment) {
                return ['success' => false, 'message' => 'Payment record not found.'];
            }

            $to = trim($payment['client_email'] ?? '');
            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid or missing client email address.'];
            }

            $clientName  = $payment['client_name'];
            $adminName   = $admin['name'] ?? 'ClientFlow Team';
            $adminEmail  = $admin['email'] ?? '';

            $tot         = (float)$payment['amount'];
            $paidAmt     = (float)$payment['paid_amount'];
            $rem         = max(0, $tot - $paidAmt);
            $isSettled   = ($payment['status'] === 'paid' || $rem <= 0.005);
            $badgeColor  = $isSettled ? '#10b981' : '#3b82f6';
            $badgeText   = $isSettled ? 'PAYMENT RECEIVED · FULLY SETTLED' : 'PARTIAL PAYMENT RECEIVED';
            $invoiceNo   = $payment['invoice_no'];
            $projectCode = $payment['project_code'];
            $projectTitle = $payment['project_title'];
            $txnId       = !empty($payment['transaction_id']) ? $payment['transaction_id'] : 'N/A';
            $paidDateFmt = !empty($payment['paid_date']) ? date('d M Y', strtotime($payment['paid_date'])) : date('d M Y');
            $totFmt      = number_format($tot, 2);
            $paidFmt     = number_format($paidAmt, 2);
            $recFmt      = number_format($amountReceived, 2);
            $remFmt      = number_format($rem, 2);

            $rawStatus   = $payment['project_status'] ?? 'in_progress';
            $isAdvance   = (!empty($payment['is_advance']) && (int)$payment['is_advance'] === 1);
            if ($rawStatus === 'in_progress' || $isAdvance) {
                $projectStatusText = 'In Process';
            } else {
                $projectStatusText = function_exists('projectStatusLabel') ? projectStatusLabel($rawStatus) : ucfirst(str_replace('_', ' ', $rawStatus));
                if ($projectStatusText === 'In Progress') {
                    $projectStatusText = 'In Process';
                }
            }

            if ($isAdvance || $projectStatusText === 'In Process') {
                $subject = "Payment Receipt: ₹{$recFmt} received · Project Status : In Process";
            } else {
                $subject = "Payment Receipt: ₹{$recFmt} received for Invoice {$invoiceNo}";
            }

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $portalUrl = 'http://' . $host . '/client-flow/client/payments.php';

            $remColor = ($rem > 0 ? '#ef4444' : '#10b981');

            $statusBannerHtml = '';
            if ($projectStatusText === 'In Process') {
                $statusBannerHtml = <<<HTML
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 8px; padding: 14px 18px; margin: 20px 0;">
                <p style="margin: 0; font-size: 14px; font-weight: 700; color: #166534;">
                    &#9679; Project Status : In Process
                </p>
                <p style="margin: 4px 0 0; font-size: 13px; color: #15803d; line-height: 1.5;">
                    Advance payment received! Work on your project has officially started and active development is in process.
                </p>
            </div>
HTML;
            } else {
                $statusBannerHtml = <<<HTML
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 12px 16px; margin: 20px 0;">
                <p style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b;">
                    Project Status : {$projectStatusText}
                </p>
            </div>
HTML;
            }

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: #2ed8a7; color: #064e3b; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                {$badgeText}
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 700;">₹{$recFmt} Payment Received</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">{$invoiceNo} &bull; {$projectTitle}</p>
        </div>

        <div style="padding: 24px;">
            <p style="font-size: 14px; line-height: 1.5; margin-top: 0; color: #334155;">
                Hello <strong>{$clientName}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569; margin-bottom: 16px;">
                Payment of <strong>₹{$recFmt}</strong> has been successfully credited for project <strong>{$projectTitle}</strong> ({$projectCode}).
            </p>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Invoice No:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 700; color: #2c3345;">{$invoiceNo}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Milestone:</td>
                        <td style="padding: 4px 0; text-align: right; color: #475569;">{$payment['title']}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Method / Txn:</td>
                        <td style="padding: 4px 0; text-align: right; color: #475569;">{$method} {$txnId}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Payment Date:</td>
                        <td style="padding: 4px 0; text-align: right; color: #475569;">{$paidDateFmt}</td>
                    </tr>
                    <tr style="border-top: 1px dashed #cbd5e1;">
                        <td style="padding: 8px 0 4px; color: #64748b;">Amount Received:</td>
                        <td style="padding: 8px 0 4px; text-align: right; font-weight: 700; color: #059669; font-size: 15px;">₹{$paidFmt}</td>
                    </tr>
                    <tr style="border-top: 2px solid #e2e8f0;">
                        <td style="padding: 8px 0 4px; font-weight: 700; color: #2c3345;">Balance Remaining:</td>
                        <td style="padding: 8px 0 4px; text-align: right; font-weight: 800; font-size: 15px; color: {$remColor};">₹{$remFmt}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align: center; margin: 24px 0 16px;">
                <a href="{$portalUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    View Receipts & Invoices &rarr;
                </a>
            </div>
        </div>

        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Authorized by {$adminName} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$clientName},\r\n\r\n"
                . "Thank you! We have received your payment of ₹{$recFmt} for Invoice {$invoiceNo}.\r\n"
                . "Project: {$projectTitle} ({$projectCode})\r\n"
                . "Project Status : {$projectStatusText}\r\n"
                . "Method: {$method}\r\n"
                . "Transaction ID: {$txnId}\r\n"
                . "Date: {$paidDateFmt}\r\n"
                . "Balance Due: ₹{$remFmt}\r\n\r\n"
                . ($projectStatusText === 'In Process' ? "Advance payment received! Work on your project has officially started (Project Status : In Process).\r\n\r\n" : "")
                . "Access your portal to download receipts:\r\n{$portalUrl}\r\n\r\n"
                . "Regards,\r\n{$adminName}";

            return sendSystemEmail($to, $clientName, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('sendAgreementConfirmedEmail')) {
    /**
     * Send agreement signed / confirmed notice to client & admin
     */
    function sendAgreementConfirmedEmail(PDO $pdo, int $projectId): array
    {
        try {
            $st = $pdo->prepare("
                SELECT p.*, 
                       c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.company_name,
                       a.name AS admin_name, a.email AS admin_email
                FROM projects p
                JOIN clients c ON c.id = p.client_id
                LEFT JOIN admin a ON a.id = p.created_by
                WHERE p.id = ? AND p.deleted_at IS NULL
            ");
            $st->execute([$projectId]);
            $prj = $st->fetch();

            if (!$prj) return ['success' => false, 'message' => 'Project not found.'];

            $to = trim($prj['client_email'] ?? '');
            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid client email.'];
            }

            $clientName  = $prj['client_name'];
            $adminName   = $prj['admin_name'] ?: 'Super Admin';
            $adminEmail  = $prj['admin_email'] ?: '';
            $subject     = "Agreement Confirmed: {$prj['title']} ({$prj['project_code']})";
            $signedDate  = !empty($prj['agreed_at']) ? date('d M Y, h:i A', strtotime($prj['agreed_at'])) : date('d M Y, h:i A');
            $budgetFmt   = number_format((float)$prj['total_amount'], 2);

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $portalUrl = 'http://' . $host . '/client-flow/client/projects.php';

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: #2ed8a7; color: #064e3b; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                AGREEMENT SIGNED & CONFIRMED
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">Agreement Formally Executed</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">{$prj['title']} &bull; {$prj['project_code']}</p>
        </div>
        <div style="padding: 24px;">
            <p style="font-size: 14px; line-height: 1.5; margin-top: 0; color: #334155;">
                Hello <strong>{$clientName}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569; margin-bottom: 16px;">
                This email confirms that the digital project proposal and milestone agreement for <strong>{$prj['title']}</strong> was digitally signed and executed on <strong>{$signedDate}</strong>.
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Project Code:</td>
                        <td style="padding: 4px 0; font-weight: 700; text-align: right; color: #2c3345;">{$prj['project_code']}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Total Budget:</td>
                        <td style="padding: 4px 0; font-weight: 700; text-align: right; color: #7848ff;">₹{$budgetFmt}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Executed At:</td>
                        <td style="padding: 4px 0; text-align: right; color: #059669; font-weight: 600;">{$signedDate}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Digitally Signed By:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #2c3345;">{$clientName}</td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 24px 0 16px;">
                <a href="{$portalUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    Access Client Dashboard &rarr;
                </a>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Authorized by {$adminName} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$clientName},\r\n\r\nAgreement confirmed for {$prj['title']} ({$prj['project_code']}) on {$signedDate}.\r\nTotal Budget: ₹{$budgetFmt}\r\nDashboard: {$portalUrl}\r\n\r\nRegards,\r\n{$adminName}";

            return sendSystemEmail($to, $clientName, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('sendProjectCompletedEmail')) {
    /**
     * Send project completion congratulations email to client
     */
    function sendProjectCompletedEmail(PDO $pdo, int $projectId): array
    {
        try {
            $st = $pdo->prepare("
                SELECT p.*, 
                       c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.company_name,
                       a.name AS admin_name, a.email AS admin_email
                FROM projects p
                JOIN clients c ON c.id = p.client_id
                LEFT JOIN admin a ON a.id = p.created_by
                WHERE p.id = ? AND p.deleted_at IS NULL
            ");
            $st->execute([$projectId]);
            $prj = $st->fetch();

            if (!$prj) return ['success' => false, 'message' => 'Project not found.'];

            $to = trim($prj['client_email'] ?? '');
            if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid client email.'];
            }

            $clientName  = $prj['client_name'];
            $adminName   = $prj['admin_name'] ?: 'Super Admin';
            $adminEmail  = $prj['admin_email'] ?: '';
            $subject     = "Project Completed: Congratulations! '{$prj['title']}' is Officially Complete";
            $budgetFmt   = number_format((float)$prj['total_amount'], 2);

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $portalUrl = 'http://' . $host . '/client-flow/client/projects.php';

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef1f6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #2c3345;">
    <div style="max-width: 580px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 18px rgba(120, 72, 255, 0.08); border: 1px solid #e2e8f0;">
        <div style="background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); padding: 26px 24px; text-align: center;">
            <div style="display: inline-block; background: rgba(255, 255, 255, 0.2); color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;">
                PROJECT DELIVERED & COMPLETED
            </div>
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700;">Congratulations!</h2>
            <p style="color: rgba(255, 255, 255, 0.85); margin: 4px 0 0; font-size: 13px;">{$prj['title']} &bull; {$prj['project_code']}</p>
        </div>
        <div style="padding: 24px;">
            <p style="font-size: 14px; line-height: 1.5; margin-top: 0; color: #334155;">
                Hello <strong>{$clientName}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569; margin-bottom: 16px;">
                All deliverables and milestones for project <strong>{$prj['title']}</strong> have been officially completed and approved!
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Project Title:</td>
                        <td style="padding: 4px 0; font-weight: 600; text-align: right; color: #2c3345;">{$prj['title']}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Total Value:</td>
                        <td style="padding: 4px 0; font-weight: 700; text-align: right; color: #7848ff;">₹{$budgetFmt}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748b;">Status:</td>
                        <td style="padding: 4px 0; text-align: right;"><span style="background: #dcfce7; color: #15803d; font-weight: 700; font-size: 11px; padding: 2px 8px; border-radius: 4px;">COMPLETED</span></td>
                    </tr>
                </table>
            </div>
            <div style="text-align: center; margin: 24px 0 16px;">
                <a href="{$portalUrl}" style="display: inline-block; background: linear-gradient(135deg, #7848ff 0%, #693be7 100%); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 14px rgba(120, 72, 255, 0.35);">
                    View Delivered Project Files &rarr;
                </a>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 14px 20px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #8e99ac;">
            <p style="margin: 0;">Authorized by {$adminName} &bull; ClientFlow Agency Management System</p>
        </div>
    </div>
</body>
</html>
HTML;

            $plainBody = "Hello {$clientName},\r\n\r\nCongratulations! Project '{$prj['title']}' ({$prj['project_code']}) is officially completed!\r\nAccess your portal to download delivered assets:\r\n{$portalUrl}\r\n\r\nRegards,\r\n{$adminName}";

            return sendSystemEmail($to, $clientName, $subject, $htmlBody, $plainBody, $adminEmail, $adminName);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('generateWhatsAppReminderUrl')) {
    function generateWhatsAppReminderUrl(string $phone, string $name, string $projectTitle, float $dueAmount, string $dueDate, string $invoiceNo, float $paidAmount, float $totalAmount): string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }

        $dueDateFmt = !empty($dueDate) ? date('d M Y', strtotime($dueDate)) : 'Scheduled Delivery';
        $portalUrl  = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/client-flow/client/payments.php";

        $msg = "Hello {$name},\n\n"
            . "This is a friendly payment reminder for project *{$projectTitle}*.\n\n"
            . "*Invoice No:* {$invoiceNo}\n"
            . "*Total Amount:* ₹" . number_format($totalAmount, 2) . "\n"
            . "*Paid Amount:* ₹" . number_format($paidAmount, 2) . "\n"
            . "*Balance Due:* ₹" . number_format($dueAmount, 2) . "\n"
            . "*Due Date:* {$dueDateFmt}\n\n"
            . "Click here to view invoice and make payment:\n"
            . "{$portalUrl}\n\n"
            . "Thank you!";

        return "https://wa.me/{$cleanPhone}?text=" . rawurlencode($msg);
    }
}

/* ----------------------------------------------------------
 |  3-Touch Automated Reminders & Auto-Pause Automation
 * ---------------------------------------------------------- */
if (!function_exists('runAutomatedPaymentReminders')) {
    function runAutomatedPaymentReminders(PDO $pdo): array
    {
        $stats = [
            'touch1_count'   => 0, // 3 days before due date
            'touch2_count'   => 0, // 1 day past due date
            'touch3_count'   => 0, // 7+ days past due date (urgent)
            'projects_paused' => 0, // Paused due to Touch 3
            'errors'         => []
        ];

        try {
            // First run overdue milestone sync
            checkAllOverdueMilestones($pdo);

            // Fetch default admin for sender identity
            $stAdmin = $pdo->query("SELECT id, name, email FROM admin ORDER BY id ASC LIMIT 1");
            $defaultAdmin = $stAdmin->fetch() ?: ['id' => 1, 'name' => 'Admin Team', 'email' => 'admin@clientflow.local'];

            // Fetch candidate payments for active projects
            $sql = "
                SELECT pay.*, 
                       p.title AS project_title, p.project_code, p.status AS project_status,
                       c.id AS client_id, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
                FROM payments pay
                JOIN projects p ON p.id = pay.project_id
                JOIN clients c ON c.id = pay.client_id
                WHERE pay.status != 'paid'
                  AND pay.paid_amount < pay.amount
                  AND pay.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                  AND p.status NOT IN ('draft', 'proposal_sent', 'waiting_confirmation', 'revision_requested', 'cancelled', 'completed')
            ";
            $candidates = $pdo->query($sql)->fetchAll();

            $today = date('Y-m-d');
            $todayTime = strtotime($today);

            foreach ($candidates as $row) {
                $payId = (int) $row['id'];
                $clientId = (int) $row['client_id'];
                $projectId = (int) $row['project_id'];
                $dueDate = $row['due_date'];
                if (empty($dueDate)) continue;

                $dueTime = strtotime($dueDate);
                $diffDays = (int) round(($dueTime - $todayTime) / 86400); // positive = future, negative = past

                $clientData = ['id' => $clientId, 'name' => $row['client_name'], 'email' => $row['client_email'], 'phone' => $row['client_phone']];
                $payData    = $row;
                $prjData    = ['id' => $projectId, 'title' => $row['project_title'], 'code' => $row['project_code']];

                $lastSent = !empty($row['last_reminder_sent_at']) ? strtotime($row['last_reminder_sent_at']) : 0;
                $daysSinceLastSent = $lastSent > 0 ? (time() - $lastSent) / 86400 : 999;

                // -------------------------------------------------------------
                // Touch 1: Advance Notice (Due in exactly 3 days)
                // -------------------------------------------------------------
                if ($diffDays === 3 && $daysSinceLastSent >= 1) {
                    sendPaymentReminderEmail($clientData, $payData, $prjData, $defaultAdmin, 'upcoming');

                    $pdo->prepare("
                        INSERT INTO reminder_logs (payment_id, client_id, type, sent_by, notes, sent_at)
                        VALUES (?, ?, 'email', ?, ?, NOW())
                    ")->execute([$payId, $clientId, $defaultAdmin['id'], '[Automated Touch 1] Upcoming reminder dispatched (Due in 3 days)']);

                    $pdo->prepare("UPDATE payments SET last_reminder_sent_at = NOW() WHERE id = ?")->execute([$payId]);

                    addNotification(
                        $pdo,
                        'client',
                        $clientId,
                        'Upcoming Payment Reminder',
                        "Invoice {$row['invoice_no']} for '{$row['title']}' is due in 3 days on " . date('d M Y', $dueTime) . ".",
                        '/client-flow/client/payments.php',
                        'info',
                        'bi-calendar-event'
                    );

                    $stats['touch1_count']++;
                }

                // -------------------------------------------------------------
                // Touch 2: Due Date / 1 Day Past Due Settlement Alert
                // -------------------------------------------------------------
                elseif ($diffDays === -1 && $daysSinceLastSent >= 1) {
                    $pdo->prepare("UPDATE payments SET status = 'overdue', overdue_notified_at = COALESCE(overdue_notified_at, NOW()) WHERE id = ?")->execute([$payId]);
                    sendPaymentReminderEmail($clientData, $payData, $prjData, $defaultAdmin, 'due');

                    $pdo->prepare("
                        INSERT INTO reminder_logs (payment_id, client_id, type, sent_by, notes, sent_at)
                        VALUES (?, ?, 'email', ?, ?, NOW())
                    ")->execute([$payId, $clientId, $defaultAdmin['id'], '[Automated Touch 2] Due date settlement reminder dispatched (1 day past due)']);

                    $pdo->prepare("UPDATE payments SET last_reminder_sent_at = NOW() WHERE id = ?")->execute([$payId]);

                    addNotification(
                        $pdo,
                        'client',
                        $clientId,
                        'Payment Due Alert',
                        "Invoice {$row['invoice_no']} for '{$row['title']}' was due yesterday. Please settle payment to prevent project pause.",
                        '/client-flow/client/payments.php',
                        'warning',
                        'bi-exclamation-circle-fill'
                    );

                    $stats['touch2_count']++;
                }

                // -------------------------------------------------------------
                // Touch 3: 7+ Days Overdue -> Urgent Alert & Auto-Pause Project
                // -------------------------------------------------------------
                elseif ($diffDays <= -7 && $daysSinceLastSent >= 2.5) {
                    $pdo->prepare("UPDATE payments SET status = 'overdue', overdue_notified_at = COALESCE(overdue_notified_at, NOW()) WHERE id = ?")->execute([$payId]);

                    // Pause project if currently in_progress or advance_pending
                    if ($row['project_status'] !== 'on_hold') {
                        $pdo->prepare("UPDATE projects SET status = 'on_hold', updated_at = NOW() WHERE id = ?")->execute([$projectId]);
                        addProjectLog(
                            $pdo,
                            $projectId,
                            'status_change',
                            'Project Work Paused',
                            "Project placed on hold automatically because invoice {$row['invoice_no']} ({$row['title']}) is overdue by 7+ days.",
                            'system',
                            'System Automation'
                        );
                        $stats['projects_paused']++;
                    }

                    sendPaymentReminderEmail($clientData, $payData, $prjData, $defaultAdmin, 'urgent');

                    $pdo->prepare("
                        INSERT INTO reminder_logs (payment_id, client_id, type, sent_by, notes, sent_at)
                        VALUES (?, ?, 'email', ?, ?, NOW())
                    ")->execute([$payId, $clientId, $defaultAdmin['id'], '[Automated Touch 3] 7+ days overdue: Project placed on hold (on_hold)']);

                    $pdo->prepare("UPDATE payments SET last_reminder_sent_at = NOW() WHERE id = ?")->execute([$payId]);

                    addNotification(
                        $pdo,
                        'client',
                        $clientId,
                        'Project Paused: Overdue Milestone',
                        "Project '{$row['project_title']}' has been paused due to unpaid invoice {$row['invoice_no']} (overdue 7+ days). Work resumes immediately upon settlement.",
                        '/client-flow/client/payments.php',
                        'danger',
                        'bi-pause-circle-fill'
                    );

                    $stats['touch3_count']++;
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = $e->getMessage();
        }

        return $stats;
    }
}

if (!function_exists('autoRunRemindersDaily')) {
    function autoRunRemindersDaily(PDO $pdo): void
    {
        try {
            $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clientflow_reminders.lock';
            $now = time();

            // Run at most once every 6 hours (21600 seconds)
            if (file_exists($lockFile)) {
                $lastRun = (int) @file_get_contents($lockFile);
                if (($now - $lastRun) < 21600) {
                    return; // Cooldown active, skip
                }
            }

            @file_put_contents($lockFile, (string)$now);
            runAutomatedPaymentReminders($pdo);
        } catch (Throwable $e) {
            // Silently suppress background exceptions
        }
    }
}
