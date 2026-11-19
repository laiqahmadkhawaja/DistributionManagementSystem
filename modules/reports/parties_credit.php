<?php
/**
 * Parties Credit Summary Report (Market Outstanding Udhaar Report)
 * Modeled after FMCG Distributor Traditional Standard (Ref Image 6)
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('reports', 'sales');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);

// Filters
$areaId = (int)($_GET['area_id'] ?? 0);
$bookerId = (int)($_GET['booker_id'] ?? 0);
$minAmount = (float)($_GET['min_amount'] ?? 1);

$where = ["c.current_balance >= ? AND c.status = 'active'"];
$params = [$minAmount];

if ($areaId > 0) {
    $where[] = "c.area_id = ?";
    $params[] = $areaId;
}
if ($bookerId > 0) {
    $where[] = "c.assigned_order_booker_id = ?";
    $params[] = $bookerId;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT 
        c.id, c.code, c.shop_name, c.owner_name, c.address, c.current_balance, c.phone,
        a.name as area_name,
        e.full_name as booker_name
    FROM customers c
    JOIN areas a ON c.area_id = a.id
    LEFT JOIN employees e ON c.assigned_order_booker_id = e.id
    WHERE {$whereSql}
    ORDER BY c.current_balance DESC, a.name ASC, c.shop_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$parties = $stmt->fetchAll(PDO::FETCH_ASSOC);

$areas = $pdo->query("SELECT id, name FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bookers = $pdo->query("SELECT id, full_name FROM employees WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

$grandTotalCredit = 0.00;
foreach ($parties as $p) {
    $grandTotalCredit += (float)$p['current_balance'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parties_Credit_Summary_<?= date('Ymd') ?></title>
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
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #000;
        }

        /* Banner matching Image 6 (Blue Title Bar) */
        .header-banner {
            background-color: #5b8494 !important;
            color: #000;
            text-align: center;
            padding: 10px;
            font-size: 16pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        table.credit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
        }
        table.credit-table th, table.credit-table td {
            border: 1px solid #000;
            padding: 6px 10px;
            vertical-align: middle;
        }
        /* Header row with dark grey/green fill and yellow bold text matching Image 6 */
        table.credit-table thead tr th {
            background-color: #3f4749 !important;
            color: #ffff00 !important;
            font-weight: 900;
            font-size: 11pt;
            letter-spacing: 0.5px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .col-party { width: 45%; }
        .col-address { width: 35%; }
        .col-amount { width: 20%; text-align: right; font-weight: bold; }

        .row-total td {
            font-weight: 900;
            font-size: 12pt;
            background-color: #d4a017 !important;
            color: #000;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .highlight-yellow {
            background-color: #ffff00 !important;
            font-weight: 900;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sheet-container { border: 2px solid #000; max-width: 100%; }
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Printing Controls -->
    <div class="toolbar">
        <form method="GET" action="">
            <select name="area_id">
                <option value="0">-- All Areas / Routes --</option>
                <?php foreach ($areas as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $areaId == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="booker_id">
                <option value="0">-- All Order Bookers --</option>
                <?php foreach ($bookers as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bookerId == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>

        <div style="display: flex; gap: 8px;">
            <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-secondary">&larr; Reports</a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                &#128438; Print Report (A4)
            </button>
        </div>
    </div>

    <!-- Parties Credit Sheet (Ref Image 6 Standard) -->
    <div class="sheet-container">
        <div class="header-banner">
            Parties Credit
        </div>

        <table class="credit-table">
            <thead>
                <tr>
                    <th class="col-party text-left">Booker Name / Shop</th>
                    <th class="col-address text-left">Address</th>
                    <th class="col-amount text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parties)): ?>
                    <tr>
                        <td colspan="3" class="text-center" style="padding: 25px; color: #64748b;">
                            No outstanding credit balances found for active parties.
                        </td>
                    </tr>
                <?php else: foreach ($parties as $p): 
                    $bal = (float)$p['current_balance'];
                ?>
                    <tr>
                        <td class="col-party text-left">
                            <strong><?= htmlspecialchars($p['shop_name']) ?></strong>
                            <?php if (!empty($p['owner_name']) && $p['owner_name'] !== $p['shop_name']): ?>
                                <small style="color: #444;"> (<?= htmlspecialchars($p['owner_name']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="col-address text-left">
                            <?= htmlspecialchars($p['address'] ?: $p['area_name']) ?>
                        </td>
                        <td class="col-amount text-right">
                            <?= number_format($bal, 0) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td colspan="2" class="text-center">Total</td>
                    <td class="text-right"><?= number_format($grandTotalCredit, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

</body>
</html>
