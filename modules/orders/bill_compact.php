<?php
/**
 * Ultra-Compact High-Density Commercial Sales Bill (Half-Sheet / 2-per-A4)
 * Modeled after Muzzam Enterprises FMCG Standard
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canViewBill = hasPermission('orders', 'view') 
    || hasPermission('delivery', 'view') 
    || hasPermission('warehouse', 'view') 
    || hasPermission('accounting', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ORDER_BOOKER, ROLE_SALESMAN, ROLE_DELIVERY_MAN, ROLE_STOREKEEPER, ROLE_ACCOUNTANT]);

if (!$canViewBill) {
    requirePermission('orders', 'view');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$orderId = (int)($_GET['id'] ?? ($_GET['order_id'] ?? 0));

if (!$orderId) {
    setFlashMessage('error', 'Invalid Bill request. Order identifier missing.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

$orderStmt = $pdo->prepare("
    SELECT so.*,
           c.shop_name, c.owner_name, c.phone as cust_phone, c.whatsapp as cust_whatsapp, 
           c.address as cust_address, c.code as cust_code, c.current_balance as cust_balance,
           a.name as area_name,
           e.full_name as booker_name, e.phone as booker_phone
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    LEFT JOIN `employees` e ON so.order_booker_id = e.id
    WHERE so.id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    setFlashMessage('error', 'Order not found.');
    redirect(BASE_URL . '/modules/orders/index.php');
}

$itemsStmt = $pdo->prepare("
    SELECT soi.*, p.name as product_name, p.code as product_code,
           u.name as unit_name, u.code as unit_code,
           COALESCE(uc.multiplier, 1) as carton_multiplier
    FROM `sales_order_items` soi
    JOIN `products` p ON soi.product_id = p.id
    JOIN `units` u ON soi.unit_id = u.id
    LEFT JOIN `unit_conversions` uc ON uc.product_id = p.id AND uc.from_unit_id = 2
    WHERE soi.sales_order_id = ?
    ORDER BY soi.id ASC
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

$billTerms = getSetting($pdo, 'bill_terms_conditions', 'Goods once sold are non-returnable without prior distributor authorization.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bill_<?= htmlspecialchars($order['order_number']) ?>_<?= htmlspecialchars($order['shop_name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
        body { background: #f1f5f9; padding: 20px; font-size: 11pt; color: #000; }
        .no-print { display: flex; justify-content: space-between; align-items: center; max-width: 148mm; margin: 0 auto 12px; }
        .btn-print { background: #2563eb; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; font-weight: bold; cursor: pointer; text-decoration: none; font-size: 9pt; }
        .btn-back { background: #64748b; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 9pt; }
        
        .compact-bill-container {
            width: 148mm;
            min-height: 130mm;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .bill-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 4px; margin-bottom: 6px; }
        .comp-title { font-size: 13pt; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
        .comp-contact { font-size: 9pt; font-weight: bold; margin-top: 1px; }

        .meta-grid { display: table; width: 100%; margin-bottom: 6px; font-size: 9.5pt; }
        .meta-row { display: table-row; }
        .meta-col-left { display: table-cell; width: 60%; vertical-align: top; line-height: 1.3; }
        .meta-col-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; line-height: 1.3; }

        .bill-table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 6px; }
        .bill-table th, .bill-table td { border: 1px solid #000; padding: 3px 5px; }
        .bill-table th { background-color: #f1f5f9; text-align: center; font-weight: bold; }
        .col-product { text-align: left; }
        .col-carton { width: 45px; text-align: center; }
        .col-qty { width: 45px; text-align: center; }
        .col-price { width: 65px; text-align: right; }
        .col-total { width: 75px; text-align: right; font-weight: bold; }

        .total-row td { background-color: #ffff00; font-weight: bold; font-size: 10pt; }
        
        .bill-footer { margin-top: 8px; font-size: 8pt; display: flex; justify-content: space-between; align-items: flex-end; }
        .sign-box { border-top: 1px solid #000; width: 120px; text-align: center; padding-top: 2px; font-weight: bold; font-size: 8pt; }

        @media print {
            body { background: transparent; padding: 0; }
            .no-print { display: none !important; }
            .compact-bill-container { border: 1px solid #000; box-shadow: none; margin: 0; width: 100%; }
            @page { size: A4 portrait; margin: 6mm; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="javascript:history.back()" class="btn-back">&larr; Back</a>
    <div style="font-weight: bold; font-size: 10pt;">Compact Sales Bill (Muzzam Style)</div>
    <button onclick="window.print()" class="btn-print">&#128438; Print Bill</button>
</div>

<div class="compact-bill-container">
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
                <strong>BILL NO.</strong> <?= htmlspecialchars($order['order_number']) ?><br>
                <strong>Name:</strong> <?= htmlspecialchars($order['shop_name']) ?><br>
                <strong>Address:</strong> <?= htmlspecialchars($order['cust_address'] ?: $order['area_name']) ?>
            </div>
            <div class="meta-col-right">
                <strong>Booker:</strong> <?= htmlspecialchars($order['booker_name'] ?? 'Direct') ?><br>
                <strong>Dt:</strong> <?= date('d.m.y', strtotime($order['order_date'])) ?>
            </div>
        </div>
    </div>

    <!-- Items Table (Matching Muzzam Enterprises Exact Columns) -->
    <table class="bill-table">
        <thead>
            <tr>
                <th class="col-product" style="width: 34%;">Product</th>
                <th class="col-carton" style="width: 10%;">Carton</th>
                <th style="width: 12%;">Con. Pkng</th>
                <th style="width: 12%;">Box / Packets</th>
                <th class="col-qty" style="width: 8%;">Qty</th>
                <th class="col-price" style="width: 11%;">Unit Prize</th>
                <th class="col-total" style="width: 13%;">Total</th>
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

                $conPkg = $multiplier > 1 ? '1*' . (int)$multiplier : '';
                $boxType = ($ctnVal > 0) ? 'M' : '';
            ?>
            <tr>
                <td class="col-product"><?= htmlspecialchars($it['product_name']) ?></td>
                <td class="col-carton"><?= $ctnVal !== '' && $ctnVal > 0 ? $ctnVal : '' ?></td>
                <td style="text-align: center;"><?= $conPkg ?></td>
                <td style="text-align: center;"><?= $boxType ?></td>
                <td class="col-qty"><?= $looseVal !== '' ? $looseVal : '' ?></td>
                <td class="col-price"><?= number_format((float)$it['unit_price'], 0) ?></td>
                <td class="col-total"><?= number_format((float)($it['line_total'] ?? ($it['quantity'] * $it['unit_price'])), 0) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td style="text-align: right; font-weight: 900;">Total</td>
                <td class="col-carton"><?= $totCtn > 0 ? $totCtn : '' ?></td>
                <td></td>
                <td></td>
                <td class="col-qty"><?= $totLoose > 0 ? $totLoose : '' ?></td>
                <td class="col-price">-</td>
                <td class="col-total"><?= number_format((float)$order['grand_total'], 0) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="bill-footer">
        <div>
            <div style="font-size: 7.5pt; color: #555;"><?= htmlspecialchars($billTerms) ?></div>
        </div>
        <div style="display: flex; gap: 20px;">
            <div class="sign-box">Customer Signature</div>
            <div class="sign-box">Delivery / Salesman</div>
        </div>
    </div>
</div>

</body>
</html>
