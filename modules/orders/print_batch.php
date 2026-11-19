<?php
/**
 * Multi-Bill Batch Print Generator (Exact 2-Bills per A4 Portrait Sheet)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canView = hasPermission('orders', 'view') 
    || hasPermission('delivery', 'view') 
    || hasPermission('warehouse', 'view') 
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_STOREKEEPER, ROLE_ACCOUNTANT]);

if (!$canView) {
    requirePermission('orders', 'view');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$areaId = (int)($_GET['area_id'] ?? 0);
$bookerId = (int)($_GET['booker_id'] ?? 0);
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);

// Fetch Filter Dropdowns
$areas = $pdo->query("SELECT id, name FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bookers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE designation IN ('Order Booker', 'Salesman') AND status = 'active'")->fetchAll();
$drivers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE designation = 'Delivery Man' AND status = 'active'")->fetchAll();

// Query Orders Matching Filter
$where = ["so.order_date = :odate", "so.status NOT IN ('draft', 'cancelled', 'rejected')"];
$params = [':odate' => $date];

if ($areaId > 0) {
    $where[] = "so.area_id = :aid";
    $params[':aid'] = $areaId;
}
if ($bookerId > 0) {
    $where[] = "so.order_booker_id = :bid";
    $params[':bid'] = $bookerId;
}
if ($deliveryManId > 0) {
    $where[] = "so.id IN (SELECT sales_order_id FROM delivery_assignments WHERE delivery_man_id = :dmid)";
    $params[':dmid'] = $deliveryManId;
}

$whereSql = implode(' AND ', $where);
$ordersStmt = $pdo->prepare("
    SELECT so.*,
           c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           a.name as area_name,
           e.full_name as booker_name
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    LEFT JOIN `employees` e ON so.order_booker_id = e.id
    WHERE {$whereSql}
    ORDER BY so.id ASC
");
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll();

// Fetch All Line Items for these orders
$orderIds = array_column($orders, 'id');
$itemsByOrder = [];

if (!empty($orderIds)) {
    $inPl = implode(',', array_fill(0, count($orderIds), '?'));
    $itStmt = $pdo->prepare("
        SELECT soi.*, p.name as product_name, p.code as product_code,
               u.name as unit_name, u.code as unit_code,
               COALESCE(uc.multiplier, 1) as carton_multiplier
        FROM `sales_order_items` soi
        JOIN `products` p ON soi.product_id = p.id
        JOIN `units` u ON soi.unit_id = u.id
        LEFT JOIN `unit_conversions` uc ON uc.product_id = p.id AND uc.from_unit_id = 2
        WHERE soi.sales_order_id IN ({$inPl})
        ORDER BY soi.id ASC
    ");
    $itStmt->execute($orderIds);
    while ($row = $itStmt->fetch()) {
        $itemsByOrder[$row['sales_order_id']][] = $row;
    }
}

$billTerms = getSetting($pdo, 'bill_terms_conditions', 'Goods once sold are non-returnable without prior distributor authorization.');
$billsPerA4 = (int)getSetting($pdo, 'batch_bills_per_a4', '2');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Batch_Bills_<?= htmlspecialchars($date) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
        body { background: #f8fafc; padding: 15px; font-size: 10pt; color: #000; }
        
        .no-print-bar {
            max-width: 190mm;
            margin: 0 auto 15px;
            background: #fff;
            padding: 12px 16px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .filter-form select, .filter-form input { padding: 5px 8px; font-size: 8.5pt; border: 1px solid #cbd5e1; border-radius: 4px; }
        .btn-action { padding: 6px 12px; font-size: 8.5pt; font-weight: bold; border-radius: 4px; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-dark { background: #0f172a; color: #fff; }

        .sheet-wrapper { max-width: 190mm; margin: 0 auto; }

        .batch-bill-card {
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: 8px 12px;
            margin-bottom: 8mm;
            min-height: 128mm;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .bill-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 3px; margin-bottom: 5px; }
        .comp-title { font-size: 12pt; font-weight: 900; text-transform: uppercase; }
        .comp-contact { font-size: 8.5pt; font-weight: bold; }

        .meta-grid { display: table; width: 100%; margin-bottom: 4px; font-size: 9pt; }
        .meta-row { display: table-row; }
        .meta-col-left { display: table-cell; width: 60%; vertical-align: top; line-height: 1.25; }
        .meta-col-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; line-height: 1.25; }

        .bill-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 5px; }
        .bill-table th, .bill-table td { border: 1px solid #000; padding: 2.5px 5px; }
        .bill-table th { background-color: #f1f5f9; text-align: center; font-weight: bold; }
        .col-product { text-align: left; }
        .col-carton { width: 40px; text-align: center; }
        .col-qty { width: 40px; text-align: center; }
        .col-price { width: 60px; text-align: right; }
        .col-total { width: 70px; text-align: right; font-weight: bold; }

        .total-row td { background-color: #ffff00; font-weight: bold; font-size: 9.5pt; }

        .bill-footer { font-size: 7.5pt; display: flex; justify-content: space-between; align-items: flex-end; margin-top: 6px; }
        .sign-box { border-top: 1px solid #000; width: 110px; text-align: center; padding-top: 2px; font-weight: bold; font-size: 7.5pt; }

        @media print {
            body { background: transparent; padding: 0; }
            .no-print-bar { display: none !important; }
            .sheet-wrapper { max-width: 100%; margin: 0; }
            
            @page {
                size: A4 portrait;
                margin: 8mm 6mm;
            }

            .batch-bill-card {
                border: 1px solid #000;
                box-shadow: none;
                margin: 0 0 8mm 0;
                height: 136mm;
                max-height: 136mm;
                overflow: hidden;
                page-break-inside: avoid;
            }

            /* Exactly 2 Bills per A4 Portrait Sheet */
            <?php if ($billsPerA4 === 2): ?>
            .batch-bill-card:nth-child(2n) {
                margin-bottom: 0;
                page-break-after: always;
            }
            <?php elseif ($billsPerA4 === 1): ?>
            .batch-bill-card {
                height: 275mm;
                max-height: 275mm;
                page-break-after: always;
            }
            <?php endif; ?>
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div>
        <h3 style="font-size: 11pt; font-weight: bold; margin-bottom: 2px;">Batch Print Sales Bills (2 per A4 Sheet)</h3>
        <small style="color: #64748b;"><?= count($orders) ?> Customer Bills queued for printing</small>
    </div>
    
    <form method="GET" class="filter-form">
        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
        <select name="area_id">
            <option value="">-- All Areas / Routes --</option>
            <?php foreach ($areas as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $areaId == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="booker_id">
            <option value="">-- All Bookers --</option>
            <?php foreach ($bookers as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $bookerId == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-action btn-dark">Filter</button>
    </form>

    <div style="display: flex; gap: 8px;">
        <button onclick="window.print()" class="btn-action btn-primary">&#128438; Print All Bills</button>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn-action btn-dark">&larr; Orders Hub</a>
    </div>
</div>

<div class="sheet-wrapper">
    <?php if (empty($orders)): ?>
        <div style="background: #fff; padding: 30px; text-align: center; border-radius: 8px; color: #64748b;">
            No sales orders found matching selected date and filters.
        </div>
    <?php else: ?>
        <?php foreach ($orders as $idx => $ord): 
            $items = $itemsByOrder[$ord['id']] ?? [];
        ?>
        <div class="batch-bill-card">
            <!-- Header -->
            <div class="bill-header">
                <div class="comp-title"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></div>
                <div class="comp-contact">
                    <?= htmlspecialchars($company['phone'] ?? '+92 300 0673276') ?> &bull; <?= htmlspecialchars($company['address'] ?? 'Jandiala Bhagwala G.T. Road Gujranwala') ?>
                </div>
            </div>

            <!-- Meta Info -->
            <div class="meta-grid">
                <div class="meta-row">
                    <div class="meta-col-left">
                        <strong>BILL NO.</strong> <?= htmlspecialchars($ord['order_number']) ?><br>
                        <strong>Name:</strong> <?= htmlspecialchars($ord['shop_name']) ?><br>
                        <strong>Address:</strong> <?= htmlspecialchars($ord['cust_address'] ?: $ord['area_name']) ?>
                    </div>
                    <div class="meta-col-right">
                        <strong>Booker:</strong> <?= htmlspecialchars($ord['booker_name'] ?? 'Direct') ?><br>
                        <strong>Dt:</strong> <?= date('d.m.y', strtotime($ord['order_date'])) ?>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <table class="bill-table">
                <thead>
                    <tr>
                        <th class="col-product">Product</th>
                        <th class="col-carton">Carton</th>
                        <th class="col-qty">Qty</th>
                        <th class="col-price">Unit Price</th>
                        <th class="col-total">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totCtn = 0;
                    $totLoose = 0;
                    foreach ($items as $it): 
                        $multiplier = (float)$it['carton_multiplier'];
                        $qty = (float)$it['quantity'];
                        $isCarton = (strtolower($it['unit_code']) === 'ctn' || strtolower($it['unit_name']) === 'carton');
                        
                        if ($isCarton) {
                            $ctnVal = $qty;
                            $looseVal = '';
                            $totCtn += $ctnVal;
                        } elseif ($multiplier > 1) {
                            $ctnVal = floor($qty / $multiplier);
                            $rem = fmod($qty, $multiplier);
                            $looseVal = $rem > 0 ? (int)$rem : '';
                            $totCtn += $ctnVal;
                            if ($rem > 0) $totLoose += $rem;
                        } else {
                            $ctnVal = '';
                            $looseVal = $qty;
                            $totLoose += $qty;
                        }
                    ?>
                    <tr>
                        <td class="col-product"><?= htmlspecialchars($it['product_name']) ?></td>
                        <td class="col-carton"><?= $ctnVal !== '' && $ctnVal > 0 ? $ctnVal : '' ?></td>
                        <td class="col-qty"><?= $looseVal !== '' ? $looseVal : '' ?></td>
                        <td class="col-price"><?= number_format((float)$it['unit_price'], 0) ?></td>
                        <td class="col-total"><?= number_format((float)($it['line_total'] ?? ($it['quantity'] * $it['unit_price'])), 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: 900;">Total</td>
                        <td class="col-carton"><?= $totCtn > 0 ? $totCtn : '' ?></td>
                        <td class="col-qty"><?= $totLoose > 0 ? $totLoose : '' ?></td>
                        <td class="col-price">-</td>
                        <td class="col-total"><?= number_format((float)$ord['grand_total'], 0) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="bill-footer">
                <div>
                    <div style="font-size: 7pt; color: #555;"><?= htmlspecialchars($billTerms) ?></div>
                </div>
                <div style="display: flex; gap: 15px;">
                    <div class="sign-box">Customer Signature</div>
                    <div class="sign-box">Delivery Incharge</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
