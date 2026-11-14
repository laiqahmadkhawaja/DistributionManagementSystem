<?php
/**
 * Dispatch Load Sheet & Booker Order Consolidation Report
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

// Authorized for management, warehouse, delivery, and sales audit
$canView = hasPermission('reports', 'sales')
    || hasPermission('warehouse', 'view')
    || hasPermission('delivery', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STOREKEEPER, ROLE_ORDER_BOOKER, ROLE_DELIVERY_MAN, ROLE_ACCOUNTANT]);

if (!$canView) {
    requirePermission('reports', 'sales');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$mode = sanitize($_GET['mode'] ?? 'dispatch'); // 'dispatch' or 'booker'
$bookerId = (int)($_GET['booker_id'] ?? 0);
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);
$status = sanitize($_GET['status'] ?? 'all');

// Parse Multi-Area Selection (e.g. area_ids=1,2)
$selectedAreaIds = [];
if (!empty($_GET['area_ids'])) {
    if (is_array($_GET['area_ids'])) {
        $selectedAreaIds = array_filter(array_map('intval', $_GET['area_ids']));
    } else {
        $selectedAreaIds = array_filter(array_map('intval', explode(',', $_GET['area_ids'])));
    }
} elseif ($areaId > 0) {
    $selectedAreaIds = [$areaId];
}

// Route Cadence & Scheduled Period Key for Dispatch Date
$cadence = getSetting($pdo, 'route_assignment_cadence', 'weekly');
if ($cadence === 'daily') {
    $periodKey = $date;
} elseif ($cadence === 'monthly') {
    $periodKey = date('Y-m', strtotime($date));
} elseif ($cadence === 'fixed') {
    $periodKey = 'ALL';
} else {
    $periodKey = date('o-\WW', strtotime($date));
}

// Fetch dropdown filter options
$bookers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$deliveryStaff = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$allAreas = $pdo->query("SELECT id, name, code, city FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Pre-compute Staff-to-Assigned-Areas Mapping for Dynamic Filtering
$bookerAreasMap = [];
foreach ($bookers as $b) {
    $bId = (int)$b['id'];
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.code
        FROM areas a
        LEFT JOIN employee_area_assignments eaa ON a.id = eaa.area_id AND eaa.employee_id = ? AND (eaa.schedule_key = ? OR eaa.schedule_key = 'ALL')
        WHERE a.status = 'active' AND (eaa.id IS NOT NULL OR a.assigned_order_booker_id = ?)
        ORDER BY a.name ASC
    ");
    $stmt->execute([$bId, $periodKey, $bId]);
    $bookerAreasMap[$bId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$riderAreasMap = [];
foreach ($deliveryStaff as $ds) {
    $dsId = (int)$ds['id'];
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.code
        FROM areas a
        LEFT JOIN employee_area_assignments eaa ON a.id = eaa.area_id AND eaa.employee_id = ? AND (eaa.schedule_key = ? OR eaa.schedule_key = 'ALL')
        WHERE a.status = 'active' AND (eaa.id IS NOT NULL OR a.assigned_rider_id = ?)
        ORDER BY a.name ASC
    ");
    $stmt->execute([$dsId, $periodKey, $dsId]);
    $riderAreasMap[$dsId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Booker Daily Leaderboard Summary Query
$bookerSummaryStmt = $pdo->prepare("
    SELECT 
        e.id, e.full_name, e.employee_code, e.phone,
        COUNT(DISTINCT so.id) as orders_booked,
        COUNT(DISTINCT so.customer_id) as shops_booked,
        COALESCE(SUM(so.grand_total), 0) as total_booked_value,
        COALESCE(SUM(soi.quantity), 0) as total_units_booked
    FROM employees e
    LEFT JOIN sales_orders so ON e.id = so.order_booker_id AND so.order_date = ? AND so.status NOT IN ('draft', 'rejected', 'cancelled')
    LEFT JOIN sales_order_items soi ON so.id = soi.sales_order_id
    WHERE e.role_type IN ('order_booker', 'salesman') AND e.status = 'active'
    GROUP BY e.id, e.full_name, e.employee_code, e.phone
    ORDER BY total_booked_value DESC
");
$bookerSummaryStmt->execute([$date]);
$bookerDailyStats = $bookerSummaryStmt->fetchAll();

// Build Base Where Clause for Orders
$whereConditions = ["so.order_date = :order_date"];
$params = [':order_date' => $date];

if ($status === 'all') {
    $whereConditions[] = "so.status NOT IN ('draft', 'rejected', 'cancelled')";
} else {
    $whereConditions[] = "so.status = :status";
    $params[':status'] = $status;
}

if ($bookerId > 0) {
    $whereConditions[] = "so.order_booker_id = :booker_id";
    $params[':booker_id'] = $bookerId;
}

if (!empty($selectedAreaIds)) {
    $phList = [];
    foreach ($selectedAreaIds as $idx => $aIdVal) {
        $phKey = ":aid_" . $idx;
        $phList[] = $phKey;
        $params[$phKey] = $aIdVal;
    }
    $whereConditions[] = "so.area_id IN (" . implode(',', $phList) . ")";
}

if ($deliveryManId > 0) {
    $whereConditions[] = "so.id IN (SELECT sales_order_id FROM delivery_assignments WHERE delivery_man_id = :delivery_man_id)";
    $params[':delivery_man_id'] = $deliveryManId;
}

$whereSql = implode(' AND ', $whereConditions);

// 1. Consolidated Product Loading Matrix (Total Quantities per SKU)
$skuSql = "
    SELECT 
        p.id as product_id,
        p.sku,
        p.name as product_name,
        c.name as category_name,
        u.name as unit_name,
        u.code as unit_code,
        SUM(soi.quantity) as total_qty,
        AVG(soi.unit_price) as avg_price,
        SUM(soi.line_total) as total_value
    FROM sales_order_items soi
    JOIN sales_orders so ON soi.sales_order_id = so.id
    JOIN products p ON soi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN units u ON soi.unit_id = u.id
    WHERE {$whereSql}
    GROUP BY p.id, p.sku, p.name, c.name, u.name, u.code
    ORDER BY c.name ASC, p.name ASC
";
$skuStmt = $pdo->prepare($skuSql);
$skuStmt->execute($params);
$skuItems = $skuStmt->fetchAll();

// 2. Customer-by-Customer Orders Breakdown for Delivery
$ordersSql = "
    SELECT 
        so.id,
        so.order_number,
        so.order_date,
        so.grand_total,
        so.payment_type,
        so.status,
        c.shop_name,
        c.owner_name,
        c.phone as cust_phone,
        a.name as area_name,
        eb.full_name as booker_name,
        ed.full_name as delivery_name,
        (SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = so.id) as item_count
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    LEFT JOIN areas a ON so.area_id = a.id
    LEFT JOIN employees eb ON so.order_booker_id = eb.id
    LEFT JOIN delivery_assignments da ON so.id = da.sales_order_id
    LEFT JOIN employees ed ON da.delivery_man_id = ed.id
    WHERE {$whereSql}
    ORDER BY a.name ASC, c.shop_name ASC
";
$ordersStmt = $pdo->prepare($ordersSql);
$ordersStmt->execute($params);
$ordersList = $ordersStmt->fetchAll();

// Metrics Summary
$totalOrders = count($ordersList);
$totalDistinctSkus = count($skuItems);
$totalUnitsQty = 0;
$totalLoadValue = 0.00;
foreach ($skuItems as $item) {
    $totalUnitsQty += (float)$item['total_qty'];
    $totalLoadValue += (float)$item['total_value'];
}

$pageTitle = 'Dispatch Load Sheet - ' . date('d M Y', strtotime($date));
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
/* Clean Compact High-Density Table & Excel View Styling */
.table-excel-dense {
    font-size: 0.84rem;
    border: 1px solid #000 !important;
}
.table-excel-dense th {
    background-color: #f1f5f9 !important;
    color: #0f172a !important;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.74rem;
    letter-spacing: 0.5px;
    border: 1px solid #475569 !important;
    padding: 5px 7px !important;
    vertical-align: middle;
}
.table-excel-dense td {
    border: 1px solid #94a3b8 !important;
    padding: 4px 7px !important;
    vertical-align: middle;
    color: #1e293b;
}
.excel-box {
    border: 1px solid #000;
    padding: 4px 8px;
    font-size: 0.82rem;
    background: #fff;
}
@media print {
    @page {
        size: A4 portrait;
        margin: 8mm 6mm;
    }
    body {
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, sans-serif !important;
        font-size: 8.5pt !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .dms-layout-container, .dms-main-content {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    header.navbar, .sidebar, .dms-sidebar, .d-print-none, .flash-container {
        display: none !important;
    }
    .table-excel-dense th {
        background-color: #e2e8f0 !important;
        border: 1px solid #000 !important;
        color: #000 !important;
        font-size: 7.5pt !important;
        padding: 3px 5px !important;
    }
    .table-excel-dense td {
        border: 1px solid #333 !important;
        padding: 3px 5px !important;
        font-size: 8pt !important;
    }
    .print-sign-box {
        border-top: 1px solid #000;
        margin-top: 25px;
        padding-top: 4px;
        text-align: center;
        font-size: 7.5pt;
        font-weight: bold;
    }
}
</style>

<div class="dms-loadsheet-wrapper">
    <!-- Header Controls (Hidden on Print) -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
        <div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Reports Hub
                </a>
                <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-truck-flatbed text-primary me-2"></i>Dispatch Load Sheet (لوڈ شیٹ)</h4>
            </div>
            <small class="text-muted">Consolidated vehicle loading summary & booker order delivery dispatch sheet</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-clock-history me-1"></i> Daily Day Closing
            </a>
            <a href="<?= BASE_URL ?>/modules/reports/loadsheet_print.php?date=<?= $date ?>&mode=<?= $mode ?>&booker_id=<?= $bookerId ?>&delivery_man_id=<?= $deliveryManId ?>&area_ids=<?= urlencode(implode(',', $selectedAreaIds)) ?>&area_id=<?= $areaId ?>&autoprint=1" target="_blank" class="btn btn-sm btn-primary">
                <i class="bi bi-printer me-1"></i> Print 1-Page Minimal (Excel View)
            </a>
            <button type="button" class="btn btn-sm btn-success" onclick="exportLoadsheetToCSV();">
                <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.CSV)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="shareLoadsheetWhatsApp();">
                <i class="bi bi-whatsapp me-1 text-success"></i> Share WhatsApp
            </button>
        </div>
    </div>

    <!-- Mode Selector Navigation Pills (Vehicle Dispatch vs Booker Daily Summary) -->
    <ul class="nav nav-pills mb-3 d-print-none gap-2">
        <li class="nav-item">
            <a class="nav-link <?= $mode === 'dispatch' ? 'active' : 'bg-light text-dark border' ?> fw-semibold py-1 px-3" href="<?= BASE_URL ?>/modules/reports/loadsheet.php?mode=dispatch&date=<?= $date ?>&booker_id=<?= $bookerId ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>">
                <i class="bi bi-truck-flatbed me-1"></i> 1. Vehicle Dispatch Load Sheet (گاڑی کی لوڈ شیٹ)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $mode === 'booker' ? 'active' : 'bg-light text-dark border' ?> fw-semibold py-1 px-3" href="<?= BASE_URL ?>/modules/reports/loadsheet.php?mode=booker&date=<?= $date ?>&booker_id=<?= $bookerId ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>">
                <i class="bi bi-person-badge me-1"></i> 2. Order Booker Daily Booking Sheet (آرڈر بکر وائز ڈیلی سمری)
            </a>
        </li>
    </ul>

    <!-- Booker Daily Performance Leaderboard (Shown in Booker Mode) -->
    <?php if ($mode === 'booker' && !empty($bookerDailyStats)): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
            <div class="card-header bg-white py-2 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-people-fill text-primary me-1"></i> Today's Field Order Bookers Activity (آج کی فیلڈ بکنگ سمری)
                </h6>
                <span class="small text-muted"><?= count($bookerDailyStats) ?> sales reps active</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Order Booker</th>
                            <th>Code</th>
                            <th class="text-center">Shops Booked</th>
                            <th class="text-center">Orders Count</th>
                            <th class="text-end">Units Booked</th>
                            <th class="text-end">Gross Booked Value</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookerDailyStats as $bStat): ?>
                            <tr class="<?= $bookerId === (int)$bStat['id'] ? 'table-primary fw-bold' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($bStat['full_name']) ?></strong>
                                    <span class="text-muted small ms-1">(<?= htmlspecialchars($bStat['phone']) ?>)</span>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($bStat['employee_code']) ?></span></td>
                                <td class="text-center fw-bold"><?= $bStat['shops_booked'] ?> shops</td>
                                <td class="text-center"><?= $bStat['orders_booked'] ?> orders</td>
                                <td class="text-end"><?= number_format((float)$bStat['total_units_booked'], 2) ?></td>
                                <td class="text-end fw-bold text-success">Rs. <?= number_format((float)$bStat['total_booked_value'], 2) ?></td>
                                <td class="text-end pe-3">
                                    <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php?mode=booker&date=<?= $date ?>&booker_id=<?= $bStat['id'] ?>" class="btn btn-sm <?= $bookerId === (int)$bStat['id'] ? 'btn-primary' : 'btn-outline-primary' ?> py-0 px-2" title="Filter this Booker's Load Sheet">
                                        <i class="bi bi-funnel"></i> View Sheet
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Card (Hidden on Print) -->
    <div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
        <div class="card-body p-3">
            <form method="GET" action="<?= BASE_URL ?>/modules/reports/loadsheet.php" id="loadsheetFilterForm" class="row g-2 align-items-end">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                <input type="hidden" name="area_ids" id="areaIdsInput" value="<?= htmlspecialchars(implode(',', $selectedAreaIds)) ?>">

                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1">Dispatch Date</label>
                    <input type="date" class="form-control form-control-sm" name="date" value="<?= htmlspecialchars($date) ?>" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold mb-1">Order Booker</label>
                    <select name="booker_id" id="bookerSelect" class="form-select form-select-sm">
                        <option value="0">-- All Order Bookers --</option>
                        <?php foreach ($bookers as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $bookerId === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold mb-1">Delivery Man / Driver</label>
                    <select name="delivery_man_id" id="deliveryManSelect" class="form-select form-select-sm">
                        <option value="0">-- All Delivery Staff --</option>
                        <?php foreach ($deliveryStaff as $ds): ?>
                            <option value="<?= $ds['id'] ?>" <?= $deliveryManId === (int)$ds['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ds['full_name']) ?> (<?= htmlspecialchars($ds['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold mb-0">Market / Territory</label>
                        <span id="areaScopeBadge" class="badge bg-secondary-subtle text-dark border small" style="font-size: 0.68rem;">All</span>
                    </div>
                    <select name="area_id" id="areaSelect" class="form-select form-select-sm">
                        <!-- Populated dynamically based on staff selection and cadence -->
                        <option value="0">-- All Areas --</option>
                        <?php foreach ($allAreas as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (in_array((int)$a['id'], $selectedAreaIds) || $areaId === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php?date=<?= urlencode($date) ?>&mode=<?= urlencode($mode) ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Printable Excel-View Load Sheet Container -->
    <div id="printableLoadSheetArea" class="bg-white p-3 p-print-0 border rounded-3 mb-4 shadow-sm">
        <!-- Print Header -->
        <div class="border-bottom pb-2 mb-2">
            <div class="row align-items-center">
                <div class="col-7">
                    <h5 class="fw-bold mb-0 text-dark text-uppercase"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h5>
                    <div class="small text-muted" style="font-size: 0.78rem;">
                        <?= htmlspecialchars($company['address'] ?? 'Central Logistics Terminal') ?> Â· Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 1234567') ?>
                    </div>
                </div>
                <div class="col-5 text-end">
                    <div class="badge bg-dark fs-6 px-2 py-1 text-uppercase"><?= $mode === "booker" ? "BOOKER BOOKING SUMMARY / لوڈ شیٹ برائے آرڈر بکر" : "VEHICLE LOAD SHEET / لوڈ شیٹ برائے ترسیل" ?></div>
                    <div class="small fw-bold text-dark mt-1">Date: <?= date('d-M-Y (l)', strtotime($date)) ?></div>
                </div>
            </div>
        </div>

        <!-- Meta Information Matrix Box -->
        <div class="row g-2 mb-2">
            <div class="col-3">
                <div class="excel-box">
                    <span class="text-muted small d-block">Order Booker:</span>
                    <strong><?= $bookerId ? htmlspecialchars($bookers[array_search($bookerId, array_column($bookers, 'id'))]['full_name'] ?? 'Selected') : 'Consolidated (All)' ?></strong>
                </div>
            </div>
            <div class="col-3">
                <div class="excel-box">
                    <span class="text-muted small d-block">Delivery / Route:</span>
                    <strong><?= $deliveryManId ? htmlspecialchars($deliveryStaff[array_search($deliveryManId, array_column($deliveryStaff, 'id'))]['full_name'] ?? 'Selected') : 'Standard Fleet' ?></strong>
                </div>
            </div>
            <div class="col-3">
                <div class="excel-box">
                    <span class="text-muted small d-block">Territory / Area:</span>
                    <strong><?= $areaId ? htmlspecialchars($areas[array_search($areaId, array_column($areas, 'id'))]['name'] ?? 'Selected') : 'All Market Areas' ?></strong>
                </div>
            </div>
            <div class="col-3">
                <div class="excel-box bg-light text-end">
                    <span class="text-muted small d-block">Total Orders / Value:</span>
                    <strong class="text-primary"><?= $totalOrders ?> Orders Â· Rs. <?= number_format($totalLoadValue, 2) ?></strong>
                </div>
            </div>
        </div>

        <!-- Section 1: Consolidated Loading Summary for Warehouse -->
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-dark small text-uppercase">
                    <i class="bi bi-box-seam me-1"></i> 1. Consolidated Vehicle Loading Matrix (گاڑی میں لوڈ ہونے والا کل سامان)
                </span>
                <span class="small text-muted d-print-none"><?= $totalDistinctSkus ?> distinct items to load</span>
            </div>

            <table id="loadsheetTable" class="table table-excel-dense table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 35px;">#</th>
                        <th style="width: 85px;">SKU Code</th>
                        <th>Product Description & Packaging</th>
                        <th style="width: 100px;">Category</th>
                        <th class="text-center" style="width: 65px;">Unit</th>
                        <th class="text-end" style="width: 80px;">Booked Qty</th>
                        <th class="text-end" style="width: 90px;">Rate (TP)</th>
                        <th class="text-end" style="width: 100px;">Total Value</th>
                        <th class="text-center" style="width: 75px;">Loaded âœ“</th>
                        <th class="text-center" style="width: 75px;">Delivered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($skuItems)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-3 text-muted">No booked orders found matching the selected criteria for <?= htmlspecialchars($date) ?>.</td>
                        </tr>
                    <?php else: ?>
                        <?php $sr = 1; foreach ($skuItems as $it): ?>
                            <tr>
                                <td class="text-center fw-bold"><?= $sr++ ?></td>
                                <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($it['sku']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($it['product_name']) ?></strong>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($it['category_name'] ?? 'General') ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($it['unit_code'] ?? $it['unit_name']) ?></span>
                                </td>
                                <td class="text-end fw-bold fs-6">
                                    <?= number_format((float)$it['total_qty'], 2) ?>
                                </td>
                                <td class="text-end text-muted small">
                                    <?= number_format((float)$it['avg_price'], 2) ?>
                                </td>
                                <td class="text-end fw-bold">
                                    Rs. <?= number_format((float)$it['total_value'], 2) ?>
                                </td>
                                <td class="text-center bg-light">
                                    <span class="d-print-none text-muted small">[ Â  Â  ]</span>
                                </td>
                                <td class="text-center bg-light">
                                    <span class="d-print-none text-muted small">[ Â  Â  ]</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Table Total Summary Footer -->
                        <tr class="fw-bold" style="background-color: #f8fafc;">
                            <td colspan="5" class="text-end text-uppercase">Total Vehicle Load Summary:</td>
                            <td class="text-end fs-6 text-primary"><?= number_format($totalUnitsQty, 2) ?></td>
                            <td class="text-end">-</td>
                            <td class="text-end fs-6 text-dark">Rs. <?= number_format($totalLoadValue, 2) ?></td>
                            <td class="text-center">âœ“ Tally</td>
                            <td class="text-center">âœ“ Tally</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 2: Shop-Wise Delivery Distribution Details -->
        <div class="mt-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-dark small text-uppercase">
                    <i class="bi bi-shop me-1"></i> 2. Shop-by-Shop Delivery Drop List (دکان وار ڈیلیوری اور بلز)
                </span>
                <span class="small text-muted d-print-none"><?= $totalOrders ?> shop orders</span>
            </div>

            <table class="table table-excel-dense table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 35px;">#</th>
                        <th style="width: 100px;">Order #</th>
                        <th>Shop / Customer Name</th>
                        <th style="width: 110px;">Market / Area</th>
                        <th style="width: 100px;">Booker</th>
                        <th class="text-center" style="width: 60px;">Lines</th>
                        <th class="text-center" style="width: 70px;">Terms</th>
                        <th class="text-end" style="width: 95px;">Bill Amount</th>
                        <th class="text-center" style="width: 80px;">Cash Recv.</th>
                        <th class="text-center" style="width: 80px;">Receiver Sign</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ordersList)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-2 text-muted">No shop orders scheduled.</td>
                        </tr>
                    <?php else: ?>
                        <?php $osr = 1; foreach ($ordersList as $ord): ?>
                            <tr>
                                <td class="text-center"><?= $osr++ ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $ord['id'] ?>" class="text-decoration-none fw-bold text-primary">
                                        <?= htmlspecialchars($ord['order_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($ord['shop_name']) ?></strong>
                                    <span class="text-muted small">(<?= htmlspecialchars($ord['owner_name']) ?>)</span>
                                </td>
                                <td><?= htmlspecialchars($ord['area_name'] ?? 'Central') ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($ord['booker_name'] ?? 'Direct') ?></td>
                                <td class="text-center"><?= $ord['item_count'] ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $ord['payment_type'] === 'cash' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size: 0.65rem;">
                                        <?= strtoupper($ord['payment_type']) ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold">
                                    Rs. <?= number_format((float)$ord['grand_total'], 2) ?>
                                </td>
                                <td class="text-center bg-light"></td>
                                <td class="text-center bg-light"></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold" style="background-color: #f8fafc;">
                            <td colspan="7" class="text-end text-uppercase">Total Delivery Recoverable Value:</td>
                            <td class="text-end fs-6 text-dark">Rs. <?= number_format($totalLoadValue, 2) ?></td>
                            <td colspan="2" class="text-center text-muted small">Physical Cash & Signatures</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Standard Operational Signatures for Logistics Clearance -->
        <div class="row mt-4 pt-2">
            <div class="col-3">
                <div class="print-sign-box">
                    Prepared By (Storekeeper)<br>
                    <span class="small text-muted">Warehouse Dispatch Staff</span>
                </div>
            </div>
            <div class="col-3">
                <div class="print-sign-box">
                    Loaded By (Warehouse)<br>
                    <span class="small text-muted">Stock Physical Count</span>
                </div>
            </div>
            <div class="col-3">
                <div class="print-sign-box">
                    Vehicle Driver / Incharge<br>
                    <span class="small text-muted">Received in Sound Condition</span>
                </div>
            </div>
            <div class="col-3">
                <div class="print-sign-box">
                    Security Gate Clearance<br>
                    <span class="small text-muted">Outward Stamp & Time Out</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportLoadsheetToCSV() {
    const table = document.getElementById('loadsheetTable');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s+)/g, ' ');
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvFile = new Blob([csv.join('\n')], {type: "text/csv;charset=utf-8;"});
    const downloadLink = document.createElement("a");
    downloadLink.download = "LoadSheet_<?= htmlspecialchars($date) ?>.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function shareLoadsheetWhatsApp() {
    const text = `*LOAD SHEET - <?= addslashes(htmlspecialchars($company['name'] ?? APP_NAME)) ?>*\n` +
                 `Date: <?= $date ?>\n` +
                 `Total Orders: <?= $totalOrders ?>\n` +
                 `Total Load Qty: <?= number_format($totalUnitsQty, 2) ?> units\n` +
                 `Total Value: Rs. <?= number_format($totalLoadValue, 2) ?>\n` +
                 `Status: Ready for Delivery`;
    window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
}

// Staff-to-Territory Area Mapping & Dynamic Filter Synchronization
const staffAreasData = {
    cadence: <?= json_encode($cadence) ?>,
    bookers: <?= json_encode($bookerAreasMap) ?>,
    riders: <?= json_encode($riderAreasMap) ?>,
    all: <?= json_encode($allAreas) ?>,
    selectedIds: <?= json_encode($selectedAreaIds) ?>,
    selectedAreaId: <?= (int)$areaId ?>
};

function syncTerritoryOptions() {
    const bookerVal = document.getElementById('bookerSelect')?.value || '0';
    const riderVal = document.getElementById('deliveryManSelect')?.value || '0';
    const areaSelect = document.getElementById('areaSelect');
    const badge = document.getElementById('areaScopeBadge');
    if (!areaSelect) return;

    let targetAreas = [];
    let isScoped = false;

    if (bookerVal !== '0' && staffAreasData.bookers[bookerVal]) {
        targetAreas = staffAreasData.bookers[bookerVal];
        isScoped = true;
    } else if (riderVal !== '0' && staffAreasData.riders[riderVal]) {
        targetAreas = staffAreasData.riders[riderVal];
        isScoped = true;
    } else {
        targetAreas = staffAreasData.all;
    }

    const currentVal = areaSelect.value;
    areaSelect.innerHTML = '';

    if (isScoped) {
        if (badge) {
            badge.className = 'badge bg-primary text-white border small';
            badge.textContent = 'Assigned (' + targetAreas.length + ')';
        }

        if (targetAreas.length > 0) {
            const allAssignedOpt = document.createElement('option');
            allAssignedOpt.value = 'all_assigned';
            allAssignedOpt.textContent = `-- All Assigned Areas (${targetAreas.length} Areas) --`;
            areaSelect.appendChild(allAssignedOpt);

            targetAreas.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name + (a.code ? ` (${a.code})` : '');
                if (staffAreasData.selectedAreaId == a.id || (staffAreasData.selectedIds.length === 1 && staffAreasData.selectedIds[0] == a.id)) {
                    opt.selected = true;
                }
                areaSelect.appendChild(opt);
            });

            if (staffAreasData.selectedIds.length > 1) {
                areaSelect.value = 'all_assigned';
            }
        } else {
            const opt = document.createElement('option');
            opt.value = '0';
            opt.textContent = `-- No Areas Assigned (${staffAreasData.cadence}) --`;
            areaSelect.appendChild(opt);
        }
    } else {
        if (badge) {
            badge.className = 'badge bg-secondary-subtle text-dark border small';
            badge.textContent = 'All';
        }
        const allOpt = document.createElement('option');
        allOpt.value = '0';
        allOpt.textContent = `-- All Areas (${targetAreas.length} Areas) --`;
        areaSelect.appendChild(allOpt);

        targetAreas.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.name + (a.code ? ` (${a.code})` : '');
            if (staffAreasData.selectedAreaId == a.id) {
                opt.selected = true;
            }
            areaSelect.appendChild(opt);
        });
    }
}

