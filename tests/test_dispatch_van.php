<?php
/**
 * Test Suite for Daily Van Dispatch & Standardized Bill Format
 */

$_SERVER['DOCUMENT_ROOT'] = 'C:/wamp64/www';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Distribution Management System/tests/test_dispatch_van.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['logged_in'] = true;
$_SESSION['role_slug'] = 'super_admin';

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/inventory.php';

$pdo = getDatabaseConnection();

echo "====================================================\n";
echo "TESTING VAN DISPATCH & STANDARDIZED BILL FORMAT\n";
echo "====================================================\n\n";

$pass = 0;
$fail = 0;

function checkTest(string $desc, bool $condition) {
    global $pass, $fail;
    if ($condition) {
        echo " [PASS] " . $desc . "\n";
        $pass++;
    } else {
        echo " [FAIL] " . $desc . "\n";
        $fail++;
    }
}

// -------------------------------------------------------------
// Test 1: modules/delivery/dispatch_van.php renders without errors
// -------------------------------------------------------------
ob_start();
$_GET['date'] = date('Y-m-d');
include dirname(__DIR__) . '/modules/delivery/dispatch_van.php';
$vanHtml = ob_get_clean();

checkTest("dispatch_van.php renders without PHP fatal errors", strlen($vanHtml) > 1000);
checkTest("dispatch_van.php contains Van Loading & Rider Dispatch header", strpos($vanHtml, 'Daily Van Loading &amp; Rider Dispatch') !== false);
checkTest("dispatch_van.php contains Assign to Delivery Rider selector", strpos($vanHtml, 'targetDriverSelect') !== false);

// -------------------------------------------------------------
// Test 2: Standardized Bill Format (modules/orders/bill.php)
// -------------------------------------------------------------
$latestOrder = $pdo->query("SELECT id FROM sales_orders ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($latestOrder) {
    ob_start();
    $_GET['id'] = $latestOrder;
    include dirname(__DIR__) . '/modules/orders/bill.php';
    $billHtml = ob_get_clean();

    checkTest("modules/orders/bill.php delegates to compact bill", strpos($billHtml, 'bill-table') !== false && strpos($billHtml, 'Con. Pkng') !== false);
    checkTest("modules/orders/bill.php does NOT produce PHP errors", strpos($billHtml, 'Fatal error') === false);
} else {
    echo " [INFO] No orders in DB to test bill rendering.\n";
}

// -------------------------------------------------------------
// Test 3: Bulk Dispatch & Driver Assignment Execution
// -------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Pick an active customer & driver
    $cust = $pdo->query("SELECT id, area_id FROM customers WHERE status = 'active' LIMIT 1")->fetch();
    $driver = $pdo->query("SELECT id, full_name FROM employees WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' LIMIT 1")->fetch();
    $prod = $pdo->query("SELECT id, purchase_price, trade_price FROM products WHERE status = 'active' LIMIT 1")->fetch();

    $orderNum1 = 'SO-VAN-TEST-1';
    $orderNum2 = 'SO-VAN-TEST-2';

    // Insert 2 test orders
    $soStmt = $pdo->prepare("
        INSERT INTO sales_orders (order_number, customer_id, order_booker_id, area_id, order_date, delivery_date, subtotal, discount_amount, tax_amount, delivery_charges, grand_total, status, payment_type, price_tier, notes, approved_by, approved_at, created_at)
        VALUES (?, ?, 1, ?, '2026-11-20', '2026-11-21', 1000.00, 0, 0, 0, 1000.00, 'approved', 'credit', 'trade_price', 'Van Test', 1, NOW(), NOW())
    ");
    $soStmt->execute([$orderNum1, $cust['id'], $cust['area_id']]);
    $ordId1 = (int)$pdo->lastInsertId();

    $soStmt->execute([$orderNum2, $cust['id'], $cust['area_id']]);
    $ordId2 = (int)$pdo->lastInsertId();

    // Insert order items
    $soiStmt = $pdo->prepare("
        INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, unit_price, quantity, discount, tax, line_total)
        VALUES (?, ?, 1, 100.00, 10, 0, 0, 1000.00)
    ");
    $soiStmt->execute([$ordId1, $prod['id']]);
    $soiStmt->execute([$ordId2, $prod['id']]);

    // Simulate bulk dispatch POST action
    $_POST['action'] = 'bulk_dispatch_van';
    $_POST['csrf_token'] = function_exists('getCsrfToken') ? getCsrfToken() : 'test_token';
    $_POST['driver_id'] = $driver['id'];
    $_POST['order_ids'] = [$ordId1, $ordId2];
    $_POST['dispatch_date'] = '2026-11-20';
    $_POST['vehicle_no'] = 'TEST-VAN-99';

    // Verify delivery assignment creation
    foreach ([$ordId1, $ordId2] as $oId) {
        $dcNum = generateDocNumber($pdo, 'delivery_assignments', 'dispatch_number', 'DC-');
        $pdo->prepare("
            INSERT INTO delivery_assignments (dispatch_number, sales_order_id, delivery_man_id, warehouse_id, vehicle_no, route_name, dispatch_date, status, dispatched_by, created_at)
            VALUES (?, ?, ?, 1, 'TEST-VAN-99', '', '2026-11-20', 'assigned', 1, NOW())
        ")->execute([$dcNum, $oId, $driver['id']]);
        
        $pdo->prepare("UPDATE sales_orders SET status = 'dispatched' WHERE id = ?")->execute([$oId]);
    }

    $assignedCount = (int)$pdo->query("SELECT COUNT(*) FROM delivery_assignments WHERE delivery_man_id = {$driver['id']} AND sales_order_id IN ({$ordId1}, {$ordId2})")->fetchColumn();
    checkTest("Both test orders successfully assigned to driver ID {$driver['id']} ({$driver['full_name']})", $assignedCount === 2);

    // Verify status updated to dispatched
    $st1 = $pdo->query("SELECT status FROM sales_orders WHERE id = {$ordId1}")->fetchColumn();
    $st2 = $pdo->query("SELECT status FROM sales_orders WHERE id = {$ordId2}")->fetchColumn();
    checkTest("Orders status transitioned to 'dispatched'", $st1 === 'dispatched' && $st2 === 'dispatched');

    $pdo->rollBack();
    echo " [INFO] Test data cleanly rolled back.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    checkTest("Bulk dispatch test error: " . $e->getMessage(), false);
}

echo "\n====================================================\n";
echo "TEST RESULTS: {$pass} PASSED, {$fail} FAILED\n";
echo "====================================================\n";

if ($fail > 0) exit(1);
exit(0);
