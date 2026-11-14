<?php
/**
 * Proper Double-Entry Accounting Engine
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

function getSystemAccount(PDO $pdo, string $systemTag): array {
    static $systemAccounts = [];
    if (isset($systemAccounts[$systemTag])) {
        return $systemAccounts[$systemTag];
    }

    $stmt = $pdo->prepare("SELECT * FROM `chart_of_accounts` WHERE `system_tag` = ? LIMIT 1");
    $stmt->execute([$systemTag]);
    $acc = $stmt->fetch();

    if (!$acc) {
        throw new Exception("Core accounting system account with tag '{$systemTag}' not configured in chart of accounts.");
    }

    $systemAccounts[$systemTag] = $acc;
    return $acc;
}

/**
 * Core Journal Entry Poster
 * Enforces Total Debit == Total Credit.
 * $lines is an array of ['account_id' => int, 'debit' => float, 'credit' => float, 'memo' => string]
 */
function postJournalEntry(PDO $pdo, string $date, string $refType, ?int $refId, string $narration, array $lines, ?int $userId = null): int {
    if (empty($lines)) {
        throw new Exception("Cannot post journal entry: No transaction lines provided.");
    }

    $totalDebit = 0.00;
    $totalCredit = 0.00;

    foreach ($lines as $line) {
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);

        if ($debit < 0 || $credit < 0) {
            throw new Exception("Negative debit or credit values are strictly disallowed in double-entry accounting.");
        }
        if ($debit > 0 && $credit > 0) {
            throw new Exception("A single journal line cannot contain both debit and credit amounts.");
        }

        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    $totalDebit = round($totalDebit, 2);
    $totalCredit = round($totalCredit, 2);

    if (abs($totalDebit - $totalCredit) > 0.001) {
        throw new Exception("Unbalanced Journal Entry: Total Debit ({$totalDebit}) does not equal Total Credit ({$totalCredit}). Financial postings must balance.");
    }

    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? 1;
    }

    $entryNumber = generateDocNumber($pdo, 'journal_entries', 'entry_number', 'JE-');

    $stmt = $pdo->prepare("
        INSERT INTO `journal_entries` (`entry_number`, `entry_date`, `reference_type`, `reference_id`, `narration`, `total_debit`, `total_credit`, `posted_by`, `status`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'posted', NOW())
    ");
    $stmt->execute([$entryNumber, $date, $refType, $refId, $narration, $totalDebit, $totalCredit, $userId]);
    $journalEntryId = (int)$pdo->lastInsertId();

    $lineStmt = $pdo->prepare("
        INSERT INTO `journal_entry_lines` (`journal_entry_id`, `account_id`, `debit`, `credit`, `memo`)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($lines as $l) {
        $accId = (int)$l['account_id'];
        $d = round((float)($l['debit'] ?? 0), 2);
        $c = round((float)($l['credit'] ?? 0), 2);
        $memo = substr($l['memo'] ?? '', 0, 255);

        if ($d > 0 || $c > 0) {
            $lineStmt->execute([$journalEntryId, $accId, $d, $c, $memo]);
        }
    }

    return $journalEntryId;
}

/**
 * Updates Customer Balance (Debit increases balance/receivable, Credit decreases balance)
 */
function updateCustomerBalance(PDO $pdo, int $customerId, float $deltaAmount): void {
    $stmt = $pdo->prepare("
        UPDATE `customers` 
        SET `current_balance` = `current_balance` + ?, `updated_at` = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$deltaAmount, $customerId]);
}

/**
 * Updates Supplier Balance (Credit increases payable, Debit decreases payable)
 */
