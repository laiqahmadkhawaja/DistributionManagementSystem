<?php
/**
 * Payment Vouchers & Cash Collection Registry
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('payments', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];

$typeFilter = sanitize($_GET['type'] ?? 'all');
$where = ["p.status = 'posted'"];
$params = [];

if ($typeFilter !== 'all') {
    $where[] = "p.payment_type = ?";
    $params[] = $typeFilter;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT p.*, pm.name as method_name, e.full_name as collector_name,
           CASE 
               WHEN p.party_type = 'customer' THEN (SELECT shop_name FROM `customers` WHERE id = p.party_id)
               WHEN p.party_type = 'supplier' THEN (SELECT name FROM `suppliers` WHERE id = p.party_id)
               ELSE 'Other'
           END as party_name
    FROM `payments` p
    JOIN `payment_methods` pm ON p.payment_method_id = pm.id
    LEFT JOIN `employees` e ON p.collected_by = e.id
    WHERE {$whereSql}
    ORDER BY p.id DESC
    LIMIT 100
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'Payments & Collections';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-stack text-success me-2"></i>Payments & Financial Recoveries</h4>
        <small class="text-muted">Customer Receipts, Delivery Collections & Supplier Disbursements</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/payments/recovery.php" class="btn btn-success btn-sm fw-bold">
            <i class="bi bi-cash-coin me-1"></i> + Record Customer Recovery
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-wallet2 me-1"></i> Cash Handover & Recon
        </a>
    </div>
</div>

<!-- Type Filter Buttons -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
    <div class="card-body p-2">
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-sm <?= $typeFilter === 'all' ? 'btn-primary' : 'btn-light border' ?>">All Payments</a>
            <a href="<?= BASE_URL ?>/modules/payments/index.php?type=customer_receipt" class="btn btn-sm <?= $typeFilter === 'customer_receipt' ? 'btn-success' : 'btn-light border' ?>">Customer Receipts</a>
            <a href="<?= BASE_URL ?>/modules/payments/index.php?type=supplier_payment" class="btn btn-sm <?= $typeFilter === 'supplier_payment' ? 'btn-danger' : 'btn-light border' ?>">Supplier Payments</a>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="p-4 text-center text-muted">No payment records found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th>Voucher #</th>
                            <th>Date</th>
                            <th>Party / Payee</th>
                            <th>Transaction Type</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Collector / Custody</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td data-label="Voucher #" class="fw-bold text-primary">
                                    <?php if ($pay['payment_type'] === 'customer_receipt'): ?>
                                        <a href="<?= BASE_URL ?>/modules/payments/receipt_print.php?id=<?= $pay['id'] ?>" target="_blank" class="text-decoration-none">
                                            <?= htmlspecialchars($pay['payment_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($pay['payment_number']) ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Date"><?= formatDate($pay['payment_date']) ?></td>
                                <td data-label="Party">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pay['party_name'] ?? 'General') ?></div>
                                    <small class="text-muted"><?= ucfirst($pay['party_type']) ?></small>
                                </td>
                                <td data-label="Type">
                                    <?php if ($pay['payment_type'] === 'customer_receipt'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-arrow-down-left me-1"></i>Receipt</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-arrow-up-right me-1"></i>Disbursement</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Method">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($pay['method_name']) ?></span>
                                    <?php if ($pay['reference_no']): ?>
                                        <div class="text-muted" style="font-size: 0.68rem;"><?= htmlspecialchars($pay['reference_no']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Amount" class="text-end fw-bold fs-6 <?= $pay['payment_type'] === 'customer_receipt' ? 'text-success' : 'text-danger' ?>">
                                    <?= formatMoney($pay['amount']) ?>
                                </td>
                                <td data-label="Custody">
                                    <div><?= htmlspecialchars($pay['collector_name'] ?? '-') ?></div>
                                    <?php if ($pay['handover_status'] === 'pending_handover'): ?>
                                        <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Pending Handover</span>
                                    <?php elseif ($pay['handover_status'] === 'reconciled'): ?>
                                        <span class="badge bg-success" style="font-size: 0.65rem;">Reconciled</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action" class="text-end">
                                    <?php if ($pay['payment_type'] === 'customer_receipt'): ?>
                                        <a href="<?= BASE_URL ?>/modules/payments/receipt_print.php?id=<?= $pay['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Print Receipt Voucher">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
