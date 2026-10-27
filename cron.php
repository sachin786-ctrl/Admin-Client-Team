<?php
/**
 * ClientFlow - Automated Payment Reminders & Overdue Project Suspension Cron
 * 
 * Can be executed via:
 * 1. CLI / Windows Task Scheduler:
 *    php c:\xampp\htdocs\client-flow\cron.php
 * 
 * 2. Webhook / URL:
 *    http://localhost/client-flow/cron.php?key=clientflow_cron_secret
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin/includes/functions.php';

// Security: Check token if called via HTTP (allows local development or CLI directly)
$isCli = (php_sapi_name() === 'cli');
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$cronKey = $_GET['key'] ?? '';

if (!$isCli && !$isLocalhost && $cronKey !== 'clientflow_cron_secret') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Unauthorized access. Provide valid key.'
    ]);
    exit;
}

$startTime = microtime(true);

// Execute the 3-Touch Reminders and Overdue Checks
$results = runAutomatedPaymentReminders($pdo);

$executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

echo json_encode([
    'status'              => 'success',
    'timestamp'           => date('Y-m-d H:i:s'),
    'execution_time_ms'   => $executionTimeMs,
    'touch1_upcoming_3d'  => $results['touch1_count'],
    'touch2_due_1d'       => $results['touch2_count'],
    'touch3_urgent_pause' => $results['touch3_count'],
    'projects_paused'     => $results['projects_paused'],
    'errors'              => $results['errors'],
    'message'             => 'Automated 3-touch payment reminders and overdue sync executed successfully.'
], JSON_PRETTY_PRINT);
