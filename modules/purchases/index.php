<?php
/**
 * Purchase Orders & Goods Receiving Notes (GRN) Directory
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('purchases', 'view');

$pdo = getDatabaseConnection();

// Fetch Purchase Orders
$orders = $pdo->query("
    SELECT po.*, s.name as supplier_name, w.name as warehouse_name, u.full_name as creator_name
    FROM `purchase_orders` po
    JOIN `suppliers` s ON po.supplier_id = s.id
    JOIN `warehouses` w ON po.warehouse_id = w.id
    LEFT JOIN `users` u ON po.created_by = u.id
    ORDER BY po.id DESC LIMIT 50
")->fetchAll();

// Fetch GRNs
$grns = $pdo->query("
    SELECT grn.*, s.name as supplier_name, w.name as warehouse_name
    FROM `goods_receipts` grn
    JOIN `suppliers` s ON grn.supplier_id = s.id
    JOIN `warehouses` w ON grn.warehouse_id = w.id
    ORDER BY grn.id DESC LIMIT 50
")->fetchAll();

$pageTitle = 'Purchases & Goods Receiving';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-bag-check text-primary me-2"></i>Purchases & Goods Receiving (GRN)</h4>
        <small class="text-muted">Procurement, Supplier Invoices & Warehouse Receiving</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/purchases/create.php" class="btn btn-primary btn-touch shadow-sm">
            <i class="bi bi-plus-circle me-1"></i> New Purchase Order
        </a>
    </div>
</div>

<!-- Tabs: Purchase Orders vs GRNs -->
<ul class="nav nav-pills mb-3" id="purchaseTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active fw-semibold" id="po-tab" data-bs-toggle="pill" data-bs-target="#po-pane" type="button">
            <i class="bi bi-file-earmark-text me-1"></i> Purchase Orders (<?= count($orders) ?>)
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-semibold" id="grn-tab" data-bs-toggle="pill" data-bs-target="#grn-pane" type="button">
            <i class="bi bi-box-arrow-in-down me-1"></i> Received GRNs (<?= count($grns) ?>)
        </button>
    </li>
</ul>

<div class="tab-content" id="purchaseTabsContent">
    <!-- Purchase Orders Pane -->
    <div class="tab-pane fade show active" id="po-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <?php if (empty($orders)): ?>
                    <div class="p-4 text-center text-muted">No purchase orders created yet. Click "New Purchase Order" to begin procurement.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>PO # / Date</th>
                                    <th>Supplier</th>
                                    <th>Warehouse</th>
                                    <th>Grand Total</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $po): ?>
                                    <tr>
                                        <td data-label="PO #">
                                            <span class="fw-bold text-primary"><?= htmlspecialchars($po['po_number']) ?></span>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= formatDate($po['po_date']) ?></div>
                                        </td>
                                        <td data-label="Supplier" class="fw-semibold text-dark"><?= htmlspecialchars($po['supplier_name']) ?></td>
                                        <td data-label="Warehouse"><?= htmlspecialchars($po['warehouse_name']) ?></td>
                                        <td data-label="Total" class="fw-bold text-dark"><?= formatMoney($po['grand_total']) ?></td>
                                        <td data-label="Status">
                                            <?php
                                            $b = match ($po['status']) {
                                                'received' => 'bg-success',
                                                'partially_received' => 'bg-info text-dark',
                                                'approved' => 'bg-warning text-dark',
                                                'submitted' => 'bg-secondary',
                                                default => 'bg-secondary'
                                            };
                                            $statusLabel = match ($po['status']) {
                                                'received' => 'Received (Stock In)',
                                                'partially_received' => 'Partially Received',
                                                'submitted' => 'Pending Receiving (GRN)',
                                                default => ucwords(str_replace('_', ' ', $po['status']))
                                            };
                                            ?>
                                            <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                        </td>
                                        <td data-label="Action" class="text-end">
                                            <?php if ($po['status'] !== 'received'): ?>
                                                <a href="<?= BASE_URL ?>/modules/purchases/grn.php?po_id=<?= $po['id'] ?>" class="btn btn-sm btn-success">
                                                    <i class="bi bi-box-arrow-in-down me-1"></i> Receive Goods (GRN)
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-success border"><i class="bi bi-check-all me-1"></i>Stock Inflow Complete</span>
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
    </div>

    <!-- Goods Receipts Pane -->
    <div class="tab-pane fade" id="grn-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <?php if (empty($grns)): ?>
                    <div class="p-4 text-center text-muted">No goods receipts posted yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>GRN #</th>
                                    <th>Receiving Date</th>
                                    <th>Supplier</th>
                                    <th>Warehouse</th>
                                    <th>Supplier Invoice #</th>
                                    <th>Total Inventory Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grns as $grn): ?>
                                    <tr>
                                        <td data-label="GRN #" class="fw-bold text-dark"><?= htmlspecialchars($grn['grn_number']) ?></td>
                                        <td data-label="Date"><?= formatDate($grn['grn_date']) ?></td>
                                        <td data-label="Supplier"><?= htmlspecialchars($grn['supplier_name']) ?></td>
                                        <td data-label="Warehouse"><?= htmlspecialchars($grn['warehouse_name']) ?></td>
                                        <td data-label="Invoice #"><?= htmlspecialchars($grn['invoice_no'] ?: '-') ?></td>
                                        <td data-label="Total" class="fw-bold text-success"><?= formatMoney($grn['total_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
