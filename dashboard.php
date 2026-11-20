<?php
/**
 * Master Dashboard (Role-Aware & Real Transactional Metrics)
 * Enterprise Distribution Management System (DMS)
 */

require_once __DIR__ . '/config/app.php';
requireLogin();

$pdo = getDatabaseConnection();
$user = getCurrentUser();
$roleSlug = $user['role_slug'];
$pageTitle = 'Dashboard';

// Date filter
$period = sanitize($_GET['period'] ?? 'this_month');
$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

// Fetch Real Transactional Metrics directly from database
$totalSalesToday = (float)$pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM `sales_orders` WHERE `order_date` = '{$today}' AND `status` != 'cancelled'")->fetchColumn();
$totalSalesMonth = (float)$pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM `sales_orders` WHERE `order_date` >= '{$firstDayOfMonth}' AND `status` != 'cancelled'")->fetchColumn();
$totalPurchases = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM `goods_receipts`")->fetchColumn();
$totalReceivables = (float)$pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM `customers` WHERE current_balance > 0")->fetchColumn();
$totalPayables = (float)$pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM `suppliers` WHERE current_balance > 0")->fetchColumn();

// Cash and Bank from Double-Entry Journal / Chart of Accounts
$cashAcc = getSystemAccount($pdo, 'cash');
$bankAcc = getSystemAccount($pdo, 'bank');

$cashBalance = (float)$pdo->query("SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM `journal_entry_lines` WHERE `account_id` = {$cashAcc['id']}")->fetchColumn();
$bankBalance = (float)$pdo->query("SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM `journal_entry_lines` WHERE `account_id` = {$bankAcc['id']}")->fetchColumn();

// Profit & Loss calculations (Gross Profit = Net Sales - COGS, Net Profit = Gross Profit - Operating Expenses)
$cogsAcc = getSystemAccount($pdo, 'cogs');
$salesRevAcc = getSystemAccount($pdo, 'sales_revenue');

$revenueVal = (float)$pdo->query("SELECT COALESCE(SUM(credit) - SUM(debit), 0) FROM `journal_entry_lines` WHERE `account_id` = {$salesRevAcc['id']}")->fetchColumn();
$cogsVal = (float)$pdo->query("SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM `journal_entry_lines` WHERE `account_id` = {$cogsAcc['id']}")->fetchColumn();
$grossProfit = $revenueVal - $cogsVal;

$totalExpenses = (float)$pdo->query("
    SELECT COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) 
    FROM `journal_entry_lines` jel
    JOIN `chart_of_accounts` coa ON jel.account_id = coa.id
    WHERE coa.group_type = 'Expense'
")->fetchColumn();

$netProfit = $grossProfit - $totalExpenses;

// Operational Counters
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM `products` WHERE `status` = 'active'")->fetchColumn();
$totalShops = (int)$pdo->query("SELECT COUNT(*) FROM `customers` WHERE `status` = 'active'")->fetchColumn();
$activeBookers = (int)$pdo->query("SELECT COUNT(*) FROM `employees` WHERE `role_type` = 'order_booker' AND `status` = 'active'")->fetchColumn();
$activeDeliveryMen = (int)$pdo->query("SELECT COUNT(*) FROM `employees` WHERE `role_type` = 'delivery_man' AND `status` = 'active'")->fetchColumn();

$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` IN ('submitted', 'pending_approval')")->fetchColumn();
$readyForDispatch = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` = 'approved'")->fetchColumn();
$deliveredOrders = (int)$pdo->query("SELECT COUNT(*) FROM `sales_orders` WHERE `status` = 'delivered'")->fetchColumn();

