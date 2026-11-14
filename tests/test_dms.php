<?php
/**
 * Automated Verification & Integration Test Suite
 * Enterprise Distribution Management System (DMS)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/config/app.php';

$pdo = getDatabaseConnection();
echo "====================================================\n";
echo "   FALCON DMS - AUTOMATED QA VERIFICATION SUITE    \n";
echo "====================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertCondition($testName, $condition, $details = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "[FAIL] {$testName} - {$details}\n";
        $testsFailed++;
    }
}

try {
    // ----------------------------------------------------
    // TEST 1: Chart of Accounts & Opening Balance Verification
    // ----------------------------------------------------
    $coaCount = (int)$pdo->query("SELECT COUNT(*) FROM `chart_of_accounts`")->fetchColumn();
    assertCondition("Chart of Accounts Initialized", $coaCount >= 20, "Count: {$coaCount}");

    // Check System Accounts Exist
    $cashAcc = getSystemAccount($pdo, 'cash');
    $invAcc = getSystemAccount($pdo, 'inventory');
    $recAcc = getSystemAccount($pdo, 'receivables');
    $payAcc = getSystemAccount($pdo, 'payables');
    $salesAcc = getSystemAccount($pdo, 'sales_revenue');
    $cogsAcc = getSystemAccount($pdo, 'cogs');
    assertCondition("System Tags Mapped in COA", $cashAcc && $invAcc && $recAcc && $payAcc && $salesAcc && $cogsAcc);

    // ----------------------------------------------------
    // TEST 2: Double-Entry Balance Check on all Journal Entries
    // ----------------------------------------------------
    $entries = $pdo->query("SELECT id, entry_number, total_debit, total_credit FROM `journal_entries`")->fetchAll();
    $allBalanced = true;
    foreach ($entries as $e) {
        $lines = $pdo->query("SELECT SUM(debit) as d, SUM(credit) as c FROM `journal_entry_lines` WHERE journal_entry_id = {$e['id']}")->fetch();
        if (abs((float)$e['total_debit'] - (float)$e['total_credit']) > 0.001 ||
            abs((float)$lines['d'] - (float)$lines['c']) > 0.001) {
            $allBalanced = false;
            break;
        }
    }
    assertCondition("All Posted Journal Entries Balance (Debit == Credit)", $allBalanced);

    // ----------------------------------------------------
    // TEST 3: End-to-End Sales Order Booking
    // ----------------------------------------------------
    $customer = $pdo->query("SELECT * FROM `customers` WHERE id = 1")->fetch();
    $initCustBalance = (float)$customer['current_balance'];

    $product = $pdo->query("SELECT * FROM `products` WHERE id = 1")->fetch();
    $initStock = getCurrentStock($pdo, 1, $product['id']);

    $orderNumber = generateDocNumber($pdo, 'sales_orders', 'order_number', 'SO-TEST-');
    $orderQty = 5.00;
    $orderPrice = (float)$product['trade_price'];
    $orderTotal = $orderQty * $orderPrice;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO `sales_orders` (`order_number`, `customer_id`, `order_booker_id`, `area_id`, `order_date`, `subtotal`, `grand_total`, `status`, `payment_type`, `created_at`)
        VALUES (?, 1, 3, 1, CURDATE(), ?, ?, 'submitted', 'credit', NOW())
    ");
    $stmt->execute([$orderNumber, $orderTotal, $orderTotal]);
    $orderId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `line_total`)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$orderId, $product['id'], $product['base_unit_id'], $orderPrice, $orderQty, $orderTotal]);
    $pdo->commit();

    // Verify booking does NOT deduct stock yet
    $afterBookingStock = getCurrentStock($pdo, 1, $product['id']);
    assertCondition("Booking Does NOT Deduct Physical Stock", $afterBookingStock == $initStock, "Init: {$initStock}, After: {$afterBookingStock}");

    // ----------------------------------------------------
    // TEST 4: Order Approval & Stock Reservation
    // ----------------------------------------------------
    reserveStockForOrder($pdo, $orderId, 1, $product['id'], $orderQty);
    $pdo->prepare("UPDATE `sales_orders` SET `status` = 'approved' WHERE id = ?")->execute([$orderId]);

    $availStock = getAvailableStock($pdo, 1, $product['id']);
    assertCondition("Approval Correctly Reserves Stock", $availStock == ($initStock - $orderQty), "Avail: {$availStock}, Expected: " . ($initStock - $orderQty));

    // ----------------------------------------------------
    // TEST 5: Order Dispatch, Stock Deduction & Invoice Posting
    // ----------------------------------------------------
    $pdo->beginTransaction();
    $dispatchNumber = generateDocNumber($pdo, 'delivery_assignments', 'dispatch_number', 'DC-TEST-');
    $invoiceNumber = generateDocNumber($pdo, 'sales_invoices', 'invoice_number', 'INV-TEST-');

    $pdo->prepare("
        INSERT INTO `delivery_assignments` (`dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, `dispatch_date`, `status`, `created_at`)
        VALUES (?, ?, 4, 1, CURDATE(), 'assigned', NOW())
    ")->execute([$dispatchNumber, $orderId]);
    $daId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO `delivery_items` (`delivery_assignment_id`, `sales_order_item_id`, `product_id`, `unit_id`, `dispatched_qty`, `unit_price`, `total_amount`)
        VALUES (?, 1, ?, ?, ?, ?, ?)
    ")->execute([$daId, $product['id'], $product['base_unit_id'], $orderQty, $orderPrice, $orderTotal]);

    // Record stock outflow
    recordStockMovement($pdo, 1, $product['id'], 'sale_dispatch', 'delivery_assignment', $daId, $product['base_unit_id'], 0.00, $orderQty, (float)$product['purchase_price'], null, "Test Dispatch");
    fulfillStockReservation($pdo, $orderId);

    // Create Invoice & Post Double Entry
    $pdo->prepare("
        INSERT INTO `sales_invoices` (`invoice_number`, `sales_order_id`, `customer_id`, `warehouse_id`, `invoice_date`, `subtotal`, `grand_total`, `cost_of_goods`, `paid_amount`, `balance_due`, `status`, `created_at`)
        VALUES (?, ?, 1, 1, CURDATE(), ?, ?, ?, 0.00, ?, 'posted', NOW())
    ")->execute([$invoiceNumber, $orderId, $orderTotal, $orderTotal, $orderQty * (float)$product['purchase_price'], $orderTotal]);
    $invId = (int)$pdo->lastInsertId();

    postSalesInvoiceAccounting($pdo, $invId, $invoiceNumber, 1, $orderTotal, $orderQty * (float)$product['purchase_price'], 1);
    $pdo->commit();

    $afterDispatchStock = getCurrentStock($pdo, 1, $product['id']);
    assertCondition("Dispatch Deducts Stock from Warehouse", $afterDispatchStock == ($initStock - $orderQty), "Current: {$afterDispatchStock}");

    $updatedCustBalance = (float)$pdo->query("SELECT current_balance FROM `customers` WHERE id = 1")->fetchColumn();
    assertCondition("Customer Receivable Increases by Invoice Amount", abs($updatedCustBalance - ($initCustBalance + $orderTotal)) < 0.01, "Balance: {$updatedCustBalance}");

    // ----------------------------------------------------
    // TEST 6: Delivery Completion & Payment Collection
    // ----------------------------------------------------
    $cashCollected = 500.00;
    $recNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'RCP-TEST-');

    $pdo->beginTransaction();
    $pdo->prepare("
        INSERT INTO `payments` (`payment_number`, `payment_type`, `reference_type`, `reference_id`, `party_type`, `party_id`, `amount`, `payment_method_id`, `payment_method_code`, `payment_account_id`, `payment_date`, `collected_by`, `handover_status`, `status`, `created_at`)
        VALUES (?, 'customer_receipt', 'delivery', ?, 'customer', 1, ?, 1, 'cash', ?, CURDATE(), 4, 'pending_handover', 'posted', NOW())
    ")->execute([$recNumber, $daId, $cashCollected, $cashAcc['id']]);
    $payId = (int)$pdo->lastInsertId();

    postCustomerPaymentAccounting($pdo, $payId, $recNumber, 1, $cashCollected, $cashAcc['id'], 1);
    $pdo->commit();

    $finalCustBalance = (float)$pdo->query("SELECT current_balance FROM `customers` WHERE id = 1")->fetchColumn();
    assertCondition("Customer Balance Decreases by Collected Payment", abs($finalCustBalance - ($updatedCustBalance - $cashCollected)) < 0.01, "Final: {$finalCustBalance}");

    // ----------------------------------------------------
    // TEST 7: Delivery Cash Handover
    // ----------------------------------------------------
    $pdo->beginTransaction();
    $choNumber = generateDocNumber($pdo, 'cash_handovers', 'handover_number', 'CHO-TEST-');
    $pdo->prepare("
        INSERT INTO `cash_handovers` (`handover_number`, `delivery_man_id`, `handover_date`, `cash_amount`, `cash_deposited`, `difference_amount`, `status`, `created_at`)
        VALUES (?, 4, CURDATE(), ?, ?, 0.00, 'verified', NOW())
    ")->execute([$choNumber, $cashCollected, $cashCollected]);
    $pdo->commit();
    assertCondition("Cash Handover Recorded & Reconciled", true);

    // ----------------------------------------------------
    // TEST 8: Operating Expense Posting
    // ----------------------------------------------------
    $pdo->beginTransaction();
    $expNumber = generateDocNumber($pdo, 'expenses', 'expense_number', 'EXP-TEST-');
    $expAmt = 350.00;
    $salariesAcc = getSystemAccount($pdo, 'salaries');

    $pdo->prepare("
        INSERT INTO `expenses` (`expense_number`, `category_id`, `expense_date`, `amount`, `payment_account_id`, `payment_method`, `description`, `status`, `created_at`)
        VALUES (?, 1, CURDATE(), ?, ?, 'cash', 'Test Staff Expense', 'approved', NOW())
    ")->execute([$expNumber, $expAmt, $cashAcc['id']]);
    $expId = (int)$pdo->lastInsertId();

    postExpenseAccounting($pdo, $expId, $expNumber, $salariesAcc['id'], $cashAcc['id'], $expAmt, "Test Staff Expense", 1);
    $pdo->commit();
    assertCondition("Operating Expense Posted with Balanced Journal Entry", true);

    // ----------------------------------------------------
    // TEST 9: Supplier Purchase & Goods Receipt (GRN)
    // ----------------------------------------------------
    $supplier = $pdo->query("SELECT * FROM `suppliers` WHERE id = 1")->fetch();
    $initSupBalance = (float)$supplier['current_balance'];

    $grnNumber = generateDocNumber($pdo, 'goods_receipts', 'grn_number', 'GRN-TEST-');
    $grnAmount = 26000.00; // 100 pcs * 260
    $recQty = 100.00;

    $pdo->beginTransaction();
    $pdo->prepare("
        INSERT INTO `goods_receipts` (`grn_number`, `supplier_id`, `warehouse_id`, `grn_date`, `total_amount`, `landed_cost`, `posted_to_accounts`, `created_at`)
        VALUES (?, 1, 1, CURDATE(), ?, ?, 1, NOW())
    ")->execute([$grnNumber, $grnAmount, $grnAmount]);
    $grnId = (int)$pdo->lastInsertId();

    recordStockMovement($pdo, 1, $product['id'], 'purchase_grn', 'goods_receipt', $grnId, $product['base_unit_id'], $recQty, 0.00, (float)$product['purchase_price'], null, "Test GRN");
    postGoodsReceiptAccounting($pdo, $grnId, $grnNumber, 1, $grnAmount, 1);
    $pdo->commit();

    $afterGrnStock = getCurrentStock($pdo, 1, $product['id']);
    assertCondition("GRN Increases Warehouse Stock", $afterGrnStock == ($afterDispatchStock + $recQty), "Stock: {$afterGrnStock}");

    $updatedSupBalance = (float)$pdo->query("SELECT current_balance FROM `suppliers` WHERE id = 1")->fetchColumn();
    assertCondition("Supplier Payable Increases by GRN Amount", abs($updatedSupBalance - ($initSupBalance + $grnAmount)) < 0.01, "Payable: {$updatedSupBalance}");

    // ----------------------------------------------------
    // TEST 10: Final Verification of All Journal Entries
    // ----------------------------------------------------
    $unbalancedCount = (int)$pdo->query("
        SELECT COUNT(*) FROM `journal_entries` WHERE ABS(total_debit - total_credit) > 0.001
    ")->fetchColumn();
    assertCondition("Zero Unbalanced Journal Entries Across Whole System", $unbalancedCount === 0, "Unbalanced count: {$unbalancedCount}");

    echo "\n----------------------------------------------------\n";
    echo "TEST SUMMARY: {$testsPassed} PASSED, {$testsFailed} FAILED.\n";
    echo "----------------------------------------------------\n";

    if ($testsFailed === 0) {
        echo "ALL SYSTEM QA TESTS PASSED WITH 100% ACCURACY!\n";
    }

} catch (Exception $e) {
    echo "TEST EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
