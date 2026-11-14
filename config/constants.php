<?php
/**
 * Application Constants
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

// Application Info
define('APP_NAME', 'Falcon DMS');
define('APP_VERSION', '2.5.0');
define('APP_TIMEZONE', 'Asia/Karachi');

// Currency & Local Formatting
define('DEFAULT_CURRENCY', 'PKR');
define('DEFAULT_CURRENCY_SYMBOL', 'Rs.');

// Document Statuses
define('STATUS_DRAFT', 'draft');
define('STATUS_SUBMITTED', 'submitted');
define('STATUS_PENDING', 'pending');
define('STATUS_APPROVED', 'approved');
define('STATUS_REJECTED', 'rejected');
define('STATUS_PROCESSING', 'processing');
define('STATUS_READY_FOR_DISPATCH', 'ready_for_dispatch');
define('STATUS_DISPATCHED', 'dispatched');
define('STATUS_PARTIALLY_DELIVERED', 'partially_delivered');
define('STATUS_DELIVERED', 'delivered');
define('STATUS_CANCELLED', 'cancelled');
define('STATUS_POSTED', 'posted');
define('STATUS_VOIDED', 'voided');

// System Roles
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN', 'admin');
define('ROLE_ORDER_BOOKER', 'order_booker');
define('ROLE_SALESMAN', 'salesman');
define('ROLE_DELIVERY_MAN', 'delivery_man');
define('ROLE_STOREKEEPER', 'storekeeper');
define('ROLE_ACCOUNTANT', 'accountant');

// Base Paths and URLs
define('BASE_PATH', dirname(__DIR__));
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

// Match from start up to /modules, /ajax, /config, /database, /tests, or /assets
if (preg_match('#^(.*?)/(modules|ajax|config|database|tests|assets)/#i', $scriptName, $matches)) {
    $baseDir = $matches[1];
} else {
    $baseDir = dirname($scriptName);
}
$baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
define('BASE_URL', $protocol . $host . $baseDir);
