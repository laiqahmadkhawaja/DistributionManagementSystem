<?php
/**
 * Daily Delivery Run Sheet (DAILY SALE RECEIVED + CREDIT)
 * Modeled after FMCG Distributor Traditional Standard (Ref Image 2)
 * Carried by Delivery Rider / Van Driver for on-the-road cash & credit logging.
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canView = hasPermission('delivery', 'view')
    || hasPermission('reports', 'sales')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_DELIVERY_MAN, ROLE_STOREKEEPER, ROLE_ACCOUNTANT, ROLE_ORDER_BOOKER]);

if (!$canView) {
    requirePermission('delivery', 'view');
}

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$deliveryManId = (int)($_GET['delivery_man_id'] ?? 0);
$areaId = (int)($_GET['area_id'] ?? 0);
$bookerId = (int)($_GET['booker_id'] ?? 0);

// Filter sources
$deliveryStaff = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('delivery_man', 'delivery_manager', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bookers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Build query
$whereClauses = ["so.order_date = ?", "so.status NOT IN ('draft', 'rejected', 'cancelled')"];
$params = [$date];

if ($deliveryManId > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM delivery_assignments da WHERE da.sales_order_id = so.id AND da.delivery_man_id = ?)";
    $params[] = $deliveryManId;
}
if ($areaId > 0) {
    $whereClauses[] = "so.area_id = ?";
    $params[] = $areaId;
}
if ($bookerId > 0) {
    $whereClauses[] = "so.order_booker_id = ?";
    $params[] = $bookerId;
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT 
        so.id as sales_order_id,
        so.order_number,
        so.order_date,
        so.grand_total as total_amount,
        so.status as order_status,
        c.id as customer_id,
        c.shop_name,
        c.owner_name,
        c.phone as cust_phone,
        a.name as area_name,
        e.full_name as booker_name,
        (SELECT da.delivery_man_id FROM delivery_assignments da WHERE da.sales_order_id = so.id LIMIT 1) as assigned_rider_id,
        (SELECT emp.full_name FROM delivery_assignments da JOIN employees emp ON da.delivery_man_id = emp.id WHERE da.sales_order_id = so.id LIMIT 1) as assigned_rider_name
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    JOIN areas a ON so.area_id = a.id
    LEFT JOIN employees e ON so.order_booker_id = e.id
    WHERE {$whereSql}
    ORDER BY a.name ASC, c.shop_name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Driver name display
$selectedRiderName = 'All Delivery Staff';
if ($deliveryManId > 0) {
    foreach ($deliveryStaff as $ds) {
        if ($ds['id'] == $deliveryManId) {
            $selectedRiderName = $ds['full_name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily_Sale_Received_Credit_<?= date('Ymd', strtotime($date)) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
        body { background: #f8fafc; padding: 15px; font-size: 10pt; color: #000; }

        .toolbar {
            max-width: 960px;
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
        .btn-warning { background: #f59e0b; color: #000; font-weight: bold; }
        .btn-secondary { background: #64748b; color: #fff; }

        .sheet-container {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border: 1px solid #000;
        }

        .header-title-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #000;
        }
        .sheet-title {
            font-size: 14pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .date-display {
            font-size: 12pt;
            font-weight: 900;
        }

        .sub-meta {
            margin-bottom: 10px;
            font-size: 9.5pt;
            display: flex;
            justify-content: space-between;
        }

        table.run-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
        }
        table.run-table th, table.run-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: middle;
        }
        table.run-table thead tr th {
            background-color: #ffff00 !important;
            color: #000;
            font-weight: bold;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }

        .row-total td {
            font-weight: 900;
            background-color: #f59e0b !important;
            color: #000;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .signatures {
            margin-top: 35px;
            display: table;
            width: 100%;
        }
        .sig-block {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: bottom;
            padding: 0 40px;
        }
        .sig-line {
            border-top: 1px solid #000;
            padding-top: 5px;
            font-size: 9pt;
            font-weight: bold;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sheet-container { border: none; padding: 0; max-width: 100%; }
            table.run-table thead tr th {
                background-color: #ffff00 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .row-total td {
                background-color: #f59e0b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 10mm 10mm 12mm 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Printing Controls -->
    <div class="toolbar">
        <form method="GET" action="">
            <label style="font-weight: bold; font-size: 9pt;">Date:</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">

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
            <a href="<?= BASE_URL ?>/modules/delivery/sheet_reconcile.php?date=<?= $date ?>&delivery_man_id=<?= $deliveryManId ?>" class="btn btn-warning">
                &#9881; Reconcile Sheet
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                &#128438; Print Run Sheet (A4)
            </button>
        </div>
    </div>

    <!-- Printable Daily Run Sheet (Ref Image 2 Format) -->
    <div class="sheet-container">
        <div class="header-title-bar">
            <h1 class="sheet-title">DAILY SALE RECIVED + CREDIT</h1>
            <div class="date-display"><?= date('d.m.y', strtotime($date)) ?></div>
        </div>

        <div class="sub-meta">
            <div>
                <strong>Van / Delivery Man:</strong> <?= htmlspecialchars($selectedRiderName) ?>
            </div>
            <div>
                <strong>Company:</strong> <?= htmlspecialchars($company['company_name'] ?? 'Falcon FMCG Distribution') ?>
            </div>
        </div>

        <table class="run-table">
            <thead>
                <tr>
                    <th style="width: 5%;">Sr.</th>
                    <th style="width: 32%;">Shop Name</th>
                    <th style="width: 15%;">Bill No.</th>
                    <th style="width: 14%;">Total Amount</th>
                    <th style="width: 11%;">Credit</th>
                    <th style="width: 11%;">Return</th>
                    <th style="width: 12%;">Cash</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sr = 1;
                $grandTotalAmount = 0.00;

                if (empty($orders)):
                ?>
                    <tr>
                        <td colspan="7" class="text-center" style="padding: 25px; color: #64748b;">
                            No dispatched sales orders found for this date.
                        </td>
                    </tr>
                <?php 
                else:
                    foreach ($orders as $o): 
                        $amt = (float)$o['total_amount'];
                        $grandTotalAmount += $amt;
                ?>
                    <tr>
                        <td class="text-center"><?= $sr++ ?></td>
                        <td class="text-left font-bold"><?= htmlspecialchars($o['shop_name']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($o['order_number']) ?></td>
                        <td class="text-right" style="font-weight: 600;"><?= number_format($amt, 0) ?></td>
                        <!-- Blank cells for Rider handwritten notes on delivery -->
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endforeach; endif; ?>

                <?php 
                // Extra blank rows if fewer than 12 orders for manual spot orders or handwritten additions
                $padRows = max(0, 10 - count($orders));
                for ($p = 0; $p < $padRows; $p++):
                ?>
                    <tr style="height: 28px;">
                        <td class="text-center"><?= $sr++ ?></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td colspan="3" class="text-center" style="font-size: 10pt;">Total</td>
                    <td class="text-right" style="font-size: 10pt;"><?= number_format($grandTotalAmount, 0) ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <!-- Signatures & Handover Verification -->
        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line">Delivery Man / Rider (Deposit Signature)</div>
            </div>
            <div class="sig-block">
                <div class="sig-line">Cashier / Accountant (Receiving Signature)</div>
            </div>
        </div>
    </div>

</body>
</html>
