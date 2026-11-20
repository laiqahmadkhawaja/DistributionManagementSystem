<?php
/**
 * Van Total Stock Issue Summary (Outward Gatepass Load Sheet)
 * Modeled after FMCG Distributor Traditional Standard (Ref Image 3)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canView = hasPermission('warehouse', 'view')
    || hasPermission('delivery', 'view')
    || hasPermission('reports', 'sales')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STOREKEEPER, ROLE_ORDER_BOOKER, ROLE_DELIVERY_MAN, ROLE_ACCOUNTANT]);

if (!$canView) {
    requirePermission('warehouse', 'view');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$bookerId = (int)($_GET['booker_id'] ?? 0);
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);
$warehouseId = (int)($_GET['warehouse_id'] ?? 1);

// Dropdown filter sources
$bookers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$deliveryStaff = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$warehouses = $pdo->query("SELECT id, name, code FROM warehouses WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Build filter parameters
$whereClauses = ["so.order_date = ?", "so.status NOT IN ('draft', 'rejected', 'cancelled')"];
$params = [$date];

if ($bookerId > 0) {
    $whereClauses[] = "so.order_booker_id = ?";
    $params[] = $bookerId;
}
if ($areaId > 0) {
    $whereClauses[] = "so.area_id = ?";
    $params[] = $areaId;
}
if ($deliveryManId > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM delivery_assignments da WHERE da.sales_order_id = so.id AND da.delivery_man_id = ?)";
    $params[] = $deliveryManId;
}

$whereSql = implode(' AND ', $whereClauses);

// Query consolidated items: when no specific area is selected, merge identical items across all bookers & routes
if ($areaId > 0) {
    $selectArea = "a.name as area_name, COALESCE(b.name, a.name) as company_name,";
    $groupBy = "a.id, a.name, b.name, p.id, p.name, p.code, u.name, u.code, uc.multiplier";
} else {
    $selectArea = "'All Routes' as area_name, COALESCE(b.name, 'General') as company_name,";
    $groupBy = "b.name, p.id, p.name, p.code, u.name, u.code, uc.multiplier";
}

$sql = "
    SELECT 
        {$selectArea}
        p.id as product_id,
        p.name as product_name,
        p.code as product_code,
        COALESCE(u.name, 'Piece') as unit_name,
        COALESCE(u.code, 'pc') as unit_code,
        COALESCE(uc.multiplier, 1) as carton_multiplier,
        (SUM(soi.line_total) / NULLIF(SUM(soi.quantity), 0)) as avg_rate,
        SUM(soi.quantity) as total_qty,
        SUM(soi.line_total) as total_amount
    FROM sales_order_items soi
    JOIN sales_orders so ON soi.sales_order_id = so.id
    JOIN products p ON soi.product_id = p.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN areas a ON so.area_id = a.id
    LEFT JOIN units u ON soi.unit_id = u.id
    LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
    WHERE {$whereSql}
    GROUP BY {$groupBy}
    ORDER BY company_name ASC, p.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Booker names display
$selectedBookerName = 'All Bookers';
if ($bookerId > 0) {
    foreach ($bookers as $b) {
        if ($b['id'] == $bookerId) {
            $selectedBookerName = $b['full_name'];
            break;
        }
    }
}

// Delivery staff display
$selectedRiderName = 'All Delivery Vans';
if ($deliveryManId > 0) {
    foreach ($deliveryStaff as $d) {
        if ($d['id'] == $deliveryManId) {
            $selectedRiderName = $d['full_name'];
            break;
        }
    }
}

// Calculate Form #
$formNumber = 'SIS-' . date('Ymd', strtotime($date)) . ($bookerId ? "-B{$bookerId}" : '') . ($deliveryManId ? "-D{$deliveryManId}" : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Total_Stock_Issue_<?= $formNumber ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
        body { background: #f8fafc; padding: 15px; font-size: 10pt; color: #000; }

        .toolbar {
            max-width: 1050px;
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
        .toolbar select, .toolbar input {
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
        .btn-success { background: #16a34a; color: #fff; }
        .btn-secondary { background: #64748b; color: #fff; }

        .sheet-container {
            width: 100%;
            max-width: 1050px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border: 1px solid #000;
        }

        .sheet-header {
            text-align: center;
            margin-bottom: 12px;
        }
        .sheet-title {
            font-size: 14pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .meta-strip {
            display: table;
            width: 100%;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        .meta-cell {
            display: table-cell;
            vertical-align: middle;
        }
        .meta-cell.left { text-align: left; width: 25%; }
        .meta-cell.center { text-align: center; width: 50%; }
        .meta-cell.right { text-align: right; width: 25%; }

        table.stock-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        table.stock-table th, table.stock-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            vertical-align: middle;
        }
        table.stock-table thead tr.head-yellow th {
            background-color: #ffff00 !important;
            color: #000;
            font-weight: bold;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        table.stock-table th.sub-th {
            background-color: #fffedb !important;
            font-size: 8pt;
            font-weight: bold;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }

        .row-total td {
            font-weight: 900;
            background-color: #fffde7 !important;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .signatures {
            margin-top: 30px;
            display: table;
            width: 100%;
        }
        .sig-block {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            vertical-align: bottom;
            padding: 0 15px;
        }
        .sig-line {
            border-top: 1px solid #000;
            padding-top: 4px;
            font-size: 8.5pt;
            font-weight: bold;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sheet-container { border: none; padding: 0; max-width: 100%; }
            table.stock-table thead tr.head-yellow th {
                background-color: #ffff00 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .row-total td {
                background-color: #ffff00 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 8mm 8mm 10mm 8mm;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Printing Control Toolbar -->
    <div class="toolbar">
        <form method="GET" action="">
            <label style="font-weight: bold; font-size: 9pt;">Date:</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">

            <select name="booker_id">
                <option value="0">-- All Order Bookers --</option>
                <?php foreach ($bookers as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bookerId == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="delivery_man_id">
                <option value="0">-- All Delivery Vans / Riders --</option>
                <?php foreach ($deliveryStaff as $ds): ?>
                    <option value="<?= $ds['id'] ?>" <?= $deliveryManId == $ds['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ds['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="area_id">
                <option value="0">-- All Areas / Routes --</option>
                <?php foreach ($areas as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $areaId == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>

        <div style="display: flex; gap: 8px;">
            <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-secondary">&larr; Warehouse</a>
            <button type="button" class="btn btn-primary" onclick="window.print();">&#128438; Print Load Form (A4)</button>
        </div>
    </div>

    <!-- Printable Sheet (Ref Image 3 Format) -->
    <div class="sheet-container">
        <div class="sheet-header">
            <h1 class="sheet-title">TOTAL STOCK ISSUE SUMMARY</h1>
        </div>

        <div class="meta-strip">
            <div class="meta-cell left">
                <strong>Form #:</strong> <?= htmlspecialchars($formNumber) ?>
            </div>
            <div class="meta-cell center">
                <strong>Booker(s):</strong> <?= htmlspecialchars($selectedBookerName) ?>
                <?php if ($deliveryManId > 0): ?>
                    &nbsp;|&nbsp; <strong>Van:</strong> <?= htmlspecialchars($selectedRiderName) ?>
                <?php endif; ?>
            </div>
            <div class="meta-cell right">
                <strong>Date:</strong> <?= date('d.m.y', strtotime($date)) ?>
            </div>
        </div>

        <table class="stock-table">
            <thead>
                <tr class="head-yellow">
                    <th rowspan="2" style="width: 14%;">Company</th>
                    <th rowspan="2" style="width: 32%;">Product</th>
                    <th rowspan="2" style="width: 7%;">Ctn Qty</th>
                    <th rowspan="2" style="width: 7%;">Pcs Qty</th>
                    <th rowspan="2" style="width: 8%;">Rate</th>
                    <th rowspan="2" style="width: 10%;">Total Amount</th>
                    <th colspan="2" style="width: 14%;">Return</th>
                    <th rowspan="2" style="width: 8%;">Credit</th>
                </tr>
                <tr class="head-yellow">
                    <th class="sub-th" style="width: 7%;">Return</th>
                    <th class="sub-th" style="width: 7%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandCtn = 0;
                $grandPcs = 0;
                $grandTotal = 0.00;

                if (empty($items)):
                ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 20px; color: #64748b;">
                            No issued stock found for the selected date and filters.
                        </td>
                    </tr>
                <?php 
                else:
                    foreach ($items as $it): 
                        $mult = (float)$it['carton_multiplier'];
                        $totQty = (float)$it['total_qty'];
                        $isCtn = (strtolower($it['unit_code']) === 'ctn');

                        if ($isCtn) {
                            $ctnVal = $totQty;
                            $pcsVal = 0;
                            $grandCtn += $ctnVal;
                        } elseif ($mult > 1) {
                            $ctnVal = floor($totQty / $mult);
                            $pcsVal = (int)fmod($totQty, $mult);
                            $grandCtn += $ctnVal;
                            $grandPcs += $pcsVal;
                        } else {
                            $ctnVal = 0;
                            $pcsVal = (int)$totQty;
                            $grandPcs += $pcsVal;
                        }

                        $grandTotal += (float)$it['total_amount'];

                        // Format product packing name e.g. "Nimko Daal Moth (1*36)"
                        $prodDisplay = $it['product_name'];
                        if ($mult > 1 && !strpos($prodDisplay, '*')) {
                            $prodDisplay .= " (1*" . (int)$mult . ")";
                        }
                ?>
                    <tr>
                        <td class="text-left"><?= htmlspecialchars($it['company_name'] ?: $it['area_name']) ?></td>
                        <td class="text-left"><?= htmlspecialchars($prodDisplay) ?></td>
                        <td class="text-center font-bold"><?= $ctnVal > 0 ? $ctnVal : '' ?></td>
                        <td class="text-center"><?= $pcsVal > 0 ? $pcsVal : '' ?></td>
                        <td class="text-right"><?= number_format((float)$it['avg_rate'], 0) ?></td>
                        <td class="text-right" style="font-weight: 600;"><?= number_format((float)$it['total_amount'], 0) ?></td>
                        <!-- Blank cells for handwritten Return Qty, Return Amount, and Credit -->
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td colspan="2" class="text-center">Total</td>
                    <td class="text-center"><?= $grandCtn > 0 ? $grandCtn : '' ?></td>
                    <td class="text-center"><?= $grandPcs > 0 ? $grandPcs : '' ?></td>
                    <td></td>
                    <td class="text-right" style="font-size: 10pt;"><?= number_format($grandTotal, 0) ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <!-- Warehouse Verification & Custody Transfer Signatures -->
        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line">Storekeeper (Warehouse Outward)</div>
            </div>
            <div class="sig-block">
                <div class="sig-line">Delivery Van Driver / Receiver</div>
            </div>
            <div class="sig-block">
                <div class="sig-line">Order Booker / Sales Incharge</div>
            </div>
        </div>
    </div>

</body>
</html>
