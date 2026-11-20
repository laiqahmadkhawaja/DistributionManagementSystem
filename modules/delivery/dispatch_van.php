<?php
/**
 * Daily Van Loading & Rider Dispatch (گاڑی لوڈنگ و رائڈر اسائنمنٹ)
 * 1-Click Bulk Assignment of Booked Orders to Delivery Driver / Van
 * Generates Master Load Form, Delivery Run Sheet & Batch Bills seamlessly
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'dispatch');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currentUser = getCurrentUser();

// Request parameters
$date = sanitize($_GET['date'] ?? date('Y-m-d'));
$areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
$bookerId = isset($_GET['booker_id']) ? (int)$_GET['booker_id'] : 0;
$deliveryManId = isset($_GET['delivery_man_id']) ? (int)$_GET['delivery_man_id'] : 0;
$warehouseId = (int)($pdo->query("SELECT id FROM warehouses WHERE status = 'active' ORDER BY is_default DESC LIMIT 1")->fetchColumn() ?: 1);

// Dropdown sources
$drivers = $pdo->query("
    SELECT id, full_name, employee_code, phone 
    FROM employees 
    WHERE role_type IN ('delivery_man', 'delivery_manager', 'salesman', 'admin', 'super_admin') 
      AND status = 'active' 
    ORDER BY full_name ASC
")->fetchAll();

$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bookers = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE role_type IN ('order_booker', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Handle POST: Bulk Assign & Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_dispatch_van') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $targetDriverId = (int)($_POST['driver_id'] ?? 0);
    $selectedOrderIds = $_POST['order_ids'] ?? [];
    $dispatchDate = sanitize($_POST['dispatch_date'] ?? $date);
    $vehicleNo = sanitize($_POST['vehicle_no'] ?? '');

    if ($targetDriverId <= 0) {
        setFlashMessage('error', 'Please select a Delivery Driver / Rider to assign the orders.');
        redirect(BASE_URL . "/modules/delivery/dispatch_van.php?date={$dispatchDate}&area_id={$areaId}&booker_id={$bookerId}");
    }

    if (empty($selectedOrderIds)) {
        setFlashMessage('error', 'Please select at least one order to dispatch into the van.');
        redirect(BASE_URL . "/modules/delivery/dispatch_van.php?date={$dispatchDate}&area_id={$areaId}&booker_id={$bookerId}&delivery_man_id={$targetDriverId}");
    }

    try {
        $pdo->beginTransaction();

        $dispatchedCount = 0;
        $totalDispatchedValue = 0.00;

        // Fetch driver info
        $dStmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
        $dStmt->execute([$targetDriverId]);
        $driverName = $dStmt->fetchColumn() ?: 'Assigned Driver';

        foreach ($selectedOrderIds as $ordId) {
            $ordId = (int)$ordId;
            if ($ordId <= 0) continue;

            // Fetch order
            $soStmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id = ? FOR UPDATE");
            $soStmt->execute([$ordId]);
            $order = $soStmt->fetch();

            if (!$order) continue;

            $totalDispatchedValue += (float)$order['grand_total'];

            // Check if already assigned
            $chkDa = $pdo->prepare("SELECT id FROM delivery_assignments WHERE sales_order_id = ? LIMIT 1");
            $chkDa->execute([$ordId]);
            $existingDaId = (int)$chkDa->fetchColumn();

            if ($existingDaId > 0) {
                // Update assignment with new driver and vehicle
                $upDa = $pdo->prepare("
                    UPDATE delivery_assignments 
                    SET delivery_man_id = ?, vehicle_no = ?, dispatch_date = ?, status = 'assigned'
                    WHERE id = ?
                ");
                $upDa->execute([$targetDriverId, $vehicleNo, $dispatchDate, $existingDaId]);
            } else {
                // Generate dispatch number & create new assignment
                $dispatchNumber = generateDocNumber($pdo, 'delivery_assignments', 'dispatch_number', 'DC-');
                $insDa = $pdo->prepare("
                    INSERT INTO delivery_assignments (
                        dispatch_number, sales_order_id, delivery_man_id, warehouse_id,
                        vehicle_no, route_name, dispatch_date, status, dispatched_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, '', ?, 'assigned', ?, NOW())
                ");
                $insDa->execute([
                    $dispatchNumber, $ordId, $targetDriverId, $warehouseId,
                    $vehicleNo, $dispatchDate, $currentUser['id']
                ]);
                $existingDaId = (int)$pdo->lastInsertId();

                // Deduct stock for each line item if not already deducted
                $itemsStmt = $pdo->prepare("
                    SELECT soi.*, p.name as product_name, p.purchase_price as prod_cost, p.base_unit_id, u.name as unit_name
                    FROM sales_order_items soi
                    JOIN products p ON soi.product_id = p.id
                    JOIN units u ON soi.unit_id = u.id
                    WHERE soi.sales_order_id = ?
                ");
                $itemsStmt->execute([$ordId]);
                $items = $itemsStmt->fetchAll();

                $insDi = $pdo->prepare("
                    INSERT INTO delivery_items (
                        delivery_assignment_id, sales_order_item_id, product_id, unit_id,
                        dispatched_qty, unit_price, total_amount
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($items as $item) {
                    $insDi->execute([
                        $existingDaId, $item['id'], $item['product_id'], $item['unit_id'],
                        $item['quantity'], $item['unit_price'], $item['line_total']
                    ]);

                    $baseUnitId = (int)($item['base_unit_id'] ?: $item['unit_id']);
                    $physicalQty = (float)$item['quantity'];

                    recordStockMovement(
                        $pdo,
                        $warehouseId,
                        $item['product_id'],
                        'sale_dispatch',
                        'delivery_assignment',
                        $existingDaId,
                        $baseUnitId,
                        0.00,
                        $physicalQty,
                        (float)$item['prod_cost'],
                        null,
                        "Dispatched {$physicalQty} pcs via Challan {$dispatchNumber}"
                    );
                }
            }

            // Update order status to dispatched
            $pdo->prepare("UPDATE sales_orders SET status = 'dispatched' WHERE id = ?")->execute([$ordId]);
            $dispatchedCount++;
        }

        $pdo->commit();

        logAudit($pdo, 'warehouse', $targetDriverId, 'bulk_van_dispatch', null, [
            'driver_name' => $driverName,
            'orders_count' => $dispatchedCount,
            'total_value' => $totalDispatchedValue,
            'date' => $dispatchDate
        ]);

        $_SESSION['last_dispatch_driver_id'] = $targetDriverId;
        $_SESSION['last_dispatch_date'] = $dispatchDate;
        $_SESSION['last_dispatch_area_id'] = $areaId;

        setFlashMessage('success', "Success! {$dispatchedCount} orders (Rs. " . number_format($totalDispatchedValue, 2) . ") assigned to {$driverName} and dispatched into the van. You can now print the Load Sheet, Run Sheet, and Bills below!");
        redirect(BASE_URL . "/modules/delivery/dispatch_van.php?date={$dispatchDate}&area_id={$areaId}&delivery_man_id={$targetDriverId}&dispatched=1");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Bulk dispatch failed: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/delivery/dispatch_van.php?date={$dispatchDate}&area_id={$areaId}&delivery_man_id={$targetDriverId}");
    }
}

// Query Orders for table
$where = ["so.order_date = :odate", "so.status IN ('submitted', 'approved', 'dispatched')"];
$params = [':odate' => $date];

if ($areaId > 0) {
    $where[] = "so.area_id = :aid";
    $params[':aid'] = $areaId;
}
if ($bookerId > 0) {
    $where[] = "so.order_booker_id = :bid";
    $params[':bid'] = $bookerId;
}

$whereSql = implode(' AND ', $where);

$ordersStmt = $pdo->prepare("
    SELECT so.id, so.order_number, so.order_date, so.grand_total, so.status,
           c.shop_name, c.owner_name, c.phone as cust_phone,
           a.name as area_name,
           e.full_name as booker_name,
           da.id as assignment_id, da.dispatch_number, da.status as da_status,
           da_driver.id as assigned_driver_id, da_driver.full_name as assigned_driver_name,
           (SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = so.id) as item_count,
           (SELECT COALESCE(SUM(quantity), 0) FROM sales_order_items WHERE sales_order_id = so.id) as total_pieces
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    JOIN areas a ON so.area_id = a.id
    LEFT JOIN employees e ON so.order_booker_id = e.id
    LEFT JOIN delivery_assignments da ON da.sales_order_id = so.id
    LEFT JOIN employees da_driver ON da.delivery_man_id = da_driver.id
    WHERE {$whereSql}
    ORDER BY a.name ASC, so.id ASC
");
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$justDispatched = isset($_GET['dispatched']) && $_GET['dispatched'] == '1';

$pageTitle = 'Daily Van Loading & Rider Dispatch';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-truck text-primary me-2"></i> Daily Van Loading &amp; Rider Dispatch
        </h4>
        <small class="text-muted">1-Click bulk van loading: assign booked bills to delivery riders &amp; print load sheets</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php?date=<?= urlencode($date) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-lightning-charge me-1"></i> Order Booking
        </a>
        <a href="<?= BASE_URL ?>/modules/delivery/sheet_reconcile.php?date=<?= urlencode($date) ?>&delivery_man_id=<?= $deliveryManId ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-cash-stack me-1"></i> Evening Cash Reconcile
        </a>
    </div>
</div>

<?php if ($justDispatched): ?>
<!-- Post-Dispatch Instant 3-Print Action Banner -->
<div class="card border-0 shadow-sm rounded-3 mb-4 bg-success bg-opacity-10 border border-success">
    <div class="card-body p-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <h5 class="fw-bold text-success mb-1">
                    <i class="bi bi-check-circle-fill me-1"></i> Van Loaded &amp; Dispatched Successfully!
                </h5>
                <p class="small text-muted mb-0">The driver is ready to roll. Print the 3 standard dispatch documents below:</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>/modules/warehouse/stock_issue_print.php?date=<?= urlencode($date) ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>" target="_blank" class="btn btn-primary fw-bold btn-sm shadow-sm">
                    <i class="bi bi-truck me-1"></i> 1. Print Master Load Sheet (Van Challan)
                </a>
                <a href="<?= BASE_URL ?>/modules/delivery/sheet_print.php?date=<?= urlencode($date) ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>" target="_blank" class="btn btn-dark fw-bold btn-sm shadow-sm">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> 2. Print Delivery Run Sheet
                </a>
                <a href="<?= BASE_URL ?>/modules/orders/print_batch.php?date=<?= urlencode($date) ?>&delivery_man_id=<?= $deliveryManId ?>&area_id=<?= $areaId ?>" target="_blank" class="btn btn-info text-white fw-bold btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> 3. Print Batch Bills (2 per A4)
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filter & Selection Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <form method="GET" action="" id="vanFilterForm" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Dispatch / Order Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>" onchange="document.getElementById('vanFilterForm').submit();">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Route / Area</label>
                <select name="area_id" class="form-select form-select-sm" onchange="document.getElementById('vanFilterForm').submit();">
                    <option value="0" <?= $areaId == 0 ? 'selected' : '' ?>>-- All Routes (<?= count($orders) ?> Orders) --</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $areaId == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Filter by Booker</label>
                <select name="booker_id" class="form-select form-select-sm" onchange="document.getElementById('vanFilterForm').submit();">
                    <option value="0" <?= $bookerId == 0 ? 'selected' : '' ?>>-- All Bookers --</option>
                    <?php foreach ($bookers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $bookerId == $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Filter by Driver</label>
                <select name="delivery_man_id" class="form-select form-select-sm" onchange="document.getElementById('vanFilterForm').submit();">
                    <option value="0" <?= $deliveryManId == 0 ? 'selected' : '' ?>>-- All Drivers --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $deliveryManId == $d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Dispatch Form -->
<form method="POST" action="" id="bulkDispatchForm">
    <?= getCsrfField() ?>
    <input type="hidden" name="action" value="bulk_dispatch_van">
    <input type="hidden" name="dispatch_date" value="<?= htmlspecialchars($date) ?>">

    <div class="card border-0 shadow-sm rounded-3 bg-white mb-3">
        
        <!-- Assignment Action Header -->
        <div class="card-header bg-white py-3 border-bottom">
            <div class="row g-2 align-items-center">
                <div class="col-md-4 col-12">
                    <label class="form-label small fw-bold text-dark mb-1">
                        <i class="bi bi-person-badge text-primary me-1"></i> Assign to Delivery Rider / Driver:
                    </label>
                    <select name="driver_id" id="targetDriverSelect" class="form-select form-select-sm fw-bold border-primary" required>
                        <option value="">-- Choose Delivery Rider --</option>
                        <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($deliveryManId == $d['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['employee_code']) ?> - Ph: <?= htmlspecialchars($d['phone'] ?: 'N/A') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label small fw-bold text-muted mb-1">Vehicle / Van No (Optional):</label>
                    <input type="text" name="vehicle_no" placeholder="e.g. Suzuki Ravi GW-4521" class="form-control form-control-sm">
                </div>
                <div class="col-md-5 col-12 text-md-end mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary fw-bold py-2 px-4 shadow-sm" id="btnDispatchSubmit" onclick="return confirmDispatch();">
                        <i class="bi bi-truck me-1"></i> Assign to Driver &amp; Load Van
                    </button>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($orders)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-clipboard-x fs-1 text-secondary d-block mb-2"></i>
                    <h6 class="fw-bold text-dark">No booked orders found for <?= htmlspecialchars($date) ?>.</h6>
                    <p class="small text-muted mb-2">Book orders first via the Daily Order Booking desk.</p>
                    <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php?date=<?= urlencode($date) ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Go to Order Booking
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="dispatchOrdersTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;" class="text-center">
                                    <input type="checkbox" id="selectAllCheckbox" class="form-check-input" checked onchange="toggleSelectAll(this);">
                                </th>
                                <th>Order #</th>
                                <th>Shop Name &amp; Area</th>
                                <th>Order Booker</th>
                                <th class="text-center">Items / Pcs</th>
                                <th class="text-end">Bill Amount</th>
                                <th class="text-center">Current Status / Driver</th>
                                <th class="text-center" style="width: 80px;">Bill</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totValue = 0.00;
                            $totPcs = 0;
                            foreach ($orders as $ord): 
                                $totValue += (float)$ord['grand_total'];
                                $totPcs += (int)$ord['total_pieces'];
                                $isAssigned = !empty($ord['assigned_driver_name']);
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="order_ids[]" value="<?= $ord['id'] ?>" class="form-check-input order-row-checkbox" checked onchange="updateSummaryCounters();">
                                    </td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/modules/orders/bill_compact.php?id=<?= $ord['id'] ?>" target="_blank" class="fw-bold text-primary text-decoration-none">
                                            <?= htmlspecialchars($ord['order_number']) ?>
                                        </a>
                                        <div class="text-muted" style="font-size: 0.72rem;">
                                            <?= date('d M Y', strtotime($ord['order_date'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="d-block text-dark"><?= htmlspecialchars($ord['shop_name']) ?></strong>
                                        <small class="text-muted">
                                            <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($ord['area_name']) ?>
                                            <?php if (!empty($ord['cust_phone'])): ?> &middot; <i class="bi bi-telephone"></i> <?= htmlspecialchars($ord['cust_phone']) ?><?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="text-dark small fw-semibold"><?= htmlspecialchars($ord['booker_name'] ?? 'Direct') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= $ord['item_count'] ?> SKU</span>
                                        <span class="badge bg-info bg-opacity-10 text-primary border border-info"><?= $ord['total_pieces'] ?> Pcs</span>
                                    </td>
                                    <td class="text-end fw-bold text-dark">
                                        Rs. <?= number_format($ord['grand_total'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isAssigned): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                                <i class="bi bi-person-check-fill me-1"></i> <?= htmlspecialchars($ord['assigned_driver_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-10 text-dark border border-warning">
                                                <i class="bi bi-hourglass-split me-1"></i> Awaiting Van Loading
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?= BASE_URL ?>/modules/orders/bill_compact.php?id=<?= $ord['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Print Bill">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light border-top">
                            <tr>
                                <th colspan="4" class="text-end">Selected Total:</th>
                                <th class="text-center" id="counterSelectedPcs"><?= number_format($totPcs) ?> Pcs</th>
                                <th class="text-end text-primary fs-6" id="counterSelectedValue">Rs. <?= number_format($totValue, 2) ?></th>
                                <th colspan="2" class="text-center" id="counterSelectedCount"><?= count($orders) ?> of <?= count($orders) ?> Selected</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
function toggleSelectAll(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.order-row-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateSummaryCounters();
}

function updateSummaryCounters() {
    const checkboxes = document.querySelectorAll('.order-row-checkbox');
    let selCount = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) selCount++;
    });

    const countDisplay = document.getElementById('counterSelectedCount');
    if (countDisplay) {
        countDisplay.innerText = `${selCount} of ${checkboxes.length} Selected`;
    }

    const btnSubmit = document.getElementById('btnDispatchSubmit');
    if (btnSubmit) {
        btnSubmit.disabled = (selCount === 0);
    }
}

function confirmDispatch() {
    const driverSelect = document.getElementById('targetDriverSelect');
    if (!driverSelect || !driverSelect.value) {
        alert('Please select a Delivery Rider / Driver before assigning.');
        driverSelect.focus();
        return false;
    }

    const checkedBoxes = document.querySelectorAll('.order-row-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('Please select at least one order to dispatch.');
        return false;
    }

    const driverName = driverSelect.options[driverSelect.selectedIndex].text;
    return confirm(`Are you sure you want to assign ${checkedBoxes.length} orders to ${driverName} and dispatch into the delivery van?`);
}

document.addEventListener('DOMContentLoaded', () => {
    updateSummaryCounters();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
