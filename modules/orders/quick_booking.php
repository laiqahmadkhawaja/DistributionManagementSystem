<?php
/**
 * Rapid Desktop Route Order Booking - POS Product Cards & Fast Tray Edition
 * High-speed visual order booking with product cards, live search, rate confirmation & discount modal
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('orders', 'create');

$pdo = getDatabaseConnection();
$currentUser = getCurrentUser();
$company = getCompanyInfo($pdo);
$currentEmp = getCurrentEmployee();

// Fetch active bookers & areas
$bookers = $pdo->query("
    SELECT id, employee_code, full_name 
    FROM employees 
    WHERE status = 'active' AND role_type IN ('order_booker', 'salesman', 'admin', 'super_admin') 
    ORDER BY full_name ASC
")->fetchAll();

$areas = $pdo->query("SELECT id, name, code FROM areas WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch categories for product filtering
$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch products with brand, category, and carton multipliers
$prodSql = "
    SELECT p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price,
           p.base_unit_id, u.name as unit_name, u.code as unit_code,
           COALESCE(b.name, 'Falcon') as brand_name,
           COALESCE(c.name, 'General') as category_name,
           c.id as category_id,
           COALESCE(uc.multiplier, 1) as carton_multiplier,
           COALESCE(SUM(sl.quantity_in) - SUM(sl.quantity_out), 0) as current_stock
    FROM products p
    JOIN units u ON p.base_unit_id = u.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN unit_conversions uc ON uc.product_id = p.id AND uc.from_unit_id = 2
    LEFT JOIN stock_ledger sl ON p.id = sl.product_id
    WHERE p.status = 'active'
    GROUP BY p.id, p.code, p.name, p.trade_price, p.wholesale_price, p.retail_price, 
             p.base_unit_id, u.name, u.code, b.name, c.name, c.id, uc.multiplier
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
        SELECT c.id, c.code, c.shop_name, c.owner_name, c.phone, c.address, c.current_balance, c.credit_limit, c.area_id, a.name as area_name
        FROM customers c
        LEFT JOIN areas a ON c.area_id = a.id
        WHERE c.status = 'active' AND c.area_id = ?
        ORDER BY c.shop_name ASC
    ");
    $custStmt->execute([$selectedAreaId]);
} else {
    $custStmt = $pdo->query("
        SELECT c.id, c.code, c.shop_name, c.owner_name, c.phone, c.address, c.current_balance, c.credit_limit, c.area_id, a.name as area_name
        FROM customers c
        LEFT JOIN areas a ON c.area_id = a.id
        WHERE c.status = 'active'
        ORDER BY c.shop_name ASC
    ");
}
$customersList = $custStmt->fetchAll(PDO::FETCH_ASSOC);

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
    $discounts = $_POST['item_discount'] ?? [];

    if ($customerId <= 0) {
        setFlashMessage('error', 'Please select a customer shop.');
        redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}");
    }

    if (empty($prodIds)) {
        setFlashMessage('error', 'Please add at least one product to the bill tray.');
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
            $disc = (float)($discounts[$i] ?? 0);

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
            $lineRaw = round($totalPieces * $rate, 2);
            $lineTotal = max(0.00, round($lineRaw - $disc, 2));
            $subtotal += $lineTotal;

            $orderItems[] = [
                'product_id' => $pId,
                'unit_id' => $baseUnitId,
                'unit_price' => $rate,
                'quantity' => $totalPieces,
                'discount' => $disc,
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

        // Post accounting journal & update customer receivable balance
        postSalesInvoiceAccounting($pdo, $orderId, $orderNumber, $customerId, $grandTotal, $grandTotal, $currentUser['id']);

        $pdo->commit();

        logAudit($pdo, 'sales_orders', $orderId, 'quick_booked', null, [
            'order_number' => $orderNumber,
            'customer' => $cust['shop_name'],
            'grand_total' => $grandTotal
        ]);

        if ($redirectAction === 'save_print') {
            redirect(BASE_URL . "/modules/orders/bill_compact.php?id={$orderId}");
        } else {
            setFlashMessage('success', "Order #{$orderNumber} successfully booked for {$cust['shop_name']} (Rs. " . number_format($grandTotal, 2) . "). Ready for next order!");
            redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', 'Booking failed: ' . $e->getMessage());
        redirect(BASE_URL . "/modules/orders/quick_booking.php?date={$orderDate}&booker_id={$bookerId}&area_id={$selectedAreaId}&customer_id={$customerId}");
    }
}

// Current customer data if selected
$currentCustomer = null;
if ($selectedCustomerId > 0) {
    foreach ($customersList as $c) {
        if ($c['id'] == $selectedCustomerId) {
            $currentCustomer = $c;
            break;
        }
    }
}

$pageTitle = 'Rapid Route Order Booking';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Rapid Route Order Booking
        </h4>
        <small class="text-muted">High-speed POS product card entry with editable rates, discounts and live bill tray</small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/orders/print_batch.php?date=<?= urlencode($selectedDate) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer me-1"></i> Print Batch Bills (A4/A5)
        </a>
        <a href="<?= BASE_URL ?>/modules/orders/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-check me-1"></i> All Orders
        </a>
    </div>
</div>

<!-- Route & Control Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
    <div class="card-body p-3">
        <form method="GET" action="" id="routeFilterForm" class="row g-2 align-items-end">
            <div class="col-md-2 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Order Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($selectedDate) ?>" onchange="document.getElementById('routeFilterForm').submit();">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Order Booker</label>
                <select name="booker_id" class="form-select form-select-sm" onchange="document.getElementById('routeFilterForm').submit();">
                    <?php foreach ($bookers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $selectedBookerId == $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['full_name']) ?> (<?= htmlspecialchars($b['employee_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold text-muted mb-1">Route / Area</label>
                <select name="area_id" class="form-select form-select-sm" onchange="document.getElementById('routeFilterForm').submit();">
                    <option value="0" <?= $selectedAreaId == 0 ? 'selected' : '' ?>>-- All Routes (<?= count($customersList) ?> Shops) --</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $selectedAreaId == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-12">
                <label class="form-label small fw-bold text-muted mb-1">
                    <i class="bi bi-search text-primary me-1"></i> Search &amp; Select Customer Shop
                </label>
                <div class="input-group input-group-sm">
                    <input type="text" id="custLiveSearch" class="form-control form-control-sm" style="max-width: 160px;" placeholder="🔍 Search shop..." oninput="filterCustomerDropdown(this.value);" onkeydown="if(event.key==='Enter'){event.preventDefault();selectFirstMatchedCustomer();}">
                    <select name="customer_id" id="customerSelect" class="form-select form-select-sm" onchange="document.getElementById('routeFilterForm').submit();">
                        <option value="">-- Select Retail Shop --</option>
                        <?php foreach ($customersList as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $selectedCustomerId == $sh['id'] ? 'selected' : '' ?> data-name="<?= htmlspecialchars(strtolower($sh['shop_name'] . ' ' . $sh['owner_name'] . ' ' . $sh['code'] . ' ' . $sh['phone'])) ?>">
                                <?= htmlspecialchars($sh['shop_name']) ?> (<?= htmlspecialchars($sh['code']) ?>) - Due: Rs. <?= number_format($sh['current_balance'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?= BASE_URL ?>/modules/customers/create.php" target="_blank" class="btn btn-outline-primary" title="Register New Shop">+ New</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!$currentCustomer): ?>
    <!-- Notice to Pick Customer -->
    <div class="card border-0 shadow-sm rounded-3 bg-white p-5 text-center text-muted">
        <i class="bi bi-shop-window fs-1 text-primary mb-2"></i>
        <h5 class="fw-bold text-dark">Please select a Retail Customer Shop from the top dropdown above to begin order booking.</h5>
        <p class="small text-muted mb-0">You can also register a new shop with one click via "+ New".</p>
    </div>
<?php else: ?>

    <!-- Active Shop Information Banner -->
    <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white border-start border-4 border-primary">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="badge bg-primary me-2"><?= htmlspecialchars($currentCustomer['code']) ?></span>
                <span class="h5 fw-bold text-dark mb-0"><?= htmlspecialchars($currentCustomer['shop_name']) ?></span>
                <span class="text-muted small ms-2">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($currentCustomer['owner_name']) ?> &middot; 
                    <i class="bi bi-telephone"></i> <?= htmlspecialchars($currentCustomer['phone'] ?: 'N/A') ?> &middot; 
                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($currentCustomer['area_name'] ?? 'General') ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end">
                    <small class="text-muted d-block">Current Due (Udhaar)</small>
                    <span class="fw-bold <?= $currentCustomer['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 1.15rem;">
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

    <!-- Main Booking Workspace: Product Cards Catalog (Left) + Live Cart Tray (Right) -->
    <div class="row g-3">
        
        <!-- Left: Product Catalog & Cards Grid -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 bg-white mb-3">
                <div class="card-body p-3">
                    
                    <!-- Search & Category Filters -->
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                        <div class="flex-grow-1" style="min-width: 250px;">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="productSearchInput" class="form-control border-start-0 ps-0" placeholder="Type product name, brand, or SKU (e.g. 'Nimko', '36', 'Zoro')..." autofocus oninput="filterProductCards(this.value);">
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted fw-bold mb-0">Tier:</label>
                            <select id="priceTierGlobal" class="form-select form-select-sm" style="width: 140px;" onchange="updateTierPreference(this.value)">
                                <option value="trade_price" selected>Trade Price</option>
                                <option value="wholesale_price">Wholesale Price</option>
                                <option value="retail_price">Retail Price</option>
                            </select>
                        </div>
                    </div>

                    <!-- Category Pills Filter -->
                    <div class="d-flex gap-1 overflow-auto pb-2 mb-3" id="categoryPills">
                        <button type="button" class="btn btn-sm btn-primary category-btn active" onclick="filterByCategory('all', this)">All (<?= count($products) ?>)</button>
                        <?php foreach ($categories as $cat): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary category-btn" onclick="filterByCategory('<?= htmlspecialchars(strtolower($cat['name'])) ?>', this)">
                                <?= htmlspecialchars($cat['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Products Grid -->
                    <div class="row g-2" id="productsGrid" style="max-height: 580px; overflow-y: auto; padding-right: 4px;">
                        <?php foreach ($products as $p): 
                            $mult = (float)$p['carton_multiplier'];
                            $pLabel = $p['name'];
                            if ($mult > 1 && !strpos($pLabel, '*')) {
                                $pLabel .= " (1*" . (int)$mult . ")";
                            }
                        ?>
                            <div class="col-xl-4 col-md-6 col-12 prod-card-col" 
                                 data-name="<?= htmlspecialchars(strtolower($p['name'] . ' ' . $pLabel . ' ' . $p['code'] . ' ' . $p['brand_name'])) ?>"
                                 data-cat="<?= htmlspecialchars(strtolower($p['category_name'])) ?>">
                                <div class="card h-100 border product-item-card p-2 text-start rounded-3 bg-white" 
                                     onclick="openConfirmModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                                     style="cursor: pointer; transition: all 0.15s ease;"
                                     id="card_<?= $p['id'] ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <span class="badge bg-light text-dark border text-truncate" style="max-width: 100px; font-size: 0.7rem;">
                                            <?= htmlspecialchars($p['brand_name']) ?>
                                        </span>
                                        <span class="badge <?= $p['current_stock'] > 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' ?>" style="font-size: 0.7rem;">
                                            Stock: <?= (int)$p['current_stock'] ?>
                                        </span>
                                    </div>
                                    <strong class="d-block text-dark text-truncate mb-1" style="font-size: 0.9rem;" title="<?= htmlspecialchars($pLabel) ?>">
                                        <?= htmlspecialchars($pLabel) ?>
                                    </strong>
                                    <div class="d-flex justify-content-between align-items-end mt-2 pt-1 border-top">
                                        <div>
                                            <small class="text-muted d-block" style="font-size: 0.68rem;">Trade Price</small>
                                            <span class="fw-bold text-primary" style="font-size: 0.95rem;">
                                                Rs. <?= number_format($p['trade_price'], 2) ?>
                                            </span>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block" style="font-size: 0.68rem;">Retail Price</small>
                                            <span class="text-muted small">
                                                Rs. <?= number_format($p['retail_price'], 2) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <!-- In-cart indicator -->
                                    <div class="in-cart-indicator mt-1 py-0 px-2 rounded bg-warning bg-opacity-10 text-dark small fw-bold d-none text-center" id="inCartBadge_<?= $p['id'] ?>">
                                        <i class="bi bi-check-circle-fill text-warning me-1"></i> <span class="in-cart-text">0 in bill</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- Right: Live Order Bill Tray & Checkout -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 bg-white position-sticky" style="top: 80px;">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-receipt text-primary me-1"></i> Order Bill Tray
                    </span>
                    <span class="badge bg-primary rounded-pill" id="cartCountBadge">0 Items</span>
                </div>
                
                <form method="POST" action="" id="bookingOrderForm">
                    <?= getCsrfField() ?>
                    <input type="hidden" name="action" value="quick_save_order">
                    <input type="hidden" name="customer_id" value="<?= $currentCustomer['id'] ?>">
                    <input type="hidden" name="booker_id" value="<?= $selectedBookerId ?>">
                    <input type="hidden" name="order_date" value="<?= htmlspecialchars($selectedDate) ?>">
                    <input type="hidden" name="submit_action" id="submitActionInput" value="save_print">
                    
                    <div id="hiddenInputsContainer"></div>

                    <div class="card-body p-2" style="max-height: 400px; overflow-y: auto;" id="cartItemsContainer">
                        <div class="p-4 text-center text-muted small" id="emptyCartNotice">
                            <i class="bi bi-cart3 fs-3 d-block text-secondary mb-1"></i>
                            Click any product card on the left to add items to this bill.
                        </div>
                        <div id="cartList" class="d-none"></div>
                    </div>

                    <!-- Cart Footer / Summary -->
                    <div class="card-footer bg-light p-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-1 small">
                            <span class="text-muted">Items Subtotal:</span>
                            <span class="fw-bold text-dark" id="subtotalDisplay">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Special Order Discount:</span>
                            <div class="input-group input-group-sm" style="width: 130px;">
                                <span class="input-group-text bg-white">Rs.</span>
                                <input type="number" step="1" min="0" name="order_discount" id="orderDiscountInput" value="0" class="form-control text-end fw-bold" oninput="recalcCartTotals();">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top mb-3">
                            <span class="fw-bold text-dark">Grand Total:</span>
                            <span class="fw-bold text-primary fs-5" id="grandTotalDisplay">Rs. 0.00</span>
                        </div>
                        
                        <div class="mb-3">
                            <input type="text" name="notes" placeholder="Order notes (optional)..." class="form-control form-control-sm">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary fw-bold shadow-sm py-2" onclick="submitBookingOrder('save_print');">
                                <i class="bi bi-printer-fill me-1"></i> Save &amp; Print Bill (A4/A5)
                            </button>
                            <button type="button" class="btn btn-success fw-bold shadow-sm py-2" onclick="submitBookingOrder('save_next');">
                                <i class="bi bi-arrow-right-circle-fill me-1"></i> Save &amp; Next Shop
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

<?php endif; ?>

<!-- ========================================================
     Product Rate & Quantity Confirmation Modal (POS Dialog)
     ======================================================== -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-3">
            <div class="modal-header bg-light py-2 border-bottom">
                <div>
                    <h6 class="modal-title fw-bold text-dark mb-0" id="modalProdTitle">Product Title</h6>
                    <small class="text-muted" id="modalProdSubtitle">1 Carton = 36 Pcs &middot; Available Stock: 500</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <input type="hidden" id="modalProdId">
                <input type="hidden" id="modalCartonMultiplier">

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted mb-1">Carton Qty (Ctn)</label>
                        <input type="number" min="0" step="1" id="modalCtnQty" class="form-control form-control-lg text-center fw-bold fs-4" value="1" oninput="calcModalLineTotal();">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted mb-1">Loose Pieces (Pcs)</label>
                        <input type="number" min="0" step="1" id="modalPcsQty" class="form-control form-control-lg text-center fs-4" value="0" oninput="calcModalLineTotal();">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted mb-1">Rate / Price per Piece</label>
                        <input type="number" min="0" step="0.01" id="modalUnitRate" class="form-control text-end fw-bold fs-5 text-primary" oninput="calcModalLineTotal();">
                        <small class="text-muted" style="font-size: 0.7rem;">Carton Rate: <span id="modalCartonRatePreview">Rs. 0</span></small>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted mb-1">Special Item Discount (Rs.)</label>
                        <input type="number" min="0" step="0.01" id="modalItemDiscount" class="form-control text-end fs-5 text-danger" value="0" oninput="calcModalLineTotal();">
                        <small class="text-muted" style="font-size: 0.7rem;">Deducted from line total</small>
                    </div>
                </div>

                <!-- Line Calculation Summary Box -->
                <div class="bg-light p-3 rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                        <span class="text-muted">Total Calculated Pieces:</span>
                        <strong class="text-dark" id="modalPiecesTotalDisplay">0 Pieces</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                        <span class="text-muted">Subtotal (Qty &times; Rate):</span>
                        <span id="modalSubtotalDisplay">Rs. 0.00</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1 small text-danger" id="modalDiscountRow">
                        <span>Line Discount:</span>
                        <span id="modalDiscountDisplay">-Rs. 0.00</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <strong class="text-dark">Net Line Total:</strong>
                        <strong class="text-primary fs-4" id="modalNetLineTotalDisplay">Rs. 0.00</strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 border-top">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" onclick="commitModalItem();">
                    <i class="bi bi-check2-circle me-1"></i> Add to Bill (Enter)
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.product-item-card:hover {
    border-color: #2563eb !important;
    box-shadow: 0 4px 12px rgba(37,99,235,0.15);
    transform: translateY(-2px);
}
.cart-item-row {
    border-bottom: 1px solid #f1f5f9;
    padding: 8px 0;
    font-size: 0.85rem;
}
.cart-item-row:last-child {
    border-bottom: none;
}
</style>

<script>
// State Management for Order Bill Tray
let cartItems = [];
let modalBs = null;

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('addItemModal');
    if (modalEl) {
        modalBs = new bootstrap.Modal(modalEl);
    }
});

// Filter Customer dropdown live
function filterCustomerDropdown(term) {
    term = (term || '').toLowerCase().trim();
    const select = document.getElementById('customerSelect');
    if (!select) return;

    Array.from(select.options).forEach((opt, idx) => {
        if (idx === 0) return;
        const name = (opt.getAttribute('data-name') || opt.text).toLowerCase();
        const matches = (term === '' || name.includes(term));
        opt.style.display = matches ? '' : 'none';
    });
}

function selectFirstMatchedCustomer() {
    const select = document.getElementById('customerSelect');
    if (!select) return;

    for (let i = 1; i < select.options.length; i++) {
        if (select.options[i].style.display !== 'none') {
            select.selectedIndex = i;
            document.getElementById('routeFilterForm').submit();
            break;
        }
    }
}

// Filter Product Cards by Search Term
function filterProductCards(term) {
    term = (term || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.prod-card-col');
    cards.forEach(card => {
        const name = card.getAttribute('data-name') || '';
        const isMatch = (term === '' || name.includes(term));
        card.style.display = isMatch ? '' : 'none';
    });
}

// Filter by Category Button
function filterByCategory(cat, btn) {
    document.querySelectorAll('.category-btn').forEach(b => {
        b.classList.remove('btn-primary', 'active');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary', 'active');

    const cards = document.querySelectorAll('.prod-card-col');
    cards.forEach(card => {
        const cardCat = card.getAttribute('data-cat') || '';
        if (cat === 'all' || cardCat === cat) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// Open Quantity & Rate Confirmation Modal
function openConfirmModal(product) {
    if (!modalBs) return;

    const mult = parseFloat(product.carton_multiplier) || 1;
    let label = product.name;
    if (mult > 1 && !label.includes('*')) {
        label += ` (1*${mult})`;
    }

    document.getElementById('modalProdTitle').innerText = label;
    document.getElementById('modalProdSubtitle').innerText = `1 Carton = ${mult} Pieces · Available Stock: ${product.current_stock} Pcs`;
    document.getElementById('modalProdId').value = product.id;
    document.getElementById('modalCartonMultiplier').value = mult;

    // Check if this item is already in the cart
    const existing = cartItems.find(it => it.product_id == product.id);
    if (existing) {
        document.getElementById('modalCtnQty').value = existing.ctn_qty;
        document.getElementById('modalPcsQty').value = existing.pcs_qty;
        document.getElementById('modalUnitRate').value = existing.rate;
        document.getElementById('modalItemDiscount').value = existing.discount;
    } else {
        document.getElementById('modalCtnQty').value = 1;
        document.getElementById('modalPcsQty').value = 0;
        
        // Rate per piece based on selected tier
        const tier = document.getElementById('priceTierGlobal')?.value || 'trade_price';
        let unitRate = parseFloat(product[tier]) || parseFloat(product.trade_price) || 0;
        document.getElementById('modalUnitRate').value = unitRate.toFixed(2);
        document.getElementById('modalItemDiscount').value = 0;
    }

    calcModalLineTotal();
    modalBs.show();

    setTimeout(() => {
        const ctnInput = document.getElementById('modalCtnQty');
        ctnInput.focus();
        ctnInput.select();
    }, 300);
}

// Calculate Modal Total dynamically
function calcModalLineTotal() {
    const mult = parseFloat(document.getElementById('modalCartonMultiplier').value) || 1;
    const ctn = parseFloat(document.getElementById('modalCtnQty').value) || 0;
    const pcs = parseFloat(document.getElementById('modalPcsQty').value) || 0;
    const rate = parseFloat(document.getElementById('modalUnitRate').value) || 0;
    const disc = parseFloat(document.getElementById('modalItemDiscount').value) || 0;

    const totalPieces = (ctn * mult) + pcs;
    const subtotal = totalPieces * rate;
    const netTotal = Math.max(0, subtotal - disc);

    document.getElementById('modalPiecesTotalDisplay').innerText = `${totalPieces} Pieces (${ctn} Ctn + ${pcs} Pcs)`;
    document.getElementById('modalSubtotalDisplay').innerText = `Rs. ${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('modalDiscountDisplay').innerText = `-Rs. ${disc.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('modalNetLineTotalDisplay').innerText = `Rs. ${netTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

    const cartonRate = (rate * mult);
    document.getElementById('modalCartonRatePreview').innerText = `Rs. ${cartonRate.toFixed(2)} / ctn`;
}

// Commit Item from Modal to Cart
function commitModalItem() {
    const prodId = parseInt(document.getElementById('modalProdId').value) || 0;
    const mult = parseFloat(document.getElementById('modalCartonMultiplier').value) || 1;
    const ctn = parseFloat(document.getElementById('modalCtnQty').value) || 0;
    const pcs = parseFloat(document.getElementById('modalPcsQty').value) || 0;
    const rate = parseFloat(document.getElementById('modalUnitRate').value) || 0;
    const disc = parseFloat(document.getElementById('modalItemDiscount').value) || 0;

    if (prodId <= 0 || (ctn <= 0 && pcs <= 0)) {
        alert('Please enter at least 1 carton or 1 loose piece.');
        return;
    }

    const title = document.getElementById('modalProdTitle').innerText;
    const totalPieces = (ctn * mult) + pcs;
    const lineTotal = Math.max(0, (totalPieces * rate) - disc);

    // Update or add
    const existingIdx = cartItems.findIndex(it => it.product_id == prodId);
    if (existingIdx >= 0) {
        cartItems[existingIdx] = {
            product_id: prodId,
            name: title,
            carton_multiplier: mult,
            ctn_qty: ctn,
            pcs_qty: pcs,
            total_pieces: totalPieces,
            rate: rate,
            discount: disc,
            line_total: lineTotal
        };
    } else {
        cartItems.push({
            product_id: prodId,
            name: title,
            carton_multiplier: mult,
            ctn_qty: ctn,
            pcs_qty: pcs,
            total_pieces: totalPieces,
            rate: rate,
            discount: disc,
            line_total: lineTotal
        });
    }

    if (modalBs) {
        modalBs.hide();
    }

    renderCartTray();

    // Refocus product search bar
    setTimeout(() => {
        const searchInput = document.getElementById('productSearchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }, 200);
}

// Render Order Bill Cart Tray
function renderCartTray() {
    const container = document.getElementById('cartItemsContainer');
    const emptyNotice = document.getElementById('emptyCartNotice');
    const cartList = document.getElementById('cartList');
    const hiddenContainer = document.getElementById('hiddenInputsContainer');
    const badge = document.getElementById('cartCountBadge');

    badge.innerText = `${cartItems.length} Items`;

    if (cartItems.length === 0) {
        emptyNotice.classList.remove('d-none');
        cartList.classList.add('d-none');
        cartList.innerHTML = '';
        hiddenContainer.innerHTML = '';
    } else {
        emptyNotice.classList.add('d-none');
        cartList.classList.remove('d-none');

        let html = '';
        let hiddenHtml = '';

        cartItems.forEach((it, idx) => {
            html += `
                <div class="cart-item-row">
                    <div class="d-flex justify-content-between align-items-start">
                        <strong class="text-dark text-truncate" style="max-width: 190px;" title="${it.name}">${it.name}</strong>
                        <span class="fw-bold text-primary">Rs. ${it.line_total.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center text-muted small mt-1">
                        <span>
                            ${it.ctn_qty > 0 ? it.ctn_qty + ' Ctn' : ''} ${it.pcs_qty > 0 ? it.pcs_qty + ' Pcs' : ''} 
                            (@ Rs. ${it.rate.toFixed(0)})
                            ${it.discount > 0 ? '<span class="text-danger"> -Rs. ' + it.discount.toFixed(0) + '</span>' : ''}
                        </span>
                        <div>
                            <button type="button" class="btn btn-sm btn-link p-0 text-primary me-2" onclick="editCartItem(${idx})"><i class="bi bi-pencil-square"></i></button>
                            <button type="button" class="btn btn-sm btn-link p-0 text-danger" onclick="removeCartItem(${idx})"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </div>
            `;

            hiddenHtml += `
                <input type="hidden" name="prod_id[]" value="${it.product_id}">
                <input type="hidden" name="ctn_qty[]" value="${it.ctn_qty}">
                <input type="hidden" name="pcs_qty[]" value="${it.pcs_qty}">
                <input type="hidden" name="rate[]" value="${it.rate}">
                <input type="hidden" name="item_discount[]" value="${it.discount}">
            `;
        });

        cartList.innerHTML = html;
        hiddenContainer.innerHTML = hiddenHtml;
    }

    // Update in-card badges
    document.querySelectorAll('.in-cart-indicator').forEach(b => b.classList.add('d-none'));
    cartItems.forEach(it => {
        const b = document.getElementById(`inCartBadge_${it.product_id}`);
        if (b) {
            b.classList.remove('d-none');
            const summary = `${it.ctn_qty > 0 ? it.ctn_qty + ' ctn' : ''} ${it.pcs_qty > 0 ? it.pcs_qty + ' pcs' : ''}`;
            b.querySelector('.in-cart-text').innerText = `${summary} in bill`;
        }
    });

    recalcCartTotals();
}

// Recalculate Cart Totals
function recalcCartTotals() {
    let subtotal = 0;
    cartItems.forEach(it => {
        subtotal += it.line_total;
    });

    const discInput = document.getElementById('orderDiscountInput');
    const disc = parseFloat(discInput ? discInput.value : 0) || 0;
    const grand = Math.max(0, subtotal - disc);

    const subDisplay = document.getElementById('subtotalDisplay');
    const grandDisplay = document.getElementById('grandTotalDisplay');

    if (subDisplay) subDisplay.innerText = `Rs. ${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    if (grandDisplay) grandDisplay.innerText = `Rs. ${grand.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

// Edit item in cart
function editCartItem(idx) {
    const it = cartItems[idx];
    if (!it) return;

    // Find original product
    const p = <?= json_encode($products) ?>.find(x => x.id == it.product_id);
    if (p) {
        openConfirmModal(p);
    }
}

// Remove item from cart
function removeCartItem(idx) {
    const it = cartItems[idx];
    if (it) {
        const b = document.getElementById(`inCartBadge_${it.product_id}`);
        if (b) b.classList.add('d-none');
    }
    cartItems.splice(idx, 1);
    renderCartTray();
}

// Submit Booking Order Form
function submitBookingOrder(actionType) {
    if (cartItems.length === 0) {
        alert('Please add at least one product to the bill tray before saving.');
        return;
    }

    const actionInput = document.getElementById('submitActionInput');
    if (actionInput) actionInput.value = actionType;

    document.getElementById('bookingOrderForm').submit();
}

// Keyboard Enter Key support in Modal
document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        const modalEl = document.getElementById('addItemModal');
        if (modalEl && modalEl.classList.contains('show')) {
            e.preventDefault();
            commitModalItem();
        }
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
