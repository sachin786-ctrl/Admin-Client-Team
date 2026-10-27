<?php
/**
 * Compatibility bootstrap — core helpers live in admin/includes/functions.php
 */
if (!defined('CF_ROOT')) {
    define('CF_ROOT', dirname(__DIR__));
}
require_once CF_ROOT . '/admin/includes/functions.php';