function updateSupplierBalance(PDO $pdo, int $supplierId, float $deltaAmount): void {
    $stmt = $pdo->prepare("
        UPDATE `suppliers` 
        SET `current_balance` = `current_balance` + ? 
        WHERE id = ?
    ");
    $stmt->execute([$deltaAmount, $supplierId]);
}

/**
 * Post Accounting Entry for Credit / Cash Sale Invoice:
 * 1. Debit: Customer Receivables (or Cash)
 * 2. Credit: Sales Revenue
 * 3. Debit: Cost of Goods Sold (COGS)
 * 4. Credit: Merchandise Inventory
 */
function postSalesInvoiceAccounting(PDO $pdo, int $invoiceId, string $invoiceNumber, int $customerId, float $grandTotal, float $cogs, ?int $userId = null): int {
    $recAcc = getSystemAccount($pdo, 'receivables');
    $salesAcc = getSystemAccount($pdo, 'sales_revenue');
    $cogsAcc = getSystemAccount($pdo, 'cogs');
    $invAcc = getSystemAccount($pdo, 'inventory');

    $lines = [
        [
            'account_id' => $recAcc['id'],
            'debit' => $grandTotal,
            'credit' => 0.00,
            'memo' => "Customer Receivable for {$invoiceNumber}"
        ],
        [
            'account_id' => $salesAcc['id'],
            'debit' => 0.00,
            'credit' => $grandTotal,
            'memo' => "Sales Revenue for {$invoiceNumber}"
        ]
    ];

    if ($cogs > 0) {
        $lines[] = [
            'account_id' => $cogsAcc['id'],
            'debit' => $cogs,
            'credit' => 0.00,
            'memo' => "Cost of Goods Sold for {$invoiceNumber}"
        ];
        $lines[] = [
            'account_id' => $invAcc['id'],
            'debit' => 0.00,
            'credit' => $cogs,
            'memo' => "Inventory reduction at cost for {$invoiceNumber}"
        ];
    }

    $jeId = postJournalEntry($pdo, date('Y-m-d'), 'sales_invoice', $invoiceId, "Sales Invoice {$invoiceNumber} posting", $lines, $userId);
    updateCustomerBalance($pdo, $customerId, $grandTotal);
    return $jeId;
}

/**
 * Post Accounting Entry for Customer Payment Received
 * Debit: Cash in Hand / Bank
 * Credit: Customer Receivables
 */
function postCustomerPaymentAccounting(PDO $pdo, int $paymentId, string $receiptNumber, int $customerId, float $amount, int $paymentAccountId, ?int $userId = null): int {
    $recAcc = getSystemAccount($pdo, 'receivables');

    $lines = [
        [
            'account_id' => $paymentAccountId,
            'debit' => $amount,
            'credit' => 0.00,
            'memo' => "Cash/Bank received from customer on {$receiptNumber}"
        ],
        [
            'account_id' => $recAcc['id'],
            'debit' => 0.00,
            'credit' => $amount,
            'memo' => "Reduction in customer balance on {$receiptNumber}"
        ]
    ];

    $jeId = postJournalEntry($pdo, date('Y-m-d'), 'customer_payment', $paymentId, "Payment receipt {$receiptNumber} from customer", $lines, $userId);
    updateCustomerBalance($pdo, $customerId, -$amount); // Decreases receivable
    return $jeId;
}

/**
 * Post Accounting Entry for Goods Receipt Note (GRN) from Supplier:
 * Debit: Merchandise Inventory
 * Credit: Accounts Payable (Supplier)
 */
function postGoodsReceiptAccounting(PDO $pdo, int $grnId, string $grnNumber, int $supplierId, float $totalAmount, ?int $userId = null): int {
    $invAcc = getSystemAccount($pdo, 'inventory');
    $payAcc = getSystemAccount($pdo, 'payables');

    $lines = [
        [
            'account_id' => $invAcc['id'],
            'debit' => $totalAmount,
            'credit' => 0.00,
            'memo' => "Stock inventory increase from {$grnNumber}"
        ],
        [
            'account_id' => $payAcc['id'],
            'debit' => 0.00,
            'credit' => $totalAmount,
            'memo' => "Supplier payable liability for {$grnNumber}"
        ]
    ];

    $jeId = postJournalEntry($pdo, date('Y-m-d'), 'goods_receipt', $grnId, "Goods Receipt Note {$grnNumber} posting", $lines, $userId);
    updateSupplierBalance($pdo, $supplierId, $totalAmount); // Increases payable
    return $jeId;
}

/**
 * Post Accounting Entry for Payment to Supplier
 * Debit: Accounts Payable (Supplier)
 * Credit: Cash / Bank
 */
function postSupplierPaymentAccounting(PDO $pdo, int $paymentId, string $voucherNumber, int $supplierId, float $amount, int $paymentAccountId, ?int $userId = null): int {
    $payAcc = getSystemAccount($pdo, 'payables');

    $lines = [
        [
            'account_id' => $payAcc['id'],
            'debit' => $amount,
            'credit' => 0.00,
            'memo' => "Reduction in supplier payable liability for {$voucherNumber}"
        ],
        [
            'account_id' => $paymentAccountId,
            'debit' => 0.00,
            'credit' => $amount,
            'memo' => "Payment disbursed to supplier from account"
        ]
    ];

    $jeId = postJournalEntry($pdo, date('Y-m-d'), 'supplier_payment', $paymentId, "Supplier payment voucher {$voucherNumber}", $lines, $userId);
    updateSupplierBalance($pdo, $supplierId, -$amount); // Decreases payable
    return $jeId;
}

/**
 * Post Accounting Entry for Sales Return & Credit Note
 * Debit: Sales Returns & Allowances (Contra Revenue)
 * Credit: Customer Receivables
 * Debit: Inventory (if good condition)
 * Credit: Cost of Goods Sold (COGS)
 */
function postSalesReturnAccounting(PDO $pdo, int $returnId, string $returnNumber, int $customerId, float $salesCreditAmount, float $inventoryCost, string $destination = 'good_stock', ?int $userId = null): int {
    $salesReturnAcc = getSystemAccount($pdo, 'sales_returns');
    $recAcc = getSystemAccount($pdo, 'receivables');
    $invAcc = getSystemAccount($pdo, 'inventory');
    $cogsAcc = getSystemAccount($pdo, 'cogs');

    $lines = [
        [
            'account_id' => $salesReturnAcc['id'],
            'debit' => $salesCreditAmount,
            'credit' => 0.00,
            'memo' => "Sales Return allowance on {$returnNumber}"
        ],
        [
            'account_id' => $recAcc['id'],
            'debit' => 0.00,
            'credit' => $salesCreditAmount,
            'memo' => "Credit note to customer on {$returnNumber}"
        ]
    ];

    if ($destination === 'good_stock' && $inventoryCost > 0) {
        $lines[] = [
            'account_id' => $invAcc['id'],
            'debit' => $inventoryCost,
            'credit' => 0.00,
            'memo' => "Inventory restocked from sales return {$returnNumber}"
        ];
        $lines[] = [
            'account_id' => $cogsAcc['id'],
            'debit' => 0.00,
            'credit' => $inventoryCost,
            'memo' => "COGS reversal on returned goods {$returnNumber}"
        ];
    }

    $jeId = postJournalEntry($pdo, date('Y-m-d'), 'sales_return', $returnId, "Sales Return {$returnNumber} credit note", $lines, $userId);
    updateCustomerBalance($pdo, $customerId, -$salesCreditAmount);
    return $jeId;
}

/**
 * Post Accounting Entry for Business Expense
 * Debit: Expense Account (Salaries, Fuel, Rent, etc.)
 * Credit: Payment Account (Cash / Bank)
 */
function postExpenseAccounting(PDO $pdo, int $expenseId, string $expenseNumber, int $expenseAccountId, int $paymentAccountId, float $amount, string $description, ?int $userId = null): int {
    $lines = [
        [
            'account_id' => $expenseAccountId,
            'debit' => $amount,
            'credit' => 0.00,
            'memo' => "Expense: {$description}"
        ],
        [
            'account_id' => $paymentAccountId,
            'debit' => 0.00,
            'credit' => $amount,
            'memo' => "Disbursement for {$expenseNumber}"
        ]
    ];

    return postJournalEntry($pdo, date('Y-m-d'), 'expense', $expenseId, "Operating Expense {$expenseNumber} - {$description}", $lines, $userId);
}
