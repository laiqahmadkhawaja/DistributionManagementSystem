<?php
/**
 * Dedicated Minimal Excel-View Print Template for Load Sheets
 * Enterprise Distribution Management System (DMS)
 * Fits perfectly on a single A4 page with high-density tabular presentation
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canView = hasPermission('reports', 'sales')
    || hasPermission('warehouse', 'view')
    || hasPermission('delivery', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STOREKEEPER, ROLE_ORDER_BOOKER, ROLE_DELIVERY_MAN, ROLE_ACCOUNTANT]);

if (!$canView) {
    requirePermission('reports', 'sales');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$mode = sanitize($_GET['mode'] ?? 'dispatch'); // 'dispatch' or 'booker'
$bookerId = (int)($_GET['booker_id'] ?? 0);
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);
$status = sanitize($_GET['status'] ?? 'all');

// Fetch dropdown data for labels
$bookerName = 'All Bookers (Consolidated)';
if ($bookerId > 0) {
    $bStmt = $pdo->prepare("SELECT full_name, employee_code FROM employees WHERE id = ?");
    $bStmt->execute([$bookerId]);
    $b = $bStmt->fetch();
    if ($b) $bookerName = $b['full_name'] . ' (' . $b['employee_code'] . ')';
}

$deliveryName = 'Standard Route Fleet';
if ($deliveryManId > 0) {
    $dStmt = $pdo->prepare("SELECT full_name, employee_code FROM employees WHERE id = ?");
    $dStmt->execute([$deliveryManId]);
    $d = $dStmt->fetch();
    if ($d) $deliveryName = $d['full_name'] . ' (' . $d['employee_code'] . ')';
}

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

$areaName = 'All Market Areas';
if (!empty($selectedAreaIds)) {
    if (count($selectedAreaIds) === 1) {
        $aStmt = $pdo->prepare("SELECT name FROM areas WHERE id = ?");
        $aStmt->execute([reset($selectedAreaIds)]);
        $a = $aStmt->fetch();
        if ($a) $areaName = $a['name'];
    } else {
        $inPl = implode(',', array_fill(0, count($selectedAreaIds), '?'));
        $aStmt = $pdo->prepare("SELECT name FROM areas WHERE id IN ({$inPl})");
        $aStmt->execute($selectedAreaIds);
        $names = $aStmt->fetchAll(PDO::FETCH_COLUMN);
        $areaName = implode(', ', $names) . ' (' . count($selectedAreaIds) . ' Areas)';
    }
}

// Build Query
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

// 1. Consolidated SKU Matrix
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

// 2. Orders breakdown
$ordersSql = "
    SELECT 
        so.id,
        so.order_number,
        so.grand_total,
        so.payment_type,
        c.shop_name,
        c.owner_name,
        a.name as area_name,
        eb.full_name as booker_name,
        (SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = so.id) as item_count
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    LEFT JOIN areas a ON so.area_id = a.id
    LEFT JOIN employees eb ON so.order_booker_id = eb.id
    WHERE {$whereSql}
    ORDER BY a.name ASC, c.shop_name ASC
";
$ordersStmt = $pdo->prepare($ordersSql);
$ordersStmt->execute($params);
$ordersList = $ordersStmt->fetchAll();

$totalOrders = count($ordersList);
$totalUnitsQty = 0;
$totalLoadValue = 0.00;
foreach ($skuItems as $item) {
    $totalUnitsQty += (float)$item['total_qty'];
    $totalLoadValue += (float)$item['total_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $mode === 'booker' ? 'Booker Booking Sheet' : 'Vehicle Load Sheet' ?> - <?= date('d-M-Y', strtotime($date)) ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 6mm 5mm 5mm 5mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 7.8pt;
            line-height: 1.15;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
            border-bottom: 1.5px solid #000;
            padding-bottom: 2px;
        }
        .header-table td {
            vertical-align: top;
            padding: 0;
        }
        .comp-name {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .comp-sub {
            font-size: 7pt;
            color: #333;
        }
        .doc-title-badge {
            display: inline-block;
            background: #000;
            color: #fff;
            font-weight: bold;
            font-size: 8.5pt;
            padding: 2px 7px;
            text-transform: uppercase;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        .meta-table td {
            border: 1px solid #000;
            padding: 2px 5px;
            font-size: 7.5pt;
            background: #fafafa;
        }
        .meta-table td strong {
            color: #000;
        }
        .section-title {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 3px 0 2px 0;
            background: #e5e7eb;
            padding: 1px 4px;
            border: 1px solid #999;
        }
        table.excel-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        table.excel-table th {
            border: 1px solid #000;
            background: #e2e8f0;
            color: #000;
            font-size: 7.2pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 2px 3px;
            text-align: left;
        }
        table.excel-table td {
            border: 1px solid #444;
            padding: 1.5px 3px;
            font-size: 7.5pt;
            vertical-align: middle;
        }
        table.excel-table tr.total-row td {
            border-top: 1.5px solid #000;
            border-bottom: 1.5px solid #000;
            background: #f1f5f9;
            font-weight: bold;
        }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }
        .sign-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }
        .sign-grid td {
            width: 25%;
            text-align: center;
            font-size: 7pt;
            border-top: 1px solid #000;
            padding-top: 2px;
            vertical-align: top;
        }
        .no-print-bar {
            background: #2563eb;
            color: #fff;
            padding: 8px 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9pt;
        }
        .no-print-bar a, .no-print-bar button {
            color: #fff;
            background: rgba(255,255,255,0.2);
            border: 1px solid #fff;
            padding: 4px 10px;
            cursor: pointer;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 8.5pt;
        }
        .no-print-bar button:hover, .no-print-bar a:hover {
            background: #fff;
            color: #2563eb;
        }
        @media print {
            .no-print-bar {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div>
        <strong><?= $mode === 'booker' ? 'Order Booker Daily Booking Sheet' : 'Vehicle Dispatch Load Sheet' ?></strong> Â· <?= date('d-M-Y', strtotime($date)) ?>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick="window.print();">ðŸ–¨ï¸  Print Page (1-Page Excel View)</button>
        <a href="<?= BASE_URL ?>/modules/reports/loadsheet.php?date=<?= $date ?>&mode=<?= $mode ?>&booker_id=<?= $bookerId ?>">â†  Back to Loadsheet</a>
    </div>
</div>

<div style="padding: 2px 4px;">
    <!-- Company & Document Header -->
    <table class="header-table">
        <tr>
            <td>
                <div class="comp-name"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></div>
                <div class="comp-sub"><?= htmlspecialchars($company['address'] ?? 'Plot 45, Small Industrial Estate') ?> Â· Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 1234567') ?></div>
            </td>
            <td class="text-end">
                <div class="doc-title-badge">
                    <?= $mode === 'booker' ? 'ORDER BOOKER DAILY SUMMARY / آرڈر بکر سمری' : 'VEHICLE LOAD SHEET / گاڑی کی لوڈ شیٹ' ?>
                </div>
                <div style="font-size: 8pt; font-weight: bold; margin-top: 2px;">Date: <?= date('d-M-Y (l)', strtotime($date)) ?></div>
            </td>
        </tr>
    </table>

    <!-- Meta Details Box -->
    <table class="meta-table">
        <tr>
            <td style="width: 25%;">Order Booker: <strong><?= htmlspecialchars($bookerName) ?></strong></td>
            <td style="width: 25%;">Route / Fleet: <strong><?= htmlspecialchars($deliveryName) ?></strong></td>
            <td style="width: 25%;">Market Area: <strong><?= htmlspecialchars($areaName) ?></strong></td>
            <td style="width: 25%;" class="text-end">Total Orders: <strong><?= $totalOrders ?></strong> | Value: <strong>Rs. <?= number_format($totalLoadValue, 2) ?></strong></td>
        </tr>
    </table>

    <!-- Table 1: Item Loading Matrix -->
    <div class="section-title">
        1. <?= $mode === 'booker' ? 'Items Booked Summary (آرڈر شدہ آئٹمز کی سمری)' : 'Consolidated Vehicle Loading Matrix (گاڑی میں لوڈ ہونے والا کل مال)' ?>
    </div>
    <table class="excel-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">#</th>
                <th style="width: 70px;">SKU</th>
                <th>Product Description & Packaging</th>
                <th style="width: 80px;">Category</th>
                <th class="text-center" style="width: 50px;">Unit</th>
                <th class="text-end" style="width: 65px;">Booked Qty</th>
                <th class="text-end" style="width: 75px;">Rate (TP)</th>
                <th class="text-end" style="width: 85px;">Total Amount</th>
                <th class="text-center" style="width: 55px;">Loaded âœ“</th>
                <th class="text-center" style="width: 55px;">Delivered</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($skuItems)): ?>
                <tr>
                    <td colspan="10" class="text-center" style="padding: 10px; color: #666;">No booked items found for selected date and filters.</td>
                </tr>
            <?php else: ?>
                <?php $sr = 1; foreach ($skuItems as $it): ?>
                    <tr>
                        <td class="text-center"><?= $sr++ ?></td>
                        <td style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($it['sku']) ?></td>
                        <td><strong><?= htmlspecialchars($it['product_name']) ?></strong></td>
                        <td><?= htmlspecialchars($it['category_name'] ?? 'General') ?></td>
                        <td class="text-center"><?= htmlspecialchars($it['unit_code'] ?? $it['unit_name']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((float)$it['total_qty'], 2) ?></td>
                        <td class="text-end"><?= number_format((float)$it['avg_price'], 2) ?></td>
                        <td class="text-end fw-bold">Rs. <?= number_format((float)$it['total_value'], 2) ?></td>
                        <td class="text-center"></td>
                        <td class="text-center"></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" class="text-end">TOTAL SUMMARY:</td>
                    <td class="text-end"><?= number_format($totalUnitsQty, 2) ?></td>
                    <td class="text-end">-</td>
                    <td class="text-end">Rs. <?= number_format($totalLoadValue, 2) ?></td>
                    <td class="text-center">Tally</td>
                    <td class="text-center">Tally</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Table 2: Shop-by-Shop Orders List -->
    <div class="section-title">
        2. Shop-by-Shop Delivery Drop List (دکان وار ڈیلیوری اور بلز کی لسٹ)
    </div>
    <table class="excel-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">#</th>
                <th style="width: 85px;">Order #</th>
                <th>Retail Shop & Owner Name</th>
                <th style="width: 100px;">Market / Area</th>
                <th style="width: 90px;">Booker</th>
                <th class="text-center" style="width: 40px;">Lines</th>
                <th class="text-center" style="width: 45px;">Terms</th>
                <th class="text-end" style="width: 80px;">Bill Value</th>
                <th class="text-center" style="width: 65px;">Cash Recv.</th>
                <th class="text-center" style="width: 70px;">Receiver Sign</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($ordersList)): ?>
                <tr>
                    <td colspan="10" class="text-center" style="padding: 6px; color: #666;">No shop orders scheduled.</td>
                </tr>
            <?php else: ?>
                <?php $osr = 1; foreach ($ordersList as $ord): ?>
                    <tr>
                        <td class="text-center"><?= $osr++ ?></td>
                        <td style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($ord['order_number']) ?></td>
                        <td><strong><?= htmlspecialchars($ord['shop_name']) ?></strong> <span style="color:#555;">(<?= htmlspecialchars($ord['owner_name']) ?>)</span></td>
                        <td><?= htmlspecialchars($ord['area_name'] ?? 'Market') ?></td>
                        <td><?= htmlspecialchars($ord['booker_name'] ?? 'Direct') ?></td>
                        <td class="text-center"><?= $ord['item_count'] ?></td>
                        <td class="text-center font-bold"><?= strtoupper($ord['payment_type']) ?></td>
                        <td class="text-end fw-bold">Rs. <?= number_format((float)$ord['grand_total'], 2) ?></td>
                        <td class="text-center"></td>
                        <td class="text-center"></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="7" class="text-end">TOTAL DELIVERABLE BILL VALUE:</td>
                    <td class="text-end">Rs. <?= number_format($totalLoadValue, 2) ?></td>
                    <td colspan="2" class="text-center" style="font-size: 7pt; color: #444;">Physical Cash & Signatures</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 4 Neat Signatures Block -->
    <table class="sign-grid">
        <tr>
            <td>
                <strong>Storekeeper / Dispatcher</strong><br>
                <span>Stock Released</span>
            </td>
            <td>
                <strong>Warehouse Loader</strong><br>
                <span>Physical Tally Verified</span>
            </td>
            <td>
                <strong>Delivery Driver / Booker</strong><br>
                <span>Received in Sound Condition</span>
            </td>
            <td>
                <strong>Security Officer</strong><br>
                <span>Outward Gate Clearance</span>
            </td>
        </tr>
    </table>
</div>

<script>
// Auto print if ?autoprint=1
if (window.location.search.indexOf('autoprint=1') !== -1) {
    window.onload = function() { window.print(); };
}
</script>

</body>
</html>
