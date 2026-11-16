<?php
/**
 * Order Dispatch Execution & Stock Deduction
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('warehouse', 'dispatch');

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    setFlashMessage('error', 'Invalid order specified for dispatch.');
    redirect(BASE_URL . '/modules/warehouse/index.php');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();

// Fetch order
$stmt = $pdo->prepare("
    SELECT so.*, c.shop_name, c.owner_name, c.phone as cust_phone, c.address as cust_address,
           a.name as area_name
    FROM `sales_orders` so
    JOIN `customers` c ON so.customer_id = c.id
    JOIN `areas` a ON so.area_id = a.id
    WHERE so.id = ? AND so.status = 'approved'
");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    setFlashMessage('error', 'Order not found or is not currently in approved status.');
    redirect(BASE_URL . '/modules/warehouse/index.php');
}

// Fetch line items with current warehouse stock
$itemsStmt = $pdo->prepare("
    SELECT soi.*, p.name as product_name, p.code as product_code, p.purchase_price as prod_cost, p.base_unit_id,
           u.name as unit_name,
           (SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM `stock_ledger` WHERE product_id = soi.product_id AND warehouse_id = 1) as current_stock
    FROM `sales_order_items` soi
    JOIN `products` p ON soi.product_id = p.id
    JOIN `units` u ON soi.unit_id = u.id
    WHERE soi.sales_order_id = ?
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

// Fetch warehouses & delivery drivers
$currentEmp = getCurrentEmployee();
$warehouses = $pdo->query("SELECT id, name FROM `warehouses` WHERE status = 'active' ORDER BY is_default DESC")->fetchAll();
$deliveryStaff = $pdo->query("SELECT id, full_name, phone, role_type FROM `employees` WHERE role_type IN ('delivery_man', 'salesman') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

$defaultDriverId = !empty($_GET['delivery_man_id']) 
    ? (int)$_GET['delivery_man_id'] 
    : ((!empty($currentEmp['id']) && in_array($currentEmp['role_type'] ?? '', ['delivery_man', 'salesman'])) ? (int)$currentEmp['id'] : ($deliveryStaff[0]['id'] ?? 0));

// Handle Dispatch POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $deliveryManId = (int)($_POST['delivery_man_id'] ?? 0);
    $vehicleNo = sanitize($_POST['vehicle_no'] ?? '');
    $routeName = sanitize($_POST['route_name'] ?? '');
    $dispatchDate = $_POST['dispatch_date'] ?? date('Y-m-d');
    $dispatchedQuantities = $_POST['dispatched_qty'] ?? [];

    if (!$deliveryManId) {
        setFlashMessage('error', 'Please select an assigned delivery driver.');
        redirect(BASE_URL . "/modules/warehouse/dispatch.php?order_id={$orderId}");
    }

    try {
        $pdo->beginTransaction();

        $dispatchNumber = generateDocNumber($pdo, 'delivery_assignments', 'dispatch_number', 'DC-');
        $invoiceNumber = generateDocNumber($pdo, 'sales_invoices', 'invoice_number', 'INV-');

        // Create Delivery Assignment
        $daStmt = $pdo->prepare("
            INSERT INTO `delivery_assignments` (
                `dispatch_number`, `sales_order_id`, `delivery_man_id`, `warehouse_id`, 
                `vehicle_no`, `route_name`, `dispatch_date`, `status`, `dispatched_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'assigned', ?, NOW())
        ");
        $daStmt->execute([
            $dispatchNumber, $orderId, $deliveryManId, $warehouseId,
            $vehicleNo, $routeName, $dispatchDate, $currentUser['id']
        ]);
        $assignmentId = (int)$pdo->lastInsertId();

        $totalInvoiceAmount = 0.00;
        $totalCostOfGoods = 0.00;

        $diStmt = $pdo->prepare("
            INSERT INTO `delivery_items` (
                `delivery_assignment_id`, `sales_order_item_id`, `product_id`, `unit_id`, 
                `dispatched_qty`, `unit_price`, `total_amount`
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $soiUpdate = $pdo->prepare("UPDATE `sales_order_items` SET `dispatched_qty` = ? WHERE id = ?");

        foreach ($items as $item) {
            $itemId = $item['id'];
            $pId = $item['product_id'];
            $uId = $item['unit_id'];
            $uPrice = (float)$item['unit_price'];
            $uCost = (float)$item['prod_cost'];
            $dispQty = (float)($dispatchedQuantities[$itemId] ?? $item['quantity']);

            if ($dispQty > 0) {
                $lineTotal = round($dispQty * $uPrice, 2);
                $lineCost = round($dispQty * $uCost, 2);

                $totalInvoiceAmount += $lineTotal;
                $totalCostOfGoods += $lineCost;

                // 1. Record Delivery Item
                $diStmt->execute([$assignmentId, $itemId, $pId, $uId, $dispQty, $uPrice, $lineTotal]);
                $soiUpdate->execute([$dispQty, $itemId]);

                // 2. Critical Inventory Rule: Convert dispatched packaging units to base physical units
                $baseUnitId = (int)($item['base_unit_id'] ?: $uId);
                $physicalQtyOut = convertUnitQuantity($pdo, $pId, $uId, $baseUnitId, $dispQty);

                recordStockMovement(
                    $pdo,
                    $warehouseId,
                    $pId,
                    'sale_dispatch',
                    'delivery_assignment',
                    $assignmentId,
                    $baseUnitId,
                    0.00, // qty in
                    $physicalQtyOut, // physical base units deducted
                    $uCost,
                    null,
                    "Dispatched {$dispQty} {$item['unit_name']} via Challan {$dispatchNumber}"
                );
            }
        }

        // Fulfill the temporary stock reservation
        fulfillStockReservation($pdo, $orderId);

        // Update Sales Order Status to Dispatched
        $pdo->prepare("UPDATE `sales_orders` SET `status` = 'dispatched' WHERE id = ?")->execute([$orderId]);

        // Add Status History
        $pdo->prepare("
            INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
            VALUES (?, 'approved', 'dispatched', ?, ?, NOW())
        ")->execute([$orderId, "Dispatched under Challan {$dispatchNumber}", $currentUser['id']]);

        // 3. Generate Sales Invoice
        $invStmt = $pdo->prepare("
            INSERT INTO `sales_invoices` (
                `invoice_number`, `sales_order_id`, `customer_id`, `warehouse_id`, 
                `invoice_date`, `subtotal`, `grand_total`, `cost_of_goods`, 
                `paid_amount`, `balance_due`, `status`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'posted', NOW())
        ");
        $invStmt->execute([
            $invoiceNumber, $orderId, $order['customer_id'], $warehouseId,
            $dispatchDate, $totalInvoiceAmount, $totalInvoiceAmount, $totalCostOfGoods,
            $totalInvoiceAmount
        ]);
        $invoiceId = (int)$pdo->lastInsertId();

        // 4. Critical Accounting Rule: Post Double-Entry Journal Entry
        postSalesInvoiceAccounting($pdo, $invoiceId, $invoiceNumber, $order['customer_id'], $totalInvoiceAmount, $totalCostOfGoods, $currentUser['id']);

        logAudit($pdo, 'warehouse', $assignmentId, 'dispatch', null, [
            'challan' => $dispatchNumber,
            'invoice' => $invoiceNumber,
            'amount' => $totalInvoiceAmount
        ]);

        $pdo->commit();

        setFlashMessage('success', "Order successfully dispatched! Challan {$dispatchNumber} generated, stock deducted, and invoice {$invoiceNumber} posted. Gate Pass is ready below.");
        redirect(BASE_URL . "/modules/warehouse/gatepass.php?id={$assignmentId}");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Dispatch failed: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/warehouse/dispatch.php?order_id={$orderId}");
    }
}

$pageTitle = 'Dispatch Order ' . $order['order_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-box-arrow-up-right text-primary me-2"></i>Execute Order Dispatch</h4>
        <small class="text-muted">Order: <span class="fw-bold text-dark"><?= htmlspecialchars($order['order_number']) ?></span> · Customer: <?= htmlspecialchars($order['shop_name']) ?></small>
    </div>
    <a href="<?= BASE_URL ?>/modules/warehouse/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Cancel
    </a>
</div>

<form method="POST" action="<?= BASE_URL ?>/modules/warehouse/dispatch.php?order_id=<?= $orderId ?>">
    <?= getCsrfField() ?>

    <div class="row g-3">
        <!-- Dispatch Settings Card -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-truck me-2 text-primary"></i>Delivery & Route</h6>
                </div>
                <div class="card-body p-3">
                    <div class="mb-3">
                        <label for="warehouse_id" class="form-label small fw-semibold">Dispatch Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="delivery_man_id" class="form-label small fw-semibold">Delivery Driver <span class="text-danger">*</span></label>
                        <select class="form-select" id="delivery_man_id" name="delivery_man_id" required>
                            <option value="">-- Assign Driver --</option>
                            <?php foreach ($deliveryStaff as $ds): ?>
                                <option value="<?= $ds['id'] ?>" <?= $ds['id'] == $defaultDriverId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ds['full_name']) ?> (<?= htmlspecialchars($ds['phone']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="dispatch_date" class="form-label small fw-semibold">Dispatch Date</label>
                        <input type="date" class="form-control" id="dispatch_date" name="dispatch_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="vehicle_no" class="form-label small fw-semibold">Vehicle / Van No.</label>
                        <input type="text" class="form-control" id="vehicle_no" name="vehicle_no" placeholder="e.g. GAW-8821">
                    </div>

                    <div class="mb-3">
                        <label for="route_name" class="form-label small fw-semibold">Delivery Route</label>
                        <input type="text" class="form-control" id="route_name" name="route_name" value="<?= htmlspecialchars($order['area_name']) ?> Route">
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Items & Verified Quantities -->
        <div class="col-12 col-md-8">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-check-all me-2 text-primary"></i>Verify Dispatched Quantities</h6>
                    <small class="text-muted">Stock will be permanently deducted based on the quantities verified below.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Avail Stock</th>
                                    <th class="text-center">Ordered</th>
                                    <th class="text-center" style="width: 130px;">Dispatched Qty</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td data-label="Product">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                                        </td>
                                        <td data-label="Stock" class="text-center">
                                            <span class="badge bg-light text-dark border"><?= (float)$item['current_stock'] ?></span>
                                        </td>
                                        <td data-label="Ordered" class="text-center fw-bold text-muted"><?= (float)$item['quantity'] ?></td>
                                        <td data-label="Dispatch Qty" class="text-center">
                                            <input type="number" 
                                                   class="form-control form-control-sm text-center fw-bold text-primary" 
                                                   name="dispatched_qty[<?= $item['id'] ?>]" 
                                                   value="<?= (float)$item['quantity'] ?>" 
                                                   min="0" 
                                                   max="<?= (float)$item['quantity'] ?>" 
                                                   step="1" 
                                                   required>
                                        </td>
                                        <td data-label="Rate" class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                                        <td data-label="Total" class="text-end fw-bold text-dark"><?= formatMoney($item['line_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted d-block">Automatic Accounting Action:</small>
                                <span class="badge bg-success">Auto-Debit Receivable</span>
                                <span class="badge bg-primary">Auto-Credit Revenue</span>
                                <span class="badge bg-secondary">Auto-Stock Deduction</span>
                            </div>
                            <button type="submit" class="btn btn-primary btn-touch shadow-sm">
                                <i class="bi bi-box-seam me-1"></i> Confirm Dispatch & Print Challan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
