<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['client_id'])) {
    header('Location: /client-flow/client/auth/logout.php');
    exit;
}

header('Location: /client-flow/admin/logout.php');
exit;
