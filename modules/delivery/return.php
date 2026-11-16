<?php
/**
 * Delivery Driver Shop-Wise Returns Entry (دکان سے مال کی واپسی)
 * Allows field delivery personnel to record returned goods from retail shops
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$canAccess = hasPermission('delivery', 'deliver') 
    || hasPermission('returns', 'create')
    || hasPermission('returns', 'view')
    || hasRole([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_DELIVERY_MAN, ROLE_SALESMAN, ROLE_STOREKEEPER]);

if (!$canAccess) {
    requirePermission('delivery', 'deliver');
}

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$roleSlug = $currentUser['role_slug'];
$currentEmp = getCurrentEmployee();

// Resolve Driver ID
$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);
$isDeliveryMan = ($roleSlug === ROLE_DELIVERY_MAN);
$defaultDriverId = $isSuperAdmin 
    ? (!empty($_GET['delivery_man_id']) ? (int)$_GET['delivery_man_id'] : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : 0))
    : (int)($currentEmp['id'] ?? 0);

// Process POST: Save Shop Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shop_return'])) {
    validateCsrf();

    $customerId = (int)($_POST['customer_id'] ?? 0);
    
    // Security Rule: Only Super Administrator can record return under another employee's custody
    if ($isSuperAdmin) {
        $driverId = !empty($_POST['delivery_man_id']) ? (int)$_POST['delivery_man_id'] : ($currentEmp['id'] ?? 0);
    } else {
        if (empty($currentEmp['id'])) {
            setFlashMessage('error', 'Access denied. You do not have an active employee profile linked to your user account.');
            redirect(BASE_URL . '/modules/delivery/return.php');
        }
        $driverId = (int)$currentEmp['id'];
    }
    $assignmentId = !empty($_POST['delivery_assignment_id']) ? (int)$_POST['delivery_assignment_id'] : null;
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $returnDate = sanitize($_POST['return_date'] ?? date('Y-m-d'));
    $reasonCategory = sanitize($_POST['reason_category'] ?? 'Damaged / Rejected');
    $notes = sanitize($_POST['notes'] ?? '');

    $productIds = $_POST['prod_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitPrices = $_POST['unit_price'] ?? [];
    $conditions = $_POST['condition_type'] ?? [];

    if ($customerId <= 0) {
        setFlashMessage('error', 'Please select a valid customer / retail shop.');
        redirect(BASE_URL . '/modules/delivery/return.php');
    }

    if ($driverId <= 0) {
        setFlashMessage('error', 'Please select or assign a delivery driver.');
        redirect(BASE_URL . '/modules/delivery/return.php?customer_id=' . $customerId);
    }

    // Verify Customer
    $cStmt = $pdo->prepare("SELECT id, shop_name, owner_name, current_balance, phone FROM `customers` WHERE id = ?");
    $cStmt->execute([$customerId]);
    $customer = $cStmt->fetch();

    if (!$customer) {
        setFlashMessage('error', 'Customer not found.');
        redirect(BASE_URL . '/modules/delivery/return.php');
    }

    try {
        $pdo->beginTransaction();

        $returnItems = [];
        $totalReturnAmount = 0.00;

        for ($i = 0; $i < count($productIds); $i++) {
            $pId = (int)($productIds[$i] ?? 0);
            $qty = (float)($quantities[$i] ?? 0);
            $price = (float)($unitPrices[$i] ?? 0);
            $cond = sanitize($conditions[$i] ?? 'good');

            if ($pId > 0 && $qty > 0) {
                // Fetch product cost & unit
                $pStmt = $pdo->prepare("SELECT purchase_price, trade_price, base_unit_id FROM `products` WHERE id = ?");
                $pStmt->execute([$pId]);
                $pInfo = $pStmt->fetch();

                $cost = (float)($pInfo['purchase_price'] ?? 0);
                if ($price <= 0) {
                    $price = (float)($pInfo['trade_price'] ?? 0);
                }
                $uId = (int)($pInfo['base_unit_id'] ?? 1);

                $lineTotal = round($qty * $price, 2);
                $totalReturnAmount += $lineTotal;

                $returnItems[] = [
                    'product_id' => $pId,
                    'unit_id' => $uId,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'unit_cost' => $cost,
                    'total_amount' => $lineTotal,
                    'condition_type' => $cond
                ];
            }
        }

        if (empty($returnItems)) {
            throw new Exception("Please add at least one product with a return quantity greater than zero.");
        }

        $returnNumber = generateDocNumber($pdo, 'sales_returns', 'return_number', 'SR-');

        // 1. Insert into sales_returns with in_vehicle_custody & quarantine destination
        // (Active warehouse stock is NOT increased until administrator inspection)
        $srStmt = $pdo->prepare("
            INSERT INTO `sales_returns` (
                `return_number`, `customer_id`, `delivery_man_id`, `delivery_assignment_id`,
                `warehouse_id`, `return_date`, `total_amount`, `return_destination`,
                `status`, `handover_status`, `reason`, `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'quarantine', 'approved', 'in_vehicle_custody', ?, ?, NOW())
        ");
        $fullReason = $reasonCategory . (!empty($notes) ? " - {$notes}" : "");
        $srStmt->execute([
            $returnNumber, $customerId, $driverId, $assignmentId,
            $warehouseId, $returnDate, $totalReturnAmount, $fullReason,
            $currentUser['id']
        ]);
        $returnId = (int)$pdo->lastInsertId();

        // 2. Insert itemized return items with resell_status = 'in_vehicle_custody'
        $sriStmt = $pdo->prepare("
            INSERT INTO `sales_return_items` (
                `sales_return_id`, `product_id`, `unit_id`, `quantity`,
                `unit_price`, `unit_cost`, `total_amount`, `condition_type`, `resell_status`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'in_vehicle_custody')
        ");
        foreach ($returnItems as $ri) {
            $sriStmt->execute([
                $returnId, $ri['product_id'], $ri['unit_id'], $ri['quantity'],
                $ri['unit_price'], $ri['unit_cost'], $ri['total_amount'], $ri['condition_type']
            ]);
        }

        // 3. Issue Credit Note to customer balance
        $cnNumber = generateDocNumber($pdo, 'credit_notes', 'credit_note_number', 'CN-');
        $pdo->prepare("
            INSERT INTO `credit_notes` (`credit_note_number`, `sales_return_id`, `customer_id`, `amount`, `note_date`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ")->execute([$cnNumber, $returnId, $customerId, $totalReturnAmount, $returnDate]);

        // Credit the customer's balance immediately
        updateCustomerBalance($pdo, $customerId, -$totalReturnAmount);

        // 4. Audit Log
        logAudit($pdo, 'returns', $returnId, 'driver_shop_return', null, [
            'return_number' => $returnNumber,
            'customer_id' => $customerId,
            'driver_id' => $driverId,
            'amount' => $totalReturnAmount,
            'items_count' => count($returnItems)
        ]);

        $pdo->commit();

        setFlashMessage('success', "Shop return <strong>{$returnNumber}</strong> recorded successfully! Products are now held in your vehicle custody awaiting warehouse handover.");
        redirect(BASE_URL . '/modules/delivery/return_print.php?id=' . $returnId);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Return submission error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/delivery/return.php?customer_id=' . $customerId);
    }
}

// Pre-selections from GET
$preSelectedCustId = (int)($_GET['customer_id'] ?? 0);
$preSelectedDelId = (int)($_GET['delivery_id'] ?? 0);

$selectedCustomer = null;
$prefillItems = [];

if ($preSelectedDelId > 0) {
    $daStmt = $pdo->prepare("
        SELECT da.*, so.customer_id, c.shop_name, c.owner_name, c.phone, c.current_balance, a.name as area_name
        FROM `delivery_assignments` da
        JOIN `sales_orders` so ON da.sales_order_id = so.id
        JOIN `customers` c ON so.customer_id = c.id
        JOIN `areas` a ON so.area_id = a.id
        WHERE da.id = ?
    ");
    $daStmt->execute([$preSelectedDelId]);
    $delAssignment = $daStmt->fetch();

    if ($delAssignment) {
        $preSelectedCustId = (int)$delAssignment['customer_id'];
        $selectedCustomer = $delAssignment;

        // Fetch items on this delivery challan
        $diStmt = $pdo->prepare("
            SELECT di.*, p.name as product_name, p.code as product_code, p.trade_price, u.name as unit_name
            FROM `delivery_items` di
            JOIN `products` p ON di.product_id = p.id
            JOIN `units` u ON di.unit_id = u.id
            WHERE di.delivery_assignment_id = ?
        ");
        $diStmt->execute([$preSelectedDelId]);
        $prefillItems = $diStmt->fetchAll();
    }
}

if (!$selectedCustomer && $preSelectedCustId > 0) {
    $cStmt = $pdo->prepare("
        SELECT c.*, a.name as area_name 
        FROM `customers` c 
        JOIN `areas` a ON c.area_id = a.id 
        WHERE c.id = ?
    ");
    $cStmt->execute([$preSelectedCustId]);
    $selectedCustomer = $cStmt->fetch();
}

// Active Customers List
$customers = $pdo->query("
    SELECT c.id, c.shop_name, c.owner_name, c.phone, c.current_balance, a.name as area_name 
    FROM `customers` c 
    JOIN `areas` a ON c.area_id = a.id 
    WHERE c.status = 'active' 
    ORDER BY c.shop_name ASC
")->fetchAll();

// Active Products Catalog
$products = $pdo->query("
    SELECT p.id, p.code, p.name, p.trade_price, p.base_unit_id, u.name as unit_name
    FROM `products` p
    JOIN `units` u ON p.base_unit_id = u.id
    WHERE p.status = 'active'
    ORDER BY p.name ASC
")->fetchAll();

// Active Delivery Drivers
$drivers = $pdo->query("
    SELECT id, employee_code, full_name, role_type, phone
    FROM `employees`
    WHERE status = 'active' AND role_type IN ('delivery_man', 'salesman', 'order_booker')
    ORDER BY full_name ASC
")->fetchAll();

if (!$defaultDriverId && !empty($drivers)) {
    $defaultDriverId = (int)$drivers[0]['id'];
}

// Driver's Current Vehicle Custody Summary
$activeDriverId = $isDeliveryMan ? ($currentEmp['id'] ?? 0) : $defaultDriverId;
$custodySummary = ['total_items' => 0, 'total_value' => 0.00];

if ($activeDriverId > 0) {
    $custStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(sri.quantity), 0) as total_items,
            COALESCE(SUM(sri.total_amount), 0) as total_value
        FROM `sales_returns` sr
        JOIN `sales_return_items` sri ON sr.id = sri.sales_return_id
        WHERE sr.delivery_man_id = ? 
          AND sr.handover_status = 'in_vehicle_custody'
    ");
    $custStmt->execute([$activeDriverId]);
    $custodySummary = $custStmt->fetch();
}

// Recent Returns Log by this Driver
$recentReturns = [];
if ($activeDriverId > 0) {
    $rrStmt = $pdo->prepare("
        SELECT sr.*, c.shop_name, a.name as area_name,
               (SELECT COUNT(*) FROM `sales_return_items` WHERE sales_return_id = sr.id) as sku_count,
               (SELECT SUM(quantity) FROM `sales_return_items` WHERE sales_return_id = sr.id) as total_qty
        FROM `sales_returns` sr
        JOIN `customers` c ON sr.customer_id = c.id
        JOIN `areas` a ON c.area_id = a.id
        WHERE sr.delivery_man_id = ?
        ORDER BY sr.id DESC
        LIMIT 10
    ");
    $rrStmt->execute([$activeDriverId]);
    $recentReturns = $rrStmt->fetchAll();
}

$pageTitle = 'Shop Return Entry';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-box-arrow-in-left text-danger me-2"></i>Shop Returns Entry (دکان سے مال کی واپسی)
        </h4>
        <small class="text-muted">Record customer product returns shop-wise into vehicle custody</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php" class="btn btn-warning text-dark fw-bold btn-sm">
            <i class="bi bi-box-seam me-1"></i> Warehouse Stock Handover
        </a>
        <a href="<?= BASE_URL ?>/modules/delivery/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-truck-flatbed me-1"></i> Delivery App
        </a>
    </div>
</div>

<!-- Vehicle Custody Live Alert Banner -->
<div class="card border-0 shadow-sm rounded-3 mb-4 border-start border-4 <?= $custodySummary['total_items'] > 0 ? 'border-warning bg-warning bg-opacity-10' : 'border-success bg-white' ?>">
    <div class="card-body p-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 rounded-circle <?= $custodySummary['total_items'] > 0 ? 'bg-warning text-dark' : 'bg-success text-white' ?>">
                <i class="bi <?= $custodySummary['total_items'] > 0 ? 'bi-truck' : 'bi-check2-circle' ?> fs-4"></i>
            </div>
            <div>
                <div class="fw-bold text-dark">
                    <?= $custodySummary['total_items'] > 0 ? 'Physical Return Stock in Your Vehicle Custody' : 'Vehicle Return Custody Clear' ?>
                </div>
                <div class="small text-muted">
                    <?php if ($custodySummary['total_items'] > 0): ?>
                        You currently hold <strong class="text-dark"><?= (float)$custodySummary['total_items'] ?> return items</strong> 
                        valued at <strong class="text-danger"><?= formatMoney($custodySummary['total_value']) ?></strong>. 
                        Surrender these at warehouse check-in during <strong>Stock Handover</strong>.
                    <?php else: ?>
                        No pending returned goods in vehicle custody. All past returns have been handed over to warehouse.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($custodySummary['total_items'] > 0): ?>
            <a href="<?= BASE_URL ?>/modules/warehouse/stock_handover.php" class="btn btn-sm btn-warning text-dark fw-bold text-nowrap">
                <i class="bi bi-box-seam me-1"></i> Handover to Warehouse Now
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- Main Return Form -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pencil-square text-danger me-2"></i>Record Shop Return
                </h6>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Shop-Wise Voucher</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/modules/delivery/return.php" id="returnForm">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="save_shop_return" value="1">
                    <?php if ($preSelectedDelId > 0): ?>
                        <input type="hidden" name="delivery_assignment_id" value="<?= $preSelectedDelId ?>">
                    <?php endif; ?>

                    <div class="row g-2 mb-3">
                        <!-- Retail Shop Selection -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Retail Shop / Customer <span class="text-danger">*</span></label>
                            <select class="form-select" name="customer_id" id="customerSelect" required onchange="handleCustomerSelect(this.value)">
                                <option value="">-- Choose Shop --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                            data-balance="<?= (float)$c['current_balance'] ?>"
                                            data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                            data-owner="<?= htmlspecialchars($c['owner_name']) ?>"
                                            data-area="<?= htmlspecialchars($c['area_name']) ?>"
                                            <?= $c['id'] == $preSelectedCustId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['area_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Delivery Driver -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Delivery Driver / Agent <span class="text-danger">*</span></label>
                            <?php if ($isSuperAdmin): ?>
                                <select class="form-select" name="delivery_man_id" id="deliveryManSelect" required>
                                    <?php foreach ($drivers as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= $d['id'] == $defaultDriverId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['employee_code']) ?> - <?= ucwords(str_replace('_', ' ', $d['role_type'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="delivery_man_id" value="<?= (int)($currentEmp['id'] ?? 0) ?>">
                                <select class="form-select bg-light" disabled>
                                    <option value="<?= (int)($currentEmp['id'] ?? 0) ?>" selected>
                                        <?= htmlspecialchars($currentEmp['full_name'] ?? 'Myself') ?> (<?= htmlspecialchars($currentEmp['employee_code'] ?? '') ?> - <?= ucwords(str_replace('_', ' ', $currentEmp['role_type'] ?? 'Staff')) ?>)
                                    </option>
                                </select>
                                <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-shield-lock text-secondary me-1"></i>Locked to your vehicle custody (Super Admin can reassign)</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <!-- Return Reason Category -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Return Reason / Nature</label>
                            <select class="form-select" name="reason_category">
                                <option value="Damaged / Broken / Leaking (خراب / پھٹا ہوا)">Damaged / Broken / Leaking (خراب / پھٹا ہوا)</option>
                                <option value="Expired / Near Expiry (تاریخ ختم)">Expired / Near Expiry (تاریخ ختم)</option>
                                <option value="Customer Refused / Excess (دکاندار نے لینے سے انکار کیا)">Customer Refused / Excess (دکاندار نے لینے سے انکار کیا)</option>
                                <option value="Wrong Item Delivered (غلط آئٹم ڈیلیور ہوا)">Wrong Item Delivered (غلط آئٹم ڈیلیور ہوا)</option>
                                <option value="Defective / Quality Issue (کوالٹی کا مسئلہ)">Defective / Quality Issue (کوالٹی کا مسئلہ)</option>
                            </select>
                        </div>

                        <!-- Return Date -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Return Collection Date</label>
                            <input type="date" class="form-control" name="return_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Return Items Table -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-semibold mb-0">Returned Items & Quantities <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Another Product
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle" id="returnItemsTable">
                                <thead class="table-light small">
                                    <tr>
                                        <th style="min-width: 220px;">Product</th>
                                        <th style="width: 100px;">Unit</th>
                                        <th style="width: 110px;">Return Qty</th>
                                        <th style="width: 120px;">Price (Rs.)</th>
                                        <th style="min-width: 160px;">Condition</th>
                                        <th style="width: 110px;" class="text-end">Subtotal</th>
                                        <th style="width: 40px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="returnTableBody">
                                    <?php if (!empty($prefillItems)): ?>
                                        <?php foreach ($prefillItems as $idx => $pi): ?>
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="prod_id[]" value="<?= $pi['product_id'] ?>">
                                                    <div class="fw-bold text-dark small"><?= htmlspecialchars($pi['product_name']) ?></div>
                                                    <span class="text-muted" style="font-size: 0.68rem;"><?= htmlspecialchars($pi['product_code']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($pi['unit_name']) ?></span>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm text-center fw-bold text-danger item-qty" name="quantity[]" value="0" min="0" max="<?= (float)$pi['dispatched_qty'] ?>" step="1" onchange="calculateTotals()">
                                                    <small class="text-muted" style="font-size: 0.65rem;">Max: <?= (float)$pi['dispatched_qty'] ?></small>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm text-end item-price" name="unit_price[]" value="<?= (float)$pi['trade_price'] ?>" step="0.01" readonly>
                                                </td>
                                                <td>
                                                    <select class="form-select form-select-sm" name="condition_type[]">
                                                        <option value="good">Good / Sealed</option>
                                                        <option value="damaged">Damaged / Broken</option>
                                                        <option value="expired">Expired</option>
                                                        <option value="wrong_item">Wrong Variant</option>
                                                    </select>
                                                </td>
                                                <td class="text-end fw-bold text-dark item-subtotal">Rs. 0.00</td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Default empty row -->
                                        <tr>
                                            <td>
                                                <select class="form-select form-select-sm prod-select" name="prod_id[]" required onchange="handleProductSelect(this)">
                                                    <option value="">-- Select Product --</option>
                                                    <?php foreach ($products as $p): ?>
                                                        <option value="<?= $p['id'] ?>" data-price="<?= (float)$p['trade_price'] ?>" data-unit="<?= htmlspecialchars($p['unit_name']) ?>">
                                                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border unit-badge">-</span>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm text-center fw-bold text-danger item-qty" name="quantity[]" value="1" min="1" step="1" required onchange="calculateTotals()">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm text-end item-price" name="unit_price[]" value="0.00" step="0.01" readonly>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm" name="condition_type[]">
                                                    <option value="good">Good / Sealed</option>
                                                    <option value="damaged">Damaged / Broken</option>
                                                    <option value="expired">Expired</option>
                                                    <option value="wrong_item">Wrong Variant</option>
                                                </select>
                                            </td>
                                            <td class="text-end fw-bold text-dark item-subtotal">Rs. 0.00</td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="5" class="text-end fw-bold">Total Credit Amount:</td>
                                        <td class="text-end fw-bold text-danger fs-6" id="totalReturnAmountDisplay">Rs. 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks / Inspection Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="e.g. 2 boxes rejected by shopkeeper due to damaged packaging during transport."></textarea>
                    </div>

                    <button type="submit" class="btn btn-danger w-100 btn-touch shadow-sm fw-bold py-2">
                        <i class="bi bi-check2-circle me-1"></i> Save Return Voucher & Credit Customer Balance
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Vehicle Custody & Instructions -->
    <div class="col-12 col-lg-4">
        <!-- Process Flow Card -->
        <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-shield-check text-success me-2"></i>Return & Handover Protocol
                </h6>
            </div>
            <div class="card-body p-3 pt-0">
                <div class="d-flex align-items-start gap-2 mb-3">
                    <span class="badge bg-danger rounded-circle p-2" style="width: 28px; height: 28px; line-height: 12px;">1</span>
                    <div>
                        <div class="fw-bold small text-dark">Driver Vehicle Custody</div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            Items returned are stored under the driver's vehicle custody. They are NOT added to active warehouse stock.
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-start gap-2 mb-3">
                    <span class="badge bg-warning text-dark rounded-circle p-2" style="width: 28px; height: 28px; line-height: 12px;">2</span>
                    <div>
                        <div class="fw-bold small text-dark">Warehouse Stock Handover</div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            At end-of-day, the storekeeper verifies and receives the physical stock into the Quarantine Return Bin.
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-start gap-2">
                    <span class="badge bg-success rounded-circle p-2" style="width: 28px; height: 28px; line-height: 12px;">3</span>
                    <div>
                        <div class="fw-bold small text-dark">Admin "Mark to Resell"</div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            The Administrator inspects the sound items and marks them to resell. ONLY THEN does active inventory increase.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Returns by this Driver -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Your Recent Returns</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentReturns)): ?>
                    <div class="p-3 text-center text-muted small">No returns logged by you recently.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush small">
                        <?php foreach ($recentReturns as $rr): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span class="fw-bold text-primary">
                                        <a href="<?= BASE_URL ?>/modules/delivery/return_print.php?id=<?= $rr['id'] ?>" target="_blank" class="text-decoration-none">
                                            <?= htmlspecialchars($rr['return_number']) ?>
                                        </a>
                                    </span>
                                    <span class="fw-bold text-danger"><?= formatMoney($rr['total_amount']) ?></span>
                                </div>
                                <div class="text-dark fw-semibold"><?= htmlspecialchars($rr['shop_name']) ?></div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="text-muted" style="font-size: 0.7rem;"><?= formatDate($rr['return_date']) ?> · <?= (float)$rr['total_qty'] ?> Units</span>
                                    <?php if ($rr['handover_status'] === 'in_vehicle_custody'): ?>
                                        <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">In Vehicle Custody</span>
                                    <?php else: ?>
                                        <span class="badge bg-success" style="font-size: 0.65rem;">Warehouse Handover Done</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const productsCatalog = <?= json_encode($products) ?>;

function handleProductSelect(selectElem) {
    const row = selectElem.closest('tr');
    const opt = selectElem.options[selectElem.selectedIndex];
    const price = parseFloat(opt.getAttribute('data-price')) || 0;
    const unit = opt.getAttribute('data-unit') || '-';

    row.querySelector('.unit-badge').textContent = unit;
    row.querySelector('.item-price').value = price.toFixed(2);
    calculateTotals();
}

function calculateTotals() {
    let grandTotal = 0;
    const rows = document.querySelectorAll('#returnTableBody tr');
    rows.forEach(function(row) {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const lineTotal = qty * price;
        grandTotal += lineTotal;
        row.querySelector('.item-subtotal').textContent = 'Rs. ' + lineTotal.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    });
    document.getElementById('totalReturnAmountDisplay').textContent = 'Rs. ' + grandTotal.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function addRow() {
    let optionsHtml = '<option value="">-- Select Product --</option>';
    productsCatalog.forEach(function(p) {
        optionsHtml += `<option value="${p.id}" data-price="${p.trade_price}" data-unit="${p.unit_name}">${p.name} (${p.code})</option>`;
    });

    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <select class="form-select form-select-sm prod-select" name="prod_id[]" required onchange="handleProductSelect(this)">
                ${optionsHtml}
            </select>
        </td>
        <td>
            <span class="badge bg-light text-dark border unit-badge">-</span>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-center fw-bold text-danger item-qty" name="quantity[]" value="1" min="1" step="1" required onchange="calculateTotals()">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-end item-price" name="unit_price[]" value="0.00" step="0.01" readonly>
        </td>
        <td>
            <select class="form-select form-select-sm" name="condition_type[]">
                <option value="good">Good / Sealed</option>
                <option value="damaged">Damaged / Broken</option>
                <option value="expired">Expired</option>
                <option value="wrong_item">Wrong Variant</option>
            </select>
        </td>
        <td class="text-end fw-bold text-dark item-subtotal">Rs. 0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
        </td>
    `;
    document.getElementById('returnTableBody').appendChild(newRow);
}

function removeRow(btn) {
    const tbody = document.getElementById('returnTableBody');
    if (tbody.querySelectorAll('tr').length > 1) {
        btn.closest('tr').remove();
        calculateTotals();
    } else {
        alert('At least one product row is required.');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
