<?php
/**
 * Rapid Desktop Route Order Booking
 * High-speed keyboard-driven order booking from booker paper sheets / phone orders
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('orders', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$company = getCompanyInfo($pdo);
$currentEmp = getCurrentEmployee();

// Fetch active bookers, areas, and products
$bookers = $pdo->query("
    SELECT id, employee_code, full_name 
    FROM employees 
    WHERE status = 'active' AND role_type IN ('order_booker', 'salesman', 'admin', 'super_admin') 
    ORDER BY full_name ASC
")->fetchAll();

$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch products with carton multipliers
$prodSql = "
    SELECT p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price,
           p.base_unit_id, u.name as unit_name, u.code as unit_code,
           COALESCE(uc.multiplier, 1) as carton_multiplier,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as current_stock
    FROM products p
    JOIN units u ON p.base_unit_id = u.id
    LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
    LEFT JOIN stock_ledger sl ON p.id = sl.product_id
    WHERE p.status = 'active'
    GROUP BY p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price, p.base_unit_id, u.name, u.code, uc.multiplier
    ORDER BY p.name ASC
";
$products = $pdo->query($prodSql)->fetchAll(PDO::FETCH_ASSOC);

// Selected parameters
$selectedDate = sanitize($_GET['date'] ?? date('Y-m-d'));
$selectedBookerId = (int)($_GET['booker_id'] ?? (!empty($currentEmp['id']) ? $currentEmp['id'] : ($bookers[0]['id'] ?? 0)));
$selectedAreaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);

// Load customers for selected area (or all active customers if 0)
if ($selectedAreaId > 0) {
    $custStmt = $pdo->prepare("
        SELECT id, code, shop_name, owner_name, phone, address, current_balance, credit_limit
        FROM customers 
        WHERE status = 'active' AND area_id = ?
        ORDER BY shop_name ASC
    ");
    $custStmt->execute([$selectedAreaId]);
} else {
    $custStmt = $pdo->query("
        SELECT id, code, shop_name, owner_name, phone, address, current_balance, credit_limit
        FROM customers 
        WHERE status = 'active'
        ORDER BY shop_name ASC
    ");
}
$customersInArea = $custStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_save_order') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $bookerId = (int)($_POST['booker_id'] ?? $selectedBookerId);
    $orderDate = sanitize($_POST['order_date'] ?? date('Y-m-d'));
    $deliveryDate = !empty($_POST['delivery_date']) ? sanitize($_POST['delivery_date']) : date('Y-m-d', strtotime('+1 day'));
    $priceTier = sanitize($_POST['price_tier'] ?? 'trade_price');
    $notes = sanitize($_POST['notes'] ?? '');
    $redirectAction = sanitize($_POST['submit_action'] ?? 'save_print');

    $prodIds = $_POST['prod_id'] ?? [];
    $ctnQtys = $_POST['ctn_qty'] ?? [];
    $pcsQtys = $_POST['pcs_qty'] ?? [];
    $rates = $_POST['rate'] ?? [];

    if ($customerId <= 0) {
        setFlashMessage('error', 'Please select a customer shop.');
        redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}");
    }

    if (empty($prodIds)) {
        setFlashMessage('error', 'Please add at least one product row.');
        redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}&customer_id={$customerId}");
    }

    try {
        $pdo->beginTransaction();

        $cQuery = $pdo->prepare("SELECT * FROM customers WHERE id = ? FOR UPDATE");
        $cQuery->execute([$customerId]);
        $cust = $cQuery->fetch();

        if (!$cust) {
            throw new Exception("Customer not found.");
        }

        $orderItems = [];
        $subtotal = 0.00;

        for ($i = 0; $i < count($prodIds); $i++) {
            $pId = (int)$prodIds[$i];
            $ctn = (float)($ctnQtys[$i] ?? 0);
            $pcs = (float)($pcsQtys[$i] ?? 0);
            $rate = (float)($rates[$i] ?? 0);

            if ($pId <= 0 || ($ctn <= 0 && $pcs <= 0)) {
                continue;
            }

            // Fetch product conversion multiplier
            $pStmt = $pdo->prepare("
                SELECT p.base_unit_id, COALESCE(uc.multiplier, 1) as carton_multiplier
                FROM products p
                LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
                WHERE p.id = ?
            ");
            $pStmt->execute([$pId]);
            $pData = $pStmt->fetch();

            $multiplier = (float)($pData['carton_multiplier'] ?? 1);
            $baseUnitId = (int)($pData['base_unit_id'] ?? 1);

            $totalPieces = ($ctn * $multiplier) + $pcs;
            $lineTotal = round($totalPieces * $rate, 2);
            $subtotal += $lineTotal;

            $orderItems[] = [
                'product_id' => $pId,
                'unit_id' => $baseUnitId,
                'unit_price' => $rate,
                'quantity' => $totalPieces,
                'discount' => 0.00,
                'line_total' => $lineTotal
            ];
        }

        if (empty($orderItems)) {
            throw new Exception("No valid items entered with quantity > 0.");
        }

        $orderDiscount = (float)($_POST['order_discount'] ?? 0.00);
        $grandTotal = max(0.00, $subtotal - $orderDiscount);

        // Generate Order #
        $orderNumber = generateDocNumber($pdo, 'sales_orders', 'order_number', 'SO-');

        // Create Sales Order (auto approved for traditional desktop workflow)
        $soStmt = $pdo->prepare("
            INSERT INTO sales_orders (
                order_number, customer_id, order_booker_id, area_id,
                order_date, delivery_date, subtotal, discount_amount,
                tax_amount, delivery_charges, grand_total, status,
                payment_type, price_tier, notes, approved_by, approved_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, 'approved', 'credit', ?, ?, ?, NOW(), NOW())
        ");
        $soStmt->execute([
            $orderNumber, $customerId, $bookerId, $cust['area_id'],
            $orderDate, $deliveryDate, $subtotal, $orderDiscount,
            $grandTotal, $priceTier, $notes, $currentUser['id']
        ]);
        $orderId = (int)$pdo->lastInsertId();

        // Insert Order Items
        $soiStmt = $pdo->prepare("
            INSERT INTO sales_order_items (sales_order_id, product_id, unit_id, unit_price, quantity, discount, tax, line_total)
            VALUES (?, ?, ?, ?, ?, ?, 0.00, ?)
        ");
        foreach ($orderItems as $it) {
            $soiStmt->execute([
                $orderId, $it['product_id'], $it['unit_id'], $it['unit_price'],
                $it['quantity'], $it['discount'], $it['line_total']
            ]);
        }

        // Status history
        $pdo->prepare("
            INSERT INTO order_status_history (sales_order_id, from_status, to_status, remarks, changed_by, created_at)
            VALUES (?, 'draft', 'approved', 'Direct rapid booking - auto approved for loading', ?, NOW())
        ")->execute([$orderId, $currentUser['id']]);

        logAudit($pdo, 'orders', $orderId, 'create_quick_order', null, [
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'grand_total' => $grandTotal
        ]);

        $pdo->commit();

        setFlashMessage('success', "Order #{$orderNumber} successfully booked for {$cust['shop_name']}! Total: Rs. " . number_format($grandTotal, 2));

        if ($redirectAction === 'save_print') {
            redirect(BASE_URL . "/modules/orders/bill_compact.php?id={$orderId}&autoprint=1");
        } else {
            // Find next customer index in area sequence
            $nextCustId = 0;
            $foundCurrent = false;
            foreach ($customersInArea as $cRow) {
                if ($foundCurrent) {
                    $nextCustId = (int)$cRow['id'];
                    break;
                }
                if ($cRow['id'] == $customerId) {
                    $foundCurrent = true;
                }
            }
            redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}&customer_id={$nextCustId}");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Booking failed: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}&customer_id={$customerId}");
    }
}

$pageTitle = 'Rapid Route Order Booking';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-lightning-charge text-primary me-2"></i>Rapid Route Order Booking
        </h4>
        <small class="text-muted">High-speed keyboard entry from paper sheets &amp; phone orders with Carton / Pcs calculation</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/print_batch.php?date=<?= $selectedDate ?>&area_id=<?= $selectedAreaId ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1"></i> Print Batch Bills (2 per A4)
        </a>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-check me-1"></i> All Orders
        </a>
    </div>
</div>

<!-- Route & Booker Selection Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <form method="GET" action="" id="routeFilterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">Order Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($selectedDate) ?>" onchange="document.getElementById('routeFilterForm').submit();">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1">Order Booker</label>
                <select name="booker_id" class="form-select form-select-sm" onchange="document.getElementById('routeFilterForm').submit();">
                    <?php foreach ($bookers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $selectedBookerId == $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold text-muted mb-1">Route / Area</label>
                <select name="area_id" class="form-select form-select-sm" onchange="document.getElementById('routeFilterForm').submit();">
                    <option value="0" <?= $selectedAreaId == 0 ? 'selected' : '' ?>>-- All Routes / All Customers (<?= count($customersInArea) ?>) --</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $selectedAreaId == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- Left Column: Shops in this Area Sequence -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-3 bg-white h-100">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
                <span class="fw-bold text-dark small">
                    <i class="bi bi-shop text-primary me-1"></i> Shops (<?= count($customersInArea) ?>)
                </span>
                <a href="<?= BASE_URL ?>/modules/customers/create.php" target="_blank" class="badge bg-outline-primary text-primary border border-primary text-decoration-none">
                    + New Shop
                </a>
            </div>
            <div class="p-2 border-bottom bg-light">
                <input type="text" id="shopSearchInput" class="form-control form-control-sm" placeholder="🔍 Quick search shop..." oninput="filterShopList(this.value);">
            </div>
            <div class="list-group list-group-flush overflow-auto" id="shopListContainer" style="max-height: 580px;">
                <?php if (empty($customersInArea)): ?>
                    <div class="p-3 text-center text-muted small">No active shops found.</div>
                <?php else: foreach ($customersInArea as $sh): 
                    $isSelected = ($selectedCustomerId == $sh['id']) || ($selectedCustomerId === 0 && $customersInArea[0]['id'] == $sh['id']);
                    if ($isSelected && $selectedCustomerId === 0) {
                        $selectedCustomerId = (int)$sh['id'];
                    }
                ?>
                    <a href="<?= BASE_URL ?>/modules/orders/quick_booking.php?date=<?= $selectedDate ?>&booker_id=<?= $selectedBookerId ?>&area_id=<?= $selectedAreaId ?>&customer_id=<?= $sh['id'] ?>" 
                       class="list-group-item list-group-item-action py-2 px-3 shop-list-item <?= $isSelected ? 'active text-white' : '' ?>"
                       data-shop-name="<?= htmlspecialchars(strtolower($sh['shop_name'] . ' ' . $sh['owner_name'] . ' ' . $sh['phone'] . ' ' . $sh['code'])) ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong style="font-size: 0.9rem;"><?= htmlspecialchars($sh['shop_name']) ?></strong>
                            <small class="badge <?= $isSelected ? 'bg-light text-dark' : 'bg-secondary' ?>"><?= htmlspecialchars($sh['code']) ?></small>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1" style="font-size: 0.78rem;">
                            <span><?= htmlspecialchars($sh['owner_name'] ?: 'Owner') ?></span>
                            <span class="<?= $isSelected ? 'text-white' : ($sh['current_balance'] > 0 ? 'text-danger font-monospace fw-bold' : 'text-success') ?>">
                                Rs. <?= number_format((float)$sh['current_balance'], 0) ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Order Entry Grid -->
    <div class="col-md-9">
        <?php 
        // Fetch current selected customer info
        $currentCustomer = null;
        if ($selectedCustomerId > 0) {
            foreach ($customersInArea as $c) {
                if ($c['id'] == $selectedCustomerId) {
                    $currentCustomer = $c;
                    break;
                }
            }
        }
        ?>

        <?php if (!$currentCustomer): ?>
            <div class="card border-0 shadow-sm rounded-3 bg-white p-5 text-center text-muted">
                <i class="bi bi-arrow-left-circle fs-1 text-primary mb-2"></i>
                <h5>Select a shop from the left list to book order.</h5>
            </div>
        <?php else: ?>
            <form method="POST" action="" id="bookingForm">
                <?= getCsrfField() ?>
                <input type="hidden" name="action" value="quick_save_order">
                <input type="hidden" name="customer_id" value="<?= $currentCustomer['id'] ?>">
                <input type="hidden" name="booker_id" value="<?= $selectedBookerId ?>">
                <input type="hidden" name="order_date" value="<?= htmlspecialchars($selectedDate) ?>">
                <input type="hidden" name="submit_action" id="submitActionInput" value="save_print">

                <!-- Shop Banner -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="badge bg-primary me-1"><?= htmlspecialchars($currentCustomer['code']) ?></span>
                            <span class="h5 fw-bold text-dark mb-0"><?= htmlspecialchars($currentCustomer['shop_name']) ?></span>
                            <span class="text-muted small ms-2">Owner: <?= htmlspecialchars($currentCustomer['owner_name']) ?> &middot; Ph: <?= htmlspecialchars($currentCustomer['phone'] ?: 'N/A') ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-end">
                                <small class="text-muted d-block">Current Balance</small>
                                <span class="fw-bold <?= $currentCustomer['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 1.1rem;">
                                    Rs. <?= number_format((float)$currentCustomer['current_balance'], 2) ?>
                                </span>
                            </div>
                            <div class="text-end border-start ps-3">
                                <small class="text-muted d-block">Credit Limit</small>
                                <span class="fw-bold text-dark">Rs. <?= number_format((float)$currentCustomer['credit_limit'], 0) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
                        <span class="fw-bold text-dark">
                            <i class="bi bi-cart3 text-primary me-1"></i> Order Items
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted fw-bold mb-0">Price Tier:</label>
                            <select name="price_tier" id="priceTierSelect" class="form-select form-select-sm" style="width: 140px;" onchange="updateAllRates();">
                                <option value="trade_price" selected>Trade Price</option>
                                <option value="wholesale_price">Wholesale Price</option>
                                <option value="retail_price">Retail Price</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered align-middle mb-0" id="orderItemsTable" style="font-size: 0.88rem;">
                            <thead class="table-light text-center">
                                <tr>
                                    <th style="width: 42%;">Product / SKU</th>
                                    <th style="width: 12%;">Carton (Ctn)</th>
                                    <th style="width: 12%;">Loose (Pcs)</th>
                                    <th style="width: 14%;">Rate / Pc</th>
                                    <th style="width: 15%;">Line Total</th>
                                    <th style="width: 5%;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsTbody">
                                <!-- Pre-populated with 3 empty rows -->
                                <?php for ($r = 0; $r < 4; $r++): ?>
                                    <tr class="item-row" data-row-idx="<?= $r ?>">
                                        <td>
                                            <select name="prod_id[]" class="form-select form-select-sm prod-select" onchange="onProductSelect(this, <?= $r ?>);">
                                                <option value="">-- Select Product --</option>
                                                <?php foreach ($products as $p): 
                                                    $mult = (float)$p['carton_multiplier'];
                                                    $pLabel = $p['name'] . ($mult > 1 ? " (1*{$mult})" : "") . " [Stock: {$p['current_stock']}]";
                                                ?>
                                                    <option value="<?= $p['id'] ?>" 
                                                            data-tp="<?= $p['trade_price'] ?>" 
                                                            data-wp="<?= $p['wholesale_price'] ?>" 
                                                            data-rp="<?= $p['retail_price'] ?>" 
                                                            data-mult="<?= $mult ?>">
                                                        <?= htmlspecialchars($pLabel) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="ctn_qty[]" class="form-control form-control-sm text-center input-ctn" placeholder="0" oninput="calcRow(<?= $r ?>);">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="pcs_qty[]" class="form-control form-control-sm text-center input-pcs" placeholder="0" oninput="calcRow(<?= $r ?>);">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="rate[]" class="form-control form-control-sm text-end input-rate" placeholder="0.00" oninput="calcRow(<?= $r ?>);">
                                        </td>
                                        <td class="text-end fw-bold line-total-cell" style="font-size: 0.95rem;">
                                            Rs. 0
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this);" title="Remove">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-light p-2 d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addNewRow();">
                            <i class="bi bi-plus-circle me-1"></i> Add Another SKU (Enter)
                        </button>
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted small">Special Discount:</span>
                            <input type="number" step="1" min="0" name="order_discount" id="orderDiscount" class="form-control form-control-sm text-end" style="width: 100px;" value="0" oninput="recalcOrderTotal();">
                            <span class="fw-bold text-dark fs-5 ms-2">Grand Total: <span class="text-primary" id="grandTotalDisplay">Rs. 0</span></span>
                        </div>
                    </div>
                </div>

                <!-- Action Controls -->
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        <i class="bi bi-info-circle me-1"></i> Order will be booked and marked approved for today's van loading dispatch.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" onclick="submitBooking('save_print');">
                            <i class="bi bi-printer-fill me-1"></i> Save &amp; Print Bill (2 per A4)
                        </button>
                        <button type="button" class="btn btn-success px-4 fw-bold shadow-sm" onclick="submitBooking('save_next');">
                            <i class="bi bi-arrow-right-circle-fill me-1"></i> Save &amp; Next Shop
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
let rowCounter = 4;

function onProductSelect(selectEl, rowIdx) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    if (!selectedOpt || !selectedOpt.value) return;

    const row = document.querySelector(`tr[data-row-idx="${rowIdx}"]`);
    if (!row) return;

    const tier = document.getElementById('priceTierSelect').value;
    let rate = 0;
    if (tier === 'wholesale_price') {
        rate = parseFloat(selectedOpt.getAttribute('data-wp')) || 0;
    } else if (tier === 'retail_price') {
        rate = parseFloat(selectedOpt.getAttribute('data-rp')) || 0;
    } else {
        rate = parseFloat(selectedOpt.getAttribute('data-tp')) || 0;
    }

    row.querySelector('.input-rate').value = rate;
    calcRow(rowIdx);
}

function updateAllRates() {
    document.querySelectorAll('.item-row').forEach(row => {
        const rowIdx = row.getAttribute('data-row-idx');
        const sel = row.querySelector('.prod-select');
        onProductSelect(sel, rowIdx);
    });
}

function calcRow(rowIdx) {
    const row = document.querySelector(`tr[data-row-idx="${rowIdx}"]`);
    if (!row) return;

    const sel = row.querySelector('.prod-select');
    const selectedOpt = sel.options[sel.selectedIndex];
    const mult = selectedOpt ? (parseFloat(selectedOpt.getAttribute('data-mult')) || 1) : 1;

    const ctn = parseFloat(row.querySelector('.input-ctn').value) || 0;
    const pcs = parseFloat(row.querySelector('.input-pcs').value) || 0;
    const rate = parseFloat(row.querySelector('.input-rate').value) || 0;

    const totalUnits = (ctn * mult) + pcs;
    const lineTotal = Math.round(totalUnits * rate);

    row.querySelector('.line-total-cell').innerText = 'Rs. ' + lineTotal.toLocaleString();
    recalcOrderTotal();
}

function recalcOrderTotal() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const sel = row.querySelector('.prod-select');
        const selectedOpt = sel.options[sel.selectedIndex];
        if (!selectedOpt || !selectedOpt.value) return;

        const mult = parseFloat(selectedOpt.getAttribute('data-mult')) || 1;
        const ctn = parseFloat(row.querySelector('.input-ctn').value) || 0;
        const pcs = parseFloat(row.querySelector('.input-pcs').value) || 0;
        const rate = parseFloat(row.querySelector('.input-rate').value) || 0;

        subtotal += Math.round(((ctn * mult) + pcs) * rate);
    });

    const disc = parseFloat(document.getElementById('orderDiscount').value) || 0;
    const grand = Math.max(0, subtotal - disc);

    document.getElementById('grandTotalDisplay').innerText = 'Rs. ' + grand.toLocaleString();
}

function addNewRow() {
    const tbody = document.getElementById('itemsTbody');
    const firstRow = tbody.querySelector('.item-row');
    if (!firstRow) return;

    const newRow = firstRow.cloneNode(true);
    newRow.setAttribute('data-row-idx', rowCounter);
    newRow.querySelector('.prod-select').selectedIndex = 0;
    newRow.querySelector('.prod-select').setAttribute('onchange', `onProductSelect(this, ${rowCounter});`);
    newRow.querySelector('.input-ctn').value = '';
    newRow.querySelector('.input-ctn').setAttribute('oninput', `calcRow(${rowCounter});`);
    newRow.querySelector('.input-pcs').value = '';
    newRow.querySelector('.input-pcs').setAttribute('oninput', `calcRow(${rowCounter});`);
    newRow.querySelector('.input-rate').value = '';
    newRow.querySelector('.input-rate').setAttribute('oninput', `calcRow(${rowCounter});`);
    newRow.querySelector('.line-total-cell').innerText = 'Rs. 0';

    tbody.appendChild(newRow);
    newRow.querySelector('.prod-select').focus();
    rowCounter++;
}

function removeRow(btn) {
    const tbody = document.getElementById('itemsTbody');
    if (tbody.querySelectorAll('.item-row').length > 1) {
        btn.closest('tr').remove();
        recalcOrderTotal();
    } else {
        const row = btn.closest('tr');
        row.querySelector('.prod-select').selectedIndex = 0;
        row.querySelector('.input-ctn').value = '';
        row.querySelector('.input-pcs').value = '';
        row.querySelector('.input-rate').value = '';
        row.querySelector('.line-total-cell').innerText = 'Rs. 0';
        recalcOrderTotal();
    }
}

function filterShopList(term) {
    term = (term || '').toLowerCase().trim();
    const items = document.querySelectorAll('.shop-list-item');
    items.forEach(el => {
        const text = (el.getAttribute('data-shop-name') || el.innerText).toLowerCase();
        el.style.display = (term === '' || text.includes(term)) ? '' : 'none';
    });
}

function submitBooking(actionType) {
    document.getElementById('submitActionInput').value = actionType;
    document.getElementById('bookingForm').submit();
}

// Enter key in table to add row
document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target && e.target.classList && (e.target.classList.contains('input-pcs') || e.target.classList.contains('input-rate'))) {
        e.preventDefault();
        addNewRow();
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