document.getElementById('loadsheetFilterForm')?.addEventListener('submit', function() {
    const areaSelect = document.getElementById('areaSelect');
    const hiddenIds = document.getElementById('areaIdsInput');
    const bookerVal = document.getElementById('bookerSelect')?.value || '0';
    const riderVal = document.getElementById('deliveryManSelect')?.value || '0';

    if (areaSelect && areaSelect.value === 'all_assigned') {
        let assigned = [];
        if (bookerVal !== '0' && staffAreasData.bookers[bookerVal]) {
            assigned = staffAreasData.bookers[bookerVal];
        } else if (riderVal !== '0' && staffAreasData.riders[riderVal]) {
            assigned = staffAreasData.riders[riderVal];
        }
        if (assigned.length > 0) {
            hiddenIds.value = assigned.map(a => a.id).join(',');
            areaSelect.value = '0';
        }
    } else if (areaSelect && areaSelect.value !== '0') {
        hiddenIds.value = areaSelect.value;
    } else {
        hiddenIds.value = '';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const bSel = document.getElementById('bookerSelect');
    const rSel = document.getElementById('deliveryManSelect');
    if (bSel) bSel.addEventListener('change', syncTerritoryOptions);
    if (rSel) rSel.addEventListener('change', syncTerritoryOptions);
    syncTerritoryOptions();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
