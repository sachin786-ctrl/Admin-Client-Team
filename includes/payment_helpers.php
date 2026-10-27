<?php
/**
 * Compatibility bootstrap — payment helpers live in admin/includes/payment_helpers.php
 */
if (!defined('CF_ROOT')) {
    define('CF_ROOT', dirname(__DIR__));
}
require_once CF_ROOT . '/admin/includes/payment_helpers.php';
