<?php
/**
 * Warehouse Picking & Dispatch Queue
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'view');

$pdo = getDatabaseConnection();

// Fetch approved orders awaiting warehouse picking and dispatch
$approvedOrders = $pdo->query("
    SELECT so.*, c.shop_name, c.owner_name, a.name as area_name, e.full_name as booker_name,
           (SELECT COUNT(*) FROM `sales_order_items` WHERE sales_order_id = so.id) as item_count
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON so.order_booker_id = e.id
    WHERE so.status = 'approved'
    ORDER BY so.id ASC
")->fetchAll();

// Fetch recent dispatch challans
$recentDispatches = $pdo->query("
    SELECT da.*, so.order_number, c.shop_name, e.full_name as delivery_man_name, w.name as warehouse_name
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    JOIN `warehouses` w ON da.warehouse_id = w.id
    ORDER BY da.id DESC LIMIT 15
")->fetchAll();

$pageTitle = 'Warehouse & Dispatch Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam text-primary me-2"></i>Warehouse & Dispatch Center</h4>
        <small class="text-muted">Order Picking, Packing, Delivery Assignment & Stock Deduction</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php" class="btn btn-sm btn-primary">
            <i class="bi bi-truck-flatbed me-1"></i> Vehicle Load Sheet (لوڈ شیٹ)
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-layers me-1"></i> Stock Ledger
        </a>
    </div>
</div>

<!-- Section 1: Orders Approved & Ready for Dispatch -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-hourglass-split me-1 text-warning"></i> Approved Orders Awaiting Dispatch
            <span class="badge bg-warning text-dark ms-1"><?= count($approvedOrders) ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($approvedOrders)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-check2-circle fs-1 text-success d-block mb-1"></i>
                All approved orders have been picked and dispatched. No pending dispatches!
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Shop / Customer</th>
                            <th>Territory / Booker</th>
                            <th>Items</th>
                            <th>Order Amount</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvedOrders as $ord): ?>
                            <tr>
                                <td data-label="Order #">
                                    <span class="fw-bold text-primary"><?= htmlspecialchars($ord['order_number']) ?></span>
                                    <div class="text-muted" style="font-size: 0.72rem;"><?= formatDate($ord['order_date']) ?></div>
                                </td>
                                <td data-label="Shop">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ord['shop_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($ord['owner_name']) ?></small>
                                </td>
                                <td data-label="Area">
                                    <div><?= htmlspecialchars($ord['area_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($ord['booker_name']) ?></small>
                                </td>
                                <td data-label="Items">
                                    <span class="badge bg-light text-dark border"><?= $ord['item_count'] ?> items</span>
                                </td>
                                <td data-label="Amount" class="fw-bold text-dark"><?= formatMoney($ord['grand_total']) ?></td>
                                <td data-label="Action" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $ord['id'] ?>" class="btn btn-sm btn-outline-success" title="Preview Order Bill">
                                            <i class="bi bi-receipt"></i> Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/warehouse/dispatch.php?order_id=<?= $ord['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-truck me-1"></i> Dispatch
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Section 2: Recent Dispatched Challans -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Recent Delivery Challans</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentDispatches)): ?>
            <div class="p-4 text-center text-muted">No dispatch challans generated yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Challan #</th>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Delivery Staff</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-end">Gate Pass</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDispatches as $disp): ?>
                            <tr>
                                <td data-label="Challan #" class="fw-bold text-dark"><?= htmlspecialchars($disp['dispatch_number']) ?></td>
                                <td data-label="Order #">
                                    <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $disp['sales_order_id'] ?>" class="text-primary text-decoration-none">
                                        <?= htmlspecialchars($disp['order_number']) ?>
                                    </a>
                                </td>
                                <td data-label="Customer"><?= htmlspecialchars($disp['shop_name']) ?></td>
                                <td data-label="Delivery Staff">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($disp['delivery_man_name']) ?>
                                </td>
                                <td data-label="Date"><?= formatDate($disp['dispatch_date']) ?></td>
                                <td data-label="Status">
                                    <?php
                                    $b = match ($disp['status']) {
                                        'assigned', 'in_transit' => 'bg-primary',
                                        'delivered' => 'bg-success',
                                        'partially_delivered' => 'bg-warning text-dark',
                                        'returned' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars(str_replace('_', ' ', $disp['status'])) ?></span>
                                </td>
                                <td data-label="Gate Pass" class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $disp['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-shield-check me-1"></i> Gate Pass
                                    </a>
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
