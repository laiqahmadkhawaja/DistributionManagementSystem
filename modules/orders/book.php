<?php
/**
 * Mobile-First Order Booker App
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('orders', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$currentEmp = getCurrentEmployee();
$roleSlug = $currentUser['role_slug'];

// Area & Customer Scoping:
// If Order Booker, only show shops in assigned areas
$assignedAreaIds = getUserAssignedAreaIds($currentUser['id']);
$areaPlaceholders = !empty($assignedAreaIds) ? implode(',', array_map('intval', $assignedAreaIds)) : '0';

if ($roleSlug === ROLE_SUPER_ADMIN || $roleSlug === ROLE_ADMIN) {
    $custQuery = "SELECT id, shop_name, owner_name, phone, area_id, current_balance, credit_limit FROM `customers` WHERE status = 'active' ORDER BY shop_name ASC";
    $customers = $pdo->query($custQuery)->fetchAll();
} else {
    $custQuery = "SELECT id, shop_name, owner_name, phone, area_id, current_balance, credit_limit FROM `customers` WHERE status = 'active' AND area_id IN ({$areaPlaceholders}) ORDER BY shop_name ASC";
    $customers = $pdo->query($custQuery)->fetchAll();
}

// Fetch active products with prices and stock in default warehouse
$productsQuery = "
    SELECT p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price, p.min_sale_price,
           p.base_unit_id, u.name as unit_name, u.code as unit_code,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as current_stock
    FROM `products` p
    JOIN `units` u ON p.base_unit_id = u.id
    LEFT JOIN `stock_ledger` sl ON p.id = sl.product_id
    WHERE p.status = 'active'
    GROUP BY p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price, p.min_sale_price, p.base_unit_id, u.name, u.code
    ORDER BY p.name ASC
";
$products = $pdo->query($productsQuery)->fetchAll();

// Fetch units and unit conversions for multi-tier unit selection in order booking
$allUnits = $pdo->query("SELECT id, name, code, is_base FROM `units` ORDER BY is_base DESC, id ASC")->fetchAll();
$allConversions = $pdo->query("
    SELECT uc.*, u1.name as from_name, u1.code as from_code, u2.name as to_name, u2.code as to_code 
    FROM `unit_conversions` uc
    JOIN `units` u1 ON uc.from_unit_id = u1.id
    JOIN `units` u2 ON uc.to_unit_id = u2.id
")->fetchAll();

// Fetch active order bookers and sales agents
$isSuperAdmin = ($roleSlug === ROLE_SUPER_ADMIN);

$bookers = $pdo->query("
    SELECT id, employee_code, full_name, role_type 
    FROM `employees` 
    WHERE status = 'active' AND role_type IN ('order_booker', 'salesman', 'admin', 'super_admin')
    ORDER BY full_name ASC
")->fetchAll();

$defaultBookerId = $isSuperAdmin 
    ? (!empty($_GET['booker_id']) ? (int)$_GET['booker_id'] : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : ($bookers[0]['id'] ?? 1)))
    : (int)($currentEmp['id'] ?? 0);

// Handle Server-Side POST submission when online
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $priceTier = sanitize($_POST['price_tier'] ?? 'trade_price');
    if (!in_array($priceTier, ['trade_price', 'wholesale_price', 'retail_price'])) {
        $priceTier = 'trade_price';
    }
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
    $notes = sanitize($_POST['notes'] ?? '');
    $productIds = $_POST['prod_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['discount'] ?? [];
    $unitIds = $_POST['unit_id'] ?? [];

    if (empty($customerId)) {
        setFlashMessage('error', 'Please select a retail shop/customer.');
        redirect(BASE_URL . '/modules/orders/book.php');
    }

    if (empty($productIds)) {
        setFlashMessage('error', 'Please add at least one product to the order.');
        redirect(BASE_URL . '/modules/orders/book.php');
    }

    // Fetch customer details to get area
    $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE id = ?");
    $cStmt->execute([$customerId]);
    $customer = $cStmt->fetch();

    if (!$customer) {
        setFlashMessage('error', 'Customer not found.');
        redirect(BASE_URL . '/modules/orders/book.php');
    }

    // Security Rule: Only Super Administrator can book under another employee's name
    if ($isSuperAdmin) {
        $bookerId = !empty($_POST['order_booker_id']) 
            ? (int)$_POST['order_booker_id'] 
            : (!empty($currentEmp['id']) ? (int)$currentEmp['id'] : ($customer['assigned_order_booker_id'] ?: 1));
    } else {
        if (empty($currentEmp['id'])) {
            setFlashMessage('error', 'Access denied. You do not have an active employee profile linked to your user account.');
            redirect(BASE_URL . '/modules/orders/book.php');
        }
        $bookerId = (int)$currentEmp['id'];
    }

    try {
        $pdo->beginTransaction();

        $subtotal = 0.00;
        $orderItems = [];

        for ($i = 0; $i < count($productIds); $i++) {
            $pId = (int)$productIds[$i];
            $qty = (float)($quantities[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);
            $disc = (float)($discounts[$i] ?? 0);
            $uId = (int)($unitIds[$i] ?? 1);

            if ($pId > 0 && $qty > 0) {
                $lineTotal = round(($qty * $price) - $disc, 2);
                $subtotal += $lineTotal;
                $orderItems[] = [
                    'product_id' => $pId,
                    'unit_id' => $uId,
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'discount' => $disc,
                    'line_total' => $lineTotal
                ];
            }
        }

        if (empty($orderItems)) {
            throw new Exception("Please specify valid quantities for selected products.");
        }

        $orderDiscount = (float)($_POST['order_discount'] ?? 0.00);
        $taxAmount = (float)($_POST['order_tax'] ?? 0.00);
        $grandTotal = max(0.00, $subtotal - $orderDiscount + $taxAmount);

        // Check Credit Limit
        $newProjectedBalance = $customer['current_balance'] + $grandTotal;
        if ($customer['credit_limit'] > 0 && $newProjectedBalance > $customer['credit_limit']) {
            // Note in remarks about credit limit exceeded
            $notes .= " [Credit limit exceeded: Limit Rs. {$customer['credit_limit']}, Projected Rs. {$newProjectedBalance}]";
        }

        $orderNumber = generateDocNumber($pdo, 'sales_orders', 'order_number', 'SO-');

        $orderStmt = $pdo->prepare("
            INSERT INTO `sales_orders` (
                `order_number`, `customer_id`, `order_booker_id`, `area_id`, 
                `order_date`, `delivery_date`, `subtotal`, `discount_amount`, 
                `tax_amount`, `grand_total`, `status`, `payment_type`, `price_tier`, `notes`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'credit', ?, ?, NOW())
        ");
        $orderStmt->execute([
            $orderNumber, $customerId, $bookerId, $customer['area_id'],
            $orderDate, $deliveryDate, $subtotal, $orderDiscount,
            $taxAmount, $grandTotal, $priceTier, $notes
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `sales_order_items` (`sales_order_id`, `product_id`, `unit_id`, `unit_price`, `quantity`, `discount`, `line_total`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($orderItems as $item) {
            $itemStmt->execute([
                $orderId, $item['product_id'], $item['unit_id'],
                $item['unit_price'], $item['quantity'], $item['discount'],
                $item['line_total']
            ]);
        }

        // Status history
        $pdo->prepare("
            INSERT INTO `order_status_history` (`sales_order_id`, `from_status`, `to_status`, `remarks`, `changed_by`, `created_at`)
            VALUES (?, NULL, 'submitted', 'Order booked by field sales', ?, NOW())
        ")->execute([$orderId, $currentUser['id']]);

        logAudit($pdo, 'orders', $orderId, 'create', null, ['order_number' => $orderNumber, 'total' => $grandTotal]);

        $pdo->commit();

        setFlashMessage('success', "Order {$orderNumber} booked successfully (Rs. " . number_format($grandTotal, 2) . ")!");
        redirect(BASE_URL . '/modules/orders/view.php?id=' . $orderId);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Error booking order: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/orders/book.php');
    }
}

$pageTitle = 'Book New Order';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-cart-plus-fill text-primary me-2"></i>Book New Order</h4>
        <small class="text-muted">Field Sales Booking · Mobile Optimized</small>
    </div>
    <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Orders List
    </a>
</div>

<!-- Offline Mode Warning Box (Auto toggled by offline-sync.js) -->
<div id="booking-offline-notice" class="alert alert-warning d-none py-2 px-3 mb-3 rounded-3" role="alert">
    <i class="bi bi-cloud-slash-fill me-2"></i>
    <strong>Working Offline:</strong> Your order will be stored safely in your device storage and synchronized automatically once internet connection is available.
</div>

<form id="order-booking-form" method="POST" action="<?= BASE_URL ?>/modules/orders/book.php">
    <?= getCsrfField() ?>

    <div class="row g-3">
        <!-- Customer Selection Card -->
        <div class="col-12 col-md-5">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shop me-2 text-primary"></i>Select Retail Customer</h6>
                </div>
                <div class="card-body p-3">
                    <div class="mb-3">
                        <label for="customer_id" class="form-label small fw-semibold">Retail Shop <span class="text-danger">*</span></label>
                        <select class="form-select" id="customer_id" name="customer_id" required>
                            <option value="">-- Choose Shop --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-balance="<?= (float)$c['current_balance'] ?>" 
                                        data-limit="<?= (float)$c['credit_limit'] ?>"
                                        data-area="<?= $c['area_id'] ?>">
                                    <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['owner_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="order_booker_id" class="form-label small fw-semibold">Order Booker / Salesman <span class="text-danger">*</span></label>
                        <?php if ($isSuperAdmin): ?>
                            <select class="form-select form-select-sm fw-semibold" id="order_booker_id" name="order_booker_id" required>
                                <?php foreach ($bookers as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $defaultBookerId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?> - <?= ucwords(str_replace('_', ' ', $b['role_type'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="order_booker_id" value="<?= (int)($currentEmp['id'] ?? 0) ?>">
                            <select class="form-select form-select-sm fw-semibold bg-light" disabled>
                                <option value="<?= (int)($currentEmp['id'] ?? 0) ?>" selected>
                                    <?= htmlspecialchars($currentEmp['full_name'] ?? 'Myself') ?> (<?= htmlspecialchars($currentEmp['employee_code'] ?? '') ?> - <?= ucwords(str_replace('_', ' ', $currentEmp['role_type'] ?? 'Staff')) ?>)
                                </option>
                            </select>
                            <small class="text-muted" style="font-size:0.7rem;"><i class="bi bi-shield-lock text-secondary me-1"></i>Locked to your employee profile (Super Admin can reassign)</small>
                        <?php endif; ?>
                    </div>

                    <!-- Customer Financial Health Widget -->
                    <div id="customer-info-box" class="p-3 bg-light rounded-3 d-none mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Current Balance:</span>
                            <span id="cust-current-bal" class="fw-bold text-danger">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Credit Limit:</span>
                            <span id="cust-credit-limit" class="fw-bold text-dark">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Available Credit:</span>
                            <span id="cust-avail-credit" class="fw-bold text-success">Rs. 0.00</span>
                        </div>
                    </div>

                    <!-- Price Tier Selection (Trade Price, Wholesale, Retail) -->
                    <div class="mb-3">
                        <label for="price_tier" class="form-label small fw-bold text-dark">
                            <i class="bi bi-tag-fill text-primary me-1"></i> Order Pricing Tier
                        </label>
                        <select class="form-select form-select-sm fw-bold border-primary text-primary" id="price_tier" name="price_tier">
                            <option value="trade_price" selected>Trade Price (TP) - Standard</option>
                            <option value="wholesale_price">Wholesale Price - Bulk Discount</option>
                            <option value="retail_price">Retail Price (MRP) - Full Price</option>
                        </select>
                        <small class="text-muted" style="font-size: 0.72rem;">Changing rate tier recalculates rates for all products</small>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label for="order_date" class="form-label small fw-semibold">Order Date</label>
                            <input type="date" class="form-control form-control-sm" id="order_date" name="order_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label for="delivery_date" class="form-label small fw-semibold">Delivery Date</label>
                            <input type="date" class="form-control form-control-sm" id="delivery_date" name="delivery_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="notes" class="form-label small fw-semibold">Booking Remarks</label>
                        <textarea class="form-control form-control-sm" id="notes" name="notes" rows="2" placeholder="e.g. Deliver before 12 PM"></textarea>
                    </div>

                    <div class="mt-3 text-center">
                        <a href="<?= BASE_URL ?>/modules/customers/create.php" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-plus-circle me-1"></i> Register New Shop in Area
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Selection & Items Table Card -->
        <div class="col-12 col-md-7">
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam me-2 text-primary"></i>Order Items</h6>
                    <button type="button" id="btn-add-item" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Add Product
                    </button>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle" id="order-items-table">
                            <thead class="table-light small">
                                <tr>
                                    <th style="min-width: 170px;">Product</th>
                                    <th style="width: 110px;">Packaging Unit</th>
                                    <th style="width: 80px;">Qty</th>
                                    <th style="width: 105px;">Rate (Rs)</th>
                                    <th style="width: 70px;">Disc</th>
                                    <th style="width: 95px;">Total</th>
                                    <th style="width: 35px;"></th>
                                </tr>
                            </thead>
                            <tbody id="items-container">
                                <!-- Dynamic Item Rows Rendered via JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary & Totals -->
                    <div class="bg-light p-3 rounded-3 mt-3">
                        <div class="row align-items-center">
                            <div class="col-6 col-md-7">
                                <div class="text-muted small">Subtotal:</div>
                                <h6 class="fw-bold mb-0" id="disp-subtotal">Rs. 0.00</h6>
                            </div>
                            <div class="col-6 col-md-5 text-end">
                                <div class="text-muted small">Grand Total:</div>
                                <h4 class="fw-bold text-primary mb-0" id="disp-grand-total">Rs. 0.00</h4>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" id="btn-submit-order" class="btn btn-primary w-100 btn-touch shadow-sm py-2">
                            <i class="bi bi-check2-circle fs-5 me-1 align-middle"></i> Submit Sales Order
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Pre-cached Products & Units Data for Instant Autocomplete & Offline Operation -->
<script>
const cachedProducts = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const customersData = <?= json_encode($customers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const allUnits = <?= json_encode($allUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const allConversions = <?= json_encode($allConversions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.getElementById('customer_id');
    const custInfoBox = document.getElementById('customer-info-box');
    const priceTierSelect = document.getElementById('price_tier');
    const itemsContainer = document.getElementById('items-container');
    const btnAddItem = document.getElementById('btn-add-item');
    const dispSubtotal = document.getElementById('disp-subtotal');
    const dispGrandTotal = document.getElementById('disp-grand-total');

    // Unit Multiplier Resolver
    function getUnitMultiplier(fromUnitId, toUnitId) {
        fromUnitId = parseInt(fromUnitId);
        toUnitId = parseInt(toUnitId);
        if (fromUnitId === toUnitId) return 1.0;

        // Direct rule
        let direct = allConversions.find(c => parseInt(c.from_unit_id) === fromUnitId && parseInt(c.to_unit_id) === toUnitId);
        if (direct) return parseFloat(direct.multiplier);

        // Reverse rule
        let rev = allConversions.find(c => parseInt(c.from_unit_id) === toUnitId && parseInt(c.to_unit_id) === fromUnitId);
        if (rev && parseFloat(rev.multiplier) > 0) return 1.0 / parseFloat(rev.multiplier);

        // 2-step chain (e.g. Carton -> Box -> Piece)
        let step1 = allConversions.filter(c => parseInt(c.from_unit_id) === fromUnitId);
        for (let s1 of step1) {
            let step2 = allConversions.find(c => parseInt(c.from_unit_id) === parseInt(s1.to_unit_id) && parseInt(c.to_unit_id) === toUnitId);
            if (step2) {
                return parseFloat(s1.multiplier) * parseFloat(step2.multiplier);
            }
        }
        return 1.0;
    }

    // Customer Selection Change -> update financial preview
    customerSelect.addEventListener('change', function () {
        const selectedOpt = this.options[this.selectedIndex];
        if (this.value) {
            const bal = parseFloat(selectedOpt.getAttribute('data-balance') || 0);
            const limit = parseFloat(selectedOpt.getAttribute('data-limit') || 0);
            const avail = Math.max(0, limit - bal);

            document.getElementById('cust-current-bal').innerText = 'Rs. ' + bal.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('cust-credit-limit').innerText = 'Rs. ' + limit.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('cust-avail-credit').innerText = 'Rs. ' + avail.toLocaleString('en-US', {minimumFractionDigits: 2});
            custInfoBox.classList.remove('d-none');
        } else {
            custInfoBox.classList.add('d-none');
        }
    });

    // When Global Price Tier changes -> update all rows
    priceTierSelect.addEventListener('change', function () {
        document.querySelectorAll('.item-row').forEach(row => {
            updateRowPriceFromTier(row);
            recalcRow(row);
        });
    });

    function updateRowPriceFromTier(row) {
        const sel = row.querySelector('.prod-select');
        const unitSel = row.querySelector('.prod-unit-select');
        const priceInput = row.querySelector('.item-price');
        const helperBadge = row.querySelector('.rate-tier-badge');

        const pId = sel.value;
        if (!pId) return;

        const prod = cachedProducts.find(p => p.id == pId);
        if (!prod) return;

        const tier = priceTierSelect.value;
        let baseRate = parseFloat(prod[tier] || prod.trade_price);
        const multiplier = getUnitMultiplier(unitSel.value, prod.base_unit_id);
        const finalRate = baseRate * multiplier;

        priceInput.value = finalRate.toFixed(2);

        // Update rate hints helper
        if (helperBadge) {
            const tp = (parseFloat(prod.trade_price) * multiplier).toFixed(1);
            const ws = (parseFloat(prod.wholesale_price) * multiplier).toFixed(1);
            const rtl = (parseFloat(prod.retail_price) * multiplier).toFixed(1);
            helperBadge.innerHTML = `TP:${tp} | WS:${ws} | MRP:${rtl}`;
        }
    }

    // Add Item Row Function
    function addItemRow(defaultProdId = null) {
        const row = document.createElement('tr');
        row.className = 'item-row';

        let prodOptions = '<option value="">-- Choose Item --</option>';
        cachedProducts.forEach(p => {
            const isSelected = defaultProdId && p.id == defaultProdId ? 'selected' : '';
            prodOptions += `<option value="${p.id}" 
                data-tp="${p.trade_price}" 
                data-ws="${p.wholesale_price}" 
                data-rtl="${p.retail_price}" 
                data-min="${p.min_sale_price}" 
                data-unit="${p.base_unit_id}" 
                data-stock="${p.current_stock}" ${isSelected}>
                ${p.name} (Stock: ${p.current_stock})
            </option>`;
        });

        row.innerHTML = `
            <td>
                <select class="form-select form-select-sm prod-select" name="prod_id[]" required>
                    ${prodOptions}
                </select>
                <div class="rate-tier-badge text-muted small mt-1" style="font-size: 0.67rem; line-height: 1;"></div>
            </td>
            <td>
                <select class="form-select form-select-sm prod-unit-select" name="unit_id[]" required>
                    <!-- Dynamically populated based on product -->
                </select>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-qty text-center" name="quantity[]" value="1" min="1" step="1" required>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-price text-end" name="unit_price[]" value="0.00" step="0.01" required>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-disc text-end" name="discount[]" value="0.00" step="0.01">
            </td>
            <td class="text-end fw-bold item-line-total">Rs. 0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger p-1 btn-del-row" title="Remove"><i class="bi bi-trash"></i></button>
            </td>
        `;

        itemsContainer.appendChild(row);

        const sel = row.querySelector('.prod-select');
        const unitSel = row.querySelector('.prod-unit-select');
        const qty = row.querySelector('.item-qty');
        const price = row.querySelector('.item-price');
        const disc = row.querySelector('.item-disc');
        const delBtn = row.querySelector('.btn-del-row');

        // Populate unit dropdown for selected product
        function populateUnits(productId) {
            unitSel.innerHTML = '';
            const prod = cachedProducts.find(p => p.id == productId);
            if (!prod) {
                allUnits.forEach(u => {
                    unitSel.innerHTML += `<option value="${u.id}">${u.name}</option>`;
                });
                return;
            }

            // Always add product's base unit first
            const baseUnit = allUnits.find(u => u.id == prod.base_unit_id);
            if (baseUnit) {
                unitSel.innerHTML += `<option value="${baseUnit.id}" selected>${baseUnit.name} (${baseUnit.code})</option>`;
            }

            // Add other packaging units with conversion ratios
            allUnits.forEach(u => {
                if (u.id != prod.base_unit_id) {
                    const mult = getUnitMultiplier(u.id, prod.base_unit_id);
                    let label = `${u.name}`;
                    if (mult > 1) {
                        label += ` (x${mult} ${baseUnit ? baseUnit.code : ''})`;
                    }
                    unitSel.innerHTML += `<option value="${u.id}">${label}</option>`;
                }
            });
        }

        sel.addEventListener('change', function () {
            if (this.value) {
                populateUnits(this.value);
                updateRowPriceFromTier(row);
            } else {
                unitSel.innerHTML = '<option value="">-- Select Product --</option>';
                price.value = '0.00';
                const helperBadge = row.querySelector('.rate-tier-badge');
                if (helperBadge) helperBadge.innerHTML = '';
            }
            recalcRow(row);
        });

        unitSel.addEventListener('change', function () {
            updateRowPriceFromTier(row);
            recalcRow(row);
        });

        qty.addEventListener('input', () => recalcRow(row));
        price.addEventListener('input', () => recalcRow(row));
        disc.addEventListener('input', () => recalcRow(row));

        delBtn.addEventListener('click', function () {
            if (document.querySelectorAll('.item-row').length > 1) {
                row.remove();
                recalcAll();
            } else {
                sel.value = '';
                unitSel.innerHTML = '<option value="">-- Select Product --</option>';
                qty.value = '1';
                price.value = '0.00';
                disc.value = '0.00';
                const helperBadge = row.querySelector('.rate-tier-badge');
                if (helperBadge) helperBadge.innerHTML = '';
                recalcRow(row);
            }
        });

        if (defaultProdId) {
            sel.value = defaultProdId;
            populateUnits(defaultProdId);
            updateRowPriceFromTier(row);
            recalcRow(row);
        } else {
            unitSel.innerHTML = '<option value="">-- Select Product --</option>';
            price.value = '0.00';
            recalcRow(row);
        }
    }

    function recalcRow(row) {
        const q = parseFloat(row.querySelector('.item-qty').value || 0);
        const p = parseFloat(row.querySelector('.item-price').value || 0);
        const d = parseFloat(row.querySelector('.item-disc').value || 0);
        const total = Math.max(0, (q * p) - d);
        row.querySelector('.item-line-total').innerText = 'Rs. ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
        recalcAll();
    }

    function recalcAll() {
        let subtotal = 0;
        document.querySelectorAll('.item-row').forEach(r => {
            const q = parseFloat(r.querySelector('.item-qty').value || 0);
            const p = parseFloat(r.querySelector('.item-price').value || 0);
            const d = parseFloat(r.querySelector('.item-disc').value || 0);
            subtotal += Math.max(0, (q * p) - d);
        });

        dispSubtotal.innerText = 'Rs. ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2});
        dispGrandTotal.innerText = 'Rs. ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2});
    }

    btnAddItem.addEventListener('click', () => addItemRow());

    // Initialize with 1 empty item row
    addItemRow();

    // Form Interceptor for Offline / Online Submission
    const orderForm = document.getElementById('order-booking-form');
    orderForm.addEventListener('submit', function (e) {
        if (!navigator.onLine) {
            e.preventDefault();
            // Build offline payload
            const custId = customerSelect.value;
            const orderDate = document.getElementById('order_date').value;
            const priceTier = priceTierSelect.value;
            const items = [];

            document.querySelectorAll('.item-row').forEach(r => {
                const pId = r.querySelector('.prod-select').value;
                const uId = r.querySelector('.prod-unit-select').value;
                const q = parseFloat(r.querySelector('.item-qty').value || 0);
                const p = parseFloat(r.querySelector('.item-price').value || 0);
                const d = parseFloat(r.querySelector('.item-disc').value || 0);
                if (pId && q > 0) {
                    items.push({ product_id: pId, unit_id: uId, quantity: q, unit_price: p, discount: d });
                }
            });

            if (items.length === 0) {
                alert('Please add valid items.');
                return;
            }

            const payload = {
                customer_id: custId,
                order_booker_id: <?= (int)($currentEmp['id'] ?? 1) ?>,
                order_date: orderDate,
                price_tier: priceTier,
                items: items
            };

            DMSOffline.addToQueue('order', payload);
            window.location.href = '<?= BASE_URL ?>/modules/orders/index.php';
        }
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
