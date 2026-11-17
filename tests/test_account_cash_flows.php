<?php
/**
 * Test Suite: Account Selection Across Cash Flows
 * Enterprise Distribution Management System (DMS)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/accounting.php';

echo "=== TESTING CASH FLOW ACCOUNT SELECTION ===\n";

$pdo = getDatabaseConnection();

// 1. Verify Cash & Bank Accounts exist
$cashAcc = getSystemAccount($pdo, 'cash');
$bankAcc = getSystemAccount($pdo, 'bank');

if (!$cashAcc || !$bankAcc) {
    echo "FAIL: Required Cash / Bank accounts not found.\n";
    exit(1);
}
echo "PASS: Found Cash Account (ID: {$cashAcc['id']}, {$cashAcc['code']} - {$cashAcc['name']})\n";
echo "PASS: Found Bank Account (ID: {$bankAcc['id']}, {$bankAcc['code']} - {$bankAcc['name']})\n";

// 2. Test Supplier Payment Disbursement with specific Bank Account
$pdo->beginTransaction();
try {
    $supplier = $pdo->query("SELECT id, name FROM `suppliers` WHERE status = 'active' LIMIT 1")->fetch();
    if (!$supplier) {
        throw new Exception("No active supplier found for testing.");
    }

    $amount = 1500.00;
    $vNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'TEST-PV-');
    $payDate = date('Y-m-d');
    
    // Disburse from Bank Account (Main)
    $targetAccountId = (int)$bankAcc['id'];

    $pStmt = $pdo->prepare("
        INSERT INTO `payments` (
            `payment_number`, `payment_type`, `reference_type`, `reference_id`,
            `party_type`, `party_id`, `amount`, `payment_method_id`,
            `payment_method_code`, `payment_account_id`, `payment_date`,
            `reference_no`, `notes`, `status`, `created_by`, `created_at`
        ) VALUES (?, 'supplier_payment', 'supplier_disbursement', ?, 'supplier', ?, ?, 2, 'bank_transfer', ?, ?, 'TEST-CHQ-1', 'Test bank disbursement', 'posted', 1, NOW())
    ");
    $pStmt->execute([$vNumber, $supplier['id'], $supplier['id'], $amount, $targetAccountId, $payDate]);
    $payId = (int)$pdo->lastInsertId();

    $jeId = postSupplierPaymentAccounting($pdo, $payId, $vNumber, (int)$supplier['id'], $amount, $targetAccountId, 1);

    // Verify Journal Entry Lines
    $lines = $pdo->query("SELECT * FROM journal_entry_lines WHERE journal_entry_id = {$jeId}")->fetchAll();
    $debitedAcc = null;
    $creditedAcc = null;
    foreach ($lines as $line) {
        if ((float)$line['debit'] > 0) $debitedAcc = (int)$line['account_id'];
        if ((float)$line['credit'] > 0) $creditedAcc = (int)$line['account_id'];
    }

    if ($creditedAcc !== $targetAccountId) {
        throw new Exception("Supplier payment credited account {$creditedAcc}, expected {$targetAccountId}");
    }
    echo "PASS: Supplier Payment disbursed correctly credited target account ID {$targetAccountId} ({$bankAcc['name']}).\n";

    // Verify Supplier Statement query formats account
    $stmtTest = $pdo->prepare("
        SELECT p.payment_date as tx_date, p.payment_number as doc_number, 'Supplier Payment' as tx_type,
               CONCAT('Payment via ', UPPER(p.payment_method_code), 
                      CASE WHEN coa.name IS NOT NULL THEN CONCAT(' [', coa.code, ' - ', coa.name, ']') ELSE '' END,
                      ' (Ref: ', COALESCE(p.reference_no, '-'), ')') as description,
               p.amount as debit, 0.00 as credit, p.created_at
        FROM `payments` p
        LEFT JOIN `chart_of_accounts` coa ON p.payment_account_id = coa.id
        WHERE p.id = ?
    ");
    $stmtTest->execute([$payId]);
    $statementRow = $stmtTest->fetch();
    if (!str_contains($statementRow['description'], $bankAcc['name'])) {
        throw new Exception("Statement description does not contain account name: {$statementRow['description']}");
    }
    echo "PASS: Supplier Ledger description verified: '{$statementRow['description']}'\n";

    // 3. Test Operating Expense with specific Account
    $category = $pdo->query("SELECT id, name, account_id FROM `expense_categories` WHERE status = 'active' LIMIT 1")->fetch();
    $expAmount = 450.00;
    $expNumber = generateDocNumber($pdo, 'expenses', 'expense_number', 'TEST-EXP-');

    $expStmt = $pdo->prepare("
        INSERT INTO `expenses` (
            `expense_number`, `category_id`, `expense_date`, `amount`, 
            `payment_account_id`, `payment_method`, `reference_no`, `description`, 
            `entered_by`, `approved_by`, `status`, `created_at`
        ) VALUES (?, ?, ?, ?, ?, 'bank_transfer', 'BILL-01', 'Test Expense Description', 1, 1, 'approved', NOW())
    ");
    $expStmt->execute([$expNumber, $category['id'], $payDate, $expAmount, $targetAccountId]);
    $expenseId = (int)$pdo->lastInsertId();

    $expJeId = postExpenseAccounting($pdo, $expenseId, $expNumber, (int)$category['account_id'], $targetAccountId, $expAmount, 'Test Expense', 1);

    $expLines = $pdo->query("SELECT * FROM journal_entry_lines WHERE journal_entry_id = {$expJeId}")->fetchAll();
    $expCreditedAcc = null;
    foreach ($expLines as $line) {
        if ((float)$line['credit'] > 0) $expCreditedAcc = (int)$line['account_id'];
    }
    if ($expCreditedAcc !== $targetAccountId) {
        throw new Exception("Expense credited account {$expCreditedAcc}, expected {$targetAccountId}");
    }
    echo "PASS: Expense voucher correctly credited target account ID {$targetAccountId} ({$bankAcc['name']}).\n";

    // Verify Expense Query with coa_pay join
    $expRow = $pdo->query("
        SELECT e.*, ec.name as category_name,
               coa_pay.code as pay_account_code, coa_pay.name as pay_account_name
        FROM `expenses` e
        JOIN `expense_categories` ec ON e.category_id = ec.id
        LEFT JOIN `chart_of_accounts` coa_pay ON e.payment_account_id = coa_pay.id
        WHERE e.id = {$expenseId}
    ")->fetch();
    if ($expRow['pay_account_name'] !== $bankAcc['name']) {
        throw new Exception("Expense joined account mismatch: {$expRow['pay_account_name']}");
    }
    echo "PASS: Expense vouchers query joined account verified: {$expRow['pay_account_code']} - {$expRow['pay_account_name']}\n";

    // 4. Test Customer Payment Recovery with specific Account
    $customer = $pdo->query("SELECT id, shop_name, current_balance FROM `customers` WHERE status = 'active' LIMIT 1")->fetch();
    $recAmount = 2500.00;
    $recNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'TEST-RCP-');

    $rStmt = $pdo->prepare("
        INSERT INTO `payments` (
            `payment_number`, `payment_type`, `reference_type`, `reference_id`,
            `party_type`, `party_id`, `amount`, `payment_method_id`,
            `payment_method_code`, `payment_account_id`, `payment_date`,
            `reference_no`, `collected_by`, `handover_status`, `notes`,
            `status`, `created_by`, `created_at`
        ) VALUES (?, 'customer_receipt', 'recovery', ?, 'customer', ?, ?, 2, 'bank_transfer', ?, ?, 'TRF-TEST', 1, 'reconciled', 'Direct bank collection', 'posted', 1, NOW())
    ");
    $rStmt->execute([$recNumber, $customer['id'], $customer['id'], $recAmount, $targetAccountId, $payDate]);
    $recPayId = (int)$pdo->lastInsertId();

    $recJeId = postCustomerPaymentAccounting($pdo, $recPayId, $recNumber, (int)$customer['id'], $recAmount, $targetAccountId, 1);

    $recLines = $pdo->query("SELECT * FROM journal_entry_lines WHERE journal_entry_id = {$recJeId}")->fetchAll();
    $recDebitedAcc = null;
    foreach ($recLines as $line) {
        if ((float)$line['debit'] > 0) $recDebitedAcc = (int)$line['account_id'];
    }
    if ($recDebitedAcc !== $targetAccountId) {
        throw new Exception("Customer recovery debited account {$recDebitedAcc}, expected {$targetAccountId}");
    }
    echo "PASS: Customer Payment Recovery correctly debited target account ID {$targetAccountId} ({$bankAcc['name']}).\n";

    // Verify Customer Ledger query joins account
    $custStmtTest = $pdo->prepare("
        SELECT p.payment_date as tx_date, p.payment_number as doc_number, 'Payment Receipt' as tx_type,
               CONCAT('Payment via ', UPPER(p.payment_method_code), 
                      CASE WHEN coa.name IS NOT NULL THEN CONCAT(' [', coa.code, ' - ', coa.name, ']') ELSE '' END,
                      ' (Ref: ', COALESCE(p.reference_no, '-'), ')') as description,
               0.00 as debit, p.amount as credit, p.created_at
        FROM `payments` p
        LEFT JOIN `chart_of_accounts` coa ON p.payment_account_id = coa.id
        WHERE p.id = ?
    ");
    $custStmtTest->execute([$recPayId]);
    $custRow = $custStmtTest->fetch();
    if (!str_contains($custRow['description'], $bankAcc['name'])) {
        throw new Exception("Customer ledger statement does not contain account name: {$custRow['description']}");
    }
    echo "PASS: Customer Ledger statement description verified: '{$custRow['description']}'\n";

    // Verify Double-Entry Balance on all 3 entries
    foreach ([$jeId, $expJeId, $recJeId] as $jid) {
        $bal = $pdo->query("SELECT SUM(debit) as deb, SUM(credit) as cred FROM journal_entry_lines WHERE journal_entry_id = {$jid}")->fetch();
        if (abs((float)$bal['deb'] - (float)$bal['cred']) > 0.001) {
            throw new Exception("Journal Entry {$jid} is not balanced: Debit={$bal['deb']}, Credit={$bal['cred']}");
        }
    }
    echo "PASS: All journal entries strictly balanced (Total Debit == Total Credit).\n";

    // Rollback test mutations to keep database pristine
    $pdo->rollBack();
    echo "\n=== ALL 7 CASH FLOW ACCOUNT SELECTION TESTS PASSED SUCCESSFULLY! ===\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
