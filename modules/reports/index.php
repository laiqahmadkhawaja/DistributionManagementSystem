<?php
/**
 * Comprehensive Enterprise Reports & P&L Analytics
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('reports', 'sales');

$pdo = getDatabaseConnection();

$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate = sanitize($_GET['end_date'] ?? date('Y-m-d'));
$reportType = sanitize($_GET['tab'] ?? 'pnl');

// 1. Profit & Loss Statement Data
$salesRevAcc = getSystemAccount($pdo, 'sales_revenue');
$cogsAcc = getSystemAccount($pdo, 'cogs');
$salesRetAcc = getSystemAccount($pdo, 'sales_returns');

// Gross Sales Revenue
$stmt = $pdo->prepare("SELECT COALESCE(SUM(credit) - SUM(debit), 0) FROM `journal_entry_lines` jel JOIN `journal_entries` je ON jel.journal_entry_id = je.id WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?");
$stmt->execute([$salesRevAcc['id'], $startDate, $endDate]);
$grossSalesVal = (float)$stmt->fetchColumn();

// Sales Returns
$stmt->execute([$salesRetAcc['id'], $startDate, $endDate]);
$salesReturnsVal = (float)$stmt->fetchColumn();

$netSalesVal = max(0, $grossSalesVal - $salesReturnsVal);

// Cost of Goods Sold (COGS)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM `journal_entry_lines` jel JOIN `journal_entries` je ON jel.journal_entry_id = je.id WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?");
$stmt->execute([$cogsAcc['id'], $startDate, $endDate]);
$cogsVal = (float)$stmt->fetchColumn();

$grossProfitVal = $netSalesVal - $cogsVal;

// Categorized Operating Expenses
$expStmt = $pdo->prepare("
    SELECT coa.name as category_name, COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) as cat_amount
    FROM `journal_entry_lines` jel
    JOIN `journal_entries` je ON jel.journal_entry_id = je.id
    JOIN `chart_of_accounts` coa ON jel.account_id = coa.id
    WHERE coa.group_type = 'Expense' AND je.entry_date BETWEEN ? AND ?
    GROUP BY coa.id, coa.name
    HAVING cat_amount > 0
");
$expStmt->execute([$startDate, $endDate]);
$expenseBreakdown = $expStmt->fetchAll();

$totalExpensesVal = 0.00;
foreach ($expenseBreakdown as $eb) {
    $totalExpensesVal += (float)$eb['cat_amount'];
}

$netProfitVal = $grossProfitVal - $totalExpensesVal;

// 2. Area-wise Sales Performance
$areaStmt = $pdo->prepare("
    SELECT a.name as area_name, COUNT(so.id) as order_count, COALESCE(SUM(so.grand_total), 0) as total_sales
    FROM `areas` a
    LEFT JOIN `sales_orders` so ON a.id = so.area_id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
    WHERE a.status = 'active'
    GROUP BY a.id, a.name
    ORDER BY total_sales DESC
");
$areaStmt->execute([$startDate, $endDate]);
$areaPerformance = $areaStmt->fetchAll();

// 3. Order Booker Performance
$bookerStmt = $pdo->prepare("
    SELECT e.full_name as booker_name, e.employee_code, COUNT(so.id) as total_orders,
           COALESCE(SUM(so.grand_total), 0) as booked_amount,
           COALESCE(SUM(CASE WHEN so.status = 'delivered' THEN so.grand_total ELSE 0 END), 0) as delivered_amount
    FROM `employees` e
    LEFT JOIN `sales_orders` so ON e.id = so.order_booker_id AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
    WHERE e.role_type IN ('order_booker', 'salesman') AND e.status = 'active'
    GROUP BY e.id, e.full_name, e.employee_code
    ORDER BY booked_amount DESC
");
$bookerStmt->execute([$startDate, $endDate]);
$bookerPerformance = $bookerStmt->fetchAll();

$pageTitle = 'Business Analytics & Reports';
require_once dirname(__DIR__, 2) . '/includes/header.php';
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-line text-primary me-2"></i>Executive Reports & Analytics</h4>
        <small class="text-muted">Profit & Loss, Territory Performance, Sales Booker Audits</small>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <button type="button" class="btn btn-sm btn-success" onclick="exportTableToCSV('reportTable', 'DMS_Report_<?= $reportType ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.csv)
        </button>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Excel Sheet
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareReportWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('reportContainer', 'DMS_Report_<?= $reportType ?>_<?= date('Ymd') ?>');">
            <i class="bi bi-image me-1"></i> Image
        </button>
    </div>
</div>

<!-- Date Filter Form -->
<div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
    <div class="card-body p-3">
        <form method="GET" action="<?= BASE_URL ?>/modules/reports/index.php" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($reportType) ?>">
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">Reporting From</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= $startDate ?>">
            </div>
            <div class="col-5 col-md-4">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= $endDate ?>">
            </div>
            <div class="col-2 col-md-4">
                <button type="submit" class="btn btn-sm btn-primary w-100">Update Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Navigation Tabs -->
<ul class="nav nav-pills mb-3 d-print-none gap-1" role="tablist">
    <li class="nav-item">
        <a class="nav-link btn-touch <?= $reportType === 'pnl' ? 'active' : '' ?> fw-semibold" href="<?= BASE_URL ?>/modules/reports/index.php?tab=pnl&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
            <i class="bi bi-calculator me-1"></i> Profit & Loss Statement
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link btn-touch <?= $reportType === 'areas' ? 'active' : '' ?> fw-semibold" href="<?= BASE_URL ?>/modules/reports/index.php?tab=areas&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
            <i class="bi bi-geo-alt me-1"></i> Territory Sales
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link btn-touch <?= $reportType === 'bookers' ? 'active' : '' ?> fw-semibold" href="<?= BASE_URL ?>/modules/reports/index.php?tab=bookers&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
            <i class="bi bi-people me-1"></i> Order Booker Audits
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link btn-touch fw-semibold text-primary" href="<?= BASE_URL ?>/modules/reports/loadsheet.php">
            <i class="bi bi-truck-flatbed me-1"></i> Dispatch Load Sheet
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link btn-touch fw-semibold text-success" href="<?= BASE_URL ?>/modules/reports/daily_closing.php">
            <i class="bi bi-safe2 me-1"></i> Daily Day Closing
        </a>
    </li>
</ul>

<!-- Report Contents Container (Excel Spreadsheet Style) -->
<div id="reportContainer" class="bg-white p-3 p-md-4 rounded-3 shadow-sm border mb-4">
    <!-- Excel Report Header Banner -->
    <div class="border-bottom pb-3 mb-3">
        <div class="row align-items-center">
            <div class="col-sm-8">
                <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h5>
                <div class="text-muted small"><?= htmlspecialchars($company['address'] ?? 'Gujranwala, Pakistan') ?> · Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 0000000') ?></div>
                <div class="fw-semibold text-primary mt-1" style="font-size: 0.95rem;">
                    <?php if ($reportType === 'pnl'): ?>
                        Statement of Profit & Loss (Income Statement)
                    <?php elseif ($reportType === 'areas'): ?>
                        Territory & Area-wise Sales Performance Report
                    <?php else: ?>
                        Order Booker & Sales Representative Efficiency Audit
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-sm-4 text-sm-end mt-2 mt-sm-0">
                <span class="badge bg-success bg-opacity-10 text-success border border-success fw-bold px-2 py-1 mb-1">Spreadsheet View</span>
                <div class="small text-muted"><strong>Period:</strong> <?= formatDate($startDate) ?> to <?= formatDate($endDate) ?></div>
                <div class="small text-muted"><strong>Generated:</strong> <?= date('d-M-Y H:i') ?> by <?= htmlspecialchars($currUser['full_name'] ?? 'Admin') ?></div>
                <div class="small text-muted"><strong>Currency:</strong> <?= DEFAULT_CURRENCY ?> (<?= DEFAULT_CURRENCY_SYMBOL ?>)</div>
            </div>
        </div>
    </div>

<?php if ($reportType === 'pnl'): ?>
    <!-- 1. Formal Double-Entry Profit & Loss Statement (Excel Styled) -->
    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th colspan="2" style="background-color: #107c41; color: #fff;">Financial Head / Account Description</th>
                    <th class="text-end" style="width: 200px; background-color: #107c41; color: #fff;">Amount (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td colspan="3" class="text-dark">1. OPERATING REVENUE</td>
                </tr>
                <tr>
                    <td class="ps-4">Gross Sales Revenue (Invoiced)</td>
                    <td class="num-cell"><?= number_format($grossSalesVal, 2) ?></td>
                    <td></td>
                </tr>
                <tr>
                    <td class="ps-4 text-danger">Less: Sales Returns & Allowances</td>
                    <td class="num-cell text-danger">(<?= number_format($salesReturnsVal, 2) ?>)</td>
                    <td></td>
                </tr>
                <tr style="background-color: #e8f5e9; font-weight: 700;">
                    <td class="ps-3 text-success">NET SALES REVENUE</td>
                    <td></td>
                    <td class="num-cell text-success fs-6"><?= number_format($netSalesVal, 2) ?></td>
                </tr>

                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td colspan="3" class="text-dark">2. COST OF GOODS SOLD</td>
                </tr>
                <tr>
                    <td class="ps-4">Cost of Goods Sold (at Weighted Average Cost)</td>
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
                    <td colspan="2" class="ps-3 text-danger">Total Operating Expenses</td>
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

<?php elseif ($reportType === 'areas'): ?>
    <!-- 2. Territory / Area Performance (Excel Styled) -->
    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 60px;">#</th>
                    <th>Territory / Sales Zone</th>
                    <th class="text-center" style="width: 150px;">Orders Booked</th>
                    <th class="text-end" style="width: 220px;">Booked Sales Volume (PKR)</th>
                    <th class="text-end" style="width: 180px;">Share of Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totOrders = 0;
                $totSales = 0.00;
                foreach ($areaPerformance as $ap) {
                    $totOrders += (int)$ap['order_count'];
                    $totSales += (float)$ap['total_sales'];
                }
                $idx = 1;
                foreach ($areaPerformance as $ap):
                    $share = $totSales > 0 ? (($ap['total_sales'] / $totSales) * 100) : 0;
                ?>
                    <tr>
                        <td class="text-center text-muted"><?= $idx++ ?></td>
                        <td class="fw-bold text-dark"><?= htmlspecialchars($ap['area_name']) ?></td>
                        <td class="text-center"><?= $ap['order_count'] ?></td>
                        <td class="num-cell text-primary fw-bold"><?= number_format((float)$ap['total_sales'], 2) ?></td>
                        <td class="num-cell text-muted"><?= number_format($share, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="2" class="text-dark">TOTAL TERRITORY SALES</td>
                    <td class="text-center text-dark"><?= $totOrders ?></td>
                    <td class="num-cell text-primary"><?= number_format($totSales, 2) ?></td>
                    <td class="num-cell text-dark">100.0%</td>
                </tr>
            </tfoot>
        </table>
    </div>

<?php elseif ($reportType === 'bookers'): ?>
    <!-- 3. Booker Performance (Excel Styled) -->
    <div class="table-responsive">
        <table class="table table-excel align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 60px;">#</th>
                    <th>Sales Representative / Booker</th>
                    <th>Staff Code</th>
                    <th class="text-center" style="width: 140px;">Total Orders</th>
                    <th class="text-end" style="width: 200px;">Booked Value (PKR)</th>
                    <th class="text-end" style="width: 200px;">Delivered Value (PKR)</th>
                    <th class="text-end" style="width: 150px;">Fulfillment %</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totBookerOrders = 0;
                $totBooked = 0.00;
                $totDelivered = 0.00;
                foreach ($bookerPerformance as $bp) {
                    $totBookerOrders += (int)$bp['total_orders'];
                    $totBooked += (float)$bp['booked_amount'];
                    $totDelivered += (float)$bp['delivered_amount'];
                }
                $idx = 1;
                foreach ($bookerPerformance as $bp):
                    $rate = $bp['booked_amount'] > 0 ? (($bp['delivered_amount'] / $bp['booked_amount']) * 100) : 0;
                ?>
                    <tr>
                        <td class="text-center text-muted"><?= $idx++ ?></td>
                        <td class="fw-bold text-dark"><?= htmlspecialchars($bp['booker_name']) ?></td>
                        <td><code><?= htmlspecialchars($bp['employee_code']) ?></code></td>
                        <td class="text-center"><?= $bp['total_orders'] ?></td>
                        <td class="num-cell text-primary fw-bold"><?= number_format((float)$bp['booked_amount'], 2) ?></td>
                        <td class="num-cell text-success fw-bold"><?= number_format((float)$bp['delivered_amount'], 2) ?></td>
                        <td class="num-cell"><?= number_format($rate, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #e2e8f0; font-weight: 700; border-top: 2px solid #000; border-bottom: 3px double #000;">
                    <td colspan="3" class="text-dark">TOTAL REPRESENTATIVE PERFORMANCE</td>
                    <td class="text-center text-dark"><?= $totBookerOrders ?></td>
                    <td class="num-cell text-primary"><?= number_format($totBooked, 2) ?></td>
                    <td class="num-cell text-success"><?= number_format($totDelivered, 2) ?></td>
                    <td class="num-cell text-dark"><?= $totBooked > 0 ? number_format(($totDelivered / $totBooked) * 100, 1) : '0.0' ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
</div>

<script>
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
        text += `Gross Operating Profit: Rs. <?= number_format($grossProfitVal, 2) ?>\n`;
        text += `Operating Expenses: Rs. <?= number_format($totalExpensesVal, 2) ?>\n`;
        text += `*Net Profit: Rs. <?= number_format($netProfitVal, 2) ?>*\n`;
    } else if (reportType === 'areas') {
        text += `*Territory Sales Performance*\n`;
        text += `Total Booked Sales: Rs. <?= number_format($totSales ?? 0, 2) ?>\n`;
        text += `Total Orders: <?= $totOrders ?? 0 ?>\n`;
    } else {
        text += `*Order Booker Performance*\n`;
        text += `Total Booked: Rs. <?= number_format($totBooked ?? 0, 2) ?>\n`;
        text += `Total Delivered: Rs. <?= number_format($totDelivered ?? 0, 2) ?>\n`;
    }
    
    text += `------------------------------------\n`;
    text += `Generated from Enterprise DMS`;
    
    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp('', text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
