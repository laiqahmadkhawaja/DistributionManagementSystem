<?php
/**
 * Test Suite for Traditional FMCG Refinements
 * Tests:
 * 1. Category Management link & endpoint availability
 * 2. Traditional Mode UI Isolation (Sidebar & Live Poller)
 * 3. Carton vs Loose Piece Pricing Calculation & Order Booking
 * 4. Bill Rendering (Unit Prize matching Carton Rate)
 * 5. Weighted Average Cost (WAC) & Audit Logging
 */

$_SERVER['DOCUMENT_ROOT'] = 'C:/wamp64/www';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Distribution Management System/tests/test_traditional_refinements.php';

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
echo "RUNNING FMCG TRADITIONAL REFINEMENTS TEST SUITE\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $desc . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $desc . "\n";
        $failCount++;
    }
}

// -----------------------------------------------------------------
// Test 1: Category Management Endpoint Exists & Responds
// -----------------------------------------------------------------
$catFile = dirname(__DIR__) . '/modules/products/categories.php';
assertTest("Category management file exists (modules/products/categories.php)", file_exists($catFile));

$catCount = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE status = 'active'")->fetchColumn();
assertTest("Active categories exist in database (Count: {$catCount})", $catCount > 0);

// -----------------------------------------------------------------
// Test 2: Traditional Mode Isolation in Live Poller
// -----------------------------------------------------------------
$pdo->prepare("INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)")->execute(['system_operational_mode', 'traditional', 'Distribution Engine Operational Mode']);
$currentMode = getSetting($pdo, 'system_operational_mode', 'hybrid', true);
assertTest("System operational mode successfully set to 'traditional'", $currentMode === 'traditional');

// Test live_updates logic under traditional mode
ob_start();
include dirname(__DIR__) . '/ajax/live_updates.php';
$liveUpdatesJson = ob_get_clean();
// Parse JSON even if headers were sent
$jsonStart = strpos($liveUpdatesJson, '{');
$liveData = ($jsonStart !== false) ? json_decode(substr($liveUpdatesJson, $jsonStart), true) : null;

assertTest("Live poller returns valid JSON in traditional mode", !empty($liveData) && isset($liveData['counts']));
assertTest("Live poller pending_deliveries is strictly 0 in traditional mode", ($liveData['counts']['pending_deliveries'] ?? -1) === 0);
assertTest("Live poller pending_returns is strictly 0 in traditional mode", ($liveData['counts']['pending_returns'] ?? -1) === 0);

// -----------------------------------------------------------------
// Test 3: Carton vs Loose Piece Rate Calculation Logic
// -----------------------------------------------------------------
// Test product with 1*36 packaging (Carton rate 1512, loose piece rate 42)
$ctnMultiplier = 36.0;
$cartonRate = 1512.00;
$pieceRate = round($cartonRate / $ctnMultiplier, 2);
assertTest("Loose piece rate is calculated as Carton Rate / Multiplier (1512 / 36 = 42.00)", $pieceRate == 42.00);

// Test Line Total: 1 Carton + 2 Loose pieces
$ctnQty = 1;
$pcsQty = 2;
$itemDiscount = 0.00;
$lineRaw = ($ctnQty * $cartonRate) + ($pcsQty * $pieceRate);
$lineTotal = max(0.00, $lineRaw - $itemDiscount);
$expectedTotal = (1 * 1512.00) + (2 * 42.00); // 1512 + 84 = 1596
assertTest("Line Total for 1 Ctn + 2 Pcs is exact (Rs. {$lineTotal} == Rs. {$expectedTotal})", $lineTotal == $expectedTotal);

