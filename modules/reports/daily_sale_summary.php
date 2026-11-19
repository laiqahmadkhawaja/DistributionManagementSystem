<?php
/**
 * Daily Sales Received Macro Summary (Executive Closing Sheet)
 * Modeled after FMCG Distributor Traditional Standard (Ref Image 2)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('reports', 'sales');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate = sanitize($_GET['end_date'] ?? date('Y-m-d'));

// Query dates in the range that have sales orders, payments, or returns
$sql = "
    SELECT 
        d.dt as tx_date,
        COALESCE(inv.total_invoices, 0) as total_invoices,
        COALESCE(pay.total_cash, 0) as total_cash,
        COALESCE(inv.total_invoices, 0) - COALESCE(pay.total_cash, 0) - COALESCE(ret.total_returns, 0) as total_credit,
        COALESCE(ret.total_returns, 0) as total_returns
    FROM (
        SELECT DISTINCT order_date as dt FROM sales_orders WHERE order_date BETWEEN ? AND ?
        UNION
        SELECT DISTINCT payment_date as dt FROM payments WHERE payment_date BETWEEN ? AND ?
        UNION
        SELECT DISTINCT return_date as dt FROM sales_returns WHERE return_date BETWEEN ? AND ?
    ) d
    LEFT JOIN (
        SELECT order_date, SUM(grand_total) as total_invoices
        FROM sales_orders
        WHERE status NOT IN ('draft', 'rejected', 'cancelled')
        GROUP BY order_date
    ) inv ON d.dt = inv.order_date
    LEFT JOIN (
        SELECT payment_date, SUM(amount) as total_cash
        FROM payments
        WHERE payment_method_code = 'cash' AND status = 'posted'
        GROUP BY payment_date
    ) pay ON d.dt = pay.payment_date
    LEFT JOIN (
        SELECT return_date, SUM(total_amount) as total_returns
        FROM sales_returns
        WHERE status = 'approved'
        GROUP BY return_date
    ) ret ON d.dt = ret.return_date
    ORDER BY d.dt ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totInvoices = 0.00;
$totCash = 0.00;
$totCredit = 0.00;
$totReturns = 0.00;
foreach ($records as $r) {
    $totInvoices += (float)$r['total_invoices'];
    $totCash += (float)$r['total_cash'];
    $totCredit += max(0, (float)$r['total_credit']);
    $totReturns += (float)$r['total_returns'];
}

$compTitle = strtoupper(explode(' ', trim($company['name'] ?? 'MUZZAM'))[0]) . ' DAILY SALE RECIVED';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily_Sale_Received_Summary_<?= date('Ymd') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
        body { background: #f8fafc; padding: 20px; font-size: 11pt; color: #000; }

        .toolbar {
            max-width: 900px;
            margin: 0 auto 15px;
            background: #fff;
            padding: 12px 18px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .toolbar input {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 9pt;
        }
        .btn {
            padding: 6px 14px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 9pt;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-secondary { background: #64748b; color: #fff; }

        .sheet-container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #000;
        }

        .header-title-bar {
            text-align: center;
            padding: 12px;
            border-bottom: 2px solid #000;
        }
        .sheet-title {
            font-size: 15pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        table.summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
        }
        table.summary-table th, table.summary-table td {
            border: 1px solid #000;
            padding: 6px 12px;
            vertical-align: middle;
        }
        /* Header matching Image 2 yellow row */
        table.summary-table thead tr th {
            background-color: #ffff00 !important;
            color: #000;
            font-weight: 900;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }

        .row-total td {
            font-weight: 900;
            font-size: 11pt;
            background-color: #ffff00 !important;
            color: #000;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sheet-container { border: 2px solid #000; max-width: 100%; }
            @page {
                size: A4 portrait;
                margin: 12mm;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Printing Controls -->
    <div class="toolbar">
        <form method="GET" action="">
            <label style="font-weight: bold; font-size: 9pt;">From:</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">

            <label style="font-weight: bold; font-size: 9pt;">To:</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">

            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>

        <div style="display: flex; gap: 8px;">
            <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-secondary">&larr; Reports</a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                &#128438; Print Macro Sheet (A4)
            </button>
        </div>
    </div>

    <!-- Daily Sale Received Macro Sheet (Ref Image 2 Standard) -->
    <div class="sheet-container">
        <div class="header-title-bar">
            <h1 class="sheet-title"><?= htmlspecialchars($compTitle) ?></h1>
        </div>

        <table class="summary-table">
            <thead>
                <tr>
                    <th style="width: 20%;">Date</th>
                    <th style="width: 20%;">Invoices</th>
                    <th style="width: 20%;">Cash Recived</th>
                    <th style="width: 20%;">Credit</th>
                    <th style="width: 20%;">Return</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="5" class="text-center" style="padding: 25px; color: #64748b;">
                            No daily sales transactions recorded in this period.
                        </td>
                    </tr>
                <?php else: foreach ($records as $r): 
                    $inv = (float)$r['total_invoices'];
                    $csh = (float)$r['total_cash'];
                    $crd = max(0, (float)$r['total_credit']);
                    $ret = (float)$r['total_returns'];
                ?>
                    <tr>
                        <td class="text-center"><?= date('d.m.y', strtotime($r['tx_date'])) ?></td>
                        <td class="text-right fw-bold"><?= $inv > 0 ? number_format($inv, 0) : '' ?></td>
                        <td class="text-right"><?= $csh > 0 ? number_format($csh, 0) : '' ?></td>
                        <td class="text-right"><?= $crd > 0 ? number_format($crd, 0) : '' ?></td>
                        <td class="text-right"><?= $ret > 0 ? number_format($ret, 0) : '' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td class="text-center">Total</td>
                    <td class="text-right"><?= number_format($totInvoices, 0) ?></td>
                    <td class="text-right"><?= number_format($totCash, 0) ?></td>
                    <td class="text-right"><?= number_format($totCredit, 0) ?></td>
                    <td class="text-right"><?= number_format($totReturns, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

</body>
</html>
