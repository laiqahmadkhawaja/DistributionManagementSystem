<?php
/**
 * Comprehensive Enterprise Reports & Analytics Suite
 * Profit & Loss, Item-Wise Profitability, Order Booker Audits, Territory Sales & Recovery, Driver Recon & Customer Exposure
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('reports', 'sales');

$pdo = getDatabaseConnection();

$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate = sanitize($_GET['end_date'] ?? date('Y-m-d'));
$reportType = sanitize($_GET['tab'] ?? 'pnl');

// Valid tabs
$allowedTabs = ['pnl', 'items', 'bookers', 'areas', 'drivers', 'customers'];
if (!in_array($reportType, $allowedTabs)) {
    $reportType = 'pnl';
}

// -------------------------------------------------------------
// TAB 1: PROFIT & LOSS STATEMENT (DOUBLE-ENTRY)
// -------------------------------------------------------------
$salesRevAcc = getSystemAccount($pdo, 'sales_revenue');
$cogsAcc = getSystemAccount($pdo, 'cogs');
$salesRetAcc = getSystemAccount($pdo, 'sales_returns');

// Gross Sales Revenue
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(credit) - SUM(debit), 0) 
    FROM `journal_entry_lines` jel 
    JOIN `journal_entries` je ON jel.journal_entry_id = je.id 
    WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?
");
$stmt->execute([$salesRevAcc['id'], $startDate, $endDate]);
$grossSalesVal = (float)$stmt->fetchColumn();

// Sales Returns
$stmtRet = $pdo->prepare("
    SELECT COALESCE(SUM(debit) - SUM(credit), 0) 
    FROM `journal_entry_lines` jel 
    JOIN `journal_entries` je ON jel.journal_entry_id = je.id 
    WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?
");
$stmtRet->execute([$salesRetAcc['id'], $startDate, $endDate]);
$salesReturnsVal = max(0, (float)$stmtRet->fetchColumn());

$netSalesVal = max(0, $grossSalesVal - $salesReturnsVal);

// Cost of Goods Sold (COGS)
$stmtCogs = $pdo->prepare("
    SELECT COALESCE(SUM(debit) - SUM(credit), 0) 
    FROM `journal_entry_lines` jel 
    JOIN `journal_entries` je ON jel.journal_entry_id = je.id 
    WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?
");
$stmtCogs->execute([$cogsAcc['id'], $startDate, $endDate]);
$cogsVal = (float)$stmtCogs->fetchColumn();

$grossProfitVal = $netSalesVal - $cogsVal;
$grossProfitMarginPct = $netSalesVal > 0 ? ($grossProfitVal / $netSalesVal) * 100 : 0;

// Categorized Operating Expenses
$expStmt = $pdo->prepare("
    SELECT coa.name as category_name, COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) as cat_amount
    FROM `journal_entry_lines` jel
    JOIN `journal_entries` je ON jel.journal_entry_id = je.id
    JOIN `chart_of_accounts` coa ON jel.account_id = coa.id
    WHERE coa.group_type = 'Expense' AND je.entry_date BETWEEN ? AND ?
    GROUP BY coa.id, coa.name
    HAVING cat_amount > 0
    ORDER BY cat_amount DESC
");
$expStmt->execute([$startDate, $endDate]);
$expenseBreakdown = $expStmt->fetchAll();

$totalExpensesVal = 0.00;
foreach ($expenseBreakdown as $eb) {
    $totalExpensesVal += (float)$eb['cat_amount'];
}
$netProfitVal = $grossProfitVal - $totalExpensesVal;
$netProfitMarginPct = $netSalesVal > 0 ? ($netProfitVal / $netSalesVal) * 100 : 0;


// -------------------------------------------------------------
// TAB 2: ITEM-WISE SALES & PROFITABILITY
// -------------------------------------------------------------
$itemsData = [];
if ($reportType === 'items') {
    $itemStmt = $pdo->prepare("
        SELECT 
            p.id as product_id,
            p.code as product_code,
            p.name as product_name,
            COALESCE(c.name, 'General') as category_name,
            p.purchase_price as unit_cost,
            p.trade_price as current_trade_price,
            COALESCE(SUM(soi.quantity), 0) as total_booked_qty,
            COALESCE(SUM(CASE WHEN so.status IN ('delivered', 'partially_delivered') THEN (CASE WHEN soi.delivered_qty > 0 THEN soi.delivered_qty ELSE soi.quantity END) ELSE 0 END), 0) as delivered_qty,
            COALESCE(SUM(soi.line_total), 0) as booked_revenue,
            COALESCE(SUM(
                CASE 
                    WHEN so.status IN ('delivered', 'partially_delivered') THEN ((CASE WHEN soi.delivered_qty > 0 THEN soi.delivered_qty ELSE soi.quantity END) * soi.unit_price)
                    ELSE 0 
                END
            ), 0) as delivered_revenue,
            COALESCE(SUM(
                CASE 
                    WHEN so.status IN ('delivered', 'partially_delivered') THEN ((CASE WHEN soi.delivered_qty > 0 THEN soi.delivered_qty ELSE soi.quantity END) * COALESCE(p.purchase_price, 0))
                    ELSE 0 
                END
            ), 0) as delivered_cogs,
            COALESCE((
                SELECT SUM(sri.quantity)
                FROM sales_return_items sri
                JOIN sales_returns sr ON sri.sales_return_id = sr.id
                WHERE sri.product_id = p.id AND sr.return_date BETWEEN ? AND ? AND sr.status = 'approved'
            ), 0) as returned_qty,
            COALESCE((
                SELECT SUM(sri.total_amount)
                FROM sales_return_items sri
                JOIN sales_returns sr ON sri.sales_return_id = sr.id
                WHERE sri.product_id = p.id AND sr.return_date BETWEEN ? AND ? AND sr.status = 'approved'
            ), 0) as returned_revenue
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN sales_order_items soi ON p.id = soi.product_id
        LEFT JOIN sales_orders so ON soi.sales_order_id = so.id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
        GROUP BY p.id, p.code, p.name, c.name, p.purchase_price, p.trade_price
        HAVING total_booked_qty > 0 OR returned_qty > 0 OR delivered_qty > 0
        ORDER BY booked_revenue DESC
    ");
    $itemStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $itemsData = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
}


// -------------------------------------------------------------
// TAB 3: ORDER BOOKER EFFICIENCY & PROFIT CONTRIBUTION
// -------------------------------------------------------------
$bookersData = [];
if ($reportType === 'bookers') {
    $bookerStmt = $pdo->prepare("
        SELECT 
            e.id as employee_id,
            e.full_name as booker_name,
            e.employee_code,
            e.phone,
            e.commission_rate,
            COUNT(DISTINCT so.id) as total_orders,
            COALESCE(SUM(so.grand_total), 0) as booked_amount,
            COUNT(DISTINCT CASE WHEN so.status = 'delivered' THEN so.id END) as delivered_orders,
            COALESCE(SUM(CASE WHEN so.status = 'delivered' THEN so.grand_total ELSE 0 END), 0) as delivered_amount,
            COALESCE(SUM(
                CASE 
                    WHEN so.status = 'delivered' THEN (
                        SELECT COALESCE(SUM((CASE WHEN soi.delivered_qty > 0 THEN soi.delivered_qty ELSE soi.quantity END) * COALESCE(p.purchase_price, 0)), 0)
                        FROM sales_order_items soi
                        JOIN products p ON soi.product_id = p.id
                        WHERE soi.sales_order_id = so.id
                    )
                    ELSE 0 
                END
            ), 0) as delivered_cogs,
            COALESCE(
                (SELECT SUM(py.amount) 
                 FROM payments py 
                 WHERE (py.collected_by = e.user_id OR py.collected_by = e.id) AND py.payment_date BETWEEN ? AND ? AND py.status = 'posted'), 
                0
            ) as direct_recovery,
            COALESCE(
                (SELECT SUM(py2.amount)
                 FROM payments py2
                 JOIN customers c ON py2.party_id = c.id AND py2.party_type = 'customer'
                 WHERE c.assigned_order_booker_id = e.id AND py2.payment_date BETWEEN ? AND ? AND py2.status = 'posted'),
                0
            ) as territory_recovery
        FROM employees e
        LEFT JOIN sales_orders so ON e.id = so.order_booker_id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
        WHERE e.role_type IN ('order_booker', 'salesman') AND e.status = 'active'
        GROUP BY e.id, e.full_name, e.employee_code, e.phone, e.commission_rate, e.user_id
        ORDER BY booked_amount DESC
    ");
    $bookerStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $bookersData = $bookerStmt->fetchAll(PDO::FETCH_ASSOC);
}


// -------------------------------------------------------------
// TAB 4: TERRITORY / AREA-WISE SALES, PROFIT & RECOVERY
// -------------------------------------------------------------
$areasData = [];
if ($reportType === 'areas') {
    $areaStmt = $pdo->prepare("
        SELECT 
            a.id as area_id,
            a.name as area_name,
            a.code as area_code,
            a.city,
            (SELECT COUNT(*) FROM customers c WHERE c.area_id = a.id AND c.status = 'active') as customer_count,
            (SELECT COALESCE(SUM(c.current_balance), 0) FROM customers c WHERE c.area_id = a.id) as total_market_due,
            COUNT(DISTINCT so.id) as order_count,
            COALESCE(SUM(so.grand_total), 0) as total_sales,
            COALESCE(SUM(CASE WHEN so.status = 'delivered' THEN so.grand_total ELSE 0 END), 0) as delivered_sales,
            COALESCE(SUM(
                (SELECT COALESCE(SUM(soi.quantity * COALESCE(p.purchase_price, 0)), 0)
                 FROM sales_order_items soi
                 JOIN products p ON soi.product_id = p.id
                 WHERE soi.sales_order_id = so.id)
            ), 0) as total_cogs,
            COALESCE((
                SELECT SUM(p.amount)
                FROM payments p
                JOIN customers cust ON p.party_id = cust.id AND p.party_type = 'customer'
                WHERE cust.area_id = a.id AND p.payment_date BETWEEN ? AND ? AND p.status = 'posted'
            ), 0) as total_recovery
        FROM areas a
        LEFT JOIN sales_orders so ON a.id = so.area_id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
        WHERE a.status = 'active'
        GROUP BY a.id, a.name, a.code, a.city
        ORDER BY total_sales DESC
    ");
    $areaStmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $areasData = $areaStmt->fetchAll(PDO::FETCH_ASSOC);
}


// -------------------------------------------------------------
// TAB 5: DELIVERY DRIVER RECONCILIATION & ROUTE PERFORMANCE
// -------------------------------------------------------------
$driversData = [];
if ($reportType === 'drivers') {
    $driverStmt = $pdo->prepare("
        SELECT 
            e.id as driver_id,
            e.full_name as driver_name,
            e.employee_code,
            e.phone,
            COUNT(DISTINCT da.id) as total_dispatches,
            COUNT(DISTINCT CASE WHEN da.status = 'delivered' THEN da.id END) as completed_dispatches,
            COALESCE(SUM(so.grand_total), 0) as assigned_value,
            COALESCE(SUM(CASE WHEN da.status = 'delivered' THEN so.grand_total ELSE 0 END), 0) as delivered_value,
            COALESCE((
                SELECT SUM(sr.total_amount) 
                FROM sales_returns sr 
                WHERE sr.delivery_man_id = e.id AND sr.return_date BETWEEN ? AND ? AND sr.status = 'approved'
            ), 0) as returns_value,
            COALESCE((
                SELECT SUM(ch.cash_deposited)
                FROM cash_handovers ch
                WHERE ch.delivery_man_id = e.id AND ch.handover_date BETWEEN ? AND ? AND ch.status = 'verified'
            ), 0) as verified_handovers,
            COALESCE((
                SELECT SUM(p.amount)
                FROM payments p
                WHERE (p.collected_by = e.user_id OR p.collected_by = e.id) AND p.payment_method_code = 'cash' AND p.payment_date BETWEEN ? AND ? AND p.status = 'posted'
            ), 0) as cash_collected,
            COALESCE((
                SELECT SUM(p.amount)
                FROM payments p
                WHERE (p.collected_by = e.user_id OR p.collected_by = e.id) AND p.payment_method_code != 'cash' AND p.payment_date BETWEEN ? AND ? AND p.status = 'posted'
            ), 0) as digital_collected
        FROM employees e
        LEFT JOIN delivery_assignments da ON e.id = da.delivery_man_id AND da.dispatch_date BETWEEN ? AND ?
        LEFT JOIN sales_orders so ON da.sales_order_id = so.id
        WHERE e.role_type IN ('delivery_man', 'delivery_manager') AND e.status = 'active'
        GROUP BY e.id, e.full_name, e.employee_code, e.phone, e.user_id
        ORDER BY total_dispatches DESC
    ");
    $driverStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $driversData = $driverStmt->fetchAll(PDO::FETCH_ASSOC);
}


// -------------------------------------------------------------
// TAB 6: CUSTOMER / SHOP PROFITABILITY & EXPOSURE AUDIT
// -------------------------------------------------------------
$customersData = [];
if ($reportType === 'customers') {
    $custStmt = $pdo->prepare("
        SELECT 
            c.id as customer_id,
            c.shop_name,
            c.owner_name,
            c.phone,
            a.name as area_name,
            c.current_balance,
            c.credit_limit,
            COUNT(DISTINCT so.id) as total_invoices,
            COALESCE(SUM(so.grand_total), 0) as total_sales,
            COALESCE(SUM(
                (SELECT COALESCE(SUM(soi.quantity * COALESCE(p.purchase_price, 0)), 0)
                 FROM sales_order_items soi
                 JOIN products p ON soi.product_id = p.id
                 WHERE soi.sales_order_id = so.id)
            ), 0) as total_cogs,
            COALESCE((
                SELECT SUM(py.amount)
                FROM payments py
                WHERE py.party_id = c.id AND py.party_type = 'customer' AND py.payment_date BETWEEN ? AND ? AND py.status = 'posted'
            ), 0) as total_paid
        FROM customers c
        LEFT JOIN areas a ON c.area_id = a.id
        LEFT JOIN sales_orders so ON c.id = so.customer_id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
        WHERE c.status = 'active'
        GROUP BY c.id, c.shop_name, c.owner_name, c.phone, a.name, c.current_balance, c.credit_limit
        HAVING total_sales > 0 OR current_balance > 0 OR total_paid > 0
        ORDER BY total_sales DESC
    ");
    $custStmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $customersData = $custStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Enterprise Analytics & Reports';
require_once dirname(__DIR__, 2) . '/includes/header.php';
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-line text-primary me-2"></i>Executive Reports & Analytics</h4>
        <small class="text-muted">Item Profitability, Booker Performance, Territory Sales & Recovery, Driver Custody</small>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <a href="<?= BASE_URL ?>/modules/reports/parties_credit.php" target="_blank" class="btn btn-sm btn-info text-dark fw-bold btn-touch shadow-sm">
            <i class="bi bi-person-lines-fill me-1"></i> Parties Credit
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/daily_sale_summary.php" target="_blank" class="btn btn-sm btn-warning text-dark fw-bold btn-touch shadow-sm">
            <i class="bi bi-calendar-check me-1"></i> Daily Sale Macro
        </a>
        <button type="button" class="btn btn-sm btn-success btn-touch shadow-sm" onclick="exportTableToCSV('reportTable', 'DMS_Report_<?= $reportType ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.csv)
        </button>
        <button type="button" class="btn btn-sm btn-primary btn-touch shadow-sm" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Sheet
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp btn-touch shadow-sm" onclick="shareReportWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark btn-touch shadow-sm" onclick="captureAndShareElement('reportContainer', 'DMS_Report_<?= $reportType ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-image me-1"></i> Image
        </button>
    </div>
</div>

<!-- Date Filter & Quick Presets Form -->
<div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/reports/index.php" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($reportType) ?>">
            
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold text-muted mb-1">Quick Date Filters</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('<?= date('Y-m-d') ?>', '<?= date('Y-m-d') ?>')">Today</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('<?= date('Y-m-d', strtotime('-7 days')) ?>', '<?= date('Y-m-d') ?>')">Last 7 Days</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('<?= date('Y-m-01') ?>', '<?= date('Y-m-d') ?>')">This Month</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('<?= date('Y-m-01', strtotime('last month')) ?>', '<?= date('Y-m-t', strtotime('last month')) ?>')">Last Month</button>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Reporting From</label>
                <input type="date" class="form-control form-control-sm" id="startDateInput" name="start_date" value="<?= $startDate ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" class="form-control form-control-sm" id="endDateInput" name="end_date" value="<?= $endDate ?>">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                    <i class="bi bi-arrow-repeat me-1"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Report Navigation Tabs -->
<div class="d-print-none mb-3">
    <ul class="nav nav-pills flex-nowrap overflow-auto pb-1 gap-1" role="tablist">
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'pnl' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=pnl&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-calculator me-1"></i> P&L Statement
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'items' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=items&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-box-seam me-1"></i> Item-wise Sale & Profit
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'bookers' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=bookers&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-people me-1"></i> Order Booker Profit
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'areas' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=areas&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-geo-alt me-1"></i> Area Sale, Profit & Recovery
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'drivers' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=drivers&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-truck me-1"></i> Driver Route & Recon
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch <?= $reportType === 'customers' ? 'active shadow-sm fw-bold' : 'text-dark' ?>" 
               href="<?= BASE_URL ?>/modules/reports/index.php?tab=customers&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                <i class="bi bi-shop me-1"></i> Customer Exposure & Profit
            </a>
        </li>
        <li class="nav-item ms-auto">
            <a class="nav-link btn-touch text-primary fw-semibold" href="<?= BASE_URL ?>/modules/reports/loadsheet.php">
                <i class="bi bi-truck-flatbed me-1"></i> Load Sheet
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link btn-touch text-success fw-semibold" href="<?= BASE_URL ?>/modules/reports/daily_closing.php">
                <i class="bi bi-safe2 me-1"></i> Day Closing
            </a>
        </li>
    </ul>
</div>

<!-- Report Contents Container (Excel Spreadsheet Style) -->
<div id="reportContainer" class="bg-white p-3 p-md-4 rounded-3 shadow-sm border mb-4">
    <!-- Excel Report Header Banner -->
    <div class="border-bottom pb-3 mb-3">
        <div class="row align-items-center">
            <div class="col-sm-8">
                <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h5>
                <div class="text-muted small"><?= htmlspecialchars($company['address'] ?? 'Gujranwala, Pakistan') ?> · Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 0000000') ?></div>
                <div class="fw-bold text-primary mt-1" style="font-size: 1rem;">
                    <?php if ($reportType === 'pnl'): ?>
                        <i class="bi bi-journal-text me-1"></i> Statement of Profit & Loss (Income Statement)
                    <?php elseif ($reportType === 'items'): ?>
                        <i class="bi bi-box-seam me-1"></i> Product / Item-wise Sales, COGS & Gross Profit Analysis
                    <?php elseif ($reportType === 'bookers'): ?>
                        <i class="bi bi-people me-1"></i> Order Booker Sales Performance, Profit Contribution & Recovery Audit
                    <?php elseif ($reportType === 'areas'): ?>
                        <i class="bi bi-geo-alt me-1"></i> Territory & Area-wise Sales, Profitability & Market Recovery Report
                    <?php elseif ($reportType === 'drivers'): ?>
                        <i class="bi bi-truck me-1"></i> Delivery Driver Fulfillment, Shop Returns & Custody Reconciliation
                    <?php else: ?>
                        <i class="bi bi-shop me-1"></i> Customer / Retail Shop Turnover, Profit Contribution & Credit Exposure
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-sm-4 text-sm-end mt-2 mt-sm-0">
                <span class="badge bg-success bg-opacity-10 text-success border border-success fw-bold px-2 py-1 mb-1">
                    <i class="bi bi-table me-1"></i> Verified Spreadsheet
                </span>
                <div class="small text-muted"><strong>Period:</strong> <?= formatDate($startDate) ?> to <?= formatDate($endDate) ?></div>
                <div class="small text-muted"><strong>Generated:</strong> <?= date('d-M-Y H:i') ?> by <?= htmlspecialchars($currUser['full_name'] ?? 'Admin') ?></div>
                <div class="small text-muted"><strong>Currency:</strong> <?= DEFAULT_CURRENCY ?> (<?= DEFAULT_CURRENCY_SYMBOL ?>)</div>
            </div>
        </div>
    </div>

    <!-- Quick Search Bar for Live In-Browser Filtering -->
    <?php if ($reportType !== 'pnl'): ?>
    <div class="row align-items-center mb-3 d-print-none">
        <div class="col-sm-6 col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" id="reportSearchInput" class="form-control" placeholder="Quick filter in table rows..." onkeyup="filterReportTable();">
                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('reportSearchInput').value=''; filterReportTable();"><i class="bi bi-x"></i></button>
            </div>
        </div>
        <div class="col-sm-6 col-md-8 text-sm-end mt-2 mt-sm-0">
            <span id="filteredRowCount" class="badge bg-light text-dark border"></span>
            <small class="text-muted ms-2"><i class="bi bi-info-circle me-1"></i>Amounts in Pakistani Rupees (PKR)</small>
        </div>
    </div>
    <?php endif; ?>

<?php if ($reportType === 'pnl'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 1. P&L STATEMENT (Double-Entry General Ledger)                -->
    <!-- ------------------------------------------------------------- -->
    <!-- Summary KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Net Operating Sales</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($netSalesVal, 2) ?></div>
                <small class="text-muted">Gross: <?= number_format($grossSalesVal, 0) ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Cost of Goods (COGS)</div>
                <div class="h5 fw-bold text-danger mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($cogsVal, 2) ?></div>
                <small class="text-muted">Inventory Cost Basis</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Gross Profit (Margin %)</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($grossProfitVal, 2) ?></div>
                <small class="badge bg-primary bg-opacity-10 text-primary"><?= number_format($grossProfitMarginPct, 1) ?>% Margin</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded <?= $netProfitVal >= 0 ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10' ?>">
                <div class="text-muted small fw-semibold">Net Distributor Profit</div>
                <div class="h5 fw-bold <?= $netProfitVal >= 0 ? 'text-success' : 'text-danger' ?> mb-0">
                    <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($netProfitVal, 2) ?>
                </div>
                <small class="fw-bold <?= $netProfitVal >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($netProfitMarginPct, 1) ?>% Net</small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th colspan="2" style="background-color: #107c41; color: #fff;">Financial Head / General Ledger Head</th>
                    <th class="text-end" style="width: 220px; background-color: #107c41; color: #fff;">Amount (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td colspan="3" class="text-dark">1. OPERATING REVENUE</td>
                </tr>
                <tr>
                    <td class="ps-4">Gross Sales Invoices (Billed)</td>
                    <td class="num-cell"><?= number_format($grossSalesVal, 2) ?></td>
                    <td></td>
                </tr>
                <tr>
                    <td class="ps-4 text-danger">Less: Shop Sales Returns & Allowances</td>
                    <td class="num-cell text-danger">(<?= number_format($salesReturnsVal, 2) ?>)</td>
                    <td></td>
                </tr>
                <tr style="background-color: #e8f5e9; font-weight: 700;">
                    <td class="ps-3 text-success">NET REVENUE FROM SALES</td>
                    <td></td>
                    <td class="num-cell text-success fs-6"><?= number_format($netSalesVal, 2) ?></td>
                </tr>

                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td colspan="3" class="text-dark">2. COST OF GOODS SOLD (COGS)</td>
                </tr>
                <tr>
                    <td class="ps-4">Cost of Goods Delivered (Weighted Average Cost)</td>
                    <td class="num-cell text-danger"><?= number_format($cogsVal, 2) ?></td>
                    <td class="num-cell text-danger">(<?= number_format($cogsVal, 2) ?>)</td>
                </tr>

                <tr style="background-color: #e3f2fd; font-weight: 800; font-size: 0.95rem;">
                    <td colspan="2" class="text-primary">GROSS OPERATING PROFIT</td>
                    <td class="num-cell text-primary"><?= number_format($grossProfitVal, 2) ?></td>
                </tr>

                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td colspan="3" class="text-dark">3. OPERATING & ADMINISTRATIVE EXPENSES</td>
                </tr>
                <?php if (empty($expenseBreakdown)): ?>
                    <tr>
                        <td colspan="2" class="ps-4 text-muted fst-italic">No operating expenses recorded in selected date range.</td>
                        <td class="num-cell">0.00</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseBreakdown as $eb): ?>
                        <tr>
                            <td class="ps-4"><?= htmlspecialchars($eb['category_name']) ?></td>
                            <td class="num-cell"><?= number_format((float)$eb['cat_amount'], 2) ?></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr style="background-color: #fff3e0; font-weight: 700;">
                    <td colspan="2" class="ps-3 text-danger">Total Operating & Administrative Expenses</td>
                    <td class="num-cell text-danger">(<?= number_format($totalExpensesVal, 2) ?>)</td>
                </tr>

                <!-- Net Profit Final Excel Summary Row -->
                <tr style="background-color: <?= $netProfitVal >= 0 ? '#dcfce7' : '#fee2e2' ?>; font-weight: 800; font-size: 1.05rem; border-top: 2px solid #000; border-bottom: 4px double #000;">
                    <td colspan="2" class="<?= $netProfitVal >= 0 ? 'text-success' : 'text-danger' ?>">
                        NET DISTRIBUTOR OPERATING PROFIT / (LOSS)
                    </td>
                    <td class="num-cell <?= $netProfitVal >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($netProfitVal, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

<?php elseif ($reportType === 'items'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 2. ITEM-WISE SALES & PROFITABILITY                            -->
    <!-- ------------------------------------------------------------- -->
    <?php
    $sumBookedQty = 0;
    $sumDeliveredQty = 0;
    $sumReturnedQty = 0;
    $sumNetSoldQty = 0;
    $sumRevenue = 0.00;
    $sumCogs = 0.00;
    $sumProfit = 0.00;

    foreach ($itemsData as $it) {
        $netQty = max(0, (float)$it['delivered_qty'] - (float)$it['returned_qty']);
        $netRev = max(0, (float)$it['delivered_revenue'] - (float)$it['returned_revenue']);
        if ($netRev == 0 && (float)$it['booked_revenue'] > 0) {
            $netRev = (float)$it['booked_revenue'];
            $netQty = (float)$it['total_booked_qty'];
        }
        $cogs = $netQty * (float)$it['unit_cost'];
        $profit = $netRev - $cogs;

        $sumBookedQty += (float)$it['total_booked_qty'];
        $sumDeliveredQty += (float)$it['delivered_qty'];
        $sumReturnedQty += (float)$it['returned_qty'];
        $sumNetSoldQty += $netQty;
        $sumRevenue += $netRev;
        $sumCogs += $cogs;
        $sumProfit += $profit;
    }
    $avgMarginPct = $sumRevenue > 0 ? ($sumProfit / $sumRevenue) * 100 : 0;
    ?>

    <!-- KPI Summary Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Active Products</div>
                <div class="h5 fw-bold text-dark mb-0"><?= count($itemsData) ?></div>
                <small class="text-muted">With movement</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Net Sold Qty</div>
                <div class="h5 fw-bold text-dark mb-0"><?= number_format($sumNetSoldQty, 0) ?></div>
                <small class="text-muted">Booked: <?= number_format($sumBookedQty, 0) ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Sales Revenue</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($sumRevenue, 2) ?></div>
                <small class="text-muted">Invoiced value</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Total COGS</div>
                <div class="h5 fw-bold text-danger mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($sumCogs, 2) ?></div>
                <small class="text-muted">Purchase Cost</small>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="p-2 border rounded bg-success bg-opacity-10">
                <div class="text-muted small fw-semibold">Gross Profit & Margin</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($sumProfit, 2) ?></div>
                <small class="badge bg-success"><?= number_format($avgMarginPct, 1) ?>% Margin</small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Product / Item Description</th>
                    <th>Category</th>
                    <th class="text-end">Cost Price</th>
                    <th class="text-end">Trade Price</th>
                    <th class="text-center">Booked</th>
                    <th class="text-center">Delivered</th>
                    <th class="text-center text-danger">Returns</th>
                    <th class="text-center fw-bold">Net Sold</th>
                    <th class="text-end">Net Sales (PKR)</th>
                    <th class="text-end text-danger">COGS (PKR)</th>
                    <th class="text-end text-success fw-bold">Gross Profit</th>
                    <th class="text-center">Margin %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($itemsData)): ?>
                    <tr>
                        <td colspan="13" class="text-center py-4 text-muted fst-italic">
                            <i class="bi bi-box-seam fs-3 d-block mb-1 text-secondary"></i>
                            No product sales movements recorded for the selected period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $idx = 1;
                    foreach ($itemsData as $it):
                        $netQty = max(0, (float)$it['delivered_qty'] - (float)$it['returned_qty']);
                        $netRev = max(0, (float)$it['delivered_revenue'] - (float)$it['returned_revenue']);
                        if ($netRev == 0 && (float)$it['booked_revenue'] > 0) {
                            $netRev = (float)$it['booked_revenue'];
                            $netQty = (float)$it['total_booked_qty'];
                        }
                        $cogs = $netQty * (float)$it['unit_cost'];
                        $profit = $netRev - $cogs;
                        $marginPct = $netRev > 0 ? ($profit / $netRev) * 100 : 0;

                        $badgeClass = 'bg-success';
                        if ($profit < 0) {
                            $badgeClass = 'bg-danger';
                        } elseif ($marginPct < 8) {
                            $badgeClass = 'bg-warning text-dark';
                        }
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx++ ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($it['product_name']) ?></div>
                                <code class="small text-muted"><?= htmlspecialchars($it['product_code']) ?></code>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($it['category_name']) ?></span></td>
                            <td class="num-cell text-muted"><?= number_format((float)$it['unit_cost'], 2) ?></td>
                            <td class="num-cell fw-semibold"><?= number_format((float)$it['current_trade_price'], 2) ?></td>
                            <td class="text-center"><?= number_format((float)$it['total_booked_qty'], 0) ?></td>
                            <td class="text-center"><?= number_format((float)$it['delivered_qty'], 0) ?></td>
                            <td class="text-center text-danger"><?= (float)$it['returned_qty'] > 0 ? '-' . number_format((float)$it['returned_qty'], 0) : '-' ?></td>
                            <td class="text-center fw-bold text-dark"><?= number_format($netQty, 0) ?></td>
                            <td class="num-cell text-primary fw-bold"><?= number_format($netRev, 2) ?></td>
                            <td class="num-cell text-danger"><?= number_format($cogs, 2) ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format($profit, 2) ?></td>
                            <td class="text-center">
                                <span class="badge <?= $badgeClass ?>"><?= number_format($marginPct, 1) ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="5" class="text-dark">PORTFOLIO TOTALS</td>
                    <td class="text-center"><?= number_format($sumBookedQty, 0) ?></td>
                    <td class="text-center"><?= number_format($sumDeliveredQty, 0) ?></td>
                    <td class="text-center text-danger"><?= $sumReturnedQty > 0 ? '-' . number_format($sumReturnedQty, 0) : '0' ?></td>
                    <td class="text-center fw-bold"><?= number_format($sumNetSoldQty, 0) ?></td>
                    <td class="num-cell text-primary"><?= number_format($sumRevenue, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($sumCogs, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($sumProfit, 2) ?></td>
                    <td class="text-center">
                        <span class="badge bg-success"><?= number_format($avgMarginPct, 1) ?>%</span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

<?php elseif ($reportType === 'bookers'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 3. ORDER BOOKER PERFORMANCE, PROFIT & RECOVERY                -->
    <!-- ------------------------------------------------------------- -->
    <?php
    $totOrders = 0;
    $totBooked = 0.00;
    $totDeliveredOrders = 0;
    $totDelivered = 0.00;
    $totDelCogs = 0.00;
    $totProfit = 0.00;
    $totRecovery = 0.00;

    foreach ($bookersData as $bp) {
        $profit = max(0, (float)$bp['delivered_amount'] - (float)$bp['delivered_cogs']);
        $totOrders += (int)$bp['total_orders'];
        $totBooked += (float)$bp['booked_amount'];
        $totDeliveredOrders += (int)$bp['delivered_orders'];
        $totDelivered += (float)$bp['delivered_amount'];
        $totDelCogs += (float)$bp['delivered_cogs'];
        $totProfit += $profit;
        $totRecovery += ((float)$bp['direct_recovery'] > 0 ? (float)$bp['direct_recovery'] : (float)$bp['territory_recovery']);
    }
    $avgFulfillment = $totBooked > 0 ? ($totDelivered / $totBooked) * 100 : 0;
    $avgBookerMargin = $totDelivered > 0 ? ($totProfit / $totDelivered) * 100 : 0;
    ?>

    <!-- KPI Summary Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Active Bookers</div>
                <div class="h5 fw-bold text-dark mb-0"><?= count($bookersData) ?></div>
                <small class="text-muted">Field sales staff</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Total Booked</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totBooked, 2) ?></div>
                <small class="text-muted"><?= $totOrders ?> Orders</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Total Delivered</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totDelivered, 2) ?></div>
                <small class="badge bg-success bg-opacity-10 text-success"><?= number_format($avgFulfillment, 1) ?>% Fill</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Gross Profit Contribution</div>
                <div class="h5 fw-bold text-dark mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totProfit, 2) ?></div>
                <small class="badge bg-info text-dark"><?= number_format($avgBookerMargin, 1) ?>% Margin</small>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="p-2 border rounded bg-success bg-opacity-10">
                <div class="text-muted small fw-semibold">Payment Recovery</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totRecovery, 2) ?></div>
                <small class="text-muted">Collected from booker route</small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Order Booker / Salesman</th>
                    <th>Code / Contact</th>
                    <th class="text-center">Orders</th>
                    <th class="text-end">Booked (PKR)</th>
                    <th class="text-center">Delivered</th>
                    <th class="text-end">Delivered (PKR)</th>
                    <th class="text-center">Fill Rate</th>
                    <th class="text-end text-danger">Delivered COGS</th>
                    <th class="text-end text-success fw-bold">Gross Profit</th>
                    <th class="text-center">Margin %</th>
                    <th class="text-end text-success">Recovery (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookersData)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-muted fst-italic">
                            <i class="bi bi-people fs-3 d-block mb-1 text-secondary"></i>
                            No order bookers recorded.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $idx = 1;
                    foreach ($bookersData as $bp):
                        $profit = max(0, (float)$bp['delivered_amount'] - (float)$bp['delivered_cogs']);
                        $fillRate = (float)$bp['booked_amount'] > 0 ? ((float)$bp['delivered_amount'] / (float)$bp['booked_amount']) * 100 : 0;
                        $margin = (float)$bp['delivered_amount'] > 0 ? ($profit / (float)$bp['delivered_amount']) * 100 : 0;
                        $recovery = ((float)$bp['direct_recovery'] > 0 ? (float)$bp['direct_recovery'] : (float)$bp['territory_recovery']);
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx++ ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($bp['booker_name']) ?></div>
                                <span class="badge bg-light text-dark border">Order Booker</span>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($bp['employee_code']) ?></code>
                                <div class="small text-muted"><?= htmlspecialchars($bp['phone'] ?? '-') ?></div>
                            </td>
                            <td class="text-center"><?= $bp['total_orders'] ?></td>
                            <td class="num-cell text-primary fw-bold"><?= number_format((float)$bp['booked_amount'], 2) ?></td>
                            <td class="text-center"><?= $bp['delivered_orders'] ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format((float)$bp['delivered_amount'], 2) ?></td>
                            <td class="text-center">
                                <span class="badge <?= $fillRate >= 80 ? 'bg-success' : ($fillRate >= 50 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                    <?= number_format($fillRate, 1) ?>%
                                </span>
                            </td>
                            <td class="num-cell text-danger"><?= number_format((float)$bp['delivered_cogs'], 2) ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format($profit, 2) ?></td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= number_format($margin, 1) ?>%</span>
                            </td>
                            <td class="num-cell text-success fw-bold"><?= number_format($recovery, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="3" class="text-dark">TOTAL SALES FORCE PERFORMANCE</td>
                    <td class="text-center"><?= $totOrders ?></td>
                    <td class="num-cell text-primary"><?= number_format($totBooked, 2) ?></td>
                    <td class="text-center"><?= $totDeliveredOrders ?></td>
                    <td class="num-cell text-success"><?= number_format($totDelivered, 2) ?></td>
                    <td class="text-center"><?= number_format($avgFulfillment, 1) ?>%</td>
                    <td class="num-cell text-danger"><?= number_format($totDelCogs, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totProfit, 2) ?></td>
                    <td class="text-center"><?= number_format($avgBookerMargin, 1) ?>%</td>
                    <td class="num-cell text-success"><?= number_format($totRecovery, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

<?php elseif ($reportType === 'areas'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 4. TERRITORY / AREA-WISE SALES, PROFIT & RECOVERY             -->
    <!-- ------------------------------------------------------------- -->
    <?php
    $totShops = 0;
    $totAreaOrders = 0;
    $totSales = 0.00;
    $totAreaCogs = 0.00;
    $totAreaProfit = 0.00;
    $totAreaRecovery = 0.00;
    $totMarketDue = 0.00;

    foreach ($areasData as $ap) {
        $profit = max(0, (float)$ap['total_sales'] - (float)$ap['total_cogs']);
        $totShops += (int)$ap['customer_count'];
        $totAreaOrders += (int)$ap['order_count'];
        $totSales += (float)$ap['total_sales'];
        $totAreaCogs += (float)$ap['total_cogs'];
        $totAreaProfit += $profit;
        $totAreaRecovery += (float)$ap['total_recovery'];
        $totMarketDue += (float)$ap['total_market_due'];
    }
    $avgAreaMargin = $totSales > 0 ? ($totAreaProfit / $totSales) * 100 : 0;
    $recoveryRate = $totSales > 0 ? ($totAreaRecovery / $totSales) * 100 : 0;
    ?>

    <!-- KPI Summary Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Territories & Shops</div>
                <div class="h5 fw-bold text-dark mb-0"><?= count($areasData) ?> Areas</div>
                <small class="text-muted"><?= $totShops ?> Registered Shops</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Invoiced Sales</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totSales, 2) ?></div>
                <small class="text-muted"><?= $totAreaOrders ?> Orders</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Area Gross Profit</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totAreaProfit, 2) ?></div>
                <small class="badge bg-success"><?= number_format($avgAreaMargin, 1) ?>% Margin</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Payment Recoveries</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totAreaRecovery, 2) ?></div>
                <small class="badge bg-info text-dark"><?= number_format($recoveryRate, 1) ?>% Recovery Rate</small>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="p-2 border rounded bg-danger bg-opacity-10">
                <div class="text-muted small fw-semibold">Outstanding Market Balance</div>
                <div class="h5 fw-bold text-danger mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totMarketDue, 2) ?></div>
                <small class="text-danger fw-semibold">Credit across all shops</small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Territory / Sales Zone</th>
                    <th>City / Region</th>
                    <th class="text-center">Shops</th>
                    <th class="text-center">Orders</th>
                    <th class="text-end">Sales Volume (PKR)</th>
                    <th class="text-end text-danger">COGS (PKR)</th>
                    <th class="text-end text-success fw-bold">Gross Profit</th>
                    <th class="text-center">Margin %</th>
                    <th class="text-end text-success fw-bold">Recovered (PKR)</th>
                    <th class="text-end text-danger fw-bold">Market Due (PKR)</th>
                    <th class="text-end">Share %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($areasData)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-muted fst-italic">
                            <i class="bi bi-geo-alt fs-3 d-block mb-1 text-secondary"></i>
                            No territory sales activity recorded.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $idx = 1;
                    foreach ($areasData as $ap):
                        $profit = max(0, (float)$ap['total_sales'] - (float)$ap['total_cogs']);
                        $margin = (float)$ap['total_sales'] > 0 ? ($profit / (float)$ap['total_sales']) * 100 : 0;
                        $share = $totSales > 0 ? (((float)$ap['total_sales'] / $totSales) * 100) : 0;
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx++ ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($ap['area_name']) ?></div>
                                <code><?= htmlspecialchars($ap['area_code']) ?></code>
                            </td>
                            <td><?= htmlspecialchars($ap['city'] ?? 'Gujranwala') ?></td>
                            <td class="text-center fw-semibold"><?= $ap['customer_count'] ?></td>
                            <td class="text-center"><?= $ap['order_count'] ?></td>
                            <td class="num-cell text-primary fw-bold"><?= number_format((float)$ap['total_sales'], 2) ?></td>
                            <td class="num-cell text-danger"><?= number_format((float)$ap['total_cogs'], 2) ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format($profit, 2) ?></td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= number_format($margin, 1) ?>%</span>
                            </td>
                            <td class="num-cell text-success fw-bold"><?= number_format((float)$ap['total_recovery'], 2) ?></td>
                            <td class="num-cell text-danger fw-bold"><?= number_format((float)$ap['total_market_due'], 2) ?></td>
                            <td class="num-cell text-muted"><?= number_format($share, 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="3" class="text-dark">TOTAL TERRITORY PORTFOLIO</td>
                    <td class="text-center"><?= $totShops ?></td>
                    <td class="text-center"><?= $totAreaOrders ?></td>
                    <td class="num-cell text-primary"><?= number_format($totSales, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($totAreaCogs, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totAreaProfit, 2) ?></td>
                    <td class="text-center"><?= number_format($avgAreaMargin, 1) ?>%</td>
                    <td class="num-cell text-success"><?= number_format($totAreaRecovery, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($totMarketDue, 2) ?></td>
                    <td class="num-cell text-dark">100.0%</td>
                </tr>
            </tfoot>
        </table>
    </div>

<?php elseif ($reportType === 'drivers'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 5. DELIVERY DRIVER PERFORMANCE & CASH RECONCILIATION          -->
    <!-- ------------------------------------------------------------- -->
    <?php
    $totDispatches = 0;
    $totCompleted = 0;
    $totAssignedVal = 0.00;
    $totDeliveredVal = 0.00;
    $totReturnsVal = 0.00;
    $totCashColl = 0.00;
    $totDigitalColl = 0.00;
    $totDeposited = 0.00;

    foreach ($driversData as $dp) {
        $totDispatches += (int)$dp['total_dispatches'];
        $totCompleted += (int)$dp['completed_dispatches'];
        $totAssignedVal += (float)$dp['assigned_value'];
        $totDeliveredVal += (float)$dp['delivered_value'];
        $totReturnsVal += (float)$dp['returns_value'];
        $totCashColl += (float)$dp['cash_collected'];
        $totDigitalColl += (float)$dp['digital_collected'];
        $totDeposited += (float)$dp['verified_handovers'];
    }
    $avgSuccessRate = $totDispatches > 0 ? ($totCompleted / $totDispatches) * 100 : 0;
    $totPendingCustody = max(0, $totCashColl - $totDeposited);
    ?>

    <!-- KPI Summary Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Delivery Drivers</div>
                <div class="h5 fw-bold text-dark mb-0"><?= count($driversData) ?> Active</div>
                <small class="text-muted"><?= $totDispatches ?> Dispatches</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Delivered Goods</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totDeliveredVal, 2) ?></div>
                <small class="badge bg-success"><?= number_format($avgSuccessRate, 1) ?>% Success</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Shop Returns</div>
                <div class="h5 fw-bold text-danger mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totReturnsVal, 2) ?></div>
                <small class="text-muted">Quarantine / In Custody</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Cash Collections & Handover</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totCashColl, 2) ?></div>
                <small class="text-muted">Deposited: <?= number_format($totDeposited, 0) ?></small>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="p-2 border rounded <?= $totPendingCustody > 0 ? 'bg-warning bg-opacity-25' : 'bg-success bg-opacity-10' ?>">
                <div class="text-muted small fw-semibold">Un-handed Cash Custody</div>
                <div class="h5 fw-bold <?= $totPendingCustody > 0 ? 'text-warning text-dark' : 'text-success' ?> mb-0">
                    <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totPendingCustody, 2) ?>
                </div>
                <small class="fw-bold"><?= $totPendingCustody == 0 ? '✓ 100% Fully Reconciled' : '⚠ Requires Office Handover' ?></small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Delivery Specialist / Driver</th>
                    <th>Code / Phone</th>
                    <th class="text-center">Trips</th>
                    <th class="text-center">Delivered</th>
                    <th class="text-center">Success %</th>
                    <th class="text-end">Dispatched (PKR)</th>
                    <th class="text-end text-success">Delivered (PKR)</th>
                    <th class="text-end text-danger">Returns (PKR)</th>
                    <th class="text-end text-primary">Cash Coll.</th>
                    <th class="text-end text-success">Deposited</th>
                    <th class="text-end fw-bold">Custody Diff</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($driversData)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-muted fst-italic">
                            <i class="bi bi-truck fs-3 d-block mb-1 text-secondary"></i>
                            No delivery activity found for the selected period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $idx = 1;
                    foreach ($driversData as $dp):
                        $rate = (int)$dp['total_dispatches'] > 0 ? (((int)$dp['completed_dispatches'] / (int)$dp['total_dispatches']) * 100) : 0;
                        $diff = (float)$dp['cash_collected'] - (float)$dp['verified_handovers'];
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx++ ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($dp['driver_name']) ?></div>
                                <span class="badge bg-light text-dark border">Delivery Rider</span>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($dp['employee_code']) ?></code>
                                <div class="small text-muted"><?= htmlspecialchars($dp['phone'] ?? '-') ?></div>
                            </td>
                            <td class="text-center"><?= $dp['total_dispatches'] ?></td>
                            <td class="text-center"><?= $dp['completed_dispatches'] ?></td>
                            <td class="text-center">
                                <span class="badge <?= $rate >= 90 ? 'bg-success' : ($rate >= 60 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                    <?= number_format($rate, 1) ?>%
                                </span>
                            </td>
                            <td class="num-cell text-muted"><?= number_format((float)$dp['assigned_value'], 2) ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format((float)$dp['delivered_value'], 2) ?></td>
                            <td class="num-cell text-danger"><?= (float)$dp['returns_value'] > 0 ? number_format((float)$dp['returns_value'], 2) : '-' ?></td>
                            <td class="num-cell text-primary fw-bold"><?= number_format((float)$dp['cash_collected'], 2) ?></td>
                            <td class="num-cell text-success"><?= number_format((float)$dp['verified_handovers'], 2) ?></td>
                            <td class="num-cell <?= $diff > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= $diff <= 0 ? 'Reconciled' : number_format($diff, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="3" class="text-dark">TOTAL FLEET RECONCILIATION</td>
                    <td class="text-center"><?= $totDispatches ?></td>
                    <td class="text-center"><?= $totCompleted ?></td>
                    <td class="text-center"><?= number_format($avgSuccessRate, 1) ?>%</td>
                    <td class="num-cell text-muted"><?= number_format($totAssignedVal, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totDeliveredVal, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($totReturnsVal, 2) ?></td>
                    <td class="num-cell text-primary"><?= number_format($totCashColl, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totDeposited, 2) ?></td>
                    <td class="num-cell <?= $totPendingCustody > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $totPendingCustody <= 0 ? 'Reconciled' : number_format($totPendingCustody, 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

<?php elseif ($reportType === 'customers'): ?>
    <!-- ------------------------------------------------------------- -->
    <!-- 6. CUSTOMER PROFITABILITY & EXPOSURE AUDIT                    -->
    <!-- ------------------------------------------------------------- -->
    <?php
    $totCustInvoices = 0;
    $totCustSales = 0.00;
    $totCustCogs = 0.00;
    $totCustProfit = 0.00;
    $totCustPaid = 0.00;
    $totCustBalance = 0.00;

    foreach ($customersData as $cu) {
        $profit = max(0, (float)$cu['total_sales'] - (float)$cu['total_cogs']);
        $totCustInvoices += (int)$cu['total_invoices'];
        $totCustSales += (float)$cu['total_sales'];
        $totCustCogs += (float)$cu['total_cogs'];
        $totCustProfit += $profit;
        $totCustPaid += (float)$cu['total_paid'];
        $totCustBalance += (float)$cu['current_balance'];
    }
    $avgCustMargin = $totCustSales > 0 ? ($totCustProfit / $totCustSales) * 100 : 0;
    ?>

    <!-- KPI Summary Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Transacting Shops</div>
                <div class="h5 fw-bold text-dark mb-0"><?= count($customersData) ?> Outlets</div>
                <small class="text-muted"><?= $totCustInvoices ?> Total Invoices</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Net Turnover</div>
                <div class="h5 fw-bold text-primary mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totCustSales, 2) ?></div>
                <small class="text-muted">Invoiced business</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Gross Profit Earned</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totCustProfit, 2) ?></div>
                <small class="badge bg-success"><?= number_format($avgCustMargin, 1) ?>% Margin</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="p-2 border rounded bg-light">
                <div class="text-muted small fw-semibold">Customer Collections</div>
                <div class="h5 fw-bold text-success mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totCustPaid, 2) ?></div>
                <small class="text-muted">Cleared in period</small>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="p-2 border rounded bg-danger bg-opacity-10">
                <div class="text-muted small fw-semibold">Total Credit Exposure</div>
                <div class="h5 fw-bold text-danger mb-0"><?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($totCustBalance, 2) ?></div>
                <small class="text-danger fw-semibold">Current ledger balance</small>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Retail Shop / Customer</th>
                    <th>Territory / Contact</th>
                    <th class="text-center">Orders</th>
                    <th class="text-end">Turnover (PKR)</th>
                    <th class="text-end text-danger">COGS (PKR)</th>
                    <th class="text-end text-success fw-bold">Gross Profit</th>
                    <th class="text-center">Margin %</th>
                    <th class="text-end text-success fw-bold">Paid (PKR)</th>
                    <th class="text-end text-danger fw-bold">Current Due (PKR)</th>
                    <th class="text-center">Credit Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customersData)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted fst-italic">
                            <i class="bi bi-shop fs-3 d-block mb-1 text-secondary"></i>
                            No customer transactions recorded.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $idx = 1;
                    foreach ($customersData as $cu):
                        $profit = max(0, (float)$cu['total_sales'] - (float)$cu['total_cogs']);
                        $margin = (float)$cu['total_sales'] > 0 ? ($profit / (float)$cu['total_sales']) * 100 : 0;
                        $bal = (float)$cu['current_balance'];
                        $limit = (float)$cu['credit_limit'];

                        $statusBadge = '<span class="badge bg-success">Safe</span>';
                        if ($limit > 0 && $bal > $limit) {
                            $statusBadge = '<span class="badge bg-danger">Over Limit</span>';
                        } elseif ($limit > 0 && $bal > ($limit * 0.8)) {
                            $statusBadge = '<span class="badge bg-warning text-dark">Near Limit</span>';
                        } elseif ($bal > 0) {
                            $statusBadge = '<span class="badge bg-info text-dark">Active Dues</span>';
                        }
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx++ ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/customers/ledger.php?customer_id=<?= $cu['customer_id'] ?>" class="fw-bold text-dark text-decoration-none">
                                    <?= htmlspecialchars($cu['shop_name']) ?>
                                </a>
                                <div class="small text-muted"><?= htmlspecialchars($cu['owner_name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($cu['area_name'] ?? 'General') ?></span>
                                <div class="small text-muted"><?= htmlspecialchars($cu['phone'] ?? '-') ?></div>
                            </td>
                            <td class="text-center"><?= $cu['total_invoices'] ?></td>
                            <td class="num-cell text-primary fw-bold"><?= number_format((float)$cu['total_sales'], 2) ?></td>
                            <td class="num-cell text-danger"><?= number_format((float)$cu['total_cogs'], 2) ?></td>
                            <td class="num-cell text-success fw-bold"><?= number_format($profit, 2) ?></td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= number_format($margin, 1) ?>%</span>
                            </td>
                            <td class="num-cell text-success fw-bold"><?= number_format((float)$cu['total_paid'], 2) ?></td>
                            <td class="num-cell text-danger fw-bold"><?= number_format($bal, 2) ?></td>
                            <td class="text-center"><?= $statusBadge ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="3" class="text-dark">TOTAL CUSTOMER EXPOSURE</td>
                    <td class="text-center"><?= $totCustInvoices ?></td>
                    <td class="num-cell text-primary"><?= number_format($totCustSales, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($totCustCogs, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totCustProfit, 2) ?></td>
                    <td class="text-center"><?= number_format($avgCustMargin, 1) ?>%</td>
                    <td class="num-cell text-success"><?= number_format($totCustPaid, 2) ?></td>
                    <td class="num-cell text-danger"><?= number_format($totCustBalance, 2) ?></td>
                    <td class="text-center">-</td>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
</div>

<script>
// Date Range Shortcut Helper
function setDateRange(start, end) {
    document.getElementById('startDateInput').value = start;
    document.getElementById('endDateInput').value = end;
}

// Client-side Instant Filter for Table Rows
function filterReportTable() {
    const input = document.getElementById('reportSearchInput');
    const filter = input ? input.value.toLowerCase().trim() : '';
    const table = document.getElementById('reportTable');
    if (!table) return;

    const tbody = table.getElementsByTagName('tbody')[0];
    if (!tbody) return;
    const rows = tbody.getElementsByTagName('tr');

    let visibleCount = 0;
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].classList.contains('no-filter')) continue;
        const text = rows[i].textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            rows[i].style.display = '';
            visibleCount++;
        } else {
            rows[i].style.display = 'none';
        }
    }
    const countBadge = document.getElementById('filteredRowCount');
    if (countBadge) {
        countBadge.textContent = filter ? `${visibleCount} match(es) found` : '';
    }
}

// WhatsApp Share Formatter
function shareReportWhatsApp() {
    const reportType = "<?= $reportType ?>";
    const startDate = "<?= formatDate($startDate) ?>";
    const endDate = "<?= formatDate($endDate) ?>";
    const company = "<?= htmlspecialchars($company['name'] ?? APP_NAME) ?>";
    
    let text = `*${company} - Business Report*\n`;
    text += `Period: ${startDate} to ${endDate}\n`;
    text += `------------------------------------\n`;
    
    if (reportType === 'pnl') {
        text += `*Profit & Loss Summary*\n`;
        text += `Gross Sales: Rs. <?= number_format($grossSalesVal, 2) ?>\n`;
        text += `Net Sales: Rs. <?= number_format($netSalesVal, 2) ?>\n`;
        text += `Cost of Goods (COGS): Rs. <?= number_format($cogsVal, 2) ?>\n`;
        text += `Gross Operating Profit: Rs. <?= number_format($grossProfitVal, 2) ?> (<?= number_format($grossProfitMarginPct, 1) ?>%)\n`;
        text += `Operating Expenses: Rs. <?= number_format($totalExpensesVal, 2) ?>\n`;
        text += `*Net Profit: Rs. <?= number_format($netProfitVal, 2) ?> (<?= number_format($netProfitMarginPct, 1) ?>%)*\n`;
    } else if (reportType === 'items') {
        text += `*Item-wise Sales & Profitability*\n`;
        text += `Active Items: <?= count($itemsData) ?>\n`;
        text += `Net Sold Units: <?= number_format($sumNetSoldQty ?? 0, 0) ?>\n`;
        text += `Total Sales Revenue: Rs. <?= number_format($sumRevenue ?? 0, 2) ?>\n`;
        text += `Total COGS: Rs. <?= number_format($sumCogs ?? 0, 2) ?>\n`;
        text += `*Gross Profit: Rs. <?= number_format($sumProfit ?? 0, 2) ?> (<?= number_format($avgMarginPct ?? 0, 1) ?>%)*\n`;
    } else if (reportType === 'bookers') {
        text += `*Order Booker Performance & Profit*\n`;
        text += `Active Bookers: <?= count($bookersData) ?>\n`;
        text += `Total Booked: Rs. <?= number_format($totBooked ?? 0, 2) ?> (<?= $totOrders ?? 0 ?> orders)\n`;
        text += `Total Delivered: Rs. <?= number_format($totDelivered ?? 0, 2) ?>\n`;
        text += `Gross Profit: Rs. <?= number_format($totProfit ?? 0, 2) ?> (<?= number_format($avgBookerMargin ?? 0, 1) ?>%)\n`;
        text += `Payment Recovery: Rs. <?= number_format($totRecovery ?? 0, 2) ?>\n`;
    } else if (reportType === 'areas') {
        text += `*Territory Sales & Recovery Summary*\n`;
        text += `Total Sales: Rs. <?= number_format($totSales ?? 0, 2) ?>\n`;
        text += `Gross Profit: Rs. <?= number_format($totAreaProfit ?? 0, 2) ?> (<?= number_format($avgAreaMargin ?? 0, 1) ?>%)\n`;
        text += `Total Recoveries: Rs. <?= number_format($totAreaRecovery ?? 0, 2) ?>\n`;
        text += `*Market Outstanding: Rs. <?= number_format($totMarketDue ?? 0, 2) ?>*\n`;
    } else if (reportType === 'drivers') {
        text += `*Delivery Driver & Custody Reconciliation*\n`;
        text += `Total Trips: <?= $totDispatches ?? 0 ?>\n`;
        text += `Delivered Value: Rs. <?= number_format($totDeliveredVal ?? 0, 2) ?>\n`;
        text += `Returns Value: Rs. <?= number_format($totReturnsVal ?? 0, 2) ?>\n`;
        text += `Cash Collected: Rs. <?= number_format($totCashColl ?? 0, 2) ?>\n`;
        text += `Deposited to Cashier: Rs. <?= number_format($totDeposited ?? 0, 2) ?>\n`;
        text += `*Un-handed Balance: Rs. <?= number_format($totPendingCustody ?? 0, 2) ?>*\n`;
    } else {
        text += `*Customer Turnover & Exposure*\n`;
        text += `Active Shops: <?= count($customersData) ?>\n`;
        text += `Net Sales: Rs. <?= number_format($totCustSales ?? 0, 2) ?>\n`;
        text += `Gross Profit: Rs. <?= number_format($totCustProfit ?? 0, 2) ?>\n`;
        text += `Payments Recovered: Rs. <?= number_format($totCustPaid ?? 0, 2) ?>\n`;
        text += `*Total Ledger Balance: Rs. <?= number_format($totCustBalance ?? 0, 2) ?>*\n`;
    }
    
    text += `------------------------------------\n`;
    text += `Enterprise Distribution Management System`;
    
    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp('', text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
