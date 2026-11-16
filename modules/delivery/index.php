<?php
/**
 * Delivery Man Mobile App - Deliveries & Collection Dashboard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('delivery', 'view');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'] ?? '';
$currentEmp = getCurrentEmployee();

$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);
$isDeliveryMan = ($roleSlug === ROLE_DELIVERY_MAN);
$isManagement = ($isSuperAdmin || hasRole([ROLE_ADMIN, ROLE_SALES_MANAGER, ROLE_WAREHOUSE_MANAGER]));

// Get GET Filter Parameters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$areaFilter = (int)($_GET['area_id'] ?? 0);
$driverFilter = (int)($_GET['delivery_man_id'] ?? 0);
$sort = trim($_GET['sort'] ?? 'default');

// Master query for Area and Driver filter dropdowns
$allAreas = [];
try {
    $allAreas = $pdo->query("SELECT id, name, code FROM `areas` ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    // Fail silently if table not yet migrated
}

$allDrivers = [];
if ($isManagement) {
    try {
        $allDrivers = $pdo->query("
            SELECT id, full_name, employee_code, role_type 
            FROM `employees` 
            WHERE (`role_type` = 'delivery_man' OR id IN (SELECT DISTINCT delivery_man_id FROM delivery_assignments)) 
              AND `status` = 'active'
            ORDER BY full_name ASC
        ")->fetchAll();
    } catch (Exception $e) {
        // Fail silently
    }
}

// Scoping: If Delivery Man, strictly show their own assigned deliveries
$whereClause = "1=1";
$params = [];

if ($isDeliveryMan) {
    $empId = $currentEmp['id'] ?? 0;
    $whereClause .= " AND da.delivery_man_id = ?";
    $params[] = $empId;
} elseif ($driverFilter > 0 && $isManagement) {
    $whereClause .= " AND da.delivery_man_id = ?";
    $params[] = $driverFilter;
}

// Server-side filter conditions (if requested directly via GET)
if ($statusFilter === 'pending') {
    $whereClause .= " AND da.status IN ('assigned', 'in_transit')";
} elseif ($statusFilter === 'completed') {
    $whereClause .= " AND da.status IN ('delivered', 'partially_delivered')";
} elseif ($statusFilter === 'returned') {
    $whereClause .= " AND da.status = 'returned'";
}

if ($areaFilter > 0) {
    $whereClause .= " AND so.area_id = ?";
    $params[] = $areaFilter;
}

if ($search !== '') {
    $whereClause .= " AND (
        c.shop_name LIKE ? OR 
        c.owner_name LIKE ? OR 
        c.phone LIKE ? OR 
        c.address LIKE ? OR 
        da.dispatch_number LIKE ? OR 
        so.order_number LIKE ? OR 
        a.name LIKE ?
    )";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

// Sorting logic
$orderBy = "da.id DESC";
if ($sort === 'due_desc') {
    $orderBy = "c.current_balance DESC, da.id DESC";
} elseif ($sort === 'amount_desc') {
    $orderBy = "so.grand_total DESC, da.id DESC";
} elseif ($sort === 'shop_asc') {
    $orderBy = "c.shop_name ASC, da.id DESC";
}

// Assigned Deliveries Query
$deliveriesQuery = "
    SELECT da.*, so.order_number, so.grand_total as order_amount, so.order_date, so.area_id,
           c.id as customer_id, c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           c.current_balance as cust_balance, a.name as area_name,
           e.full_name as delivery_man_name, e.employee_code as delivery_man_code
    FROM `delivery_assignments` da
    JOIN `sales_orders` so ON da.sales_order_id = so.id
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    JOIN `employees` e ON da.delivery_man_id = e.id
    WHERE {$whereClause}
    ORDER BY {$orderBy}
    LIMIT 200
";

$stmt = $pdo->prepare($deliveriesQuery);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// Calculate Today's Delivery Driver KPIs
$today = date('Y-m-d');
$totalAssigned = count($deliveries);
$pendingDeliveries = 0;
$completedDeliveries = 0;
$returnedDeliveries = 0;
$todayCashCollected = 0.00;
$todayDigitalCollected = 0.00;
$driverCustodyQty = 0.00;

foreach ($deliveries as $d) {
    if (in_array($d['status'], ['assigned', 'in_transit'])) {
        $pendingDeliveries++;
    } elseif ($d['status'] === 'delivered' || $d['status'] === 'partially_delivered') {
        $completedDeliveries++;
    } elseif ($d['status'] === 'returned') {
        $returnedDeliveries++;
    }
}

// Cash collected & custody calculation
$empIdForKpi = $isDeliveryMan ? ($currentEmp['id'] ?? 0) : ($driverFilter > 0 ? $driverFilter : 0);

if ($empIdForKpi > 0) {
    $cStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN payment_method_code = 'cash' THEN amount ELSE 0 END), 0) as cash_tot,
            COALESCE(SUM(CASE WHEN payment_method_code != 'cash' THEN amount ELSE 0 END), 0) as digital_tot
        FROM `payments`
        WHERE `collected_by` = ? AND `payment_date` = ? AND `status` = 'posted'
    ");
    $cStmt->execute([$empIdForKpi, $today]);
    $coll = $cStmt->fetch();
    $todayCashCollected = (float)$coll['cash_tot'];
    $todayDigitalCollected = (float)$coll['digital_tot'];

    // Vehicle Return Stock Custody
    $custStmt = $pdo->prepare("
        SELECT COALESCE(SUM(sri.quantity), 0)
        FROM `sales_returns` sr
        JOIN `sales_return_items` sri ON sr.id = sri.sales_return_id
        WHERE sr.delivery_man_id = ? AND sr.handover_status = 'in_vehicle_custody'
    ");
    $custStmt->execute([$empIdForKpi]);
    $driverCustodyQty = (float)$custStmt->fetchColumn();
} elseif ($isManagement) {
    // Total system collections today for management
    $cStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN payment_method_code = 'cash' THEN amount ELSE 0 END), 0) as cash_tot,
            COALESCE(SUM(CASE WHEN payment_method_code != 'cash' THEN amount ELSE 0 END), 0) as digital_tot
        FROM `payments`
        WHERE `payment_date` = ? AND `status` = 'posted'
    ");
    $cStmt->execute([$today]);
    $coll = $cStmt->fetch();
    $todayCashCollected = (float)$coll['cash_tot'];
    $todayDigitalCollected = (float)$coll['digital_tot'];
}

$pageTitle = 'Delivery Driver App';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Driver App Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-truck-flatbed text-success me-2"></i>Delivery Driver App
        </h4>
        <small class="text-muted">Route Deliveries, Shop Returns & Payment Collections</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/delivery/return.php" class="btn btn-sm btn-danger fw-semibold">
            <i class="bi bi-box-arrow-in-left me-1"></i> Record Shop Return
        </a>
        <a href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php" class="btn btn-sm btn-outline-warning text-dark fw-bold">
            <i class="bi bi-box-seam me-1"></i> Stock Handover
            <?php if ($driverCustodyQty > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= $driverCustodyQty ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="btn btn-sm btn-warning text-dark fw-bold">
            <i class="bi bi-wallet2 me-1"></i> Cash Handover
        </a>
    </div>
</div>

<!-- Driver Daily KPI Cards Grid -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Pending Delivery</span>
            <h4 class="fw-bold text-danger mb-0" id="kpiPendingCount"><?= $pendingDeliveries ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Out of <?= $totalAssigned ?> Orders</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Completed Today</span>
            <h4 class="fw-bold text-success mb-0" id="kpiCompletedCount"><?= $completedDeliveries ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Deliveries</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Cash in Hand</span>
            <h4 class="fw-bold text-primary mb-0"><?= formatMoney($todayCashCollected) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">Needs Handover</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <span class="text-muted small fw-semibold">Digital Recovery</span>
            <h4 class="fw-bold text-info mb-0"><?= formatMoney($todayDigitalCollected) ?></h4>
            <small class="text-muted" style="font-size: 0.7rem;">JazzCash / Bank</small>
        </div>
    </div>
</div>

<!-- Quick Search & Filter Operations Panel -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <!-- Row 1: Search Bar with Clear Button -->
        <div class="position-relative mb-2">
            <div class="input-group delivery-search-box">
                <span class="input-group-text bg-transparent border-0 text-muted ps-3">
                    <i class="bi bi-search fs-5"></i>
                </span>
                <input type="text" id="quickSearchInput" class="form-control bg-transparent border-0 py-2 ps-1" 
                       placeholder="Search shop name, owner, phone, challan (DC-...) or order #..." 
                       value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button class="btn bg-transparent border-0 pe-3 text-muted <?= empty($search) ? 'd-none' : '' ?>" 
                        type="button" id="clearSearchBtn" onclick="clearSearchInput()" title="Clear search">
                    <i class="bi bi-x-circle-fill fs-5"></i>
                </button>
            </div>
        </div>

        <!-- Row 2: Status Quick Filter Pills and Controls -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-1">
            <div class="btn-group btn-group-sm flex-wrap" role="group" id="statusPillsGroup">
                <button type="button" class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?> fw-semibold status-pill" data-status="all" onclick="setStatusFilter('all', this)">
                    All Stops <span class="badge bg-white text-dark rounded-pill ms-1" id="badgeAll"><?= $totalAssigned ?></span>
                </button>
                <button type="button" class="btn <?= $statusFilter === 'pending' ? 'btn-warning text-dark' : 'btn-outline-secondary' ?> fw-semibold status-pill" data-status="pending" onclick="setStatusFilter('pending', this)">
                    <i class="bi bi-clock-history me-1"></i>Pending <span class="badge bg-dark text-white rounded-pill ms-1" id="badgePending"><?= $pendingDeliveries ?></span>
                </button>
                <button type="button" class="btn <?= $statusFilter === 'completed' ? 'btn-success text-white' : 'btn-outline-secondary' ?> fw-semibold status-pill" data-status="completed" onclick="setStatusFilter('completed', this)">
                    <i class="bi bi-check2-circle me-1"></i>Completed <span class="badge bg-white text-success rounded-pill ms-1" id="badgeCompleted"><?= $completedDeliveries ?></span>
                </button>
                <?php if ($returnedDeliveries > 0): ?>
                <button type="button" class="btn <?= $statusFilter === 'returned' ? 'btn-danger text-white' : 'btn-outline-secondary' ?> fw-semibold status-pill" data-status="returned" onclick="setStatusFilter('returned', this)">
                    <i class="bi bi-arrow-return-left me-1"></i>Returned <span class="badge bg-white text-danger rounded-pill ms-1" id="badgeReturned"><?= $returnedDeliveries ?></span>
                </button>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" data-bs-toggle="collapse" data-bs-target="#advancedFiltersCollapse" aria-expanded="false" id="toggleFiltersBtn">
                    <i class="bi bi-sliders me-1"></i> Filters & Sort <i class="bi bi-chevron-down ms-1" style="font-size:0.75rem;"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetAllDeliveryFilters()" title="Reset All Filters">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
            </div>
        </div>

        <!-- Row 3: Collapsible Advanced Filters (Area, Driver, Sort) -->
        <div class="collapse pt-3 mt-2 border-top <?= ($areaFilter > 0 || $driverFilter > 0 || $sort !== 'default') ? 'show' : '' ?>" id="advancedFiltersCollapse">
            <div class="row g-2 align-items-end">
                <!-- Route / Area Filter -->
                <div class="col-12 col-sm-6 col-md-4">
                    <label class="form-label small text-muted mb-1 fw-semibold">
                        <i class="bi bi-geo-alt me-1 text-danger"></i>Filter by Route / Area
                    </label>
                    <select class="form-select form-select-sm" id="areaFilterSelect" onchange="applyDeliveryFilters()">
                        <option value="">All Routes & Areas</option>
                        <?php foreach ($allAreas as $ar): ?>
                            <option value="<?= $ar['id'] ?>" <?= $ar['id'] == $areaFilter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ar['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Driver Filter (Admin/Super Admin only) -->
                <?php if (!empty($allDrivers)): ?>
                <div class="col-12 col-sm-6 col-md-4">
                    <label class="form-label small text-muted mb-1 fw-semibold">
                        <i class="bi bi-person-badge me-1 text-primary"></i>Filter by Delivery Driver
                    </label>
                    <select class="form-select form-select-sm" id="driverFilterSelect" onchange="applyDeliveryFilters()">
                        <option value="">All Delivery Drivers</option>
                        <?php foreach ($allDrivers as $drv): ?>
                            <option value="<?= $drv['id'] ?>" <?= $drv['id'] == $driverFilter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($drv['full_name']) ?> (<?= htmlspecialchars($drv['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Sort Deliveries -->
                <div class="col-12 col-sm-6 col-md-4">
                    <label class="form-label small text-muted mb-1 fw-semibold">
                        <i class="bi bi-sort-down me-1 text-secondary"></i>Sort Deliveries
                    </label>
                    <select class="form-select form-select-sm" id="sortFilterSelect" onchange="applyDeliveryFilters()">
                        <option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>Route Sequence (Default)</option>
                        <option value="shop_asc" <?= $sort === 'shop_asc' ? 'selected' : '' ?>>Shop Name (A → Z)</option>
                        <option value="due_desc" <?= $sort === 'due_desc' ? 'selected' : '' ?>>Highest Shop Due First</option>
                        <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Highest Order Amount First</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assigned Deliveries List (Mobile Optimized Card Stack) -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="fw-bold mb-0 text-dark">
                <i class="bi bi-geo-alt-fill text-danger me-1"></i>Today's Assigned Route Deliveries
            </h6>
            <small class="text-muted">
                Showing <strong id="visibleStopsCount"><?= count($deliveries) ?></strong> of <strong id="totalStopsCount"><?= count($deliveries) ?></strong> Stops
            </small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border px-2 py-1" id="activeFilterBadge">
                <?= $statusFilter === 'all' ? 'All Stops' : ucfirst($statusFilter) ?>
            </span>
        </div>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($deliveries)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-truck fs-1 d-block mb-1 text-secondary opacity-50"></i>
                No delivery assignments assigned for this criteria.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="deliveryCardsContainer">
                <?php foreach ($deliveries as $idx => $del): ?>
                    <?php
                    $isDone = in_array($del['status'], ['delivered', 'partially_delivered']);
                    $isReturned = ($del['status'] === 'returned');
                    $statusGroup = $isDone ? 'completed' : ($isReturned ? 'returned' : 'pending');
                    $totalReceivable = (float)$del['cust_balance'];
                    $orderAmount = (float)$del['order_amount'];
                    
                    // Search text blob for instant regex match
                    $searchBlob = strtolower(implode(' ', [
                        $del['shop_name'],
                        $del['owner_name'],
                        $del['cust_phone'],
                        $del['cust_address'],
                        $del['area_name'],
                        $del['dispatch_number'],
                        $del['order_number'],
                        $del['delivery_man_name']
                    ]));
                    ?>
                    <div class="list-group-item p-3 delivery-stop-card <?= $isDone ? 'bg-light bg-opacity-50' : '' ?>"
                         id="delivery-card-<?= $del['id'] ?>"
                         data-id="<?= $del['id'] ?>"
                         data-search="<?= htmlspecialchars($searchBlob) ?>"
                         data-shop="<?= strtolower(htmlspecialchars($del['shop_name'])) ?>"
                         data-status="<?= htmlspecialchars($del['status']) ?>"
                         data-status-group="<?= $statusGroup ?>"
                         data-area-id="<?= (int)($del['area_id'] ?? 0) ?>"
                         data-driver-id="<?= (int)$del['delivery_man_id'] ?>"
                         data-due="<?= $totalReceivable ?>"
                         data-amount="<?= $orderAmount ?>">

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                            <!-- Left: Shop & Route Info -->
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center flex-wrap gap-2">
                                    <span class="badge bg-secondary text-white rounded-pill px-2" style="font-size: 0.72rem;">#<?= $idx + 1 ?></span>
                                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($del['shop_name']) ?></span>
                                    <?php
                                    $b = match ($del['status']) {
                                        'assigned', 'in_transit' => 'bg-warning text-dark',
                                        'delivered' => 'bg-success text-white',
                                        'partially_delivered' => 'bg-info text-dark',
                                        'returned' => 'bg-danger text-white',
                                        default => 'bg-secondary text-white'
                                    };
                                    ?>
                                    <span class="badge badge-status <?= $b ?>"><?= htmlspecialchars(str_replace('_', ' ', $del['status'])) ?></span>
                                    <?php if ($isManagement && !empty($del['delivery_man_name'])): ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;">
                                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($del['delivery_man_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="small text-muted mt-1 d-flex flex-wrap align-items-center gap-2">
                                    <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($del['owner_name']) ?></span>
                                    <span>·</span>
                                    <a href="tel:<?= htmlspecialchars($del['cust_phone']) ?>" class="btn btn-sm btn-outline-success px-2 py-0 text-decoration-none fw-semibold" style="font-size: 0.78rem;">
                                        <i class="bi bi-telephone-fill me-1"></i><?= htmlspecialchars($del['cust_phone']) ?>
                                    </a>
                                    <?php
                                    $waNumber = preg_replace('/[^0-9]/', '', $del['cust_phone']);
                                    if (str_starts_with($waNumber, '03')) {
                                        $waNumber = '92' . substr($waNumber, 1);
                                    }
                                    ?>
                                    <?php if (!empty($waNumber)): ?>
                                        <a href="https://wa.me/<?= $waNumber ?>" target="_blank" class="btn btn-sm btn-outline-success px-2 py-0" title="Chat on WhatsApp" style="font-size: 0.78rem;">
                                            <i class="bi bi-whatsapp"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="small text-secondary mt-1 d-flex align-items-center flex-wrap gap-2">
                                    <span><i class="bi bi-geo-alt me-1 text-danger"></i><?= htmlspecialchars($del['cust_address']) ?> (<strong><?= htmlspecialchars($del['area_name']) ?></strong>)</span>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($del['shop_name'] . ' ' . $del['cust_address'] . ' ' . $del['area_name']) ?>" target="_blank" class="text-decoration-none text-primary" style="font-size: 0.75rem;">
                                        <i class="bi bi-compass me-1"></i>Map Directions
                                    </a>
                                </div>

                                <div class="small text-muted mt-1 d-flex flex-wrap gap-3">
                                    <span>Challan: <strong class="text-dark"><?= htmlspecialchars($del['dispatch_number']) ?></strong></span>
                                    <span>Order: <strong class="text-dark"><?= htmlspecialchars($del['order_number']) ?></strong></span>
                                </div>
                            </div>

                            <!-- Right: Financials & Action Buttons -->
                            <div class="text-md-end w-100 w-md-auto mt-2 mt-md-0" style="min-width: 260px;">
                                <div class="d-flex justify-content-between justify-content-md-end gap-3 mb-2 p-2 bg-light rounded-2 border">
                                    <div class="text-start text-md-end">
                                        <div class="text-muted" style="font-size: 0.7rem;">Order Amount</div>
                                        <div class="fw-bold text-dark"><?= formatMoney($orderAmount) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="text-muted" style="font-size: 0.7rem;">Total Shop Due</div>
                                        <div class="fw-bold <?= $totalReceivable > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= formatMoney($totalReceivable) ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$isDone): ?>
                                    <a href="<?= BASE_URL ?>/modules/delivery/deliver.php?id=<?= $del['id'] ?>" class="btn btn-success btn-touch w-100 shadow-sm mb-1 fw-bold">
                                        <i class="bi bi-check2-circle me-1"></i> Deliver & Collect Payment
                                    </a>
                                    <div class="d-flex gap-1 mb-1">
                                        <a href="<?= BASE_URL ?>/modules/delivery/return.php?delivery_id=<?= $del['id'] ?>&customer_id=<?= $del['customer_id'] ?>" class="btn btn-sm btn-outline-danger w-100">
                                            <i class="bi bi-box-arrow-in-left me-1"></i> Record Shop Return
                                        </a>
                                    </div>
                                    <div class="d-flex gap-1 mb-1">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $del['sales_order_id'] ?>" class="btn btn-sm btn-outline-success w-50">
                                            <i class="bi bi-receipt me-1"></i> Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $del['id'] ?>" class="btn btn-sm btn-outline-primary w-50">
                                            <i class="bi bi-shield-check me-1"></i> Gate Pass
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex gap-1 mb-1">
                                        <a href="<?= BASE_URL ?>/modules/delivery/return.php?delivery_id=<?= $del['id'] ?>&customer_id=<?= $del['customer_id'] ?>" class="btn btn-sm btn-outline-danger w-100">
                                            <i class="bi bi-box-arrow-in-left me-1"></i> Record Shop Return
                                        </a>
                                    </div>
                                    <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $del['sales_order_id'] ?>" class="btn btn-sm btn-success w-100 mb-1">
                                        <i class="bi bi-receipt me-1"></i> View Bill & Customer Receipt
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/warehouse/gatepass.php?id=<?= $del['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                        <i class="bi bi-shield-check me-1"></i> View Gate Pass
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Zero Results Placeholder (shown by JS when all cards hidden) -->
            <div id="noMatchingResultsState" class="p-5 text-center text-muted d-none">
                <i class="bi bi-search fs-1 d-block mb-2 text-secondary opacity-50"></i>
                <h6 class="fw-bold text-dark">No Deliveries Found</h6>
                <p class="small text-muted mb-3">No assigned deliveries match your search query or selected filters.</p>
                <button type="button" class="btn btn-sm btn-primary px-3" onclick="resetAllDeliveryFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Clear Search & Filters
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Instant Client-Side Search & Filter Engine -->
<script>
let currentStatusFilter = '<?= htmlspecialchars($statusFilter) ?>';

// Live Keystroke Search
document.getElementById('quickSearchInput')?.addEventListener('input', function() {
    const clearBtn = document.getElementById('clearSearchBtn');
    if (clearBtn) {
        if (this.value.trim().length > 0) {
            clearBtn.classList.remove('d-none');
        } else {
            clearBtn.classList.add('d-none');
        }
    }
    applyDeliveryFilters();
});

// Clear Search Input
function clearSearchInput() {
    const input = document.getElementById('quickSearchInput');
    if (input) {
        input.value = '';
        document.getElementById('clearSearchBtn')?.classList.add('d-none');
        input.focus();
        applyDeliveryFilters();
    }
}

// Status Pill Filter
function setStatusFilter(status, btnElem) {
    currentStatusFilter = status;
    
    // Update button styles
    const pills = document.querySelectorAll('.status-pill');
    pills.forEach(p => {
        p.className = 'btn btn-outline-secondary fw-semibold status-pill';
        const badge = p.querySelector('.badge');
        if (badge) {
            badge.className = 'badge bg-secondary text-white rounded-pill ms-1';
        }
    });

    if (btnElem) {
        if (status === 'all') {
            btnElem.className = 'btn btn-primary fw-semibold status-pill';
            const b = btnElem.querySelector('.badge');
            if (b) b.className = 'badge bg-white text-dark rounded-pill ms-1';
        } else if (status === 'pending') {
            btnElem.className = 'btn btn-warning text-dark fw-semibold status-pill';
            const b = btnElem.querySelector('.badge');
            if (b) b.className = 'badge bg-dark text-white rounded-pill ms-1';
        } else if (status === 'completed') {
            btnElem.className = 'btn btn-success text-white fw-semibold status-pill';
            const b = btnElem.querySelector('.badge');
            if (b) b.className = 'badge bg-white text-success rounded-pill ms-1';
        } else if (status === 'returned') {
            btnElem.className = 'btn btn-danger text-white fw-semibold status-pill';
            const b = btnElem.querySelector('.badge');
            if (b) b.className = 'badge bg-white text-danger rounded-pill ms-1';
        }
    }

    // Update active filter badge text
    const badgeText = (status === 'all') ? 'All Stops' : status.charAt(0).toUpperCase() + status.slice(1);
    const activeBadge = document.getElementById('activeFilterBadge');
    if (activeBadge) activeBadge.textContent = badgeText;

    applyDeliveryFilters();
}

// Master Filtering Function
function applyDeliveryFilters() {
    const searchInput = document.getElementById('quickSearchInput');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    
    const areaSelect = document.getElementById('areaFilterSelect');
    const selectedArea = areaSelect ? areaSelect.value : '';

    const driverSelect = document.getElementById('driverFilterSelect');
    const selectedDriver = driverSelect ? driverSelect.value : '';

    const sortSelect = document.getElementById('sortFilterSelect');
    const selectedSort = sortSelect ? sortSelect.value : 'default';

    const cards = Array.from(document.querySelectorAll('.delivery-stop-card'));
    let visibleCount = 0;

    cards.forEach(card => {
        const searchBlob = card.getAttribute('data-search') || '';
        const cardStatusGroup = card.getAttribute('data-status-group') || '';
        const cardAreaId = card.getAttribute('data-area-id') || '';
        const cardDriverId = card.getAttribute('data-driver-id') || '';

        // Match checks
        let matchQuery = (!query) || (searchBlob.indexOf(query) !== -1);
        let matchStatus = (currentStatusFilter === 'all') || (cardStatusGroup === currentStatusFilter);
        let matchArea = (!selectedArea) || (cardAreaId === selectedArea);
        let matchDriver = (!selectedDriver) || (cardDriverId === selectedDriver);

        if (matchQuery && matchStatus && matchArea && matchDriver) {
            card.classList.remove('d-none');
            visibleCount++;
        } else {
            card.classList.add('d-none');
        }
    });

    // Sorting visible cards
    if (selectedSort !== 'default') {
        const container = document.getElementById('deliveryCardsContainer');
        if (container) {
            cards.sort((a, b) => {
                if (selectedSort === 'shop_asc') {
                    const shopA = a.getAttribute('data-shop') || '';
                    const shopB = b.getAttribute('data-shop') || '';
                    return shopA.localeCompare(shopB);
                } else if (selectedSort === 'due_desc') {
                    const dueA = parseFloat(a.getAttribute('data-due') || 0);
                    const dueB = parseFloat(b.getAttribute('data-due') || 0);
                    return dueB - dueA;
                } else if (selectedSort === 'amount_desc') {
                    const amtA = parseFloat(a.getAttribute('data-amount') || 0);
                    const amtB = parseFloat(b.getAttribute('data-amount') || 0);
                    return amtB - amtA;
                }
                return 0;
            });
            cards.forEach(card => container.appendChild(card));
        }
    }

    // Update Counter
    const visibleCountElem = document.getElementById('visibleStopsCount');
    if (visibleCountElem) visibleCountElem.textContent = visibleCount;

    // Toggle Empty Results Alert
    const noResults = document.getElementById('noMatchingResultsState');
    if (noResults) {
        if (visibleCount === 0 && cards.length > 0) {
            noResults.classList.remove('d-none');
        } else {
            noResults.classList.add('d-none');
        }
    }

    // Synchronize URL parameters without page reload
    if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        if (query) url.searchParams.set('search', query); else url.searchParams.delete('search');
        if (currentStatusFilter !== 'all') url.searchParams.set('status', currentStatusFilter); else url.searchParams.delete('status');
        if (selectedArea) url.searchParams.set('area_id', selectedArea); else url.searchParams.delete('area_id');
        if (selectedDriver) url.searchParams.set('delivery_man_id', selectedDriver); else url.searchParams.delete('delivery_man_id');
        if (selectedSort !== 'default') url.searchParams.set('sort', selectedSort); else url.searchParams.delete('sort');
        window.history.replaceState({}, '', url.toString());
    }
}

// Reset All Filters in 1 Click
function resetAllDeliveryFilters() {
    const searchInput = document.getElementById('quickSearchInput');
    if (searchInput) searchInput.value = '';
    document.getElementById('clearSearchBtn')?.classList.add('d-none');

    const areaSelect = document.getElementById('areaFilterSelect');
    if (areaSelect) areaSelect.value = '';

    const driverSelect = document.getElementById('driverFilterSelect');
    if (driverSelect) driverSelect.value = '';

    const sortSelect = document.getElementById('sortFilterSelect');
    if (sortSelect) sortSelect.value = 'default';

    const allPill = document.querySelector('.status-pill[data-status="all"]');
    setStatusFilter('all', allPill);
}

// Keyboard shortcuts: Escape clears search
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const searchInput = document.getElementById('quickSearchInput');
        if (searchInput && document.activeElement === searchInput) {
            clearSearchInput();
        }
    }
});

// Run initial filter check on load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('quickSearchInput')?.value) {
        applyDeliveryFilters();
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
