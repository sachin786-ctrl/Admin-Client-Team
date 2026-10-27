<?php
require_once __DIR__ . '/includes/functions.php';
$admin = getAuthenticatedAdmin($pdo);

$notifId = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$action  = $_REQUEST['action'] ?? 'read';
$isAjax  = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

// Bulk Delete Support
if ($action === 'bulk_delete' || isset($_POST['bulk_delete'])) {
    $ids = (array)($_POST['notif_ids'] ?? $_POST['notification_ids'] ?? $_GET['notif_ids'] ?? []);
    $deleted = bulkDeleteNotifications($pdo, $ids, 'admin', $admin['id']);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
            'unread_count' => getUnreadNotificationsCount($pdo, 'admin', $admin['id'])
        ]);
        exit;
    }
    setFlash('success', "{$deleted} notification(s) deleted successfully.");
    header('Location: /client-flow/admin/notifications.php');
    exit;
}

if ($notifId > 0) {
    if ($action === 'delete') {
        deleteNotification($pdo, $notifId, 'admin', $admin['id']);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'unread_count' => getUnreadNotificationsCount($pdo, 'admin', $admin['id'])
            ]);
            exit;
        }
        setFlash('success', 'Notification deleted successfully.');
        header('Location: /client-flow/admin/notifications.php');
        exit;
    } else {
        // Mark single notification as read
        $notif = getNotificationById($pdo, $notifId, 'admin', $admin['id']);
        markNotificationAsRead($pdo, $notifId, 'admin', $admin['id']);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'unread_count' => getUnreadNotificationsCount($pdo, 'admin', $admin['id']),
                'notif' => $notif
            ]);
            exit;
        }

        // Direct browser click redirect
        if ($notif && !empty($notif['link']) && $notif['link'] !== '#') {
            header('Location: ' . $notif['link']);
            exit;
        }
        header('Location: /client-flow/admin/notifications.php?highlight=' . $notifId);
        exit;
    }
} else {
    // Mark ALL as read
    markNotificationsAsRead($pdo, 'admin', $admin['id']);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }

    $back = $_SERVER['HTTP_REFERER'] ?? '/client-flow/admin/dashboard.php';
    header('Location: ' . $back);
    exit;
}
