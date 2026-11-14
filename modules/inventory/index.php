<?php
/**
 * Immutable Stock Movement Ledger & Inventory Valuation
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('inventory', 'view');

$pdo = getDatabaseConnection();

$filterWarehouse = (int)($_GET['warehouse_id'] ?? 0);
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterType = sanitize($_GET['type'] ?? 'all');

$where = ["1=1"];
$params = [];

if ($filterWarehouse > 0) {
    $where[] = "sl.warehouse_id = ?";
    $params[] = $filterWarehouse;
}
if ($filterProduct > 0) {
    $where[] = "sl.product_id = ?";
    $params[] = $filterProduct;
}
if ($filterType !== 'all' && !empty($filterType)) {
    $where[] = "sl.movement_type = ?";
    $params[] = $filterType;
}

$whereSql = implode(' AND ', $where);

// Fetch Stock Movements
$query = "
    SELECT sl.*, p.name as product_name, p.code as product_code, w.name as warehouse_name, u.name as unit_name
    FROM `stock_ledger` sl
    JOIN `products` p ON sl.product_id = p.id
    JOIN `warehouses` w ON sl.warehouse_id = w.id
    JOIN `units` u ON sl.unit_id = u.id
    WHERE {$whereSql}
    ORDER BY sl.id DESC
    LIMIT 150
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Product On-hand Summary
$stockSummary = $pdo->query("
    SELECT p.id, p.name, p.code, p.purchase_price, p.wholesale_price, p.reorder_level, u.name as unit_name,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as on_hand_qty,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) * p.purchase_price as total_val
    FROM `products` p
    JOIN `units` u ON p.base_unit_id = u.id
    LEFT JOIN `stock_ledger` sl ON p.id = sl.product_id
    WHERE p.status = 'active'
    GROUP BY p.id, p.name, p.code, p.purchase_price, p.wholesale_price, p.reorder_level, u.name
    ORDER BY p.name ASC
")->fetchAll();

$totalInventoryValuation = 0.00;
foreach ($stockSummary as $s) {
    $totalInventoryValuation += (float)$s['total_val'];
}

$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active'")->fetchAll();
$allProducts = $pdo->query("SELECT id, name FROM `products` WHERE status = 'active'")->fetchAll();

$pageTitle = 'Stock Movement Ledger';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-layers text-primary me-2"></i>Stock Movement Ledger & Inventory</h4>
        <small class="text-muted">Immutable Stock Traceability, Valuation (WAC) & Audit Trails</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/inventory/adjust.php" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-sliders me-1"></i> Stock Adjustment
        </a>
    </div>
</div>

<!-- Total Valuation KPI Banner -->
<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Total Stock Valuation</span>
            <h4 class="fw-bold text-primary mb-0"><?= formatMoney($totalInventoryValuation) ?></h4>
            <small class="text-muted" style="font-size: 0.72rem;">Costed via Weighted Average Cost (WAC)</small>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <!-- Filter Card -->
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <form method="GET" action="<?= BASE_URL ?>/modules/inventory/index.php" class="row g-2">
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="warehouse_id">
                            <option value="">All Warehouses</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $filterWarehouse == $wh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="product_id">
                            <option value="">All Products</option>
                            <?php foreach ($allProducts as $pr): ?>
                                <option value="<?= $pr['id'] ?>" <?= $filterProduct == $pr['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" name="type">
                            <option value="all">All Movements</option>
                            <option value="purchase_grn" <?= $filterType === 'purchase_grn' ? 'selected' : '' ?>>Purchase GRN</option>
                            <option value="sale_dispatch" <?= $filterType === 'sale_dispatch' ? 'selected' : '' ?>>Sale Dispatch</option>
                            <option value="sale_return" <?= $filterType === 'sale_return' ? 'selected' : '' ?>>Sale Return</option>
                            <option value="adjustment_in" <?= $filterType === 'adjustment_in' ? 'selected' : '' ?>>Adjustment In</option>
                            <option value="adjustment_out" <?= $filterType === 'adjustment_out' ? 'selected' : '' ?>>Adjustment Out</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tabs: Ledger Log vs Product Balances -->
<ul class="nav nav-pills mb-3" id="invTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active fw-semibold" id="ledger-tab" data-bs-toggle="pill" data-bs-target="#ledger-pane" type="button">
            <i class="bi bi-clock-history me-1"></i> Stock Ledger Log (<?= count($movements) ?>)
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-semibold" id="balances-tab" data-bs-toggle="pill" data-bs-target="#balances-pane" type="button">
            <i class="bi bi-boxes me-1"></i> On-Hand Balances & Valuations (<?= count($stockSummary) ?>)
        </button>
    </li>
</ul>

<div class="tab-content" id="invTabsContent">
    <!-- Ledger Log -->
    <div class="tab-pane fade show active" id="ledger-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <?php if (empty($movements)): ?>
                    <div class="p-4 text-center text-muted">No stock movement logs found for this filter.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Product</th>
                                    <th>Warehouse</th>
                                    <th>Movement Type</th>
                                    <th class="text-center text-success">In (+Qty)</th>
                                    <th class="text-center text-danger">Out (-Qty)</th>
                                    <th class="text-center fw-bold">Balance</th>
                                    <th class="text-end">Cost / Val</th>
                                    <th>Reference Notes</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php foreach ($movements as $m): ?>
                                    <tr>
                                        <td><?= formatDateTime($m['created_at']) ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($m['product_name']) ?></div>
                                            <span class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($m['product_code']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($m['warehouse_name']) ?></td>
                                        <td>
                                            <?php
                                            $b = match ($m['movement_type']) {
                                                'purchase_grn' => 'bg-success',
                                                'sale_dispatch' => 'bg-primary',
                                                'sale_return' => 'bg-info text-dark',
                                                'adjustment_in' => 'bg-warning text-dark',
                                                'adjustment_out' => 'bg-danger',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?= $b ?>"><?= htmlspecialchars(str_replace('_', ' ', $m['movement_type'])) ?></span>
                                        </td>
                                        <td class="text-center text-success fw-bold">
                                            <?= $m['quantity_in'] > 0 ? '+' . (float)$m['quantity_in'] : '-' ?>
                                        </td>
                                        <td class="text-center text-danger fw-bold">
                                            <?= $m['quantity_out'] > 0 ? '-' . (float)$m['quantity_out'] : '-' ?>
                                        </td>
                                        <td class="text-center fw-bold text-dark">
                                            <?= (float)$m['balance_quantity'] ?> <?= htmlspecialchars($m['unit_name']) ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="text-dark fw-semibold"><?= formatMoney($m['total_cost']) ?></div>
                                            <small class="text-muted" style="font-size: 0.68rem;">@ <?= formatMoney($m['unit_cost'], false) ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($m['notes'] ?: $m['reference_type']) ?></small>
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

    <!-- Current On-Hand Balances & Valuations -->
    <div class="tab-pane fade" id="balances-pane">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Product Name</th>
                                <th>Code / SKU</th>
                                <th class="text-center">On-Hand Stock</th>
                                <th class="text-end">Average Cost (WAC)</th>
                                <th class="text-end">Wholesale Rate</th>
                                <th class="text-end">Total Valuation</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockSummary as $s): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($s['name']) ?></td>
                                    <td><?= htmlspecialchars($s['code']) ?></td>
                                    <td class="text-center fw-bold">
                                        <?= (float)$s['on_hand_qty'] ?> <?= htmlspecialchars($s['unit_name']) ?>
                                    </td>
                                    <td class="text-end"><?= formatMoney($s['purchase_price']) ?></td>
                                    <td class="text-end text-primary fw-semibold"><?= formatMoney($s['wholesale_price']) ?></td>
                                    <td class="text-end fw-bold text-success"><?= formatMoney($s['total_val']) ?></td>
                                    <td>
                                        <?php if ((float)$s['on_hand_qty'] <= (float)$s['reorder_level']): ?>
                                            <span class="badge bg-danger">Low Stock Alert</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
