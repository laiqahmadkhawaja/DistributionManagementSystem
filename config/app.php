<?php
/**
 * Master Centralized Application Bootstrap
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

// 1. Session Configuration & Hardening (MUST execute before any headers or output)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    // Unique session name to prevent collisions with other local WAMP applications
    session_name('FALCON_DMS_SESSID');
    
    // Set cookie path
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 2. Timezone & Locale
date_default_timezone_set('Asia/Karachi');

// 3. Error Reporting & Logging (Errors logged securely, never raw traces in browser)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 4. Include Core System Components (Order of dependency)
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/audit.php';
require_once BASE_PATH . '/includes/csrf.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/accounting.php';
require_once BASE_PATH . '/includes/inventory.php';

// 5. CSRF Check for POST requests (except designated API / offline sync endpoints)
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isExempt = str_contains($script, 'ajax/sync.php') || str_contains($script, 'login.php');
    if (!$isExempt) {
        requireCsrfToken();
    }
}
