<?php
/**
 * Automated Test Suite for Direct Purchase, Instant Stock Fill & Payment Integration
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/inventory.php';
require_once dirname(__DIR__) . '/includes/accounting.php';

$pdo = getDatabaseConnection();

echo "====================================================\n";
echo "   FALCON DMS - DIRECT PURCHASE & PROCUREMENT QA   \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $message . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $message . "\n";
        $failCount++;
    }
}

// 1. Test Settings Upsert & Retrieval
$initialMode = getSetting($pdo, 'purchase_receiving_mode', 'two_step_grn');
$pdo->prepare("
    INSERT INTO `settings` (`setting_key`, `setting_value`, `description`, `updated_at`)
    VALUES ('purchase_receiving_mode', 'direct_receive', 'Test', NOW())
    ON DUPLICATE KEY UPDATE `setting_value` = 'direct_receive'
")->execute();

$updatedMode = getSetting($pdo, 'purchase_receiving_mode', null, true);
assertTest($updatedMode === 'direct_receive', "Procurement Policy Setting stores 'direct_receive'");

// Setup Test Data
$supplier = $pdo->query("SELECT id, current_balance FROM `suppliers` WHERE status = 'active' LIMIT 1")->fetch();
$warehouse = $pdo->query("SELECT id FROM `warehouses` WHERE status = 'active' LIMIT 1")->fetch();
$product = $pdo->query("SELECT id, base_unit_id, purchase_price FROM `products` WHERE status = 'active' LIMIT 1")->fetch();
$cashAccount = $pdo->query("SELECT id FROM `chart_of_accounts` WHERE system_tag = 'cash' LIMIT 1")->fetch();

if (!$supplier || !$warehouse || !$product || !$cashAccount) {
    die("Prerequisites not met for test.\n");
}

$suppId = (int)$supplier['id'];
$whId = (int)$warehouse['id'];
$prodId = (int)$product['id'];
$cashAccId = (int)$cashAccount['id'];
$baseUnitId = (int)$product['base_unit_id'];

$initStock = getCurrentStock($pdo, $whId, $prodId);
$initSuppBal = (float)$supplier['current_balance'];

// 2. Test Direct Purchase on Credit (Instant Stock Fill, No Immediate Payment)
$poDate = date('Y-m-d');
$poNumber = generateDocNumber($pdo, 'purchase_orders', 'po_number', 'TEST-PO-');
$qty = 10.00;
$cost = 50.00;
$total = 500.00;

$pdo->beginTransaction();

// Insert PO as received
$stmt = $pdo->prepare("
    INSERT INTO `purchase_orders` (`po_number`, `supplier_id`, `warehouse_id`, `po_date`, `subtotal`, `grand_total`, `status`, `created_by`, `created_at`)
    VALUES (?, ?, ?, ?, ?, ?, 'received', 1, NOW())
");
$stmt->execute([$poNumber, $suppId, $whId, $poDate, $total, $total]);
$poId = (int)$pdo->lastInsertId();

// Insert Item
$pdo->prepare("
    INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `received_qty`, `unit_cost`, `total_cost`)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([$poId, $prodId, $baseUnitId, $qty, $qty, $cost, $total]);

// Auto-generate GRN
$grnNumber = generateDocNumber($pdo, 'goods_receipts', 'grn_number', 'TEST-GRN-');
$pdo->prepare("
    INSERT INTO `goods_receipts` (`grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, `grn_date`, `total_amount`, `landed_cost`, `posted_to_accounts`, `created_by`, `created_at`)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())
")->execute([$grnNumber, $poId, $suppId, $whId, $poDate, $total, $total]);
$grnId = (int)$pdo->lastInsertId();

// Insert GRN Item
$pdo->prepare("
    INSERT INTO `goods_receipt_items` (`goods_receipt_id`, `product_id`, `unit_id`, `received_qty`, `unit_cost`, `total_cost`)
    VALUES (?, ?, ?, ?, ?, ?)
")->execute([$grnId, $prodId, $baseUnitId, $qty, $cost, $total]);

// Record Stock Movement
recordStockMovement(
    $pdo,
    $whId,
    $prodId,
    'purchase_grn',
    'goods_receipt',
    $grnId,
    $baseUnitId,
    $qty,
    0.00,
    $cost,
    null,
    "Test direct purchase stock movement"
);

// Post Accounting
$jeId1 = postGoodsReceiptAccounting($pdo, $grnId, $grnNumber, $suppId, $total, 1);

$pdo->commit();

// Verifications
$newStock = getCurrentStock($pdo, $whId, $prodId);
assertTest($newStock === ($initStock + $qty), "Direct Purchase instantly incremented physical stock (+{$qty})");

$suppAfterCredit = (float)$pdo->query("SELECT current_balance FROM `suppliers` WHERE id = {$suppId}")->fetchColumn();
assertTest(abs($suppAfterCredit - ($initSuppBal + $total)) < 0.01, "Direct Purchase on Credit increased Supplier Payable by Rs. {$total}");

// 3. Test Direct Purchase with Immediate Full Payment
$poNumber2 = generateDocNumber($pdo, 'purchase_orders', 'po_number', 'TEST-PO-');
$pdo->beginTransaction();

$stmt->execute([$poNumber2, $suppId, $whId, $poDate, $total, $total]);
$poId2 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO `purchase_items` (`purchase_order_id`, `product_id`, `unit_id`, `order_qty`, `received_qty`, `unit_cost`, `total_cost`)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([$poId2, $prodId, $baseUnitId, $qty, $qty, $cost, $total]);

$grnNumber2 = generateDocNumber($pdo, 'goods_receipts', 'grn_number', 'TEST-GRN-');
$pdo->prepare("
    INSERT INTO `goods_receipts` (`grn_number`, `purchase_order_id`, `supplier_id`, `warehouse_id`, `grn_date`, `total_amount`, `landed_cost`, `posted_to_accounts`, `created_by`, `created_at`)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())
")->execute([$grnNumber2, $poId2, $suppId, $whId, $poDate, $total, $total]);
$grnId2 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO `goods_receipt_items` (`goods_receipt_id`, `product_id`, `unit_id`, `received_qty`, `unit_cost`, `total_cost`)
    VALUES (?, ?, ?, ?, ?, ?)
")->execute([$grnId2, $prodId, $baseUnitId, $qty, $cost, $total]);

recordStockMovement($pdo, $whId, $prodId, 'purchase_grn', 'goods_receipt', $grnId2, $baseUnitId, $qty, 0.00, $cost, null, "Test direct purchase 2");
$jeId2 = postGoodsReceiptAccounting($pdo, $grnId2, $grnNumber2, $suppId, $total, 1);

// Direct Payment Voucher
$payNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'TEST-PAY-');
$pdo->prepare("
    INSERT INTO `payments` (
        `payment_number`, `payment_type`, `reference_type`, `reference_id`, 
        `party_type`, `party_id`, `amount`, `payment_method_id`, 
        `payment_method_code`, `payment_account_id`, `payment_date`, 
        `notes`, `status`, `created_by`, `created_at`
    ) VALUES (?, 'supplier_payment', 'purchase_order', ?, 'supplier', ?, ?, 1, 'cash', ?, ?, 'Test immediate payment', 'posted', 1, NOW())
")->execute([$payNumber, $poId2, $suppId, $total, $cashAccId, $poDate]);
$payId = (int)$pdo->lastInsertId();

$jeId3 = postSupplierPaymentAccounting($pdo, $payId, $payNumber, $suppId, $total, $cashAccId, 1);

$pdo->commit();

// Verifications
$stockAfterPay = getCurrentStock($pdo, $whId, $prodId);
assertTest($stockAfterPay === ($initStock + $qty * 2), "Direct Purchase with Payment incremented physical stock (+{$qty})");

$suppAfterPay = (float)$pdo->query("SELECT current_balance FROM `suppliers` WHERE id = {$suppId}")->fetchColumn();
assertTest(abs($suppAfterPay - $suppAfterCredit) < 0.01, "Immediate Full Payment kept net supplier balance unchanged (Payable credited then debited)");

// Verify Balanced Journal Entries
$unbalanced = $pdo->query("
    SELECT je.id, je.entry_number, SUM(jel.debit) as tot_debit, SUM(jel.credit) as tot_credit
    FROM `journal_entries` je
    JOIN `journal_entry_lines` jel ON je.id = jel.journal_entry_id
    WHERE je.id IN ({$jeId1}, {$jeId2}, {$jeId3})
    GROUP BY je.id
    HAVING ABS(tot_debit - tot_credit) > 0.01
")->fetchAll();

assertTest(empty($unbalanced), "All Journal Entries for GRN and Payment strictly balance (Debit == Credit)");

// 4. Teardown Test Data
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM `journal_entry_lines` WHERE `journal_entry_id` IN ({$jeId1}, {$jeId2}, {$jeId3})")->execute();
$pdo->prepare("DELETE FROM `journal_entries` WHERE `id` IN ({$jeId1}, {$jeId2}, {$jeId3})")->execute();
$pdo->prepare("DELETE FROM `payments` WHERE `id` = ?")->execute([$payId]);
$pdo->prepare("DELETE FROM `stock_ledger` WHERE `reference_type` = 'goods_receipt' AND `reference_id` IN ({$grnId}, {$grnId2})")->execute();
$pdo->prepare("DELETE FROM `goods_receipt_items` WHERE `goods_receipt_id` IN ({$grnId}, {$grnId2})")->execute();
$pdo->prepare("DELETE FROM `goods_receipts` WHERE `id` IN ({$grnId}, {$grnId2})")->execute();
$pdo->prepare("DELETE FROM `purchase_items` WHERE `purchase_order_id` IN ({$poId}, {$poId2})")->execute();
$pdo->prepare("DELETE FROM `purchase_orders` WHERE `id` IN ({$poId}, {$poId2})")->execute();
// Restore supplier balance
$pdo->prepare("UPDATE `suppliers` SET `current_balance` = ? WHERE `id` = ?")->execute([$initSuppBal, $suppId]);
$pdo->commit();

echo "\n----------------------------------------------------\n";
echo "TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED.\n";
echo "----------------------------------------------------\n";

if ($failCount === 0) {
    echo "ALL DIRECT PURCHASE & PROCUREMENT TESTS PASSED 100%!\n";
} else {
    exit(1);
}
