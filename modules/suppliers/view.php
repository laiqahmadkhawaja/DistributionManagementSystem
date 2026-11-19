<?php
/**
 * Supplier 360° Comprehensive Profile & Financial Dashboard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('suppliers', 'view');

$supplierId = (int)($_GET['id'] ?? ($_GET['supplier_id'] ?? 0));
if (!$supplierId) {
    setFlashMessage('error', 'Invalid supplier identifier.');
    redirect(BASE_URL . '/modules/suppliers/index.php');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

// Fetch Supplier Master Details
$sStmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
$sStmt->execute([$supplierId]);
$supplier = $sStmt->fetch();

if (!$supplier) {
    setFlashMessage('error', 'Supplier not found.');
    redirect(BASE_URL . '/modules/suppliers/index.php');
}

// 1. Fetch Purchase Orders History
$poStmt = $pdo->prepare("
    SELECT po.*,
           (SELECT COUNT(*) FROM purchase_items WHERE purchase_order_id = po.id) as item_count,
           (SELECT COALESCE(SUM(order_qty), 0) FROM purchase_items WHERE purchase_order_id = po.id) as total_units
    FROM purchase_orders po
    WHERE po.supplier_id = ?
    ORDER BY po.po_date DESC, po.id DESC
    LIMIT 100
");
$poStmt->execute([$supplierId]);
$purchaseOrders = $poStmt->fetchAll();

// 2. Fetch Supplier Payments History
$paymentsStmt = $pdo->prepare("
    SELECT p.*, coa.name as account_name
    FROM payments p
    LEFT JOIN chart_of_accounts coa ON p.payment_account_id = coa.id
    WHERE p.party_type = 'supplier' AND p.party_id = ? AND p.status = 'posted'
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 100
");
$paymentsStmt->execute([$supplierId]);
$payments = $paymentsStmt->fetchAll();

// 3. Fetch Purchase Returns History
$returnsStmt = $pdo->prepare("
    SELECT pr.*
    FROM purchase_returns pr
    WHERE pr.supplier_id = ?
    ORDER BY pr.return_date DESC, pr.id DESC
    LIMIT 50
");
$returnsStmt->execute([$supplierId]);
$returns = $returnsStmt->fetchAll();

// 4. Ledger Snapshot (Recent 30 transactions)
$ledgerItems = [];
foreach ($purchaseOrders as $po) {
    if (!in_array($po['status'], ['draft', 'cancelled'])) {
        $ledgerItems[] = [
            'tx_date' => $po['po_date'],
            'doc_number' => $po['po_number'],
            'tx_type' => 'Goods Receipt / PO',
            'memo' => 'Purchase of inventory (PO #' . $po['po_number'] . ')',
            'debit' => 0.00,
            'credit' => (float)$po['grand_total'],
            'created_at' => $po['created_at']
        ];
    }
}

foreach ($payments as $p) {
    $ledgerItems[] = [
        'tx_date' => $p['payment_date'],
        'doc_number' => $p['payment_number'],
        'tx_type' => 'Payment Disbursed',
        'memo' => 'Disbursed via ' . strtoupper($p['payment_method_code']) . ' [' . ($p['account_name'] ?: 'Cash/Bank') . '] (Ref: ' . ($p['reference_no'] ?: '-') . ')',
        'debit' => (float)$p['amount'],
        'credit' => 0.00,
        'created_at' => $p['created_at']
    ];
}

foreach ($returns as $pr) {
    $ledgerItems[] = [
        'tx_date' => $pr['return_date'],
        'doc_number' => $pr['return_number'],
        'tx_type' => 'Purchase Return',
        'memo' => 'Supplier Return: ' . ($pr['reason'] ?: 'Stock returned to vendor'),
        'debit' => (float)$pr['total_amount'],
        'credit' => 0.00,
        'created_at' => $pr['created_at']
    ];
}

usort($ledgerItems, function ($a, $b) {
    return strcmp($b['tx_date'] . $b['created_at'], $a['tx_date'] . $a['created_at']);
});
$recentLedger = array_slice($ledgerItems, 0, 25);

$currentBalance = (float)$supplier['current_balance'];
$creditLimit = (float)$supplier['credit_limit'];

$pageTitle = htmlspecialchars($supplier['name']) . ' - Supplier 360° Profile';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Supplier Header Card -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning-subtle text-dark d-flex align-items-center justify-content-center" style="width: 58px; height: 58px; font-size: 1.5rem; font-weight: bold;">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($supplier['name']) ?></h4>
                        <span class="badge bg-secondary"><?= htmlspecialchars($supplier['code']) ?></span>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($supplier['company_name'] ?: 'Vendor') ?></span>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-person me-1"></i>Contact Person: <strong><?= htmlspecialchars($supplier['contact_person'] ?: 'N/A') ?></strong> &middot; 
                        <i class="bi bi-geo-alt me-1"></i>City/Area: <strong><?= htmlspecialchars($supplier['city'] ?? $supplier['area'] ?? 'N/A') ?></strong> &middot; 
                        <i class="bi bi-file-earmark-text me-1"></i>NTN/STRN: <strong><?= htmlspecialchars($supplier['ntn_strn'] ?: 'Unregistered') ?></strong>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-pin-map me-1"></i>Address: <?= htmlspecialchars($supplier['address'] ?: 'Not recorded') ?>
                    </div>
                </div>
            </div>

            <!-- Outstanding Payable Balance Badge -->
            <div class="d-flex flex-column align-items-lg-end bg-light p-3 rounded border">
                <span class="text-muted small fw-semibold">Outstanding Balance Payable</span>
                <h3 class="fw-bold mb-0 <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>">
                    Rs. <?= number_format($currentBalance, 2) ?>
                </h3>
                <small class="text-muted">
                    Payment Terms: <?= htmlspecialchars($supplier['payment_terms'] ?: 'Standard') ?>
                </small>
            </div>
        </div>

        <hr class="my-3">

        <!-- Action Quick-Bar -->
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>/modules/purchases/create.php?supplier_id=<?= $supplierId ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-cart-check me-1"></i> New Purchase Order
                </a>
                <a href="<?= BASE_URL ?>/modules/suppliers/ledger.php?id=<?= $supplierId ?>" class="btn btn-sm btn-warning text-dark fw-bold">
                    <i class="bi bi-cash-stack me-1"></i> Disburse Payment
                </a>
                <a href="<?= BASE_URL ?>/modules/suppliers/ledger.php?id=<?= $supplierId ?>" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-journal-text me-1"></i> Full Ledger Statement
                </a>
                <?php if (!empty($supplier['whatsapp']) || !empty($supplier['phone'])): 
                    $rawPhone = preg_replace('/[^0-9]/', '', $supplier['whatsapp'] ?: $supplier['phone']);
                    if (str_starts_with($rawPhone, '03')) { $rawPhone = '92' . substr($rawPhone, 1); }
                ?>
                    <a href="https://wa.me/<?= $rawPhone ?>" target="_blank" class="btn btn-sm btn-success">
                        <i class="bi bi-whatsapp me-1"></i> WhatsApp Vendor
                    </a>
                <?php endif; ?>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/suppliers/create.php?id=<?= $supplierId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i> Edit Profile
                </a>
                <a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="btn btn-sm btn-secondary">
                    &larr; Back to Suppliers
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-3" id="supplierTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-bold" id="tab-po-btn" data-bs-toggle="pill" data-bs-target="#tab-po" type="button" role="tab">
            <i class="bi bi-box-arrow-in-down me-1"></i> Purchase Orders &amp; GRNs (<?= count($purchaseOrders) ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-ledger-btn" data-bs-toggle="pill" data-bs-target="#tab-ledger" type="button" role="tab">
            <i class="bi bi-journal-check me-1"></i> Financial Statement &amp; Ledger
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-payments-btn" data-bs-toggle="pill" data-bs-target="#tab-payments" type="button" role="tab">
            <i class="bi bi-wallet2 me-1"></i> Payments Disbursed (<?= count($payments) ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="tab-returns-btn" data-bs-toggle="pill" data-bs-target="#tab-returns" type="button" role="tab">
            <i class="bi bi-arrow-return-right me-1"></i> Vendor Returns &amp; Debits (<?= count($returns) ?>)
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="supplierTabsContent">

    <!-- Tab 1: Purchase Orders & GRNs -->
    <div class="tab-pane fade show active" id="tab-po" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>PO Date</th>
                                <th>Expected Delivery</th>
                                <th class="text-center">Items Count</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchaseOrders)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No purchase orders found for this supplier.</td>
                                </tr>
                            <?php else: foreach ($purchaseOrders as $po): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($po['po_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($po['po_date'])) ?></td>
                                    <td><?= $po['expected_date'] ? date('d M Y', strtotime($po['expected_date'])) : '-' ?></td>
                                    <td class="text-center"><?= $po['item_count'] ?> SKUs (<?= (int)$po['total_units'] ?> units)</td>
                                    <td class="text-end fw-bold">Rs. <?= number_format((float)$po['grand_total'], 2) ?></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= ucfirst($po['status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/purchases/view.php?id=<?= $po['id'] ?>" class="btn btn-xs btn-outline-primary">
                                            <i class="bi bi-eye"></i> View PO
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
                <a href="<?= BASE_URL ?>/modules/suppliers/ledger.php?id=<?= $supplierId ?>" class="btn btn-sm btn-primary">
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
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($tx['tx_type']) ?></span></td>
                                    <td class="small text-muted"><?= htmlspecialchars($tx['memo']) ?></td>
                                    <td class="text-end fw-semibold text-success"><?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '-' ?></td>
                                    <td class="text-end fw-semibold text-danger"><?= $tx['credit'] > 0 ? number_format($tx['credit'], 2) : '-' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 3: Payments Disbursed -->
    <div class="tab-pane fade" id="tab-payments" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Voucher #</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Paid From Account</th>
                                <th>Reference</th>
                                <th class="text-end">Amount (Rs.)</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No disbursements logged for this vendor.</td>
                                </tr>
                            <?php else: foreach ($payments as $pay): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($pay['payment_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                    <td><span class="badge bg-warning-subtle text-dark border"><?= strtoupper($pay['payment_method_code']) ?></span></td>
                                    <td><?= htmlspecialchars($pay['account_name'] ?? 'Cash/Bank') ?></td>
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

    <!-- Tab 4: Vendor Returns & Debits -->
    <div class="tab-pane fade" id="tab-returns" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Return #</th>
                                <th>Return Date</th>
                                <th>Reason</th>
                                <th class="text-end">Amount (Rs.)</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No vendor returns recorded.</td>
                                </tr>
                            <?php else: foreach ($returns as $ret): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($ret['return_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($ret['return_date'])) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($ret['reason'] ?: 'Damaged / rejected stock return') ?></td>
                                    <td class="text-end fw-bold text-success">Rs. <?= number_format((float)$ret['total_amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= ucfirst($ret['status']) ?></span></td>
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