// Low Stock Alerts (Stock <= Reorder Level)
$lowStockItems = $pdo->query("
    SELECT p.id, p.name, p.code, p.reorder_level, u.name as unit_name,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as current_stock
    FROM `products` p
    JOIN `units` u ON p.base_unit_id = u.id
    LEFT JOIN `stock_ledger` sl ON p.id = sl.product_id
    WHERE p.status = 'active'
    GROUP BY p.id, p.name, p.code, p.reorder_level, u.name
    HAVING current_stock <= p.reorder_level
    LIMIT 5
")->fetchAll();

// Recent Sales Orders
$recentOrders = $pdo->query("
    SELECT so.*, c.shop_name, e.full_name as booker_name, a.name as area_name
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `employees` e ON so.order_booker_id = e.id
    JOIN `areas` a ON so.area_id = a.id
    ORDER BY so.id DESC LIMIT 6
")->fetchAll();

// 1. Chart: 7-Day Sales Trend Data
$trendLabels = [];
$trendValues = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} days"));
    $trendLabels[$dateKey] = date('D, d M', strtotime($dateKey));
    $trendValues[$dateKey] = 0.00;
}
$sevenDaysAgo = date('Y-m-d', strtotime('-6 days'));
$trendStmt = $pdo->prepare("
    SELECT order_date, COALESCE(SUM(grand_total), 0) as day_total
    FROM `sales_orders`
    WHERE order_date >= ? AND status != 'cancelled'
    GROUP BY order_date
");
$trendStmt->execute([$sevenDaysAgo]);
while ($r = $trendStmt->fetch()) {
    if (isset($trendValues[$r['order_date']])) {
        $trendValues[$r['order_date']] = (float)$r['day_total'];
    }
}
$trendChartLabels = array_values($trendLabels);
$trendChartValues = array_values($trendValues);

// 2. Chart: P&L Doughnut Breakdown
$chartPnlLabels = ['Sales Revenue', 'COGS', 'Expenses', 'Net Profit'];
$chartPnlValues = [
    round(max(0, $revenueVal), 2),
    round(max(0, $cogsVal), 2),
    round(max(0, $totalExpenses), 2),
    round(max(0, $netProfit), 2)
];

// 3. Chart: Area-Wise Sales Distribution
$areaChartStmt = $pdo->query("
    SELECT a.name as area_name, COALESCE(SUM(so.grand_total), 0) as total_sales
    FROM `areas` a
    LEFT JOIN `sales_orders` so ON a.id = so.area_id AND so.status != 'cancelled'
    WHERE a.status = 'active'
    GROUP BY a.id, a.name
    ORDER BY total_sales DESC
    LIMIT 6
");
$areaChartLabels = [];
$areaChartValues = [];
while ($row = $areaChartStmt->fetch()) {
    $areaChartLabels[] = $row['area_name'];
    $areaChartValues[] = (float)$row['total_sales'];
}

// 4. Chart: Top 5 Selling Products
$topProdStmt = $pdo->query("
    SELECT p.id, p.name as product_name, p.code as product_code, COALESCE(SUM(soi.line_total), 0) as product_sales
    FROM `sales_order_items` soi
    JOIN `products` p ON soi.product_id = p.id
    JOIN `sales_orders` so ON soi.sales_order_id = so.id AND so.status != 'cancelled'
    GROUP BY p.id, p.name, p.code
    ORDER BY product_sales DESC
    LIMIT 5
");
$topProductsList = $topProdStmt->fetchAll(PDO::FETCH_ASSOC);
$topProdLabels = [];
$topProdValues = [];
foreach ($topProductsList as $row) {
    $topProdLabels[] = $row['product_name'];
    $topProdValues[] = (float)$row['product_sales'];
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Mobile Quick Action Shortcuts -->
<div class="d-md-none mb-3">
    <div class="row g-2">
        <div class="col-6">
            <a href="<?= BASE_URL ?>/modules/orders/book.php" class="btn btn-primary w-100 py-2 d-flex flex-column align-items-center rounded-3 shadow-sm">
                <i class="bi bi-cart-plus fs-4"></i>
                <span class="small fw-bold mt-1">Book New Order</span>
            </a>
        </div>
        <div class="col-6">
            <a href="<?= BASE_URL ?>/modules/customers/create.php" class="btn btn-outline-primary w-100 py-2 d-flex flex-column align-items-center rounded-3 shadow-sm bg-white">
                <i class="bi bi-shop-window fs-4"></i>
                <span class="small fw-bold mt-1">Register Shop</span>
            </a>
        </div>
    </div>
</div>

<!-- Page Title & Greeting -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark">Business Overview</h4>
        <small class="text-muted">Role: <span class="fw-semibold text-primary"><?= htmlspecialchars($user['role_name']) ?></span> · Live Ledger Figures</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php" class="btn btn-sm btn-warning fw-bold d-inline-flex align-items-center shadow-sm">
            <i class="bi bi-lightning-charge-fill me-1"></i> Quick Booking
        </a>
        <a href="<?= BASE_URL ?>/modules/warehouse/stock_issue_print.php" target="_blank" class="btn btn-sm btn-outline-primary d-none d-md-inline-flex align-items-center">
            <i class="bi bi-truck me-1"></i> Load Form
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php" class="btn btn-sm btn-outline-success d-none d-md-inline-flex align-items-center">
            <i class="bi bi-safe2 me-1"></i> Day Closing
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center">
            <i class="bi bi-graph-up me-1"></i> Reports
        </a>
    </div>
</div>

<!-- Daily Traditional FMCG Operations Hub -->
<div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center border-bottom gap-2">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-box-seam text-primary me-2"></i>Daily FMCG Distribution Desk
            </h5>
            <small class="text-muted">Standard daily workflow for order booking, van load forms, billing and cash reconciliation</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/modules/products/import.php" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-excel me-1"></i> Import Products
            </a>
            <a href="<?= BASE_URL ?>/modules/customers/import.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-people me-1"></i> Import Shops & Opening Balances
            </a>
            <a href="<?= BASE_URL ?>/modules/reports/parties_credit.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-journal-bookmark me-1"></i> Parties Credit Ledger
            </a>
        </div>
    </div>
    <div class="card-body p-3">
        <div class="row g-2">
            <!-- Step 1: Quick Booking -->
            <div class="col-md-2 col-6">
                <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php" class="card h-100 border text-decoration-none text-dark p-3 text-center rounded-3 bg-light hover-shadow" style="transition: transform 0.15s ease;">
                    <div class="mb-2">
                        <span class="badge bg-warning text-dark rounded-pill px-2 py-1">Step 1</span>
                    </div>
                    <i class="bi bi-lightning-charge-fill text-warning fs-3 mb-2 d-block"></i>
                    <strong class="d-block text-truncate" style="font-size: 0.9rem;">Order Booking</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">Enter Booker Slips</small>
                </a>
            </div>
            <!-- Step 2: Load Form -->
            <div class="col-md-2 col-6">
                <a href="<?= BASE_URL ?>/modules/warehouse/stock_issue_print.php" target="_blank" class="card h-100 border text-decoration-none text-dark p-3 text-center rounded-3 bg-light hover-shadow" style="transition: transform 0.15s ease;">
                    <div class="mb-2">
                        <span class="badge bg-primary rounded-pill px-2 py-1">Step 2</span>
                    </div>
                    <i class="bi bi-truck text-primary fs-3 mb-2 d-block"></i>
                    <strong class="d-block text-truncate" style="font-size: 0.9rem;">Master Load Form</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">Consolidated Gatepass</small>
                </a>
            </div>
            <!-- Step 3: Print Bills -->
            <div class="col-md-3 col-6">
                <a href="<?= BASE_URL ?>/modules/orders/print_batch.php" target="_blank" class="card h-100 border text-decoration-none text-dark p-3 text-center rounded-3 bg-light hover-shadow" style="transition: transform 0.15s ease;">
                    <div class="mb-2">
                        <span class="badge bg-info text-dark rounded-pill px-2 py-1">Step 3</span>
                    </div>
                    <i class="bi bi-printer-fill text-info fs-3 mb-2 d-block"></i>
                    <strong class="d-block text-truncate" style="font-size: 0.9rem;">Print Batch Bills</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">A4 (Full) / A5 (Half Sheet)</small>
                </a>
            </div>
            <!-- Step 4: Run Sheet -->
            <div class="col-md-2 col-6">
                <a href="<?= BASE_URL ?>/modules/delivery/sheet_print.php" target="_blank" class="card h-100 border text-decoration-none text-dark p-3 text-center rounded-3 bg-light hover-shadow" style="transition: transform 0.15s ease;">
                    <div class="mb-2">
                        <span class="badge bg-success rounded-pill px-2 py-1">Step 4</span>
                    </div>
                    <i class="bi bi-file-earmark-spreadsheet text-success fs-3 mb-2 d-block"></i>
                    <strong class="d-block text-truncate" style="font-size: 0.9rem;">Delivery Run Sheet</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">Daily Sale & Credit Sheet</small>
                </a>
            </div>
            <!-- Step 5: Reconcile -->
            <div class="col-md-3 col-12">
                <a href="<?= BASE_URL ?>/modules/delivery/sheet_reconcile.php" class="card h-100 border text-decoration-none text-dark p-3 text-center rounded-3 bg-light hover-shadow" style="transition: transform 0.15s ease;">
                    <div class="mb-2">
                        <span class="badge bg-danger rounded-pill px-2 py-1">Step 5</span>
                    </div>
                    <i class="bi bi-cash-stack text-success fs-3 mb-2 d-block"></i>
                    <strong class="d-block text-truncate" style="font-size: 0.9rem;">Evening Cash Reconcile</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">Post Cash, Udhaar & Returns</small>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Primary Financial KPIs Grid -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold">Sales Today</span>
                    <h5 class="fw-bold mt-1 mb-0 text-primary"><?= formatMoney($totalSalesToday) ?></h5>
                    <small class="text-muted" style="font-size: 0.75rem;">Month: <?= formatMoney($totalSalesMonth) ?></small>
                </div>
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-currency-dollar"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold">Receivables</span>
                    <h5 class="fw-bold mt-1 mb-0 text-danger"><?= formatMoney($totalReceivables) ?></h5>
                    <small class="text-muted" style="font-size: 0.75rem;">From <?= $totalShops ?> Shops</small>
                </div>
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="bi bi-arrow-down-left-circle"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold">Cash in Hand</span>
                    <h5 class="fw-bold mt-1 mb-0 text-success"><?= formatMoney($cashBalance) ?></h5>
                    <small class="text-muted" style="font-size: 0.75rem;">Bank: <?= formatMoney($bankBalance) ?></small>
                </div>
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-cash"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold">Net Profit</span>
                    <h5 class="fw-bold mt-1 mb-0 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMoney($netProfit) ?></h5>
                    <small class="text-muted" style="font-size: 0.75rem;">Gross: <?= formatMoney($grossProfit) ?></small>
                </div>
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-pie-chart"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Operational Status Badges Grid -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Pending Approval</div>
                        <h4 class="fw-bold mb-0 text-warning"><?= $pendingOrders ?></h4>
                    </div>
                    <a href="<?= BASE_URL ?>/modules/orders/index.php?status=submitted" class="btn btn-sm btn-outline-warning">
                        View Orders
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Ready for Dispatch</div>
                        <h4 class="fw-bold mb-0 text-info"><?= $readyForDispatch ?></h4>
                    </div>
                    <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-info">
                        Warehouse
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Delivered Orders</div>
                        <h4 class="fw-bold mb-0 text-success"><?= $deliveredOrders ?></h4>
                    </div>
                    <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="btn btn-sm btn-outline-success">
                        Deliveries
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Business Charts & Analytics Grid -->
<div class="row g-3 mb-4">
    <!-- Chart 1: 7-Day Sales Trend -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Sales Revenue Trend (Last 7 Days)</h6>
                    <small class="text-muted">Daily booked sales volume across all territories</small>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1">PKR Trend</span>
            </div>
            <div class="card-body pt-0 pb-3">
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart 2: Profit & Cost Structure Doughnut -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-pie-chart-fill me-2 text-info"></i>Income vs Costs</h6>
                    <small class="text-muted">Revenue, COGS, Expenses & Net Margin</small>
                </div>
            </div>
            <div class="card-body pt-0 pb-3">
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="pnlDoughnutChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart 3: Area-wise Sales Performance -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt-fill me-2 text-success"></i>Territory Sales Breakdown</h6>
                    <small class="text-muted">Sales booked per assigned delivery territory</small>
                </div>
            </div>
            <div class="card-body pt-0 pb-3">
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="areaSalesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart 4: Top 5 Selling Products -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-trophy-fill me-2 text-warning"></i>Top Selling Products</h6>
                    <small class="text-muted">Highest booked revenue by product name</small>
                </div>
            </div>
            <div class="card-body pt-0 pb-3">
                <div style="position: relative; height: 230px; width: 100%;">
                    <canvas id="topProductsChart"></canvas>
                </div>
                <?php if (!empty($topProductsList)): ?>
                <div class="mt-2 pt-2 border-top d-flex flex-wrap justify-content-between gap-1 small">
                    <?php 
                    $rankColors = ['text-warning', 'text-primary', 'text-success', 'text-info', 'text-secondary'];
                    foreach ($topProductsList as $idx => $tp): 
                        $colorClass = $rankColors[$idx % count($rankColors)];
                    ?>
                        <span class="badge bg-light text-dark border">
                            <span class="<?= $colorClass ?> fw-bold">#<?= $idx + 1 ?></span> <?= htmlspecialchars($tp['product_name']) ?>: <strong class="text-dark"><?= formatMoney((float)$tp['product_sales']) ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Main Content: Recent Orders & Stock Alert Cards -->
<div class="row g-3">
    <!-- Recent Orders Table (Responsive Card Design for Mobile) -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-1 text-primary"></i> Recent Sales Orders</h6>
                <a href="<?= BASE_URL ?>/modules/orders/index.php" class="text-decoration-none small fw-semibold">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentOrders)): ?>
                    <div class="p-4 text-center text-muted">No sales orders found yet. Use "Book New Order" to start.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Shop / Customer</th>
                                    <th>Area</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td data-label="Order #">
                                            <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="fw-semibold text-primary text-decoration-none">
                                                <?= htmlspecialchars($order['order_number']) ?>
                                            </a>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?= formatDate($order['order_date']) ?></div>
                                        </td>
                                        <td data-label="Customer">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($order['shop_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($order['booker_name']) ?></small>
                                        </td>
                                        <td data-label="Area"><?= htmlspecialchars($order['area_name']) ?></td>
                                        <td data-label="Amount" class="fw-bold text-dark"><?= formatMoney($order['grand_total']) ?></td>
                                        <td data-label="Status">
                                            <?php
                                            $badgeClass = match ($order['status']) {
                                                'submitted', 'pending_approval' => 'bg-warning text-dark',
                                                'approved' => 'bg-info text-dark',
                                                'dispatched' => 'bg-primary',
                                                'delivered' => 'bg-success',
                                                'cancelled', 'rejected' => 'bg-danger',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge badge-status <?= $badgeClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $order['status'])) ?></span>
                                        </td>
                                        <td data-label="Action" class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="<?= BASE_URL ?>/modules/orders/bill.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-success" title="View Customer Bill">
                                                    <i class="bi bi-receipt"></i> Bill
                                                </a>
                                                <a href="<?= BASE_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                    Details
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
    </div>

    <!-- Quick Stock Alert & System Status -->
    <div class="col-lg-4">
        <!-- Low Stock Alerts -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Low Stock Alerts</h6>
                <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="text-decoration-none small fw-semibold">Inventory</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($lowStockItems)): ?>
                    <div class="p-3 text-center text-muted small">All warehouse stocks are above reorder levels.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($lowStockItems as $item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                                <div>
                                    <div class="fw-semibold text-dark small"><?= htmlspecialchars($item['name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['code']) ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-danger rounded-pill"><?= (float)$item['current_stock'] ?> <?= htmlspecialchars($item['unit_name']) ?></span>
                                    <div class="text-muted" style="font-size: 0.65rem;">Min: <?= (float)$item['reorder_level'] ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- System Fast Access -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-lightning-charge-fill me-1 text-warning"></i> Fast Navigations</h6>
            </div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush">
                    <a href="<?= BASE_URL ?>/modules/orders/book.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <span><i class="bi bi-phone me-2 text-primary"></i> Order Booker Field App</span>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <span><i class="bi bi-truck me-2 text-success"></i> Delivery Driver App</span>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <span><i class="bi bi-cash-coin me-2 text-warning"></i> Delivery Cash Handover</span>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/accounting/ledger.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <span><i class="bi bi-journal-text me-2 text-info"></i> Financial General Ledger</span>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/sync/index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <span><i class="bi bi-cloud-check me-2 text-secondary"></i> Offline Sync Manager</span>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Engine & Dashboard Chart Visualizations -->
<script src="<?= BASE_URL ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js library is not loaded');
        return;
    }

    // Currency Formatter Helper
    const formatPKR = (val) => 'Rs. ' + Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

    // 1. Sales Trend Line/Area Chart
    const trendCtx = document.getElementById('salesTrendChart');
    if (trendCtx) {
        new Chart(trendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode($trendChartLabels) ?>,
                datasets: [{
                    label: 'Booked Sales (PKR)',
                    data: <?= json_encode($trendChartValues) ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#0d6efd',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return 'Sales: ' + formatPKR(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (val) {
                                return val >= 1000 ? (val / 1000).toFixed(0) + 'k' : val;
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }

    // 2. Profit & Loss Doughnut Chart
    const pnlCtx = document.getElementById('pnlDoughnutChart');
    if (pnlCtx) {
        new Chart(pnlCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartPnlLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartPnlValues) ?>,
                    backgroundColor: ['#0d6efd', '#dc3545', '#ffc107', '#198754'],
                    hoverOffset: 4,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.label + ': ' + formatPKR(ctx.parsed);
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // 3. Area Sales Bar Chart
    const areaCtx = document.getElementById('areaSalesChart');
    if (areaCtx) {
        new Chart(areaCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($areaChartLabels) ?>,
                datasets: [{
                    label: 'Territory Revenue',
                    data: <?= json_encode($areaChartValues) ?>,
                    backgroundColor: '#198754',
                    borderRadius: 6,
                    maxBarThickness: 36
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return 'Revenue: ' + formatPKR(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (val) {
                                return val >= 1000 ? (val / 1000).toFixed(0) + 'k' : val;
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }

    // 4. Top Selling Products Bar Chart (Displays exact Product Names)
    const topCtx = document.getElementById('topProductsChart');
    if (topCtx) {
        new Chart(topCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($topProdLabels) ?>,
                datasets: [{
                    label: 'Booked Sales (PKR)',
                    data: <?= json_encode($topProdValues) ?>,
                    backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#6366f1', '#ec4899'],
                    borderRadius: 6,
                    maxBarThickness: 45
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                return items[0].label;
                            },
                            label: function (ctx) {
                                return 'Sales: ' + formatPKR(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'category',
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '500' },
                            maxRotation: 20,
                            minRotation: 0,
                            callback: function (val, index) {
                                const lbl = this.getLabelForValue(val);
                                return (lbl && lbl.length > 20) ? lbl.substring(0, 18) + '...' : lbl;
                            }
                        }
                    },
                    y: {
                        type: 'linear',
                        beginAtZero: true,
                        ticks: {
                            callback: function (val) {
                                return val >= 1000 ? (val / 1000).toFixed(0) + 'k' : val;
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
