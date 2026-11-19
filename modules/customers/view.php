<?php
/**
 * Customer 360° Comprehensive Profile & Financial Dashboard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('customers', 'view');

$customerId = (int)($_GET['id'] ?? ($_GET['customer_id'] ?? 0));
if (!$customerId) {
    setFlashMessage('error', 'Invalid customer identifier.');
    redirect(BASE_URL . '/modules/customers/index.php');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

// Fetch Customer Master Details
$cStmt = $pdo->prepare("
    SELECT c.*, a.name as area_name, e.full_name as booker_name, e.phone as booker_phone
    FROM customers c
    LEFT JOIN areas a ON c.area_id = a.id
    LEFT JOIN employees e ON c.assigned_order_booker_id = e.id
    WHERE c.id = ?
");
$cStmt->execute([$customerId]);
$customer = $cStmt->fetch();

if (!$customer) {
    setFlashMessage('error', 'Customer not found.');
    redirect(BASE_URL . '/modules/customers/index.php');
}

// 1. Fetch Sales Orders / Bills History
$ordersStmt = $pdo->prepare("
    SELECT so.*, e.full_name as booker_name,
           (SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = so.id) as item_count,
           (SELECT COALESCE(SUM(quantity), 0) FROM sales_order_items WHERE sales_order_id = so.id) as total_units
    FROM sales_orders so
    LEFT JOIN employees e ON so.order_booker_id = e.id
    WHERE so.customer_id = ?
    ORDER BY so.order_date DESC, so.id DESC
    LIMIT 100
");
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll();

// 2. Fetch Payment Receipts History
$paymentsStmt = $pdo->prepare("
    SELECT p.*, emp.full_name as collector_name, coa.name as account_name
    FROM payments p
    LEFT JOIN employees emp ON p.collected_by = emp.id
    LEFT JOIN chart_of_accounts coa ON p.payment_account_id = coa.id
    WHERE p.party_type = 'customer' AND p.party_id = ? AND p.status = 'posted'
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 100
");
$paymentsStmt->execute([$customerId]);
$payments = $paymentsStmt->fetchAll();

// 3. Fetch Returns & Credit Notes
$returnsStmt = $pdo->prepare("
    SELECT sr.*, emp.full_name as rider_name,
           (SELECT cn.credit_note_number FROM credit_notes cn WHERE cn.sales_return_id = sr.id LIMIT 1) as credit_note_number
    FROM sales_returns sr
    LEFT JOIN employees emp ON sr.delivery_man_id = emp.id
    WHERE sr.customer_id = ?
    ORDER BY sr.return_date DESC, sr.id DESC
    LIMIT 50
");
$returnsStmt->execute([$customerId]);
$returns = $returnsStmt->fetchAll();

// 4. Ledger Snapshot (Recent 30 transactions)
$ledgerItems = [];
$invStmt = $pdo->prepare("
    SELECT si.invoice_date as tx_date, si.invoice_number as doc_number, 'Sales Invoice' as tx_type,
           CONCAT('Bill against Order #', so.order_number) as memo,
           si.grand_total as debit, 0.00 as credit, si.created_at
    FROM sales_invoices si
    JOIN sales_orders so ON si.sales_order_id = so.id
    WHERE si.customer_id = ? AND si.status != 'voided'
");
$invStmt->execute([$customerId]);
while ($r = $invStmt->fetch()) { $ledgerItems[] = $r; }

foreach ($payments as $p) {
    $ledgerItems[] = [
        'tx_date' => $p['payment_date'],
        'doc_number' => $p['payment_number'],
        'tx_type' => 'Payment Receipt',
        'memo' => 'Payment via ' . strtoupper($p['payment_method_code']) . ' (Ref: ' . ($p['reference_no'] ?: '-') . ')',
        'debit' => 0.00,
        'credit' => (float)$p['amount'],
        'created_at' => $p['created_at']
    ];
}

foreach ($returns as $sr) {
    $ledgerItems[] = [
        'tx_date' => $sr['return_date'],
        'doc_number' => $sr['return_number'],
        'tx_type' => 'Sales Return',
        'memo' => 'Return: ' . ($sr['reason'] ?: 'Product return / credit note'),
        'debit' => 0.00,
        'credit' => (float)$sr['total_amount'],
        'created_at' => $sr['created_at']
    ];
}

usort($ledgerItems, function ($a, $b) {
    return strcmp($b['tx_date'] . $b['created_at'], $a['tx_date'] . $a['created_at']);
});
$recentLedger = array_slice($ledgerItems, 0, 25);

// Statistics
$totalOrdersCount = count($orders);
$totalPurchasesVal = 0.00;
foreach ($orders as $o) {
    if (!in_array($o['status'], ['rejected', 'cancelled', 'draft'])) {
        $totalPurchasesVal += (float)$o['grand_total'];
    }
}
$totalPaidVal = 0.00;
foreach ($payments as $p) {
    $totalPaidVal += (float)$p['amount'];
}
$currentBalance = (float)$customer['current_balance'];
$creditLimit = (float)$customer['credit_limit'];

$pageTitle = htmlspecialchars($customer['shop_name']) . ' - Customer 360° Profile';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Customer Header Card -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 58px; height: 58px; font-size: 1.5rem; font-weight: bold;">
                    <i class="bi bi-shop"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($customer['shop_name']) ?></h4>
                        <span class="badge bg-secondary"><?= htmlspecialchars($customer['code']) ?></span>
                        <span class="badge bg-light text-dark border"><?= ucfirst($customer['customer_type'] ?? 'retailer') ?></span>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-person me-1"></i>Owner: <strong><?= htmlspecialchars($customer['owner_name'] ?: 'N/A') ?></strong> &middot; 
                        <i class="bi bi-geo-alt me-1"></i>Area: <strong><?= htmlspecialchars($customer['area_name'] ?? 'Unassigned') ?></strong> &middot; 
                        <i class="bi bi-person-badge me-1"></i>Booker: <strong><?= htmlspecialchars($customer['booker_name'] ?? 'Direct') ?></strong>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-pin-map me-1"></i>Address: <?= htmlspecialchars($customer['address'] ?: 'Not recorded') ?>
                    </div>
                </div>
            </div>

            <!-- Outstanding Balance Badge -->
            <div class="d-flex flex-column align-items-lg-end bg-light p-3 rounded border">
                <span class="text-muted small fw-semibold">Current Outstanding Balance</span>
                <h3 class="fw-bold mb-0 <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>">
                    Rs. <?= number_format($currentBalance, 2) ?>
                </h3>
                <small class="text-muted">
                    Credit Limit: Rs. <?= number_format($creditLimit, 2) ?>
                    <?php if ($creditLimit > 0 && $currentBalance > $creditLimit): ?>
                        <span class="badge bg-danger ms-1">Limit Exceeded</span>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <hr class="my-3">

        <!-- Action Quick-Bar -->
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php?area_id=<?= (int)$customer['area_id'] ?>&customer_id=<?= $customerId ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-cart-plus me-1"></i> Quick Order Booking
                </a>
                <a href="<?= BASE_URL ?>/modules/payments/recovery.php?customer_id=<?= $customerId ?>" class="btn btn-sm btn-warning text-dark fw-bold">
                    <i class="bi bi-cash-coin me-1"></i> Receive Payment
                </a>
                <a href="<?= BASE_URL ?>/modules/customers/ledger.php?id=<?= $customerId ?>" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-journal-text me-1"></i> Full Ledger Statement
                </a>
                <?php if (!empty($customer['whatsapp']) || !empty($customer['phone'])): 
                    $rawPhone = preg_replace('/[^0-9]/', '', $customer['whatsapp'] ?: $customer['phone']);
                    if (str_starts_with($rawPhone, '03')) { $rawPhone = '92' . substr($rawPhone, 1); }
                ?>
                    <a href="https://wa.me/<?= $rawPhone ?>" target="_blank" class="btn btn-sm btn-success">
                        <i class="bi bi-whatsapp me-1"></i> WhatsApp Shop
                    </a>
                <?php endif; ?>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/customers/create.php?id=<?= $customerId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i> Edit Profile
                </a>
                <a href="<?= BASE_URL ?>/modules/customers/index.php" class="btn btn-sm btn-secondary">
                    &larr; Back to Customers
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-3" id="customerTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-bold" id="tab-orders-btn" data-bs-toggle="pill" data-bs-target="#tab-orders" type="button" role="tab">
            <i class="bi bi-receipt me-1"></i> Sales Bills &amp; Invoices (<?= $totalOrdersCount ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-ledger-btn" data-bs-toggle="pill" data-bs-target="#tab-ledger" type="button" role="tab">
            <i class="bi bi-journal-check me-1"></i> Financial Statement &amp; Ledger
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-payments-btn" data-bs-toggle="pill" data-bs-target="#tab-payments" type="button" role="tab">
            <i class="bi bi-wallet-fill me-1"></i> Recoveries &amp; Receipts (<?= count($payments) ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-returns-btn" data-bs-toggle="pill" data-bs-target="#tab-returns" type="button" role="tab">
            <i class="bi bi-arrow-return-left me-1"></i> Returns &amp; Credit Notes (<?= count($returns) ?>)
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="customerTabsContent">

    <!-- Tab 1: Sales Orders & Bills -->
    <div class="tab-pane fade show active" id="tab-orders" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Bill / Order #</th>
                                <th>Order Date</th>
                                <th>Booker</th>
                                <th class="text-center">Items</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Print &amp; View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No sales orders found for this customer.</td>
                                </tr>
                            <?php else: foreach ($orders as $ord): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $ord['id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($ord['order_number']) ?>
                                        </a>
                                    </td>
                                    <td><?= date('d M Y', strtotime($ord['order_date'])) ?></td>
                                    <td><?= htmlspecialchars($ord['booker_name'] ?? 'Direct') ?></td>
                                    <td class="text-center"><?= $ord['item_count'] ?> SKUs (<?= (int)$ord['total_units'] ?> units)</td>
                                    <td class="text-end fw-bold">Rs. <?= number_format((float)$ord['grand_total'], 2) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= ucfirst($ord['status']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill_compact.php?id=<?= $ord['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="Print 2-per-page Compact Bill">
                                            <i class="bi bi-printer me-1"></i> Compact Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $ord['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Full Standard Invoice">
                                            <i class="bi bi-file-earmark-text"></i> Invoice
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Financial Statement & Ledger -->
    <div class="tab-pane fade" id="tab-ledger" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark">Recent Ledger Transactions</span>
                <a href="<?= BASE_URL ?>/modules/customers/ledger.php?id=<?= $customerId ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-printer me-1"></i> Open &amp; Print Full Statement
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Doc Number</th>
                                <th>Type</th>
                                <th>Description / Memo</th>
                                <th class="text-end">Debit (Rs.)</th>
                                <th class="text-end">Credit (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLedger)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No transactions recorded yet.</td>
                                </tr>
                            <?php else: foreach ($recentLedger as $tx): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($tx['tx_date'])) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($tx['doc_number']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($tx['tx_type']) ?></span>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars($tx['memo']) ?></td>
                                    <td class="text-end fw-semibold text-danger">
                                        <?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '-' ?>
                                    </td>
                                    <td class="text-end fw-semibold text-success">
                                        <?= $tx['credit'] > 0 ? number_format($tx['credit'], 2) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 3: Payment Recoveries -->
    <div class="tab-pane fade" id="tab-payments" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Receipt #</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Collected By</th>
                                <th>Reference</th>
                                <th class="text-end">Amount (Rs.)</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No payment recoveries logged.</td>
                                </tr>
                            <?php else: foreach ($payments as $pay): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($pay['payment_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                    <td><span class="badge bg-info-subtle text-info border"><?= strtoupper($pay['payment_method_code']) ?></span></td>
                                    <td><?= htmlspecialchars($pay['collector_name'] ?? 'Direct Cashier') ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($pay['reference_no'] ?: '-') ?></td>
                                    <td class="text-end fw-bold text-success">Rs. <?= number_format((float)$pay['amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge bg-success"><?= ucfirst($pay['status']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 4: Sales Returns -->
    <div class="tab-pane fade" id="tab-returns" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Return #</th>
                                <th>Credit Note #</th>
                                <th>Return Date</th>
                                <th>Rider / Received By</th>
                                <th>Reason</th>
                                <th class="text-end">Amount (Rs.)</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No product returns recorded for this customer.</td>
                                </tr>
                            <?php else: foreach ($returns as $ret): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($ret['return_number']) ?></td>
                                    <td class="text-primary font-monospace"><?= htmlspecialchars($ret['credit_note_number'] ?: '-') ?></td>
                                    <td><?= date('d M Y', strtotime($ret['return_date'])) ?></td>
                                    <td><?= htmlspecialchars($ret['rider_name'] ?? 'Warehouse') ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($ret['reason'] ?: 'Damaged / expired / trade return') ?></td>
                                    <td class="text-end fw-bold text-danger">Rs. <?= number_format((float)$ret['total_amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?= ucfirst($ret['status']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
