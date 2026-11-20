<?php
/**
 * Test Suite: Fast Traditional FMCG Simplification Verification
 */

require_once __DIR__ . '/../config/app.php';

$pdo = getDatabaseConnection();

echo "========================================================\n";
echo "FAST TRADITIONAL FMCG SIMPLIFICATION TEST SUITE\n";
echo "========================================================\n\n";

$pdo->beginTransaction();

try {
    // Test 1: Product auto-detection of (1*36) pack size
    $pName = 'Nimko Special Dal Moth (1*36)';
    $pcsPerCarton = 0;
    if (preg_match('/(?:1\s*[*xX]\s*(\d+))/', $pName, $matches)) {
        $pcsPerCarton = (float)$matches[1];
    }
    assert($pcsPerCarton === 36.0, "Failed to parse 1*36 from product name");
    echo "1. Pack Size Auto-Detection:\n";
    echo "  [PASS] Successfully parsed '1*36' as {$pcsPerCarton} pieces per carton.\n\n";

    // Test 2: Customer Opening Balance Saving
    $shopName = 'Test Super Store ' . rand(100, 999);
    $phone = '0300' . rand(1000000, 9999999);
    $code = 'CUST-TST-' . rand(100, 999);
    $openingBal = 7850.50;

    $insCust = $pdo->prepare("
        INSERT INTO customers (
            `area_id`, `shop_name`, `owner_name`, `code`, `phone`, `whatsapp`,
            `address`, `credit_limit`, `opening_balance`, `current_balance`, `status`, `created_at`
        ) VALUES (1, ?, 'Owner Test', ?, ?, ?, 'Market Bazar', 50000, ?, ?, 'active', NOW())
    ");
    $insCust->execute([$shopName, $code, $phone, $phone, $openingBal, $openingBal]);
    $custId = (int)$pdo->lastInsertId();

    $chkCust = $pdo->query("SELECT opening_balance, current_balance FROM customers WHERE id = {$custId}")->fetch(PDO::FETCH_ASSOC);
    assert((float)$chkCust['opening_balance'] === 7850.50, "Opening balance mismatch");
    assert((float)$chkCust['current_balance'] === 7850.50, "Current balance mismatch");
    echo "2. Customer Excel Opening Balance:\n";
    echo "  [PASS] Customer ID {$custId} correctly saved with opening balance Rs. 7,850.50 and current balance Rs. 7,850.50.\n\n";

    // Test 3: Consolidated Load Form (2 different bookers ordering the same item)
    // Create product with valid category_id = 1
    $pdo->query("INSERT INTO products (code, name, category_id, base_unit_id, purchase_price, trade_price, retail_price, status) VALUES ('PRD-TST-MERGE', 'Nimko Mix (1*24)', 1, 1, 100, 120, 150, 'active')");
    $mergeProdId = (int)$pdo->lastInsertId();
    $pdo->query("INSERT INTO unit_conversions (product_id, from_unit_id, to_unit_id, multiplier) VALUES ({$mergeProdId}, 2, 1, 24)");

    // Booker 1 order: 10 cartons (= 240 pcs)
    $today = date('Y-m-d');
    $pdo->query("INSERT INTO sales_orders (order_number, customer_id, order_booker_id, area_id, order_date, status, subtotal, grand_total) VALUES ('SO-B1', {$custId}, 3, 1, '{$today}', 'approved', 28800, 28800)");
    $so1 = (int)$pdo->lastInsertId();
    $pdo->query("INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, quantity, unit_price, line_total) VALUES ({$so1}, {$mergeProdId}, 1, 240, 120, 28800)");

    // Booker 2 order: 5 cartons (= 120 pcs)
    $pdo->query("INSERT INTO sales_orders (order_number, customer_id, order_booker_id, area_id, order_date, status, subtotal, grand_total) VALUES ('SO-B2', {$custId}, 4, 2, '{$today}', 'approved', 14400, 14400)");
    $so2 = (int)$pdo->lastInsertId();
    $pdo->query("INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, quantity, unit_price, line_total) VALUES ({$so2}, {$mergeProdId}, 1, 120, 120, 14400)");

    // Run consolidated query (area_id = 0)
    $stmt = $pdo->prepare("
        SELECT 
            p.id as product_id,
            p.name as product_name,
            COALESCE(uc.multiplier, 1) as carton_multiplier,
            SUM(soi.quantity) as total_qty,
            SUM(soi.line_total) as total_amount
        FROM sales_order_items soi
        JOIN sales_orders so ON soi.sales_order_id = so.id
        JOIN products p ON soi.product_id = p.id
        LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
        WHERE so.order_date = ? AND so.status NOT IN ('draft', 'rejected', 'cancelled') AND p.id = ?
        GROUP BY p.id, p.name, uc.multiplier
    ");
    $stmt->execute([$today, $mergeProdId]);
    $mergedItem = $stmt->fetch(PDO::FETCH_ASSOC);

    assert($mergedItem !== false, "Consolidated item not found");
    assert((float)$mergedItem['total_qty'] === 360.0, "Merged quantity expected 360, got " . $mergedItem['total_qty']);
    assert((float)$mergedItem['total_amount'] === 43200.0, "Merged amount expected 43200, got " . $mergedItem['total_amount']);

    $ctn = floor($mergedItem['total_qty'] / $mergedItem['carton_multiplier']);
    $pcs = (int)fmod($mergedItem['total_qty'], $mergedItem['carton_multiplier']);
    assert($ctn == 15, "Expected 15 cartons, got {$ctn}");
    assert($pcs == 0, "Expected 0 loose pieces, got {$pcs}");

    echo "3. Master Load Form Cross-Booker Merging:\n";
    echo "  [PASS] Order 1 (10 ctns) + Order 2 (5 ctns) successfully merged into 1 consolidated row:\n";
    echo "         Item: {$mergedItem['product_name']} -> Total: {$ctn} Cartons, {$pcs} Loose Pcs, Value: Rs. " . number_format($mergedItem['total_amount'], 2) . "\n\n";

    echo "========================================================\n";
    echo "ALL TESTS PASSED WITH 100% SUCCESS!\n";
    echo "========================================================\n";

} finally {
    $pdo->rollBack();
}
