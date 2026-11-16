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

// 3. Server Superglobal Sanitization & Fallbacks (Ensures zero undefined notices on local and server deployments)
if (empty($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
if (empty($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
if (empty($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] = 'DMS/Client';
}

// 3. Error Reporting & Logging
error_reporting(E_ALL);
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) 
    || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || (php_sapi_name() === 'cli');

if ($isLocalhost) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// Global Shutdown Handler for Fatal Errors
register_shutdown_function(function() use ($isLocalhost) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if ($isLocalhost) {
            echo "<div style='background:#fee2e2; border:2px solid #ef4444; color:#991b1b; padding:16px; margin:20px; font-family:system-ui, sans-serif; border-radius:8px; z-index:99999; position:relative;'>";
            echo "<h4 style='margin:0 0 8px 0;'><strong>DMS Application Error:</strong> " . htmlspecialchars($error['message']) . "</h4>";
            echo "<p style='margin:0; font-size:0.9rem;'><strong>File:</strong> " . htmlspecialchars($error['file']) . " (Line <strong>" . $error['line'] . "</strong>)</p>";
            echo "</div>";
        }
    }
});

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
