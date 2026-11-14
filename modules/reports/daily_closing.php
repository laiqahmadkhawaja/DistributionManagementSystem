<?php
/**
 * Daily Day Closing, Sales Margin & Cash-in-Hand Reconciliation
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

// Authorized for Super Admin, Admin, and Accountant
$canClose = hasPermission('reports', 'sales')
    || hasPermission('accounting', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT]);

if (!$canClose) {
    requirePermission('reports', 'sales');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$closingDate = sanitize($_GET['date'] ?? date('Y-m-d'));

// 1. Check if Day is already closed
$closingRecordStmt = $pdo->prepare("
    SELECT dc.*, u.full_name as closed_by_name, u.username as closed_by_user
    FROM daily_closings dc
    LEFT JOIN users u ON dc.closed_by = u.id
    WHERE dc.closing_date = ?
    LIMIT 1
");
$closingRecordStmt->execute([$closingDate]);
$existingClosing = $closingRecordStmt->fetch();

// 2. Compute Previous Day's Closing Cash for Initial Opening Balance
$prevDate = date('Y-m-d', strtotime($closingDate . ' -1 day'));
$prevClosingStmt = $pdo->prepare("SELECT actual_cash FROM daily_closings WHERE closing_date <= ? AND status = 'closed' ORDER BY closing_date DESC LIMIT 1");
$prevClosingStmt->execute([$prevDate]);
$prevActualCash = (float)($prevClosingStmt->fetchColumn() ?: 0.00);

// Default Opening Cash
$defaultOpeningCash = $existingClosing ? (float)$existingClosing['opening_cash'] : $prevActualCash;

// 3. Compute Today's Real-time Sales, Cost & Profit
$salesStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT so.id) as total_orders,
        COALESCE(SUM(so.grand_total), 0) as total_sales,
        COALESCE(SUM(soi.quantity * COALESCE(p.purchase_price, 0)), 0) as total_cogs
    FROM sales_orders so
    LEFT JOIN sales_order_items soi ON so.id = soi.sales_order_id
    LEFT JOIN products p ON soi.product_id = p.id
    WHERE so.order_date = ? AND so.status NOT IN ('draft', 'rejected', 'cancelled')
");
$salesStmt->execute([$closingDate]);
$salesMetrics = $salesStmt->fetch();

$totalSales = $existingClosing ? (float)$existingClosing['total_sales'] : (float)$salesMetrics['total_sales'];
$totalCost = $existingClosing ? (float)$existingClosing['total_cost'] : (float)$salesMetrics['total_cogs'];
$grossProfit = $existingClosing ? (float)$existingClosing['gross_profit'] : ($totalSales - $totalCost);
$profitMarginPct = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0.0;
$totalOrdersCount = $existingClosing ? (int)$existingClosing['orders_count'] : (int)$salesMetrics['total_orders'];

// 4. Compute Real-time Cash Inflow (Customer Collections Received in Cash)
$cashInflowStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_date = ? AND payment_type = 'customer_receipt' AND (LOWER(payment_method_code) LIKE '%cash%' OR payment_method_id = 1) AND status = 'posted'
");
$cashInflowStmt->execute([$closingDate]);
$cashCollected = $existingClosing ? (float)$existingClosing['cash_collected'] : (float)$cashInflowStmt->fetchColumn();

// If no separate payment record, check cash-based sales orders
if ($cashCollected == 0 && !$existingClosing) {
    $cashOrdersStmt = $pdo->prepare("
        SELECT COALESCE(SUM(grand_total), 0)
        FROM sales_orders
        WHERE order_date = ? AND payment_type = 'cash' AND status NOT IN ('draft', 'rejected', 'cancelled')
    ");
    $cashOrdersStmt->execute([$closingDate]);
    $cashCollected = (float)$cashOrdersStmt->fetchColumn();
}

// 5. Compute Real-time Cash Outflow (Operating Expenses Paid in Cash)
$expensesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE expense_date = ? AND LOWER(payment_method) LIKE '%cash%' AND status = 'approved'
");
$expensesStmt->execute([$closingDate]);
$expensesPaid = $existingClosing ? (float)$existingClosing['expenses_paid'] : (float)$expensesStmt->fetchColumn();

// 6. Compute Supplier Disbursements Paid in Cash
$supplierPayStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_date = ? AND payment_type = 'supplier_payment' AND (LOWER(payment_method_code) LIKE '%cash%' OR payment_method_id = 1) AND status = 'posted'
");
$supplierPayStmt->execute([$closingDate]);
$supplierPayments = $existingClosing ? (float)$existingClosing['supplier_payments'] : (float)$supplierPayStmt->fetchColumn();

// Expected System Cash in Hand
$expectedCash = $defaultOpeningCash + $cashCollected - $expensesPaid - $supplierPayments;
$actualCash = $existingClosing ? (float)$existingClosing['actual_cash'] : $expectedCash;
$cashDifference = $existingClosing ? (float)$existingClosing['cash_difference'] : ($actualCash - $expectedCash);

// Detailed Breakdown of Today's Expenses for Visual Audit
$expListStmt = $pdo->prepare("
    SELECT e.expense_number, e.amount, e.description, ec.name as category_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date = ? AND LOWER(e.payment_method) LIKE '%cash%' AND e.status = 'approved'
    ORDER BY e.id DESC
");
$expListStmt->execute([$closingDate]);
$dailyExpensesList = $expListStmt->fetchAll();

// Handle Day Closing POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_day_closing'])) {
    requirePermission('accounting', 'create');

    $closingDatePost = sanitize($_POST['closing_date'] ?? $closingDate);
    $openingCashPost = (float)($_POST['opening_cash'] ?? 0);
    $totalSalesPost = (float)($_POST['total_sales'] ?? $totalSales);
    $totalCostPost = (float)($_POST['total_cost'] ?? $totalCost);
    $grossProfitPost = $totalSalesPost - $totalCostPost;
    $cashCollectedPost = (float)($_POST['cash_collected'] ?? 0);
    $expensesPaidPost = (float)($_POST['expenses_paid'] ?? 0);
    $supplierPaymentsPost = (float)($_POST['supplier_payments'] ?? 0);
    $expectedCashPost = (float)($_POST['expected_cash'] ?? 0);
    $actualCashPost = (float)($_POST['actual_cash'] ?? 0);
    $cashDiffPost = $actualCashPost - $expectedCashPost;
    $ordersCountPost = (int)($_POST['orders_count'] ?? $totalOrdersCount);
    $notesPost = sanitize($_POST['notes'] ?? '');

    try {
        $saveStmt = $pdo->prepare("
            INSERT INTO daily_closings 
            (closing_date, opening_cash, total_sales, total_cost, gross_profit, cash_collected, expenses_paid, supplier_payments, expected_cash, actual_cash, cash_difference, orders_count, status, notes, closed_by, closed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'closed', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            opening_cash = VALUES(opening_cash),
            total_sales = VALUES(total_sales),
            total_cost = VALUES(total_cost),
            gross_profit = VALUES(gross_profit),
            cash_collected = VALUES(cash_collected),
            expenses_paid = VALUES(expenses_paid),
            supplier_payments = VALUES(supplier_payments),
            expected_cash = VALUES(expected_cash),
            actual_cash = VALUES(actual_cash),
            cash_difference = VALUES(cash_difference),
            orders_count = VALUES(orders_count),
            status = 'closed',
            notes = VALUES(notes),
            closed_by = VALUES(closed_by),
            closed_at = NOW()
        ");
        $saveStmt->execute([
            $closingDatePost, $openingCashPost, $totalSalesPost, $totalCostPost, $grossProfitPost,
            $cashCollectedPost, $expensesPaidPost, $supplierPaymentsPost, $expectedCashPost,
            $actualCashPost, $cashDiffPost, $ordersCountPost, $notesPost, $currUser['id']
        ]);

        logAudit($pdo, 'daily_closing', (int)$pdo->lastInsertId(), 'close_day', null, [
            'date' => $closingDatePost,
            'expected_cash' => $expectedCashPost,
            'actual_cash' => $actualCashPost,
            'difference' => $cashDiffPost
        ]);

        setFlashMessage('success', "Daily Day Closing for " . date('d M Y', strtotime($closingDatePost)) . " successfully saved and locked.");
        redirect(BASE_URL . '/modules/reports/daily_closing.php?date=' . $closingDatePost);
    } catch (Exception $e) {
        setFlashMessage('error', 'Failed to save daily closing: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/reports/daily_closing.php?date=' . $closingDatePost);
    }
}

// Handle Re-open Day POST Action (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reopen_day'])) {
    if ($currUser['role_slug'] !== ROLE_SUPER_ADMIN && $currUser['role_slug'] !== ROLE_ADMIN) {
        setFlashMessage('error', 'Only Super Administrator can reopen a locked business day.');
        redirect(BASE_URL . '/modules/reports/daily_closing.php?date=' . $closingDate);
    }

    $pdo->prepare("UPDATE daily_closings SET status = 'open' WHERE closing_date = ?")->execute([$closingDate]);
    logAudit($pdo, 'daily_closing', 0, 'reopen_day', null, ['date' => $closingDate]);
    setFlashMessage('info', "Business day " . date('d M Y', strtotime($closingDate)) . " reopened for editing.");
    redirect(BASE_URL . '/modules/reports/daily_closing.php?date=' . $closingDate);
}

// 7. Recent Past Closings History
$historyStmt = $pdo->query("
    SELECT dc.*, u.full_name as closed_by_name
    FROM daily_closings dc
    LEFT JOIN users u ON dc.closed_by = u.id
    ORDER BY dc.closing_date DESC
    LIMIT 7
");
$pastClosings = $historyStmt->fetchAll();

$pageTitle = 'Daily Day Closing & Cash Reconciliation - ' . date('d M Y', strtotime($closingDate));
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
.metric-box {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 14px;
    background: #fff;
}
.metric-box-title {
    font-size: 0.76rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 4px;
}
.metric-box-value {
    font-size: 1.35rem;
    font-weight: 800;
    line-height: 1.1;
}
.table-closing-dense {
    font-size: 0.85rem;
}
.table-closing-dense td {
    padding: 6px 10px;
    vertical-align: middle;
}
.table-closing-dense tr.highlight-row {
    background-color: #f8fafc;
    font-weight: 700;
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
    }
    .d-print-none, header.navbar, .sidebar, .dms-sidebar, .flash-container {
        display: none !important;
    }
    .dms-layout-container, .dms-main-content {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
    .table-closing-dense td, .table-closing-dense th {
        border: 1px solid #000 !important;
        padding: 3px 6px !important;
        font-size: 8pt !important;
    }
    .print-sign-box {
        border-top: 1px solid #000;
        margin-top: 28px;
        padding-top: 4px;
        text-align: center;
        font-size: 7.5pt;
        font-weight: bold;
    }
}
</style>

<div class="dms-closing-wrapper">
    <!-- Header Controls -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
        <div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Reports Hub
                </a>
                <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-safe2 text-success me-2"></i>Daily Day Closing (ڈیلی ڈے کلوزنگ)</h4>
            </div>
            <small class="text-muted">Daily starting cash, gross profit calculation, expense reconciliation & physical cash-in-hand lock</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-truck-flatbed me-1"></i> Dispatch Load Sheet
            </a>
            <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
                <i class="bi bi-printer me-1"></i> Print Closing Sheet (Excel View)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="shareClosingWhatsApp();">
                <i class="bi bi-whatsapp me-1 text-success"></i> Share WhatsApp
            </button>
        </div>
    </div>

    <!-- Date Selector & Status Banner -->
    <div class="card border-0 shadow-sm rounded-3 mb-3 d-print-none">
        <div class="card-body p-3">
            <div class="row align-items-center g-2">
                <div class="col-12 col-md-5">
                    <form method="GET" action="<?= BASE_URL ?>/modules/reports/daily_closing.php" class="d-flex align-items-center gap-2">
                        <label class="small fw-bold text-nowrap mb-0">Select Day:</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($closingDate) ?>" onchange="this.form.submit();">
                        <button type="submit" class="btn btn-sm btn-outline-dark">Go</button>
                        <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php" class="btn btn-sm btn-outline-secondary text-nowrap">Today</a>
                    </form>
                </div>
                <div class="col-12 col-md-7 text-md-end">
                    <?php if ($existingClosing && $existingClosing['status'] === 'closed'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 px-3 py-2">
                            <i class="bi bi-lock-fill me-1"></i> Day Closed by <?= htmlspecialchars($existingClosing['closed_by_name'] ?? 'Admin') ?> on <?= date('d-M-Y h:i A', strtotime($existingClosing['closed_at'])) ?>
                        </span>
                        <?php if ($currUser['role_slug'] === ROLE_SUPER_ADMIN || $currUser['role_slug'] === ROLE_ADMIN): ?>
                            <form method="POST" action="<?= BASE_URL ?>/modules/reports/daily_closing.php?date=<?= $closingDate ?>" class="d-inline ms-2">
                                <?= getCsrfField() ?>
                                <input type="hidden" name="reopen_day" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-warning py-1" onclick="return confirm('Reopen this closed day for adjustments?');">
                                    <i class="bi bi-unlock me-1"></i> Reopen Day
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-dark border border-warning-subtle fs-6 px-3 py-2">
                            <i class="bi bi-clock me-1"></i> Business Day Open (Real-Time Live Calculation)
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Printable Daily Closing Voucher -->
    <div id="printableClosingArea" class="bg-white p-3 p-print-0 border rounded-3 mb-4 shadow-sm">
        <!-- Certificate Header -->
        <div class="border-bottom pb-2 mb-3">
            <div class="row align-items-center">
                <div class="col-7">
                    <h5 class="fw-bold mb-0 text-dark text-uppercase"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h5>
                    <div class="small text-muted" style="font-size: 0.78rem;">
                        <?= htmlspecialchars($company['address'] ?? 'Plot 45, Small Industrial Estate') ?> Â· Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 1234567') ?>
                    </div>
                </div>
                <div class="col-5 text-end">
                    <div class="badge bg-dark fs-6 px-3 py-1 text-uppercase">DAILY CLOSING & CASH STATEMENT</div>
                    <div class="small fw-bold text-dark mt-1">Date: <?= date('d-F-Y (l)', strtotime($closingDate)) ?></div>
                </div>
            </div>
        </div>

        <!-- 4 Top KPI Indicator Tiles -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="metric-box border-primary">
                    <div class="metric-box-title text-primary"><i class="bi bi-cash-stack me-1"></i> Opening Cash</div>
                    <div class="metric-box-value text-primary">Rs. <?= number_format($defaultOpeningCash, 2) ?></div>
                    <small class="text-muted" style="font-size: 0.72rem;">Brought forward from previous day</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-box">
                    <div class="metric-box-title"><i class="bi bi-cart-check me-1"></i> Total Sales (<?= $totalOrdersCount ?> Orders)</div>
                    <div class="metric-box-value text-dark">Rs. <?= number_format($totalSales, 2) ?></div>
                    <small class="text-muted" style="font-size: 0.72rem;">Cost Price: Rs. <?= number_format($totalCost, 2) ?></small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-box border-success">
                    <div class="metric-box-title text-success"><i class="bi bi-graph-up-arrow me-1"></i> Gross Profit (Margin)</div>
                    <div class="metric-box-value text-success">Rs. <?= number_format($grossProfit, 2) ?></div>
                    <small class="text-success fw-bold" style="font-size: 0.72rem;"><?= number_format($profitMarginPct, 1) ?>% Gross Margin</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-box <?= $cashDifference == 0 ? 'border-success' : ($cashDifference < 0 ? 'border-danger' : 'border-info') ?>">
                    <div class="metric-box-title <?= $cashDifference == 0 ? 'text-success' : ($cashDifference < 0 ? 'text-danger' : 'text-info') ?>">
                        <i class="bi bi-wallet2 me-1"></i> Physical Cash in Hand
                    </div>
                    <div class="metric-box-value <?= $cashDifference == 0 ? 'text-success' : ($cashDifference < 0 ? 'text-danger' : 'text-info') ?>">
                        Rs. <?= number_format($actualCash, 2) ?>
                    </div>
                    <small class="fw-bold" style="font-size: 0.72rem;">
                        <?php if ($cashDifference == 0): ?>
                            <span class="text-success">âœ“ Cash Exactly Matched</span>
                        <?php elseif ($cashDifference < 0): ?>
                            <span class="text-danger">âš  Short by Rs. <?= number_format(abs($cashDifference), 2) ?></span>
                        <?php else: ?>
                            <span class="text-info">+ Excess by Rs. <?= number_format($cashDifference, 2) ?></span>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- Left Side: Sales, Cost & Profit Breakdown -->
            <div class="col-12 col-lg-6">
                <div class="card border rounded-3 h-100">
                    <div class="card-header bg-light py-2">
                        <span class="fw-bold text-dark text-uppercase small">
                            <i class="bi bi-receipt me-1"></i> 1. Sales & Profitability Analysis (کتنے میں بیچی، کاسٹ، پرافٹ)
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-closing-dense mb-0">
                            <tbody>
                                <tr>
                                    <td>Total Booked Sales Orders</td>
                                    <td class="text-end fw-bold"><?= $totalOrdersCount ?> Orders</td>
                                </tr>
                                <tr>
                                    <td>Gross Sales Value (Trade Price Total)</td>
                                    <td class="text-end fw-bold fs-6 text-dark">Rs. <?= number_format($totalSales, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Less: Cost of Goods Sold (Purchase Cost Total)</td>
                                    <td class="text-end text-danger fw-semibold">- Rs. <?= number_format($totalCost, 2) ?></td>
                                </tr>
                                <tr class="highlight-row table-success">
                                    <td>Net Gross Trading Profit</td>
                                    <td class="text-end text-success fw-bold fs-6">Rs. <?= number_format($grossProfit, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Gross Profit Margin %</td>
                                    <td class="text-end fw-bold text-success"><?= number_format($profitMarginPct, 2) ?> %</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Side: Cash in Hand Reconciliation & Register Balance -->
            <div class="col-12 col-lg-6">
                <div class="card border rounded-3 h-100">
                    <div class="card-header bg-light py-2">
                        <span class="fw-bold text-dark text-uppercase small">
                            <i class="bi bi-calculator me-1"></i> 2. Daily Cash in Hand Ledger (کیش ان ہینڈ کی کلوزنگ)
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-closing-dense mb-0">
                            <tbody>
                                <tr>
                                    <td><i class="bi bi-arrow-right-circle text-primary me-1"></i> <strong>Starting / Opening Cash</strong> (صبح کا ابتدائی کیش)</td>
                                    <td class="text-end fw-bold text-primary">Rs. <?= number_format($defaultOpeningCash, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-plus-circle text-success me-1"></i> Add: Cash Collections Received (وصولیاں)</td>
                                    <td class="text-end text-success fw-bold">+ Rs. <?= number_format($cashCollected, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-dash-circle text-danger me-1"></i> Less: Operating Expenses Paid (روزمرہ اخراجات)</td>
                                    <td class="text-end text-danger fw-semibold">- Rs. <?= number_format($expensesPaid, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-dash-circle text-danger me-1"></i> Less: Supplier Cash Disbursed (سپلائر ادائیگیاں)</td>
                                    <td class="text-end text-danger fw-semibold">- Rs. <?= number_format($supplierPayments, 2) ?></td>
                                </tr>
                                <tr class="highlight-row table-light">
                                    <td><strong>Expected System Cash in Hand</strong> (متوقع کیش بیلنس)</td>
                                    <td class="text-end fw-bold fs-6 text-dark">Rs. <?= number_format($expectedCash, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Actual Physical Cash Count</strong> (گن کر رکھا گیا کیش)</td>
                                    <td class="text-end fw-bold fs-6 text-primary">Rs. <?= number_format($actualCash, 2) ?></td>
                                </tr>
                                <tr class="<?= $cashDifference == 0 ? 'table-success' : ($cashDifference < 0 ? 'table-danger' : 'table-info') ?>">
                                    <td><strong>Reconciliation Difference</strong> (کیش کا فرق)</td>
                                    <td class="text-end fw-bold fs-6">
                                        <?php if ($cashDifference == 0): ?>
                                            <span class="text-success">Rs. 0.00 (Balanced)</span>
                                        <?php elseif ($cashDifference < 0): ?>
                                            <span class="text-danger">- Rs. <?= number_format(abs($cashDifference), 2) ?> (Short)</span>
                                        <?php else: ?>
                                            <span class="text-info">+ Rs. <?= number_format($cashDifference, 2) ?> (Over)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Today's Cash Expenses Details (If Any) -->
        <?php if (!empty($dailyExpensesList)): ?>
            <div class="card border rounded-3 mt-3">
                <div class="card-header bg-light py-2">
                    <span class="fw-bold text-dark text-uppercase small">
                        <i class="bi bi-wallet me-1"></i> 3. Itemized Cash Expenses Paid Today (آج ادا شدہ کیش اخراجات)
                    </span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-closing-dense mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 120px;">Expense #</th>
                                <th style="width: 180px;">Category</th>
                                <th>Description / Particulars</th>
                                <th class="text-end" style="width: 120px;">Amount (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyExpensesList as $de): ?>
                                <tr>
                                    <td class="font-monospace fw-bold"><?= htmlspecialchars($de['expense_number']) ?></td>
                                    <td><span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($de['category_name'] ?? 'General') ?></span></td>
                                    <td><?= htmlspecialchars($de['description']) ?></td>
                                    <td class="text-end fw-bold text-danger">Rs. <?= number_format((float)$de['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Day Closing Form (Interactive for Open Day, Hidden on Print) -->
        <?php if (!$existingClosing || $existingClosing['status'] !== 'closed'): ?>
            <div class="card border-primary shadow-sm rounded-3 mt-3 d-print-none">
                <div class="card-header bg-primary text-white py-2">
                    <span class="fw-bold small text-uppercase">
                        <i class="bi bi-check2-circle me-1"></i> Daily Day-End Verification & Cash Closing Entry
                    </span>
                </div>
                <div class="card-body p-3">
                    <form method="POST" action="<?= BASE_URL ?>/modules/reports/daily_closing.php?date=<?= $closingDate ?>" id="dayClosingForm">
                        <?= getCsrfField() ?>
                        <input type="hidden" name="submit_day_closing" value="1">
                        <input type="hidden" name="closing_date" value="<?= htmlspecialchars($closingDate) ?>">
                        <input type="hidden" name="total_sales" value="<?= $totalSales ?>">
                        <input type="hidden" name="total_cost" value="<?= $totalCost ?>">
                        <input type="hidden" name="orders_count" value="<?= $totalOrdersCount ?>">

                        <div class="row g-3">
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-bold">1. Opening Cash (Rs.)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="input_opening_cash" name="opening_cash" value="<?= $defaultOpeningCash ?>" oninput="recalcCashClosing();" required>
                                <small class="text-muted">Morning start cash in till</small>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-bold">2. Cash Recoveries Inflow (Rs.)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="input_cash_collected" name="cash_collected" value="<?= $cashCollected ?>" oninput="recalcCashClosing();" required>
                                <small class="text-muted">Total customer collections</small>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-bold">3. Cash Expenses Outflow (Rs.)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="input_expenses_paid" name="expenses_paid" value="<?= $expensesPaid ?>" oninput="recalcCashClosing();" required>
                                <small class="text-muted">Fuel, maintenance, tea, etc.</small>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-bold">4. Supplier Cash Outflow (Rs.)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="input_supplier_payments" name="supplier_payments" value="<?= $supplierPayments ?>" oninput="recalcCashClosing();" required>
                                <small class="text-muted">Cash paid to suppliers</small>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-bold text-primary">5. Expected Cash Balance (Rs.)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm bg-light fw-bold" id="input_expected_cash" name="expected_cash" value="<?= $expectedCash ?>" readonly>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-bold text-success">6. Actual Physical Cash Counted (Rs.) *</label>
                                <input type="number" step="0.01" class="form-control form-control-sm border-success fw-bold" id="input_actual_cash" name="actual_cash" value="<?= $actualCash ?>" oninput="recalcCashClosing();" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-bold">Difference (Over / Short)</label>
                                <div id="live_diff_badge" class="form-control form-control-sm fw-bold bg-light">
                                    Rs. <?= number_format($cashDifference, 2) ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Closing Notes / Remarks</label>
                                <input type="text" class="form-control form-control-sm" name="notes" placeholder="e.g. All van drivers submitted collections; cash physically tallied with cashier." value="<?= htmlspecialchars($existingClosing['notes'] ?? '') ?>">
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-success fw-bold px-4">
                                    <i class="bi bi-lock-fill me-1"></i> Lock & Complete Daily Closing
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Operational Signatures on Print -->
        <div class="row mt-4 pt-2">
            <div class="col-4">
                <div class="print-sign-box">
                    Cashier / Accounts Officer<br>
                    <span class="small text-muted"><?= htmlspecialchars($currUser['full_name']) ?></span>
                </div>
            </div>
            <div class="col-4">
                <div class="print-sign-box">
                    Operations Manager<br>
                    <span class="small text-muted">Audited & Verified</span>
                </div>
            </div>
            <div class="col-4">
                <div class="print-sign-box">
                    Proprietor / Managing Director<br>
                    <span class="small text-muted">Executive Authorization</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Recent Closings History Table -->
    <?php if (!empty($pastClosings)): ?>
        <div class="card border-0 shadow-sm rounded-3 d-print-none mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-secondary me-2"></i>Past 7 Days Closings History</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Opening</th>
                            <th class="text-end">Sales Value</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Gross Profit</th>
                            <th class="text-end">Expenses</th>
                            <th class="text-end">Actual Cash</th>
                            <th class="text-center">Status</th>
                            <th>Closed By</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pastClosings as $pc): ?>
                            <tr>
                                <td class="fw-bold">
                                    <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php?date=<?= $pc['closing_date'] ?>" class="text-decoration-none text-dark">
                                        <?= date('d-M-Y', strtotime($pc['closing_date'])) ?>
                                    </a>
                                </td>
                                <td class="text-end">Rs. <?= number_format((float)$pc['opening_cash'], 2) ?></td>
                                <td class="text-end fw-semibold">Rs. <?= number_format((float)$pc['total_sales'], 2) ?></td>
                                <td class="text-end text-muted small">Rs. <?= number_format((float)$pc['total_cost'], 2) ?></td>
                                <td class="text-end fw-bold text-success">Rs. <?= number_format((float)$pc['gross_profit'], 2) ?></td>
                                <td class="text-end text-danger">Rs. <?= number_format((float)$pc['expenses_paid'], 2) ?></td>
                                <td class="text-end fw-bold text-primary">Rs. <?= number_format((float)$pc['actual_cash'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $pc['status'] === 'closed' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                        <?= strtoupper($pc['status']) ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($pc['closed_by_name'] ?? 'System') ?></td>
                                <td class="text-end pe-3">
                                    <a href="<?= BASE_URL ?>/modules/reports/daily_closing.php?date=<?= $pc['closing_date'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="View Closing Voucher">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function recalcCashClosing() {
    const opening = parseFloat(document.getElementById('input_opening_cash').value) || 0;
    const collected = parseFloat(document.getElementById('input_cash_collected').value) || 0;
    const expenses = parseFloat(document.getElementById('input_expenses_paid').value) || 0;
    const supplier = parseFloat(document.getElementById('input_supplier_payments').value) || 0;
    const actual = parseFloat(document.getElementById('input_actual_cash').value) || 0;

    const expected = opening + collected - expenses - supplier;
    document.getElementById('input_expected_cash').value = expected.toFixed(2);

    const diff = actual - expected;
    const badge = document.getElementById('live_diff_badge');
    if (diff === 0) {
        badge.className = 'form-control form-control-sm fw-bold bg-success-subtle text-success';
        badge.innerText = 'Rs. 0.00 (Balanced âœ“)';
    } else if (diff < 0) {
        badge.className = 'form-control form-control-sm fw-bold bg-danger-subtle text-danger';
        badge.innerText = '- Rs. ' + Math.abs(diff).toFixed(2) + ' (Short)';
    } else {
        badge.className = 'form-control form-control-sm fw-bold bg-info-subtle text-info';
        badge.innerText = '+ Rs. ' + diff.toFixed(2) + ' (Excess)';
    }
}

function shareClosingWhatsApp() {
    const text = `*DAILY DAY CLOSING - <?= addslashes(htmlspecialchars($company['name'] ?? APP_NAME)) ?>*\n` +
                 `Date: <?= $closingDate ?>\n` +
                 `Opening Cash: Rs. <?= number_format($defaultOpeningCash, 2) ?>\n` +
                 `Total Sales: Rs. <?= number_format($totalSales, 2) ?> (<?= $totalOrdersCount ?> Orders)\n` +
                 `Cost Price: Rs. <?= number_format($totalCost, 2) ?>\n` +
                 `Gross Profit: Rs. <?= number_format($grossProfit, 2) ?> (<?= number_format($profitMarginPct, 1) ?>%)\n` +
                 `Cash Recoveries: Rs. <?= number_format($cashCollected, 2) ?>\n` +
                 `Expenses Paid: Rs. <?= number_format($expensesPaid, 2) ?>\n` +
                 `Actual Cash in Hand: Rs. <?= number_format($actualCash, 2) ?>\n` +
                 `Status: <?= strtoupper($existingClosing['status'] ?? 'Open') ?>`;
    window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
