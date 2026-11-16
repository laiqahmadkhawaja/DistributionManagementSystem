<?php
/**
 * Warehouse Product Handover & Returns Inspection Portal (اسٹاک ہینڈ اوور)
 * End-of-day physical stock check-in from delivery vehicles into warehouse quarantine,
 * and Administrator inspection to "Mark to Resell" into active inventory.
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canAccess = hasPermission('warehouse', 'view') 
    || hasPermission('returns', 'view')
    || hasPermission('delivery', 'deliver')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STOREKEEPER, ROLE_DELIVERY_MAN, ROLE_SALESMAN]);

if (!$canAccess) {
    requirePermission('warehouse', 'view');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();

$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);
$isDeliveryMan = ($roleSlug === ROLE_DELIVERY_MAN);
$isAdminOrStorekeeper = in_array($roleSlug, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STOREKEEPER]);

// Handle POST: Submit Product Handover (Storekeeper receives driver's vehicle return stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stock_handover'])) {
    validateCsrf();

    if ($isAdminOrStorekeeper) {
        $driverId = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : ($currentEmp['id'] ?? 0);
    } else {
        if (empty($currentEmp['id'])) {
            setFlashMessage('error', 'Access denied. You do not have an active employee profile linked to your user account.');
            redirect(BASE_URL . '/modules/warehouse/stock_handover.php');
        }
        $driverId = (int)$currentEmp['id'];
    }
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $handoverDate = sanitize($_POST['handover_date'] ?? date('Y-m-d'));
    $notes = sanitize($_POST['notes'] ?? '');

    if ($driverId <= 0) {
        setFlashMessage('error', 'Please select a valid delivery driver.');
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php');
    }

    try {
        $pdo->beginTransaction();

        // Fetch all pending return items in this driver's vehicle custody
        $pendingItemsStmt = $pdo->prepare("
            SELECT sri.*, sr.id as sales_return_id, sr.return_number, sr.customer_id, p.name as product_name
            FROM `sales_return_items` sri
            JOIN `sales_returns` sr ON sri.sales_return_id = sr.id
            JOIN `products` p ON sri.product_id = p.id
            WHERE sr.delivery_man_id = ? 
              AND sr.handover_status = 'in_vehicle_custody'
              AND sri.resell_status = 'in_vehicle_custody'
        ");
        $pendingItemsStmt->execute([$driverId]);
        $custodyItems = $pendingItemsStmt->fetchAll();

        if (empty($custodyItems)) {
            throw new Exception("No return items found in this driver's vehicle custody to handover.");
        }

        $totalSkus = count($custodyItems);
        $totalQty = 0.00;
        $totalVal = 0.00;
        $srIdsToUpdate = [];

        foreach ($custodyItems as $ci) {
            $totalQty += (float)$ci['quantity'];
            $totalVal += (float)$ci['total_amount'];
            $srIdsToUpdate[(int)$ci['sales_return_id']] = true;
        }

        $handoverNo = generateDocNumber($pdo, 'stock_handovers', 'handover_number', 'PRH-');

        // 1. Insert into stock_handovers
        $shStmt = $pdo->prepare("
            INSERT INTO `stock_handovers` (
                `handover_number`, `delivery_man_id`, `warehouse_id`, `handover_date`,
                `total_skus`, `total_quantity`, `total_value`, `status`,
                `received_by`, `received_at`, `notes`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'verified', ?, NOW(), ?, NOW())
        ");
        $shStmt->execute([
            $handoverNo, $driverId, $warehouseId, $handoverDate,
            $totalSkus, $totalQty, $totalVal,
            $currentUser['id'], $notes
        ]);
        $handoverId = (int)$pdo->lastInsertId();

        // 2. Insert itemized handover records with resell_status = 'quarantine_holding'
        $shiStmt = $pdo->prepare("
            INSERT INTO `stock_handover_items` (
                `stock_handover_id`, `sales_return_id`, `sales_return_item_id`,
                `product_id`, `unit_id`, `quantity`, `condition_type`, `resell_status`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'quarantine_holding')
        ");

        $sriUpdate = $pdo->prepare("UPDATE `sales_return_items` SET `resell_status` = 'quarantine_holding' WHERE id = ?");

        foreach ($custodyItems as $ci) {
            $shiStmt->execute([
                $handoverId, $ci['sales_return_id'], $ci['id'],
                $ci['product_id'], $ci['unit_id'], $ci['quantity'], $ci['condition_type']
            ]);
            $sriUpdate->execute([$ci['id']]);
        }

        // 3. Mark sales returns as received in warehouse
        $srUpdate = $pdo->prepare("UPDATE `sales_returns` SET `handover_status` = 'received_in_warehouse' WHERE id = ?");
        foreach (array_keys($srIdsToUpdate) as $srId) {
            $srUpdate->execute([$srId]);
        }

        // Notice: Active stock in stock_ledger is NOT increased here!
        // Stock remains in Quarantine Holding until Administrator approves "Mark to Resell".
        logAudit($pdo, 'warehouse', $handoverId, 'stock_handover_received', null, [
            'handover_number' => $handoverNo,
            'driver_id' => $driverId,
            'total_qty' => $totalQty,
            'total_value' => $totalVal
        ]);

        $pdo->commit();

        setFlashMessage('success', "Stock Handover <strong>{$handoverNo}</strong> verified! {$totalQty} returned units received into Warehouse Quarantine Holding. Driver vehicle custody cleared.");
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Handover error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php');
    }
}

// Handle POST: Administrator "Mark to Resell" / Restock into Active Stock (STRICTLY SUPER ADMINISTRATOR ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_restock_resell'])) {
    validateCsrf();

    if (!$isSuperAdmin) {
        setFlashMessage('error', 'Security Violation: Only the Super Administrator can verify and mark returned stock to resell.');
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');
    }

    $shiId = (int)($_POST['handover_item_id'] ?? 0);
    $resellQty = (float)($_POST['resell_qty'] ?? 0.00);
    $targetWarehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $adminNotes = sanitize($_POST['admin_notes'] ?? '');

    if ($shiId <= 0 || $resellQty <= 0.001) {
        setFlashMessage('error', 'Invalid restock request. Quantity must be greater than zero.');
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');
    }

    try {
        $pdo->beginTransaction();

        // Fetch handover item details
        $itemStmt = $pdo->prepare("
            SELECT shi.*, p.name as product_name, p.purchase_price, sr.return_number
            FROM `stock_handover_items` shi
            JOIN `products` p ON shi.product_id = p.id
            JOIN `sales_returns` sr ON shi.sales_return_id = sr.id
            WHERE shi.id = ? AND shi.resell_status = 'quarantine_holding'
        ");
        $itemStmt->execute([$shiId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            throw new Exception("Quarantine stock record not found or already processed.");
        }

        $maxQty = (float)$item['quantity'];
        if ($resellQty > $maxQty) {
            throw new Exception("Cannot resell more than quarantined quantity ({$maxQty}).");
        }

        $unitCost = (float)($item['purchase_price'] ?? 0.00);

        // 1. CRITICAL: NOW AND ONLY NOW, record stock movement into active stock_ledger
        recordStockMovement(
            $pdo,
            $targetWarehouseId,
            (int)$item['product_id'],
            'sale_return',
            'stock_handover',
            (int)$item['stock_handover_id'],
            (int)$item['unit_id'],
            $resellQty, // Quantity In -> Active stock increases!
            0.00,
            $unitCost,
            null,
            "Approved to resell by Admin from Return #{$item['return_number']}. {$adminNotes}"
        );

        // 2. Update stock handover item and sales return item status
        $pdo->prepare("
            UPDATE `stock_handover_items` 
            SET `resell_status` = 'restocked_active', `restocked_qty` = ?, `inspected_by` = ?, `inspected_at` = NOW(), `inspection_notes` = ?
            WHERE id = ?
        ")->execute([$resellQty, $currentUser['id'], $adminNotes, $shiId]);

        $pdo->prepare("
            UPDATE `sales_return_items`
            SET `resell_status` = 'restocked_active', `restocked_qty` = ?, `restocked_at` = NOW(), `restocked_by` = ?
            WHERE id = ?
        ")->execute([$resellQty, $currentUser['id'], $item['sales_return_item_id']]);

        logAudit($pdo, 'warehouse', $shiId, 'marked_to_resell', null, [
            'product_id' => $item['product_id'],
            'quantity' => $resellQty,
            'warehouse_id' => $targetWarehouseId
        ]);

        $pdo->commit();

        setFlashMessage('success', "Product <strong>" . htmlspecialchars($item['product_name']) . "</strong> ({$resellQty} units) marked to resell! Active sellable stock has been updated.");
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Restock approval error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');
    }
}

// Handle POST: Administrator "Mark as Scrapped / Damaged"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mark_scrap'])) {
    validateCsrf();

    if (!$isSuperAdmin) {
        setFlashMessage('error', 'Security Violation: Only the Super Administrator can mark quarantine stock as scrap.');
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');
    }

    $shiId = (int)($_POST['handover_item_id'] ?? 0);
    $adminNotes = sanitize($_POST['admin_notes'] ?? 'Damaged / Unsellable');

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE `stock_handover_items` 
            SET `resell_status` = 'written_off', `inspected_by` = ?, `inspected_at` = NOW(), `inspection_notes` = ?
            WHERE id = ? AND `resell_status` = 'quarantine_holding'
        ")->execute([$currentUser['id'], $adminNotes, $shiId]);

        $pdo->prepare("
            UPDATE `sales_return_items` sri
            JOIN `stock_handover_items` shi ON sri.id = shi.sales_return_item_id
            SET sri.resell_status = 'written_off', sri.restocked_at = NOW(), sri.restocked_by = ?
            WHERE shi.id = ?
        ")->execute([$currentUser['id'], $shiId]);

        $pdo->commit();
        setFlashMessage('success', "Item marked as damaged/scrapped. It will NOT enter active stock.");
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Scrap marking error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=quarantine');
    }
}

// Active Tab
$activeTab = sanitize($_GET['tab'] ?? 'handover');
if ($activeTab === 'quarantine' && !$isSuperAdmin) {
    setFlashMessage('error', 'Access denied. The Quarantine Inspection & Resell verification section is strictly restricted to Super Administrator.');
    redirect(BASE_URL . '/modules/warehouse/stock_handover.php?tab=handover');
}

// Fetch Drivers with Custody or All Drivers
$drivers = $pdo->query("
    SELECT id, employee_code, full_name, role_type, phone
    FROM `employees`
    WHERE status = 'active' AND role_type IN ('delivery_man', 'salesman', 'order_booker')
    ORDER BY full_name ASC
")->fetchAll();

$selectedDriverId = !empty($_GET['driver_id']) 
    ? (int)$_GET['driver_id'] 
    : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : ($drivers[0]['id'] ?? 0));

// Fetch Current Driver's Vehicle Custody Items
$driverCustodyItems = [];
$driverCustodyTotalQty = 0.00;
$driverCustodyTotalVal = 0.00;

if ($selectedDriverId > 0) {
    $cItemsStmt = $pdo->prepare("
        SELECT sri.*, sr.return_number, sr.return_date, sr.reason,
               c.shop_name, c.owner_name, c.phone as cust_phone, a.name as area_name,
               p.name as product_name, p.code as product_code, u.name as unit_name
        FROM `sales_return_items` sri
        JOIN `sales_returns` sr ON sri.sales_return_id = sr.id
        JOIN `customers` c ON sr.customer_id = c.id
        JOIN `areas` a ON c.area_id = a.id
        JOIN `products` p ON sri.product_id = p.id
        JOIN `units` u ON sri.unit_id = u.id
        WHERE sr.delivery_man_id = ? 
          AND sr.handover_status = 'in_vehicle_custody'
          AND sri.resell_status = 'in_vehicle_custody'
        ORDER BY sr.id ASC
    ");
    $cItemsStmt->execute([$selectedDriverId]);
    $driverCustodyItems = $cItemsStmt->fetchAll();

    foreach ($driverCustodyItems as $dci) {
        $driverCustodyTotalQty += (float)$dci['quantity'];
        $driverCustodyTotalVal += (float)$dci['total_amount'];
    }
}

// Fetch Items Currently in Warehouse Quarantine Holding (Awaiting Admin Resell Approval)
$quarantineItems = $pdo->query("
    SELECT shi.*, sh.handover_number, sh.handover_date,
           p.name as product_name, p.code as product_code, p.purchase_price, p.trade_price,
           u.name as unit_name,
           e.full_name as driver_name, e.employee_code as driver_code,
           c.shop_name, c.owner_name,
           sr.return_number
    FROM `stock_handover_items` shi
    JOIN `stock_handovers` sh ON shi.stock_handover_id = sh.id
    JOIN `products` p ON shi.product_id = p.id
    JOIN `units` u ON shi.unit_id = u.id
    JOIN `employees` e ON sh.delivery_man_id = e.id
    JOIN `sales_returns` sr ON shi.sales_return_id = sr.id
    JOIN `customers` c ON sr.customer_id = c.id
    WHERE shi.resell_status = 'quarantine_holding'
    ORDER BY shi.id DESC
")->fetchAll();

$quarantineTotalQty = 0.00;
$quarantineTotalVal = 0.00;
foreach ($quarantineItems as $qi) {
    $quarantineTotalQty += (float)$qi['quantity'];
    $quarantineTotalVal += (float)$qi['quantity'] * (float)$qi['trade_price'];
}

// Fetch Past Stock Handovers History (Superadmin sees all company records; field workers see only their own)
$historyWhere = "1=1";
$historyParams = [];
if (!$isSuperAdmin) {
    $empId = (int)($currentEmp['id'] ?? 0);
    $historyWhere = "sh.delivery_man_id = ?";
    $historyParams = [$empId];
}

$shHistStmt = $pdo->prepare("
    SELECT sh.*, e.full_name as driver_name, e.employee_code as driver_code,
           u.full_name as receiver_name, w.name as warehouse_name
    FROM `stock_handovers` sh
    JOIN `employees` e ON sh.delivery_man_id = e.id
    JOIN `warehouses` w ON sh.warehouse_id = w.id
    LEFT JOIN `users` u ON sh.received_by = u.id
    WHERE {$historyWhere}
    ORDER BY sh.id DESC
    LIMIT 30
");
$shHistStmt->execute($historyParams);
$handoversHistory = $shHistStmt->fetchAll();

$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active'")->fetchAll();

$pageTitle = 'Warehouse Product Handover';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-box-seam text-warning me-2"></i>Warehouse Product Handover & Quarantine (اسٹاک ہینڈ اوور)
        </h4>
        <small class="text-muted">End-of-day driver product returns check-in and Administrator resell approvals</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/delivery/return.php" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-box-arrow-in-left me-1"></i> Record Shop Return
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/handover.php" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-wallet2 me-1"></i> Cash Handover
        </a>
    </div>
</div>

<!-- Navigation Tabs -->
<ul class="nav nav-pills mb-4 gap-2 border-bottom pb-3">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'handover' ? 'active bg-warning text-dark fw-bold shadow-sm' : 'bg-white border text-dark' ?>" href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php?tab=handover">
            <i class="bi bi-truck me-1"></i> 1. Driver Stock Handover
            <?php if ($driverCustodyTotalQty > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= (float)$driverCustodyTotalQty ?> Units</span>
            <?php endif; ?>
        </a>
    </li>
    <?php if ($isSuperAdmin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'quarantine' ? 'active bg-danger text-white fw-bold shadow-sm' : 'bg-white border text-dark' ?>" href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php?tab=quarantine">
            <i class="bi bi-shield-exclamation me-1"></i> 2. Quarantine Holding & Resell Approval
            <?php if (count($quarantineItems) > 0): ?>
                <span class="badge bg-white text-danger rounded-pill ms-1"><?= count($quarantineItems) ?> SKUs</span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'history' ? 'active bg-primary text-white fw-bold shadow-sm' : 'bg-white border text-dark' ?>" href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php?tab=history">
            <i class="bi bi-clock-history me-1"></i> <?= $isSuperAdmin ? 'Handover Audit History' : 'Your Handover History' ?>
        </a>
    </li>
</ul>

<?php if ($activeTab === 'handover'): ?>
    <!-- TAB 1: DRIVER STOCK HANDOVER -->
    <div class="row g-3">
        <!-- Left: Handover Confirmation Form -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-check2-circle text-warning me-2"></i>Receive Driver Return Stock
                    </h6>
                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle">Check-in at Dock</span>
                </div>
                <div class="card-body p-3">
                    <form method="POST" action="<?= BASE_URL ?>/modules/warehouse/stock_handover.php" id="stockHandoverForm">
                        <?= getCsrfField() ?>
                        <input type="hidden" name="submit_stock_handover" value="1">

                        <!-- Driver Select -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Delivery Driver / Specialist <span class="text-danger">*</span></label>
                            <?php if ($isAdminOrStorekeeper): ?>
                                <select class="form-select fw-semibold" name="driver_id" id="driverSelect" onchange="window.location.href='<?= BASE_URL ?>/modules/warehouse/stock_handover.php?tab=handover&driver_id=' + this.value;">
                                    <?php foreach ($drivers as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= $d['id'] == $selectedDriverId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['employee_code']) ?> - <?= ucwords(str_replace('_', ' ', $d['role_type'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="driver_id" value="<?= (int)($currentEmp['id'] ?? 0) ?>">
                                <select class="form-select fw-semibold bg-light" disabled>
                                    <option value="<?= (int)($currentEmp['id'] ?? 0) ?>" selected>
                                        <?= htmlspecialchars($currentEmp['full_name'] ?? 'Myself') ?> (<?= htmlspecialchars($currentEmp['employee_code'] ?? '') ?> - <?= ucwords(str_replace('_', ' ', $currentEmp['role_type'] ?? 'Staff')) ?>)
                                    </option>
                                </select>
                                <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-shield-lock text-secondary me-1"></i>Locked to your vehicle returns (Warehouse Manager checks in)</small>
                            <?php endif; ?>
                        </div>

                        <!-- Warehouse & Date -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Receiving Warehouse</label>
                                <select class="form-select form-select-sm" name="warehouse_id">
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Check-in Date</label>
                                <input type="date" class="form-control form-control-sm" name="handover_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <!-- Live Custody Summary Box -->
                        <div class="p-3 bg-light rounded-3 mb-3 border">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <span class="text-muted small fw-semibold">Physical Items to Unload:</span>
                                <span class="fw-bold <?= $driverCustodyTotalQty > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= (float)$driverCustodyTotalQty ?> Units (<?= count($driverCustodyItems) ?> SKUs)
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Total Return Value:</span>
                                <span class="fw-bold text-dark"><?= formatMoney($driverCustodyTotalVal) ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small">Warehouse Destination:</span>
                                <span class="badge bg-warning text-dark">Quarantine Holding (Not Active Stock)</span>
                            </div>
                        </div>

                        <!-- Storekeeper Verification Checkbox -->
                        <div class="form-check p-3 bg-white border rounded-3 mb-3">
                            <input class="form-check-input ms-0 me-2" type="checkbox" id="checkTally" required <?= empty($driverCustodyItems) ? 'disabled' : '' ?>>
                            <label class="form-check-label small fw-semibold text-dark" for="checkTally">
                                Storekeeper Verification: I confirm that all returned items listed on the right have been physically counted, verified, and unloaded into the warehouse quarantine return bin.
                            </label>
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Handover / Inspection Remarks</label>
                            <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. Unloaded 4 cartons from van. Physical count matched voucher."></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 btn-touch shadow-sm fw-bold text-dark py-2" <?= empty($driverCustodyItems) ? 'disabled' : '' ?>>
                            <i class="bi bi-box-arrow-in-down me-1"></i> Receive Stock into Warehouse Quarantine
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Itemized Custody List to Unload -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-truck me-2 text-primary"></i>Items in Driver's Vehicle Custody</h6>
                        <small class="text-muted">Physically in van awaiting unloading and count verification</small>
                    </div>
                    <span class="badge bg-danger rounded-pill"><?= count($driverCustodyItems) ?> Lines</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($driverCustodyItems)): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                            <h6 class="fw-bold text-dark">Vehicle Custody Clear!</h6>
                            <p class="small text-muted mb-0">This driver has no pending return items in their vehicle.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Shop / Return #</th>
                                        <th>Product SKU</th>
                                        <th class="text-center">Qty to Unload</th>
                                        <th>Condition</th>
                                        <th class="text-end">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($driverCustodyItems as $it): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark small"><?= htmlspecialchars($it['shop_name']) ?></div>
                                                <span class="text-primary font-monospace" style="font-size: 0.68rem;"><?= htmlspecialchars($it['return_number']) ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark small"><?= htmlspecialchars($it['product_name']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($it['unit_name']) ?></small>
                                            </td>
                                            <td class="text-center fw-bold text-danger fs-6">
                                                <?= (float)$it['quantity'] ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border" style="font-size: 0.68rem;">
                                                    <?= ucfirst(str_replace('_', ' ', $it['condition_type'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-end fw-semibold text-dark">
                                                <?= formatMoney($it['total_amount']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($activeTab === 'quarantine'): ?>
    <!-- TAB 2: QUARANTINE INSPECTION & ADMIN MARK TO RESELL -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-shield-exclamation text-danger me-2"></i>Warehouse Quarantine Return Stock (معائنہ اور ری سیل)
                </h6>
                <small class="text-muted">
                    These items are physically in the warehouse return bin, but <strong>NOT shown in active stock</strong> until the Administrator marks them to resell.
                </small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark border px-3 py-2">
                    Total in Quarantine: <strong><?= (float)$quarantineTotalQty ?> Units</strong> (<?= formatMoney($quarantineTotalVal) ?>)
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($quarantineItems)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-shield-check fs-1 text-success d-block mb-2"></i>
                    <h6 class="fw-bold text-dark">Quarantine Holding Clear!</h6>
                    <p class="small text-muted mb-0">No returned items currently awaiting inspection or resell approval.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Product Details</th>
                                <th>Returned By</th>
                                <th>Driver / Handover #</th>
                                <th class="text-center">Quarantine Qty</th>
                                <th>Condition Reported</th>
                                <th class="text-end" style="min-width: 250px;">Administrator Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quarantineItems as $qi): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($qi['product_name']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($qi['product_code']) ?> · Trade Price: <?= formatMoney($qi['trade_price']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($qi['shop_name']) ?></div>
                                        <small class="text-muted">Return #<?= htmlspecialchars($qi['return_number']) ?></small>
                                    </td>
                                    <td>
                                        <div class="text-dark small fw-semibold"><?= htmlspecialchars($qi['driver_name']) ?></div>
                                        <small class="text-muted">#<?= htmlspecialchars($qi['handover_number']) ?> · <?= formatDate($qi['handover_date']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger fs-6 px-3 py-1"><?= (float)$qi['quantity'] ?> <?= htmlspecialchars($qi['unit_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= ucfirst(str_replace('_', ' ', $qi['condition_type'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($isAdminOrStorekeeper): ?>
                                            <div class="d-flex justify-content-end gap-1">
                                                <!-- Mark to Resell Trigger Button -->
                                                <button type="button" class="btn btn-sm btn-success fw-bold" data-bs-toggle="collapse" data-bs-target="#resellBox_<?= $qi['id'] ?>">
                                                    <i class="bi bi-arrow-repeat me-1"></i> Mark to Resell
                                                </button>
                                                <!-- Scrap Button -->
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#scrapBox_<?= $qi['id'] ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>

                                            <!-- Inline Mark to Resell Form -->
                                            <div class="collapse text-start mt-2 p-3 bg-light rounded border border-success" id="resellBox_<?= $qi['id'] ?>">
                                                <form method="POST" action="<?= BASE_URL ?>/modules/warehouse/stock_handover.php">
                                                    <?= getCsrfField() ?>
                                                    <input type="hidden" name="action_restock_resell" value="1">
                                                    <input type="hidden" name="handover_item_id" value="<?= $qi['id'] ?>">

                                                    <div class="fw-bold text-success small mb-2">
                                                        <i class="bi bi-check-circle-fill me-1"></i>Approve for Active Sellable Stock:
                                                    </div>
                                                    <div class="row g-2 mb-2">
                                                        <div class="col-6">
                                                            <label class="form-label" style="font-size: 0.7rem;">Resell Qty (Units)</label>
                                                            <input type="number" class="form-control form-control-sm" name="resell_qty" value="<?= (float)$qi['quantity'] ?>" min="1" max="<?= (float)$qi['quantity'] ?>" step="1" required>
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label" style="font-size: 0.7rem;">Active Warehouse</label>
                                                            <select class="form-select form-select-sm" name="warehouse_id">
                                                                <?php foreach ($warehouses as $w): ?>
                                                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="mb-2">
                                                        <input type="text" class="form-control form-control-sm" name="admin_notes" placeholder="Inspection remarks (e.g. Packing intact, approved for sale)">
                                                    </div>
                                                    <div class="d-flex gap-1 justify-content-end">
                                                        <button type="submit" class="btn btn-sm btn-success fw-bold">Confirm & Add to Active Stock</button>
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#resellBox_<?= $qi['id'] ?>">Cancel</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Inline Scrap Form -->
                                            <div class="collapse text-start mt-2 p-2 bg-light rounded border border-danger" id="scrapBox_<?= $qi['id'] ?>">
                                                <form method="POST" action="<?= BASE_URL ?>/modules/warehouse/stock_handover.php">
                                                    <?= getCsrfField() ?>
                                                    <input type="hidden" name="action_mark_scrap" value="1">
                                                    <input type="hidden" name="handover_item_id" value="<?= $qi['id'] ?>">

                                                    <div class="fw-bold text-danger small mb-1">Confirm Write-Off / Vendor Claim:</div>
                                                    <p class="small text-muted mb-2" style="font-size: 0.75rem;">This item is damaged or expired and will NOT enter active stock.</p>
                                                    <div class="d-flex gap-1 justify-content-end">
                                                        <button type="submit" class="btn btn-sm btn-danger">Confirm Scrap</button>
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#scrapBox_<?= $qi['id'] ?>">Cancel</button>
                                                    </div>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">Requires Admin Inspection</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($activeTab === 'history'): ?>
    <!-- TAB 3: HANDOVER AUDIT HISTORY -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-text me-2 text-primary"></i>Past Stock Handovers History</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($handoversHistory)): ?>
                <div class="p-4 text-center text-muted">No stock handovers recorded yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Handover #</th>
                                <th>Driver</th>
                                <th>Date</th>
                                <th>Warehouse</th>
                                <th class="text-center">Total SKUs</th>
                                <th class="text-center">Total Quantity</th>
                                <th class="text-end">Value</th>
                                <th>Received By</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($handoversHistory as $h): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($h['handover_number']) ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($h['driver_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($h['driver_code']) ?></small>
                                    </td>
                                    <td><?= formatDate($h['handover_date']) ?></td>
                                    <td><?= htmlspecialchars($h['warehouse_name']) ?></td>
                                    <td class="text-center fw-semibold"><?= (int)$h['total_skus'] ?></td>
                                    <td class="text-center fw-bold text-danger"><?= (float)$h['total_quantity'] ?></td>
                                    <td class="text-end fw-bold text-dark"><?= formatMoney($h['total_value']) ?></td>
                                    <td><?= htmlspecialchars($h['receiver_name'] ?? 'Storekeeper') ?></td>
                                    <td>
                                        <span class="badge bg-success" style="font-size: 0.68rem;">Verified Received</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
