<?php
/**
 * Customer Ledger Statement & Financial History
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('customers', 'view');

$customerId = (int)($_GET['id'] ?? 0);
if (!$customerId) {
    setFlashMessage('error', 'Invalid customer specified.');
    redirect(BASE_URL . '/modules/customers/index.php');
}

$pdo = getDatabaseConnection();

// Fetch Customer info
$cStmt = $pdo->prepare("
    SELECT c.*, a.name as area_name, e.full_name as booker_name
    FROM `customers` c
    JOIN `areas` a ON c.area_id = a.id
    LEFT JOIN `employees` e ON c.assigned_order_booker_id = e.id
    WHERE c.id = ?
");
$cStmt->execute([$customerId]);
$customer = $cStmt->fetch();

if (!$customer) {
    setFlashMessage('error', 'Customer not found.');
    redirect(BASE_URL . '/modules/customers/index.php');
}

// Date filters
$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate = sanitize($_GET['end_date'] ?? date('Y-m-d'));

// Query all financial transactions for this customer:
$transactions = [];

// 1. Invoices
$invStmt = $pdo->prepare("
    SELECT si.invoice_date as tx_date, si.invoice_number as doc_number, 'Sales Invoice' as tx_type,
           CONCAT('Invoice against Order #', so.order_number) as description,
           si.grand_total as debit, 0.00 as credit, si.created_at
    FROM `sales_invoices` si
    JOIN `sales_orders` so ON si.sales_order_id = so.id
    WHERE si.customer_id = ? AND si.status != 'voided' AND si.invoice_date BETWEEN ? AND ?
");
$invStmt->execute([$customerId, $startDate, $endDate]);
while ($row = $invStmt->fetch()) {
    $transactions[] = $row;
}

// 2. Payments
$payStmt = $pdo->prepare("
    SELECT payment_date as tx_date, payment_number as doc_number, 'Payment Receipt' as tx_type,
           CONCAT('Payment via ', UPPER(payment_method_code), ' (Ref: ', COALESCE(reference_no, '-'), ')') as description,
           0.00 as debit, amount as credit, created_at
    FROM `payments`
    WHERE party_type = 'customer' AND party_id = ? AND status = 'posted' AND payment_date BETWEEN ? AND ?
");
$payStmt->execute([$customerId, $startDate, $endDate]);
while ($row = $payStmt->fetch()) {
    $transactions[] = $row;
}

// 3. Sales Returns
$retStmt = $pdo->prepare("
    SELECT return_date as tx_date, return_number as doc_number, 'Sales Return' as tx_type,
           CONCAT('Credit Note: ', COALESCE(reason, 'Product Return')) as description,
           0.00 as debit, total_amount as credit, created_at
    FROM `sales_returns`
    WHERE customer_id = ? AND status = 'approved' AND return_date BETWEEN ? AND ?
");
$retStmt->execute([$customerId, $startDate, $endDate]);
while ($row = $retStmt->fetch()) {
    $transactions[] = $row;
}

// Sort transactions chronologically
usort($transactions, function ($a, $b) {
    return strcmp($a['tx_date'] . $a['created_at'], $b['tx_date'] . $b['created_at']);
});

// Calculate Opening Balance prior to $startDate
$dStmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM `sales_invoices` WHERE customer_id = ? AND status != 'voided' AND invoice_date < ?");
$dStmt->execute([$customerId, $startDate]);
$priorInvoices = (float)$dStmt->fetchColumn();

$cStmt2 = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM `payments` WHERE party_type = 'customer' AND party_id = ? AND status = 'posted' AND payment_date < ?");
$cStmt2->execute([$customerId, $startDate]);
$priorPayments = (float)$cStmt2->fetchColumn();

$rStmt2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM `sales_returns` WHERE customer_id = ? AND status = 'approved' AND return_date < ?");
$rStmt2->execute([$customerId, $startDate]);
$priorReturns = (float)$rStmt2->fetchColumn();

$periodOpeningBalance = (float)$customer['opening_balance'] + $priorInvoices - $priorPayments - $priorReturns;

$runningBalance = $periodOpeningBalance;
$totalPeriodDebit = 0.00;
$totalPeriodCredit = 0.00;

$pageTitle = 'Ledger - ' . $customer['shop_name'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
$company = getCompanyInfo($pdo);
?>

<!-- Document Action Toolbar (Hidden in Print) -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-text text-primary me-2"></i>Customer Financial Statement</h4>
        <small class="text-muted"><?= htmlspecialchars($customer['shop_name']) ?> (<?= htmlspecialchars($customer['code']) ?>) · Area: <?= htmlspecialchars($customer['area_name']) ?></small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-success" onclick="exportTableToCSV('ledgerTable', 'Ledger_<?= $customer['code'] ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.csv)
        </button>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Statement (A4)
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareLedgerWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('ledgerContainer', 'Ledger_<?= $customer['code'] ?>');">
            <i class="bi bi-image me-1"></i> Download Image
        </button>
        <a href="<?= BASE_URL ?>/modules/customers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Customers List
        </a>
    </div>
</div>

<!-- Date Filter Form (Hidden in Print) -->
<div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/customers/ledger.php" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?= $customerId ?>">
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">Statement From</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= $startDate ?>">
            </div>
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= $endDate ?>">
            </div>
            <div class="col-2 col-md-4">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter Ledger</button>
            </div>
        </form>
    </div>
</div>

<!-- Printable Ledger Sheet (Minimal Excel High-Density Format) -->
<div id="ledgerContainer" class="bg-white p-2 p-md-3 rounded-2 shadow-sm border mb-4">
    
    <!-- Minimalist Excel Print Header -->
    <div class="excel-meta-box mb-2">
        <table class="table-layout-grid mb-1 border-bottom pb-1" style="width: 100%; table-layout: fixed;">
            <tr>
                <td style="width: 60%; vertical-align: middle;">
                    <span class="fw-bold fs-6 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></span>
                    <span class="text-muted ms-2 small" style="font-size: 0.75rem;">· CUSTOMER STATEMENT OF ACCOUNT (LEDGER)</span>
                </td>
                <td style="width: 40%; vertical-align: middle; text-align: right; font-size: 0.72rem;">
                    <strong>Period:</strong> <?= formatDate($startDate) ?> to <?= formatDate($endDate) ?> | <strong>Currency:</strong> <?= DEFAULT_CURRENCY ?>
                </td>
            </tr>
        </table>
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed; font-size: 0.72rem; line-height: 1.25;">
            <tr>
                <td style="width: 72%; vertical-align: top;">
                    <strong>Shop / Customer:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($customer['shop_name']) ?></span> (<code><?= htmlspecialchars($customer['code']) ?></code>) &nbsp;|&nbsp;
                    <strong>Owner:</strong> <?= htmlspecialchars($customer['owner_name']) ?> (<?= htmlspecialchars($customer['phone']) ?>) &nbsp;|&nbsp;
                    <strong>Area:</strong> <?= htmlspecialchars($customer['area_name']) ?> (Booker: <?= htmlspecialchars($customer['booker_name'] ?? 'Direct') ?>)
                </td>
                <td style="width: 28%; vertical-align: top; text-align: right;">
                    <strong>Credit Terms:</strong> <?= htmlspecialchars($customer['payment_terms'] ?: 'Net 15') ?> &nbsp;|&nbsp;
                    <strong>Outstanding:</strong> <span class="fw-bold <?= $customer['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= formatMoney($customer['current_balance']) ?></span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Ledger Transactions Table (High-Density Excel Spreadsheet Style) -->
    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="ledgerTable" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 10%;">Date</th>
                    <th style="width: 15%;">Doc #</th>
                    <th style="width: 14%;">Type</th>
                    <th style="width: 33%;">Particulars / Transaction Details</th>
                    <th class="text-end" style="width: 9%;">Debit (PKR)</th>
                    <th class="text-end" style="width: 9%;">Credit (PKR)</th>
                    <th class="text-end" style="width: 10%;">Balance (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr style="background-color: #fef9c3; font-weight: 700;">
                    <td class="py-1"><?= formatDate($startDate) ?></td>
                    <td class="py-1"><strong>OPENING</strong></td>
                    <td class="py-1">Opening Balance</td>
                    <td class="py-1">Brought forward balance prior to <?= formatDate($startDate) ?></td>
                    <td class="num-cell py-1">-</td>
                    <td class="num-cell py-1">-</td>
                    <td class="num-cell fw-bold text-dark py-1"><?= number_format($periodOpeningBalance, 2) ?></td>
                </tr>

                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-3 text-muted fst-italic">No financial transactions recorded within this date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <?php
                        $d = (float)$tx['debit'];
                        $c = (float)$tx['credit'];
                        $runningBalance += ($d - $c);
                        $totalPeriodDebit += $d;
                        $totalPeriodCredit += $c;
                        ?>
                        <tr>
                            <td class="py-1"><?= formatDate($tx['tx_date']) ?></td>
                            <td class="py-1 fw-semibold"><code><?= htmlspecialchars($tx['doc_number']) ?></code></td>
                            <td class="py-1"><?= htmlspecialchars($tx['tx_type']) ?></td>
                            <td class="py-1"><?= htmlspecialchars($tx['description']) ?></td>
                            <td class="num-cell py-1 <?= $d > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= $d > 0 ? number_format($d, 2) : '-' ?>
                            </td>
                            <td class="num-cell py-1 <?= $c > 0 ? 'text-success fw-bold' : 'text-muted' ?>">
                                <?= $c > 0 ? number_format($c, 2) : '-' ?>
                            </td>
                            <td class="num-cell py-1 fw-bold text-dark">
                                <?= number_format($runningBalance, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f1f5f9; font-weight: 700;">
                    <td colspan="4" class="text-end text-dark py-1">PERIOD ACTIVITY & CLOSING BALANCE:</td>
                    <td class="num-cell text-danger py-1"><?= number_format($totalPeriodDebit, 2) ?></td>
                    <td class="num-cell text-success py-1"><?= number_format($totalPeriodCredit, 2) ?></td>
                    <td class="num-cell text-primary py-1 fs-6"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($runningBalance, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Written Statement Terms (Configurable from System Settings) -->
    <?php
    $custTerms = getSetting($pdo, 'customer_ledger_terms', 'This financial statement reflects all invoiced sales, payments, and returns posted up to the printed date. Any discrepancy or reconciliation dispute must be reported in writing within 7 days.');
    ?>
    <div class="mt-2 pt-1 border-top text-muted" style="font-size: 0.68rem; line-height: 1.3;">
        <strong>STATEMENT TERMS:</strong> <?= htmlspecialchars($custTerms) ?>
    </div>

    <!-- Minimal Excel Verification Signatures (Visible on Print) -->
    <div class="d-none d-print-block mt-3 pt-2 border-top">
        <table class="table-layout-grid mb-0" style="width: 100%; table-layout: fixed; font-size: 0.7rem;">
            <tr>
                <td style="width: 33.33%; vertical-align: top;">
                    <div>Prepared By: ___________________</div>
                    <small class="text-muted">Accounts Department</small>
                </td>
                <td style="width: 33.33%; vertical-align: top; text-align: center;">
                    <div>Checked & Verified: ___________________</div>
                    <small class="text-muted">Internal Audit</small>
                </td>
                <td style="width: 33.34%; vertical-align: top; text-align: right;">
                    <div>Customer Acknowledgment: ___________________</div>
                    <small class="text-muted">Signature & Stamp</small>
                </td>
            </tr>
        </table>
    </div>
</div>

<script>
function shareLedgerWhatsApp() {
    const shop = "<?= addslashes(htmlspecialchars($customer['shop_name'])) ?>";
    const startDate = "<?= formatDate($startDate) ?>";
    const endDate = "<?= formatDate($endDate) ?>";
    const closing = "<?= number_format($runningBalance, 2) ?>";
    const debits = "<?= number_format($totalPeriodDebit, 2) ?>";
    const credits = "<?= number_format($totalPeriodCredit, 2) ?>";
    const targetPhone = "<?= htmlspecialchars($customer['whatsapp'] ?: $customer['phone']) ?>";

    let text = `*Falcon FMCG Distribution - Customer Statement*\n`;
    text += `Customer: *${shop}*\n`;
    text += `Period: ${startDate} to ${endDate}\n`;
    text += `Total Invoices (Debit): Rs. ${debits}\n`;
    text += `Total Payments (Credit): Rs. ${credits}\n`;
    text += `*Net Outstanding Balance: Rs. ${closing}*\n`;
    text += `------------------------------------\n`;
    text += `Please review and arrange payment as per agreed terms.\n`;
    text += `Thank you!`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
