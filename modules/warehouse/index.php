<?php
/**
 * Warehouse Picking & Dispatch Queue
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'view');

$pdo = getDatabaseConnection();

// -------------------------------------------------------------
// Filters for Section 1: Approved Orders Awaiting Dispatch
// -------------------------------------------------------------
$search = sanitize($_GET['q'] ?? '');
$filterArea = (int)($_GET['area_id'] ?? 0);
$filterBooker = (int)($_GET['booker_id'] ?? 0);
$filterOrderDate = sanitize($_GET['order_date'] ?? '');

$ordersWhere = ["so.status = 'approved'"];
$ordersParams = [];

if ($filterArea > 0) {
    $ordersWhere[] = "so.area_id = ?";
    $ordersParams[] = $filterArea;
}

if ($filterBooker > 0) {
    $ordersWhere[] = "so.order_booker_id = ?";
    $ordersParams[] = $filterBooker;
}

if (!empty($filterOrderDate)) {
    $ordersWhere[] = "so.order_date = ?";
    $ordersParams[] = $filterOrderDate;
}

if (!empty($search)) {
    $ordersWhere[] = "(so.order_number LIKE ? OR c.shop_name LIKE ? OR c.owner_name LIKE ? OR e.full_name LIKE ? OR a.name LIKE ?)";
    $like = "%{$search}%";
    $ordersParams[] = $like;
    $ordersParams[] = $like;
    $ordersParams[] = $like;
    $ordersParams[] = $like;
    $ordersParams[] = $like;
}

$ordersWhereSql = implode(' AND ', $ordersWhere);

// Fetch approved orders awaiting warehouse picking and dispatch
$ordersStmt = $pdo->prepare("
    SELECT so.*, c.shop_name, c.owner_name, c.phone as cust_phone, a.name as area_name, e.full_name as booker_name,
           (SELECT COUNT(*) FROM `sales_order_items` WHERE sales_order_id = so.id) as item_count,
           (SELECT COALESCE(SUM(quantity), 0) FROM `sales_order_items` WHERE sales_order_id = so.id) as total_units
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON so.order_booker_id = e.id
    WHERE {$ordersWhereSql}
    ORDER BY so.id ASC
");
$ordersStmt->execute($ordersParams);
$approvedOrders = $ordersStmt->fetchAll();

// Territory Breakdown of Pending Orders for Quick Route Filter Pills
$areaRouteCounts = $pdo->query("
    SELECT a.id, a.name as area_name, COUNT(so.id) as pending_count, COALESCE(SUM(so.grand_total), 0) as pending_amount
    FROM `sales_orders` so
    JOIN `areas` a ON so.area_id = a.id
    WHERE so.status = 'approved'
    GROUP BY a.id, a.name
    ORDER BY pending_count DESC
")->fetchAll();

// Overall pending totals (unfiltered baseline)
$baselinePending = $pdo->query("
    SELECT COUNT(so.id) as total_count, COALESCE(SUM(so.grand_total), 0) as total_val,
           (SELECT COALESCE(SUM(quantity), 0) FROM sales_order_items soi JOIN sales_orders o ON soi.sales_order_id = o.id WHERE o.status = 'approved') as total_units
    FROM sales_orders so
    WHERE so.status = 'approved'
")->fetch();

// -------------------------------------------------------------
// Filters for Section 2: Recent Delivery Challans
// -------------------------------------------------------------
$challanSearch = sanitize($_GET['challan_q'] ?? '');
$filterDriver = (int)($_GET['driver_id'] ?? 0);
$filterChallanStatus = sanitize($_GET['challan_status'] ?? 'all');
$filterChallanDate = sanitize($_GET['challan_date'] ?? '');

$dispWhere = ["1=1"];
$dispParams = [];

if ($filterDriver > 0) {
    $dispWhere[] = "da.delivery_man_id = ?";
    $dispParams[] = $filterDriver;
}

if ($filterChallanStatus !== 'all' && !empty($filterChallanStatus)) {
    $dispWhere[] = "da.status = ?";
    $dispParams[] = $filterChallanStatus;
}

if (!empty($filterChallanDate)) {
    $dispWhere[] = "da.dispatch_date = ?";
    $dispParams[] = $filterChallanDate;
}

if (!empty($challanSearch)) {
    $dispWhere[] = "(da.dispatch_number LIKE ? OR so.order_number LIKE ? OR c.shop_name LIKE ? OR e.full_name LIKE ?)";
    $like = "%{$challanSearch}%";
    $dispParams[] = $like;
    $dispParams[] = $like;
    $dispParams[] = $like;
    $dispParams[] = $like;
}

$dispWhereSql = implode(' AND ', $dispWhere);

// Fetch recent dispatch challans
$recentStmt = $pdo->prepare("
    SELECT da.*, so.order_number, so.grand_total as order_amount, c.shop_name, c.owner_name,
           e.full_name as delivery_man_name, e.employee_code as driver_code, w.name as warehouse_name
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    JOIN `warehouses` w ON da.warehouse_id = w.id
    WHERE {$dispWhereSql}
    ORDER BY da.id DESC LIMIT 40
");
$recentStmt->execute($dispParams);
$recentDispatches = $recentStmt->fetchAll();

// Dropdown options
$areasList = $pdo->query("SELECT id, name FROM `areas` WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bookersList = $pdo->query("SELECT id, full_name, employee_code FROM `employees` WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$driversList = $pdo->query("SELECT id, full_name, employee_code FROM `employees` WHERE role_type IN ('delivery_man', 'delivery_manager', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

$hasActiveOrderFilters = !empty($search) || $filterArea > 0 || $filterBooker > 0 || !empty($filterOrderDate);
$hasActiveChallanFilters = !empty($challanSearch) || $filterDriver > 0 || ($filterChallanStatus !== 'all' && !empty($filterChallanStatus)) || !empty($filterChallanDate);

$pageTitle = 'Warehouse & Dispatch Management';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam text-primary me-2"></i>Warehouse & Dispatch Center</h4>
        <small class="text-muted">Order Picking, Packing, Delivery Route Assignment & Stock Deduction</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php" class="btn btn-sm btn-primary shadow-sm btn-touch">
            <i class="bi bi-truck-flatbed me-1"></i> Vehicle Load Sheet
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary shadow-sm btn-touch">
            <i class="bi bi-layers me-1"></i> Stock Ledger
        </a>
    </div>
</div>

<!-- Warehouse Operational KPI Metrics -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Orders Awaiting Dispatch</div>
            <div class="h5 fw-bold text-warning mb-0">
                <i class="bi bi-hourglass-split me-1"></i><?= (int)($baselinePending['total_count'] ?? 0) ?> Orders
            </div>
            <small class="text-muted">Rs. <?= number_format((float)($baselinePending['total_val'] ?? 0), 2) ?></small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Total Stock Units to Pick</div>
            <div class="h5 fw-bold text-primary mb-0">
                <i class="bi bi-boxes me-1"></i><?= number_format((float)($baselinePending['total_units'] ?? 0), 0) ?> Units
            </div>
            <small class="text-muted">Across approved batches</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Active Dispatch Routes</div>
            <div class="h5 fw-bold text-dark mb-0">
                <i class="bi bi-geo-alt me-1"></i><?= count($areaRouteCounts) ?> Territories
            </div>
            <small class="text-muted">With pending orders</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-2 border rounded bg-white shadow-sm">
            <div class="text-muted small fw-semibold">Recent Dispatches Generated</div>
            <div class="h5 fw-bold text-success mb-0">
                <i class="bi bi-truck me-1"></i><?= count($recentDispatches) ?> Challans
            </div>
            <small class="text-muted">Logged in fleet history</small>
        </div>
    </div>
</div>

<!-- Section 1: Orders Approved & Ready for Dispatch -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-hourglass-split me-1 text-warning"></i> Approved Orders Awaiting Dispatch
                    <span class="badge bg-warning text-dark ms-1"><?= count($approvedOrders) ?></span>
                </h6>
                <small class="text-muted">Select orders to pack into delivery vehicles and generate dispatch challans</small>
            </div>

            <!-- Instant In-Browser Search Bar -->
            <div class="w-100 w-md-auto d-flex align-items-center gap-2" style="max-width: 380px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" id="liveSearchOrders" class="form-control" placeholder="Quick search order, shop, booker..." onkeyup="filterOrdersTable();">
                    <button class="btn btn-outline-secondary" type="button" onclick="clearLiveOrderSearch();"><i class="bi bi-x"></i></button>
                </div>
                <span id="ordersMatchCount" class="badge bg-light text-dark border d-none"></span>
            </div>
        </div>

        <!-- Quick Territory / Route Pills -->
        <?php if (!empty($areaRouteCounts)): ?>
        <div class="mt-3 pt-2 border-top">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <span class="small fw-semibold text-muted me-1"><i class="bi bi-compass me-1"></i>Delivery Routes:</span>
                <a href="<?= BASE_URL ?>/modules/warehouse/index.php<?= !empty($search) ? '?q=' . urlencode($search) : '' ?>" 
                   class="btn btn-xs btn-touch <?= ($filterArea === 0) ? 'btn-primary shadow-sm fw-bold' : 'btn-outline-secondary' ?>">
                    All Routes (<?= (int)($baselinePending['total_count'] ?? 0) ?>)
                </a>
                <?php foreach ($areaRouteCounts as $arc): ?>
                    <a href="<?= BASE_URL ?>/modules/warehouse/index.php?area_id=<?= $arc['id'] ?><?= !empty($search) ? '&q=' . urlencode($search) : '' ?>" 
                       class="btn btn-xs btn-touch <?= ($filterArea === (int)$arc['id']) ? 'btn-primary shadow-sm fw-bold' : 'btn-outline-secondary' ?>">
                        <?= htmlspecialchars($arc['area_name']) ?> <span class="badge bg-warning text-dark ms-1"><?= $arc['pending_count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Collapsible Advanced Search & Filter Bar -->
    <div class="card-body bg-light border-top border-bottom py-2 px-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/warehouse/index.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search Keywords</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Order #, Shop, Owner, Area..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Filter Territory / Route</label>
                <select name="area_id" class="form-select form-select-sm">
                    <option value="0">All Territories</option>
                    <?php foreach ($areasList as $ar): ?>
                        <option value="<?= $ar['id'] ?>" <?= $filterArea === (int)$ar['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ar['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Order Booker</label>
                <select name="booker_id" class="form-select form-select-sm">
                    <option value="0">All Bookers</option>
                    <?php foreach ($bookersList as $bk): ?>
                        <option value="<?= $bk['id'] ?>" <?= $filterBooker === (int)$bk['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bk['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Booking Date</label>
                <input type="date" name="order_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterOrderDate) ?>">
            </div>

            <div class="col-6 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($hasActiveOrderFilters): ?>
                    <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-danger" title="Clear Filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($approvedOrders)): ?>
            <div class="p-4 text-center text-muted">
                <?php if ($hasActiveOrderFilters): ?>
                    <i class="bi bi-search fs-1 text-secondary d-block mb-1"></i>
                    No approved orders found matching your search filter.
                    <div class="mt-2">
                        <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-repeat me-1"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <i class="bi bi-check2-circle fs-1 text-success d-block mb-1"></i>
                    All approved orders have been picked and dispatched. No pending dispatches!
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0" id="ordersQueueTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;" class="text-center">#</th>
                            <th>Order #</th>
                            <th>Shop / Customer</th>
                            <th>Territory / Route</th>
                            <th>Order Booker</th>
                            <th class="text-center">Items & Units</th>
                            <th class="text-end">Order Amount</th>
                            <th class="text-end" style="min-width: 170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $ordIdx = 1;
                        $totApprovedSum = 0.00;
                        foreach ($approvedOrders as $ord): 
                            $totApprovedSum += (float)$ord['grand_total'];
                        ?>
                            <tr class="order-queue-row">
                                <td class="text-center text-muted small"><?= $ordIdx++ ?></td>
                                <td data-label="Order #">
                                    <span class="fw-bold text-primary"><?= htmlspecialchars($ord['order_number']) ?></span>
                                    <div class="text-muted small" style="font-size: 0.72rem;">
                                        <i class="bi bi-calendar3 me-1"></i><?= formatDate($ord['order_date']) ?>
                                    </div>
                                </td>
                                <td data-label="Shop">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ord['shop_name']) ?></div>
                                    <small class="text-muted">
                                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($ord['owner_name']) ?>
                                        <?php if (!empty($ord['cust_phone'])): ?>
                                            · <?= htmlspecialchars($ord['cust_phone']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td data-label="Territory">
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-geo-alt me-1 text-primary"></i><?= htmlspecialchars($ord['area_name']) ?>
                                    </span>
                                </td>
                                <td data-label="Booker">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($ord['booker_name']) ?></div>
                                    <small class="text-muted">Field Sales</small>
                                </td>
                                <td data-label="Items" class="text-center">
                                    <span class="badge bg-light text-dark border me-1"><?= $ord['item_count'] ?> items</span>
                                    <span class="badge bg-info bg-opacity-10 text-primary border border-info"><?= number_format((float)($ord['total_units'] ?? 0), 0) ?> units</span>
                                </td>
                                <td data-label="Amount" class="num-cell fw-bold text-dark text-end">
                                    <?= formatMoney($ord['grand_total']) ?>
                                </td>
                                <td data-label="Action" class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $ord['id'] ?>" class="btn btn-sm btn-outline-success btn-touch shadow-sm" title="Preview Order Bill">
                                            <i class="bi bi-receipt me-1"></i> Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/warehouse/dispatch.php?order_id=<?= $ord['id'] ?>" class="btn btn-sm btn-primary btn-touch shadow-sm fw-bold">
                                            <i class="bi bi-truck me-1"></i> Dispatch
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="6" class="text-end text-dark">Filtered Orders Total Amount:</td>
                            <td class="text-end text-primary num-cell fs-6"><?= formatMoney($totApprovedSum) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Section 2: Recent Dispatched Challans -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-file-earmark-text me-2 text-primary"></i>Recent Delivery Challans & History
                    <span class="badge bg-primary bg-opacity-10 text-primary ms-1"><?= count($recentDispatches) ?></span>
                </h6>
                <small class="text-muted">Track gate passes, delivery assignments and driver transit status</small>
            </div>

            <!-- Instant In-Browser Search for Challans -->
            <div class="w-100 w-md-auto d-flex align-items-center gap-2" style="max-width: 380px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" id="liveSearchChallans" class="form-control" placeholder="Quick search challan, driver, shop..." onkeyup="filterChallansTable();">
                    <button class="btn btn-outline-secondary" type="button" onclick="clearLiveChallanSearch();"><i class="bi bi-x"></i></button>
                </div>
                <span id="challansMatchCount" class="badge bg-light text-dark border d-none"></span>
            </div>
        </div>
    </div>

    <!-- Challan Filters Card -->
    <div class="card-body bg-light border-top border-bottom py-2 px-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/warehouse/index.php" class="row g-2 align-items-end">
            <!-- Preserve Section 1 filters if set -->
            <?php if (!empty($search)): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
            <?php if ($filterArea > 0): ?><input type="hidden" name="area_id" value="<?= $filterArea ?>"><?php endif; ?>
            <?php if ($filterBooker > 0): ?><input type="hidden" name="booker_id" value="<?= $filterBooker ?>"><?php endif; ?>

            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search Challan</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="challan_q" class="form-control" placeholder="Challan #, Order #, Customer..." value="<?= htmlspecialchars($challanSearch) ?>">
                </div>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Delivery Driver</label>
                <select name="driver_id" class="form-select form-select-sm">
                    <option value="0">All Delivery Drivers</option>
                    <?php foreach ($driversList as $dr): ?>
                        <option value="<?= $dr['id'] ?>" <?= $filterDriver === (int)$dr['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dr['full_name']) ?> (<?= htmlspecialchars($dr['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Challan Status</label>
                <select name="challan_status" class="form-select form-select-sm">
                    <option value="all" <?= $filterChallanStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="assigned" <?= $filterChallanStatus === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                    <option value="in_transit" <?= $filterChallanStatus === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                    <option value="delivered" <?= $filterChallanStatus === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="partially_delivered" <?= $filterChallanStatus === 'partially_delivered' ? 'selected' : '' ?>>Partially Delivered</option>
                    <option value="returned" <?= $filterChallanStatus === 'returned' ? 'selected' : '' ?>>Returned</option>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Dispatch Date</label>
                <input type="date" name="challan_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterChallanDate) ?>">
            </div>

            <div class="col-6 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($hasActiveChallanFilters): ?>
                    <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-danger" title="Clear Challan Filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($recentDispatches)): ?>
            <div class="p-4 text-center text-muted">
                <?php if ($hasActiveChallanFilters): ?>
                    <i class="bi bi-search fs-1 text-secondary d-block mb-1"></i>
                    No delivery challans found matching your search filter.
                    <div class="mt-2">
                        <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-repeat me-1"></i> Reset Challan Filters
                        </a>
                    </div>
                <?php else: ?>
                    <i class="bi bi-file-earmark-text fs-1 text-muted d-block mb-1"></i>
                    No dispatch challans generated yet.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-responsive-card align-middle mb-0" id="challansQueueTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;" class="text-center">#</th>
                            <th>Challan #</th>
                            <th>Order #</th>
                            <th>Customer / Shop</th>
                            <th>Delivery Driver</th>
                            <th>Dispatched Date</th>
                            <th class="text-end">Order Value</th>
                            <th>Status</th>
                            <th class="text-end">Gate Pass</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $chIdx = 1;
                        foreach ($recentDispatches as $disp): 
                        ?>
                            <tr class="challan-queue-row">
                                <td class="text-center text-muted small"><?= $chIdx++ ?></td>
                                <td data-label="Challan #" class="fw-bold text-dark">
                                    <?= htmlspecialchars($disp['dispatch_number']) ?>
                                    <div class="text-muted small" style="font-size: 0.72rem;"><?= htmlspecialchars($disp['warehouse_name'] ?? 'Main') ?></div>
                                </td>
                                <td data-label="Order #">
                                    <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $disp['sales_order_id'] ?>" class="text-primary text-decoration-none fw-bold">
                                        <?= htmlspecialchars($disp['order_number']) ?>
                                    </a>
                                </td>
                                <td data-label="Customer">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($disp['shop_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($disp['owner_name']) ?></small>
                                </td>
                                <td data-label="Delivery Staff">
                                    <div class="fw-semibold text-dark"><i class="bi bi-person me-1"></i><?= htmlspecialchars($disp['delivery_man_name']) ?></div>
                                    <small class="text-muted"><code><?= htmlspecialchars($disp['driver_code'] ?? '') ?></code></small>
                                </td>
                                <td data-label="Date">
                                    <div class="text-dark small"><i class="bi bi-calendar-event me-1 text-muted"></i><?= formatDate($disp['dispatch_date']) ?></div>
                                </td>
                                <td data-label="Value" class="num-cell text-end fw-bold text-dark">
                                    <?= formatMoney((float)($disp['order_amount'] ?? 0)) ?>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $b = match ($disp['status']) {
                                        'assigned' => 'bg-secondary',
                                        'in_transit' => 'bg-primary',
                                        'delivered' => 'bg-success',
                                        'partially_delivered' => 'bg-warning text-dark',
                                        'returned' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars(str_replace('_', ' ', $disp['status'])) ?></span>
                                </td>
                                <td data-label="Gate Pass" class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $disp['id'] ?>" class="btn btn-sm btn-outline-primary btn-touch shadow-sm">
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

<script>
// Client-side Instant Filter for Orders Table
function filterOrdersTable() {
    const input = document.getElementById('liveSearchOrders');
    const filter = input ? input.value.toLowerCase().trim() : '';
    const table = document.getElementById('ordersQueueTable');
    if (!table) return;

    const tbody = table.getElementsByTagName('tbody')[0];
    if (!tbody) return;
    const rows = tbody.getElementsByClassName('order-queue-row');

    let visibleCount = 0;
    for (let i = 0; i < rows.length; i++) {
        const text = rows[i].textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            rows[i].style.display = '';
            visibleCount++;
        } else {
            rows[i].style.display = 'none';
        }
    }

    const badge = document.getElementById('ordersMatchCount');
    if (badge) {
        if (filter) {
            badge.classList.remove('d-none');
            badge.textContent = `${visibleCount} match(es)`;
        } else {
            badge.classList.add('d-none');
            badge.textContent = '';
        }
    }
}

function clearLiveOrderSearch() {
    const input = document.getElementById('liveSearchOrders');
    if (input) {
        input.value = '';
        filterOrdersTable();
    }
}

// Client-side Instant Filter for Challans Table
function filterChallansTable() {
    const input = document.getElementById('liveSearchChallans');
    const filter = input ? input.value.toLowerCase().trim() : '';
    const table = document.getElementById('challansQueueTable');
    if (!table) return;

    const tbody = table.getElementsByTagName('tbody')[0];
    if (!tbody) return;
    const rows = tbody.getElementsByClassName('challan-queue-row');

    let visibleCount = 0;
    for (let i = 0; i < rows.length; i++) {
        const text = rows[i].textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            rows[i].style.display = '';
            visibleCount++;
        } else {
            rows[i].style.display = 'none';
        }
    }

    const badge = document.getElementById('challansMatchCount');
    if (badge) {
        if (filter) {
            badge.classList.remove('d-none');
            badge.textContent = `${visibleCount} match(es)`;
        } else {
            badge.classList.add('d-none');
            badge.textContent = '';
        }
    }
}

function clearLiveChallanSearch() {
    const input = document.getElementById('liveSearchChallans');
    if (input) {
        input.value = '';
        filterChallansTable();
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