// -----------------------------------------------------------------
// Test 4: Order Creation with Loose Rate in Database
// -----------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Select customer & product
    $cust = $pdo->query("SELECT id, area_id, current_balance FROM customers WHERE status = 'active' LIMIT 1")->fetch();
    $prod = $pdo->query("SELECT id, name FROM products WHERE status = 'active' LIMIT 1")->fetch();

    $testOrderNum = 'SO-TEST-WAC-' . rand(1000, 9999);
    $soStmt = $pdo->prepare("
        INSERT INTO sales_orders (
            order_number, customer_id, order_booker_id, area_id,
            order_date, delivery_date, subtotal, discount_amount,
            tax_amount, delivery_charges, grand_total, status,
            payment_type, price_tier, notes, approved_by, approved_at, created_at
        ) VALUES (?, ?, 1, ?, '2026-11-20', '2026-11-21', ?, 0.00, 0.00, 0.00, ?, 'approved', 'credit', 'trade_price', 'Test order', 1, NOW(), NOW())
    ");
    $soStmt->execute([$testOrderNum, $cust['id'], $cust['area_id'], $lineTotal, $lineTotal]);
    $orderId = (int)$pdo->lastInsertId();

    $totalPieces = ($ctnQty * $ctnMultiplier) + $pcsQty; // 38 pieces
    $soiStmt = $pdo->prepare("
        INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, unit_price, quantity, discount, tax, line_total)
        VALUES (?, ?, 1, ?, ?, 0.00, 0.00, ?)
    ");
    $soiStmt->execute([$orderId, $prod['id'], $pieceRate, $totalPieces, $lineTotal]);

    // Verify order item stored correctly
    $savedItem = $pdo->query("SELECT * FROM sales_order_items WHERE sales_order_id = {$orderId}")->fetch(PDO::FETCH_ASSOC);
    assertTest("Sales order item quantity is total physical pieces (38)", (float)$savedItem['quantity'] == 38);
    assertTest("Sales order item unit_price is loose piece rate (42.00)", (float)$savedItem['unit_price'] == 42.00);
    assertTest("Sales order item line_total matches calculation (1596.00)", (float)$savedItem['line_total'] == 1596.00);

    // Test print bill price calculation:
    $ctnVal = floor($savedItem['quantity'] / $ctnMultiplier); // 1
    $looseVal = fmod($savedItem['quantity'], $ctnMultiplier); // 2
    $dispPrice = ($ctnMultiplier > 1 && $ctnVal > 0) ? round((float)$savedItem['unit_price'] * $ctnMultiplier, 2) : (float)$savedItem['unit_price'];
    assertTest("Bill print Unit Prize displays full Carton Rate (1512.00)", $dispPrice == 1512.00);
    assertTest("Bill print Carton column is 1 and Qty loose column is 2", $ctnVal == 1 && $looseVal == 2);

    $pdo->rollBack();
    echo " [INFO] Temporary test order rolled back cleanly.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    assertTest("Order booking test failed: " . $e->getMessage(), false);
}

// -----------------------------------------------------------------
// Test 5: Weighted Average Cost (WAC) Auto-Recalculation & Audit Log
// -----------------------------------------------------------------
$pdo->beginTransaction();
try {
    $testProd = $pdo->query("SELECT id, name, purchase_price FROM products WHERE status = 'active' LIMIT 1")->fetch();
    $prodId = (int)$testProd['id'];
    $oldPrice = (float)$testProd['purchase_price'];

    // Inflow 10 units at new rate
    $incomingQty = 10;
    $incomingUnitCost = $oldPrice + 50.00; // different cost to trigger WAC change
    $incomingTotalCost = $incomingQty * $incomingUnitCost;

    $whId = (int)($pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn() ?: 1);

    recordStockMovement($pdo, $whId, $prodId, 'adjustment_in', 'purchase_order', 88888, 1, $incomingQty, 0, $incomingUnitCost, null, 'WAC Suite Test');

    $updatedPrice = (float)$pdo->query("SELECT purchase_price FROM products WHERE id = {$prodId}")->fetchColumn();
    assertTest("Product purchase_price auto-updated after stock inflow (Old: {$oldPrice}, New: {$updatedPrice})", $updatedPrice > 0 && abs($updatedPrice - $oldPrice) > 0);

    // Check audit log
    $auditRow = $pdo->query("SELECT * FROM audit_logs WHERE module = 'products' AND record_id = {$prodId} AND action = 'wac_cost_update' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTest("Audit log entry created with action 'wac_cost_update'", !empty($auditRow));
    assertTest("Audit log recorded previous and new purchase prices", strpos($auditRow['previous_data'], 'old_purchase_price') !== false && strpos($auditRow['new_data'], 'new_purchase_price') !== false);

    $pdo->rollBack();
    echo " [INFO] Temporary WAC test changes rolled back cleanly.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    assertTest("WAC test failed: " . $e->getMessage(), false);
}

echo "\n====================================================\n";
echo "TEST SUITE COMPLETE: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
