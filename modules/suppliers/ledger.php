<?php
/**
 * Supplier Financial Statement & Disbursement
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('suppliers', 'view');

$supplierId = (int)($_GET['id'] ?? 0);
if (!$supplierId) {
    setFlashMessage('error', 'Invalid supplier specified.');
    redirect(BASE_URL . '/modules/suppliers/index.php');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currentUser = getCurrentUser();

// Available Cash & Bank Accounts for Cash Flow Disbursement
$paymentAccounts = $pdo->query("
    SELECT id, code, name, system_tag 
    FROM `chart_of_accounts` 
    WHERE is_active = 1 
      AND group_type = 'Asset' 
      AND (system_tag IN ('cash', 'bank') OR parent_id = 1) 
      AND (system_tag IS NULL OR system_tag NOT IN ('receivables', 'inventory'))
    ORDER BY code ASC
")->fetchAll();

$paymentMethods = $pdo->query("SELECT id, name, code, account_id FROM `payment_methods` WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

$sStmt = $pdo->prepare("SELECT * FROM `suppliers` WHERE id = ?");
$sStmt->execute([$supplierId]);
$supplier = $sStmt->fetch();

if (!$supplier) {
    setFlashMessage('error', 'Supplier not found.');
    redirect(BASE_URL . '/modules/suppliers/index.php');
}

// Process POST Disburse Payment to Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disburse_payment'])) {
    requirePermission('payments', 'create');

    $amount = (float)($_POST['amount'] ?? 0.00);
    $payDate = $_POST['payment_date'] ?? date('Y-m-d');
    $methodCode = sanitize($_POST['payment_method'] ?? 'bank_transfer');
    $paymentAccountId = (int)($_POST['payment_account_id'] ?? 0);
    $refNo = sanitize($_POST['reference_no'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    if ($amount <= 0) {
        setFlashMessage('error', 'Please enter a valid payment amount.');
        redirect(BASE_URL . "/modules/suppliers/ledger.php?id={$supplierId}");
    }

    try {
        $pdo->beginTransaction();

        $pmStmt = $pdo->prepare("SELECT * FROM `payment_methods` WHERE code = ? LIMIT 1");
        $pmStmt->execute([$methodCode]);
        $pm = $pmStmt->fetch();

        if (!$pm) {
            throw new Exception("Invalid payment method selected.");
        }

        // Validate or fallback payment account
        if ($paymentAccountId <= 0) {
            $paymentAccountId = (int)$pm['account_id'];
        }

        // Fetch account name for verification and audit
        $accStmt = $pdo->prepare("SELECT name, code FROM `chart_of_accounts` WHERE id = ?");
        $accStmt->execute([$paymentAccountId]);
        $acc = $accStmt->fetch();
        if (!$acc) {
            throw new Exception("Invalid disbursing cash/bank account selected.");
        }

        $voucherNumber = generateDocNumber($pdo, 'payments', 'payment_number', 'PV-');

        $pStmt = $pdo->prepare("
            INSERT INTO `payments` (
                `payment_number`, `payment_type`, `reference_type`, `reference_id`, 
                `party_type`, `party_id`, `amount`, `payment_method_id`, 
                `payment_method_code`, `payment_account_id`, `payment_date`, 
                `reference_no`, `handover_status`, `notes`, `status`, `created_by`, `created_at`
            ) VALUES (?, 'supplier_payment', 'supplier', ?, 'supplier', ?, ?, ?, ?, ?, ?, ?, 'not_applicable', ?, 'posted', ?, NOW())
        ");
        $pStmt->execute([
            $voucherNumber, $supplierId, $supplierId, $amount,
            $pm['id'], $methodCode, $paymentAccountId, $payDate,
            $refNo, $notes ?: "Disbursement to {$supplier['name']} from {$acc['name']}",
            $currentUser['id']
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // Critical Double-Entry Accounting Rule:
        // Debit: Accounts Payable (Supplier)
        // Credit: Cash / Bank (Selected Payment Account)
        postSupplierPaymentAccounting($pdo, $paymentId, $voucherNumber, $supplierId, $amount, $paymentAccountId, $currentUser['id']);

        logAudit($pdo, 'payments', $paymentId, 'supplier_pay', null, [
            'voucher' => $voucherNumber, 
            'supplier' => $supplier['name'], 
            'amount' => $amount,
            'account' => "{$acc['code']} - {$acc['name']}"
        ]);
        $pdo->commit();

        setFlashMessage('success', "Payment voucher {$voucherNumber} of Rs. " . number_format($amount, 2) . " disbursed from {$acc['name']} posted successfully!");
        redirect(BASE_URL . "/modules/suppliers/ledger.php?id={$supplierId}");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Payment posting error: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/suppliers/ledger.php?id={$supplierId}");
    }
}

// Date filters
$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate = sanitize($_GET['end_date'] ?? date('Y-m-d'));

// Query all transactions for this supplier:
// 1. Goods Receipts (Credits -> Increase payable)
// 2. Payments (Debits -> Decrease payable)
$transactions = [];

// 1. Goods Receipts
$grnStmt = $pdo->prepare("
    SELECT grn_date as tx_date, grn_number as doc_number, 'Goods Receipt (GRN)' as tx_type,
           CONCAT('Stock Receiving (Bilty #', COALESCE(invoice_no, '-'), ')') as description,
           0.00 as debit, total_amount as credit, created_at
    FROM `goods_receipts`
    WHERE supplier_id = ? AND grn_date BETWEEN ? AND ?
");
$grnStmt->execute([$supplierId, $startDate, $endDate]);
while ($row = $grnStmt->fetch()) {
    $transactions[] = $row;
}

// 2. Supplier Payments
$payStmt = $pdo->prepare("
    SELECT p.payment_date as tx_date, p.payment_number as doc_number, 'Supplier Payment' as tx_type,
           CONCAT('Payment via ', UPPER(p.payment_method_code), 
                  CASE WHEN coa.name IS NOT NULL THEN CONCAT(' [', coa.code, ' - ', coa.name, ']') ELSE '' END,
                  ' (Ref: ', COALESCE(p.reference_no, '-'), ')') as description,
           p.amount as debit, 0.00 as credit, p.created_at
    FROM `payments` p
    LEFT JOIN `chart_of_accounts` coa ON p.payment_account_id = coa.id
    WHERE p.party_type = 'supplier' AND p.party_id = ? AND p.status = 'posted' AND p.payment_date BETWEEN ? AND ?
");
$payStmt->execute([$supplierId, $startDate, $endDate]);
while ($row = $payStmt->fetch()) {
    $transactions[] = $row;
}

usort($transactions, function ($a, $b) {
    return strcmp($a['tx_date'] . $a['created_at'], $b['tx_date'] . $b['created_at']);
});

// Calculate Opening Balance prior to $startDate
$priorGrnStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM `goods_receipts` WHERE supplier_id = ? AND grn_date < ?");
$priorGrnStmt->execute([$supplierId, $startDate]);
$priorGrn = (float)$priorGrnStmt->fetchColumn();

$priorPayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM `payments` WHERE party_type = 'supplier' AND party_id = ? AND status = 'posted' AND payment_date < ?");
$priorPayStmt->execute([$supplierId, $startDate]);
$priorPay = (float)$priorPayStmt->fetchColumn();

$periodOpeningPayable = (float)$supplier['opening_balance'] + $priorGrn - $priorPay;

$runningPayable = $periodOpeningPayable;
$totalPeriodDebit = 0.00;
$totalPeriodCredit = 0.00;

$pageTitle = 'Supplier Statement - ' . $supplier['name'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Document Controls Toolbar (Hidden in Print) -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-bookmark text-primary me-2"></i>Supplier Accounts Payable Statement</h4>
        <small class="text-muted"><?= htmlspecialchars($supplier['name']) ?> (<code><?= htmlspecialchars($supplier['code']) ?></code>) · Hub: <?= htmlspecialchars($supplier['company_name'] ?: 'Direct Supply') ?></small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-success" data-confirm-trigger data-confirm-target="panel-supplier-payment">
            <i class="bi bi-cash-stack me-1"></i> Disburse Payment
        </button>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToCSV('supplierLedgerTable', 'Supplier_<?= $supplier['code'] ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.csv)
        </button>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Statement (A4)
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareSupplierWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('supplierLedgerContainer', 'Supplier_<?= $supplier['code'] ?>');">
            <i class="bi bi-image me-1"></i> Download Image
        </button>
        <a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Suppliers
        </a>
    </div>
</div>

<!-- Inline Payment Disbursement Panel (Strict No-Modal UI) -->
<div id="panel-supplier-payment" class="card border-0 shadow-sm rounded-3 mb-3 d-none d-print-none">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-success"><i class="bi bi-cash me-1"></i>Disburse Payment to Supplier</h6>
        <button type="button" class="btn btn-sm btn-close" data-confirm-cancel data-confirm-target="panel-supplier-payment"></button>
    </div>
    <div class="card-body p-3">
        <form method="POST" action="<?= BASE_URL ?>/modules/suppliers/ledger.php?id=<?= $supplierId ?>">
            <?= getCsrfField() ?>
            <input type="hidden" name="disburse_payment" value="1">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Disbursement Amount (Rs.) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm fw-bold text-success" name="amount" required step="0.01" placeholder="e.g. 50000">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Disbursing Account <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm fw-semibold" name="payment_account_id" id="supp_payment_account" required>
                        <?php foreach ($paymentAccounts as $pAcc): ?>
                            <option value="<?= $pAcc['id'] ?>" data-tag="<?= htmlspecialchars($pAcc['system_tag'] ?? '') ?>" <?= ($pAcc['system_tag'] === 'bank') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pAcc['code']) ?> - <?= htmlspecialchars($pAcc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Payment Method <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="payment_method" id="supp_payment_method" onchange="syncSupplierPaymentAccount(this)">
                        <?php foreach ($paymentMethods as $pm): ?>
                            <option value="<?= $pm['code'] ?>" data-account="<?= $pm['account_id'] ?>" <?= ($pm['code'] === 'bank_transfer') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pm['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Payment Date</label>
                    <input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Cheque / Txn Ref #</label>
                    <input type="text" class="form-control form-control-sm" name="reference_no" placeholder="e.g. CHQ-99182 / TRF-8821">
                </div>
                <div class="col-md-8">
                    <label class="form-label small fw-semibold">Remarks / Payment Memo</label>
                    <input type="text" class="form-control form-control-sm" name="notes" placeholder="e.g. Cleared bill against Invoice # INV-1049">
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> Confirm Payment & Post Journal
                    </button>
                    <button type="button" class="btn btn-light border btn-sm ms-2" data-confirm-cancel data-confirm-target="panel-supplier-payment">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Date Filter Form (Hidden in Print) -->
<div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/suppliers/ledger.php" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?= $supplierId ?>">
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">Statement From</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= $startDate ?>">
            </div>
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= $endDate ?>">
            </div>
            <div class="col-2 col-md-4">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter Statement</button>
            </div>
        </form>
    </div>
</div>

<!-- Printable Supplier Statement Sheet (Minimal Excel High-Density Format) -->
<div id="supplierLedgerContainer" class="bg-white p-2 p-md-3 rounded-2 shadow-sm border mb-4">
    
    <!-- Minimalist Excel Print Header -->
    <div class="excel-meta-box mb-2">
        <table class="table-layout-grid mb-1 border-bottom pb-1" style="width: 100%; table-layout: fixed;">
            <tr>
                <td style="width: 60%; vertical-align: middle;">
                    <span class="fw-bold fs-6 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></span>
                    <span class="text-muted ms-2 small" style="font-size: 0.75rem;">· SUPPLIER ACCOUNTS PAYABLE STATEMENT</span>
                </td>
                <td style="width: 40%; vertical-align: middle; text-align: right; font-size: 0.72rem;">
                    <strong>Period:</strong> <?= formatDate($startDate) ?> to <?= formatDate($endDate) ?> | <strong>Currency:</strong> <?= DEFAULT_CURRENCY ?>
                </td>
            </tr>
        </table>
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed; font-size: 0.72rem; line-height: 1.25;">
            <tr>
                <td style="width: 72%; vertical-align: top;">
                    <strong>Supplier:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($supplier['name']) ?></span> (<code><?= htmlspecialchars($supplier['code']) ?></code>) &nbsp;|&nbsp;
                    <strong>Company / Hub:</strong> <?= htmlspecialchars($supplier['company_name'] ?: $supplier['name']) ?> &nbsp;|&nbsp;
                    <strong>Phone:</strong> <?= htmlspecialchars($supplier['phone']) ?>
                </td>
                <td style="width: 28%; vertical-align: top; text-align: right;">
                    <strong>Payment Terms:</strong> <?= htmlspecialchars($supplier['payment_terms'] ?? 'Net 30') ?> &nbsp;|&nbsp;
                    <strong>Outstanding:</strong> <span class="fw-bold text-danger"><?= formatMoney($supplier['current_balance']) ?></span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Statement Table (High-Density Excel Spreadsheet Style) -->
    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="supplierLedgerTable" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 10%;">Date</th>
                    <th style="width: 16%;">Doc #</th>
                    <th style="width: 15%;">Type</th>
                    <th style="width: 31%;">Particulars / Receiving Details</th>
                    <th class="text-end" style="width: 9%;">Debit (Paid)</th>
                    <th class="text-end" style="width: 9%;">Credit (Received)</th>
                    <th class="text-end" style="width: 10%;">Payable (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr style="background-color: #fef9c3; font-weight: 700;">
                    <td class="py-1"><?= formatDate($startDate) ?></td>
                    <td class="py-1"><strong>OPENING</strong></td>
                    <td class="py-1">Opening Balance</td>
                    <td class="py-1">Brought forward payable prior to <?= formatDate($startDate) ?></td>
                    <td class="num-cell py-1">-</td>
                    <td class="num-cell py-1">-</td>
                    <td class="num-cell fw-bold text-dark py-1"><?= number_format($periodOpeningPayable, 2) ?></td>
                </tr>

                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-3 text-muted fst-italic">No transactions recorded for this supplier in the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <?php
                        $d = (float)$tx['debit'];
                        $c = (float)$tx['credit'];
                        $runningPayable += ($c - $d);
                        $totalPeriodDebit += $d;
                        $totalPeriodCredit += $c;
                        ?>
                        <tr>
                            <td class="py-1"><?= formatDate($tx['tx_date']) ?></td>
                            <td class="py-1 fw-semibold"><code><?= htmlspecialchars($tx['doc_number']) ?></code></td>
                            <td class="py-1"><?= htmlspecialchars($tx['tx_type']) ?></td>
                            <td class="py-1"><?= htmlspecialchars($tx['description']) ?></td>
                            <td class="num-cell py-1 <?= $d > 0 ? 'text-success fw-bold' : 'text-muted' ?>">
                                <?= $d > 0 ? number_format($d, 2) : '-' ?>
                            </td>
                            <td class="num-cell py-1 <?= $c > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= $c > 0 ? number_format($c, 2) : '-' ?>
                            </td>
                            <td class="num-cell py-1 fw-bold text-dark">
                                <?= number_format($runningPayable, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f1f5f9; font-weight: 700;">
                    <td colspan="4" class="text-end text-dark py-1">PERIOD ACTIVITY & CLOSING BALANCE:</td>
                    <td class="num-cell text-success py-1"><?= number_format($totalPeriodDebit, 2) ?></td>
                    <td class="num-cell text-danger py-1"><?= number_format($totalPeriodCredit, 2) ?></td>
                    <td class="num-cell text-primary py-1 fs-6"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($runningPayable, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Written Statement Terms (Configurable from System Settings) -->
    <?php
    $suppTerms = getSetting($pdo, 'supplier_ledger_terms', 'All goods receipts (GRNs), payments, and credit adjustments are subject to final store audit and warehouse physical verification. Statement accounts payable subject to agreed supplier payment terms.');
    ?>
    <div class="mt-2 pt-1 border-top text-muted" style="font-size: 0.68rem; line-height: 1.3;">
        <strong>STATEMENT TERMS:</strong> <?= htmlspecialchars($suppTerms) ?>
    </div>

    <!-- Minimal Excel Verification Signatures (Visible on Print) -->
    <div class="d-none d-print-block mt-3 pt-2 border-top">
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed; font-size: 0.7rem;">
            <tr>
                <td style="width: 33.33%; vertical-align: top;">
                    <div>Prepared By: ___________________</div>
                    <small class="text-muted">Procurement & Accounts</small>
                </td>
                <td style="width: 33.33%; vertical-align: top; text-align: center;">
                    <div>Checked & Verified: ___________________</div>
                    <small class="text-muted">Finance Controller</small>
                </td>
                <td style="width: 33.34%; vertical-align: top; text-align: right;">
                    <div>Authorized Signatory: ___________________</div>
                    <small class="text-muted">Company Stamp</small>
                </td>
            </tr>
        </table>
    </div>
</div>

<script>
function syncSupplierPaymentAccount(sel) {
    const selectedOpt = sel.options[sel.selectedIndex];
    const defaultAccId = selectedOpt.getAttribute('data-account');
    const accSelect = document.getElementById('supp_payment_account');
    if (defaultAccId && accSelect) {
        for (let i = 0; i < accSelect.options.length; i++) {
            if (accSelect.options[i].value === defaultAccId) {
                accSelect.selectedIndex = i;
                break;
            }
        }
    }
}

function shareSupplierWhatsApp() {
    const supp = "<?= addslashes(htmlspecialchars($supplier['name'])) ?>";
    const startDate = "<?= formatDate($startDate) ?>";
    const endDate = "<?= formatDate($endDate) ?>";
    const closing = "<?= number_format($runningPayable, 2) ?>";
    const debits = "<?= number_format($totalPeriodDebit, 2) ?>";
    const credits = "<?= number_format($totalPeriodCredit, 2) ?>";
    const targetPhone = "<?= htmlspecialchars($supplier['phone']) ?>";

    let text = `*Falcon FMCG Distribution - Supplier Statement*\n`;
    text += `Supplier: *${supp}*\n`;
    text += `Period: ${startDate} to ${endDate}\n`;
    text += `Total Disbursed (Debit): Rs. ${debits}\n`;
    text += `Total Goods Received (Credit): Rs. ${credits}\n`;
    text += `*Closing Balance Payable: Rs. ${closing}*\n`;
    text += `------------------------------------\n`;
    text += `Falcon FMCG Central Distribution Gujranwala`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
