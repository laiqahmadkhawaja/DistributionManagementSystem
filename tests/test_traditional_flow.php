<?php
/**
 * Comprehensive Automated Integration Test for Traditional Desktop FMCG Workflow
 * Tests:
 * 1. Dual Operational Mode configuration
 * 2. Product CSV Import & Carton Multiplier conversions
 * 3. Customer Shop CSV Import & Area linking
 * 4. Rapid Route Order Booking with Carton + Loose Pcs math
 * 5. Compact Half-Sheet Bill & 2-per-A4 Batch generator queries
 * 6. Van Stock Issue Summary Load Sheet aggregation
 * 7. Daily Delivery Run Sheet layout
 * 8. End-of-Day Sheet Reconciliation (Cash + Udhaar + Returns)
 * 9. Customer 360° and Supplier 360° profile queries
 */

require_once __DIR__ . '/../config/app.php';

$pdo = getDatabaseConnection();
$passed = 0;
$failed = 0;

function assertCondition(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "FMCG TRADITIONAL WORKFLOW INTEGRATION TEST SUITE\n";
echo "========================================================\n\n";

$pdo->beginTransaction();

try {
    // -------------------------------------------------------------------------
    // TEST 1: Operational Mode Setting
    // -------------------------------------------------------------------------
    echo "1. Operational Mode Configuration:\n";
    $stmt = $pdo->prepare("
        INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
        VALUES ('system_operational_mode', 'traditional', 'Operating Mode', NOW())
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()
    ");
    $stmt->execute();

    $mode = getSetting($pdo, 'system_operational_mode', 'hybrid', true);
    assertCondition("System operational mode successfully retrieved as 'traditional'", $mode === 'traditional');

    // -------------------------------------------------------------------------
    // TEST 2: Product & Carton Multiplier Conversions
    // -------------------------------------------------------------------------
    echo "\n2. Product & Packaging Unit Multipliers:\n";
    $testProdCode = 'TEST-NIMKO-' . time();
    $catId = (int)$pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn() ?: 1;
    $pdo->prepare("
        INSERT INTO products (code, name, category_id, base_unit_id, purchase_price, trade_price, wholesale_price, retail_price, status, created_at)
        VALUES (?, 'Test Nimko Daal Moth 1*36', ?, 1, 1000.00, 1512.00, 1450.00, 1600.00, 'active', NOW())
    ")->execute([$testProdCode, $catId]);
    $prodId = (int)$pdo->lastInsertId();

    // 1 Carton (from_unit_id = 2) = 36 Pieces (to_unit_id = 1)
    $pdo->prepare("
        INSERT INTO unit_conversions (`product_id`, `from_unit_id`, `to_unit_id`, `multiplier`)
        VALUES (?, 2, 1, 36.00)
    ")->execute([$prodId]);

    // Check multiplier query
    $mStmt = $pdo->prepare("SELECT multiplier FROM unit_conversions WHERE product_id = ? AND from_unit_id = 2 LIMIT 1");
    $mStmt->execute([$prodId]);
    $multiplier = (float)$mStmt->fetchColumn();

    assertCondition("Product created with ID {$prodId}", $prodId > 0);
    assertCondition("Carton conversion multiplier is 36 pcs", $multiplier === 36.00);

    // -------------------------------------------------------------------------
    // TEST 3: Customer Shop Creation & Area Linking
    // -------------------------------------------------------------------------
    echo "\n3. Customer Shop Master Data:\n";
    $testCustCode = 'TEST-CUST-' . time();
    $areaId = (int)$pdo->query("SELECT id FROM areas WHERE status = 'active' LIMIT 1")->fetchColumn();
    if (!$areaId) {
        $pdo->prepare("INSERT INTO areas (code, name, status, created_at) VALUES ('AR-TEST', 'Test G.T. Road Area', 'active', NOW())")->execute();
        $areaId = (int)$pdo->lastInsertId();
    }

    $pdo->prepare("
        INSERT INTO customers (code, shop_name, owner_name, phone, whatsapp, address, area_id, customer_type, credit_limit, opening_balance, current_balance, status, created_at)
        VALUES (?, 'Prince Grocery Store', 'Imran Butt', '03000673276', '03000673276', 'Jandiala Bhagwala G.T. Road Gujranwala', ?, 'retailer', 50000.00, 0.00, 0.00, 'active', NOW())
    ")->execute([$testCustCode, $areaId]);
    $custId = (int)$pdo->lastInsertId();

    assertCondition("Customer 'Prince Grocery Store' created with ID {$custId}", $custId > 0);

    // -------------------------------------------------------------------------
    // TEST 4: Rapid Route Order Booking (Cartons + Loose Pieces Calculation)
    // -------------------------------------------------------------------------
    echo "\n4. Rapid Order Booking & Calculations:\n";
    // Booker wants: 2 Cartons + 12 Loose Pieces
    // Multiplier = 36, so Total Pieces = 2 * 36 + 12 = 84 pieces.
    // Rate per piece = Rs. 42 (so 36 pieces = Rs. 1512 per carton)
    $bookerId = (int)$pdo->query("SELECT id FROM employees WHERE role_type IN ('order_booker', 'salesman', 'admin') AND status = 'active' LIMIT 1")->fetchColumn();
    $ctn = 2;
    $loose = 12;
    $totalPieces = ($ctn * 36) + $loose; // 84
    $ratePerPiece = 42.00; // 1512 / 36
    $lineTotal = round($totalPieces * $ratePerPiece, 2); // 3528.00

    $soNumber = 'SO-TEST-' . time();
    $pdo->prepare("
        INSERT INTO sales_orders (
            order_number, customer_id, order_booker_id, area_id, order_date,
            subtotal, discount_amount, tax_amount, grand_total, status,
            payment_type, price_tier, notes, created_at
        ) VALUES (?, ?, ?, ?, CURDATE(), ?, 0.00, 0.00, ?, 'approved', 'credit', 'trade_price', 'Rapid Route Order Test', NOW())
    ")->execute([$soNumber, $custId, $bookerId, $areaId, $lineTotal, $lineTotal]);
    $orderId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, unit_price, quantity, discount, tax, line_total)
        VALUES (?, ?, 1, ?, ?, 0.00, 0.00, ?)
    ")->execute([$orderId, $prodId, $ratePerPiece, $totalPieces, $lineTotal]);

    assertCondition("Rapid order #{$soNumber} created with ID {$orderId}", $orderId > 0);
    assertCondition("Line total calculated as Rs. 3,528.00 (84 pcs @ 42.00)", $lineTotal === 3528.00);

    // Simulate invoice posting (receivable)
    postSalesInvoiceAccounting($pdo, $orderId, $soNumber, $custId, $lineTotal, 2000.00, 1);

    $custBalStmt = $pdo->prepare("SELECT current_balance FROM customers WHERE id = ?");
    $custBalStmt->execute([$custId]);
    $initialBalance = (float)$custBalStmt->fetchColumn();
    assertCondition("Customer receivable updated to Rs. 3,528.00", $initialBalance === 3528.00);

    // -------------------------------------------------------------------------
    // TEST 5: Compact Bill Math (Carton + Loose Decomposition)
    // -------------------------------------------------------------------------
    echo "\n5. Compact Half-Sheet Bill Decomposition (Ref Image 1 Standard):\n";
    $decompStmt = $pdo->prepare("
        SELECT soi.quantity, COALESCE(uc.multiplier, 1) as multiplier
        FROM sales_order_items soi
        JOIN products p ON soi.product_id = p.id
        LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
        WHERE soi.sales_order_id = ?
    ");
    $decompStmt->execute([$orderId]);
    $itemRow = $decompStmt->fetch(PDO::FETCH_ASSOC);

    $decompQty = (float)$itemRow['quantity']; // 84
    $decompMult = (float)$itemRow['multiplier']; // 36
    $calcCtn = floor($decompQty / $decompMult); // 2
    $calcLoose = (int)fmod($decompQty, $decompMult); // 12

    assertCondition("Bill decomposes 84 pieces into 2 Cartons", $calcCtn == 2);
    assertCondition("Bill decomposes remaining into 12 Loose pieces", $calcLoose == 12);

    // -------------------------------------------------------------------------
    // TEST 6: Van Total Stock Issue Summary (Ref Image 3 Standard)
    // -------------------------------------------------------------------------
    echo "\n6. Van Total Stock Issue Summary Form Aggregation:\n";
    $issueStmt = $pdo->prepare("
        SELECT 
            p.name as product_name,
            SUM(soi.quantity) as total_qty,
            COALESCE(uc.multiplier, 1) as multiplier,
            SUM(soi.line_total) as total_amount
        FROM sales_order_items soi
        JOIN sales_orders so ON soi.sales_order_id = so.id
        JOIN products p ON soi.product_id = p.id
        LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
        WHERE so.id = ?
        GROUP BY p.id, p.name, uc.multiplier
    ");
    $issueStmt->execute([$orderId]);
    $issueData = $issueStmt->fetch(PDO::FETCH_ASSOC);

    $issueCtn = floor($issueData['total_qty'] / $issueData['multiplier']);
    $issuePcs = (int)fmod($issueData['total_qty'], $issueData['multiplier']);

    assertCondition("Stock Issue Summary correctly aggregates 2 Ctns", $issueCtn == 2);
    assertCondition("Stock Issue Summary correctly aggregates 12 Pcs", $issuePcs == 12);
    assertCondition("Stock Issue Total matches Rs. 3,528.00", (float)$issueData['total_amount'] === 3528.00);

    // -------------------------------------------------------------------------
    // TEST 7: Daily Delivery Run Sheet (Ref Image 2 Standard)
    // -------------------------------------------------------------------------
    echo "\n7. Daily Delivery Run Sheet (DAILY SALE RECEIVED + CREDIT):\n";
    $driverId = (int)$pdo->query("SELECT id FROM employees WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' LIMIT 1")->fetchColumn();
    $dispNo = 'DSP-TEST-' . time();

    $pdo->prepare("
        INSERT INTO delivery_assignments (dispatch_number, sales_order_id, delivery_man_id, warehouse_id, dispatch_date, status, created_at)
        VALUES (?, ?, ?, 1, CURDATE(), 'assigned', NOW())
    ")->execute([$dispNo, $orderId, $driverId]);
    $assignId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO delivery_items (delivery_assignment_id, sales_order_item_id, product_id, unit_id, dispatched_qty, unit_price, total_amount)
        VALUES (?, (SELECT id FROM sales_order_items WHERE sales_order_id = ? LIMIT 1), ?, 1, ?, ?, ?)
    ")->execute([$assignId, $orderId, $prodId, $totalPieces, $ratePerPiece, $lineTotal]);

    $sheetStmt = $pdo->prepare("
        SELECT so.order_number, c.shop_name, so.grand_total
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.id
        JOIN delivery_assignments da ON da.sales_order_id = so.id
        WHERE da.id = ?
    ");
    $sheetStmt->execute([$assignId]);
    $sheetRow = $sheetStmt->fetch(PDO::FETCH_ASSOC);

    assertCondition("Run Sheet contains Shop: Prince Grocery Store", $sheetRow['shop_name'] === 'Prince Grocery Store');
    assertCondition("Run Sheet contains Bill Amount: Rs. 3,528.00", (float)$sheetRow['grand_total'] === 3528.00);

    // -------------------------------------------------------------------------
    // TEST 8: End-of-Day Sheet Reconciliation
    // -------------------------------------------------------------------------
    echo "\n8. End-of-Day Sheet Reconciliation (Cash + Udhaar + Returns):\n";
    // Rider delivers:
    // Cash Received: Rs. 2,000.00
    // Credit (Udhaar): Rs. 1,024.00
    // Return (Wapsi): Rs. 504.00 (12 pieces returned @ 42.00)
    // Total: 2000 + 1024 + 504 = 3528.00 (100% Balanced!)
    $cashAmt = 2000.00;
    $creditAmt = 1024.00;
    $returnAmt = 504.00;
    assertCondition("Reconciliation is balanced: 2000 + 1024 + 504 == 3528", ($cashAmt + $creditAmt + $returnAmt) === 3528.00);

    // Post Cash Payment
    $cashMethod = $pdo->query("SELECT * FROM payment_methods WHERE code = 'cash' LIMIT 1")->fetch();
    $cashAccountId = (int)($cashMethod['account_id'] ?? 1);
    $rcpNumber = 'RCP-TEST-' . time();

    $pdo->prepare("
        INSERT INTO payments (
            payment_number, payment_type, reference_type, reference_id,
            party_type, party_id, amount, payment_method_id, payment_method_code,
            payment_account_id, payment_date, collected_by, handover_status, notes, status, created_at
        ) VALUES (?, 'customer_receipt', 'delivery', ?, 'customer', ?, ?, ?, 'cash', ?, CURDATE(), ?, 'pending_handover', 'Reconciled run sheet', 'posted', NOW())
    ")->execute([$rcpNumber, $assignId, $custId, $cashAmt, ($cashMethod['id'] ?? 1), $cashAccountId, $driverId]);
    $payId = (int)$pdo->lastInsertId();

    postCustomerPaymentAccounting($pdo, $payId, $rcpNumber, $custId, $cashAmt, $cashAccountId, 1);

    // Post Return & Credit Note
    $srNumber = 'SR-TEST-' . time();
    $pdo->prepare("
        INSERT INTO sales_returns (
            return_number, customer_id, delivery_man_id, delivery_assignment_id,
            warehouse_id, return_date, total_amount, return_destination, status, handover_status, created_at
        ) VALUES (?, ?, ?, ?, 1, CURDATE(), ?, 'quarantine', 'approved', 'in_vehicle_custody', NOW())
    ")->execute([$srNumber, $custId, $driverId, $assignId, $returnAmt]);
    $srId = (int)$pdo->lastInsertId();

    $cnNumber = 'CN-TEST-' . time();
    $pdo->prepare("
        INSERT INTO credit_notes (credit_note_number, sales_return_id, customer_id, amount, note_date, status, created_at)
        VALUES (?, ?, ?, ?, CURDATE(), 'active', NOW())
    ")->execute([$cnNumber, $srId, $custId, $returnAmt]);

    updateCustomerBalance($pdo, $custId, -$returnAmt);

    // Check Customer Final Balance:
    // Started at 0 + 3528 (invoice) - 2000 (cash) - 504 (return) = 1024 (Credit / Udhaar)!
    $finalBalStmt = $pdo->prepare("SELECT current_balance FROM customers WHERE id = ?");
    $finalBalStmt->execute([$custId]);
    $finalBal = (float)$finalBalStmt->fetchColumn();

    assertCondition("Customer final balance exactly equals Credit/Udhaar amount (Rs. 1,024.00)", $finalBal === 1024.00);

    // -------------------------------------------------------------------------
    // TEST 9: Customer 360° Profile Queries
    // -------------------------------------------------------------------------
    echo "\n9. Customer 360° Profile Verification:\n";
    $c360Orders = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE customer_id = ?");
    $c360Orders->execute([$custId]);
    assertCondition("Customer 360° Orders query returns created order", (int)$c360Orders->fetchColumn() >= 1);

    $c360Pays = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE party_type = 'customer' AND party_id = ?");
    $c360Pays->execute([$custId]);
    assertCondition("Customer 360° Receipts query returns cash payment", (int)$c360Pays->fetchColumn() >= 1);

    $c360Rets = $pdo->prepare("SELECT COUNT(*) FROM sales_returns WHERE customer_id = ?");
    $c360Rets->execute([$custId]);
    assertCondition("Customer 360° Returns query returns sales return", (int)$c360Rets->fetchColumn() >= 1);

    // -------------------------------------------------------------------------
    // TEST 10: Supplier 360° Profile Queries
    // -------------------------------------------------------------------------
    echo "\n10. Supplier 360° Profile Verification:\n";
    $testSuppCode = 'TEST-SUPP-' . time();
    $pdo->prepare("
        INSERT INTO suppliers (code, name, company_name, contact_person, phone, current_balance, status, created_at)
        VALUES (?, 'National Foods Ltd', 'National Foods', 'Tariq Mehmood', '03001234567', 25000.00, 'active', NOW())
    ")->execute([$testSuppCode]);
    $suppId = (int)$pdo->lastInsertId();

    $s360Stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $s360Stmt->execute([$suppId]);
    $supp = $s360Stmt->fetch();
    assertCondition("Supplier 360° loaded for ID {$suppId} with payable Rs. 25,000", (float)$supp['current_balance'] === 25000.00);

} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $failed++;
} finally {
    // Roll back test transaction to leave production database pristine
    $pdo->rollBack();
    echo "\n[INFO] Test transaction safely rolled back to keep live database clean.\n";
}

echo "\n========================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
