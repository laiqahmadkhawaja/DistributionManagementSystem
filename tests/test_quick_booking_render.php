<?php
$_SERVER['DOCUMENT_ROOT'] = 'C:/wamp64/www';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/Distribution%20Management%20System/modules/orders/quick_booking.php?customer_id=1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Distribution Management System/modules/orders/quick_booking.php';

require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;
$_SESSION['role_slug'] = 'super_admin';
$_SESSION['logged_in'] = true;

ob_start();
include __DIR__ . '/../modules/orders/quick_booking.php';
$output = ob_get_clean();

echo "Rendered quick_booking.php successfully.\n";
echo "Output length: " . strlen($output) . " bytes\n";

if (strpos($output, 'Fatal error') !== false || strpos($output, 'DMS Application Error') !== false) {
    echo "FAILED: Error detected in rendered output!\n";
    exit(1);
} else {
    echo "PASSED: Zero PHP errors or fatal exceptions in rendered output.\n";
}
